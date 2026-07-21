<?php
/**
 * cron/master.php — Master Cron Dispatcher
 *
 * cPanel Cron:
 * * * * * /usr/local/bin/php /home/cloudgreat/public_html/cron/master.php >/dev/null 2>&1
 */

declare(strict_types=1);

if (!defined('CV_CRON')) define('CV_CRON', true);
if (!defined('CV_MASTER_CRON')) define('CV_MASTER_CRON', true);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

// ── Ping ──────────────────────────────────────────────────────
set_setting('master_cron_last_ping', date('Y-m-d H:i:s'));

// ── Load enabled tasks ────────────────────────────────────────
function load_tasks(): array
{
    try {
        return db()->query("
            SELECT *
            FROM cron_tasks
            WHERE enabled = 1
            ORDER BY sort_order ASC, id ASC
        ")->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

// ── Should run? ───────────────────────────────────────────────
function should_run(array $task): bool
{
    try {
        $stmt = db()->prepare("
            SELECT started_at
            FROM cron_runs
            WHERE task_key = ?
              AND status != 'skipped'
            ORDER BY started_at DESC
            LIMIT 1
        ");

        $stmt->execute([$task['task_key']]);

        $last = $stmt->fetchColumn();

        if (!$last) {
            return true;
        }

        return (time() - strtotime($last)) >= (int)$task['interval_seconds'];

    } catch (Throwable $e) {
        return true;
    }
}

// ── Save run log ──────────────────────────────────────────────
function log_run(
    string $task_key,
    string $status,
    int $duration_ms,
    string $note = ''
): void {
    try {
        db()->prepare("
            INSERT INTO cron_runs
            (task_key, started_at, duration_ms, status, note)
            VALUES (?, NOW(), ?, ?, ?)
        ")->execute([
            $task_key,
            $duration_ms,
            $status,
            mb_substr($note, 0, 500)
        ]);
    } catch (Throwable $e) {}
}

// ── Run task ──────────────────────────────────────────────────
function run_task(array $task): array
{
    $file = __DIR__ . '/' . ltrim($task['file'], '/');

    if (!file_exists($file)) {
        return [
            'ok'   => false,
            'note' => 'File not found: ' . $task['file'],
            'ms'   => 0
        ];
    }

    $start = microtime(true);

    try {

        // NORMAL execution only
        ob_start();

        require $file;

        $output = trim(ob_get_clean());

        $ms = (int)((microtime(true) - $start) * 1000);

        $lines = array_filter(
            array_map(
                'trim',
                explode("\n", strip_tags($output))
            )
        );

        $note = mb_substr(end($lines) ?: 'done', 0, 500);

        return [
            'ok'   => true,
            'note' => $note,
            'ms'   => $ms,
        ];

    } catch (Throwable $e) {

        if (ob_get_level() > 0) {
            ob_end_clean();
        }

        return [
            'ok'   => false,
            'note' => $e->getMessage(),
            'ms'   => (int)((microtime(true) - $start) * 1000),
        ];
    }
}
        

// ── Cleanup old cron logs ─────────────────────────────────────
function cleanup_old_runs(): void
{
    try {
        db()->exec("
            DELETE FROM cron_runs
            WHERE started_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
    } catch (Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// MAIN
// ══════════════════════════════════════════════════════════════

$global_start = microtime(true);

$tasks   = load_tasks();
$results = [];

$ran    = 0;
$errors = 0;

foreach ($tasks as $task) {

    // Skip if interval not reached
    if (!should_run($task)) {

        $results[$task['task_key']] = [
            'status' => 'skipped'
        ];

        continue;
    }

    $result = run_task($task);

    $status = $result['ok'] ? 'ok' : 'error';

    log_run(
        $task['task_key'],
        $status,
        $result['ms'] ?? 0,
        $result['note'] ?? ''
    );

    $results[$task['task_key']] = [
        'status'      => $status,
        'duration_ms' => $result['ms'] ?? 0,
        'note'        => $result['note'] ?? '',
    ];

    $ran++;

    if (!$result['ok']) {
        $errors++;
    }
}

// Random cleanup once/day approx
if (random_int(1, 1440) === 1) {
    cleanup_old_runs();
}

$total_ms = (int)((microtime(true) - $global_start) * 1000);

// ── JSON Output ───────────────────────────────────────────────
echo json_encode([
    'ok'       => $errors === 0,
    'ran'      => $ran,
    'errors'   => $errors,
    'total_ms' => $total_ms,
    'time'     => date('Y-m-d H:i:s'),
    'tasks'    => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);