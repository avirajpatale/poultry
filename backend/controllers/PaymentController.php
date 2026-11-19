<?php

declare(strict_types=1);

/**
 * Payments: customer receipts (money in) & supplier payments (money out).
 * Updates ledger + linked invoice balances atomically.
 */
class PaymentController
{
    private function db(): PDO { return Database::pdo(); }

    public function index(array $req, array $p): ?array
    {
        $q = $req['query'];
        $w = []; $args = [];
        if (!empty($q['from']))       { $w[] = 'pay.payment_date >= ?'; $args[] = $q['from']; }
        if (!empty($q['to']))         { $w[] = 'pay.payment_date <= ?'; $args[] = $q['to']; }
        if (!empty($q['direction']))  { $w[] = 'pay.direction = ?';     $args[] = $q['direction']; }
        if (!empty($q['party_type'])) { $w[] = 'pay.party_type = ?';    $args[] = $q['party_type']; }
        if (!empty($q['party_id']))   { $w[] = 'pay.party_id = ?';      $args[] = $q['party_id']; }
        if (!empty($q['method']))     { $w[] = 'pay.method = ?';        $args[] = $q['method']; }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        [$page, $perPage, $offset] = Validator::pageParams($q);

        $count = $this->db()->prepare("SELECT COUNT(*) FROM payments pay {$where}");
        $count->execute($args);
        $st = $this->db()->prepare(
            "SELECT pay.*,
                    CASE WHEN pay.party_type='customer' THEN c.name ELSE su.name END AS party_name,
                    s.invoice_no AS sale_invoice, pu.invoice_no AS purchase_invoice
             FROM payments pay
             LEFT JOIN customers c ON pay.party_type='customer' AND c.id = pay.party_id
             LEFT JOIN suppliers su ON pay.party_type='supplier' AND su.id = pay.party_id
             LEFT JOIN sales s ON s.id = pay.sale_id
             LEFT JOIN purchases pu ON pu.id = pay.purchase_id
             {$where} ORDER BY pay.payment_date DESC, pay.id DESC
             LIMIT {$perPage} OFFSET {$offset}");
        $st->execute($args);
        return ['items' => $st->fetchAll(), 'total' => (int)$count->fetchColumn(),
                'page' => $page, 'per_page' => $perPage];
    }

    public function store(array $req, array $p): ?array
    {
        $d = Validator::validate($req['body'], [
            'payment_date' => 'required|date',
            'direction'    => 'required|in:receipt,payment',
            'party_type'   => 'required|in:customer,supplier',
            'party_id'     => 'required|int',
            'amount'       => 'required|numeric|min:0.01',
            'method'       => 'required|in:cash,upi,bank,cheque,other',
            'reference_no' => 'max:60',
            'sale_id'      => 'int',
            'purchase_id'  => 'int',
            'notes'        => 'max:255',
        ]);
        $directionOk = ($d['direction'] === 'receipt' && $d['party_type'] === 'customer')
                    || ($d['direction'] === 'payment' && $d['party_type'] === 'supplier');
        if (!$directionOk) {
            throw new ApiException('receipt→customer or payment→supplier only', 422);
        }

        return Database::tx(function () use ($d) {
            $db     = $this->db();
            $amount = (float)$d['amount'];
            $partyId = (int)$d['party_id'];

            // cap the payment at the invoice balance when one is linked
            if (!empty($d['sale_id'])) {
                $s = $db->prepare("SELECT * FROM sales WHERE id=? AND status='completed' FOR UPDATE");
                $s->execute([(int)$d['sale_id']]);
                $sale = $s->fetch();
                if (!$sale) throw new ApiException('Sale not found', 404);
                if ($sale['customer_id'] != $partyId) throw new ApiException('Invoice belongs to another customer', 422);
                if ($amount > (float)$sale['balance_amount']) {
                    throw new ApiException('Amount exceeds invoice balance ' . $sale['balance_amount'], 422);
                }
            }
            if (!empty($d['purchase_id'])) {
                $pu = $db->prepare("SELECT * FROM purchases WHERE id=? AND status='received' FOR UPDATE");
                $pu->execute([(int)$d['purchase_id']]);
                $pRow = $pu->fetch();
                if (!$pRow) throw new ApiException('Purchase not found', 404);
                if ($amount > (float)$pRow['balance_amount']) {
                    throw new ApiException('Amount exceeds purchase balance ' . $pRow['balance_amount'], 422);
                }
            }

            $db->prepare(
                'INSERT INTO payments (payment_date, direction, party_type, party_id, amount, method,
                                       reference_no, sale_id, purchase_id, notes, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$d['payment_date'], $d['direction'], $d['party_type'], $partyId,
                        $amount, $d['method'], $d['reference_no'] ?? null,
                        $d['sale_id'] ?? null, $d['purchase_id'] ?? null,
                        $d['notes'] ?? null, Auth::id()]);
            $payId = (int)$db->lastInsertId();

            if ($d['party_type'] === 'customer') {
                LedgerService::postCustomer($partyId, 'payment', 0, $amount,
                    'payment', $payId, $d['notes'] ?? 'Receipt', $d['payment_date']);
            } else {
                LedgerService::postSupplier($partyId, 'payment', $amount, 0,
                    'payment', $payId, $d['notes'] ?? 'Payment', $d['payment_date']);
            }

            if (!empty($d['sale_id'])) {
                $db->prepare(
                    "UPDATE sales SET paid_amount = paid_amount + ?,
                            balance_amount = balance_amount - ?,
                            payment_status = CASE WHEN balance_amount - ? <= 0 THEN 'paid' ELSE 'partial' END
                     WHERE id = ?")->execute([$amount, $amount, $amount, (int)$d['sale_id']]);
            }
            if (!empty($d['purchase_id'])) {
                $db->prepare(
                    "UPDATE purchases SET paid_amount = paid_amount + ?,
                            balance_amount = balance_amount - ?,
                            payment_status = CASE WHEN balance_amount - ? <= 0 THEN 'paid' ELSE 'partial' END
                     WHERE id = ?")->execute([$amount, $amount, $amount, (int)$d['purchase_id']]);
            }

            return ['id' => $payId];
        });
    }

    /** Reverse a payment (never delete). */
    public function reverse(array $req, array $p): ?array
    {
        $id = (int)$p['id'];
        return Database::tx(function () use ($id) {
            $db = $this->db();
            $st = $db->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $pay = $st->fetch();
            if (!$pay) throw new ApiException('Payment not found', 404);
            if ($pay['status'] === 'reversed') throw new ApiException('Already reversed', 422);

            $amount = (float)$pay['amount'];
            $db->prepare("UPDATE payments SET status='reversed' WHERE id = ?")->execute([$id]);

            if ($pay['party_type'] === 'customer') {
                LedgerService::postCustomer((int)$pay['party_id'], 'reversal', $amount, 0,
                    'payment', $id, 'Payment reversed', $pay['payment_date']);
            } else {
                LedgerService::postSupplier((int)$pay['party_id'], 'reversal', 0, $amount,
                    'payment', $id, 'Payment reversed', $pay['payment_date']);
            }
            if ($pay['sale_id']) {
                $db->prepare(
                    "UPDATE sales SET paid_amount = paid_amount - ?,
                            balance_amount = balance_amount + ?,
                            payment_status = CASE WHEN paid_amount - ? <= 0 THEN 'credit' ELSE 'partial' END
                     WHERE id = ?")->execute([$amount, $amount, $amount, (int)$pay['sale_id']]);
            }
            if ($pay['purchase_id']) {
                $db->prepare(
                    "UPDATE purchases SET paid_amount = paid_amount - ?,
                            balance_amount = balance_amount + ?,
                            payment_status = CASE WHEN paid_amount - ? <= 0 THEN 'credit' ELSE 'partial' END
                     WHERE id = ?")->execute([$amount, $amount, $amount, (int)$pay['purchase_id']]);
            }
            return ['reversed' => true];
        });
    }
}
