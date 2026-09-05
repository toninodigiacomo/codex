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
 *
 * Reads via a file handle (fseek/fread), never file_get_contents() on
 * the whole archive — a header itself is always tiny (well under a
 * kilobyte), it's the *file data* that follows each one that can be
 * huge, and header traversal only needs to know how many bytes to skip
 * over that data, never read it. A multi-hundred-MB .cbr loaded whole
 * just to list its entries or pull out one page's bytes is exactly what
 * blew through this app's memory_limit — a real PHP fatal that no
 * try/catch can recover from — before this was rewritten around a
 * stream instead.
 */
final class MiniRar
{
    private const SIGNATURE = "Rar!\x1A\x07\x01\x00";
    private const HEADER_TYPE_FILE = 2;
    private const HEADER_TYPE_END = 5;
    private const MAX_ENTRY_SIZE = 200_000_000; // ~190MB — no real single comic page needs anywhere near this; guards fread() against a corrupt/malicious size claim the same way the header-size check above guards header parsing

    /** @return list<array{name: string, method: int, size: int}> */
    public static function listEntries(string $absolutePath): array
    {
        $handle = self::openValid($absolutePath);
        if ($handle === null) {
            return [];
        }
        try {
            $entries = [];
            $pos = strlen(self::SIGNATURE);
            while (true) {
                $block = self::readBlockAt($handle, $pos);
                if ($block === null || $block['headerType'] === self::HEADER_TYPE_END) {
                    break;
                }
                if ($block['headerType'] === self::HEADER_TYPE_FILE && $block['file'] !== null) {
                    $entries[] = $block['file'];
                }
                $pos = $block['nextPos'];
            }
            return $entries;
        } finally {
            fclose($handle);
        }
    }

    /** Returns the raw bytes of $name if present AND stored without compression, null otherwise. */
    public static function readStoredEntry(string $absolutePath, string $name): ?string
    {
        $handle = self::openValid($absolutePath);
        if ($handle === null) {
            return null;
        }
        try {
            $pos = strlen(self::SIGNATURE);
            while (true) {
                $block = self::readBlockAt($handle, $pos);
                if ($block === null || $block['headerType'] === self::HEADER_TYPE_END) {
                    return null;
                }
                if ($block['headerType'] === self::HEADER_TYPE_FILE && $block['file'] !== null && $block['file']['name'] === $name) {
                    if ($block['file']['method'] !== 0) {
                        return null; // compressed — can't decode RAR's proprietary algorithm
                    }
                    if ($block['file']['size'] < 0 || $block['file']['size'] > self::MAX_ENTRY_SIZE) {
                        return null; // a corrupt/malicious size claim — no real single comic page needs this much
                    }
                    if (fseek($handle, $block['dataStart']) !== 0) {
                        return null;
                    }
                    $data = fread($handle, $block['file']['size']);
                    return $data === false ? null : $data;
                }
                $pos = $block['nextPos'];
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return resource|null */
    private static function openValid(string $absolutePath)
    {
        $handle = @fopen($absolutePath, 'rb');
        if ($handle === false) {
            return null;
        }
        $signature = fread($handle, strlen(self::SIGNATURE));
        if ($signature !== self::SIGNATURE) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    /**
     * Reads one header block starting at $pos (which must point at the
     * block's leading CRC32). Returns everything needed to move to the
     * next block, plus the parsed file entry when this block is a file
     * header — or null if the stream runs out mid-header (truncated/corrupt
     * archive, or a genuine EOF at a block boundary), which callers treat
     * as "stop, don't trust anything further".
     *
     * The header's own declared size ($headerSize below) bounds every read
     * here to a handful of bytes — never the file data that follows it,
     * which is skipped over via $nextPos without ever being read at all
     * unless a caller specifically asks for one entry's bytes afterward.
     *
     * @param resource $handle
     * @return array{headerType: int, file: ?array{name: string, method: int, size: int}, dataStart: int, nextPos: int}|null
     */
    private static function readBlockAt($handle, int $pos): ?array
    {
        $pos += 4; // CRC32 of the header — not verified; a corrupt header fails later reads instead
        if (fseek($handle, $pos) !== 0) {
            return null;
        }

        $headerSize = self::readVintFromStream($handle, $pos);
        if ($headerSize === null || $headerSize <= 0 || $headerSize > 65536) {
            return null; // a sane RAR5 header is at most a few hundred bytes; anything wildly larger means a corrupt/truncated file, not a real header to trust
        }
        $headerDataStart = $pos;
        $headerDataEnd = $headerDataStart + $headerSize;

        if (fseek($handle, $headerDataStart) !== 0) {
            return null;
        }
        $header = fread($handle, $headerSize);
        if ($header === false || strlen($header) !== $headerSize) {
            return null; // truncated — the file ends mid-header
        }

        $hPos = 0;
        $hLen = $headerSize;
        $headerType = self::readVint($header, $hPos, $hLen);
        $headerFlags = self::readVint($header, $hPos, $hLen);
        if ($headerType === null || $headerFlags === null) {
            return null;
        }

        $dataSize = 0;
        if ($headerFlags & 0x0001) { // HFL_EXTRA
            if (self::readVint($header, $hPos, $hLen) === null) {
                return null;
            }
        }
        if ($headerFlags & 0x0002) { // HFL_DATA — a data area (the file's actual bytes) follows the header
            $dataSize = self::readVint($header, $hPos, $hLen);
            if ($dataSize === null) {
                return null;
            }
        }

        $file = null;
        if ($headerType === self::HEADER_TYPE_FILE) {
            $fileFlags = self::readVint($header, $hPos, $hLen);
            $unpackedSize = self::readVint($header, $hPos, $hLen);
            $attributes = self::readVint($header, $hPos, $hLen);
            if ($fileFlags === null || $unpackedSize === null || $attributes === null) {
                return null;
            }
            if ($fileFlags & 0x0002) { // has mtime
                $hPos += 4;
            }
            if ($fileFlags & 0x0004) { // has data CRC32
                $hPos += 4;
            }
            $compressionInfo = self::readVint($header, $hPos, $hLen);
            $hostOs = self::readVint($header, $hPos, $hLen);
            $nameLength = self::readVint($header, $hPos, $hLen);
            if ($compressionInfo === null || $hostOs === null || $nameLength === null || $hPos + $nameLength > $hLen) {
                return null;
            }
            $name = substr($header, $hPos, $nameLength);
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

    /**
     * Same vint format as readVint() below, read one byte at a time
     * directly off the stream — used only for a header's own leading
     * size field, before we know how many bytes the header even is (and
     * so can't read it into a buffer yet). At most ~10 bytes either way.
     * @param resource $handle
     */
    private static function readVintFromStream($handle, int &$pos): ?int
    {
        $result = 0;
        $shift = 0;
        while (true) {
            if ($shift > 63) {
                return null;
            }
            $byte = fread($handle, 1);
            if ($byte === false || $byte === '') {
                return null;
            }
            $pos++;
            $b = ord($byte);
            $result |= ($b & 0x7F) << $shift;
            if (($b & 0x80) === 0) {
                break;
            }
            $shift += 7;
        }
        return $result;
    }

    /** RAR5's variable-length integer: 7 data bits per byte, high bit set means "more bytes follow", little-endian. Operates on an in-memory buffer — used for a header's own (already fully read, and always small) bytes, never the archive as a whole. */
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
