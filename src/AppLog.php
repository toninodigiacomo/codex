<?php

declare(strict_types=1);

/**
 * PHP-level logging, deliberately independent of Apache's own ErrorLog/
 * CustomLog. An earlier attempt to redirect *Apache's* logging into
 * data/ (rather than the base php:apache image's default, symlinked to
 * /dev/stdout/stderr and therefore unreadable by a plain fopen()) caused
 * a server-wide 500 that was never root-caused and had to be reverted —
 * touching httpd's own config carries real risk of breaking every
 * request, not just this admin tab. This sidesteps that risk entirely:
 * everything here is a plain PHP file write, using PHP's own error_log
 * mechanism (which already receives every error level, including a raw
 * fatal like memory exhaustion — PHP's engine writes it there itself,
 * before terminating, independent of any userland try/catch) plus a
 * one-line-per-request access log written from the front controller.
 * Directory creation is lazy (on first write, not at container startup)
 * so there's no ordering dependency on compose.yml's own setup script.
 */
final class AppLog
{
    private const DIR = __DIR__ . '/../data/logs';

    public static function errorLogPath(): string
    {
        return self::resolvedDir() . '/error.log';
    }

    public static function accessLogPath(): string
    {
        return self::resolvedDir() . '/access.log';
    }

    /** Call once, as early as possible, from every page/script entry point. */
    public static function bootstrap(): void
    {
        self::ensureDir();
        ini_set('log_errors', '1');
        ini_set('error_log', self::errorLogPath());
    }

    /** Called from the API front controller's respond() — the one place that already knows the final status code for every request. */
    public static function logRequest(string $method, string $uri, int $status): void
    {
        $line = sprintf("[%s] %s %s -> %d\n", date('Y-m-d H:i:s'), $method, $uri, $status);
        @file_put_contents(self::accessLogPath(), $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * A free-form progress line in the access log, for a long batch loop
     * (regenerate-covers, extract-missing, sync) where the request itself
     * can hang rather than error out — nothing in error.log to explain
     * why, since nothing actually failed yet. Logged right before and
     * right after whatever might be the slow/hanging step, so the last
     * "en cours" line with no matching "ok" right after it names the
     * exact item that got stuck, instead of an unresponsive request with
     * nothing to go on.
     */
    public static function note(string $message): void
    {
        $line = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
        @file_put_contents(self::accessLogPath(), $line, FILE_APPEND | LOCK_EX);
    }

    private static function ensureDir(): void
    {
        if (!is_dir(self::DIR)) {
            @mkdir(self::DIR, 0775, true);
        }
    }

    /** self::DIR as a literal string always has an unresolved "src/../data/logs" in it — this is purely so paths shown to the admin (and used for fopen/is_file, harmless either way) read cleanly. */
    private static function resolvedDir(): string
    {
        self::ensureDir();
        $real = realpath(self::DIR);
        return $real !== false ? $real : self::DIR;
    }
}
