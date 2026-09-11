<?php

declare(strict_types=1);

/**
 * Reads entries out of a ZIP-format archive (.cbz, .epub, plain .zip)
 * without needing the "zip" PHP extension — that extension wraps libzip
 * and isn't reliably present in the stock php:8.2-apache image (installing
 * it means either a custom Dockerfile or recompiling on every container
 * start, neither of which fits this project). The ZIP container format
 * itself is simple enough to parse by hand; the only real dependency is
 * DEFLATE decompression, which core PHP always has via zlib's
 * gzinflate() — no extension needed for that.
 *
 * Only classic (32-bit) ZIP is supported, which covers effectively every
 * real-world comic/ebook archive; multi-gigabyte ZIP64 files are out of
 * scope for this use case.
 */
final class MiniZip
{
    private const EOCD_SIG = "PK\x05\x06";
    private const CENTRAL_SIG = "PK\x01\x02";
    private const LOCAL_SIG = "PK\x03\x04";
    /** General-purpose flag bit 11 ("language encoding flag / EFS") — tells any other ZIP tool an entry name is UTF-8, not the legacy CP437 assumed otherwise. Only actually matters once an entry name has non-ASCII characters (this app's own renumbered P00001.jpg-style names never do), but costs nothing to set correctly regardless. */
    private const UTF8_FLAG = 0x0800;

    /**
     * Returns the raw (decompressed) content of the first entry whose
     * basename matches $entryBasename (case-insensitive) — e.g. finding
     * "ComicInfo.xml" without knowing/caring what folder it's in. Null if
     * the archive can't be read or contains no such entry.
     */
    public static function readEntry(string $absolutePath, string $entryBasename): ?string
    {
        return self::withCentralDirectory($absolutePath, function ($fh, array $entries) use ($entryBasename) {
            foreach ($entries as $name => $header) {
                if (strcasecmp(basename($name), $entryBasename) === 0) {
                    return self::readEntryData($fh, $header);
                }
            }
            return null;
        });
    }

    /**
     * Returns the raw (decompressed) content of the entry whose full path
     * inside the archive exactly matches $entryPath (case-insensitive,
     * slashes normalized) — for when the basename alone isn't enough to
     * disambiguate (e.g. an EPUB's declared cover file).
     */
    public static function readEntryExact(string $absolutePath, string $entryPath): ?string
    {
        $wanted = strtolower(ltrim(str_replace('\\', '/', $entryPath), '/'));
        return self::withCentralDirectory($absolutePath, function ($fh, array $entries) use ($wanted) {
            foreach ($entries as $name => $header) {
                if (strtolower(ltrim(str_replace('\\', '/', $name), '/')) === $wanted) {
                    return self::readEntryData($fh, $header);
                }
            }
            return null;
        });
    }

    /** Returns every entry's full path inside the archive, in central-directory order, or []. */
    public static function listEntries(string $absolutePath): array
    {
        $result = self::withCentralDirectory($absolutePath, fn($fh, array $entries) => array_keys($entries));
        return $result ?? [];
    }

    /**
     * Reads every entry's raw (decompressed) content in one pass — what
     * CbzEditor needs to rebuild an archive, since MiniZip::write() takes
     * a full name => content map rather than reading lazily one entry at
     * a time. An entry MiniZip can't decompress (an unsupported method)
     * is simply omitted rather than failing the whole read — the caller
     * treats a missing expected entry as its own error condition.
     * @return array<string, string>|null name => raw content, in
     *         central-directory order, or null if the archive itself
     *         couldn't be opened/parsed at all.
     */
    public static function readAllEntries(string $absolutePath): ?array
    {
        return self::withCentralDirectory($absolutePath, function ($fh, array $entries) {
            $result = [];
            foreach ($entries as $name => $header) {
                $data = self::readEntryData($fh, $header);
                if ($data !== null) {
                    $result[$name] = $data;
                }
            }
            return $result;
        });
    }

    /**
     * Opens the archive, locates and parses its central directory once,
     * and hands the file handle + a [name => header] map to $callback —
     * shared setup for all the read operations above.
     */
    private static function withCentralDirectory(string $absolutePath, callable $callback)
    {
        $fh = @fopen($absolutePath, 'rb');
        if ($fh === false) {
            return null;
        }
        try {
            $size = filesize($absolutePath);
            if ($size === false || $size < 22) {
                return null;
            }
            $eocd = self::findEndOfCentralDirectory($fh, $size);
            if ($eocd === null) {
                return null;
            }
            $entries = self::readCentralDirectory($fh, $eocd);
            if ($entries === null) {
                return null;
            }
            return $callback($fh, $entries);
        } finally {
            fclose($fh);
        }
    }

    private static function findEndOfCentralDirectory($fh, int $size): ?array
    {
        $windowSize = min($size, 65557); // EOCD (22 bytes) + max comment length (65535)
        fseek($fh, -$windowSize, SEEK_END);
        $tail = fread($fh, $windowSize);
        if ($tail === false) {
            return null;
        }

        $pos = strrpos($tail, self::EOCD_SIG);
        if ($pos === false) {
            return null;
        }

        $record = substr($tail, $pos, 22);
        if (strlen($record) < 22) {
            return null;
        }

        $fields = unpack(
            'Vsig/vdisk/vcdDisk/ventriesDisk/ventries/VcdSize/VcdOffset/vcommentLen',
            $record
        );
        return $fields === false ? null : $fields;
    }

