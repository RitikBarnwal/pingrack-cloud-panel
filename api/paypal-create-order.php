<?php
/**
 * api/paypal-create-order.php
 * Creates a PayPal order for wallet top-up.
 *
 * POST { amount: 20, csrf: '...' }
 * Returns { ok: true, order_id: '...' }
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'error'=>'POST only']); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?? [];
if (!verify_csrf($body['csrf'] ?? '')) { echo json_encode(['ok'=>false,'error'=>'Invalid CSRF']); exit; }

$client_id     = get_setting('paypal_client_id', '');
$client_secret = get_setting('paypal_client_secret', '');
$mode          = get_setting('paypal_mode', 'sandbox');
$pp_on         = get_setting('paypal_enabled', '0') === '1' && !empty($client_id) && !empty($client_secret);

if (!$pp_on) { echo json_encode(['ok'=>false,'error'=>'PayPal not configured']); exit; }

$base_url = $mode === 'live'
    ? 'https://api-m.paypal.com'
    : 'https://api-m.sandbox.paypal.com';

$user     = current_user();
$uid      = (int)$user['id'];
$currency = strtoupper($user['currency'] ?? 'USD');

// PayPal only supports certain currencies — fallback to USD for INR
$pp_currency  = in_array($currency, ['USD','EUR','GBP','AUD','CAD']) ? $currency : 'USD';
$amount       = (float)($body['amount'] ?? 0);
$fee_pct      = (float)get_setting('paypal_fee_pct', '3.5');
$total_charge = round($amount * (1 + $fee_pct/100), 2);

if ($amount <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid amount']); exit; }

// ── Get access token ──────────────────────────────────────────
$ch = curl_init($base_url . '/v1/oauth2/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
    CURLOPT_USERPWD        => $client_id . ':' . $client_secret,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT        => 20,
]);
$token_resp = json_decode(curl_exec($ch), true);
curl_close($ch);

$access_token = $token_resp['access_token'] ?? '';
if (!$access_token) {
    echo json_encode(['ok'=>false,'error'=>'PayPal auth failed. Check credentials.']); exit;
}

// ── Create order ──────────────────────────────────────────────
$order_payload = json_encode([
    'intent' => 'CAPTURE',
    'purchase_units' => [[
        'amount'      => ['currency_code' => $pp_currency, 'value' => number_format($total_charge, 2, '.', '')],
        'description' => 'Wallet top-up — ' . APP_NAME,
        'custom_id'   => $uid . ':' . $amount . ':' . $currency,
    ]],
    'application_context' => ['shipping_preference' => 'NO_SHIPPING'],
]);

$ch = curl_init($base_url . '/v2/checkout/orders');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $order_payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $access_token,
    ],
    CURLOPT_TIMEOUT => 20,
]);
$resp = json_decode(curl_exec($ch), true);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 201 || empty($resp['id'])) {
    $err = $resp['message'] ?? 'PayPal order creation failed';
    error_log('[paypal-create] ' . json_encode($resp));
    echo json_encode(['ok'=>false,'error'=>$err]); exit;
}

echo json_encode(['ok'=>true,'order_id'=>$resp['id']]);
