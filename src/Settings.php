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
}
