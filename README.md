# Codex

A personal ebook/comic library server (Ubooquity-style) — same hosting
approach as [My Lost Treasure]: `php:8.2-apache` via `compose.yml`, no
custom Docker image.

**Status: functional daily-use app.** Auth (TOTP-based MFA, invites,
three-tier roles), library sync/scan, an admin console (users,
libraries, réglages, journaux), the reader (embedded viewer, reading
progress), and an éditeur/collection browsing nav are all built and in
use against a real multi-thousand-item collection. Still rough around
the edges in a few places — see the "Known gap" notes scattered
through this file.

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

## Cover images

`POST /api/items/:id/extract-cover` (and, for comics, automatically as
part of `extract-metadata`) saves the extracted image under
`public/assets/covers/<item-id>.<ext>`, updating `cover_path`. What counts
as "the cover" depends on type (`src/CoverExtractor.php`):

- **comic** — the first page, naturally sorted the way a reader would
  order pages (`page002.jpg` before `page010.jpg`), skipping hidden/system
  entries like `__MACOSX/`.
- **ebook** — the cover declared in the EPUB's own manifest (EPUB3
  `properties="cover-image"`, or EPUB2's `<meta name="cover">` + matching
  manifest item), falling back to the first image in the archive if
  nothing is declared.
- **other** (a standalone image file) — the file itself.

Extracting from `.epub`/`.cbz` reuses `MiniZip.php`. `src/Thumbnails.php`
resizes the saved image down to a "contain" fit within a configured
box (Réglages → Miniatures, a slider — width in px, 50–300, default
165; height always follows at a fixed 25:36 ratio, so there's only one
number to set, not two that could drift out of sync), via **GD** —
installed at container startup (`compose.yml`, same
`docker-php-ext-install` pattern already used there for `pdo_sqlite`/
`simplexml`, rather than baked into a custom image) instead of served
at original resolution. This is a reversal of an earlier decision
(resizing skipped entirely, GD considered too much reliability risk for
a "nice to have") — revisited once the same trade-off was already being
accepted for the other extensions installed the same way, and it turned
out to matter a lot once the collection grew into the thousands: a grid
of 80 full-resolution scans was visibly slow to load, and a resized
JPEG (quality 82) is a fraction of the size.

**Degrades gracefully at every step, never a broken cover.** GD missing
entirely, a corrupt/undecodable image, or a genuinely oversized source
(a `getimagesize()` check — cheap, header-only — skips anything over
~60 megapixels *before* attempting a full decode, since that's what
actually costs memory: GD's internal buffer is roughly width × height ×
4 bytes, and a real memory-exhaustion fatal in PHP can't be caught by
any `try`/`catch`, so this has to be avoided rather than recovered
from) — any of these just falls back to saving the original bytes
unresized, exactly like the old behavior. `docker/uploads.ini` sets
`memory_limit = 512M` (up from PHP's default 256M) for exactly this —
some scanned covers are large enough to need the headroom even with
the size guard in place.

Extraction/resizing only happens automatically for **newly discovered**
files (tied to sync, see below) — a library indexed before GD was
active still has its covers at full resolution until told otherwise.
The admin console's Bibliothèques tab has a **"Régénérer les
miniatures"** button per library (and a **"Tout régénérer
(miniatures)"** for every library at once) that re-extracts every
item's cover regardless of whether it already has one, batched (25 at
a time) so the admin sees live progress rather than one long silent
request. `folder.jpg`-style thumbnails (the éditeur nav's tile grids,
below) go through the same resize on the fly, cached to disk
(`public/assets/folder-thumbs/`, keyed on the source file's own mtime
plus the configured size, so both a replaced `folder.jpg` and a changed
size setting invalidate the cache on their own).

