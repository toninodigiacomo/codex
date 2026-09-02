<?php

declare(strict_types=1);

require_once __DIR__ . '/MiniZip.php';
require_once __DIR__ . '/MiniRar.php';
require_once __DIR__ . '/PdfRenderer.php';
require_once __DIR__ . '/Paths.php';

/**
 * Page-by-page access for the embedded reader, across every
 * image-based format the library supports — a comic archive (.cbz/.cbr),
 * a scanned PDF, or a single standalone image treated as a one-page
 * item. EPUB isn't included here: reflowable text needs a genuinely
 * different reading UI (chapters, a table of contents, re-flowing
 * text), not a page-image viewer — out of scope for this reader.
 *
 * A .cbr page only exists if MiniRar can actually read it (the archive
 * stored it without RAR's own compression) — see MiniRar.php. Nothing
 * here pretends otherwise: a page it can't read is just missing.
 */
final class ItemPages
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public static function count(array $item): int
    {
        $absPath = Paths::resolve($item['path']);
        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));

        if ($ext === 'pdf') {
            return PdfRenderer::pageCount($absPath);
        }
        if ($ext === 'cbr') {
            return count(self::sortedImageNamesInRar($absPath));
        }
        if (in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            return 1;
        }
        if ($ext === 'cbz') {
            return count(self::sortedImageNamesInZip($absPath));
        }
        return 0; // .epub and anything else aren't page-image formats this reader handles
    }

    /** @return array{data: string, ext: string}|null */
    public static function page(array $item, int $index): ?array
    {
        if ($index < 0) {
            return null;
        }
        $absPath = Paths::resolve($item['path']);
        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));

        if ($ext === 'pdf') {
            $data = PdfRenderer::renderPage($absPath, $index);
            return $data === null ? null : ['data' => $data, 'ext' => 'jpg'];
        }
        if ($ext === 'cbr') {
            $names = self::sortedImageNamesInRar($absPath);
            if (!isset($names[$index])) {
                return null;
            }
            $data = MiniRar::readStoredEntry($absPath, $names[$index]);
            if ($data === null) {
                return null;
            }
            return ['data' => $data, 'ext' => self::extOf($names[$index])];
        }
        if (in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            if ($index !== 0) {
                return null;
            }
            $data = @file_get_contents($absPath);
            return $data === false ? null : ['data' => $data, 'ext' => $ext === 'jpeg' ? 'jpg' : $ext];
        }
        if ($ext !== 'cbz') {
            return null; // .epub and anything else aren't page-image formats this reader handles
        }

        $names = self::sortedImageNamesInZip($absPath);
        if (!isset($names[$index])) {
            return null;
        }
        $data = MiniZip::readEntryExact($absPath, $names[$index]);
        if ($data === null) {
            return null;
        }
        return ['data' => $data, 'ext' => self::extOf($names[$index])];
    }

    /** @return list<string> */
    private static function sortedImageNamesInZip(string $absPath): array
    {
        $names = [];
        foreach (MiniZip::listEntries($absPath) as $name) {
            $base = basename($name);
            if ($base === '' || str_starts_with($base, '.')) {
                continue;
            }
            if (in_array(self::extOf($name), self::IMAGE_EXTENSIONS, true)) {
                $names[] = $name;
            }
        }
        natcasesort($names);
        return array_values($names);
    }

    /** @return list<string> */
    private static function sortedImageNamesInRar(string $absPath): array
    {
        $names = [];
        foreach (MiniRar::listEntries($absPath) as $entry) {
            $base = basename($entry['name']);
            if ($base === '' || str_starts_with($base, '.')) {
                continue;
            }
            if ($entry['method'] !== 0) {
                continue; // compressed — MiniRar can't read it, so it can't be a page either
            }
            if (in_array(self::extOf($entry['name']), self::IMAGE_EXTENSIONS, true)) {
                $names[] = $entry['name'];
            }
        }
        natcasesort($names);
        return array_values($names);
    }

    private static function extOf(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return $ext === 'jpeg' ? 'jpg' : $ext;
    }
}
