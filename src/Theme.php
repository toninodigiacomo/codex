<?php

declare(strict_types=1);

require_once __DIR__ . '/Settings.php';

/**
 * A single site-wide theme, not a per-user/per-browser preference —
 * set once by an admin (Réglages tab), applied identically to every
 * page for every visitor, logged-in or not. Stored in the `settings`
 * table like everything else on that tab, rather than a cookie.
 */
final class Theme
{
    private const SUPPORTED = ['dark', 'light'];
    private const DEFAULT_THEME = 'dark';

    public static function current(): string
    {
        $value = Settings::get('theme');
        return is_string($value) && in_array($value, self::SUPPORTED, true) ? $value : self::DEFAULT_THEME;
    }

    public static function set(string $theme): void
    {
        if (!in_array($theme, self::SUPPORTED, true)) {
            throw new InvalidArgumentException('Thème invalide.');
        }
        Settings::set('theme', $theme);
    }
}
