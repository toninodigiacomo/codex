<?php

declare(strict_types=1);

require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/I18n.php';

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

    /** The theme's own display name (as shown in Réglages' dropdown) — "Codex" or "ComiXology" — for anywhere else that wants to show which one is active, e.g. the site footer. */
    public static function displayName(): string
    {
        return t(self::current() === 'light' ? 'settings.theme_light' : 'settings.theme_dark');
    }

    /**
     * The "Propulsé par Codex — Thème X — © Vektoriel" strip shown at the
     * bottom of every page. One function rather than duplicating this
     * across eight templates — {link} is the only piece not safe to run
     * through htmlspecialchars() (it's meant to render as a real <a>),
     * so it's substituted in *after* escaping the surrounding translated
     * text, the same order account.js uses for its own {email}-in-HTML
     * banners.
     */
    public static function footerHtml(): string
    {
        $link = '<a href="https://github.com/toninodigiacomo/codex" target="_blank" rel="noopener noreferrer">Codex</a>';
        $line = str_replace(
            ['{link}', '{theme}'],
            [$link, htmlspecialchars(self::displayName())],
            htmlspecialchars(t('footer.line'))
        );
        return '<footer class="app-footer">' . $line . '</footer>';
    }
}
