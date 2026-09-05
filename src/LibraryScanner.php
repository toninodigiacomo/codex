<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Items.php';
require_once __DIR__ . '/Paths.php';
require_once __DIR__ . '/ItemEnrichment.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/AppLog.php';
require_once __DIR__ . '/LibraryJobs.php';

/**
 * Walks a library's mounted folder and reconciles it against the
 * database: creates an item for every matching file not already
 * indexed, and reports (without deleting) any indexed item whose file
 * has since disappeared — deleting is left as a deliberate admin
 * decision, not something a scan does silently.
 *
 * A file's *type* (comic/ebook/magazine/other) comes from the library
 * it's in, not the file extension — a bare .pdf is genuinely ambiguous
 * (a scanned comic and a magazine issue are both just "a PDF"), but the
 * library it lives in ("BD Franco-Belge" vs "Sorties Périodiques") isn't.
 * The extension only decides the stored `format` and whether the file is
 * recognized at all.
 */
final class LibraryScanner
{
    private const EXTENSION_FORMATS = [
        'cbz' => 'cbz',
        'cbr' => 'cbr',
        'epub' => 'epub',
        'pdf' => 'pdf',
        'jpg' => 'jpg',
        'jpeg' => 'jpg',
        'png' => 'png',
        'gif' => 'gif',
        'webp' => 'webp',
    ];

    private const MAX_DEPTH = 16; // guards against a pathological symlink loop, not a realistic library depth

