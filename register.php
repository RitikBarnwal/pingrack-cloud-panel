<?php
require_once __DIR__ . '/includes/bootstrap.php';
session_start_safe();
if (is_logged_in()) { header('Location: '.BASE_URL.'/dashboard.php'); exit; }

// ── Capture referral code from URL ────────────────────────
if (!empty($_GET['ref']) && empty($_SESSION['ref_code'])) {
    $rc = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($_GET['ref'])));
    if (strlen($rc) >= 6 && strlen($rc) <= 12) {
        $_SESSION['ref_code'] = $rc;
    }
}
if (is_logged_in()) { header('Location: '.BASE_URL.'/dashboard.php'); exit; }

$org_allowed_setting = get_setting('organization_allowed', '0');
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
            $accType  = $_POST['account_type'] ?? 'individual';
            $username = trim($_POST['username']    ?? '');
            $fullname = trim($_POST['full_name']    ?? '');
            $company  = trim($_POST['company_name'] ?? '');
            $phone    = trim($_POST['full_phone']   ?? '');
            $email    = strtolower(trim($_POST['email'] ?? ''));
            $otp      = trim($_POST['otp_value']    ?? '');
            $password = $_POST['password']  ?? '';
            $confirm  = $_POST['confirm']   ?? '';
            $displayName = $accType === 'organization' ? $company : $fullname;

            $isGoogleSignup = !empty($_POST['google_signup']) && $_POST['google_signup'] === '1';
            $isGithubSignup = !empty($_POST['github_signup']) && $_POST['github_signup'] === '1';
            $isSocial       = $isGoogleSignup || $isGithubSignup;
            $googleSub      = trim($_POST['google_sub']   ?? '');
            $googleToken    = trim($_POST['google_token'] ?? '');
            $githubId       = trim($_POST['github_id']    ?? '');

            if (!in_array($accType, ['individual','organization'])) $error = 'Invalid account type.';
            elseif ($accType==='organization' && $org_allowed_setting=='0') $error = 'Organization registration is unavailable. Use Individual.';
            elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) $error = 'Username: 3–30 chars, letters/numbers/underscore.';
            elseif ($accType==='individual' && (empty($fullname)||strlen($fullname)<2)) $error = 'Please enter your full name.';
            elseif ($accType==='organization' && (empty($company)||strlen($company)<2)) $error = 'Please enter your company name.';
            elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Invalid email address.';
            elseif (empty($phone)) $error = 'Please enter your phone number.';
            elseif ($isSocial) {
                // Social signup — verify token server-side
                if ($isGoogleSignup) {
                    if (empty($googleToken)) { $error = 'Google authentication token missing.'; }
                    else {
                        $ctx  = stream_context_create(['http'=>['timeout'=>8,'header'=>'Authorization: Bearer '.$googleToken],'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
                        $gInfo = @file_get_contents('https://www.googleapis.com/oauth2/v3/userinfo', false, $ctx);
                        if ($gInfo) {
                            $gData = json_decode($gInfo, true);
                            if (empty($gData['email']) || strtolower($gData['email']) !== $email)
                                $error = 'Google account email does not match.';
                        } else { $error = 'Could not verify Google account. Please try again.'; }
                    }
                }
                if ($isGithubSignup && empty($githubId)) { $error = 'GitHub ID missing.'; }
            } else {
                if (strlen($password)<8) $error = 'Password must be at least 8 characters.';
                elseif ($password!==$confirm) $error = 'Passwords do not match.';
                elseif (empty($otp)||strlen($otp)!==6||!ctype_digit($otp)) $error = 'Invalid OTP.';
                else {
                    $otpKey  = 'otp_reg_'.md5($email);
                    $otpData = $_SESSION[$otpKey] ?? null;
                    if (!$otpData||!password_verify($otp,$otpData['hash'])||time()>$otpData['expires'])
                        $error = 'OTP invalid or expired. Please verify your email again.';
                    elseif (!($_SESSION['otp_verified_reg_'.md5($email)] ?? false))
                        $error = 'Please verify your OTP first.';
                }
            }

            if (!$error) {
                $st = db()->prepare('SELECT id FROM users WHERE username=? OR email=?');
                $st->execute([$username, $email]);
                if ($st->fetch()) { $error = 'Username or email already taken.'; }
                else {
                    // Social signup → random unusable password (user can set one later)
                    $hash = $isSocial
                        ? hash_password(bin2hex(random_bytes(24)))
                        : hash_password($password);
                    $ins = db()->prepare('INSERT INTO users (username,full_name,company_name,email,phone,password,account_type,role,apartments,city,state,pincode,country,currency,landmark,google_sub,github_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                    $ins->execute([$username,$displayName,($accType==='organization'?$company:null),$email,$phone,$hash,$accType,'user',$_POST['apartments']??null,$_POST['city']??null,$_POST['state']??null,$_POST['pincode']??null,$_POST['country_code']??null,$_POST['currency']??'USD',$_POST['landmark']??null,($isGoogleSignup?$googleSub:null),($isGithubSignup?$githubId:null)]);
                    if (!$isSocial) {
                        unset($_SESSION['otp_reg_'.md5($email)], $_SESSION['otp_verified_reg_'.md5($email)]);
                    }

                    // ── Process referral ─────────────────────────────────
                    $new_user_id = (int)db()->lastInsertId();
                    if (!empty($_SESSION['ref_code'])) {
                        $ref_code_used = $_SESSION['ref_code'];
                        $referrer = db()->prepare('SELECT id FROM users WHERE referral_code=? AND id!=? LIMIT 1');
                        $referrer->execute([$ref_code_used, $new_user_id]);
                        $referrer_row = $referrer->fetch();
                        if ($referrer_row) {
                            $referrer_id = (int)$referrer_row['id'];
                            // Ensure referral code on new user
                            do {
                                $ncode = strtoupper(substr(md5($new_user_id . uniqid('', true)), 0, 8));
                                $cex = db()->prepare('SELECT id FROM users WHERE referral_code=? LIMIT 1');
                                $cex->execute([$ncode]);
                            } while ($cex->fetch());
                            db()->prepare('UPDATE users SET referral_code=?, referred_by=? WHERE id=?')
                               ->execute([$ncode, $referrer_id, $new_user_id]);

                            // Record referral — per-currency bonus amounts
                            $reward_on = get_setting('referral_reward_on', 'register');

                            // Fetch referrer's currency
                            $ref_user_st = db()->prepare('SELECT currency FROM users WHERE id=? LIMIT 1');
                            $ref_user_st->execute([$referrer_id]);
                            $referrer_currency = strtoupper($ref_user_st->fetchColumn() ?: 'INR');

                            // Referee currency = just-registered user's currency
                            $referee_currency = strtoupper($currency ?? 'INR');

                            // Referrer gets bonus in THEIR currency
                            if ($referrer_currency === 'USD') {
                                $bonus_r = (float)get_setting('referral_bonus_referrer_usd', '10');
                            } else {
                                $bonus_r = (float)get_setting('referral_bonus_referrer_inr', get_setting('referral_bonus_referrer','100'));
                            }

                            // Referee gets bonus in THEIR currency
                            if ($referee_currency === 'USD') {
                                $bonus_e = (float)get_setting('referral_bonus_referee_usd', '5');
                            } else {
                                $bonus_e = (float)get_setting('referral_bonus_referee_inr', get_setting('referral_bonus_referee','50'));
                            }

                            // Store currencies separately in referral record
                            $r_curr = $referrer_currency; // for backward compat column

                            // currency column = "REFERRER_CURR|REFEREE_CURR" for cross-currency tracking
                            $ref_currency_str = $referrer_currency . '|' . $referee_currency;
                            db()->prepare(
                                'INSERT IGNORE INTO referrals (referrer_id, referee_id, status, referrer_bonus, referee_bonus, currency)
                                 VALUES (?,?,?,?,?,?)'
                            )->execute([$referrer_id, $new_user_id,
                                $reward_on === 'register' ? 'rewarded' : 'pending',
                                $bonus_r, $bonus_e, $ref_currency_str]);

                            if ($reward_on === 'register') {
                                // Reward both immediately
                                require_once __DIR__ . '/includes/servers.php';
                                wallet_credit($referrer_id, $bonus_r,
                                    'Referral bonus — friend joined ('.$username.')', 'referral', $new_user_id);
                                wallet_credit($new_user_id, $bonus_e,
                                    'Welcome bonus — referred by a friend', 'referral', $referrer_id);
                                db()->prepare('UPDATE referrals SET rewarded_at=NOW() WHERE referee_id=?')
                                   ->execute([$new_user_id]);
                            }
                        }
                        unset($_SESSION['ref_code']);
                    }

                    $success = 'Account created! Redirecting to login…';
                }
            }
        }
    }
}
$csrf = csrf_token();
$reg_allowed = get_setting('user_registration_allowed','0');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Create Account — <?= APP_NAME ?></title>
  <meta name="csrf" content="<?= $csrf ?>">
  <meta name="api-csrf" content="<?= $_SESSION['csrf_token'] ?>">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@18.1.1/build/css/intlTelInput.css">
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
  <?php if (get_setting('google_signin_enabled','0')==='1' && !empty(get_setting('google_client_id'))): ?>
  <script src="https://accounts.google.com/gsi/client" async defer></script>
  <?php endif; ?>
  <script>
    var BASE_URL         = "<?= BASE_URL ?>";
    var orgAvailable     = <?= (int)$org_allowed_setting ?>;
    var GOOGLE_CLIENT_ID = "<?= htmlspecialchars(get_setting('google_client_id','')) ?>";
    var REG_CSRF         = "<?= $csrf ?>";
  </script>
