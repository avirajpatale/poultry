<?php

declare(strict_types=1);

class SupplierController
{
    private function db(): PDO { return Database::pdo(); }

    public function index(array $req, array $p): ?array
    {
        $q = $req['query'];
        $w = []; $args = [];
        if (!empty($q['search'])) {
            $w[] = '(s.name LIKE ? OR s.mobile LIKE ? OR s.code LIKE ?)';
            $s = '%' . $q['search'] . '%';
            $args = [$s, $s, $s];
        }
        if (!empty($q['type'])) { $w[] = 's.supplier_type = ?'; $args[] = $q['type']; }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        [$page, $perPage, $offset] = Validator::pageParams($q);

        $total = $this->db()->prepare("SELECT COUNT(*) FROM suppliers s {$where}");
        $total->execute($args);
        $st = $this->db()->prepare(
            "SELECT s.*,
                    COALESCE(l.t_purchases,0) AS total_purchases,
                    COALESCE(l.t_paid,0)      AS total_paid,
                    COALESCE(l.outstanding,0) AS outstanding
             FROM suppliers s
             LEFT JOIN (
               SELECT supplier_id,
                      SUM(CASE WHEN entry_type='purchase' THEN credit ELSE 0 END) AS t_purchases,
                      SUM(CASE WHEN entry_type='payment'  THEN debit  ELSE 0 END) AS t_paid,
                      SUM(credit) - SUM(debit) AS outstanding
               FROM supplier_ledger GROUP BY supplier_id
             ) l ON l.supplier_id = s.id
             {$where} ORDER BY s.name LIMIT {$perPage} OFFSET {$offset}");
        $st->execute($args);
        return ['items' => $st->fetchAll(), 'total' => (int)$total->fetchColumn(),
                'page' => $page, 'per_page' => $perPage];
    }

    private function nextCode(): string
    {
        $n = (int)$this->db()->query('SELECT COALESCE(MAX(id),0)+1 FROM suppliers')->fetchColumn();
        return 'SUPP-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    }

    public function store(array $req, array $p): ?array
    {
        $d = Validator::validate($req['body'], [
            'name'            => 'required|max:120',
            'contact_person'  => 'max:120',
            'mobile'          => 'max:20',
            'email'           => 'email|max:120',
            'address'         => 'max:500',
            'supplier_type'   => 'in:feed,medicine,chicks,equipment,packaging,other',
            'opening_balance' => 'numeric|min:0',
        ]);

        return Database::tx(function () use ($d) {
            $st = $this->db()->prepare(
                'INSERT INTO suppliers (code, name, contact_person, mobile, email, address, supplier_type, opening_balance)
                 VALUES (?,?,?,?,?,?,?,?)');
            $st->execute([$this->nextCode(), $d['name'], $d['contact_person'] ?? null,
                          $d['mobile'] ?? null, $d['email'] ?? null, $d['address'] ?? null,
                          $d['supplier_type'] ?? 'other', $d['opening_balance'] ?? 0]);
            $id = (int)$this->db()->lastInsertId();
            if (($d['opening_balance'] ?? 0) > 0) {
                LedgerService::postSupplier($id, 'opening', 0, (float)$d['opening_balance'],
                    null, null, 'Opening balance');
            }
            return ['id' => $id];
        });
    }

    public function show(array $req, array $p): ?array
    {
        $st = $this->db()->prepare('SELECT * FROM suppliers WHERE id = ?');
        $st->execute([(int)$p['id']]);
        $s = $st->fetch();
        if (!$s) throw new ApiException('Supplier not found', 404);
        $s['outstanding'] = LedgerService::supplierBalance((int)$p['id']);
        return $s;
    }

    public function update(array $req, array $p): ?array
    {
        $id = (int)$p['id'];
        $d = Validator::validate($req['body'], [
            'name'           => 'max:120',
            'contact_person' => 'max:120',
            'mobile'         => 'max:20',
            'email'          => 'email|max:120',
            'address'        => 'max:500',
            'supplier_type'  => 'in:feed,medicine,chicks,equipment,packaging,other',
            'status'         => 'in:active,inactive',
        ]);
        $sets = []; $args = [];
        foreach ($d as $f => $v) {
            if ($v !== null) { $sets[] = "{$f} = ?"; $args[] = $v; }
        }
        if (!$sets) throw new ApiException('Nothing to update', 422);
        $args[] = $id;
        $this->db()->prepare('UPDATE suppliers SET ' . implode(',', $sets) . ' WHERE id = ?')
            ->execute($args);
        return $this->show($req, $p);
    }

    public function destroy(array $req, array $p): ?array
    {
        $id = (int)$p['id'];
        $has = $this->db()->prepare('SELECT COUNT(*) FROM supplier_ledger WHERE supplier_id = ?');
        $has->execute([$id]);
        if ($has->fetchColumn() > 0) {
            $this->db()->prepare("UPDATE suppliers SET status='inactive' WHERE id=?")->execute([$id]);
            return ['deactivated' => true];
        }
        $this->db()->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$id]);
        return ['deleted' => true];
    }

    public function ledger(array $req, array $p): ?array
    {
        $id = (int)$p['id'];
        $q  = $req['query'];
        $w  = 'WHERE supplier_id = ?'; $args = [$id];
        if (!empty($q['from'])) { $w .= ' AND ledger_date >= ?'; $args[] = $q['from']; }
        if (!empty($q['to']))   { $w .= ' AND ledger_date <= ?'; $args[] = $q['to']; }
        $st = $this->db()->prepare(
            "SELECT * FROM supplier_ledger {$w} ORDER BY ledger_date, id LIMIT 500");
        $st->execute($args);
        return [
            'entries'     => $st->fetchAll(),
            'outstanding' => LedgerService::supplierBalance($id),
        ];
    }

    public function outstanding(array $req, array $p): ?array
    {
        return ['items' => LedgerService::outstanding('supplier')];
    }
}
