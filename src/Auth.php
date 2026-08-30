<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Totp.php';

final class Auth
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 900; // 15 minutes
    private const SESSION_TTL = 3600; // 1 hour of inactivity
    private const REMEMBER_COOKIE = 'codex_remember';
    private const REMEMBER_TTL = 90 * 24 * 3600; // 3 months

    public static function bootSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure' => self::cookieSecure(),
        ]);
        session_name('codex_session');
        session_start();

        // idle timeout — clears the PHP session only; a valid "remember me"
        // cookie must still be able to silently re-authenticate afterward.
        if (isset($_SESSION['last_seen']) && (time() - $_SESSION['last_seen']) > self::SESSION_TTL) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['last_seen'] = time();

        if (!self::isLoggedIn()) {
            self::tryRememberLogin();
        }
    }

    private static function cookieSecure(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public static function isSetupComplete(): bool
    {
        $stmt = Database::connection()->query('SELECT COUNT(*) FROM users');
        return ((int) $stmt->fetchColumn()) > 0;
    }

    public static function completeSetup(string $username, string $password, string $totpSecret): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (username, password_hash, role, totp_secret) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), 'admin', $totpSecret]);
    }

    private static function loadUserByUsername(string $username): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function loadUserById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['authenticated']);
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            header('Location: /login.php');
            exit;
        }
    }

    public static function requireLoginApi(): void
    {
        if (!self::isLoggedIn()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Authentification requise']);
            exit;
        }
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function checkCsrf(?string $token): bool
    {
        return !empty($_SESSION['csrf_token']) && is_string($token)
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    // ---------- brute-force protection ----------

    private static function clientKey(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    public static function isLockedOut(): bool
    {
        $stmt = Database::connection()->prepare('SELECT count, last_at FROM login_attempts WHERE ip = ?');
        $stmt->execute([self::clientKey()]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }
        $lastAt = strtotime($row['last_at']);
        return $row['count'] >= self::MAX_ATTEMPTS && (time() - $lastAt) < self::LOCKOUT_SECONDS;
    }

    public static function secondsUntilUnlock(): int
    {
        $stmt = Database::connection()->prepare('SELECT last_at FROM login_attempts WHERE ip = ?');
        $stmt->execute([self::clientKey()]);
        $row = $stmt->fetch();
        if (!$row) {
            return 0;
        }
        $remaining = self::LOCKOUT_SECONDS - (time() - strtotime($row['last_at']));
        return max(0, $remaining);
    }

    private static function recordFailure(): void
    {
        $pdo = Database::connection();
        $now = date('c');
        $stmt = $pdo->prepare(
            'INSERT INTO login_attempts (ip, count, last_at) VALUES (:ip, 1, :now)
             ON CONFLICT(ip) DO UPDATE SET count = count + 1, last_at = :now'
        );
        $stmt->execute([':ip' => self::clientKey(), ':now' => $now]);
    }

    private static function clearFailures(): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM login_attempts WHERE ip = ?');
        $stmt->execute([self::clientKey()]);
    }

    /**
     * Attempt login with username + password + 6-digit TOTP code.
     * Returns true on success, false on failure (any reason).
     */
    public static function attemptLogin(string $username, string $password, string $totpCode, bool $remember = false): bool
    {
        if (self::isLockedOut()) {
            return false;
        }
        $user = self::loadUserByUsername($username);
        if (!$user) {
            self::recordFailure();
            return false;
        }

        $passOk = password_verify($password, $user['password_hash']);
        $totpOk = Totp::verify((string) $user['totp_secret'], $totpCode);

        if ($passOk && $totpOk) {
            self::clearFailures();
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['last_seen'] = time();
            if ($remember) {
                self::issueRememberToken((int) $user['id']);
            }
            return true;
        }

        self::recordFailure();
        return false;
    }

    /**
     * Issue (or rotate) a long-lived "remember me" token: a random secret
     * sent to the browser as an HttpOnly cookie, whose SHA-256 hash is the
     * only thing stored server-side.
     */
    private static function issueRememberToken(int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        $stmt = Database::connection()->prepare(
            'UPDATE users SET remember_token_hash = ?, remember_token_expires = ? WHERE id = ?'
        );
        $stmt->execute([hash('sha256', $token), date('c', time() + self::REMEMBER_TTL), $userId]);

        setcookie(self::REMEMBER_COOKIE, $token, [
            'expires' => time() + self::REMEMBER_TTL,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure' => self::cookieSecure(),
        ]);
    }

    private static function clearRememberToken(?int $userId): void
    {
        if ($userId !== null) {
            $stmt = Database::connection()->prepare(
                'UPDATE users SET remember_token_hash = NULL, remember_token_expires = NULL WHERE id = ?'
            );
            $stmt->execute([$userId]);
        }
        setcookie(self::REMEMBER_COOKIE, '', [
            'expires' => time() - 42000,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure' => self::cookieSecure(),
        ]);
    }

    /** Silently re-establish a session from a valid "remember me" cookie, rotating it on use. */
    private static function tryRememberLogin(): void
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        if (!is_string($cookie) || $cookie === '') {
            return;
        }
        $hash = hash('sha256', $cookie);
        $stmt = Database::connection()->prepare(
            'SELECT * FROM users WHERE remember_token_hash = ? AND remember_token_expires > ?'
        );
        $stmt->execute([$hash, date('c')]);
        $user = $stmt->fetch();
        if (!$user) {
            return;
        }

        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['last_seen'] = time();
        self::issueRememberToken((int) $user['id']);
    }

    public static function logout(): void
    {
        self::clearRememberToken($_SESSION['user_id'] ?? null);
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
