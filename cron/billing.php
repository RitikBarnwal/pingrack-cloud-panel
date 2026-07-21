<?php
/**
 * cron/billing.php
 *
 * Hourly billing cron.
 * Run every hour:
 *   0 * * * * /usr/local/bin/php /home/cloudgreat/public_html/cron/billing.php
 * Or via HTTP: GET /cron/billing.php?secret=YOUR_SECRET
 *
 * Full lifecycle:
 *   - Bill running servers hourly
 *   - Suspend (DB + real provider API shutdown) on low balance
 *   - At 48h suspended → send final warning email
 *   - At 60h suspended → permanent delete (provider API + DB)
 *   - On top-up while suspended → auto-resume
 */
if (!defined('CV_CRON')) define('CV_CRON', true);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/servers.php';

// Auth
if (!defined('CV_CRON')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!function_exists('cron_log')) {
function cron_log(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n"; flush();
}
}

// ── Helper: load provider + handler for a server ─────────────
function load_provider_handler(array $srv): ?array {
    $spid = (int)($srv['source_provider_id'] ?? 0);
    $prov = null;
    if ($spid) {
        $pr = db()->prepare('SELECT * FROM providers WHERE id=? AND is_active=1 LIMIT 1');
        $pr->execute([$spid]); $prov = $pr->fetch() ?: null;
    }
    if (!$prov) return null;

    $ptype = strtolower($prov['provider_type'] ?? '');
    $bp    = __DIR__ . '/../providers/' . $ptype . '/bootstrap.php';
    $hf    = __DIR__ . '/../servers/actions/' . $ptype . '.php';
    if (!file_exists($bp) || !file_exists($hf)) return null;

    require_once $bp;
    require_once $hf;
    CloudProvider::reset();
    $cloud = new CloudProvider($prov['api_key']);
    $cls   = ucfirst($ptype) . 'Actions';
    if (!class_exists($cls)) return null;

    return ['handler' => new $cls($cloud, $srv), 'cloud' => $cloud, 'ptype' => $ptype];
}

// ── Helper: send email safely ────────────────────────────────
function billing_mail(string $to, string $to_name, string $subject, string $body): void {
    try {
        require_once __DIR__ . '/../includes/mailer.php';
        send_mail(to: $to, to_name: $to_name, subject: $subject, html_body: $body);
    } catch (Throwable $e) {
        error_log('[billing-mail] ' . $e->getMessage());
    }
}

// ── Helper: build email wrapper ───────────────────────────────
function email_wrap(string $title, string $color, string $content): string {
    return '<div style="font-family:Arial,sans-serif;max-width:580px;margin:0 auto;padding:24px">
      <div style="background:white;border-radius:12px;border:1px solid #e5e7eb;overflow:hidden">
        <div style="background:' . $color . ';padding:20px 24px;color:white;font-size:18px;font-weight:800">' . APP_NAME . ' — ' . $title . '</div>
        <div style="padding:24px">' . $content . '</div>
      </div>
    </div>';
}

// ── Helper: permanently delete server ────────────────────────
function delete_server_permanently(array $srv, string $reason): void {
    $sid = (int)$srv['id'];
    cron_log("  DELETING server #{$sid} ({$srv['name']}) — {$reason}");

    // 1. Provider API delete
    try {
        $ph = load_provider_handler($srv);
        if ($ph) {
            $ph['handler']->delete_server();
            cron_log("    Provider delete OK");
        }
    } catch (Throwable $e) {
        error_log('[billing-delete-provider] srv#' . $sid . ': ' . $e->getMessage());
    }

    // 2. Log action
    try {
        db()->prepare("INSERT INTO server_actions (server_id, user_id, action, status, created_at) VALUES (?,?,'delete','success',NOW())")
           ->execute([$sid, $srv['user_id']]);
    } catch (Throwable $e) {}

    // 3. DB soft-delete
    db()->prepare("UPDATE servers SET status='deleted', deleted_at=NOW() WHERE id=?")
       ->execute([$sid]);

    cron_log("    DB marked deleted.");
}

cron_log('====== Billing cron started ======');

