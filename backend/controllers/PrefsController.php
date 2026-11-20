<?php

declare(strict_types=1);

/** Tiny per-user key/value store — reminders and defaults. */
class PrefsController
{
    /** only these keys may be written through the API */
    private const ALLOWED = ['vaccine_date', 'vaccine_note'];

    /** GET /api/prefs — all of the user's prefs as a {key: value} map. */
    public function index(array $req, array $p): ?array
    {
        $st = Database::pdo()->prepare('SELECT skey, sval FROM user_prefs WHERE user_id = ?');
        $st->execute([Auth::id()]);
        return ['prefs' => $st->fetchAll(PDO::FETCH_KEY_PAIR)];
    }

    /** PUT /api/prefs — body {key, value}; null/'' value deletes the key. */
    public function update(array $req, array $p): ?array
    {
        $d = Validator::validate($req['body'], [
            'key'   => 'required|max:50',
            'value' => 'max:255',
        ]);
        if (!in_array($d['key'], self::ALLOWED, true)) {
            throw new ApiException('Unknown pref', 422);
        }
        $pdo = Database::pdo();
        $uid = Auth::id();
        if ($d['value'] === null || $d['value'] === '') {
            $pdo->prepare('DELETE FROM user_prefs WHERE user_id = ? AND skey = ?')
                ->execute([$uid, $d['key']]);
        } else {
            $pdo->prepare('INSERT INTO user_prefs (user_id, skey, sval) VALUES (?,?,?)
                         ON DUPLICATE KEY UPDATE sval = VALUES(sval)')
                ->execute([$uid, $d['key'], $d['value']]);
        }
        return ['saved' => true];
    }
}
