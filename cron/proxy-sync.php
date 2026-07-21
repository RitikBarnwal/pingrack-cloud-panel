<?php
// cron/proxy-sync.php — Har 5 minute me run hota hai
// aaPanel Cron: */5 * * * * php /www/wwwroot/vps.greathost.in/cron/proxy-sync.php >> /tmp/proxy_sync.log 2>&1

define('CRON_RUN', true);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/proxy_providers.php';
// require_once __DIR__ . '/../includes/mailer.php'; // uncomment when mailer available

$admin_email  = get_setting('proxy_admin_email', get_setting('company_email', ''));
$warn_days    = (int)get_setting('proxy_notify_expiry_days', 3);
$now          = date('Y-m-d H:i:s');

log_line("====== Proxy Sync START ======");

// Fetch all active orders that have a provider_order_id
$orders = db()->query(
    "SELECT po.*, pp.slug AS provider_slug, pp.api_key, pp.api_secret, pp.api_base_url, pp.name AS provider_name
     FROM proxy_orders po
     JOIN proxy_providers pp ON pp.id = po.provider_id
     WHERE po.status IN ('active','pending')
       AND po.provider_order_id IS NOT NULL
       AND po.provider_order_id != ''
       AND pp.slug != 'manual'
       AND pp.is_active = 1"
)->fetchAll();

log_line("Orders to sync: " . count($orders));

$synced = 0; $errors = 0; $suspended_alerts = []; $expiry_alerts = [];

foreach ($orders as $order) {
    $result = sync_proxy_order($order);

    if ($result['ok']) {
        $synced++;
        log_line("[OK] PRXY#{$order['order_ref']} — {$result['msg']}");

        // Check if provider says suspended/expired
        $newStatus = $result['status'] ?? $order['status'];
        if (in_array($newStatus, ['expired','suspended']) && $order['status'] === 'active') {
            $suspended_alerts[] = $order;
            log_line("  ⚠ Status changed to {$newStatus} — admin alert queued");
        }
    } else {
        $errors++;
        log_line("[ERR] PRXY#{$order['order_ref']} — {$result['msg']}");
    }

    // Small pause to be polite to provider APIs
    usleep(300000); // 300ms
}

// ── Update provider account balances ──────────────────────────
$providers = db()->query(
    "SELECT * FROM proxy_providers WHERE slug != 'manual' AND is_active=1 AND api_key IS NOT NULL"
)->fetchAll();

foreach ($providers as $prov) {
    $api  = new ProxyProviderAPI($prov);
    $info = $api->getAccountInfo();
    if (($info['status'] ?? '') === 'OK') {
        $balance  = $info['balance_usd'] ?? $info['balance'] ?? null;
        $currency = 'USD';
        if ($balance !== null) {
            db()->prepare(
                "UPDATE proxy_providers SET account_balance=?, balance_currency=?, last_synced_at=NOW() WHERE id=?"
            )->execute([$balance, $currency, $prov['id']]);
            log_line("[BALANCE] {$prov['name']}: \${$balance}");
        }
    }
    usleep(200000);
}

// ── Expiry warnings (X days before) ─────────────────────────
$expiring = db()->query(
    "SELECT po.*, u.email, u.full_name, u.username, pp.name AS plan_name
     FROM proxy_orders po
     JOIN users u ON u.id = po.user_id
     JOIN proxy_plans pp ON pp.id = po.plan_id
     WHERE po.status='active'
       AND po.expires_at IS NOT NULL
       AND po.expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL {$warn_days} DAY)"
)->fetchAll();

foreach ($expiring as $o) {
    $expiry_alerts[] = $o;
    log_line("[EXPIRY] PRXY#{$o['order_ref']} expires {$o['expires_at']} — user {$o['email']}");
}

// ── Send admin email alerts ───────────────────────────────────
if (!empty($suspended_alerts) && $admin_email) {
    $body = "The following proxy orders changed status (check provider dashboard):\n\n";
    foreach ($suspended_alerts as $o) {
        $body .= "• {$o['order_ref']} | Provider: {$o['provider_name']} | ProviderID: {$o['provider_order_id']}\n";
    }
    // send_plain_email($admin_email, '⚠ Proxy Order Status Alert', $body);
    log_line("[ALERT] Suspended alert email would be sent to {$admin_email}");
}

if (!empty($expiry_alerts) && $admin_email) {
    $body = "The following proxy orders are expiring in {$warn_days} days:\n\n";
    foreach ($expiry_alerts as $o) {
        $body .= "• {$o['order_ref']} | User: {$o['email']} | Expires: {$o['expires_at']}\n";
    }
    // send_plain_email($admin_email, '⏰ Proxy Orders Expiring Soon', $body);
    log_line("[ALERT] Expiry alert email would be sent to {$admin_email}");
}

// ── Auto-expire overdue orders ────────────────────────────────
$expired_count = db()->prepare(
    "UPDATE proxy_orders SET status='expired', updated_at=NOW()
     WHERE status='active' AND expires_at IS NOT NULL AND expires_at < NOW()"
);
$expired_count->execute();
log_line("Auto-expired: " . $expired_count->rowCount());

// ── Update settings for cron monitor ─────────────────────────
$note = "[{$now}] Synced:{$synced} Errors:{$errors} Expired:".($expired_count->rowCount())." Alerts:".count($suspended_alerts);
db()->prepare("INSERT INTO settings (`key`,`value`) VALUES ('cron_proxy_sync_last_run',?)
               ON DUPLICATE KEY UPDATE value=?")->execute([$now,$now]);
db()->prepare("INSERT INTO settings (`key`,`value`) VALUES ('cron_proxy_sync_last_note',?)
               ON DUPLICATE KEY UPDATE value=?")->execute([$note,$note]);
db()->prepare("INSERT INTO settings (`key`,`value`) VALUES ('cron_proxy_sync_last_status',?)
               ON DUPLICATE KEY UPDATE value=?")->execute(['ok','ok']);

log_line("====== Proxy Sync END — {$note} ======\n");

function log_line(string $msg): void {
    echo "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n";
}
