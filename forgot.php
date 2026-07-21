<?php
require_once __DIR__ . '/includes/bootstrap.php';
session_start_safe();

if (is_logged_in()) { header('Location: '.BASE_URL.'/dashboard.php'); exit; }

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token.';
    } else {
        if (get_setting('captcha_enabled','1')==='1') {
            $captcha = $_POST['g-recaptcha-response'] ?? '';
            if (empty($captcha)) { $error = 'Please verify CAPTCHA.'; }
            else {
                $secret = get_setting('captcha_secret_key');
                $verify = file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret=".$secret."&response=".$captcha);
                $resp = json_decode($verify, true);
                if (empty($resp['success'])) { $error = 'CAPTCHA verification failed.'; }
            }
        }
        if (!$error) {
            $email    = strtolower(trim($_POST['email'] ?? ''));
            $otp      = trim($_POST['otp_value'] ?? '');
            $password = $_POST['password']  ?? '';
            $confirm  = $_POST['confirm']   ?? '';

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Invalid email address.';
            elseif (strlen($password) < 8) $error = 'Password must be at least 8 characters.';
            elseif ($password !== $confirm) $error = 'Passwords do not match.';
            elseif (!ctype_digit($otp) || strlen($otp) !== 6) $error = 'Invalid OTP format.';
            else {
                $otpKey  = 'otp_forgot_'.md5($email);
                $otpData = $_SESSION[$otpKey] ?? null;
                if (!$otpData || !password_verify($otp, $otpData['hash']) || time() > $otpData['expires'])
                    $error = 'OTP invalid or expired.';
                elseif (!($_SESSION['otp_verified_forgot_'.md5($email)] ?? false))
                    $error = 'Please verify your OTP first.';
            }

            if (!$error) {
                $hash = hash_password($password);
                $st = db()->prepare('UPDATE users SET password=? WHERE email=?');
                $st->execute([$hash, $email]);
                if ($st->rowCount()) {
                    unset($_SESSION['otp_forgot_'.md5($email)], $_SESSION['otp_verified_forgot_'.md5($email)]);
                    $success = 'Password reset successfully! Redirecting to login…';
                } else {
                    $error = 'Email not found.';
                }
            }
        }
    }
}
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Reset Password — <?= APP_NAME ?></title>
  <meta name="csrf" content="<?= $csrf ?>">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
  <script>var BASE_URL="<?= BASE_URL ?>";</script>
<style>
/* ════════════════════════════════════════════
   FORGOT PASSWORD — matches login/register
════════════════════════════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --p:var(--primary,#16a34a);--ph:var(--primary-hover,#15803d);
  --p10:color-mix(in srgb,var(--primary,#16a34a) 10%,transparent);
  --p20:color-mix(in srgb,var(--primary,#16a34a) 20%,transparent);
  --p30:color-mix(in srgb,var(--primary,#16a34a) 30%,transparent);
  --text:#0f172a;--text2:#475569;--text3:#94a3b8;
  --border:#e8edf3;--bg:#f5f7fa;--surface:#fff;
  --shadow:0 1px 3px rgba(15,23,42,.06),0 4px 16px rgba(15,23,42,.05);
  --shadow-lg:0 8px 32px rgba(15,23,42,.1),0 2px 8px rgba(15,23,42,.06);
}
html,body{min-height:100vh}
body{font-family:'Inter',-apple-system,sans-serif;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}

/* ── SHELL ──────────────────────────────────── */
.fp-shell{display:grid;grid-template-columns:480px 1fr;min-height:100vh}

