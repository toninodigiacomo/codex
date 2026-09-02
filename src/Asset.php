<?php

declare(strict_types=1);

/**
 * Returns "$path?v=<mtime>" so a redeployed CSS/JS file is never served
 * stale from the browser cache — the version is the file's own
 * last-modified time, so there's nothing to remember to bump by hand.
 * $path is relative to public/ (e.g. "css/library.css").
 */
function asset(string $path): string
{
    $abs = __DIR__ . '/../public/' . ltrim($path, '/');
    $mtime = @filemtime($abs);
    return $mtime !== false ? "$path?v=$mtime" : $path;
}
