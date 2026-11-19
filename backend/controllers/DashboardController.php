<?php

declare(strict_types=1);

class DashboardController
{
    private function db(): PDO { return Database::pdo(); }

    /** All dashboard stat cards in one round-trip. */
    public function summary(array $req, array $p): ?array
    {
        $db   = $this->db();
        $today = date('Y-m-d');
        $monthStart = date('Y-m-01');

        $layerBirds = (int)$db->query(
            "SELECT COALESCE(birds_count,0) FROM layer_daily_records
             ORDER BY record_date DESC LIMIT 1")->fetchColumn();

        $broilerBirds = (int)$db->query(
            "SELECT COALESCE(SUM(initial_quantity - sold_quantity - mortality_total),0)
             FROM broiler_batches WHERE status = 'active'")->fetchColumn();

        $todayEggs = $db->prepare(
            'SELECT eggs_collected, eggs_sold FROM layer_daily_records WHERE record_date = ?');
        $todayEggs->execute([$today]);
        $te = $todayEggs->fetch() ?: ['eggs_collected' => 0, 'eggs_sold' => 0];

        $st = $db->prepare("SELECT COALESCE(SUM(total),0) FROM sales WHERE sale_date=? AND status='completed'");
        $st->execute([$today]);  $todaySales  = (float)$st->fetchColumn();
        $st = $db->prepare("SELECT COALESCE(SUM(total),0) FROM sales WHERE sale_date>=? AND status='completed'");
        $st->execute([$monthStart]); $monthSales = (float)$st->fetchColumn();
        $st = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date>=? AND status='active'");
        $st->execute([$monthStart]); $monthExp = (float)$st->fetchColumn();
        $st = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date>=? AND status='active' AND category_id IN (SELECT id FROM expense_categories WHERE name IN ('Feed','Medicine','Vaccination'))");
        $st->execute([$monthStart]); $monthCogs = (float)$st->fetchColumn();

        $custOut = (float)$db->query('SELECT COALESCE(SUM(debit)-SUM(credit),0) FROM customer_ledger')->fetchColumn();
        $suppOut = (float)$db->query('SELECT COALESCE(SUM(credit)-SUM(debit),0) FROM supplier_ledger')->fetchColumn();

        // feed + medicine stock grouped by category type
        $stocks = $db->query(
            "SELECT c.type, COALESCE(SUM(i.quantity),0) AS qty
             FROM products pr
             JOIN categories c ON c.id = pr.category_id
             LEFT JOIN inventory i ON i.product_id = pr.id
             WHERE pr.track_stock = 1 AND pr.status='active' AND c.type IN ('feed','medicine','vaccine')
             GROUP BY c.type")->fetchAll(PDO::FETCH_KEY_PAIR);

        // month COGS ≈ feed+medicine consumed + chick costs + batch-tagged expenses
        $cogs = (float)$db->query(
            "SELECT COALESCE(SUM(sm.quantity_out * i.avg_cost),0)
             FROM stock_movements sm
             JOIN inventory i ON i.product_id = sm.product_id
             WHERE sm.movement_type = 'consumption' AND sm.movement_date >= '{$monthStart}'")->fetchColumn();

        $netProfit = $monthSales - $cogs - $monthExp;

        return [
            'layer_birds'        => $layerBirds,
            'broiler_birds'      => $broilerBirds,
            'eggs_collected'     => (int)$te['eggs_collected'],
            'eggs_sold'          => (int)$te['eggs_sold'],
            'egg_stock'          => StockService::eggStock(),
            'today_sales'        => $todaySales,
            'month_sales'        => $monthSales,
            'month_expenses'     => $monthExp,
            'month_cogs'         => round($cogs, 2),
            'month_profit'       => round($netProfit, 2),
            'customer_outstanding' => $custOut,
            'supplier_outstanding' => $suppOut,
            'feed_stock'         => (float)($stocks['feed'] ?? 0),
            'medicine_stock'     => (float)(($stocks['medicine'] ?? 0) + ($stocks['vaccine'] ?? 0)),
        ];
    }

    /** Time series for charts: sales vs expenses vs eggs per day in range. */
    public function charts(array $req, array $p): ?array
    {
        $q    = $req['query'];
        $from = $q['from'] ?? date('Y-m-d', strtotime('-29 days'));
        $to   = $q['to']   ?? date('Y-m-d');
        $db   = $this->db();

        $sales = $db->prepare(
            "SELECT sale_date d, SUM(total) v FROM sales
             WHERE sale_date BETWEEN ? AND ? AND status='completed' GROUP BY sale_date");
        $sales->execute([$from, $to]);

        $exp = $db->prepare(
            "SELECT expense_date d, SUM(amount) v FROM expenses
             WHERE expense_date BETWEEN ? AND ? AND status='active' GROUP BY expense_date");
        $exp->execute([$from, $to]);

        $eggs = $db->prepare(
            'SELECT record_date d, eggs_collected v FROM layer_daily_records
             WHERE record_date BETWEEN ? AND ?');
        $eggs->execute([$from, $to]);

        $expByCat = $db->prepare(
            "SELECT c.name, SUM(e.amount) v FROM expenses e
             JOIN expense_categories c ON c.id = e.category_id
             WHERE e.expense_date BETWEEN ? AND ? AND e.status='active'
             GROUP BY c.id ORDER BY v DESC");
        $expByCat->execute([$from, $to]);

        $merge = function (array $rows) {
            $out = [];
            foreach ($rows as $r) $out[$r['d']] = (float)$r['v'];
            return $out;
        };
        $sMap = $merge($sales->fetchAll());
        $eMap = $merge($exp->fetchAll());
        $gMap = $merge($eggs->fetchAll());

        $series = [];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $series[] = [
                'date'     => $d,
                'sales'    => $sMap[$d] ?? 0,
                'expenses' => $eMap[$d] ?? 0,
                'eggs'     => $gMap[$d] ?? 0,
            ];
        }

        return ['series' => $series, 'expense_by_category' => $expByCat->fetchAll()];
    }

    /** Low-stock products + batches near market age. */
    public function alerts(array $req, array $p): ?array
    {
        $db = $this->db();
        $low = $db->query(
            'SELECT p.id, p.name, p.unit, p.min_stock_level, COALESCE(i.quantity,0) AS quantity
             FROM products p
             LEFT JOIN inventory i ON i.product_id = p.id
             WHERE p.track_stock = 1 AND p.status = "active"
               AND p.min_stock_level > 0 AND COALESCE(i.quantity,0) <= p.min_stock_level
             ORDER BY p.name')->fetchAll();

        $recentMortality = $db->query(
            'SELECT b.batch_code, m.mortality_date, m.quantity, m.cause
             FROM bird_mortality m JOIN broiler_batches b ON b.id = m.batch_id
             ORDER BY m.mortality_date DESC, m.id DESC LIMIT 5')->fetchAll();

        return ['low_stock' => $low, 'recent_mortality' => $recentMortality];
    }

    /** Last 10 transactions (sales + purchases + payments + expenses merged). */
    public function recent(array $req, array $p): ?array
    {
        $db = $this->db();
        return ['items' => $db->query(
            "(SELECT 'sale' AS kind, s.invoice_no AS ref, s.sale_date AS d,
                     s.total AS amount, COALESCE(c.name,'Walk-in') AS party, s.status
              FROM sales s LEFT JOIN customers c ON c.id = s.customer_id)
             UNION ALL
             (SELECT 'purchase', pu.invoice_no, pu.purchase_date, pu.total,
                     COALESCE(su.name,'—'), pu.status
              FROM purchases pu LEFT JOIN suppliers su ON su.id = pu.supplier_id)
             UNION ALL
             (SELECT 'expense', ec.name, e.expense_date, e.amount,
                     e.description, e.status
              FROM expenses e JOIN expense_categories ec ON ec.id = e.category_id)
             ORDER BY d DESC LIMIT 12")->fetchAll()];
    }
}
