# Codex

A personal ebook/comic library server (Ubooquity-style) — same hosting
approach as [My Lost Treasure]: `php:8.2-apache` via `compose.yml`, no
custom Docker image.

**Status: early scaffold.** Sign-in is a static page (visual direction
only, no auth wired up yet). Database layer, REST API, and the library
browsing screen are built and tested. Still to come: the reader, the
server admin panel, the mobile layout, and real authentication.

## Design

Visual direction: **comiXology** — near-black background, a bold signature
orange, big poster-style cover art, rounded modern UI, clean sans-serif
type (Inter). This replaced an earlier, more "technical blueprint" mockup
this project started from (hairline square-cornered cards, monospace
headings) — the change is purely in `public/css/style.css` and the pages'
own layout CSS; nothing in the database or API changed. Tokens: `--color-*`
(near-black ground `#0e0e10`, accent orange `#ff5a1f`, secondary blue
`#4fa8ff`, plus 100–900 ramps for each), `--font-*` (Inter throughout),
`--space-*`, `--radius-*` (rounded now — 6/10/16px, not the old sharp 0),
`--shadow-*`. Component classes (`.btn`, `.card`, `.field`/`.input`,
`.nav`, `.tag`, `.table`, `.dialog`) read from those variables.

## Database

Unlike My Lost Treasure's flat JSON files, this project uses **SQLite**
(`data/codex.sqlite`) — the scale (potentially thousands of auto-indexed
items, not a hand-curated list), the relational shape (tags, series,
per-user reading progress), and search/filter needs all fit SQL far better
than array filtering. Still no separate database server or container: it's
one file, opened directly by PHP's `PDO`, bind-mounted like `data/` always
was.

`src/schema.sql` is the single source of truth for the structure, applied
idempotently on every request by `src/Database.php` (`CREATE TABLE IF NOT
EXISTS` everywhere — no separate migration history yet).

```
libraries          id, name, path
items              id, type, title, path, format, cover_path, publisher,
                    library_id, series_id, issue_number, synopsis, added_at
comic_details       item_id, writer, artist, colorist, letterer
ebook_details       item_id, author, isbn, language
magazine_details    item_id, issue_date, frequency
series              id, name, type, description, cover_path
tags               id, name
item_tags          item_id, tag_id                       (many-to-many)
users              id, username, password_hash, role, totp_secret
reading_progress   user_id, item_id, position, updated_at
```

`items` is a single table for every content type (comic/ebook/magazine/
other), holding only the fields meaningful across all of them — type-
specific fields (writer/artist for comics, author/ISBN for ebooks, ...)
live in a detail table per type, sharing the item's own id as primary key.
This keeps every cross-type operation (search, tagging, reading progress,
sorting) a single query against `items`; a join only happens when
rendering one item's full detail page, and by then `type` already says
which table to join.

`src/Items.php` is the data-access layer: `create()`/`update()` write both
the base row and the right detail table in one transaction;
`find($id)` returns an item with its details and tags already attached;
`search($filters, $sort, $dir, $limit, $offset)` handles type/library/
series/tag/text filtering server-side. `src/Tags.php`, `src/Series.php`,
and `src/Libraries.php` cover the simpler supporting tables.

Foreign keys cascade on delete (`ON DELETE CASCADE`) — removing an item
cleans up its detail row, its tags, and any reading progress for it
automatically; deleting a tag or series just detaches it from items rather
than deleting them.

## REST API

`public/api/index.php` is the front controller — same routing pattern as
My Lost Treasure (parses the path after `/api/`, no framework). All
responses are JSON; reads are open, writes aren't auth-gated yet (that
lands once the real sign-in flow is built).

