<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Libraries.php';
require_once __DIR__ . '/Paths.php';

/**
 * A library organized as Éditeur/Collection/Tome... on disk (rather than
 * relying on per-item metadata) can be browsed that way too, once
 * enabled in Réglages. Nothing here is stored — every listing is
 * derived on the fly from `items.path`, relative to whichever library
 * each item belongs to: the first folder segment is the "publisher",
 * the second (when there is one) is the "collection". An item sitting
 * directly in a library's own root, or one level deep with nothing
 * beneath it, simply doesn't participate — there's no publisher/collection
 * to show it under.
 *
 * The nav itself has three levels: Bibliothèque (one tile per library of
 * the chosen type — libraries are never merged, even when two share a
 * type) → Éditeur (within that one library) → Collection, and an
 * éditeur's tile grid also carries along the standalone tomes that sit
 * directly under it with no collection folder.
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

    /** @return list<array{name: string, thumbnail: ?string, count: int}> */
    public static function listPublishers(string $type, ?array $allowedLibraryIds, ?int $libraryId): array
    {
        return self::groupBy($type, $allowedLibraryIds, 0, null, $libraryId);
    }

    /** @return list<array{name: string, thumbnail: ?string, count: int}> */
    public static function listCollections(string $type, ?array $allowedLibraryIds, ?string $publisherFilter, ?int $libraryId): array
    {
        return self::groupBy($type, $allowedLibraryIds, 1, $publisherFilter, $libraryId);
    }

    /**
     * Item ids whose path — relative to whichever library each belongs
     * to — matches the given publisher and/or collection segment
     * exactly. Matching is done in PHP rather than as a SQL LIKE
     * pattern deliberately: a collection reached from the flat,
     * publisher-less "Collections" listing has no publisher to anchor
     * a path prefix on, and a wildcard-anywhere pattern risks a false
     * match if the collection's name happened to appear as a substring
     * elsewhere in some other item's path.
     *
     * $collection has three meanings: null = don't filter on it at all,
     * a string = must sit in that exact collection folder, false = must
     * sit directly under the publisher with no collection folder at all
     * (the standalone tomes shown alongside the collection tiles).
     * @return list<int>
     */
    public static function itemIdsMatching(string $type, ?array $allowedLibraryIds, ?string $publisher, string|false|null $collection, ?int $libraryId = null): array
    {
        $ids = [];
        foreach (self::librariesOfType($type, $allowedLibraryIds, $libraryId) as $lib) {
            $stmt = Database::connection()->prepare('SELECT id, path FROM items WHERE library_id = ?');
            $stmt->execute([$lib['id']]);
            foreach ($stmt->fetchAll() as $row) {
                $segments = explode('/', self::relativeToLibrary($row['path'], $lib['path']));
                array_pop($segments); // filename
                if ($publisher !== null && ($segments[0] ?? null) !== $publisher) {
                    continue;
                }
                if ($collection === false) {
                    if (count($segments) !== 1) {
                        continue;
                    }
                } elseif ($collection !== null && ($segments[1] ?? null) !== $collection) {
                    continue;
                }
                $ids[] = (int) $row['id'];
            }
        }
        return $ids;
    }

    /** @return list<array{name: string, thumbnail: ?string, count: int}> */
    private static function groupBy(string $type, ?array $allowedLibraryIds, int $segmentIndex, ?string $publisherFilter, ?int $libraryId = null): array
    {
        $groups = []; // name => ['count' => int, 'absDir' => string, 'sortKey' => ?string, 'cover' => ?string]

        foreach (self::librariesOfType($type, $allowedLibraryIds, $libraryId) as $lib) {
            $stmt = Database::connection()->prepare('SELECT path, cover_path FROM items WHERE library_id = ?');
            $stmt->execute([$lib['id']]);
            $libRoot = Paths::libraryRoot() . '/' . trim($lib['path'], '/');

            foreach ($stmt->fetchAll() as $row) {
                $relative = self::relativeToLibrary($row['path'], $lib['path']);
                $segments = explode('/', $relative);
                array_pop($segments); // drop the filename itself — only folder levels matter here
                if (count($segments) <= $segmentIndex) {
                    continue; // this item isn't nested deep enough to have this level at all
                }
                if ($publisherFilter !== null && ($segments[0] ?? null) !== $publisherFilter) {
                    continue;
                }

                $name = $segments[$segmentIndex];
                if (!isset($groups[$name])) {
                    $dirSegments = array_slice($segments, 0, $segmentIndex + 1);
                    $groups[$name] = [
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
        foreach ($groups as $name => $g) {
            $result[] = [
                'name' => $name,
                'count' => $g['count'],
                'thumbnail' => self::resolveThumbnail($g['absDir'], $g['cover']),
            ];
        }
        usort($result, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return $result;
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
