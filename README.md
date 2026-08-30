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

## Cover thumbnails

`POST /api/items/:id/extract-cover` (and, for comics, automatically as
part of `extract-metadata`) generates a thumbnail and saves it under
`public/assets/covers/<item-id>.jpg`, updating `cover_path`. What counts
as "the cover" depends on type (`src/CoverExtractor.php`):

- **comic** — the first page, naturally sorted the way a reader would
  order pages (`page002.jpg` before `page010.jpg`), skipping hidden/system
  entries like `__MACOSX/`.
- **ebook** — the cover declared in the EPUB's own manifest (EPUB3
  `properties="cover-image"`, or EPUB2's `<meta name="cover">` + matching
  manifest item), falling back to the first image in the archive if
  nothing is declared.
- **other** (a standalone image file) — the file itself.

Extracting from `.epub`/`.cbz` reuses `MiniZip.php`. Resizing (down to
480px wide, saved as JPEG) uses **GD** — also not bundled by default in
`php:8.2-apache`, so it's checked for and installed at container start,
same pattern as `pdo_sqlite`/`simplexml`.

**Magazines (PDF) don't have thumbnail generation yet** — rendering a PDF
page to an image needs something PHP has no built-in support for at all
(Ghostscript or Imagick, typically), a heavier dependency than anything
above. Same story as CBR: worth adding once it's actually needed, not
before.

## Authentication

Codex is a private personal library, not a public site with an admin
section bolted on — **every page and every API call requires a logged-in
session**, not just writes.

### First run — `/setup.php`

Redirected here automatically as long as `users` is empty. Generates a
random TOTP secret, shows it as a real **scannable QR code** (rendered
client-side by a vendored MIT library, `public/vendor/qrcode.js` — the
secret never leaves your server), asks for a username + password (12
characters minimum), and verifies the first 6-digit code before creating
the account (role `admin`, stored in `users`).

### Every other visit — `/login.php`

Username + password + 6-digit code together, plus an optional **"Se
souvenir de moi pendant 3 mois"** checkbox (a separate long-lived
`codex_remember` cookie — only its SHA-256 hash is stored, in `users`,
and it rotates on every silent re-login). Failed attempts are tracked per
IP in the `login_attempts` table; 5 failures locks that IP out for 15
minutes. Sessions use `HttpOnly`/`SameSite=Strict` cookies, a 1-hour idle
timeout, and session ID regeneration on login — same approach as My Lost
Treasure's admin, just backed by SQLite instead of a JSON file this time,
since `users` was already part of the schema.

`/library.php` and `/item.php` both call `Auth::requireLogin()`; the
`/api/*` front controller calls `Auth::requireLoginApi()` for every
request, reads included — the browser's session cookie is sent
automatically, so `library.js`/`item.js` needed no changes to work
against the now-protected API.

## Admin console

`/admin.php` (admin role only — `Auth::requireAdmin()`) has three tabs:

- **Utilisateurs** — invite a user by username + email + role
  (admin/reader) + which libraries they can see. No password is set by the
  admin: an invite link (`/accept-invite.php?token=...`) is generated and
  emailed via the configured SMTP relay; if sending fails (or SMTP isn't
  configured yet), the link is shown directly in the admin UI to copy and
  send manually — nothing is ever blocked on email actually working.
  On that page, the invited person sets their own password and, **unless
  the admin has checked "Exiger la MFA" at invite time**, decides for
  themselves whether to enable it. When forced, the MFA/QR-code step isn't
  optional — the checkbox is replaced by a note explaining it's required,
  and the account can't activate without a valid code. Either way, the
  admin can also force (or lift) the requirement **after the fact** on an
  already-active account — if it doesn't have a secret enrolled yet, its
  next login stops right after the password check and lands on
  `/mfa-setup.php` instead of the library: the session is deliberately
  left half-authenticated (`pending_mfa_user_id` set, but not
  `authenticated`) until they scan a QR code and confirm a code, so
  nothing else — no page, no API call — is reachable in between. A user
  with no MFA and no requirement logs in with just a password, same as
  always; one with MFA enrolled (by choice or by force) needs a code every
  time from then on. The admin can resend an invite
  (issues a fresh 7-day token), change a user's role/library access, or
  delete them — blocked from deleting/demoting the last remaining admin,
  or deleting their own account.
- **Bibliothèques** — add/edit/delete `libraries` rows (name + path,
  relative to the `libraries/` mount — see below).
- **Réglages** — SMTP relay configuration (host, port, encryption,
  credentials, from-address) plus a "send a test email" button to verify
  it before relying on it for real invites. The password field is never
  echoed back by the API (`smtp_password_set: true/false` only) and is
  left untouched if you save the form without retyping it.

**Reader access is actually enforced, not just recorded**: `/api/items`
(list, and single-item lookup by id) is filtered server-side to a
reader's assigned libraries — `src/Items.php`'s `library_ids` filter plus
a `currentUserAllowedLibraries()` check in the API layer that admins skip
entirely. A reader with no libraries assigned sees nothing, by design,
rather than defaulting to "everything" — an admin has to deliberately
grant access.

## Sending email — `src/Mailer.php`

A small hand-written SMTP client (connect, optional STARTTLS, AUTH LOGIN,
MAIL FROM/RCPT TO/DATA) rather than pulling in PHPMailer or a Composer
dependency — same reasoning as `MiniZip.php`: the protocol itself is
simple enough, and PHP's `mail()` shells out to a local MTA a minimal
container doesn't have configured (and residential IPs generally can't
send mail directly to begin with, ISPs block outbound port 25). Point it
at whatever SMTP relay you already have — Gmail, your email provider,
etc. — via the Réglages tab.

## Local hosting

```bash
docker compose up -d
```

Same pattern as My Lost Treasure: `public/` read-only except
`public/assets/` (read-write, for covers/uploads later), `data/`
read-write, PHP upload limits raised for large scans (`docker/uploads.ini`).
The container also checks for `pdo_sqlite`, `simplexml`, and `gd` at
startup and installs whichever is missing (none usually are — all three
are commonly bundled by default; this is just a safety net, and only
costs time on the rare first boot where one is actually absent).

## License

Personal project.
