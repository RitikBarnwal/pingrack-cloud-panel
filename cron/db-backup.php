<?php
/**
 * cron/db-backup.php
 *
 * Database backup with email delivery.
 *
 * Crontab (cPanel → Cron Jobs):
 *   0 * * * * /usr/local/bin/php /home/cloudgreat/public_html/cron/db-backup.php >> /tmp/backup.log 2>&1
 *
 * Can also be called directly:
 *   php cron/db-backup.php       — auto (respects interval)
 *   php cron/db-backup.php db    — force run now
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

// ── Logging ───────────────────────────────────────────────────
if (!function_exists('bkp_log')) {
    function bkp_log(string $msg): void {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
        if (ob_get_level() > 0) ob_flush();
        flush();
    }
}

// ── Main entry point (called by cron or admin panel) ─────────
function run_backup(string $type = 'auto'): array
{
    $db_enabled   = get_setting('backup_db_enabled', '0') === '1';
    $interval_hrs = (int)get_setting('backup_interval_hours', '3');
    $backup_dir   = rtrim(get_setting('backup_db_path', dirname(__DIR__) . '/backups'), '/');
    $retention    = (int)get_setting('backup_retention_days', '7');
    $emails_raw   = get_setting('backup_emails', '');
    $emails       = array_filter(array_map('trim', explode("\n", $emails_raw)));

    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0750, true);
    }

    $results = [];

    // ── DB Backup ─────────────────────────────────────────────
    $run_db = ($type === 'db')
           || ($type === 'auto' && $db_enabled && should_run('backup_last_db_at', $interval_hrs));

    if ($run_db && ($db_enabled || $type === 'db')) {
        bkp_log('Starting database backup...');
        try {
            $file = backup_database($backup_dir);
            set_setting('backup_last_db_at', date('Y-m-d H:i:s'));
            bkp_log('DB backup saved: ' . basename($file) . ' (' . round(filesize($file)/1024/1024, 2) . ' MB)');

            if (!empty($emails)) {
                send_backup_email($file, $emails);
                bkp_log('DB backup emailed to: ' . implode(', ', $emails));
            }
            $results['db'] = ['ok' => true, 'file' => basename($file)];
        } catch (Throwable $e) {
            bkp_log('DB backup FAILED: ' . $e->getMessage());
            $results['db'] = ['ok' => false, 'error' => $e->getMessage()];
        }
    } elseif ($type === 'auto' && $db_enabled) {
        bkp_log('DB backup: skipped (interval not reached yet)');
    } elseif ($type === 'auto' && !$db_enabled) {
        bkp_log('DB backup: disabled in settings');
    }

    // ── Cleanup old backups ───────────────────────────────────
    if ($retention > 0) {
        cleanup_old_backups($backup_dir, $retention);
    }

    $final_msg = isset($results['db'])
        ? 'DB: ' . ($results['db']['ok'] ? '✓ ' . $results['db']['file'] : '✗ ' . ($results['db']['error'] ?? 'failed'))
        : 'No backup ran — check settings (db backup enabled?)';

    bkp_log('Result: ' . $final_msg);

    return [
        'ok'      => !empty($results) && empty(array_filter($results, fn($r) => !$r['ok'])),
        'message' => $final_msg,
        'results' => $results,
    ];
}

// ── Check if backup should run based on interval ──────────────
function should_run(string $last_key, int $interval_hrs): bool
{
    $last = get_setting($last_key, '');
    if (!$last) return true;
    return (time() - strtotime($last)) >= ($interval_hrs * 3600);
}

// ── Database backup via mysqldump ─────────────────────────────
function backup_database(string $backup_dir): string
{
    $host   = DB_HOST;
    $dbname = DB_NAME;
    $user   = DB_USER;
    $pass   = DB_PASS;
    $fname  = 'db_' . $dbname . '_' . date('Y-m-d_H-i-s') . '.sql.gz';
    $outfile = $backup_dir . '/' . $fname;

    $cnf_file = tempnam(sys_get_temp_dir(), 'mybackup_');
    file_put_contents($cnf_file, "[client]\npassword=" . $pass . "\n");
    chmod($cnf_file, 0600);

    $cmd = sprintf(
        'mysqldump --defaults-extra-file=%s --host=%s --user=%s --single-transaction --quick --lock-tables=false %s 2>&1 | gzip > %s',
        escapeshellarg($cnf_file),
        escapeshellarg($host),
        escapeshellarg($user),
        escapeshellarg($dbname),
        escapeshellarg($outfile)
    );

    $output = shell_exec($cmd);
    unlink($cnf_file);

    if (!file_exists($outfile) || filesize($outfile) < 100) {
        throw new RuntimeException('mysqldump failed. Output: ' . ($output ?: 'none'));
    }

    return $outfile;
}

// ── Send backup via email ─────────────────────────────────────
function send_backup_email(string $file_path, array $emails): void
{
    require_once __DIR__ . '/../includes/mailer.php';

    $app      = APP_NAME;
    $fname    = basename($file_path);
    $size_mb  = round(filesize($file_path) / 1024 / 1024, 2);
    $date_str = date('d M Y, h:i A');
    $subject  = "[{$app}] Database Backup Ready";

    $attachments = [[
        'name'    => $fname,
        'content' => file_get_contents($file_path),
        'type'    => 'application/octet-stream',
    ]];

    $html = '
    <div style="background:#f4f7fa;padding:40px 10px;font-family:sans-serif;">
        <div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e1e8f0;">
            <div style="background:#0f172a;padding:30px;text-align:center;">
                <h1 style="color:#fff;margin:0;font-size:22px;">' . htmlspecialchars($app) . ' — DB Backup</h1>
            </div>
            <div style="padding:30px;">
                <p style="color:#475569;">Your scheduled <strong>database backup</strong> is ready.</p>
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:20px;">
                    <table style="width:100%;font-size:13px;color:#1e293b;">
                        <tr><td style="padding:5px 0;color:#64748b;">Filename:</td><td style="font-family:monospace;">' . htmlspecialchars($fname) . '</td></tr>
                        <tr><td style="padding:5px 0;color:#64748b;">Size:</td><td><strong>' . $size_mb . ' MB</strong></td></tr>
                        <tr><td style="padding:5px 0;color:#64748b;">Date:</td><td>' . $date_str . '</td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>';

    foreach ($emails as $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        try {
            $sent = send_mail(
                to:          $email,
                to_name:     $email,
                subject:     $subject,
                html_body:   $html,
                attachments: $attachments
            );
            bkp_log($sent ? 'Email sent to: ' . $email : 'Email FAILED to: ' . $email);
        } catch (Throwable $e) {
            bkp_log('Email failed to ' . $email . ': ' . $e->getMessage());
        }
    }
}

// ── Cleanup old backup files ──────────────────────────────────
function cleanup_old_backups(string $backup_dir, int $days): void
{
    $cutoff  = time() - ($days * 86400);
    $files   = glob($backup_dir . '/*.{sql,sql.gz,zip,tar.gz,gz}', GLOB_BRACE) ?: [];
    $deleted = 0;
    foreach ($files as $f) {
        if (filemtime($f) < $cutoff) {
            unlink($f);
            $deleted++;
        }
    }
    if ($deleted > 0) bkp_log("Cleaned up $deleted old backup(s).");
}

// ── Run when called directly (cron / CLI) ────────────────────
if (php_sapi_name() === 'cli' || defined('CV_CRON')) {
    if (!isset($__backup_admin_require)) {
        $type = $argv[1] ?? 'auto';
        bkp_log('====== Backup cron started (type: ' . $type . ') ======');
        $result = run_backup($type);
        bkp_log('====== Done: ' . $result['message'] . ' ======');
    }
}