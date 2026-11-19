<?php

declare(strict_types=1);

/**
 * Money / हिसाब — one row per income or expense.
 * Deliberately simple: no ledgers, no balances per party, just a daily register.
 */
class MoneyController
{
    private function db(): PDO { return Database::pdo(); }

    /** Common categories offered in the UI dropdown (stored as plain text). */
    public function categories(array $req, array $p): ?array
    {
        return [
            'income'  => ['Egg Sale', 'Bird Sale', 'Manure Sale', 'Other Income'],
            'expense' => ['Feed', 'Medicine', 'Chicks', 'Labour', 'Electricity', 'Transport', 'Other'],
        ];
    }

    public function index(array $req, array $p): ?array
    {
        $q = $req['query'];
        $w = ['created_by = ?']; $args = [(int)Auth::id()];
        if (!empty($q['from'])) { $w[] = 'txn_date >= ?'; $args[] = $q['from']; }
        if (!empty($q['to']))   { $w[] = 'txn_date <= ?'; $args[] = $q['to']; }
        if (!empty($q['type'])) { $w[] = 'type = ?';       $args[] = $q['type']; }
        $where = 'WHERE ' . implode(' AND ', $w);

        $st = $this->db()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount END),0) AS income,
                    COALESCE(SUM(CASE WHEN type='expense' THEN amount END),0) AS expense
             FROM money_transactions {$where}");
        $st->execute($args);
        $totals = $st->fetch();

        [$page, $perPage, $offset] = Validator::pageParams($q);
        $count = $this->db()->prepare("SELECT COUNT(*) FROM money_transactions {$where}");
        $count->execute($args);

        $rows = $this->db()->prepare(
            "SELECT * FROM money_transactions {$where}
             ORDER BY txn_date DESC, id DESC LIMIT {$perPage} OFFSET {$offset}");
        $rows->execute($args);

        return [
            'items'    => $rows->fetchAll(),
            'income'   => (float)$totals['income'],
            'expense'  => (float)$totals['expense'],
            'balance'  => (float)$totals['income'] - (float)$totals['expense'],
            'total'    => (int)$count->fetchColumn(),
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    public function store(array $req, array $p): ?array
    {
        $d = $this->validate($req['body']);
        $this->db()->prepare(
            'INSERT INTO money_transactions (txn_date, type, category, description, amount, payment_mode, created_by)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$d['txn_date'], $d['type'], $d['category'] ?? 'Other',
                    $d['description'] ?? null, $d['amount'], $d['payment_mode'], Auth::id()]);
        return ['id' => (int)$this->db()->lastInsertId()];
    }

    public function update(array $req, array $p): ?array
    {
        $d = $this->validate($req['body']);
        $st = $this->db()->prepare(
            'UPDATE money_transactions
             SET txn_date=?, type=?, category=?, description=?, amount=?, payment_mode=?
             WHERE id=? AND created_by=?');
        $st->execute([$d['txn_date'], $d['type'], $d['category'] ?? 'Other',
                      $d['description'] ?? null, $d['amount'], $d['payment_mode'],
                      $p['id'], (int)Auth::id()]);
        if ($st->rowCount() === 0) throw new ApiException('Entry not found', 404);
        return ['updated' => true];
    }

    public function destroy(array $req, array $p): ?array
    {
        $st = $this->db()->prepare('DELETE FROM money_transactions WHERE id = ? AND created_by = ?');
        $st->execute([$p['id'], (int)Auth::id()]);
        if ($st->rowCount() === 0) throw new ApiException('Entry not found', 404);
        return ['deleted' => true];
    }

    private function validate(array $body): array
    {
        $d = Validator::validate($body, [
            'txn_date'     => 'required|date',
            'type'         => 'required|in:income,expense',
            'category'     => 'max:60',
            'description'  => 'max:255',
            'amount'       => 'required|numeric|min:0.01',
            'payment_mode' => 'in:cash,upi,bank',
        ]);
        $d['payment_mode'] = $d['payment_mode'] ?? 'cash';
        return $d;
    }
}
