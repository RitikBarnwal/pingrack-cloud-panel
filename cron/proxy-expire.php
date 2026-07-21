<?php
// cron/proxy-expire.php — Hourly: auto-expire + admin alerts
define('CRON_RUN', true);
require_once __DIR__ . '/../includes/bootstrap.php';

$admin_email = get_setting('proxy_admin_email', get_setting('company_email', ''));
$warn_days   = (int)get_setting('proxy_notify_expiry_days', 3);
$now         = date('Y-m-d H:i:s');

// Auto-expire
$st = db()->prepare("UPDATE proxy_orders SET status='expired', updated_at=? WHERE status='active' AND expires_at IS NOT NULL AND expires_at < ?");
$st->execute([$now, $now]);
$n = $st->rowCount();
if ($n) echo "[".date('Y-m-d H:i:s')."] Auto-expired: {$n} order(s)\n";

// Expiring soon alert
$expiring = db()->query(
    "SELECT po.order_ref, po.expires_at, u.email, u.full_name, pp.name plan_name, prov.name provider_name
     FROM proxy_orders po
     JOIN users u ON u.id=po.user_id JOIN proxy_plans pp ON pp.id=po.plan_id JOIN proxy_providers prov ON prov.id=po.provider_id
     WHERE po.status='active' AND po.expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL {$warn_days} DAY)"
)->fetchAll();

if (!empty($expiring) && $admin_email) {
    $body = "The following proxy orders are expiring within {$warn_days} days:\n\n";
    foreach ($expiring as $o) {
        $body .= "• {$o['order_ref']} | {$o['plan_name']} ({$o['provider_name']}) | User: {$o['email']} | Expires: {$o['expires_at']}\n";
    }
    echo "[".date('Y-m-d H:i:s')."] Would send expiry alert for ".count($expiring)." orders to {$admin_email}\n";
    // Uncomment when mailer is available:
    // send_plain_email($admin_email, '⏰ Proxy Orders Expiring Soon', $body);
}

echo "[".date('Y-m-d H:i:s')."] proxy-expire done\n";
