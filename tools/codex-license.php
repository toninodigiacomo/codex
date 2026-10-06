<?php
/**
 * Codex Premium — émission des clés d'enregistrement. À utiliser sur TA machine, pas sur le serveur.
 *
 *   php codex-license.php init [dossier]
 *       Crée license-private.key (SECRÈTE) et license-public.key. Refuse d'écraser.
 *   php codex-license.php issue <license-private.key> --licensee "Nom" [--bind ID|CODE] [--updates-until AAAA-MM-JJ] [--id IDENTIFIANT]
 *       --bind lie la clé à une installation (identifiant à 20 caractères, ou le code de demande CDXREQ1. complet).
 *       Affiche une clé sur la sortie standard (le reste va sur la sortie d'erreur) et
 *       l'ajoute à issued-keys.jsonl, à côté de la clé privée.
 *   php codex-license.php inspect <license-public.key> <clé>
 *       Vérifie une clé et affiche son contenu.
 *
 * La clé PUBLIQUE se copie dans src/license-public.key de chaque installation de Codex.
 * La clé PRIVÉE ne quitte jamais ta machine : qui la détient peut émettre des clés. Si tu la
 * perds, tu ne peux plus en émettre, et il faudrait changer la clé publique de chaque installation.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (!extension_loaded('sodium')) {
    fwrite(STDERR, "L'extension PHP sodium est requise.\n");
    exit(1);
}

const PREFIX = 'CDX1';
const PRODUCT = 'codex-premium';

function b64url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function fail(string $msg): never
{
    fwrite(STDERR, "Erreur : $msg\n");
    exit(1);
}

function is_date(?string $v): bool
{
    if ($v === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return false;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    return $d !== false && $d->format('Y-m-d') === $v;
}

function normalize_id(string $s): ?string
{
    $s = strtoupper((string) preg_replace('/[\s-]+/', '', $s));
    return preg_match('/^[A-HJ-NP-Z2-9]{20}$/', $s) ? $s : null;
}

/** @return array<string, mixed> */
function parse_request(string $s): array
{
    $parts = explode('.', (string) preg_replace('/\s+/', '', $s));
    if (count($parts) !== 3 || $parts[0] !== 'CDXREQ1') {
        fail("code de demande invalide (il commence par CDXREQ1.).");
    }
    if (!hash_equals(b64url(substr(hash('sha256', $parts[0] . '.' . $parts[1], true), 0, 6)), $parts[2])) {
        fail("code de demande altéré ou incomplet (somme de contrôle incorrecte).");
    }
    $json = base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4), true);
    $data = $json === false ? null : json_decode($json, true);
    if (!is_array($data) || ($data['v'] ?? null) !== 1 || ($data['product'] ?? null) !== PRODUCT
        || !is_string($data['installation_id'] ?? null) || normalize_id($data['installation_id']) === null) {
        fail("code de demande illisible ou pour un autre produit.");
    }
    return $data;
}

function read_key_file(string $file, int $length, string $what): string
{
    if (!is_file($file)) {
        fail("fichier introuvable : $file");
    }
    $raw = base64_decode(trim((string) file_get_contents($file)), true);
    if ($raw === false || strlen($raw) !== $length) {
        fail("$what illisible ou de mauvaise taille : $file");
    }
    return $raw;
}

/** @return array{0: array<int, string>, 1: array<string, ?string>} */
function parse_args(array $args): array
{
    $positional = [];
    $options = [];
    for ($i = 0; $i < count($args); $i++) {
        if (str_starts_with($args[$i], '--')) {
            $options[substr($args[$i], 2)] = $args[++$i] ?? null;
        } else {
            $positional[] = $args[$i];
        }
    }
    return [$positional, $options];
}

$command = $argv[1] ?? 'help';
[$positional, $options] = parse_args(array_slice($argv, 2));

