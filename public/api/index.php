<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Items.php';
require_once __DIR__ . '/../../src/Tags.php';
require_once __DIR__ . '/../../src/Series.php';
require_once __DIR__ . '/../../src/Libraries.php';
require_once __DIR__ . '/../../src/Paths.php';
require_once __DIR__ . '/../../src/ComicInfo.php';
require_once __DIR__ . '/../../src/CoverExtractor.php';
require_once __DIR__ . '/../../src/Thumbnails.php';
require_once __DIR__ . '/../../src/Users.php';
require_once __DIR__ . '/../../src/Settings.php';
require_once __DIR__ . '/../../src/Mailer.php';
require_once __DIR__ . '/../../src/LibraryScanner.php';
require_once __DIR__ . '/../../src/ItemEnrichment.php';
require_once __DIR__ . '/../../src/ItemPages.php';
require_once __DIR__ . '/../../src/PdfRenderer.php';
require_once __DIR__ . '/../../src/LibraryGroups.php';
require_once __DIR__ . '/../../src/AppLog.php';
require_once __DIR__ . '/../../src/LibraryJobs.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
// Sync and the metadata/cover backfill can process a meaningful batch of
// files per request — PHP's usual few-second default isn't enough for that.
ini_set('max_execution_time', '300');
AppLog::bootstrap();

// Codex is a private personal library, not a public site with an admin
// section bolted on — every API call (reads included) requires a logged-in
// session, sent automatically by the browser via the session cookie once
// signed in through login.php. The one exception is the sync routes,
// which an external scheduler (a host crontab entry, say) needs to be
// able to call without ever having a browser session at all — those
// accept a valid X-Sync-Token header instead, checked below before the
// blanket session requirement, and nowhere else.
Auth::bootSession();

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$uri = preg_replace('#^/api/?#', '', $uri);
$segments = array_values(array_filter(explode('/', trim((string) $uri, '/')), fn($s) => $s !== ''));
$resource = $segments[0] ?? null;
$rawSecondSegment = $segments[1] ?? null; // kept as a string too — not every resource's 2nd segment is a numeric id (e.g. /api/settings/test-email)
$id = $rawSecondSegment !== null ? (int) $rawSecondSegment : null;
$action = $segments[2] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

$isSyncRoute = ($resource === 'sync-all') || ($resource === 'libraries' && $id !== null && $action === 'sync');
$syncTokenHeader = $_SERVER['HTTP_X_SYNC_TOKEN'] ?? '';
$hasValidSyncToken = $isSyncRoute && $syncTokenHeader !== '' && hash_equals(Settings::syncToken(), $syncTokenHeader);
if (!$hasValidSyncToken) {
    Auth::requireLoginApi();
}

function respond(int $code, $data): void
{
    AppLog::logRequest($_SERVER['REQUEST_METHOD'] ?? '?', $_SERVER['REQUEST_URI'] ?? '?', $code);
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bodyJson(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Extracts and saves a cover image for the given item, updating
 * cover_path in the DB. Returns the web-relative cover path on success,
 * null if no source image could be found.
 */
/** Resolve an array of tag names (creating any that don't exist yet) to their ids. */
function resolveTagIds(array $names): array
{
    $ids = [];
    foreach ($names as $name) {
        if (is_string($name) && trim($name) !== '') {
            $ids[] = Tags::findOrCreate($name);
        }
    }
    return $ids;
}

/** If the body carries a human-readable "series_name", resolve/create it and
 *  replace it with the corresponding series_id (null if left blank). */
function resolveSeriesName(array &$body): void
{
    if (array_key_exists('series_name', $body)) {
        $name = trim((string) $body['series_name']);
        $body['series_id'] = $name === '' ? null : Series::findOrCreate($name);
        unset($body['series_name']);
    }
}

/**
 * Returns the list of library ids the logged-in user is allowed to see,
 * or null if they're an admin (no restriction at all). A 'reader' with
 * no libraries explicitly assigned sees an empty array — nothing — not
 * "everything", so newly invited accounts default to seeing nothing until
 * an admin grants access.
 */
function currentUserAllowedLibraries(): ?array
{
    $current = Auth::currentUser();
    if (!$current || $current['role'] === 'admin') {
        return null;
    }
    $user = Users::find($current['id']);
    return $user['library_ids'] ?? [];
}

/**
 * Backfills file_size/file_mtime on an already-existing item — the
 * baseline LibraryScanner::sync() compares against on later syncs to
 * detect an edit in place (see schema.sql's comment on those columns).
 * A newly-created item gets these set immediately at insertion instead
 * (LibraryScanner::sync() does that directly); this is for everything
 * that predates the columns, backfilled the same way extract-missing and
 * regenerate-covers already backfill metadata/covers for old items.
 */
function updateFileStat(array $item): void
{
    $absPath = Paths::resolve($item['path']);
    $size = @filesize($absPath);
    $mtime = @filemtime($absPath);
    if ($size !== false || $mtime !== false) {
        Items::update((int) $item['id'], [
            'file_size' => $size !== false ? $size : null,
            'file_mtime' => $mtime !== false ? $mtime : null,
        ]);
    }
}

/** The éditeur nav's folder path travels as a JSON-encoded array of segments (e.g. ["Panini Books","Marvel"]) — a single delimited string would break on a folder name that itself contains the delimiter. Anything malformed or not a list of strings is treated as the root. */
function decodeGroupPath(string $raw): array
{
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    return array_values(array_map('strval', $decoded));
}

/**
 * Returns up to $lines of the end of $path, read backwards in fixed-size
 * chunks rather than loading the whole file — cheap regardless of how
 * large the log has grown. Returns an empty array (not an error) if the
 * file doesn't exist yet or isn't readable, since a fresh container with
 * no errors logged yet is the common case, not a failure.
 * @return list<string>
 */
function tailFile(string $path, int $lines): array
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }
    $chunkSize = 8192;
    $buffer = '';
    $newlineCount = 0;
    $pos = @filesize($path) ?: 0;

    while ($pos > 0 && $newlineCount <= $lines) {
        $readSize = min($chunkSize, $pos);
        $pos -= $readSize;
        fseek($handle, $pos);
        $chunk = fread($handle, $readSize);
        if ($chunk === false) {
            break;
        }
        $buffer = $chunk . $buffer;
        $newlineCount += substr_count($chunk, "\n");
    }
    fclose($handle);

    $allLines = explode("\n", rtrim($buffer, "\n"));
    return array_slice($allLines, -$lines);
}

