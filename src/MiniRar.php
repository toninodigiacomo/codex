<?php

declare(strict_types=1);

/**
 * A CBR is a RAR archive, and RAR's compression algorithm is proprietary
 * — there's no equivalent to writing a small pure-PHP decompressor the
 * way MiniZip.php does for ZIP's (open, documented) DEFLATE format.
 * What IS fully readable without it: the RAR5 container format itself
 * (header structure, file names, sizes, compression method per entry)
 * and, when a given entry's compression method is "store" (no
 * compression at all), its raw bytes. In practice that covers a
 * meaningful share of real comic archives — packing an already-JPEG
 * page gains essentially nothing, so many scanning/release groups
 * store images uncompressed specifically to avoid wasting time on it.
 * A STORED entry's bytes are exactly its final bytes; nothing to
 * decompress.
 *
 * Scope, deliberately: RAR5 only (the format WinRAR 5+ has produced
 * since 2013, i.e. what any current archiver creates) — the older RAR4
 * container is a different, unrelated header format and isn't handled.
 * An entry using real compression (method != store) can't be read at
 * all and is skipped; if that happens to be the first page, this
 * returns null rather than a wrong or corrupted image.
 */
final class MiniRar
{
    private const SIGNATURE = "Rar!\x1A\x07\x01\x00";
    private const HEADER_TYPE_FILE = 2;
    private const HEADER_TYPE_END = 5;

    /** @return list<array{name: string, method: int, size: int}> */
    public static function listEntries(string $absolutePath): array
    {
        $data = @file_get_contents($absolutePath);
        if ($data === false || !str_starts_with($data, self::SIGNATURE)) {
            return [];
        }

        $entries = [];
        $pos = strlen(self::SIGNATURE);
        $len = strlen($data);

        while ($pos < $len) {
            $block = self::readBlockAt($data, $pos);
            if ($block === null) {
                break; // malformed or truncated — stop rather than risk an infinite loop
            }
            if ($block['headerType'] === self::HEADER_TYPE_END) {
                break;
            }
            if ($block['headerType'] === self::HEADER_TYPE_FILE && $block['file'] !== null) {
                $entries[] = $block['file'];
            }
            $pos = $block['nextPos'];
        }

        return $entries;
    }

    /** Returns the raw bytes of $name if present AND stored without compression, null otherwise. */
    public static function readStoredEntry(string $absolutePath, string $name): ?string
    {
        $data = @file_get_contents($absolutePath);
        if ($data === false || !str_starts_with($data, self::SIGNATURE)) {
            return null;
        }

        $pos = strlen(self::SIGNATURE);
        $len = strlen($data);

        while ($pos < $len) {
            $block = self::readBlockAt($data, $pos);
            if ($block === null) {
                return null;
            }
            if ($block['headerType'] === self::HEADER_TYPE_END) {
                return null;
            }
            if ($block['headerType'] === self::HEADER_TYPE_FILE && $block['file'] !== null && $block['file']['name'] === $name) {
                if ($block['file']['method'] !== 0) {
                    return null; // compressed — can't decode RAR's proprietary algorithm
                }
                return substr($data, $block['dataStart'], $block['file']['size']);
            }
            $pos = $block['nextPos'];
        }

        return null;
    }

    /**
     * Reads one header block starting at $pos (which must point at the
     * block's leading CRC32). Returns everything needed to move to the
     * next block, plus the parsed file entry when this block is a file
     * header — or null if the data runs out mid-header (truncated/corrupt
     * archive), which callers treat as "stop, don't trust anything further".
     *
     * @return array{headerType: int, file: ?array{name: string, method: int, size: int}, dataStart: int, nextPos: int}|null
     */
    private static function readBlockAt(string $data, int $pos): ?array
    {
        $len = strlen($data);
        if ($pos + 4 > $len) {
            return null;
        }
        $pos += 4; // CRC32 of the header — not verified; a corrupt header fails later reads instead

        $headerSize = self::readVint($data, $pos, $len);
        if ($headerSize === null) {
            return null;
        }
        $headerDataStart = $pos;
        $headerDataEnd = $headerDataStart + $headerSize;
        if ($headerDataEnd > $len) {
            return null;
        }

        $headerType = self::readVint($data, $pos, $len);
        $headerFlags = self::readVint($data, $pos, $len);
        if ($headerType === null || $headerFlags === null) {
            return null;
        }

        $dataSize = 0;
        if ($headerFlags & 0x0001) { // HFL_EXTRA
            if (self::readVint($data, $pos, $len) === null) {
                return null;
            }
        }
        if ($headerFlags & 0x0002) { // HFL_DATA — a data area (the file's actual bytes) follows the header
            $dataSize = self::readVint($data, $pos, $len);
            if ($dataSize === null) {
                return null;
            }
        }

        $file = null;
        if ($headerType === self::HEADER_TYPE_FILE) {
            $fileFlags = self::readVint($data, $pos, $len);
            $unpackedSize = self::readVint($data, $pos, $len);
            $attributes = self::readVint($data, $pos, $len);
            if ($fileFlags === null || $unpackedSize === null || $attributes === null) {
                return null;
            }
            if ($fileFlags & 0x0002) { // has mtime
                $pos += 4;
            }
            if ($fileFlags & 0x0004) { // has data CRC32
                $pos += 4;
            }
            $compressionInfo = self::readVint($data, $pos, $len);
            $hostOs = self::readVint($data, $pos, $len);
            $nameLength = self::readVint($data, $pos, $len);
            if ($compressionInfo === null || $hostOs === null || $nameLength === null || $pos + $nameLength > $len) {
                return null;
            }
            $name = substr($data, $pos, $nameLength);
            $method = ($compressionInfo >> 7) & 0x7; // bits 7-9 of the compression-info vint
            $isDirectory = ($fileFlags & 0x0001) !== 0;
            if (!$isDirectory) {
                $file = ['name' => $name, 'method' => $method, 'size' => $dataSize];
            }
        }

        return [
            'headerType' => $headerType,
            'file' => $file,
            'dataStart' => $headerDataEnd,
            'nextPos' => $headerDataEnd + $dataSize,
        ];
    }

    /** RAR5's variable-length integer: 7 data bits per byte, high bit set means "more bytes follow", little-endian. */
    private static function readVint(string $data, int &$pos, int $len): ?int
    {
        $result = 0;
        $shift = 0;
        while (true) {
            if ($pos >= $len || $shift > 63) {
                return null;
            }
            $byte = ord($data[$pos]);
            $pos++;
            $result |= ($byte & 0x7F) << $shift;
            if (($byte & 0x80) === 0) {
                break;
            }
            $shift += 7;
        }
        return $result;
    }
}
