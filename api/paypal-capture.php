<?php
/**
 * api/paypal-capture.php
 * Captures an approved PayPal order and credits user wallet.
 *
 * POST { order_id: '...', csrf: '...' }
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/servers.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'error'=>'POST only']); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?? [];
if (!verify_csrf($body['csrf'] ?? '')) { echo json_encode(['ok'=>false,'error'=>'Invalid CSRF']); exit; }

$client_id     = get_setting('paypal_client_id', '');
$client_secret = get_setting('paypal_client_secret', '');
$mode          = get_setting('paypal_mode', 'sandbox');
$base_url      = $mode === 'live'
    ? 'https://api-m.paypal.com'
    : 'https://api-m.sandbox.paypal.com';

$user     = current_user();
$uid      = (int)$user['id'];
$order_id = trim($body['order_id'] ?? '');

if (!$order_id) { echo json_encode(['ok'=>false,'error'=>'Order ID required']); exit; }

// Prevent double-capture
$already = db()->prepare("SELECT id FROM transactions WHERE ref_type='paypal' AND ref_id=? LIMIT 1");
$already->execute([$order_id]);
if ($already->fetch()) { echo json_encode(['ok'=>false,'error'=>'Already processed']); exit; }

// ── Get access token ──────────────────────────────────────────
$ch = curl_init($base_url . '/v1/oauth2/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
    CURLOPT_USERPWD => $client_id . ':' . $client_secret,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT => 20,
]);
$token_resp   = json_decode(curl_exec($ch), true);
curl_close($ch);
$access_token = $token_resp['access_token'] ?? '';
if (!$access_token) { echo json_encode(['ok'=>false,'error'=>'PayPal auth failed']); exit; }

// ── Capture order ─────────────────────────────────────────────
$ch = curl_init($base_url . '/v2/checkout/orders/' . urlencode($order_id) . '/capture');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => '{}',
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $access_token,
    ],
    CURLOPT_TIMEOUT => 20,
]);
$resp = json_decode(curl_exec($ch), true);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 201 || ($resp['status'] ?? '') !== 'COMPLETED') {
    $err = $resp['message'] ?? 'PayPal capture failed';
    error_log('[paypal-capture] ' . json_encode($resp));
    echo json_encode(['ok'=>false,'error'=>$err]); exit;
}

// Extract wallet amount from custom_id (uid:wallet_amt:currency)
$custom_id = $resp['purchase_units'][0]['payments']['captures'][0]['custom_id'] ?? '';
[$order_uid, $wallet_amt, $wallet_curr] = array_pad(explode(':', $custom_id), 3, '');
$wallet_amt = (float)$wallet_amt;

if ((int)$order_uid !== $uid || $wallet_amt <= 0) {
    echo json_encode(['ok'=>false,'error'=>'Payment verification failed']); exit;
}

// ── Credit wallet ─────────────────────────────────────────────
try {

    wallet_credit(
        (int)$user['id'],
        $wallet_amt,
        'Wallet top-up via PayPal | Order: ' . $order_id,
        'paypal'
    );

} catch (Throwable $e) {

    error_log('[paypal-capture] wallet_credit FAILED: ' . $e->getMessage());

    echo json_encode([
        'ok'    => false,
        'error' => 'Payment verified but wallet credit failed. Please contact support with: ' . $order_id
    ]);

    exit;
}

echo json_encode(['ok'=>true,'amount'=>$wallet_amt]);
