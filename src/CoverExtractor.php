<?php

declare(strict_types=1);

require_once __DIR__ . '/MiniZip.php';
require_once __DIR__ . '/MiniRar.php';
require_once __DIR__ . '/PdfRenderer.php';

/**
 * Finds the source image bytes for an item's cover:
 *  - comic (.cbz): the first page — sorted naturally, the same way a
 *    comic reader determines page order.
 *  - comic (.cbr): same idea, via MiniRar — only works when the archive
 *    stores its images without RAR's own (proprietary) compression; see
 *    MiniRar.php for why, and what that does and doesn't cover.
 *  - ebook (.epub): the cover declared in the package's manifest
 *    (EPUB2's <meta name="cover"> or EPUB3's properties="cover-image"),
 *    falling back to the first image in the archive if nothing is
 *    declared.
 *  - other (a standalone image file): the file itself.
 *  - any type, when the file is actually a .pdf (scanned comics/books
 *    are often distributed this way regardless of which library
 *    they're catalogued under): page 1, actually rendered — see
 *    PdfRenderer.
 * Returns ['data' => raw bytes, 'ext' => lowercase extension] or null.
 */
final class CoverExtractor
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public static function forItem(string $absolutePath, string $type): ?array
    {
        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            $data = PdfRenderer::renderPage($absolutePath, 0);
            return $data === null ? null : ['data' => $data, 'ext' => 'jpg'];
        }
        if ($ext === 'cbr') {
            return self::firstImageInRar($absolutePath);
        }
        return match ($type) {
            'comic' => self::firstImageInZip($absolutePath),
            'ebook' => self::epubCover($absolutePath) ?? self::firstImageInZip($absolutePath),
            'other' => self::rawImageFile($absolutePath),
            default => null,
        };
    }

    private static function firstImageInRar(string $absolutePath): ?array
    {
        $entries = MiniRar::listEntries($absolutePath);
        $images = [];
        foreach ($entries as $entry) {
            $base = basename($entry['name']);
            if ($base === '' || str_starts_with($base, '.')) {
                continue;
            }
            $ext = strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION));
            if (in_array($ext, self::IMAGE_EXTENSIONS, true)) {
                $images[$entry['name']] = $entry;
            }
        }
        if (!$images) {
            return null;
        }
        $names = array_keys($images);
        natcasesort($names);
        foreach ($names as $name) {
            if ($images[$name]['method'] !== 0) {
                continue; // compressed — can't decode it, try the next candidate
            }
            $data = MiniRar::readStoredEntry($absolutePath, $name);
            if ($data === null) {
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            return ['data' => $data, 'ext' => $ext === 'jpeg' ? 'jpg' : $ext];
        }
        return null;
    }

    private static function rawImageFile(string $absolutePath): ?array
    {
        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        if (!in_array($ext, self::IMAGE_EXTENSIONS, true) || !is_file($absolutePath)) {
            return null;
        }
        $data = file_get_contents($absolutePath);
        return $data === false ? null : ['data' => $data, 'ext' => $ext === 'jpeg' ? 'jpg' : $ext];
    }

    private static function firstImageInZip(string $absolutePath): ?array
    {
        $entries = MiniZip::listEntries($absolutePath);
        $images = [];
        foreach ($entries as $name) {
            $base = basename($name);
            if ($base === '' || str_starts_with($base, '.')) {
                continue; // skip directories and hidden/system files (e.g. __MACOSX, .DS_Store)
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, self::IMAGE_EXTENSIONS, true)) {
                $images[] = $name;
            }
        }
        if (!$images) {
            return null;
        }
        natcasesort($images);
        $first = reset($images);

        $data = MiniZip::readEntryExact($absolutePath, $first);
        if ($data === null) {
            return null;
        }
        $ext = strtolower(pathinfo($first, PATHINFO_EXTENSION));
        return ['data' => $data, 'ext' => $ext === 'jpeg' ? 'jpg' : $ext];
    }

    private static function epubCover(string $absolutePath): ?array
    {
        $container = MiniZip::readEntryExact($absolutePath, 'META-INF/container.xml');
        if ($container === null) {
            return null;
        }
        $prev = libxml_use_internal_errors(true);
        $containerXml = simplexml_load_string($container);
        libxml_use_internal_errors($prev);
        if ($containerXml === false) {
            return null;
        }
        $opfPath = (string) ($containerXml->rootfiles->rootfile['full-path'] ?? '');
        if ($opfPath === '') {
            return null;
        }

        $opfContent = MiniZip::readEntryExact($absolutePath, $opfPath);
        if ($opfContent === null) {
            return null;
        }
        $prev = libxml_use_internal_errors(true);
        $opf = simplexml_load_string($opfContent);
        libxml_use_internal_errors($prev);
        if ($opf === false) {
            return null;
        }

        $opfDir = trim(dirname($opfPath), '.');
        $opfDir = $opfDir === '' || $opfDir === '/' ? '' : trim($opfDir, '/') . '/';

        // EPUB3: <item properties="cover-image" href="...">
        foreach ($opf->manifest->item ?? [] as $item) {
            $props = (string) ($item['properties'] ?? '');
            if (str_contains($props, 'cover-image')) {
                $href = (string) $item['href'];
                return self::readByHref($absolutePath, $opfDir, $href);
            }
        }

        // EPUB2: <meta name="cover" content="some-manifest-id"> + matching <item id="..." href="...">
        $coverId = null;
        foreach ($opf->metadata->meta ?? [] as $meta) {
            if ((string) ($meta['name'] ?? '') === 'cover') {
                $coverId = (string) $meta['content'];
                break;
            }
        }
        if ($coverId !== null) {
            foreach ($opf->manifest->item ?? [] as $item) {
                if ((string) $item['id'] === $coverId) {
                    $href = (string) $item['href'];
                    return self::readByHref($absolutePath, $opfDir, $href);
                }
            }
        }

        return null;
    }

    private static function readByHref(string $absolutePath, string $opfDir, string $href): ?array
    {
        if ($href === '') {
            return null;
        }
        $data = MiniZip::readEntryExact($absolutePath, $opfDir . $href);
        if ($data === null) {
            return null;
        }
        $ext = strtolower(pathinfo($href, PATHINFO_EXTENSION));
        if (!in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            return null;
        }
        return ['data' => $data, 'ext' => $ext === 'jpeg' ? 'jpg' : $ext];
    }
}
