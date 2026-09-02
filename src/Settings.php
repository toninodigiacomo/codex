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
}
