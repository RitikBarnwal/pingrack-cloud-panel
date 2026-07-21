<?php
/**
 * api/stripe-create-intent.php
 * Creates a Stripe PaymentIntent for wallet top-up.
 *
 * POST { amount: 500, csrf: '...', coupon: '' }
 * Returns { ok: true, client_secret: 'pi_xxx_secret_yyy' }
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'error'=>'POST only']); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?? [];
if (!verify_csrf($body['csrf'] ?? '')) { echo json_encode(['ok'=>false,'error'=>'Invalid CSRF']); exit; }

// Config
$secret_key  = get_setting('stripe_secret_key', '');
$stripe_on   = get_setting('stripe_enabled', '0') === '1' && !empty($secret_key);
if (!$stripe_on) { echo json_encode(['ok'=>false,'error'=>'Stripe not configured']); exit; }

$user     = current_user();
$currency = strtoupper($user['currency'] ?? 'INR');
$uid      = (int)$user['id'];

$amount   = (float)($body['amount'] ?? 0);
$fee_pct  = (float)get_setting('stripe_fee_pct', '2.9');
$min      = (float)get_setting('min_deposit', '100');

if ($amount <= 0 || $amount < $min) {
    echo json_encode(['ok'=>false,'error'=>"Minimum deposit: {$min}"]); exit;
}

// Apply gateway fee
$fee_amount   = round($amount * $fee_pct / 100, 2);
$total_charge = round($amount + $fee_amount, 2);

// Stripe uses smallest currency unit (paise for INR, cents for USD)
$stripe_currency = strtolower($currency);
$stripe_amount   = (int)round($total_charge * 100); // paise/cents

// Call Stripe API via cURL (no SDK dependency)
$payload = http_build_query([
    'amount'               => $stripe_amount,
    'currency'             => $stripe_currency,
    'description'          => 'Wallet top-up — ' . APP_NAME,
    'metadata[user_id]'    => $uid,
    'metadata[wallet_amt]' => $amount,
    'metadata[currency]'   => $currency,
]);

$ch = curl_init('https://api.stripe.com/v1/payment_intents');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_USERPWD        => $secret_key . ':',
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT        => 20,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($resp, true);

if ($code !== 200 || empty($data['client_secret'])) {
    $err = $data['error']['message'] ?? 'Stripe error';
    error_log('[stripe-create] ' . $err);
    echo json_encode(['ok'=>false,'error'=>$err]); exit;
}

// Store pending payment reference
try {
    db()->prepare(
        'INSERT INTO transactions (user_id, type, amount, currency, ref_type, ref_id, note, status, created_at)
         VALUES (?,?,?,?,?,?,?,?,NOW())'
    )->execute([$uid, 'credit', $amount, $currency, 'stripe_intent', $data['id'], 'Wallet top-up via Stripe', 'pending']);
} catch (Throwable $e) {}

echo json_encode([
    'ok'            => true,
    'client_secret' => $data['client_secret'],
    'amount'        => $amount,
]);
