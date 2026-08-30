<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

final class Users
{
    private const INVITE_TTL = 7 * 24 * 3600; // 7 days

    /** @return array<int, array> every user, with their assigned library ids attached */
    public static function all(): array
    {
        $pdo = Database::connection();
        $users = $pdo->query(
            'SELECT id, username, email, role, status, totp_secret IS NOT NULL AS mfa_enabled, mfa_required, created_at FROM users ORDER BY username COLLATE NOCASE'
        )->fetchAll();
        foreach ($users as &$user) {
            $user['mfa_enabled'] = (bool) $user['mfa_enabled'];
            $user['mfa_required'] = (bool) $user['mfa_required'];
            $user['library_ids'] = self::libraryIdsFor($pdo, (int) $user['id']);
        }
        return $users;
    }

    public static function find(int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT id, username, email, role, status, totp_secret IS NOT NULL AS mfa_enabled, mfa_required, created_at FROM users WHERE id = ?'
        );
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) {
            return null;
        }
        $user['mfa_enabled'] = (bool) $user['mfa_enabled'];
        $user['mfa_required'] = (bool) $user['mfa_required'];
        $user['library_ids'] = self::libraryIdsFor($pdo, $id);
        return $user;
    }

    private static function libraryIdsFor(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare('SELECT library_id FROM user_libraries WHERE user_id = ?');
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function setLibraries(int $userId, array $libraryIds): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM user_libraries WHERE user_id = ?')->execute([$userId]);
            $stmt = $pdo->prepare('INSERT OR IGNORE INTO user_libraries (user_id, library_id) VALUES (?, ?)');
            foreach (array_unique(array_map('intval', $libraryIds)) as $libId) {
                $stmt->execute([$userId, $libId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Creates a pending (status='invited', no password yet) user and
     * returns [user array, raw invite token] — the raw token is only
     * ever available here, at creation time; only its hash is stored.
     */
    public static function invite(string $username, string $email, string $role, array $libraryIds, bool $mfaRequired = false): array
    {
        $username = trim($username);
        $email = trim($email);
        if ($username === '' || strlen($username) < 3) {
            throw new InvalidArgumentException("Le nom d'utilisateur doit contenir au moins 3 caractères");
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Adresse e-mail invalide');
        }
        if (!in_array($role, ['admin', 'reader'], true)) {
            throw new InvalidArgumentException('Rôle invalide');
        }

        $pdo = Database::connection();
        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, email, role, status, mfa_required, invite_token_hash, invite_token_expires)
             VALUES (:username, :email, :role, \'invited\', :mfa_required, :hash, :expires)'
        );
        $stmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':role' => $role,
            ':mfa_required' => $mfaRequired ? 1 : 0,
            ':hash' => hash('sha256', $token),
            ':expires' => date('c', time() + self::INVITE_TTL),
        ]);
        $userId = (int) $pdo->lastInsertId();
        self::setLibraries($userId, $libraryIds);

        return [self::find($userId), $token];
    }

    /** Generates a fresh invite token for an existing (still-invited) user — for "resend". */
    public static function regenerateInvite(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $stmt = Database::connection()->prepare(
            'UPDATE users SET invite_token_hash = ?, invite_token_expires = ? WHERE id = ? AND status = \'invited\''
        );
        $stmt->execute([hash('sha256', $token), date('c', time() + self::INVITE_TTL), $userId]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Utilisateur introuvable ou déjà actif');
        }
        return $token;
    }

    /** Resolves a raw invite token to its user row, or null if invalid/expired. */
    public static function findByInviteToken(string $token): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM users WHERE invite_token_hash = ? AND status = 'invited' AND invite_token_expires > ?"
        );
        $stmt->execute([hash('sha256', $token), date('c')]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Completes an invite: sets the user's own password, activates the
     * account, and enables MFA only if the user chose to (their decision,
     * made on the invite-acceptance page — not the admin's).
     */
    public static function acceptInvite(int $userId, string $password, ?string $totpSecret): void
    {
        $stmt = Database::connection()->prepare(
            "UPDATE users SET password_hash = ?, totp_secret = ?, status = 'active',
             invite_token_hash = NULL, invite_token_expires = NULL WHERE id = ?"
        );
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $totpSecret, $userId]);
    }

    public static function updateRole(int $userId, string $role): void
    {
        if (!in_array($role, ['admin', 'reader'], true)) {
            throw new InvalidArgumentException('Rôle invalide');
        }
        $stmt = Database::connection()->prepare('UPDATE users SET role = ? WHERE id = ?');
        $stmt->execute([$role, $userId]);
    }

    /**
     * Forces (or lifts) the MFA requirement for a user. Forcing it on an
     * account that has no totp_secret yet doesn't set one up by itself —
     * it just blocks their next login until they enroll (see
     * Auth::attemptLogin()'s 'mfa_setup_required' path).
     */
    public static function setMfaRequired(int $userId, bool $required): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET mfa_required = ? WHERE id = ?');
        $stmt->execute([$required ? 1 : 0, $userId]);
    }

    public static function delete(int $userId): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$userId]);
    }

    /** How many admins currently exist — used to stop deleting/demoting the last one. */
    public static function adminCount(): int
    {
        return (int) Database::connection()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    }
}
