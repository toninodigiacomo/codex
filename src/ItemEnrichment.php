<?php

declare(strict_types=1);

require_once __DIR__ . '/Items.php';
require_once __DIR__ . '/Series.php';
require_once __DIR__ . '/Paths.php';
require_once __DIR__ . '/ComicInfo.php';
require_once __DIR__ . '/CoverExtractor.php';
require_once __DIR__ . '/Thumbnails.php';

/**
 * Reads whatever metadata and cover a file has to offer and applies it to
 * an already-created item. Used right after LibraryScanner discovers a
 * new file, and for backfilling items that predate this — never called
 * directly by an end user; there's no "force re-extract" button anywhere
 * in the app, on purpose. A user with thousands of items has no practical
 * way to trigger this one file at a time, and no reason to: it runs
 * automatically for every newly discovered file, and a bulk "extract
 * what's missing" pass (src/LibraryScanner.php callers use this too)
 * covers anything added before this existed.
 */
final class ItemEnrichment
{
    /** @return array{metaFound: bool, coverPath: ?string} */
    public static function run(array $item): array
    {
        $metaFound = false;
        if ($item['type'] === 'comic') {
            $absPath = Paths::resolve($item['path']);
            $meta = ComicInfo::read($absPath);
            $metaFound = $meta !== null;
            if ($meta !== null) {
                if (!empty($meta['series_name'])) {
                    $meta['series_id'] = Series::findOrCreate($meta['series_name']);
                }
                unset($meta['series_name']);
                if ($meta) {
                    Items::update((int) $item['id'], $meta);
                }
            }
        }

        $current = Items::find((int) $item['id']);
        $coverPath = $current !== null ? self::extractAndSaveCover($current) : null;

        Items::update((int) $item['id'], ['metadata_checked_at' => date('c')]);

        return ['metaFound' => $metaFound, 'coverPath' => $coverPath];
    }

    /**
     * Any failure here — a malformed archive, an unusual file MiniZip
     * can't parse, whatever — is logged and treated as "no cover found",
     * never thrown: one bad file must not stop a batch of thousands.
     */
    public static function extractAndSaveCover(array $item): ?string
    {
        try {
            $absPath = Paths::resolve($item['path']);
            $found = CoverExtractor::forItem($absPath, $item['type']);
            if ($found === null) {
                return null;
            }
            $publicDir = realpath(__DIR__ . '/../public');
            $coverDir = $publicDir . '/assets/covers';
            // Remove any previous cover for this item first, in case its
            // extension differs from this extraction's (e.g. a re-scanned
            // file now yields a .jpg where a .png used to be, or GD wasn't
            // available last time so it's still at full resolution).
            foreach (glob($coverDir . '/' . $item['id'] . '.*') ?: [] as $old) {
                @unlink($old);
            }
            $ext = Thumbnails::save($found['data'], $coverDir, (string) $item['id'], $found['ext']);
            if ($ext === null) {
                return null;
            }
            $relPath = 'assets/covers/' . $item['id'] . '.' . $ext;
            Items::update((int) $item['id'], ['cover_path' => $relPath]);
            return $relPath;
        } catch (Throwable $e) {
            error_log('ItemEnrichment: cover extraction failed for item #' . $item['id'] . ' (' . $item['path'] . '): ' . $e->getMessage());
            return null;
        }
    }
}
