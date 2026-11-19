<?php

declare(strict_types=1);

class InventoryController
{
    private function db(): PDO { return Database::pdo(); }

    /** Stock list with valuation + low-stock flag. */
    public function index(array $req, array $p): ?array
    {
        $q = $req['query'];
        $w = ['pr.track_stock = 1', "pr.status = 'active'"]; $args = [];
        if (!empty($q['category'])) { $w[] = 'c.type = ?';      $args[] = $q['category']; }
        if (!empty($q['search']))   { $w[] = 'pr.name LIKE ?';  $args[] = '%' . $q['search'] . '%'; }
        if (!empty($q['low_only'])) { $w[] = 'COALESCE(i.quantity,0) <= pr.min_stock_level AND pr.min_stock_level > 0'; }
        $where = 'WHERE ' . implode(' AND ', $w);

        $items = $this->db()->prepare(
            "SELECT pr.id, pr.name, pr.sku, pr.unit, pr.min_stock_level,
                    c.name AS category, c.type AS category_type,
                    COALESCE(i.quantity,0) AS quantity,
                    COALESCE(i.avg_cost,0) AS avg_cost,
                    ROUND(COALESCE(i.quantity,0) * COALESCE(i.avg_cost,0), 2) AS stock_value,
                    i.last_movement
             FROM products pr
             JOIN categories c ON c.id = pr.category_id
             LEFT JOIN inventory i ON i.product_id = pr.id
             {$where} ORDER BY c.type, pr.name LIMIT 500");
        $items->execute($args);
        $rows = $items->fetchAll();

        $totalValue = array_sum(array_column($rows, 'stock_value'));
        return ['items' => $rows, 'total_value' => round($totalValue, 2),
                'egg_stock' => StockService::eggStock()];
    }

    /** Manual stock adjustment (count correction, wastage). */
    public function adjust(array $req, array $p): ?array
    {
        $d = Validator::validate($req['body'], [
            'product_id' => 'required|int',
            'quantity'   => 'required|numeric',      // signed: + adds, − removes
            'reason'     => 'required|max:255',
            'date'       => 'date',
        ]);
        if ((float)$d['quantity'] == 0.0) throw new ApiException('Quantity cannot be zero', 422);

        return Database::tx(function () use ($d) {
            $qty = (float)$d['quantity'];
            $new = StockService::move((int)$d['product_id'],
                $qty < 0 ? 'wastage' : 'adjustment',
                max(0, $qty), max(0, -$qty),
                'manual', null, $d['reason'], $d['date'] ?? null);
            return ['quantity' => $new];
        });
    }

    /** Record consumption (feed fed to birds, medicine used). */
    public function consume(array $req, array $p): ?array
    {
        $d = Validator::validate($req['body'], [
            'product_id' => 'required|int',
            'quantity'   => 'required|numeric|min:0.001',
            'notes'      => 'max:255',
            'date'       => 'date',
            'batch_id'   => 'int',
        ]);
        return Database::tx(function () use ($d) {
            $notes = $d['notes'] ?? 'Consumption';
            if (!empty($d['batch_id'])) $notes .= ' (batch #' . (int)$d['batch_id'] . ')';
            $new = StockService::move((int)$d['product_id'], 'consumption',
                0, (float)$d['quantity'], 'manual', null, $notes, $d['date'] ?? null);
            return ['quantity' => $new];
        });
    }

    public function movements(array $req, array $p): ?array
    {
        $q = $req['query'];
        $w = []; $args = [];
        if (!empty($q['product_id'])) { $w[] = 'sm.product_id = ?';        $args[] = $q['product_id']; }
        if (!empty($q['type']))       { $w[] = 'sm.movement_type = ?';     $args[] = $q['type']; }
        if (!empty($q['from']))       { $w[] = 'sm.movement_date >= ?';    $args[] = $q['from']; }
        if (!empty($q['to']))         { $w[] = 'sm.movement_date <= ?';    $args[] = $q['to']; }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

        $st = $this->db()->prepare(
            "SELECT sm.*, pr.name AS product_name, pr.unit
             FROM stock_movements sm JOIN products pr ON pr.id = sm.product_id
             {$where} ORDER BY sm.id DESC LIMIT 500");
        $st->execute($args);
        return ['items' => $st->fetchAll()];
    }

    public function lowStock(array $req, array $p): ?array
    {
        return ['items' => $this->db()->query(
            'SELECT pr.id, pr.name, pr.unit, pr.min_stock_level, COALESCE(i.quantity,0) AS quantity
             FROM products pr LEFT JOIN inventory i ON i.product_id = pr.id
             WHERE pr.track_stock = 1 AND pr.min_stock_level > 0
               AND COALESCE(i.quantity,0) <= pr.min_stock_level
             ORDER BY pr.name')->fetchAll()];
    }
}
