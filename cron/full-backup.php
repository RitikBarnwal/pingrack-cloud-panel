<?php
/**
 * cron/full-backup.php
 *
 * Full backup (DB + Files) with SSH remote delivery.
 * Uses backup_config, backup_profiles, backup_jobs tables.
 *
 * aaPanel Cron → Shell Script → Every 1 hour:
 *   /usr/local/bin/php /home/cloudgreat/public_html/cron/full-backup.php
 *
 * CLI force run:
 *   php cron/full-backup.php           — auto (interval check)
 *   php cron/full-backup.php full      — force full
 *   php cron/full-backup.php db        — force DB only
 *   php cron/full-backup.php files     — force files only
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

// ── Logger (buffer for DB job log) ───────────────────────────
$_fb_log_buffer = '';
function fbkp_log(string $msg): void {
    global $_fb_log_buffer;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    $_fb_log_buffer .= $line;
    echo $line;
    if (ob_get_level() > 0) ob_flush();
    flush();
}

// ── Load config from backup_config table ─────────────────────
function fb_get_config(): array {
    $row = db()->query("SELECT * FROM backup_config WHERE id=1 LIMIT 1")->fetch();
    if (!$row) throw new RuntimeException('backup_config table missing or empty. Run migration SQL first.');
    return $row;
}

function fb_save_config_field(string $col, mixed $val): void {
    db()->prepare("UPDATE backup_config SET `{$col}`=?, updated_at=NOW() WHERE id=1")->execute([$val]);
}

// ── Load active SSH profile ───────────────────────────────────
function fb_get_active_profile(): ?array {
    $row = db()->query("SELECT * FROM backup_profiles WHERE is_active=1 ORDER BY id LIMIT 1")->fetch();
    return $row ?: null;
}

// ── Job record helpers ────────────────────────────────────────
function fb_job_start(int $profile_id_or_0, string $type, string $triggered_by): int {
    db()->prepare(
        "INSERT INTO backup_jobs (profile_id, type, triggered_by, status, started_at)
         VALUES (?, ?, ?, 'running', NOW())"
    )->execute([$profile_id_or_0 ?: null, $type, $triggered_by]);
    return (int)db()->lastInsertId();
}

function fb_job_finish(int $job_id, string $status, array $data = []): void {
    global $_fb_log_buffer;
    db()->prepare(
        "UPDATE backup_jobs SET
           status=?, db_file=?, db_size=?, files_file=?, files_size=?,
           ssh_uploaded=?, log=?, error=?, finished_at=NOW(),
           duration_sec=TIMESTAMPDIFF(SECOND, started_at, NOW())
         WHERE id=?"
    )->execute([
        $status,
        $data['db_file']    ?? null,
        $data['db_size']    ?? null,
        $data['files_file'] ?? null,
        $data['files_size'] ?? null,
        $data['ssh_uploaded'] ?? 0,
        $_fb_log_buffer,
        $data['error'] ?? null,
        $job_id,
    ]);
}

// ── Main ──────────────────────────────────────────────────────
function run_full_backup(string $type = 'auto'): array
{
    $cfg     = fb_get_config();
    $profile = fb_get_active_profile();

    $interval_hrs = (int)$cfg['interval_hours'];
    $retention    = (int)$cfg['retention_days'];
    $local_dir    = rtrim($cfg['local_staging_path'] ?: dirname(__DIR__) . '/backups/full', '/');
    $project_root = rtrim($cfg['project_root'] ?: dirname(__DIR__), '/');
    $triggered_by = (php_sapi_name() === 'cli') ? 'cron' : 'manual';

    // Auto mode: enabled check + interval check
    if ($type === 'auto') {
        if (!$cfg['enabled']) {
            fbkp_log('Full backup disabled. Exiting.');
            return ['ok' => true, 'message' => 'Disabled'];
        }
        if ($cfg['last_run_at']) {
            $elapsed = time() - strtotime($cfg['last_run_at']);
            if ($elapsed < $interval_hrs * 3600) {
                fbkp_log('Interval not reached. Skipping.');
                return ['ok' => true, 'message' => 'Interval not reached'];
            }
        }
    }

    $do_db    = in_array($type, ['auto', 'full', 'db'])    && (bool)$cfg['backup_db'];
    $do_files = in_array($type, ['auto', 'full', 'files']) && (bool)$cfg['backup_files'];

    // Force mode ignores enabled toggle on individual type
    if ($type === 'db')    { $do_db = true; $do_files = false; }
    if ($type === 'files') { $do_db = false; $do_files = true; }
    if ($type === 'full')  { $do_db = true;  $do_files = true; }

    $job_type        = $do_db && $do_files ? 'full' : ($do_db ? 'db' : 'files');
    $job_id          = fb_job_start($profile['id'] ?? 0, $job_type, $triggered_by);
    $job_data        = [];
    $ssh_uploaded    = false;
    $ts              = date('Y-m-d_H-i-s');
    $app_slug        = preg_replace('/[^a-z0-9_\-]/i', '_', APP_NAME ?: 'cloudvault');
    $overall_ok      = true;

    if (!is_dir($local_dir)) mkdir($local_dir, 0750, true);

    // ── DB backup ─────────────────────────────────────────────
    if ($do_db) {
        fbkp_log('--- DB backup starting ---');
        try {
            $db_file = fb_backup_database($local_dir, $app_slug, $ts);
            $db_size = filesize($db_file);
            fbkp_log('DB done: ' . basename($db_file) . ' (' . fb_human_size($db_size) . ')');
            $job_data['db_file'] = basename($db_file);
            $job_data['db_size'] = $db_size;

            if ($profile) {
                fb_ssh_ensure_dir($profile, $profile['remote_path'] . '/db');
                fb_scp_upload($db_file, $profile, $profile['remote_path'] . '/db/' . basename($db_file));
                fbkp_log('DB → SSH uploaded OK');
                $ssh_uploaded = true;
            }
        } catch (Throwable $e) {
            fbkp_log('DB FAILED: ' . $e->getMessage());
            $job_data['error'] = 'DB: ' . $e->getMessage();
            $overall_ok = false;
        }
    }

    // ── Files backup ──────────────────────────────────────────
    if ($do_files) {
        fbkp_log('--- Files backup starting ---');
        try {
            $files_archive = fb_backup_files($local_dir, $app_slug, $ts, $project_root, $cfg['excludes']);
            $files_size    = filesize($files_archive);
            fbkp_log('Files done: ' . basename($files_archive) . ' (' . fb_human_size($files_size) . ')');
            $job_data['files_file'] = basename($files_archive);
            $job_data['files_size'] = $files_size;

            if ($profile) {
                fb_ssh_ensure_dir($profile, $profile['remote_path'] . '/files');
                fb_scp_upload($files_archive, $profile, $profile['remote_path'] . '/files/' . basename($files_archive));
                fbkp_log('Files → SSH uploaded OK');
                $ssh_uploaded = true;
            }
        } catch (Throwable $e) {
            fbkp_log('Files FAILED: ' . $e->getMessage());
            $job_data['error'] = ($job_data['error'] ?? '') . ' | Files: ' . $e->getMessage();
            $overall_ok = false;
        }
    }

    $job_data['ssh_uploaded'] = $ssh_uploaded ? 1 : 0;

    // ── Retention cleanup ─────────────────────────────────────
    fb_cleanup_local($local_dir, $retention);
    if ($profile && $ssh_uploaded) {
        try { fb_ssh_cleanup_remote($profile, $profile['remote_path'], $retention); }
        catch (Throwable $e) { fbkp_log('Remote cleanup warn: ' . $e->getMessage()); }
    }

    // ── Update last_run_at ────────────────────────────────────
    $next = date('Y-m-d H:i:s', time() + $interval_hrs * 3600);
    db()->prepare("UPDATE backup_config SET last_run_at=NOW(), next_run_at=?, updated_at=NOW() WHERE id=1")
       ->execute([$next]);

    $status = $overall_ok ? 'success' : ($job_data['db_file'] ?? $job_data['files_file'] ?? false ? 'partial' : 'failed');
    $summary = implode(' | ', array_filter([
        isset($job_data['db_file'])    ? '✓ DB: '    . $job_data['db_file']    : (($do_db    && !$overall_ok) ? '✗ DB failed'    : null),
        isset($job_data['files_file']) ? '✓ Files: ' . $job_data['files_file'] : (($do_files && !$overall_ok) ? '✗ Files failed' : null),
    ]));
    $job_data['error'] = $job_data['error'] ?? null;

    fb_job_finish($job_id, $status, $job_data);
    // ── Email notify ─────────────────────────────────────────────
try {

    global $_fb_log_buffer;
    $emails = array_filter(array_map('trim', explode("\n", $cfg['notify_emails'] ?? '')));

    if ($emails) {

        require_once __DIR__ . '/../includes/mailer.php';

        $subject = '[' . APP_NAME . '] Backup ' . strtoupper($status);

        $body = '
        <div style="font-family:Arial,sans-serif;font-size:14px;color:#111">
            <h2 style="margin:0 0 15px">Backup Report</h2>

            <table cellpadding="8" cellspacing="0" border="1" style="border-collapse:collapse;border-color:#ddd">
                <tr>
                    <td><b>Status</b></td>
                    <td>' . htmlspecialchars($status) . '</td>
                </tr>
                <tr>
                    <td><b>Type</b></td>
                    <td>' . htmlspecialchars($job_type) . '</td>
                </tr>
                <tr>
                    <td><b>Time</b></td>
                    <td>' . date('d M Y H:i:s') . '</td>
                </tr>
                <tr>
                    <td><b>Summary</b></td>
                    <td>' . nl2br(htmlspecialchars($summary)) . '</td>
                </tr>
            </table>

            <br>

            <pre style="background:#f5f5f5;padding:12px;border-radius:6px;font-size:12px;overflow:auto">'
            . htmlspecialchars($_fb_log_buffer) .
            '</pre>
        </div>';

        foreach ($emails as $to) {

            send_mail(
                $to,
                'Admin',
                $subject,
                $body
            );

        }

        fbkp_log('Email notification sent.');

    }

} catch (Throwable $e) {

    fbkp_log('Email notify failed: ' . $e->getMessage());

}
    fbkp_log("=== Done [{$status}]: {$summary} ===");

    return ['ok' => $overall_ok, 'message' => $summary, 'job_id' => $job_id];
}

// ── mysqldump ────────────────────────────────────────────────
function fb_backup_database(string $dir, string $slug, string $ts): string
{
    $out = $dir . "/db_{$slug}_{$ts}.sql.gz";
    $cnf = tempnam(sys_get_temp_dir(), 'fbkp_');
    file_put_contents(
    $cnf,
    "[client]\nuser=\"" . DB_USER . "\"\npassword=\"" . DB_PASS . "\"\nhost=\"" . DB_HOST . "\"\n"
);
    chmod($cnf, 0600);
    $cmd = sprintf(
        'mysqldump --defaults-extra-file=%s --host=%s --user=%s --single-transaction --quick --lock-tables=false %s 2>&1 | gzip > %s',
        escapeshellarg($cnf), escapeshellarg(DB_HOST), escapeshellarg(DB_USER),
        escapeshellarg(DB_NAME), escapeshellarg($out)
    );
    shell_exec($cmd);
    unlink($cnf);
    if (!file_exists($out) || filesize($out) < 100) throw new RuntimeException('mysqldump failed');
    return $out;
}

// ── tar.gz files ─────────────────────────────────────────────
function fb_backup_files(string $dir, string $slug, string $ts, string $root, string $excludes_raw): string
{
    $out      = $dir . "/files_{$slug}_{$ts}.tar.gz";
    $excludes = array_filter(array_map('trim', explode("\n", $excludes_raw)));
    $ex_args  = implode(' ', array_map(fn($e) => '--exclude=' . escapeshellarg(ltrim($e, '/')), $excludes));
    $cmd      = "tar {$ex_args} -czf " . escapeshellarg($out) . " -C " . escapeshellarg($root) . " . 2>&1";
    shell_exec($cmd);
    if (!file_exists($out) || filesize($out) < 100) throw new RuntimeException('tar failed');
    return $out;
}

// ── SSH helpers ───────────────────────────────────────────────
function fb_ssh_cmd(array $p, string $remote_cmd): string {
    if ($p['ssh_key_path'] && file_exists($p['ssh_key_path'])) {
        $cmd = sprintf('ssh -i %s -p %d -o StrictHostKeyChecking=no -o BatchMode=yes -o ConnectTimeout=20 %s %s 2>&1',
            escapeshellarg($p['ssh_key_path']), (int)$p['ssh_port'],
            escapeshellarg($p['ssh_user'] . '@' . $p['ssh_host']),
            escapeshellarg($remote_cmd));
    } else {
        $cmd = sprintf('sshpass -p %s ssh -p %d -o StrictHostKeyChecking=no -o ConnectTimeout=20 %s %s 2>&1',
            escapeshellarg($p['ssh_password']), (int)$p['ssh_port'],
            escapeshellarg($p['ssh_user'] . '@' . $p['ssh_host']),
            escapeshellarg($remote_cmd));
    }
    return (string)shell_exec($cmd);
}

function fb_ssh_ensure_dir(array $p, string $remote_dir): void {
    fb_ssh_cmd($p, 'mkdir -p ' . escapeshellarg($remote_dir));
}

function fb_scp_upload(string $local, array $p, string $remote_full): void {
    if ($p['ssh_key_path'] && file_exists($p['ssh_key_path'])) {
        $cmd = sprintf('scp -i %s -P %d -o StrictHostKeyChecking=no -o BatchMode=yes %s %s 2>&1',
            escapeshellarg($p['ssh_key_path']), (int)$p['ssh_port'],
            escapeshellarg($local),
            escapeshellarg($p['ssh_user'] . '@' . $p['ssh_host'] . ':' . $remote_full));
    } else {
        $cmd = sprintf('sshpass -p %s scp -P %d -o StrictHostKeyChecking=no %s %s 2>&1',
            escapeshellarg($p['ssh_password']), (int)$p['ssh_port'],
            escapeshellarg($local),
            escapeshellarg($p['ssh_user'] . '@' . $p['ssh_host'] . ':' . $remote_full));
    }
    $out = shell_exec($cmd);
    if ($out && trim($out) !== '') {
    throw new RuntimeException('SCP upload failed: ' . $out);
}
}

function fb_ssh_cleanup_remote(array $p, string $base, int $days): void {
    $out = fb_ssh_cmd($p,
        sprintf('find %s -type f \( -name "*.sql.gz" -o -name "*.tar.gz" \) -mtime +%d -delete 2>&1',
            escapeshellarg($base), $days)
    );
    fbkp_log('Remote cleanup done' . (trim($out) ? ': ' . trim($out) : '.'));
}

function fb_cleanup_local(string $dir, int $days): void {
    $cutoff = time() - ($days * 86400);
    foreach (glob($dir . '/*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < $cutoff) { unlink($f); fbkp_log('Local deleted: ' . basename($f)); }
    }
}

function fb_human_size(int $b): string {
    if ($b >= 1073741824) return round($b/1073741824,2).' GB';
    if ($b >= 1048576)    return round($b/1048576,2).' MB';
    if ($b >= 1024)       return round($b/1024,1).' KB';
    return $b.' B';
}

// ── CLI entry ─────────────────────────────────────────────────
if (php_sapi_name() === 'cli' || defined('CV_CRON')) {
    if (!isset($__fullbackup_admin_require)) {
        $type = $argv[1] ?? 'auto';
        fbkp_log("====== Full Backup cron started [type:{$type}] ======");
        $r = run_full_backup($type);
        fbkp_log('====== ' . ($r['ok'] ? 'SUCCESS' : 'FAILED') . ': ' . $r['message'] . ' ======');
        exit($r['ok'] ? 0 : 1);
    }
}