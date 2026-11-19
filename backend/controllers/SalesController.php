<?php

declare(strict_types=1);

/**
 * Sales / invoicing.
 * One transaction: header + items + stock deductions + ledger + payment.
 * Egg items deduct from egg stock (trays converted to pieces);
 * broiler items deduct live birds from the referenced batch;
 * stockable products deduct from inventory.
 */
class SalesController
{
    private function db(): PDO { return Database::pdo(); }

    private function traySize(): int
    {
        $v = $this->db()->query(
            "SELECT setting_value FROM settings WHERE setting_key='egg_tray_size'")->fetchColumn();
        return max(1, (int)($v ?: 30));
    }

    private function nextInvoiceNo(): string
    {
        $prefix = $this->db()->query(
            "SELECT setting_value FROM settings WHERE setting_key='invoice_prefix'")->fetchColumn() ?: 'INV-';
        $n = (int)$this->db()->query(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(invoice_no, " . (strlen($prefix) + 1) . ") AS UNSIGNED)),0) + 1
             FROM sales WHERE invoice_no LIKE '{$prefix}%'")->fetchColumn();
        return $prefix . str_pad((string)$n, 5, '0', STR_PAD_LEFT);
    }

    public function nextInvoice(array $req, array $p): ?array
    {
        return ['invoice_no' => $this->nextInvoiceNo()];
    }

    public function index(array $req, array $p): ?array
    {
        $q = $req['query'];
        $w = []; $args = [];
        if (!empty($q['from']))        { $w[] = 's.sale_date >= ?';    $args[] = $q['from']; }
        if (!empty($q['to']))          { $w[] = 's.sale_date <= ?';    $args[] = $q['to']; }
        if (!empty($q['customer_id'])) { $w[] = 's.customer_id = ?';   $args[] = $q['customer_id']; }
        if (!empty($q['status']))      { $w[] = 's.status = ?';        $args[] = $q['status']; }
        if (!empty($q['search']))      { $w[] = 's.invoice_no LIKE ?'; $args[] = '%' . $q['search'] . '%'; }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        [$page, $perPage, $offset] = Validator::pageParams($q);

        $count = $this->db()->prepare("SELECT COUNT(*) FROM sales s {$where}");
        $count->execute($args);
        $st = $this->db()->prepare(
            "SELECT s.*, COALESCE(c.name,'Walk-in') AS customer_name
             FROM sales s LEFT JOIN customers c ON c.id = s.customer_id
             {$where} ORDER BY s.sale_date DESC, s.id DESC
             LIMIT {$perPage} OFFSET {$offset}");
        $st->execute($args);
        return ['items' => $st->fetchAll(), 'total' => (int)$count->fetchColumn(),
                'page' => $page, 'per_page' => $perPage];
    }

    public function show(array $req, array $p): ?array
    {
        $id = (int)$p['id'];
        $st = $this->db()->prepare(
            'SELECT s.*, c.name AS customer_name, c.mobile AS customer_mobile, c.address AS customer_address,
                    u.name AS created_by_name
             FROM sales s
             LEFT JOIN customers c ON c.id = s.customer_id
             LEFT JOIN users u ON u.id = s.created_by
             WHERE s.id = ?');
        $st->execute([$id]);
        $sale = $st->fetch();
        if (!$sale) throw new ApiException('Invoice not found', 404);

        $items = $this->db()->prepare(
            'SELECT si.*, b.batch_code FROM sale_items si
             LEFT JOIN broiler_batches b ON b.id = si.batch_id WHERE si.sale_id = ?');
        $items->execute([$id]);
        $sale['items'] = $items->fetchAll();

        $pays = $this->db()->prepare(
            "SELECT * FROM payments WHERE sale_id = ? AND status='active' ORDER BY payment_date");
        $pays->execute([$id]);
        $sale['payments'] = $pays->fetchAll();
        return $sale;
    }

    public function store(array $req, array $p): ?array
    {
        $b = $req['body'];
        $d = Validator::validate($b, [
            'sale_date'      => 'required|date',
            'customer_id'    => 'int',
            'discount'       => 'numeric|min:0',
            'tax'            => 'numeric|min:0',
            'paid_amount'    => 'numeric|min:0',
            'payment_method' => 'in:cash,upi,bank,credit,mixed',
            'notes'          => 'max:255',
            'items'          => 'required|array',
        ]);
        if (empty($d['items'])) throw new ApiException('Invoice needs at least one item', 422);

        foreach ($d['items'] as $i => $it) {
            Validator::validate($it, [
                'product_id'  => 'int',
                'batch_id'    => 'int',
                'description' => 'required|max:160',
                'quantity'    => 'required|numeric|min:0.001',
                'unit'        => 'required|max:20',
                'rate'        => 'required|numeric|min:0',
                'birds_count' => 'int|min:0',
            ]);
        }

        return Database::tx(function () use ($d) {
            $db = $this->db();
            $subtotal = 0.0;
            $types = [];

            // ---- pass 1: validate items + prefetch products ----
            $preparedItems = [];
            foreach ($d['items'] as $it) {
                $qty    = (float)$it['quantity'];
                $amount = round($qty * (float)$it['rate'], 2);
                $subtotal += $amount;
                $product  = null;

                if (!empty($it['product_id'])) {
                    $st = $db->prepare(
                        'SELECT p.*, c.type AS cat_type FROM products p
                         JOIN categories c ON c.id = p.category_id WHERE p.id = ?');
                    $st->execute([(int)$it['product_id']]);
                    $product = $st->fetch();
                    if (!$product) throw new ApiException("Product {$it['product_id']} not found", 422);
                }
                if (!empty($it['batch_id'])) {
                    $types['broiler'] = true;
                } elseif ($product && $product['cat_type'] === 'egg') {
                    $types['egg'] = true;
                } else {
                    $types['other'] = true;
                }
                $it['amount']  = $amount;
                $it['product'] = $product;
                $preparedItems[] = $it;
            }

            $discount = (float)($d['discount'] ?? 0);
            $tax      = (float)($d['tax'] ?? 0);
            $total    = round($subtotal - $discount + $tax, 2);
            if ($total < 0) throw new ApiException('Total cannot be negative', 422);

            $customerId = !empty($d['customer_id']) ? (int)$d['customer_id'] : null;
            $paid = (float)($d['paid_amount'] ?? 0);
            if ($paid > $total) throw new ApiException('Paid amount exceeds invoice total', 422);
            $balance = round($total - $paid, 2);
            $payStatus = $balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'credit');
            if ($payStatus !== 'paid' && $customerId === null) {
                throw new ApiException('Unpaid invoices require a customer', 422);
            }
            $method = $payStatus === 'credit' ? 'credit' : ($d['payment_method'] ?? 'cash');

            // ---- pass 2: header ----
            $invoiceNo = $this->nextInvoiceNo();
            $saleType  = count($types) > 1 ? 'mixed' : array_key_first($types);
            $db->prepare(
                'INSERT INTO sales
                 (invoice_no, sale_date, customer_id, sale_type, subtotal, discount, tax,
                  total, paid_amount, balance_amount, payment_method, payment_status, notes, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$invoiceNo, $d['sale_date'], $customerId, $saleType,
                        round($subtotal, 2), $discount, $tax, $total, $paid, $balance,
                        $method, $payStatus, $d['notes'] ?? null, Auth::id()]);
            $saleId = (int)$db->lastInsertId();

            // ---- pass 3: items + stock deductions keyed to the real item id ----
            $itemSt = $db->prepare(
                'INSERT INTO sale_items (sale_id, product_id, batch_id, description, quantity, unit, rate, amount, birds_count)
                 VALUES (?,?,?,?,?,?,?,?,?)');
            $eggsSoldPcs = 0;
            foreach ($preparedItems as $it) {
                $product  = $it['product'];
                $qty      = (float)$it['quantity'];
                $batchId  = !empty($it['batch_id']) ? (int)$it['batch_id'] : null;
                $birds    = null;

                if ($batchId !== null) {                               // broiler sale
                    $current = StockService::batchBirds($batchId, true);
                    $birds   = (int)($it['birds_count'] ?? ($it['unit'] === 'pcs' ? $qty : 0));
                    if ($birds <= 0) throw new ApiException('Broiler sale needs birds_count', 422);
                    if ($birds > $current) {
                        throw new ApiException("Only {$current} birds left in batch — cannot sell {$birds}", 422);
                    }
                    $db->prepare('UPDATE broiler_batches SET sold_quantity = sold_quantity + ? WHERE id = ?')
                        ->execute([$birds, $batchId]);
                } elseif ($product && $product['cat_type'] === 'egg') {  // egg sale
                    $pieces = $it['unit'] === 'tray'
                        ? (int)round($qty * $this->traySize()) : (int)round($qty);
                    StockService::moveEggs(-$pieces, 'sale', 'sale', $saleId,
                        $it['description'], $d['sale_date']);
                    $eggsSoldPcs += $pieces;
                } elseif ($product && (int)$product['track_stock'] === 1) {
                    StockService::move((int)$product['id'], 'sale', 0, $qty,
                        'sale', $saleId, $it['description'], $d['sale_date']);
                }

                $itemSt->execute([$saleId, $it['product_id'] ?? null, $batchId,
                                  $it['description'], $qty, $it['unit'],
                                  $it['rate'], $it['amount'], $birds]);
            }

            // reflect egg sales in the day's layer record, if one exists
            if ($eggsSoldPcs > 0) {
                $db->prepare(
                    'UPDATE layer_daily_records
                     SET eggs_sold = eggs_sold + ?, closing_eggs = closing_eggs - ?
                     WHERE record_date = ?')->execute([$eggsSoldPcs, $eggsSoldPcs, $d['sale_date']]);
            }

            // customer ledger + optional upfront payment
            if ($customerId) {
                LedgerService::postCustomer($customerId, 'sale', $total, 0,
                    'sale', $saleId, "Invoice {$invoiceNo}", $d['sale_date']);
            }
            if ($paid > 0) {
                $db->prepare(
                    'INSERT INTO payments (payment_date, direction, party_type, party_id, amount, method, sale_id, notes, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                )->execute([$d['sale_date'], 'receipt', 'customer', $customerId ?? 0, $paid,
                            $method === 'credit' ? 'cash' : $method, $saleId,
                            "Payment against {$invoiceNo}", Auth::id()]);
                if ($customerId) {
                    LedgerService::postCustomer($customerId, 'payment', 0, $paid,
                        'sale', $saleId, "Paid on invoice {$invoiceNo}", $d['sale_date']);
                }
            }

            return ['id' => $saleId, 'invoice_no' => $invoiceNo, 'total' => $total,
                    'balance' => $balance, 'egg_stock' => StockService::eggStock()];
        });
    }

    /** Cancel = reverse every effect; nothing is deleted. */
    public function cancel(array $req, array $p): ?array
    {
        $id = (int)$p['id'];
        return Database::tx(function () use ($id) {
            $db = $this->db();
            $st = $db->prepare('SELECT * FROM sales WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $sale = $st->fetch();
            if (!$sale) throw new ApiException('Invoice not found', 404);
            if ($sale['status'] === 'cancelled') throw new ApiException('Already cancelled', 422);

            $items = $db->prepare('SELECT * FROM sale_items WHERE sale_id = ?');
            $items->execute([$id]);
            $eggsBack = 0;
            foreach ($items->fetchAll() as $it) {
                if (!empty($it['batch_id']) && (int)$it['birds_count'] > 0) {
                    $db->prepare('UPDATE broiler_batches SET sold_quantity = sold_quantity - ? WHERE id = ?')
                        ->execute([(int)$it['birds_count'], (int)$it['batch_id']]);
                } elseif (!empty($it['product_id'])) {
                    $prod = $db->prepare('SELECT p.*, c.type AS cat_type FROM products p JOIN categories c ON c.id=p.category_id WHERE p.id=?');
                    $prod->execute([(int)$it['product_id']]);
                    $pr = $prod->fetch();
                    if ($pr && $pr['cat_type'] === 'egg') {
                        $pieces = $it['unit'] === 'tray'
                            ? (int)round($it['quantity'] * $this->traySize())
                            : (int)round($it['quantity']);
                        StockService::moveEggs($pieces, 'adjustment', 'sale', $id,
                            'Cancel ' . $sale['invoice_no'], $sale['sale_date']);
                        $eggsBack += $pieces;
                    } elseif ($pr && (int)$pr['track_stock'] === 1) {
                        StockService::move((int)$pr['id'], 'return', (float)$it['quantity'], 0,
                            'sale', $id, 'Cancel ' . $sale['invoice_no'], $sale['sale_date']);
                    }
                }
            }
            if ($eggsBack > 0) {
                $db->prepare(
                    'UPDATE layer_daily_records
                     SET eggs_sold = GREATEST(eggs_sold - ?, 0), closing_eggs = closing_eggs + ?
                     WHERE record_date = ?')->execute([$eggsBack, $eggsBack, $sale['sale_date']]);
            }

            // reverse payments + ledger
            $pays = $db->prepare("SELECT * FROM payments WHERE sale_id = ? AND status='active'");
            $pays->execute([$id]);
            foreach ($pays->fetchAll() as $pay) {
                $db->prepare("UPDATE payments SET status='reversed' WHERE id = ?")->execute([$pay['id']]);
            }
            if ($sale['customer_id']) {
                LedgerService::postCustomer((int)$sale['customer_id'], 'reversal', 0,
                    (float)$sale['total'], 'sale', $id, 'Cancel ' . $sale['invoice_no']);
                if ((float)$sale['paid_amount'] > 0) {
                    LedgerService::postCustomer((int)$sale['customer_id'], 'reversal',
                        (float)$sale['paid_amount'], 0, 'sale', $id,
                        'Refund/reversal ' . $sale['invoice_no']);
                }
            }
            $db->prepare(
                "UPDATE sales SET status='cancelled', payment_status='credit', balance_amount=0 WHERE id=?")
                ->execute([$id]);

            return ['cancelled' => true, 'egg_stock' => StockService::eggStock()];
        });
    }
}
