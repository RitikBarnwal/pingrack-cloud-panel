<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/currency.php';   // must load before admin.php
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/providers.php';
require_once __DIR__ . '/../includes/os_icons.php';
require_admin();

// ── WA Direct Send AJAX ─────────────────────────────────────
if (!empty($_GET['ajax']) && $_GET['ajax'] === 'wa_send_direct') {
    header('Content-Type: application/json');
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok'=>false,'error'=>'Invalid CSRF token']); exit;
    }
    $phone   = preg_replace('/\D/', '', trim($_POST['phone'] ?? ''));
    $message = trim($_POST['message'] ?? '');
    if (!$phone || !$message) {
        echo json_encode(['ok'=>false,'error'=>'Phone and message required']); exit;
    }
    $wa_api   = get_setting('wa_api')   ?? '';
    $wa_token = get_setting('wa_token') ?? '';
    if (!$wa_api || !$wa_token) {
        echo json_encode(['ok'=>false,'error'=>'WhatsApp API not configured. Set wa_api and wa_token in Settings.']); exit;
    }
    $url = rtrim($wa_api,'/') . '?number=' . urlencode($phone) . '&type=text&message=' . urlencode($message) . '&token=' . urlencode($wa_token);
    $ch = curl_init();
    curl_setopt_array($ch,[CURLOPT_URL=>$url,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_FOLLOWLOCATION=>true]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) { echo json_encode(['ok'=>false,'error'=>'cURL: '.$err]); exit; }
    if ($code >= 200 && $code < 300) {
        $j = json_decode($resp,true);
        if (is_array($j) && (!empty($j['error']) || (isset($j['success']) && !$j['success']) || (isset($j['status']) && strtolower($j['status'])==='error'))) {
            echo json_encode(['ok'=>false,'error'=>'API error: '.($j['message']??$j['error']??'Unknown')]); exit;
        }
        echo json_encode(['ok'=>true]); exit;
    }
    echo json_encode(['ok'=>false,'error'=>"HTTP $code from WA API"]); exit;
}

$user     = current_user();
$app_name = APP_NAME;
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$csrf     = csrf_token();

$tab = $_GET['tab'] ?? 'overview';

// ── Orders tab POST ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    // Generate claim token for an order
    if ($action === 'generate_claim_token') {
        $order_id    = (int)($_POST['order_id']    ?? 0);
        $provider_id = (int)($_POST['provider_id'] ?? 0);
        $vps_id      = trim($_POST['vps_id']       ?? '');
        $server_name = trim($_POST['server_name']  ?? '');
        $vcpu        = (int)($_POST['vcpu']        ?? 1);
        $ram_gb      = (float)($_POST['ram_gb']    ?? 1);
        $disk_gb     = (int)($_POST['disk_gb']     ?? 25);
        $os_label    = trim($_POST['os_label']     ?? '');
        $region_slug = trim($_POST['region_slug']  ?? '');
        $region_lbl  = trim($_POST['region_label'] ?? '');
        $price_hourly= (float)($_POST['price_hourly'] ?? 0);
        $currency    = strtoupper(trim($_POST['currency'] ?? 'INR'));
        $expires_days= (int)($_POST['expires_days'] ?? 0);

        if (!$provider_id || !$vps_id) {
            $_SESSION['admin_err'] = 'Provider and VPS ID are required.';
        } else {
            // Detect provider type from DB for claim token
            $prov_type_stmt = db()->prepare('SELECT provider_type FROM providers WHERE id=? LIMIT 1');
            $prov_type_stmt->execute([$provider_id]);
            $prov_type_for_claim = strtolower($prov_type_stmt->fetchColumn() ?: 'virtualizor');
            // Generate unique token
            $token = 'CLAIM-' . strtoupper(bin2hex(random_bytes(5)));
            $expires = $expires_days > 0 ? date('Y-m-d H:i:s', strtotime("+{$expires_days} days")) : null;

            db()->prepare(
                'INSERT INTO server_claim_tokens
                 (token, provider_type, provider_id, vps_id, server_name, vcpu, ram_gb, disk_gb,
                  os_label, region_slug, region_label, price_hourly, currency, custom_order_id, expires_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $token, $prov_type_for_claim, $provider_id, $vps_id, $server_name,
                $vcpu, $ram_gb, $disk_gb, $os_label, $region_slug, $region_lbl,
                $price_hourly, $currency, $order_id ?: null, $expires,
            ]);

            // Update order status if linked
            if ($order_id) {
                db()->prepare("UPDATE custom_orders SET status='processing' WHERE id=?")->execute([$order_id]);
            }

            // Email token to user if order_id given
            if ($order_id) {
                try {
                    $ord = db()->prepare('SELECT co.*, u.email, u.full_name, u.username FROM custom_orders co JOIN users u ON u.id=co.user_id WHERE co.id=? LIMIT 1');
                    $ord->execute([$order_id]);
                    $ord_data = $ord->fetch();
                    if ($ord_data) {
                        require_once __DIR__ . '/../includes/mailer.php';
                        _send_claim_token_email($ord_data, $token, $expires);
                    }
                } catch(Throwable $e) { error_log('[claim token email] ' . $e->getMessage()); }
            }

            $_SESSION['admin_msg'] = "✓ Claim token generated: <strong>{$token}</strong>";
        }
        $tab = 'orders';
    }

    // Update order status
    if ($action === 'update_order_status') {
        $order_id = (int)($_POST['order_id'] ?? 0);
        $status   = $_POST['order_status'] ?? '';
        $note     = trim($_POST['admin_note'] ?? '');
        if ($order_id && in_array($status, ['pending','processing','fulfilled','cancelled'])) {
            db()->prepare("UPDATE custom_orders SET status=?, admin_note=? WHERE id=?")->execute([$status, $note, $order_id]);
        }
        $tab = 'orders';
    }

    // ── Referrals ─────────────────────────────────────────────
    if ($action === 'save_referral_settings') {
        $fields = [
            'referral_enabled', 'referral_reward_on',
            // INR settings
            'referral_bonus_referrer_inr', 'referral_bonus_referee_inr', 'referral_min_topup_inr',
            // USD settings
            'referral_bonus_referrer_usd', 'referral_bonus_referee_usd', 'referral_min_topup_usd',
            // Legacy (kept for backward compat)
            'referral_bonus_referrer', 'referral_bonus_referee', 'referral_min_topup', 'referral_currency',
        ];
        foreach ($fields as $f) {
            $val = trim($_POST[$f] ?? '');
            db()->prepare('INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE value=?')
               ->execute([$f, $val, $val]);
        }
        $flash = 'Referral settings saved!';
        $tab = 'referrals';
    }
    if ($action === 'manual_reward_referral') {
        $ref_id = (int)($_POST['ref_id'] ?? 0);
        $row = db()->prepare('SELECT * FROM referrals WHERE id=? LIMIT 1');
        $row->execute([$ref_id]); $ref = $row->fetch();
        if ($ref && $ref['status'] === 'pending') {
            require_once __DIR__ . '/../includes/servers.php';
            wallet_credit((int)$ref['referrer_id'], (float)$ref['referrer_bonus'],
                'Referral bonus (manual reward)', 'referral', (int)$ref['referee_id']);
            wallet_credit((int)$ref['referee_id'], (float)$ref['referee_bonus'],
                'Welcome referral bonus (manual reward)', 'referral', (int)$ref['referrer_id']);
            db()->prepare("UPDATE referrals SET status='rewarded',rewarded_at=NOW() WHERE id=?")
               ->execute([$ref_id]);
            $flash = 'Referral rewarded!';
        }
        $tab = 'referrals';
    }

    // ── Coupons ───────────────────────────────────────────
    if ($action === 'create_coupon') {
        $code         = strtoupper(preg_replace('/[^A-Z0-9\-_]/i', '', trim($_POST['coupon_code'] ?? '')));
        $type         = in_array($_POST['coupon_type'] ?? '', ['percentage','fixed']) ? $_POST['coupon_type'] : 'percentage';
        $value        = (float)($_POST['coupon_value']  ?? 0);
        $max_uses     = trim($_POST['max_uses']  ?? '') !== '' ? (int)$_POST['max_uses'] : null;
        $min_deposit  = (float)($_POST['min_deposit']   ?? 0);
        $max_discount = trim($_POST['max_discount'] ?? '') !== '' ? (float)$_POST['max_discount'] : null;
        $expires_at   = trim($_POST['expires_at']  ?? '') ?: null;
        $description  = trim($_POST['description'] ?? '');
        $is_active    = (int)($_POST['is_active']   ?? 1);

        if (!$code)  { $_SESSION['admin_err'] = 'Coupon code required.'; }
        elseif ($value <= 0) { $_SESSION['admin_err'] = 'Value must be > 0.'; }
        elseif ($type === 'percentage' && $value > 100) { $_SESSION['admin_err'] = 'Percentage cannot exceed 100%.'; }
        else {
            try {
                db()->prepare(
                    'INSERT INTO coupons (code,type,value,max_uses,min_deposit,max_discount,expires_at,description,is_active,created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?)'
                )->execute([$code,$type,$value,$max_uses,$min_deposit,$max_discount,$expires_at,$description,$is_active,$user['id']]);
                $_SESSION['admin_msg'] = "✓ Coupon <strong>{$code}</strong> created successfully.";
            } catch(Throwable $e) {
                $_SESSION['admin_err'] = str_contains($e->getMessage(),'Duplicate') ? "Coupon code '{$code}' already exists." : $e->getMessage();
            }
        }
        $tab = 'coupons';
    }
    if ($action === 'toggle_coupon') {
        db()->prepare('UPDATE coupons SET is_active = 1 - is_active WHERE id=?')->execute([(int)$_POST['coupon_id']]);
        $tab = 'coupons';
    }
    if ($action === 'delete_coupon') {
        $cid = (int)($_POST['coupon_id'] ?? 0);
        db()->prepare('DELETE FROM coupon_uses WHERE coupon_id=?')->execute([$cid]);
        db()->prepare('DELETE FROM coupons WHERE id=?')->execute([$cid]);
        $_SESSION['admin_msg'] = 'Coupon deleted.';
        $tab = 'coupons';
    }

    // ── Admin Ticket Actions ──────────────────────────────
    if ($action === 'admin_reply_ticket') {
        $tk_id   = (int)($_POST['ticket_db_id'] ?? 0);
        $message = trim($_POST['message']        ?? '');
        $new_status = $_POST['ticket_status']    ?? '';

        $tk = db()->prepare('SELECT * FROM tickets WHERE id=? LIMIT 1');
        $tk->execute([$tk_id]);
        $ticket = $tk->fetch();

        if ($ticket && strlen($message) >= 1) {
            // Insert admin reply
            db()->prepare(
                'INSERT INTO ticket_replies (ticket_id, user_id, message, is_admin) VALUES (?,?,?,1)'
            )->execute([$tk_id, $user['id'], $message]);
            $reply_id = (int)db()->lastInsertId();

            // Handle attachments
            if (!empty($_FILES['attachments']['name'][0])) {
                $upload_dir = rtrim(defined('UPLOAD_PATH') ? UPLOAD_PATH : __DIR__ . '/../uploads/tickets', '/') . '/';
                if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);
                foreach ($_FILES['attachments']['name'] as $i => $fname) {
                    if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
                    $ext   = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                    $allow = ['jpg','jpeg','png','gif','pdf','txt','zip','doc','docx','xlsx','csv','log'];
                    if (!in_array($ext, $allow)) continue;
                    if ($_FILES['attachments']['size'][$i] > 10 * 1024 * 1024) continue;
                    $safe  = preg_replace('/[^a-z0-9._-]/i', '_', $fname);
                    $ufile = time() . '_' . $i . '_' . $safe;
                    $dest  = $upload_dir . $ufile;
                    if (move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $dest)) {
                        db()->prepare('INSERT INTO ticket_attachments (reply_id, filename, filepath, filesize) VALUES (?,?,?,?)')
                           ->execute([$reply_id, $fname, 'uploads/tickets/' . $ufile, $_FILES['attachments']['size'][$i]]);
                    }
                }
            }

            // Update status
            $set_status = in_array($new_status, ['open','in_progress','waiting','resolved','closed'])
                ? $new_status : 'in_progress';
            db()->prepare("UPDATE tickets SET status=?, updated_at=NOW() WHERE id=?")
               ->execute([$set_status, $tk_id]);

            // Email user
            $u_st = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
            $u_st->execute([$ticket['user_id']]);
            $ticket_user = $u_st->fetch();
            if ($ticket_user) {
                _admin_ticket_email_user($ticket_user, $ticket, $message);
            }
            $_SESSION['admin_msg'] = 'Reply sent.';
        }
        header('Location: ?tab=tickets&tid=' . urlencode($ticket['ticket_id'] ?? '')); exit;
    }

    if ($action === 'admin_update_ticket_status') {
        $tk_id   = (int)($_POST['ticket_db_id'] ?? 0);
        $status  = $_POST['ticket_status'] ?? '';
        if ($tk_id && in_array($status, ['open','in_progress','waiting','resolved','closed'])) {
            db()->prepare("UPDATE tickets SET status=?, updated_at=NOW() WHERE id=?")->execute([$status, $tk_id]);
        }
        $tab = 'tickets';
    }

    // ── KYC: Approve ──────────────────────────────────────────
    if ($action === 'kyc_approve') {
    $kyc_id = (int)($_POST['kyc_id'] ?? 0);

    // approve request
    db()->prepare("
        UPDATE kyc_requests 
        SET status='approved', reviewed_by=?, reviewed_at=NOW() 
        WHERE id=?
    ")->execute([$user['id'], $kyc_id]);

    // get data
    $krow = db()->prepare("
        SELECT k.user_id, u.email, u.full_name, u.username 
        FROM kyc_requests k 
        JOIN users u ON u.id = k.user_id 
        WHERE k.id=? LIMIT 1
    ");
    $krow->execute([$kyc_id]);
    $kdata = $krow->fetch();

    if ($kdata) {
        // direct update using same user_id
        db()->prepare("UPDATE users SET kyc=1 WHERE id=?")
           ->execute([$kdata['user_id']]);

        _kyc_send_email($kdata, 'approved', '');
    }

    $_SESSION['admin_msg'] = '✓ KYC approved.';
    $tab = 'kyc';
}

    // ── KYC: Reject ──────────────────────────────────────────
    if ($action === 'kyc_reject') {
        $kyc_id = (int)($_POST['kyc_id'] ?? 0);
        $reason = trim($_POST['reject_reason'] ?? '');
        if (!$reason) { $_SESSION['admin_err'] = 'Reject reason is required.'; $tab = 'kyc'; }
        else {
            db()->prepare("UPDATE kyc_requests SET status='rejected', reject_reason=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")
               ->execute([$reason, $user['id'], $kyc_id]);
            $krow = db()->prepare('SELECT k.*, u.email, u.full_name, u.username FROM kyc_requests k JOIN users u ON u.id=k.user_id WHERE k.id=? LIMIT 1');
            $krow->execute([$kyc_id]);
            $kdata = $krow->fetch();
            if ($kdata) _kyc_send_email($kdata, 'rejected', $reason);
            $_SESSION['admin_msg'] = 'KYC rejected.';
            $tab = 'kyc';
        }
    }
}

function _admin_ticket_email_user(array $ticket_user, array $ticket, string $reply_msg): void {
    try {
        require_once __DIR__ . '/../includes/mailer.php';
        $app     = APP_NAME;
        $tid     = $ticket['ticket_id'];
        $subject = $ticket['subject'];
        $name    = $ticket_user['full_name'] ?: $ticket_user['username'];
        $body    = "<!DOCTYPE html><html><body style='margin:0;padding:0;background:#f4f4f5;font-family:Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0' style='padding:32px 0'>
<tr><td align='center'>
<table width='560' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)'>
  <tr><td style='background:#1a1a2e;padding:22px 30px'>
    <h2 style='margin:0;color:#fff;font-size:18px'>{$app} Support</h2>
    <p style='margin:4px 0 0;color:#94a3b8;font-size:12px'>We replied to your support ticket</p>
  </td></tr>
  <tr><td style='padding:26px 30px'>
    <p style='font-size:14px;color:#374151;margin:0 0 6px'>Hi <strong>" . htmlspecialchars($name) . "</strong>,</p>
    <p style='font-size:13px;color:#6b7280;margin:0 0 20px'>Our support team has replied to your ticket.</p>
    <table width='100%' style='border-collapse:collapse;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:20px'>
      <tr><td style='padding:10px 16px;border-bottom:1px solid #e2e8f0'>
        <span style='font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase'>Ticket</span>&nbsp;
        <span style='font-family:monospace;font-weight:700;color:#2563eb'>#{$tid}</span>
      </td></tr>
      <tr><td style='padding:10px 16px;border-bottom:1px solid #e2e8f0'>
        <span style='font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase'>Subject</span><br>
        <span style='font-size:13px;color:#374151'>" . htmlspecialchars($subject) . "</span>
      </td></tr>
      <tr><td style='padding:14px 16px'>
        <span style='font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase'>Reply from {$app} Support Team</span><br>
        <div style='font-size:13.5px;color:#1e293b;line-height:1.7;margin-top:8px;white-space:pre-wrap'>" . htmlspecialchars($reply_msg) . "</div>
      </td></tr>
    </table>
    <div style='text-align:center'>
      <a href='" . BASE_URL . "/tickets.php?id=" . $ticket['id'] . "' style='background:#1a1a2e;color:#fff;text-decoration:none;padding:11px 28px;border-radius:8px;font-size:13px;font-weight:700;display:inline-block'>
        View &amp; Reply &rarr;
      </a>
    </div>
    <p style='font-size:11.5px;color:#94a3b8;text-align:center;margin:18px 0 0'>{$app} &middot; Ticket #{$tid}</p>
  </td></tr>
</table>
</td></tr></table></body></html>";
        send_mail($ticket_user['email'], $name, "[Ticket #{$tid}] Re: {$subject}", $body);
    } catch (Throwable $e) {
        error_log('[admin ticket email] ' . $e->getMessage());
    }
}


function _kyc_send_email(array $kyc, string $decision, string $reason): void {
    try {
        require_once __DIR__ . '/../includes/mailer.php';
        $app  = APP_NAME;
        $name = $kyc['full_name'] ?: $kyc['username'];
        $doc_labels = ['aadhaar'=>'Aadhaar Card','driving_license'=>'Driving License','pan'=>'PAN Card','passport'=>'Passport'];
        $doc_type  = $kyc['doc_type'] ?? '';
        $doc_label = $doc_labels[$doc_type] ?? ($doc_type ? ucfirst($doc_type) : 'Document');

        if ($decision === 'approved') {
            $subject = "✅ KYC Verified — {$app}";
            $body_content = "
            <div style='text-align:center;margin-bottom:24px'>
              <div style='width:60px;height:60px;background:#dcfce7;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:28px'>✅</div>
              <h2 style='color:#14532d;margin:0'>KYC Approved!</h2>
              <p style='color:#15803d;margin-top:6px'>Your identity has been successfully verified.</p>
            </div>
            <p style='font-size:14px;color:#374151;line-height:1.7'>
              Hi <strong>" . htmlspecialchars($name) . "</strong>,<br><br>
              Great news! Your KYC submission (<strong>{$doc_label}</strong>) has been <strong>approved</strong>.
              Your account is now fully verified and all platform features are unlocked.
            </p>";
        } else {
            $subject = "❌ KYC Rejected — Action Required — {$app}";
            $body_content = "
            <div style='text-align:center;margin-bottom:24px'>
              <div style='width:60px;height:60px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:28px'>❌</div>
              <h2 style='color:#991b1b;margin:0'>KYC Not Approved</h2>
              <p style='color:#b91c1c;margin-top:6px'>Your submission requires attention.</p>
            </div>
            <p style='font-size:14px;color:#374151;line-height:1.7'>
              Hi <strong>" . htmlspecialchars($name) . "</strong>,<br><br>
              Unfortunately, your KYC submission (<strong>{$doc_label}</strong>) has been <strong>rejected</strong>.
            </p>
            <div style='background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:14px 18px;margin:16px 0'>
              <div style='font-size:12px;font-weight:700;text-transform:uppercase;color:#9ca3af;margin-bottom:5px'>Reason</div>
              <div style='font-size:14px;color:#991b1b;font-weight:600'>" . htmlspecialchars($reason) . "</div>
            </div>
            <p style='font-size:13.5px;color:#6b7280'>Please log in and resubmit with the correct documents.</p>
            <div style='text-align:center;margin-top:20px'>
              <a href='" . BASE_URL . "/kyc.php' style='background:#dc2626;color:#fff;text-decoration:none;padding:11px 28px;border-radius:8px;font-size:13px;font-weight:700;display:inline-block'>
                Resubmit KYC &rarr;
              </a>
            </div>";
        }

        $body = "<!DOCTYPE html><html><body style='margin:0;padding:0;background:#f4f4f5;font-family:Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0' style='padding:32px 0'>
<tr><td align='center'>
<table width='560' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)'>
  <tr><td style='background:#1a1a2e;padding:22px 30px'>
    <h2 style='margin:0;color:#fff;font-size:18px'>{$app}</h2>
    <p style='margin:4px 0 0;color:#94a3b8;font-size:12px'>KYC Verification Update</p>
  </td></tr>
  <tr><td style='padding:28px 30px'>{$body_content}</td></tr>
  <tr><td style='padding:14px 30px;background:#f8fafc;border-top:1px solid #e2e8f0'>
    <p style='margin:0;font-size:11.5px;color:#94a3b8;text-align:center'>{$app} &middot; KYC Verification System</p>
  </td></tr>
</table>
</td></tr></table></body></html>";

        send_mail($kyc['email'], $name, $subject, $body);
    } catch (Throwable $e) {
        error_log('[kyc email] ' . $e->getMessage());
    }
}

function _send_claim_token_email(array $order, string $token, ?string $expires): void {
    $app  = APP_NAME;
    $name = $order['full_name'] ?: $order['username'];
    $exp_text = $expires ? 'Expires: ' . date('d M Y', strtotime($expires)) : 'No expiry';
    $body = "
    <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto'>
    <div style='background:linear-gradient(135deg,#1a1a2e,#16213e);padding:28px;border-radius:12px 12px 0 0;text-align:center'>
      <h2 style='color:white;margin:0;font-size:22px'>🎁 Your Server is Ready!</h2>
      <p style='color:#94a3b8;margin:6px 0 0'>{$app}</p>
    </div>
    <div style='background:white;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;padding:32px'>
      <p style='font-size:15px;color:#374151'>Hi <strong>" . htmlspecialchars($name) . "</strong>,</p>
      <p style='font-size:14px;color:#6b7280;line-height:1.6'>Your custom server order has been fulfilled! Use the claim code below to add your server to your account.</p>
      <div style='text-align:center;margin:28px 0'>
        <div style='font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;letter-spacing:.1em;margin-bottom:10px'>Your Claim Code</div>
        <div style='background:#f8fafc;border:2px dashed #16a34a;border-radius:12px;padding:18px 32px;display:inline-block'>
          <span style='font-size:28px;font-weight:900;font-family:monospace;letter-spacing:4px;color:#1a1a2e'>{$token}</span>
        </div>
        <div style='font-size:12px;color:#94a3b8;margin-top:10px'>{$exp_text}</div>
      </div>
      <div style='background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:16px;font-size:13px;color:#1e40af'>
        <strong>How to claim:</strong><br>
        1. Go to <a href='" . BASE_URL . "/servers.php' style='color:#2563eb'>{$app} → My Servers</a><br>
        2. Click the <strong>Claim Server</strong> button<br>
        3. Enter your claim code above<br>
        4. Your server will be added to your account instantly!
      </div>
    </div></div>";
    send_mail($order['email'], $name, "🎁 Your Custom Server is Ready — Claim Code: {$token}", $body);
}
$msg = ''; $err = '';
$available_types = get_available_provider_types();

