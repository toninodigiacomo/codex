-- Codex database schema (SQLite).
-- Applied idempotently by src/Database.php on every boot (CREATE ... IF NOT
-- EXISTS everywhere), so this file is also the single source of truth for
-- the structure — no separate migration history to track yet.

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS libraries (
  id   INTEGER PRIMARY KEY,
  name TEXT NOT NULL,
  path TEXT NOT NULL UNIQUE
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
  username               TEXT NOT NULL UNIQUE,
  password_hash          TEXT NOT NULL,
  role                   TEXT NOT NULL DEFAULT 'reader' CHECK (role IN ('admin', 'reader')),
  totp_secret            TEXT,
  remember_token_hash    TEXT,
  remember_token_expires TEXT,
  created_at             TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

CREATE TABLE IF NOT EXISTS login_attempts (
  ip         TEXT PRIMARY KEY,
  count      INTEGER NOT NULL DEFAULT 0,
  last_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS reading_progress (
  user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  item_id    INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
  position   TEXT,               -- page number, EPUB CFI, scroll offset... whatever the reader needs
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
  PRIMARY KEY (user_id, item_id)
);

CREATE INDEX IF NOT EXISTS idx_reading_progress_user ON reading_progress(user_id, updated_at);