| Method | Path | Description |
|---|---|---|
| GET | `/api/items` | List/search. Query params: `type`, `library_id`, `series_id`, `tag_id`, `q`, `sort` (`title`/`added_at`/`issue_number`), `dir` (`ASC`/`DESC`), `limit`, `offset` |
| GET | `/api/items/:id` | One item, with its type-specific details and tags attached |
| POST | `/api/items` | Create. Body: `{ type, title, path, ...type-specific fields, tags?: string[] }` |
| PUT | `/api/items/:id` | Update (partial) |
| DELETE | `/api/items/:id` | Delete (cascades) |
| GET/POST | `/api/libraries` | List / create `{ name, path }` |
| DELETE | `/api/libraries/:id` | Delete |
| GET/POST | `/api/series` | List / create |
| GET/PUT/DELETE | `/api/series/:id` | Read / update / delete |
| GET/POST | `/api/tags` | List / create-or-get by `{ name }` |
| DELETE | `/api/tags/:id` | Delete (just detaches from items) |

`tags` on an item is a list of **names**, not ids — the API resolves each
one via `Tags::findOrCreate()`, so tagging never requires a separate
"create this tag first" step from whatever's driving the API (admin UI,
indexer, ...).

## Library browsing screen

`public/library.html` + `public/js/library.js` — sidebar (libraries,
series, tags, each clickable as a filter with an "active" state and a
clear button), a type switcher (Tous/BD/Ebooks/Magazines), debounced
search, sort control, and a responsive cover grid that falls back to a
blueprint-styled title card when there's no `cover_path` yet. Everything
reads from the REST API above; no server-side rendering.

Not built yet: the "continue reading" row (needs a logged-in user),
alphabet quick-jump, and the item detail / reader page that the grid's
links (`item.html?id=...`) already point to.

## Item detail page & ComicRack metadata

`public/item.html` + `public/js/item.js` — full item view with an
editable form, reached from the library grid (`item.html?id=...`).

For comics, an **"Extraire les métadonnées du fichier .cbz"** button
reads the file directly: a `.cbz` is a ZIP archive, and most comic
taggers (ComicRack, ComicTagger, Komga, Kavita, ...) embed a
`ComicInfo.xml` at its root following a shared community schema. If
present, its fields (title, series, issue number, publisher, summary,
writer/penciller/inker/colorist/letterer/cover artist/editor, genre,
characters, age rating) are read and saved straight into `items` /
`comic_details` — series is resolved/created by name via
`Series::findOrCreate()`, same pattern as tags. If the archive has no
`ComicInfo.xml`, the endpoint just reports nothing was found, and every
field on the page is a plain editable input — hand-typed values are saved
exactly the same way, no distinction is made in the database between
"came from the file" and "typed by hand". **Tags are never read from
files** — they only ever exist in the database (`tags`/`item_tags`), by
design.

Reading a `.cbz` doesn't need the `zip` PHP extension (`ZipArchive`),
which isn't reliably present in the stock `php:8.2-apache` image and
would mean either a custom Dockerfile or recompiling on every container
start. `src/MiniZip.php` is a small, dependency-free ZIP reader — it
parses the archive's central directory by hand and inflates entries with
`gzinflate()` (zlib, always part of core PHP) — good enough to pull one
named file (`ComicInfo.xml`) out of a classic (32-bit) ZIP, which is all
real-world comic archives are. `src/ComicInfo.php` sits on top of it and
maps the XML fields onto our schema.

`POST /api/items/:id/extract-metadata` (comics only) triggers this; it
resolves `items.path` against the bind-mounted library root (see below)
and applies whatever it finds.

## Where the actual files live

`items.path` is stored **relative to a single mounted library root**, not
as an absolute host path — the container has no way to see arbitrary host
paths otherwise. `compose.yml` bind-mounts `./libraries` (read-only) to
`/var/www/html/libraries`; `src/Paths.php` resolves a relative path like
`comics/sandman/sandman-01.cbz` against that root. Point your library
folders here (or symlink them in) for metadata extraction — and later,
the reader — to have anything to read.

## Local hosting

```bash
docker compose up -d
```

Same pattern as My Lost Treasure: `public/` read-only except
`public/assets/` (read-write, for covers/uploads later), `data/`
read-write, PHP upload limits raised for large scans (`docker/uploads.ini`).
The container also checks for `pdo_sqlite` and `simplexml` at startup and
installs whichever is missing (neither usually is — both are commonly
bundled by default, this is just a safety net, and only costs time on the
rare first boot where one is actually absent).

## License

Personal project.
