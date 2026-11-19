<?php

declare(strict_types=1);

/**
 * Daily egg register — every record is private to its owner (created_by).
 * Closing = opening + collected + purchased − sold − damaged − used.
 * Editing an old day recomputes opening/closing for every later day of
 * THE SAME USER inside one transaction.
 */
class LayerController
{
    private function db(): PDO { return Database::pdo(); }
    private function me(): int { return (int)Auth::id(); }

    public function records(array $req, array $p): ?array
    {
        $q = $req['query'];
        $w = ['created_by = ?']; $args = [$this->me()];
        if (!empty($q['from'])) { $w[] = 'record_date >= ?'; $args[] = $q['from']; }
        if (!empty($q['to']))   { $w[] = 'record_date <= ?'; $args[] = $q['to']; }
        $where = 'WHERE ' . implode(' AND ', $w);
        [$page, $perPage, $offset] = Validator::pageParams($q);

        $cnt = $this->db()->prepare("SELECT COUNT(*) FROM layer_daily_records {$where}");
        $cnt->execute($args);
        $st = $this->db()->prepare(
            "SELECT * FROM layer_daily_records {$where}
             ORDER BY record_date DESC LIMIT {$perPage} OFFSET {$offset}");
        $st->execute($args);

        return ['items' => $st->fetchAll(), 'total' => (int)$cnt->fetchColumn(),
                'page' => $page, 'per_page' => $perPage,
                'egg_stock' => $this->latestStock()];
    }

    public function record(array $req, array $p): ?array
    {
        $st = $this->db()->prepare(
            'SELECT * FROM layer_daily_records WHERE record_date = ? AND created_by = ?');
        $st->execute([$p['date'], $this->me()]);
        $row = $st->fetch();
        if (!$row) throw new ApiException('No record for ' . $p['date'], 404);
        return $row;
    }

    /**
     * Upsert one day's record for the current user.
     * Opening defaults to that user's previous closing (or user-entered value).
     */
    public function saveRecord(array $req, array $p): ?array
    {
        $d = Validator::validate($req['body'], [
            'record_date'    => 'required|date',
            'opening_eggs'   => 'int|min:0',
            'eggs_collected' => 'required|int|min:0',
            'eggs_purchased' => 'int|min:0',
            'eggs_damaged'   => 'int|min:0',
            'eggs_used'      => 'int|min:0',
            'eggs_sold'      => 'int|min:0',
            'notes'          => 'max:255',
        ]);

        $me = $this->me();
        return Database::tx(function () use ($d, $me) {
            $db   = $this->db();
            $date = $d['record_date'];

            $prev = $db->prepare(
                'SELECT closing_eggs FROM layer_daily_records
                 WHERE created_by = ? AND record_date < ? ORDER BY record_date DESC LIMIT 1');
            $prev->execute([$me, $date]);
            $defaultOpening = (int)($prev->fetchColumn() ?: 0);

            $existing = $db->prepare(
                'SELECT * FROM layer_daily_records WHERE record_date = ? AND created_by = ?');
            $existing->execute([$date, $me]);
            $old = $existing->fetch();

            $opening = $d['opening_eggs'] !== null
                ? (int)$d['opening_eggs']
                : ($old ? (int)$old['opening_eggs'] : $defaultOpening);

            $collected = (int)$d['eggs_collected'];
            $purchased = (int)($d['eggs_purchased'] ?? 0);
            $sold      = (int)($d['eggs_sold'] ?? ($old['eggs_sold'] ?? 0));
            $damaged   = (int)($d['eggs_damaged'] ?? 0);
            $used      = (int)($d['eggs_used'] ?? 0);
            $closing   = $opening + $collected + $purchased - $sold - $damaged - $used;
            if ($closing < 0) {
                throw new ApiException("Egg counts invalid — closing stock would be {$closing}", 422);
            }

            if ($old) {
                $db->prepare(
                    'UPDATE layer_daily_records SET opening_eggs=?, eggs_collected=?, eggs_purchased=?,
                     eggs_sold=?, eggs_damaged=?, eggs_used=?, closing_eggs=?, notes=?
                     WHERE id=?'
                )->execute([$opening, $collected, $purchased, $sold, $damaged, $used,
                            $closing, $d['notes'] ?? null, $old['id']]);
                $recordId = (int)$old['id'];
            } else {
                $db->prepare(
                    'INSERT INTO layer_daily_records
                     (record_date, opening_eggs, eggs_collected, eggs_purchased, eggs_sold,
                      eggs_damaged, eggs_used, closing_eggs, notes, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?)'
                )->execute([$date, $opening, $collected, $purchased, $sold, $damaged, $used,
                            $closing, $d['notes'] ?? null, $me]);
                $recordId = (int)$db->lastInsertId();
            }

            $this->recascade($date, $me);

            return ['id' => $recordId, 'opening_eggs' => $opening,
                    'closing_eggs' => $closing, 'egg_stock' => $this->latestStock()];
        });
    }

    /**
     * Recompute opening/closing for every record of THIS user after $date.
     * Keeps their day-to-day chain consistent after edits.
     */
    private function recascade(string $date, int $me): void
    {
        $db = $this->db();
        $st = $db->prepare(
            'SELECT * FROM layer_daily_records
             WHERE created_by = ? AND record_date > ? ORDER BY record_date ASC');
        $st->execute([$me, $date]);
        $prev = $db->prepare(
            'SELECT closing_eggs FROM layer_daily_records
             WHERE created_by = ? AND record_date <= ? ORDER BY record_date DESC LIMIT 1');
        $upd = $db->prepare(
            'UPDATE layer_daily_records SET opening_eggs=?, closing_eggs=? WHERE id=?');

        $prev->execute([$me, $date]);
        $running = (int)($prev->fetchColumn() ?: 0);

        foreach ($st->fetchAll() as $r) {
            $closing = $running
                + (int)$r['eggs_collected'] + (int)$r['eggs_purchased']
                - (int)$r['eggs_sold'] - (int)$r['eggs_damaged'] - (int)$r['eggs_used'];
            if ($closing < 0) {
                throw new ApiException(
                    "This change would make stock negative on {$r['record_date']}", 422);
            }
            $upd->execute([$running, $closing, $r['id']]);
            $running = $closing;
        }
    }

    /** This user's egg stock = closing of their newest record. */
    private function latestStock(): int
    {
        $st = $this->db()->prepare(
            'SELECT closing_eggs FROM layer_daily_records
             WHERE created_by = ? ORDER BY record_date DESC LIMIT 1');
        $st->execute([$this->me()]);
        $v = $st->fetchColumn();
        return $v === false ? 0 : (int)$v;
    }

    public function deleteRecord(array $req, array $p): ?array
    {
        $me = $this->me();
        return Database::tx(function () use ($p, $me) {
            $st = $this->db()->prepare(
                'SELECT id FROM layer_daily_records WHERE record_date = ? AND created_by = ?');
            $st->execute([$p['date'], $me]);
            $id = $st->fetchColumn();
            if (!$id) throw new ApiException('Record not found', 404);
            $this->db()->prepare('DELETE FROM layer_daily_records WHERE id = ?')->execute([$id]);
            $this->recascade($p['date'], $me);
            return ['deleted' => true, 'egg_stock' => $this->latestStock()];
        });
    }

    /** Current egg stock (per user) — movement list kept for compatibility. */
    public function eggStock(array $req, array $p): ?array
    {
        return ['stock' => $this->latestStock(), 'movements' => []];
    }
}
