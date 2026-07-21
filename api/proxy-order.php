<?php
// api/proxy-order.php — Place new proxy order (wallet deduction)
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'error'=>'Method not allowed']); exit; }
$in   = json_decode(file_get_contents('php://input'), true) ?: [];
$csrf = $in['csrf'] ?? '';
if (!verify_csrf($csrf)) { echo json_encode(['success'=>false,'error'=>'Invalid CSRF']); exit; }
if (get_setting('proxy_module_enabled','1') !== '1') { echo json_encode(['success'=>false,'error'=>'Proxy module disabled']); exit; }

$user    = current_user();
$plan_id = (int)($in['plan_id'] ?? 0);
$location = trim($in['location'] ?? 'ANY');
$protocol = trim($in['protocol'] ?? 'http');
$rotation = trim($in['rotation'] ?? 'rotating');

// Validate plan
$st = db()->prepare("SELECT pl.*, prov.id provider_id FROM proxy_plans pl JOIN proxy_providers prov ON prov.id=pl.provider_id WHERE pl.id=? AND pl.is_active=1");
$st->execute([$plan_id]); $plan = $st->fetch();
if (!$plan) { echo json_encode(['success'=>false,'error'=>'Plan not found or inactive']); exit; }

// Validate enums
if (!in_array($protocol, ['http','socks5','https'])) $protocol = $plan['protocol'];
if (!in_array($rotation, ['rotating','sticky']))      $rotation = $plan['rotation'];

// Price
$currency = strtoupper($user['currency'] ?? 'INR');
$price    = $currency === 'INR' ? (float)$plan['price_inr'] : (float)$plan['price_usd'];
$balance  = (float)($user['wallet_balance'] ?? 0);
if ($balance < $price) {
    echo json_encode(['success'=>false,'error'=>"Insufficient wallet balance. Need {$currency} {$price}, have {$currency} {$balance}."]); exit;
}

// Unique order ref
do {
    $ref = 'PRXY-' . strtoupper(substr(md5(uniqid(rand(),true)), 0, 8));
    $chk = db()->prepare("SELECT id FROM proxy_orders WHERE order_ref=?"); $chk->execute([$ref]);
} while ($chk->fetch());

$db = db(); $db->beginTransaction();
try {
    // Deduct wallet
    $db->prepare("UPDATE users SET wallet_balance = wallet_balance - ? WHERE id=?")->execute([$price, $user['id']]);
    // Record transaction
    $db->prepare("INSERT INTO transactions (user_id,type,amount,description,created_at) VALUES (?,'debit',?,?,NOW())")
       ->execute([$user['id'], $price, "Proxy Order: {$plan['name']} ({$ref})"]);
    // Create order
    $db->prepare(
        "INSERT INTO proxy_orders
         (order_ref,user_id,plan_id,provider_id,proxy_type,protocol,location,
          bandwidth_gb,threads,duration_days,amount_paid,currency,status,rotation,created_at,updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'pending',?,NOW(),NOW())"
    )->execute([
        $ref, $user['id'], $plan['id'], $plan['provider_id'],
        $plan['proxy_type'], $protocol, $location,
        $plan['bandwidth_gb'], $plan['threads'], $plan['duration_days'],
        $price, $currency, $rotation
    ]);
    $db->commit();
    echo json_encode(['success'=>true,'order_ref'=>$ref]);
} catch (Throwable $e) {
    $db->rollBack();
    error_log('proxy-order: '.$e->getMessage());
    echo json_encode(['success'=>false,'error'=>'Order failed. Please try again.']);
}