**`.pdf` files (regardless of whether they're catalogued as a magazine
or an ebook) get real page rendering** — `src/PdfRenderer.php` shells
out to `poppler-utils`' `pdftoppm`/`pdfinfo` (installed at container
startup, same "check and install what's missing" pattern as the PHP
extensions) to actually render each page, text and images composed
together, exactly as a normal PDF viewer would. An earlier version of
this scanned the raw file for embedded JPEG-compressed image objects and
used those directly — clever for a PDF that's literally one scanned
image per page, but wrong for the far more common case of a real
document (a novel, a gamebook, a word-processor export): a page like
that has actual vector text plus maybe a small illustration or two, and
scanning for embedded JPEGs found only those illustrations, floating
alone on an otherwise-blank page, while every text-only page was
invisible to it entirely. `poppler-utils` is a mature, widely-packaged
library — installing it is a meaningfully different trade-off than the
Ghostscript/Imagick route considered (and declined) earlier in this
project: a single common apt package, no PHP extension to compile, and
the interaction is two command-line tools invoked through `proc_open`
with an argument array (never a shell string built from input), so
there's nothing here for a crafted filename to inject into. If the
binaries are missing for any reason, `pageCount()`/`renderPage()`
return `0`/`null` rather than erroring — a PDF just ends up with no
pages available, the same as any other extraction failure elsewhere in
this codebase.

**`.cbr` (RAR) covers work too, within a narrower limit than everything
else here** — `src/MiniRar.php` reads the RAR5 container format (file
names, sizes, per-entry compression method) but can only extract an
entry whose compression method is "store" (none at all). RAR's actual
compression algorithm is proprietary — unlike ZIP's openly-documented
DEFLATE (which is why `MiniZip.php` could be written at all), there's no
equivalent path to decoding it in pure PHP. In practice this still
covers a meaningful share of real comic archives: compressing an
already-JPEG page gains essentially nothing, so many scanning/release
groups store images uncompressed specifically to skip the wasted CPU
time. A CBR using real compression for its images can't be read at all;
it's catalogued normally, just without a cover. Only the RAR5 container
format is handled (what any current archiver produces) — the older RAR4
structure is a different, unrelated header layout and isn't recognized.

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

## Reader home page — shelves, not a firehose

`library.php` opens in **home mode**: one shelf per content type that
actually has a library (`#shelf-comic`, `#shelf-ebook`, ...), the most
recently added items of that type. Each shelf fetches a fixed 60 items
but only shows a configurable window before a horizontal scroll is
needed to see the rest (Réglages → Étagères de la page d'accueil:
columns visible, rows visible — above 1 row, tiles fill a column at a
time via CSS Grid's `grid-auto-flow: column`, so scrolling stays
horizontal-only regardless — and how many are fetched in the first
place, 40–120, default 60). A shelf with nothing in it hides entirely.
The first interaction with search or the type tabs switches to **browse
mode** (a single grid with sidebar filters): `library.js`'s `state.mode`
flips once and stays there for the rest of the session. Default sort,
both on the shelves and once in browse mode, is most-recently-added
first (`added_at DESC`).

**Grids are paginated, not "load everything and trim the last row."**
An earlier approach rendered every matching item and cropped the
trailing partial row client-side; once a library reached the
thousands, this meant one huge, slow-to-render response per view.
Réglages → Densité des grilles sets two independent numbers: how many
columns wide a grid is (1–15, default 10 — a CSS `max-width` cap
combined with `auto-fill` so it's still responsive *below* that width,
just never wider), and the total items per page (1–300, default 80,
so 80÷10 reads as "10×8" at the defaults without either number being
derived from the other). Real pagination («, ‹, page/total, ›, ») —
server-side `limit`/`offset` on `GET /api/items` — replaces the old
client-side trim.

## Browsing by publisher / collection (the éditeur nav)

For a library laid out on disk as `Éditeur/Collection/Tome...` — or
nested arbitrarily deeper (`Éditeur/Collection/Sous-collection/...`) —
a single Réglages toggle (`show_publishers`) adds a small arrow next to
that type's tab, opening a recursive folder-tile browser. This replaced
an earlier design with two separate toggles (publishers-only /
collections-only / both) and a fixed two-level hierarchy; the fixed
depth didn't survive contact with a real collection that had a third
level (e.g. an éditeur's sub-imprints, each with their own
collections).

