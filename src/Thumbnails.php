<?php

declare(strict_types=1);

/**
 * Writes extracted cover bytes straight to disk, at their original
 * resolution and format — no resizing.
 *
 * This deliberately doesn't use GD. Resizing would need it, and unlike
 * the ZIP-reading problem (MiniZip.php) — where the format itself was
 * simple enough to reimplement in pure PHP — GD has no such shortcut:
 * getting it working means compiling it against zlib/libpng/libjpeg dev
 * headers that the base php:8.2-apache image doesn't ship, which in turn
 * means network access and a compiler at *every container recreation*
 * (the compiled extension lives in the container's writable layer, which
 * doesn't survive `docker compose down && up`, only a plain restart) —
 * a real reliability cost for a "nice to have". Serving full-resolution
 * cover art costs a bit more bandwidth per grid tile; on a personal home
 * server that's a fine trade for never having a working feature turn into
 * a broken one because a package mirror was unreachable on some reboot.
 */
final class Thumbnails
{
    public static function available(): bool
    {
        return true; // no dependency to check for anymore
    }

    /** Writes $imageData to $destAbsolutePath as-is, creating the destination folder if needed. */
    public static function save(string $imageData, string $destAbsolutePath): bool
    {
        $dir = dirname($destAbsolutePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        return file_put_contents($destAbsolutePath, $imageData) !== false;
    }
}
