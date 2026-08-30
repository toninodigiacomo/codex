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

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Codex is a private personal library, not a public site with an admin
// section bolted on — every API call (reads included) requires a logged-in
// session, sent automatically by the browser via the session cookie once
// signed in through login.php.
Auth::bootSession();
Auth::requireLoginApi();

function respond(int $code, $data): void
{
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
 * Extracts and saves a cover thumbnail for the given item, updating
 * cover_path in the DB. Returns the web-relative cover path on success,
 * null if no source image could be found (or GD is unavailable).
 */
function extractAndSaveCover(array $item): ?string
{
    if (!Thumbnails::available()) {
        return null;
    }
    $absPath = Paths::resolve($item['path']);
    $found = CoverExtractor::forItem($absPath, $item['type']);
    if ($found === null) {
        return null;
    }
    $publicDir = realpath(__DIR__ . '/..');
    $relPath = 'assets/covers/' . $item['id'] . '.jpg';
    if (!Thumbnails::saveResized($found['data'], $publicDir . '/' . $relPath)) {
        return null;
    }
    Items::update($item['id'], ['cover_path' => $relPath]);
    return $relPath;
}

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

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$uri = preg_replace('#^/api/?#', '', $uri);
$segments = array_values(array_filter(explode('/', trim((string) $uri, '/')), fn($s) => $s !== ''));
$resource = $segments[0] ?? null;
$id = isset($segments[1]) ? (int) $segments[1] : null;
$action = $segments[2] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($resource) {
        case 'items':
            if ($method === 'POST' && $id !== null && $action === 'extract-metadata') {
                $item = Items::find($id);
                if (!$item) {
                    respond(404, ['error' => 'Item introuvable']);
                }
                if ($item['type'] !== 'comic') {
                    respond(400, ['error' => "L'extraction de métadonnées n'est prise en charge que pour les BD (.cbz)"]);
                }
                $absPath = Paths::resolve($item['path']);
                $meta = ComicInfo::read($absPath);
                $metaFound = $meta !== null;
                if ($meta !== null) {
                    if (!empty($meta['series_name'])) {
                        $meta['series_id'] = Series::findOrCreate($meta['series_name']);
                    }
                    unset($meta['series_name']);
                    if ($meta) {
                        Items::update($id, $meta);
                    }
                }
                // Cover extraction (first page) is independent of ComicInfo.xml
                // being present — try it either way.
                $coverPath = extractAndSaveCover(Items::find($id));
                respond(200, [
                    'extracted' => $metaFound,
                    'coverExtracted' => $coverPath !== null,
                    'item' => Items::find($id),
                ]);
            }

            if ($method === 'POST' && $id !== null && $action === 'extract-cover') {
                $item = Items::find($id);
                if (!$item) {
                    respond(404, ['error' => 'Item introuvable']);
                }
                if (!Thumbnails::available()) {
                    respond(200, [
                        'extracted' => false,
                        'reason' => "L'extension GD n'est pas disponible sur le serveur",
                        'item' => $item,
                    ]);
                }
                $coverPath = extractAndSaveCover($item);
                respond(200, ['extracted' => $coverPath !== null, 'item' => Items::find($id)]);
            }
            if ($method === 'GET' && $id === null) {
                $limit = min(200, max(1, (int) ($_GET['limit'] ?? 60)));
                $offset = max(0, (int) ($_GET['offset'] ?? 0));
                $filters = array_filter([
                    'type' => $_GET['type'] ?? null,
                    'library_id' => isset($_GET['library_id']) ? (int) $_GET['library_id'] : null,
                    'series_id' => isset($_GET['series_id']) ? (int) $_GET['series_id'] : null,
                    'tag_id' => isset($_GET['tag_id']) ? (int) $_GET['tag_id'] : null,
                    'query' => $_GET['q'] ?? null,
                ], fn($v) => $v !== null && $v !== '');
                $sort = (string) ($_GET['sort'] ?? 'title');
                $dir = (string) ($_GET['dir'] ?? 'ASC');
                respond(200, Items::search($filters, $sort, $dir, $limit, $offset));
            }
            if ($method === 'GET' && $id !== null) {
                $item = Items::find($id);
                if (!$item) {
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
            if ($method === 'PUT' && $id !== null) {
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
            if ($method === 'DELETE' && $id !== null) {
                Items::delete($id);
                respond(200, ['deleted' => true]);
            }
            respond(405, ['error' => 'Méthode non autorisée']);

        case 'libraries':
            if ($method === 'GET' && $id === null) {
                respond(200, Libraries::all());
            }
            if ($method === 'POST' && $id === null) {
                $body = bodyJson();
                $newId = Libraries::create((string) ($body['name'] ?? ''), (string) ($body['path'] ?? ''));
                respond(201, Libraries::find($newId));
            }
            if ($method === 'DELETE' && $id !== null) {
                Libraries::delete($id);
                respond(200, ['deleted' => true]);
            }
            respond(405, ['error' => 'Méthode non autorisée']);

        case 'series':
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

        default:
            respond(404, ['error' => 'Route API inconnue']);
    }
} catch (InvalidArgumentException $e) {
    respond(400, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    respond(500, ['error' => 'Erreur serveur', 'detail' => $e->getMessage()]);
}
