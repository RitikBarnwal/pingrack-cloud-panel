<?php
/**
 * Google OAuth Login Handler (AJAX)
 * Called from login.php when user clicks "Continue with Google"
 */
require_once __DIR__ . '/bootstrap.php';
session_start_safe();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']); exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Invalid request token.']); exit;
}

if (get_setting('google_signin_enabled','0') !== '1') {
    echo json_encode(['ok' => false, 'error' => 'Google Sign-In is disabled.']); exit;
}

$googleToken = trim($_POST['google_token'] ?? '');
$googleEmail = strtolower(trim($_POST['google_email'] ?? ''));
$googleSub   = trim($_POST['google_sub'] ?? '');

if (empty($googleToken) || empty($googleEmail) || empty($googleSub)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid Google account data.']); exit;
}

// Verify token with Google
function gauth_curl(string $url, string $token): ?array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        ]);
        $res = curl_exec($ch); curl_close($ch);
        return $res ? json_decode($res, true) : null;
    }
    $ctx = stream_context_create(['http'=>['timeout'=>8,'header'=>'Authorization: Bearer '.$token],'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
    $res = @file_get_contents($url, false, $ctx);
    return $res ? json_decode($res, true) : null;
}

$gData = gauth_curl('https://www.googleapis.com/oauth2/v3/userinfo', $googleToken);
if (empty($gData['email']) || strtolower($gData['email']) !== $googleEmail) {
    echo json_encode(['ok' => false, 'error' => 'Google account verification failed.']); exit;
}

// Find user
$st = db()->prepare('SELECT * FROM users WHERE email=? OR google_sub=? LIMIT 1');
$st->execute([$googleEmail, $googleSub]);
$user = $st->fetch();

if (!$user) {
    echo json_encode([
        'ok'     => false,
        'error'  => 'No account found with this Google account. Please register first.',
        'action' => 'register'
    ]); exit;
}

if (($user['status'] ?? '') === 'banned') {
    echo json_encode(['ok' => false, 'error' => 'Your account has been suspended. Contact support.']); exit;
}

// Save google_sub if missing
if (empty($user['google_sub'])) {
    db()->prepare('UPDATE users SET google_sub=? WHERE id=?')->execute([$googleSub, $user['id']]);
}

// 2FA check
if (!empty($user['totp_enabled'])) {
    $method = $user['twofa_method'] ?? 'totp';
    $_SESSION['2fa_pending_user_id'] = $user['id'];
    if ($method === 'email') {
        require_once __DIR__ . '/email_otp.php';
        $_SESSION['2fa_pending_email'] = $user['email'];
        $res = EmailOTP::sendToUser($user);
        if ($res['ok']) $_SESSION['2fa_email_sent'] = true;
    }
    echo json_encode(['ok' => true, 'redirect' => BASE_URL . '/login.php']); exit;
}

login_user($user);
echo json_encode([
    'ok'       => true,
    'redirect' => BASE_URL . (empty($user['onboarded']) ? '/onboarding.php' : '/dashboard.php')
]);
exit;
