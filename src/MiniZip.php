<?php

declare(strict_types=1);

/**
 * Reads a single named entry out of a ZIP-format archive (.cbz, .epub,
 * plain .zip) without needing the "zip" PHP extension — that extension
 * wraps libzip and isn't reliably present in the stock php:8.2-apache
 * image (installing it means either a custom Dockerfile or recompiling
 * on every container start, neither of which fits this project). The ZIP
 * container format itself is simple enough to parse by hand; the only
 * real dependency is DEFLATE decompression, which core PHP always has via
 * zlib's gzinflate() — no extension needed for that.
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

    /**
     * Returns the raw (decompressed) content of the first entry whose
     * basename matches $entryBasename (case-insensitive), or null if the
     * archive can't be read or contains no such entry.
     */
    public static function readEntry(string $absolutePath, string $entryBasename): ?string
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

            $entry = self::findCentralDirectoryEntry($fh, $eocd, $entryBasename);
            if ($entry === null) {
                return null;
            }

            return self::readEntryData($fh, $entry);
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

    private static function findCentralDirectoryEntry($fh, array $eocd, string $entryBasename): ?array
    {
        fseek($fh, $eocd['cdOffset']);
        $cd = fread($fh, $eocd['cdSize']);
        if ($cd === false) {
            return null;
        }

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
            if (strcasecmp(basename($name), $entryBasename) === 0) {
                return $header;
            }
            $pos = $nameStart + $header['nameLen'] + $header['extraLen'] + $header['commentLen'];
        }
        return null;
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
}