<style>
/* ════════════════════════════════════════════
   REGISTER PAGE — PREMIUM  (matches login.php)
════════════════════════════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --p:var(--primary,#16a34a);--ph:var(--primary-hover,#15803d);
  --p10:color-mix(in srgb,var(--primary,#16a34a) 10%,transparent);
  --p20:color-mix(in srgb,var(--primary,#16a34a) 20%,transparent);
  --p30:color-mix(in srgb,var(--primary,#16a34a) 30%,transparent);
  --text:#0f172a;--text2:#475569;--text3:#94a3b8;
  --border:#e8edf3;--bg:#f5f7fa;--surface:#fff;
  --radius:16px;
  --shadow:0 1px 3px rgba(15,23,42,.06),0 4px 16px rgba(15,23,42,.05);
  --shadow-lg:0 8px 32px rgba(15,23,42,.1),0 2px 8px rgba(15,23,42,.06);
}
html,body{min-height:100vh}
body{font-family:'Inter',-apple-system,sans-serif;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}

/* ── SHELL ──────────────────────────────────── */
.rg-shell{display:grid;grid-template-columns:500px 1fr;min-height:100vh}

/* ── LEFT ───────────────────────────────────── */
.rg-left{
  background:var(--surface);
  display:flex;flex-direction:column;
  box-shadow:4px 0 40px rgba(15,23,42,.06);
  position:relative;z-index:2;
}
.rg-topnav{
  padding:20px 48px 0;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;
}
.rg-logo{display:flex;align-items:center;gap:9px;text-decoration:none}
.rg-logo-mark{width:34px;height:34px;border-radius:10px;background:var(--p);display:flex;align-items:center;justify-content:center;box-shadow:0 3px 10px var(--p30)}
.rg-logo-mark svg{width:17px;height:17px;color:#fff}
.rg-logo-name{font-size:16px;font-weight:800;color:var(--text);letter-spacing:-.4px}
.rg-back{font-size:12.5px;font-weight:500;color:var(--text3);text-decoration:none;display:flex;align-items:center;gap:4px;transition:color .15s}
.rg-back:hover{color:var(--text2)}
.rg-scroll{flex:1;padding:28px 48px 0;overflow-y:auto}
.rg-bottom-bar{padding:16px 48px;border-top:1px solid var(--border);flex-shrink:0;display:flex;align-items:center;gap:14px}
.rg-cert{display:flex;align-items:center;gap:5px;font-size:11.5px;font-weight:500;color:var(--text3)}
.rg-cert svg{width:11px;height:11px;color:var(--text3)}
.rg-cert-sep{color:var(--border)}

/* ── FORM HEAD ──────────────────────────────── */
.rg-head{margin-bottom:22px}
.rg-head h1{font-size:22px;font-weight:800;color:var(--text);letter-spacing:-.5px;margin-bottom:4px}
.rg-head p{font-size:13.5px;color:var(--text2)}

/* ── ACCOUNT TYPE CARDS ─────────────────────── */
.type-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px}
.type-card{
  border:1.5px solid var(--border);border-radius:12px;padding:12px 14px;
  cursor:pointer;transition:all .15s;background:var(--surface);position:relative;
}
.type-card:hover{border-color:#cbd5e1;background:var(--bg)}
.type-card.selected{border-color:var(--p);background:var(--p10)}
.type-card.disabled{opacity:.5;cursor:not-allowed;pointer-events:none}
.type-card input[type=radio]{position:absolute;opacity:0;pointer-events:none}
.type-icon{font-size:20px;margin-bottom:5px}
.type-name{font-size:13px;font-weight:700;color:var(--text)}
.type-card.selected .type-name{color:var(--p)}
.type-hint{font-size:11px;color:var(--text3);margin-top:2px}
.org-unavail{font-size:10.5px;color:#ef4444;margin-top:3px;display:none}

/* ── FIELDS ─────────────────────────────────── */
.fi{margin-bottom:14px}
.fi-row{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:5px}
.fi-lbl{font-size:13px;font-weight:600;color:var(--text)}
.fi-link{font-size:12px;font-weight:500;color:var(--p);text-decoration:none;transition:opacity .15s}
.fi-link:hover{opacity:.75}
.fi-wrap{position:relative}
.fi-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text3);display:flex;pointer-events:none}
.fi-icon svg{width:14px;height:14px}
.fi-inp{
  width:100%;padding:10px 12px 10px 38px;
  border:1.5px solid var(--border);border-radius:11px;
  font-size:13.5px;font-family:inherit;color:var(--text);
  background:var(--surface);outline:none;transition:all .15s;-webkit-appearance:none;
}
.fi-inp:focus{border-color:var(--p);box-shadow:0 0 0 3px var(--p10)}
.fi-inp::placeholder{color:var(--text3);font-size:13px}
.fi-inp.no-pad{padding-left:12px}
.fi-inp.phone-inp{padding-left:88px !important}
.iti{width:100%}

