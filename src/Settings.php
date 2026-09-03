<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

final class Settings
{
    private const SMTP_KEYS = ['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'smtp_from_email', 'smtp_from_name'];

    public static function get(string $key, ?string $default = null): ?string
    {
        $stmt = Database::connection()->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : $value;
    }

    public static function set(string $key, ?string $value): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO settings (key, value) VALUES (:key, :value)
             ON CONFLICT(key) DO UPDATE SET value = :value'
        );
        $stmt->execute([':key' => $key, ':value' => $value]);
    }

    /** @return array<string, ?string> */
    public static function smtpConfig(): array
    {
        $config = [];
        foreach (self::SMTP_KEYS as $key) {
            $config[$key] = self::get($key);
        }
        return $config;
    }

    public static function setSmtpConfig(array $fields): void
    {
        foreach (self::SMTP_KEYS as $key) {
            if (array_key_exists($key, $fields)) {
                $value = trim((string) $fields[$key]);
                self::set($key, $value === '' ? null : $value);
            }
        }
    }

    public static function isSmtpConfigured(): bool
    {
        $config = self::smtpConfig();
        return !empty($config['smtp_host']) && !empty($config['smtp_from_email']);
    }

    /** The base URL used to build invite links — set once, or guessed from the current request. */
    public static function siteUrl(): string
    {
        $stored = self::get('site_url');
        if ($stored) {
            return rtrim($stored, '/');
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return "$scheme://$host";
    }

    /** A shared secret for triggering a sync from outside a browser session (an external cron job, etc.) — generated on first use. */
    public static function syncToken(): string
    {
        $token = self::get('sync_api_token');
        if (!$token) {
            $token = bin2hex(random_bytes(24));
            self::set('sync_api_token', $token);
        }
        return $token;
    }

    public static function regenerateSyncToken(): string
    {
        $token = bin2hex(random_bytes(24));
        self::set('sync_api_token', $token);
        return $token;
    }

    /**
     * A PCRE pattern (delimiters included, e.g. "/foo/i") checked against
     * every file and folder *basename* during a library scan — a match
     * means "skip this, don't index it". Covers per-folder sidecar files
     * other library tools leave behind (Ubooquity's folder.jpg, header.jpg,
     * folder.css, folder-info.html...), which aren't content themselves.
     * Note this is separate from — and in addition to — the always-on
     * skip for any name starting with "." (macOS's own "._*" AppleDouble
     * files, .DS_Store, etc.), which needs no configuration since it's
     * never legitimate content either way.
     */
    public static function scanExcludePattern(): string
    {
        return self::get('scan_exclude_pattern') ?? self::defaultScanExcludePattern();
    }

    public static function defaultScanExcludePattern(): string
    {
        return '/^(folder|cover|header)\.(jpg|jpeg|png|webp)$|^folder-info\.html$|^folder\.css$/i';
    }

    /**
     * @return string|null an error message if $pattern isn't a valid PCRE
     * pattern, or null if it's fine to save.
     */
    public static function validateScanExcludePattern(string $pattern): ?string
    {
        if (trim($pattern) === '') {
            return null; // empty is allowed — falls back to the default at read time
        }
        $prev = set_error_handler(fn() => true); // swallow the E_WARNING preg_match emits on a bad pattern; we check the return value instead
        $result = @preg_match($pattern, '');
        set_error_handler($prev);
        return $result === false ? "Expression régulière invalide (vérifie les délimiteurs, ex. /motif/i)" : null;
    }

    public static function setScanExcludePattern(string $pattern): void
    {
        $pattern = trim($pattern);
        self::set('scan_exclude_pattern', $pattern === '' ? null : $pattern);
    }

    /**
     * Whether the reader nav exposes browsing by Bibliothèque/Éditeur/Collection —
     * derived from folder structure (Éditeur/Collection/Tome...), not
     * metadata. Global, not per-library, matching every other setting
     * in this file — a library-by-library version would need its own
     * UI and storage shape for comparatively little gain, since a
     * reader only ever sees this through the type-level nav tab anyway,
     * already spanning every library that shares that type.
     */
    public static function showPublishers(): bool
    {
        return self::get('show_publishers') === '1';
    }

    public static function setShowPublishers(bool $value): void
    {
        self::set('show_publishers', $value ? '1' : '0');
    }

    /**
     * Within the éditeur nav above: whether a type's library grid also
     * lists libraries that have no Éditeur/Collection structure at all
     * (dead-end tiles) or hides them. Off by default — an empty tile
     * that leads nowhere isn't useful until an admin opts in.
     */
    public static function showEmptyLibrariesInNav(): bool
    {
        return self::get('show_empty_libraries_nav') === '1';
    }

    public static function setShowEmptyLibrariesInNav(bool $value): void
    {
        self::set('show_empty_libraries_nav', $value ? '1' : '0');
    }

    /**
     * The on-screen width, in pixels, of a cover tile in the grids — and,
     * since a tile is generated at exactly this size rather than some
     * larger "safe" resolution, also the width a thumbnail is actually
     * rendered at. Height always follows at a fixed 25:36 ratio (matching
     * a typical comic cover); there's deliberately only one slider for
     * this, not two, so the two numbers can't drift out of proportion.
     * Applies to both extracted covers (Thumbnails::save, item grids) and
     * folder.jpg-style thumbnails (Thumbnails::resizeFile, the éditeur
     * nav's tile grids). Changing it only affects thumbnails generated
     * from here on: folder-thumbnail's disk cache is keyed on this value
     * too so it regenerates on its own, but already-extracted covers need
     * the admin's "Régénérer les miniatures" to pick up a new size.
     */
    public static function thumbnailWidth(): int
    {
        $stored = self::get('thumbnail_width');
        $value = $stored !== null ? (int) $stored : 165;
        return $value > 0 ? $value : 165;
    }

    public static function setThumbnailWidth(int $value): void
    {
        self::set('thumbnail_width', (string) max(50, min(300, $value)));
    }

    /** thumbnailWidth()'s fixed companion — see the ratio note above. */
    public static function thumbnailHeight(): int
    {
        return (int) round(self::thumbnailWidth() * 36 / 25);
    }

    /**
     * How many columns wide a grid is — the classic browse grid and every
     * level of the éditeur nav's tile grids alike. Not fixed at 10 after
     * all: admin-configurable, default 10.
     */
    public static function gridColumns(): int
    {
        $stored = self::get('grid_columns');
        $value = $stored !== null ? (int) $stored : 10;
        return $value > 0 ? $value : 10;
    }

    public static function setGridColumns(int $value): void
    {
        self::set('grid_columns', (string) max(1, min(15, $value)));
    }

    /**
     * How many tiles a grid shows before paginating — the classic browse
     * grid and every level of the éditeur nav's tile grids alike, so a
     * library with thousands of items or an éditeur with hundreds of
     * loose tomes never renders more than this at once. Independent of
     * gridColumns() above — this is the page's total, not a row count.
     */
    public static function gridPageSize(): int
    {
        $stored = self::get('grid_page_size');
        $value = $stored !== null ? (int) $stored : 80;
        return $value > 0 ? $value : 80;
    }

    public static function setGridPageSize(int $value): void
    {
        self::set('grid_page_size', (string) max(1, min(300, $value)));
    }

    /**
     * How many columns wide a home page shelf (Bandes Dessinées
     * récentes...) shows before the rest needs a horizontal scroll to
     * reach — the shelf still fetches a full 60 regardless (see
     * library.js), this only controls how many are visible without
     * scrolling.
     */
    public static function homeShelfColumns(): int
    {
        $stored = self::get('home_shelf_columns');
        $value = $stored !== null ? (int) $stored : 10;
        return $value > 0 ? $value : 10;
    }

    public static function setHomeShelfColumns(int $value): void
    {
        self::set('home_shelf_columns', (string) max(1, min(15, $value)));
    }

    /**
     * How many rows tall a home page shelf is — 1 (a single scrolling
     * row, the original design) by default. Above 1, tiles fill a column
     * at a time (see .shelf-row's grid-auto-flow in library.css) so
     * scrolling still only ever happens horizontally.
     */
    public static function homeShelfRows(): int
    {
        $stored = self::get('home_shelf_rows');
        $value = $stored !== null ? (int) $stored : 1;
        return $value > 0 ? $value : 1;
    }

    public static function setHomeShelfRows(int $value): void
    {
        self::set('home_shelf_rows', (string) max(1, min(5, $value)));
    }
}