/** Fetches an item and enforces the same library-access rule as the single-item GET route — a 404, not 403, so an out-of-scope item's existence isn't revealed. Ends the request itself on failure. */
function requireItemAccess(int $id): array
{
    $item = Items::find($id);
    if (!$item) {
        respond(404, ['error' => 'Item introuvable']);
    }
    $allowedLibraryIds = currentUserAllowedLibraries();
    if ($allowedLibraryIds !== null && !in_array((int) $item['library_id'], $allowedLibraryIds, true)) {
        respond(404, ['error' => 'Item introuvable']);
    }
    return $item;
}

try {
    switch ($resource) {
        case 'items':
            if ($method === 'GET' && $id === null) {
                // Listing/browsing the catalog is the one thing admins don't do here —
                // everything else about /api/items (single-item read for editing,
                // create, update, delete, metadata/cover extraction) stays open to them.
                Auth::requireReaderApi();
                $limit = min(300, max(1, (int) ($_GET['limit'] ?? 60)));
                $offset = max(0, (int) ($_GET['offset'] ?? 0));
                $filters = array_filter([
                    'type' => $_GET['type'] ?? null,
                    'library_id' => isset($_GET['library_id']) ? (int) $_GET['library_id'] : null,
                    'series_id' => isset($_GET['series_id']) ? (int) $_GET['series_id'] : null,
                    'tag_id' => isset($_GET['tag_id']) ? (int) $_GET['tag_id'] : null,
                    'query' => $_GET['q'] ?? null,
                ], fn($v) => $v !== null && $v !== '');
                $allowedLibraryIds = currentUserAllowedLibraries();
                if ($allowedLibraryIds !== null) {
                    if (!$allowedLibraryIds) {
                        respond(200, ['items' => [], 'total' => 0]);
                    }
                    $filters['library_ids'] = $allowedLibraryIds;
                }
                $groupLibraryId = isset($_GET['library_id']) && $_GET['library_id'] !== '' ? (int) $_GET['library_id'] : null;
                if (isset($_GET['path']) && $_GET['path'] !== '' && !empty($filters['type'])) {
                    $path = decodeGroupPath((string) $_GET['path']);
                    // exact=1 means "standalone tomes sitting directly under this exact
                    // folder, with no further subfolder" — the items shown alongside a
                    // level's subfolder tiles rather than reached through one of them.
                    $filters['ids'] = LibraryGroups::itemIdsMatching((string) $filters['type'], $allowedLibraryIds, $path, !empty($_GET['exact']), $groupLibraryId);
                    if (!$filters['ids']) {
                        respond(200, ['items' => [], 'total' => 0]);
                    }
                }
                $sort = (string) ($_GET['sort'] ?? 'title');
                $dir = (string) ($_GET['dir'] ?? 'ASC');
                respond(200, Items::search($filters, $sort, $dir, $limit, $offset));
            }
            if ($method === 'GET' && $id !== null && $action === null) {
                $item = Items::find($id);
                if (!$item) {
                    respond(404, ['error' => 'Item introuvable']);
                }
                $allowedLibraryIds = currentUserAllowedLibraries();
                if ($allowedLibraryIds !== null && !in_array((int) $item['library_id'], $allowedLibraryIds, true)) {
                    respond(404, ['error' => 'Item introuvable']);
                }
                respond(200, $item);
            }
            if ($method === 'POST' && $id === null) {
                $body = bodyJson();
                resolveSeriesName($body);
                $type = (string) ($body['type'] ?? '');
                $newId = Items::create($type, $body);
                if (!empty($body['tags']) && is_array($body['tags'])) {
                    Items::setTags($newId, resolveTagIds($body['tags']));
                }
                respond(201, Items::find($newId));
            }
            if ($method === 'PUT' && $id !== null && $action === null) {
                $current = Auth::currentUser();
                if ($current && $current['role'] === 'reader_basic') {
                    respond(403, ['error' => "Ce compte n'est pas autorisé à modifier les fiches"]);
                }
                $body = bodyJson();
                resolveSeriesName($body);
                Items::update($id, $body);
                if (array_key_exists('tags', $body) && is_array($body['tags'])) {
                    Items::setTags($id, resolveTagIds($body['tags']));
                }
                $item = Items::find($id);
                if (!$item) {
                    respond(404, ['error' => 'Item introuvable']);
                }
                respond(200, $item);
            }
            if ($method === 'DELETE' && $id !== null && $action === null) {
                Items::delete($id);
                respond(200, ['deleted' => true]);
            }
            if ($method === 'GET' && $id !== null && $action === 'download') {
                $item = requireItemAccess($id);
                $absPath = Paths::resolve($item['path']);
                if (!is_file($absPath)) {
                    respond(404, ['error' => 'Fichier introuvable sur le disque']);
                }
                $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
                $safeTitle = preg_replace('/[\/\\\\:*?"<>|]+/', '_', (string) $item['title']) ?: 'fichier';
                header('Content-Type: application/octet-stream');
                header('Content-Length: ' . filesize($absPath));
                header('Content-Disposition: attachment; filename="' . $safeTitle . '.' . $ext . '"');
                readfile($absPath);
                exit;
            }
            if ($method === 'GET' && $id !== null && $action === 'pages') {
                $item = requireItemAccess($id);
                respond(200, ['count' => ItemPages::count($item)]);
            }
            if ($method === 'GET' && $id !== null && $action === 'page') {
                $item = requireItemAccess($id);
                $index = max(0, (int) ($_GET['index'] ?? 0));
                $page = ItemPages::page($item, $index);
                if ($page === null) {
                    respond(404, ['error' => 'Page introuvable']);
                }
                $mimeTypes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
                header('Content-Type: ' . ($mimeTypes[$page['ext']] ?? 'application/octet-stream'));
                header('Content-Length: ' . strlen($page['data']));
                header('Cache-Control: private, max-age=3600'); // pages don't change once an item's been added; the browser can hold onto them
                echo $page['data'];
                exit;
            }
            if ($id !== null && $action === 'progress') {
                $item = requireItemAccess($id);
                $userId = (int) Auth::currentUser()['id'];
                $pdo = Database::connection();

                if ($method === 'GET') {
                    $stmt = $pdo->prepare('SELECT position, total_pages, completed_at FROM reading_progress WHERE user_id = ? AND item_id = ?');
                    $stmt->execute([$userId, $id]);
                    $row = $stmt->fetch();
                    respond(200, $row ?: ['position' => null, 'total_pages' => null, 'completed_at' => null]);
                }
                if ($method === 'PUT') {
                    $body = bodyJson();
                    $existing = (function () use ($pdo, $userId, $id) {
                        $stmt = $pdo->prepare('SELECT position, total_pages, completed_at FROM reading_progress WHERE user_id = ? AND item_id = ?');
                        $stmt->execute([$userId, $id]);
                        return $stmt->fetch() ?: null;
                    })();

                    $position = array_key_exists('current_page', $body) ? (string) (int) $body['current_page'] : ($existing['position'] ?? null);
                    $totalPages = array_key_exists('total_pages', $body) ? (int) $body['total_pages'] : ($existing['total_pages'] ?? null);
                    $completedAt = $existing['completed_at'] ?? null;
                    if (array_key_exists('completed', $body)) {
                        $completedAt = $body['completed'] ? date('c') : null;
                        if ($body['completed'] && $totalPages && !array_key_exists('current_page', $body)) {
                            $position = (string) max(0, $totalPages - 1);
                        }
                    }

                    $stmt = $pdo->prepare(
                        'INSERT INTO reading_progress (user_id, item_id, position, total_pages, completed_at, updated_at)
                         VALUES (:uid, :iid, :pos, :total, :completed, :updated)
                         ON CONFLICT(user_id, item_id) DO UPDATE SET
                           position = excluded.position, total_pages = excluded.total_pages,
                           completed_at = excluded.completed_at, updated_at = excluded.updated_at'
                    );
                    $stmt->execute([
                        ':uid' => $userId, ':iid' => $id, ':pos' => $position, ':total' => $totalPages,
                        ':completed' => $completedAt, ':updated' => date('c'),
                    ]);
                    respond(200, ['position' => $position, 'total_pages' => $totalPages, 'completed_at' => $completedAt]);
                }
                if ($method === 'DELETE') {
                    $pdo->prepare('DELETE FROM reading_progress WHERE user_id = ? AND item_id = ?')->execute([$userId, $id]);
                    respond(200, ['reset' => true]);
                }
            }
            respond(405, ['error' => 'Méthode non autorisée']);

        case 'libraries':
            if ($method === 'GET' && $id === null) {
                // Filtered to the current user's own access (all of them for an
                // admin) — this same endpoint backs both the admin's Bibliothèques
                // tab and the reader's sidebar, and the reader must never see a
                // library it isn't allowed into, not even just its name.
                $allowed = currentUserAllowedLibraries();
                $libs = Libraries::all();
                if ($allowed !== null) {
                    $libs = array_values(array_filter($libs, fn($l) => in_array((int) $l['id'], $allowed, true)));
                }
                // One item_count per library, added here rather than in Libraries::all()
                // itself — that method is also used by callers (sync, the éditeur nav)
                // that have no use for it and would pay for the join every time.
                $counts = Database::connection()
                    ->query('SELECT library_id, COUNT(*) AS c FROM items GROUP BY library_id')
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
                foreach ($libs as &$lib) {
                    $lib['item_count'] = $counts[$lib['id']] ?? 0;
                }
                unset($lib);
                respond(200, $libs);
            }
            if ($method === 'POST' && $id === null) {
                Auth::requireAdminApi();
                $body = bodyJson();
                $newId = Libraries::create(
                    (string) ($body['name'] ?? ''),
                    (string) ($body['path'] ?? ''),
                    (string) ($body['type'] ?? 'comic')
                );
                respond(201, Libraries::find($newId));
            }
            if ($method === 'PUT' && $id !== null) {
                Auth::requireAdminApi();
                Libraries::update($id, bodyJson());
                $lib = Libraries::find($id);
                if (!$lib) {
                    respond(404, ['error' => 'Bibliothèque introuvable']);
                }
                respond(200, $lib);
            }
            if ($method === 'DELETE' && $id !== null) {
                Auth::requireAdminApi();
                Libraries::delete($id);
                respond(200, ['deleted' => true]);
            }
            if ($method === 'POST' && $id !== null && $action === 'sync') {
                Auth::requireAdminOrSyncTokenApi();
                $lib = Libraries::find($id);
                if (!$lib) {
                    respond(404, ['error' => 'Bibliothèque introuvable']);
                }
                // No limit by default (a cron job calling this via the sync token
                // wants one full pass, not a batch to loop over) — the admin UI's
                // own "Synchroniser" button is what actually passes one, to show
                // progress on a library with a lot to scan.
                $limit = isset($_GET['limit']) && $_GET['limit'] !== '' ? max(1, (int) $_GET['limit']) : null;
                $result = LibraryScanner::sync($lib, $limit);
                $done = $result['added'] + $result['updated'] + $result['unchanged'];
                if ($limit === null || ($result['added'] === 0 && $result['updated'] === 0)) {
                    LibraryJobs::finish($id, 'sync', $done, $result['total']);
                } else {
                    LibraryJobs::progress($id, 'sync', $done, $result['total']);
                }
                respond(200, $result);
            }
            if ($method === 'POST' && $id !== null && $action === 'extract-missing') {
                Auth::requireAdminOrSyncTokenApi();
                $lib = Libraries::find($id);
                if (!$lib) {
                    respond(404, ['error' => 'Bibliothèque introuvable']);
                }
                $limit = min(100, max(1, (int) ($_GET['limit'] ?? 25)));
                $pdo = Database::connection();
                // No explicit "start" call needed — a running job for this type
                // already has a "done" count to build on; anything else (no row,
                // a different job type, or one that already finished) means this
                // is a fresh run, so the baseline is 0.
                $existing = LibraryJobs::all()[$id] ?? null;
                $baseDone = ($existing && $existing['job_type'] === 'extract-missing' && $existing['status'] === 'running') ? $existing['done'] : 0;

                $stmt = $pdo->prepare('SELECT * FROM items WHERE library_id = :lib AND metadata_checked_at IS NULL LIMIT :lim');
                $stmt->bindValue(':lib', $id, PDO::PARAM_INT);
                $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
                $stmt->execute();
                $batch = $stmt->fetchAll();

                $remainingBeforeStmt = $pdo->prepare('SELECT COUNT(*) FROM items WHERE library_id = ? AND metadata_checked_at IS NULL');
                $remainingBeforeStmt->execute([$id]);
                $totalEstimate = $baseDone + (int) $remainingBeforeStmt->fetchColumn();

                foreach ($batch as $index => $item) {
                    LibraryJobs::working($id, 'extract-missing', $baseDone + $index, $totalEstimate, $item['title'] ?: basename((string) $item['path']));
                    AppLog::note("extract-missing: item {$item['id']} ({$item['path']}) en cours");
                    ItemEnrichment::run($item);
                    updateFileStat($item);
                    AppLog::note("extract-missing: item {$item['id']} ok");
                }
                $remainingStmt = $pdo->prepare('SELECT COUNT(*) FROM items WHERE library_id = ? AND metadata_checked_at IS NULL');
                $remainingStmt->execute([$id]);
                $remaining = (int) $remainingStmt->fetchColumn();
                $done = $baseDone + count($batch);
                $total = $done + $remaining;
                if (count($batch) === 0 || $remaining === 0) {
                    LibraryJobs::finish($id, 'extract-missing', $done, $total);
                } else {
                    LibraryJobs::progress($id, 'extract-missing', $done, $total);
                }
                respond(200, ['processed' => count($batch), 'remaining' => $remaining]);
            }
            if ($method === 'POST' && $id !== null && $action === 'regenerate-covers') {
                // Separate from extract-missing on purpose: that one only ever
                // touches items that were never processed at all (metadata_checked_at
                // IS NULL). This one re-extracts the cover for every item regardless,
                // which is what's needed to shrink covers that were saved at full
                // resolution before GD became available — offset-paginated rather
                // than gated by a "done" flag, since there isn't one for this.
                Auth::requireAdminOrSyncTokenApi();
                $lib = Libraries::find($id);
                if (!$lib) {
                    respond(404, ['error' => 'Bibliothèque introuvable']);
                }
                $limit = min(100, max(1, (int) ($_GET['limit'] ?? 25)));
                $offset = max(0, (int) ($_GET['offset'] ?? 0));
                $pdo = Database::connection();
                $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM items WHERE library_id = ?');
                $totalStmt->execute([$id]);
                $total = (int) $totalStmt->fetchColumn();
                $stmt = $pdo->prepare('SELECT * FROM items WHERE library_id = :lib ORDER BY id LIMIT :lim OFFSET :off');
                $stmt->bindValue(':lib', $id, PDO::PARAM_INT);
                $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
                $stmt->execute();
                $batch = $stmt->fetchAll();
                foreach ($batch as $index => $item) {
                    LibraryJobs::working($id, 'regenerate-covers', $offset + $index, $total, $item['title'] ?: basename((string) $item['path']));
                    AppLog::note("regenerate-covers: item {$item['id']} ({$item['path']}) en cours");
                    ItemEnrichment::extractAndSaveCover($item);
                    updateFileStat($item);
                    AppLog::note("regenerate-covers: item {$item['id']} ok");
                }
                $done = $offset + count($batch);
                if ($done >= $total || count($batch) === 0) {
                    LibraryJobs::finish($id, 'regenerate-covers', $done, $total);
                } else {
                    LibraryJobs::progress($id, 'regenerate-covers', $done, $total);
                }
                respond(200, ['processed' => count($batch), 'offset' => $done, 'total' => $total]);
            }
            respond(405, ['error' => 'Méthode non autorisée']);

        case 'display-settings':
            if ($method === 'GET') {
                respond(200, [
                    'show_publishers' => Settings::showPublishers(),
                    'thumbnail_width' => Settings::thumbnailWidth(),
                    'thumbnail_height' => Settings::thumbnailHeight(),
                    'grid_columns' => Settings::gridColumns(),
                    'grid_page_size' => Settings::gridPageSize(),
                    'home_shelf_columns' => Settings::homeShelfColumns(),
                    'home_shelf_rows' => Settings::homeShelfRows(),
                    'home_shelf_fetch_limit' => Settings::homeShelfFetchLimit(),
                ]);
            }
            respond(405, ['error' => 'Méthode non autorisée']);

        case 'library-groups':
            Auth::requireReaderApi();
            if ($method === 'GET') {
                $type = (string) ($_GET['type'] ?? '');
                if ($type === '') {
                    respond(400, ['error' => 'Paramètre type requis']);
                }
                respond(200, LibraryGroups::listLibrariesForType($type, currentUserAllowedLibraries(), Settings::showEmptyLibrariesInNav()));
            }
            respond(405, ['error' => 'Méthode non autorisée']);

        case 'subfolders':
            Auth::requireReaderApi();
            if ($method === 'GET') {
                $type = (string) ($_GET['type'] ?? '');
                if ($type === '') {
                    respond(400, ['error' => 'Paramètre type requis']);
                }
                $libraryId = isset($_GET['library_id']) && $_GET['library_id'] !== '' ? (int) $_GET['library_id'] : null;
                $path = isset($_GET['path']) && $_GET['path'] !== '' ? decodeGroupPath((string) $_GET['path']) : [];
                respond(200, LibraryGroups::listSubfolders($type, currentUserAllowedLibraries(), $libraryId, $path));
            }
            respond(405, ['error' => 'Méthode non autorisée']);

        case 'folder-thumbnail':
            if ($method === 'GET') {
                $requested = trim((string) ($_GET['path'] ?? ''), '/');
                if ($requested === '' || preg_match('#(^|/)\.\.(/|$)#', $requested)) {
                    respond(400, ['error' => 'Chemin invalide']);
                }
                $ext = strtolower(pathinfo($requested, PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                    respond(400, ['error' => 'Chemin invalide']);
                }
                // Confirm the requested path actually falls under a library this
                // user can see — this is only ever built from our own listing
                // output, but a direct/crafted request shouldn't be able to peek
                // at a folder from a library the caller has no access to.
                $allowedLibraryIds = currentUserAllowedLibraries();
                $owningLibrary = null;
                foreach (Libraries::all() as $lib) {
                    $prefix = trim($lib['path'], '/') . '/';
                    if (str_starts_with($requested, $prefix)) {
                        $owningLibrary = $lib;
                        break;
                    }
                }
                if ($owningLibrary === null || ($allowedLibraryIds !== null && !in_array((int) $owningLibrary['id'], $allowedLibraryIds, true))) {
                    respond(404, ['error' => 'Introuvable']);
                }
                $abs = Paths::libraryRoot() . '/' . $requested;
                if (!is_file($abs)) {
                    respond(404, ['error' => 'Introuvable']);
                }
                $mimeTypes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];

                // folder.jpg etc. live in the read-only library mount, so unlike an
                // extracted cover they can't be resized once and saved next to the
                // source — cache the resized copy here instead, keyed on the source's
                // own mtime so a replaced folder.jpg invalidates it automatically.
                if (Thumbnails::available()) {
                    $publicDir = realpath(__DIR__ . '/..');
                    $cacheDir = $publicDir . '/assets/folder-thumbs';
                    $cachePath = $cacheDir . '/' . sha1($requested) . '-' . filemtime($abs) . '-' . Settings::thumbnailWidth() . '.jpg';
                    if (!is_file($cachePath)) {
                        $resized = Thumbnails::resizeFile($abs);
                        if ($resized !== null && (is_dir($cacheDir) || @mkdir($cacheDir, 0775, true))) {
                            @file_put_contents($cachePath, $resized);
                        }
                    }
                    if (is_file($cachePath)) {
                        header('Content-Type: image/jpeg');
                        header('Cache-Control: private, max-age=86400');
                        readfile($cachePath);
                        exit;
                    }
                }

                header('Content-Type: ' . $mimeTypes[$ext]);
                header('Cache-Control: private, max-age=3600');
                readfile($abs);
                exit;
            }
            respond(405, ['error' => 'Méthode non autorisée']);

        case 'sync-all':
            Auth::requireAdminOrSyncTokenApi();
            if ($method === 'POST') {
                $results = [];
                foreach (Libraries::all() as $lib) {
                    try {
                        $result = LibraryScanner::sync($lib);
                        LibraryJobs::finish($lib['id'], 'sync', $result['added'] + $result['updated'] + $result['unchanged'], $result['total']);
                        $results[] = ['library' => $lib['name'], 'id' => $lib['id']] + $result;
                    } catch (Throwable $e) {
                        // One library failing outright (not just a per-file conflict,
                        // which LibraryScanner::sync already absorbs — something worse,
                        // an unreadable mount, a corrupt row) must not stop every
                        // library after it in the list from being synced too.
                        LibraryJobs::fail($lib['id'], 'sync', 0, null, $e->getMessage());
                        $results[] = ['library' => $lib['name'], 'id' => $lib['id'], 'error' => $e->getMessage()];
                    }
                }
                respond(200, ['libraries' => $results]);
            }
            respond(405, ['error' => 'Méthode non autorisée']);

        case 'logs':
            // Read-only tail of this app's own PHP-level logs (src/AppLog.php)
            // — deliberately not Apache's native ErrorLog/CustomLog. In the
            // base php:apache image those are symlinked to /dev/stdout/stderr
            // (so `docker logs` captures them), which a plain fopen() can't
            // read back; redirecting Apache's own config to log into data/
            // instead caused a server-wide 500 that was never root-caused and
            // had to be reverted. Logging from PHP's own error_log mechanism
            // sidesteps all of that — it still catches every error level,
            // including a raw fatal like memory exhaustion (PHP's engine
            // writes that to error_log itself before terminating, independent
            // of any try/catch), and never touches httpd's config at all.
            Auth::requireAdminApi();
            if ($method === 'GET') {
                $which = ($_GET['log'] ?? 'error') === 'access' ? 'access' : 'error';
                $path = $which === 'access' ? AppLog::accessLogPath() : AppLog::errorLogPath();
                $lines = min(1000, max(10, (int) ($_GET['lines'] ?? 200)));
                if (!is_file($path)) {
                    respond(200, ['path' => $path, 'lines' => [], 'note' => "Fichier introuvable — rien n'a encore été écrit à cet emplacement."]);
                }
                if (!is_readable($path)) {
                    respond(200, ['path' => $path, 'lines' => [], 'note' => "Le fichier existe mais n'est pas lisible par le processus PHP (permissions)."]);
                }
                respond(200, ['path' => $path, 'lines' => tailFile($path, $lines)]);
            }
            respond(405, ['error' => 'Méthode non autorisée']);

        case 'backup':
            // A consistent snapshot even with concurrent writers — VACUUM INTO
            // runs inside its own read transaction and is SQLite's own built-in
            // way to do this; copying the raw file while WAL is active risks a
            // torn, unusable copy. The target must not already exist, hence the
            // timestamped filename — collisions across two backups in the same
            // second are the only real risk, and vanishingly unlikely from a
            // single admin clicking a button.
            Auth::requireAdminApi();
            if ($method === 'GET') {
                $dataDir = realpath(__DIR__ . '/../../data');
                $tmpPath = $dataDir . '/backup-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sqlite';
                register_shutdown_function(static function () use ($tmpPath) {
                    @unlink($tmpPath); // always clean up the scratch copy, whether readfile finished, the connection dropped, or VACUUM itself failed partway
                });
                try {
                    Database::connection()->exec('VACUUM INTO ' . Database::connection()->quote($tmpPath));
                } catch (Throwable $e) {
                    respond(500, ['error' => 'Échec de la sauvegarde : ' . $e->getMessage()]);
                }
                if (!is_file($tmpPath)) {
                    respond(500, ['error' => "La sauvegarde n'a produit aucun fichier."]);
                }
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="codex-backup-' . date('Ymd-His') . '.sqlite"');
                header('Content-Length: ' . filesize($tmpPath));
                readfile($tmpPath);
                exit;
            }
            respond(405, ['error' => 'Méthode non autorisée']);

        case 'login-attempts':
            // Self-service unlock — without this, an admin locked out by the
            // same brute-force protection everyone else gets (5 failed
            // attempts, 15 minutes) has no way back in except editing the
            // database directly.
            Auth::requireAdminApi();
            if ($method === 'GET') {
                respond(200, Auth::loginAttempts());
            }
            if ($method === 'DELETE') {
                $ip = (string) ($_GET['ip'] ?? '');
                if ($ip === '') {
                    respond(400, ['error' => 'IP requise']);
                }
                Auth::clearLoginAttemptsFor($ip);
                respond(200, ['cleared' => true]);
            }
            respond(405, ['error' => 'Méthode non autorisée']);

        case 'system-status':
            Auth::requireAdminApi();
            if ($method === 'GET') {
                $pdo = Database::connection();
                $dbPath = realpath(__DIR__ . '/../../data/codex.sqlite');
                respond(200, [
                    'php_version' => PHP_VERSION,
                    'gd_available' => Thumbnails::available(),
                    'poppler_available' => PdfRenderer::available(),
                    'smtp_configured' => Settings::isSmtpConfigured(),
                    'db_size_bytes' => $dbPath ? (@filesize($dbPath) ?: 0) : 0,
                    'item_count' => (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn(),
                    'items_missing_metadata' => (int) $pdo->query('SELECT COUNT(*) FROM items WHERE metadata_checked_at IS NULL')->fetchColumn(),
                    'items_missing_cover' => (int) $pdo->query('SELECT COUNT(*) FROM items WHERE cover_path IS NULL')->fetchColumn(),
                    'user_count' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
                    'library_count' => (int) $pdo->query('SELECT COUNT(*) FROM libraries')->fetchColumn(),
                ]);
            }
            respond(405, ['error' => 'Méthode non autorisée']);

        case 'library-jobs':
            // The persistent "where are we" behind each library's status line —
            // see schema.sql's library_jobs table. Read on every load of the
            // Bibliothèques tab, not just while a batch loop is actively running,
            // so returning to the page (or another admin logging in elsewhere)
            // shows the last known state rather than nothing.
            Auth::requireAdminApi();
            if ($method === 'GET') {
                respond(200, LibraryJobs::all());
            }
            respond(405, ['error' => 'Méthode non autorisée']);

        case 'orphaned-items':
            // Ghost items left behind by library deletion before Libraries::delete()
            // explicitly cleaned them up (it used to rely on the DB's ON DELETE SET
            // NULL, which detaches an item from its library without removing it) —
            // items.path being unique across every library means these block any
            // future sync that rediscovers the same file under a re-created library
            // at the same path. Same GET-preview/POST-delete shape as cleanup-excluded.
            Auth::requireAdminApi();
            $matches = Database::connection()->query('SELECT id, title, path FROM items WHERE library_id IS NULL')->fetchAll();
            if ($method === 'GET') {
                respond(200, ['matches' => $matches]);
            }
            if ($method === 'POST') {
                foreach ($matches as $row) {
                    Items::delete((int) $row['id']);
                }
                respond(200, ['deleted' => count($matches), 'items' => $matches]);
            }
            respond(405, ['error' => 'Méthode non autorisée']);

        case 'cleanup-excluded':
            Auth::requireAdminApi();
            $pattern = Settings::scanExcludePattern();
            $pdo = Database::connection();
            $matches = [];
            foreach ($pdo->query('SELECT id, title, path FROM items')->fetchAll() as $row) {
                $basename = basename((string) $row['path']);
                if (@preg_match($pattern, $basename) === 1) {
                    $matches[] = $row;
                }
            }
            if ($method === 'GET') {
                respond(200, ['pattern' => $pattern, 'matches' => $matches]);
            }
            if ($method === 'POST') {
                foreach ($matches as $row) {
                    Items::delete((int) $row['id']);
                }
                respond(200, ['deleted' => count($matches), 'items' => $matches]);
            }
            respond(405, ['error' => 'Méthode non autorisée']);

        case 'series':
            Auth::requireReaderApi();
            if ($method === 'GET' && $id === null) {
                respond(200, Series::all());
            }
            if ($method === 'GET' && $id !== null) {
                $s = Series::find($id);
                if (!$s) {
                    respond(404, ['error' => 'Série introuvable']);
                }
                respond(200, $s);
            }
            if ($method === 'POST' && $id === null) {
                $newId = Series::create(bodyJson());
                respond(201, Series::find($newId));
            }
            if ($method === 'PUT' && $id !== null) {
                Series::update($id, bodyJson());
                $s = Series::find($id);
                if (!$s) {
                    respond(404, ['error' => 'Série introuvable']);
                }
                respond(200, $s);
            }
            if ($method === 'DELETE' && $id !== null) {
                Series::delete($id);
                respond(200, ['deleted' => true]);
            }
            respond(405, ['error' => 'Méthode non autorisée']);

        case 'tags':
            Auth::requireReaderApi();
            if ($method === 'GET' && $id === null) {
                respond(200, Tags::all());
            }
            if ($method === 'POST' && $id === null) {
                $body = bodyJson();
                $newId = Tags::findOrCreate((string) ($body['name'] ?? ''));
                respond(201, ['id' => $newId, 'name' => trim((string) $body['name'])]);
            }
            if ($method === 'DELETE' && $id !== null) {
                Tags::delete($id);
                respond(200, ['deleted' => true]);
            }
            respond(405, ['error' => 'Méthode non autorisée']);

        case 'users':
            Auth::requireAdminApi();

            if ($method === 'GET' && $id === null) {
                respond(200, Users::all());
            }
            if ($method === 'GET' && $id !== null) {
                $u = Users::find($id);
                if (!$u) {
                    respond(404, ['error' => 'Utilisateur introuvable']);
                }
                respond(200, $u);
            }
            if ($method === 'POST' && $id === null) {
                $body = bodyJson();
                [$user, $token] = Users::invite(
                    (string) ($body['username'] ?? ''),
                    (string) ($body['email'] ?? ''),
                    (string) ($body['role'] ?? 'reader'),
                    is_array($body['library_ids'] ?? null) ? $body['library_ids'] : [],
                    !empty($body['mfa_required'])
                );
                $inviteUrl = Settings::siteUrl() . '/accept-invite.php?token=' . urlencode($token);
                $mailResult = Mailer::send(
                    (string) $body['email'],
                    'Ton accès à Codex',
                    "Bonjour {$user['username']},\n\n"
                    . "Un accès à la bibliothèque Codex vient de t'être créé.\n"
                    . "Choisis ton mot de passe ici pour l'activer :\n\n{$inviteUrl}\n\n"
                    . "Ce lien expire dans 7 jours."
                );
                respond(201, [
                    'user' => $user,
                    'inviteUrl' => $inviteUrl,
                    'emailSent' => $mailResult['ok'],
                    'emailError' => $mailResult['error'],
                ]);
            }
            if ($method === 'PUT' && $id !== null) {
                $body = bodyJson();
                if (array_key_exists('role', $body)) {
                    $current = Users::find($id);
                    if ($current && $current['role'] === 'admin' && $body['role'] !== 'admin' && Users::adminCount() <= 1) {
                        respond(400, ['error' => "Impossible de retirer le dernier compte administrateur"]);
                    }
                    Users::updateRole($id, (string) $body['role']);
                }
                if (array_key_exists('library_ids', $body) && is_array($body['library_ids'])) {
                    Users::setLibraries($id, $body['library_ids']);
                }
                if (array_key_exists('mfa_required', $body)) {
                    Users::setMfaRequired($id, (bool) $body['mfa_required']);
                }
                $u = Users::find($id);
                if (!$u) {
                    respond(404, ['error' => 'Utilisateur introuvable']);
                }
                respond(200, $u);
            }
            if ($method === 'DELETE' && $id !== null) {
                $current = Users::find($id);
                if (!$current) {
                    respond(404, ['error' => 'Utilisateur introuvable']);
                }
                if ($current['role'] === 'admin' && Users::adminCount() <= 1) {
                    respond(400, ['error' => 'Impossible de supprimer le dernier compte administrateur']);
                }
                if (Auth::currentUser()['id'] === $id) {
                    respond(400, ['error' => 'Impossible de supprimer ton propre compte']);
                }
                Users::delete($id);
                respond(200, ['deleted' => true]);
            }
            respond(405, ['error' => 'Méthode non autorisée']);

        case 'invites':
            Auth::requireAdminApi();
            if ($method === 'POST' && $id !== null && $action === 'resend') {
                $user = Users::find($id);
                if (!$user) {
                    respond(404, ['error' => 'Utilisateur introuvable']);
                }
                $token = Users::regenerateInvite($id);
                $inviteUrl = Settings::siteUrl() . '/accept-invite.php?token=' . urlencode($token);
                $mailResult = Mailer::send(
                    (string) $user['email'],
                    'Ton accès à Codex',
                    "Bonjour {$user['username']},\n\nVoici un nouveau lien pour activer ton accès à Codex :\n\n{$inviteUrl}\n\nCe lien expire dans 7 jours."
                );
                respond(200, [
                    'inviteUrl' => $inviteUrl,
                    'emailSent' => $mailResult['ok'],
                    'emailError' => $mailResult['error'],
                ]);
            }
            respond(405, ['error' => 'Méthode non autorisée']);

        case 'browse-libraries':
            Auth::requireAdminApi();
            if ($method === 'GET') {
                $root = realpath(Paths::libraryRoot());
                if ($root === false) {
                    respond(500, [
                        'error' => "Le dossier libraries/ est introuvable dans le conteneur. "
                            . "Vérifie qu'il existe sur l'hôte à côté de compose.yml, et que compose.yml "
                            . "contient bien le montage \"./libraries:/var/www/html/libraries:ro\".",
                    ]);
                }
                $requested = trim((string) ($_GET['path'] ?? ''), '/');
                // Reject literal ".." segments in the requested path — the UI
                // itself only ever sends back a path this same endpoint
                // returned (a listed entry, or a computed parent), so this
                // only matters against a hand-crafted request. It's checked
                // on the logical (unresolved) path, not the real one below,
                // because a symlink *inside* libraries/ is meant to point
                // anywhere on the host — that's the supported way to expose
                // an existing collection without duplicating it — so the
                // resolved real path is deliberately not required to stay
                // under $root the way the requested one is.
                if ($requested !== '' && preg_match('#(^|/)\.\.(/|$)#', $requested)) {
                    respond(400, ['error' => 'Chemin invalide']);
                }
                $targetAbs = $requested === '' ? $root : $root . '/' . $requested;
                if (!is_dir($targetAbs)) {
                    respond(400, ['error' => 'Chemin invalide ou introuvable']);
                }
                $entries = [];
                foreach (scandir($targetAbs) ?: [] as $item) {
                    if ($item === '.' || $item === '..' || str_starts_with($item, '.')) {
                        continue;
                    }
                    $itemAbs = $targetAbs . '/' . $item;
                    if (is_dir($itemAbs)) {
                        $entries[] = ['name' => $item, 'path' => $requested === '' ? $item : $requested . '/' . $item];
                    }
                }
                usort($entries, fn($a, $b) => strcasecmp($a['name'], $b['name']));

                $parent = null;
                if ($requested !== '') {
                    $parentRel = dirname($requested);
                    $parent = $parentRel === '.' ? '' : $parentRel;
                }
                respond(200, ['path' => $requested, 'parent' => $parent, 'entries' => $entries]);
            }
            respond(405, ['error' => 'Méthode non autorisée']);

        case 'settings':
            Auth::requireAdminApi();
            if ($method === 'GET') {
                $config = Settings::smtpConfig();
                $config['smtp_password_set'] = !empty($config['smtp_password']);
                unset($config['smtp_password']);
                $config['site_url'] = Settings::siteUrl();
                $config['sync_token'] = Settings::syncToken();
                $config['scan_exclude_pattern'] = Settings::scanExcludePattern();
                $config['show_publishers'] = Settings::showPublishers();
                $config['show_empty_libraries_nav'] = Settings::showEmptyLibrariesInNav();
                $config['thumbnail_width'] = Settings::thumbnailWidth();
                $config['thumbnail_height'] = Settings::thumbnailHeight();
                $config['grid_columns'] = Settings::gridColumns();
                $config['grid_page_size'] = Settings::gridPageSize();
                $config['home_shelf_columns'] = Settings::homeShelfColumns();
                $config['home_shelf_rows'] = Settings::homeShelfRows();
                $config['home_shelf_fetch_limit'] = Settings::homeShelfFetchLimit();
                $config['gd_available'] = Thumbnails::available();
                respond(200, $config);
            }
            if ($method === 'PUT') {
                $body = bodyJson();
                if (isset($body['site_url'])) {
                    Settings::set('site_url', trim((string) $body['site_url']) ?: null);
                    unset($body['site_url']);
                }
                if (isset($body['scan_exclude_pattern'])) {
                    $pattern = (string) $body['scan_exclude_pattern'];
                    $error = Settings::validateScanExcludePattern($pattern);
                    if ($error !== null) {
                        respond(400, ['error' => $error]);
                    }
                    Settings::setScanExcludePattern($pattern);
                    unset($body['scan_exclude_pattern']);
                }
                if (isset($body['show_publishers'])) {
                    Settings::setShowPublishers((bool) $body['show_publishers']);
                    unset($body['show_publishers']);
                }
                if (isset($body['show_empty_libraries_nav'])) {
                    Settings::setShowEmptyLibrariesInNav((bool) $body['show_empty_libraries_nav']);
                    unset($body['show_empty_libraries_nav']);
                }
                if (isset($body['thumbnail_width'])) {
                    Settings::setThumbnailWidth((int) $body['thumbnail_width']);
                    unset($body['thumbnail_width']);
                }
                if (isset($body['grid_columns'])) {
                    Settings::setGridColumns((int) $body['grid_columns']);
                    unset($body['grid_columns']);
                }
                if (isset($body['grid_page_size'])) {
                    Settings::setGridPageSize((int) $body['grid_page_size']);
                    unset($body['grid_page_size']);
                }
                if (isset($body['home_shelf_columns'])) {
                    Settings::setHomeShelfColumns((int) $body['home_shelf_columns']);
                    unset($body['home_shelf_columns']);
                }
                if (isset($body['home_shelf_rows'])) {
                    Settings::setHomeShelfRows((int) $body['home_shelf_rows']);
                    unset($body['home_shelf_rows']);
                }
                if (isset($body['home_shelf_fetch_limit'])) {
                    Settings::setHomeShelfFetchLimit((int) $body['home_shelf_fetch_limit']);
                    unset($body['home_shelf_fetch_limit']);
                }
                if (isset($body['smtp_password']) && trim((string) $body['smtp_password']) === '') {
                    unset($body['smtp_password']); // blank = "leave unchanged", not "clear it"
                }
                Settings::setSmtpConfig($body);
                respond(200, ['saved' => true]);
            }
            if ($method === 'POST' && $rawSecondSegment === 'test-email') {
                $body = bodyJson();
                $to = (string) ($body['to'] ?? '');
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    respond(400, ['error' => 'Adresse e-mail invalide']);
                }
                $result = Mailer::send($to, 'Test Codex', "Ceci est un e-mail de test envoyé depuis Codex.\n\nSi tu le reçois, la configuration SMTP fonctionne.");
                respond(200, ['sent' => $result['ok'], 'error' => $result['error']]);
            }
            if ($method === 'POST' && $rawSecondSegment === 'regenerate-sync-token') {
                respond(200, ['sync_token' => Settings::regenerateSyncToken()]);
            }
            if ($method === 'POST' && $rawSecondSegment === 'test-exclude-pattern') {
                $body = bodyJson();
                $pattern = (string) ($body['pattern'] ?? '');
                $filename = (string) ($body['filename'] ?? '');
                $error = Settings::validateScanExcludePattern($pattern);
                if ($error !== null) {
                    respond(400, ['error' => $error]);
                }
                $matches = $pattern !== '' && @preg_match($pattern, $filename) === 1;
                respond(200, ['matches' => $matches]);
            }
            respond(405, ['error' => 'Méthode non autorisée']);

        default:
            respond(404, ['error' => 'Route API inconnue']);
    }
} catch (InvalidArgumentException $e) {
    respond(400, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    respond(500, ['error' => 'Erreur serveur', 'detail' => $e->getMessage()]);
}
