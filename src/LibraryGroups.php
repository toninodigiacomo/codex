<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Libraries.php';
require_once __DIR__ . '/Paths.php';

/**
 * A library organized as Éditeur/Collection/Sous-collection/.../Tome... on
 * disk (rather than relying on per-item metadata) can be browsed that way
 * too, once enabled in Réglages. Nothing here is stored — every listing is
 * derived on the fly from `items.path`, relative to whichever library each
 * item belongs to. Nesting depth is unlimited and uniform: an éditeur, a
 * collection, and any sous-collection inside it are all just "the folders
 * directly under this path" — the nav recurses one level at a time for as
 * long as there's another folder to descend into, and falls back to a
 * plain paginated browse once a path bottoms out at actual files.
 *
 * The nav itself starts with Bibliothèque (one tile per library of the
 * chosen type — libraries are never merged, even when two share a type),
 * then descends through as many folder levels as the library actually
 * has. At every level, the tile grid for a path also carries along the
 * standalone tomes that sit directly under it with no further subfolder.
 *
 * A folder's thumbnail follows the same convention as Ubooquity's own
 * sidecar images — the exact files a library's scan already excludes
 * from being indexed as content (folder.jpg, header.jpg...) turn out to
 * be exactly the per-folder cover art to show here. If a folder has
 * none of those, the cover of its first item (naturally sorted) stands
 * in instead.
 */
final class LibraryGroups
{
    private const THUMBNAIL_CANDIDATES = ['folder.jpg', 'folder.jpeg', 'folder.png', 'cover.jpg', 'cover.png', 'header.jpg', 'header.png'];