/* ── POST ──────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { $err = 'Invalid CSRF token.'; }
    else {
        $action = $_POST['action'] ?? '';

        // ── Provider edit ────────────────────────────────────
        if ($action === 'save_provider') {
            $pid   = (int)($_POST['provider_id'] ?? 0);
            $ptype = strtolower(trim($_POST['provider_type'] ?? 'virtualizor'));
            $prov  = get_provider($pid);
            if (!$prov) { $err = 'Provider not found.'; }
            elseif (!in_array($ptype, $available_types)) { $err = "Unknown provider type '{$ptype}'."; }
            else {
                save_provider([
                    'display_name'  => trim($_POST['display_name'] ?? ''),
                    'api_key'       => trim($_POST['api_key'] ?? '') ?: $prov['api_key'],
                    'panel_url'     => trim($_POST['panel_url'] ?? ($prov['panel_url'] ?? '')),
                    'api_pass'      => trim($_POST['api_pass'] ?? '') ?: ($prov['api_pass'] ?? ''),
                    'location'      => trim($_POST['location'] ?? ''),
                    'location_flag' => strtolower(trim($_POST['location_flag'] ?? '')),
                    'margin_pct'    => (float)($_POST['margin_pct'] ?? 0),
                    'currency_base' => strtoupper(trim($_POST['currency_base'] ?? 'EUR')),
                    'is_active'     => (int)($_POST['is_active'] ?? 1),
                    'provider_type' => $ptype,
                ], $pid);
                $msg = 'Provider saved.';
            }
            $tab = 'providers';
        }

        // ── Add a NEW provider ───────────────────────────────
        if ($action === 'add_provider') {
            $ptype = strtolower(trim($_POST['provider_type'] ?? ''));
            $name  = trim($_POST['display_name'] ?? '');
            $key   = trim($_POST['api_key'] ?? '');

            if ($name === '' || $ptype === '') {
                $err = 'Display name and provider type are required.';
            } elseif (!in_array($ptype, $available_types)) {
                $err = "Unknown provider type '{$ptype}'. Add its folder under /providers first.";
            } else {
                // Build a unique slug from name (fallback to type)
                $base_slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?: $ptype;
                $base_slug = trim($base_slug, '-');
                $slug = $base_slug;
                $exists = db()->prepare('SELECT COUNT(*) FROM providers WHERE slug=?');
                $i = 1;
                while (true) {
                    $exists->execute([$slug]);
                    if (!(int)$exists->fetchColumn()) break;
                    $slug = $base_slug . '-' . (++$i);
                }
                save_provider([
                    'slug'          => $slug,
                    'display_name'  => $name,
                    'api_key'       => $key,
                    'panel_url'     => trim($_POST['panel_url'] ?? ''),
                    'api_pass'      => trim($_POST['api_pass'] ?? ''),
                    'location'      => trim($_POST['location'] ?? ''),
                    'location_flag' => strtolower(trim($_POST['location_flag'] ?? '')),
                    'margin_pct'    => (float)($_POST['margin_pct'] ?? 0),
                    'currency_base' => strtoupper(trim($_POST['currency_base'] ?? 'EUR')),
                    'is_active'     => (int)($_POST['is_active'] ?? 1),
                    'provider_type' => $ptype,
                ]);
                $msg = 'Provider "' . htmlspecialchars($name) . '" added.';
            }
            $tab = 'providers';
        }

        // ── Save margin only ─────────────────────────────────
        if ($action === 'save_margin') {
            $pid    = (int)($_POST['provider_id'] ?? 0);
            $margin = (float)($_POST['margin_pct'] ?? 0);
            if ($prov = get_provider($pid)) {
                db()->prepare('UPDATE providers SET margin_pct=? WHERE id=?')->execute([$margin,$pid]);
                $n = recalculate_provider_prices($pid, $margin);
                $msg = "Margin set to {$margin}%. {$n} plans recalculated.";
            }
            $tab = 'providers';
        }

        // ── Refresh exchange rates ────────────────────────────
        if ($action === 'refresh_rates') {
            $res = refresh_all_rates();
            $ok  = count(array_filter($res, fn($r)=>$r['ok']));
            $msg = "Rates refreshed: {$ok}/".count($res)." updated.";
            $tab = 'providers';
        }

        // ── ADD plan ─────────────────────────────────────────
        if ($action === 'add_plan') {
            $pid          = (int)($_POST['provider_id'] ?? 0);
            $plan_api_id  = strtolower(trim($_POST['plan_api_id']  ?? ''));
            $display_name = trim($_POST['display_name_plan'] ?? '') ?: strtoupper($plan_api_id);
            $locations    = array_values(array_filter((array)($_POST['plan_locations'] ?? [])));

            if (!$plan_api_id)   { $err = 'Plan API ID is required.'; }
            elseif (!$locations) { $err = 'Select at least one location.'; }
            else {
                save_provider_plan($pid, $plan_api_id, $display_name, $locations);
                $msg = "Plan '{$plan_api_id}' added. Click Sync Now to fetch specs & prices.";
            }
            $tab = 'plans';
        }

        // ── EDIT plan ────────────────────────────────────────
        if ($action === 'edit_plan') {
            $plan_id      = (int)($_POST['plan_id'] ?? 0);
            $pid          = (int)($_POST['provider_id'] ?? 0);
            $plan_api_id  = strtolower(trim($_POST['plan_api_id_edit'] ?? ''));
            $display_name = trim($_POST['display_name_plan_edit'] ?? '') ?: strtoupper($plan_api_id);
            $locations    = array_values(array_filter((array)($_POST['plan_locations_edit'] ?? [])));
            $active       = (bool)(int)($_POST['plan_active'] ?? 1);

            if (!$plan_api_id)   { $err = 'Plan API ID is required.'; }
            elseif (!$locations) { $err = 'Select at least one location.'; }
            else {
                save_provider_plan($pid, $plan_api_id, $display_name, $locations, $active, $plan_id);
                $msg = "Plan updated. Sync to refresh prices.";
            }
            $tab = 'plans';
        }

        // ── DELETE plan ──────────────────────────────────────
        if ($action === 'delete_plan') {
            $plan_id = (int)($_POST['plan_id'] ?? 0);
            $s = db()->prepare('SELECT plan_api_id FROM provider_plans WHERE id=? LIMIT 1');
            $s->execute([$plan_id]);
            $r = $s->fetch();
            delete_provider_plan($plan_id);
            $msg = "Plan ".($r['plan_api_id']??'')." removed.";
            $tab = 'plans';
        }

        // ── Toggle user ──────────────────────────────────────
        if ($action === 'toggle_user') {
            $uid = (int)($_POST['uid'] ?? 0);
            $stat= $_POST['status'] ?? 'active';
            db()->prepare('UPDATE users SET status=? WHERE id=?')->execute([$stat,$uid]);
            $msg = 'User updated.';
            $tab = 'users';
        }

        // ── Edit user ─────────────────────────────────────────
        if ($action === 'edit_user') {
            $uid       = (int)($_POST['uid'] ?? 0);
            $full_name = trim($_POST['full_name'] ?? '');
            $email     = strtolower(trim($_POST['email'] ?? ''));
            $phone     = trim($_POST['phone'] ?? '');
            $role      = in_array($_POST['role'] ?? '', ['user','admin']) ? $_POST['role'] : 'user';
            $status    = in_array($_POST['status'] ?? '', ['active','banned']) ? $_POST['status'] : 'active';
            $currency  = strtoupper(trim($_POST['currency'] ?? 'INR'));
            if ($uid && $email) {
                // Check email not taken by another user
                $ck = db()->prepare('SELECT id FROM users WHERE email=? AND id!=? LIMIT 1');
                $ck->execute([$email, $uid]);
                if ($ck->fetch()) {
                    $err = 'Email already in use by another account.';
                } else {
                    db()->prepare('UPDATE users SET full_name=?,email=?,phone=?,role=?,status=?,currency=? WHERE id=?')
                       ->execute([$full_name, $email, $phone, $role, $status, $currency, $uid]);
                    $msg = 'User updated successfully.';
                }
            }
            $tab = 'users';
        }

        // ── Manual credit ────────────────────────────────────
        if ($action === 'manual_credit') {
            $uid    = (int)($_POST['uid'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $note   = trim($_POST['note'] ?? 'Admin credit');
            if ($uid && $amount > 0) {
                require_once __DIR__ . '/../includes/servers.php';
                wallet_credit($uid, $amount, $note, 'adjustment');
                $msg = 'Wallet credited.';
            }
            $tab = 'users';
        }
    }
}

$stats     = admin_stats();
$providers = get_all_providers();
$rates     = get_cached_rates_summary();

// ── Referral data ──────────────────────────────────────────
$ref_settings = [
    'referral_enabled'            => get_setting('referral_enabled','1'),
    'referral_reward_on'          => get_setting('referral_reward_on','register'),
    // INR
    'referral_bonus_referrer_inr' => get_setting('referral_bonus_referrer_inr', get_setting('referral_bonus_referrer','100')),
    'referral_bonus_referee_inr'  => get_setting('referral_bonus_referee_inr',  get_setting('referral_bonus_referee','50')),
    'referral_min_topup_inr'      => get_setting('referral_min_topup_inr',      get_setting('referral_min_topup','0')),
    // USD
    'referral_bonus_referrer_usd' => get_setting('referral_bonus_referrer_usd','10'),
    'referral_bonus_referee_usd'  => get_setting('referral_bonus_referee_usd','5'),
    'referral_min_topup_usd'      => get_setting('referral_min_topup_usd','0'),
];
$all_referrals = [];
$ref_stats_row = ['total'=>0,'rewarded'=>0,'pending'=>0,'total_given'=>0];
try {
    $rq = db()->query('SELECT r.*,
        u1.username as referrer_name, u1.email as referrer_email,
        u2.username as referee_name, u2.email as referee_email,
        u2.created_at as referee_joined
        FROM referrals r
        JOIN users u1 ON u1.id=r.referrer_id
        JOIN users u2 ON u2.id=r.referee_id
        ORDER BY r.created_at DESC LIMIT 200');
    $all_referrals = $rq->fetchAll() ?: [];
    $rs = db()->query("SELECT COUNT(*) as total,
        SUM(status='rewarded') as rewarded,
        SUM(status='pending') as pending,
        COALESCE(SUM(referrer_bonus)+SUM(referee_bonus),0) as total_given
        FROM referrals");
    $ref_stats_row = $rs->fetch() ?: $ref_stats_row;
} catch(Throwable $e) { $all_referrals = []; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Admin — <?= $app_name ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/admin/admin.css">
  <style>
    .adm-shell{display:flex;min-height:100vh}
    
    .adm-logo{padding:18px 16px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:8px}
    .adm-logo-mark{width:28px;height:28px;border-radius:6px;background:var(--primary);display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .adm-logo-text{font-weight:800;font-size:14px;color:white;letter-spacing:-.3px}
    .adm-badge{font-size:9px;font-weight:700;background:#dc2626;color:white;padding:1px 6px;border-radius:99px;margin-left:4px;text-transform:uppercase}
    .adm-nav{flex:1;padding:10px 8px;overflow-y:auto}
    .adm-nav-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:rgba(255,255,255,.3);padding:10px 8px 4px}
    .adm-link{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:7px;font-size:13px;font-weight:500;color:rgba(255,255,255,.6);text-decoration:none;transition:all .14s;margin-bottom:1px}
    .adm-link:hover{background:rgba(255,255,255,.08);color:rgba(255,255,255,.9)}
    .adm-link.active{background:#22293b;color:white;font-weight:700}
    .adm-link svg{width:15px;height:15px;flex-shrink:0}
    .adm-footer{padding:12px 10px;border-top:1px solid rgba(255,255,255,.08)}
    .adm-av{width:30px;height:30px;border-radius:7px;background:var(--primary);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:white}
    .adm-main{margin-left:232px;flex:1;background:var(--gray-50);min-height:100vh}
    .adm-topbar{background:white;border-bottom:1px solid var(--border);height:56px;display:flex;align-items:center;padding:0 24px;position:sticky;top:0;z-index:30}
    .adm-topbar-title{font-size:15px;font-weight:800;color:var(--gray-900)}
    .adm-content{padding:24px}

    /* Stats */
    .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
    .stat-card{position:relative;background:white;border:1px solid var(--border);border-radius:14px;padding:18px 20px;box-shadow:0 1px 3px rgba(15,23,42,.06);overflow:hidden;transition:box-shadow .16s,transform .16s}
    .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--_accent,var(--primary));opacity:0;transition:opacity .16s}
    .stat-card:hover{box-shadow:0 8px 22px rgba(15,23,42,.10);transform:translateY(-2px)}
    .stat-card:hover::before{opacity:1}
    .stat-card-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
    .stat-ic{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .stat-ic svg{width:18px;height:18px}
    .stat-val{font-size:27px;font-weight:900;color:var(--gray-900);letter-spacing:-1px;line-height:1}
    .stat-lbl{font-size:11.5px;color:var(--gray-500);margin-top:5px;font-weight:600;text-transform:uppercase;letter-spacing:.5px}

    /* Cards */
    .card{background:white;border:1px solid var(--border);border-radius:13px;overflow:hidden;margin-bottom:18px}
    .card-head{padding:13px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px}
    .card-title{font-size:14px;font-weight:800;color:var(--gray-900)}
    .card-body{padding:18px}

    /* Table */
    .tbl{width:100%;border-collapse:collapse;font-size:13px}
    .tbl thead th{padding:9px 14px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--gray-400);background:var(--gray-50);border-bottom:1px solid var(--border)}
    .tbl tbody tr{border-bottom:1px solid var(--gray-100);transition:background .12s}
    .tbl tbody tr:last-child{border:none}
    .tbl tbody tr:hover{background:var(--gray-50)}
    .tbl td{padding:11px 14px;vertical-align:middle}

    /* Provider cards */
    .prov-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:16px;margin-bottom:24px}
    .prov-card{background:white;border:1.5px solid var(--border);border-radius:13px;overflow:hidden;transition:border-color .15s}
    .prov-card:hover{border-color:var(--primary)}
    .prov-head{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
    .prov-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:6px;font-size:11.5px;font-weight:700;border:1.5px solid var(--border);background:var(--gray-50);font-family:monospace;color:var(--gray-700)}
    .prov-body{padding:14px 18px}
    .prov-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid var(--gray-100);font-size:13px}
    .prov-row:last-child{border:none}
    .prov-lbl{color:var(--gray-500);font-weight:500}
    .prov-val{font-weight:700;color:var(--gray-800)}
    .prov-footer{padding:11px 18px;border-top:1px solid var(--border);background:var(--gray-50);display:flex;gap:8px;align-items:center}

    /* Margin */
    .margin-form{display:flex;align-items:center;gap:7px}
    .margin-inp{width:68px;padding:5px 8px;border:1.5px solid var(--border);border-radius:7px;font-family:monospace;font-size:14px;font-weight:700;color:var(--primary);text-align:center;outline:none}
    .margin-inp:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-ring)}

    /* Sync */
    .sync-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:7px;font-size:12.5px;font-weight:700;background:var(--primary);color:white;border:none;cursor:pointer;font-family:inherit;transition:all .14s}
    .sync-btn:hover{background:var(--primary-hover)}
    .sync-btn:disabled{opacity:.45;cursor:not-allowed}
    .sync-btn.spinning svg{animation:spin .8s linear infinite}
    @keyframes spin{to{transform:rotate(360deg)}}
    .sync-log{display:none;background:#0d1117;border-radius:8px;padding:11px 14px;margin:0 18px 14px;font-family:monospace;font-size:11.5px;line-height:1.9;max-height:200px;overflow-y:auto}
    .sync-log.open{display:block}
    .ll{color:#8b949e}.ll.ok{color:#3fb950}.ll.warn{color:#d29922}.ll.err{color:#f85149}
    .sync-st{font-size:11.5px;font-weight:600}
    .sync-st.ok{color:#16a34a}.sync-st.err{color:var(--danger)}

    /* Rate cards */
    .rate-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px}
    .rate-card{background:white;border:1px solid var(--border);border-radius:11px;padding:13px 15px}
    .rate-pair{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--gray-400);margin-bottom:4px}
    .rate-val{font-size:20px;font-weight:900;color:var(--gray-900);letter-spacing:-1px;font-family:monospace}
    .rate-meta{font-size:11px;color:var(--gray-400);margin-top:3px;display:flex;align-items:center;gap:5px}
    .rdot{width:7px;height:7px;border-radius:50%;flex-shrink:0}

    /* Plans tab */
    .plan-card{background:white;border:1.5px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:12px;transition:border-color .15s}
    .plan-card:hover{border-color:var(--gray-300)}
    .plan-card-head{padding:13px 16px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--border)}
    .plan-api-id{font-family:monospace;font-weight:800;font-size:14px;color:var(--gray-900)}
    .plan-display-name{font-size:12px;color:var(--gray-400);margin-top:2px}
    .plan-locs{display:flex;flex-wrap:wrap;gap:5px;margin-top:6px}
    .loc-chip{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;background:var(--gray-100);border-radius:5px;font-size:12px;font-weight:600;color:var(--gray-600);font-family:monospace}
    .loc-chip img{border-radius:2px}
    .plan-synced{font-size:11.5px;color:#16a34a;font-weight:600}
    .plan-not-synced{font-size:11.5px;color:var(--gray-400)}

    /* Location checkboxes */
    .loc-check-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}
    .loc-check{display:flex;align-items:center;gap:8px;padding:8px 10px;border:1.5px solid var(--border);border-radius:7px;cursor:pointer;transition:border-color .13s;background:white;font-size:13px}
    .loc-check:hover,.loc-check.checked{border-color:var(--primary);background:var(--primary-light)}
    .loc-check input{accent-color:var(--primary);flex-shrink:0}
    .loc-check img{border-radius:2px;flex-shrink:0}
    .loc-check-name{font-weight:600;color:var(--gray-800);font-family:monospace}

    /* Buttons */
    .btn{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;border:none;transition:all .14s;text-decoration:none}
    .btn-sm{padding:5px 10px;font-size:12.5px;border-radius:7px}
    .btn-primary{background:var(--primary);color:white}.btn-primary:hover{background:var(--primary-hover)}
    .btn-ghost{background:white;color:var(--gray-700);border:1px solid var(--border)}.btn-ghost:hover{background:var(--gray-100)}
    .btn-success{background:#16a34a;color:white}.btn-success:hover{background:#15803d}
    .btn-danger{background:var(--danger);color:white}.btn-danger:hover{background:#b91c1c}

    /* Form */
    .flabel{display:block;font-size:12px;font-weight:700;color:var(--gray-700);margin-bottom:5px}
    .flabel span{font-weight:400;color:var(--gray-400)}
    .form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
    .form-row.full{grid-template-columns:1fr}
    .form-control{width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:13px;color:var(--gray-900);outline:none;transition:border-color .14s}
    .form-control:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-ring)}

    /* Modal */
    .modal-bd{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100;display:flex;align-items:center;justify-content:center;padding:20px}
    .modal-box{background:white;border-radius:13px;width:100%;max-width:560px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.14);max-height:90vh;overflow-y:auto}
    .modal-head{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:white;z-index:1}
    .modal-title{font-size:14px;font-weight:800;color:var(--gray-900)}
    .modal-body{padding:18px}
    .modal-foot{padding:12px 18px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end}


    /* ── Overlay ─────────────────────────────────────────────── */
    .adm-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);backdrop-filter:blur(3px);z-index:45;opacity:0;pointer-events:none;transition:opacity .25s ease}
    .adm-overlay.open{opacity:1;pointer-events:auto}

    /* ── Mobile bar ──────────────────────────────────────────── */
    .adm-mobile-bar{display:none;background:white;border-bottom:1px solid var(--border);padding:10px 14px;align-items:center;gap:12px;position:sticky;top:0;z-index:60}
    .adm-ham{width:34px;height:34px;background:#f1f5f9;border:1px solid var(--border);border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#475569;flex-shrink:0}

    /* ── Responsive ──────────────────────────────────────────── */
    @media(max-width:900px){
      .stats-grid{grid-template-columns:repeat(2,1fr) !important}
      .rate-grid{grid-template-columns:repeat(2,1fr) !important}
      .adm-mobile-bar{display:flex}
      .adm-topbar{display:none}
      
      .adm-sidebar.open{transform:translateX(0)}
      .adm-main{margin-left:0 !important}
      .adm-content{padding:16px}
      .prov-grid{grid-template-columns:1fr !important}
      .form-row{grid-template-columns:1fr !important}
      .loc-check-grid{grid-template-columns:1fr 1fr !important}
      .tbl-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
      .tbl{min-width:560px}
    }
    @media(max-width:640px){
      .stats-grid{grid-template-columns:repeat(2,1fr) !important;gap:10px}
      .rate-grid{grid-template-columns:repeat(2,1fr) !important;gap:10px}
      .adm-content{padding:12px}
      .loc-check-grid{grid-template-columns:1fr !important}
      .card-head{flex-wrap:wrap;gap:8px}
    }
  </style>
</head>
<!-- ── Mobile top bar ────────────────────────────────────── -->
<div class="adm-mobile-bar">
  <button class="adm-ham" onclick="admToggleSidebar()" aria-label="Menu">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
      <line x1="3" y1="6"  x2="21" y2="6"/>
      <line x1="3" y1="12" x2="21" y2="12"/>
      <line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
  </button>
  <?php if (!empty(get_setting('site_logo', ''))) : ?>
    <img src="<?= htmlspecialchars(get_setting('site_logo', '')) ?>" alt="Logo" style="width: 130px;">
    <span style="font-size:9px;background:#dc2626;color:#fff;padding:2px 6px;border-radius:99px;font-weight:700;text-transform:uppercase;vertical-align:middle;margin-left:4px">Admin</span>
<?php else: ?>
    <span class="adm-mobile-title">
    <?= APP_NAME ?>
    <span style="font-size:9px;background:#dc2626;color:#fff;padding:2px 6px;border-radius:99px;font-weight:700;text-transform:uppercase;vertical-align:middle;margin-left:4px">Admin</span>
  </span>
<?php endif; ?>
</div>
<body>
<div class="adm-overlay" id="adm-overlay" onclick="closeAdmSidebar()"></div>
<div class="adm-shell">
  <?php include 'sidebar.php'; ?>

  <div class="adm-main">
    <div class="adm-topbar">
      <span class="adm-topbar-title"><?= match($tab){'overview'=>'Overview','providers'=>'Providers','plans'=>'Server Plans','catalog'=>'Regions & Images','orders'=>'Custom Orders','revenue'=>'Revenue Analytics','coupons'=>'Coupons','tickets'=>'Support Tickets','kyc'=>'KYC Verification','users'=>'Users','servers'=>'Servers','transactions'=>'Transactions','invoices'=>'Invoices','referrals'=>'Referral Program',default=>'Admin'} ?></span>
      <div style="margin-left:auto;font-size:12px;color:var(--gray-400)"><?= date('d M Y, H:i') ?></div>
    </div>

    <div class="adm-content">
      <?php if ($msg): ?><div class="alert alert-success" style="margin-bottom:16px;border-radius:10px"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="alert alert-error" style="margin-bottom:16px;border-radius:10px"><?= htmlspecialchars($err) ?></div><?php endif; ?>

      <?php if ($tab === 'overview'): ?>
      <!-- ═══════ OVERVIEW ═══════ -->
      <?php
        // KPI icons (inline SVG paths) keyed by label
        $kpi_icons = [
          'Total Users'   => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>',
          'Total Servers' => '<rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>',
          'Running'       => '<polyline points="20 6 9 17 4 12"/>',
          'Suspended'     => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
          'Revenue'       => '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>',
          'Wallets'       => '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
          'Invoices'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
          'Providers'     => '<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>',
        ];
      ?>
      <div class="stats-grid">
        <?php foreach ([
          [$stats['total_users'],   'Total Users',    '#eff6ff','#2563eb'],
          [$stats['total_servers'], 'Total Servers',  '#f0fdf4','#16a34a'],
          [$stats['running_servers'],'Running',       '#f0fdf4','#16a34a'],
          [$stats['suspended'],     'Suspended',      '#fef2f2','#dc2626'],
          ['₹'.number_format($stats['total_revenue'],0),'Revenue','#fff7ed','#d97706'],
          ['₹'.number_format($stats['wallet_total'],0), 'Wallets', '#faf5ff','#9333ea'],
          [$stats['invoices_count'],'Invoices',       '#f0fdf4','#16a34a'],
          [count($providers),       'Providers',      '#eff6ff','#2563eb'],
        ] as [$v,$l,$bg,$c]): ?>
        <div class="stat-card" style="--_accent:<?= $c ?>">
          <div class="stat-card-top">
            <div class="stat-val" style="color:<?= $c ?>"><?= $v ?></div>
            <div class="stat-ic" style="background:<?= $bg ?>;color:<?= $c ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $kpi_icons[$l] ?? '' ?></svg>
            </div>
          </div>
          <div class="stat-lbl"><?= $l ?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="card">
        <div class="card-head"><span class="card-title">Recent Signups</span><a href="?tab=users" class="btn btn-ghost btn-sm">All →</a></div>
        <div class="tbl-wrap"><table class="tbl">
          <thead><tr><th>User</th><th>Email</th><th>Country</th><th>Balance</th><th>Joined</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach (admin_recent_users(8) as $u): ?>
          <tr>
            <td style="font-weight:700"><?= htmlspecialchars($u['username']) ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><img src="https://flagcdn.com/w20/<?= strtolower($u['country']??'in') ?>.png" width="14" style="border-radius:2px;vertical-align:middle;margin-right:4px" onerror="this.style.display='none'"><?= htmlspecialchars($u['country']??'') ?></td>
            <td style="font-family:monospace"><?= $u['currency']==='INR'?'₹':'$' ?><?= number_format((float)$u['wallet_balance'],2) ?></td>
            <td><?= date('d M Y',strtotime($u['created_at'])) ?></td>
            <td><span class="badge <?= $u['status']==='active'?'badge-green':'badge-red' ?>"><?= $u['status'] ?></span></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>

      <?php elseif ($tab === 'providers'): ?>
      <!-- ═══════ PROVIDERS ═══════ -->

      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
        <div style="font-size:13px;color:var(--gray-500)">Provider types available: <strong><?= implode(', ', $available_types) ?: 'none' ?></strong></div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <button type="button" class="btn btn-primary btn-sm" onclick="openAddProv()">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Provider
          </button>
          <form method="POST" style="margin:0">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="refresh_rates">
            <button type="submit" class="btn btn-ghost btn-sm">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><polyline points="23 20 23 14 17 14"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>
              Refresh Rates
            </button>
          </form>
        </div>
      </div>

      <!-- Rate cards -->
      <div class="rate-grid">
        <?php foreach ($rates as $r): ?>
        <div class="rate-card">
          <div class="rate-pair"><?= $r['from'] ?> → <?= $r['to'] ?></div>
          <div class="rate-val"><?= $r['rate'] ? number_format($r['rate'],4) : '<span style="color:var(--gray-300)">—</span>' ?></div>
          <div class="rate-meta">
            <span class="rdot" style="background:<?= $r['fresh']?'#16a34a':($r['rate']?'#d97706':'#9ca3af') ?>"></span>
            <?php if ($r['rate']): ?>
            <?= $r['fresh']?'Fresh':'Stale ('.$r['age_min'].'min)' ?> · <?= $r['cached_at'] ?>
            <?php else: ?>Not fetched<?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="prov-grid">
        <?php foreach ($providers as $prov):
          $ptype    = strtolower($prov['provider_type']??'hetzner');
          $base_cur = strtoupper($prov['currency_base']??'EUR');
          $pplans   = get_provider_plans((int)$prov['id']);
          $pp_synced = db()->prepare('SELECT COUNT(*) FROM plan_pricing WHERE provider_id=?');
          $pp_synced->execute([$prov['id']]);
          $synced_count = (int)$pp_synced->fetchColumn();
        ?>
        <div class="prov-card">
          <div class="prov-head">
            <?php
              $logo_html = match($ptype) {
                'hetzner'      => '<img src="https://upload.wikimedia.org/wikipedia/commons/thumb/0/0c/Logo_Hetzner.svg/3840px-Logo_Hetzner.svg.png" style="height:26px;max-width:110px;object-fit:contain">',
                'linode'       => '<img src="https://www.structureresearch.net/wp-content/uploads/2022/03/linode-logo-png-transparent.png" style="height:22px;max-width:110px;object-fit:contain">',
                'utho'         => '<img src="https://utho.com/assets/utho-logo-light-C_lCVum7.png" style="height:22px;max-width:110px;object-fit:contain">',
                'contabo'      => '<img src="https://cdn.brandfetch.io/id8dkHJQ4Y/theme/dark/logo.svg?c=1dxbfHSJFAPEGdCLU4o5B" style="height:22px;max-width:110px;object-fit:contain">',
                'digitalocean' => '<img src="https://i.ibb.co/wNxhB6Cd/svgviewer-png-output.png" style="height:24px;max-width:110px;object-fit:contain">',
                'virtualizor'  => '<img src="https://i.ibb.co/spRk96MJ/id-HFHIrw-Nu-1777518882731.png" style="height:22px;max-width:110px;object-fit:contain">',
                'proxmox' => '<img src="https://i.ibb.co/Jw5Zb1qz/960px-Logo-Proxmox-svg.png" style="height:22px;max-width:110px;object-fit:contain">',
                'vultr' => '<img src="https://images.g2crowd.com/uploads/product/image/387c3dfdb21c2883f61e09e50a9e2ddf/vultr.png" style="height:22px;max-width:110px;object-fit:contain">',
                default        => '<div class="prov-badge">☁️ '.htmlspecialchars(strtoupper($ptype)).'</div>',
              };
              echo $logo_html;
            ?>
            <div>
              <div style="font-size:14px;font-weight:800;color:var(--gray-900)"><?= htmlspecialchars($prov['display_name']) ?></div>
              <div style="font-size:11px;color:var(--gray-400);margin-top:1px"><?= htmlspecialchars($prov['slug']) ?></div>
            </div>
            <div style="margin-left:auto;width:9px;height:9px;border-radius:50%;background:<?= $prov['is_active']?'#16a34a':'#9ca3af' ?>" title="<?= $prov['is_active']?'Active':'Inactive' ?>"></div>
          </div>
          <div class="prov-body">
            <div class="prov-row">
              <span class="prov-lbl">API Key</span>
              <span class="prov-val" style="font-family:monospace;font-size:12px">
                <?= $prov['api_key'] ? '••••••••'.htmlspecialchars(substr($prov['api_key'],-6)) : '<span style="color:var(--danger)">Not set</span>' ?>
              </span>
            </div>
            <div class="prov-row">
              <span class="prov-lbl">Base currency</span>
              <span class="prov-val"><?= $base_cur ?></span>
            </div>
            <div class="prov-row">
              <span class="prov-lbl">Plans configured</span>
              <span class="prov-val"><?= count($pplans) ?> manual · <?= $synced_count ?> synced</span>
            </div>
            <div class="prov-row">
              <span class="prov-lbl">Last synced</span>
              <span class="prov-val" style="font-size:12px"><?= $prov['last_synced'] ? date('d M Y, H:i',strtotime($prov['last_synced'])) : '—' ?></span>
            </div>
            <div class="prov-row">
              <span class="prov-lbl">Sync status</span>
              <span class="badge <?= ['never'=>'badge-gray','success'=>'badge-green','error'=>'badge-red'][$prov['sync_status']]??'badge-gray' ?>">
                <?= match($prov['sync_status']){'never'=>'Never','success'=>'✓ Synced','error'=>'✗ Error',default=>'—'} ?>
              </span>
            </div>
            <!-- Margin -->
            <div class="prov-row" style="align-items:flex-start;padding-top:11px">
              <div>
                <div style="font-size:13px;font-weight:700;color:var(--gray-800)">Margin %</div>
                <div style="font-size:11px;color:var(--gray-400);margin-top:1px"><?= $base_cur ?> → +<?= $prov['margin_pct'] ?>% → USD → INR</div>
              </div>
              <form method="POST" class="margin-form">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="save_margin">
                <input type="hidden" name="provider_id" value="<?= $prov['id'] ?>">
                <input type="number" name="margin_pct" class="margin-inp" value="<?= $prov['margin_pct'] ?>" min="0" max="999" step="0.5">
                <span style="color:var(--gray-400);font-weight:700">%</span>
                <button type="submit" class="btn btn-sm btn-success" style="padding:5px 9px">Save</button>
              </form>
            </div>
          </div>
          <div class="sync-log" id="sync-log-<?= $prov['id'] ?>"></div>
          <div class="prov-footer">
            <?php if ($ptype === 'hetzner'): ?>
            <!-- Hetzner: manual plans so Fetch Regions first, then Sync -->
            <button class="btn btn-ghost btn-sm" id="fetch-reg-btn-<?= $prov['id'] ?>"
                    onclick="fetchRegions(<?= $prov['id'] ?>, this)"
                    title="Fetch all regions & OS images from provider API">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
              Fetch Regions
            </button>
            <?php endif; ?>

            <button class="sync-btn" id="sync-btn-<?= $prov['id'] ?>" onclick="doSync(<?= $prov['id'] ?>,this)">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><polyline points="23 20 23 14 17 14"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>
              <?= $ptype === 'hetzner' ? 'Sync Plans' : 'Sync All' ?>
            </button>
            <span class="sync-st" id="sync-st-<?= $prov['id'] ?>"><?= $prov['last_synced'] ? date('d M, H:i',strtotime($prov['last_synced'])) : '' ?></span>
            <div style="margin-left:auto">
              <button class="btn btn-ghost btn-sm" onclick="openEditProv(<?= htmlspecialchars(json_encode($prov)) ?>)">Edit</button>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php elseif ($tab === 'plans'): ?>
      <!-- ═══════ SERVER PLANS ═══════ -->

      <?php
        // Check if ALL providers are non-hetzner or if any hetzner exists
        $has_hetzner = false;
        foreach ($providers as $pv) { if (strtolower($pv['provider_type']??'') === 'hetzner') { $has_hetzner = true; break; } }
      ?>
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;flex-wrap:wrap;gap:12px">
        <div>
          <div style="font-size:13.5px;color:var(--gray-600);line-height:1.6">
            <?php if ($has_hetzner): ?>
            <strong>Hetzner:</strong> Add plan IDs manually (e.g. <code style="font-family:monospace;background:var(--gray-100);padding:1px 5px;border-radius:4px">cpx11</code>), select locations, then click <strong>Sync Now</strong>.<br>
            <?php endif; ?>
            <strong>Other providers</strong> (Linode, Utho, DigitalOcean, Contabo): Just set your <strong>Margin %</strong> and click <strong>Sync All</strong> — all plans, regions &amp; images are fetched automatically.
          </div>
        </div>
        <?php if ($has_hetzner): ?>
        <button class="btn btn-primary" onclick="document.getElementById('add-plan-modal').style.display='flex'">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add Plan
        </button>
        <?php endif; ?>
      </div>

      <?php foreach ($providers as $prov):
        $pplans = get_provider_plans((int)$prov['id']);
        // Load regions for location checkboxes
        $rg = db()->prepare('SELECT * FROM region_catalog WHERE provider_id=? AND is_active=1 ORDER BY city');
        $rg->execute([$prov['id']]);
        $regions = $rg->fetchAll() ?: [];
      ?>
      <div class="card" style="margin-bottom:24px">
        <div class="card-head">
          <span class="card-title"><?= htmlspecialchars($prov['display_name']) ?></span>
          <?php
            $ptype_plan = strtolower($prov['provider_type'] ?? 'virtualizor');
            $is_hetzner = ($ptype_plan === 'hetzner');
          ?>
          <?php if ($is_hetzner): ?>
          <span style="font-size:11px;background:#fff7ed;color:#d97706;padding:3px 9px;border-radius:99px;font-weight:700;border:1px solid #fed7aa">Manual Plans</span>
          <button class="btn btn-primary btn-sm" onclick="openAddPlan(<?= $prov['id'] ?>, <?= htmlspecialchars(json_encode($regions)) ?>)">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Plan
          </button>
          <?php else: ?>
          <span style="font-size:11px;background:#f0fdf4;color:#16a34a;padding:3px 9px;border-radius:99px;font-weight:700;border:1px solid #86efac">Auto Sync</span>
          <?php endif; ?>
        </div>

        <?php if (empty($pplans) && $is_hetzner): ?>
        <div style="padding:32px;text-align:center;color:var(--gray-400)">
          <div style="font-size:14px;font-weight:700;margin-bottom:5px">No plans added yet</div>
          <div style="font-size:13px">Add a plan above, then click Sync Now on the Providers tab.</div>
        </div>
        <?php elseif (empty($pplans) && !$is_hetzner): ?>
        <div style="padding:22px 20px;background:#f0fdf4;border-top:1px solid #86efac">
          <div style="display:flex;align-items:center;gap:12px">
            <div style="font-size:22px">⚡</div>
            <div>
              <div style="font-size:13.5px;font-weight:700;color:#166534;margin-bottom:2px">Plans auto-fetch on Sync</div>
              <div style="font-size:12.5px;color:#15803d">
                Go to <strong>Providers</strong> tab → Set your Margin % → Click <strong>Sync All</strong>.
                All plans, regions and images will be fetched automatically from <?= htmlspecialchars($prov['display_name']) ?> API.
              </div>
            </div>
          </div>
        </div>
        <?php else: ?>
        <div style="padding:14px 16px;display:flex;flex-direction:column;gap:10px">
          <?php foreach ($pplans as $pp):
            // Check if synced
            $synced = db()->prepare('SELECT price_usd,price_inr,vcpu,ram_gb,disk_gb FROM plan_pricing WHERE provider_id=? AND slug=? LIMIT 1');
            $synced->execute([$prov['id'],$pp['plan_api_id']]);
            $synced = $synced->fetch();
          ?>
          <div class="plan-card">
            <div class="plan-card-head">
              <div style="flex:1">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                  <span class="plan-api-id"><?= htmlspecialchars(strtoupper($pp['plan_api_id'])) ?></span>
                  <span class="badge badge-blue" style="font-size:11px"><?= htmlspecialchars($pp['display_name']) ?></span>
                  <?php if (!$pp['is_active']): ?>
                  <span class="badge badge-yellow" style="font-size:11px">Inactive</span>
                  <?php endif; ?>
                  <?php if ($synced): ?>
                  <span class="plan-synced">✓ Synced · <?= $synced['vcpu'] ?>vCPU / <?= $synced['ram_gb'] ?>GB / <?= $synced['disk_gb'] ?>GB · $<?= number_format((float)$synced['price_usd'],4) ?>/hr · ₹<?= number_format((float)$synced['price_inr'],4) ?>/hr</span>
                  <?php else: ?>
                  <span class="plan-not-synced">Not synced yet</span>
                  <?php endif; ?>
                </div>
                <div class="plan-locs">
                  <?php foreach ($pp['locations'] as $loc):
                    // Get region info for flag
                    $ri = db()->prepare('SELECT city,country_code FROM region_catalog WHERE slug=? LIMIT 1');
                    $ri->execute([$loc]);
                    $ri = $ri->fetch();
                  ?>
                  <span class="loc-chip">
                    <?php if ($ri): ?>
                    <img src="https://flagcdn.com/w20/<?= htmlspecialchars($ri['country_code']??'de') ?>.png" width="12" height="9" onerror="this.style.display='none'">
                    <?= htmlspecialchars($ri['city'] ?? $loc) ?>
                    <?php else: ?>
                    <?= htmlspecialchars($loc) ?>
                    <?php endif; ?>
                  </span>
                  <?php endforeach; ?>
                </div>
              </div>
              <div style="display:flex;gap:6px;flex-shrink:0">
                <button class="btn btn-ghost btn-sm"
                  onclick="openEditPlan(<?= htmlspecialchars(json_encode($pp)) ?>, <?= htmlspecialchars(json_encode($regions)) ?>)">
                  Edit
                </button>
                <form method="POST" onsubmit="return confirm('Delete plan <?= htmlspecialchars(addslashes($pp['plan_api_id'])) ?>?')">
                  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="delete_plan">
                  <input type="hidden" name="plan_id" value="<?= $pp['id'] ?>">
                  <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger);border-color:#fca5a5">Delete</button>
                </form>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <!-- Add plan info box -->
      <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 16px;font-size:12.5px;color:#1d4ed8">
        <strong>How it works:</strong>
        Plan API IDs come from the provider (e.g. Hetzner: cpx11, cpx21, ccx13).
        You set which locations to offer it in — only those locations will show this plan to users.
        Sync Now fetches the current specs and pricing from the API.
      </div>

      <?php elseif ($tab === 'catalog'): ?>
      <!-- ═══════ CATALOG ═══════ -->
      <?php foreach ($providers as $prov):
        $rg = db()->prepare('SELECT * FROM region_catalog WHERE provider_id=? AND is_active=1 ORDER BY city');
        $rg->execute([$prov['id']]); $regions = $rg->fetchAll();
        $im = db()->prepare('SELECT * FROM image_catalog WHERE provider_id=? AND is_active=1 ORDER BY os_name,os_version');
        $im->execute([$prov['id']]); $images = $im->fetchAll();
        // OS icons from /servers/os_images.json via includes/os_icons.php
      ?>
      <h3 style="font-size:15px;font-weight:800;color:var(--gray-900);margin-bottom:14px"><?= htmlspecialchars($prov['display_name']) ?></h3>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:28px">
        <div class="card">
          <div class="card-head"><span class="card-title">Regions (<?= count($regions) ?>)</span></div>
          <div class="tbl-wrap"><table class="tbl">
            <thead><tr><th>Slug</th><th>City</th><th>Country</th></tr></thead>
            <tbody>
            <?php foreach ($regions as $r): ?>
            <tr>
              <td style="font-family:monospace;font-size:12px"><?= htmlspecialchars($r['slug']) ?></td>
              <td><img src="https://flagcdn.com/w20/<?= htmlspecialchars($r['country_code']??'de') ?>.png" width="14" style="border-radius:2px;vertical-align:middle;margin-right:5px" onerror="this.style.display='none'"><?= htmlspecialchars($r['city']) ?></td>
              <td><?= htmlspecialchars($r['country']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$regions): ?><tr><td colspan="3" style="text-align:center;padding:20px;color:var(--gray-400)">Sync provider first.</td></tr><?php endif; ?>
            </tbody>
          </table></div>
        </div>
        <div class="card">
          <?php
            $os_imgs  = array_filter($images, fn($i) => ($i['image_type']??'system') === 'system');
            $app_imgs = array_filter($images, fn($i) => ($i['image_type']??'system') === 'app');
          ?>
          <div class="card-head"><span class="card-title">OS Images (<?= count($os_imgs) ?>)</span></div>
          <div class="card-body">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px">
              <?php foreach ($os_imgs as $img):
                $icon = get_os_icon_url($img['os_name']);
              ?>
              <div style="border:1px solid var(--border);border-radius:9px;padding:10px;text-align:center">
                <img src="<?= $icon ?>" width="26" height="26" style="display:block;margin:0 auto 5px;object-fit:contain" onerror="this.style.display='none'">
                <div style="font-size:12px;font-weight:700;color:var(--gray-900)"><?= htmlspecialchars(ucfirst($img['os_name'])) ?></div>
                <div style="font-size:11px;color:var(--gray-400)"><?= htmlspecialchars($img['os_version']??'') ?></div>
              </div>
              <?php endforeach; ?>
              <?php if (!$os_imgs): ?><div style="grid-column:1/-1;text-align:center;color:var(--gray-400);padding:20px">Sync provider first.</div><?php endif; ?>
            </div>
          </div>
          <?php if (!empty($app_imgs)): ?>
          <div class="card-head" style="border-top:1px solid var(--border)"><span class="card-title">Marketplace Apps (<?= count($app_imgs) ?>)</span></div>
          <div class="card-body">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px">
              <?php foreach ($app_imgs as $img):
                $icon = get_os_icon_url($img['os_name'] ?: $img['label']);
              ?>
              <div style="border:1px solid #bfdbfe;border-radius:9px;padding:10px;text-align:center;background:#f0f9ff">
                <img src="<?= $icon ?>" width="26" height="26" style="display:block;margin:0 auto 5px;object-fit:contain" onerror="this.innerHTML='📦';this.style.display='none'">
                <div style="font-size:12px;font-weight:700;color:var(--gray-900)"><?= htmlspecialchars($img['label']) ?></div>
                <div style="font-size:10px;color:#3b82f6;margin-top:2px;font-weight:600">APP</div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>

      <?php elseif ($tab === 'orders'): ?>
      <?php
        // Load data
        $orders_st = db()->query("SELECT co.*, u.full_name, u.username, u.email FROM custom_orders co JOIN users u ON u.id=co.user_id ORDER BY co.created_at DESC LIMIT 100");
        $orders    = $orders_st->fetchAll() ?: [];
        $tokens_st = db()->query("SELECT ct.*, u.full_name, u.username FROM server_claim_tokens ct LEFT JOIN users u ON u.id=ct.user_id ORDER BY ct.created_at DESC LIMIT 50");
        $tokens    = $tokens_st->fetchAll() ?: [];
        $providers_st = db()->query("SELECT id, display_name, provider_type FROM providers WHERE is_active=1 AND provider_type IN ('virtualizor','proxmox') ORDER BY display_name");
        $admin_providers = $providers_st->fetchAll() ?: [];
        $admin_msg = $_SESSION['admin_msg'] ?? ''; unset($_SESSION['admin_msg']);
        $admin_err = $_SESSION['admin_err'] ?? ''; unset($_SESSION['admin_err']);
      ?>
      <?php if ($admin_msg): ?><div class="card" style="padding:12px 18px;margin-bottom:14px;background:#f0fdf4;border-color:#bbf7d0;color:#15803d;font-size:13.5px"><?= $admin_msg ?></div><?php endif; ?>
      <?php if ($admin_err): ?><div class="card" style="padding:12px 18px;margin-bottom:14px;background:#fef2f2;border-color:#fca5a5;color:#dc2626;font-size:13.5px"><?= htmlspecialchars($admin_err) ?></div><?php endif; ?>

      <!-- Custom Orders list -->
      <div class="card" style="margin-bottom:24px">
        <div class="card-head"><span class="card-title">Custom Orders (<?= count($orders) ?>)</span></div>
        <?php if (empty($orders)): ?>
        <div style="padding:32px;text-align:center;color:var(--gray-400)">No custom orders yet.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="tbl">
          <thead><tr>
            <th>ID</th><th>User</th><th>Type</th><th>Config</th><th>OS</th><th>Status</th><th>Date</th><th>Actions</th>
          </tr></thead>
          <tbody>
          <?php foreach ($orders as $ord): ?>
          <tr>
            <td><span style="font-family:monospace;font-size:12px">#<?= $ord['id'] ?></span></td>
            <td>
              <div style="font-weight:700;font-size:13px"><?= htmlspecialchars($ord['full_name'] ?: $ord['username']) ?></div>
              <div style="font-size:11px;color:var(--gray-400)"><?= htmlspecialchars($ord['email']) ?></div>
            </td>
            <td><span style="font-weight:700;text-transform:uppercase;font-size:12px"><?= $ord['server_type'] ?></span></td>
            <td style="font-size:12.5px">
              <strong><?= $ord['cpu_cores'] ?></strong> vCPU &nbsp;
              <strong><?= $ord['ram_gb'] ?></strong>GB RAM &nbsp;
              <strong><?= $ord['disk_size'] ?></strong>GB <?= strtoupper($ord['disk_type']) ?>
              <?php if ($ord['cpu_brand'] !== 'any'): ?><br><span style="color:var(--gray-400)">CPU: <?= ucfirst($ord['cpu_brand']) ?></span><?php endif; ?>
            </td>
            <td style="font-size:12px"><?= htmlspecialchars($ord['os_pref'] ?: '—') ?></td>
            <td>
              <?php $sc=['pending'=>'badge-yellow','processing'=>'badge-blue','fulfilled'=>'badge-green','cancelled'=>'badge-gray'];?>
              <span class="badge <?= $sc[$ord['status']] ?? 'badge-gray' ?>"><?= ucfirst($ord['status']) ?></span>
            </td>
            <td style="font-size:12px;color:var(--gray-400)"><?= date('d M Y', strtotime($ord['created_at'])) ?></td>
            <?php if ($ord['status'] !== "fulfilled" && $ord['status'] !== "cancelled") : ?>
            <td>
              <button onclick="openGenToken(<?= $ord['id'] ?>)" class="btn btn-primary btn-sm" style="font-size:11px">
                🎁 Generate Token
              </button>
              <!-- Quick status update -->
              <form method="POST" style="display:inline;margin-left:4px">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="update_order_status">
                <input type="hidden" name="order_id" value="<?= $ord['id'] ?>">
                <select name="order_status" class="form-control" style="display:inline;width:auto;padding:4px 8px;font-size:11px;height:28px" onchange="this.form.submit()">
                  <?php foreach (['pending','processing','fulfilled','cancelled'] as $s): ?>
                  <option value="<?= $s ?>" <?= $ord['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <?php else : ?>
            <td>-N/A-</td>
            <?php endif; ?>
          </tr>
          <?php if ($ord['message']): ?>
          <tr><td colspan="8" style="padding:0 16px 12px;font-size:12px;color:var(--gray-500);background:var(--gray-50)">
            <strong>Notes:</strong> <?= htmlspecialchars($ord['message']) ?>
          </td></tr>
          <?php endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php endif; ?>
      </div>

      <!-- Claim Tokens list -->
      <div class="card">
        <div class="card-head"><span class="card-title">Claim Tokens (<?= count($tokens) ?>)</span></div>
        <?php if (empty($tokens)): ?>
        <div style="padding:32px;text-align:center;color:var(--gray-400)">No tokens generated yet.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="tbl">
          <thead><tr><th>Token</th><th>VPS ID</th><th>Config</th><th>Status</th><th>Claimed By</th><th>Expires</th></tr></thead>
          <tbody>
          <?php foreach ($tokens as $tok): ?>
          <tr>
            <td><span style="font-family:monospace;font-weight:700;font-size:13px;color:var(--primary)"><?= htmlspecialchars($tok['token']) ?></span></td>
            <td><span style="font-family:monospace;font-size:12px"><?= htmlspecialchars($tok['vps_id']) ?></span></td>
            <td style="font-size:12px"><?= $tok['vcpu'] ?>v / <?= $tok['ram_gb'] ?>GB / <?= $tok['disk_gb'] ?>GB<br><span style="color:var(--gray-400)"><?= htmlspecialchars($tok['os_label'] ?: '—') ?></span></td>
            <td>
              <?php if ($tok['user_id']): ?>
              <span class="badge badge-green">✓ Claimed</span>
              <?php elseif ($tok['expires_at'] && strtotime($tok['expires_at']) < time()): ?>
              <span class="badge badge-red">Expired</span>
              <?php else: ?>
              <span class="badge badge-yellow">Pending</span>
              <?php endif; ?>
            </td>
            <td style="font-size:12px"><?= $tok['user_id'] ? htmlspecialchars($tok['full_name'] ?: $tok['username'] ?? '—') : '—' ?></td>
            <td style="font-size:12px;color:var(--gray-400)"><?= $tok['expires_at'] ? date('d M Y', strtotime($tok['expires_at'])) : '∞ Never' ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php endif; ?>
      </div>

      <!-- Generate Token Modal -->
      <div id="gen-token-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100;align-items:center;justify-content:center;padding:20px">
        <div class="modal-box" style="max-width:500px">
          <div class="modal-head">
            <div style="font-weight:800;font-size:16px">🎁 Generate Claim Token</div>
            <button onclick="document.getElementById('gen-token-modal').classList.remove('open')" style="width:28px;height:28px;border:none;background:var(--gray-100);border-radius:6px;cursor:pointer">✕</button>
          </div>
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="generate_claim_token">
            <input type="hidden" name="order_id" id="gt_order_id">

            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px;margin-bottom:16px;font-size:12.5px;color:#15803d">
              ✅ Steps: 1) Create VPS on Virtualizor &nbsp; 2) Fill form below &nbsp; 3) Click Generate — token emailed to user automatically
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
              <div class="form-group">
                <label class="flabel">Provider</label>
                <select name="provider_id" class="form-control" required>
                  <option value="">— Select —</option>
                  <?php foreach ($admin_providers as $ap): ?>
                  <option value="<?= $ap['id'] ?>"><?= htmlspecialchars($ap['display_name']) ?> (<?= $ap['provider_type'] ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label class="flabel" id="gt_vpsid_label">VPS / VM ID</label>
                <input type="text" name="vps_id" class="form-control" required placeholder="e.g. 4 (Virtualizor) or 100 (Proxmox)" style="font-family:monospace">
              </div>
            </div>

            <div class="form-group">
              <label class="flabel">Server Name / Hostname</label>
              <input type="text" name="server_name" class="form-control" placeholder="e.g. custom-server-001">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
              <div class="form-group">
                <label class="flabel">vCPU</label>
                <input type="number" name="vcpu" class="form-control" value="2" min="1" required>
              </div>
              <div class="form-group">
                <label class="flabel">RAM (GB)</label>
                <input type="number" name="ram_gb" class="form-control" value="4" min="0.5" step="0.5" required>
              </div>
              <div class="form-group">
                <label class="flabel">Disk (GB)</label>
                <input type="number" name="disk_gb" class="form-control" value="100" min="1" required>
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
              <div class="form-group">
                <label class="flabel">OS Label</label>
                <input type="text" name="os_label" class="form-control" placeholder="e.g. Ubuntu 22.04">
              </div>
              <div class="form-group">
                <label class="flabel">City</label>
                <input type="text" name="region_slug" class="form-control" placeholder="e.g. noida">
              </div>
              <div class="form-group">
                <label class="flabel">Region</label>
                <input type="text" name="region_label" class="form-control" placeholder="e.g. in">
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
              <div class="form-group">
                <label class="flabel" id="gt_price_label">Price/hr (INR)</label>
                <input type="number" name="price_hourly" class="form-control" value="0" min="0" step="0.0001">
              </div>
              <div class="form-group">
                <label class="flabel">Currency</label>
                <select name="currency" class="form-control" id="gt_currency" onchange="document.getElementById('gt_price_label').textContent='Price/hr ('+this.value+')'">
                  <option value="INR">INR</option>
                  <option value="USD">USD</option>
                </select>
              </div>
              <div class="form-group">
                <label class="flabel">Expires (days, 0=never)</label>
                <input type="number" name="expires_days" class="form-control" value="30" min="0">
              </div>
            </div>

            <button type="submit" class="btn btn-primary btn-full">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
              Generate Token & Email User
            </button>
          </form>
        </div>
      </div>
      <script>
      function openGenToken(orderId) {
          document.getElementById('gt_order_id').value = orderId;
          var modal = document.getElementById('gen-token-modal');
          modal.style.display = 'flex';  // classList.remove('open') wali line hatao
          }
      document.getElementById('gen-token-modal')?.addEventListener('click', function(e){ 
  if(e.target===this) this.style.display = 'none'; 
});
      </script>

      <?php elseif ($tab === 'coupons'): ?>
      <?php
        $cpn_msg = $_SESSION['admin_msg'] ?? ''; unset($_SESSION['admin_msg']);
        $cpn_err = $_SESSION['admin_err'] ?? ''; unset($_SESSION['admin_err']);
        $coupons_list = db()->query("SELECT c.*, (SELECT COUNT(*) FROM coupon_uses cu WHERE cu.coupon_id=c.id) as use_count FROM coupons c ORDER BY c.created_at DESC")->fetchAll() ?: [];
      ?>
      <?php if ($cpn_msg): ?><div style="padding:12px 16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;margin-bottom:14px;color:#15803d;font-size:13.5px"><?= $cpn_msg ?></div><?php endif; ?>
      <?php if ($cpn_err): ?><div style="padding:12px 16px;background:#fef2f2;border:1px solid #fca5a5;border-radius:9px;margin-bottom:14px;color:#dc2626;font-size:13.5px"><?= htmlspecialchars($cpn_err) ?></div><?php endif; ?>

      <div style="display:grid;grid-template-columns:1fr 380px;gap:18px;align-items:start">

        <!-- Coupons list -->
        <div class="card">
          <div class="card-head">
            <span class="card-title">All Coupons (<?= count($coupons_list) ?>)</span>
          </div>
          <?php if (empty($coupons_list)): ?>
          <div style="padding:40px;text-align:center;color:var(--gray-400)">
            <div style="font-size:36px;margin-bottom:10px">🎟️</div>
            <div style="font-weight:700;margin-bottom:4px">No coupons yet</div>
            <div style="font-size:13px">Create your first coupon using the form →</div>
          </div>
          <?php else: ?>
          <div style="overflow-x:auto">
          <table class="tbl">
            <thead><tr>
              <th>Code</th><th>Type</th><th>Value</th><th>Uses</th>
              <th>Min Deposit</th><th>Expiry</th><th>Status</th><th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($coupons_list as $cp): ?>
            <?php
              $expired    = $cp['expires_at'] && strtotime($cp['expires_at']) < time();
              $maxed      = $cp['max_uses'] !== null && $cp['used_count'] >= $cp['max_uses'];
              $effectively_active = $cp['is_active'] && !$expired && !$maxed;
            ?>
            <tr>
              <td>
                <div style="font-family:monospace;font-weight:900;font-size:14px;color:var(--primary);letter-spacing:.5px"><?= htmlspecialchars($cp['code']) ?></div>
                <?php if ($cp['description']): ?><div style="font-size:11px;color:var(--gray-400);margin-top:2px"><?= htmlspecialchars($cp['description']) ?></div><?php endif; ?>
              </td>
              <td>
                <?php if ($cp['type'] === 'percentage'): ?>
                <span class="badge badge-purple">% Percent</span>
                <?php else: ?>
                <span class="badge badge-blue">₹ Fixed</span>
                <?php endif; ?>
              </td>
              <td style="font-weight:800;font-size:15px">
                <?= $cp['type']==='percentage' ? $cp['value'].'%' : '₹'.number_format((float)$cp['value'],2) ?>
                <?php if ($cp['max_discount']): ?>
                <div style="font-size:11px;color:var(--gray-400)">max ₹<?= number_format((float)$cp['max_discount'],0) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <div style="font-weight:700"><?= (int)$cp['use_count'] ?><?= $cp['max_uses'] ? '/'.$cp['max_uses'] : '' ?></div>
                <?php if ($maxed): ?><div style="font-size:10px;color:var(--danger)">Limit reached</div><?php endif; ?>
              </td>
              <td style="font-size:12.5px">
                <?= $cp['min_deposit'] > 0 ? '₹'.number_format((float)$cp['min_deposit'],0) : '—' ?>
              </td>
              <td style="font-size:12px">
                <?php if ($cp['expires_at']): ?>
                <span style="color:<?= $expired?'var(--danger)':'var(--gray-600)' ?>"><?= date('d M Y', strtotime($cp['expires_at'])) ?></span>
                <?php if ($expired): ?><br><span style="font-size:10px;color:var(--danger);font-weight:700">EXPIRED</span><?php endif; ?>
                <?php else: ?>
                <span style="color:var(--gray-400)">∞ Never</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge <?= $effectively_active ? 'badge-green' : 'badge-gray' ?>">
                  <?= $effectively_active ? '● Active' : '● Inactive' ?>
                </span>
              </td>
              <td>
                <div style="display:flex;gap:5px">
                  <!-- Toggle -->
                  <form method="POST" style="margin:0">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="toggle_coupon">
                    <input type="hidden" name="coupon_id" value="<?= $cp['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm" style="font-size:11px;padding:4px 8px">
                      <?= $cp['is_active'] ? 'Disable' : 'Enable' ?>
                    </button>
                  </form>
                  <!-- Delete -->
                  <form method="POST" style="margin:0">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="delete_coupon">
                    <input type="hidden" name="coupon_id" value="<?= $cp['id'] ?>">
                    <button type="submit" class="btn btn-sm" style="background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;font-size:11px;padding:4px 8px"
                            onclick="return confirm('Delete coupon <?= htmlspecialchars($cp['code']) ?>?')">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          <?php endif; ?>
        </div>

        <!-- Create coupon form -->
        <div class="card" style="position:sticky;top:80px">
          <div class="card-head">
            <span class="card-title">Create New Coupon</span>
          </div>
          <form method="POST" style="padding:18px">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="create_coupon">

            <div class="form-group">
              <label class="flabel">Coupon Code <span style="color:var(--danger)">*</span></label>
              <input type="text" name="coupon_code" class="form-control"
                     placeholder="e.g. SAVE50, WELCOME100"
                     style="font-family:monospace;font-weight:700;text-transform:uppercase;letter-spacing:1px"
                     oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9\-_]/g,'')"
                     maxlength="50" required>
            </div>

            <!-- Type selector -->
            <div class="form-group">
              <label class="flabel">Discount Type</label>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                <label id="ct-pct" onclick="selectCouponType('percentage')"
                       style="border:2px solid var(--primary);background:var(--primary-light);border-radius:9px;padding:10px;cursor:pointer;text-align:center;transition:all .12s">
                  <input type="radio" name="coupon_type" value="percentage" checked style="display:none">
                  <div style="font-size:18px">%</div>
                  <div style="font-size:12px;font-weight:700;margin-top:3px;color:var(--primary)">Percentage</div>
                </label>
                <label id="ct-fix" onclick="selectCouponType('fixed')"
                       style="border:2px solid var(--border);background:white;border-radius:9px;padding:10px;cursor:pointer;text-align:center;transition:all .12s">
                  <input type="radio" name="coupon_type" value="fixed" style="display:none">
                  <div style="font-size:18px">₹</div>
                  <div style="font-size:12px;font-weight:700;margin-top:3px;color:var(--gray-600)">Fixed Amount</div>
                </label>
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
              <div class="form-group" style="margin-bottom:0">
                <label class="flabel" id="val-label">Discount Value (%) *</label>
                <input type="number" name="coupon_value" class="form-control" min="0.01" step="0.01" required placeholder="e.g. 50">
              </div>
              <div class="form-group" style="margin-bottom:0" id="max-disc-wrap">
                <label class="flabel">Max Discount (₹)</label>
                <input type="number" name="max_discount" class="form-control" min="0" step="0.01" placeholder="Leave blank = no cap">
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px">
              <div class="form-group" style="margin-bottom:0">
                <label class="flabel">Min Deposit (₹)</label>
                <input type="number" name="min_deposit" class="form-control" min="0" value="0" step="0.01">
              </div>
              <div class="form-group" style="margin-bottom:0">
                <label class="flabel">Max Uses</label>
                <input type="number" name="max_uses" class="form-control" min="1" placeholder="Blank = unlimited">
              </div>
            </div>

            <div class="form-group" style="margin-top:10px">
              <label class="flabel">Expiry Date & Time</label>
              <input type="datetime-local" name="expires_at" class="form-control">
              <div style="font-size:11px;color:var(--gray-400);margin-top:4px">Leave blank = never expires</div>
            </div>

            <div class="form-group">
              <label class="flabel">Description</label>
              <input type="text" name="description" class="form-control" placeholder="Internal note e.g. New user promo" maxlength="200">
            </div>

            <div class="form-group">
              <label class="flabel">Status</label>
              <select name="is_active" class="form-control">
                <option value="1">● Active</option>
                <option value="0">○ Inactive</option>
              </select>
            </div>

            <!-- Preview -->
            <div id="coupon-preview" style="background:var(--gray-50);border:1.5px dashed var(--border);border-radius:9px;padding:12px;margin-bottom:14px;font-size:12.5px;display:none">
              <div style="font-weight:700;color:var(--gray-700);margin-bottom:4px">Preview</div>
              <div id="preview-text" style="color:var(--gray-600)"></div>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Create Coupon
            </button>
          </form>
        </div>
      </div>

      <script>
      function selectCouponType(t) {
        var isPct = t === 'percentage';
        document.getElementById('ct-pct').style.borderColor = isPct ? 'var(--primary)' : 'var(--border)';
        document.getElementById('ct-pct').style.background  = isPct ? 'var(--primary-light)' : 'white';
        document.getElementById('ct-pct').querySelector('div:nth-child(2)').style.color = isPct ? 'var(--primary)' : 'var(--gray-600)';
        document.getElementById('ct-fix').style.borderColor = !isPct ? 'var(--primary)' : 'var(--border)';
        document.getElementById('ct-fix').style.background  = !isPct ? 'var(--primary-light)' : 'white';
        document.getElementById('ct-fix').querySelector('div:nth-child(2)').style.color = !isPct ? 'var(--primary)' : 'var(--gray-600)';
        document.querySelector('[name="coupon_type"][value="'+t+'"]').checked = true;
        document.getElementById('val-label').textContent = isPct ? 'Discount Value (%) *' : 'Discount Amount (₹) *';
        document.getElementById('max-disc-wrap').style.display = isPct ? '' : 'none';
        updatePreview();
      }
      function updatePreview() {
        var code  = document.querySelector('[name="coupon_code"]').value || 'CODE';
        var type  = document.querySelector('[name="coupon_type"]:checked')?.value || 'percentage';
        var val   = document.querySelector('[name="coupon_value"]').value;
        var maxd  = document.querySelector('[name="max_discount"]').value;
        var mind  = document.querySelector('[name="min_deposit"]').value;
        var mu    = document.querySelector('[name="max_uses"]').value;
        if (!val) { document.getElementById('coupon-preview').style.display = 'none'; return; }
        var text = type === 'percentage'
          ? 'User gets ' + val + '% off their deposit (pays less, gets full amount credited)'
          : 'User gets ₹' + parseFloat(val).toFixed(2) + ' off their deposit';
        if (maxd) text += '\n• Max discount: ₹' + maxd;
        if (mind) text += '\n• Requires min deposit: ₹' + mind;
        if (mu)   text += '\n• Max ' + mu + ' total uses';
        document.getElementById('preview-text').textContent = text;
        document.getElementById('coupon-preview').style.display = '';
      }
      document.querySelectorAll('[name="coupon_code"],[name="coupon_value"],[name="max_discount"],[name="min_deposit"],[name="max_uses"]')
        .forEach(function(el){ el.addEventListener('input', updatePreview); });
      </script>

      <?php elseif ($tab === 'users'): ?>
      <!-- ═══════ USERS ═══════ -->
      <?php
      $all_users = db()->query("SELECT u.*, (SELECT COUNT(*) FROM servers WHERE user_id=u.id AND deleted_at IS NULL) as srv_count FROM users u ORDER BY u.created_at DESC")->fetchAll();
      $cc_map = ['+91'=>'in','+1'=>'us','+44'=>'gb','+49'=>'de','+61'=>'au','+65'=>'sg','+971'=>'ae','+92'=>'pk','+880'=>'bd','+94'=>'lk','+977'=>'np'];
      ?>
      <div class="card">
        <div class="card-head"><span class="card-title">All Users (<?= count($all_users) ?>)</span></div>
        <div class="tbl-wrap"><table class="tbl">
          <thead><tr><th>User</th><th>Email</th><th>Phone</th><th>Balance</th><th>Servers</th><th>Status</th><th>Role</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($all_users as $u):
            $phone_raw = $u['phone'] ?? '';
            $phone_flag = '';
            if ($phone_raw && str_starts_with($phone_raw, '+')) {
                foreach ($cc_map as $prefix => $cc) {
                    if (str_starts_with($phone_raw, $prefix)) { $phone_flag = $cc; break; }
                }
                if (!$phone_flag) $phone_flag = strtolower($u['country'] ?? 'in');
            }
            $u_json = htmlspecialchars(json_encode(['id'=>(int)$u['id'],'full_name'=>$u['full_name']??'','email'=>$u['email'],'phone'=>$u['phone']??'','role'=>$u['role'],'status'=>$u['status'],'currency'=>$u['currency']??'INR']), ENT_QUOTES);
          ?>
          <tr>
            <td>
              <div style="font-weight:700"><?= htmlspecialchars($u['username']) ?></div>
              <div style="font-size:11px;color:var(--gray-400)"><?= htmlspecialchars($u['full_name']??'') ?></div>
              <div style="font-size:10.5px;color:var(--gray-300);margin-top:1px">#<?= $u['id'] ?> · <?= date('d M Y', strtotime($u['created_at'])) ?></div>
            </td>
            <td style="font-size:12px"><?= htmlspecialchars($u['email']) ?></td>
            <td>
              <?php if ($phone_raw): ?>
              <div style="display:flex;align-items:center;gap:5px">
                <?php if ($phone_flag): ?><img src="https://flagcdn.com/w20/<?= $phone_flag ?>.png" width="16" height="12" style="border-radius:2px;border:1px solid #e2e8f0;flex-shrink:0" onerror="this.style.display='none'"><?php endif; ?>
                <span style="font-family:monospace;font-size:12px"><?= htmlspecialchars($phone_raw) ?></span>
                <button
                  onclick="openWaDirect('<?= preg_replace('/\D/','',$phone_raw) ?>','<?= htmlspecialchars(addslashes($u['full_name']?:$u['username']),ENT_QUOTES) ?>')"
                  title="Send WhatsApp Message"
                  style="background:none;border:none;cursor:pointer;padding:2px 3px;display:flex;align-items:center;opacity:.75;transition:opacity .15s"
                  onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='.75'"
                >
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="#25d366"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                </button>
              </div>
              <?php else: ?><span style="color:var(--gray-300);font-size:12px">—</span><?php endif; ?>
            </td>
            <td style="font-family:monospace;font-weight:700"><?= $u['currency']==='INR'?'₹':'$' ?><?= number_format((float)$u['wallet_balance'],2) ?></td>
            <td><?= $u['srv_count'] ?></td>
            <td><span class="badge <?= $u['status']==='active'?'badge-green':'badge-red' ?>"><?= $u['status'] ?></span></td>
            <td><span class="badge <?= $u['role']==='admin'?'badge-purple':'badge-blue' ?>"><?= $u['role'] ?></span></td>
            <td>
              <div style="display:flex;gap:4px;flex-wrap:wrap">
                <button class="btn btn-ghost btn-sm" onclick="openEditUser(<?= $u_json ?>)">✏️ Edit</button>
                <button class="btn btn-ghost btn-sm" onclick="openCredit(<?= $u['id'] ?>,'<?= htmlspecialchars($u['username'],ENT_QUOTES) ?>')">💰 Credit</button>
                <a href="<?= BASE_URL ?>/history.php?uid=<?= $u['id'] ?>" class="btn btn-ghost btn-sm">📋 History</a>
                <?php if ($u['role'] !== 'admin'): ?>
                <button class="btn btn-sm" style="background:#faf5ff;border:1px solid #e9d5ff;color:#7c3aed;padding:4px 8px"
                        onclick="impersonateUser(<?= $u['id'] ?>,'<?= htmlspecialchars($u['username'],ENT_QUOTES) ?>')">
                  👁 View As
                </button>
                <?php endif; ?>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="toggle_user">
                  <input type="hidden" name="uid" value="<?= $u['id'] ?>">
                  <input type="hidden" name="status" value="<?= $u['status']==='active'?'banned':'active' ?>">
                  <input type="hidden" name="tab_return" value="users">
                  <button type="submit" class="btn btn-sm <?= $u['status']==='active'?'btn-danger':'btn-success' ?>" style="padding:4px 8px"><?= $u['status']==='active'?'🚫 Ban':'✅ Unban' ?></button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>

      <?php elseif ($tab === 'servers'): ?>
      <!-- ═══════ ALL SERVERS ═══════ -->
      <?php require_once __DIR__ . '/../includes/servers.php';
      $all_srv = db()->query("SELECT s.*,u.username,u.currency FROM servers s JOIN users u ON u.id=s.user_id WHERE s.deleted_at IS NULL ORDER BY s.created_at DESC")->fetchAll(); ?>
      <div class="card">
        <div class="card-head"><span class="card-title">All Servers (<?= count($all_srv) ?>)</span></div>
        <div class="tbl-wrap"><table class="tbl">
          <thead><tr><th>Server</th><th>User</th><th>Status</th><th>IP</th><th>Plan</th><th>Region</th><th>Price/hr</th><th>Created</th></tr></thead>
          <tbody>
          <?php foreach ($all_srv as $s): $sym=$s['currency']==='INR'?'₹':'$'; ?>
          <tr>
            <td><div style="font-weight:700"><?= htmlspecialchars($s['name']) ?></div><div style="font-size:11px;color:var(--gray-400)"><?= htmlspecialchars($s['os_label']??'') ?></div></td>
            <td style="font-weight:600"><?= htmlspecialchars($s['username']) ?></td>
            <td><?= server_status_badge($s['status']) ?></td>
            <td style="font-family:monospace;font-size:12px"><?= htmlspecialchars($s['ipv4']??'—') ?></td>
            <td style="font-family:monospace"><?= strtoupper($s['plan_slug']) ?></td>
            <td><img src="https://flagcdn.com/w20/<?= htmlspecialchars($s['region_flag']??'de') ?>.png" width="14" style="border-radius:2px;vertical-align:middle;margin-right:4px" onerror="this.style.display='none'"><?= htmlspecialchars($s['region_label']??$s['region_slug']) ?></td>
            <td style="font-family:monospace;font-weight:700"><?= $sym.number_format((float)$s['price_hourly'],4) ?></td>
            <td style="font-size:12px"><?= date('d M Y',strtotime($s['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>

      <?php elseif ($tab === 'revenue'): ?>
      <?php
      // ── All revenue data queries ───────────────────────────
      $tz = 'Asia/Kolkata';
      $now      = new DateTime('now', new DateTimeZone($tz));
      $today    = $now->format('Y-m-d');
      $yest     = (clone $now)->modify('-1 day')->format('Y-m-d');
      $mStart   = $now->format('Y-m-01');
      $mEnd     = $now->format('Y-m-t');
      $lmStart  = (clone $now)->modify('first day of last month')->format('Y-m-d');
      $lmEnd    = (clone $now)->modify('last day of last month')->format('Y-m-d');
      $w7Start  = (clone $now)->modify('-6 days')->format('Y-m-d');

      // ── Helper: fetch deposits for a date range (all or per currency) ──
      function rev_sum(string $from, string $to, string $currency = ''): float {
          if ($currency) {
              $r = db()->prepare("SELECT COALESCE(SUM(t.amount),0) FROM transactions t JOIN users u ON u.id=t.user_id WHERE t.type='credit' AND t.ref_type='topup' AND u.currency=? AND DATE(t.created_at) BETWEEN ? AND ?");
              $r->execute([$currency, $from, $to]);
          } else {
              $r = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='credit' AND ref_type='topup' AND DATE(created_at) BETWEEN ? AND ?");
              $r->execute([$from, $to]);
          }
          return (float)$r->fetchColumn();
      }
      function order_count(string $from, string $to): int {
          $r = db()->prepare("SELECT COUNT(*) FROM servers WHERE deleted_at IS NULL AND DATE(created_at) BETWEEN ? AND ?");
          $r->execute([$from, $to]);
          return (int)$r->fetchColumn();
      }
      function pct_change(float $old, float $new): string {
          if ($old == 0) return $new > 0 ? '+100%' : '0%';
          $pct = round((($new - $old) / $old) * 100, 1);
          return ($pct >= 0 ? '+' : '') . $pct . '%';
      }
      function pct_dir(float $old, float $new): string {
          return $new >= $old ? 'up' : 'down';
      }

      // ── KPI values ────────────────────────────────────────
      // All-currency totals (backward compat display)
      $rev_today    = rev_sum($today,   $today);
      $rev_yest     = rev_sum($yest,    $yest);
      $rev_month    = rev_sum($mStart,  $today);
      $rev_lmonth   = rev_sum($lmStart, $lmEnd);
      $rev_7days    = rev_sum($w7Start, $today);
      $rev_7prev    = rev_sum((clone $now)->modify('-13 days')->format('Y-m-d'), (clone $now)->modify('-7 days')->format('Y-m-d'));

      // ── Per-currency KPI values ────────────────────────────
      $inr_today    = rev_sum($today,   $today,   'INR');
      $inr_yest     = rev_sum($yest,    $yest,    'INR');
      $inr_month    = rev_sum($mStart,  $today,   'INR');
      $inr_lmonth   = rev_sum($lmStart, $lmEnd,   'INR');
      $inr_7days    = rev_sum($w7Start, $today,   'INR');
      $inr_7prev    = rev_sum((clone $now)->modify('-13 days')->format('Y-m-d'), (clone $now)->modify('-7 days')->format('Y-m-d'), 'INR');

      $usd_today    = rev_sum($today,   $today,   'USD');
      $usd_yest     = rev_sum($yest,    $yest,    'USD');
      $usd_month    = rev_sum($mStart,  $today,   'USD');
      $usd_lmonth   = rev_sum($lmStart, $lmEnd,   'USD');
      $usd_7days    = rev_sum($w7Start, $today,   'USD');
      $usd_7prev    = rev_sum((clone $now)->modify('-13 days')->format('Y-m-d'), (clone $now)->modify('-7 days')->format('Y-m-d'), 'USD');

      $ord_today    = order_count($today,   $today);
      $ord_yest     = order_count($yest,    $yest);
      $ord_month    = order_count($mStart,  $today);
      $ord_lmonth   = order_count($lmStart, $lmEnd);
      $ord_7days    = order_count($w7Start, $today);
      $ord_7prev    = order_count((clone $now)->modify('-13 days')->format('Y-m-d'), (clone $now)->modify('-7 days')->format('Y-m-d'));

      // ── Daily chart data — last 30 days ───────────────────
      $daily_rev = []; $daily_rev_inr = []; $daily_rev_usd = [];
      $daily_ord = [];
      $daily_labels = [];
      for ($i = 29; $i >= 0; $i--) {
          $d = (clone $now)->modify("-{$i} days")->format('Y-m-d');
          $daily_labels[] = (clone $now)->modify("-{$i} days")->format('d M');
          $daily_rev[]     = round(rev_sum($d, $d), 2);
          $daily_rev_inr[] = round(rev_sum($d, $d, 'INR'), 2);
          $daily_rev_usd[] = round(rev_sum($d, $d, 'USD'), 2);
          $o = db()->prepare("SELECT COUNT(*) FROM servers WHERE deleted_at IS NULL AND DATE(created_at)=?");
          $o->execute([$d]); $daily_ord[] = (int)$o->fetchColumn();
      }

      // ── Monthly chart data — last 12 months ───────────────
      $monthly_rev = []; $monthly_rev_inr = []; $monthly_rev_usd = [];
      $monthly_ord = [];
      $monthly_labels = [];
      for ($i = 11; $i >= 0; $i--) {
          $ms = (clone $now)->modify("first day of -{$i} months")->format('Y-m-d');
          $me = (clone $now)->modify("last day of -{$i} months")->format('Y-m-t');
          $ml = (clone $now)->modify("first day of -{$i} months")->format('M Y');
          $monthly_labels[]    = $ml;
          $monthly_rev[]       = round(rev_sum($ms, $me), 2);
          $monthly_rev_inr[]   = round(rev_sum($ms, $me, 'INR'), 2);
          $monthly_rev_usd[]   = round(rev_sum($ms, $me, 'USD'), 2);
          $monthly_ord[]       = order_count($ms, $me);
      }

      // ── Top users by revenue ──────────────────────────────
      $top_users = db()->query("SELECT u.full_name, u.username, u.currency, COALESCE(SUM(t.amount),0) as total_rev, COUNT(DISTINCT s.id) as total_srv FROM transactions t JOIN users u ON u.id=t.user_id LEFT JOIN servers s ON s.user_id=u.id WHERE t.type='credit' AND t.ref_type='topup' GROUP BY u.id ORDER BY total_rev DESC LIMIT 10")->fetchAll();

      // ── Revenue by currency ────────────────────────────────
      $by_currency = db()->query("SELECT currency, COALESCE(SUM(amount),0) as total FROM transactions WHERE type='credit' AND ref_type='topup' GROUP BY currency")->fetchAll();
      $curr_sym_map = ['INR'=>'₹','USD'=>'$','EUR'=>'€'];
      ?>

      <!-- ── CSS for revenue page ──────────────────────────── -->
      <style>
        .kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}
        .kpi-card{background:white;border:1px solid var(--border);border-radius:12px;padding:18px 20px;box-shadow:var(--shadow-sm)}
        .kpi-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--gray-400);margin-bottom:8px}
        .kpi-value{font-size:26px;font-weight:900;color:var(--gray-900);letter-spacing:-.5px;line-height:1}
        .kpi-sub{font-size:12px;margin-top:6px;display:flex;align-items:center;gap:5px}
        .kpi-up{color:#16a34a;font-weight:700}.kpi-down{color:#dc2626;font-weight:700}.kpi-neu{color:var(--gray-400)}
        .kpi-vs{color:var(--gray-400)}
        .chart-card{background:white;border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:18px}
        .chart-title{font-size:14px;font-weight:800;color:var(--gray-900);margin-bottom:4px}
        .chart-sub{font-size:12px;color:var(--gray-400);margin-bottom:16px}
        .section-divider{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--gray-400);margin:22px 0 12px;display:flex;align-items:center;gap:10px}
        .section-divider::after{content:'';flex:1;height:1px;background:var(--border)}
        @media(max-width:900px){.kpi-grid{grid-template-columns:1fr 1fr}}
        @media(max-width:480px){.kpi-grid{grid-template-columns:1fr}}
      </style>

      <!-- Date range indicator -->
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px">
        <div style="font-size:12px;color:var(--gray-400)">
          Timezone: IST (Asia/Kolkata) &nbsp;·&nbsp; Today: <strong style="color:var(--gray-700)"><?= $today ?></strong>
        </div>
        <div style="font-size:12px;color:var(--gray-400)">All amounts in account currency</div>
      </div>

      <!-- ═══ REVENUE KPIs — SPLIT INR + USD ════════════════ -->
      <div class="section-divider">💰 Revenue (Wallet Deposits)</div>

      <!-- INR row -->
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
        <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#15803d;background:#f0fdf4;border:1px solid #86efac;border-radius:99px;padding:3px 11px">🇮🇳 INR — Indian Rupee</span>
        <span style="font-size:12px;color:#94a3b8">Total all-time: <strong style="color:#15803d">₹<?= number_format(rev_sum('2000-01-01', $today, 'INR'), 0) ?></strong></span>
      </div>
      <div class="kpi-grid" style="margin-bottom:20px">
        <?php foreach ([
          ['Today',      $inr_today,  $inr_yest,   'vs yesterday'],
          ['Yesterday',  $inr_yest,   null,        ''],
          ['This Month', $inr_month,  $inr_lmonth, 'vs last month'],
          ['Last 7 Days',$inr_7days,  $inr_7prev,  'vs prev 7 days'],
        ] as [$label, $val, $compare, $vs_label]):
          $pct = $compare !== null ? pct_change((float)$compare, (float)$val) : null;
          $dir = $compare !== null ? pct_dir((float)$compare, (float)$val) : null;
        ?>
        <div class="kpi-card" style="border-top:3px solid #16a34a">
          <div class="kpi-label"><?= $label ?></div>
          <div class="kpi-value" style="color:#16a34a">₹<?= number_format($val, 0) ?></div>
          <?php if ($pct !== null): ?>
          <div class="kpi-sub">
            <span class="kpi-<?= $dir ?>"><?= $dir === 'up' ? '▲' : '▼' ?> <?= $pct ?></span>
            <span class="kpi-vs"><?= $vs_label ?></span>
          </div>
          <?php elseif ($label === 'Yesterday'): ?>
          <div class="kpi-sub"><span class="kpi-vs"><?= date('d M Y', strtotime($yest)) ?></span></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- USD row -->
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
        <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#1d4ed8;background:#eff6ff;border:1px solid #bfdbfe;border-radius:99px;padding:3px 11px">🌐 USD — US Dollar</span>
        <span style="font-size:12px;color:#94a3b8">Total all-time: <strong style="color:#1d4ed8">$<?= number_format(rev_sum('2000-01-01', $today, 'USD'), 2) ?></strong></span>
      </div>
      <div class="kpi-grid" style="margin-bottom:22px">
        <?php foreach ([
          ['Today',      $usd_today,  $usd_yest,   'vs yesterday'],
          ['Yesterday',  $usd_yest,   null,        ''],
          ['This Month', $usd_month,  $usd_lmonth, 'vs last month'],
          ['Last 7 Days',$usd_7days,  $usd_7prev,  'vs prev 7 days'],
        ] as [$label, $val, $compare, $vs_label]):
          $pct = $compare !== null ? pct_change((float)$compare, (float)$val) : null;
          $dir = $compare !== null ? pct_dir((float)$compare, (float)$val) : null;
        ?>
        <div class="kpi-card" style="border-top:3px solid #2563eb">
          <div class="kpi-label"><?= $label ?></div>
          <div class="kpi-value" style="color:#2563eb">$<?= number_format($val, 2) ?></div>
          <?php if ($pct !== null): ?>
          <div class="kpi-sub">
            <span class="kpi-<?= $dir ?>"><?= $dir === 'up' ? '▲' : '▼' ?> <?= $pct ?></span>
            <span class="kpi-vs"><?= $vs_label ?></span>
          </div>
          <?php elseif ($label === 'Yesterday'): ?>
          <div class="kpi-sub"><span class="kpi-vs"><?= date('d M Y', strtotime($yest)) ?></span></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- ═══ ORDER KPIs ════════════════════════════════════ -->
      <div class="section-divider">🖥️ Server Orders (Deployments)</div>
      <div class="kpi-grid">
        <?php
        $ord_kpis = [
          ['Today',      $ord_today,  $ord_yest,   'vs yesterday'],
          ['Yesterday',  $ord_yest,   null,        ''],
          ['This Month', $ord_month,  $ord_lmonth, 'vs last month'],
          ['Last 7 Days',$ord_7days,  $ord_7prev,  'vs prev 7 days'],
        ];
        foreach ($ord_kpis as [$label, $val, $compare, $vs_label]):
          $pct = $compare !== null ? pct_change((float)$compare, (float)$val) : null;
          $dir = $compare !== null ? pct_dir((float)$compare, (float)$val) : null;
        ?>
        <div class="kpi-card" style="border-top:3px solid var(--primary)">
          <div class="kpi-label"><?= $label ?></div>
          <div class="kpi-value" style="color:var(--primary)"><?= number_format($val) ?></div>
          <?php if ($pct !== null): ?>
          <div class="kpi-sub">
            <span class="kpi-<?= $dir ?>">
              <?= $dir === 'up' ? '▲' : '▼' ?> <?= $pct ?>
            </span>
            <span class="kpi-vs"><?= $vs_label ?></span>
          </div>
          <?php elseif ($label === 'Yesterday'): ?>
          <div class="kpi-sub"><span class="kpi-vs">servers deployed</span></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- ═══ CHARTS ═══════════════════════════════════════ -->
      <div class="section-divider">📈 Charts</div>

      <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

      <!-- Daily Revenue + Orders (30 days) -->
      <div class="chart-card">
        <div class="chart-title">Daily Revenue & Orders — Last 30 Days</div>
        <div class="chart-sub">INR (₹) + USD ($) revenue separately &nbsp;·&nbsp; Line = Orders</div>
        <!-- Tabs -->
        <div style="display:flex;gap:6px;margin-bottom:14px">
          <button onclick="showChart('both')" id="btn-both" class="btn btn-primary btn-sm" style="font-size:12px">Both</button>
          <button onclick="showChart('rev')"  id="btn-rev"  class="btn btn-ghost btn-sm"  style="font-size:12px">Revenue Only</button>
          <button onclick="showChart('ord')"  id="btn-ord"  class="btn btn-ghost btn-sm"  style="font-size:12px">Orders Only</button>
        </div>
        <div style="position:relative;height:300px">
          <canvas id="daily-chart"></canvas>
        </div>
      </div>

      <!-- Monthly Revenue (12 months) -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div class="chart-card">
          <div class="chart-title">Monthly Revenue — Last 12 Months</div>
          <div class="chart-sub">Total deposits per month</div>
          <div style="position:relative;height:240px">
            <canvas id="monthly-rev-chart"></canvas>
          </div>
        </div>
        <div class="chart-card">
          <div class="chart-title">Monthly Orders — Last 12 Months</div>
          <div class="chart-sub">Server deployments per month</div>
          <div style="position:relative;height:240px">
            <canvas id="monthly-ord-chart"></canvas>
          </div>
        </div>
      </div>

      <!-- ═══ TOP USERS + CURRENCY ══════════════════════════ -->
      <div class="section-divider">📊 Breakdown</div>
      <div style="display:grid;grid-template-columns:2fr 1fr;gap:14px">

        <!-- Top 10 users -->
        <div class="chart-card" style="padding-bottom:0;overflow:hidden">
          <div class="chart-title">Top Users by Revenue</div>
          <div class="chart-sub">Ranked by total wallet deposits</div>
          <table class="tbl" style="min-width:unset">
            <thead><tr>
              <th>#</th><th>User</th><th>Revenue</th><th>Servers</th>
            </tr></thead>
            <tbody>
            <?php foreach ($top_users as $i => $u): ?>
            <tr>
              <td style="color:var(--gray-400);font-size:12px"><?= $i+1 ?></td>
              <td>
                <div style="font-weight:700;font-size:13px"><?= htmlspecialchars($u['full_name'] ?: $u['username']) ?></div>
                <div style="font-size:11px;color:var(--gray-400)">@<?= htmlspecialchars($u['username']) ?></div>
              </td>
              <td style="font-weight:700;color:var(--primary);font-size:13px">
                <?= ($curr_sym_map[$u['currency']] ?? '₹') . number_format((float)$u['total_rev'], 2) ?>
              </td>
              <td style="font-size:13px"><?= $u['total_srv'] ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($top_users)): ?>
            <tr><td colspan="4" style="text-align:center;color:var(--gray-400);padding:24px">No data yet</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Revenue by currency + quick stats -->
        <div>
          <div class="chart-card" style="margin-bottom:14px">
            <div class="chart-title">Revenue by Currency</div>
            <div class="chart-sub">All-time deposits per currency</div>
            <?php
            // Ensure INR and USD always shown even if 0
            $cur_map_full = ['INR' => ['₹', '#16a34a', '#f0fdf4'], 'USD' => ['$', '#2563eb', '#eff6ff']];
            $bc_map = [];
            foreach ($by_currency as $bc) $bc_map[$bc['currency']] = (float)$bc['total'];
            foreach ($cur_map_full as $cur => [$sym, $clr, $bg]):
                $total_cur = $bc_map[$cur] ?? 0;
                $tx_count  = (int)db()->prepare("SELECT COUNT(*) FROM transactions t JOIN users u ON u.id=t.user_id WHERE t.type='credit' AND t.ref_type='topup' AND u.currency=?")->execute([$cur]) ? db()->prepare("SELECT COUNT(*) FROM transactions t JOIN users u ON u.id=t.user_id WHERE t.type='credit' AND t.ref_type='topup' AND u.currency=?")->execute([$cur]) && ($tx_stmt = db()->prepare("SELECT COUNT(*) FROM transactions t JOIN users u ON u.id=t.user_id WHERE t.type='credit' AND t.ref_type='topup' AND u.currency=?")) && $tx_stmt->execute([$cur]) ? (int)$tx_stmt->fetchColumn() : 0 : 0;
                $user_count = (int)(db()->prepare("SELECT COUNT(*) FROM users WHERE currency=? AND role='user'")->execute([$cur]) ? ($uc_stmt=db()->prepare("SELECT COUNT(*) FROM users WHERE currency=? AND role='user'")) && $uc_stmt->execute([$cur]) ? $uc_stmt->fetchColumn() : 0 : 0);
            ?>
            <div style="background:<?= $bg ?>;border-radius:10px;padding:12px 14px;margin-bottom:10px">
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
                <div style="font-weight:700;font-size:13px;color:<?= $clr ?>"><?= $cur ?></div>
                <div style="font-weight:900;font-size:20px;color:<?= $clr ?>"><?= $sym . number_format($total_cur, $cur==='USD'?2:0) ?></div>
              </div>
              <div style="font-size:11px;color:<?= $clr ?>;opacity:.7"><?= $user_count ?> users &middot; all-time total</div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($by_currency)): ?>
            <div style="text-align:center;color:var(--gray-400);padding:20px;font-size:13px">No deposits yet</div>
            <?php endif; ?>
          </div>

          <!-- Quick stats -->
          <div class="chart-card">
            <div class="chart-title">Quick Stats</div>
            <?php
            $total_users   = (int)db()->query("SELECT COUNT(*) FROM users WHERE role='user'")->fetchColumn();
            $active_srv    = (int)db()->query("SELECT COUNT(*) FROM servers WHERE deleted_at IS NULL")->fetchColumn();
            $running_srv   = (int)db()->query("SELECT COUNT(*) FROM servers WHERE status='running' AND deleted_at IS NULL")->fetchColumn();
            $total_rev_all = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='credit' AND ref_type='topup'")->fetchColumn();
            $stats = [
              ['Total Users',     number_format($total_users), '👤'],
              ['Active Servers',  number_format($active_srv),  '🖥️'],
              ['Running Servers', number_format($running_srv), '✅'],
              ['INR Revenue',  '₹' . number_format(rev_sum('2000-01-01', $today, 'INR'), 0), '🇮🇳'],
              ['USD Revenue',  '$' . number_format(rev_sum('2000-01-01', $today, 'USD'), 2), '🌐'],
            ];
            foreach ($stats as [$lbl, $val, $icon]): ?>
            <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--gray-100)">
              <div style="font-size:13px;color:var(--gray-600)"><?= $icon ?> <?= $lbl ?></div>
              <div style="font-weight:800;font-size:14px;color:var(--gray-900)"><?= $val ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

      </div>

      <!-- ═══ CHART JS ══════════════════════════════════════ -->
      <script>
      var PRIMARY = getComputedStyle(document.documentElement).getPropertyValue('--primary').trim() || '#16a34a';
      var LABELS_30    = <?= json_encode($daily_labels)    ?>;
      var DATA_REV     = <?= json_encode($daily_rev)       ?>;
      var DATA_REV_INR = <?= json_encode($daily_rev_inr)   ?>;
      var DATA_REV_USD = <?= json_encode($daily_rev_usd)   ?>;
      var DATA_ORD     = <?= json_encode($daily_ord)       ?>;
      var LABELS_12    = <?= json_encode($monthly_labels)  ?>;
      var DATA_MREV    = <?= json_encode($monthly_rev)     ?>;
      var DATA_MREV_INR= <?= json_encode($monthly_rev_inr) ?>;
      var DATA_MREV_USD= <?= json_encode($monthly_rev_usd) ?>;
      var DATA_MORD    = <?= json_encode($monthly_ord)     ?>;

      Chart.defaults.font.family = "'Plus Jakarta Sans', system-ui, sans-serif";
      Chart.defaults.color       = '#94a3b8';

      // ── Daily combo chart ─────────────────────────────────
      var dailyCtx = document.getElementById('daily-chart').getContext('2d');
      var dailyChart = new Chart(dailyCtx, {
        data: {
          labels: LABELS_30,
          datasets: [
            {
              type: 'bar',
              label: 'Revenue INR (₹)',
              data: DATA_REV_INR,
              backgroundColor: 'rgba(22,163,74,.25)',
              borderColor: '#16a34a',
              borderWidth: 2,
              borderRadius: 4,
              yAxisID: 'y',
              stack: 'rev',
            },
            {
              type: 'bar',
              label: 'Revenue USD ($)',
              data: DATA_REV_USD,
              backgroundColor: 'rgba(37,99,235,.22)',
              borderColor: '#2563eb',
              borderWidth: 2,
              borderRadius: 4,
              yAxisID: 'y',
              stack: 'rev',
            },
            {
              type: 'line',
              label: 'Orders',
              data: DATA_ORD,
              borderColor: '#f59e0b',
              backgroundColor: '#f59e0b22',
              borderWidth: 2.5,
              pointBackgroundColor: '#f59e0b',
              pointRadius: 3,
              tension: 0.4,
              fill: false,
              yAxisID: 'y2',
            }
          ]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: { position: 'top', labels: { usePointStyle: true, padding: 18 } },
            tooltip: {
              callbacks: {
                label: function(ctx) {
                  if (ctx.dataset.yAxisID === 'y') return ' ₹' + ctx.parsed.y.toLocaleString('en-IN', {minimumFractionDigits:2});
                  return ' ' + ctx.parsed.y + ' orders';
                }
              }
            }
          },
          scales: {
            x: { grid: { display: false }, ticks: { maxTicksLimit: 10, font: { size: 11 } } },
            y: {
              type: 'linear', position: 'left',
              title: { display: true, text: 'Revenue (₹)', font: { size: 11 } },
              grid: { color: '#f1f5f9' },
              ticks: {
                callback: function(v) { return '₹' + (v >= 1000 ? (v/1000).toFixed(0)+'K' : v); },
                font: { size: 11 }
              }
            },
            y2: {
              type: 'linear', position: 'right',
              title: { display: true, text: 'Orders', font: { size: 11 } },
              grid: { display: false },
              ticks: { stepSize: 1, font: { size: 11 } }
            }
          }
        }
      });

      function showChart(mode) {
        dailyChart.data.datasets[0].hidden = (mode === 'ord');
        dailyChart.data.datasets[1].hidden = (mode === 'rev');
        dailyChart.update();
        ['both','rev','ord'].forEach(function(m) {
          var btn = document.getElementById('btn-' + m);
          btn.className = m === mode ? 'btn btn-primary btn-sm' : 'btn btn-ghost btn-sm';
          btn.style.fontSize = '12px';
        });
      }

      // ── Monthly Revenue chart — INR + USD stacked ────────────
      new Chart(document.getElementById('monthly-rev-chart').getContext('2d'), {
        type: 'bar',
        data: {
          labels: LABELS_12,
          datasets: [
            {
              label: 'INR (₹)',
              data: DATA_MREV_INR,
              backgroundColor: 'rgba(22,163,74,.3)',
              borderColor: '#16a34a',
              borderWidth: 1,
              borderRadius: 5,
              stack: 'rev',
            },
            {
              label: 'USD ($)',
              data: DATA_MREV_USD,
              backgroundColor: 'rgba(37,99,235,.25)',
              borderColor: '#2563eb',
              borderWidth: 1,
              borderRadius: 5,
              stack: 'rev',
            },
          ]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            x: { grid: { display: false }, ticks: { font: { size: 10 } } },
            y: {
              grid: { color: '#f1f5f9' },
              ticks: {
                callback: function(v){ return '₹'+(v>=1000?(v/1000).toFixed(0)+'K':v); },
                font: { size: 10 }
              }
            }
          }
        }
      });

      // ── Monthly Orders chart ───────────────────────────────
      new Chart(document.getElementById('monthly-ord-chart').getContext('2d'), {
        type: 'bar',
        data: {
          labels: LABELS_12,
          datasets: [{
            label: 'Orders',
            data: DATA_MORD,
            backgroundColor: LABELS_12.map(function(_,i){ return i === LABELS_12.length-1 ? '#f59e0b' : '#f59e0b66'; }),
            borderRadius: 6,
          }]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            x: { grid: { display: false }, ticks: { font: { size: 10 } } },
            y: {
              grid: { color: '#f1f5f9' },
              ticks: { stepSize: 1, font: { size: 10 } }
            }
          }
        }
      });
      </script>

      <?php elseif ($tab === 'transactions'): ?>
      <?php $all_tx = db()->query("SELECT t.*,u.username FROM transactions t JOIN users u ON u.id=t.user_id ORDER BY t.created_at DESC LIMIT 200")->fetchAll(); ?>
      <div class="card">
        <div class="card-head"><span class="card-title">Transactions (last 200)</span></div>
        <div class="tbl-wrap"><table class="tbl">
          <thead><tr><th>User</th><th>Type</th><th>Amount</th><th>Description</th><th>Date</th></tr></thead>
          <tbody>
          <?php foreach ($all_tx as $tx): $sym=$tx['currency']==='INR'?'₹':'$'; ?>
          <tr>
            <td style="font-weight:600"><?= htmlspecialchars($tx['username']) ?></td>
            <td><span class="badge <?= $tx['type']==='credit'?'badge-green':'badge-red' ?>"><?= $tx['type'] ?></span></td>
            <td style="font-family:monospace;font-weight:700;color:<?= $tx['type']==='credit'?'#16a34a':'var(--danger)' ?>"><?= ($tx['type']==='debit'?'−':'+').$sym.number_format((float)$tx['amount'],4) ?></td>
            <td style="font-size:12px;color:var(--gray-500)"><?= htmlspecialchars($tx['description']??'') ?></td>
            <td style="font-size:12px"><?= date('d M, H:i',strtotime($tx['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>

      <?php elseif ($tab === 'referrals'): ?>
      <!-- ══ REFERRAL PROGRAM TAB ══════════════════════════════════════ -->

      <?php if (!empty($flash)): ?>
      <div style="padding:12px 16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;margin-bottom:16px;color:#15803d;font-size:13.5px"><?= htmlspecialchars($flash) ?></div>
      <?php endif; ?>

      <!-- Stats -->
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px">
        <div style="background:white;border:1px solid var(--border);border-radius:12px;padding:16px;text-align:center">
          <div style="font-size:28px;font-weight:900;color:#0f172a"><?= (int)$ref_stats_row['total'] ?></div>
          <div style="font-size:12px;color:var(--gray-400);margin-top:4px">Total Referrals</div>
        </div>
        <div style="background:white;border:1px solid var(--border);border-radius:12px;padding:16px;text-align:center">
          <div style="font-size:28px;font-weight:900;color:var(--primary)"><?= (int)$ref_stats_row['rewarded'] ?></div>
          <div style="font-size:12px;color:var(--gray-400);margin-top:4px">Rewarded</div>
        </div>
        <div style="background:white;border:1px solid var(--border);border-radius:12px;padding:16px;text-align:center">
          <div style="font-size:28px;font-weight:900;color:#f59e0b"><?= (int)$ref_stats_row['pending'] ?></div>
          <div style="font-size:12px;color:var(--gray-400);margin-top:4px">Pending</div>
        </div>
        <div style="background:white;border:1px solid var(--border);border-radius:12px;padding:16px;text-align:center">
          <div style="font-size:18px;font-weight:900;color:#0f172a">
            <?php
              // Show combined total — sum from DB is mixed currencies, just show raw number
              echo number_format((float)$ref_stats_row['total_given'], 2);
            ?>
          </div>
          <div style="font-size:12px;color:var(--gray-400);margin-top:4px">Total Credits Given (all currencies)</div>
        </div>
      </div>

      <!-- Settings card -->
      <div class="list-card" style="padding:22px;margin-bottom:20px">
        <div style="font-size:15px;font-weight:800;color:#0f172a;margin-bottom:18px">⚙️ Referral Settings</div>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="save_referral_settings">
          <!-- General settings row -->
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px">
            <div class="form-group" style="margin:0">
              <label class="flabel">Program Status</label>
              <select name="referral_enabled" class="form-control">
                <option value="1" <?= $ref_settings['referral_enabled']==='1'?'selected':'' ?>>✓ Active</option>
                <option value="0" <?= $ref_settings['referral_enabled']==='0'?'selected':'' ?>>✗ Disabled</option>
              </select>
            </div>
            <div class="form-group" style="margin:0">
              <label class="flabel">Reward Trigger</label>
              <select name="referral_reward_on" class="form-control">
                <option value="register" <?= $ref_settings['referral_reward_on']==='register'?'selected':'' ?>>On Registration</option>
                <option value="topup"    <?= $ref_settings['referral_reward_on']==='topup'   ?'selected':'' ?>>On First Topup</option>
              </select>
              <small style="font-size:11px;color:#94a3b8">When to credit the referral bonus</small>
            </div>
          </div>

          <!-- INR Settings -->
          <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:16px;margin-bottom:14px">
            <div style="font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#15803d;margin-bottom:12px">🇮🇳 INR Settings — For Indian Rupee Users</div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px">
              <div class="form-group" style="margin:0">
                <label class="flabel">Referrer Bonus ₹ <span style="font-size:10px;color:#94a3b8">(referrer gets)</span></label>
                <input type="number" name="referral_bonus_referrer_inr" class="form-control" min="0" step="0.01"
                       value="<?= htmlspecialchars($ref_settings['referral_bonus_referrer_inr']) ?>" placeholder="e.g. 100">
                <small style="font-size:11px;color:#94a3b8">Credited to referrer's INR wallet</small>
              </div>
              <div class="form-group" style="margin:0">
                <label class="flabel">Referee Bonus ₹ <span style="font-size:10px;color:#94a3b8">(friend gets)</span></label>
                <input type="number" name="referral_bonus_referee_inr" class="form-control" min="0" step="0.01"
                       value="<?= htmlspecialchars($ref_settings['referral_bonus_referee_inr']) ?>" placeholder="e.g. 50">
                <small style="font-size:11px;color:#94a3b8">Credited to new user's INR wallet</small>
              </div>
              <div class="form-group" style="margin:0">
                <label class="flabel">Minimum Deposit ₹</label>
                <input type="number" name="referral_min_topup_inr" class="form-control" min="0" step="1"
                       value="<?= htmlspecialchars($ref_settings['referral_min_topup_inr']) ?>" placeholder="e.g. 500">
                <small style="font-size:11px;color:#94a3b8">0 = no minimum required</small>
              </div>
            </div>
          </div>

          <!-- USD Settings -->
          <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:16px;margin-bottom:18px">
            <div style="font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#1d4ed8;margin-bottom:12px">🌐 USD Settings — For US Dollar Users</div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px">
              <div class="form-group" style="margin:0">
                <label class="flabel">Referrer Bonus $ <span style="font-size:10px;color:#94a3b8">(referrer gets)</span></label>
                <input type="number" name="referral_bonus_referrer_usd" class="form-control" min="0" step="0.01"
                       value="<?= htmlspecialchars($ref_settings['referral_bonus_referrer_usd']) ?>" placeholder="e.g. 10">
                <small style="font-size:11px;color:#94a3b8">Credited to referrer's USD wallet</small>
              </div>
              <div class="form-group" style="margin:0">
                <label class="flabel">Referee Bonus $ <span style="font-size:10px;color:#94a3b8">(friend gets)</span></label>
                <input type="number" name="referral_bonus_referee_usd" class="form-control" min="0" step="0.01"
                       value="<?= htmlspecialchars($ref_settings['referral_bonus_referee_usd']) ?>" placeholder="e.g. 5">
                <small style="font-size:11px;color:#94a3b8">Credited to new user's USD wallet</small>
              </div>
              <div class="form-group" style="margin:0">
                <label class="flabel">Minimum Deposit $</label>
                <input type="number" name="referral_min_topup_usd" class="form-control" min="0" step="0.01"
                       value="<?= htmlspecialchars($ref_settings['referral_min_topup_usd']) ?>" placeholder="e.g. 10">
                <small style="font-size:11px;color:#94a3b8">0 = no minimum required</small>
              </div>
            </div>
          </div>

          <div style="background:#fef9c3;border:1px solid #fde047;border-radius:8px;padding:11px 14px;margin-bottom:14px;font-size:12.5px;color:#854d0e">
            💡 <strong>Currency logic:</strong> Each user's reward is determined by their own account currency.
            Referrer always gets <em>their</em> currency bonus. Referee always gets <em>their</em> currency bonus.
            Cross-currency referrals (INR → USD) work correctly — each party gets their own currency amount.
          </div>
          <button type="submit" class="btn btn-primary">Save Settings</button>
        </form>
      </div>

      <!-- Referrals table -->
      <div class="list-card" style="padding:0;overflow:hidden">
        <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
          <span style="font-size:15px;font-weight:800;color:#0f172a">All Referrals (<?= count($all_referrals) ?>)</span>
        </div>
        <?php if (empty($all_referrals)): ?>
        <div style="padding:40px;text-align:center;color:var(--gray-400);font-size:13.5px">No referrals yet.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
          <table style="width:100%;border-collapse:collapse">
            <thead style="background:#f8fafc">
              <tr>
                <th style="padding:9px 14px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--gray-400);border-bottom:1px solid var(--border)">Referrer</th>
                <th style="padding:9px 14px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--gray-400);border-bottom:1px solid var(--border)">Referee</th>
                <th style="padding:9px 14px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--gray-400);border-bottom:1px solid var(--border)">Joined</th>
                <th style="padding:9px 14px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--gray-400);border-bottom:1px solid var(--border)">Bonuses</th>
                <th style="padding:9px 14px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--gray-400);border-bottom:1px solid var(--border)">Status</th>
                <th style="padding:9px 14px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--gray-400);border-bottom:1px solid var(--border)">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($all_referrals as $r):
                $sym2 = $r['currency'] === 'INR' ? '₹' : '$';
                $stcls = match($r['status']) { 'rewarded'=>'background:#f0fdf4;color:var(--primary)', 'cancelled'=>'background:#fef2f2;color:#dc2626', default=>'background:#fef9c3;color:#854d0e' };
              ?>
              <tr style="border-bottom:1px solid #f1f5f9">
                <td style="padding:11px 14px;font-size:13px">
                  <div style="font-weight:700;color:#0f172a"><?= htmlspecialchars($r['referrer_name']) ?></div>
                  <div style="font-size:11.5px;color:var(--gray-400)"><?= htmlspecialchars($r['referrer_email']) ?></div>
                </td>
                <td style="padding:11px 14px;font-size:13px">
                  <div style="font-weight:700;color:#0f172a"><?= htmlspecialchars($r['referee_name']) ?></div>
                  <div style="font-size:11.5px;color:var(--gray-400)"><?= htmlspecialchars($r['referee_email']) ?></div>
                </td>
                <td style="padding:11px 14px;font-size:12.5px;color:var(--gray-500)"><?= date('d M Y', strtotime($r['referee_joined'])) ?></td>
                <td style="padding:11px 14px;font-size:13px">
                  <?php
                    // Parse "INR|USD" currency string
                    $r_cur_parts = explode('|', $r['currency'] ?? 'INR');
                    $r_ref_er_sym = strtoupper($r_cur_parts[0] ?? 'INR') === 'USD' ? '$' : '₹';
                    $r_ref_ee_sym = strtoupper($r_cur_parts[1] ?? $r_cur_parts[0] ?? 'INR') === 'USD' ? '$' : '₹';
                  ?>
                  <div style="color:var(--primary);font-weight:700">+<?= $r_ref_er_sym.number_format((float)$r['referrer_bonus'],2) ?> referrer</div>
                  <div style="color:#0891b2;font-size:12px">+<?= $r_ref_ee_sym.number_format((float)$r['referee_bonus'],2) ?> referee</div>
                </td>
                <td style="padding:11px 14px">
                  <span style="<?= $stcls ?>;padding:3px 9px;border-radius:99px;font-size:11.5px;font-weight:700">
                    <?= ucfirst($r['status']) ?>
                  </span>
                </td>
                <td style="padding:11px 14px">
                  <?php if ($r['status'] === 'pending'): ?>
                  <form method="POST" style="margin:0" onsubmit="return confirm('Manually reward this referral?')">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="manual_reward_referral">
                    <input type="hidden" name="ref_id" value="<?= $r['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-primary" style="font-size:12px">Reward Now</button>
                  </form>
                  <?php else: ?>
                  <span style="font-size:12px;color:var(--gray-400)"><?= $r['rewarded_at'] ? date('d M Y', strtotime($r['rewarded_at'])) : '—' ?></span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <?php elseif ($tab === 'tickets'): ?>
      <?php
        require_once __DIR__ . '/../includes/mailer.php';
        $adm_msg  = $_SESSION['admin_msg'] ?? ''; unset($_SESSION['admin_msg']);
        $filter_st= $_GET['status'] ?? 'all';
        $sel_tid  = $_GET['tid']    ?? '';

        // Load selected ticket
$view_tk    = null;
$view_reps  = [];
$view_uname = null;

if ($sel_tid) {
    // Ticket + user (UPDATED)
    $st = db()->prepare('SELECT t.*, u.full_name, u.username, u.email, u.account_type, u.company_name FROM tickets t JOIN users u ON u.id=t.user_id WHERE t.ticket_id=? LIMIT 1');
    $st->execute([$sel_tid]);
    $view_tk = $st->fetch() ?: null;

    if ($view_tk) {
        // Replies + user (UPDATED)
        $rp = db()->prepare("SELECT r.*, u.full_name, u.username, u.role, u.account_type, u.company_name, u.user_profile FROM ticket_replies r LEFT JOIN users u ON u.id=r.user_id WHERE r.ticket_id=? ORDER BY r.created_at ASC");
        $rp->execute([$view_tk['id']]);
        $view_reps = $rp->fetchAll() ?: [];

        // Ticket owner display name
        $view_uname = $view_tk['account_type']==='organization'
            ? ($view_tk['company_name'] ?: $view_tk['username'])
            : ($view_tk['full_name'] ?: $view_tk['username']);
    }
}

        // Load ticket list
        $where = '';
        if ($filter_st !== 'all') $where = "AND t.status='" . $filter_st . "'";
        $all_tkts = db()->query("SELECT t.*, u.full_name, u.username, u.email, (SELECT COUNT(*) FROM ticket_replies r WHERE r.ticket_id=t.id) as rep_cnt FROM tickets t JOIN users u ON u.id=t.user_id WHERE 1=1 {$where} ORDER BY t.updated_at DESC LIMIT 200")->fetchAll() ?: [];

        // Counts
        $tk_counts = [];
        foreach (db()->query("SELECT status, COUNT(*) as c FROM tickets GROUP BY status")->fetchAll() as $r) $tk_counts[$r['status']] = $r['c'];
        $tk_total = array_sum($tk_counts);

        function adm_status_style(string $s): array {
            return match($s) {
                'open'        => ['Open',                '#dc2626','#fef2f2'],
                'in_progress' => ['Answered',             '#2563eb','#eff6ff'],
                'waiting'     => ['Pending on customer',  '#d97706','#fffbeb'],
                'resolved'    => ['Answered',             '#16a34a','#f0fdf4'],
                'closed'      => ['Closed',               '#6b7280','#f1f5f9'],
                default       => [ucfirst($s),            '#6b7280','#f1f5f9'],
            };
        }
        function adm_dept_label(string $d): string {
            return match($d) { 'technical'=>'Cloud Support','billing'=>'Cloud Billing','sales'=>'Cloud Sales','abuse'=>'Abuse',default=>'Cloud Support' };
        }
      ?>
      <?php if ($adm_msg): ?>
      <div style="padding:10px 16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;margin-bottom:14px;color:#15803d;font-size:13px"><?= $adm_msg ?></div>
      <?php endif; ?>

      <style>
        .adm-tk-shell{display:flex;height:calc(100vh - 130px);border:1px solid var(--border);border-radius:12px;overflow:hidden;background:#fff}
        .adm-tk-list{width:340px;flex-shrink:0;border-right:1px solid var(--border);display:flex;flex-direction:column;background:#fff}
        .adm-tk-content{flex:1;display:flex;flex-direction:column;overflow:hidden;background:#f8fafc}
        .adm-tk-row{padding:12px 14px;border-bottom:1px solid #f1f5f9;cursor:pointer;text-decoration:none;display:block;color:inherit;transition:background .1s}
        .adm-tk-row:hover{background:#f8fafc}
        .adm-tk-row.active{background:#eff6ff;border-left:3px solid var(--primary)}
        .adm-msg-area{flex:1;overflow-y:auto;padding:18px 22px}
        .adm-msg-wrap{display:flex;gap:11px;margin-bottom:16px}
        .adm-msg-avatar{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;flex-shrink:0}
        .adm-msg-body{flex:1;min-width:0}
        .adm-msg-meta{font-size:11.5px;color:var(--gray-400);margin-bottom:4px;display:flex;align-items:center;gap:7px}
        .adm-msg-name{font-weight:700;color:#0f172a;font-size:13px}
        .adm-msg-text{background:#fff;border:1px solid var(--border);border-radius:0 10px 10px 10px;padding:12px 15px;font-size:13.5px;color:#374151;line-height:1.7;white-space:pre-wrap;word-break:break-word}
        .adm-msg-text.staff-reply{background:#f0f9ff;border-color:#bae6fd}
        .adm-rte-btn{width:26px;height:26px;border:none;background:transparent;cursor:pointer;border-radius:4px;color:var(--gray-500);font-size:12px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;transition:background .1s}
        .adm-rte-btn:hover{background:#f1f5f9;color:#0f172a}
      </style>

      <div class="adm-tk-shell">

        <!-- ── Left list ──────────────────────────────── -->
        <div class="adm-tk-list">
          <!-- Header + filters -->
          <div style="padding:12px 14px;border-bottom:1px solid var(--border);flex-shrink:0">
            <div style="font-size:15px;font-weight:800;color:#0f172a;margin-bottom:10px">
              Support Tickets
              <span style="font-size:12px;font-weight:500;color:var(--gray-400);margin-left:6px"><?= $tk_total ?> total</span>
            </div>
            <!-- Status filter pills -->
            <div style="display:flex;gap:4px;flex-wrap:wrap">
              <?php
              $fs = ['all'=>'All','open'=>'Open','waiting'=>'Pending','in_progress'=>'Answered','closed'=>'Closed'];
              foreach ($fs as $k=>$v):
                $cnt = $k==='all' ? $tk_total : ($tk_counts[$k] ?? 0);
              ?>
              <a href="?tab=tickets&status=<?= $k ?>" style="padding:3px 9px;border-radius:99px;font-size:11px;font-weight:700;text-decoration:none;background:<?= $filter_st===$k?'var(--primary)':'#f1f5f9' ?>;color:<?= $filter_st===$k?'#fff':'#64748b' ?>"><?= $v ?><?php if ($cnt) echo " ({$cnt})"; ?></a>
              <?php endforeach; ?>
            </div>
            <!-- Search -->
            <div style="position:relative;margin-top:8px">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--gray-400)"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" placeholder="Search tickets..." id="adm-tk-search" oninput="admTkSearch(this.value)"
                     style="width:100%;padding:6px 10px 6px 28px;background:#f1f5f9;border:1.5px solid transparent;border-radius:7px;font-size:12.5px;outline:none;box-sizing:border-box">
            </div>
          </div>
          <!-- Ticket rows -->
          <div id="adm-tk-list" style="flex:1;overflow-y:auto">
            <?php if (empty($all_tkts)): ?>
            <div style="padding:32px;text-align:center;color:var(--gray-400);font-size:13px">No tickets yet</div>
            <?php else: ?>
            <?php foreach ($all_tkts as $t):
              [$sl,$sc_,$sbg] = adm_status_style($t['status']);
              $is_sel = $view_tk && $view_tk['id'] === $t['id'];
            ?>
            <a class="adm-tk-row <?= $is_sel?'active':'' ?>" href="?tab=tickets&tid=<?= urlencode($t['ticket_id']) ?>&status=<?= $filter_st ?>">
              <div style="display:flex;justify-content:space-between;margin-bottom:2px">
                <span style="font-family:monospace;font-size:11.5px;font-weight:700;color:var(--primary)">#<?= $t['ticket_id'] ?></span>
                <span style="font-size:10.5px;color:var(--gray-400)"><?= date('d M, H:i', strtotime($t['updated_at'])) ?></span>
              </div>
              <div style="font-size:13px;font-weight:700;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:4px"><?= htmlspecialchars($t['subject']) ?></div>
              <div style="font-size:11.5px;color:var(--gray-400);margin-bottom:5px"><?= htmlspecialchars($t['full_name'] ?: $t['username']) ?></div>
              <div style="display:flex;gap:5px;flex-wrap:wrap">
                <span style="background:#f1f5f9;color:#475569;padding:1px 7px;border-radius:99px;font-size:10.5px;font-weight:600"><?= adm_dept_label($t['department']) ?></span>
                <span style="background:<?= $sbg ?>;color:<?= $sc_ ?>;padding:1px 7px;border-radius:99px;font-size:10.5px;font-weight:700"><?= $sl ?></span>
              </div>
            </a>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- ── Right content ──────────────────────────── -->
        <div class="adm-tk-content">
          <?php if (!$view_tk): ?>
          <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--gray-400)">
            <div style="font-size:40px;margin-bottom:12px">🎫</div>
            <div style="font-weight:700;font-size:15px;color:#374151;margin-bottom:5px">Select a ticket</div>
            <div style="font-size:13px">Click any ticket on the left to view and reply</div>
          </div>
          <?php else: ?>
          <?php [$sl,$sc_,$sbg] = adm_status_style($view_tk['status']); ?>

          <!-- Ticket header -->
          <div style="background:#fff;border-bottom:1px solid var(--border);padding:14px 20px;flex-shrink:0">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:14px">
              <div style="flex:1;min-width:0">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px">
                  <span style="font-family:monospace;font-weight:900;font-size:15px;color:var(--primary)">#<?= $view_tk['ticket_id'] ?></span>
                  <span style="background:<?= $sbg ?>;color:<?= $sc_ ?>;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700"><?= $sl ?></span>
                  <span style="background:#f1f5f9;color:#475569;padding:2px 9px;border-radius:99px;font-size:11px"><?= adm_dept_label($view_tk['department']) ?></span>
                </div>
                <div style="font-size:14px;font-weight:700;color:#0f172a"><?= htmlspecialchars($view_tk['subject']) ?></div>
                <div style="font-size:12px;color:var(--gray-400);margin-top:3px">
                  From: <strong><?= htmlspecialchars($view_tk['full_name'] ?: $view_tk['username']) ?></strong>
                  &lt;<?= htmlspecialchars($view_tk['email']) ?>&gt; &middot;
                  <?= date('Y-m-d H:i:s', strtotime($view_tk['created_at'])) ?>
                </div>
              </div>
              <!-- Quick status change -->
              <form method="POST" style="display:flex;align-items:center;gap:6px;flex-shrink:0">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="admin_update_ticket_status">
                <input type="hidden" name="ticket_db_id" value="<?= $view_tk['id'] ?>">
                <select name="ticket_status" class="form-control" style="font-size:12px;padding:5px 8px;height:auto;width:auto" onchange="this.form.submit()">
                  <option value="open"        <?= $view_tk['status']==='open'        ?'selected':'' ?>>Open</option>
                  <option value="in_progress" <?= $view_tk['status']==='in_progress' ?'selected':'' ?>>Answered</option>
                  <option value="waiting"     <?= $view_tk['status']==='waiting'     ?'selected':'' ?>>Pending on customer</option>
                  <option value="resolved"    <?= $view_tk['status']==='resolved'    ?'selected':'' ?>>Resolved</option>
                  <option value="closed"      <?= $view_tk['status']==='closed'      ?'selected':'' ?>>Closed</option>
                </select>
              </form>
            </div>
          </div>

          <!-- Messages -->
          <div class="adm-msg-area" id="adm-msg-area">
            <?php foreach ($view_reps as $rp):
              $is_staff = (int)$rp['is_admin'];
              $rp_name  = $is_staff ? (APP_NAME . ' Support') : (htmlspecialchars($rp['account_type']==='organization'?($rp['company_name']?:$rp['username']):($rp['full_name']?:$rp['username'])));
              $time_fmt = date('d M Y, H:i', strtotime($rp['created_at']));
              $rp_atts_st = db()->prepare('SELECT * FROM ticket_attachments WHERE reply_id=?');
              $rp_atts_st->execute([$rp['id']]);
              $rp_atts = $rp_atts_st->fetchAll() ?: [];
            ?>
            <div class="adm-msg-wrap">
              <div class="adm-msg-avatar" style="background:<?= $is_staff?'#1a1a2e':'var(--primary)' ?>;color:#fff">
<?php if ($is_staff): ?>
    ⚡
<?php else: ?>
    <?php if (!empty($rp['user_profile'])): ?>
        <img src="<?= htmlspecialchars(BASE_URL . '/' . $rp['user_profile']) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:5px;">
    <?php else: ?>
        <?= strtoupper(substr($rp_name, 0, 1)) ?>
    <?php endif; ?>
<?php endif; ?>
</div>
              <div class="adm-msg-body">
                <div class="adm-msg-meta">
                  <span class="adm-msg-name"><?= htmlspecialchars($rp_name) ?></span>
                  <?php if ($is_staff): ?>
                  <span style="background:#1a1a2e;color:#fff;padding:1px 6px;border-radius:99px;font-size:9px;font-weight:700">STAFF</span>
                  <?php endif; ?>
                  <span><?= $time_fmt ?></span>
                </div>
                <div class="adm-msg-text <?= $is_staff?'staff-reply':'' ?>"><?= nl2br(htmlspecialchars($rp['message'])) ?></div>
              <?php if (!empty($rp_atts)): ?>
              <div style="margin-top:7px;display:flex;flex-wrap:wrap;gap:5px">
                <?php foreach ($rp_atts as $att):
                  $ext  = strtolower(pathinfo($att['filename'], PATHINFO_EXTENSION));
                  $icon = in_array($ext,['jpg','jpeg','png','gif'])?'🖼️':($ext==='pdf'?'📄':($ext==='zip'?'🗜️':'📎'));
                  $size = $att['filesize'] < 1048576 ? round($att['filesize']/1024).'KB' : round($att['filesize']/1048576,1).'MB';
                ?>
                <a href="<?= BASE_URL . '/' . htmlspecialchars($att['filepath']) ?>" target="_blank" download="<?= htmlspecialchars($att['filename']) ?>"
                   style="display:inline-flex;align-items:center;gap:4px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:4px 8px;font-size:11.5px;color:#374151;text-decoration:none">
                  <?= $icon ?> <span style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($att['filename']) ?></span>
                  <span style="color:#94a3b8;font-size:10px">(<?= $size ?>)</span>
                </a>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
            <div id="adm-bottom"></div>
          </div>

          <!-- Reply box -->
          <?php if ($view_tk['status'] !== 'closed'): ?>
          <div style="border-top:1px solid var(--border);background:#fff;flex-shrink:0">
            <form method="POST" id="adm-reply-form" enctype="multipart/form-data">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="admin_reply_ticket">
              <input type="hidden" name="ticket_db_id" value="<?= $view_tk['id'] ?>">
              <input type="hidden" name="message" id="adm-reply-hidden">
              <input type="hidden" name="ticket_status" id="adm-status-hidden" value="in_progress">

              <!-- Reply toolbar -->
              <div style="display:flex;gap:2px;padding:8px 14px 5px;border-bottom:1px solid #f1f5f9;flex-wrap:wrap;align-items:center">
                <span style="font-size:12px;font-weight:700;color:#374151;margin-right:6px">↩ Reply</span>
                <button type="button" onclick="admFmt('bold')"          class="adm-rte-btn" title="Bold"><b>B</b></button>
                <button type="button" onclick="admFmt('italic')"        class="adm-rte-btn" title="Italic"><i>I</i></button>
                <button type="button" onclick="admFmt('underline')"     class="adm-rte-btn" title="Underline"><u>U</u></button>
                <button type="button" onclick="admFmt('strikeThrough')" class="adm-rte-btn" title="Strike"><s>S</s></button>
                <div style="width:1px;height:18px;background:var(--border);margin:0 4px"></div>
                <button type="button" onclick="admFmt('insertUnorderedList')" class="adm-rte-btn">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                </button>
                <button type="button" onclick="admFmt('insertOrderedList')" class="adm-rte-btn">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/></svg>
                </button>
                <div style="width:1px;height:18px;background:var(--border);margin:0 4px"></div>
                <button type="button" onclick="document.execCommand('undo')" class="adm-rte-btn">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.86"/></svg>
                </button>
                <button type="button" onclick="document.execCommand('redo')" class="adm-rte-btn">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-.49-4.86"/></svg>
                </button>
                <!-- Set status on send -->
                <div style="margin-left:auto;display:flex;align-items:center;gap:7px">
                  <span style="font-size:11.5px;color:var(--gray-400)">Set status:</span>
                  <select id="adm-send-status" class="form-control" style="font-size:12px;padding:4px 8px;height:auto;width:auto">
                    <option value="in_progress">Answered</option>
                    <option value="waiting">Pending on customer</option>
                    <option value="resolved">Resolved</option>
                    <option value="closed">Closed</option>
                  </select>
                </div>
              </div>

              <div id="adm-rte" contenteditable="true"
                   style="min-height:80px;max-height:180px;overflow-y:auto;padding:12px 16px;font-size:13.5px;color:#374151;line-height:1.7;outline:none"
                   data-placeholder="Write your reply..."
                   oninput="admUpdateChar()"></div>

              <!-- Attach preview -->
              <div id="adm-attach-preview" style="display:none;padding:8px 14px;border-top:1px solid #f1f5f9;display:flex;flex-wrap:wrap;gap:6px"></div>
              <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 14px;border-top:1px solid #f1f5f9">
                <div style="display:flex;align-items:center;gap:10px">
                  <label for="adm-attach-input" style="display:flex;align-items:center;gap:5px;cursor:pointer;font-size:12.5px;color:#64748b;font-weight:600;padding:4px 8px;border-radius:6px" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background=''">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                    Attach
                  </label>
                  <input type="file" id="adm-attach-input" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.txt,.zip,.doc,.docx,.xlsx,.csv,.log" style="display:none" onchange="admHandleAttach(this)">
                  <span id="adm-char-cnt" style="font-size:12px;color:var(--gray-400)">0 chars</span>
                </div>
                <button type="button" onclick="admSubmitReply()" class="btn btn-primary btn-sm" style="background:#1a1a2e;border-color:#1a1a2e">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                  Send Reply
                </button>
              </div>
            </form>
          </div>
          <?php else: ?>
          <div style="padding:12px 20px;background:#f8fafc;border-top:1px solid var(--border);text-align:center;font-size:13px;color:var(--gray-400)">
            Ticket is closed. Update status above to reopen.
          </div>
          <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <script>
      function admFmt(cmd, val) {
        document.getElementById('adm-rte').focus();
        document.execCommand(cmd, false, val || null);
      }
      function admUpdateChar() {
        var t = (document.getElementById('adm-rte').innerText||'').length;
        document.getElementById('adm-char-cnt').textContent = t + ' chars';
      }
      function admSubmitReply() {
        var rte = document.getElementById('adm-rte');
        var txt = (rte.innerText||'').trim();
        if (!txt) { rte.focus(); return; }
        document.getElementById('adm-reply-hidden').value  = txt;
        document.getElementById('adm-status-hidden').value = document.getElementById('adm-send-status').value;
        document.getElementById('adm-reply-form').submit();
      }
      var admPendingFiles = [];
      function admHandleAttach(input) {
        var preview = document.getElementById('adm-attach-preview');
        preview.style.display = 'flex';
        Array.from(input.files).forEach(function(file) {
          if (file.size > 10 * 1024 * 1024) { alert(file.name + ' too large'); return; }
          admPendingFiles.push(file);
          var idx = admPendingFiles.length - 1;
          var ext = file.name.split('.').pop().toLowerCase();
          var icon = ['jpg','jpeg','png','gif'].includes(ext) ? '🖼️' : ext==='pdf' ? '📄' : '📎';
          var size = file.size < 1048576 ? Math.round(file.size/1024) + 'KB' : (file.size/1048576).toFixed(1) + 'MB';
          var chip = document.createElement('div');
          chip.className = 'attach-chip';
          chip.id = 'adm-chip-' + idx;
          chip.innerHTML = icon + ' <span style="max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + file.name + '">' + file.name + '</span> <span style="color:#94a3b8;font-size:10px">(' + size + ')</span> <button type="button" onclick="admRemoveAttach(' + idx + ')">✕</button>';
          preview.appendChild(chip);
        });
        admSyncFiles();
      }
      function admRemoveAttach(idx) {
        admPendingFiles[idx] = null;
        var chip = document.getElementById('adm-chip-' + idx);
        if (chip) chip.remove();
        admSyncFiles();
      }
      function admSyncFiles() {
        var dt = new DataTransfer();
        admPendingFiles.forEach(function(f){ if(f) dt.items.add(f); });
        document.getElementById('adm-attach-input').files = dt.files;
      }
      function admTkSearch(q) {
        q = q.toLowerCase();
        document.querySelectorAll('.adm-tk-row').forEach(function(r) {
          r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
      }
      // Scroll to bottom of messages
      var ma = document.getElementById('adm-msg-area');
      if (ma) ma.scrollTop = ma.scrollHeight;

      // rte placeholder
      document.getElementById('adm-rte')?.addEventListener('focus', function() {
        if (!this.innerText.trim()) this.innerHTML = '';
      });
      </script>

      <?php elseif ($tab === 'invoices'): ?>
      <?php $all_inv = db()->query("SELECT i.*,u.username FROM invoices i JOIN users u ON u.id=i.user_id ORDER BY i.created_at DESC LIMIT 200")->fetchAll(); ?>
      <div class="card">
        <div class="card-head"><span class="card-title">Invoices (<?= count($all_inv) ?>)</span></div>
        <div class="tbl-wrap"><table class="tbl">
          <thead><tr><th>Invoice #</th><th>User</th><th>Type</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
          <tbody>
          <?php foreach ($all_inv as $inv): $sym=$inv['currency']==='INR'?'₹':'$'; ?>
          <tr>
            <td style="font-family:monospace;font-weight:700"><?= htmlspecialchars($inv['invoice_no']) ?></td>
            <td><?= htmlspecialchars($inv['username']) ?></td>
            <td><span class="badge badge-blue" style="font-size:10px"><?= $inv['type'] ?></span></td>
            <td style="font-family:monospace;font-weight:700"><?= $sym.number_format((float)$inv['amount'],2) ?></td>
            <td><span class="badge <?= $inv['status']==='paid'?'badge-green':'badge-yellow' ?>"><?= $inv['status'] ?></span></td>
            <td style="font-size:12px"><?= date('d M Y',strtotime($inv['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>


      <?php elseif ($tab === 'kyc'): ?>
      <?php
        $kyc_msg = $_SESSION['admin_msg'] ?? ''; unset($_SESSION['admin_msg']);
        $kyc_err = $_SESSION['admin_err'] ?? ''; unset($_SESSION['admin_err']);

        $filter_kyc = $_GET['kfilter'] ?? 'pending';
        $where_kyc  = $filter_kyc !== 'all' ? "AND k.status='" . $filter_kyc . "'" : '';

        $kyc_list = db()->query(
            "SELECT k.*, u.full_name, u.username, u.email, u.phone, u.country
             FROM kyc_requests k
             JOIN users u ON u.id=k.user_id
             WHERE 1=1 {$where_kyc}
             ORDER BY k.submitted_at DESC LIMIT 200"
        )->fetchAll() ?: [];

        $kyc_counts = [];
        foreach (db()->query("SELECT status, COUNT(*) as c FROM kyc_requests GROUP BY status")->fetchAll() as $r) {
            $kyc_counts[$r['status']] = (int)$r['c'];
        }
        $kyc_total = array_sum($kyc_counts);

        $doc_labels = ['aadhaar'=>'Aadhaar','driving_license'=>'Driving License','pan'=>'PAN Card','passport'=>'Passport'];
      ?>

      <?php if ($kyc_msg): ?>
      <div style="padding:12px 16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;margin-bottom:14px;color:#15803d;font-size:13.5px"><?= $kyc_msg ?></div>
      <?php endif; ?>
      <?php if ($kyc_err): ?>
      <div style="padding:12px 16px;background:#fef2f2;border:1px solid #fca5a5;border-radius:9px;margin-bottom:14px;color:#dc2626;font-size:13.5px"><?= htmlspecialchars($kyc_err) ?></div>
      <?php endif; ?>

      <!-- KYC Stats -->
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px">
        <?php foreach ([
          ['Total', $kyc_total, '#eff6ff','#2563eb'],
          ['Pending', $kyc_counts['pending'] ?? 0, '#fffbeb','#d97706'],
          ['Approved', $kyc_counts['approved'] ?? 0, '#f0fdf4','#16a34a'],
          ['Rejected', $kyc_counts['rejected'] ?? 0, '#fef2f2','#dc2626'],
        ] as [$lbl,$cnt,$bg,$clr]): ?>
        <div style="background:white;border:1px solid var(--border);border-radius:12px;padding:16px 18px">
          <div style="font-size:26px;font-weight:900;color:<?= $clr ?>"><?= $cnt ?></div>
          <div style="font-size:12px;color:var(--gray-500);margin-top:3px"><?= $lbl ?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Filter pills -->
      <div style="display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap">
        <?php foreach (['pending'=>'⏳ Pending','all'=>'All','approved'=>'✓ Approved','rejected'=>'✗ Rejected'] as $k=>$v): ?>
        <a href="?tab=kyc&kfilter=<?= $k ?>"
           style="padding:5px 14px;border-radius:99px;font-size:12.5px;font-weight:700;text-decoration:none;background:<?= $filter_kyc===$k?'var(--primary)':'white' ?>;color:<?= $filter_kyc===$k?'#fff':'var(--gray-600)' ?>;border:1.5px solid <?= $filter_kyc===$k?'var(--primary)':'var(--border)' ?>">
          <?= $v ?><?php if(isset($kyc_counts[$k])) echo " ({$kyc_counts[$k]})"; ?>
        </a>
        <?php endforeach; ?>
      </div>

      <!-- KYC Table -->
      <div class="card">
        <div class="card-head">
          <span class="card-title">KYC Requests (<?= count($kyc_list) ?>)</span>
        </div>
        <?php if (empty($kyc_list)): ?>
        <div style="padding:48px;text-align:center;color:var(--gray-400)">
          <div style="font-size:36px;margin-bottom:10px">🔍</div>
          <div style="font-weight:700;font-size:15px;margin-bottom:4px">No <?= $filter_kyc !== 'all' ? $filter_kyc : '' ?> KYC requests</div>
          <div style="font-size:13px">Requests will appear here when users submit KYC.</div>
        </div>
        <?php else: ?>
        <div class="tbl-wrap">
        <table class="tbl">
          <thead>
            <tr>
              <th>#</th>
              <th>User</th>
              <th>Document</th>
              <th>Files</th>
              <th>Status</th>
              <th>Video</th>
              <th>Submitted</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($kyc_list as $kyc_row): ?>
          <?php
            $st_map = [
              'pending'  => ['⏳ Pending',  '#d97706','#fffbeb'],
              'approved' => ['✓ Approved',  '#16a34a','#f0fdf4'],
              'rejected' => ['✗ Rejected',  '#dc2626','#fef2f2'],
            ];
            [$st_label,$st_clr,$st_bg] = $st_map[$kyc_row['status']] ?? ['?','#6b7280','#f1f5f9'];
          ?>
          <tr>
            <td style="font-family:monospace;font-size:12px;color:var(--gray-400)">#<?= $kyc_row['id'] ?></td>
            <td>
              <div style="font-weight:700;font-size:13px"><?= htmlspecialchars($kyc_row['full_name'] ?: $kyc_row['username']) ?></div>
              <div style="font-size:11.5px;color:var(--gray-400)"><?= htmlspecialchars($kyc_row['email']) ?></div>
              <?php if ($kyc_row['phone']): ?>
              <div style="font-size:11px;color:var(--gray-400);font-family:monospace"><?= htmlspecialchars($kyc_row['phone']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <span style="background:#eff6ff;color:#2563eb;padding:3px 9px;border-radius:6px;font-size:12px;font-weight:700">
                <?= htmlspecialchars($doc_labels[$kyc_row['doc_type']] ?? $kyc_row['doc_type']) ?>
              </span>
            </td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap">
                <?php if ($kyc_row['file_front']): ?>
                <a href="<?= BASE_URL ?>/admin/download-resume.php?file=<?= urlencode(basename($kyc_row['file_front'])) ?>&type=kyc" target="_blank"
                   style="display:inline-flex;align-items:center;gap:4px;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:4px 9px;font-size:11.5px;color:#15803d;text-decoration:none;font-weight:600">
                  <?php
                    $ext_f = strtolower(pathinfo($kyc_row['file_front'], PATHINFO_EXTENSION));
                    echo $ext_f === 'pdf' ? '📄' : '🖼️';
                  ?>
                  Front
                </a>
                <?php endif; ?>
                <?php if ($kyc_row['file_back']): ?>
                <a href="<?= BASE_URL ?>/admin/download-resume.php?file=<?= urlencode(basename($kyc_row['file_back'])) ?>&type=kyc" target="_blank"
                   style="display:inline-flex;align-items:center;gap:4px;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:4px 9px;font-size:11.5px;color:#15803d;text-decoration:none;font-weight:600">
                  <?php
                    $ext_b = strtolower(pathinfo($kyc_row['file_back'], PATHINFO_EXTENSION));
                    echo $ext_b === 'pdf' ? '📄' : '🖼️';
                  ?>
                  Back
                </a>
                <?php endif; ?>
              </div>
            </td>
            <td>
              <span style="background:<?= $st_bg ?>;color:<?= $st_clr ?>;padding:4px 10px;border-radius:99px;font-size:12px;font-weight:700">
                <?= $st_label ?>
              </span>
              <?php if ($kyc_row['status'] === 'rejected' && $kyc_row['reject_reason']): ?>
              <div style="font-size:11px;color:var(--gray-400);margin-top:4px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($kyc_row['reject_reason']) ?>">
                <?= htmlspecialchars(mb_strimwidth($kyc_row['reject_reason'], 0, 40, '…')) ?>
              </div>
              <?php endif; ?>
            </td>
            <td>
                <a href="<?= BASE_URL ?>/admin/download-resume.php?file=<?= urlencode(basename($kyc_row['file_video'])) ?>&type=kyc" target="_blank"
                   style="display:inline-flex;align-items:center;gap:4px;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:4px 9px;font-size:11.5px;color:#15803d;text-decoration:none;font-weight:600">
                  📽️Selfie Video
                </a>
            </td>
            <td style="font-size:12px;color:var(--gray-500)">
              <?= date('d M Y', strtotime($kyc_row['submitted_at'])) ?>
              <div style="font-size:11px;color:var(--gray-300)"><?= date('H:i', strtotime($kyc_row['submitted_at'])) ?></div>
            </td>
            <td>
              <?php if ($kyc_row['status'] === 'pending'): ?>
              <div style="display:flex;gap:5px">
                <!-- Approve -->
                <form method="POST" onsubmit="return confirm('Approve this KYC?')">
                  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="kyc_approve">
                  <input type="hidden" name="kyc_id" value="<?= $kyc_row['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-success" style="font-size:12px;padding:5px 10px">
                    ✓ Approve
                  </button>
                </form>
                <!-- Reject trigger -->
                <button type="button" class="btn btn-sm btn-danger" style="font-size:12px;padding:5px 10px"
                        onclick="openRejectModal(<?= $kyc_row['id'] ?>, '<?= htmlspecialchars(addslashes($kyc_row['full_name'] ?: $kyc_row['username'])) ?>')">
                  ✕ Reject
                </button>
              </div>
              <?php elseif ($kyc_row['status'] === 'approved'): ?>
              <span style="font-size:12px;color:#16a34a;font-weight:600">Reviewed <?= $kyc_row['reviewed_at'] ? date('d M Y', strtotime($kyc_row['reviewed_at'])) : '' ?></span>
              <?php else: ?>
              <span style="font-size:12px;color:#dc2626;font-weight:600">Rejected</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php endif; ?>
      </div>

      <!-- ── Reject Modal ──────────────────────────────────────── -->
      <div id="kyc-reject-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:200;align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)this.style.display='none'">
        <div style="background:white;border-radius:14px;width:100%;max-width:480px;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.2)">
          <div style="padding:16px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px">
            <div style="width:34px;height:34px;background:#fee2e2;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:18px">✕</div>
            <div>
              <div style="font-size:15px;font-weight:800;color:var(--gray-900)">Reject KYC</div>
              <div style="font-size:12px;color:var(--gray-400)" id="reject-modal-user"></div>
            </div>
            <button onclick="document.getElementById('kyc-reject-modal').style.display='none'" style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:20px;color:var(--gray-400)">×</button>
          </div>
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="kyc_reject">
            <input type="hidden" name="kyc_id" id="reject-kyc-id">
            <div style="padding:20px">
              <label style="font-size:12px;font-weight:700;color:var(--gray-700);display:block;margin-bottom:7px">
                Rejection Reason <span style="color:#dc2626">*</span>
              </label>
              <textarea name="reject_reason" id="reject-reason-txt" rows="4" class="form-control"
                        placeholder="e.g. Document is blurry or unclear. Please resubmit with a clearer image.&#10;&#10;Be specific so the user knows what to fix."
                        style="resize:vertical;font-size:13.5px;line-height:1.6" required></textarea>
              <div style="font-size:11.5px;color:var(--gray-400);margin-top:5px">This reason will be shown to the user and emailed to them.</div>

              <!-- Quick reason chips -->
              <div style="margin-top:12px">
                <div style="font-size:11px;font-weight:700;color:var(--gray-500);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px">Quick Reasons</div>
                <div style="display:flex;flex-wrap:wrap;gap:6px">
                  <?php foreach ([
                    'Document is blurry or unclear.',
                    'Document appears to be expired.',
                    'Name on document does not match account.',
                    'Both sides of the document are required.',
                    'Invalid or unsupported document type.',
                    'Document is cropped or incomplete.',
                  ] as $qr): ?>
                  <button type="button" onclick="appendReason('<?= addslashes($qr) ?>')"
                          style="padding:4px 10px;border-radius:99px;font-size:11.5px;background:#f1f5f9;border:1px solid var(--border);cursor:pointer;color:var(--gray-600);font-weight:600;transition:all .12s"
                          onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                    + <?= htmlspecialchars($qr) ?>
                  </button>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <div style="padding:13px 22px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end">
              <button type="button" onclick="document.getElementById('kyc-reject-modal').style.display='none'" class="btn btn-ghost btn-sm">Cancel</button>
              <button type="submit" class="btn btn-sm btn-danger">✕ Reject KYC & Notify User</button>
            </div>
          </form>
        </div>
      </div>

      <script>
      function openRejectModal(kycId, userName) {
        document.getElementById('reject-kyc-id').value = kycId;
        document.getElementById('reject-modal-user').textContent = 'User: ' + userName;
        document.getElementById('reject-reason-txt').value = '';
        document.getElementById('kyc-reject-modal').style.display = 'flex';
        setTimeout(function(){ document.getElementById('reject-reason-txt').focus(); }, 100);
      }
      function appendReason(text) {
        var ta = document.getElementById('reject-reason-txt');
        ta.value = ta.value ? ta.value.trimEnd() + ' ' + text : text;
        ta.focus();
      }
      </script>

      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ═══ ADD PLAN MODAL ═══════════════════════════════════════ -->
<div id="add-plan-modal" style="display:none" class="modal-bd">
  <div class="modal-box">
    <div class="modal-head">
      <div class="modal-title">Add Plan</div>
      <button onclick="this.closest('.modal-bd').style.display='none'" style="background:none;border:none;cursor:pointer;font-size:20px;color:var(--gray-400)">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="add_plan">
      <input type="hidden" name="provider_id" id="ap_pid">
      <div class="modal-body">
        <div class="form-row">
          <div>
            <label class="flabel">Plan API ID <span>(exact provider slug)</span></label>
            <input name="plan_api_id" id="ap_api_id" class="form-control" placeholder="cpx11" style="font-family:monospace;text-transform:lowercase" required>
            <div style="font-size:11px;color:var(--gray-400);margin-top:4px">Hetzner examples: cpx11, cpx21, cpx31, ccx13, cax11</div>
          </div>
          <div>
            <label class="flabel">Display Name <span>(shown to users)</span></label>
            <input name="display_name_plan" id="ap_dname" class="form-control" placeholder="Starter, Pro, etc.">
            <div style="font-size:11px;color:var(--gray-400);margin-top:4px">Leave blank to use plan ID</div>
          </div>
        </div>
        <div class="form-row full">
          <div>
            <label class="flabel">Locations <span>(select where to offer this plan)</span></label>
            <div class="loc-check-grid" id="ap_locs" style="margin-top:6px">
              <!-- Filled by JS -->
            </div>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="this.closest('.modal-bd').style.display='none'">Cancel</button>
        <button type="submit" class="btn btn-primary">Add Plan</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ EDIT PLAN MODAL ══════════════════════════════════════ -->
<div id="edit-plan-modal" style="display:none" class="modal-bd">
  <div class="modal-box">
    <div class="modal-head">
      <div class="modal-title">Edit Plan</div>
      <button onclick="this.closest('.modal-bd').style.display='none'" style="background:none;border:none;cursor:pointer;font-size:20px;color:var(--gray-400)">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="edit_plan">
      <input type="hidden" name="plan_id" id="ep2_id">
      <input type="hidden" name="provider_id" id="ep2_pid">
      <div class="modal-body">
        <div class="form-row">
          <div>
            <label class="flabel">Plan API ID</label>
            <input name="plan_api_id_edit" id="ep2_api" class="form-control" style="font-family:monospace" required>
          </div>
          <div>
            <label class="flabel">Display Name</label>
            <input name="display_name_plan_edit" id="ep2_dname" class="form-control">
          </div>
        </div>
        <div class="form-row full">
          <div>
            <label class="flabel">Locations</label>
            <div class="loc-check-grid" id="ep2_locs" style="margin-top:6px"></div>
          </div>
        </div>
        <div class="form-row full">
          <div>
            <label class="flabel">Status</label>
            <select name="plan_active" class="form-control">
              <option value="1">Active</option>
              <option value="0">Inactive</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="this.closest('.modal-bd').style.display='none'">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ EDIT PROVIDER MODAL ══════════════════════════════════ -->
<div id="edit-prov-modal" style="display:none" class="modal-bd">
  <div class="modal-box">
    <div class="modal-head">
      <div class="modal-title">Edit Provider</div>
      <button onclick="this.closest('.modal-bd').style.display='none'" style="background:none;border:none;cursor:pointer;font-size:20px;color:var(--gray-400)">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="save_provider">
      <input type="hidden" name="provider_id" id="epp_id">
      <input type="hidden" name="provider_type" id="epp_type">
      <div class="modal-body">
        <div class="form-row full">
          <div><label class="flabel">Display Name</label><input name="display_name" id="epp_name" class="form-control" required></div>
        </div>
        <div class="form-row" id="epp_virt_fields">
          <div><label class="flabel">Panel URL <span style="font-weight:400;color:#94a3b8">(Virtualizor)</span></label><input name="panel_url" id="epp_panel" class="form-control" placeholder="https://your-panel-ip" style="font-family:monospace"></div>
          <div><label class="flabel">API Pass <span style="font-weight:400;color:#94a3b8">(Virtualizor)</span></label><input name="api_pass" id="epp_pass" class="form-control" placeholder="Leave blank to keep existing" style="font-family:monospace"></div>
        </div>
        <div class="form-row">
          <div><label class="flabel">Server Location</label><input name="location" id="epp_loc" class="form-control" placeholder="e.g. Mumbai, India"></div>
          <div><label class="flabel">Location Flag <span style="font-weight:400;color:#94a3b8">(2-letter code)</span></label><input name="location_flag" id="epp_locflag" class="form-control" placeholder="in, us, sg, de" maxlength="2" style="text-transform:lowercase"></div>
        </div>
        <div class="form-row full">
          <div>
            <label class="flabel">API Key</label>
            <textarea name="api_key" id="epp_key" class="form-control" placeholder="Virtualizor API key (or Proxmox JSON credentials)" style="font-family:monospace" rows="3"></textarea>
            <div id="epp_key_hint" style="display:none;margin-top:6px;padding:8px 10px;background:#0d1117;border-radius:6px;font-family:monospace;font-size:11px;color:#3fb950;white-space:pre;line-height:1.6"></div>
          </div>
        </div>
        <div class="form-row">
          <div>
            <label class="flabel">Base Currency</label>
            <select name="currency_base" id="epp_cur" class="form-control">
              <option value="EUR">EUR — Euro</option>
              <option value="USD">USD — US Dollar</option>
              <option value="INR">INR — Indian Rupee</option>
            </select>
          </div>
          <div>
            <label class="flabel">Active</label>
            <select name="is_active" id="epp_active" class="form-control"><option value="1">Yes</option><option value="0">No</option></select>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="this.closest('.modal-bd').style.display='none'">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ ADD PROVIDER MODAL ═══════════════════════════════════ -->
<div id="add-prov-modal" style="display:none" class="modal-bd">
  <div class="modal-box">
    <div class="modal-head">
      <div class="modal-title">Add Provider</div>
      <button onclick="this.closest('.modal-bd').style.display='none'" style="background:none;border:none;cursor:pointer;font-size:20px;color:var(--gray-400)">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="add_provider">
      <div class="modal-body">
        <div class="form-row">
          <div>
            <label class="flabel">Provider Type</label>
            <select name="provider_type" id="app_type" class="form-control" required onchange="appUpdateHint()">
              <option value="">Select type…</option>
              <?php foreach ($available_types as $t): ?>
              <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars(ucfirst($t)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="flabel">Base Currency</label>
            <select name="currency_base" id="app_cur" class="form-control">
              <option value="EUR">EUR — Euro</option>
              <option value="USD">USD — US Dollar</option>
              <option value="INR">INR — Indian Rupee</option>
            </select>
          </div>
        </div>
        <div class="form-row full">
          <div><label class="flabel">Display Name</label><input name="display_name" id="app_name" class="form-control" placeholder="e.g. My Virtualizor Cloud" required></div>
        </div>
        <!-- Virtualizor: separate credential columns -->
        <div class="form-row" id="app_virt_fields">
          <div><label class="flabel">Panel URL <span style="font-weight:400;color:#94a3b8">(Virtualizor)</span></label><input name="panel_url" id="app_panel" class="form-control" placeholder="https://your-panel-ip" style="font-family:monospace"></div>
          <div><label class="flabel">API Pass <span style="font-weight:400;color:#94a3b8">(Virtualizor)</span></label><input name="api_pass" id="app_pass" class="form-control" placeholder="API password" style="font-family:monospace"></div>
        </div>
        <div class="form-row">
          <div><label class="flabel">Server Location</label><input name="location" id="app_loc" class="form-control" placeholder="e.g. Mumbai, India"></div>
          <div><label class="flabel">Location Flag <span style="font-weight:400;color:#94a3b8">(2-letter code)</span></label><input name="location_flag" id="app_locflag" class="form-control" placeholder="in, us, sg, de" maxlength="2" style="text-transform:lowercase"></div>
        </div>
        <div class="form-row full">
          <div>
            <label class="flabel" id="app_key_label">API Key</label>
            <textarea name="api_key" id="app_key" class="form-control" placeholder="Virtualizor API key (or Proxmox JSON credentials)" style="font-family:monospace" rows="3"></textarea>
            <div id="app_key_hint" style="display:none;margin-top:6px;padding:8px 10px;background:#0d1117;border-radius:6px;font-family:monospace;font-size:11px;color:#3fb950;white-space:pre;line-height:1.6"></div>
          </div>
        </div>
        <div class="form-row">
          <div>
            <label class="flabel">Margin %</label>
            <input type="number" step="0.1" name="margin_pct" id="app_margin" class="form-control" value="0">
          </div>
          <div>
            <label class="flabel">Active</label>
            <select name="is_active" id="app_active" class="form-control"><option value="1">Yes</option><option value="0">No</option></select>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="this.closest('.modal-bd').style.display='none'">Cancel</button>
        <button type="submit" class="btn btn-primary">Add Provider</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ EDIT USER MODAL ══════════════════════════════════════ -->
<div id="edit-user-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:200;align-items:center;justify-content:center;padding:20px">
  <div style="background:white;border-radius:16px;width:100%;max-width:500px;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.2)">
    <div style="padding:16px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
      <div style="font-size:15px;font-weight:800;color:var(--gray-900)">✏️ Edit User</div>
      <button onclick="document.getElementById('edit-user-modal').style.display='none'" style="background:none;border:none;cursor:pointer;font-size:20px;color:var(--gray-400)">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="edit_user">
      <input type="hidden" name="tab_return" value="users">
      <input type="hidden" name="uid" id="eu-uid">
      <div style="padding:20px;display:flex;flex-direction:column;gap:14px">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div>
            <label style="font-size:12px;font-weight:700;color:var(--gray-600);display:block;margin-bottom:5px">Full Name</label>
            <input type="text" name="full_name" id="eu-full-name" class="form-control" placeholder="Full name">
          </div>
          <div>
            <label style="font-size:12px;font-weight:700;color:var(--gray-600);display:block;margin-bottom:5px">Email</label>
            <input type="email" name="email" id="eu-email" class="form-control" required>
          </div>
        </div>
        <div>
          <label style="font-size:12px;font-weight:700;color:var(--gray-600);display:block;margin-bottom:5px">Phone Number</label>
          <input type="text" name="phone" id="eu-phone" class="form-control" placeholder="+91XXXXXXXXXX">
          <div style="font-size:11px;color:var(--gray-400);margin-top:3px">Include country code (e.g. +91 for India)</div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
          <div>
            <label style="font-size:12px;font-weight:700;color:var(--gray-600);display:block;margin-bottom:5px">Role</label>
            <select name="role" id="eu-role" class="form-control">
              <option value="user">User</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <div>
            <label style="font-size:12px;font-weight:700;color:var(--gray-600);display:block;margin-bottom:5px">Status</label>
            <select name="status" id="eu-status" class="form-control">
              <option value="active">Active</option>
              <option value="banned">Banned</option>
            </select>
          </div>
          <div>
            <label style="font-size:12px;font-weight:700;color:var(--gray-600);display:block;margin-bottom:5px">Currency</label>
            <select name="currency" id="eu-currency" class="form-control">
              <option value="INR">INR ₹</option>
              <option value="USD">USD $</option>
            </select>
          </div>
        </div>
      </div>
      <div style="padding:13px 22px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end">
        <button type="button" onclick="document.getElementById('edit-user-modal').style.display='none'" class="btn btn-ghost btn-sm">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ CREDIT MODAL ═════════════════════════════════════════ -->
<div id="credit-modal" style="display:none" class="modal-bd">
  <div class="modal-box">
    <div class="modal-head"><div class="modal-title">Manual Credit</div><button onclick="this.closest('.modal-bd').style.display='none'" style="background:none;border:none;cursor:pointer;font-size:20px;color:var(--gray-400)">×</button></div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="manual_credit">
      <input type="hidden" name="uid" id="cr_uid">
      <div class="modal-body">
        <p style="font-size:13px;color:var(--gray-600);margin-bottom:14px">Credit wallet of: <strong id="cr_uname"></strong></p>
        <div class="form-row">
          <div><label class="flabel">Amount</label><input name="amount" type="number" step="0.01" min="1" class="form-control" required placeholder="100.00" style="font-family:monospace"></div>
          <div><label class="flabel">Note</label><input name="note" class="form-control" value="Admin credit"></div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="this.closest('.modal-bd').style.display='none'">Cancel</button>
        <button type="submit" class="btn btn-success">Credit Wallet</button>
      </div>
    </form>
  </div>
</div>

<script>
var CSRF = '<?= $csrf ?>';
var BASE = '<?= BASE_URL ?>';

/* ── Fetch Regions (run before adding plans) ───────────────── */
function fetchRegions(pid, btn) {
  btn.disabled = true;
  var origText = btn.innerHTML;
  btn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation:spin .8s linear infinite"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.86"/></svg> Fetching…';

  var log = document.getElementById('sync-log-'+pid);
  log.innerHTML = '<div class="ll">Fetching regions from provider API...</div>';
  log.classList.add('open');

  fetch(BASE+'/providers/hetzner/fetch-regions.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({provider_id:pid, csrf_token:CSRF})
  })
  .then(r=>r.json()).then(d=>{
    btn.disabled = false;
    btn.innerHTML = origText;

    if (d.ok) {
      log.innerHTML = '<div class="ll ok">✓ '+esc(d.message)+'</div>';

      // Show region list in log
      if (d.regions && d.regions.length) {
        log.innerHTML += '<div class="ll" style="color:#58a6ff;margin-top:4px">Regions saved:</div>';
        d.regions.forEach(function(r) {
          log.innerHTML += '<div class="ll ok">  '+esc(r.slug)+' — '+esc(r.city)+', '+esc(r.country)+'</div>';
        });
      }

      log.innerHTML += '<div class="ll warn" style="margin-top:6px">Now go to Server Plans tab → Add Plan → locations will appear.</div>';
      log.scrollTop = log.scrollHeight;

      // Reload after 4s so Plans tab shows the new regions
      setTimeout(()=>location.reload(), 4000);
    } else {
      log.innerHTML = '<div class="ll err">✗ '+esc(d.error||'Failed')+'</div>';
    }
  }).catch(function(e){
    btn.disabled = false;
    btn.innerHTML = origText;
    log.innerHTML = '<div class="ll err">✗ Request failed: '+esc(e.message||'unknown')+'</div>';
  });
}

/* ── Sync provider ─────────────────────────────────────────── */
function doSync(pid, btn) {
  btn.disabled = true;
  btn.classList.add('spinning');
  var st  = document.getElementById('sync-st-'+pid);
  var log = document.getElementById('sync-log-'+pid);
  st.textContent = 'Syncing…'; st.className = 'sync-st';
  log.innerHTML  = ''; log.classList.add('open');

  fetch(BASE+'/api/sync-provider.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({provider_id:pid, csrf_token:CSRF})
  })
  .then(r=>r.json()).then(d=>{
    btn.disabled = false; btn.classList.remove('spinning');
    if (d.log) {
      log.innerHTML = d.log.map(l=>{
        var c = l.startsWith('✓')?'ok':l.startsWith('✗')?'err':l.startsWith('⚠')?'warn':'';
        return '<div class="ll '+c+'">'+esc(l)+'</div>';
      }).join('');
      if (d.samples) log.innerHTML += '<div class="ll" style="color:#58a6ff;margin-top:5px">Prices:</div>'+d.samples.map(s=>'<div class="ll ok">  '+esc(s)+'</div>').join('');
      log.scrollTop = log.scrollHeight;
    }
    st.textContent = d.ok ? '✓ '+(d.summary||'Done') : '✗ '+(d.error||'Failed');
    st.className   = 'sync-st '+(d.ok?'ok':'err');
    if (d.ok) setTimeout(()=>location.reload(), 3500);
  }).catch(()=>{ btn.disabled=false; btn.classList.remove('spinning'); st.textContent='✗ Request failed'; st.className='sync-st err'; });
}

/* ── Location checkboxes builder ────────────────────────────── */
function buildLocChecks(containerId, regions, selectedLocs, nameAttr) {
  var c = document.getElementById(containerId);
  if (!c || !regions.length) {
    c.innerHTML = '<div style="color:var(--gray-400);font-size:13px">No regions synced. Sync provider first.</div>';
    return;
  }
  c.innerHTML = regions.map(function(r) {
    var checked = selectedLocs.indexOf(r.slug) !== -1;
    return '<label class="loc-check '+(checked?'checked':'')+'" onclick="toggleLocCheck(this)">' +
      '<input type="checkbox" name="'+nameAttr+'[]" value="'+r.slug+'"'+(checked?' checked':'')+' style="accent-color:var(--primary)">' +
      '<img src="https://flagcdn.com/w20/'+(r.country_code||'de')+'.png" width="14" height="10" onerror="this.style.display=\'none\'">' +
      '<span class="loc-check-name">'+r.slug+'</span>' +
      '<span style="font-size:11px;color:var(--gray-400);margin-left:2px">'+r.city+'</span>' +
    '</label>';
  }).join('');
}
function toggleLocCheck(el) {
  var cb = el.querySelector('input[type=checkbox]');
  setTimeout(function(){ el.classList.toggle('checked', cb.checked); }, 0);
}

/* ── Add plan modal ─────────────────────────────────────────── */
function openAddPlan(pid, regions) {
  document.getElementById('ap_pid').value = pid;
  document.getElementById('ap_api_id').value = '';
  document.getElementById('ap_dname').value = '';
  buildLocChecks('ap_locs', regions, [], 'plan_locations');
  document.getElementById('add-plan-modal').style.display = 'flex';
}

/* ── Edit plan modal ─────────────────────────────────────────── */
function openEditPlan(plan, regions) {
  document.getElementById('ep2_id').value   = plan.id;
  document.getElementById('ep2_pid').value  = plan.provider_id;
  document.getElementById('ep2_api').value  = plan.plan_api_id;
  document.getElementById('ep2_dname').value= plan.display_name;
  var locs = plan.locations || [];
  buildLocChecks('ep2_locs', regions, locs, 'plan_locations_edit');
  document.getElementById('edit-plan-modal').querySelector('[name=plan_active]').value = plan.is_active ? '1' : '0';
  document.getElementById('edit-plan-modal').style.display = 'flex';
}

/* ── Edit provider ─────────────────────────────────────────── */
function openEditProv(prov) {
  // Resolve Virtualizor creds from columns OR from legacy JSON in api_key,
  // so the details always show even before the DB columns are added.
  var panel = prov.panel_url || '';
  var apiKey = prov.api_key || '';
  var apiPass = prov.api_pass || '';
  if ((!panel || !apiPass) && typeof apiKey === 'string' && apiKey.trim().charAt(0) === '{') {
    try {
      var j = JSON.parse(apiKey);
      panel   = panel   || (j.panel_url || '');
      apiPass = apiPass || (j.api_pass  || '');
      apiKey  = j.api_key || '';
    } catch (e) {}
  }

  document.getElementById('epp_id').value    = prov.id;
  document.getElementById('epp_type').value  = prov.provider_type || 'virtualizor';
  document.getElementById('epp_name').value  = prov.display_name;
  document.getElementById('epp_panel').value = panel;
  // Prefill the actual key/pass so the admin can see what's saved (editable).
  document.getElementById('epp_key').value   = (prov.provider_type === 'proxmox') ? (prov.api_key || '') : apiKey;
  document.getElementById('epp_pass').value  = apiPass;
  document.getElementById('epp_loc').value     = prov.location || '';
  document.getElementById('epp_locflag').value = prov.location_flag || '';
  // Virtualizor uses panel/key/pass columns; Proxmox uses a JSON blob in api_key
  document.getElementById('epp_virt_fields').style.display = (prov.provider_type === 'proxmox') ? 'none' : '';
  document.getElementById('epp_cur').value   = prov.currency_base || 'EUR';
  document.getElementById('epp_active').value= prov.is_active;

  // Show format hint for JSON-credential providers
  var hint   = document.getElementById('epp_key_hint');
  var ptype  = (prov.provider_type || '').toLowerCase();
  var hints  = {
    'contabo': '// Contabo — paste this JSON (fill in your values):\n{\n  "client_id":     "your-client-id",\n  "client_secret": "your-client-secret",\n  "api_user":      "your@email.com",\n  "api_password":  "your-contabo-password"\n}',
    'virtualizor': '// Virtualizor — paste this JSON (fill in your values):\n{\n  "panel_url": "https://your-virtualizor-panel.com",\n  "api_key":   "your-api-key",\n  "api_pass":  "your-api-pass"\n}\n// Get API Key: Virtualizor Admin → Configuration → API',
    'proxmox': '// Proxmox VE — paste this JSON (fill in your values):\n{\n  "host":         "https://your-proxmox-ip:8006",\n  "token_id":     "root@pam!mytoken",\n  "token_secret": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",\n  "node":         "pve",\n  "verify_ssl":   false\n}\n// Create token: Proxmox → Datacenter → API Tokens → Add'
  };
  if (hints[ptype]) {
    hint.textContent = hints[ptype];
    hint.style.display = 'block';
  } else {
    hint.style.display = 'none';
  }

  document.getElementById('edit-prov-modal').style.display = 'flex';
}

/* ── Add provider modal ───────────────────────────────────── */
var PROV_CRED_HINTS = {
  'contabo': '// Contabo — paste this JSON (fill in your values):\n{\n  "client_id":     "your-client-id",\n  "client_secret": "your-client-secret",\n  "api_user":      "your@email.com",\n  "api_password":  "your-contabo-password"\n}',
  'virtualizor': '// Virtualizor — paste this JSON (fill in your values):\n{\n  "panel_url": "https://your-virtualizor-panel.com",\n  "api_key":   "your-api-key",\n  "api_pass":  "your-api-pass"\n}\n// Get API Key: Virtualizor Admin → Configuration → API',
  'proxmox': '// Proxmox VE — paste this JSON (fill in your values):\n{\n  "host":         "https://your-proxmox-ip:8006",\n  "token_id":     "root@pam!mytoken",\n  "token_secret": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",\n  "node":         "pve",\n  "verify_ssl":   false\n}\n// Create token: Proxmox → Datacenter → API Tokens → Add',
  'hetzner': '// Hetzner Cloud — paste your API token (single line).\n// Get it: Hetzner Console → Security → API Tokens → Generate.',
  'digitalocean': '// DigitalOcean — paste your Personal Access Token (single line).\n// Get it: DO → API → Tokens → Generate New Token.',
  'vultr': '// Vultr — paste your API key (single line).\n// Get it: Vultr → Account → API.',
  'linode': '// Linode — paste your Personal Access Token (single line).\n// Get it: Linode → API Tokens → Create.'
};
function openAddProv() {
  document.getElementById('app_type').value   = '';
  document.getElementById('app_name').value   = '';
  document.getElementById('app_key').value    = '';
  document.getElementById('app_cur').value    = 'EUR';
  document.getElementById('app_margin').value = '0';
  document.getElementById('app_active').value = '1';
  appUpdateHint();
  document.getElementById('add-prov-modal').style.display = 'flex';
}
function appUpdateHint() {
  var ptype = (document.getElementById('app_type').value || '').toLowerCase();
  var hint  = document.getElementById('app_key_hint');
  // Virtualizor uses Panel URL + API Key + API Pass columns; Proxmox uses a JSON blob.
  document.getElementById('app_virt_fields').style.display = (ptype === 'proxmox') ? 'none' : '';
  document.getElementById('app_key_label').textContent = (ptype === 'proxmox') ? 'Credentials (JSON)' : 'API Key';
  // Default base currency guess per type
  var curGuess = {virtualizor:'INR', proxmox:'INR'};
  if (curGuess[ptype]) document.getElementById('app_cur').value = curGuess[ptype];
  if (PROV_CRED_HINTS[ptype]) {
    hint.textContent = PROV_CRED_HINTS[ptype];
    hint.style.display = 'block';
  } else {
    hint.style.display = 'none';
  }
}

/* ── Credit modal ─────────────────────────────────────────── */
function openEditUser(data) {
  if (typeof data === 'string') data = JSON.parse(data);
  document.getElementById('eu-uid').value       = data.id;
  document.getElementById('eu-full-name').value = data.full_name || '';
  document.getElementById('eu-email').value     = data.email || '';
  document.getElementById('eu-phone').value     = data.phone || '';
  document.getElementById('eu-role').value      = data.role || 'user';
  document.getElementById('eu-status').value    = data.status || 'active';
  document.getElementById('eu-currency').value  = data.currency || 'INR';
  document.getElementById('edit-user-modal').style.display = 'flex';
}

function openCredit(uid, uname) {
  document.getElementById('cr_uid').value = uid;
  document.getElementById('cr_uname').textContent = uname;
  document.getElementById('credit-modal').style.display = 'flex';
}

/* ── Close modals on backdrop click ─────────────────────────── */
document.querySelectorAll('.modal-bd').forEach(function(m) {
  m.addEventListener('click', function(e){ if(e.target===m) m.style.display='none'; });
});

function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function closeAdmSidebar() {
  document.getElementById('adm-sidebar').classList.remove('open');
  document.getElementById('adm-overlay').classList.remove('open');
}
 
// ════════════════════════════════════════════
// WA Direct Message Modal
// ════════════════════════════════════════════
var _waPhone = '', _waName = '', _waAppName = '<?= addslashes($app_name) ?>';
var _waCsrf  = '<?= $csrf ?>';
var _waBaseUrl = '<?= BASE_URL ?>';
 
var WA_QUICK_TEMPLATES = [
  { icon:'👋', label:'Welcome',  body:'Hello {{name}}! 👋\n\nWelcome to *' + _waAppName + '*! Your account is ready. Need any help? Just reply here.' },
  { icon:'💰', label:'Low Bal',  body:'Hi {{name}} ⚠️\n\nYour *' + _waAppName + '* wallet balance is low.\n\nTopup here: ' + _waBaseUrl + '/billing.php' },
  { icon:'🔔', label:'Reminder', body:'Hi {{name}} 👋\n\nThis is a reminder from *' + _waAppName + '*.\n\n[Your message here]' },
  { icon:'🎁', label:'Offer',    body:'Hi {{name}}! 🎁\n\nSpecial offer for you on *' + _waAppName + '*!\n\n[Offer details here]\n\n' + _waBaseUrl }
];
 
function openWaDirect(phone, name) {
  _waPhone = phone;
  _waName  = name || 'there';
 
  // Set header
  document.getElementById('wa-direct-to-name').textContent  = name || phone;
  document.getElementById('wa-direct-to-phone').textContent = '+' + phone;
 
  // Reset
  document.getElementById('wa-direct-msg').value = '';
  document.getElementById('wa-direct-preview-text').innerHTML = '<span style="color:#aaa">Type a message to preview...</span>';
  document.getElementById('wa-direct-result').style.display = 'none';
  document.getElementById('wa-direct-send-btn').disabled = false;
  document.getElementById('wa-direct-send-btn').innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Send Now';
 
  document.getElementById('wa-direct-modal').style.display = 'flex';
  setTimeout(function(){ document.getElementById('wa-direct-msg').focus(); }, 120);
}
 
function closeWaDirect() {
  document.getElementById('wa-direct-modal').style.display = 'none';
}
 
function waDirectPreview() {
  var raw = document.getElementById('wa-direct-msg').value;
  var displayed = raw.replace(/{{name}}/g, _waName).replace(/{{app_name}}/g, _waAppName);
  var html = displayed.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                      .replace(/\*(.*?)\*/g,'<strong>$1</strong>').replace(/\n/g,'<br>');
  document.getElementById('wa-direct-preview-text').innerHTML = html || '<span style="color:#aaa">Type a message to preview...</span>';
}
 
function waLoadTemplate(tpl) {
  var body = tpl.body.replace(/{{name}}/g, _waName).replace(/{{app_name}}/g, _waAppName);
  document.getElementById('wa-direct-msg').value = body;
  waDirectPreview();
}

// Render quick templates in modal
(function() {
  var grid = document.getElementById('wa-direct-tpl-grid');
  if (!grid) return;
  WA_QUICK_TEMPLATES.forEach(function(t) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.style.cssText = 'padding:6px 8px;border:1.5px solid var(--border);border-radius:7px;background:var(--gray-50);cursor:pointer;font-family:inherit;font-size:11px;font-weight:700;color:var(--text);transition:all .14s;text-align:center';
    btn.innerHTML = t.icon + ' ' + t.label;
    btn.onmouseover = function(){ this.style.borderColor='#25d366'; this.style.background='rgba(37,211,102,.07)'; };
    btn.onmouseout  = function(){ this.style.borderColor=''; this.style.background=''; };
    btn.onclick = function(){ waLoadTemplate(t); };
    grid.appendChild(btn);
  });
})();
 
function waSendDirect() {
  var msg = document.getElementById('wa-direct-msg').value.trim();
  if (!msg) { alert('Please type a message first.'); return; }
 
  // Render variables
  var rendered = msg.replace(/{{name}}/g, _waName).replace(/{{app_name}}/g, _waAppName);
 
  var btn = document.getElementById('wa-direct-send-btn');
  btn.disabled = true;
  btn.innerHTML = '<span style="width:12px;height:12px;border:2px solid rgba(255,255,255,.4);border-top-color:white;border-radius:50%;animation:wa-spin .55s linear infinite;display:inline-block"></span> Sending...';
 
  var fd = new FormData();
  fd.append('csrf_token', _waCsrf);
  fd.append('phone', _waPhone);
  fd.append('message', rendered);
 
  fetch(_waBaseUrl + '/admin/index.php?ajax=wa_send_direct', { method:'POST', body:fd })
    .then(function(r){ return r.json(); })
    .then(function(d){
      var res = document.getElementById('wa-direct-result');
      res.style.display = 'flex';
      if (d.ok) {
        res.style.background = 'rgba(37,211,102,.12)';
        res.style.color = '#16a34a';
        res.style.border = '1px solid rgba(37,211,102,.3)';
        res.innerHTML = '✅ <strong>Message sent successfully!</strong>';
        btn.disabled = false;
        btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Send Now';
      } else {
        res.style.background = 'rgba(220,38,38,.08)';
        res.style.color = '#dc2626';
        res.style.border = '1px solid rgba(220,38,38,.2)';
        res.innerHTML = '❌ ' + (d.error || 'Send failed. Check WA API settings.');
        btn.disabled = false;
        btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Send Now';
      }
    })
    .catch(function(){
      var res = document.getElementById('wa-direct-result');
      res.style.display = 'flex';
      res.style.background = 'rgba(220,38,38,.08)';
      res.style.color = '#dc2626';
      res.style.border = '1px solid rgba(220,38,38,.2)';
      res.innerHTML = '❌ Network error. Try again.';
      btn.disabled = false;
      btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Send Now';
    });
}
 
// Close on backdrop
document.getElementById('wa-direct-modal').addEventListener('click', function(e) {
  if (e.target === this) closeWaDirect();
});
 
</script>
 
<!-- ════ WA Direct Message Modal ════ -->
<div id="wa-direct-modal" style="display:none;position:fixed;inset:0;background:rgba(10,15,30,.6);backdrop-filter:blur(4px);z-index:300;align-items:center;justify-content:center;padding:20px">
  <div style="background:var(--surface);border:1px solid var(--border);border-radius:16px;width:100%;max-width:520px;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.25)">
 
    <!-- Header -->
    <div style="background:#075e54;padding:14px 20px;display:flex;align-items:center;gap:12px">
      <div style="width:38px;height:38px;border-radius:50%;background:#25d366;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:800;color:white;flex-shrink:0">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="white"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
      </div>
      <div style="flex:1">
        <div style="font-size:14px;font-weight:800;color:white" id="wa-direct-to-name">User</div>
        <div style="font-size:11px;color:rgba(255,255,255,.7)" id="wa-direct-to-phone">+91...</div>
      </div>
      <button onclick="closeWaDirect()" style="background:none;border:none;cursor:pointer;color:rgba(255,255,255,.7);font-size:22px;line-height:1;padding:2px 6px" onmouseover="this.style.color='white'" onmouseout="this.style.color='rgba(255,255,255,.7)'">×</button>
    </div>
 
    <div style="padding:20px;display:grid;grid-template-columns:1fr 1fr;gap:14px">
 
      <!-- Left: Compose -->
      <div>
        <!-- Quick templates -->
        <div style="margin-bottom:10px">
          <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Quick Templates</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px" id="wa-direct-tpl-grid"></div>
        </div>
 
        <div style="margin-bottom:10px">
          <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:5px;text-transform:uppercase;letter-spacing:.05em">
            Message <span style="color:var(--text-muted);font-weight:400;text-transform:none">· <code style="font-size:10px">{{name}}</code> <code style="font-size:10px">{{app_name}}</code></span>
          </div>
          <textarea id="wa-direct-msg"
            oninput="waDirectPreview()"
            placeholder="Type your WhatsApp message..."
            style="width:100%;box-sizing:border-box;padding:9px 11px;border:1.5px solid var(--border);border-radius:8px;background:var(--gray-50);color:var(--text);font-family:'JetBrains Mono',monospace;font-size:11.5px;line-height:1.55;resize:none;height:130px;outline:none;transition:border .15s"
            onfocus="this.style.borderColor='#25d366'" onblur="this.style.borderColor=''"
          ></textarea>
        </div>
 
        <!-- Result -->
        <div id="wa-direct-result" style="display:none;padding:9px 12px;border-radius:8px;font-size:12.5px;font-weight:600;margin-bottom:10px;gap:7px;align-items:center"></div>
 
        <button id="wa-direct-send-btn" onclick="waSendDirect()"
          style="width:100%;padding:10px;background:#25d366;color:white;border:none;border-radius:9px;font-size:13px;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;font-family:inherit;transition:background .15s"
          onmouseover="this.style.background='#128c5a'" onmouseout="this.style.background='#25d366'">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          Send Now
        </button>
      </div>
 
      <!-- Right: WA Preview -->
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Preview</div>
        <div style="background:#e5ddd5;border-radius:10px;padding:12px;min-height:200px">
          <div style="background:white;border-radius:0 8px 8px 8px;padding:9px 12px;box-shadow:0 1px 3px rgba(0,0,0,.12)">
            <div id="wa-direct-preview-text" style="font-size:12.5px;line-height:1.5;color:#111;white-space:pre-wrap;word-break:break-word">
              <span style="color:#aaa">Type a message to preview...</span>
            </div>
            <div style="font-size:10px;color:#667781;text-align:right;margin-top:4px"><?= date('h:i A') ?> ✓✓</div>
          </div>
        </div>
        <div style="margin-top:10px;background:var(--gray-50);border:1px solid var(--border);border-radius:8px;padding:10px 12px;font-size:11.5px;color:var(--text-muted);line-height:1.5">
          ⚡ <strong style="color:var(--text)">Direct send</strong> — No queue, no delay. Message goes immediately via WA API.
        </div>
      </div>
 
    </div>
  </div>
</div>
 
<style>
@keyframes wa-spin { to { transform:rotate(360deg); } }
</style>
 
<script>
var ADMIN_CSRF = '<?= csrf_token() ?>';
function impersonateUser(uid, uname) {
  if (!confirm('Enter the account of "' + uname + '"?\n\nYou will see their dashboard. A purple bar at the top lets you exit back.')) return;
  fetch('<?= BASE_URL ?>/api/admin-impersonate.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action:'enter', user_id: uid, csrf: ADMIN_CSRF})
  }).then(r=>r.json()).then(function(d){
    if (d.ok) window.location.href = d.redirect;
    else alert(d.error || 'Could not impersonate user');
  });
}
</script>
</body>
</html>