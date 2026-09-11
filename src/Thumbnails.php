<?php

declare(strict_types=1);

require_once __DIR__ . '/Settings.php';

/**
 * Resizes cover art down to a size that actually makes sense for a grid
 * tile, via GD — installed at container startup (see compose.yml, same
 * `docker-php-ext-install` pattern already used there for pdo_sqlite and
 * simplexml) rather than baked into a custom image, so it follows the
 * same reliability trade-off already accepted for those: a package
 * mirror being briefly unreachable on some reboot means thumbnails
 * temporarily fall back to full-resolution covers, never a broken app.
 *
 * Every write path in here degrades the same way on any failure —
 * GD missing, a corrupt image, an unsupported format GD can't decode —
 * falling back to saving the original bytes untouched. A cover that's
 * merely bigger than ideal is always preferable to no cover at all.
 */
final class Thumbnails
{
    private const JPEG_QUALITY = 82;
    private const MAX_SOURCE_PIXELS = 220_000_000; // ~ a 14800×14800 image — see the note in resize() below

    public static function available(): bool
    {
        return extension_loaded('gd');
    }

    /**
     * Saves $imageData under $destDir as "$baseName.<ext>" — a resized
     * JPEG when GD is available and can decode the image, the original
     * bytes under $originalExt otherwise. Returns the extension actually
     * written (the caller needs this for cover_path — a resized image is
     * always a .jpg regardless of what it started as), or null on failure.
     */
    public static function save(string $imageData, string $destDir, string $baseName, string $originalExt): ?string
    {
        if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
            return null;
        }
        if (self::available()) {
            $resized = self::resize($imageData);
            if ($resized !== null) {
                return file_put_contents($destDir . '/' . $baseName . '.jpg', $resized) !== false ? 'jpg' : null;
            }
        }
        return file_put_contents($destDir . '/' . $baseName . '.' . $originalExt, $imageData) !== false ? $originalExt : null;
    }

    /**
     * Reads $absPath off disk and returns it resized as JPEG bytes, or
     * null if GD is unavailable or can't make sense of the file — the
     * caller's own fallback (serving the file as-is) takes over either
     * way. Used for folder.jpg-style thumbnails, which — unlike extracted
     * covers — live in read-only library folders and can't be resized
     * once and saved next to the source; the caller is expected to cache
     * the result itself.
     */
    public static function resizeFile(string $absPath): ?string
    {
        if (!self::available()) {
            return null;
        }
        $data = @file_get_contents($absPath);
        return $data === false ? null : self::resize($data);
    }

    /** @return string|null resized JPEG bytes, or null if GD is unavailable or couldn't decode $imageData */
    private static function resize(string $imageData): ?string
    {
        if (!self::available()) {
            return null;
        }

        // getimagesize() reads just the header, not the pixel data — cheap
        // enough to check before committing to a full decode, which is what
        // actually costs memory (roughly width × height × 4 bytes for GD's
        // internal truecolor buffer). A genuinely huge source can exceed
        // even a generous memory_limit and crash the whole request in a way
        // no try/catch can recover from — a real PHP memory-exhaustion fatal
        // isn't catchable. Skipping the resize for that one image (falling
        // back to saving it as-is, same as GD being unavailable) is what
        // keeps one oversized cover from taking an entire batch down.
        $info = @getimagesizefromstring($imageData);
        if ($info === false || $info[0] * $info[1] > self::MAX_SOURCE_PIXELS) {
            return null;
        }

        // Real scanned comic covers can occasionally be enormous (a real
        // one that prompted this: 10335×13787, ~142.5 MP) — this cap exists
        // to catch these safely, not to reject them outright. GD's own
        // truecolor buffer for the *decode* alone is ~4 bytes/pixel, plus
        // meaningful additional overhead libjpeg needs while decoding
        // (roughly another 1.5-2x on top of the final buffer size, in
        // practice) — raising the PHP-wide memory_limit permanently for
        // every request just to cover this one rare case would be the
        // wrong trade-off. ini_set() only affects the current request, so
        // this bump disappears again the moment this request ends; a
        // request already running under a higher limit (set some other
        // way) never gets lowered by this, since it only ever raises.
        $neededBytes = (int) ($info[0] * $info[1] * 4 * 1.8) + 67_108_864;
        $currentLimitBytes = self::iniBytes((string) ini_get('memory_limit'));
        if ($currentLimitBytes > 0 && $currentLimitBytes < $neededBytes) {
            @ini_set('memory_limit', (string) $neededBytes);
        }

        $image = @imagecreatefromstring($imageData);
        if ($image === false) {
            return null;
        }
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($image);
            return null;
        }

        // "Contain" within the configured box — scale by whichever axis is
        // more constraining for this particular image's own aspect ratio,
        // never upscale a cover that's already smaller than the box. The
        // display side (library.css) crops to fill its tile via
        // object-fit: cover, so the result doesn't need to exactly match
        // the box's own aspect ratio, just fit within it.
        $targetWidth = Settings::thumbnailWidth();
        $targetHeight = Settings::thumbnailHeight();
        $scale = min($targetWidth / $width, $targetHeight / $height, 1.0);
        if ($scale < 1.0) {
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));
            $canvas = imagecreatetruecolor($newWidth, $newHeight);
            // Flattens any transparency onto white first — a comic cover is
            // never meaningfully transparent, and a JPEG can't hold alpha
            // anyway; without this, a transparent source renders black.
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $canvas;
        }

        ob_start();
        $written = imagejpeg($image, null, self::JPEG_QUALITY);
        $data = ob_get_clean();
        imagedestroy($image);
        return $written && $data !== false ? $data : null;
    }

    /** Parses a php.ini-style size string ("512M", "1G", "-1" for unlimited) into a byte count. Returns -1 unchanged for "unlimited" — the caller's own "$current < $needed" check already treats that as "always enough" without special-casing it. */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;
        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
