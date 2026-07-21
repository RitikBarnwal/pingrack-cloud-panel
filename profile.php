<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/servers.php';
require_once __DIR__ . '/includes/currency.php';
require_once __DIR__ . '/includes/totp.php';
require_once __DIR__ . '/includes/email_otp.php';
require_login();

extract((function(){
    $user=current_user();$currency=strtoupper($user['currency']??'USD');
    return['user'=>$user,'app_name'=>APP_NAME,'currency'=>$currency,'curr_sym'=>currency_symbol($currency),
    'avatar'=>strtoupper(mb_substr($user['full_name']?:$user['username'],0,1)),
    'fname'=>htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username'])),
    'uname'=>htmlspecialchars($user['username']),
    'balance'=>(float)$user['wallet_balance'],'csrf'=>csrf_token()];
})());

$msg = ''; $err = '';
$tab = $_GET['tab'] ?? 'profile';

// ── POST handlers ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    // Update profile info
    if ($action === 'update_profile') {
        //$full_name  = trim($_POST['full_name']  ?? '');
        $phone      = trim($_POST['phone']      ?? '');
        $apartments = trim($_POST['apartments'] ?? '');
        //$city       = trim($_POST['city']       ?? '');
        //$state      = trim($_POST['state']      ?? '');
        //$pincode    = trim($_POST['pincode']    ?? '');
        //$landmark   = trim($_POST['landmark']   ?? '');

        db()->prepare(
            'UPDATE users SET phone=?,apartments=?, WHERE id=?'
        )->execute([$phone,$apartments,$user['id']]);

        $msg = 'Profile updated successfully.';
        $user = current_user(); // refresh
        //$fname = htmlspecialchars($user['full_name'] ?: $user['username']);
        //$avatar= strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
    }

    // Change password
    if ($action === 'change_password') {
        $current  = $_POST['current_password']  ?? '';
        $new_pw   = $_POST['new_password']       ?? '';
        $confirm  = $_POST['confirm_password']   ?? '';

        if (!verify_password($current, $user['password'])) {
            $err = 'Current password is incorrect.';
        } elseif (strlen($new_pw) < 8) {
            $err = 'New password must be at least 8 characters.';
        } elseif ($new_pw !== $confirm) {
            $err = 'Passwords do not match.';
        } else {
            db()->prepare('UPDATE users SET password=? WHERE id=?')
               ->execute([hash_password($new_pw), $user['id']]);
            $msg = 'Password changed successfully.';
        }
    }

    // Update GST/company info
    if ($action === 'update_billing_info') {
        //$account_type = $_POST['account_type'] ?? 'individual';
        //$company_name = trim($_POST['company_name'] ?? '');
        //$gstin        = trim($_POST['gstin']        ?? '');
        //$country      = strtoupper(trim($_POST['country'] ?? 'IN'));

        // db()->prepare(
        //     'UPDATE users SET account_type=?,company_name=?,gstin=?,country=? WHERE id=?'
        // )->execute([$account_type,$company_name,$gstin,$country,$user['id']]);

        $msg = 'Billing information not updated.';
        $user = current_user();
    }

    // ── 2FA: Start setup — generate secret ───────────────────
    if ($action === 'totp_start_setup') {
        $secret = TOTP::generateSecret();
        $_SESSION['totp_pending_secret_' . $user['id']] = $secret;
        $msg = '__2fa_setup__'; // trigger UI to show QR
    }

    // ── 2FA: Confirm and enable TOTP ─────────────────────────
    if ($action === 'totp_confirm_enable') {
        $secret = $_SESSION['totp_pending_secret_' . $user['id']] ?? '';
        $code   = preg_replace('/\D/', '', trim($_POST['totp_code'] ?? ''));
        if (empty($secret)) {
            $err = 'Setup session expired. Please try again.';
        } elseif (!TOTP::verify($secret, $code)) {
            $err = 'Invalid code. Please check your authenticator app and try again.';
        } else {
            $plainCodes  = TOTP::generateRecoveryCodes(8);
            $hashedCodes = TOTP::hashRecoveryCodes($plainCodes);
            db()->prepare('UPDATE users SET totp_secret=?,totp_enabled=1,twofa_method=?,totp_recovery=? WHERE id=?')
               ->execute([$secret, 'totp', json_encode($hashedCodes), $user['id']]);
            unset($_SESSION['totp_pending_secret_' . $user['id']]);
            $_SESSION['totp_new_recovery_' . $user['id']] = $plainCodes;
            $user = current_user();
            $msg = '__2fa_enabled__';
        }
    }

    // ── 2FA: Enable Email OTP ─────────────────────────────────
    if ($action === 'email_2fa_enable') {
        $pw = $_POST['confirm_password'] ?? '';
        if (!verify_password($pw, $user['password'])) {
            $err = 'Incorrect password.';
        } else {
            db()->prepare('UPDATE users SET totp_enabled=1, twofa_method=? WHERE id=?')
               ->execute(['email', $user['id']]);
            $user = current_user();
            $msg  = '__email_2fa_enabled__';
        }
    }

    // ── 2FA: Switch method ────────────────────────────────────
    if ($action === 'twofa_switch_method') {
        $new_method = $_POST['new_method'] ?? '';
        $pw         = $_POST['confirm_password'] ?? '';
        if (!in_array($new_method, ['totp', 'email'])) {
            $err = 'Invalid method.';
        } elseif (!verify_password($pw, $user['password'])) {
            $err = 'Incorrect password.';
        } elseif ($new_method === 'totp') {
            // Switch to TOTP — need to set up QR first
            // Disable email first, then redirect to TOTP setup
            db()->prepare('UPDATE users SET twofa_method=? WHERE id=?')->execute(['totp', $user['id']]);
            $secret = TOTP::generateSecret();
            $_SESSION['totp_pending_secret_' . $user['id']] = $secret;
            $user = current_user();
            $msg = '__2fa_setup__';
        } else {
            // Switch to email — enable immediately
            db()->prepare('UPDATE users SET twofa_method=? WHERE id=?')->execute(['email', $user['id']]);
            $user = current_user();
            $msg  = '__email_2fa_enabled__';
        }
    }

    // ── 2FA: Disable ──────────────────────────────────────────
    if ($action === 'totp_disable') {
        $pw = $_POST['confirm_password'] ?? '';
        if (!verify_password($pw, $user['password'])) {
            $err = 'Incorrect password. 2FA was NOT disabled.';
        } else {
            db()->prepare('UPDATE users SET totp_secret=NULL,totp_enabled=0,twofa_method=NULL,totp_recovery=NULL WHERE id=?')
               ->execute([$user['id']]);
            $user = current_user();
            $msg = 'Two-factor authentication has been disabled.';
        }
    }

    // ── Logout a specific session ─────────────────────────────
    if ($action === 'logout_session') {
        $session_db_id = (int)($_POST['session_id'] ?? 0);
        $st = db()->prepare('SELECT * FROM login_sessions WHERE id=? AND user_id=? LIMIT 1');
        $st->execute([$session_db_id, $user['id']]);
        $sess = $st->fetch();
        if ($sess) {
            db()->prepare("UPDATE login_sessions SET is_active=0, is_current=0, logged_out_at=NOW() WHERE id=?")
               ->execute([$session_db_id]);
            $msg = 'Session logged out successfully.';
        }
    }

    // ── Logout all other sessions ─────────────────────────────
    if ($action === 'logout_all_other') {
        $current_token = $_SESSION['session_token'] ?? '';
        db()->prepare("UPDATE login_sessions SET is_active=0, is_current=0, logged_out_at=NOW() WHERE user_id=? AND token != ? AND is_active=1")
           ->execute([$user['id'], $current_token]);
        $msg = 'All other sessions have been logged out.';
    }
}

