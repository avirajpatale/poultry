<?php

declare(strict_types=1);

/**
 * Raw material purchases — private to each user (created_by).
 * Kept in its own table so nothing is double-counted in the money hisab;
 * dashboard/report pages merge these totals on top of money_transactions.
 */
class MaterialController
{
    private function db(): PDO { return Database::pdo(); }
    private function me(): int { return (int)Auth::id(); }

    // ---------- categories ----------

    public function categories(array $req, array $p): ?array
    {
        $st = $this->db()->prepare(
            'SELECT id, name FROM material_categories WHERE created_by = ? ORDER BY name');
        $st->execute([$this->me()]);
        return ['items' => $st->fetchAll()];
    }

    public function storeCategory(array $req, array $p): ?array
    {
        $d = Validator::validate($req['body'], ['name' => 'required|min:1|max:80']);
        try {
            $this->db()->prepare('INSERT INTO material_categories (name, created_by) VALUES (?,?)')
                ->execute([trim($d['name']), $this->me()]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                throw new ApiException('Material already exists', 422);
            }
            throw $e;
        }
        return ['id' => (int)$this->db()->lastInsertId()];
    }

    public function updateCategory(array $req, array $p): ?array
    {
        $d = Validator::validate($req['body'], ['name' => 'required|min:1|max:80']);
        $st = $this->db()->prepare(
            'UPDATE material_categories SET name = ? WHERE id = ? AND created_by = ?');
        $st->execute([trim($d['name']), $p['id'], $this->me()]);
        if ($st->rowCount() === 0) throw new ApiException('Material not found', 404);
        return ['updated' => true];
    }

    public function destroyCategory(array $req, array $p): ?array
    {
        $st = $this->db()->prepare(
            'DELETE FROM material_categories WHERE id = ? AND created_by = ?');
        $st->execute([$p['id'], $this->me()]);
        if ($st->rowCount() === 0) throw new ApiException('Material not found', 404);
        return ['deleted' => true];
    }

    // ---------- expense entries ----------

    public function index(array $req, array $p): ?array
    {
        $q = $req['query'];
        $w = ['e.created_by = ?']; $args = [$this->me()];
        if (!empty($q['from']))        { $w[] = 'e.txn_date >= ?';   $args[] = $q['from']; }
        if (!empty($q['to']))          { $w[] = 'e.txn_date <= ?';   $args[] = $q['to']; }
        if (!empty($q['category_id'])) { $w[] = 'e.category_id = ?'; $args[] = $q['category_id']; }
        $where = 'WHERE ' . implode(' AND ', $w);

        // per-material totals for the same filter (qty + amount)
        $sm = $this->db()->prepare(
            "SELECT e.category_id, c.name,
                    SUM(e.quantity) AS qty, e.unit,
                    SUM(e.amount)   AS amount
             FROM material_expenses e
             JOIN material_categories c ON c.id = e.category_id
             {$where} GROUP BY e.category_id, e.unit ORDER BY amount DESC");
        $sm->execute($args);

        [$page, $perPage, $offset] = Validator::pageParams($q);
        $cnt = $this->db()->prepare("SELECT COUNT(*) FROM material_expenses e {$where}");
        $cnt->execute($args);

        $st = $this->db()->prepare(
            "SELECT e.*, c.name AS category_name
             FROM material_expenses e
             JOIN material_categories c ON c.id = e.category_id
             {$where} ORDER BY e.txn_date DESC, e.id DESC
             LIMIT {$perPage} OFFSET {$offset}");
        $st->execute($args);

        $summary = $sm->fetchAll();
        return [
            'items'       => $st->fetchAll(),
            'summary'     => $summary,
            'grand_qty'   => array_sum(array_map(fn($s) => (float)$s['qty'], $summary)),
            'grand_total' => array_sum(array_map(fn($s) => (float)$s['amount'], $summary)),
            'total'       => (int)$cnt->fetchColumn(),
            'page'        => $page,
            'per_page'    => $perPage,
        ];
    }

    /** Just the spend total for a range — used by dashboard/monthly report merge. */
    public function totals(array $req, array $p): ?array
    {
        $q = $req['query'];
        $st = $this->db()->prepare(
            'SELECT COALESCE(SUM(amount),0) FROM material_expenses
             WHERE created_by = ? AND txn_date >= ? AND txn_date <= ?');
        $st->execute([$this->me(), $q['from'] ?? '0000-01-01', $q['to'] ?? '9999-12-31']);
        return ['total' => (float)$st->fetchColumn()];
    }

    public function store(array $req, array $p): ?array
    {
        $d = $this->validate($req['body']);
        $this->assertCategoryOwned((int)$d['category_id']);
        $amount = round($d['quantity'] * $d['rate'], 2);
        $this->db()->prepare(
            'INSERT INTO material_expenses
             (txn_date, category_id, quantity, unit, rate, amount, supplier, payment_mode, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([$d['txn_date'], (int)$d['category_id'], $d['quantity'], $d['unit'] ?? 'KG',
                    $d['rate'], $amount, $d['supplier'] ?? null,
                    $d['payment_mode'] ?? 'cash', $d['notes'] ?? null, $this->me()]);
        return ['id' => (int)$this->db()->lastInsertId(), 'amount' => $amount];
    }

    public function update(array $req, array $p): ?array
    {
        $d = $this->validate($req['body']);
        $this->assertCategoryOwned((int)$d['category_id']);
        $amount = round($d['quantity'] * $d['rate'], 2);
        $st = $this->db()->prepare(
            'UPDATE material_expenses
             SET txn_date=?, category_id=?, quantity=?, unit=?, rate=?, amount=?,
                 supplier=?, payment_mode=?, notes=?
             WHERE id=? AND created_by=?');
        $st->execute([$d['txn_date'], (int)$d['category_id'], $d['quantity'], $d['unit'] ?? 'KG',
                      $d['rate'], $amount, $d['supplier'] ?? null,
                      $d['payment_mode'] ?? 'cash', $d['notes'] ?? null,
                      $p['id'], $this->me()]);
        if ($st->rowCount() === 0) throw new ApiException('Entry not found', 404);
        return ['updated' => true, 'amount' => $amount];
    }

    public function destroy(array $req, array $p): ?array
    {
        $st = $this->db()->prepare(
            'DELETE FROM material_expenses WHERE id = ? AND created_by = ?');
        $st->execute([$p['id'], $this->me()]);
        if ($st->rowCount() === 0) throw new ApiException('Entry not found', 404);
        return ['deleted' => true];
    }

    private function validate(array $body): array
    {
        return Validator::validate($body, [
            'txn_date'     => 'required|date',
            'category_id'  => 'required|int',
            'quantity'     => 'required|numeric|min:0.01',
            'unit'         => 'max:20',
            'rate'         => 'required|numeric|min:0',
            'supplier'     => 'max:120',
            'payment_mode' => 'in:cash,upi,bank',
            'notes'        => 'max:255',
        ]);
    }

    private function assertCategoryOwned(int $catId): void
    {
        $st = $this->db()->prepare(
            'SELECT id FROM material_categories WHERE id = ? AND created_by = ?');
        $st->execute([$catId, $this->me()]);
        if (!$st->fetchColumn()) throw new ApiException('Invalid material', 422);
    }
}