    /**
     * The first tile grid of the éditeur nav: one tile per library of
     * $type (not merged — two libraries sharing a type, e.g. two "BD"
     * libraries, stay two separate tiles so their éditeurs aren't mixed
     * together). $includeEmpty controls whether a library with no
     * Éditeur/Collection structure at all still gets a (dead-end) tile.
     * @return list<array{id: int, name: string, thumbnail: ?string, count: int}>
     */
    public static function listLibrariesForType(string $type, ?array $allowedLibraryIds, bool $includeEmpty): array
    {
        $result = [];
        foreach (self::librariesOfType($type, $allowedLibraryIds, null) as $lib) {
            $stmt = Database::connection()->prepare('SELECT path, cover_path FROM items WHERE library_id = ?');
            $stmt->execute([$lib['id']]);
            $libRoot = Paths::libraryRoot() . '/' . trim($lib['path'], '/');

            $count = 0;
            $hasStructure = false;
            $sortKey = null;
            $cover = null;
            foreach ($stmt->fetchAll() as $row) {
                $count++;
                $segments = explode('/', self::relativeToLibrary($row['path'], $lib['path']));
                array_pop($segments); // filename
                if (count($segments) > 0) {
                    $hasStructure = true;
                }
                $relative = self::relativeToLibrary($row['path'], $lib['path']);
                if ($sortKey === null || strnatcasecmp($relative, $sortKey) < 0) {
                    $sortKey = $relative;
                    $cover = $row['cover_path'];
                }
            }
            if (!$hasStructure && !$includeEmpty) {
                continue;
            }
            $result[] = [
                'id' => (int) $lib['id'],
                'name' => (string) $lib['name'],
                'count' => $count,
                'thumbnail' => self::resolveThumbnail($libRoot, $cover),
            ];
        }
        usort($result, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return $result;
    }

    /**
     * The tile grid of folders directly under $pathPrefix — an éditeur
     * listing when $pathPrefix is empty, a collection or sous-collection
     * listing at any depth otherwise. Recursing further is just calling
     * this again with one more segment appended; an empty result means
     * $pathPrefix is a leaf (nothing left to descend into).
     * @param list<string> $pathPrefix
     * @return list<array{name: string, thumbnail: ?string, count: int}>
     */
    public static function listSubfolders(string $type, ?array $allowedLibraryIds, ?int $libraryId, array $pathPrefix): array
    {
        $depth = count($pathPrefix);
        $groups = []; // name => ['name' => string, 'count' => int, 'absDir' => string, 'sortKey' => ?string, 'cover' => ?string]

        foreach (self::librariesOfType($type, $allowedLibraryIds, $libraryId) as $lib) {
            $stmt = Database::connection()->prepare('SELECT path, cover_path FROM items WHERE library_id = ?');
            $stmt->execute([$lib['id']]);
            $libRoot = Paths::libraryRoot() . '/' . trim($lib['path'], '/');

            foreach ($stmt->fetchAll() as $row) {
                $relative = self::relativeToLibrary($row['path'], $lib['path']);
                $segments = explode('/', $relative);
                array_pop($segments); // drop the filename itself — only folder levels matter here
                if (count($segments) <= $depth || array_slice($segments, 0, $depth) !== $pathPrefix) {
                    continue; // doesn't sit under $pathPrefix at all, or doesn't go one level deeper than it
                }

                $name = $segments[$depth];
                if (!isset($groups[$name])) {
                    $dirSegments = array_slice($segments, 0, $depth + 1);
                    $groups[$name] = [
                        // kept alongside the array key rather than read back from it: a folder
                        // named e.g. "2000" would otherwise come back as the *integer* 2000 —
                        // PHP silently casts purely-numeric string array keys to int — and break
                        // the strnatcasecmp() sort below, which requires a string.
                        'name' => $name,
                        'count' => 0,
                        'absDir' => $libRoot . '/' . implode('/', $dirSegments),
                        'sortKey' => null,
                        'cover' => null,
                    ];
                }
                $groups[$name]['count']++;
                if ($groups[$name]['sortKey'] === null || strnatcasecmp($relative, $groups[$name]['sortKey']) < 0) {
                    $groups[$name]['sortKey'] = $relative;
                    $groups[$name]['cover'] = $row['cover_path'];
                }
            }
        }

        $result = [];
        foreach ($groups as $g) {
            $result[] = [
                'name' => $g['name'],
                'count' => $g['count'],
                'thumbnail' => self::resolveThumbnail($g['absDir'], $g['cover']),
            ];
        }
        usort($result, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return $result;
    }

    /**
     * Item ids nested under $pathPrefix — a list of folder segments below
     * whichever library each item belongs to. Matching is done in PHP
     * rather than as a SQL LIKE pattern deliberately: a wildcard-anywhere
     * pattern risks a false match if a folder name happened to appear as
     * a substring elsewhere in some other item's path.
     *
     * $exactDepthOnly = false (the default, used for actually browsing a
     * chosen éditeur/collection/sous-collection) matches anything nested
     * under $pathPrefix however deep. true matches only items sitting
     * directly in that exact folder with no further subfolder — the
     * standalone tomes shown alongside a level's subfolder tiles.
     * @param list<string> $pathPrefix
     * @return list<int>
     */
    public static function itemIdsMatching(string $type, ?array $allowedLibraryIds, array $pathPrefix, bool $exactDepthOnly = false, ?int $libraryId = null): array
    {
        $depth = count($pathPrefix);
        $ids = [];
        foreach (self::librariesOfType($type, $allowedLibraryIds, $libraryId) as $lib) {
            $stmt = Database::connection()->prepare('SELECT id, path FROM items WHERE library_id = ?');
            $stmt->execute([$lib['id']]);
            foreach ($stmt->fetchAll() as $row) {
                $segments = explode('/', self::relativeToLibrary($row['path'], $lib['path']));
                array_pop($segments); // filename
                if (count($segments) < $depth || array_slice($segments, 0, $depth) !== $pathPrefix) {
                    continue;
                }
                if ($exactDepthOnly && count($segments) !== $depth) {
                    continue;
                }
                $ids[] = (int) $row['id'];
            }
        }
        return $ids;
    }

    private static function resolveThumbnail(string $absDir, ?string $fallbackCoverPath): ?string
    {
        foreach (self::THUMBNAIL_CANDIDATES as $candidate) {
            $full = $absDir . '/' . $candidate;
            if (is_file($full)) {
                // served through the same folder-thumbnail endpoint that reads it live off disk —
                // library content is mounted read-only and outside public/, so it can't be linked directly
                return 'api/folder-thumbnail?path=' . rawurlencode(self::relativeToLibraryRoot($full));
            }
        }
        return $fallbackCoverPath;
    }

    private static function relativeToLibraryRoot(string $absPath): string
    {
        $root = Paths::libraryRoot();
        return trim(substr($absPath, strlen($root)), '/');
    }

    private static function relativeToLibrary(string $itemPath, string $libraryPath): string
    {
        return trim(substr($itemPath, strlen(trim($libraryPath, '/'))), '/');
    }

    /**
     * $libraryId, when given, scopes everything to that single library
     * instead of merging every library of $type together — the éditeur
     * nav no longer mixes libraries that happen to share a type (e.g.
     * two "BD" libraries), it browses one at a time. The permission
     * filter still applies on top, so a caller can't widen its own
     * access by passing a library id it isn't allowed to see.
     * @return list<array{id: int, path: string}>
     */
    private static function librariesOfType(string $type, ?array $allowedLibraryIds, ?int $libraryId): array
    {
        $libs = array_filter(Libraries::all(), fn($l) => $l['type'] === $type);
        if ($allowedLibraryIds !== null) {
            $libs = array_filter($libs, fn($l) => in_array((int) $l['id'], $allowedLibraryIds, true));
        }
        if ($libraryId !== null) {
            $libs = array_filter($libs, fn($l) => (int) $l['id'] === $libraryId);
        }
        return array_values($libs);
    }
}
