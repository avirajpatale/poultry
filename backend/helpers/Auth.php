<?php

declare(strict_types=1);

/** Session-based authentication + permission helpers. */
class Auth
{
    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function id(): ?int
    {
        return isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'          => (int)$user['id'],
            'name'        => $user['name'],
            'username'    => $user['username'],
            'role'        => $user['role_name'],
            'role_id'     => (int)$user['role_id'],
            'permissions' => $user['permissions'] ?? [],
        ];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function check(): void
    {
        $u = self::user();
        if (!$u) {
            throw new ApiException('Unauthenticated', 401);
        }
        // Re-verify the account every request: a user the admin has
        // deactivated/rejected loses access immediately, not at next login.
        $st = Database::pdo()->prepare('SELECT status FROM users WHERE id = ?');
        $st->execute([$u['id']]);
        $status = $st->fetchColumn();
        if ($status !== 'active') {
            self::logout();
            throw new ApiException('Account is no longer active', 403);
        }
    }

    /** Permission codes come from role_permissions; admin role implicitly has all. */
    public static function can(string $permission): bool
    {
        $u = self::user();
        if (!$u) return false;
        if ($u['role'] === 'admin') return true;
        return in_array($permission, $u['permissions'], true);
    }

    public static function requirePermission(string $permission): void
    {
        self::check();
        if (!self::can($permission)) {
            throw new ApiException('Forbidden: missing permission ' . $permission, 403);
        }
    }
}