`src/LibraryGroups.php::listSubfolders($type, $allowedLibraryIds,
$libraryId, $pathPrefix)` is the one function behind every level: given
a path prefix (empty for the éditeur listing itself), it returns the
folder names directly under it — an éditeur, a collection, and a
sous-collection are all just "the folders under this path," recursed by
calling the same function again with one more segment appended.
Nothing is stored: a library re-synced with folders renamed just
reflects that on the next page load. A path with **no subfolders at
all** (a leaf) skips straight to the normal paginated item grid instead
of showing a dead-end tile screen — this applies at any depth, so an
éditeur with no collections, or a collection with no sous-collections,
behaves the same way. A tile grid also carries along any tomes sitting
directly in that folder alongside its subfolders (`exact=1` on
`GET /api/items`, matching only items whose path stops exactly at that
depth) — a lone one-shot living next to several collection folders
doesn't just disappear.

The nav starts one level up from all this: **one tile per library of
the chosen type** (`GET /api/library-groups`), never merged even when
two libraries share a type — two "BD" libraries stay two separate
tiles so their éditeurs aren't mixed together. Skipped automatically
straight to the éditeur grid when there's only one library of that
type. A tile's thumbnail reuses the exact convention
`scan_exclude_pattern` already exists to filter out —
`folder.jpg`/`header.jpg`/etc. sitting directly in that folder,
Ubooquity-style — falling back to the cover of that folder's first item
(naturally sorted) if there's no sidecar image.

`GET /api/display-settings` exists because `GET /api/settings` is
admin-only (it carries the SMTP password) — readers need to know
`show_publishers` and the grid-density/thumbnail-size numbers above to
render correctly, so that reader-safe subset gets its own route rather
than loosening the real settings endpoint.

## Admin console

`/admin.php` (admin role only — `Auth::requireAdmin()`) has four tabs:

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
  relative to the `libraries/` mount — see below), each showing its own
  item count and last-sync date, refreshed in place after any action
  rather than needing a manual page reload. Per library: **Synchroniser**
  (batched, 25 new files at a time, showing live "X/Y" progress rather
  than one silent long request — see "Discovering content" below),
  **Métadonnées manquantes** (backfill), **Régénérer les miniatures**
  (re-extract every cover at the currently configured size, batched the
  same way). **Tout synchroniser** / **Tout extraire (métadonnées)** /
  **Tout régénérer (miniatures)** repeat each of those across every
  library in turn — one library failing outright doesn't stop the rest
  of the list from being attempted; a "Fiches orphelines" card below the
  list finds and deletes any item left with no library at all (see
  "Deleting a library" below).
- **Réglages** — SMTP relay configuration (host, port, encryption,
  credentials, from-address) plus a "send a test email" button to verify
  it before relying on it for real invites (the password field is never
  echoed back by the API — `smtp_password_set: true/false` only — and is
  left untouched if you save the form without retyping it); the éditeur
  nav toggle; thumbnail size (a slider, see "Cover images"); grid
  density and home-shelf density (see "Reader home page" and "Browsing
  by publisher / collection" above).
- **Journaux** — a read-only tail of Apache's error/access logs.
  **Known gap**: in the base `php:8.2-apache` image,
  `${APACHE_LOG_DIR}/access.log` and `error.log` are symlinked to
  `/dev/stdout`/`/dev/stderr` (so `docker logs` captures them) — a
  character device, not a regular file, so this tab currently reports
  them as not found even though Apache is actively writing through that
  same path. An attempt to redirect logging into `data/` instead (a
  location this app fully controls, and one that would also survive a
  container recreation) caused a server-wide 500 and was reverted before
  being properly root-caused — worth another look, but the working
  hypothesis is a directory-creation ordering issue between the startup
  script and Apache's own config-parse step, not a `.htaccess` problem
  (that file is unrelated and wasn't touched).

**Reader access is actually enforced, not just recorded**: `/api/items`
(list, and single-item lookup by id) is filtered server-side to a
reader's assigned libraries — `src/Items.php`'s `library_ids` filter plus
a `currentUserAllowedLibraries()` check in the API layer that admins skip
entirely. A reader with no libraries assigned sees nothing, by design,
rather than defaulting to "everything" — an admin has to deliberately
grant access. `Items::search()` also unconditionally excludes any item
with `library_id IS NULL` (see "Deleting a library" below) — an orphaned
item has no library to check permissions against, so it must never
surface just because nothing else happened to filter it out.

## Sending email — `src/Mailer.php`

A small hand-written SMTP client (connect, optional STARTTLS, AUTH LOGIN,
MAIL FROM/RCPT TO/DATA) rather than pulling in PHPMailer or a Composer
dependency — same reasoning as `MiniZip.php`: the protocol itself is
simple enough, and PHP's `mail()` shells out to a local MTA a minimal
container doesn't have configured (and residential IPs generally can't
send mail directly to begin with, ISPs block outbound port 25). Point it
at whatever SMTP relay you already have — Gmail, your email provider,
etc. — via the Réglages tab.

## Role separation: admins don't browse

Admin accounts are for administering, not reading. `Auth::requireReaderPage()`
(`library.php`) and `Auth::requireReaderApi()` (only the `/api/items` list —
`GET` with no id — plus `/api/tags` and `/api/series`) redirect/reject an
admin just like they would a logged-out visitor. **Managing individual
items stays open to admins** — `item.php`, and `/api/items` for anything
that isn't the browsing list (single-item read, create, update, delete,
metadata/cover extraction) — since that's curation work, not the reading
experience the restriction is actually about. Post-login redirects
(`login.php`, `index.php`, `mfa-setup.php`) are role-aware too — an admin
lands on `/admin.php`, a reader on `/library.php`. `/api/libraries` stays
open to both roles: the admin console's own tabs and the reader-facing
sidebar both need it.

