<?php

declare(strict_types=1);

class ExpenseController
{
    private function db(): PDO { return Database::pdo(); }

    public function categories(array $req, array $p): ?array
    {
        return ['items' => $this->db()->query(
            "SELECT * FROM expense_categories WHERE status='active' ORDER BY name")->fetchAll()];
    }

    public function index(array $req, array $p): ?array
    {
        $q = $req['query'];
        $w = ["e.status = 'active'"]; $args = [];
        if (!empty($q['from']))        { $w[] = 'e.expense_date >= ?'; $args[] = $q['from']; }
        if (!empty($q['to']))          { $w[] = 'e.expense_date <= ?'; $args[] = $q['to']; }
        if (!empty($q['category_id'])) { $w[] = 'e.category_id = ?';   $args[] = $q['category_id']; }
        if (!empty($q['batch_id']))    { $w[] = 'e.batch_id = ?';      $args[] = $q['batch_id']; }
        if (!empty($q['search']))      { $w[] = 'e.description LIKE ?'; $args[] = '%' . $q['search'] . '%'; }
        $where = 'WHERE ' . implode(' AND ', $w);
        [$page, $perPage, $offset] = Validator::pageParams($q);

        $count = $this->db()->prepare("SELECT COUNT(*), COALESCE(SUM(e.amount),0) FROM expenses e {$where}");
        $count->execute($args);
        [$total, $sum] = $count->fetch(PDO::FETCH_NUM);

        $st = $this->db()->prepare(
            "SELECT e.*, c.name AS category, b.batch_code
             FROM expenses e
             JOIN expense_categories c ON c.id = e.category_id
             LEFT JOIN broiler_batches b ON b.id = e.batch_id
             {$where} ORDER BY e.expense_date DESC, e.id DESC
             LIMIT {$perPage} OFFSET {$offset}");
        $st->execute($args);
        return ['items' => $st->fetchAll(), 'total' => (int)$total,
                'sum' => (float)$sum, 'page' => $page, 'per_page' => $perPage];
    }

    public function store(array $req, array $p): ?array
    {
        $d = Validator::validate($req['body'], [
            'expense_date' => 'required|date',
            'category_id'  => 'required|int',
            'description'  => 'required|max:255',
            'amount'       => 'required|numeric|min:0.01',
            'payment_mode' => 'in:cash,upi,bank,credit',
            'batch_id'     => 'int',
            'vendor'       => 'max:120',
            'notes'        => 'max:255',
        ]);
        $st = $this->db()->prepare(
            'INSERT INTO expenses (expense_date, category_id, description, amount, payment_mode, batch_id, vendor, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?)');
        $st->execute([$d['expense_date'], (int)$d['category_id'], $d['description'],
                      $d['amount'], $d['payment_mode'] ?? 'cash', $d['batch_id'] ?? null,
                      $d['vendor'] ?? null, $d['notes'] ?? null, Auth::id()]);
        return ['id' => (int)$this->db()->lastInsertId()];
    }

    public function update(array $req, array $p): ?array
    {
        $d = Validator::validate($req['body'], [
            'expense_date' => 'date',
            'category_id'  => 'int',
            'description'  => 'max:255',
            'amount'       => 'numeric|min:0.01',
            'payment_mode' => 'in:cash,upi,bank,credit',
            'batch_id'     => 'int',
            'vendor'       => 'max:120',
            'notes'        => 'max:255',
        ]);
        $sets = []; $args = [];
        foreach ($d as $f => $v) {
            if ($v !== null) { $sets[] = "{$f} = ?"; $args[] = $v; }
        }
        if (!$sets) throw new ApiException('Nothing to update', 422);
        $args[] = (int)$p['id'];
        $this->db()->prepare(
            "UPDATE expenses SET " . implode(',', $sets) . " WHERE id = ? AND status='active'")
            ->execute($args);
        return ['updated' => true];
    }

    /** Financial records are cancelled, never deleted. */
    public function destroy(array $req, array $p): ?array
    {
        $this->db()->prepare("UPDATE expenses SET status='cancelled' WHERE id = ?")
            ->execute([(int)$p['id']]);
        return ['cancelled' => true];
    }
}
