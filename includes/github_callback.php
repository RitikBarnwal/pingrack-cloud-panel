<?php
/**
 * GitHub OAuth Callback Handler
 * Handles both login and register flows
 */
require_once __DIR__ . '/bootstrap.php';
session_start_safe();

$action = $_GET['action'] ?? 'login'; // 'login' | 'register'
$csrfIn = $_GET['csrf']   ?? '';
$code   = $_GET['code']   ?? '';
$state  = $_GET['state']  ?? '';

$clientId     = get_setting('github_client_id', '');
$clientSecret = get_setting('github_client_secret', '');

// ── Step 1: Redirect to GitHub if no code yet ─────────────────
if (empty($code)) {
    if (!$clientId) {
        header('Location: ' . BASE_URL . '/login.php?error=github_not_configured'); exit;
    }
    $stateToken = bin2hex(random_bytes(16));
    $_SESSION['gh_oauth_state']  = $stateToken;
    $_SESSION['gh_oauth_action'] = $action;
    $_SESSION['gh_oauth_csrf']   = $csrfIn;

    $params = http_build_query([
        'client_id'    => $clientId,
        'redirect_uri' => BASE_URL . '/includes/github_callback.php',
        'scope'        => 'read:user user:email',
        'state'        => $stateToken,
    ]);
    header('Location: https://github.com/login/oauth/authorize?' . $params);
    exit;
}

// ── Step 2: GitHub redirected back with code ──────────────────
// Validate state
if (empty($_SESSION['gh_oauth_state']) || $state !== $_SESSION['gh_oauth_state']) {
    header('Location: ' . BASE_URL . '/login.php?error=github_state_mismatch'); exit;
}
$action = $_SESSION['gh_oauth_action'] ?? 'login';
unset($_SESSION['gh_oauth_state'], $_SESSION['gh_oauth_action'], $_SESSION['gh_oauth_csrf']);

// Exchange code for access token
function gh_curl(string $url, array $postData = [], array $headers = []): ?array {
    $ch = curl_init($url);
    $defaultHeaders = ['Accept: application/json', 'User-Agent: GreatHost/1.0'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => array_merge($defaultHeaders, $headers),
    ]);
    if (!empty($postData)) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($defaultHeaders, ['Content-Type: application/json'], $headers));
    }
    $res = curl_exec($ch);
    curl_close($ch);
    return $res ? json_decode($res, true) : null;
}

$tokenData = gh_curl('https://github.com/login/oauth/access_token', [
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'code'          => $code,
    'redirect_uri'  => BASE_URL . '/includes/github_callback.php',
]);

if (empty($tokenData['access_token'])) {
    header('Location: ' . BASE_URL . '/login.php?error=github_token_failed'); exit;
}

$accessToken = $tokenData['access_token'];

// Fetch user profile
$profile = gh_curl('https://api.github.com/user', [], ['Authorization: Bearer ' . $accessToken]);
if (empty($profile['id'])) {
    header('Location: ' . BASE_URL . '/login.php?error=github_profile_failed'); exit;
}

// Fetch email (may be private)
$email = $profile['email'] ?? '';
if (empty($email)) {
    $emails = gh_curl('https://api.github.com/user/emails', [], ['Authorization: Bearer ' . $accessToken]);
    if (is_array($emails)) {
        foreach ($emails as $e) {
            if (!empty($e['primary']) && !empty($e['verified'])) { $email = $e['email']; break; }
        }
        if (empty($email)) foreach ($emails as $e) { if (!empty($e['email'])) { $email = $e['email']; break; } }
    }
}

$ghId    = (string)$profile['id'];
$ghName  = $profile['name']  ?? $profile['login'] ?? '';
$ghLogin = $profile['login'] ?? '';

// ── Login flow ────────────────────────────────────────────────
if ($action === 'login') {
    $st = db()->prepare('SELECT * FROM users WHERE github_id=? OR email=? LIMIT 1');
    $st->execute([$ghId, $email]);
    $user = $st->fetch();

    if (!$user) {
        // Not registered — redirect to register with pre-fill
        $params = http_build_query([
            'social'    => 'github',
            'gh_id'     => $ghId,
            'gh_name'   => $ghName,
            'gh_email'  => $email,
            'gh_login'  => $ghLogin,
        ]);
        header('Location: ' . BASE_URL . '/register.php?' . $params); exit;
    }

    if (($user['status'] ?? '') === 'banned') {
        header('Location: ' . BASE_URL . '/login.php?error=banned'); exit;
    }

    // Save github_id if missing
    if (empty($user['github_id'])) {
        db()->prepare('UPDATE users SET github_id=? WHERE id=?')->execute([$ghId, $user['id']]);
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
        header('Location: ' . BASE_URL . '/login.php'); exit;
    }

    login_user($user);
    header('Location: ' . BASE_URL . (empty($user['onboarded']) ? '/onboarding.php' : '/dashboard.php'));
    exit;
}

// ── Register flow — redirect to register page with pre-fill ──
$params = http_build_query([
    'social'   => 'github',
    'gh_id'    => $ghId,
    'gh_name'  => $ghName,
    'gh_email' => $email,
    'gh_login' => $ghLogin,
]);
header('Location: ' . BASE_URL . '/register.php?' . $params);
exit;