/* Email row with verify button */
.email-row{display:flex;gap:0}
.email-inp{border-radius:11px 0 0 11px !important;border-right:none !important;flex:1}
.verify-btn{
  padding:10px 13px;border:1.5px solid var(--border);border-left:none;
  border-radius:0 11px 11px 0;background:var(--bg);
  color:var(--text2);font-size:13px;font-weight:600;font-family:inherit;
  cursor:pointer;white-space:nowrap;transition:all .15s;
  display:flex;align-items:center;gap:5px;
}
.verify-btn:hover{background:var(--p10);color:var(--p);border-color:var(--p)}
.verify-btn:disabled{opacity:.5;cursor:not-allowed}
.verified-badge{display:none;align-items:center;gap:4px;font-size:12px;font-weight:600;color:var(--p)}
.verified-badge svg{width:13px;height:13px}

/* Disposable warn */
.disp-warn{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:8px 12px;margin-top:7px;font-size:12.5px;color:#dc2626;display:none;align-items:center;gap:7px}
.disp-warn.show{display:flex}
.biz-hint{display:none;font-size:11.5px;color:var(--text3);margin-top:5px;align-items:center;gap:5px}
.biz-hint svg{width:12px;height:12px}

/* OTP digits */
.otp-row{display:flex;gap:7px;justify-content:center;margin:14px 0}
.otp-d{
  width:44px;height:52px;
  border:1.5px solid var(--border);border-radius:11px;
  font-size:20px;font-weight:700;text-align:center;font-family:monospace;
  color:var(--text);background:var(--surface);outline:none;
  transition:all .13s;
}
.otp-d:focus{border-color:var(--p);box-shadow:0 0 0 3px var(--p10)}
.otp-d.filled{border-color:var(--p);background:var(--p10)}
.otp-d.ok{border-color:var(--p);background:var(--p10);color:var(--p)}
.otp-d.bad{border-color:#ef4444;background:#fef2f2;color:#ef4444}

/* Hints / status */
.hint{font-size:11.5px;margin-top:5px;display:flex;align-items:center;gap:5px;line-height:1.4}
.hint.ok{color:var(--p)}.hint.err{color:#ef4444}.hint.info{color:var(--text3)}
.resend-row{display:flex;align-items:center;justify-content:center;gap:10px;margin-top:8px}
.resend-btn{background:none;border:none;cursor:pointer;color:var(--p);font-weight:600;font-size:12.5px;font-family:inherit;padding:0}
.resend-btn:hover{text-decoration:underline}
.resend-btn:disabled{color:var(--text3);cursor:not-allowed}
.timer-txt{font-size:12px;color:var(--text3)}

/* Divider */
.or-div{display:flex;align-items:center;gap:12px;margin:16px 0}
.or-div::before,.or-div::after{content:'';flex:1;height:1px;background:var(--border)}
.or-div span{font-size:11.5px;color:var(--text3);font-weight:500}
.sec-div{height:1px;background:var(--border);margin:18px 0}

/* Social buttons */
.social-btn{
  width:100%;display:flex;align-items:center;justify-content:center;gap:9px;
  padding:10px 14px;border:1.5px solid var(--border);border-radius:11px;
  background:var(--surface);font-size:13.5px;font-weight:600;font-family:inherit;
  color:var(--text);cursor:pointer;transition:all .15s;
  text-decoration:none;margin-bottom:9px;
}
.social-btn:hover{background:var(--bg);border-color:#d1d9e0}
.social-btn svg{width:17px;height:17px;flex-shrink:0}

/* Password strength */
.pw-bar{height:4px;background:var(--border);border-radius:99px;margin-top:6px;overflow:hidden}
#pw-fill{height:100%;width:0;transition:all .3s;border-radius:99px}

/* Alerts */
.alert-err{display:flex;align-items:flex-start;gap:9px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:10px 13px;font-size:13px;color:#b91c1c;margin-bottom:16px}
.alert-err svg{width:14px;height:14px;flex-shrink:0;margin-top:1px}
.alert-ok{display:flex;align-items:center;gap:9px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:10px 13px;font-size:13px;color:#15803d;margin-bottom:16px}
.alert-ok svg{width:14px;height:14px;flex-shrink:0}

/* Checkbox */
.check-row{display:flex;align-items:flex-start;gap:9px;cursor:pointer}
.check-row input[type=checkbox]{width:15px;height:15px;accent-color:var(--p);cursor:pointer;margin-top:2px;flex-shrink:0}
.check-row span{font-size:13px;color:var(--text2);line-height:1.5}
.check-row a{color:var(--p);text-decoration:none}
.check-row a:hover{text-decoration:underline}

/* Submit button */
.btn-main{
  width:100%;padding:12px 20px;background:var(--p);color:#fff;
  border:none;border-radius:11px;font-size:14px;font-weight:700;font-family:inherit;
  cursor:pointer;transition:all .18s;
  display:flex;align-items:center;justify-content:center;gap:8px;
  margin-top:4px;box-shadow:0 3px 14px var(--p30);
}
.btn-main:hover{background:var(--ph);transform:translateY(-1px);box-shadow:0 6px 22px var(--p30)}
.btn-main:active{transform:translateY(0)}
.btn-main:disabled{opacity:.5;cursor:not-allowed;transform:none;box-shadow:none}

/* Address section — pin lookup */
.pin-loader{font-size:12px;color:var(--text3);display:none;align-items:center;gap:6px;margin-top:5px}
.pin-loader .dot{width:10px;height:10px;border:2px solid var(--border);border-top-color:var(--p);border-radius:50%;animation:spinR .7s linear infinite}
.pin-fallback{background:#fef9ec;border:1px solid #fde68a;border-radius:8px;padding:8px 12px;font-size:12px;color:#92400e;display:none;margin-top:6px;align-items:center;gap:6px}
.pin-fallback svg{width:12px;height:12px;flex-shrink:0}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:12px}

/* Reg closed */
.closed-box{text-align:center;padding:32px 0}
.closed-icon{font-size:52px;margin-bottom:16px}
.closed-title{font-size:20px;font-weight:800;color:#ef4444;margin-bottom:8px}
.closed-sub{font-size:13.5px;color:var(--text2);margin-bottom:20px;line-height:1.6}
.closed-timer{background:var(--bg);border:1px dashed var(--border);border-radius:10px;padding:12px 16px;font-size:13.5px;color:var(--text2)}

/* Footer */
.rg-foot{text-align:center;font-size:13px;color:var(--text2);padding:16px 0 20px}
.rg-foot a{color:var(--p);font-weight:700;text-decoration:none}
.rg-foot a:hover{text-decoration:underline}

/* Spinner */
.spin{width:15px;height:15px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spinR .7s linear infinite;flex-shrink:0}
@keyframes spinR{to{transform:rotate(360deg)}}

/* ── RIGHT ───────────────────────────────────── */
.rg-right{
  position:relative;overflow:hidden;
  background:linear-gradient(160deg,#f0fdf4 0%,#f8fafc 40%,#eff6ff 100%);
  display:flex;align-items:center;justify-content:center;
  padding:60px 48px;
}
.rg-right::before{
  content:'';position:absolute;inset:0;
  background-image:radial-gradient(circle,rgba(15,23,42,.07) 1px,transparent 1px);
  background-size:26px 26px;
  mask-image:radial-gradient(ellipse 90% 90% at 50% 50%,black 20%,transparent 100%);
}
.rg-blob{position:absolute;border-radius:50%;filter:blur(80px);pointer-events:none;z-index:0}
.rg-blob-1{width:380px;height:380px;background:color-mix(in srgb,var(--primary,#16a34a) 12%,transparent);top:-10%;left:-5%}
.rg-blob-2{width:320px;height:320px;background:rgba(99,102,241,.1);bottom:-5%;right:-5%}
.rg-blob-3{width:240px;height:240px;background:rgba(6,182,212,.08);top:40%;right:15%}
.rg-right-inner{position:relative;z-index:1;width:100%;max-width:440px}

/* Steps card */
.r-card{background:rgba(255,255,255,.85);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.9);border-radius:20px;padding:24px;box-shadow:var(--shadow-lg);margin-bottom:16px}
.r-card-title{font-size:15px;font-weight:800;color:var(--text);margin-bottom:16px;display:flex;align-items:center;gap:7px}
.r-card-title svg{width:16px;height:16px;color:var(--p)}
.r-step{display:flex;align-items:flex-start;gap:11px;margin-bottom:13px}
.r-step:last-child{margin-bottom:0}
.r-step-n{width:24px;height:24px;border-radius:7px;background:var(--p);color:#fff;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px}
.r-step-txt{font-size:13px;color:var(--text2);line-height:1.55}
.r-step-txt strong{color:var(--text);font-weight:600}

/* Benefits */
.r-ben{display:flex;align-items:center;gap:10px;margin-bottom:11px;font-size:13px;color:var(--text2)}
.r-ben:last-child{margin-bottom:0}
.r-ben-ico{width:28px;height:28px;border-radius:8px;background:var(--bg);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.r-ben-ico svg{width:14px;height:14px;color:var(--p)}

/* Trust chips */
.trust-row{display:flex;gap:7px;flex-wrap:wrap;margin-top:16px}
.trust-chip{display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.85);backdrop-filter:blur(8px);border:1px solid rgba(255,255,255,.9);border-radius:8px;padding:5px 11px;font-size:12px;font-weight:500;color:var(--text2);box-shadow:var(--shadow)}
.trust-chip svg{width:12px;height:12px;color:var(--p)}

/* Responsive */
@media(max-width:960px){.rg-shell{grid-template-columns:1fr}.rg-right{display:none}.rg-left{box-shadow:none}}
@media(max-width:480px){.rg-scroll{padding:24px 24px 0}.rg-topnav,.rg-bottom-bar{padding-left:24px;padding-right:24px}}
</style>
</head>
<body>
<div class="rg-shell">

<!-- ══ LEFT: FORM ═══════════════════════════════ -->
<div class="rg-left">
  <!-- Top nav -->
  <div class="rg-topnav">
    <a href="<?= BASE_URL ?>/" class="rg-logo">
      <?php if (!empty(get_setting('site_logo', ''))) : ?>
    <img src="<?= htmlspecialchars(get_setting('site_logo', '')) ?>" alt="Logo" style="width: 200px;">
<?php else: ?>
    <div class="rg-logo-mark"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg></div>
      <span class="rg-logo-name"><?= APP_NAME ?></span>
<?php endif; ?>
    </a>
    <a href="<?= BASE_URL ?>/" class="rg-back">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
      Back
    </a>
  </div>

  <!-- Scrollable form -->
  <div class="rg-scroll">

  <?php if ($reg_allowed == '0'): ?>
    <div class="closed-box">
      <div class="closed-icon">🚫</div>
      <div class="closed-title">Registration Closed</div>
      <p class="closed-sub">New registrations are currently unavailable. Please check back later or contact support.</p>
      <div class="closed-timer">Redirecting to login in <strong id="rc-timer" style="color:#ef4444;font-size:17px">5</strong>s...</div>
    </div>
    <script>var _t=5,_iv=setInterval(function(){document.getElementById('rc-timer').textContent=--_t;if(_t<=0){clearInterval(_iv);location.href='<?= BASE_URL ?>/login.php';}},1000);</script>

  <?php elseif ($success): ?>
    <div class="alert-ok" style="margin-top:20px">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
      <?= htmlspecialchars($success) ?>
    </div>
    <script>setTimeout(function(){location.href='<?= BASE_URL ?>/login.php';},1800);</script>

  <?php else: ?>

    <div class="rg-head">
      <h1>Create your account</h1>
      <p>Free to start. No credit card needed.</p>
    </div>

    <?php if ($error): ?>
    <div class="alert-err">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="reg-form" autocomplete="off" novalidate>
      <input type="hidden" name="csrf_token"     value="<?= $csrf ?>">
      <input type="hidden" name="otp_value"      id="reg-otp-value">
      <input type="hidden" name="full_phone"     id="full-phone">
      <input type="hidden" name="google_signup"  id="reg-google-signup"  value="">
      <input type="hidden" name="google_sub"     id="reg-google-sub"     value="">
      <input type="hidden" name="google_token"   id="reg-google-token"   value="">
      <input type="hidden" name="github_signup"  id="reg-github-signup"  value="">
      <input type="hidden" name="github_id"      id="reg-github-id"      value="">

      <!-- Account type -->
      <div style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:8px">I am registering as</div>
      <div class="type-grid">
        <label class="type-card selected" id="card-individual" onclick="selectType('individual')">
          <input type="radio" name="account_type" value="individual" checked>
          <div class="type-icon">👤</div>
          <div class="type-name">Individual</div>
          <div class="type-hint">Personal / freelancer</div>
        </label>
        <label class="type-card" id="card-org" onclick="handleOrgClick(event)">
          <input type="radio" name="account_type" id="radio-org" value="organization">
          <div class="type-icon">🏢</div>
          <div class="type-name">Organization</div>
          <div class="type-hint">Company / team</div>
          <div class="org-unavail" id="org-msg">Not available</div>
        </label>
      </div>

      <!-- Username -->
      <div class="fi">
        <div class="fi-row"><label class="fi-lbl">Username</label></div>
        <div class="fi-wrap">
          <span class="fi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
          <input class="fi-inp" type="text" id="reg-username" name="username"
                 value="<?= htmlspecialchars($_POST['username']??'') ?>"
                 placeholder="yourname" required autofocus autocomplete="off">
        </div>
        <div class="hint info" id="username-hint"></div>
      </div>

      <!-- Full Name -->
      <div class="fi" id="field-fullname">
        <div class="fi-row"><label class="fi-lbl">Full Name</label></div>
        <div class="fi-wrap">
          <span class="fi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></span>
          <input class="fi-inp" type="text" id="reg-fullname" name="full_name"
                 value="<?= htmlspecialchars($_POST['full_name']??'') ?>"
                 placeholder="Your full name" required>
        </div>
      </div>

      <!-- Company Name -->
      <div class="fi" id="field-company" style="display:none">
        <div class="fi-row"><label class="fi-lbl">Company Name</label></div>
        <div class="fi-wrap">
          <span class="fi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg></span>
          <input class="fi-inp" type="text" id="reg-company" name="company_name"
                 value="<?= htmlspecialchars($_POST['company_name']??'') ?>"
                 placeholder="Your company name">
        </div>
      </div>

      <!-- Phone -->
      <div class="fi">
        <div class="fi-row"><label class="fi-lbl">Phone Number</label></div>
        <input type="tel" id="phone" name="phone_input" class="fi-inp phone-inp" required>
      </div>

      <!-- Email + Verify -->
      <div class="fi">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:5px">
          <label class="fi-lbl" id="email-lbl">Email Address</label>
          <span class="verified-badge" id="rg-email-ok">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            Verified
          </span>
        </div>
        <div class="email-row">
          <div class="fi-wrap" style="flex:1">
            <span class="fi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></span>
            <input class="fi-inp email-inp" type="email" id="reg-email" name="email"
                   value="<?= htmlspecialchars($_POST['email']??'') ?>"
                   placeholder="you@example.com" required>
          </div>
          <button type="button" id="reg-verify-btn" class="verify-btn">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/></svg>
            Verify
          </button>
        </div>
        <div class="disp-warn" id="disposable-warn">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          Disposable email not allowed.
        </div>
        <div class="biz-hint" id="biz-email-hint">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/></svg>
          Use a business email for organization accounts.
        </div>
      </div>

      <?php
        $g_on  = get_setting('google_signin_enabled','0')==='1' && !empty(get_setting('google_client_id'));
        $gh_on = get_setting('github_signin_enabled','0')==='1' && !empty(get_setting('github_client_id'));
      ?>
      <?php if ($g_on || $gh_on): ?>
      <div id="social-signup-row">
        <div class="or-div"><span>or sign up with</span></div>
        <?php if ($g_on): ?>
        <button type="button" class="social-btn" onclick="startGoogleSignup()">
          <svg viewBox="0 0 24 24" width="17" height="17"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
          Sign up with Google
        </button>
        <?php endif; ?>
        <?php if ($gh_on): ?>
        <a href="<?= BASE_URL ?>/includes/github_callback.php?action=register&csrf=<?= $csrf ?>" class="social-btn">
          <svg viewBox="0 0 24 24" width="17" height="17" fill="#1b1f23"><path d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844a9.59 9.59 0 012.504.337c1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.02 10.02 0 0022 12.017C22 6.484 17.522 2 12 2z"/></svg>
          Sign up with GitHub
        </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- OTP section -->
      <div id="reg-otp-section" style="display:none">
        <div style="text-align:center;font-size:13px;color:var(--text2);margin-bottom:4px">Enter the 6-digit code sent to your email</div>
        <div class="otp-row">
          <input class="otp-d" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]">
          <input class="otp-d" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]">
          <input class="otp-d" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]">
          <input class="otp-d" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]">
          <input class="otp-d" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]">
          <input class="otp-d" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]">
        </div>
        <div class="hint info" id="reg-otp-status" style="justify-content:center"></div>
        <div class="resend-row">
          <button type="button" id="reg-resend" class="resend-btn" disabled>Resend OTP</button>
          <span id="reg-timer" class="timer-txt"></span>
        </div>
      </div>

      <!-- After OTP verified -->
      <div id="reg-after-otp" style="display:none">
        <div class="sec-div"></div>

        <!-- Password -->
        <div id="step-password">
          <div class="fi">
            <div class="fi-row"><label class="fi-lbl">Password</label></div>
            <div class="fi-wrap">
              <span class="fi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
              <input class="fi-inp" type="password" id="reg-pw" name="password" placeholder="Min. 8 characters" required>
            </div>
            <div class="pw-bar"><div id="pw-fill"></div></div>
          </div>
          <div class="fi">
            <div class="fi-row"><label class="fi-lbl">Confirm Password</label></div>
            <div class="fi-wrap">
              <span class="fi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
              <input class="fi-inp" type="password" id="reg-pw2" name="confirm" placeholder="Repeat password" required>
            </div>
            <div class="hint" id="pw-hint"></div>
          </div>
        </div>

        <!-- Address -->
        <div id="step-address" style="display:none">
          <div class="fi">
            <div class="fi-row"><label class="fi-lbl">Address</label></div>
            <div class="fi-wrap">
              <span class="fi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg></span>
              <input class="fi-inp" type="text" name="apartments" id="reg-apartment" placeholder="Enter your address">
            </div>
          </div>
          <div class="fi">
            <div class="fi-row"><label class="fi-lbl">Pincode</label></div>
            <div class="fi-wrap">
              <span class="fi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/></svg></span>
              <input class="fi-inp" type="text" id="reg-pincode" name="pincode" placeholder="6-digit pincode">
            </div>
            <div class="pin-loader" id="pin-loading"><span class="dot"></span>Fetching location...</div>
            <div class="pin-fallback" id="pin-manual-fallback">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              Location auto-fill failed. Please enter manually.
            </div>
          </div>
        </div>

        <!-- Final step -->
        <div id="step-final" style="display:none">
          <div class="fi" id="landmark-select-wrap">
            <div class="fi-row"><label class="fi-lbl">Area / Landmark</label></div>
            <select class="fi-inp no-pad" id="reg-landmark" name="landmark"><option value="">Select area</option></select>
          </div>
          <div class="fi" id="landmark-manual-wrap" style="display:none">
            <div class="fi-row"><label class="fi-lbl">Area / Landmark</label></div>
            <input class="fi-inp no-pad" type="text" id="reg-landmark-manual" name="landmark" placeholder="Enter your area">
          </div>
          <div class="two-col">
            <div class="fi"><div class="fi-row"><label class="fi-lbl">City</label></div><input class="fi-inp no-pad" type="text" name="city" id="reg-city" readonly placeholder="Auto-filled"></div>
            <div class="fi"><div class="fi-row"><label class="fi-lbl">State</label></div><input class="fi-inp no-pad" type="text" name="state" id="reg-state" readonly placeholder="Auto-filled"></div>
          </div>
          <div class="two-col">
            <div class="fi"><div class="fi-row"><label class="fi-lbl">Country</label></div><input class="fi-inp no-pad" type="text" id="reg-country" readonly><input type="hidden" name="country_code" id="reg-country-code"></div>
            <div class="fi"><div class="fi-row"><label class="fi-lbl">Currency</label></div><input class="fi-inp no-pad" type="text" id="reg-currency" name="currency" readonly></div>
          </div>

          <div class="fi" style="margin-top:4px">
            <label class="check-row">
              <input type="checkbox" id="terms-check">
              <span>I agree to the <a href="<?= BASE_URL ?>/terms-of-service.php" target="_blank">Terms of Service</a> and <a href="<?= BASE_URL ?>/privacy-policy.php" target="_blank">Privacy Policy</a></span>
            </label>
          </div>

          <?php if (get_setting('captcha_enabled','1')==='1'): ?>
          <div style="margin:12px 0">
            <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars(get_setting('captcha_site_key','')) ?>"></div>
          </div>
          <?php endif; ?>

          <button type="submit" class="btn-main" id="reg-submit" disabled>
            Create Account
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
          </button>
        </div>
      </div>
    </form>

    <div class="rg-foot">Already have an account? <a href="<?= BASE_URL ?>/login.php">Sign in</a></div>

  <?php endif; ?>
  </div><!-- /rg-scroll -->

  <!-- Certs bar -->
  <div class="rg-bottom-bar">
    <div class="rg-cert"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>TLS 1.3</div>
    <span class="rg-cert-sep">|</span>
    <div class="rg-cert"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>SOC 2 Type II</div>
    <span class="rg-cert-sep">|</span>
    <div class="rg-cert"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>ISO 27001</div>
  </div>
</div>

<!-- ══ RIGHT: INFO PANEL ══════════════════════════ -->
<div class="rg-right">
  <div class="rg-blob rg-blob-1"></div>
  <div class="rg-blob rg-blob-2"></div>
  <div class="rg-blob rg-blob-3"></div>
  <div class="rg-right-inner">

    <!-- Steps -->
    <div class="r-card">
      <div class="r-card-title">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
        Get started in 4 steps
      </div>
      <div class="r-step"><div class="r-step-n">1</div><div class="r-step-txt"><strong>Create account</strong> — fill in details and verify your email with OTP</div></div>
      <div class="r-step"><div class="r-step-n">2</div><div class="r-step-txt"><strong>Add credits</strong> — top up wallet via UPI, cards, or net banking</div></div>
      <div class="r-step"><div class="r-step-n">3</div><div class="r-step-txt"><strong>Deploy server</strong> — choose plan, OS, and region in seconds</div></div>
      <div class="r-step"><div class="r-step-n">4</div><div class="r-step-txt"><strong>Take control</strong> — SSH in with full root access and go live</div></div>
    </div>

    <!-- Benefits -->
    <div class="r-card">
      <div class="r-card-title">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        What you get
      </div>
      <div class="r-ben"><div class="r-ben-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></div><span><strong>NVMe SSD</strong> — 10× faster than SATA drives</span></div>
      <div class="r-ben"><div class="r-ben-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/></svg></div><span><strong>IPv4 + IPv6</strong> — included on every server</span></div>
      <div class="r-ben"><div class="r-ben-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div><span><strong>Cloud Firewall</strong> — one-click protection</span></div>
      <div class="r-ben"><div class="r-ben-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></div><span><strong>INR billing</strong> — UPI, no forex fees</span></div>
      <div class="r-ben"><div class="r-ben-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div><span><strong>Real-time monitoring</strong> — CPU, RAM, bandwidth</span></div>
    </div>

    <!-- Trust -->
    <div class="trust-row">
      <div class="trust-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>TLS 1.3</div>
      <div class="trust-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>SOC 2 Type II</div>
      <div class="trust-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/></svg>ISO 27001</div>
      <div class="trust-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>99.99% Uptime</div>
    </div>

  </div>
</div>

</div><!-- .rg-shell -->

<div class="toast-wrap" id="toast-wrap"></div>
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@18.1.1/build/js/intlTelInput.min.js"></script>
<script>
var USER_CC='',OTP_VERIFIED=false,_resendTimer,_pincodeTimeout=null,_manualMode=false,_currentAccType='individual';

/* account type */
function selectType(t){
  document.getElementById('card-individual').classList.toggle('selected',t==='individual');
  document.getElementById('card-org').classList.toggle('selected',t==='organization');
  document.getElementById('field-fullname').style.display=t==='individual'?'':'none';
  document.getElementById('field-company').style.display=t==='organization'?'':'none';
  var r=document.querySelector('input[name=account_type][value='+t+']');if(r)r.checked=true;
  _currentAccType=t;
  var sr=document.getElementById('social-signup-row');if(sr)sr.style.display=t==='organization'?'none':'';
  document.getElementById('biz-email-hint').style.display=t==='organization'?'flex':'none';
  document.getElementById('email-lbl').textContent=t==='organization'?'Business Email':'Email Address';
}
function handleOrgClick(e){
  if(orgAvailable==0){e.preventDefault();if(typeof toast==='function')toast('Organization registration not available.','err');return false;}
  selectType('organization');
}
document.addEventListener('DOMContentLoaded',function(){
  if(orgAvailable==0){
    var card=document.getElementById('card-org'),radio=document.getElementById('radio-org'),msg=document.getElementById('org-msg');
    if(card)card.classList.add('disabled');if(radio)radio.disabled=true;if(msg)msg.style.display='block';
  }
});

/* intl-tel-input */
var iti;
window.addEventListener('load',function(){
  var ph=document.querySelector('#phone');if(!ph)return;
  iti=window.intlTelInput(ph,{
    utilsScript:'https://cdn.jsdelivr.net/npm/intl-tel-input@18.1.1/build/js/utils.js',
    initialCountry:'auto',separateDialCode:true,
    geoIpLookup:function(cb){
      fetch(BASE_URL+'/includes/api_handler.php?type=ip',{headers:{'X-CSRF-Token':document.querySelector('meta[name="api-csrf"]').content,'X-Requested-With':'XMLHttpRequest'}})
        .then(function(r){return r.json();}).then(function(d){cb(d.countryCode||'IN');}).catch(function(){cb('IN');});
    }
  });
  document.getElementById('reg-form').addEventListener('submit',function(e){
    if(iti&&iti.isValidNumber()){document.getElementById('full-phone').value=iti.getNumber();}
    else{e.preventDefault();if(typeof toast==='function')toast('Please enter a valid phone number','err');}
  });
});

/* IP detect */
fetch(BASE_URL+'/includes/api_handler.php?type=ip',{headers:{'X-CSRF-Token':document.querySelector('meta[name="api-csrf"]').content,'X-Requested-With':'XMLHttpRequest'}})
  .then(function(r){return r.json();}).then(function(d){
    if(d.status==='success'){
      USER_CC=d.countryCode;
      var cc=document.getElementById('reg-country-code'),cn=document.getElementById('reg-country'),cu=document.getElementById('reg-currency');
      if(cc)cc.value=d.countryCode;if(cn)cn.value=d.country;if(cu)cu.value=d.countryCode==='IN'?'INR':'USD';
    }
  }).catch(function(){});

/* resend timer */
function startRegResend(sec){
  if(OTP_VERIFIED)return;
  var btn=document.getElementById('reg-resend'),lbl=document.getElementById('reg-timer');
  if(btn)btn.disabled=true;var s=sec;clearInterval(_resendTimer);tick();
  _resendTimer=setInterval(function(){
    if(OTP_VERIFIED){clearInterval(_resendTimer);if(lbl)lbl.textContent='';return;}
    s--;if(s<=0){clearInterval(_resendTimer);if(btn)btn.disabled=false;if(lbl)lbl.textContent='';}else tick();
  },1000);
  function tick(){if(!OTP_VERIFIED&&lbl)lbl.textContent='Resend in '+s+'s';}
}

/* OTP digits */
var otpDigits=document.querySelectorAll('.otp-d');
otpDigits.forEach(function(inp,i){
  inp.addEventListener('input',function(){
    inp.value=inp.value.replace(/\D/g,'').slice(-1);
    inp.classList.toggle('filled',!!inp.value);
    if(inp.value&&i<otpDigits.length-1)otpDigits[i+1].focus();
    if(Array.from(otpDigits).every(function(d){return d.value;}))autoVerifyOtp();
  });
  inp.addEventListener('keydown',function(e){
    if(e.key==='Backspace'&&!inp.value&&i>0){otpDigits[i-1].value='';otpDigits[i-1].classList.remove('filled','ok','bad');otpDigits[i-1].focus();}
    if(e.key==='ArrowLeft'&&i>0)otpDigits[i-1].focus();
    if(e.key==='ArrowRight'&&i<otpDigits.length-1)otpDigits[i+1].focus();
  });
  inp.addEventListener('paste',function(e){
    var p=(e.clipboardData||window.clipboardData).getData('text').replace(/\D/g,'');
    if(p.length>=6){otpDigits.forEach(function(d,j){d.value=p[j]||'';d.classList.toggle('filled',!!d.value);});e.preventDefault();autoVerifyOtp();}
  });
});

function autoVerifyOtp(){
  var code=Array.from(otpDigits).map(function(d){return d.value;}).join('');
  if(code.length!==6)return;
  var st=document.getElementById('reg-otp-status');
  if(st){st.className='hint info';st.innerHTML='<span style="display:inline-block;width:10px;height:10px;border:2px solid var(--text3);border-top-color:var(--p);border-radius:50%;animation:spinR .7s linear infinite"></span> Verifying...';}
  var fd=new FormData();
  fd.append('email',document.getElementById('reg-email').value.trim());
  fd.append('otp',code);fd.append('mode','reg');
  fd.append('csrf_token',document.querySelector('meta[name=csrf]').content);
  fetch(BASE_URL+'/includes/verify_otp.php',{method:'POST',body:fd})
    .then(function(r){return r.json();}).then(function(res){
      if(res.success||res.ok){
        OTP_VERIFIED=true;clearInterval(_resendTimer);document.getElementById('reg-timer').textContent='';
        otpDigits.forEach(function(d){d.classList.add('ok');d.classList.remove('bad','filled');d.disabled=true;});
        if(st){st.className='hint ok';st.innerHTML='<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Email verified!';}
        document.getElementById('reg-otp-value').value=code;
        document.getElementById('reg-after-otp').style.display='block';
        document.getElementById('reg-verify-btn').style.display='none';
        var ok=document.getElementById('rg-email-ok');if(ok)ok.style.display='inline-flex';
        document.getElementById('reg-email').readOnly=true;
        if(typeof toast==='function')toast('Email verified!','ok');
      }else{
        otpDigits.forEach(function(d){d.classList.add('bad');d.classList.remove('ok');});
        if(st){st.className='hint err';st.innerHTML='<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Invalid code.';}
        setTimeout(function(){otpDigits.forEach(function(d){d.value='';d.classList.remove('bad','ok','filled');});otpDigits[0].focus();},1200);
      }
    }).catch(function(){if(st){st.className='hint err';st.textContent='Network error';}});
}

/* password strength */
document.getElementById('reg-pw')?.addEventListener('input',function(){
  var v=this.value,s=0;
  if(v.length>=8)s++;if(/[A-Z]/.test(v))s++;if(/[0-9]/.test(v))s++;if(/[^A-Za-z0-9]/.test(v))s++;
  var f=document.getElementById('pw-fill');if(!f)return;
  if(s<=1){f.style.width='33%';f.style.background='#ef4444';}
  else if(s===2){f.style.width='66%';f.style.background='#f59e0b';}
  else{f.style.width='100%';f.style.background='#22c55e';}
});

/* password confirm */
document.getElementById('reg-pw2')?.addEventListener('input',function(){
  var pw=document.getElementById('reg-pw').value,h=document.getElementById('pw-hint');if(!h)return;
  if(this.value===pw){
    h.className='hint ok';h.innerHTML='<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Passwords match';
    document.getElementById('step-address').style.display='block';
  }else{
    h.className='hint err';h.innerHTML='<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Passwords don\'t match';
    document.getElementById('step-address').style.display='none';
    document.getElementById('step-final').style.display='none';
  }
  validateForm();
});

/* pincode lookup */
function enableManualFallback(){
  _manualMode=true;
  document.getElementById('pin-loading').style.display='none';
  document.getElementById('pin-manual-fallback').style.display='flex';
  var c=document.getElementById('reg-city'),s=document.getElementById('reg-state');
  if(c){c.removeAttribute('readonly');c.placeholder='Enter city';}
  if(s){s.removeAttribute('readonly');s.placeholder='Enter state';}
  document.getElementById('landmark-select-wrap').style.display='none';
  document.getElementById('landmark-manual-wrap').style.display='block';
  var mi=document.getElementById('reg-landmark-manual'),se=document.getElementById('reg-landmark');
  if(mi)mi.name='landmark';if(se)se.removeAttribute('name');
  document.getElementById('step-final').style.display='block';
  validateForm();
}
document.getElementById('reg-pincode')?.addEventListener('input',function(){
  var pin=this.value.trim();
  var loader=document.getElementById('pin-loading');
  if(pin.length<6){if(loader)loader.style.display='none';if(_pincodeTimeout){clearTimeout(_pincodeTimeout);_pincodeTimeout=null;}return;}
  if(_manualMode)return;
  if(loader)loader.style.display='flex';
  if(_pincodeTimeout)clearTimeout(_pincodeTimeout);
  _pincodeTimeout=setTimeout(enableManualFallback,10000);
  fetch(BASE_URL+'/includes/api_handler.php?type=pincode&pin='+encodeURIComponent(pin)+'&country='+encodeURIComponent(USER_CC||'IN'),
    {headers:{'X-CSRF-Token':document.querySelector('meta[name="api-csrf"]').content,'X-Requested-With':'XMLHttpRequest'}})
    .then(function(r){return r.json();}).then(function(d){
      if(_pincodeTimeout){clearTimeout(_pincodeTimeout);_pincodeTimeout=null;}
      if(loader)loader.style.display='none';
      if(!d.postalCodes||!d.postalCodes.length){enableManualFallback();return;}
      _manualMode=false;
      document.getElementById('pin-manual-fallback').style.display='none';
      document.getElementById('landmark-select-wrap').style.display='block';
      document.getElementById('landmark-manual-wrap').style.display='none';
      var se=document.getElementById('reg-landmark'),mi=document.getElementById('reg-landmark-manual');
      if(se)se.name='landmark';if(mi)mi.removeAttribute('name');
      var fc=d.postalCodes[0];
      var ce=document.getElementById('reg-city'),st=document.getElementById('reg-state');
      if(ce){ce.setAttribute('readonly','');ce.value=fc.adminName2||'';}
      if(st){st.setAttribute('readonly','');st.value=fc.adminName1||'';}
      var lm=document.getElementById('reg-landmark');
      if(lm){lm.innerHTML='<option value="">Select area</option>';d.postalCodes.forEach(function(p){var o=document.createElement('option');o.value=p.placeName;o.textContent=p.placeName;lm.appendChild(o);});}
      document.getElementById('step-final').style.display='block';
    }).catch(function(){
      if(_pincodeTimeout){clearTimeout(_pincodeTimeout);_pincodeTimeout=null;}
      if(loader)loader.style.display='none';enableManualFallback();
    });
});

/* validate form */
function validateForm(){
  var isSocial=document.getElementById('reg-google-signup')?.value==='1'||document.getElementById('reg-github-signup')?.value==='1';
  var pw=document.getElementById('reg-pw')?.value||'',pw2=document.getElementById('reg-pw2')?.value||'';
  var city=document.getElementById('reg-city')?.value||'';
  var lm=document.getElementById('reg-landmark')?.value||document.getElementById('reg-landmark-manual')?.value||'';
  var trm=document.getElementById('terms-check')?.checked||false;
  var ok=isSocial?(city&&lm&&trm):(pw.length>=8&&pw===pw2&&city&&lm&&trm);
  var btn=document.getElementById('reg-submit');if(btn)btn.disabled=!ok;
}
['reg-pw','reg-pw2','reg-pincode','reg-landmark','reg-landmark-manual','terms-check'].forEach(function(id){
  var el=document.getElementById(id);
  if(el){el.addEventListener('input',validateForm);el.addEventListener('change',validateForm);}
});
validateForm();

/* Google signup */
function startGoogleSignup(){
  if(!GOOGLE_CLIENT_ID){alert('Google Sign-Up not configured.');return;}
  var client=google.accounts.oauth2.initTokenClient({
    client_id:GOOGLE_CLIENT_ID,scope:'openid email profile',
    callback:function(tr){
      if(tr.error)return;
      fetch('https://www.googleapis.com/oauth2/v3/userinfo',{headers:{'Authorization':'Bearer '+tr.access_token}})
        .then(function(r){return r.json();}).then(function(p){applyGoogleProfile(p,tr.access_token);});
    }
  });
  client.requestAccessToken();
}
window.startGoogleSignup=startGoogleSignup;

function applyGoogleProfile(p,token){
  var ne=document.getElementById('reg-fullname'),ee=document.getElementById('reg-email'),ue=document.getElementById('reg-username');
  if(ne)ne.value=p.name||'';
  if(ee){ee.value=p.email||'';ee.readOnly=true;}
  var base=((p.given_name||p.name||'').toLowerCase().replace(/[^a-z0-9]/g,'')||'user').substring(0,20);
  if(ue)ue.value=base+(Math.floor(Math.random()*9000)+1000);
  document.getElementById('reg-google-signup').value='1';
  document.getElementById('reg-google-sub').value=p.sub||'';
  document.getElementById('reg-google-token').value=token||'';
  OTP_VERIFIED=true;document.getElementById('reg-otp-value').value='000000';
  document.getElementById('reg-verify-btn').style.display='none';
  var ok=document.getElementById('rg-email-ok');if(ok)ok.style.display='inline-flex';
  var os=document.getElementById('reg-otp-section');if(os)os.style.display='none';
  var sr=document.getElementById('social-signup-row');if(sr)sr.style.display='none';
  var ps=document.getElementById('step-password');if(ps)ps.style.display='none';
  document.getElementById('reg-after-otp').style.display='block';
  document.getElementById('step-address').style.display='block';
  if(typeof toast==='function')toast('Google account connected!','ok');
  validateForm();
}

/* GitHub prefill */
(function(){
  var p=new URLSearchParams(window.location.search);
  if(p.get('social')==='github'&&p.get('gh_name')){
    var ne=document.getElementById('reg-fullname'),ee=document.getElementById('reg-email'),ue=document.getElementById('reg-username');
    var ghN=decodeURIComponent(p.get('gh_name')||''),ghE=decodeURIComponent(p.get('gh_email')||''),ghId=p.get('gh_id')||'',ghL=p.get('gh_login')||'';
    if(ne&&ghN)ne.value=ghN;if(ee&&ghE){ee.value=ghE;ee.readOnly=true;}
    if(ue){var bu=(ghL||ghN.toLowerCase().replace(/[^a-z0-9]/g,'')).substring(0,20)||'user';ue.value=bu+(Math.floor(Math.random()*900)+100);}
    document.getElementById('reg-github-signup').value='1';document.getElementById('reg-github-id').value=ghId;
    if(ghE){
      OTP_VERIFIED=true;document.getElementById('reg-otp-value').value='000000';
      document.getElementById('reg-verify-btn').style.display='none';
      var ok=document.getElementById('rg-email-ok');if(ok)ok.style.display='inline-flex';
      var os=document.getElementById('reg-otp-section');if(os)os.style.display='none';
    }
    var sr=document.getElementById('social-signup-row');if(sr)sr.style.display='none';
    var ps=document.getElementById('step-password');if(ps)ps.style.display='none';
    document.getElementById('reg-after-otp').style.display='block';
    document.getElementById('step-address').style.display='block';
    if(typeof toast==='function')toast('GitHub account connected!','ok');
    validateForm();
  }
  if(p.get('social')==='google'&&p.get('hint')){
    var ee=document.getElementById('reg-email');if(ee)ee.value=decodeURIComponent(p.get('hint'));
  }
})();

/* submit spinner */
document.getElementById('reg-form')?.addEventListener('submit',function(){
  var btn=document.getElementById('reg-submit');
  if(btn){btn.disabled=true;btn.innerHTML='<div class="spin"></div> Creating account...';}
});
</script>
</body>
</html>