<?php

declare(strict_types=1);

class ProductController
{
    private function db(): PDO { return Database::pdo(); }

    public function categories(array $req, array $p): ?array
    {
        return ['items' => $this->db()->query('SELECT * FROM categories ORDER BY type, name')->fetchAll()];
    }

    public function index(array $req, array $p): ?array
    {
        $q = $req['query'];
        $w = []; $args = [];
        if (!empty($q['category_id'])) { $w[] = 'p.category_id = ?'; $args[] = $q['category_id']; }
        if (!empty($q['type']))        { $w[] = 'c.type = ?';        $args[] = $q['type']; }
        if (!empty($q['search']))      { $w[] = 'p.name LIKE ?';     $args[] = '%' . $q['search'] . '%'; }
        if (!isset($q['all']))         { $w[] = "p.status = 'active'"; }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

        $st = $this->db()->prepare(
            "SELECT p.*, c.name AS category, c.type AS category_type,
                    COALESCE(i.quantity,0) AS stock
             FROM products p
             JOIN categories c ON c.id = p.category_id
             LEFT JOIN inventory i ON i.product_id = p.id
             {$where} ORDER BY c.type, p.name LIMIT 500");
        $st->execute($args);
        return ['items' => $st->fetchAll()];
    }

    public function store(array $req, array $p): ?array
    {
        $d = Validator::validate($req['body'], [
            'category_id'     => 'required|int',
            'name'            => 'required|max:120',
            'sku'             => 'max:40',
            'unit'            => 'required|max:20',
            'sale_price'      => 'numeric|min:0',
            'purchase_price'  => 'numeric|min:0',
            'min_stock_level' => 'numeric|min:0',
            'track_stock'     => 'int',
            'opening_stock'   => 'numeric|min:0',
        ]);

        return Database::tx(function () use ($d) {
            $cat = $this->db()->prepare('SELECT id FROM categories WHERE id = ?');
            $cat->execute([(int)$d['category_id']]);
            if (!$cat->fetchColumn()) throw new ApiException('Category not found', 422);

            $st = $this->db()->prepare(
                'INSERT INTO products (category_id, name, sku, unit, sale_price, purchase_price, min_stock_level, track_stock)
                 VALUES (?,?,?,?,?,?,?,?)');
            try {
                $st->execute([(int)$d['category_id'], $d['name'], $d['sku'] ?? null, $d['unit'],
                              $d['sale_price'] ?? 0, $d['purchase_price'] ?? 0,
                              $d['min_stock_level'] ?? 0, (int)($d['track_stock'] ?? 1)]);
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'Duplicate')) {
                    throw new ApiException('SKU already exists', 422);
                }
                throw $e;
            }
            $id = (int)$this->db()->lastInsertId();
            if (($d['opening_stock'] ?? 0) > 0) {
                StockService::move($id, 'opening', (float)$d['opening_stock'], 0,
                    'manual', null, 'Opening stock');
            }
            return ['id' => $id];
        });
    }

    public function update(array $req, array $p): ?array
    {
        $id = (int)$p['id'];
        $d = Validator::validate($req['body'], [
            'category_id'     => 'int',
            'name'            => 'max:120',
            'sku'             => 'max:40',
            'unit'            => 'max:20',
            'sale_price'      => 'numeric|min:0',
            'purchase_price'  => 'numeric|min:0',
            'min_stock_level' => 'numeric|min:0',
            'track_stock'     => 'int',
            'status'          => 'in:active,inactive',
        ]);
        $sets = []; $args = [];
        foreach ($d as $f => $v) {
            if ($v !== null) { $sets[] = "{$f} = ?"; $args[] = $v; }
        }
        if (!$sets) throw new ApiException('Nothing to update', 422);
        $args[] = $id;
        try {
            $this->db()->prepare('UPDATE products SET ' . implode(',', $sets) . ' WHERE id = ?')
                ->execute($args);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                throw new ApiException('SKU already exists', 422);
            }
            throw $e;
        }
        return ['updated' => true];
    }

    public function destroy(array $req, array $p): ?array
    {
        $id = (int)$p['id'];
        $used = $this->db()->prepare('SELECT COUNT(*) FROM stock_movements WHERE product_id = ?');
        $used->execute([$id]);
        if ($used->fetchColumn() > 0) {
            $this->db()->prepare("UPDATE products SET status='inactive' WHERE id = ?")->execute([$id]);
            return ['deactivated' => true];
        }
        $this->db()->prepare('DELETE FROM inventory WHERE product_id = ?')->execute([$id]);
        $this->db()->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        return ['deleted' => true];
    }
}
