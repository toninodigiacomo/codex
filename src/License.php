<?php

declare(strict_types=1);

require_once __DIR__ . '/Settings.php';

/**
 * Codex Premium licence — what unlocks the "IA" tab.
 *
 * A licence key is signed with a private key that only the vendor holds and
 * checked here against the matching public key, src/license-public.key —
 * offline, no licence server involved. The key is just "CDX1." +
 * base64url(JSON payload) + "." + base64url(Ed25519 signature of the first
 * part), so it can be pasted into a form.
 *
 * Binding a key to an installation. A Docker container can't see the host's
 * hardware (no disk labels; the MAC address is derived from the container's
 * IP, so two installs on the same IP share it; the hostname changes at every
 * re-creation), so "this machine" can't be measured reliably. What Codex does
 * fully control is its own data volume: it generates a random installation id
 * once, keeps it in the database (so it survives updates and travels with a
 * restored backup) and shows it as a "request code". The vendor puts that id
 * in the signed key as `bind`; a key with a `bind` only verifies on the
 * installation that has that id. A key without `bind` verifies anywhere.
 * That deters casual sharing of a key; it does not stop someone who edits
 * their own database or this file (see the last paragraph).
 *
 * What a licence does and does not do:
 *  - Entering a valid key unlocks the IA tab, and that stays unlocked. Nothing
 *    here ever re-locks it: a revoked key, or an "updates until" date in the
 *    past, only affects what the vendor's update channel will still deliver —
 *    the installed version keeps working. That is deliberate: the entitlement
 *    is to updates, not a leash on what is already installed.
 *  - `updates_until` is carried in the key (and shown to the admin); it is NOT
 *    checked here to switch anything off.
 *  - This is licensing for honest installations, not copy protection: the code
 *    runs on the customer's own server, and anyone who can edit PHP there can
 *    edit this file. The lever that actually binds is the update channel.
 */
final class License
{
    private const PREFIX = 'CDX1';
    private const REQUEST_PREFIX = 'CDXREQ1';
    private const PRODUCT = 'codex-premium';
    private const SETTING = 'license_key';
    private const ID_SETTING = 'installation_id';
    private const ID_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no I, O, 0, 1: easy to read out over the phone
    private const ID_PATTERN = '/^[A-HJ-NP-Z2-9]{20}$/';

    /** @var array<string, mixed>|null */
    private static ?array $status = null;

    /** The vendor's public key (32 raw bytes), or null if the installation hasn't been given one. */
    public static function publicKey(): ?string
    {
        $file = __DIR__ . '/license-public.key';
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $raw = base64_decode(trim((string) file_get_contents($file)), true);
        return ($raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) ? $raw : null;
    }

