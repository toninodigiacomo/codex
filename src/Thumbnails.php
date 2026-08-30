<?php

declare(strict_types=1);

/**
 * Turns raw image bytes (pulled from inside a .cbz/.epub, or an uploaded
 * photo) into a reasonably-sized JPEG saved under public/assets/covers/,
 * so the library grid isn't loading full-resolution scans for every tile.
 * Uses GD — not bundled by default in php:8.2-apache either, checked for
 * and installed at container start the same way as pdo_sqlite/simplexml.
 */
final class Thumbnails
{
    private const MAX_WIDTH = 480;
    private const JPEG_QUALITY = 82;

    public static function available(): bool
    {
        return function_exists('imagecreatefromstring');
    }

    /**
     * Resizes $imageData (raw bytes of any GD-supported format) down to
     * MAX_WIDTH if wider, saves it as a JPEG at $destAbsolutePath
     * (creating the destination folder if needed), and returns true on
     * success.
     */
    public static function saveResized(string $imageData, string $destAbsolutePath): bool
    {
        if (!self::available()) {
            return false;
        }

        $src = @imagecreatefromstring($imageData);
        if ($src === false) {
            return false;
        }

        $width = imagesx($src);
        $height = imagesy($src);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($src);
            return false;
        }

        if ($width > self::MAX_WIDTH) {
            $newWidth = self::MAX_WIDTH;
            $newHeight = (int) round($height * ($newWidth / $width));
            $dst = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($src);
        } else {
            $dst = $src;
        }

        $dir = dirname($destAbsolutePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            imagedestroy($dst);
            return false;
        }

        $ok = imagejpeg($dst, $destAbsolutePath, self::JPEG_QUALITY);
        imagedestroy($dst);
        return $ok;
    }
}