**Known gap**: there's still no list/browse view built specifically for
admins to find an existing item to edit — `item.php` needs an id in the
URL. Worth a small admin-side item list once that's needed day-to-day.

## Reader library access — deny by default

A reader's access to `/api/items` is scoped to the libraries an admin has
explicitly granted them (`user_libraries`) — **empty by default**, not
"everything": a newly invited reader sees nothing until an admin grants
access, whether at invite time or afterward. Each user row in the admin
console's Utilisateurs tab has a **"Bibliothèques..."** button (readers
only) that opens a checklist of every library to grant/revoke, independent
of when the account was created — adding a new library later doesn't
retroactively grant anyone access to it; each reader's list has to be
updated deliberately.

## Library path picker

Adding or editing a library, the **"Parcourir..."** button next to the
path field opens a small folder browser (`GET /api/browse-libraries`,
admin-only) rooted at the `libraries/` mount — click into a folder to
descend, ".." to go back up, "Choisir ce dossier" to fill in the field.

If `libraries/` doesn't exist in the container at all, the endpoint says
so explicitly rather than a generic "invalid path" — check it exists on
the host next to `compose.yml`, and that `compose.yml` still has the
`./libraries:/var/www/html/libraries:ro` mount.

**Bringing in a collection that lives elsewhere on the host: add a
volume line, don't symlink.** A symlink placed inside `./libraries` on
the host, pointing at another host path (e.g.
`ln -s /mnt/nvme0n1/docker/ubooquity/comics libraries/comics`), doesn't
work — only `./libraries` itself is mounted into the container, so from
inside it that symlink's target doesn't exist at all, and it's silently
skipped as a broken link (`is_dir()` on it returns false). The fix is a
second `volumes:` line in `compose.yml`, mounting the external folder
directly at the path you want it to appear under `libraries/`:

```yaml
volumes:
  - ./libraries:/var/www/html/libraries:ro
  - /mnt/nvme0n1/docker/ubooquity/comics:/var/www/html/libraries/comics:ro
```

One line per external source. This makes it a real, ordinary directory
from the container's point of view — no symlink resolution involved, no
container/host boundary to cross — so the path picker and everything
else just sees it like any other folder under `libraries/`. Requires
`docker compose down && up` (a plain `restart` won't pick up a new
volume line) each time you add one.

Browsing itself still rejects a literal `..` segment in the requested
path — a hand-crafted attempt at relative traversal — but doesn't
otherwise care where a given folder is actually mounted from.

## Schema migrations

`CREATE TABLE IF NOT EXISTS` (in `schema.sql`) only matters for a table
that doesn't exist yet — it never touches one that's already there, so
adding a column to an existing table in a later revision silently does
nothing for anyone who already has a database, until `Database::migrate()`
runs. It's a short, explicit list of "table → column → definition"
(`Database::COLUMN_MIGRATIONS`), checked against `PRAGMA table_info()` on
every boot and applied via `ALTER TABLE ... ADD COLUMN` for whatever's
missing — safe to run every time (skips columns that already exist) and
safe on a brand-new database (nothing to add). Existing rows are
backfilled through each column's own `DEFAULT` — `status` defaults to
`'active'` specifically because a `users` row that predates the column
must already be a real, active account, not a pending invite.

