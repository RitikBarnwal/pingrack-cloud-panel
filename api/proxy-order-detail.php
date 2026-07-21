<?php
// api/proxy-order-detail.php — Fetch one proxy order (user-owned)
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$user = current_user();
$id   = (int)($_GET['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Missing order ID']); exit;
}

$st = db()->prepare(
    "SELECT po.*, pp.name AS plan_name, pc.password_plain
     FROM proxy_orders po
     JOIN proxy_plans pp ON pp.id = po.plan_id
     LEFT JOIN proxy_credentials pc ON pc.order_id = po.id
     WHERE po.id = ? AND po.user_id = ?"
);
$st->execute([$id, $user['id']]);
$order = $st->fetch();

if (!$order) {
    echo json_encode(['success' => false, 'error' => 'Order not found']); exit;
}

// Hide proxy_list for non-active orders
if ($order['status'] !== 'active') {
    $order['proxy_list']     = null;
    $order['username']       = null;
    $order['password_plain'] = null;
    $order['gateway_host']   = null;
    $order['gateway_port']   = null;
}

echo json_encode(['success' => true, 'order' => $order]);
