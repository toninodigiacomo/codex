<?php

declare(strict_types=1);

/**
 * PDF image extraction, scoped deliberately narrow: finds
 * JPEG-compressed image objects in the file and returns their raw
 * bytes as-is (a JPEG stream needs no decoding — the /DCTDecode filter
 * *is* plain JPEG data).
 *
 * This is not a PDF parser. A correct one would resolve the object
 * graph (cross-reference table or stream, indirect references, object
 * streams) to walk pages in their real, guaranteed order — genuinely
 * substantial to build correctly, on the order of MiniZip.php but
 * considerably harder given how much more PDF's structure varies.
 * Instead this scans the raw bytes for every `<< ... >> stream ...
 * endstream` block whose dictionary declares `/Subtype /Image` and a
 * `/DCTDecode` filter, wherever in the file that happens to be, and
 * treats them as the pages in the order found.
 *
 * That's a real trade-off, not a full solution: it doesn't strictly
 * guarantee file order matches reading order (in practice it reliably
 * does — scanners and scan-to-PDF tools emit objects in page order),
 * and it only recognizes JPEG-compressed images — a PDF using
 * FlateDecode (raw/PNG-style pixel data), JBIG2Decode or CCITTFaxDecode
 * (common for monochrome/fax-style scanned text pages) yields nothing.
 * That covers the common case this exists for — scanned comics/books
 * saved as JPEG pages — without the size and risk of a real PDF engine
 * (Ghostscript/Imagick), consistent with every other extraction choice
 * in this codebase. Never throws: anything it can't confidently handle
 * returns null or an empty list.
 */
final class PdfCoverExtractor
{
    private const COVER_SCAN_BYTES = 8 * 1024 * 1024; // page 1 lives well within this on any normally-structured PDF
    private const FULL_SCAN_BYTES = 400 * 1024 * 1024; // a generous ceiling for reading the whole book — home-server scale, not a public service

    public static function firstImage(string $absolutePath): ?array
    {
        $data = self::readBytes($absolutePath, self::COVER_SCAN_BYTES);
        if ($data === null) {
            return null;
        }
        $ranges = self::findImageRanges($data, 1);
        if (!$ranges) {
            return null;
        }
        return ['data' => substr($data, $ranges[0][0], $ranges[0][1]), 'ext' => 'jpg'];
    }

    public static function pageCount(string $absolutePath): int
    {
        $data = self::readBytes($absolutePath, self::FULL_SCAN_BYTES);
        if ($data === null) {
            return 0;
        }
        return count(self::findImageRanges($data));
    }

    /** @return array{data: string, ext: string}|null */
    public static function page(string $absolutePath, int $index): ?array
    {
        $data = self::readBytes($absolutePath, self::FULL_SCAN_BYTES);
        if ($data === null) {
            return null;
        }
        $ranges = self::findImageRanges($data, $index + 1);
        if (!isset($ranges[$index])) {
            return null;
        }
        return ['data' => substr($data, $ranges[$index][0], $ranges[$index][1]), 'ext' => 'jpg'];
    }

    private static function readBytes(string $absolutePath, int $maxBytes): ?string
    {
        $fh = @fopen($absolutePath, 'rb');
        if ($fh === false) {
            return null;
        }
        try {
            $size = filesize($absolutePath);
            if ($size === false || $size < 10) {
                return null;
            }
            $data = fread($fh, min($size, $maxBytes));
            return $data === false ? null : $data;
        } finally {
            fclose($fh);
        }
    }

    /**
     * Scans $data for JPEG-compressed image streams, stopping early
     * once $limit have been found (pass null for "find them all").
     * @return list<array{0: int, 1: int}> [byteOffset, byteLength] pairs, in the order found
     */
    private static function findImageRanges(string $data, ?int $limit = null): array
    {
        $ranges = [];
        $searchFrom = 0;
        while (($streamPos = strpos($data, 'stream', $searchFrom)) !== false) {
            $dictStart = max(0, $streamPos - 2000);
            $dict = substr($data, $dictStart, $streamPos - $dictStart);
            $isImage = preg_match('/\/Subtype\s*\/Image\b/', $dict) === 1;
            $isJpeg = preg_match('/\/Filter\s*(?:\[[^\]]*?)?\/DCTDecode\b/', $dict) === 1;

            if ($isImage && $isJpeg) {
                $dataStart = $streamPos + strlen('stream');
                if (substr($data, $dataStart, 2) === "\r\n") {
                    $dataStart += 2;
                } elseif (in_array(substr($data, $dataStart, 1), ["\n", "\r"], true)) {
                    $dataStart += 1;
                }
                $endPos = strpos($data, 'endstream', $dataStart);
                if ($endPos !== false) {
                    $length = $endPos - $dataStart;
                    // trim a trailing CR/LF that precedes "endstream" without shifting the start
                    while ($length > 0 && in_array($data[$dataStart + $length - 1], ["\r", "\n"], true)) {
                        $length--;
                    }
                    if ($length >= 2 && substr($data, $dataStart, 2) === "\xFF\xD8") { // sanity-check: real JPEG data starts with this marker
                        $ranges[] = [$dataStart, $length];
                        if ($limit !== null && count($ranges) >= $limit) {
                            break;
                        }
                    }
                }
            }
            $searchFrom = $streamPos + 6;
        }
        return $ranges;
    }
}
