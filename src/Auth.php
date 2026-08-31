<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Session-cookie auth. The cookie carries only a random session ID
 * (HttpOnly/Secure/SameSite=Strict) — all user state lives server-side
 * in the PHP session store, never in the cookie itself.
 */
final class Auth
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;
    private const SESSION_ABSOLUTE_LIFETIME = 8 * 3600; // 8 hours

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $config = require __DIR__ . '/../config/config.php';
        $forceHttps = $config['app']['force_https'];

        session_set_cookie_params([
            'lifetime' => 0, // session cookie — cleared when the browser closes
            'path'     => '/',
            'secure'   => $forceHttps,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_name('SMSESSID');
        session_start();

        // Absolute session lifetime, independent of activity.
        if (!isset($_SESSION['_started_at'])) {
            $_SESSION['_started_at'] = time();
        } elseif (time() - $_SESSION['_started_at'] > self::SESSION_ABSOLUTE_LIFETIME) {
            self::logout();
            session_start();
            $_SESSION['_started_at'] = time();
        }
    }

    public static function attempt(string $username, string $password): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
        $stmt->execute(['u' => $username]);
        $user = $stmt->fetch();

        if (!$user) {
            // Still hash to keep timing consistent whether or not the user exists.
            password_hash('dummy-password', PASSWORD_ARGON2ID);
            return false;
        }

        if ($user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {
            return false;
        }

        if (!password_verify($password, $user['password_hash'])) {
            self::registerFailedAttempt((int) $user['id'], (int) $user['failed_attempts']);
            return false;
        }

        self::clearFailedAttempts((int) $user['id']);
        self::establishSession($user);
        return true;
    }

    private static function registerFailedAttempt(int $userId, int $currentAttempts): void
    {
        $pdo = Database::connection();
        $attempts = $currentAttempts + 1;
        $lockedUntil = null;

        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', time() + self::LOCKOUT_MINUTES * 60);
            $attempts = 0;
        }

        $stmt = $pdo->prepare('UPDATE users SET failed_attempts = :a, locked_until = :l WHERE id = :id');
        $stmt->execute(['a' => $attempts, 'l' => $lockedUntil, 'id' => $userId]);
    }

    private static function clearFailedAttempts(int $userId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = :id');
        $stmt->execute(['id' => $userId]);
    }

    private static function establishSession(array $user): void
    {
        // Regenerate the session ID on privilege change to prevent session fixation.
        session_regenerate_id(true);
        $_SESSION['user_id']      = (int) $user['id'];
        $_SESSION['username']     = $user['username'];
        $_SESSION['display_name'] = $user['display_name'];
        $_SESSION['initials']     = $user['initials'];
        $_SESSION['role']         = $user['role'];
        $_SESSION['_started_at']  = time();
    }

    public static function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: /login.php');
            exit;
        }
    }

    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        return [
            'id'           => $_SESSION['user_id'],
            'username'     => $_SESSION['username'],
            'display_name' => $_SESSION['display_name'],
            'initials'     => $_SESSION['initials'],
            'role'         => $_SESSION['role'],
        ];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        return is_string($token) && !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }
}
