<?php

declare(strict_types=1);

/**
 * Two locales for now — fr (also the fallback for any missing key, since
 * it's this app's original language and therefore always complete) and
 * en. Detected from the browser's Accept-Language header on first visit,
 * remembered afterward via a plain (non-httponly, so a client-side
 * language switcher can also set it) cookie — so a manual override sticks
 * across visits instead of re-guessing from the header every time.
 */
final class I18n
{
    private const SUPPORTED = ['fr', 'en'];
    private const DEFAULT_LOCALE = 'fr';
    private const COOKIE_NAME = 'codex_locale';

    private static ?string $locale = null;
    /** @var array<string,string>|null */
    private static ?array $strings = null;

    public static function locale(): string
    {
        if (self::$locale === null) {
            self::$locale = self::detect();
        }
        return self::$locale;
    }

    /** Called once, early — same idea as Auth::bootSession(), a page/API entry point sets the resolved locale before anything renders. Also (re)issues the cookie so a header-detected locale is pinned for next time, not re-detected (and potentially different, if the browser's own header ever changes) on every request. */
    public static function boot(): void
    {
        $locale = self::locale();
        if (($_COOKIE[self::COOKIE_NAME] ?? null) !== $locale) {
            setcookie(self::COOKIE_NAME, $locale, time() + 60 * 60 * 24 * 365, '/', '', false, false);
        }
    }

    /**
     * $vars does simple {name} substitution — deliberately not a full
     * ICU/pluralization system, this app's strings don't need one.
     * Falls back to the French string, then to $key itself, so a
     * translation that hasn't been written yet for a newer string is
     * visibly a raw key (easy to spot and fill in) rather than a blank.
     */
    public static function t(string $key, array $vars = []): string
    {
        $strings = self::strings();
        $text = $strings[$key] ?? self::stringsFor(self::DEFAULT_LOCALE)[$key] ?? $key;
        foreach ($vars as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    /** The full dictionary for the current locale — what a page embeds as `window.I18N` for its own JS to read via the same t() convention (public/js/i18n.js). */
    public static function all(): array
    {
        return self::strings();
    }

    /** @return array<string,string> */
    private static function strings(): array
    {
        if (self::$strings === null) {
            self::$strings = self::stringsFor(self::locale());
        }
        return self::$strings;
    }

    /** @return array<string,string> */
    private static function stringsFor(string $locale): array
    {
        static $cache = [];
        if (!isset($cache[$locale])) {
            $path = __DIR__ . "/translations/{$locale}.php";
            $cache[$locale] = is_file($path) ? require $path : [];
        }
        return $cache[$locale];
    }

    private static function detect(): string
    {
        $cookie = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (is_string($cookie) && in_array($cookie, self::SUPPORTED, true)) {
            return $cookie;
        }
        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        // Accept-Language looks like "en-US,en;q=0.9,fr;q=0.8" — take each
        // tag before the first '-' or ';', in the order sent (already
        // roughly quality-sorted by the browser), and use the first one
        // this app actually has a dictionary for.
        foreach (explode(',', $header) as $part) {
            $tag = strtolower(trim(explode(';', $part)[0] ?? ''));
            $primary = explode('-', $tag)[0] ?? '';
            if (in_array($primary, self::SUPPORTED, true)) {
                return $primary;
            }
        }
        return self::DEFAULT_LOCALE;
    }
}

/** Global shorthand — used throughout every .php template the same way esc()/asset() already are. */
function t(string $key, array $vars = []): string
{
    return I18n::t($key, $vars);
}
