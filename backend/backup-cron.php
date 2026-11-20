<?php
/**
 * Daily backup — run from Task Scheduler:
 *   php "E:\Website new\poultry-management\backend\backup-cron.php"
 * Writes one JSON file per user into backups\YYYY-MM-DD\ and keeps 30 days.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

$TABLES = ['layer_daily_records', 'money_transactions', 'material_categories', 'material_expenses', 'credit_entries'];
$dir = __DIR__ . '/../backups/' . date('Y-m-d');
if (!is_dir($dir)) mkdir($dir, 0777, true);

$db = Database::pdo();
$users = $db->query('SELECT id, name, email FROM users')->fetchAll();

foreach ($users as $u) {
    $data = [];
    foreach ($TABLES as $t) {
        $st = $db->prepare("SELECT * FROM {$t} WHERE created_by = ? ORDER BY id");
        $st->execute([$u['id']]);
        $data[$t] = $st->fetchAll();
    }
    $file = $dir . '/user-' . $u['id'] . '-' . preg_replace('/[^a-z0-9]+/i', '-', $u['email']) . '.json';
    file_put_contents($file, json_encode([
        'app' => 'poultry-hisab', 'version' => 1,
        'exported_at' => date('c'), 'user' => $u['email'], 'data' => $data,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "backed up {$u['email']} -> {$file}\n";
}

// prune folders older than 30 days
$root = __DIR__ . '/../backups';
foreach (glob($root . '/*', GLOB_ONLYDIR) as $d) {
    if (strtotime(basename($d)) < strtotime('-30 days')) {
        array_map('unlink', glob($d . '/*'));
        rmdir($d);
        echo "pruned {$d}\n";
    }
}
echo "done\n";