// ── AJAX: Profile picture upload ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_pic'])) {
    header('Content-Type: application/json');
    $csrf_in = $_POST['csrf_token'] ?? '';
    if (!verify_csrf($csrf_in)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF']); exit;
    }
    $file    = $_FILES['profile_pic'];
    $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
    $maxSize = 3 * 1024 * 1024;
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'Upload failed. Try again.']); exit;
    }
    if (!in_array(mime_content_type($file['tmp_name']), $allowed)) {
        echo json_encode(['ok' => false, 'error' => 'Only JPG, PNG, GIF, WEBP allowed.']); exit;
    }
    if ($file['size'] > $maxSize) {
        echo json_encode(['ok' => false, 'error' => 'Image must be under 3 MB.']); exit;
    }
    $upload_dir = '/www/uploads/pr_pic/';
    if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
    $old_pic = $user['user_profile'] ?? '';
    if ($old_pic && file_exists('/www/uploads/' . ltrim($old_pic, 'uploads/'))) {
    @unlink('/www/uploads/' . ltrim($old_pic, 'uploads/'));
}
    $mime = mime_content_type($file['tmp_name']);
    $ext  = $mime === 'image/png' ? 'png' : ($mime === 'image/gif' ? 'gif' : ($mime === 'image/webp' ? 'webp' : 'jpg'));
    $filename = 'u' . $user['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $upload_dir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        echo json_encode(['ok' => false, 'error' => 'Could not save file.']); exit;
    }
    $rel_path = 'pr_pic/' . $filename;
    db()->prepare('UPDATE users SET user_profile=? WHERE id=?')->execute([$rel_path, $user['id']]);
    echo json_encode(['ok' => true, 'url' => BASE_URL . '/serve-file.php?type=pr_pic&file=' . urlencode($filename)]);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Profile — <?= $app_name ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    .card{background:white;border:1px solid var(--border);border-radius:13px;overflow:hidden;margin-bottom:20px}
    .card-head{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
    .card-title{font-size:14px;font-weight:800;color:var(--gray-900)}
    .card-body{padding:22px}
    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    .form-grid.full{grid-template-columns:1fr}
    .flabel{display:block;font-size:12px;font-weight:700;color:var(--gray-700);margin-bottom:5px}
    .flabel span{font-weight:400;color:var(--gray-400)}
    .tabs{display:flex;gap:2px;background:var(--gray-100);border-radius:9px;padding:3px;margin-bottom:22px;width:fit-content}
    .tab-btn{padding:7px 18px;border-radius:7px;font-size:13.5px;font-weight:600;color:var(--gray-600);text-decoration:none;transition:all .13s}
    .tab-btn.active{background:white;color:var(--gray-900);box-shadow:0 1px 4px rgba(0,0,0,.08)}
    .avatar-big{width:72px;height:72px;border-radius:16px;background:var(--primary);display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:900;color:white;flex-shrink:0;position:relative;cursor:pointer;overflow:hidden}
    .avatar-big img{width:100%;height:100%;object-fit:cover;border-radius:16px;display:block}
    .avatar-cam{position:absolute;inset:0;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;border-radius:16px;opacity:0;transition:opacity .18s;pointer-events:none}
    .avatar-big:hover .avatar-cam{opacity:1}
    .avatar-upload-toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#1e293b;color:white;padding:10px 20px;border-radius:10px;font-size:13px;font-weight:600;z-index:9999;box-shadow:0 4px 20px rgba(0,0,0,.2);display:none;align-items:center;gap:8px}
    .save-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 20px;background:var(--primary);color:white;border:none;border-radius:9px;font-size:13.5px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .15s}
    .save-btn:hover{background:var(--primary-hover)}
    .danger-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 20px;background:var(--danger);color:white;border:none;border-radius:9px;font-size:13.5px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .15s}
    .danger-btn:hover{background:#b91c1c}
    @media(max-width:640px){.form-grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main-content" style="margin-left:260px;min-height:100vh;background:var(--gray-50)">
    <div class="mobile-bar">
      <button class="ham-btn" onclick="document.getElementById('sidebar').classList.toggle('open');document.getElementById('overlay').classList.toggle('open')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <span style="font-weight:800;font-size:15px">Profile</span>
    </div>
    <div class="topbar"><span class="topbar-title">Profile & Settings</span></div>

    <div style="padding:24px;max-width:740px">

      <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

      <!-- User header card -->
      <div class="card" style="margin-bottom:22px">
        <div class="card-body" style="display: flex;align-items: center;gap: 18px;flex-wrap: wrap">
          <?php
$profile_pic_url = !empty($user['user_profile'])
    ? BASE_URL . '/serve-file.php?type=pr_pic&file=' . urlencode(basename($user['user_profile']))
    : getGravatar($user['email'], $user['user_profile']);
?>
          <div class="avatar-big" onclick="document.getElementById('pic-input').click()" title="Change profile picture">
            <img src="<?= $profile_pic_url ?>" id="avatar-preview" alt="Avatar">
            <div class="avatar-cam">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
            </div>
          </div>
          <input type="file" id="pic-input" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none" onchange="uploadProfilePic(this)">
          <div>
            <div style="font-size:18px;font-weight:900;color:var(--gray-900)"><?= $fname ?></div>
            <div style="font-size:13px;color:var(--gray-500);margin-top:2px">@<?= $uname ?> · <?= htmlspecialchars($user['email']) ?></div>
            <div style="display:flex;gap:8px;margin-top:8px">
              <span class="badge badge-blue"><?= ucfirst($user['role']) ?></span>
              <span class="badge badge-green"><?= ucfirst($user['status']) ?></span>
              <span class="badge" style="background:var(--gray-100);color:var(--gray-600)"><?= strtoupper($user['currency'] ?? 'USD') ?></span>
              <span class="badge" style="background:var(--gray-100);color:var(--gray-600)">
                <img src="https://flagcdn.com/w20/<?= strtolower($user['country'] ?? 'in') ?>.png" width="14" style="border-radius:2px" onerror="this.style.display='none'">
                <?= strtoupper($user['country'] ?? 'IN') ?>
              </span>
            </div>
          </div>
          <style>
              .member-since-box{margin-left: auto;text-align: right}@media(max-width: 640px){.member-since-box{margin-left: 0;width: 100%;text-align: left;padding-top: 15px;border-top: 1px solid var(--gray-100)}}
          </style>
          <div class="member-since-box">
    <div style="font-size:11px;color:var(--gray-400);margin-bottom:3px">Member since</div>
    <div style="font-size:13px;font-weight:700;color:var(--gray-700)"><?= date('d M Y', strtotime($user['created_at'])) ?></div>
</div>
        </div>
      </div>

      <!-- Tabs -->
      <div class="tabs">
        <a href="?tab=profile" class="tab-btn <?= $tab==='profile'  ?'active':'' ?>">Personal Info</a>
        <a href="?tab=billing" class="tab-btn <?= $tab==='billing'  ?'active':'' ?>">Billing Info</a>
        <a href="?tab=security" class="tab-btn <?= $tab==='security' ?'active':'' ?>">Security</a>
      </div>

      <?php if ($tab === 'profile'): ?>
      <!-- ── PERSONAL INFO ── -->
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="update_profile">
        <div class="card">
          <div class="card-head"><span class="card-title">Personal Information</span></div>
          <div class="card-body">
            <div class="form-grid" style="margin-bottom:16px">
              <div>
                  <?php if ($user['account_type']==='organization') : ?>
                  <label class="flabel">Company Name</label>
                <input type="text" readonly class="form-control" value="<?= htmlspecialchars($user['company_name'] ?? '') ?>" placeholder="Your Company name">
                <?php else : ?>
                <label class="flabel">Full Name</label>
                <input type="text" readonly class="form-control" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" placeholder="Your full name">
                <?php endif; ?>
              </div>
              <div>
                <label class="flabel">Username <span>(cannot change)</span></label>
                <input type="text" class="form-control" value="<?= $uname ?>" disabled>
              </div>
            </div>
            <div class="form-grid" style="margin-bottom:16px">
              <div>
                <label class="flabel">Email <span>(cannot change)</span></label>
                <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" disabled>
              </div>
              <div>
                <label class="flabel">Phone</label>
                <input type="tel" name="phone" class="form-control" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="+91 99999 99999">
              </div>
            </div>
            <div style="margin-bottom:6px;font-size:12px;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:.7px">Address</div>
            <div class="form-grid full" style="margin-bottom:16px">
              <div>
                <label class="flabel">Apartment / Street</label>
                <input type="text" name="apartments" class="form-control" value="<?= htmlspecialchars($user['apartments'] ?? '') ?>" placeholder="House no., Street">
              </div>
            </div>
            <div class="form-grid" style="margin-bottom:16px">
              <div>
                <label class="flabel">City <span>(cannot change)</span></label>
                <input type="text" name="city" readonly class="form-control" value="<?= htmlspecialchars($user['city'] ?? '') ?>">
              </div>
              <div>
                <label class="flabel">State <span>(cannot change)</span></label>
                <input type="text" name="state" readonly class="form-control" value="<?= htmlspecialchars($user['state'] ?? '') ?>">
              </div>
            </div>
            <div class="form-grid" style="margin-bottom:20px">
              <div>
                <label class="flabel">PIN/ZIP Code <span>(cannot change)</span></label>
                <input type="text" name="pincode" readonly class="form-control" value="<?= htmlspecialchars($user['pincode'] ?? '') ?>">
              </div>
              <div>
                <label class="flabel">Landmark <span>(cannot change)</span></label>
                <input type="text" name="landmark" readonly class="form-control" value="<?= htmlspecialchars($user['landmark'] ?? '') ?>">
              </div>
            </div>
            <button type="submit" class="save-btn">Save Changes</button>
          </div>
        </div>
      </form>

      <?php elseif ($tab === 'billing'): ?>
      <!-- ── BILLING INFO ── -->
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="update_billing_info">
        <div class="card">
          <div class="card-head"><span class="card-title">Billing Information</span></div>
          <div class="card-body">
            <div class="form-grid full" style="margin-bottom:16px">
              <div>
                <label class="flabel">Account Type</label>
                <select readonly class="form-control">
                  <option value="individual" <?= ($user['account_type']??'')==='individual'?'selected':'' ?>>Individual</option>
                  <option value="organization" <?= ($user['account_type']??'')==='organization'?'selected':'' ?>>Organization / Company</option>
                </select>
              </div>
            </div>
            <div class="form-grid" style="margin-bottom:16px">
              <div>
                <label class="flabel">Company Name <span>(optional)</span></label>
                <input type="text" readonly class="form-control" value="<?= htmlspecialchars($user['company_name'] ?? '') ?>" placeholder="Your company name">
              </div>
              <div>
                <label class="flabel">GSTIN <span>(India, optional)</span></label>
                <input type="text" readonly class="form-control" value="<?= htmlspecialchars($user['gstin'] ?? '') ?>" placeholder="22AAAAA0000A1Z5" style="font-family:monospace">
              </div>
            </div>
            <div class="form-grid" style="margin-bottom:20px">
              <div>
                <label class="flabel">Country</label>
                <select readonly name="country" class="form-control">
                  <?php foreach (['IN'=>'India','US'=>'United States','GB'=>'United Kingdom','DE'=>'Germany','SG'=>'Singapore','AU'=>'Australia','CA'=>'Canada'] as $code => $name): ?>
                  <option value="<?= $code ?>" <?= ($user['country']??'IN')===$code?'selected':'' ?>><?= $name ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="flabel">Currency <span>(set at signup)</span></label>
                <input type="text" class="form-control" value="<?= strtoupper($user['currency'] ?? 'USD') ?>" disabled>
              </div>
            </div>
            <button type="submit" class="save-btn">Save Billing Info</button>
          </div>
        </div>
      </form>

      <?php elseif ($tab === 'security'): ?>
      <!-- ── SECURITY ── -->
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="change_password">
        <div class="card">
          <div class="card-head"><span class="card-title">Change Password</span></div>
          <div class="card-body">
            <div class="form-grid full" style="margin-bottom:16px">
              <div>
                <label class="flabel">Current Password</label>
                <input type="password" placeholder="Current Password" name="current_password" class="form-control" required autocomplete="current-password">
              </div>
            </div>
            <div class="form-grid" style="margin-bottom:20px">
              <div>
                <label class="flabel">New Password</label>
                <input type="password" placeholder="New Password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password" oninput="checkStrength(this)">
                <div id="pw-strength" style="height:3px;border-radius:99px;background:var(--gray-100);margin-top:6px;overflow:hidden">
                  <div id="pw-fill" style="height:100%;width:0;border-radius:99px;transition:all .3s"></div>
                </div>
              </div>
              <div>
                <label class="flabel">Confirm New Password</label>
                <input type="password" placeholder="Confirm New Password" name="confirm_password" class="form-control" required autocomplete="new-password">
              </div>
            </div>
            <button type="submit" class="save-btn">Change Password</button>
          </div>
        </div>
      </form>

      <div class="card">
        <div class="card-head"><span class="card-title">Account Info</span></div>
        <div class="card-body">
  <?php foreach ([
    ['User ID', '#' . $user['id'], 'badge badge-blue'],
    ['Role', ucfirst($user['role']), 'badge badge-yellow'],
    ['Status', ucfirst($user['status']), 'badge badge-green'],
    ['Joined', date('d M Y', strtotime($user['created_at'])), 'badge badge-purple'],
  ] as [$lbl,$val,$cls]): ?>
  <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--gray-100);font-size:13.5px">
    <span style="color:var(--gray-500)"><?= $lbl ?></span>
    <span class="<?= $cls ?>" style="font-weight:700"><?= htmlspecialchars($val) ?></span>
  </div>
  <?php endforeach; ?>
</div>
      </div>

      <!-- ── 2FA CARD ─────────────────────────────────────── -->
      <?php
        $totp_enabled   = !empty($user['totp_enabled']);
        $twofa_method   = $user['twofa_method'] ?? 'totp';
        $is_email_2fa   = $totp_enabled && $twofa_method === 'email';
        $is_totp_2fa    = $totp_enabled && $twofa_method === 'totp';
        $pending_secret = $_SESSION['totp_pending_secret_' . $user['id']] ?? null;
        $new_recovery   = $_SESSION['totp_new_recovery_'   . $user['id']] ?? null;

        if ($new_recovery && $msg === '__2fa_enabled__') {
            unset($_SESSION['totp_new_recovery_' . $user['id']]);
        }
        $show_setup_qr   = ($msg === '__2fa_setup__' && $pending_secret);
        $show_enabled_ok = ($msg === '__2fa_enabled__');
        $show_email_ok   = ($msg === '__email_2fa_enabled__');
      ?>

      <?php if ($show_enabled_ok && $new_recovery): ?>
      <div class="card" style="border-color:#bbf7d0">
        <div class="card-head" style="background:#f0fdf4">
          <span style="font-size:16px">🎉</span>
          <span class="card-title" style="color:#16a34a">2FA Enabled — Save Your Recovery Codes!</span>
        </div>
        <div class="card-body">
          <div class="alert alert-error" style="margin-bottom:16px;background:#fff7ed;border-color:#fed7aa;color:#9a3412">
            ⚠️ <strong>These codes will never be shown again.</strong> Store them in a safe place. Each code can only be used once.
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;background:var(--gray-50);border:1.5px solid var(--border);border-radius:10px;padding:18px;font-family:monospace">
            <?php foreach ($new_recovery as $rc): ?>
            <div style="background:white;border:1px solid var(--border);border-radius:7px;padding:8px 14px;font-size:14px;font-weight:700;letter-spacing:1px;text-align:center"><?= htmlspecialchars($rc) ?></div>
            <?php endforeach; ?>
          </div>
          <button onclick="copyRecovery()" class="save-btn" style="margin-top:14px;background:var(--gray-700)">📋 Copy All Codes</button>
          <script>
          function copyRecovery() {
            const codes = <?= json_encode($new_recovery) ?>;
            navigator.clipboard.writeText(codes.join('\n')).then(()=>alert('Recovery codes copied!'));
          }
          </script>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($show_email_ok): ?>
      <div class="alert alert-success">✓ Email 2FA enabled. You will receive an OTP on your email when logging in.</div>
      <?php endif; ?>

      <!-- ── 2FA CARD ───────────────────────────────────────── -->
      <div class="card">
        <div class="card-head">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
          <span class="card-title">Two-Factor Authentication (2FA)</span>
          <?php if ($totp_enabled): ?>
          <span style="margin-left:auto;background:#f0fdf4;color:#16a34a;font-size:11px;font-weight:800;padding:3px 10px;border-radius:99px;border:1px solid #bbf7d0">
            ✓ <?= $is_email_2fa ? 'EMAIL OTP' : 'AUTHENTICATOR APP' ?> ENABLED
          </span>
          <?php else: ?>
          <span style="margin-left:auto;background:var(--gray-100);color:var(--gray-500);font-size:11px;font-weight:700;padding:3px 10px;border-radius:99px">OFF</span>
          <?php endif; ?>
        </div>
        <div class="card-body">

          <?php if (!$totp_enabled && !$show_setup_qr): ?>
          <!-- ── State A: Not enabled — choose method ─────── -->
          <p style="font-size:13.5px;color:var(--gray-600);margin-bottom:20px;line-height:1.6">
            Add an extra layer of security to your account. Choose your preferred method:
          </p>

          <!-- Method cards -->
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:22px" id="method-grid">

            <!-- Email OTP -->
            <div class="method-card" id="mc-email" onclick="selectMethod('email')"
                 style="border:2px solid var(--border);border-radius:12px;padding:18px;cursor:pointer;transition:all .15s;background:white">
              <div style="font-size:26px;margin-bottom:8px">📧</div>
              <div style="font-weight:800;font-size:14px;color:var(--gray-900);margin-bottom:4px">Email OTP</div>
              <div style="font-size:12px;color:var(--gray-500);line-height:1.5">
                Get a 6-digit code sent to your email <strong><?= htmlspecialchars(substr($user['email'], 0, 3) . '***@' . explode('@', $user['email'])[1]) ?></strong> every time you log in.
              </div>
              <div style="margin-top:10px;font-size:11px;color:#f59e0b;font-weight:600">✓ Easy to use &nbsp;&nbsp; ✗ Requires email access</div>
            </div>

            <!-- TOTP App -->
            <div class="method-card" id="mc-totp" onclick="selectMethod('totp')"
                 style="border:2px solid var(--border);border-radius:12px;padding:18px;cursor:pointer;transition:all .15s;background:white">
              <div style="font-size:26px;margin-bottom:8px">📱</div>
              <div style="font-weight:800;font-size:14px;color:var(--gray-900);margin-bottom:4px">Authenticator App</div>
              <div style="font-size:12px;color:var(--gray-500);line-height:1.5">
                Use Google Authenticator, Authy, or any TOTP app. Works offline, more secure.
              </div>
              <div style="margin-top:10px;font-size:11px;color:#16a34a;font-weight:600">✓ More secure &nbsp;&nbsp; ✓ Works offline</div>
            </div>
          </div>

          <!-- Email OTP form -->
          <div id="form-email" style="display:none">
            <div class="alert alert-info" style="margin-bottom:14px;background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8">
              📧 An OTP will be sent to <strong><?= htmlspecialchars($user['email']) ?></strong> each time you sign in.
            </div>
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="email_2fa_enable">
              <?php if ($err && str_contains($err, 'password')): ?>
              <div class="alert alert-error" style="margin-bottom:12px"><?= htmlspecialchars($err) ?></div>
              <?php endif; ?>
              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <input type="password" name="confirm_password" class="form-control" placeholder="Confirm your password to enable"
                       style="max-width:260px" required autocomplete="current-password" autofocus>
                <button type="submit" class="save-btn">Enable Email 2FA</button>
                <button type="button" onclick="selectMethod(null)" class="btn btn-ghost btn-sm">Cancel</button>
              </div>
            </form>
          </div>

          <!-- TOTP setup form -->
          <div id="form-totp" style="display:none">
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="totp_start_setup">
              <button type="submit" class="save-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                Set Up Authenticator App
              </button>
              <button type="button" onclick="selectMethod(null)" class="btn btn-ghost btn-sm" style="margin-left:8px">Cancel</button>
            </form>
          </div>

          <style>
          .method-card.selected{border-color:var(--primary)!important;background:#f0fdf4!important;box-shadow:0 0 0 3px var(--primary-ring)}
          </style>
          <script>
          function selectMethod(m) {
            document.querySelectorAll('.method-card').forEach(c=>c.classList.remove('selected'));
            document.getElementById('form-email').style.display='none';
            document.getElementById('form-totp').style.display='none';
            if(m==='email'){document.getElementById('mc-email').classList.add('selected');document.getElementById('form-email').style.display='';}
            if(m==='totp') {document.getElementById('mc-totp').classList.add('selected'); document.getElementById('form-totp').style.display='';}
          }
          </script>

          <?php elseif ($show_setup_qr && $pending_secret): ?>
          <!-- ── State B: TOTP QR setup ─────────────────── -->
          <?php
            $qr_url  = TOTP::getQrUrl($pending_secret, $user['email'], APP_NAME);
            $otp_uri = TOTP::getOtpAuthUri($pending_secret, $user['email'], APP_NAME);
          ?>
          <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;margin-bottom:20px">
            <div style="flex-shrink:0;text-align:center">
              <img src="<?= htmlspecialchars($qr_url) ?>" width="180" height="180"
                   style="border:8px solid white;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.12)" alt="QR Code">
              <div style="font-size:11px;color:var(--gray-400);margin-top:6px">Scan with authenticator app</div>
            </div>
            <div style="flex:1;min-width:200px">
              <div style="font-size:13px;font-weight:700;color:var(--gray-800);margin-bottom:8px">Step 1 — Scan QR Code</div>
              <p style="font-size:13px;color:var(--gray-600);line-height:1.6;margin-bottom:12px">
                Open <strong>Google Authenticator</strong>, Authy, or any TOTP app and scan the QR code. Or enter the key manually:
              </p>
              <div style="background:var(--gray-50);border:1.5px solid var(--border);border-radius:8px;padding:10px 14px;font-family:monospace;font-size:13px;font-weight:700;letter-spacing:2px;word-break:break-all;color:var(--gray-900)">
                <?= htmlspecialchars($pending_secret) ?>
              </div>
              <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($pending_secret) ?>').then(()=>this.textContent='✓ Copied!')"
                      style="margin-top:8px;font-size:12px;color:var(--primary);background:none;border:none;cursor:pointer;font-weight:600;padding:0">📋 Copy Key</button>
            </div>
          </div>
          <div style="font-size:13px;font-weight:700;color:var(--gray-800);margin-bottom:10px">Step 2 — Enter Verification Code</div>
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="totp_confirm_enable">
            <?php if ($err): ?><div class="alert alert-error" style="margin-bottom:12px"><?= htmlspecialchars($err) ?></div><?php endif; ?>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              <input type="text" name="totp_code" class="form-control" placeholder="Enter 6-digit code"
                     style="max-width:180px;font-family:monospace;font-size:18px;text-align:center;letter-spacing:4px"
                     maxlength="6" inputmode="numeric" autocomplete="one-time-code" autofocus>
              <button type="submit" class="save-btn">Verify &amp; Enable 2FA</button>
            </div>
          </form>

          <?php elseif ($totp_enabled): ?>
          <!-- ── State C: Already enabled — show status + switch option ─ -->
          <div class="alert alert-success" style="margin-bottom:16px;background:#f0fdf4;border-color:#bbf7d0;color:#15803d">
            ✓ Two-factor authentication is active via
            <strong><?= $is_email_2fa ? '📧 Email OTP' : '📱 Authenticator App' ?></strong>.
          </div>

          <!-- Switch method option -->
          <div style="border:1.5px solid var(--border);border-radius:10px;padding:16px;margin-bottom:16px;background:var(--gray-50)">
            <div style="font-size:13px;font-weight:700;color:var(--gray-800);margin-bottom:12px">Switch 2FA Method</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
              <div onclick="selectSwitchMethod('email')" id="sw-email"
                   style="border:2px solid <?= $is_email_2fa ? 'var(--primary)' : 'var(--border)' ?>;background:<?= $is_email_2fa ? '#f0fdf4' : 'white' ?>;border-radius:9px;padding:12px;cursor:pointer;text-align:center;transition:all .15s">
                <div style="font-size:20px">📧</div>
                <div style="font-size:12.5px;font-weight:700;margin-top:4px">Email OTP</div>
                <?php if ($is_email_2fa): ?><div style="font-size:10px;color:var(--primary);font-weight:700">✓ Current</div><?php endif; ?>
              </div>
              <div onclick="selectSwitchMethod('totp')" id="sw-totp"
                   style="border:2px solid <?= $is_totp_2fa ? 'var(--primary)' : 'var(--border)' ?>;background:<?= $is_totp_2fa ? '#f0fdf4' : 'white' ?>;border-radius:9px;padding:12px;cursor:pointer;text-align:center;transition:all .15s">
                <div style="font-size:20px">📱</div>
                <div style="font-size:12.5px;font-weight:700;margin-top:4px">Authenticator App</div>
                <?php if ($is_totp_2fa): ?><div style="font-size:10px;color:var(--primary);font-weight:700">✓ Current</div><?php endif; ?>
              </div>
            </div>
            <form method="POST" id="switch-form" style="display:none">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="twofa_switch_method">
              <input type="hidden" name="new_method" id="new_method_val">
              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <input type="password" name="confirm_password" class="form-control" placeholder="Confirm password to switch"
                       style="max-width:240px" required autocomplete="current-password">
                <button type="submit" class="save-btn" id="switch-btn">Switch Method</button>
                <button type="button" onclick="closeSwitchForm()" class="btn btn-ghost btn-sm">Cancel</button>
              </div>
            </form>
            <script>
            function selectSwitchMethod(m) {
              var curr = '<?= $twofa_method ?>';
              document.getElementById('sw-email').style.borderColor = m==='email' ? 'var(--primary)' : 'var(--border)';
              document.getElementById('sw-email').style.background  = m==='email' ? '#f0fdf4' : 'white';
              document.getElementById('sw-totp').style.borderColor  = m==='totp'  ? 'var(--primary)' : 'var(--border)';
              document.getElementById('sw-totp').style.background   = m==='totp'  ? '#f0fdf4' : 'white';
              if (m === curr) { closeSwitchForm(); return; }
              document.getElementById('new_method_val').value = m;
              document.getElementById('switch-form').style.display = '';
              var label = m === 'email' ? 'Email OTP' : 'Authenticator App';
              document.getElementById('switch-btn').textContent = 'Switch to ' + label;
            }
            function closeSwitchForm() {
              document.getElementById('switch-form').style.display = 'none';
            }
            </script>
          </div>

          <!-- Disable 2FA -->
          <p style="font-size:13px;color:var(--gray-600);margin-bottom:10px">To completely disable 2FA, confirm your password:</p>
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="totp_disable">
            <?php if ($err): ?><div class="alert alert-error" style="margin-bottom:12px"><?= htmlspecialchars($err) ?></div><?php endif; ?>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              <input type="password" name="confirm_password" class="form-control" placeholder="Confirm your password"
                     style="max-width:240px" required autocomplete="current-password">
              <button type="submit" class="save-btn" style="background:#dc2626"
                      onclick="return confirm('Disable 2FA? Your account will be less secure.')">
                Disable 2FA
              </button>
            </div>
          </form>
          <?php endif; ?>

        </div>
      </div>
      <!-- ── END 2FA CARD ─────────────────────────────────── -->
      <?php endif; ?>

      <?php if ($tab === 'security'): ?>
      <!-- ── LOGIN ACTIVITY CARD ───────────────────────────── -->
      <?php
        try {
          $sessions_st = db()->prepare(
            "SELECT * FROM login_sessions WHERE user_id=? ORDER BY logged_in_at DESC LIMIT 20"
          );
          $sessions_st->execute([$user['id']]);
          $sessions = $sessions_st->fetchAll() ?: [];
          $current_token = $_SESSION['session_token'] ?? '';
        } catch (Throwable $e) {
          $sessions = [];
          $current_token = '';
        }
        $active_count  = count(array_filter($sessions, fn($s) => $s['is_active']));
        $other_active  = count(array_filter($sessions, fn($s) => $s['is_active'] && $s['token'] !== $current_token));
      ?>

      <div class="card" style="margin-top:18px">
        <div class="card-head" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
          <div style="display:flex;align-items:center;gap:8px">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
            <span class="card-title">Login Activity & Active Sessions</span>
            <?php if ($active_count > 0): ?>
            <span style="background:#eff6ff;color:#2563eb;font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px;border:1px solid #bfdbfe"><?= $active_count ?> active</span>
            <?php endif; ?>
          </div>
          <?php if ($other_active > 0): ?>
          <form method="POST" style="margin:0">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="logout_all_other">
            <button type="submit" class="btn btn-sm" style="background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;font-size:12px"
                    onclick="return confirm('Log out all other <?= $other_active ?> session(s)?')">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
              Log Out All Other Sessions (<?= $other_active ?>)
            </button>
          </form>
          <?php endif; ?>
        </div>
        <div class="card-body" style="padding:0">
          <?php if (empty($sessions)): ?>
          <div style="padding:32px;text-align:center;color:var(--gray-400)">
            <div style="font-size:32px;margin-bottom:10px">📱</div>
            <div style="font-size:13.5px">No login history yet. Sessions are tracked after your next login.</div>
          </div>
          <?php else: ?>
          <?php foreach ($sessions as $sess):
            $is_current = $sess['token'] === $current_token;
            $is_active  = (bool)$sess['is_active'];
            $device_icon = match($sess['device_type']) {
              'mobile'  => '📱',
              'tablet'  => '📟',
              'bot'     => '🤖',
              'desktop' => '💻',
              default   => '🖥️',
            };
            // Time calculations
            $login_ts      = strtotime($sess['logged_in_at']);
            $last_active_ts= strtotime($sess['last_active']);
            $logout_ts     = $sess['logged_out_at'] ? strtotime($sess['logged_out_at']) : null;
            $since = function(int $ts): string {
              $diff = time() - $ts;
              if ($diff < 60)     return 'Just now';
              if ($diff < 3600)   return (int)($diff/60) . 'm ago';
              if ($diff < 86400)  return (int)($diff/3600) . 'h ago';
              if ($diff < 604800) return (int)($diff/86400) . 'd ago';
              return date('d M Y', $ts);
            };
          ?>
          <div style="display:flex;align-items:flex-start;gap:14px;padding:16px 20px;border-bottom:1px solid var(--gray-100);<?= $is_current ? 'background:#f0fdf4;' : '' ?>">
            <!-- Device icon -->
            <div style="width:40px;height:40px;background:<?= $is_current?'#dcfce7':($is_active?'#eff6ff':'var(--gray-100)') ?>;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0">
              <?= $device_icon ?>
            </div>

            <!-- Info -->
            <div style="flex:1;min-width:0">
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:3px">
                <span style="font-weight:700;font-size:13.5px;color:var(--gray-900)"><?= htmlspecialchars($sess['device_name'] ?: 'Unknown Device') ?></span>
                <?php if ($is_current): ?>
                <span style="background:#dcfce7;color:#15803d;font-size:10px;font-weight:800;padding:2px 7px;border-radius:99px">✓ THIS DEVICE</span>
                <?php elseif ($is_active): ?>
                <span style="background:#eff6ff;color:#2563eb;font-size:10px;font-weight:700;padding:2px 7px;border-radius:99px">● ACTIVE</span>
                <?php else: ?>
                <span style="background:var(--gray-100);color:var(--gray-400);font-size:10px;font-weight:700;padding:2px 7px;border-radius:99px">○ SIGNED OUT</span>
                <?php endif; ?>
              </div>

              <div style="display:flex;flex-wrap:wrap;gap:12px;font-size:12px;color:var(--gray-500);margin-bottom:4px">
                <span>
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:middle"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                  <?= htmlspecialchars($sess['ip_address']) ?>
                </span>
                <?php if (!empty($sess['city']) || !empty($sess['country'])): ?>
                <span>
                  <?php if (!empty($sess['country'])): ?>
                  <img src="https://flagcdn.com/w20/<?= strtolower(htmlspecialchars($sess['country'])) ?>.png"
                       width="13" style="border-radius:2px;vertical-align:middle;margin-right:3px"
                       onerror="this.style.display='none'">
                  <?php endif; ?>
                  <?= htmlspecialchars(trim(($sess['city'] ?? '') . (!empty($sess['country']) ? ' · ' . strtoupper($sess['country']) : ''))) ?>
                </span>
                <?php endif; ?>
                <span>
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;vertical-align:middle"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                  Signed in <?= $since($login_ts) ?> · <?= date('d M Y, H:i', $login_ts) ?>
                </span>
                <?php if ($is_active && !$is_current): ?>
                <span style="color:#2563eb">
                  Last active <?= $since($last_active_ts) ?>
                </span>
                <?php elseif (!$is_active && $logout_ts): ?>
                <span>Signed out <?= $since($logout_ts) ?></span>
                <?php endif; ?>
              </div>

              <!-- OS + Browser chips -->
              <div style="display:flex;gap:5px;flex-wrap:wrap">
                <?php if ($sess['os_name']): ?>
                <span style="background:var(--gray-100);color:var(--gray-600);font-size:10.5px;padding:2px 7px;border-radius:5px;font-weight:600"><?= htmlspecialchars($sess['os_name']) ?></span>
                <?php endif; ?>
                <?php if ($sess['browser_name']): ?>
                <span style="background:var(--gray-100);color:var(--gray-600);font-size:10.5px;padding:2px 7px;border-radius:5px;font-weight:600"><?= htmlspecialchars($sess['browser_name']) ?></span>
                <?php endif; ?>
              </div>
            </div>

            <!-- Logout button (only for active non-current sessions) -->
            <?php if ($is_active && !$is_current): ?>
            <form method="POST" style="margin:0;flex-shrink:0">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="logout_session">
              <input type="hidden" name="session_id" value="<?= $sess['id'] ?>">
              <button type="submit" class="btn btn-ghost btn-sm" style="font-size:11.5px;color:var(--danger);border-color:#fca5a5"
                      onclick="return confirm('Log out this session?')">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Log Out
              </button>
            </form>
            <?php elseif ($is_current): ?>
            <a href="<?= BASE_URL ?>/logout.php" class="btn btn-ghost btn-sm" style="font-size:11.5px;flex-shrink:0">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
              Sign Out
            </a>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
      <!-- ── END LOGIN ACTIVITY ─────────────────────────────── -->
      <?php endif; ?>

    </div>
  </div>
</div>
<div class="overlay" id="overlay" onclick="document.getElementById('sidebar').classList.remove('open');this.classList.remove('open')"></div>

<script>
function checkStrength(inp) {
  var v = inp.value;
  var s = 0;
  if (v.length >= 8)  s++;
  if (/[A-Z]/.test(v)) s++;
  if (/[0-9]/.test(v)) s++;
  if (/[^A-Za-z0-9]/.test(v)) s++;
  var colors = ['#ef4444','#f97316','#eab308','#22c55e'];
  var fill = document.getElementById('pw-fill');
  fill.style.width = (s * 25) + '%';
  fill.style.background = colors[s-1] || '#e5e7eb';
}
</script>

<div class="avatar-upload-toast" id="upload-toast">
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
  <span id="toast-msg">Uploading...</span>
</div>

<script>
function uploadProfilePic(input) {
  var file = input.files[0];
  if (!file) return;

  // Client-side size check (3 MB)
  if (file.size > 3 * 1024 * 1024) {
    showToast('Image must be under 3 MB.', true); return;
  }

  // Instant preview
  var reader = new FileReader();
  reader.onload = function(e) {
    document.getElementById('avatar-preview').src = e.target.result;
  };
  reader.readAsDataURL(file);

  showToast('Uploading...', false, true);

  var fd = new FormData();
  fd.append('profile_pic', file);
  fd.append('csrf_token', '<?= csrf_token() ?>');

  fetch(window.location.pathname, { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(d) {
      if (d.ok) {
        showToast('✓ Profile picture updated!', false);
        // Update preview to server URL so cache is fresh
        document.getElementById('avatar-preview').src = d.url + '?t=' + Date.now();
      } else {
        showToast(d.error || 'Upload failed.', true);
        // Revert preview
        document.getElementById('avatar-preview').src = '<?= $profile_pic_url ?>';
      }
    })
    .catch(function() { showToast('Network error. Try again.', true); });

  // Reset so same file can be picked again
  input.value = '';
}

function showToast(msg, isErr, loading) {
  var t = document.getElementById('upload-toast');
  var m = document.getElementById('toast-msg');
  m.textContent = msg;
  t.style.background = isErr ? '#dc2626' : (loading ? '#1e293b' : '#16a34a');
  t.style.display = 'flex';
  if (!loading) setTimeout(function(){ t.style.display = 'none'; }, 3000);
}
</script>

</body>
</html>