<?php

declare(strict_types=1);

/**
 * Two themes, same mechanism as src/I18n.php's locale: a plain
 * (non-httponly) cookie so the account popup's switcher can set it
 * client-side, read server-side on every page so the right
 * `data-theme` attribute is already on <html> before first paint —
 * no flash of the wrong theme while JS loads.
 */
final class Theme
{
    private const SUPPORTED = ['dark', 'light'];
    private const DEFAULT_THEME = 'dark';
    private const COOKIE_NAME = 'codex_theme';

    public static function current(): string
    {
        $cookie = $_COOKIE[self::COOKIE_NAME] ?? null;
        return is_string($cookie) && in_array($cookie, self::SUPPORTED, true) ? $cookie : self::DEFAULT_THEME;
    }
}
