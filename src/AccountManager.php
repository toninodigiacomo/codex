<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/Totp.php';
require_once __DIR__ . '/Users.php';
require_once __DIR__ . '/EmailTemplates.php';

/**
 * Self-service account changes — a user (reader or admin) changing their
 * own email or password, from the account popup (public/js/account.js).
 * Every change here follows the same shape: the old value stays active
 * and nothing is written to the real column until proof of ownership is
 * given, so a request alone — even one left half-finished for a day —
 * never by itself weakens or changes the account.
 *
 * Email is proven by replying with a code mailed to the *new* address
 * (proves the user can actually receive mail there). Password is proven
 * either the same way — a code mailed to the *current, already-verified*
 * address — or, for a user with MFA enrolled, by a live TOTP code
 * instead: a TOTP code is only valid for ~30 seconds, so there's no
 * sense in a day-long "pending" window for it the way there is for a
 * mailed code — it's a single immediate step instead.
 */
final class AccountManager
{
    private const CODE_TTL_SECONDS = 86400; // 1 day, for both pending email and pending password codes
    private const MAX_CODE_ATTEMPTS = 10; // a wrong-code guess this many times invalidates the pending change, rather than leaving a low-entropy 6-digit code guessable indefinitely
    private const MIN_PASSWORD_LENGTH = 12; // matches accept-invite.php/setup.php's own new-password minlength

    // ---------- email ----------

    /** @return array{ok: bool, error: ?string} */
    public static function requestEmailChange(int $userId, string $newEmail): array
    {
        $newEmail = trim($newEmail);
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Adresse e-mail invalide.'];
        }
        $pdo = Database::connection();
        $existing = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $existing->execute([$newEmail, $userId]);
        if ($existing->fetchColumn() !== false) {
            return ['ok' => false, 'error' => 'Cette adresse est déjà utilisée par un autre compte.'];
        }

        $code = self::generateCode();
        $stmt = $pdo->prepare(
            'UPDATE users SET pending_email = :email, pending_email_code_hash = :hash,
             pending_email_expires = :expires, pending_email_attempts = 0 WHERE id = :id'
        );
        $stmt->execute([
            ':email' => $newEmail,
            ':hash' => hash('sha256', $code),
            ':expires' => date('c', time() + self::CODE_TTL_SECONDS),
            ':id' => $userId,
        ]);