switch ($command) {
    case 'init':
        $dir = rtrim($positional[0] ?? '.', '/');
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            fail("impossible de créer $dir");
        }
        $private = "$dir/license-private.key";
        $public = "$dir/license-public.key";
        if (file_exists($private) || file_exists($public)) {
            fail("des clés existent déjà dans $dir : je ne les écrase pas.");
        }
        $pair = sodium_crypto_sign_keypair();
        umask(0177);
        file_put_contents($private, base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n");
        chmod($private, 0600);
        file_put_contents($public, base64_encode(sodium_crypto_sign_publickey($pair)) . "\n");
        chmod($public, 0644);
        fwrite(STDERR, "Clés créées dans $dir :\n  $private   <- SECRÈTE : sauvegarde-la hors du serveur, ne la copie nulle part ailleurs\n  $public    <- à copier dans src/license-public.key de chaque installation de Codex\n");
        break;

    case 'issue':
        $privateFile = $positional[0] ?? fail("usage : issue <license-private.key> --licensee \"Nom\" [--updates-until AAAA-MM-JJ] [--id ID]");
        $licensee = trim((string) ($options['licensee'] ?? ''));
        if ($licensee === '' || strlen($licensee) > 240) {
            fail("--licensee est requis (240 octets au plus).");
        }
        $until = $options['updates-until'] ?? null;
        if ($until !== null && !is_date($until)) {
            fail("--updates-until doit être une date AAAA-MM-JJ valide.");
        }
        $bind = null;
        if (isset($options['bind'])) {
            $bind = str_starts_with(trim((string) $options['bind']), 'CDXREQ1.')
                ? normalize_id(parse_request((string) $options['bind'])['installation_id'])
                : normalize_id((string) $options['bind']);
            if ($bind === null) {
                fail("--bind : identifiant d'installation invalide (20 caractères, avec ou sans tirets) ou code de demande CDXREQ1.");
            }
        }
        $id = $options['id'] ?? null;
        if ($id === null) {
            $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $id = '';
            for ($i = 0; $i < 10; $i++) {
                $id .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        }
        if (!preg_match('/^[A-Za-z0-9_-]{4,64}$/', $id)) {
            fail("--id : 4 à 64 caractères parmi A-Z a-z 0-9 _ -");
        }
        $secret = read_key_file($privateFile, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 'la clé privée');
        $issued = date('Y-m-d');
        $payload = json_encode(
            ['v' => 1, 'product' => PRODUCT, 'id' => $id, 'licensee' => $licensee, 'issued' => $issued, 'updates_until' => $until, 'bind' => $bind],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $signed = PREFIX . '.' . b64url($payload);
        $key = $signed . '.' . b64url(sodium_crypto_sign_detached($signed, $secret));
        file_put_contents(
            dirname($privateFile) . '/issued-keys.jsonl',
            json_encode(['id' => $id, 'licensee' => $licensee, 'issued' => $issued, 'updates_until' => $until, 'bind' => $bind], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND
        );
        fwrite(STDERR, "Clé émise — n° $id, pour « $licensee », mises à jour " . ($until ? "jusqu'au $until" : 'sans limite de date') . ". " . ($bind ? "Liée à l'installation $bind." : "ATTENTION : clé NON liée à une installation (valable partout).") . "\n");
        echo $key . "\n";
        break;

    case 'inspect':
        $publicFile = $positional[0] ?? fail("usage : inspect <license-public.key> <clé>");
        $key = preg_replace('/\s+/', '', (string) ($positional[1] ?? '')) ?? '';
        $public = read_key_file($publicFile, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'la clé publique');
        $parts = explode('.', $key);
        if (count($parts) !== 3 || $parts[0] !== PREFIX) {
            fail("format de clé invalide.");
        }
        $pad = static fn(string $s): string => strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4);
        $sig = base64_decode($pad($parts[2]), true);
        $json = base64_decode($pad($parts[1]), true);
        if ($sig === false || $json === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($sig, $parts[0] . '.' . $parts[1], $public)) {
            fwrite(STDERR, "SIGNATURE INVALIDE\n");
            exit(1);
        }
        echo "Signature valide.\n" . json_encode(json_decode($json, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        break;

    case 'request-inspect':
        $data = parse_request((string) ($positional[0] ?? fail("usage : request-inspect <code de demande>")));
        echo "Code de demande valide.\n" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        break;

    default:
        fwrite(STDERR, "Commandes : init [dossier] | issue <clé-privée> --licensee \"Nom\" [--bind ID|CODE] [--updates-until AAAA-MM-JJ] [--id ID] | request-inspect <code> | inspect <clé-publique> <clé>\n");
        exit($command === 'help' ? 0 : 1);
}
