<?php

declare(strict_types=1);

class AuthController
{
    /** Login with registered email + password. Only 'active' (admin-approved) users get in. */
    public function login(array $req, array $params): ?array
    {
        $d = Validator::validate($req['body'], [
            'email'    => 'required|max:120',
            'password' => 'required|max:120',
        ]);

        $st = Database::pdo()->prepare(
            'SELECT u.*, r.name AS role_name FROM users u
             JOIN roles r ON r.id = u.role_id WHERE u.email = ?');
        $st->execute([strtolower(trim($d['email']))]);
        $user = $st->fetch();

        if (!$user || !password_verify($d['password'], $user['password_hash'])) {
            throw new ApiException('Invalid email or password', 401);
        }
        match ($user['status']) {
            'active'   => null,
            'pending'  => throw new ApiException('Your account is waiting for admin approval', 403),
            'rejected' => throw new ApiException('Your registration was not approved', 403),
            default    => throw new ApiException('Account is disabled', 403),
        };

        $perm = Database::pdo()->prepare(
            'SELECT p.code FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?');
        $perm->execute([$user['role_id']]);
        $user['permissions'] = array_column($perm->fetchAll(), 'code');

        Auth::login($user);
        Database::pdo()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
            ->execute([$user['id']]);

        return ['user' => Auth::user(), 'token' => Auth::issueToken((int)$user['id'])];
    }

    /**
     * Public registration — creates an ACTIVE account and logs the user in
     * immediately, so they land straight on the dashboard.
     */
    public function register(array $req, array $params): ?array
    {
        $d = Validator::validate($req['body'], [
            'name'     => 'required|min:2|max:100',
            'email'    => 'required|email|max:120',
            'mobile'   => 'required|min:10|max:20',
            'password' => 'required|min:6|max:120',
        ]);
        $email = strtolower(trim($d['email']));

        $staff = Database::pdo()->query("SELECT id FROM roles WHERE name='staff'")->fetchColumn();
        if (!$staff) $staff = Database::pdo()->query('SELECT id FROM roles ORDER BY id LIMIT 1')->fetchColumn();

        try {
            Database::pdo()->prepare(
                "INSERT INTO users (role_id, name, username, email, phone, password_hash, status)
                 VALUES (?,?,?,?,?,?, 'active')"
            )->execute([(int)$staff, $d['name'], $email, $email,
                        $d['mobile'], password_hash($d['password'], PASSWORD_BCRYPT)]);
            $userId = (int)Database::pdo()->lastInsertId();
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                throw new ApiException('This email is already registered', 422);
            }
            throw $e;
        }

        // auto-login: same session shape as login()
        $st = Database::pdo()->prepare(
            'SELECT u.*, r.name AS role_name FROM users u
             JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
        $st->execute([$userId]);
        $user = $st->fetch();
        $perm = Database::pdo()->prepare(
            'SELECT p.code FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?');
        $perm->execute([$user['role_id']]);
        $user['permissions'] = array_column($perm->fetchAll(), 'code');

        Auth::login($user);
        return ['user' => Auth::user(), 'token' => Auth::issueToken($userId)];
    }

    /**
     * Start password reset. Always returns success so emails can't be probed.
     * Token is emailed when mail() is configured; in development the reset
     * link is also returned in `dev_link` and written to the PHP error log.
     */
    public function forgot(array $req, array $params): ?array
    {
        $d = Validator::validate($req['body'], ['email' => 'required|email|max:120']);
        $email = strtolower(trim($d['email']));

        $st = Database::pdo()->prepare("SELECT id, name FROM users WHERE email = ? AND status='active'");
        $st->execute([$email]);
        $user = $st->fetch();

        $out = ['sent' => true];
        if ($user) {
            $token = bin2hex(random_bytes(16));  // 32-char token emailed to the user
            Database::pdo()->prepare(
                'INSERT INTO password_resets (email, token_hash, expires_at)
                 VALUES (?,?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))'
            )->execute([$email, hash('sha256', $token)]);

            $link = (getenv('APP_URL') ?: 'http://localhost:5173') . '/reset-password?token=' . $token;
            $subject = 'Poultry Hisab - Password Reset';
            $body = "Namaste {$user['name']},\n\nClick to reset your password (valid 30 minutes):\n{$link}\n\nIf you did not ask for this, ignore this email.";
            @mail($email, $subject, $body, "From: noreply@farm.local\r\nContent-Type: text/plain; charset=utf-8");
            error_log("[password-reset] {$email}: {$link}");
            if (APP_ENV === 'development') $out['dev_link'] = $link;
        }
        return $out;
    }

    /** Complete password reset with the emailed token. */
    public function reset(array $req, array $params): ?array
    {
        $d = Validator::validate($req['body'], [
            'token'    => 'required|min:20|max:120',
            'password' => 'required|min:6|max:120',
        ]);

        $st = Database::pdo()->prepare(
            'SELECT * FROM password_resets
             WHERE token_hash = ? AND used = 0 AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1');
        $st->execute([hash('sha256', $d['token'])]);
        $row = $st->fetch();
        if (!$row) throw new ApiException('Reset link is invalid or expired', 422);

        Database::tx(function () use ($row, $d) {
            Database::pdo()->prepare('UPDATE users SET password_hash = ? WHERE email = ?')
                ->execute([password_hash($d['password'], PASSWORD_BCRYPT), $row['email']]);
            Database::pdo()->prepare('UPDATE password_resets SET used = 1 WHERE id = ?')
                ->execute([$row['id']]);
        });

        return ['reset' => true];
    }

    public function logout(array $req, array $params): ?array
    {
        Auth::logout();
        return ['message' => 'Logged out'];
    }

    public function me(array $req, array $params): ?array
    {
        return ['user' => Auth::user()];
    }
}
