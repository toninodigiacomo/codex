<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * The customizable outgoing email content behind the Maintenance tab's
 * "Modèles d'e-mails" card — schema.sql seeds every key with the standard
 * wording via INSERT OR IGNORE, so a fresh install always has usable text
 * and an admin's own edits are never silently reverted by a later
 * schema.sql run. DEFAULTS below is only consulted for the "restaurer le
 * modèle par défaut" action — the actual sent content always comes from
 * the database, never from this class's constants directly.
 */
final class EmailTemplates
{
    /** @var array<string, array{label: string, placeholders: list<string>, subject: string, body: string}> */
    private const DEFAULTS = [
        'invitation' => [
            'label' => "Invitation (et renvoi d'invitation)",
            'placeholders' => ['username', 'invite_url'],
            'subject' => 'Ton accès à Codex',
            'body' => "Bonjour {username},\n\nUn accès à la bibliothèque Codex t'a été créé.\nChoisis ton mot de passe ici pour l'activer :\n\n{invite_url}\n\nCe lien expire dans 7 jours.",
        ],
        'email_change_code' => [
            'label' => "Code de changement d'adresse e-mail",
            'placeholders' => ['code'],
            'subject' => 'Confirme ta nouvelle adresse — Codex',
            'body' => "Un changement d'adresse e-mail a été demandé pour ton compte Codex.\n\nCode de confirmation : {code}\n\nEntre ce code dans Codex pour confirmer cette adresse. Il est valable 24 heures.\nSi tu n'es pas à l'origine de cette demande, ignore cet e-mail — ton adresse actuelle reste inchangée.",
        ],
        'password_change_code' => [
            'label' => 'Code de changement de mot de passe',
            'placeholders' => ['code'],
            'subject' => 'Confirme ton nouveau mot de passe — Codex',
            'body' => "Un changement de mot de passe a été demandé pour ton compte Codex.\n\nCode de confirmation : {code}\n\nEntre ce code dans Codex pour appliquer le nouveau mot de passe. Il est valable 24 heures.\nSi tu n'es pas à l'origine de cette demande, ignore cet e-mail — ton mot de passe actuel reste inchangé.",
        ],
    ];

    /** @return array<string, array{label: string, placeholders: list<string>, subject: string, body: string, updated_at: string}> keyed by template key — what the Maintenance tab renders */
    public static function all(): array
    {
        $rows = Database::connection()->query('SELECT * FROM email_templates')->fetchAll();
        $byKey = [];
        foreach ($rows as $row) {
            $meta = self::DEFAULTS[$row['key']] ?? ['label' => $row['key'], 'placeholders' => []];
            $byKey[$row['key']] = [
                'label' => $meta['label'],
                'placeholders' => $meta['placeholders'],
                'subject' => $row['subject'],
                'body' => $row['body'],
                'updated_at' => $row['updated_at'],
            ];
        }
        return $byKey;
    }

    /** @return array{ok: bool, error: ?string} */
    public static function update(string $key, string $subject, string $body): array
    {
        if (!isset(self::DEFAULTS[$key])) {
            return ['ok' => false, 'error' => 'Modèle inconnu.'];
        }
        if (trim($subject) === '' || trim($body) === '') {
            return ['ok' => false, 'error' => "L'objet et le corps du message ne peuvent pas être vides."];
        }
        $stmt = Database::connection()->prepare(
            "UPDATE email_templates SET subject = ?, body = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE key = ?"
        );
        $stmt->execute([$subject, $body, $key]);
        return ['ok' => true, 'error' => null];
    }

    /** @return array{ok: bool, error: ?string} */
    public static function resetToDefault(string $key): array
    {
        if (!isset(self::DEFAULTS[$key])) {
            return ['ok' => false, 'error' => 'Modèle inconnu.'];
        }
        return self::update($key, self::DEFAULTS[$key]['subject'], self::DEFAULTS[$key]['body']);
    }

    /**
     * Loads $key's current subject/body and substitutes {placeholder}
     * tokens from $vars — used right before every Mailer::send() call that
     * has a template, so a customized subject and a customized body are
     * always applied together, never one from the database and the other
     * from a stale hardcoded string.
     * @param array<string, string> $vars
     * @return array{subject: string, body: string}
     */
    public static function render(string $key, array $vars): array
    {
        $stmt = Database::connection()->prepare('SELECT subject, body FROM email_templates WHERE key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        // Falls back to the hardcoded default only if the row is somehow
        // missing entirely (a database older than this feature, read before
        // schema.sql's seed insert has run for some reason) — normal
        // operation always finds the row schema.sql seeded.
        $subject = $row['subject'] ?? (self::DEFAULTS[$key]['subject'] ?? '');
        $body = $row['body'] ?? (self::DEFAULTS[$key]['body'] ?? '');
        foreach ($vars as $name => $value) {
            $subject = str_replace('{' . $name . '}', $value, $subject);
            $body = str_replace('{' . $name . '}', $value, $body);
        }
        return ['subject' => $subject, 'body' => $body];
    }
}
