<?php

declare(strict_types=1);

class SettingsController
{
    private function db(): PDO { return Database::pdo(); }

    private const KEYS = [
        'farm_name','farm_address','farm_phone','currency',
        'invoice_prefix','egg_tray_size','low_stock_alert',
    ];

    public function index(array $req, array $p): ?array
    {
        $rows = $this->db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        $out = [];
        foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_value'];
        return ['settings' => $out];
    }

    public function update(array $req, array $p): ?array
    {
        $st = $this->db()->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?,?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach (self::KEYS as $k) {
            if (array_key_exists($k, $req['body'])) {
                $st->execute([$k, (string)$req['body'][$k]]);
            }
        }
        return $this->index($req, $p);
    }
}
