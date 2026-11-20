<?php

declare(strict_types=1);

/** Session-based authentication + permission helpers. */
class Auth
{
    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    /** Read `Authorization: Bearer <token>` from any SAPI (Apache/nginx/built-in). */
    private static function bearerToken(): ?string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? getallheaders()['authorization'] ?? null) : null);
        if ($h && preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($h), $m)) {
            return strtolower($m[1]);
        }
        return null;
    }

    /**
     * Issue a long-lived API token for the Android app (Capacitor WebView
     * can't rely on cross-site session cookies). Stored hashed; the plain
     * token is returned once to the client.
     */
    public static function issueToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM api_tokens WHERE user_id = ? AND expires_at < NOW()')->execute([$userId]);
        $pdo->prepare('INSERT INTO api_tokens (user_id, token_hash, expires_at)
                       VALUES (?,?, DATE_ADD(NOW(), INTERVAL 90 DAY))')
            ->execute([$userId, hash('sha256', $token)]);
        return $token;
    }

    /** Resolve a Bearer token to the same user shape session login produces. */
    private static function bearerUser(): ?array
    {
        $token = self::bearerToken();
        if (!$token) return null;

        $st = Database::pdo()->prepare(
            'SELECT u.*, r.name AS role_name FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             JOIN roles r ON r.id = u.role_id
             WHERE t.token_hash = ? AND t.expires_at > NOW() LIMIT 1');
        $st->execute([hash('sha256', $token)]);
        $user = $st->fetch();
        if (!$user) return null;

        $perm = Database::pdo()->prepare(
            'SELECT p.code FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?');
        $perm->execute([$user['role_id']]);

        return [
            'id'          => (int)$user['id'],
            'name'        => $user['name'],
            'username'    => $user['username'],
            'role'        => $user['role_name'],
            'role_id'     => (int)$user['role_id'],
            'permissions' => array_column($perm->fetchAll(), 'code'),
        ];
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
        // Revoke the app token too, if this request carried one.
        if ($t = self::bearerToken()) {
            Database::pdo()->prepare('DELETE FROM api_tokens WHERE token_hash = ?')
                ->execute([hash('sha256', $t)]);
        }
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
        if (!$u && ($u = self::bearerUser())) {
            $_SESSION['user'] = $u;   // token auth — same request shape as session
        }
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