/* ── LEFT ───────────────────────────────────── */
.fp-left{background:var(--surface);display:flex;flex-direction:column;box-shadow:4px 0 40px rgba(15,23,42,.06);position:relative;z-index:2}
.fp-topnav{padding:20px 48px 0;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.fp-logo{display:flex;align-items:center;gap:9px;text-decoration:none}
.fp-logo-mark{width:34px;height:34px;border-radius:10px;background:var(--p);display:flex;align-items:center;justify-content:center;box-shadow:0 3px 10px var(--p30)}
.fp-logo-mark svg{width:17px;height:17px;color:#fff}
.fp-logo-name{font-size:16px;font-weight:800;color:var(--text);letter-spacing:-.4px}
.fp-back-link{font-size:12.5px;font-weight:500;color:var(--text3);text-decoration:none;display:flex;align-items:center;gap:4px;transition:color .15s}
.fp-back-link:hover{color:var(--text2)}
.fp-scroll{flex:1;padding:32px 48px 0;overflow-y:auto;display:flex;flex-direction:column;justify-content:center}
.fp-bot{padding:16px 48px;border-top:1px solid var(--border);flex-shrink:0;display:flex;align-items:center;gap:14px}
.fp-cert{display:flex;align-items:center;gap:5px;font-size:11.5px;font-weight:500;color:var(--text3)}
.fp-cert svg{width:11px;height:11px}
.fp-cert-sep{color:var(--border)}

/* ── STEP INDICATOR ─────────────────────────── */
.fp-steps{margin-bottom:28px}
.fp-steps-row{display:flex;align-items:center}
.fp-step-wrap{display:flex;flex-direction:column;align-items:center;flex:1}
.fp-step-dot{
  width:28px;height:28px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:12px;font-weight:700;flex-shrink:0;
  transition:all .25s;
}
.fp-step-dot.active{background:var(--p);color:#fff;box-shadow:0 0 0 3px var(--p20)}
.fp-step-dot.done{background:var(--p);color:#fff}
.fp-step-dot.idle{background:var(--bg);border:1.5px solid var(--border);color:var(--text3)}
.fp-step-line{flex:1;height:2px;background:var(--border);margin:0 4px;transition:background .25s;margin-bottom:14px}
.fp-step-line.done{background:var(--p)}
.fp-step-label{font-size:10.5px;font-weight:600;margin-top:5px;transition:color .25s}
.fp-step-label.active{color:var(--p)}
.fp-step-label.done{color:var(--p)}
.fp-step-label.idle{color:var(--text3)}

/* ── FORM HEAD ──────────────────────────────── */
.fp-head{margin-bottom:22px}
.fp-head h1{font-size:22px;font-weight:800;color:var(--text);letter-spacing:-.5px;margin-bottom:5px}
.fp-head p{font-size:13.5px;color:var(--text2);line-height:1.5}

/* ── FIELDS ─────────────────────────────────── */
.fi{margin-bottom:14px}
.fi-lbl{font-size:13px;font-weight:600;color:var(--text);display:block;margin-bottom:5px}
.fi-wrap{position:relative}
.fi-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text3);display:flex;pointer-events:none}
.fi-icon svg{width:15px;height:15px}
.fi-inp{
  width:100%;padding:11px 12px 11px 40px;
  border:1.5px solid var(--border);border-radius:11px;
  font-size:13.5px;font-family:inherit;color:var(--text);
  background:var(--surface);outline:none;transition:all .15s;-webkit-appearance:none;
}
.fi-inp:focus{border-color:var(--p);box-shadow:0 0 0 3px var(--p10)}
.fi-inp::placeholder{color:var(--text3);font-size:13px}
.fi-inp[readonly]{background:var(--bg);color:var(--text2)}
.fi-eye{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text3);display:flex;padding:4px;border-radius:6px;transition:color .15s}
.fi-eye:hover{color:var(--text)}
.fi-eye svg{width:15px;height:15px}

/* email row + send button */
.email-row{display:flex;gap:0}
.email-inp{border-radius:11px 0 0 11px !important;border-right:none !important;flex:1}
.send-btn{
  padding:11px 14px;border:1.5px solid var(--border);border-left:none;
  border-radius:0 11px 11px 0;background:var(--bg);
  color:var(--text2);font-size:13px;font-weight:600;font-family:inherit;
  cursor:pointer;white-space:nowrap;transition:all .15s;
  display:flex;align-items:center;gap:5px;
}
.send-btn:hover{background:var(--p10);color:var(--p);border-color:var(--p)}
.send-btn:disabled{opacity:.5;cursor:not-allowed}
.verified-badge{display:none;align-items:center;gap:5px;font-size:12px;font-weight:600;color:var(--p);margin-top:6px}
.verified-badge.show{display:flex}
.verified-badge svg{width:13px;height:13px}

/* OTP digits */
.otp-row{display:flex;gap:7px;justify-content:center;margin:14px 0}
.otp-d{width:46px;height:54px;border:1.5px solid var(--border);border-radius:11px;font-size:21px;font-weight:700;text-align:center;font-family:monospace;color:var(--text);background:var(--surface);outline:none;transition:all .13s}
.otp-d:focus{border-color:var(--p);box-shadow:0 0 0 3px var(--p10)}
.otp-d.filled{border-color:var(--p);background:var(--p10)}
.otp-d.ok{border-color:var(--p);background:var(--p10);color:var(--p)}
.otp-d.bad{border-color:#ef4444;background:#fef2f2;color:#ef4444}
.otp-sep{width:10px;height:2px;background:var(--border);border-radius:2px;align-self:center;flex-shrink:0}

/* hints */
.hint{font-size:11.5px;margin-top:5px;display:flex;align-items:center;gap:5px;line-height:1.4}
.hint.ok{color:var(--p)}.hint.err{color:#ef4444}.hint.info{color:var(--text3)}
.resend-row{display:flex;align-items:center;justify-content:center;gap:10px;margin-top:8px}
.resend-btn{background:none;border:none;cursor:pointer;color:var(--p);font-weight:600;font-size:12.5px;font-family:inherit;padding:0}
.resend-btn:hover{text-decoration:underline}
.resend-btn:disabled{color:var(--text3);cursor:not-allowed}
.timer-txt{font-size:12px;color:var(--text3)}

/* alerts */
.alert-err{display:flex;align-items:flex-start;gap:9px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:10px 13px;font-size:13px;color:#b91c1c;margin-bottom:16px}
.alert-err svg{width:14px;height:14px;flex-shrink:0;margin-top:1px}
.alert-ok{display:flex;align-items:center;gap:9px;background:var(--p10);border:1px solid var(--p20);border-radius:10px;padding:10px 13px;font-size:13px;color:var(--p);margin-bottom:16px}

/* divider */
.sec-div{height:1px;background:var(--border);margin:18px 0}

/* password bar */
.pw-bar{height:4px;background:var(--border);border-radius:99px;margin-top:6px;overflow:hidden}
#fp-pw-fill{height:100%;width:0;transition:all .3s;border-radius:99px}

/* submit */
.btn-main{width:100%;padding:12px 20px;background:var(--p);color:#fff;border:none;border-radius:11px;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .18s;display:flex;align-items:center;justify-content:center;gap:8px;margin-top:4px;box-shadow:0 3px 14px var(--p30)}
.btn-main:hover{background:var(--ph);transform:translateY(-1px);box-shadow:0 6px 22px var(--p30)}
.btn-main:disabled{opacity:.5;cursor:not-allowed;transform:none;box-shadow:none}

/* spinner */
.spin{width:15px;height:15px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spinR .7s linear infinite;flex-shrink:0}
@keyframes spinR{to{transform:rotate(360deg)}}

/* ── RIGHT ───────────────────────────────────── */
.fp-right{position:relative;overflow:hidden;background:linear-gradient(160deg,#f0fdf4 0%,#f8fafc 40%,#eff6ff 100%);display:flex;align-items:center;justify-content:center;padding:60px 48px}
.fp-right::before{content:'';position:absolute;inset:0;background-image:radial-gradient(circle,rgba(15,23,42,.07) 1px,transparent 1px);background-size:26px 26px;mask-image:radial-gradient(ellipse 90% 90% at 50% 50%,black 20%,transparent 100%)}
.fp-blob{position:absolute;border-radius:50%;filter:blur(80px);pointer-events:none;z-index:0}
.fp-blob-1{width:380px;height:380px;background:color-mix(in srgb,var(--primary,#16a34a) 12%,transparent);top:-10%;left:-5%}
.fp-blob-2{width:320px;height:320px;background:rgba(99,102,241,.1);bottom:-5%;right:-5%}
.fp-blob-3{width:240px;height:240px;background:rgba(6,182,212,.08);top:40%;right:15%}
.fp-r-inner{position:relative;z-index:1;width:100%;max-width:400px}

/* right panel cards */
.r-card{background:rgba(255,255,255,.85);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.9);border-radius:20px;padding:24px;box-shadow:var(--shadow-lg);margin-bottom:16px}
.r-card-title{font-size:15px;font-weight:800;color:var(--text);margin-bottom:14px;display:flex;align-items:center;gap:7px}
.r-card-title svg{width:16px;height:16px;color:var(--p)}

/* step visual */
.r-step-vis{display:flex;flex-direction:column;gap:12px}
.r-step-row{display:flex;align-items:flex-start;gap:12px}
.r-step-n{width:26px;height:26px;border-radius:8px;background:var(--p);color:#fff;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px}
.r-step-n.idle{background:var(--bg);border:1.5px solid var(--border);color:var(--text3)}
.r-step-txt{font-size:13px;color:var(--text2);line-height:1.55}
.r-step-txt strong{color:var(--text);font-weight:600}
.r-step-connector{width:2px;height:14px;background:var(--border);margin-left:12px;border-radius:2px}

/* tips */
.r-tip{display:flex;align-items:flex-start;gap:10px;margin-bottom:11px;font-size:13px;color:var(--text2);line-height:1.5}
.r-tip:last-child{margin-bottom:0}
.r-tip-ico{width:28px;height:28px;border-radius:8px;background:var(--bg);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.r-tip-ico svg{width:14px;height:14px;color:var(--p)}

/* trust chips */
.trust-row{display:flex;gap:7px;flex-wrap:wrap;margin-top:4px}
.trust-chip{display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.85);backdrop-filter:blur(8px);border:1px solid rgba(255,255,255,.9);border-radius:8px;padding:5px 11px;font-size:12px;font-weight:500;color:var(--text2);box-shadow:var(--shadow)}
.trust-chip svg{width:12px;height:12px;color:var(--p)}

/* responsive */
@media(max-width:960px){.fp-shell{grid-template-columns:1fr}.fp-right{display:none}.fp-left{box-shadow:none}}
@media(max-width:480px){.fp-scroll{padding:24px 24px 0}.fp-topnav,.fp-bot{padding-left:24px;padding-right:24px}}
</style>
</head>
<body>
<div class="fp-shell">

<!-- ══ LEFT: FORM ═══════════════════════════════ -->
<div class="fp-left">
  <div class="fp-topnav">
    <a href="<?= BASE_URL ?>/" class="fp-logo">
      <?php if (!empty(get_setting('site_logo', ''))) : ?>
    <img src="<?= htmlspecialchars(get_setting('site_logo', '')) ?>" alt="Logo" style="width: 200px;">
<?php else: ?>
    <div class="fp-logo-mark"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg></div>
      <span class="fp-logo-name"><?= APP_NAME ?></span>
<?php endif; ?>
    </a>
    <a href="<?= BASE_URL ?>/login.php" class="fp-back-link">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
      Back to login
    </a>
  </div>

  <div class="fp-scroll">

    <!-- 3-step indicator -->
    <div class="fp-steps">
      <div class="fp-steps-row">
        <div class="fp-step-wrap">
          <div class="fp-step-dot active" id="sd1">1</div>
        </div>
        <div class="fp-step-line" id="sl1"></div>
        <div class="fp-step-wrap">
          <div class="fp-step-dot idle" id="sd2">2</div>
        </div>
        <div class="fp-step-line" id="sl2"></div>
        <div class="fp-step-wrap">
          <div class="fp-step-dot idle" id="sd3">3</div>
        </div>
      </div>
      <div style="display:flex;margin-top:6px">
        <div style="flex:1;text-align:center" class="fp-step-label active" id="sl1-lbl">Email</div>
        <div style="flex:1;text-align:center" class="fp-step-label idle"   id="sl2-lbl">Verify OTP</div>
        <div style="flex:1;text-align:center" class="fp-step-label idle"   id="sl3-lbl">New Password</div>
      </div>
    </div>

    <div class="fp-head">
      <h1 id="fp-title">Reset your password</h1>
      <p id="fp-subtitle">Enter your email — we'll send a 6-digit reset code.</p>
    </div>

    <?php if ($error): ?>
    <div class="alert-err">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert-ok">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
      <?= htmlspecialchars($success) ?>
    </div>
    <script>setTimeout(function(){location.href='<?= BASE_URL ?>/login.php';},1800);</script>
    <?php else: ?>

    <form method="POST" id="fp-form" novalidate>
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="otp_value"  id="fp-otp-value">

      <!-- STEP 1: Email -->
      <div id="fp-s1">
        <div class="fi">
          <label class="fi-lbl">Email Address</label>
          <div class="email-row">
            <div class="fi-wrap" style="flex:1">
              <span class="fi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></span>
              <input class="fi-inp email-inp" type="email" id="fp-email" name="email"
                     placeholder="you@example.com" required autocomplete="email">
            </div>
            <button type="button" id="fp-send-btn" class="send-btn">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
              Send Code
            </button>
          </div>
          <div class="verified-badge" id="fp-verified">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            Email verified
          </div>
        </div>
      </div>

      <!-- STEP 2: OTP -->
      <div id="fp-s2" style="display:none">
        <div style="text-align:center;font-size:13px;color:var(--text2);margin-bottom:4px">Enter the 6-digit code sent to your email</div>
        <div class="otp-row">
          <input type="text" maxlength="1" inputmode="numeric" class="otp-d" autocomplete="one-time-code">
          <input type="text" maxlength="1" inputmode="numeric" class="otp-d">
          <input type="text" maxlength="1" inputmode="numeric" class="otp-d">
          <span class="otp-sep"></span>
          <input type="text" maxlength="1" inputmode="numeric" class="otp-d">
          <input type="text" maxlength="1" inputmode="numeric" class="otp-d">
          <input type="text" maxlength="1" inputmode="numeric" class="otp-d">
        </div>
        <div class="hint info" id="fp-otp-status" style="justify-content:center"></div>
        <div class="resend-row">
          <button type="button" id="fp-resend-btn" class="resend-btn" disabled>Resend code</button>
          <span id="fp-timer" class="timer-txt"></span>
        </div>
      </div>

      <!-- STEP 3: New Password -->
      <div id="fp-s3" style="display:none">
        <div class="sec-div"></div>
        <div class="fi">
          <label class="fi-lbl">New Password</label>
          <div class="fi-wrap">
            <span class="fi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
            <input class="fi-inp" type="password" id="fp-pw" name="password" placeholder="Min. 8 characters" required autocomplete="new-password">
            <button type="button" class="fi-eye" id="fp-eye1" onclick="toggleEye('fp-pw','fp-eye1')">
              <svg class="es" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg class="eh" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            </button>
          </div>
          <div class="pw-bar"><div id="fp-pw-fill"></div></div>
        </div>
        <div class="fi">
          <label class="fi-lbl">Confirm New Password</label>
          <div class="fi-wrap">
            <span class="fi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
            <input class="fi-inp" type="password" id="fp-pw2" name="confirm" placeholder="Repeat new password" required autocomplete="new-password">
            <button type="button" class="fi-eye" id="fp-eye2" onclick="toggleEye('fp-pw2','fp-eye2')">
              <svg class="es" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg class="eh" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            </button>
          </div>
          <div class="hint" id="fp-pw-hint"></div>
        </div>

        <?php if (get_setting('captcha_enabled','1')==='1'): ?>
        <div style="margin:12px 0">
          <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars(get_setting('captcha_site_key','')) ?>"></div>
        </div>
        <?php endif; ?>

        <button type="submit" id="fp-submit" class="btn-main">
          Reset Password
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
      </div>
    </form>

    <?php endif; ?>

    <div style="padding-bottom:24px"></div>
  </div><!-- /fp-scroll -->

  <!-- Bottom certs -->
  <div class="fp-bot">
    <div class="fp-cert"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>TLS 1.3</div>
    <span class="fp-cert-sep">|</span>
    <div class="fp-cert"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>End-to-End Encrypted</div>
    <span class="fp-cert-sep">|</span>
    <div class="fp-cert"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>OTP expires in 10 min</div>
  </div>
</div>

<!-- ══ RIGHT: INFO PANEL ══════════════════════════ -->
<div class="fp-right">
  <div class="fp-blob fp-blob-1"></div>
  <div class="fp-blob fp-blob-2"></div>
  <div class="fp-blob fp-blob-3"></div>
  <div class="fp-r-inner">

    <!-- How it works card -->
    <div class="r-card">
      <div class="r-card-title">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Secure Password Reset
      </div>
      <div class="r-step-vis">
        <div class="r-step-row" id="rr-s1">
          <div class="r-step-n" id="rr-n1">1</div>
          <div class="r-step-txt"><strong>Enter your email</strong> — we'll send a 6-digit OTP to verify it's you</div>
        </div>
        <div class="r-step-connector"></div>
        <div class="r-step-row">
          <div class="r-step-n idle" id="rr-n2">2</div>
          <div class="r-step-txt"><strong>Verify the OTP</strong> — enter the code from your inbox (expires in 10 min)</div>
        </div>
        <div class="r-step-connector"></div>
        <div class="r-step-row">
          <div class="r-step-n idle" id="rr-n3">3</div>
          <div class="r-step-txt"><strong>Set new password</strong> — choose a strong password and you're done</div>
        </div>
      </div>
    </div>

    <!-- Password tips -->
    <div class="r-card">
      <div class="r-card-title">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        Tips for a strong password
      </div>
      <div class="r-tip">
        <div class="r-tip-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg></div>
        <span>Use at least <strong>8 characters</strong> with uppercase and lowercase</span>
      </div>
      <div class="r-tip">
        <div class="r-tip-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
        <span>Add <strong>numbers</strong> and symbols like @, #, ! for extra strength</span>
      </div>
      <div class="r-tip">
        <div class="r-tip-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg></div>
        <span>Avoid using your <strong>name, email</strong>, or birth date</span>
      </div>
      <div class="r-tip">
        <div class="r-tip-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg></div>
        <span>Never reuse passwords across <strong>multiple services</strong></span>
      </div>
    </div>

    <!-- Trust chips -->
    <div class="trust-row">
      <div class="trust-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>TLS 1.3</div>
      <div class="trust-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>E2E Encrypted</div>
      <div class="trust-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>10 min expiry</div>
      <div class="trust-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>SOC 2</div>
    </div>

  </div>
</div>

</div><!-- .fp-shell -->

<div class="toast-wrap" id="toast-wrap"></div>
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
<script>
var OTP_VERIFIED=false,_resendTimer;

/* ── Step UI update ──────────────────────────── */
function setStep(n){
  var dots=[['sd1','active'],['sd2',n>=2?'active':'idle'],['sd3',n>=3?'active':'idle']];
  dots.forEach(function(d,i){
    var el=document.getElementById(d[0]);if(!el)return;
    var state=i+1<n?'done':i+1===n?'active':'idle';
    el.className='fp-step-dot '+state;
    el.innerHTML=i+1<n?'<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>':String(i+1);
  });
  ['sl1','sl2'].forEach(function(id,i){
    var el=document.getElementById(id);if(el)el.className='fp-step-line '+(i+1<n?'done':'');
  });
  var lbls=['sl1-lbl','sl2-lbl','sl3-lbl'];
  var states=['done','done','done'].map(function(_,i){return i+1<n?'done':i+1===n?'active':'idle';});
  lbls.forEach(function(id,i){
    var el=document.getElementById(id);if(!el)return;
    el.className='fp-step-label '+states[i];
  });
  /* right panel step numbers */
  ['rr-n1','rr-n2','rr-n3'].forEach(function(id,i){
    var el=document.getElementById(id);if(!el)return;
    if(i+1<n){el.className='r-step-n';el.innerHTML='<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>';}
    else if(i+1===n){el.className='r-step-n';el.textContent=i+1;}
    else{el.className='r-step-n idle';el.textContent=i+1;}
  });
  /* subtitles */
  var subs=['Enter your email — we\'ll send a 6-digit reset code.','Enter the 6-digit code sent to your email.','Choose a strong new password for your account.'];
  document.getElementById('fp-subtitle').textContent=subs[n-1];
}

/* ── Send OTP ────────────────────────────────── */
document.getElementById('fp-send-btn')?.addEventListener('click',function(){
  var email=document.getElementById('fp-email').value.trim();
  if(!email||!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){
    if(typeof toast==='function')toast('Enter a valid email address','err'); return;
  }
  var btn=this;
  btn.disabled=true;
  btn.innerHTML='<div class="spin"></div> Sending...';
  var fd=new FormData();
  fd.append('email',email);fd.append('mode','forgot');
  fd.append('csrf_token',document.querySelector('meta[name=csrf]').content);
  fetch(BASE_URL+'/includes/send_otp.php',{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(res){
      btn.disabled=false;
      btn.innerHTML='<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Resend';
      if(res.success||res.ok){
        document.getElementById('fp-email').readOnly=true;
        document.getElementById('fp-s2').style.display='block';
        setStep(2); startResendTimer(60);
        if(typeof toast==='function')toast('Code sent to '+email,'ok');
        document.querySelector('.otp-d')?.focus();
      } else {
        if(typeof toast==='function')toast(res.error||'Failed to send code','err');
      }
    })
    .catch(function(){
      btn.disabled=false;
      btn.innerHTML='<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Send Code';
      if(typeof toast==='function')toast('Network error','err');
    });
});

/* ── Resend ──────────────────────────────────── */
document.getElementById('fp-resend-btn')?.addEventListener('click',function(){
  var email=document.getElementById('fp-email').value.trim();
  this.disabled=true;
  var fd=new FormData();
  fd.append('email',email);fd.append('mode','forgot');fd.append('resend','1');
  fd.append('csrf_token',document.querySelector('meta[name=csrf]').content);
  fetch(BASE_URL+'/includes/send_otp.php',{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(res){
      if(res.success||res.ok){if(typeof toast==='function')toast('New code sent!','ok');startResendTimer(60);}
      else{if(typeof toast==='function')toast(res.error||'Failed','err');}
    });
});
function startResendTimer(sec){
  var btn=document.getElementById('fp-resend-btn'),lbl=document.getElementById('fp-timer');
  if(btn)btn.disabled=true;var s=sec;clearInterval(_resendTimer);tick();
  _resendTimer=setInterval(function(){
    s--;if(s<=0){clearInterval(_resendTimer);if(btn)btn.disabled=false;if(lbl)lbl.textContent='';}else tick();
  },1000);
  function tick(){if(lbl)lbl.textContent='Resend in '+s+'s';}
}

/* ── OTP digit inputs ────────────────────────── */
var otpDigs=document.querySelectorAll('.otp-d');
otpDigs.forEach(function(inp,i){
  inp.addEventListener('input',function(){
    inp.value=inp.value.replace(/\D/g,'').slice(-1);
    inp.classList.toggle('filled',!!inp.value);
    if(inp.value&&i<otpDigs.length-1)otpDigs[i+1].focus();
    if(Array.from(otpDigs).every(function(d){return d.value;}))fpAutoVerify();
  });
  inp.addEventListener('keydown',function(e){
    if(e.key==='Backspace'&&!inp.value&&i>0){otpDigs[i-1].value='';otpDigs[i-1].classList.remove('filled','ok','bad');otpDigs[i-1].focus();}
    if(e.key==='ArrowLeft'&&i>0)otpDigs[i-1].focus();
    if(e.key==='ArrowRight'&&i<otpDigs.length-1)otpDigs[i+1].focus();
  });
  inp.addEventListener('paste',function(e){
    var p=(e.clipboardData||window.clipboardData).getData('text').replace(/\D/g,'');
    if(p.length>=6){otpDigs.forEach(function(d,j){d.value=p[j]||'';d.classList.toggle('filled',!!d.value);});e.preventDefault();fpAutoVerify();}
  });
});

function fpAutoVerify(){
  var code=Array.from(otpDigs).map(function(d){return d.value;}).join('');
  if(code.length!==6)return;
  var st=document.getElementById('fp-otp-status');
  if(st){st.className='hint info';st.innerHTML='<span style="display:inline-block;width:10px;height:10px;border:2px solid var(--text3);border-top-color:var(--p);border-radius:50%;animation:spinR .7s linear infinite"></span> Verifying...';}
  var fd=new FormData();
  fd.append('email',document.getElementById('fp-email').value.trim());
  fd.append('otp',code);fd.append('mode','forgot');
  fd.append('csrf_token',document.querySelector('meta[name=csrf]').content);
  fetch(BASE_URL+'/includes/verify_otp.php',{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(res){
      if(res.success||res.ok){
        OTP_VERIFIED=true;clearInterval(_resendTimer);
        document.getElementById('fp-timer').textContent='';
        otpDigs.forEach(function(d){d.classList.add('ok');d.classList.remove('bad','filled');d.disabled=true;});
        if(st){st.className='hint ok';st.innerHTML='<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Code verified!';}
        document.getElementById('fp-otp-value').value=code;
        var vb=document.getElementById('fp-verified');if(vb)vb.classList.add('show');
        document.getElementById('fp-s3').style.display='block';
        setStep(3);
        if(typeof toast==='function')toast('Verified! Set your new password.','ok');
        document.getElementById('fp-pw')?.focus();
      } else {
        otpDigs.forEach(function(d){d.classList.add('bad');d.classList.remove('ok');});
        if(st){st.className='hint err';st.innerHTML='<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Invalid code.';}
        setTimeout(function(){otpDigs.forEach(function(d){d.value='';d.classList.remove('bad','ok','filled');});otpDigs[0].focus();},1200);
      }
    }).catch(function(){if(st){st.className='hint err';st.textContent='Network error';}});
}

/* ── Password strength ───────────────────────── */
document.getElementById('fp-pw')?.addEventListener('input',function(){
  var v=this.value,s=0;
  if(v.length>=8)s++;if(/[A-Z]/.test(v))s++;if(/[0-9]/.test(v))s++;if(/[^A-Za-z0-9]/.test(v))s++;
  var f=document.getElementById('fp-pw-fill');if(!f)return;
  if(s<=1){f.style.width='33%';f.style.background='#ef4444';}
  else if(s===2){f.style.width='66%';f.style.background='#f59e0b';}
  else{f.style.width='100%';f.style.background='#22c55e';}
});

/* ── Password confirm ────────────────────────── */
document.getElementById('fp-pw2')?.addEventListener('input',function(){
  var pw=document.getElementById('fp-pw').value,h=document.getElementById('fp-pw-hint');if(!h)return;
  if(this.value===pw){h.className='hint ok';h.innerHTML='<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Passwords match';}
  else{h.className='hint err';h.innerHTML='<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Passwords don\'t match';}
});

/* ── Eye toggle ──────────────────────────────── */
function toggleEye(inputId,btnId){
  var inp=document.getElementById(inputId),btn=document.getElementById(btnId);if(!inp||!btn)return;
  inp.type=inp.type==='password'?'text':'password';
  btn.querySelector('.es').style.display=inp.type==='text'?'none':'';
  btn.querySelector('.eh').style.display=inp.type==='text'?'':'none';
}

/* ── Submit spinner ──────────────────────────── */
document.getElementById('fp-form')?.addEventListener('submit',function(){
  var btn=document.getElementById('fp-submit');
  if(btn){btn.disabled=true;btn.innerHTML='<div class="spin"></div> Resetting...';}
});
</script>
</body>
</html>