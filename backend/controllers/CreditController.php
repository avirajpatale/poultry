<?php

declare(strict_types=1);

/**
 * उधारी / credit register — "they owe me" vs "I owe them", per user.
 * Settling keeps the row (history) but removes it from the open balance.
 */
class CreditController
{
    private function me(): int { return (int)Auth::id(); }

    /** GET /api/credit?person=&settled= — items + open-balance summary per person. */
    public function index(array $req, array $p): ?array
    {
        $q = $req['query'];
        $w = ['created_by = ?']; $args = [$this->me()];
        if (!empty($q['person']))         { $w[] = 'person = ?';   $args[] = $q['person']; }
        if (isset($q['settled']) && $q['settled'] !== '') {
            $w[] = 'settled = ?'; $args[] = (int)$q['settled'];
        }
        $where = 'WHERE ' . implode(' AND ', $w);

        $st = Database::pdo()->prepare(
            "SELECT * FROM credit_entries {$where} ORDER BY settled, txn_date DESC, id DESC LIMIT 300");
        $st->execute($args);

        $sum = Database::pdo()->prepare(
            "SELECT person, net FROM (
                SELECT person,
                       SUM(CASE WHEN direction='they_owe_me' THEN amount ELSE -amount END) AS net
                 FROM credit_entries WHERE created_by = ? AND settled = 0
                 GROUP BY person) s
             WHERE net <> 0 ORDER BY ABS(net) DESC");
        $sum->execute([$this->me()]);

        return ['items' => $st->fetchAll(), 'summary' => $sum->fetchAll()];
    }

    public function store(array $req, array $p): ?array
    {
        $d = Validator::validate($req['body'], [
            'txn_date'  => 'required|date',
            'person'    => 'required|min:2|max:100',
            'direction' => 'required|in:they_owe_me,i_owe_them',
            'amount'    => 'required|numeric|min:0.01|max:99999999',
            'note'      => 'max:255',
        ]);
        Database::pdo()->prepare(
            'INSERT INTO credit_entries (txn_date, person, direction, amount, note, created_by)
             VALUES (?,?,?,?,?,?)'
        )->execute([$d['txn_date'], trim($d['person']), $d['direction'],
                    $d['amount'], $d['note'] ?? null, $this->me()]);
        return ['id' => (int)Database::pdo()->lastInsertId()];
    }

    /** PUT /api/credit/{id} — only toggles the settled flag. */
    public function update(array $req, array $p): ?array
    {
        $settled = !empty($req['body']['settled']) ? 1 : 0;
        $st = Database::pdo()->prepare(
            'UPDATE credit_entries SET settled = ? WHERE id = ? AND created_by = ?');
        $st->execute([$settled, $p['id'], $this->me()]);
        if (!$st->rowCount()) throw new ApiException('Entry not found', 404);
        return ['settled' => $settled];
    }

    public function destroy(array $req, array $p): ?array
    {
        $st = Database::pdo()->prepare(
            'DELETE FROM credit_entries WHERE id = ? AND created_by = ?');
        $st->execute([$p['id'], $this->me()]);
        if (!$st->rowCount()) throw new ApiException('Entry not found', 404);
        return ['deleted' => true];
    }
}