// ════════════════════════════════════════════════════════════
//  PART 1: Prepaid expiry — suspend servers whose paid period ended
//  (Hourly billing has been removed. All servers are prepaid/cycle-based.)
// ════════════════════════════════════════════════════════════
$running = db()->query(
    "SELECT s.*, u.wallet_balance, u.currency, u.email, u.full_name, u.username
     FROM servers s
     JOIN users u ON u.id = s.user_id
     WHERE s.status = 'running' AND s.deleted_at IS NULL"
)->fetchAll();

cron_log('Running servers: ' . count($running));
$billed = $suspended = $errors = 0;

foreach ($running as $srv) {
    $server_id = (int)$srv['id'];

    // Only prepaid servers with a past expiry are suspended. No hourly charges.
    if (empty($srv['expires_at']) || strtotime($srv['expires_at']) >= time()) {
        continue;
    }

    // Paid period ended — power down + suspend until renewal.
    try {
        $ph = load_provider_handler($srv);
        if ($ph) {
            try { $ph['handler']->shutdown(); }
            catch (Throwable $e) { try { $ph['handler']->stop(); } catch (Throwable $e2) {} }
        }
    } catch (Throwable $e) { error_log('[billing-prepaid-expire] ' . $e->getMessage()); }

    db()->prepare("UPDATE servers SET status='suspended', suspended_at=NOW() WHERE id=?")->execute([$server_id]);
    billing_mail($srv['email'], $srv['full_name'] ?: $srv['username'],
        APP_NAME . ' — Server Expired: ' . $srv['name'],
        email_wrap('Server Expired', '#d97706',
            '<p style="font-size:15px;color:#111827">Your server <strong>' . htmlspecialchars($srv['name']) . '</strong> has reached the end of its billing period and is now suspended.</p>
             <p style="font-size:14px;color:#6b7280">Renew from your dashboard to bring it back online.</p>
             <a href="' . BASE_URL . '/servers.php" style="display:inline-block;padding:12px 24px;background:#2563eb;color:white;border-radius:8px;font-weight:700;text-decoration:none;margin-top:12px">Renew Now →</a>'));
    cron_log("  EXPIRED server #{$server_id} ({$srv['name']})");
    $suspended++;
}

// ════════════════════════════════════════════════════════════
//  PART 2: Suspended server lifecycle
//  48h → warning email | 60h → permanent delete
// ════════════════════════════════════════════════════════════
$suspended_servers = db()->query(
    "SELECT s.*, u.wallet_balance, u.currency, u.email, u.full_name, u.username
     FROM servers s
     JOIN users u ON u.id = s.user_id
     WHERE s.status = 'suspended' AND s.deleted_at IS NULL"
)->fetchAll();

cron_log('Suspended servers to check: ' . count($suspended_servers));
$warnings_sent = $deleted = 0;

foreach ($suspended_servers as $srv) {
    $server_id   = (int)$srv['id'];
    $balance     = (float)$srv['wallet_balance'];
    $amount      = (float)$srv['price_hourly'];
    $currency    = $srv['currency'];
    $sym         = $currency === 'INR' ? '₹' : '$';

    // ── Resume only if still within the paid (prepaid) period ─────
    // Hourly billing is removed; a suspended server comes back only when its
    // paid period is still valid (e.g. renewed → expires_at pushed forward).
    if (!empty($srv['expires_at']) && strtotime($srv['expires_at']) > time()) {
        cron_log("  RESUME server #{$server_id} ({$srv['name']}) — within paid period");

        // Start server via provider API
        try {
            $ph = load_provider_handler($srv);
            if ($ph) { $ph['handler']->start(); }
        } catch (Throwable $e) {
            error_log('[billing-resume] ' . $e->getMessage());
        }

        db()->prepare("UPDATE servers SET status='starting', suspended_at=NULL, suspend_warning_sent_at=NULL WHERE id=?")
           ->execute([$server_id]);
        cron_log("    Resumed #{$server_id}");
        continue;
    }

    // Get suspension time
    $suspended_at = $srv['suspended_at'] ?? $srv['updated_at'] ?? null;
    if (!$suspended_at) continue;

    $hours_suspended = (time() - strtotime($suspended_at)) / 3600;

    // ── 60h+ → Permanent delete ───────────────────────────────
    if ($hours_suspended >= 60) {
        // Check if warning was sent (should have been at 48h)
        $warning_sent = !empty($srv['suspend_warning_sent_at']);

        if (!$warning_sent) {
            // Edge case: warning not sent but 60h passed — send now then delete
            billing_mail($srv['email'], $srv['full_name'] ?: $srv['username'],
                APP_NAME . ' — Server Being Deleted Now: ' . $srv['name'],
                email_wrap('Server Deleted', '#7c3aed',
                    '<p style="font-size:15px;color:#111827">Your server <strong>' . htmlspecialchars($srv['name']) . '</strong> has been <strong>permanently deleted</strong> due to non-payment.</p>
                     <p style="font-size:14px;color:#6b7280">The server was suspended and no payment was received within 60 hours.</p>
                     <p style="font-size:14px;color:#6b7280">All data has been permanently lost. We are not responsible for this data loss.</p>'
                )
            );
        } else {
            // Normal flow: warning sent 12h ago, now deleting
            billing_mail($srv['email'], $srv['full_name'] ?: $srv['username'],
                APP_NAME . ' — Server Permanently Deleted: ' . $srv['name'],
                email_wrap('Server Permanently Deleted', '#7c3aed',
                    '<p style="font-size:15px;color:#111827">Your server <strong>' . htmlspecialchars($srv['name']) . '</strong> has now been <strong>permanently deleted</strong>.</p>
                     <p style="font-size:14px;color:#dc2626">All data has been permanently lost. We are not responsible for this data loss as you were notified 12 hours in advance.</p>
                     <p style="font-size:14px;color:#6b7280">You can create a new server anytime after adding funds to your wallet.</p>
                     <a href="' . BASE_URL . '/billing.php" style="display:inline-block;padding:12px 24px;background:#2563eb;color:white;border-radius:8px;font-weight:700;text-decoration:none;margin-top:12px">Add Funds →</a>'
                )
            );
        }

        delete_server_permanently($srv, '60h suspended with no top-up');
        $deleted++;
        continue;
    }

    // ── 48h+ → Final warning email (once only) ───────────────
    if ($hours_suspended >= 48 && empty($srv['suspend_warning_sent_at'])) {
        cron_log("  WARNING email for server #{$server_id} ({$srv['name']}) — {$hours_suspended}h suspended");

        billing_mail($srv['email'], $srv['full_name'] ?: $srv['username'],
            APP_NAME . ' — Final Warning: Server Will Be Deleted in 12 Hours',
            email_wrap('⚠️ Final Warning — Server Deletion in 12 Hours', '#f59e0b',
                '<p style="font-size:15px;color:#111827">Your server <strong>' . htmlspecialchars($srv['name']) . '</strong> has been suspended for <strong>48 hours</strong>.</p>
                 <table style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:14px 16px;margin:14px 0;width:100%;border-collapse:collapse">
                   <tr><td style="font-size:14px;color:#dc2626;padding:4px 0">⚠️ <strong>Your server will be PERMANENTLY DELETED in 12 hours if you do not add funds.</strong></td></tr>
                   <tr><td style="font-size:13px;color:#9a3412;padding:4px 0">All data on this server will be permanently lost. We will not be responsible for this data loss.</td></tr>
                 </table>
                 <p style="font-size:14px;color:#374151">Server: <strong>' . htmlspecialchars($srv['name']) . '</strong></p>
                 <p style="font-size:14px;color:#374151">Suspended since: <strong>' . date('d M Y H:i', strtotime($suspended_at)) . '</strong></p>
                 <p style="font-size:14px;color:#374151">Balance needed: <strong>' . $sym . number_format($amount * 5, 2) . '</strong> (5 hours min)</p>
                 <a href="' . BASE_URL . '/billing.php" style="display:inline-block;padding:14px 28px;background:#dc2626;color:white;border-radius:8px;font-weight:700;font-size:15px;text-decoration:none;margin-top:14px">⚡ Add Funds NOW to save your server →</a>'
            )
        );

        db()->prepare("UPDATE servers SET suspend_warning_sent_at=NOW() WHERE id=?")
           ->execute([$server_id]);
        $warnings_sent++;
    }
}

cron_log("====== Done. Billed: {$billed} | Suspended: {$suspended} | Warnings: {$warnings_sent} | Deleted: {$deleted} | Errors: {$errors} ======");