        $tpl = EmailTemplates::render('email_change_code', ['code' => $code]);
        $mail = Mailer::send($newEmail, $tpl['subject'], $tpl['body']);
        if (!$mail['ok']) {
            return ['ok' => false, 'error' => "L'e-mail n'a pas pu être envoyé : " . ($mail['error'] ?? 'erreur inconnue')];
        }
        return ['ok' => true, 'error' => null];
    }

    /** @return array{ok: bool, error: ?string} */
    public static function confirmEmailChange(int $userId, string $code): array
    {
        $user = self::findWithPending($userId);
        if ($user === null || $user['pending_email'] === null) {
            return ['ok' => false, 'error' => 'Aucun changement en attente.'];
        }
        if (strtotime($user['pending_email_expires']) < time()) {
            self::cancelEmailChange($userId);
            return ['ok' => false, 'error' => 'Ce code a expiré — recommence la demande.'];
        }
        if ((int) $user['pending_email_attempts'] >= self::MAX_CODE_ATTEMPTS) {
            self::cancelEmailChange($userId);
            return ['ok' => false, 'error' => 'Trop de tentatives — recommence la demande.'];
        }
        if (!hash_equals($user['pending_email_code_hash'], hash('sha256', trim($code)))) {
            Database::connection()
                ->prepare('UPDATE users SET pending_email_attempts = pending_email_attempts + 1 WHERE id = ?')
                ->execute([$userId]);
            return ['ok' => false, 'error' => 'Code incorrect.'];
        }
        $stmt = Database::connection()->prepare(
            'UPDATE users SET email = pending_email, pending_email = NULL, pending_email_code_hash = NULL,
             pending_email_expires = NULL, pending_email_attempts = 0 WHERE id = ?'
        );
        $stmt->execute([$userId]);
        return ['ok' => true, 'error' => null];
    }

    public static function cancelEmailChange(int $userId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET pending_email = NULL, pending_email_code_hash = NULL,
             pending_email_expires = NULL, pending_email_attempts = 0 WHERE id = ?'
        );
        $stmt->execute([$userId]);
    }

    // ---------- password ----------

    /** For a user with no MFA enrolled — mails a code to their current address rather than changing anything yet. @return array{ok: bool, error: ?string} */
    public static function requestPasswordChange(int $userId, string $newPassword): array
    {
        $lenError = self::validatePasswordLength($newPassword);
        if ($lenError !== null) {
            return ['ok' => false, 'error' => $lenError];
        }
        $user = Users::find($userId);
        if ($user === null || empty($user['email'])) {
            return ['ok' => false, 'error' => "Aucune adresse e-mail enregistrée pour recevoir le code — contacte un administrateur."];
        }

        $code = self::generateCode();
        $stmt = Database::connection()->prepare(
            'UPDATE users SET pending_password_hash = :hash, pending_password_code_hash = :codeHash,
             pending_password_expires = :expires, pending_password_attempts = 0 WHERE id = :id'
        );
        $stmt->execute([
            ':hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            ':codeHash' => hash('sha256', $code),
            ':expires' => date('c', time() + self::CODE_TTL_SECONDS),
            ':id' => $userId,
        ]);

        $tpl = EmailTemplates::render('password_change_code', ['code' => $code]);
        $mail = Mailer::send((string) $user['email'], $tpl['subject'], $tpl['body']);
        if (!$mail['ok']) {
            return ['ok' => false, 'error' => "L'e-mail n'a pas pu être envoyé : " . ($mail['error'] ?? 'erreur inconnue')];
        }
        return ['ok' => true, 'error' => null];
    }

    /** @return array{ok: bool, error: ?string} */
    public static function confirmPasswordChange(int $userId, string $code): array
    {
        $user = self::findWithPending($userId);
        if ($user === null || $user['pending_password_hash'] === null) {
            return ['ok' => false, 'error' => 'Aucun changement en attente.'];
        }
        if (strtotime($user['pending_password_expires']) < time()) {
            self::cancelPasswordChange($userId);
            return ['ok' => false, 'error' => 'Ce code a expiré — recommence la demande.'];
        }
        if ((int) $user['pending_password_attempts'] >= self::MAX_CODE_ATTEMPTS) {
            self::cancelPasswordChange($userId);
            return ['ok' => false, 'error' => 'Trop de tentatives — recommence la demande.'];
        }
        if (!hash_equals($user['pending_password_code_hash'], hash('sha256', trim($code)))) {
            Database::connection()
                ->prepare('UPDATE users SET pending_password_attempts = pending_password_attempts + 1 WHERE id = ?')
                ->execute([$userId]);
            return ['ok' => false, 'error' => 'Code incorrect.'];
        }
        $stmt = Database::connection()->prepare(
            'UPDATE users SET password_hash = pending_password_hash, pending_password_hash = NULL,
             pending_password_code_hash = NULL, pending_password_expires = NULL, pending_password_attempts = 0,
             remember_token_hash = NULL, remember_token_expires = NULL WHERE id = ?'
        );
        $stmt->execute([$userId]);
        return ['ok' => true, 'error' => null];
    }

    public static function cancelPasswordChange(int $userId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET pending_password_hash = NULL, pending_password_code_hash = NULL,
             pending_password_expires = NULL, pending_password_attempts = 0 WHERE id = ?'
        );
        $stmt->execute([$userId]);
    }

    /**
     * For a user with MFA enrolled — a live TOTP code is proof enough on
     * its own (unlike a mailed code, it can't be "come back tomorrow and
     * enter it" since it only lasts ~30s), so this applies the new
     * password immediately rather than going through the pending dance.
     * @return array{ok: bool, error: ?string}
     */
    public static function changePasswordWithMfa(int $userId, string $newPassword, string $totpCode): array
    {
        $lenError = self::validatePasswordLength($newPassword);
        if ($lenError !== null) {
            return ['ok' => false, 'error' => $lenError];
        }
        $user = self::findWithPending($userId);
        if ($user === null || empty($user['totp_secret'])) {
            return ['ok' => false, 'error' => "La double authentification n'est pas activée sur ce compte."];
        }
        if (!Totp::verify((string) $user['totp_secret'], $totpCode)) {
            return ['ok' => false, 'error' => 'Code de double authentification incorrect.'];
        }
        $stmt = Database::connection()->prepare(
            'UPDATE users SET password_hash = ?, remember_token_hash = NULL, remember_token_expires = NULL WHERE id = ?'
        );
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
        return ['ok' => true, 'error' => null];
    }

    // ---------- shared ----------

    /** What the account popup needs to render its current state — never the hashes/codes themselves. */
    public static function statusFor(int $userId): array
    {
        $user = self::findWithPending($userId);
        if ($user === null) {
            return [];
        }
        return [
            'username' => $user['username'],
            'email' => $user['email'],
            'mfa_enabled' => !empty($user['totp_secret']),
            'pending_email' => $user['pending_email'],
            'pending_email_expires' => $user['pending_email_expires'],
            'pending_password' => $user['pending_password_hash'] !== null,
            'pending_password_expires' => $user['pending_password_expires'],
        ];
    }

    private static function generateCode(): string
    {
        return (string) random_int(100000, 999999);
    }

    private static function validatePasswordLength(string $password): ?string
    {
        return strlen($password) < self::MIN_PASSWORD_LENGTH
            ? 'Le mot de passe doit contenir au moins ' . self::MIN_PASSWORD_LENGTH . ' caractères.'
            : null;
    }

    private static function findWithPending(int $userId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
