<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/totp.php';
require_once __DIR__ . '/includes/email_otp.php';
session_start_safe();

if (is_logged_in()) { header('Location: '.BASE_URL.'/dashboard.php'); exit; }

$error        = '';
$success      = '';
$show_2fa     = false;
$twofa_method = 'totp';
$mode         = $_GET['mode'] ?? 'password'; // 'password' | 'otp'
$otp_step     = $_GET['step'] ?? ''; // 'verify'

// ══════════════════════════════════════════════════════════════
// POST HANDLER
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token.';

    // ── Resend 2FA email OTP ──────────────────────────────────
    } elseif (!empty($_POST['resend_email_otp']) && !empty($_SESSION['2fa_pending_user_id'])) {
        $userId = (int)$_SESSION['2fa_pending_user_id'];
        $st = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
        $st->execute([$userId]); $user = $st->fetch();
        if ($user) {
            $res = EmailOTP::sendToUser($user);
            $error = $res['ok'] ? '' : ($res['error'] ?? 'Could not resend.');
            if ($res['ok']) { $_SESSION['2fa_email_sent'] = true; $_SESSION['2fa_pending_email'] = $user['email']; }
        }
        $show_2fa = true; $twofa_method = 'email'; $mode = 'password';

    // ── 2FA verify (password login flow) ─────────────────────
    } elseif (!empty($_POST['totp_step']) && !empty($_SESSION['2fa_pending_user_id'])) {
        $userId = (int)$_SESSION['2fa_pending_user_id'];
        $st = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
        $st->execute([$userId]); $user = $st->fetch();
        if (!$user) {
            unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_email_sent']);
            $error = 'Session expired. Please login again.';
        } else {
            $method   = $user['twofa_method'] ?? 'totp';
            $code     = preg_replace('/\s+/', '', trim($_POST['totp_code'] ?? ''));
            $verified = false;
            if ($method === 'email') {
                $verified = EmailOTP::verify((int)$user['id'], $code);
            } else {
                if (strlen($code) === 6 && ctype_digit($code)) $verified = TOTP::verify($user['totp_secret'], $code);
                if (!$verified && !empty($user['totp_recovery'])) {
                    [$ok, $nj] = TOTP::useRecoveryCode($user['totp_recovery'], $code);
                    if ($ok) { $verified = true; db()->prepare('UPDATE users SET totp_recovery=? WHERE id=?')->execute([$nj,$userId]); }
                }
            }
            if ($verified) {
                unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_email_sent']);
                login_user($user);
                header('Location: '.BASE_URL.(empty($user['onboarded']) ? '/onboarding.php' : '/dashboard.php')); exit;
            } else {
                $error = $method === 'email' ? 'Invalid or expired OTP.' : 'Invalid code.';
                $show_2fa = true; $twofa_method = $method; $mode = 'password';
            }
        }

    // ── OTP Login: send OTP ────────────────────────────────────
    } elseif (!empty($_POST['otp_send'])) {
        $email = strtolower(trim($_POST['otp_email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
            $mode  = 'otp';
        } else {
            $st = db()->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
            $st->execute([$email]); $user = $st->fetch();
            if (!$user) {
                usleep(random_int(100000,250000));
                $error = 'No account found with this email.';
                $mode  = 'otp';
            } elseif (($user['status']??'') === 'banned') {
                $error = 'Your account has been banned. Contact support.';
                $mode  = 'otp';
            } else {
                $res = EmailOTP::sendToUser($user);
                if ($res['ok']) {
                    $_SESSION['otp_login_user_id'] = $user['id'];
                    $_SESSION['otp_login_email']   = $user['email'];
                    $_SESSION['otp_login_sent']    = true;
                    header('Location: '.BASE_URL.'/login.php?mode=otp&step=verify'); exit;
                } else {
                    $error = $res['error'] ?? 'Could not send OTP.';
                    $mode  = 'otp';
                }
            }
        }

    // ── OTP Login: resend OTP ──────────────────────────────────
    } elseif (!empty($_POST['otp_resend']) && !empty($_SESSION['otp_login_user_id'])) {
        $userId = (int)$_SESSION['otp_login_user_id'];
        $st = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
        $st->execute([$userId]); $user = $st->fetch();
        if ($user) {
            $res = EmailOTP::sendToUser($user);
            if ($res['ok']) { $_SESSION['otp_login_sent'] = true; $success = 'New code sent!'; }
            else $error = $res['error'] ?? 'Could not resend.';
        }
        $mode = 'otp'; $otp_step = 'verify';

    // ── OTP Login: verify OTP ─────────────────────────────────
    } elseif (!empty($_POST['otp_verify']) && !empty($_SESSION['otp_login_user_id'])) {
        $userId = (int)$_SESSION['otp_login_user_id'];
        $code   = preg_replace('/\s+/', '', trim($_POST['otp_code'] ?? ''));
        $st = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
        $st->execute([$userId]); $user = $st->fetch();
        if (!$user) {
            unset($_SESSION['otp_login_user_id'], $_SESSION['otp_login_email'], $_SESSION['otp_login_sent']);
            $error = 'Session expired.'; $mode = 'otp';
        } else {
            if (EmailOTP::verify((int)$user['id'], $code)) {
                unset($_SESSION['otp_login_user_id'], $_SESSION['otp_login_email'], $_SESSION['otp_login_sent']);
                login_user($user);
                header('Location: '.BASE_URL.(empty($user['onboarded']) ? '/onboarding.php' : '/dashboard.php')); exit;
            } else {
                $error = 'Invalid or expired OTP. Try again.';
                $mode = 'otp'; $otp_step = 'verify';
            }
        }

    // ── Password login ─────────────────────────────────────────
    } else {
        $identifier = trim($_POST['identifier'] ?? '');
        $password   = $_POST['password'] ?? '';
        if (empty($identifier) || empty($password)) {
            $error = 'Please enter your credentials.';
        } else {
            $st = db()->prepare('SELECT * FROM users WHERE username=? OR email=? LIMIT 1');
            $st->execute([$identifier, strtolower($identifier)]); $user = $st->fetch();
            if (!$user) { usleep(random_int(100000,300000)); $error = 'Invalid email or username.'; }
            elseif (($user['status']??'') === 'banned') { $error = 'Account banned. Contact support.'; }
            elseif (!verify_password($password, $user['password'])) { usleep(random_int(100000,300000)); $error = 'Incorrect password.'; }
            else {
                if (!empty($user['totp_enabled'])) {
                    $method = $user['twofa_method'] ?? 'totp';
                    $_SESSION['2fa_pending_user_id'] = $user['id'];
                    $show_2fa = true; $twofa_method = $method;
                    if ($method === 'email') {
                        $_SESSION['2fa_pending_email'] = $user['email'];
                        $res = EmailOTP::sendToUser($user);
                        if ($res['ok']) $_SESSION['2fa_email_sent'] = true;
                        else $error = $res['error'] ?? 'Could not send OTP.';
                    }
                } else {
                    login_user($user);
                    header('Location: '.BASE_URL.(empty($user['onboarded']) ? '/onboarding.php' : '/dashboard.php')); exit;
                }
            }
        }
    }
}

// Restore 2FA state on GET
if (!$show_2fa && !empty($_SESSION['2fa_pending_user_id'])) {
    $show_2fa = true;
    $ut = db()->prepare('SELECT twofa_method FROM users WHERE id=? LIMIT 1');
    $ut->execute([(int)$_SESSION['2fa_pending_user_id']]); $ur = $ut->fetch();
    $twofa_method = $ur['twofa_method'] ?? 'totp';
}

// OTP login state
if (isset($_GET['mode']) && $_GET['mode'] === 'otp') $mode = 'otp';
if (isset($_GET['step']) && $_GET['step'] === 'verify') $otp_step = 'verify';
if ($otp_step === 'verify' && empty($_SESSION['otp_login_user_id'])) {
    $otp_step = ''; $mode = 'otp';
}

$csrf = csrf_token();

// Masked email helper
function maskEmail(string $email): string {
    $p = explode('@', $email); $u = $p[0] ?? ''; $d = $p[1] ?? '';
    return (strlen($u) <= 2 ? substr($u,0,1).'**' : substr($u,0,2).str_repeat('*', max(0,strlen($u)-2))) . '@' . $d;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <?php if (get_setting('google_signin_enabled','0')==='1' && !empty(get_setting('google_client_id'))): ?>
  <script src="https://accounts.google.com/gsi/client" async defer></script>
  <?php endif; ?>
  <script>
    var BASE_URL = "<?= BASE_URL ?>";
    var GOOGLE_CLIENT_ID = "<?= htmlspecialchars(get_setting('google_client_id','')) ?>";
    var LOGIN_CSRF = "<?= csrf_token() ?>";
  </script>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Sign In — <?= APP_NAME ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ════════════════════════════════════════════
   PREMIUM LOGIN — BETTER THAN UTHO
   Clean minimal, glassmorphism, premium feel
════════════════════════════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

:root {
  --p: var(--primary, #16a34a);
  --ph: var(--primary-hover, #15803d);
  --p10: color-mix(in srgb, var(--primary, #16a34a) 10%, transparent);
  --p20: color-mix(in srgb, var(--primary, #16a34a) 20%, transparent);
  --p30: color-mix(in srgb, var(--primary, #16a34a) 30%, transparent);
  --text: #0f172a;
  --text2: #475569;
  --text3: #94a3b8;
  --border: #e8edf3;
  --bg: #f5f7fa;
  --surface: #ffffff;
  --radius: 16px;
  --shadow: 0 1px 3px rgba(15,23,42,.06), 0 4px 16px rgba(15,23,42,.05);
  --shadow-lg: 0 8px 32px rgba(15,23,42,.1), 0 2px 8px rgba(15,23,42,.06);
}

html { height:100%; }
body {
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  -webkit-font-smoothing: antialiased;
}

/* ── LAYOUT ─────────────────────────────────── */
.lp-wrap {
  display: grid;
  grid-template-columns: 480px 1fr;
  min-height: 100vh;
}

/* LEFT: form panel */
.lp-left {
  background: var(--surface);
  display: flex;
  flex-direction: column;
  padding: 0;
  position: relative;
  z-index: 2;
  box-shadow: 4px 0 40px rgba(15,23,42,.06);
}
.lp-left-scroll {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
  padding: 48px 52px;
  overflow-y: auto;
}

/* TOP NAV inside left panel */
.lp-topnav {
  padding: 20px 52px 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-shrink: 0;
}
.lp-logo {
  display: flex; align-items: center; gap: 9px;
  text-decoration: none;
}
.lp-logo-icon {
  width: 34px; height: 34px; border-radius: 10px;
  background: var(--p);
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 3px 10px var(--p30);
}
.lp-logo-icon svg { width: 17px; height: 17px; color: #fff; }
.lp-logo-name {
  font-size: 16px; font-weight: 800;
  color: var(--text); letter-spacing: -.4px;
}
.lp-back-home {
  font-size: 12.5px; font-weight: 500; color: var(--text3);
  text-decoration: none; display: flex; align-items: center; gap: 4px;
  transition: color .15s;
}
.lp-back-home:hover { color: var(--text2); }

/* ── FORM AREA ──────────────────────────────── */
.lp-form-head { margin-bottom: 28px; }
.lp-form-head h1 {
  font-size: 24px; font-weight: 800;
  color: var(--text); letter-spacing: -.6px;
  margin-bottom: 5px;
}
.lp-form-head p {
  font-size: 13.5px; color: var(--text2); line-height: 1.5;
}

/* ── INPUT ──────────────────────────────────── */
.fi { margin-bottom: 16px; }
.fi-label-row {
  display: flex; justify-content: space-between; align-items: baseline;
  margin-bottom: 6px;
}
.fi-label {
  font-size: 13px; font-weight: 600; color: var(--text);
}
.fi-link {
  font-size: 12px; font-weight: 500; color: var(--p);
  text-decoration: none; transition: opacity .15s;
}
.fi-link:hover { opacity: .75; }
.fi-wrap { position: relative; }
.fi-icon {
  position: absolute; left: 13px; top: 50%;
  transform: translateY(-50%);
  color: var(--text3); display: flex; pointer-events: none;
}
.fi-icon svg { width: 16px; height: 16px; }
.fi-input {
  width: 100%; padding: 11px 14px 11px 40px;
  border: 1.5px solid var(--border); border-radius: 12px;
  font-size: 13.5px; font-family: inherit; color: var(--text);
  background: var(--surface); outline: none;
  transition: border-color .15s, box-shadow .15s;
  -webkit-appearance: none;
}
.fi-input:focus {
  border-color: var(--p);
  box-shadow: 0 0 0 3px var(--p10);
}
.fi-input::placeholder { color: var(--text3); font-size: 13px; }
.fi-eye {
  position: absolute; right: 12px; top: 50%;
  transform: translateY(-50%);
  background: none; border: none; cursor: pointer;
  color: var(--text3); display: flex; padding: 4px;
  border-radius: 6px; transition: color .15s;
}
.fi-eye:hover { color: var(--text); }
.fi-eye svg { width: 16px; height: 16px; }

/* ── BUTTONS ────────────────────────────────── */
.btn-main {
  width: 100%; padding: 12px 20px;
  background: var(--p); color: #fff;
  border: none; border-radius: 12px;
  font-size: 14px; font-weight: 700; font-family: inherit;
  cursor: pointer; transition: all .18s;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  margin-top: 4px;
  box-shadow: 0 3px 14px var(--p30);
}
.btn-main:hover {
  background: var(--ph);
  transform: translateY(-1px);
  box-shadow: 0 6px 22px var(--p30);
}
.btn-main:active { transform: translateY(0); }

.btn-outline {
  width: 100%; padding: 10px 16px;
  background: var(--surface); color: var(--text);
  border: 1.5px solid var(--border); border-radius: 12px;
  font-size: 13.5px; font-weight: 500; font-family: inherit;
  cursor: pointer; transition: all .15s;
  display: flex; align-items: center; justify-content: center; gap: 9px;
  text-decoration: none; margin-bottom: 9px;
}
.btn-outline:hover {
  background: var(--bg); border-color: #d1d9e0;
}
.btn-outline svg { width: 17px; height: 17px; flex-shrink: 0; }

/* ── DIVIDER ─────────────────────────────────── */
.or-div {
  display: flex; align-items: center; gap: 12px;
  margin: 20px 0 18px;
}
.or-div::before,.or-div::after {
  content: ''; flex: 1; height: 1px; background: var(--border);
}
.or-div span { font-size: 11.5px; color: var(--text3); font-weight: 500; }

/* ── ALERTS ─────────────────────────────────── */
.alert-err {
  display: flex; align-items: flex-start; gap: 9px;
  background: #fef2f2; border: 1px solid #fecaca;
  border-radius: 10px; padding: 11px 14px;
  font-size: 13px; color: #b91c1c;
  margin-bottom: 18px;
}
.alert-err svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 1px; }
.alert-ok {
  background: #f0fdf4; border: 1px solid #bbf7d0;
  border-radius: 10px; padding: 11px 14px;
  font-size: 13px; color: #15803d;
  margin-bottom: 18px;
}

/* ── FOOTER TEXT ─────────────────────────────── */
.lp-form-footer {
  text-align: center; font-size: 13px; color: var(--text2);
  margin-top: 22px;
}
.lp-form-footer a {
  color: var(--p); font-weight: 700; text-decoration: none;
}

/* ── BOTTOM BAR ──────────────────────────────── */
.lp-bottom-bar {
  padding: 16px 52px;
  border-top: 1px solid var(--border);
  display: flex; align-items: center; gap: 16px; flex-shrink: 0;
}
.lp-cert {
  display: flex; align-items: center; gap: 5px;
  font-size: 11.5px; font-weight: 500; color: var(--text3);
}
.lp-cert svg { width: 12px; height: 12px; color: var(--text3); }
.lp-cert-sep { color: var(--border); }

/* ── OTP DIGIT BOXES ─────────────────────────── */
.digits-wrap {
  display: flex; gap: 8px; justify-content: center;
  margin: 22px 0 18px;
}
.dbox {
  width: 48px; height: 56px;
  border: 1.5px solid var(--border); border-radius: 12px;
  font-size: 22px; font-weight: 700; text-align: center;
  font-family: monospace; background: var(--surface);
  color: var(--text); transition: all .15s; outline: none;
}
.dbox:focus {
  border-color: var(--p);
  box-shadow: 0 0 0 3px var(--p10);
}
.dbox-sep { display: flex; align-items: center; color: var(--text3); font-size: 20px; }
.resend-btn {
  background: none; border: none; font-size: 13px; font-weight: 600;
  color: var(--p); cursor: pointer; padding: 4px 6px; font-family: inherit;
}
.resend-btn:hover { text-decoration: underline; }
.back-link {
  display: inline-flex; align-items: center; gap: 5px;
  font-size: 12.5px; color: var(--text3); text-decoration: none;
  transition: color .15s;
}
.back-link:hover { color: var(--text2); }

/* ── 2FA icon ────────────────────────────────── */
.twofa-icon {
  width: 56px; height: 56px;
  background: var(--p10); border: 1.5px solid var(--p20);
  border-radius: 16px;
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 20px;
}
.twofa-icon svg { width: 26px; height: 26px; color: var(--p); }

/* ── RIGHT PANEL ─────────────────────────────── */
.lp-right {
  position: relative; overflow: hidden;
  background: linear-gradient(160deg, #f0fdf4 0%, #f8fafc 40%, #eff6ff 100%);
  display: flex; align-items: center; justify-content: center;
  padding: 60px 48px;
}
/* Dot grid */
.lp-right::before {
  content: '';
  position: absolute; inset: 0;
  background-image: radial-gradient(circle, rgba(15,23,42,.07) 1px, transparent 1px);
  background-size: 26px 26px;
  mask-image: radial-gradient(ellipse 90% 90% at 50% 50%, black 20%, transparent 100%);
}
/* Ambient blobs */
.lp-blob {
  position: absolute; border-radius: 50%;
  filter: blur(80px); pointer-events: none; z-index: 0;
}
.lp-blob-1 {
  width: 400px; height: 400px;
  background: color-mix(in srgb, var(--primary, #16a34a) 12%, transparent);
  top: -10%; left: -5%;
}
.lp-blob-2 {
  width: 350px; height: 350px;
  background: rgba(99,102,241,.1);
  bottom: -5%; right: -5%;
}
.lp-blob-3 {
  width: 250px; height: 250px;
  background: rgba(6,182,212,.08);
  top: 40%; right: 20%;
}

.lp-right-inner {
  position: relative; z-index: 1;
  width: 100%; max-width: 560px;
}

/* Brand header in right panel */
.rp-brand {
  display: flex; align-items: center; gap: 12px;
  margin-bottom: 36px;
}
.rp-brand-icon {
  width: 46px; height: 46px; border-radius: 13px;
  background: var(--surface);
  border: 1px solid rgba(255,255,255,.8);
  box-shadow: var(--shadow);
  display: flex; align-items: center; justify-content: center;
}
.rp-brand-icon svg { width: 22px; height: 22px; }
.rp-brand-title {
  font-size: 18px; font-weight: 800; color: var(--text); letter-spacing: -.4px;
}
.rp-brand-sub {
  font-size: 12px; color: var(--text3); margin-top: 1px;
}

/* Main feature card */
.rp-hero-card {
  background: rgba(255,255,255,.85);
  backdrop-filter: blur(16px);
  border: 1px solid rgba(255,255,255,.9);
  border-radius: 22px;
  padding: 28px 28px 24px;
  box-shadow: var(--shadow-lg);
  margin-bottom: 20px;
}
.rp-hero-title {
  font-size: 20px; font-weight: 800;
  color: var(--text); letter-spacing: -.5px;
  margin-bottom: 6px; display: flex; align-items: center; gap: 8px;
}
.rp-hero-title svg { width: 22px; height: 22px; color: var(--p); }
.rp-hero-sub {
  font-size: 13px; color: var(--text2); line-height: 1.55;
  margin-bottom: 20px;
}

/* Stats row */
.rp-stats {
  display: grid; grid-template-columns: repeat(4,1fr);
  gap: 12px;
}
.rp-stat {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 14px; padding: 14px 12px;
  text-align: center; position: relative; overflow: hidden;
  transition: transform .2s, box-shadow .2s;
}
.rp-stat:hover { transform: translateY(-2px); box-shadow: var(--shadow); }
.rp-stat::before {
  content: ''; position: absolute;
  top: 0; left: 0; right: 0; height: 2px;
}
.rp-stat:nth-child(1)::before { background: var(--p); }
.rp-stat:nth-child(2)::before { background: #6366f1; }
.rp-stat:nth-child(3)::before { background: #f59e0b; }
.rp-stat:nth-child(4)::before { background: #8b5cf6; }
.rp-stat-icon {
  width: 32px; height: 32px; border-radius: 9px;
  background: var(--bg); border: 1px solid var(--border);
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 10px;
}
.rp-stat-icon svg { width: 16px; height: 16px; }
.rp-stat-n {
  font-size: 20px; font-weight: 800; color: var(--text);
  letter-spacing: -.8px; line-height: 1;
}
.rp-stat-l {
  font-size: 9.5px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .8px; color: var(--text3); margin-top: 4px;
}

/* 2-col bottom row */
.rp-bottom-row {
  display: grid; grid-template-columns: 1fr 1fr; gap: 14px;
}

/* Status card */
.rp-status-card {
  background: rgba(255,255,255,.85);
  backdrop-filter: blur(16px);
  border: 1px solid rgba(255,255,255,.9);
  border-radius: 18px; padding: 18px 20px;
  box-shadow: var(--shadow);
}
.rp-status-header {
  display: flex; align-items: center; gap: 9px; margin-bottom: 12px;
}
.rp-status-dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--p);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary, #16a34a) 20%, transparent);
  animation: pulse 2s infinite;
}
@keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.6;transform:scale(1.3)} }
.rp-status-title {
  font-size: 13.5px; font-weight: 700; color: var(--text);
}
.rp-status-row {
  display: flex; align-items: center; gap: 7px;
  font-size: 12px; color: var(--text2); padding: 4px 0;
  border-bottom: 1px solid var(--border);
}
.rp-status-row:last-child { border-bottom: none; }
.rp-status-row svg { width: 13px; height: 13px; color: var(--p); flex-shrink: 0; }
.rp-status-ok {
  margin-left: auto; font-size: 10px; font-weight: 700;
  color: var(--p); background: color-mix(in srgb, var(--primary, #16a34a) 10%, transparent);
  padding: 2px 7px; border-radius: 99px;
}

/* Security card */
.rp-sec-card {
  background: rgba(255,255,255,.85);
  backdrop-filter: blur(16px);
  border: 1px solid rgba(255,255,255,.9);
  border-radius: 18px; padding: 18px 20px;
  box-shadow: var(--shadow);
}
.rp-sec-title {
  font-size: 12px; font-weight: 700; color: var(--text);
  margin-bottom: 12px; display: flex; align-items: center; gap: 6px;
}
.rp-sec-title svg { width: 14px; height: 14px; color: var(--p); }
.rp-sec-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 7px; }
.rp-sec-chip {
  display: flex; align-items: center; gap: 6px;
  background: var(--bg); border: 1px solid var(--border);
  border-radius: 8px; padding: 6px 10px;
  font-size: 11.5px; font-weight: 500; color: var(--text2);
}
.rp-sec-chip svg { width: 12px; height: 12px; color: var(--p); flex-shrink: 0; }

/* Spinner */
.spin {
  width: 17px; height: 17px;
  border: 2px solid rgba(255,255,255,.3); border-radius: 50%;
  border-top-color: #fff;
  animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── RESPONSIVE ──────────────────────────────── */
@media (max-width: 900px) {
  .lp-wrap { grid-template-columns: 1fr; }
  .lp-right { display: none; }
  .lp-left { box-shadow: none; }
}
@media (max-width: 480px) {
  .lp-left-scroll { padding: 36px 28px; }
  .lp-topnav { padding: 16px 28px 0; }
  .lp-bottom-bar { padding: 14px 28px; }
}
</style>
</head>
<body>
<div class="lp-wrap">

  <!-- ═══ LEFT: FORM ═══════════════════════════ -->
  <div class="lp-left">

    <!-- Top nav -->
    <div class="lp-topnav">
      <a href="<?= BASE_URL ?>/" class="lp-logo">
        <?php if (!empty(get_setting('site_logo', ''))) : ?>
    <img src="<?= htmlspecialchars(get_setting('site_logo', '')) ?>" alt="Logo" style="width: 200px;">
<?php else: ?>
    <div class="lp-logo-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg>
        </div>
        <span class="lp-logo-name"><?= APP_NAME ?></span>
<?php endif; ?>
      </a>
      <a href="<?= BASE_URL ?>/" class="lp-back-home">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
        Back to home
      </a>
    </div>

    <!-- Scrollable form area -->
    <div class="lp-left-scroll">

      <?php if ($show_2fa): ?>
      <!-- ── 2FA ───────────────────────────── -->
      <div style="text-align:center">
        <div class="twofa-icon">
          <?php if ($twofa_method === 'email'): ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          <?php else: ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
          <?php endif; ?>
        </div>
        <div class="lp-form-head">
          <h1>Two-Factor Authentication</h1>
          <p><?= $twofa_method === 'email'
              ? (!empty($_SESSION['2fa_email_sent']) ? 'Code sent to <strong>'.htmlspecialchars(maskEmail($_SESSION['2fa_pending_email']??'')).'</strong>' : 'Enter the code from your email')
              : 'Enter the 6-digit code from your authenticator app' ?>
          </p>
        </div>
      </div>
      <?php if ($error): ?><div class="alert-err"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <form method="POST" id="tf-form">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="totp_step" value="1">
        <input type="hidden" name="totp_code" id="tf-hidden-value">
        <div id="tf-digits-area">
          <div class="digits-wrap" id="tf-digits-container">
            <input type="text" maxlength="1" inputmode="numeric" class="dbox" autocomplete="off">
            <input type="text" maxlength="1" inputmode="numeric" class="dbox">
            <input type="text" maxlength="1" inputmode="numeric" class="dbox">
            <span class="dbox-sep">·</span>
            <input type="text" maxlength="1" inputmode="numeric" class="dbox">
            <input type="text" maxlength="1" inputmode="numeric" class="dbox">
            <input type="text" maxlength="1" inputmode="numeric" class="dbox">
          </div>
        </div>
        <div id="tf-recovery-wrap" style="display:none;margin:12px 0">
          <div class="fi">
            <label class="fi-label">Recovery Code</label>
            <div class="fi-wrap">
              <input type="text" id="tf-recovery-input" class="fi-input" placeholder="XXXXX-XXXXX" style="text-align:center;font-family:monospace;padding-left:14px">
            </div>
          </div>
        </div>
        <button type="submit" id="tf-submit-btn" class="btn-main">Verify &amp; Sign In</button>
      </form>
      <div style="text-align:center;margin-top:16px">
        <?php if ($twofa_method === 'email'): ?>
        <form method="POST" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="resend_email_otp" value="1">
          <button type="submit" class="resend-btn" id="resend-otp-btn">Resend code</button>
          <span id="resend-timer" style="display:none;font-size:12px;color:var(--text3)">Resend in <span id="resend-seconds">60</span>s</span>
        </form>
        <?php else: ?>
        <span class="resend-btn" id="toggle-recovery-link" style="cursor:pointer">Use recovery code</span>
        <?php endif; ?>
      </div>
      <div style="text-align:center;margin-top:12px">
        <a href="<?= BASE_URL ?>/login.php" class="back-link">← Back to login</a>
      </div>

      <?php elseif ($mode === 'otp' && $otp_step === 'verify'): ?>
      <!-- ── OTP Verify ────────────────────── -->
      <?php $otpEmail = $_SESSION['otp_login_email'] ?? ''; ?>
      <div style="text-align:center">
        <div class="twofa-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        </div>
        <div class="lp-form-head">
          <h1>Check your inbox</h1>
          <p>6-digit code sent to <strong><?= htmlspecialchars(maskEmail($otpEmail)) ?></strong></p>
        </div>
      </div>
      <?php if ($error): ?><div class="alert-err"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <?php if ($success): ?><div class="alert-ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>
      <form method="POST" id="otp-verify-form">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="otp_verify" value="1">
        <input type="hidden" name="otp_code" id="otp-hidden-value">
        <div class="digits-wrap" id="otp-digits">
          <input type="text" maxlength="1" inputmode="numeric" class="dbox">
          <input type="text" maxlength="1" inputmode="numeric" class="dbox">
          <input type="text" maxlength="1" inputmode="numeric" class="dbox">
          <span class="dbox-sep">·</span>
          <input type="text" maxlength="1" inputmode="numeric" class="dbox">
          <input type="text" maxlength="1" inputmode="numeric" class="dbox">
          <input type="text" maxlength="1" inputmode="numeric" class="dbox">
        </div>
        <button type="submit" id="otp-verify-btn" class="btn-main">Verify &amp; Continue</button>
      </form>
      <div style="text-align:center;margin-top:16px;display:flex;flex-direction:column;gap:8px">
        <form method="POST" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="otp_resend" value="1">
          <button type="submit" class="resend-btn" id="otp-resend-button">Resend code</button>
          <span id="otp-timer-text" style="display:none;font-size:12px;color:var(--text3)">Resend in <span id="otp-seconds">60</span>s</span>
        </form>
        <div style="display:flex;gap:16px;justify-content:center">
          <a href="<?= BASE_URL ?>/login.php?mode=otp" class="back-link">← Change email</a>
          <a href="<?= BASE_URL ?>/login.php" class="back-link">Use password instead</a>
        </div>
      </div>

      <?php elseif ($mode === 'otp'): ?>
      <!-- ── OTP Email Entry ───────────────── -->
      <div class="lp-form-head">
        <h1>Login with OTP</h1>
        <p>Enter your email — we'll send a one-time passcode.</p>
      </div>
      <?php if ($error): ?><div class="alert-err"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="otp_send" value="1">
        <div class="fi">
          <div class="fi-label-row"><label class="fi-label">Email address</label></div>
          <div class="fi-wrap">
            <span class="fi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></span>
            <input class="fi-input" type="email" name="otp_email" placeholder="you@company.com" value="<?= htmlspecialchars($_POST['otp_email']??'') ?>" required autofocus>
          </div>
        </div>
        <button type="submit" class="btn-main">Send OTP →</button>
      </form>
      <div class="or-div"><span>or</span></div>
      <a href="<?= BASE_URL ?>/login.php" class="btn-outline">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Sign in with password
      </a>
      <div class="lp-form-footer">New here? <a href="<?= BASE_URL ?>/register.php">Create account</a></div>

      <?php else: ?>
      <!-- ── Password Login (default) ─────── -->
      <div class="lp-form-head">
        <h1>Sign in to your account</h1>
        <p>Authenticate to access cloud infrastructure</p>
      </div>

      <?php if ($error): ?>
      <div class="alert-err">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>

      <form method="POST" id="login-form">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <!-- Email -->
        <div class="fi">
          <div class="fi-label-row"><label class="fi-label">Email or Username</label></div>
          <div class="fi-wrap">
            <span class="fi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></span>
            <input class="fi-input" type="text" name="identifier" placeholder="Enter your email" value="<?= htmlspecialchars($_POST['identifier']??'') ?>" required autofocus autocomplete="username">
          </div>
        </div>

        <!-- Password -->
        <div class="fi">
          <div class="fi-label-row">
            <label class="fi-label">Password</label>
            <a href="<?= BASE_URL ?>/forgot.php" class="fi-link">Forgot password?</a>
          </div>
          <div class="fi-wrap">
            <span class="fi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
            <input class="fi-input" type="password" id="lp-pwd" name="password" placeholder="Enter your password" required autocomplete="current-password">
            <button type="button" class="fi-eye" id="lp-eye" title="Show/hide">
              <svg id="lp-eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <button type="submit" id="lp-submit" class="btn-main">
          Sign In
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
      </form>

      <div class="or-div"><span>or continue with</span></div>

      <?php
        $g_on  = get_setting('google_signin_enabled','0')==='1' && !empty(get_setting('google_client_id'));
        $gh_on = get_setting('github_signin_enabled','0')==='1' && !empty(get_setting('github_client_id'));
      ?>
      <?php if ($g_on): ?>
      <button type="button" class="btn-outline" onclick="startGoogleLogin()">
        <svg viewBox="0 0 24 24" width="17" height="17"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
        Continue with Google
      </button>
      <?php endif; ?>
      <?php if ($gh_on): ?>
      <a href="<?= BASE_URL ?>/includes/github_callback.php?action=login&csrf=<?= csrf_token() ?>" class="btn-outline">
        <svg viewBox="0 0 24 24" width="17" height="17" fill="currentColor"><path d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844a9.59 9.59 0 012.504.337c1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.02 10.02 0 0022 12.017C22 6.484 17.522 2 12 2z"/></svg>
        Continue with GitHub
      </a>
      <?php endif; ?>

      <a href="<?= BASE_URL ?>/login.php?mode=otp" class="btn-outline">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 014.69 12 19.79 19.79 0 011.63 3.4 2 2 0 013.6 1.21h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L7.91 8.35a16 16 0 006.29 6.29l.95-.95a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
        Login with OTP
      </a>

      <div class="lp-form-footer">Don't have an account? <a href="<?= BASE_URL ?>/register.php">Sign up free</a></div>
      <?php endif; ?>

    </div><!-- /lp-left-scroll -->

    <!-- Bottom certs -->
    <div class="lp-bottom-bar">
      <div class="lp-cert">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
        TLS 1.3
      </div>
      <span class="lp-cert-sep">|</span>
      <div class="lp-cert">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        SOC 2 Type II
      </div>
      <span class="lp-cert-sep">|</span>
      <div class="lp-cert">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        ISO 27001
      </div>
    </div>

  </div><!-- /lp-left -->

  <!-- ═══ RIGHT: BRAND PANEL ══════════════════ -->
  <div class="lp-right">
    <div class="lp-blob lp-blob-1"></div>
    <div class="lp-blob lp-blob-2"></div>
    <div class="lp-blob lp-blob-3"></div>

    <div class="lp-right-inner">

      <!-- Brand header -->
      <div class="rp-brand">
        <div class="rp-brand-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z" stroke="var(--primary,#16a34a)"/></svg>
        </div>
        <div>
          <div class="rp-brand-title"><?= APP_NAME ?></div>
          <div class="rp-brand-sub">Reliability &amp; Compliance</div>
        </div>
      </div>

      <!-- Hero card: Reliability -->
      <div class="rp-hero-card">
        <div class="rp-hero-title">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          Reliability &amp; Compliance
        </div>
        <div class="rp-hero-sub">Enterprise-grade security posture — built for modern cloud infrastructure with zero compromise.</div>
        <!-- Stats -->
        <div class="rp-stats">
          <div class="rp-stat">
            <div class="rp-stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2"><circle cx="12" cy="10" r="3"/><path d="M12 2a8 8 0 0 0-8 8c0 5.4 7.05 11.5 7.72 12.06a.5.5 0 0 0 .56 0C12.95 21.5 20 15.4 20 10a8 8 0 0 0-8-8z"/></svg></div>
            <div class="rp-stat-n">7+</div>
            <div class="rp-stat-l">Regions</div>
          </div>
          <div class="rp-stat">
            <div class="rp-stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
            <div class="rp-stat-n">99.99%</div>
            <div class="rp-stat-l">Uptime</div>
          </div>
          <div class="rp-stat">
            <div class="rp-stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2"><polyline points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></div>
            <div class="rp-stat-n">&lt;30ms</div>
            <div class="rp-stat-l">Latency</div>
          </div>
          <div class="rp-stat">
            <div class="rp-stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
            <div class="rp-stat-n">24×7</div>
            <div class="rp-stat-l">Monitoring</div>
          </div>
        </div>
      </div>

      <!-- Bottom 2 cards -->
      <div class="rp-bottom-row">

        <!-- Status card -->
        <div class="rp-status-card">
          <div class="rp-status-header">
            <span class="rp-status-dot"></span>
            <span class="rp-status-title">Platform Status</span>
          </div>
          <div class="rp-status-row">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/></svg>
            API Gateway
            <span class="rp-status-ok">OK</span>
          </div>
          <div class="rp-status-row">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/></svg>
            Storage
            <span class="rp-status-ok">OK</span>
          </div>
          <div class="rp-status-row">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/></svg>
            Network
            <span class="rp-status-ok">OK</span>
          </div>
        </div>

        <!-- Security card -->
        <div class="rp-sec-card">
          <div class="rp-sec-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            Security Architecture
          </div>
          <div class="rp-sec-grid">
            <div class="rp-sec-chip">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              Zero-Trust IAM
            </div>
            <div class="rp-sec-chip">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
              E2E Encryption
            </div>
            <div class="rp-sec-chip">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
              Compliance
            </div>
            <div class="rp-sec-chip">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
              RBAC
            </div>
          </div>
        </div>

      </div>
    </div>
  </div><!-- /lp-right -->

</div><!-- /lp-wrap -->

<script>
// ── Digit inputs (2FA + OTP) ──────────────────
function initDigits(formId, hiddenId, submitId) {
  const form = document.getElementById(formId);
  if (!form) return;
  const boxes = Array.from(form.querySelectorAll('.dbox'));
  if (!boxes.length) return;
  const collect = () => boxes.map(b => b.value).join('');
  const go = () => {
    document.getElementById(hiddenId).value = collect();
    const btn = document.getElementById(submitId);
    if (btn) { btn.disabled = true; btn.innerHTML = '<div class="spin"></div>'; }
    form.submit();
  };
  boxes.forEach((b, i) => {
    b.addEventListener('input', () => {
      b.value = b.value.replace(/\D/g,'').slice(0,1);
      if (b.value && i < boxes.length-1) boxes[i+1].focus();
      if (collect().length === 6) go();
    });
    b.addEventListener('keydown', e => {
      if (e.key === 'Backspace' && !b.value && i > 0) { boxes[i-1].value=''; boxes[i-1].focus(); }
    });
    b.addEventListener('paste', e => {
      e.preventDefault();
      const p = (e.clipboardData||window.clipboardData).getData('text').replace(/\D/g,'');
      if (p.length >= 6) { boxes.forEach((b2,j) => b2.value = p[j]||''); go(); }
    });
  });
  form.addEventListener('submit', () => { document.getElementById(hiddenId).value = collect(); });
  if (boxes[0]) boxes[0].focus();
}
initDigits('tf-form',         'tf-hidden-value',  'tf-submit-btn');
initDigits('otp-verify-form', 'otp-hidden-value', 'otp-verify-btn');

// ── Recovery toggle ───────────────────────────
let recoveryMode = false;
document.getElementById('toggle-recovery-link')?.addEventListener('click', () => {
  recoveryMode = !recoveryMode;
  document.getElementById('tf-digits-area').style.display  = recoveryMode ? 'none' : '';
  document.getElementById('tf-recovery-wrap').style.display = recoveryMode ? '' : 'none';
  document.getElementById('toggle-recovery-link').textContent = recoveryMode ? '← Use authenticator app' : 'Use recovery code';
  if (recoveryMode) document.getElementById('tf-recovery-input')?.focus();
});
document.getElementById('tf-form')?.addEventListener('submit', function() {
  if (recoveryMode) document.getElementById('tf-hidden-value').value = document.getElementById('tf-recovery-input')?.value.trim()||'';
});

// ── Password eye ──────────────────────────────
document.getElementById('lp-eye')?.addEventListener('click', () => {
  const f = document.getElementById('lp-pwd');
  const icon = document.getElementById('lp-eye-icon');
  if (!f) return;
  f.type = f.type === 'password' ? 'text' : 'password';
  icon.innerHTML = f.type === 'text'
    ? '<line x1="1" y1="1" x2="23" y2="23"/><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/>'
    : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
});

// ── Login submit spinner ──────────────────────
document.getElementById('login-form')?.addEventListener('submit', () => {
  const btn = document.getElementById('lp-submit');
  if (btn) { btn.disabled = true; btn.innerHTML = '<div class="spin"></div> Signing in...'; }
});

// ── Resend timers ─────────────────────────────
function startTimer(btnId, timerId, secId, secs=60) {
  const btn = document.getElementById(btnId);
  const timer = document.getElementById(timerId);
  const sec = document.getElementById(secId);
  if (!btn || !timer) return;
  btn.style.display = 'none'; timer.style.display = 'inline';
  let c = secs;
  const iv = setInterval(() => {
    c--; if (sec) sec.textContent = c;
    if (c <= 0) { clearInterval(iv); btn.style.display = ''; timer.style.display = 'none'; }
  }, 1000);
}
<?php if (!empty($_SESSION['2fa_email_sent'])): ?>startTimer('resend-otp-btn','resend-timer','resend-seconds');<?php endif; ?>
<?php if (!empty($_SESSION['otp_login_sent'])): ?>startTimer('otp-resend-button','otp-timer-text','otp-seconds');<?php endif; ?>

// ── Google Sign-In ────────────────────────────
function startGoogleLogin() {
  if (!GOOGLE_CLIENT_ID) { alert('Google Sign-In not configured.'); return; }
  var client = google.accounts.oauth2.initTokenClient({
    client_id: GOOGLE_CLIENT_ID, scope: 'openid email profile',
    callback: function(tr) {
      if (tr.error) return;
      fetch('https://www.googleapis.com/oauth2/v3/userinfo',{headers:{'Authorization':'Bearer '+tr.access_token}})
      .then(r=>r.json()).then(p=>{
        var fd=new FormData();
        fd.append('google_login','1');fd.append('google_email',p.email||'');
        fd.append('google_name',p.name||'');fd.append('google_sub',p.sub||'');
        fd.append('google_token',tr.access_token||'');fd.append('csrf_token',LOGIN_CSRF);
        fetch(BASE_URL+'/includes/google_auth.php',{method:'POST',body:fd})
        .then(r=>r.json()).then(res=>{
          if(res.ok&&res.redirect) window.location.href=res.redirect;
          else if(res.action==='register') window.location.href=BASE_URL+'/register.php?social=google&hint='+encodeURIComponent(p.email||'');
          else alert(res.error||'Google login failed.');
        });
      });
    }
  });
  client.requestAccessToken();
}
window.startGoogleLogin = startGoogleLogin;

// Nav scroll shadow
window.addEventListener('scroll',()=>{
  document.querySelector('.lp-left')?.classList.toggle('scrolled', window.scrollY > 5);
});
</script>
</body>
</html>