<?php
/**
 * wa-queue.php — Background WhatsApp Queue Worker
 */

define('CLOUDVAULT_WORKER', true);

// ── Resolve bootstrap path ──────────────────────────────────
$root = dirname(__DIR__);

if (!file_exists($root . '/includes/bootstrap.php')) {
    $root = __DIR__;

    while (
        !file_exists($root . '/includes/bootstrap.php')
        && $root !== '/'
    ) {
        $root = dirname($root);
    }
}

if (!file_exists($root . '/includes/bootstrap.php')) {
    echo '[wa-queue] Cannot find bootstrap.php' . PHP_EOL;
    return;
}

require_once $root . '/includes/bootstrap.php';

// ── Logger ──────────────────────────────────────────────────
if (!function_exists('cron_log')) {

    function cron_log(string $msg): void
    {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}

// ── Prevent multiple workers ───────────────────────────────
$lock_file = sys_get_temp_dir() . '/wa_queue_worker.lock';

$lock_fp = fopen($lock_file, 'c');

if (!$lock_fp || !flock($lock_fp, LOCK_EX | LOCK_NB)) {

    cron_log('WA Queue: another worker running, skipped.');

    return;
}

// ── Long-running process settings ──────────────────────────
if (function_exists('set_time_limit')) {
    set_time_limit(0);
}

if (function_exists('ignore_user_abort')) {
    ignore_user_abort(true);
}

// ── Fetch WA settings ──────────────────────────────────────
$wa_api_url = get_setting('wa_api') ?? '';
$wa_token   = get_setting('wa_token') ?? '';

if (!$wa_api_url || !$wa_token) {

    cron_log('WA Queue: API not configured — skipped.');

    release_lock($lock_fp, $lock_file);

    return;
}

// ── Fetch campaigns ────────────────────────────────────────
$campaigns = db()->query(
    "SELECT id, delay_seconds
     FROM wa_campaigns
     WHERE status IN ('queued','running')
     ORDER BY id ASC
     LIMIT 10"
)->fetchAll(PDO::FETCH_ASSOC);

// ── Process campaigns ──────────────────────────────────────
foreach ($campaigns as $campaign) {

    $cid   = (int)$campaign['id'];
    $delay = max(1, (int)$campaign['delay_seconds']);

    db()->prepare(
        "UPDATE wa_campaigns
         SET status='running',
             started_at=COALESCE(started_at,NOW())
         WHERE id=?"
    )->execute([$cid]);

    log_wa("[wa-queue] Processing campaign #{$cid} with delay={$delay}s");

    $pending = db()->prepare(
        "SELECT id, phone, message
         FROM wa_queue
         WHERE campaign_id=?
         AND status='pending'
         ORDER BY id ASC"
    );

    $pending->execute([$cid]);

    while ($row = $pending->fetch(PDO::FETCH_ASSOC)) {

        // ── Check stopped status ────────────────────────────
        $status = db()->prepare(
            "SELECT status
             FROM wa_campaigns
             WHERE id=?"
        );

        $status->execute([$cid]);

        $current_status = $status->fetchColumn();

        if ($current_status === 'stopped') {

            log_wa("[wa-queue] Campaign #{$cid} stopped externally.");

            break;
        }

        $qid   = (int)$row['id'];
        $phone = preg_replace('/\D/', '', $row['phone']);
        $msg   = $row['message'];

        // ── Build API URL ──────────────────────────────────
        $url = rtrim($wa_api_url, '/')
            . '?number=' . urlencode($phone)
            . '&type=text'
            . '&message=' . urlencode($msg)
            . '&token=' . urlencode($wa_token);

        $ok = send_wa_message($url);

        if ($ok) {

            db()->prepare(
                "UPDATE wa_queue
                 SET status='sent',
                     attempted_at=NOW()
                 WHERE id=?"
            )->execute([$qid]);

            db()->prepare(
                "UPDATE wa_campaigns
                 SET sent=sent+1
                 WHERE id=?"
            )->execute([$cid]);

            log_wa("[wa-queue] #{$cid} ✅ Sent to {$phone}");

        } else {

            db()->prepare(
                "UPDATE wa_queue
                 SET status='failed',
                     attempted_at=NOW()
                 WHERE id=?"
            )->execute([$qid]);

            db()->prepare(
                "UPDATE wa_campaigns
                 SET failed=failed+1
                 WHERE id=?"
            )->execute([$cid]);

            // ── Save failed number ─────────────────────────
            $cur = db()->prepare(
                "SELECT failed_numbers
                 FROM wa_campaigns
                 WHERE id=?"
            );

            $cur->execute([$cid]);

            $fn_json = $cur->fetchColumn();

            $fn = json_decode($fn_json ?: '[]', true) ?: [];

            $fn[] = $phone;

            db()->prepare(
                "UPDATE wa_campaigns
                 SET failed_numbers=?
                 WHERE id=?"
            )->execute([
                json_encode($fn),
                $cid
            ]);

            log_wa("[wa-queue] #{$cid} ❌ Failed for {$phone}");
        }

        // ── Delay between sends ────────────────────────────
        sleep($delay);
    }

    // ── Remaining pending count ────────────────────────────
    $remaining_stmt = db()->prepare(
        "SELECT COUNT(*)
         FROM wa_queue
         WHERE campaign_id=?
         AND status='pending'"
    );

    $remaining_stmt->execute([$cid]);

    $remaining = (int)$remaining_stmt->fetchColumn();

    // ── Final campaign status ─────────────────────────────
    $final_stmt = db()->prepare(
        "SELECT status
         FROM wa_campaigns
         WHERE id=?"
    );

    $final_stmt->execute([$cid]);

    $final_status = $final_stmt->fetchColumn();

    if ($final_status !== 'stopped') {

        if ($remaining === 0) {

            db()->prepare(
                "UPDATE wa_campaigns
                 SET status='completed',
                     finished_at=NOW()
                 WHERE id=?"
            )->execute([$cid]);

            log_wa("[wa-queue] #{$cid} ✅ COMPLETED");
        }
    }
}

// ── Summary ────────────────────────────────────────────────
$summary_camps = count($campaigns);

cron_log(
    "WA Queue done. Campaigns processed: {$summary_camps}"
);

// ── Release lock ───────────────────────────────────────────
release_lock($lock_fp, $lock_file);

return;

// ── Helpers ────────────────────────────────────────────────

function send_wa_message(string $url): bool
{
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'CloudVault-WA-Worker/1.0',
    ]);

    $response  = curl_exec($ch);

    $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $curl_err  = curl_error($ch);

    curl_close($ch);

    if ($curl_err) {

        log_wa("[wa-queue] cURL error: {$curl_err}");

        return false;
    }

    if ($http_code >= 200 && $http_code < 300) {

        $json = json_decode($response, true);

        if (is_array($json)) {

            if (
                isset($json['status'])
                && strtolower($json['status']) === 'error'
            ) {
                return false;
            }

            if (
                isset($json['success'])
                && !$json['success']
            ) {
                return false;
            }

            if (
                isset($json['error'])
                && $json['error']
            ) {
                return false;
            }
        }

        return true;
    }

    log_wa(
        "[wa-queue] HTTP {$http_code} | Response: "
        . substr($response, 0, 200)
    );

    return false;
}

function release_lock($fp, string $path): void
{
    flock($fp, LOCK_UN);

    fclose($fp);

    @unlink($path);
}

function log_wa(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] '
          . $msg
          . PHP_EOL;

    echo $line;

    if (ob_get_level() > 0) {
        ob_flush();
    }

    flush();

    error_log($msg);

    $log_file = sys_get_temp_dir() . '/wa_queue.log';

    @file_put_contents(
        $log_file,
        $line,
        FILE_APPEND | LOCK_EX
    );
}