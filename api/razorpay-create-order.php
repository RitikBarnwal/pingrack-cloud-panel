<?php
/**
 * api/razorpay-create-order.php
 * Creates a Razorpay order for wallet top-up.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/currency.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok'=>false,'error'=>'POST required']); exit;
}

$body       = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$amount     = (float)($body['amount']      ?? 0);
$csrf       = $body['csrf'] ?? $body['csrf_token'] ?? '';
$coupon_code= strtoupper(trim($body['coupon_code'] ?? ''));

if (!verify_csrf($csrf)) { echo json_encode(['ok'=>false,'error'=>'Invalid token']); exit; }

$user     = current_user();
$currency = strtoupper($user['currency'] ?? 'INR');
$min_dep_inr = (float)get_setting('min_deposit', '100'); // Stored in INR

// Convert min deposit to user's currency for validation
if ($currency === 'INR') {
    $min_dep = $min_dep_inr;
} else {
    require_once __DIR__ . '/../includes/currency.php';
    $inr_usd = get_rate('INR', 'USD');
    $min_dep_usd = round($min_dep_inr * $inr_usd, 2);
    if ($currency === 'USD') {
        $min_dep = max($min_dep_usd, 1.0);
    } else {
        $min_dep = max(round($min_dep_usd * get_rate('USD', $currency), 2), 1.0);
    }
}

if ($amount < $min_dep) {
    $sym = match($currency) { 'INR' => '₹', 'EUR' => '€', 'GBP' => '£', default => '$' };
    echo json_encode(['ok'=>false,'error'=>'Minimum deposit is '.$sym.number_format($min_dep,2)]); exit;
}

// Get Razorpay settings
$rzp_key_id  = get_setting('razorpay_key_id', '');
$rzp_secret  = get_setting('razorpay_key_secret', '');
$gateway_fee_pct = (float)get_setting('payment_gateway_fee_pct', '2');

if (!$rzp_key_id || !$rzp_secret) {
    echo json_encode(['ok'=>false,'error'=>'Payment gateway not configured.']); exit;
}

// ── Apply coupon if provided ──────────────────────────────
$coupon       = null;
$discount_amt = 0.0;
$charged_amt  = $amount; // default: user pays full amount

if ($coupon_code) {
    $st = db()->prepare('SELECT * FROM coupons WHERE code=? AND is_active=1 LIMIT 1');
    $st->execute([$coupon_code]);
    $coupon = $st->fetch();

    if ($coupon) {
        // Validate again server-side
        $valid = true;
        if ($coupon['expires_at'] && strtotime($coupon['expires_at']) < time()) $valid = false;
        if ($coupon['max_uses'] !== null && $coupon['used_count'] >= $coupon['max_uses']) $valid = false;
        if ($amount < (float)$coupon['min_deposit']) $valid = false;
        $used_check = db()->prepare('SELECT id FROM coupon_uses WHERE coupon_id=? AND user_id=? LIMIT 1');
        $used_check->execute([$coupon['id'], $user['id']]);
        if ($used_check->fetch()) $valid = false;

        if ($valid) {
            if ($coupon['type'] === 'percentage') {
                $discount_amt = round($amount * (float)$coupon['value'] / 100, 2);
                if ($coupon['max_discount'] !== null) $discount_amt = min($discount_amt, (float)$coupon['max_discount']);
            } else {
                $discount_amt = min((float)$coupon['value'], $amount);
            }
            $discount_amt = round($discount_amt, 2);
            $charged_amt  = max(1, round($amount - $discount_amt, 2));
            $discount_amt = round($amount - $charged_amt, 2); // recalc if capped
        } else {
            $coupon = null; // invalid, ignore
        }
    }
}

// ── Calculate gateway fee on CHARGED amount only ──────────
$gateway_fee  = round($charged_amt * $gateway_fee_pct / 100, 2);
$total_charge = round($charged_amt + $gateway_fee, 2);

// Razorpay expects amount in paise (INR) or cents
$amount_minor = (int)round($total_charge * 100);

// Create Razorpay order via API
$order_data = [
    'amount'   => $amount_minor,
    'currency' => $currency,
    'receipt'  => 'wallet_'.time().'_'.$user['id'],
    'notes'    => [
        'user_id'      => $user['id'],
        'wallet_amt'   => $amount,       // full amount credited to wallet
        'charged_amt'  => $charged_amt,  // actual amount charged
        'gateway_fee'  => $gateway_fee,
        'discount_amt' => $discount_amt,
        'coupon_code'  => $coupon ? $coupon['code'] : '',
        'coupon_id'    => $coupon ? $coupon['id']   : '',
    ],
];

$ch = curl_init('https://api.razorpay.com/v1/orders');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($order_data),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_USERPWD        => $rzp_key_id . ':' . $rzp_secret,
    CURLOPT_TIMEOUT        => 30,
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$resp = json_decode($raw, true) ?? [];

if ($code !== 200 || empty($resp['id'])) {
    error_log('[razorpay-create-order] HTTP '.$code.' '.substr($raw,0,300));
    echo json_encode(['ok'=>false,'error'=>$resp['error']['description'] ?? 'Could not create payment order.']);
    exit;
}

echo json_encode([
    'ok'           => true,
    'order_id'     => $resp['id'],
    'amount'       => $amount_minor,
    'currency'     => $currency,
    'wallet_amt'   => $amount,
    'charged_amt'  => $charged_amt,
    'gateway_fee'  => $gateway_fee,
    'discount_amt' => $discount_amt,
    'total'        => $total_charge,
    'coupon_id'    => $coupon ? (int)$coupon['id'] : null,
    'coupon_code'  => $coupon ? $coupon['code']    : null,
    'rzp_key'      => $rzp_key_id,
    'user_name'    => $user['full_name'] ?: $user['username'],
    'user_email'   => $user['email'],
    'user_phone'   => $user['phone'] ?? '',
]);
