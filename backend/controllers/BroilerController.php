<?php

declare(strict_types=1);

/**
 * Broiler farm: batch lifecycle.
 * current_birds = initial_quantity − sold_quantity − mortality_total
 * (sold_quantity / mortality_total kept in sync transactionally by sales & mortality entries).
 */
class BroilerController
{
    private function db(): PDO { return Database::pdo(); }

    private function enrich(array $b): array
    {
        $b['current_birds'] = (int)$b['initial_quantity'] - (int)$b['sold_quantity'] - (int)$b['mortality_total'];
        $b['age_days'] = (int)((strtotime('today') - strtotime($b['chick_date'])) / 86400);
        return $b;
    }

    public function batches(array $req, array $p): ?array
    {
        $q = $req['query'];
        $w = []; $args = [];
        if (!empty($q['status'])) { $w[] = 'b.status = ?'; $args[] = $q['status']; }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        $st = $this->db()->prepare(
            "SELECT b.*, s.name AS supplier_name FROM broiler_batches b
             LEFT JOIN suppliers s ON s.id = b.supplier_id {$where}
             ORDER BY b.status='active' DESC, b.chick_date DESC LIMIT 200");
        $st->execute($args);
        return ['items' => array_map(fn($b) => $this->enrich($b), $st->fetchAll())];
    }

    public function batch(array $req, array $p): ?array
    {
        $id = (int)$p['id'];
        $st = $this->db()->prepare(
            'SELECT b.*, s.name AS supplier_name FROM broiler_batches b
             LEFT JOIN suppliers s ON s.id = b.supplier_id WHERE b.id = ?');
        $st->execute([$id]);
        $b = $st->fetch();
        if (!$b) throw new ApiException('Batch not found', 404);
        $b = $this->enrich($b);

        // batch P&L: chick cost + feed consumed + batch expenses vs sales revenue
        $feed = $this->db()->prepare('SELECT COALESCE(SUM(feed_kg),0) FROM broiler_daily_records WHERE batch_id=?');
        $feed->execute([$id]);
        $expenses = $this->db()->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE batch_id=? AND status='active'");
        $expenses->execute([$id]);
        $revenue = $this->db()->prepare(
            "SELECT COALESCE(SUM(si.amount),0), COALESCE(SUM(si.quantity),0)
             FROM sale_items si JOIN sales s ON s.id = si.sale_id
             WHERE si.batch_id = ? AND s.status='completed'");
        $revenue->execute([$id]);
        [$rev, $kgSold] = array_values($revenue->fetch(PDO::FETCH_NUM) ?: [0, 0]);
        $mort = $this->db()->prepare('SELECT * FROM bird_mortality WHERE batch_id=? ORDER BY mortality_date DESC');
        $mort->execute([$id]);
        $latestWeight = $this->db()->prepare(
            'SELECT avg_weight_g FROM broiler_daily_records WHERE batch_id=? AND avg_weight_g IS NOT NULL ORDER BY record_date DESC LIMIT 1');
        $latestWeight->execute([$id]);
        $w = $latestWeight->fetchColumn();

        $totalCost = (float)$b['chick_cost'] + (float)$expenses->fetchColumn();
        $b['pnl'] = [
            'revenue'      => (float)$rev,
            'kg_sold'      => (float)$kgSold,
            'feed_kg'      => (float)$feed->fetchColumn(),
            'chick_cost'   => (float)$b['chick_cost'],
            'expenses'     => (float)$expenses->fetchColumn(),
            'total_cost'   => $totalCost,
            'profit'       => round((float)$rev - $totalCost, 2),
            'avg_weight_g' => $w === false ? null : (float)$w,
            'live_weight_kg' => $w === false ? null : round($b['current_birds'] * (float)$w / 1000, 2),
        ];
        $b['mortality_log'] = $mort->fetchAll();
        return $b;
    }

    public function createBatch(array $req, array $p): ?array
    {
        $d = Validator::validate($req['body'], [
            'batch_code'       => 'required|max:20',
            'name'             => 'max:100',
            'supplier_id'      => 'int',
            'chick_date'       => 'required|date',
            'initial_quantity' => 'required|int|min:1',
            'chick_rate'       => 'numeric|min:0',
            'notes'            => 'max:255',
        ]);
        $cost = round($d['initial_quantity'] * (float)($d['chick_rate'] ?? 0), 2);
        $st = $this->db()->prepare(
            'INSERT INTO broiler_batches
             (batch_code, name, supplier_id, chick_date, initial_quantity, chick_rate, chick_cost, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?)');
        try {
            $st->execute([$d['batch_code'], $d['name'] ?? null, $d['supplier_id'] ?? null,
                          $d['chick_date'], $d['initial_quantity'], $d['chick_rate'] ?? 0,
                          $cost, $d['notes'] ?? null, Auth::id()]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                throw new ApiException('Batch code already exists', 422);
            }
            throw $e;
        }
        return ['id' => (int)$this->db()->lastInsertId()];
    }

    public function updateBatch(array $req, array $p): ?array
    {
        $id = (int)$p['id'];
        $b  = $this->db()->prepare('SELECT * FROM broiler_batches WHERE id = ?');
        $b->execute([$id]);
        if (!$b->fetch()) throw new ApiException('Batch not found', 404);

        $d = Validator::validate($req['body'], [
            'name'        => 'max:100',
            'supplier_id' => 'int',
            'notes'       => 'max:255',
            'status'      => 'in:active,closed',
        ]);
        $sets = []; $args = [];
        foreach (['name','supplier_id','notes','status'] as $f) {
            if (array_key_exists($f, $d) && $d[$f] !== null) { $sets[] = "{$f} = ?"; $args[] = $d[$f]; }
        }
        if (($d['status'] ?? null) === 'closed') { $sets[] = 'closing_date = COALESCE(closing_date, CURDATE())'; }
        if ($sets) {
            $args[] = $id;
            $this->db()->prepare('UPDATE broiler_batches SET ' . implode(',', $sets) . ' WHERE id = ?')
                ->execute($args);
        }
        return $this->batch($req, $p);
    }

    /**
     * Daily record upsert for a batch. Mortality delta is applied to
     * batches.mortality_total + a bird_mortality row so stock stays exact.
     */
    public function saveDaily(array $req, array $p): ?array
    {
        $id = (int)$p['id'];
        $d = Validator::validate($req['body'], [
            'record_date'  => 'required|date',
            'mortality'    => 'int|min:0',
            'feed_kg'      => 'numeric|min:0',
            'avg_weight_g' => 'numeric|min:0',
            'medicine_used'=> 'max:255',
            'cause'        => 'max:120',
            'notes'        => 'max:255',
        ]);

        return Database::tx(function () use ($id, $d) {
            $db = $this->db();
            $current = StockService::batchBirds($id, true);   // locks the batch row

            $old = $db->prepare('SELECT * FROM broiler_daily_records WHERE batch_id=? AND record_date=?');
            $old->execute([$id, $d['record_date']]);
            $oldRow = $old->fetch();
            $oldMortality = $oldRow ? (int)$oldRow['mortality'] : 0;
            $newMortality = (int)($d['mortality'] ?? 0);
            $delta = $newMortality - $oldMortality;

            if ($delta > $current) {
                throw new ApiException("Mortality {$newMortality} exceeds current birds {$current}", 422);
            }

            if ($oldRow) {
                $db->prepare(
                    'UPDATE broiler_daily_records SET mortality=?, feed_kg=?, avg_weight_g=?,
                     medicine_used=?, notes=? WHERE id=?'
                )->execute([$newMortality, $d['feed_kg'] ?? 0, $d['avg_weight_g'] ?? null,
                            $d['medicine_used'] ?? null, $d['notes'] ?? null, $oldRow['id']]);
            } else {
                $db->prepare(
                    'INSERT INTO broiler_daily_records
                     (batch_id, record_date, mortality, feed_kg, avg_weight_g, medicine_used, notes, created_by)
                     VALUES (?,?,?,?,?,?,?,?)'
                )->execute([$id, $d['record_date'], $newMortality, $d['feed_kg'] ?? 0,
                            $d['avg_weight_g'] ?? null, $d['medicine_used'] ?? null,
                            $d['notes'] ?? null, Auth::id()]);
            }

            if ($delta > 0) {
                $db->prepare('UPDATE broiler_batches SET mortality_total = mortality_total + ? WHERE id = ?')
                    ->execute([$delta, $id]);
                $db->prepare(
                    'INSERT INTO bird_mortality (batch_id, mortality_date, quantity, cause, notes, created_by)
                     VALUES (?,?,?,?,?,?)'
                )->execute([$id, $d['record_date'], $delta, $d['cause'] ?? null,
                            $d['notes'] ?? null, Auth::id()]);
            } elseif ($delta < 0) {
                $db->prepare('UPDATE broiler_batches SET mortality_total = mortality_total + ? WHERE id = ?')
                    ->execute([$delta, $id]);   // negative delta reduces total
            }

            return ['current_birds' => StockService::batchBirds($id)];
        });
    }

    public function dailyList(array $req, array $p): ?array
    {
        $st = $this->db()->prepare(
            'SELECT * FROM broiler_daily_records WHERE batch_id = ? ORDER BY record_date DESC LIMIT 90');
        $st->execute([(int)$p['id']]);
        return ['items' => $st->fetchAll()];
    }

    public function mortalityList(array $req, array $p): ?array
    {
        $st = $this->db()->prepare(
            'SELECT * FROM bird_mortality WHERE batch_id = ? ORDER BY mortality_date DESC LIMIT 200');
        $st->execute([(int)$p['id']]);
        return ['items' => $st->fetchAll()];
    }
}
