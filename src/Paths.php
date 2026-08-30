<?php

declare(strict_types=1);

/**
 * All library content is bind-mounted read-only into the container at a
 * single root (see compose.yml: ./libraries -> /var/www/html/libraries).
 * items.path is always stored RELATIVE to that root (e.g.
 * "comics/sandman/sandman-01.cbz"), never an absolute host path — the
 * container has no way to see arbitrary host paths otherwise.
 */
final class Paths
{
    public static function libraryRoot(): string
    {
        $real = realpath(__DIR__ . '/../libraries');
        return $real !== false ? $real : (__DIR__ . '/../libraries');
    }

    public static function resolve(string $relativePath): string
    {
        return rtrim(self::libraryRoot(), '/') . '/' . ltrim($relativePath, '/');
    }
}