Adding a column only gets you so far, though: a `users` table created
before the invite system existed has `password_hash NOT NULL` (every
account used to set one immediately at `/setup.php`), and SQLite's
`ALTER TABLE` has no way to relax an existing `NOT NULL` — only add
columns. `Database::relaxUsersPasswordHashConstraint()` checks for this
specifically (`PRAGMA table_info(users)`, the `notnull` flag on
`password_hash`) and, if needed, does the standard SQLite rebuild dance:
rename the table out of the way, let `schema.sql` recreate it fresh with
today's (nullable) definition, copy every row across unchanged, drop the
renamed original — all in one transaction. A `settings` row marks it done
so every later request skips straight past the check.

That rename step has a sharp edge worth knowing about: SQLite's modern
default behavior (`legacy_alter_table` off) doesn't just rename a table —
it also rewrites the `CREATE TABLE` text of every *other* table that
references it via foreign key, so their reference follows the new name.
`user_libraries` and `reading_progress` both reference `users(id)`, so
renaming `users` to a temporary name mid-migration silently rewrote both
of them to reference *that* temporary name — and once it was dropped at
the end of the migration, they were left referencing a table that no
longer existed. `users` itself came out fine; every later write to either
of those two tables failed with `no such table: ...temporary name...`,
which looked unrelated. `Database::repairDanglingUserReferences()` finds
and fixes any table already left in that state (literally checking each
candidate's own `CREATE TABLE` text for the temporary name), and the
rename itself now runs with `legacy_alter_table = ON` so it can't happen
again.

On top of that: this whole thing runs on every request until it
succeeds once, and Apache serves multiple requests concurrently — the
admin console alone fires two API calls in parallel on page load. A
plain deferred transaction only takes SQLite's write lock at the first
actual write, so two concurrent requests could both pass the "does this
need fixing" check before either has written anything, then collide on
the rename. The migration now opens with `BEGIN IMMEDIATE` (takes the
write lock immediately) plus `PRAGMA busy_timeout = 5000` on every
connection, so a second request just waits its turn instead of racing,
and re-checks the table once unblocked — correctly finding nothing left
to do if another request already finished.

## Three-tier user roles

`users.role` is one of `admin`, `reader` (branded "Utilisateur avancé"
in the UI — every existing reader account before this feature became
this tier automatically, no data migration needed since the DB value
itself didn't change), or `reader_basic` ("Utilisateur" — same reading
access as the advanced tier, minus the ability to edit an item's
metadata). New invites default to `reader_basic`. An existing user's
role can be changed anytime from the admin console's Utilisateurs tab,
not just at invite time — a `<select>` right on their row, calling the
same `PUT /api/users/:id` the invite flow's role field already used.

Adding `reader_basic` needed a table-rebuild migration — same reasoning
and same care as `password_hash`'s: `role`'s `CHECK` constraint is baked
into the table at creation time, and SQLite's `ALTER TABLE` can't touch
an existing `CHECK`. `Database::allowReaderBasicRole()` follows the
identical pattern (`BEGIN IMMEDIATE` against a concurrent request,
`legacy_alter_table = ON` so the rename doesn't repeat the dangling
foreign-key bug the password_hash migration hit, a settings-table marker
so it only ever runs once).

The restriction itself is enforced server-side, not just hidden in the
UI: `PUT /api/items/:id` (item.php's save) returns 403 for a
`reader_basic` session, checked before anything else in that handler —
confirmed with a direct API call bypassing the UI entirely, not just a
button click. item.php reads the session role from a data attribute
(`Auth::requireLogin()` already allows all three roles onto that page,
same as before) and renders every field `readonly` and omits the
"Enregistrer" button/status entirely for that tier, so there's nothing
in the UI suggesting an edit is possible in the first place.

## Full summary popup

The résumé box only has room for so much text before it needs
scrolling internally. The small expand icon next to the "Résumé" label
opens a modal (reusing `style.css`'s existing `.dialog`/`.dialog-backdrop`
component) showing the title and the complete text at a comfortable
reading size, generously sized so a typical summary never needs to
scroll — a max-height with its own scroll is still there as a safety
net for a pathologically long one, rather than ever silently cutting
text off. Closes via the button, clicking outside, or Escape. Available
to every role, including `reader_basic` — reading the full text isn't
an edit.

## Embedded reader

Four icons on an item's page (item.php): download, read, reset progress,
mark as read. Only image-based formats are readable in the embedded
viewer — `.cbz`, `.cbr` (only the pages an archive happens to store
uncompressed — see `MiniRar.php`), `.pdf` (every page, actually
rendered — see `PdfRenderer.php`), and a standalone image (one page).
**EPUB is deliberately excluded** — reflowable text needs a genuinely
different reading UI (chapters, table of contents, re-flowing text), not
a page-image viewer; `src/ItemPages.php` returns 0 pages for anything
that isn't one of those four, and the read button disables itself
accordingly (checked via `GET /api/items/:id/pages` when the item page
loads).

`src/ItemPages.php` is the single place that knows how to list and fetch
pages across every supported format — `reader.php`/the fallback path in
`reader.js` and the API route below don't know or care which one a
given item is.

**A `.pdf` specifically renders through PDF.js in the browser, not
`ItemPages.php`, whenever it can** — `reader.js` loads the raw file
(`GET /api/items/:id/download`, same permission check as everything
else; the `Content-Disposition: attachment` header on that route has no
effect on a same-origin `fetch()`, only on a top-level navigation) via
the vendored `public/vendor/pdfjs/`, draws each page to a `<canvas>`,
and overlays real clickable `<a>` elements positioned from the page's
own link annotations (`page.getAnnotations()` + PDF.js's
`viewport.convertToViewportRectangle()` for the pixel math) — a table
of contents entry or a cross-reference actually works now, calling back
into this same reader's own `goTo()` for an internal destination rather
than PDF.js's own multi-page scrolling viewer, which isn't the UI this
reader uses. `ItemPages.php`/`PdfRenderer.php`'s flat per-page JPEG
rendering is still there as the fallback for whatever PDF.js can't make
sense of (caught around `pdfjsLib.getDocument(...).promise`) — links
just won't be clickable for that one file, same as before this existed.

**Reading progress** (`reading_progress` table — `position` as a
1-based-in-the-UI/0-based-internally page index, `total_pages` cached so
a progress bar never needs to reopen the archive, `completed_at`
independent of position so "mark as read" doesn't fight with whatever
page the position tracking last saved) is per-user, via:
- `GET /api/items/:id/progress` — current state.
- `PUT /api/items/:id/progress` — body `{current_page?, total_pages?,
  completed?}`; the reader calls this (debounced ~400ms after each page
  turn) as someone reads, and item.php's "Lu" button calls it with just
  `{completed: true}` (or `false` to un-mark — it's a toggle).
- `DELETE /api/items/:id/progress` — reset; item.php's reset button only
  shows once there's something to reset.

`GET /api/items/:id/page?index=N` streams one page's raw image bytes
(the reader fetches pages one at a time as someone navigates, plus a
one-page read-ahead in each direction, rather than pulling a whole
archive up front). `GET /api/items/:id/download` streams the original
file unmodified with a `Content-Disposition: attachment` filename built
from the item's title. Both — like every per-item route — enforce the
same library-access check as the single-item GET (a 404, not a 403, so
an out-of-scope item's existence isn't revealed).

## Reader nav bar

The top bar's type tabs (Bande Dessinée / Ebooks / Magazines / Fichiers)
are generated client-side from whichever library **types** actually
exist — `library.js`'s `renderTypeTabs()` checks the fetched libraries
list, not a fixed set, so a type with no library at all just doesn't get
a tab. The Home icon button returns to the shelf-based landing view
(`switchToHomeMode()`) — the previous "Tous" tab is gone; searching
without picking a type tab still searches across every type by default,
so that capability isn't lost, just no longer a dedicated button. The
username in the top-right opens a small dropdown (currently just
"Déconnexion"), closing on an outside click or when a menu item is used.

## Discovering content — library sync

Nothing populates `items` on its own — every item in earlier testing
throughout this project was created by hand via the API. Real discovery
is `src/LibraryScanner.php`: walks a library's mounted folder
recursively, creates an item for every recognized file (`.cbz`, `.cbr`,
`.epub`, `.pdf`, and standalone images) not already indexed by its path,
and reports — without deleting — any already-indexed item whose file has
since disappeared. Deleting an orphaned entry is a deliberate action the
admin takes from the sync result, not something a scan does on its own.

**A file already indexed under the same path still gets re-checked, not
just skipped.** `items.file_size`/`file_mtime` record what `stat()` said
at the last (re-)extraction; a later sync compares those against the
file's *current* `filesize()`/`filemtime()` — both already effectively
free, since PHP's own stat cache means they're very likely reusing the
same syscall result `walk()`'s own `is_file()` check just made — and if
either differs, treats it as edited in place (a rescanned page, a
corrected translation, anything that keeps the same filename on
purpose) and re-runs `ItemEnrichment::run()` for it, same as a brand
new file. Hashing every file's actual bytes on every sync would catch
this too, but at the cost of reading the full content of every
already-indexed file, every time — for a library living on a network
mount, that's minutes turned into potentially hours; a size/mtime
mismatch is the cheap 99% case, and the one edit it can't catch
(identical byte count *and* identical mtime) essentially never happens
by accident.

**An item's type comes from its library, not its file extension.** A
bare `.pdf` is genuinely ambiguous on its own — a scanned comic and a
magazine issue are both just "a PDF" — but the library it's found in
("BD Franco-Belge" vs "Sorties Périodiques") isn't. Each library has a
`type` (comic/ebook/magazine/other), set when it's created or edited,
and every item discovered under it gets that type; only the per-file
extension decides the stored `format`.

**Metadata and cover extraction happen automatically, tied to sync — not
as something any user (reader or admin) can trigger by hand.** There is
no "extract" button anywhere in the app on purpose: with a library that
can run into the thousands of items, one-at-a-time was never going to be
usable, and letting a reader trigger it made no sense to begin with.
`src/ItemEnrichment.php` (`ComicInfo.xml` reading, cover extraction —
same logic the earlier per-item buttons used, just relocated and now
reusable outside the API) runs right after `LibraryScanner` creates a
newly discovered item, so a normal, day-to-day sync stays incremental:
only the files that are actually new get processed, same as the file
discovery itself.

For a library synced before this existed — or any item extraction
genuinely never got to for some reason — `POST
/api/libraries/:id/extract-missing` processes a bounded batch (default
25, capped at 100) of items that have never been checked, and the
admin console's **"Extraire les métadonnées manquantes"** button calls
it repeatedly with a progress readout until nothing's left. "Never been
checked" is tracked with its own column, `items.metadata_checked_at` —
set the moment extraction is *attempted*, regardless of whether
anything was actually found. It's deliberately not inferred from
`cover_path IS NULL`: a magazine (no PDF cover support yet) or a
genuinely unreadable file will never have a cover no matter how many
times it's retried, and without this distinction a backfill batch would
just keep re-selecting the same permanently-empty items forever instead
of ever finishing.

Cover extraction failures are caught and logged, never thrown past
`ItemEnrichment::extractAndSaveCover()` — one unusual archive among
thousands must not take down a whole sync or backfill batch, or turn an
otherwise-successful metadata read into a confusing error for content
that already saved correctly.

**Sidecar files from other library tools don't get indexed as content.**
Anything starting with `.` is always skipped (macOS's `._*` AppleDouble
files, `.DS_Store` — never configurable, never legitimate content
either way). On top of that, Réglages has a **configurable exclude
pattern** (a PCRE regex, checked against each file/folder's own name) —
covers things like Ubooquity's per-folder `folder.jpg`, `header.jpg`,
`folder.css`, `folder-info.html`, which otherwise show up in the grid as
nonsense items ("folder", "header"...) since they're plain image files
sitting right next to the real content. The Réglages field includes a
live tester (checks a typed filename against the pattern using PHP's own
`preg_match` — same engine the scan itself uses, so it means what it
tests) and a **"Prévisualiser les fiches déjà scannées à tort"** action
for cleaning up anything indexed before the pattern existed or was
updated: lists every existing item whose file basename matches the
current pattern, and deletes them on confirmation. Only fixes what's in
the database — nothing on disk is touched.

From the admin console's Bibliothèques tab: a **"Synchroniser"** button
per library, batched (25 new files per call, the client loops until
nothing's left) so it shows live "X/Y" progress on a library with a lot
to index rather than one long silent request — `LibraryScanner::sync()`
takes an optional `$limit` for this; a `null` limit (what `sync-all` and
the cron sync token still use) processes everything in one pass, same
as before. No state needs tracking between batched calls: each call
walks and diffs the whole tree fresh, but a file a previous call already
inserted is now simply in the database and shows up as "unchanged"
instead of being re-inserted, so the loop just keeps going until
`added` comes back 0. A **"Tout synchroniser"** button repeats this
across every library — one library failing outright doesn't stop the
rest of the list from being attempted, and `items.path`'s collisions
(see "Deleting a library" below) are absorbed per-file rather than
aborting that library's whole sync.

**Scheduling** doesn't run inside the container — no cron daemon was
added, on the same "avoid another moving dependency" reasoning as
everything else in this project (`MiniZip.php`, the hand-written SMTP
client). Instead, `POST /api/sync-all` and `POST /api/libraries/:id/sync`
accept a shared secret as an `X-Sync-Token` header, as an alternative to
a logged-in session — meant for an external scheduler (a crontab entry
on the host, wherever Codex already runs) that has no browser session to
work with. The token lives in Réglages (view/regenerate it there —
regenerating immediately invalidates the old one), along with a
ready-to-copy example crontab line built from it and the configured
site URL. This exception is scoped narrowly: the token only unlocks the
two sync routes specifically (checked before the app's normal "every
request needs a session" rule even runs) — it's never accepted anywhere
else, so a leaked token can't be used to browse or manage anything
beyond triggering a sync.

## Deleting a library — items.path is unique across all of them

`items.path` is `UNIQUE` **across the whole table**, not per library —
it's stored relative to the shared `libraries/` mount, so two genuinely
different files under two different libraries never collide in
practice. `Libraries::delete()` used to rely purely on the schema's
`ON DELETE SET NULL` on `items.library_id` to detach a deleted
library's items, rather than actually deleting them — which left them
behind as permanent ghosts (`library_id IS NULL`, excluded from every
listing per the note in "Admin console" above, but still occupying
their `path` value in the unique index). Re-creating a library at the
same path and syncing it rediscovers the same files and collides with
its own leftovers: `SQLSTATE[23000]: UNIQUE constraint failed:
items.path`. `Libraries::delete()` now deletes the library's items
outright first; the admin console's "Fiches orphelines" cleanup handles
any ghosts left over from before this fix. `LibraryScanner::sync()`
also catches a `PDOException` on this specific constraint per-file now
(reported back as `conflicted`, shown in the sync result) rather than
letting it escape and abort the rest of that library's sync — a genuine
remaining path collision (two libraries whose folders actually
overlap, say) no longer takes an entire sync down with it.

## Local hosting

```bash
docker compose up -d
```

Same pattern as My Lost Treasure: `public/` read-only except
`public/assets/` (read-write, for covers/uploads later), `data/`
read-write, PHP upload limits raised for large scans (`docker/uploads.ini`,
which also sets `memory_limit = 512M` — see "Cover images"). The
container also checks for `pdo_sqlite`, `simplexml`, `poppler-utils`
(PDF rendering), and `gd` (thumbnail resizing) at startup and installs
whichever is missing — GD needs its own system libraries
(`libpng-dev`/`libjpeg-dev`/`libwebp-dev`) the base image doesn't ship,
so it's compiled the same "check and install what's missing" way as
the rest rather than baked into a custom image.
**None of these checks can block Apache from starting** — each step in
the startup command is independent (`|| true` / `|| echo 'WARN: ...'`
rather than a single `&&`-chained pipeline), and the final step is
`exec apache2-foreground`. Earlier revisions chained everything with
`&&`, which meant one failed step (e.g. an extension install failing
because the image has no network access, or lacks a build dependency)
silently killed the whole startup script before Apache ever ran — the
container would exit right after "Started", and a reverse proxy in front
of it would show a 502 with nothing useful in the logs to explain why.

## License

Personal project.
