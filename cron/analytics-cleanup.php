<?php
/**
 * cron/analytics-cleanup.php
 * aaPanel cron: 0 3 * * * php /www/wwwroot/vps.greathost.in/cron/analytics-cleanup.php
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = db();
$log = [];

$jobs = [
    ['activity_log',      'created_at', 30],
    ['error_log',         'created_at', 30],
    ['sec_event_log',     'created_at', 90],
    ['sec_rate_limit',    'window_start', 1],
    ['sec_login_attempts','attempted_at', 7],
];

foreach ($jobs as [$table, $col, $days]) {
    try {
        $st = $pdo->exec("DELETE FROM `{$table}` WHERE `{$col}` < NOW() - INTERVAL {$days} DAY");
        $log[] = "[OK] {$table}: {$st} rows deleted (older than {$days}d)";
    } catch (\Throwable $e) {
        $log[] = "[ERR] {$table}: " . $e->getMessage();
    }
}

$log[] = "Done: " . date('Y-m-d H:i:s');
echo implode("\n", $log) . "\n";