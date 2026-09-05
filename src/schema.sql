-- Codex database schema (SQLite).
-- Applied idempotently by src/Database.php on every boot (CREATE ... IF NOT
-- EXISTS everywhere), so this file is also the single source of truth for
-- the structure — no separate migration history to track yet.

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS libraries (
  id              INTEGER PRIMARY KEY,
  name            TEXT NOT NULL,
  path            TEXT NOT NULL UNIQUE,
  type            TEXT NOT NULL DEFAULT 'comic' CHECK (type IN ('comic', 'ebook', 'magazine', 'other')),
  last_synced_at  TEXT
);

CREATE TABLE IF NOT EXISTS series (
  id          INTEGER PRIMARY KEY,
  name        TEXT NOT NULL,
  type        TEXT,              -- informational only: 'story_arc' / 'book_series' / 'periodical' / ...
  description TEXT,
  cover_path  TEXT
);

CREATE TABLE IF NOT EXISTS items (
  id           INTEGER PRIMARY KEY,
  type         TEXT NOT NULL CHECK (type IN ('comic', 'ebook', 'magazine', 'other')),
  title        TEXT NOT NULL,
  path         TEXT NOT NULL UNIQUE,
  format       TEXT,             -- cbz, cbr, epub, pdf, ...
  cover_path   TEXT,
  publisher    TEXT,
  library_id   INTEGER REFERENCES libraries(id) ON DELETE SET NULL,
  series_id    INTEGER REFERENCES series(id) ON DELETE SET NULL,
  issue_number REAL,
  synopsis     TEXT,
  metadata_checked_at TEXT,        -- when extraction was last attempted, success or not — NOT the same as "has a cover": a magazine or an unreadable file is legitimately never going to have one, and must not be retried forever
  file_size    INTEGER,            -- filesize()/filemtime() at the last (re-)extraction — a sync compares the
  file_mtime   INTEGER,            -- file's current stat() against these to detect an edit in place (same path,
                                    -- different content) far more cheaply than hashing every file's bytes every
                                    -- time, at the cost of missing the rare edit that preserves both exactly
  added_at     TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

CREATE INDEX IF NOT EXISTS idx_items_title      ON items(title);
CREATE INDEX IF NOT EXISTS idx_items_type        ON items(type);
CREATE INDEX IF NOT EXISTS idx_items_library_id  ON items(library_id);
CREATE INDEX IF NOT EXISTS idx_items_series_id   ON items(series_id);

-- Type-specific detail tables: same primary key as the item they extend,
-- so joining is a single condition and a missing row simply means "no
-- extra details recorded" rather than a pile of NULL columns on items.

CREATE TABLE IF NOT EXISTS comic_details (
  item_id      INTEGER PRIMARY KEY REFERENCES items(id) ON DELETE CASCADE,
  writer       TEXT,
  penciller    TEXT,
  inker        TEXT,
  colorist     TEXT,
  letterer     TEXT,
  cover_artist TEXT,
  editor       TEXT,
  genre        TEXT,
  characters   TEXT,
  age_rating   TEXT
);

CREATE TABLE IF NOT EXISTS ebook_details (
  item_id  INTEGER PRIMARY KEY REFERENCES items(id) ON DELETE CASCADE,
  author   TEXT,
  isbn     TEXT,
  language TEXT
);

CREATE TABLE IF NOT EXISTS magazine_details (
  item_id    INTEGER PRIMARY KEY REFERENCES items(id) ON DELETE CASCADE,
  issue_date TEXT,
  frequency  TEXT
);

CREATE TABLE IF NOT EXISTS tags (
  id   INTEGER PRIMARY KEY,
  name TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS item_tags (
  item_id INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
  tag_id  INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
  PRIMARY KEY (item_id, tag_id)
);

CREATE INDEX IF NOT EXISTS idx_item_tags_tag_id ON item_tags(tag_id);

CREATE TABLE IF NOT EXISTS users (
  id                     INTEGER PRIMARY KEY,
  username               TEXT NOT NULL UNIQUE COLLATE NOCASE,
  email                  TEXT,
  password_hash          TEXT,             -- NULL until an invited user accepts and sets one
  role                   TEXT NOT NULL DEFAULT 'reader_basic' CHECK (role IN ('admin', 'reader', 'reader_basic')),
  status                 TEXT NOT NULL DEFAULT 'invited' CHECK (status IN ('invited', 'active')),
  totp_secret            TEXT,             -- NULL = this user has no MFA set up yet
  mfa_required           INTEGER NOT NULL DEFAULT 0, -- admin-forced: 1 blocks login until the user enrolls
  invite_token_hash      TEXT,
  invite_token_expires   TEXT,
  remember_token_hash    TEXT,
  remember_token_expires TEXT,
  created_at             TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

CREATE TABLE IF NOT EXISTS user_libraries (
  user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  library_id INTEGER NOT NULL REFERENCES libraries(id) ON DELETE CASCADE,
  PRIMARY KEY (user_id, library_id)
);

CREATE TABLE IF NOT EXISTS settings (
  key   TEXT PRIMARY KEY,
  value TEXT
);

CREATE TABLE IF NOT EXISTS login_attempts (
  ip         TEXT PRIMARY KEY,
  count      INTEGER NOT NULL DEFAULT 0,
  last_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS reading_progress (
  user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  item_id      INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
  position     TEXT,               -- page number, EPUB CFI, scroll offset... whatever the reader needs
  total_pages  INTEGER,            -- cached page count, so a progress bar doesn't need to reopen the archive
  completed_at TEXT,               -- non-NULL = explicitly marked "read"; independent of position
  updated_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
  PRIMARY KEY (user_id, item_id)
);

CREATE INDEX IF NOT EXISTS idx_reading_progress_user ON reading_progress(user_id, updated_at);

-- One row per library, overwritten in place by whichever batch endpoint is
-- currently driving it (sync / extract-missing / regenerate-covers) — the
-- persistent "where are we" the admin console's Status area reads, since
-- the actual batch loop lives in the browser tab that started it and stops
-- advancing the moment that tab navigates away or reloads. Not a real
-- background job queue — no daemon process keeps calling batches for you —
-- just a server-side record of the last known progress, so returning to
-- the page (or a decently-timed refresh) shows something meaningful
-- instead of nothing, and a "Reprendre" button can pick the loop back up
-- from the recorded offset instead of starting over from zero.
CREATE TABLE IF NOT EXISTS library_jobs (
  library_id INTEGER PRIMARY KEY REFERENCES libraries(id) ON DELETE CASCADE,
  job_type   TEXT NOT NULL,        -- 'sync' | 'extract-missing' | 'regenerate-covers'
  status     TEXT NOT NULL,        -- 'running' | 'done' | 'error'
  done       INTEGER NOT NULL DEFAULT 0,
  total      INTEGER,
  current_item TEXT,               -- title of the item mid-processing right now, if any — null between items/batches
  message    TEXT,                 -- set on status = 'error'
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
