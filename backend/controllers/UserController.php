<?php

declare(strict_types=1);

/**
 * Admin user management — approve/reject registrations, create accounts,
 * deactivate or delete users. Reachable only by the admin role.
 */
class UserController
{
    private function db(): PDO { return Database::pdo(); }

    public function roles(array $req, array $p): ?array
    {
        return ['items' => $this->db()->query('SELECT id, name, description FROM roles')->fetchAll()];
    }

    public function index(array $req, array $p): ?array
    {
        return ['items' => $this->db()->query(
            "SELECT u.id, u.name, u.email, u.phone, u.status, u.last_login, u.created_at,
                    r.name AS role, u.role_id
             FROM users u JOIN roles r ON r.id = u.role_id
             ORDER BY FIELD(u.status,'pending','active','rejected','disabled'), u.name")->fetchAll()];
    }

    /** Admin creates a user directly — goes straight to 'active'. */
    public function store(array $req, array $p): ?array
    {
        $d = Validator::validate($req['body'], [
            'name'     => 'required|min:2|max:100',
            'email'    => 'required|email|max:120',
            'phone'    => 'min:10|max:20',
            'password' => 'required|min:6|max:120',
            'role_id'  => 'int',
        ]);
        $email = strtolower(trim($d['email']));

        $roleId = (int)($d['role_id'] ?? 0);
        if (!$roleId) {
            $roleId = (int)$this->db()->query("SELECT id FROM roles WHERE name='staff'")->fetchColumn();
        }
        $role = $this->db()->prepare('SELECT id FROM roles WHERE id = ?');
        $role->execute([$roleId]);
        if (!$role->fetchColumn()) throw new ApiException('Invalid role', 422);

        try {
            $this->db()->prepare(
                "INSERT INTO users (role_id, name, username, email, phone, password_hash, status)
                 VALUES (?,?,?,?,?,?, 'active')"
            )->execute([$roleId, $d['name'], $email, $email,
                        $d['phone'] ?? null, password_hash($d['password'], PASSWORD_BCRYPT)]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                throw new ApiException('This email is already registered', 422);
            }
            throw $e;
        }
        return ['id' => (int)$this->db()->lastInsertId()];
    }

    /** Approve (active) / reject / deactivate / reactivate, or edit details. */
    public function update(array $req, array $p): ?array
    {
        $id = (int)$p['id'];
        $d = Validator::validate($req['body'], [
            'name'     => 'max:100',
            'role_id'  => 'int',
            'email'    => 'email|max:120',
            'phone'    => 'max:20',
            'status'   => 'in:active,pending,rejected,disabled',
            'password' => 'min:6|max:120',
        ]);

        if ($id === Auth::id() && isset($d['status']) && $d['status'] !== 'active') {
            throw new ApiException('You cannot change your own status', 422);
        }
        if ($id === Auth::id() && isset($d['role_id'])) {
            throw new ApiException('You cannot change your own role', 422);
        }

        $sets = []; $args = [];
        foreach (['name','role_id','email','phone','status'] as $f) {
            if (array_key_exists($f, $d) && $d[$f] !== null) { $sets[] = "{$f} = ?"; $args[] = $d[$f]; }
        }
        if (!empty($d['password'])) {
            $sets[] = 'password_hash = ?';
            $args[] = password_hash($d['password'], PASSWORD_BCRYPT);
        }
        if (!$sets) throw new ApiException('Nothing to update', 422);
        $args[] = $id;
        try {
            $this->db()->prepare('UPDATE users SET ' . implode(',', $sets) . ' WHERE id = ?')
                ->execute($args);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                throw new ApiException('Email already exists', 422);
            }
            throw $e;
        }
        return ['updated' => true];
    }

    /**
     * Delete the account. If the user has linked records (FK constraint),
     * the account is deactivated instead so history is preserved.
     */
    public function destroy(array $req, array $p): ?array
    {
        $id = (int)$p['id'];
        if ($id === Auth::id()) throw new ApiException('You cannot delete your own account', 422);

        try {
            $st = $this->db()->prepare('DELETE FROM users WHERE id = ?');
            $st->execute([$id]);
            if ($st->rowCount() === 0) throw new ApiException('User not found', 404);
            return ['deleted' => true];
        } catch (PDOException $e) {
            // FK constraint — user owns records; deactivate instead of deleting
            $this->db()->prepare("UPDATE users SET status='disabled' WHERE id = ?")->execute([$id]);
            return ['disabled' => true];
        }
    }
}
