<?php

declare(strict_types=1);

/**
 * Inventory + egg-stock movement logic.
 * MUST be called inside Database::tx() for multi-write safety.
 * Uses SELECT ... FOR UPDATE so concurrent requests can't oversell stock.
 */
class StockService
{
    /** Current on-hand qty for a product (0 when no inventory row). */
    public static function currentStock(int $productId): float
    {
        $st = Database::pdo()->prepare('SELECT quantity FROM inventory WHERE product_id = ?');
        $st->execute([$productId]);
        return (float)($st->fetchColumn() ?: 0);
    }

    /**
     * Apply a stock movement. $qtyIn / $qtyOut are positive numbers.
     * Throws if the movement would take stock below zero.
     * Weighted-average cost is recalculated on inbound movements that carry a cost.
     */
    public static function move(
        int $productId, string $type, float $qtyIn, float $qtyOut,
        ?string $refType = null, ?int $refId = null,
        ?string $notes = null, ?string $date = null,
        ?float $unitCost = null
    ): float {
        $pdo = Database::pdo();

        // lock (or create then lock) the inventory row
        $pdo->prepare('INSERT IGNORE INTO inventory (product_id, quantity) VALUES (?, 0)')
            ->execute([$productId]);
        $st = $pdo->prepare('SELECT quantity, avg_cost FROM inventory WHERE product_id = ? FOR UPDATE');
        $st->execute([$productId]);
        $row  = $st->fetch();
        $cur  = (float)$row['quantity'];
        $cost = (float)$row['avg_cost'];

        $new = round($cur + $qtyIn - $qtyOut, 3);
        if ($new < 0) {
            $pname = $pdo->prepare('SELECT name FROM products WHERE id = ?');
            $pname->execute([$productId]);
            throw new ApiException(
                "Insufficient stock for '{$pname->fetchColumn()}': available {$cur}, requested {$qtyOut}", 422);
        }

        if ($qtyIn > 0 && $unitCost !== null && $unitCost >= 0) {
            $cost = ($cur + $qtyIn) > 0
                ? round((($cur * $cost) + ($qtyIn * $unitCost)) / ($cur + $qtyIn), 4)
                : $unitCost;
        }

        $pdo->prepare('UPDATE inventory SET quantity = ?, avg_cost = ?, last_movement = NOW() WHERE product_id = ?')
            ->execute([$new, $cost, $productId]);

        $pdo->prepare(
            'INSERT INTO stock_movements
             (movement_date, product_id, movement_type, quantity_in, quantity_out, balance_after,
              reference_type, reference_id, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $date ?: date('Y-m-d'), $productId, $type, $qtyIn, $qtyOut, $new,
            $refType, $refId, $notes, Auth::id(),
        ]);

        return $new;
    }

    /** Current egg stock = last recorded closing balance (or sum of movements). */
    public static function eggStock(): int
    {
        $st = Database::pdo()->query(
            'SELECT balance_after FROM egg_stock_movements ORDER BY id DESC LIMIT 1');
        $v = $st->fetchColumn();
        return $v === false ? 0 : (int)$v;
    }

    /** Record an egg movement; throws if it would make stock negative (unless $allowNegative). */
    public static function moveEggs(
        int $qtySigned, string $type, ?string $refType = null,
        ?int $refId = null, ?string $notes = null, ?string $date = null,
        bool $allowNegative = false
    ): int {
        $pdo = Database::pdo();
        // lock latest movement row to serialise concurrent writers
        $pdo->query('SELECT id FROM egg_stock_movements ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $cur = self::eggStock();
        $new = $cur + $qtySigned;
        if ($new < 0 && !$allowNegative) {
            throw new ApiException("Insufficient egg stock: available {$cur}, requested " . abs($qtySigned), 422);
        }
        $pdo->prepare(
            'INSERT INTO egg_stock_movements
             (movement_date, movement_type, quantity, balance_after, reference_type, reference_id, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$date ?: date('Y-m-d'), $type, $qtySigned, $new, $refType, $refId, $notes, Auth::id()]);
        return $new;
    }

    /** Live bird count for a batch. */
    public static function batchBirds(int $batchId, bool $forUpdate = false): int
    {
        $sql = 'SELECT initial_quantity, sold_quantity, mortality_total
                FROM broiler_batches WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : '');
        $st = Database::pdo()->prepare($sql);
        $st->execute([$batchId]);
        $b = $st->fetch();
        if (!$b) throw new ApiException('Batch not found', 404);
        return (int)$b['initial_quantity'] - (int)$b['sold_quantity'] - (int)$b['mortality_total'];
    }
}
