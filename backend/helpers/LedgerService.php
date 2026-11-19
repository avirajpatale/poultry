<?php

declare(strict_types=1);

/**
 * Customer & supplier ledger entries with running balances.
 * Customer ledger:  debit = they owe more (sale), credit = they paid.
 * Supplier ledger:  credit = we owe more (purchase), debit = we paid.
 * Call inside Database::tx().
 */
class LedgerService
{
    /** Current outstanding for one customer (+ve = they owe us). */
    public static function customerBalance(int $customerId): float
    {
        $st = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(debit) - SUM(credit),0) FROM customer_ledger WHERE customer_id = ?');
        $st->execute([$customerId]);
        return (float)$st->fetchColumn();
    }

    public static function supplierBalance(int $supplierId): float
    {
        $st = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(credit) - SUM(debit),0) FROM supplier_ledger WHERE supplier_id = ?');
        $st->execute([$supplierId]);
        return (float)$st->fetchColumn();
    }

    public static function postCustomer(
        int $customerId, string $type, float $debit, float $credit,
        ?string $refType = null, ?int $refId = null,
        ?string $notes = null, ?string $date = null
    ): void {
        $pdo = Database::pdo();
        // serialise per-customer balance computation
        $pdo->prepare('SELECT id FROM customers WHERE id = ? FOR UPDATE')->execute([$customerId]);
        $balance = self::customerBalance($customerId) + $debit - $credit;
        $pdo->prepare(
            'INSERT INTO customer_ledger
             (ledger_date, customer_id, entry_type, debit, credit, balance, reference_type, reference_id, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([$date ?: date('Y-m-d'), $customerId, $type, $debit, $credit,
                    $balance, $refType, $refId, $notes, Auth::id()]);
    }

    public static function postSupplier(
        int $supplierId, string $type, float $debit, float $credit,
        ?string $refType = null, ?int $refId = null,
        ?string $notes = null, ?string $date = null
    ): void {
        $pdo = Database::pdo();
        $pdo->prepare('SELECT id FROM suppliers WHERE id = ? FOR UPDATE')->execute([$supplierId]);
        $balance = self::supplierBalance($supplierId) + $credit - $debit;
        $pdo->prepare(
            'INSERT INTO supplier_ledger
             (ledger_date, supplier_id, entry_type, debit, credit, balance, reference_type, reference_id, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([$date ?: date('Y-m-d'), $supplierId, $type, $debit, $credit,
                    $balance, $refType, $refId, $notes, Auth::id()]);
    }

    /** Outstanding report rows for all parties of a type. */
    public static function outstanding(string $type): array
    {
        if ($type === 'customer') {
            $sql = 'SELECT c.id, c.code, c.name, c.mobile, c.customer_type,
                           COALESCE(SUM(l.debit),0)  AS total_billed,
                           COALESCE(SUM(l.credit),0) AS total_received,
                           COALESCE(SUM(l.debit) - SUM(l.credit),0) AS outstanding
                    FROM customers c
                    LEFT JOIN customer_ledger l ON l.customer_id = c.id
                    GROUP BY c.id ORDER BY outstanding DESC, c.name';
        } else {
            $sql = 'SELECT s.id, s.code, s.name, s.mobile, s.supplier_type,
                           COALESCE(SUM(l.credit),0) AS total_billed,
                           COALESCE(SUM(l.debit),0)  AS total_paid,
                           COALESCE(SUM(l.credit) - SUM(l.debit),0) AS outstanding
                    FROM suppliers s
                    LEFT JOIN supplier_ledger l ON l.supplier_id = s.id
                    GROUP BY s.id ORDER BY outstanding DESC, s.name';
        }
        return Database::pdo()->query($sql)->fetchAll();
    }
}