    /** This installation's random id (20 characters), created on first use and kept in the database. */
    public static function installationId(): string
    {
        $stored = Settings::get(self::ID_SETTING);
        if ($stored !== null && preg_match(self::ID_PATTERN, $stored)) {
            return $stored;
        }
        $id = '';
        for ($i = 0; $i < 20; $i++) {
            $id .= self::ID_ALPHABET[random_int(0, strlen(self::ID_ALPHABET) - 1)];
        }
        if ($stored !== null) {
            Settings::set(self::ID_SETTING, $id); // a damaged value is repaired, not kept
            return $id;
        }
        // Two requests racing on a brand-new installation must end up agreeing on a single id
        Database::connection()->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)')->execute([self::ID_SETTING, $id]);
        $stored = Settings::get(self::ID_SETTING);
        return ($stored !== null && preg_match(self::ID_PATTERN, $stored)) ? $stored : $id;
    }

    /** XXXX-XXXX-XXXX-XXXX-XXXX, for reading and quoting. */
    public static function displayId(string $id): string
    {
        return implode('-', str_split($id, 4));
    }

    /**
     * What the admin sends to the vendor to get a key bound to this installation:
     * "CDXREQ1." + base64url(JSON) + "." + a short checksum (catches a copy that
     * lost its tail — it is not a signature, anyone could write one). It carries
     * the installation id and a date, nothing about the libraries or the people.
     */
    public static function requestString(): string
    {
        $payload = json_encode(
            ['v' => 1, 'product' => self::PRODUCT, 'installation_id' => self::installationId(), 'created' => date('Y-m-d')],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $head = self::REQUEST_PREFIX . '.' . self::encode($payload);
        return $head . '.' . self::encode(substr(hash('sha256', $head, true), 0, 6));
    }

    /**
     * Checks a key without activating it.
     * @return array{valid: bool, code: ?string, message: ?string, payload: ?array<string, mixed>}
     */
    public static function inspect(string $key): array
    {
        $publicKey = self::publicKey();
        if ($publicKey === null) {
            return self::fail('not_configured', "Les licences ne sont pas initialisées sur cette installation : la clé publique (src/license-public.key) est absente.");
        }

        $key = (string) preg_replace('/\s+/', '', $key);
        $parts = explode('.', $key);
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            return self::fail('malformed', "Cette clé n'a pas le bon format. Colle-la en entier, sans la modifier.");
        }
        [$prefix, $encodedPayload, $encodedSignature] = $parts;
        $payloadJson = self::decode($encodedPayload);
        $signature = self::decode($encodedSignature);
        if ($payloadJson === null || $signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return self::fail('malformed', "Cette clé n'a pas le bon format. Colle-la en entier, sans la modifier.");
        }

        // Signature first: nothing inside the payload is trusted, or even interpreted, before it checks out.
        if (!sodium_crypto_sign_verify_detached($signature, $prefix . '.' . $encodedPayload, $publicKey)) {
            return self::fail('bad_signature', "Clé invalide : sa signature ne correspond pas (clé modifiée, tronquée, ou émise par un autre émetteur).");
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload) || ($payload['v'] ?? null) !== 1) {
            return self::fail('unsupported', "Cette clé a été émise pour une version de licence que ce Codex ne connaît pas : mets Codex à jour.");
        }
        if (($payload['product'] ?? null) !== self::PRODUCT) {
            return self::fail('wrong_product', "Cette clé ne concerne pas Codex Premium.");
        }
        $id = $payload['id'] ?? null;
        $licensee = $payload['licensee'] ?? null;
        $issued = $payload['issued'] ?? null;
        $until = $payload['updates_until'] ?? null;
        $bind = $payload['bind'] ?? null;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{4,64}$/', $id)
            || !is_string($licensee) || trim($licensee) === '' || strlen($licensee) > 240
            || !self::isDate($issued) || ($until !== null && !self::isDate($until))
            || ($bind !== null && (!is_string($bind) || !preg_match(self::ID_PATTERN, $bind)))) {
            return self::fail('invalid_payload', "Clé invalide : son contenu est incomplet.");
        }
        if ($bind !== null && !hash_equals(self::installationId(), $bind)) {
            return self::fail('wrong_installation', "Cette clé a été émise pour une autre installation de Codex. Demande-en une pour celle-ci avec le code de demande affiché sous le champ de clé.");
        }

        return ['valid' => true, 'code' => null, 'message' => null, 'payload' => [
            'id' => $id, 'licensee' => $licensee, 'issued' => $issued, 'updates_until' => $until, 'bound' => $bind !== null,
        ]];
    }

    /**
     * Stores a key if — and only if — it is valid. A bad key leaves whatever
     * was stored before untouched.
     * @return array<string, mixed> the new status
     * @throws InvalidArgumentException with a message fit to show the admin
     */
    public static function activate(string $key): array
    {
        $result = self::inspect($key);
        if (!$result['valid']) {
            throw new InvalidArgumentException((string) $result['message']);
        }
        Settings::set(self::SETTING, (string) preg_replace('/\s+/', '', $key));
        self::$status = null;
        return self::status();
    }

    /**
     * What the console needs to know. "active" means a stored key that verifies
     * against this installation's public key (and, if it is bound, this
     * installation's id) — nothing else: not its date, not whether the vendor
     * has since revoked it (see the class comment).
     * @return array{configured: bool, active: bool, stored_key_invalid: bool, stored_key_reason: ?string, installation_id: string, bound: ?bool, id: ?string, licensee: ?string, issued: ?string, updates_until: ?string, updates_current: ?bool}
     */
    public static function status(): array
    {
        if (self::$status !== null) {
            return self::$status;
        }
        $status = [
            'configured' => self::publicKey() !== null, 'active' => false, 'stored_key_invalid' => false, 'stored_key_reason' => null,
            'installation_id' => self::displayId(self::installationId()), 'bound' => null,
            'id' => null, 'licensee' => null, 'issued' => null, 'updates_until' => null, 'updates_current' => null,
        ];
        $stored = Settings::get(self::SETTING);
        if ($stored !== null && $stored !== '') {
            $result = self::inspect($stored);
            if ($result['valid']) {
                $p = $result['payload'];
                $status['active'] = true;
                $status['bound'] = $p['bound'];
                $status['id'] = $p['id'];
                $status['licensee'] = $p['licensee'];
                $status['issued'] = $p['issued'];
                $status['updates_until'] = $p['updates_until'];
                $status['updates_current'] = $p['updates_until'] === null || $p['updates_until'] >= date('Y-m-d');
            } else {
                // the installation's public key was replaced, or the database was restored onto another installation
                $status['stored_key_invalid'] = true;
                $status['stored_key_reason'] = $result['code'];
            }
        }
        return self::$status = $status;
    }

    public static function isActive(): bool
    {
        return self::status()['active'];
    }

    /** @return array{valid: false, code: string, message: string, payload: null} */
    private static function fail(string $code, string $message): array
    {
        return ['valid' => false, 'code' => $code, 'message' => $message, 'payload' => null];
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $s): ?string
    {
        $s = strtr($s, '-_', '+/');
        if ($s === '' || preg_match('/[^A-Za-z0-9+\/]/', $s)) {
            return null;
        }
        $pad = strlen($s) % 4;
        if ($pad === 1) {
            return null;
        }
        $decoded = base64_decode($pad ? $s . str_repeat('=', 4 - $pad) : $s, true);
        return $decoded === false ? null : $decoded;
    }

    private static function isDate(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value;
    }
}
