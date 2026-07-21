<?php
/**
 * api/razorpay-verify.php
 * Verifies Razorpay payment signature and credits wallet.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/servers.php';
require_once __DIR__ . '/../includes/currency.php';
require_once __DIR__ . '/../includes/mailer_invoice.php';

header('Content-Type: application/json');

// Must be POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok'=>false,'error'=>'POST required']); exit;
}

// Auth
require_login();
$user = current_user();

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$csrf = $body['csrf'] ?? $body['csrf_token'] ?? '';

if (!verify_csrf($csrf)) {
    echo json_encode(['ok'=>false,'error'=>'Invalid CSRF token']); exit;
}

$payment_id  = trim($body['razorpay_payment_id'] ?? '');
$order_id    = trim($body['razorpay_order_id']   ?? '');
$signature   = trim($body['razorpay_signature']   ?? '');
// NOTE: amounts are NEVER trusted from the client. They are read server-side
// from the Razorpay order below (created in razorpay-create-order.php with
// the true wallet_amt/charged_amt/coupon stored in order `notes`).

// Validate inputs
if (!$payment_id || !$order_id || !$signature) {
    error_log('[rzp-verify] Missing fields: payment='.$payment_id.' order='.$order_id);
    echo json_encode(['ok'=>false,'error'=>'Missing payment details. Please contact support with: '.$payment_id]); exit;
}

// Get Razorpay credentials
$rzp_key_id = get_setting('razorpay_key_id', '');
$rzp_secret = get_setting('razorpay_key_secret', '');
if (!$rzp_secret || !$rzp_key_id) {
    error_log('[rzp-verify] razorpay keys not configured');
    echo json_encode(['ok'=>false,'error'=>'Payment gateway not configured on server.']); exit;
}

// ── Verify HMAC-SHA256 signature ──────────────────────────────
$expected = hash_hmac('sha256', $order_id . '|' . $payment_id, $rzp_secret);

if (!hash_equals($expected, $signature)) {
    error_log('[rzp-verify] Signature MISMATCH. order='.$order_id.' payment='.$payment_id);
    echo json_encode(['ok'=>false,'error'=>'Payment signature verification failed. Contact support with payment ID: '.$payment_id]); exit;
}

// ── Fetch the order from Razorpay and derive amounts SERVER-SIDE ───────────
// This is the security-critical step: the credited amount comes from the
// order we created (notes), verified as actually paid — not from the client.
$ch = curl_init('https://api.razorpay.com/v1/orders/' . urlencode($order_id));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => $rzp_key_id . ':' . $rzp_secret,
    CURLOPT_TIMEOUT        => 20,
]);
$order_raw  = curl_exec($ch);
$order_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$order = json_decode($order_raw, true) ?? [];

if ($order_code !== 200 || empty($order['id'])) {
    error_log('[rzp-verify] order fetch failed HTTP '.$order_code.' '.substr((string)$order_raw,0,300));
    echo json_encode(['ok'=>false,'error'=>'Could not verify order. Contact support with payment ID: '.$payment_id]); exit;
}

// Order must be paid
if (($order['status'] ?? '') !== 'paid' || (int)($order['amount_paid'] ?? 0) <= 0) {
    error_log('[rzp-verify] order not paid. order='.$order_id.' status='.($order['status'] ?? ''));
    echo json_encode(['ok'=>false,'error'=>'Payment not completed. Contact support with payment ID: '.$payment_id]); exit;
}

// Order must belong to the authenticated user
$notes = $order['notes'] ?? [];
if ((int)($notes['user_id'] ?? 0) !== (int)$user['id']) {
    error_log('[rzp-verify] user mismatch. order='.$order_id.' order_uid='.($notes['user_id'] ?? '').' session_uid='.$user['id']);
    echo json_encode(['ok'=>false,'error'=>'Payment verification failed (owner mismatch).']); exit;
}

// Trusted, server-side amounts (from the order we created)
$wallet_amt   = (float)($notes['wallet_amt']   ?? 0);
$charged_amt  = (float)($notes['charged_amt']  ?? $wallet_amt);
$gateway_fee  = (float)($notes['gateway_fee']  ?? 0);
$discount_amt = (float)($notes['discount_amt'] ?? 0);
$coupon_id    = (int)($notes['coupon_id']      ?? 0);

if ($wallet_amt <= 0) {
    echo json_encode(['ok'=>false,'error'=>'Invalid wallet amount on order']); exit;
}

// ── Idempotency: check if already processed ───────────────────
try {
    $check = db()->prepare("SELECT id FROM transactions WHERE description LIKE ? LIMIT 1");
    $check->execute(['%'.$payment_id.'%']);
    if ($check->fetch()) {
        echo json_encode(['ok'=>true,'message'=>'Payment already processed. Check your balance.','already'=>true]); exit;
    }
} catch (Throwable $e) {
    // If transactions table doesn't have this column, continue
    error_log('[rzp-verify] idempotency check error: '.$e->getMessage());
}

// ── Credit wallet ─────────────────────────────────────────────
try {
    wallet_credit(
        (int)$user['id'],
        $wallet_amt,
        'Wallet top-up via Razorpay | Payment: ' . $payment_id,
        'topup'
    );
} catch (Throwable $e) {
    error_log('[rzp-verify] wallet_credit FAILED: '.$e->getMessage());
    echo json_encode(['ok'=>false,'error'=>'Payment verified but wallet credit failed. Please contact support with: '.$payment_id]); exit;
}

// ── Generate invoice + send email (non-fatal) ─────────────────
try {
    require_once __DIR__ . '/../includes/mailer_invoice.php';
    $fresh_user = current_user();
    // coupon_id, discount_amt, charged_amt pass karo taaki invoice sahi bane
    $result = process_wallet_topup($fresh_user, (float)$wallet_amt, $payment_id, [
        'coupon_id'    => $coupon_id,
        'discount_amt' => $discount_amt,
        'charged_amt'  => $charged_amt,
    ]);
} catch (Throwable $e) {
    error_log('[rzp-verify] Invoice/email error (non-fatal): '.$e->getMessage());
}

// ── Record coupon use ─────────────────────────────────────────
if ($coupon_id > 0) {
    try {
        db()->prepare(
            'INSERT IGNORE INTO coupon_uses (coupon_id, user_id, payment_id, deposit_amt, discount_amt, charged_amt, wallet_amt)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$coupon_id, $user['id'], $payment_id, $wallet_amt, $discount_amt, $charged_amt, $wallet_amt]);
        // Increment used_count
        db()->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE id=?')->execute([$coupon_id]);
    } catch (Throwable $e) {
        error_log('[rzp-verify] coupon record error (non-fatal): '.$e->getMessage());
    }
}

// ── Referral bonus on first topup ────────────────────────────
// Agar reward_on = 'topup' hai aur user ke liye pending referral hai,
// aur yeh unka FIRST topup hai jo minimum amount meet karta hai
try {
    $ref_reward_on  = get_setting('referral_reward_on', 'register');
    if ($ref_reward_on === 'topup') {
        // Check: is this user's FIRST deposit?
        $prev_topups = db()->prepare(
            "SELECT COUNT(*) FROM transactions WHERE user_id=? AND type='topup' AND id != LAST_INSERT_ID()"
        );
        $prev_topups->execute([(int)$user['id']]);
        $topup_count = (int)$prev_topups->fetchColumn();

        if ($topup_count <= 1) { // <= 1 because current topup may already be recorded
            // Check pending referral for this user (they were referred)
            $ref_row_stmt = db()->prepare(
                "SELECT r.*, u.currency as referrer_currency
                 FROM referrals r
                 JOIN users u ON u.id = r.referrer_id
                 WHERE r.referee_id = ? AND r.status = 'pending'
                 LIMIT 1"
            );
            $ref_row_stmt->execute([(int)$user['id']]);
            $ref_row = $ref_row_stmt->fetch();

            if ($ref_row) {
                // ── Per-currency minimum deposit check ──────────────────────
                $referee_currency = strtoupper($user['currency'] ?? 'INR');
                $referrer_id      = (int)$ref_row['referrer_id'];
                $referee_id       = (int)$user['id'];

                // Min required is based on referee's own currency
                if ($referee_currency === 'USD') {
                    $min_required = (float)get_setting('referral_min_topup_usd', '0');
                } else {
                    $min_required = (float)get_setting('referral_min_topup_inr',
                                        get_setting('referral_min_topup', '0'));
                }

                if ($min_required <= 0 || $wallet_amt >= $min_required) {

                    // Referrer's currency (already fetched in JOIN above)
                    $referrer_currency = strtoupper($ref_row['referrer_currency'] ?? 'INR');

                    // Referrer bonus in THEIR currency
                    if ($referrer_currency === 'USD') {
                        $bonus_referrer = (float)get_setting('referral_bonus_referrer_usd', '10');
                    } else {
                        $bonus_referrer = (float)get_setting('referral_bonus_referrer_inr',
                                              get_setting('referral_bonus_referrer', '100'));
                    }

                    // Referee bonus in THEIR currency
                    if ($referee_currency === 'USD') {
                        $bonus_referee = (float)get_setting('referral_bonus_referee_usd', '5');
                    } else {
                        $bonus_referee = (float)get_setting('referral_bonus_referee_inr',
                                              get_setting('referral_bonus_referee', '50'));
                    }

                    // Update record with correct amounts + dual currency tag
                    $ref_currency_str = $referrer_currency . '|' . $referee_currency;
                    db()->prepare(
                        "UPDATE referrals SET referrer_bonus=?, referee_bonus=?, currency=? WHERE referee_id=? AND status='pending'"
                    )->execute([$bonus_referrer, $bonus_referee, $ref_currency_str, $referee_id]);

                    // Credit referrer in their currency
                    wallet_credit(
                        $referrer_id,
                        $bonus_referrer,
                        'Referral bonus - friend made first deposit (' . ($user['full_name'] ?? 'user') . ')',
                        'referral',
                        $referee_id
                    );

                    // Credit referee in their currency
                    wallet_credit(
                        $referee_id,
                        $bonus_referee,
                        'Welcome bonus - referred by a friend',
                        'referral',
                        $referrer_id
                    );

                    // Mark rewarded
                    db()->prepare(
                        "UPDATE referrals SET status='rewarded', rewarded_at=NOW() WHERE referee_id=? AND status='pending'"
                    )->execute([$referee_id]);

                    error_log('[rzp-verify] Referral rewarded: referrer='.$referrer_id
                              .'('.$referrer_currency.')='.$bonus_referrer
                              .' | referee='.$referee_id.'('.$referee_currency.')='.$bonus_referee);
                }
            }
        }
    }
} catch (Throwable $re) {
    error_log('[rzp-verify] Referral bonus error (non-fatal): ' . $re->getMessage());
}

// ── Done ──────────────────────────────────────────────────────
error_log('[rzp-verify] SUCCESS user='.$user['id'].' amount='.$wallet_amt.' payment='.$payment_id);

echo json_encode([
    'ok'        => true,
    'message'   => 'Payment successful! ₹'.number_format($wallet_amt, 2).' added to your wallet.',
    'wallet_amt'=> $wallet_amt,
]);