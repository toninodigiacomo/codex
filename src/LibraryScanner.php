<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Items.php';
require_once __DIR__ . '/Paths.php';
require_once __DIR__ . '/ItemEnrichment.php';
require_once __DIR__ . '/Settings.php';

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

    /** @return array{added: int, unchanged: int, orphaned: array<int, array{id:int, title:string, path:string}>} */
    public static function sync(array $library): array
    {
        $pdo = Database::connection();
        $root = Paths::resolve($library['path']);

        $existing = [];
        $stmt = $pdo->prepare('SELECT id, title, path FROM items WHERE library_id = ?');
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
        $unchanged = 0;

        foreach ($foundFiles as $absPath) {
            $relPath = trim(substr($absPath, strlen($libraryRoot)), '/');
            if (isset($existing[$relPath])) {
                unset($existing[$relPath]); // still present on disk — not orphaned
                $unchanged++;
                continue;
            }

            $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
            $format = self::EXTENSION_FORMATS[$ext] ?? $ext;
            $title = pathinfo($absPath, PATHINFO_FILENAME);

            $newId = Items::create($library['type'], [
                'title' => $title,
                'path' => $relPath,
                'format' => $format,
                'library_id' => $library['id'],
            ]);
            // Metadata/cover extraction happens right here, automatically,
            // for every newly discovered file — never as something a user
            // triggers by hand. A file that's already been indexed before
            // is left alone on later syncs (see extract-missing for
            // backfilling anything that predates this).
            $newItem = Items::find($newId);
            if ($newItem !== null) {
                ItemEnrichment::run($newItem);
            }
            $added++;
        }

        // whatever's left in $existing was indexed before but not seen on this pass
        $orphaned = [];
        foreach ($existing as $row) {
            $orphaned[] = ['id' => (int) $row['id'], 'title' => $row['title'], 'path' => $row['path']];
        }

        $pdo->prepare('UPDATE libraries SET last_synced_at = ? WHERE id = ?')->execute([date('c'), $library['id']]);

        return ['added' => $added, 'unchanged' => $unchanged, 'orphaned' => $orphaned];
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
