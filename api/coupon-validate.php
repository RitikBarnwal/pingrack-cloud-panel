<?php
/**
 * api/coupon-validate.php
 * Validates a coupon code for a given deposit amount.
 * Returns discount details — does NOT mark it as used yet.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok'=>false,'error'=>'POST required']); exit;
}

$body        = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$code        = strtoupper(trim($body['code']   ?? ''));
$amount      = (float)($body['amount']          ?? 0);
$csrf        = $body['csrf'] ?? $body['csrf_token'] ?? '';

if (!verify_csrf($csrf)) { echo json_encode(['ok'=>false,'error'=>'Invalid token']); exit; }

$user = current_user();

if (!$code)   { echo json_encode(['ok'=>false,'error'=>'Please enter a coupon code.']); exit; }
if ($amount <= 0) { echo json_encode(['ok'=>false,'error'=>'Enter deposit amount first.']); exit; }

// ── Fetch coupon ──────────────────────────────────────────
$st = db()->prepare('SELECT * FROM coupons WHERE code=? AND is_active=1 LIMIT 1');
$st->execute([$code]);
$coupon = $st->fetch();

if (!$coupon) {
    echo json_encode(['ok'=>false,'error'=>'Invalid coupon code.']); exit;
}

// ── Checks ────────────────────────────────────────────────
// Expiry
if ($coupon['expires_at'] && strtotime($coupon['expires_at']) < time()) {
    echo json_encode(['ok'=>false,'error'=>'This coupon has expired.']); exit;
}

// Max uses
if ($coupon['max_uses'] !== null && $coupon['used_count'] >= $coupon['max_uses']) {
    echo json_encode(['ok'=>false,'error'=>'This coupon has reached its usage limit, claimed by all <strong>'.$coupon['max_uses'].' users</strong>.']); exit;
}

// Minimum deposit
if ($amount < (float)$coupon['min_deposit']) {
    echo json_encode(['ok'=>false,'error'=>'Minimum deposit of ₹'.number_format((float)$coupon['min_deposit'],2).' required for this coupon.']); exit;
}

// One use per user
$used = db()->prepare('SELECT id FROM coupon_uses WHERE coupon_id=? AND user_id=? LIMIT 1');
$used->execute([$coupon['id'], $user['id']]);
if ($used->fetch()) {
    echo json_encode(['ok'=>false,'error'=>'You have already used this coupon.']); exit;
}

// ── Calculate discount ────────────────────────────────────
$discount = 0.0;
if ($coupon['type'] === 'percentage') {
    $discount = round($amount * (float)$coupon['value'] / 100, 2);
    // Apply max discount cap
    if ($coupon['max_discount'] !== null && $discount > (float)$coupon['max_discount']) {
        $discount = (float)$coupon['max_discount'];
    }
} else {
    // Fixed discount
    $discount = min((float)$coupon['value'], $amount); // can't discount more than deposit
}

$discount     = round($discount, 2);
$charged_amt  = round($amount - $discount, 2);
$charged_amt  = max(1, $charged_amt); // minimum ₹1 charge

// Re-calc actual discount (if capped by min charge rule)
$actual_discount = round($amount - $charged_amt, 2);

// Add gateway fee on top of charged amount
$gateway_fee_pct = (float)get_setting('payment_gateway_fee_pct', '2');
$gateway_fee      = round($charged_amt * $gateway_fee_pct / 100, 2);
$total_charge     = round($charged_amt + $gateway_fee, 2);

echo json_encode([
    'ok'           => true,
    'coupon_id'    => (int)$coupon['id'],
    'code'         => $coupon['code'],
    'type'         => $coupon['type'],
    'value'        => (float)$coupon['value'],
    'description'  => $coupon['description'] ?? '',
    'deposit_amt'  => $amount,       // wallet credit (full amount)
    'discount_amt' => $actual_discount,
    'charged_amt'  => $charged_amt,  // what user pays
    'gateway_fee'  => $gateway_fee,
    'total_charge' => $total_charge, // final Razorpay amount
    'msg'          => '🎉 Coupon applied! You pay ₹'.number_format($charged_amt,2).' but get ₹'.number_format($amount,2).' in wallet.',
]);
