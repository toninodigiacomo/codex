<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $connection = null;

    /**
     * Columns added to a table after its first release, keyed by table
     * then column name, with the column definition SQLite needs after
     * "ADD COLUMN". CREATE TABLE IF NOT EXISTS (in schema.sql) only
     * matters for a table that doesn't exist yet — an existing SQLite
     * file from before one of these columns existed keeps its old
     * structure forever otherwise, silently breaking anything that reads
     * the missing column (e.g. a NULL "status" reads as neither 'active'
     * nor 'invited', so login always fails). migrate() below adds
     * whatever's missing, backfilling existing rows via each column's
     * DEFAULT — 'active' for status is deliberate: a pre-existing users
     * row predates the invite system entirely, so it must already be a
     * real, active account.
     */
    private const COLUMN_MIGRATIONS = [
        'users' => [
            'email' => 'TEXT',
            'status' => "TEXT NOT NULL DEFAULT 'active'",
            'mfa_required' => 'INTEGER NOT NULL DEFAULT 0',
            'invite_token_hash' => 'TEXT',
            'invite_token_expires' => 'TEXT',
            'remember_token_hash' => 'TEXT',
            'remember_token_expires' => 'TEXT',
        ],
        'comic_details' => [
            'penciller' => 'TEXT',
            'inker' => 'TEXT',
            'cover_artist' => 'TEXT',
            'editor' => 'TEXT',
            'genre' => 'TEXT',
            'characters' => 'TEXT',
            'age_rating' => 'TEXT',
        ],
    ];

    public static function connection(): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $path = __DIR__ . '/../data/codex.sqlite';
        $isNew = !is_file($path);

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');

        $schema = file_get_contents(__DIR__ . '/schema.sql');
        if ($schema === false) {
            throw new RuntimeException('Impossible de lire schema.sql');
        }
        $pdo->exec($schema);
        self::migrate($pdo);

        if ($isNew) {
            @chmod($path, 0664);
        }

        self::$connection = $pdo;
        return $pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        foreach (self::COLUMN_MIGRATIONS as $table => $columns) {
            $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($table))->fetchColumn();
            if (!$exists) {
                continue; // the table itself doesn't exist here (e.g. no comics ever added) — nothing to migrate
            }
            $existingColumns = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
            foreach ($columns as $column => $definition) {
                if (!in_array($column, $existingColumns, true)) {
                    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
                }
            }
        }
    }
}