    /** @return array<string, array>|null map of entry name => central directory header fields */
    private static function readCentralDirectory($fh, array $eocd): ?array
    {
        fseek($fh, $eocd['cdOffset']);
        $cd = fread($fh, $eocd['cdSize']);
        if ($cd === false) {
            return null;
        }

        $entries = [];
        $pos = 0;
        for ($i = 0; $i < $eocd['entries']; $i++) {
            if (substr($cd, $pos, 4) !== self::CENTRAL_SIG) {
                break;
            }
            $header = unpack(
                'Vsig/vverMade/vverNeed/vflag/vmethod/vtime/vdate/Vcrc/VcompSize/VuncompSize/' .
                'vnameLen/vextraLen/vcommentLen/vdiskStart/vintAttr/VextAttr/VlocalOffset',
                substr($cd, $pos, 46)
            );
            if ($header === false) {
                return null;
            }
            $nameStart = $pos + 46;
            $name = substr($cd, $nameStart, $header['nameLen']);
            $entries[$name] = $header;
            $pos = $nameStart + $header['nameLen'] + $header['extraLen'] + $header['commentLen'];
        }
        return $entries;
    }

    private static function readEntryData($fh, array $entry): ?string
    {
        fseek($fh, $entry['localOffset']);
        $localHeader = fread($fh, 30);
        if ($localHeader === false || substr($localHeader, 0, 4) !== self::LOCAL_SIG) {
            return null;
        }
        $lf = unpack('vnameLen/vextraLen', substr($localHeader, 26, 4));
        if ($lf === false) {
            return null;
        }
        $dataOffset = $entry['localOffset'] + 30 + $lf['nameLen'] + $lf['extraLen'];

        fseek($fh, $dataOffset);
        $raw = fread($fh, $entry['compSize']);
        if ($raw === false) {
            return null;
        }

        return match ($entry['method']) {
            0 => $raw, // stored, no compression
            8 => (static function () use ($raw) {
                $data = @gzinflate($raw);
                return $data === false ? null : $data;
            })(),
            default => null, // unsupported compression method
        };
    }

    /**
     * Writes a brand-new ZIP archive to $outputPath containing exactly
     * $entries (name => raw content), in that order — never touches an
     * existing file, including whatever $outputPath's final destination
     * might be; the caller (CbzEditor::save()) is the one responsible for
     * atomically replacing an original only once this has fully
     * succeeded, so a failure here can never leave a half-written or
     * corrupted archive in the file a reader would actually open.
     *
     * Every entry is stored (method 0, no compression) rather than
     * deflated — comic pages are already-compressed JPEG/PNG, so DEFLATE
     * would save little to nothing on them anyway, and this avoids
     * needing a hand-written compressor here at all: one clear format to
     * get right (store) instead of two. This matches how a meaningful
     * share of real-world scan releases already package their pages
     * uncompressed for exactly this reason.
     */
    public static function write(string $outputPath, array $entries): bool
    {
        $fh = @fopen($outputPath, 'wb');
        if ($fh === false) {
            return false;
        }
        try {
            [$dosTime, $dosDate] = self::dosDateTime();
            $central = [];
            foreach ($entries as $name => $data) {
                $offset = ftell($fh);
                if ($offset === false) {
                    return false;
                }
                $crc = crc32($data);
                $size = strlen($data);
                $local = pack(
                    'VvvvvvVVVvv',
                    0x04034b50, 20, self::UTF8_FLAG, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0
                );
                if (fwrite($fh, $local . $name . $data) === false) {
                    return false;
                }
                $central[] = ['name' => $name, 'crc' => $crc, 'size' => $size, 'offset' => $offset];
            }

            $cdStart = ftell($fh);
            foreach ($central as $rec) {
                $header = pack(
                    'VvvvvvvVVVvvvvvVV',
                    0x02014b50, 20, 20, self::UTF8_FLAG, 0, $dosTime, $dosDate,
                    $rec['crc'], $rec['size'], $rec['size'], strlen($rec['name']),
                    0, 0, 0, 0, 0, $rec['offset']
                );
                if (fwrite($fh, $header . $rec['name']) === false) {
                    return false;
                }
            }
            $cdSize = ftell($fh) - $cdStart;
            $eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, count($central), count($central), $cdSize, $cdStart, 0);
            return fwrite($fh, $eocd) !== false;
        } finally {
            fclose($fh);
        }
    }

    /** @return array{0: int, 1: int} [DOS time, DOS date] for the local/central headers above — the archive's own internal timestamps, unrelated to the .cbz file's own filesystem mtime. */
    private static function dosDateTime(): array
    {
        $t = getdate();
        $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
        $dosDate = (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
        return [$dosTime, $dosDate];
    }
}
