<?php
/**
 * api/stripe-verify.php
 * Verifies a completed Stripe PaymentIntent and credits user wallet.
 *
 * POST { payment_intent_id: 'pi_xxx', csrf: '...' }
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'error'=>'POST only']); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?? [];
if (!verify_csrf($body['csrf'] ?? '')) { echo json_encode(['ok'=>false,'error'=>'Invalid CSRF']); exit; }

$secret_key = get_setting('stripe_secret_key', '');
if (!$secret_key) { echo json_encode(['ok'=>false,'error'=>'Stripe not configured']); exit; }

$user           = current_user();
$uid            = (int)$user['id'];
$pi_id          = trim($body['payment_intent_id'] ?? '');

if (!$pi_id || !str_starts_with($pi_id, 'pi_')) {
    echo json_encode(['ok'=>false,'error'=>'Invalid PaymentIntent ID']); exit;
}

// Prevent double-credit
$already = db()->prepare(
    "SELECT id FROM transactions WHERE ref_type='stripe' AND ref_id=? AND status='success' LIMIT 1"
);
$already->execute([$pi_id]);
if ($already->fetch()) {
    echo json_encode(['ok'=>false,'error'=>'Payment already processed']); exit;
}

// Fetch PaymentIntent from Stripe
$ch = curl_init('https://api.stripe.com/v1/payment_intents/' . urlencode($pi_id));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => $secret_key . ':',
    CURLOPT_TIMEOUT        => 20,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$pi = json_decode($resp, true);

if ($code !== 200 || empty($pi['id'])) {
    echo json_encode(['ok'=>false,'error'=>'Could not fetch payment details from Stripe']); exit;
}

if ($pi['status'] !== 'succeeded') {
    echo json_encode(['ok'=>false,'error'=>'Payment not completed (status: ' . $pi['status'] . ')']); exit;
}

// Verify belongs to this user
$pi_uid = (int)($pi['metadata']['user_id'] ?? 0);
if ($pi_uid !== $uid) {
    echo json_encode(['ok'=>false,'error'=>'Payment user mismatch']); exit;
}

// Amount to credit (the wallet_amt we set in metadata)
$wallet_amt = (float)($pi['metadata']['wallet_amt'] ?? 0);
$pi_currency = strtoupper($pi['metadata']['currency'] ?? $user['currency']);

if ($wallet_amt <= 0) {
    echo json_encode(['ok'=>false,'error'=>'Invalid payment amount']); exit;
}

// Credit wallet
$ok = wallet_credit($uid, $wallet_amt, 'Stripe payment', 'stripe', $pi_id);
if (!$ok) {
    echo json_encode(['ok'=>false,'error'=>'Wallet credit failed']); exit;
}

// Update pending transaction to success
try {
    db()->prepare(
        "UPDATE transactions SET status='success', ref_type='stripe', ref_id=? WHERE user_id=? AND ref_type='stripe_intent' AND ref_id=? LIMIT 1"
    )->execute([$pi_id, $uid, $pi_id]);
} catch (Throwable $e) {}

// Log successful transaction if not already exists
try {
    $ex = db()->prepare("SELECT id FROM transactions WHERE ref_type='stripe' AND ref_id=? LIMIT 1");
    $ex->execute([$pi_id]);
    if (!$ex->fetch()) {
        db()->prepare(
            'INSERT INTO transactions (user_id,type,amount,currency,ref_type,ref_id,note,status,created_at)
             VALUES (?,?,?,?,?,?,?,?,NOW())'
        )->execute([$uid,'credit',$wallet_amt,$pi_currency,'stripe',$pi_id,'Wallet top-up via Stripe','success']);
    }
} catch (Throwable $e) {}

echo json_encode(['ok'=>true,'amount'=>$wallet_amt]);
