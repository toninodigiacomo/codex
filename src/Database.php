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
            'pending_email' => 'TEXT',
            'pending_email_code_hash' => 'TEXT',
            'pending_email_expires' => 'TEXT',
            'pending_email_attempts' => 'INTEGER NOT NULL DEFAULT 0',
            'pending_password_hash' => 'TEXT',
            'pending_password_code_hash' => 'TEXT',
            'pending_password_expires' => 'TEXT',
            'pending_password_attempts' => 'INTEGER NOT NULL DEFAULT 0',
        ],
        'library_jobs' => [
            'current_item' => 'TEXT',
        ],
        'items' => [
            'metadata_checked_at' => 'TEXT',
            'file_size' => 'INTEGER',
            'file_mtime' => 'INTEGER',
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
        'libraries' => [
            'type' => "TEXT NOT NULL DEFAULT 'comic'",
            'last_synced_at' => 'TEXT',
        ],
        'reading_progress' => [
            'total_pages' => 'INTEGER',
            'completed_at' => 'TEXT',
        ],
    ];

    /** Tables with a foreign key pointing at users(id) — see repairDanglingUserReferences(). */
    private const TABLES_REFERENCING_USERS = ['user_libraries', 'reading_progress'];

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
        // Apache runs multiple concurrent PHP workers, each opening its own
        // connection — without this, two requests hitting a write at the
        // same moment (e.g. the admin console loading /api/users and
        // /api/libraries together) can collide with "database is locked"
        // instead of one just waiting a moment for the other.
        $pdo->exec('PRAGMA busy_timeout = 5000');

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
        // Order matters: repair any table left pointing at a stale name by
        // an earlier, buggy run of the rename below *before* possibly
        // running that rename again — otherwise a fresh rename would just
        // point those tables at yet another now-gone temporary name.
        self::repairDanglingUserReferences($pdo);
        self::relaxUsersPasswordHashConstraint($pdo);
        self::allowReaderBasicRole($pdo);
    }

    /**
     * `users.role`'s CHECK constraint (baked into the table at creation
     * time, same limitation as password_hash above — SQLite's ALTER
     * TABLE can't touch an existing CHECK) needs 'reader_basic' added
     * once the three-tier role system exists. Same rebuild dance, same
     * reasons, same care: BEGIN IMMEDIATE against a concurrent request,
     * legacy_alter_table=ON so the rename doesn't repeat the dangling
     * foreign-key bug the password_hash migration hit, a settings-table
     * marker so this doesn't re-run every request once it's done.
     */
    private static function allowReaderBasicRole(PDO $pdo): void
    {
        $marker = $pdo->query("SELECT value FROM settings WHERE key = 'schema_role_reader_basic'")->fetchColumn();
        if ($marker === '1') {
            return;
        }

        $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
        if (!$exists) {
            return;
        }

        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('PRAGMA legacy_alter_table = ON');
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $tableSql = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
            if ($tableSql !== false && str_contains((string) $tableSql, "'reader_basic'")) {
                // a concurrent request already finished this while we were waiting for the write lock
                self::setMigrationMarker($pdo, 'schema_role_reader_basic');
                $pdo->exec('COMMIT');
                return;
            }

            $columnList = implode(', ', array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name'));
            $schema = file_get_contents(__DIR__ . '/schema.sql');
            if ($schema === false) {
                throw new RuntimeException('Impossible de lire schema.sql');
            }

            $pdo->exec('ALTER TABLE users RENAME TO users_pre_role_migration');
            $pdo->exec($schema); // recreates "users" fresh, with the updated CHECK constraint
            $pdo->exec("INSERT INTO users ($columnList) SELECT $columnList FROM users_pre_role_migration");
            $pdo->exec('DROP TABLE users_pre_role_migration');
            self::setMigrationMarker($pdo, 'schema_role_reader_basic');
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        } finally {
            $pdo->exec('PRAGMA legacy_alter_table = OFF');
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * SQLite's ALTER TABLE ... RENAME does more than rename: by default
     * (`legacy_alter_table` off, which is SQLite's modern default) it also
     * rewrites the CREATE TABLE text of every *other* table that
     * references the renamed one via FOREIGN KEY, so their reference
     * keeps pointing at the new name. relaxUsersPasswordHashConstraint()
     * below renames "users" to a temporary name and later drops it —
     * before this fix, that meant `user_libraries` and `reading_progress`
     * (both `REFERENCES users(id)`) got silently rewritten mid-migration
     * to reference that temporary name, and were left pointing at nothing
     * once it was dropped. Every later write to either table then failed
     * with "no such table: ...temporary name...", even though `users`
     * itself was already fixed and totally fine.
     *
     * This repairs any table already left in that state — checked by
     * literally looking for the temporary migration name inside each
     * candidate table's own CREATE TABLE text — using the same
     * rename/recreate/copy/drop dance, but this time with
     * `legacy_alter_table` turned on so the rename itself can't cause the
     * same problem again (nothing else references user_libraries or
     * reading_progress, but the setting costs nothing to leave on for
     * this whole migration pass either way).
     */
    private static function repairDanglingUserReferences(PDO $pdo): void
    {
        $stale = false;
        foreach (self::TABLES_REFERENCING_USERS as $table) {
            $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($table))->fetchColumn();
            if ($sql !== false && str_contains((string) $sql, '_pre_nullable_migration')) {
                $stale = true;
                break;
            }
        }
        if (!$stale) {
            return;
        }

        $schema = file_get_contents(__DIR__ . '/schema.sql');
        if ($schema === false) {
            throw new RuntimeException('Impossible de lire schema.sql');
        }

        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('PRAGMA legacy_alter_table = ON');
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            foreach (self::TABLES_REFERENCING_USERS as $table) {
                $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($table))->fetchColumn();
                if ($sql === false || !str_contains((string) $sql, '_pre_nullable_migration')) {
                    continue; // this particular table was never affected
                }
                $columnList = implode(', ', array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name'));
                $tmpName = $table . '_dangling_fk_repair';
                $pdo->exec("ALTER TABLE $table RENAME TO $tmpName");
                $pdo->exec($schema); // recreates $table fresh, with a correct reference to "users"
                $pdo->exec("INSERT INTO $table ($columnList) SELECT $columnList FROM $tmpName");
                $pdo->exec("DROP TABLE $tmpName");
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        } finally {
            $pdo->exec('PRAGMA legacy_alter_table = OFF');
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * A users table created before the invite system existed has
     * password_hash as NOT NULL (a fresh account always set one
     * immediately at /setup.php back then). Once invites landed,
     * schema.sql makes it nullable — an invited user has none until
     * they accept and choose their own — but SQLite's ALTER TABLE can't
     * relax an existing NOT NULL constraint, only add columns. The only
     * way to actually change it is the standard SQLite dance: rename the
     * old table out of the way, let schema.sql's CREATE TABLE build a
     * fresh one with the current (nullable) definition, copy every row
     * across unchanged, drop the renamed original.
     *
     * `legacy_alter_table = ON` during the rename keeps SQLite from
     * rewriting other tables' foreign key text to chase the temporary
     * name — see repairDanglingUserReferences() above for what happens
     * without it.
     *
     * This runs on every request until it succeeds once, so two
     * concurrent requests both arriving before either has migrated is a
     * real scenario, not a theoretical one — the admin console alone
     * fires two API calls in parallel on load. A plain deferred
     * transaction only takes SQLite's write lock at the first actual
     * write, so both could pass the initial check before either has
     * written anything, then collide on the rename. BEGIN IMMEDIATE
     * takes the write lock up front — the second request just waits
     * (thanks to busy_timeout above) instead of racing, and re-checks
     * the table once it's finally its turn, so it correctly sees the
     * other request's finished migration and does nothing. A marker row
     * in `settings` then lets every later request skip the check
     * entirely, rather than re-running PRAGMA table_info() forever.
     */
    private static function relaxUsersPasswordHashConstraint(PDO $pdo): void
    {
        $marker = $pdo->query("SELECT value FROM settings WHERE key = 'schema_password_hash_nullable'")->fetchColumn();
        if ($marker === '1') {
            return;
        }

        $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
        if (!$exists) {
            return;
        }

        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('PRAGMA legacy_alter_table = ON');
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            // Re-check now that this request actually holds the write lock —
            // a concurrent request that got there first and already
            // finished means we'll see the fixed table here and just
            // record the marker, instead of redoing its work.
            $columns = $pdo->query('PRAGMA table_info(users)')->fetchAll();
            $passwordColumn = null;
            foreach ($columns as $col) {
                if ($col['name'] === 'password_hash') {
                    $passwordColumn = $col;
                    break;
                }
            }
            if ($passwordColumn === null || (int) $passwordColumn['notnull'] === 0) {
                self::setMigrationMarker($pdo, 'schema_password_hash_nullable');
                $pdo->exec('COMMIT');
                return;
            }

            $columnList = implode(', ', array_column($columns, 'name'));
            $schema = file_get_contents(__DIR__ . '/schema.sql');
            if ($schema === false) {
                throw new RuntimeException('Impossible de lire schema.sql');
            }

            $pdo->exec('ALTER TABLE users RENAME TO users_pre_nullable_migration');
            $pdo->exec($schema); // recreates "users" fresh (nullable password_hash) — every other CREATE TABLE IF NOT EXISTS is a no-op
            $pdo->exec("INSERT INTO users ($columnList) SELECT $columnList FROM users_pre_nullable_migration");
            $pdo->exec('DROP TABLE users_pre_nullable_migration');
            self::setMigrationMarker($pdo, 'schema_password_hash_nullable');
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        } finally {
            $pdo->exec('PRAGMA legacy_alter_table = OFF');
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    private static function setMigrationMarker(PDO $pdo, string $key): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO settings (key, value) VALUES (:key, \'1\')
             ON CONFLICT(key) DO UPDATE SET value = \'1\''
        );
        $stmt->execute([':key' => $key]);
    }
}