    /**
     * $limit caps how many files get (re-)inserted or re-enriched — cover
     * extraction is the expensive part — in this one call, whether that's
     * a brand new file or an existing one whose size/mtime changed; passing
     * null (the default, used by sync-all and the cron sync token)
     * processes everything found in a single pass, exactly as before. With
     * a limit, repeated calls make progress a batch at a time without any
     * state to track between them: each call walks and diffs the whole
     * tree fresh, but a file a previous call already handled is now simply
     * up to date in the DB and shows up as $unchanged instead of being
     * redone — so the caller's loop just keeps calling until nothing
     * changed this batch ($added and $updated both 0).
     * @return array{added: int, updated: int, unchanged: int, total: int, conflicted: array<int, string>, orphaned: array<int, array{id:int, title:string, path:string}>}
     */
    public static function sync(array $library, ?int $limit = null): array
    {
        $pdo = Database::connection();
        $root = Paths::resolve($library['path']);

        $existing = [];
        $stmt = $pdo->prepare('SELECT id, title, path, file_size, file_mtime FROM items WHERE library_id = ?');
        $stmt->execute([$library['id']]);
        foreach ($stmt->fetchAll() as $row) {
            $existing[$row['path']] = $row;
        }

        $foundFiles = [];
        if (is_dir($root)) {
            $excludePattern = Settings::scanExcludePattern();
            self::walk($root, $foundFiles, 0, $excludePattern);
        }

        $libraryRoot = Paths::libraryRoot();
        $added = 0;
        $updated = 0;
        $unchanged = 0;
        $conflicted = [];

        foreach ($foundFiles as $absPath) {
            $relPath = trim(substr($absPath, strlen($libraryRoot)), '/');
            $currentSize = @filesize($absPath);
            $currentMtime = @filemtime($absPath);

            if (isset($existing[$relPath])) {
                $row = $existing[$relPath];
                unset($existing[$relPath]); // still present on disk — not orphaned

                // A size or mtime that differs from what was recorded at the last
                // (re-)extraction means the file at this exact path was edited in
                // place — a rescanned/upscaled page, a corrected translation, the
                // kind of touch-up that keeps the same filename on purpose.
                // Comparing stat() results (already read above, no extra I/O
                // beyond what the OS gives back for free) catches that far more
                // cheaply than hashing every file's actual bytes on every sync —
                // the one thing it can't catch is an edit that preserves both the
                // exact byte count and the exact mtime, which essentially never
                // happens outside of someone deliberately forging it.
                $sizeKnown = $row['file_size'] !== null && $currentSize !== false;
                $mtimeKnown = $row['file_mtime'] !== null && $currentMtime !== false;
                $changed = ($sizeKnown && (int) $row['file_size'] !== $currentSize)
                    || ($mtimeKnown && (int) $row['file_mtime'] !== $currentMtime);

                if (!$changed) {
                    $unchanged++;
                    continue;
                }
                if ($limit !== null && ($added + $updated) >= $limit) {
                    continue; // left for the next call, same as an unprocessed new file
                }

                $item = Items::find((int) $row['id']);
                if ($item !== null) {
                    LibraryJobs::working($library['id'], 'sync', $unchanged + $added + $updated, count($foundFiles), $row['title']);
                    AppLog::note("sync: item {$row['id']} ({$relPath}) modifié sur le disque, ré-extraction en cours");
                    ItemEnrichment::run($item);
                    Items::update((int) $row['id'], ['file_size' => $currentSize !== false ? $currentSize : null, 'file_mtime' => $currentMtime !== false ? $currentMtime : null]);
                    AppLog::note("sync: item {$row['id']} ok");
                }
                $updated++;
                continue;
            }
            if ($limit !== null && ($added + $updated) >= $limit) {
                continue; // left for the next call — not orphaned, not unchanged, just not reached yet this batch
            }

            $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
            $format = self::EXTENSION_FORMATS[$ext] ?? $ext;
            $title = pathinfo($absPath, PATHINFO_FILENAME);

            try {
                $newId = Items::create($library['type'], [
                    'title' => $title,
                    'path' => $relPath,
                    'format' => $format,
                    'library_id' => $library['id'],
                    'file_size' => $currentSize !== false ? $currentSize : null,
                    'file_mtime' => $currentMtime !== false ? $currentMtime : null,
                ]);
            } catch (PDOException $e) {
                // items.path is UNIQUE across every library, not per-library — this
                // exact relative path already belongs to a *different* library
                // (two libraries whose configured folders overlap, one library's
                // path changed after its first sync and now collides with
                // another's, ...). Skipping it here, rather than letting the
                // exception propagate, is what keeps one such conflict from
                // aborting this library's whole sync partway through — and, since
                // sync-all also no longer lets one library's failure stop it from
                // moving on to the next, every other library still gets synced
                // even while this file sits unresolved.
                if (str_contains($e->getMessage(), 'items.path')) {
                    $conflicted[] = $relPath;
                    continue;
                }
                throw $e;
            }
            // Metadata/cover extraction happens right here, automatically,
            // for every newly discovered file — never as something a user
            // triggers by hand. A file that's already been indexed before
            // is left alone on later syncs (see extract-missing for
            // backfilling anything that predates this).
            $newItem = Items::find($newId);
            if ($newItem !== null) {
                LibraryJobs::working($library['id'], 'sync', $unchanged + $added + $updated, count($foundFiles), $title);
                AppLog::note("sync: item {$newItem['id']} ({$relPath}) en cours");
                ItemEnrichment::run($newItem);
                AppLog::note("sync: item {$newItem['id']} ok");
            }
            $added++;
        }

        // whatever's left in $existing was indexed before but not seen on this pass
        $orphaned = [];
        foreach ($existing as $row) {
            $orphaned[] = ['id' => (int) $row['id'], 'title' => $row['title'], 'path' => $row['path']];
        }

        $pdo->prepare('UPDATE libraries SET last_synced_at = ? WHERE id = ?')->execute([date('c'), $library['id']]);

        return ['added' => $added, 'updated' => $updated, 'unchanged' => $unchanged, 'total' => count($foundFiles), 'conflicted' => $conflicted, 'orphaned' => $orphaned];
    }

    /** @param array<int, string> $found */
    private static function walk(string $dir, array &$found, int $depth, string $excludePattern): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            if ($excludePattern !== '' && @preg_match($excludePattern, $entry) === 1) {
                continue; // matches the admin-configured exclude pattern (e.g. Ubooquity's folder.jpg/header.jpg/folder.css/folder-info.html)
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                self::walk($path, $found, $depth + 1, $excludePattern);
            } elseif (is_file($path)) {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (isset(self::EXTENSION_FORMATS[$ext])) {
                    $found[] = $path;
                }
            }
        }
    }
}
