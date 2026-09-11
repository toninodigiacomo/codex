<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * Where scheduled (cron-triggered) backups actually live — data/backups/,
 * managed by Codex itself with a grandfather-father-son rotation, unlike
 * the "Télécharger une sauvegarde" button (public/api/index.php's plain
 * GET /api/backup), which only ever streams a one-off snapshot straight
 * to the browser and deletes its own scratch file immediately after —
 * nothing from a manual download is ever kept here.
 *
 * Retention, applied by prune() after every create(): every backup from
 * the last 7 days, plus one per week for the 4 weeks before that, plus
 * one per month for the 6 months before that. Everything else is
 * deleted. This bounds disk usage — without it, a nightly cron job would
 * accumulate one full database copy per day forever.
 */
final class BackupManager
{
    private const FILENAME_PATTERN = '/^codex-backup-(\d{4}-\d{2}-\d{2}-\d{6})\.sqlite$/';

    private static function dir(): string
    {
        $dir = __DIR__ . '/../data/backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** Creates a new snapshot in data/backups/, prunes old ones per the retention policy above, and returns the new file's basename. */
    public static function create(): string
    {
        $dir = self::dir();
        $filename = 'codex-backup-' . date('Y-m-d-His') . '.sqlite';
        $path = $dir . '/' . $filename;

        // Same VACUUM INTO approach as the manual-download route — a
        // consistent snapshot even with concurrent writers, rather than
        // risking a torn copy of the raw file while WAL is active.
        Database::connection()->exec('VACUUM INTO ' . Database::connection()->quote($path));
        if (!is_file($path)) {
            throw new RuntimeException("La sauvegarde n'a produit aucun fichier.");
        }

        self::prune();
        return $filename;
    }

    /** @return array<int, array{filename: string, size: int, created_at: string}> newest first */
    public static function list(): array
    {
        $entries = self::entries();
        usort($entries, static fn($a, $b) => $b['time'] <=> $a['time']);
        return array_map(static function ($e) {
            return [
                'filename' => $e['filename'],
                'size' => filesize($e['path']) ?: 0,
                'created_at' => $e['time']->format('c'),
            ];
        }, $entries);
    }

    /** Resolves a filename to a real path within data/backups/ only — never lets a caller-supplied filename (a delete or download request) escape that directory or reach an arbitrary path. */
    public static function resolve(string $filename): ?string
    {
        if (!preg_match(self::FILENAME_PATTERN, $filename)) {
            return null;
        }
        $path = self::dir() . '/' . $filename;
        return is_file($path) ? $path : null;
    }

    public static function delete(string $filename): bool
    {
        $path = self::resolve($filename);
        return $path !== null && @unlink($path);
    }

    private static function prune(): void
    {
        $entries = self::entries();
        usort($entries, static fn($a, $b) => $b['time'] <=> $a['time']); // newest first

        $now = new DateTimeImmutable();
        $keep = [];

        foreach ($entries as $e) {
            if ($now->diff($e['time'])->days < 7) {
                $keep[$e['path']] = true;
            }
        }
        // One per week, for the 4 weeks right after that 7-day window —
        // the *newest* backup found in each week-bucket is the one kept.
        $weekBuckets = [];
        foreach ($entries as $e) {
            $daysAgo = $now->diff($e['time'])->days;
            $weeksAgo = intdiv($daysAgo, 7);
            if ($weeksAgo >= 1 && $weeksAgo <= 4 && !isset($weekBuckets[$weeksAgo])) {
                $weekBuckets[$weeksAgo] = $e['path'];
            }
        }
        foreach ($weekBuckets as $path) {
            $keep[$path] = true;
        }
        // One per month, for the 6 months after that.
        $monthBuckets = [];
        foreach ($entries as $e) {
            $monthsAgo = ((int) $now->format('Y') - (int) $e['time']->format('Y')) * 12
                + ((int) $now->format('n') - (int) $e['time']->format('n'));
            if ($monthsAgo >= 1 && $monthsAgo <= 6 && !isset($monthBuckets[$monthsAgo])) {
                $monthBuckets[$monthsAgo] = $e['path'];
            }
        }
        foreach ($monthBuckets as $path) {
            $keep[$path] = true;
        }

        foreach ($entries as $e) {
            if (!isset($keep[$e['path']])) {
                @unlink($e['path']);
            }
        }
    }

    /** @return array<int, array{path: string, filename: string, time: DateTimeImmutable}> */
    private static function entries(): array
    {
        $dir = self::dir();
        $entries = [];
        foreach (glob($dir . '/codex-backup-*.sqlite') ?: [] as $path) {
            $filename = basename($path);
            if (preg_match(self::FILENAME_PATTERN, $filename, $m)) {
                $time = DateTimeImmutable::createFromFormat('Y-m-d-His', $m[1]);
                if ($time !== false) {
                    $entries[] = ['path' => $path, 'filename' => $filename, 'time' => $time];
                }
            }
        }
        return $entries;
    }
}
