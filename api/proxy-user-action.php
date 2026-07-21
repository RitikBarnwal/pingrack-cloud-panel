<?php
// api/proxy-user-action.php — User-facing proxy actions (whitelist IP, etc.)
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/proxy_providers.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'error'=>'Method not allowed']); exit; }
$in   = json_decode(file_get_contents('php://input'), true) ?: [];
$csrf = $in['csrf'] ?? '';
if (!verify_csrf($csrf)) { echo json_encode(['success'=>false,'error'=>'Invalid CSRF']); exit; }

$user   = current_user();
$action = $in['action'] ?? '';

// ── UPDATE WHITELIST IP ──────────────────────────────────────
if ($action === 'update_whitelist') {
    $order_id = (int)($in['order_id'] ?? 0);
    $ip       = trim($in['ip'] ?? '');

    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        echo json_encode(['success'=>false,'error'=>'Invalid IPv4 address']); exit;
    }

    // Verify order belongs to user and is active
    $st = db()->prepare(
        "SELECT po.*, prov.* FROM proxy_orders po
         JOIN proxy_providers prov ON prov.id = po.provider_id
         WHERE po.id=? AND po.user_id=? AND po.status='active'"
    );
    $st->execute([$order_id, $user['id']]); $order = $st->fetch();
    if (!$order) { echo json_encode(['success'=>false,'error'=>'Order not found or not active']); exit; }
    if (empty($order['provider_order_id'])) { echo json_encode(['success'=>false,'error'=>'Order not linked to provider yet']); exit; }
    if ($order['slug'] !== 'hydraproxy') { echo json_encode(['success'=>false,'error'=>'IP whitelist is only supported for HydraProxy orders']); exit; }

    // Check unlock timer
    if (!empty($order['whitelist_unlock_at']) && strtotime($order['whitelist_unlock_at']) > time()) {
        $unlock = date('d M Y H:i', strtotime($order['whitelist_unlock_at']));
        echo json_encode(['success'=>false,'error'=>"Whitelist can be updated after {$unlock}"]); exit;
    }

    $api    = new ProxyProviderAPI($order);
    $result = $api->updateWhitelistIp($order['provider_order_id'], $ip);

    if (($result['status'] ?? '') === 'OK') {
        // HydraProxy locks whitelist for some hours after update
        $unlock_at = date('Y-m-d H:i:s', strtotime('+6 hours'));
        db()->prepare("UPDATE proxy_orders SET whitelist_ip=?, whitelist_unlock_at=?, updated_at=NOW() WHERE id=?")
           ->execute([$ip, $unlock_at, $order_id]);
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'error'=>$result['message']??'Provider returned error']);
    }
    exit;
}

echo json_encode(['success'=>false,'error'=>'Unknown action']);
