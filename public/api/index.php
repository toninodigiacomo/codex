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

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$uri = preg_replace('#^/api/?#', '', $uri);
$segments = array_values(array_filter(explode('/', trim((string) $uri, '/')), fn($s) => $s !== ''));
$resource = $segments[0] ?? null;
$rawSecondSegment = $segments[1] ?? null; // kept as a string too — not every resource's 2nd segment is a numeric id (e.g. /api/settings/test-email)
$id = $rawSecondSegment !== null ? (int) $rawSecondSegment : null;
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
                $allowedLibraryIds = currentUserAllowedLibraries();
                if ($allowedLibraryIds !== null) {
                    if (!$allowedLibraryIds) {
                        respond(200, ['items' => [], 'total' => 0]);
                    }
                    $filters['library_ids'] = $allowedLibraryIds;
                }
                $sort = (string) ($_GET['sort'] ?? 'title');
                $dir = (string) ($_GET['dir'] ?? 'ASC');
                respond(200, Items::search($filters, $sort, $dir, $limit, $offset));
            }
            if ($method === 'GET' && $id !== null) {
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
                Auth::requireAdminApi();
                $body = bodyJson();
                $newId = Libraries::create((string) ($body['name'] ?? ''), (string) ($body['path'] ?? ''));
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

        case 'settings':
            Auth::requireAdminApi();
            if ($method === 'GET') {
                $config = Settings::smtpConfig();
                $config['smtp_password_set'] = !empty($config['smtp_password']);
                unset($config['smtp_password']);
                $config['site_url'] = Settings::siteUrl();
                respond(200, $config);
            }
            if ($method === 'PUT') {
                $body = bodyJson();
                if (isset($body['site_url'])) {
                    Settings::set('site_url', trim((string) $body['site_url']) ?: null);
                    unset($body['site_url']);
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
            respond(405, ['error' => 'Méthode non autorisée']);

        default:
            respond(404, ['error' => 'Route API inconnue']);
    }
} catch (InvalidArgumentException $e) {
    respond(400, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    respond(500, ['error' => 'Erreur serveur', 'detail' => $e->getMessage()]);
}
