<?php

declare(strict_types=1);

/**
 * All reports. Every report honours ?from&to (+ type-specific filters)
 * and ?format=csv for Excel export.
 */
class ReportController
{
    private function db(): PDO { return Database::pdo(); }

    private function range(array $q): array
    {
        return [
            $q['from'] ?? date('Y-m-01'),
            $q['to']   ?? date('Y-m-d'),
        ];
    }

    private function rows(string $sql, array $args): array
    {
        $st = $this->db()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    public function run(array $req, array $p): ?array
    {
        $q    = $req['query'];
        $type = $p['type'];
        [$from, $to] = $this->range($q);

        switch ($type) {
            case 'egg-production':  return $this->eggProduction($q, $from, $to);
            case 'egg-sales':       return $this->eggSales($q, $from, $to);
            case 'batch-report':    return $this->batchReport($q);
            case 'mortality':       return $this->mortality($q, $from, $to);
            case 'feed-consumption':return $this->feedConsumption($q, $from, $to);
            case 'purchases':       return $this->purchases($q, $from, $to);
            case 'sales':           return $this->sales($q, $from, $to);
            case 'customer-outstanding': return $this->outstanding('customer', $q);
            case 'supplier-outstanding': return $this->outstanding('supplier', $q);
            case 'expenses':        return $this->expenses($q, $from, $to);
            case 'inventory':       return $this->inventory($q);
            case 'profit-loss':     return $this->profitLoss($q, $from, $to);
            default:
                throw new ApiException('Unknown report type: ' . $type, 404);
        }
    }

    private function out(array $q, string $name, array $headers, array $rows, array $extra = []): array
    {
        if (($q['format'] ?? '') === 'csv') {
            Response::csv($name . '-' . date('Ymd') . '.csv', $headers,
                array_map('array_values', $rows));
        }
        return ['rows' => $rows] + $extra;
    }

    private function eggProduction(array $q, string $from, string $to): array
    {
        $rows = $this->rows(
            'SELECT record_date, opening_eggs, eggs_collected, eggs_purchased, eggs_sold,
                    eggs_damaged, eggs_used, closing_eggs, birds_count, mortality, feed_used_kg
             FROM layer_daily_records WHERE record_date BETWEEN ? AND ?
             ORDER BY record_date', [$from, $to]);
        $tot = $this->rows(
            'SELECT COALESCE(SUM(eggs_collected),0) collected, COALESCE(SUM(eggs_sold),0) sold,
                    COALESCE(SUM(eggs_damaged),0) damaged
             FROM layer_daily_records WHERE record_date BETWEEN ? AND ?', [$from, $to])[0];
        return $this->out($q, 'egg-production',
            ['Date','Opening','Collected','Purchased','Sold','Damaged','Used','Closing','Birds','Mortality','Feed kg'],
            $rows, ['totals' => $tot]);
    }

    private function eggSales(array $q, string $from, string $to): array
    {
        $rows = $this->rows(
            "SELECT s.sale_date, s.invoice_no, COALESCE(c.name,'Walk-in') AS customer,
                    si.description, si.quantity, si.unit, si.rate, si.amount
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             LEFT JOIN customers c ON c.id = s.customer_id
             LEFT JOIN products pr ON pr.id = si.product_id
             LEFT JOIN categories cat ON cat.id = pr.category_id
             WHERE s.sale_date BETWEEN ? AND ? AND s.status='completed'
               AND (cat.type='egg' OR si.unit IN ('pcs','tray'))
             ORDER BY s.sale_date, s.invoice_no", [$from, $to]);
        return $this->out($q, 'egg-sales',
            ['Date','Invoice','Customer','Item','Qty','Unit','Rate','Amount'], $rows);
    }

    private function batchReport(array $q): array
    {
        $w = ''; $args = [];
        if (!empty($q['batch_id'])) { $w = 'WHERE b.id = ?'; $args[] = $q['batch_id']; }
        $rows = $this->rows(
            "SELECT b.batch_code, b.chick_date, b.initial_quantity, b.mortality_total,
                    b.sold_quantity,
                    b.initial_quantity - b.sold_quantity - b.mortality_total AS current_birds,
                    b.chick_cost, b.status,
                    COALESCE(f.feed_kg,0) AS feed_kg,
                    COALESCE(rev.revenue,0) AS revenue,
                    COALESCE(exp.amount,0) AS expenses,
                    ROUND(COALESCE(rev.revenue,0) - b.chick_cost - COALESCE(exp.amount,0),2) AS profit
             FROM broiler_batches b
             LEFT JOIN (SELECT batch_id, SUM(feed_kg) feed_kg FROM broiler_daily_records GROUP BY batch_id) f
                    ON f.batch_id = b.id
             LEFT JOIN (SELECT si.batch_id, SUM(si.amount) revenue FROM sale_items si
                        JOIN sales s ON s.id = si.sale_id
                        WHERE s.status='completed' GROUP BY si.batch_id) rev
                    ON rev.batch_id = b.id
             LEFT JOIN (SELECT batch_id, SUM(amount) amount FROM expenses
                        WHERE status='active' GROUP BY batch_id) exp
                    ON exp.batch_id = b.id
             {$w} ORDER BY b.chick_date DESC", $args);
        return $this->out($q, 'batch-report',
            ['Batch','Chick date','Chicks','Mortality','Sold','Current','Chick cost','Status','Feed kg','Revenue','Expenses','Profit'],
            $rows);
    }

    private function mortality(array $q, string $from, string $to): array
    {
        $w = ''; $args = [$from, $to];
        if (!empty($q['batch_id'])) { $w = 'AND m.batch_id = ?'; $args[] = $q['batch_id']; }
        $rows = $this->rows(
            "SELECT m.mortality_date, b.batch_code, m.quantity, m.cause, m.notes
             FROM bird_mortality m JOIN broiler_batches b ON b.id = m.batch_id
             WHERE m.mortality_date BETWEEN ? AND ? {$w}
             ORDER BY m.mortality_date", $args);
        $total = array_sum(array_column($rows, 'quantity'));
        return $this->out($q, 'mortality',
            ['Date','Batch','Qty','Cause','Notes'], $rows, ['total_deaths' => $total]);
    }

    private function feedConsumption(array $q, string $from, string $to): array
    {
        $layer = $this->rows(
            'SELECT record_date, feed_used_kg FROM layer_daily_records
             WHERE record_date BETWEEN ? AND ? AND feed_used_kg > 0', [$from, $to]);
        $broiler = $this->rows(
            'SELECT r.record_date, b.batch_code, r.feed_kg
             FROM broiler_daily_records r JOIN broiler_batches b ON b.id = r.batch_id
             WHERE r.record_date BETWEEN ? AND ? AND r.feed_kg > 0', [$from, $to]);
        $issued = $this->rows(
            "SELECT sm.movement_date, pr.name AS product, sm.quantity_out, sm.notes
             FROM stock_movements sm JOIN products pr ON pr.id = sm.product_id
             WHERE sm.movement_type='consumption' AND sm.movement_date BETWEEN ? AND ?",
            [$from, $to]);
        if (($q['format'] ?? '') === 'csv') {
            Response::csv('feed-' . date('Ymd') . '.csv',
                ['Date','Source','Detail','Feed kg'],
                array_merge(
                    array_map(fn($r) => [$r['record_date'],'Layer','', $r['feed_used_kg']], $layer),
                    array_map(fn($r) => [$r['record_date'],'Broiler',$r['batch_code'],$r['feed_kg']], $broiler)));
        }
        return ['layer' => $layer, 'broiler' => $broiler, 'issued_from_stock' => $issued];
    }

    private function purchases(array $q, string $from, string $to): array
    {
        $w = ''; $args = [$from, $to];
        if (!empty($q['supplier_id'])) { $w = 'AND pu.supplier_id = ?'; $args[] = $q['supplier_id']; }
        $rows = $this->rows(
            "SELECT pu.purchase_date, pu.invoice_no, COALESCE(su.name,'—') AS supplier,
                    pu.total, pu.paid_amount, pu.balance_amount, pu.payment_status, pu.status
             FROM purchases pu LEFT JOIN suppliers su ON su.id = pu.supplier_id
             WHERE pu.purchase_date BETWEEN ? AND ? {$w}
             ORDER BY pu.purchase_date", $args);
        return $this->out($q, 'purchases',
            ['Date','Invoice','Supplier','Total','Paid','Balance','Status','Record'], $rows);
    }

    private function sales(array $q, string $from, string $to): array
    {
        $w = ''; $args = [$from, $to];
        if (!empty($q['customer_id'])) { $w = 'AND s.customer_id = ?'; $args[] = $q['customer_id']; }
        if (!empty($q['sale_type']))   { $w .= ' AND s.sale_type = ?'; $args[] = $q['sale_type']; }
        $rows = $this->rows(
            "SELECT s.sale_date, s.invoice_no, COALESCE(c.name,'Walk-in') AS customer,
                    s.sale_type, s.subtotal, s.discount, s.total, s.paid_amount,
                    s.balance_amount, s.payment_status, s.status
             FROM sales s LEFT JOIN customers c ON c.id = s.customer_id
             WHERE s.sale_date BETWEEN ? AND ? {$w}
             ORDER BY s.sale_date, s.invoice_no", $args);
        $tot = $this->rows(
            "SELECT COALESCE(SUM(total),0) total, COALESCE(SUM(paid_amount),0) received,
                    COALESCE(SUM(balance_amount),0) credit
             FROM sales s WHERE s.sale_date BETWEEN ? AND ? AND s.status='completed'",
            [$from, $to])[0];
        return $this->out($q, 'sales',
            ['Date','Invoice','Customer','Type','Subtotal','Discount','Total','Paid','Balance','Pay status','Record'],
            $rows, ['totals' => $tot]);
    }

    private function outstanding(string $type, array $q): array
    {
        $rows = LedgerService::outstanding($type);
        $rows = array_filter($rows, fn($r) => (float)$r['outstanding'] != 0 || !empty($q['all']));
        $rows = array_values($rows);
        $headers = $type === 'customer'
            ? ['Code','Customer','Mobile','Type','Total billed','Total received','Outstanding']
            : ['Code','Supplier','Mobile','Type','Total billed','Total paid','Outstanding'];
        $cols = $type === 'customer'
            ? fn($r) => [$r['code'],$r['name'],$r['mobile'],$r['customer_type'],$r['total_billed'],$r['total_received'],$r['outstanding']]
            : fn($r) => [$r['code'],$r['name'],$r['mobile'],$r['supplier_type'],$r['total_billed'],$r['total_paid'],$r['outstanding']];
        if (($q['format'] ?? '') === 'csv') {
            Response::csv($type . '-outstanding-' . date('Ymd') . '.csv', $headers,
                array_map($cols, $rows));
        }
        return ['rows' => $rows,
                'total_outstanding' => array_sum(array_column($rows, 'outstanding'))];
    }

    private function expenses(array $q, string $from, string $to): array
    {
        $w = ''; $args = [$from, $to];
        if (!empty($q['category_id'])) { $w = 'AND e.category_id = ?'; $args[] = $q['category_id']; }
        $rows = $this->rows(
            "SELECT e.expense_date, c.name AS category, e.description, e.amount,
                    e.payment_mode, b.batch_code, e.vendor
             FROM expenses e
             JOIN expense_categories c ON c.id = e.category_id
             LEFT JOIN broiler_batches b ON b.id = e.batch_id
             WHERE e.expense_date BETWEEN ? AND ? AND e.status='active' {$w}
             ORDER BY e.expense_date", $args);
        $byCat = $this->rows(
            "SELECT c.name, SUM(e.amount) amount FROM expenses e
             JOIN expense_categories c ON c.id = e.category_id
             WHERE e.expense_date BETWEEN ? AND ? AND e.status='active'
             GROUP BY c.id ORDER BY amount DESC", [$from, $to]);
        return $this->out($q, 'expenses',
            ['Date','Category','Description','Amount','Mode','Batch','Vendor'],
            $rows, ['by_category' => $byCat,
                    'total' => array_sum(array_column($rows, 'amount'))]);
    }

    private function inventory(array $q): array
    {
        $rows = $this->rows(
            "SELECT pr.name, c.name AS category, pr.unit,
                    COALESCE(i.quantity,0) AS quantity, COALESCE(i.avg_cost,0) AS avg_cost,
                    ROUND(COALESCE(i.quantity,0)*COALESCE(i.avg_cost,0),2) AS value,
                    pr.min_stock_level,
                    CASE WHEN pr.min_stock_level>0 AND COALESCE(i.quantity,0)<=pr.min_stock_level
                         THEN 'LOW' ELSE 'OK' END AS flag
             FROM products pr
             JOIN categories c ON c.id = pr.category_id
             LEFT JOIN inventory i ON i.product_id = pr.id
             WHERE pr.track_stock = 1 AND pr.status='active'
             ORDER BY c.type, pr.name", []);
        return $this->out($q, 'inventory',
            ['Product','Category','Unit','Qty','Avg cost','Value','Min level','Flag'],
            $rows, ['total_value' => array_sum(array_column($rows, 'value')),
                    'egg_stock' => StockService::eggStock()]);
    }

    /**
     * P&L grouped by day/month/year.
     * Revenue = completed sales. COGS = feed/medicine consumption valued at avg cost
     * + chick costs + batch expenses (in the opex bucket below).
     * Opex = active expenses. Cash columns are kept separate from revenue.
     */
    private function profitLoss(array $q, string $from, string $to): array
    {
        $sales = $this->rows(
            "SELECT sale_date AS d, SUM(total) rev
             FROM sales WHERE sale_date BETWEEN ? AND ? AND status='completed' GROUP BY sale_date",
            [$from, $to]);
        $cogs = $this->rows(
            "SELECT sm.movement_date AS d,
                    SUM(sm.quantity_out * COALESCE(i.avg_cost,0)) cogs
             FROM stock_movements sm JOIN inventory i ON i.product_id = sm.product_id
             WHERE sm.movement_type='consumption' AND sm.movement_date BETWEEN ? AND ?
             GROUP BY sm.movement_date", [$from, $to]);
        $chicks = $this->rows(
            'SELECT chick_date AS d, SUM(chick_cost) cost FROM broiler_batches
             WHERE chick_date BETWEEN ? AND ? GROUP BY chick_date', [$from, $to]);
        $exp = $this->rows(
            "SELECT expense_date AS d, SUM(amount) amount FROM expenses
             WHERE expense_date BETWEEN ? AND ? AND status='active' GROUP BY expense_date",
            [$from, $to]);
        $payIn = $this->rows(
            "SELECT payment_date AS d, SUM(amount) amt FROM payments
             WHERE direction='receipt' AND status='active' AND payment_date BETWEEN ? AND ?
             GROUP BY payment_date", [$from, $to]);
        $payOut = $this->rows(
            "SELECT payment_date AS d, SUM(amount) amt FROM payments
             WHERE direction='payment' AND status='active' AND payment_date BETWEEN ? AND ?
             GROUP BY payment_date", [$from, $to]);

        $buck = function (array $rows, string $col) use ($q) {
            $out = [];
            $fmt = match ($q['group_by'] ?? 'day') {
                'month' => 'Y-m', 'year' => 'Y', default => 'Y-m-d',
            };
            foreach ($rows as $r) $out[date($fmt, strtotime($r['d']))] =
                ($out[date($fmt, strtotime($r['d']))] ?? 0) + (float)$r[$col];
            return $out;
        };
        $revM = $buck($sales, 'rev');
        $cogM = $buck($cogs, 'cogs');   $chkM  = $buck($chicks, 'cost');
        $expM = $buck($exp, 'amount');
        $inM  = $buck($payIn, 'amt');   $outM  = $buck($payOut, 'amt');

        $periods = array_unique(array_merge(
            array_keys($revM), array_keys($cogM), array_keys($chkM),
            array_keys($expM), array_keys($inM), array_keys($outM)));
        sort($periods);

        $rows = [];
        $tot  = ['revenue'=>0,'cogs'=>0,'opex'=>0,'net'=>0,'cash_in'=>0,'cash_out'=>0];
        foreach ($periods as $pd) {
            $rev = $revM[$pd] ?? 0;
            $cg  = ($cogM[$pd] ?? 0) + ($chkM[$pd] ?? 0);
            $op  = $expM[$pd] ?? 0;
            $row = [
                'period'   => $pd,
                'revenue'  => round($rev, 2),
                'cogs'     => round($cg, 2),
                'opex'     => round($op, 2),
                'net'      => round($rev - $cg - $op, 2),
                'cash_in'  => round($inM[$pd] ?? 0, 2),   // payments table only — no double count
                'cash_out' => round($outM[$pd] ?? 0, 2),
            ];
            $rows[] = $row;
            foreach ($tot as $k => $v) $tot[$k] += $row[$k];
        }
        if (($q['format'] ?? '') === 'csv') {
            Response::csv('profit-loss-' . date('Ymd') . '.csv',
                ['Period','Revenue','COGS','OpEx','Net profit','Cash in','Cash out'], $rows);
        }
        return ['rows' => $rows, 'totals' => $tot];
    }
}
