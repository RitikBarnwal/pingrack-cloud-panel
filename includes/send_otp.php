<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mailer_invoice.php';

header('Content-Type: application/json');
session_start_safe();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error'=>'Method not allowed']); exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    echo json_encode(['error'=>'Invalid CSRF']); exit;
}

$email    = strtolower(trim($_POST['email'] ?? ''));
$mode     = $_POST['mode'] ?? 'reg';
$isResend = isset($_POST['resend']);
$accType  = $_POST['account_type'] ?? 'individual';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['error'=>'Invalid email address']); exit;
}

/* =========================
   DOMAIN
========================= */
$domain = strtolower(trim(substr(strrchr($email, "@"), 1)));

/* =========================
   DISPOSABLE BLOCK
========================= */
$list = @file_get_contents('https://raw.githubusercontent.com/disposable/disposable-email-domains/master/domains.json');

if ($list !== false) {
    $domains = json_decode($list, true);
    if (is_array($domains) && in_array($domain, $domains, true)) {
        echo json_encode(['error'=>'Temporary email not allowed']);
        exit;
    }
}

/* =========================
   BUSINESS EMAIL ONLY (ORG)
========================= */
if ($mode === 'reg' && $accType === 'organization') {
    $freeDomains = ['gmail.com','yahoo.com','hotmail.com','outlook.com','icloud.com'];
    if (in_array($domain, $freeDomains, true)) {
        echo json_encode(['error'=>'Use business email']);
        exit;
    }
}

/* =========================
   RESEND COOLDOWN (60s)
========================= */
$coolKey = 'otp_sent_' . md5($email . $mode);

if ($isResend) {
    if (isset($_SESSION[$coolKey]) && (time() - $_SESSION[$coolKey]) < 60) {
        $wait = 60 - (time() - $_SESSION[$coolKey]);
        echo json_encode(['error'=>"Please wait {$wait}s"]);
        exit;
    }
}

/* =========================
   VALIDATION
========================= */
if ($mode === 'forgot') {
    $st = db()->prepare('SELECT id, username FROM users WHERE email=? LIMIT 1');
    $st->execute([$email]);
    $row = $st->fetch();

    if (!$row) {
        echo json_encode(['error'=>'No account found']); exit;
    }
    $name = $row['username'];
} else {
    $st = db()->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
    $st->execute([$email]);

    if ($st->fetch()) {
        echo json_encode(['error'=>'Email already registered']); exit;
    }
    $name = explode('@',$email)[0];
}

/* =========================
   🚫 DB RATE LIMIT (IMPORTANT)
========================= */
list($ok,$wait) = check_otp_limit($email);

if (!$ok) {
    echo json_encode([
        'error'=>'Quota exceeded. Try after '.ceil($wait/60).' minutes'
    ]);
    exit;
}

/* =========================
   GENERATE OTP
========================= */
$otp = str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);

$_SESSION['otp_'.$mode.'_'.md5($email)] = [
    'hash'    => password_hash($otp, PASSWORD_BCRYPT),
    'email'   => $email,
    'expires' => time() + 600
];

$_SESSION[$coolKey] = time();

/* =========================
   SEND EMAIL
========================= */
$subject = APP_NAME . ' - Your ' . ($mode === 'forgot' ? 'password reset' : 'verification') . ' code';

$ok = send_otp_email($email, $name, $otp, $subject);

if (!$ok) {
    echo json_encode(['error'=>'Failed to send email']); exit;
}

echo json_encode(['success'=>true]);