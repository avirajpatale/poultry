<?php

declare(strict_types=1);

/**
 * One-button backup & restore of the logged-in user's register data.
 * Export returns plain JSON the frontend saves as a file; restore
 * replaces ONLY the current user's rows inside a transaction.
 */
class BackupController
{
    /** table => insertable columns (created_by/ids are re-mapped, never trusted) */
    private const TABLES = [
        'layer_daily_records' => ['record_date','opening_eggs','eggs_collected','eggs_purchased',
            'eggs_sold','egg_rate','eggs_damaged','eggs_used','closing_eggs','birds_count','mortality',
            'feed_used_kg','notes'],
        'money_transactions'  => ['txn_date','type','category','description','amount','payment_mode'],
        'material_categories' => ['name'],
        'material_expenses'   => ['txn_date','category_id','quantity','unit','rate','amount','supplier','payment_mode','notes'],
        'credit_entries'      => ['txn_date','person','direction','amount','note','settled'],
    ];

    private function uid(): int { return (int)Auth::id(); }

    /** GET /api/backup — every row belonging to this user, as JSON. */
    public function export(array $req, array $params): ?array
    {
        $db = Database::pdo();
        $data = [];
        foreach (self::TABLES as $table => $_) {
            $st = $db->prepare("SELECT * FROM {$table} WHERE created_by = ? ORDER BY id");
            $st->execute([$this->uid()]);
            $data[$table] = $st->fetchAll();
        }
        // material_expenses reference categories by id — include the map so
        // restore can relink them even when ids change.
        return [
            'app'         => 'poultry-hisab',
            'version'     => 1,
            'exported_at' => date('c'),
            'data'        => $data,
        ];
    }

    /** POST /api/backup/restore — wipes + re-inserts this user's rows in one tx. */
    public function restore(array $req, array $params): ?array
    {
        $data = $req['body']['data'] ?? null;
        if (!is_array($data)) throw new ApiException('Invalid backup file', 422);

        $uid = $this->uid();
        $db  = Database::pdo();

        return Database::tx(function () use ($db, $data, $uid) {
            $counts = [];

            // Categories first (expenses reference them); map old id -> new id.
            $catMap = [];
            $db->prepare('DELETE FROM material_expenses WHERE created_by = ?')->execute([$uid]);
            $db->prepare('DELETE FROM material_categories WHERE created_by = ?')->execute([$uid]);
            foreach (($data['material_categories'] ?? []) as $row) {
                $name = mb_substr(trim((string)($row['name'] ?? '')), 0, 80);
                if ($name === '') continue;
                $db->prepare('INSERT INTO material_categories (name, created_by) VALUES (?,?)')
                   ->execute([$name, $uid]);
                if (isset($row['id'])) $catMap[(int)$row['id']] = (int)$db->lastInsertId();
                $counts['material_categories'] = ($counts['material_categories'] ?? 0) + 1;
            }

            $expCols = self::TABLES['material_expenses'];
            foreach (($data['material_expenses'] ?? []) as $row) {
                $vals = [];
                foreach ($expCols as $c) $vals[$c] = $row[$c] ?? null;
                $vals['category_id'] = $catMap[(int)($row['category_id'] ?? 0)] ?? null;
                if ($vals['category_id'] === null) continue;  // orphan row — skip
                $this->insert($db, 'material_expenses', $expCols, $vals, $uid);
                $counts['material_expenses'] = ($counts['material_expenses'] ?? 0) + 1;
            }

            foreach (['layer_daily_records', 'money_transactions', 'credit_entries'] as $table) {
                $db->prepare("DELETE FROM {$table} WHERE created_by = ?")->execute([$uid]);
                $cols = self::TABLES[$table];
                foreach (($data[$table] ?? []) as $row) {
                    $vals = [];
                    foreach ($cols as $c) $vals[$c] = $row[$c] ?? null;
                    $this->insert($db, $table, $cols, $vals, $uid);
                    $counts[$table] = ($counts[$table] ?? 0) + 1;
                }
            }
            return ['restored' => $counts];
        });
    }

    private function insert(PDO $db, string $table, array $cols, array $vals, int $uid): void
    {
        $names   = [...$cols, 'created_by'];
        $vals['created_by'] = $uid;
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table,
            implode(',', $names), implode(',', array_fill(0, count($names), '?')));
        $db->prepare($sql)->execute(array_map(fn($c) => $vals[$c] ?? null, $names));
    }
}
