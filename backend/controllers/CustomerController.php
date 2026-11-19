<?php

declare(strict_types=1);

class CustomerController
{
    private function db(): PDO { return Database::pdo(); }

    public function index(array $req, array $p): ?array
    {
        $q = $req['query'];
        $w = []; $args = [];
        if (!empty($q['search'])) {
            $w[] = '(c.name LIKE ? OR c.mobile LIKE ? OR c.code LIKE ?)';
            $s = '%' . $q['search'] . '%';
            $args = [$s, $s, $s];
        }
        if (!empty($q['type']))   { $w[] = 'c.customer_type = ?'; $args[] = $q['type']; }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        [$page, $perPage, $offset] = Validator::pageParams($q);

        $total = $this->db()->prepare("SELECT COUNT(*) FROM customers c {$where}");
        $total->execute($args);
        $st = $this->db()->prepare(
            "SELECT c.*,
                    COALESCE(l.t_sales,0)   AS total_sales,
                    COALESCE(l.t_paid,0)    AS total_paid,
                    COALESCE(l.outstanding,0) AS outstanding
             FROM customers c
             LEFT JOIN (
               SELECT customer_id,
                      SUM(CASE WHEN entry_type='sale' THEN debit ELSE 0 END) AS t_sales,
                      SUM(CASE WHEN entry_type='payment' THEN credit ELSE 0 END) AS t_paid,
                      SUM(debit) - SUM(credit) AS outstanding
               FROM customer_ledger GROUP BY customer_id
             ) l ON l.customer_id = c.id
             {$where} ORDER BY c.name LIMIT {$perPage} OFFSET {$offset}");
        $st->execute($args);
        return ['items' => $st->fetchAll(), 'total' => (int)$total->fetchColumn(),
                'page' => $page, 'per_page' => $perPage];
    }

    private function nextCode(): string
    {
        $n = (int)$this->db()->query('SELECT COALESCE(MAX(id),0)+1 FROM customers')->fetchColumn();
        return 'CUST-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    }

    public function store(array $req, array $p): ?array
    {
        $d = Validator::validate($req['body'], [
            'name'            => 'required|max:120',
            'mobile'          => 'max:20',
            'email'           => 'email|max:120',
            'address'         => 'max:500',
            'customer_type'   => 'in:retail,wholesale,hotel,bakery,farm,other',
            'opening_balance' => 'numeric|min:0',
        ]);

        return Database::tx(function () use ($d) {
            $st = $this->db()->prepare(
                'INSERT INTO customers (code, name, mobile, email, address, customer_type, opening_balance, created_by)
                 VALUES (?,?,?,?,?,?,?,?)');
            $st->execute([$this->nextCode(), $d['name'], $d['mobile'] ?? null, $d['email'] ?? null,
                          $d['address'] ?? null, $d['customer_type'] ?? 'retail',
                          $d['opening_balance'] ?? 0, Auth::id()]);
            $id = (int)$this->db()->lastInsertId();
            if (($d['opening_balance'] ?? 0) > 0) {
                LedgerService::postCustomer($id, 'opening', (float)$d['opening_balance'], 0,
                    null, null, 'Opening balance');
            }
            return ['id' => $id];
        });
    }

    public function show(array $req, array $p): ?array
    {
        $st = $this->db()->prepare('SELECT * FROM customers WHERE id = ?');
        $st->execute([(int)$p['id']]);
        $c = $st->fetch();
        if (!$c) throw new ApiException('Customer not found', 404);
        $c['outstanding'] = LedgerService::customerBalance((int)$p['id']);
        return $c;
    }

    public function update(array $req, array $p): ?array
    {
        $id = (int)$p['id'];
        $d = Validator::validate($req['body'], [
            'name'          => 'max:120',
            'mobile'        => 'max:20',
            'email'         => 'email|max:120',
            'address'       => 'max:500',
            'customer_type' => 'in:retail,wholesale,hotel,bakery,farm,other',
            'status'        => 'in:active,inactive',
        ]);
        $sets = []; $args = [];
        foreach ($d as $f => $v) {
            if ($v !== null) { $sets[] = "{$f} = ?"; $args[] = $v; }
        }
        if (!$sets) throw new ApiException('Nothing to update', 422);
        $args[] = $id;
        $this->db()->prepare('UPDATE customers SET ' . implode(',', $sets) . ' WHERE id = ?')
            ->execute($args);
        return $this->show($req, $p);
    }

    public function destroy(array $req, array $p): ?array
    {
        $id = (int)$p['id'];
        // never hard-delete a customer with transactions — deactivate instead
        $has = $this->db()->prepare('SELECT COUNT(*) FROM customer_ledger WHERE customer_id = ?');
        $has->execute([$id]);
        if ($has->fetchColumn() > 0) {
            $this->db()->prepare("UPDATE customers SET status='inactive' WHERE id=?")->execute([$id]);
            return ['deactivated' => true];
        }
        $this->db()->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
        return ['deleted' => true];
    }

    public function ledger(array $req, array $p): ?array
    {
        $id = (int)$p['id'];
        $q  = $req['query'];
        $w  = 'WHERE customer_id = ?'; $args = [$id];
        if (!empty($q['from'])) { $w .= ' AND ledger_date >= ?'; $args[] = $q['from']; }
        if (!empty($q['to']))   { $w .= ' AND ledger_date <= ?'; $args[] = $q['to']; }
        $st = $this->db()->prepare(
            "SELECT * FROM customer_ledger {$w} ORDER BY ledger_date, id LIMIT 500");
        $st->execute($args);
        return [
            'entries'     => $st->fetchAll(),
            'outstanding' => LedgerService::customerBalance($id),
        ];
    }

    public function outstanding(array $req, array $p): ?array
    {
        return ['items' => LedgerService::outstanding('customer')];
    }
}
