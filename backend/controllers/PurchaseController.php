<?php

declare(strict_types=1);

/**
 * Purchases: header + items + stock-in (weighted avg cost) + supplier ledger + payment.
 */
class PurchaseController
{
    private function db(): PDO { return Database::pdo(); }

    public function index(array $req, array $p): ?array
    {
        $q = $req['query'];
        $w = []; $args = [];
        if (!empty($q['from']))        { $w[] = 'pu.purchase_date >= ?'; $args[] = $q['from']; }
        if (!empty($q['to']))          { $w[] = 'pu.purchase_date <= ?'; $args[] = $q['to']; }
        if (!empty($q['supplier_id'])) { $w[] = 'pu.supplier_id = ?';    $args[] = $q['supplier_id']; }
        if (!empty($q['status']))      { $w[] = 'pu.status = ?';         $args[] = $q['status']; }
        if (!empty($q['search']))      { $w[] = 'pu.invoice_no LIKE ?';  $args[] = '%' . $q['search'] . '%'; }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        [$page, $perPage, $offset] = Validator::pageParams($q);

        $count = $this->db()->prepare("SELECT COUNT(*) FROM purchases pu {$where}");
        $count->execute($args);
        $st = $this->db()->prepare(
            "SELECT pu.*, COALESCE(su.name,'—') AS supplier_name
             FROM purchases pu LEFT JOIN suppliers su ON su.id = pu.supplier_id
             {$where} ORDER BY pu.purchase_date DESC, pu.id DESC
             LIMIT {$perPage} OFFSET {$offset}");
        $st->execute($args);
        return ['items' => $st->fetchAll(), 'total' => (int)$count->fetchColumn(),
                'page' => $page, 'per_page' => $perPage];
    }

    public function show(array $req, array $p): ?array
    {
        $id = (int)$p['id'];
        $st = $this->db()->prepare(
            'SELECT pu.*, su.name AS supplier_name, su.mobile AS supplier_mobile
             FROM purchases pu LEFT JOIN suppliers su ON su.id = pu.supplier_id
             WHERE pu.id = ?');
        $st->execute([$id]);
        $pu = $st->fetch();
        if (!$pu) throw new ApiException('Purchase not found', 404);
        $items = $this->db()->prepare('SELECT * FROM purchase_items WHERE purchase_id = ?');
        $items->execute([$id]);
        $pu['items'] = $items->fetchAll();
        return $pu;
    }

    public function store(array $req, array $p): ?array
    {
        $d = Validator::validate($req['body'], [
            'invoice_no'    => 'required|max:40',
            'purchase_date' => 'required|date',
            'supplier_id'   => 'int',
            'discount'      => 'numeric|min:0',
            'paid_amount'   => 'numeric|min:0',
            'notes'         => 'max:255',
            'items'         => 'required|array',
        ]);
        if (empty($d['items'])) throw new ApiException('Purchase needs at least one item', 422);
        foreach ($d['items'] as $it) {
            Validator::validate($it, [
                'product_id'  => 'int',
                'description' => 'required|max:160',
                'quantity'    => 'required|numeric|min:0.001',
                'unit'        => 'required|max:20',
                'rate'        => 'required|numeric|min:0',
            ]);
        }

        return Database::tx(function () use ($d) {
            $db = $this->db();
            $supplierId = !empty($d['supplier_id']) ? (int)$d['supplier_id'] : null;

            $subtotal = 0.0;
            foreach ($d['items'] as $it) {
                $subtotal += round((float)$it['quantity'] * (float)$it['rate'], 2);
            }
            $discount = (float)($d['discount'] ?? 0);
            $total    = round($subtotal - $discount, 2);
            $paid     = (float)($d['paid_amount'] ?? 0);
            if ($paid > $total) throw new ApiException('Paid amount exceeds purchase total', 422);
            $balance   = round($total - $paid, 2);
            $payStatus = $balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'credit');
            if ($payStatus !== 'paid' && $supplierId === null) {
                throw new ApiException('Unpaid purchases require a supplier', 422);
            }

            try {
                $db->prepare(
                    'INSERT INTO purchases
                     (invoice_no, purchase_date, supplier_id, subtotal, discount, total,
                      paid_amount, balance_amount, payment_status, notes, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([$d['invoice_no'], $d['purchase_date'], $supplierId,
                            round($subtotal, 2), $discount, $total, $paid, $balance,
                            $payStatus, $d['notes'] ?? null, Auth::id()]);
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'Duplicate')) {
                    throw new ApiException('Invoice no already used for this supplier', 422);
                }
                throw $e;
            }
            $purchaseId = (int)$db->lastInsertId();

            $itemSt = $db->prepare(
                'INSERT INTO purchase_items (purchase_id, product_id, description, quantity, unit, rate, amount)
                 VALUES (?,?,?,?,?,?,?)');
            foreach ($d['items'] as $it) {
                $amount = round((float)$it['quantity'] * (float)$it['rate'], 2);
                $itemSt->execute([$purchaseId, $it['product_id'] ?? null, $it['description'],
                                  $it['quantity'], $it['unit'], $it['rate'], $amount]);

                if (!empty($it['product_id'])) {
                    $pr = $db->prepare('SELECT track_stock FROM products WHERE id = ?');
                    $pr->execute([(int)$it['product_id']]);
                    if ((int)$pr->fetchColumn() === 1) {
                        StockService::move((int)$it['product_id'], 'purchase',
                            (float)$it['quantity'], 0, 'purchase', $purchaseId,
                            'Purchase ' . $d['invoice_no'], $d['purchase_date'],
                            (float)$it['rate']);
                    }
                }
            }

            if ($supplierId) {
                LedgerService::postSupplier($supplierId, 'purchase', 0, $total,
                    'purchase', $purchaseId, 'Purchase ' . $d['invoice_no'], $d['purchase_date']);
            }
            if ($paid > 0) {
                $db->prepare(
                    'INSERT INTO payments (payment_date, direction, party_type, party_id, amount, method, purchase_id, notes, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                )->execute([$d['purchase_date'], 'payment', 'supplier', $supplierId ?? 0, $paid,
                            'cash', $purchaseId, 'Paid on ' . $d['invoice_no'], Auth::id()]);
                if ($supplierId) {
                    LedgerService::postSupplier($supplierId, 'payment', $paid, 0,
                        'purchase', $purchaseId, 'Paid on ' . $d['invoice_no'], $d['purchase_date']);
                }
            }

            return ['id' => $purchaseId, 'total' => $total, 'balance' => $balance];
        });
    }

    /** Cancel: only possible while purchased stock is still on hand. */
    public function cancel(array $req, array $p): ?array
    {
        $id = (int)$p['id'];
        return Database::tx(function () use ($id) {
            $db = $this->db();
            $st = $db->prepare('SELECT * FROM purchases WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $pu = $st->fetch();
            if (!$pu) throw new ApiException('Purchase not found', 404);
            if ($pu['status'] === 'cancelled') throw new ApiException('Already cancelled', 422);

            $items = $db->prepare('SELECT * FROM purchase_items WHERE purchase_id = ?');
            $items->execute([$id]);
            foreach ($items->fetchAll() as $it) {
                if (empty($it['product_id'])) continue;
                $pr = $db->prepare('SELECT track_stock FROM products WHERE id = ?');
                $pr->execute([(int)$it['product_id']]);
                if ((int)$pr->fetchColumn() === 1) {
                    // throws if stock already consumed — which is the correct guard
                    StockService::move((int)$it['product_id'], 'return',
                        0, (float)$it['quantity'], 'purchase', $id,
                        'Cancel purchase ' . $pu['invoice_no'], $pu['purchase_date']);
                }
            }

            $pays = $db->prepare("SELECT * FROM payments WHERE purchase_id = ? AND status='active'");
            $pays->execute([$id]);
            foreach ($pays->fetchAll() as $pay) {
                $db->prepare("UPDATE payments SET status='reversed' WHERE id = ?")->execute([$pay['id']]);
            }
            if ($pu['supplier_id']) {
                LedgerService::postSupplier((int)$pu['supplier_id'], 'reversal',
                    (float)$pu['total'], 0, 'purchase', $id, 'Cancel ' . $pu['invoice_no']);
                if ((float)$pu['paid_amount'] > 0) {
                    LedgerService::postSupplier((int)$pu['supplier_id'], 'reversal',
                        0, (float)$pu['paid_amount'], 'purchase', $id,
                        'Payment reversed ' . $pu['invoice_no']);
                }
            }
            $db->prepare(
                "UPDATE purchases SET status='cancelled', balance_amount=0, payment_status='credit' WHERE id=?")
                ->execute([$id]);
            return ['cancelled' => true];
        });
    }
}
