<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * The persistent "where are we" behind the admin console's per-library
 * status — see schema.sql's library_jobs table for why this exists and
 * what it isn't (not a real background job queue; the actual batch loop
 * still lives in whichever browser tab started it).
 */
final class LibraryJobs
{
    /**
     * Called right before an item starts processing (not just once per
     * batch of several) — a magazine PDF's cover can take 7-13s on its
     * own (a full-page pdftoppm render), so "which exact item is being
     * worked on right now" is worth a cheap SQLite write per item, not
     * just a count that only moves once a whole batch finishes.
     */
    public static function working(int $libraryId, string $jobType, int $done, ?int $total, string $currentItem): void
    {
        self::upsert($libraryId, $jobType, 'running', $done, $total, $currentItem, null);
    }

    /** Called after a batch, between items — $done/$total describe the whole job's progress so far, not just this one batch. current_item is cleared: nothing is mid-processing right this moment. */
    public static function progress(int $libraryId, string $jobType, int $done, ?int $total): void
    {
        self::upsert($libraryId, $jobType, 'running', $done, $total, null, null);
    }

    public static function finish(int $libraryId, string $jobType, int $done, ?int $total): void
    {
        self::upsert($libraryId, $jobType, 'done', $done, $total, null, null);
    }

    public static function fail(int $libraryId, string $jobType, int $done, ?int $total, string $message): void
    {
        self::upsert($libraryId, $jobType, 'error', $done, $total, null, $message);
    }

    /** @return array<int, array{job_type: string, status: string, done: int, total: ?int, current_item: ?string, message: ?string, updated_at: string}> keyed by library_id */
    public static function all(): array
    {
        $rows = Database::connection()->query('SELECT * FROM library_jobs')->fetchAll();
        $byLibrary = [];
        foreach ($rows as $row) {
            $byLibrary[(int) $row['library_id']] = [
                'job_type' => $row['job_type'],
                'status' => $row['status'],
                'done' => (int) $row['done'],
                'total' => $row['total'] !== null ? (int) $row['total'] : null,
                'current_item' => $row['current_item'],
                'message' => $row['message'],
                'updated_at' => $row['updated_at'],
            ];
        }
        return $byLibrary;
    }

    private static function upsert(int $libraryId, string $jobType, string $status, int $done, ?int $total, ?string $currentItem, ?string $message): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO library_jobs (library_id, job_type, status, done, total, current_item, message, updated_at)
             VALUES (:lib, :type, :status, :done, :total, :current, :message, :now)
             ON CONFLICT(library_id) DO UPDATE SET
               job_type = excluded.job_type, status = excluded.status, done = excluded.done,
               total = excluded.total, current_item = excluded.current_item,
               message = excluded.message, updated_at = excluded.updated_at'
        );
        $stmt->execute([
            ':lib' => $libraryId,
            ':type' => $jobType,
            ':status' => $status,
            ':done' => $done,
            ':total' => $total,
            ':current' => $currentItem,
            ':message' => $message,
            ':now' => date('c'),
        ]);
    }
}
