<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/currency.php';
require_login();

extract((function(){
    $user = current_user();
    $currency = strtoupper($user['currency'] ?? 'USD');
    return [
        'user'     => $user,
        'app_name' => APP_NAME,
        'currency' => $currency,
        'curr_sym' => currency_symbol($currency),
        'avatar'   => strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1)),
        'fname'    => htmlspecialchars($user['account_type'] === 'organization'
                        ? ($user['company_name'] ?: $user['username'])
                        : ($user['full_name'] ?: $user['username'])),
        'uname'    => htmlspecialchars($user['username']),
        'balance'  => (float)$user['wallet_balance'],
        'csrf'     => csrf_token(),
    ];
})());

// ── Fetch existing KYC ──────────────────────────────────────
$kyc = null;
try {
    $st = db()->prepare('SELECT * FROM kyc_requests WHERE user_id=? ORDER BY submitted_at DESC LIMIT 1');
    $st->execute([$user['id']]);
    $kyc = $st->fetch() ?: null;
} catch (Throwable $e) { $kyc = null; }

$msg = ''; $err = '';

// ── POST handlers ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_kyc') {
        if ($kyc && $kyc['status'] === 'rejected') {
            foreach (['file_front','file_back','file_video'] as $field) {
                if (!empty($kyc[$field])) {
                    $p = '/www/uploads/' . ltrim($kyc[$field], 'uploads/');
                    if (file_exists($p)) @unlink($p);
                }
            }
            db()->prepare('DELETE FROM kyc_requests WHERE id=? AND user_id=?')->execute([$kyc['id'], $user['id']]);
            $kyc = null;
            $msg = 'Previous KYC deleted. You can now submit a new one.';
        }
    }

    if ($action === 'submit_kyc') {
        if ($kyc && in_array($kyc['status'], ['pending','approved'])) {
            $err = 'You already have a KYC ' . $kyc['status'] . '.';
        } else {
            $doc_type    = $_POST['doc_type'] ?? '';
            $allowed     = ['aadhaar','driving_license','pan','passport'];
            $needs_back  = in_array($doc_type, ['aadhaar','driving_license']);

            if (!in_array($doc_type, $allowed)) {
                $err = 'Please select a valid document type.';
            } elseif (empty($_FILES['file_front']['name'])) {
                $err = 'Please upload the ' . ($needs_back ? 'front side of your ' : '') . 'document.';
            } elseif ($needs_back && empty($_FILES['file_back']['name'])) {
                $err = 'Please upload the back side of your document.';
            } elseif (empty($_FILES['file_video']['name'])) {
                $err = 'Please record and upload your selfie verification video.';
            } else {
                $upload_dir = '/www/uploads/kyc/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $allowed_mime = ['image/jpeg','image/png','image/gif','application/pdf'];
                $allowed_video_mime = ['video/webm','video/mp4','video/ogg','video/quicktime'];
                $max_size     = 5 * 1024 * 1024;
                $max_video_size = 50 * 1024 * 1024; // 50MB for video
                $saved = [];
                $fields = $needs_back ? ['file_front','file_back'] : ['file_front'];

                foreach ($fields as $field) {
                    $file = $_FILES[$field];
                    if ($file['error'] !== UPLOAD_ERR_OK)           { $err = ucfirst(str_replace('_',' ',$field)) . ' upload failed.'; break; }
                    $mime = mime_content_type($file['tmp_name']);
                    if (!in_array($mime, $allowed_mime))            { $err = 'Only JPG, PNG, or PDF allowed for document images.'; break; }
                    if ($file['size'] > $max_size)                  { $err = 'Document file must be under 5 MB.'; break; }
                    $ext_map  = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','application/pdf'=>'pdf'];
                    $ext      = $ext_map[$mime] ?? 'jpg';
                    $filename = 'kyc_u'.$user['id'].'_'.$field.'_'.bin2hex(random_bytes(6)).'.'.$ext;
                    if (!move_uploaded_file($file['tmp_name'], $upload_dir.$filename)) { $err = 'Could not save file. Please try again.'; break; }
                    $saved[$field] = 'kyc/' . $filename;
                }

                // Handle video upload
                if (!$err) {
                    $vfile = $_FILES['file_video'];
                    if ($vfile['error'] !== UPLOAD_ERR_OK) {
                        $err = 'Video upload failed. Please re-record and try again.';
                    } else {
                        $vmime = mime_content_type($vfile['tmp_name']);
                        // Accept broadly — browser-recorded webm often shows as video/webm or application/octet-stream
                        $vext = 'webm';
                        if (str_contains($vmime, 'mp4'))  $vext = 'mp4';
                        if (str_contains($vmime, 'ogg'))  $vext = 'ogg';
                        if ($vfile['size'] > $max_video_size) {
                            $err = 'Video file must be under 50 MB. Please record a shorter clip.';
                        } else {
                            $vfilename = 'kyc_u'.$user['id'].'_video_'.bin2hex(random_bytes(6)).'.'.$vext;
                            if (!move_uploaded_file($vfile['tmp_name'], $upload_dir.$vfilename)) {
                                $err = 'Could not save video. Please try again.';
                            } else {
                                $saved['file_video'] = 'kyc/' . $vfilename;
                            }
                        }
                    }
                }

                if (!$err) {
                    if ($kyc) db()->prepare('DELETE FROM kyc_requests WHERE id=? AND user_id=?')->execute([$kyc['id'],$user['id']]);
                    db()->prepare('INSERT INTO kyc_requests (user_id,doc_type,file_front,file_back,file_video,status) VALUES (?,?,?,?,?,"pending")')
                       ->execute([$user['id'],$doc_type,$saved['file_front']??null,$saved['file_back']??null,$saved['file_video']??null]);
                    $st = db()->prepare('SELECT * FROM kyc_requests WHERE user_id=? ORDER BY submitted_at DESC LIMIT 1');
                    $st->execute([$user['id']]);
                    $kyc = $st->fetch() ?: null;
                    $msg = 'KYC submitted successfully! We will review it within 24–48 hours.';
                } else {
                    foreach ($saved as $rel) { $f=__DIR__.'/'.$rel; if(file_exists($f)) @unlink($f); }
                }
            }
        }
    }
}

$kyc_status = $kyc['status'] ?? null;
$doc_labels = ['aadhaar'=>'Aadhaar Card','driving_license'=>'Driving License','pan'=>'PAN Card','passport'=>'Passport'];

// User's real name for the teleprompter text
$user_display_name = $user['account_type'] === 'organization'
    ? ($user['company_name'] ?: $user['full_name'] ?: $user['username'])
    : ($user['full_name'] ?: $user['username']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>KYC Verification — <?= $app_name ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;0,9..40,800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    body,.main-content { font-family:'DM Sans',sans-serif !important; }

    .kyc-page { padding:28px; max-width:800px; }

    /* ─── HERO BANNER ────────────────────────────────── */
    .kyc-hero {
      background: linear-gradient(130deg,#0c1a3a 0%,#0f2560 55%,#1346a8 100%);
      border-radius:18px; padding:28px 32px 24px;
      margin-bottom:22px;
      display:flex; align-items:center; gap:22px;
      position:relative; overflow:hidden;
    }
    .kyc-hero::before {
      content:''; position:absolute; top:-50px; right:-50px;
      width:220px; height:220px; border-radius:50%;
      background:rgba(255,255,255,.035); pointer-events:none;
    }
    .kyc-hero::after {
      content:''; position:absolute; bottom:-70px; right:100px;
      width:260px; height:260px; border-radius:50%;
      background:rgba(255,255,255,.025); pointer-events:none;
    }
    .kyc-hero-icon {
      width:64px; height:64px; flex-shrink:0;
      background:rgba(255,255,255,.1);
      border:1px solid rgba(255,255,255,.18);
      border-radius:18px;
      display:flex; align-items:center; justify-content:center;
    }
    .kyc-hero-title { font-size:21px; font-weight:800; color:#fff; letter-spacing:-.3px; margin-bottom:5px; }
    .kyc-hero-sub   { font-size:13px; color:rgba(255,255,255,.62); line-height:1.6; max-width:460px; }
    .kyc-hero-pills { display:flex; gap:7px; margin-top:14px; flex-wrap:wrap; }
    .kyc-hero-pill {
      background:rgba(255,255,255,.09); border:1px solid rgba(255,255,255,.16);
      color:rgba(255,255,255,.82); font-size:11px; font-weight:600;
      padding:3px 10px; border-radius:99px; letter-spacing:.2px;
    }

    /* ─── LEGAL BOX ──────────────────────────────────── */
    .legal-box {
      background:linear-gradient(135deg,#eff4ff,#e6eeff);
      border:1.5px solid #c0d0f8; border-radius:14px;
      padding:18px 20px; margin-bottom:20px;
    }
    .legal-box-hd {
      font-size:11.5px; font-weight:800; text-transform:uppercase;
      letter-spacing:.8px; color:#1e40af;
      margin-bottom:13px; display:flex; align-items:center; gap:7px;
    }
    .legal-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
    .legal-item {
      background:white; border:1px solid #c7d7f9;
      border-radius:10px; padding:11px 13px;
    }
    .legal-item-name { font-size:12px; font-weight:700; color:#1e40af; margin-bottom:3px; }
    .legal-item-desc { font-size:11.5px; color:#4b5563; line-height:1.5; }

    /* ─── KYC CARD ───────────────────────────────────── */
    .kyc-card {
      background:white; border:1.5px solid #e2e8f0;
      border-radius:16px; overflow:hidden;
      margin-bottom:16px;
      box-shadow:0 1px 3px rgba(0,0,0,.04);
    }
    .kyc-card-head {
      padding:13px 22px; border-bottom:1px solid #f1f5f9;
      display:flex; align-items:center; gap:10px;
      background:#fafbfd;
    }
    .kyc-step-num {
      width:26px; height:26px; border-radius:8px;
      background:#0f2560; color:white;
      font-size:12px; font-weight:800;
      display:flex; align-items:center; justify-content:center;
      flex-shrink:0;
    }
    .kyc-card-title { font-size:14px; font-weight:700; color:#0f172a; }
    .kyc-card-body  { padding:22px; }

    /* ─── DOC SELECTOR ───────────────────────────────── */
    .doc-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }

    .doc-card {
      border:2px solid #e2e8f0; border-radius:14px;
      padding:16px 14px 14px;
      cursor:pointer; transition:all .18s;
      background:white; position:relative;
    }
    .doc-card:hover {
      border-color:#93b4f8;
      transform:translateY(-2px);
      box-shadow:0 8px 24px rgba(19,70,168,.1);
    }
    .doc-card.selected {
      border-color:#1346a8;
      background:#f0f5ff;
      box-shadow:0 0 0 3px rgba(19,70,168,.12), 0 8px 24px rgba(19,70,168,.1);
    }
    .doc-card input[type=radio] { position:absolute; opacity:0; width:0; height:0; }

    .doc-illus {
      width:100%; height:52px;
      border-radius:9px; overflow:hidden;
      margin-bottom:11px;
      display:flex; align-items:center; justify-content:center;
    }
    .doc-card-name { font-size:13.5px; font-weight:700; color:#0f172a; margin-bottom:3px; }
    .doc-card-hint { font-size:11.5px; color:#64748b; }

    .doc-card-check {
      position:absolute; top:11px; right:11px;
      width:22px; height:22px; border-radius:50%;
      border:2px solid #cbd5e1; background:white;
      display:flex; align-items:center; justify-content:center;
      transition:all .18s;
    }
    .doc-card.selected .doc-card-check {
      background:#1346a8; border-color:#1346a8;
    }
    .doc-sides-badge {
      position:absolute; bottom:11px; right:11px;
      font-size:10px; font-weight:700;
      padding:2px 7px; border-radius:99px;
      background:#f0f5ff; color:#1346a8;
      border:1px solid #c0d0f8;
    }
    .doc-card.selected .doc-sides-badge { background:#1346a8; color:white; border-color:#1346a8; }

    /* ─── UPLOAD ZONE ─────────────────────────────────── */
    .upload-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    .upload-row.single { grid-template-columns:1fr; }

    .upload-slot-label {
      font-size:12px; font-weight:700; color:#374151;
      margin-bottom:7px; display:flex; align-items:center; gap:6px;
    }
    .upload-slot-label .req {
      background:#fff0f0; color:#dc2626;
      font-size:10px; padding:1px 7px;
      border-radius:99px; font-weight:700;
    }

    .drop-zone {
      border:2px dashed #cbd5e1; border-radius:13px;
      min-height:128px; background:#fafbfd;
      cursor:pointer; position:relative;
      transition:all .18s;
      display:flex; align-items:center; justify-content:center;
      text-align:center; padding:18px 14px;
    }
    .drop-zone:hover, .drop-zone.drag-over {
      border-color:#1346a8; background:#eff4ff;
    }
    .drop-zone input[type=file] {
      position:absolute; inset:0; opacity:0;
      cursor:pointer; width:100%; height:100%;
    }
    .dz-icon {
      width:38px; height:38px; border-radius:10px;
      background:#e8f0fe; margin:0 auto 9px;
      display:flex; align-items:center; justify-content:center;
    }
    .dz-text { font-size:13px; font-weight:600; color:#374151; margin-bottom:3px; }
    .dz-link { color:#1346a8; font-weight:700; }
    .dz-hint { font-size:11px; color:#94a3b8; }

    /* File selected state */
    .drop-zone.has-file { border-color:#059669; border-style:solid; background:#f0fdf4; }
    .drop-zone.has-file .drop-placeholder { display:none; }
    .file-preview { display:none; align-items:center; gap:12px; pointer-events:none; }
    .drop-zone.has-file .file-preview { display:flex; }

    .fp-thumb { width:54px; height:54px; border-radius:9px; object-fit:cover; border:2px solid #86efac; flex-shrink:0; }
    .fp-pdf   { width:54px; height:54px; background:#fee2e2; border-radius:9px; display:none; align-items:center; justify-content:center; flex-shrink:0; }
    .fp-name  { font-size:12.5px; font-weight:700; color:#065f46; max-width:140px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; text-align:left; }
    .fp-size  { font-size:11px; color:#059669; margin-top:2px; }
    .fp-rm    { font-size:11.5px; color:#dc2626; background:none; border:none; cursor:pointer; font-weight:700; padding:0; margin-top:4px; pointer-events:auto; display:block; }

    /* ─── UPLOAD TIPS ─────────────────────────────────── */
    .upload-tips { display:flex; flex-wrap:wrap; gap:7px; margin-top:14px; }
    .tip-chip {
      display:inline-flex; align-items:center; gap:5px;
      border-radius:99px; padding:4px 11px;
      font-size:11.5px; font-weight:600;
    }
    .tip-ok  { background:#f0fdf4; border:1px solid #86efac; color:#059669; }
    .tip-bad { background:#fef2f2; border:1px solid #fca5a5; color:#dc2626; }

    /* ─── SELFIE VIDEO SECTION ────────────────────────── */
    .video-recorder-wrap {
      display:flex; gap:20px; align-items:flex-start;
    }

    /* Camera preview box */
    .cam-preview-box {
      flex:1; min-width:0;
      background:#0c1a3a; border-radius:16px;
      overflow:hidden; position:relative;
      aspect-ratio:4/3;
    }
    #cam-video {
      width:100%; height:100%; object-fit:cover; display:block;
      transform:scaleX(-1); /* Mirror effect */
    }

    /* Teleprompter overlay on camera */
    .cam-teleprompter {
      position:absolute; bottom:0; left:0; right:0;
      background:linear-gradient(transparent, rgba(0,0,0,.82));
      padding:14px 16px 16px;
      pointer-events:none;
    }
    .cam-prompt-label {
      font-size:9.5px; font-weight:800; letter-spacing:1px;
      color:rgba(255,255,255,.5); text-transform:uppercase;
      margin-bottom:5px;
    }
    .cam-prompt-text {
      font-size:13px; font-weight:600; color:#fff;
      line-height:1.6;
    }
    .cam-prompt-text .highlight {
      color:#60a5fa; font-weight:800;
    }

    /* REC indicator */
    .rec-indicator {
      position:absolute; top:12px; left:12px;
      display:none; align-items:center; gap:6px;
      background:rgba(0,0,0,.55); border-radius:99px;
      padding:4px 10px;
    }
    .rec-indicator.active { display:flex; }
    .rec-dot {
      width:8px; height:8px; border-radius:50%;
      background:#ef4444;
      animation:recblink 1s infinite;
    }
    @keyframes recblink { 0%,100%{opacity:1} 50%{opacity:.2} }
    @keyframes spin { to { transform:rotate(360deg); } }
    .rec-text {
      font-size:11px; font-weight:800; color:white;
      letter-spacing:.5px;
    }

    /* Timer overlay */
    .cam-timer {
      position:absolute; top:12px; right:12px;
      background:rgba(0,0,0,.55); border-radius:99px;
      padding:4px 12px;
      font-size:13px; font-weight:800; color:white;
      display:none;
      font-variant-numeric: tabular-nums;
    }
    .cam-timer.active { display:block; }
    .cam-timer.warning { color:#fbbf24; }

    /* No camera state */
    .cam-no-access {
      position:absolute; inset:0;
      display:flex; flex-direction:column;
      align-items:center; justify-content:center;
      color:rgba(255,255,255,.6); text-align:center; padding:20px;
      gap:10px;
    }
    .cam-no-access svg { opacity:.5; }
    .cam-no-access p { font-size:13px; line-height:1.6; }

    /* Right panel */
    .cam-controls {
      width:200px; flex-shrink:0;
      display:flex; flex-direction:column; gap:10px;
    }

    /* Status steps */
    .vid-steps { display:flex; flex-direction:column; gap:7px; margin-bottom:4px; }
    .vid-step {
      display:flex; align-items:center; gap:8px;
      padding:8px 10px; border-radius:9px;
      background:#f8fafc; border:1.5px solid #e2e8f0;
      font-size:12px; font-weight:600; color:#64748b;
      transition:all .2s;
    }
    .vid-step.active { background:#eff4ff; border-color:#93b4f8; color:#1346a8; }
    .vid-step.done   { background:#f0fdf4; border-color:#86efac; color:#059669; }
    .vid-step-dot {
      width:20px; height:20px; border-radius:50%;
      background:#e2e8f0; flex-shrink:0;
      display:flex; align-items:center; justify-content:center;
      font-size:10px; font-weight:800; color:#94a3b8;
      transition:all .2s;
    }
    .vid-step.active .vid-step-dot { background:#1346a8; color:white; }
    .vid-step.done   .vid-step-dot { background:#059669; color:white; }

    /* Buttons */
    .cam-btn {
      width:100%; padding:11px 14px;
      border:none; border-radius:11px;
      font-size:13px; font-weight:700;
      font-family:'DM Sans',sans-serif;
      cursor:pointer; transition:all .18s;
      display:flex; align-items:center; justify-content:center; gap:7px;
    }
    .cam-btn:disabled { opacity:.4; cursor:not-allowed; }
    .cam-btn-start {
      background:linear-gradient(135deg,#1346a8,#0f3d9e);
      color:white;
      box-shadow:0 4px 14px rgba(19,70,168,.28);
    }
    .cam-btn-start:hover:not(:disabled) { box-shadow:0 6px 20px rgba(19,70,168,.38); transform:translateY(-1px); }
    .cam-btn-stop {
      background:linear-gradient(135deg,#dc2626,#b91c1c);
      color:white;
      box-shadow:0 4px 14px rgba(220,38,38,.28);
    }
    .cam-btn-stop:hover:not(:disabled) { transform:translateY(-1px); }
    .cam-btn-retry {
      background:#f8fafc; color:#374151;
      border:1.5px solid #e2e8f0;
    }
    .cam-btn-retry:hover:not(:disabled) { background:#f1f5f9; }

    /* Recorded video playback */
    .recorded-preview-wrap {
      margin-top:14px; display:none;
      background:#0c1a3a; border-radius:12px;
      overflow:hidden; position:relative;
    }
    .recorded-preview-wrap.show { display:block; }
    #recorded-video {
      width:100%; display:block;
      max-height:200px; object-fit:cover;
    }
    .rec-success-badge {
      display:flex; align-items:center; gap:8px;
      background:#f0fdf4; border:1.5px solid #86efac;
      border-radius:10px; padding:9px 13px;
      margin-top:10px; font-size:12.5px; font-weight:700; color:#065f46;
    }

    /* Video tip box */
    .video-tips-box {
      background:#f0f9ff; border:1.5px solid #bae6fd;
      border-radius:11px; padding:12px 14px;
      margin-top:14px;
    }
    .video-tips-title {
      font-size:11px; font-weight:800; color:#0369a1;
      text-transform:uppercase; letter-spacing:.6px; margin-bottom:8px;
    }
    .video-tips-list {
      display:flex; flex-direction:column; gap:5px;
    }
    .video-tip-row {
      display:flex; align-items:flex-start; gap:6px;
      font-size:12px; color:#374151; line-height:1.5;
    }
    .video-tip-row svg { flex-shrink:0; margin-top:2px; }

    /* ─── CONSENT ─────────────────────────────────────── */
    .consent-block {
      display:flex; align-items:flex-start; gap:10px;
      background:#f8fafc; border:1.5px solid #e2e8f0;
      border-radius:11px; padding:14px;
      margin-bottom:16px; cursor:pointer;
    }
    .consent-block:has(input:checked) { background:#eff4ff; border-color:#93b4f8; }
    .consent-block input { width:16px; height:16px; margin-top:2px; flex-shrink:0; accent-color:#1346a8; cursor:pointer; }
    .consent-text { font-size:12.5px; color:#374151; line-height:1.7; }

    /* ─── SUBMIT BTN ──────────────────────────────────── */
    .kyc-submit {
      width:100%; padding:15px;
      background:linear-gradient(135deg,#1346a8,#0f3d9e);
      color:white; border:none; border-radius:12px;
      font-size:15px; font-weight:700;
      font-family:'DM Sans',sans-serif;
      cursor:pointer; transition:all .18s;
      display:flex; align-items:center; justify-content:center; gap:9px;
      box-shadow:0 4px 18px rgba(19,70,168,.32);
      letter-spacing:-.1px;
    }
    .kyc-submit:hover:not(:disabled) { transform:translateY(-2px); box-shadow:0 8px 28px rgba(19,70,168,.42); }
    .kyc-submit:disabled { opacity:.45; cursor:not-allowed; transform:none; box-shadow:none; }

    /* ─── PRIVACY NOTE ────────────────────────────────── */
    .privacy-note {
      margin-top:13px; display:flex; align-items:flex-start; gap:9px;
      background:#f8fafc; border:1px solid #e2e8f0;
      border-radius:10px; padding:11px 13px;
      font-size:11.5px; color:#64748b; line-height:1.6;
    }
    .privacy-note svg { flex-shrink:0; margin-top:1px; }

    /* ─── STATUS BANNERS ──────────────────────────────── */
    .s-banner {
      border-radius:16px; padding:22px 24px;
      margin-bottom:18px;
      display:flex; align-items:flex-start; gap:18px;
    }
    .s-banner-icon { width:52px; height:52px; flex-shrink:0; border-radius:14px; display:flex; align-items:center; justify-content:center; }
    .s-banner-title { font-size:17px; font-weight:800; margin-bottom:5px; }
    .s-banner-sub   { font-size:13.5px; line-height:1.65; }

    .s-banner.pending  { background:#fffbeb; border:1.5px solid #fde68a; }
    .s-banner.pending  .s-banner-icon  { background:#fef3c7; }
    .s-banner.pending  .s-banner-title { color:#78350f; }
    .s-banner.pending  .s-banner-sub   { color:#92400e; }

    .s-banner.approved { background:#f0fdf4; border:1.5px solid #86efac; }
    .s-banner.approved .s-banner-icon  { background:#dcfce7; }
    .s-banner.approved .s-banner-title { color:#14532d; }
    .s-banner.approved .s-banner-sub   { color:#166534; }

    .s-banner.rejected { background:#fef2f2; border:1.5px solid #fca5a5; }
    .s-banner.rejected .s-banner-icon  { background:#fee2e2; }
    .s-banner.rejected .s-banner-title { color:#7f1d1d; }
    .s-banner.rejected .s-banner-sub   { color:#991b1b; }

    /* ─── PROGRESS STEPS ──────────────────────────────── */
    .prog-steps { display:flex; margin-top:18px; }
    .ps { flex:1; text-align:center; position:relative; }
    .ps::after { content:''; position:absolute; top:13px; left:50%; right:-50%; height:2px; background:#e2e8f0; z-index:0; }
    .ps:last-child::after { display:none; }
    .ps-dot {
      width:28px; height:28px; border-radius:50%;
      display:flex; align-items:center; justify-content:center;
      margin:0 auto 6px; font-size:12px; font-weight:700;
      position:relative; z-index:1;
    }
    .ps-dot.done  { background:#059669; color:white; }
    .ps-dot.doing { background:#1346a8; color:white; box-shadow:0 0 0 4px rgba(19,70,168,.15); }
    .ps-dot.todo  { background:#e2e8f0; color:#94a3b8; }
    .ps-label { font-size:11px; font-weight:600; color:#64748b; }

    /* ─── DOC CHIP ────────────────────────────────────── */
    .doc-chip {
      display:inline-flex; align-items:center; gap:7px;
      background:#f0fdf4; border:1px solid #86efac;
      border-radius:8px; padding:7px 13px;
      font-size:12.5px; font-weight:600; color:#065f46;
      text-decoration:none; transition:background .12s;
    }
    .doc-chip:hover { background:#dcfce7; }

    /* ─── RESUBMIT BTN ────────────────────────────────── */
    .resubmit-btn {
      display:inline-flex; align-items:center; gap:7px;
      padding:10px 20px;
      background:white; color:#dc2626;
      border:1.5px solid #fca5a5; border-radius:10px;
      font-size:13px; font-weight:700;
      font-family:'DM Sans',sans-serif;
      cursor:pointer; transition:all .15s;
    }
    .resubmit-btn:hover { background:#dc2626; color:white; border-color:#dc2626; }

    @media(max-width:640px) {
      .doc-grid,.upload-row,.legal-grid { grid-template-columns:1fr; }
      .kyc-page  { padding:14px; }
      .kyc-hero  { flex-direction:column; gap:14px; padding:20px; }
      .video-recorder-wrap { flex-direction:column; }
      .cam-controls { width:100%; }
    }
  </style>
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main-content" style="margin-left:260px;min-height:100vh;background:#f1f5f9">

    <div class="mobile-bar">
      <button class="ham-btn" onclick="document.getElementById('sidebar').classList.toggle('open');document.getElementById('overlay').classList.toggle('open')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <span style="font-weight:800;font-size:15px">KYC Verification</span>
    </div>
    <div class="topbar"><span class="topbar-title">KYC Verification</span></div>

    <div class="kyc-page">

      <?php if ($msg): ?>
      <div class="alert alert-success" style="margin-bottom:16px;border-radius:12px"><?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>
      <?php if ($err): ?>
      <div class="alert alert-error" style="margin-bottom:16px;border-radius:12px"><?= htmlspecialchars($err) ?></div>
      <?php endif; ?>

      <!-- ══ HERO ══ -->
      <div class="kyc-hero">
        <div class="kyc-hero-icon">
          <svg viewBox="0 0 48 56" fill="none" width="36" height="42" xmlns="http://www.w3.org/2000/svg">
            <path d="M24 2L4 10V26C4 38.6 12.8 50.4 24 54C35.2 50.4 44 38.6 44 26V10L24 2Z" fill="rgba(255,255,255,0.15)" stroke="rgba(255,255,255,0.5)" stroke-width="1.5"/>
            <path d="M16 27L21 32L32 21" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <div style="flex:1">
          <div class="kyc-hero-title">Identity Verification (KYC)</div>
          <div class="kyc-hero-sub">Mandatory compliance under Indian government law. Your documents are encrypted, stored securely, and used solely for identity verification.</div>
          <div class="kyc-hero-pills">
            <span class="kyc-hero-pill">🔒 AES-256 Encrypted</span>
            <span class="kyc-hero-pill"><svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="12" fill="#07038D" viewBox="-45 -30 90 60" style="vertical-align: inherit;"><path fill="#FFF" d="M-45-30h90v60h-90z"></path><path fill="#FF6820" d="M-45-30h90v20h-90z"></path><path fill="#046A38" d="M-45 10h90v20h-90z"></path><circle r="9.25"></circle><circle r="8" fill="#FFF"></circle><circle r="1.6"></circle><g id="d"><g id="c"><g id="b"><g id="a"><path d="m0-8 .3 4.814L0-.802l-.3-2.384z"></path><circle cy="-8" r=".35" transform="rotate(7.5)"></circle></g><use xlink:href="#a" transform="scale(-1)"></use></g><use xlink:href="#b" transform="rotate(15)"></use></g><use xlink:href="#c" transform="rotate(30)"></use></g><use xlink:href="#d" transform="rotate(60)"></use><use xlink:href="#d" transform="rotate(120)"></use></svg> RBI Compliant</span>
            <span class="kyc-hero-pill">DPDP Act 2023</span>
            <span class="kyc-hero-pill">PMLA 2002</span>
            <span class="kyc-hero-pill">IT Act 2000</span>
          </div>
        </div>
        <svg viewBox="0 0 80 80" fill="none" width="72" style="flex-shrink:0;opacity:.1" xmlns="http://www.w3.org/2000/svg">
          <circle cx="40" cy="40" r="36" stroke="white" stroke-width="2"/>
          <circle cx="40" cy="40" r="14" stroke="white" stroke-width="1.5"/>
          <line x1="40" y1="4" x2="40" y2="76" stroke="white" stroke-width="1"/>
          <line x1="4" y1="40" x2="76" y2="40" stroke="white" stroke-width="1"/>
          <line x1="14.7" y1="14.7" x2="65.3" y2="65.3" stroke="white" stroke-width="1"/>
          <line x1="65.3" y1="14.7" x2="14.7" y2="65.3" stroke="white" stroke-width="1"/>
          <circle cx="40" cy="40" r="4" fill="white" opacity=".5"/>
        </svg>
      </div>

      <?php /* ════════ STATUS VIEWS ════════ */ ?>

      <?php if ($kyc_status === 'pending'): ?>
      <!-- ── PENDING ── -->
      <div class="s-banner pending">
        <div class="s-banner-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2.2" width="26" height="26">
            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
          </svg>
        </div>
        <div style="flex:1">
          <div class="s-banner-title">KYC Under Review</div>
          <div class="s-banner-sub">
            Your <strong><?= htmlspecialchars($doc_labels[$kyc['doc_type']] ?? $kyc['doc_type']) ?></strong> is being verified by our compliance team.
            Reviews are typically completed within <strong>24–48 business hours</strong>. You will be notified by email once a decision is made.
          </div>
          <div class="prog-steps">
            <div class="ps">
              <div class="ps-dot done">
                <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3" width="13" height="13"><polyline points="20 6 9 17 4 12"/></svg>
              </div>
              <div class="ps-label">Submitted</div>
            </div>
            <div class="ps">
              <div class="ps-dot doing">
                <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3" width="11" height="11"><circle cx="12" cy="12" r="3" fill="white"/></svg>
              </div>
              <div class="ps-label">Under Review</div>
            </div>
            <div class="ps"><div class="ps-dot todo">3</div><div class="ps-label">Decision</div></div>
            <div class="ps"><div class="ps-dot todo">4</div><div class="ps-label">Verified</div></div>
          </div>
          <div style="margin-top:13px;font-size:12px;color:#92400e;display:flex;align-items:center;gap:5px">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Submitted on <?= date('d M Y \a\t H:i', strtotime($kyc['submitted_at'])) ?>
          </div>
        </div>
      </div>

      <div class="kyc-card">
        <div class="kyc-card-head">
          <svg viewBox="0 0 24 24" fill="none" stroke="#1346a8" stroke-width="2" width="16" height="16"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          <span class="kyc-card-title">Submitted Documents</span>
        </div>
        <div class="kyc-card-body" style="display:flex;gap:10px;flex-wrap:wrap">
          <?php if ($kyc['file_front']): ?>
          <a href="<?= BASE_URL ?>/admin/download-resume.php?file=<?= urlencode(basename($kyc['file_front'])) ?>&type=kyc" target="_blank" class="doc-chip">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Front Side ↗
          </a>
          <?php endif; ?>
          <?php if ($kyc['file_back']): ?>
          <a href="<?= BASE_URL ?>/admin/download-resume.php?file=<?= urlencode(basename($kyc['file_back'])) ?>&type=kyc" target="_blank" class="doc-chip">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Back Side ↗
          </a>
          <?php endif; ?>
          <?php if (!empty($kyc['file_video'])): ?>
          <a href="<?= BASE_URL ?>/admin/download-resume.php?file=<?= urlencode(basename($kyc['file_video'])) ?>&type=kyc" target="_blank" class="doc-chip" style="background:#eff4ff;border-color:#c0d0f8;color:#1e40af">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
            Selfie Video ↗
          </a>
          <?php endif; ?>
        </div>
      </div>

      <?php elseif ($kyc_status === 'approved'): ?>
      <!-- ── APPROVED ── -->
      <div class="s-banner approved">
        <div class="s-banner-icon">
          <svg viewBox="0 0 48 56" fill="none" width="30" height="35" xmlns="http://www.w3.org/2000/svg">
            <path d="M24 2L4 10V26C4 38.6 12.8 50.4 24 54C35.2 50.4 44 38.6 44 26V10L24 2Z" fill="#dcfce7" stroke="#86efac" stroke-width="1.5"/>
            <path d="M16 27L21 32L32 21" stroke="#059669" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <div>
          <div class="s-banner-title">KYC Successfully Verified</div>
          <div class="s-banner-sub">
            Your identity has been verified and your account is fully KYC-compliant as required under PMLA 2002 and RBI Digital Payment guidelines.
          </div>
          <div style="margin-top:12px;display:flex;gap:18px;flex-wrap:wrap;font-size:12px;color:#15803d">
            <span style="display:flex;align-items:center;gap:5px">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="12" height="12"><polyline points="20 6 9 17 4 12"/></svg>
              Verified on <?= date('d M Y', strtotime($kyc['reviewed_at'] ?? $kyc['updated_at'])) ?>
            </span>
            <span style="display:flex;align-items:center;gap:5px">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              <?= htmlspecialchars($doc_labels[$kyc['doc_type']] ?? $kyc['doc_type']) ?>
            </span>
          </div>
        </div>
      </div>

      <?php elseif ($kyc_status === 'rejected'): ?>
      <!-- ── REJECTED ── -->
      <div class="s-banner rejected">
        <div class="s-banner-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" width="26" height="26">
            <circle cx="12" cy="12" r="10"/>
            <line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
          </svg>
        </div>
        <div style="flex:1">
          <div class="s-banner-title">KYC Verification Failed</div>
          <div class="s-banner-sub" style="margin-bottom:12px">Your submission could not be verified. Please review the reason below and resubmit with correct documents.</div>
          <?php if ($kyc['reject_reason']): ?>
          <div style="background:white;border:1.5px solid #fca5a5;border-radius:10px;padding:12px 16px;margin-bottom:14px">
            <div style="font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.7px;color:#9ca3af;margin-bottom:5px">Rejection Reason</div>
            <div style="font-size:13.5px;color:#7f1d1d;font-weight:500;line-height:1.6"><?= nl2br(htmlspecialchars($kyc['reject_reason'])) ?></div>
          </div>
          <?php endif; ?>
          <form method="POST" onsubmit="return confirm('This will permanently delete your old submission. Continue?')">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="delete_kyc">
            <button type="submit" class="resubmit-btn">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.86"/></svg>
              Delete Old KYC &amp; Resubmit
            </button>
          </form>
        </div>
      </div>

      <?php else: /* ════════ SUBMISSION FORM ════════ */ ?>

      <!-- ── Legal Framework ── -->
      <div class="legal-box">
        <div class="legal-box-hd">
          <svg viewBox="0 0 24 24" fill="none" stroke="#1e40af" stroke-width="2" width="14" height="14">
            <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/>
          </svg>
          Why KYC Is Mandatory — Legal Basis
        </div>
        <div class="legal-grid">
          <div class="legal-item">
            <div class="legal-item-name">PMLA 2002 &amp; Rules 2005</div>
            <div class="legal-item-desc">Prevention of Money Laundering Act mandates customer identity verification before providing financial or digital services.</div>
          </div>
          <div class="legal-item">
            <div class="legal-item-name">RBI KYC Master Direction</div>
            <div class="legal-item-desc">Reserve Bank of India requires all regulated entities to conduct KYC before activating wallets, payments, or prepaid instruments.</div>
          </div>
          <div class="legal-item">
            <div class="legal-item-name">IT Act 2000 — Section 43A</div>
            <div class="legal-item-desc">Mandates reasonable security practices for all bodies handling sensitive personal data submitted during identity verification.</div>
          </div>
          <div class="legal-item">
            <div class="legal-item-name">DPDP Act 2023</div>
            <div class="legal-item-desc">Digital Personal Data Protection Act — data collected is used solely for KYC. You may request deletion post-verification via support.</div>
          </div>
        </div>
      </div>

      <!-- ── Form ── -->
      <form method="POST" enctype="multipart/form-data" id="kyc-form">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="submit_kyc">
        <!-- Hidden input to receive the recorded video blob -->
        <input type="file" name="file_video" id="file_video_input" style="display:none" accept="video/*">

        <!-- STEP 1: Document type -->
        <div class="kyc-card">
          <div class="kyc-card-head">
            <div class="kyc-step-num">1</div>
            <span class="kyc-card-title">Choose Document Type</span>
          </div>
          <div class="kyc-card-body">
            <div class="doc-grid" id="doc-type-grid">

              <!-- ── Aadhaar ── -->
              <div class="doc-card" id="dc-aadhaar" onclick="selectDoc('aadhaar')">
                <input type="radio" name="doc_type" value="aadhaar" id="dt-aadhaar">
                <div class="doc-card-check" id="dck-aadhaar">
                  <svg viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="3" width="11" height="11"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div class="doc-illus" style="background:#fff3e0">
                  <svg viewBox="0 0 120 52" fill="none" xmlns="http://www.w3.org/2000/svg" width="110" height="48">
                    <rect x="2" y="2" width="116" height="48" rx="6" fill="#FF6D00" opacity=".1"/>
                    <rect x="2" y="2" width="116" height="48" rx="6" stroke="#FF6D00" stroke-width="1.5" fill="none"/>
                    <rect x="10" y="10" width="50" height="3" rx="1.5" fill="#FF6D00" opacity=".7"/>
                    <rect x="10" y="16" width="36" height="2" rx="1" fill="#FF6D00" opacity=".4"/>
                    <rect x="10" y="38" width="55" height="2.5" rx="1.2" fill="#FF6D00" opacity=".35"/>
                    <rect x="10" y="43" width="42" height="2" rx="1" fill="#FF6D00" opacity=".25"/>
                    <rect x="10" y="21" width="20" height="14" rx="2" fill="none" stroke="#FF6D00" stroke-width="1" opacity=".45"/>
                    <circle cx="20" cy="27" r="3.5" fill="none" stroke="#FF6D00" stroke-width="1" opacity=".45"/>
                    <g transform="translate(76,6)">
                      <path d="M20 38C20 38 20 25 13 25C6 25 6 38 6 38" stroke="#FF6D00" stroke-width="1.6" stroke-linecap="round" fill="none"/>
                      <path d="M16 38C16 31 15 28 13 28C11 28 10 31 10 38" stroke="#FF6D00" stroke-width="1.6" stroke-linecap="round" fill="none"/>
                      <path d="M5 33C5 22 8 17 13 17C18 17 21 22 21 33" stroke="#FF6D00" stroke-width="1.6" stroke-linecap="round" fill="none"/>
                      <path d="M2 30C2 15 6 10 13 10C20 10 24 15 24 30" stroke="#FF6D00" stroke-width="1.5" stroke-linecap="round" fill="none" opacity=".7"/>
                      <path d="M0 27C0 11 5 5 13 5C21 5 26 11 26 27" stroke="#FF6D00" stroke-width="1.4" stroke-linecap="round" fill="none" opacity=".45"/>
                    </g>
                    <rect x="93" y="30" width="20" height="18" rx="2.5" fill="none" stroke="#FF6D00" stroke-width="1" opacity=".5"/>
                    <rect x="95.5" y="32.5" width="6" height="6" rx="1" fill="#FF6D00" opacity=".5"/>
                    <rect x="104.5" y="32.5" width="6" height="6" rx="1" fill="#FF6D00" opacity=".5"/>
                    <rect x="95.5" y="41.5" width="6" height="4" rx="1" fill="#FF6D00" opacity=".5"/>
                    <rect x="104.5" y="39" width="3" height="3" rx=".5" fill="#FF6D00" opacity=".5"/>
                    <rect x="100" y="36" width="3" height="3" rx=".5" fill="#FF6D00" opacity=".4"/>
                  </svg>
                </div>
                <div class="doc-sides-badge" id="dsb-aadhaar">2 sides</div>
                <div class="doc-card-name">Aadhaar Card</div>
                <div class="doc-card-hint">Govt. of India · 12-digit UID · Front + Back required</div>
              </div>

              <!-- ── Driving License ── -->
              <div class="doc-card" id="dc-driving_license" onclick="selectDoc('driving_license')">
                <input type="radio" name="doc_type" value="driving_license" id="dt-driving_license">
                <div class="doc-card-check" id="dck-driving_license">
                  <svg viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="3" width="11" height="11"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div class="doc-illus" style="background:#e3f2fd">
                  <svg viewBox="0 0 120 52" fill="none" xmlns="http://www.w3.org/2000/svg" width="110" height="48">
                    <rect x="2" y="2" width="116" height="48" rx="6" fill="#1565C0" opacity=".08"/>
                    <rect x="2" y="2" width="116" height="48" rx="6" stroke="#1565C0" stroke-width="1.5" fill="none"/>
                    <g transform="translate(8,14)">
                      <path d="M2 22H70M2 22C2 22 0 22 0 20V17.5L6 11L12 9H58L64 11L70 17.5V20C70 22 68 22 68 22" stroke="#1565C0" stroke-width="1.6" stroke-linecap="round" fill="none"/>
                      <path d="M12 9L16 3H54L58 9" stroke="#1565C0" stroke-width="1.4" stroke-linecap="round" fill="none" opacity=".7"/>
                      <circle cx="14" cy="22" r="4.5" stroke="#1565C0" stroke-width="1.6" fill="none"/>
                      <circle cx="14" cy="22" r="1.8" fill="#1565C0" opacity=".4"/>
                      <circle cx="56" cy="22" r="4.5" stroke="#1565C0" stroke-width="1.6" fill="none"/>
                      <circle cx="56" cy="22" r="1.8" fill="#1565C0" opacity=".4"/>
                      <rect x="63" y="16" width="5" height="3" rx="1" fill="#1565C0" opacity=".5"/>
                    </g>
                    <rect x="10" y="40" width="50" height="2.5" rx="1.2" fill="#1565C0" opacity=".35"/>
                    <rect x="10" y="45" width="38" height="2" rx="1" fill="#1565C0" opacity=".25"/>
                    <rect x="92" y="10" width="22" height="30" rx="3" fill="none" stroke="#1565C0" stroke-width="1" opacity=".4"/>
                    <circle cx="103" cy="21" r="5" fill="none" stroke="#1565C0" stroke-width="1" opacity=".4"/>
                    <rect x="95" y="30" width="16" height="2" rx="1" fill="#1565C0" opacity=".3"/>
                    <rect x="97" y="34" width="12" height="2" rx="1" fill="#1565C0" opacity=".25"/>
                  </svg>
                </div>
                <div class="doc-sides-badge" id="dsb-driving_license">2 sides</div>
                <div class="doc-card-name">Driving License</div>
                <div class="doc-card-hint">RTO Issued · Valid DL · Front + Back required</div>
              </div>

              <!-- ── PAN Card ── -->
              <div class="doc-card" id="dc-pan" onclick="selectDoc('pan')">
                <input type="radio" name="doc_type" value="pan" id="dt-pan">
                <div class="doc-card-check" id="dck-pan">
                  <svg viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="3" width="11" height="11"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div class="doc-illus" style="background:#f3e5f5">
                  <svg viewBox="0 0 120 52" fill="none" xmlns="http://www.w3.org/2000/svg" width="110" height="48">
                    <rect x="2" y="2" width="116" height="48" rx="6" fill="#6A1B9A" opacity=".08"/>
                    <rect x="2" y="2" width="116" height="48" rx="6" stroke="#6A1B9A" stroke-width="1.5" fill="none"/>
                    <g transform="translate(10,6)">
                      <circle cx="18" cy="18" r="14" stroke="#6A1B9A" stroke-width="1.4" fill="none" opacity=".7"/>
                      <circle cx="18" cy="18" r="6"  stroke="#6A1B9A" stroke-width="1" fill="none" opacity=".5"/>
                      <line x1="18" y1="4" x2="18" y2="32" stroke="#6A1B9A" stroke-width=".9" opacity=".5"/>
                      <line x1="4" y1="18" x2="32" y2="18" stroke="#6A1B9A" stroke-width=".9" opacity=".5"/>
                      <line x1="8" y1="8" x2="28" y2="28" stroke="#6A1B9A" stroke-width=".9" opacity=".4"/>
                      <line x1="28" y1="8" x2="8" y2="28" stroke="#6A1B9A" stroke-width=".9" opacity=".4"/>
                    </g>
                    <rect x="44" y="9" width="44" height="3.5" rx="1.7" fill="#6A1B9A" opacity=".55"/>
                    <rect x="44" y="15" width="34" height="2.5" rx="1.2" fill="#6A1B9A" opacity=".35"/>
                    <rect x="90" y="6" width="22" height="26" rx="3" fill="none" stroke="#6A1B9A" stroke-width="1" opacity=".45"/>
                    <circle cx="101" cy="16" r="5" fill="none" stroke="#6A1B9A" stroke-width="1" opacity=".4"/>
                    <rect x="10" y="38" width="80" height="6" rx="3" fill="#6A1B9A" opacity=".15"/>
                    <rect x="12" y="40" width="76" height="2.5" rx="1.2" fill="#6A1B9A" opacity=".3"/>
                    <rect x="10" y="46" width="55" height="2" rx="1" fill="#6A1B9A" opacity=".2"/>
                  </svg>
                </div>
                <div class="doc-sides-badge" id="dsb-pan">1 side</div>
                <div class="doc-card-name">PAN Card</div>
                <div class="doc-card-hint">Income Tax Dept · 10-digit PAN · Front only</div>
              </div>

              <!-- ── Passport ── -->
              <div class="doc-card" id="dc-passport" onclick="selectDoc('passport')">
                <input type="radio" name="doc_type" value="passport" id="dt-passport">
                <div class="doc-card-check" id="dck-passport">
                  <svg viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="3" width="11" height="11"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div class="doc-illus" style="background:#e8f5e9">
                  <svg viewBox="0 0 120 52" fill="none" xmlns="http://www.w3.org/2000/svg" width="110" height="48">
                    <rect x="2" y="2" width="116" height="48" rx="6" fill="#1B5E20" opacity=".09"/>
                    <rect x="2" y="2" width="116" height="48" rx="6" stroke="#1B5E20" stroke-width="1.5" fill="none"/>
                    <rect x="36" y="2" width="3" height="48" fill="#1B5E20" opacity=".12"/>
                    <g transform="translate(6,6)">
                      <circle cx="15" cy="19" r="13" stroke="#1B5E20" stroke-width="1.4" fill="none" opacity=".7"/>
                      <ellipse cx="15" cy="19" rx="5.5" ry="13" stroke="#1B5E20" stroke-width="1" fill="none" opacity=".5"/>
                      <line x1="2" y1="19" x2="28" y2="19" stroke="#1B5E20" stroke-width=".9" opacity=".5"/>
                    </g>
                    <rect x="44" y="7" width="20" height="24" rx="3" fill="none" stroke="#1B5E20" stroke-width="1" opacity=".45"/>
                    <circle cx="54" cy="17" r="5" fill="none" stroke="#1B5E20" stroke-width="1" opacity=".4"/>
                    <rect x="68" y="9" width="44" height="3" rx="1.5" fill="#1B5E20" opacity=".45"/>
                    <rect x="68" y="14" width="34" height="2" rx="1" fill="#1B5E20" opacity=".28"/>
                    <rect x="10" y="38" width="100" height="3" rx="1.5" fill="#1B5E20" opacity=".18"/>
                    <rect x="10" y="43" width="100" height="3" rx="1.5" fill="#1B5E20" opacity=".18"/>
                  </svg>
                </div>
                <div class="doc-sides-badge" id="dsb-passport">1 side</div>
                <div class="doc-card-name">Passport</div>
                <div class="doc-card-hint">MEA Issued · Valid Passport · Data page only</div>
              </div>

            </div><!-- /.doc-grid -->
            <div id="doc-type-error" style="display:none;margin-top:10px;padding:9px 13px;background:#fef2f2;border:1px solid #fca5a5;border-radius:9px;color:#dc2626;font-size:12.5px"></div>
          </div>
        </div>

        <!-- STEP 2: Upload -->
        <div class="kyc-card" id="upload-card" style="opacity:.4;pointer-events:none;transition:opacity .25s,transform .25s">
          <div class="kyc-card-head">
            <div class="kyc-step-num">2</div>
            <span class="kyc-card-title">Upload Document Images</span>
            <span id="upload-guide" style="margin-left:auto;font-size:12px;color:#94a3b8">Select a document type first</span>
          </div>
          <div class="kyc-card-body">
            <div class="upload-row single" id="upload-row">

              <!-- Front -->
              <div class="upload-slot" id="front-slot">
                <div class="upload-slot-label">
                  <span id="front-label">Document Image</span>
                  <span class="req">Required</span>
                </div>
                <div class="drop-zone" id="dz-front"
                     ondragover="dzDrag(event,'front')" ondragleave="dzLeave('front')" ondrop="dzDrop(event,'front','file_front')">
                  <input type="file" name="file_front" id="file_front" accept=".jpg,.jpeg,.png,.pdf" onchange="fileSelected(this,'front')">
                  <div class="drop-placeholder">
                    <div class="dz-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="#1346a8" stroke-width="2" width="20" height="20"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
                    </div>
                    <div class="dz-text">Drop file here or <span class="dz-link">browse</span></div>
                    <div class="dz-hint">JPG, PNG or PDF &middot; Max 5 MB</div>
                  </div>
                  <div class="file-preview" id="fp-front">
                    <img class="fp-thumb" id="fp-front-img" src="" alt="" style="display:none">
                    <div class="fp-pdf" id="fp-front-pdf">
                      <svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="1.8" width="24" height="24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    </div>
                    <div>
                      <div class="fp-name" id="fp-front-name"></div>
                      <div class="fp-size" id="fp-front-size"></div>
                      <button type="button" class="fp-rm" onclick="clearFile('front','file_front')">✕ Remove</button>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Back -->
              <div class="upload-slot" id="back-slot" style="display:none">
                <div class="upload-slot-label">
                  <span>Back Side</span>
                  <span class="req">Required</span>
                </div>
                <div class="drop-zone" id="dz-back"
                     ondragover="dzDrag(event,'back')" ondragleave="dzLeave('back')" ondrop="dzDrop(event,'back','file_back')">
                  <input type="file" name="file_back" id="file_back" accept=".jpg,.jpeg,.png,.pdf" onchange="fileSelected(this,'back')">
                  <div class="drop-placeholder">
                    <div class="dz-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="#1346a8" stroke-width="2" width="20" height="20"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
                    </div>
                    <div class="dz-text">Drop back side or <span class="dz-link">browse</span></div>
                    <div class="dz-hint">JPG, PNG or PDF &middot; Max 5 MB</div>
                  </div>
                  <div class="file-preview" id="fp-back">
                    <img class="fp-thumb" id="fp-back-img" src="" alt="" style="display:none">
                    <div class="fp-pdf" id="fp-back-pdf">
                      <svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="1.8" width="24" height="24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    </div>
                    <div>
                      <div class="fp-name" id="fp-back-name"></div>
                      <div class="fp-size" id="fp-back-size"></div>
                      <button type="button" class="fp-rm" onclick="clearFile('back','file_back')">✕ Remove</button>
                    </div>
                  </div>
                </div>
              </div>

            </div><!-- /.upload-row -->

            <div class="upload-tips">
              <span class="tip-chip tip-ok">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" width="11" height="11"><polyline points="20 6 9 17 4 12"/></svg>
                All 4 corners visible
              </span>
              <span class="tip-chip tip-ok">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" width="11" height="11"><polyline points="20 6 9 17 4 12"/></svg>
                Clear, well-lit, not blurry
              </span>
              <span class="tip-chip tip-bad">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" width="11" height="11"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                No screenshots
              </span>
              <span class="tip-chip tip-bad">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" width="11" height="11"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                No black &amp; white scans
              </span>
              <span class="tip-chip tip-bad">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" width="11" height="11"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                No expired documents
              </span>
            </div>
          </div>
        </div>

        <!-- ══════════════════════════════════════════════════════ -->
        <!-- STEP 3: Selfie Video Verification (NEW)               -->
        <!-- ══════════════════════════════════════════════════════ -->
        <div class="kyc-card" id="video-card" style="opacity:.4;pointer-events:none;transition:opacity .25s">
          <div class="kyc-card-head">
            <div class="kyc-step-num">3</div>
            <span class="kyc-card-title">Selfie Video Verification</span>
            <span id="video-guide" style="margin-left:auto;font-size:12px;color:#94a3b8">Upload documents first</span>
          </div>
          <div class="kyc-card-body">

            <!-- Instructions header -->
            <div style="background:linear-gradient(135deg,#eff4ff,#e6eeff);border:1.5px solid #c0d0f8;border-radius:12px;padding:13px 16px;margin-bottom:16px;display:flex;align-items:flex-start;gap:11px">
              <svg viewBox="0 0 24 24" fill="none" stroke="#1346a8" stroke-width="2" width="20" height="20" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              <div>
                <div style="font-size:12.5px;font-weight:800;color:#1e40af;margin-bottom:4px">Liveness Check Required</div>
                <div style="font-size:12px;color:#374151;line-height:1.65">
                  Record a short video (5–30 sec) clearly saying the statement shown on screen. Face the camera directly in a well-lit area. This video is used only for identity liveness verification under PMLA compliance.
                </div>
              </div>
            </div>

            <div class="video-recorder-wrap">

              <!-- Camera box -->
              <div style="flex:1;min-width:0">
                <div class="cam-preview-box" id="cam-box">
                  <video id="cam-video" autoplay muted playsinline></video>

                  <!-- No access overlay -->
                  <div class="cam-no-access" id="cam-no-access">
                    <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.5" width="40" height="40"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/><line x1="1" y1="1" x2="23" y2="23" stroke="white" stroke-width="2"/></svg>
                    <p>Camera access needed.<br>Click "Start Camera" to begin.</p>
                  </div>

                  <!-- Teleprompter text overlay — shown only when camera is live -->
                  <div class="cam-teleprompter" id="cam-teleprompter" style="display:none">
                    <div class="cam-prompt-label">📢 Please say this out loud:</div>
                    <div class="cam-prompt-text" id="cam-prompt-text">
                      <!-- Filled by JS with user's name and app name -->
                    </div>
                  </div>

                  <!-- REC indicator -->
                  <div class="rec-indicator" id="rec-indicator">
                    <div class="rec-dot"></div>
                    <div class="rec-text">REC</div>
                  </div>

                  <!-- Timer -->
                  <div class="cam-timer" id="cam-timer">0:00</div>
                </div>

                <!-- Recorded video playback -->
                <div class="recorded-preview-wrap" id="recorded-wrap">
                  <video id="recorded-video" controls playsinline></video>
                </div>

                <!-- Success badge -->
                <div id="video-success-badge" style="display:none;margin-top:10px" class="rec-success-badge">
                  <svg viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5" width="16" height="16"><polyline points="20 6 9 17 4 12"/></svg>
                  Video recorded successfully! You can re-record if needed.
                </div>
              </div>

              <!-- Controls panel -->
              <div class="cam-controls">
                <!-- Status steps -->
                <div class="vid-steps">
                  <div class="vid-step active" id="vs-camera">
                    <div class="vid-step-dot" id="vsd-camera">1</div>
                    <span>Start Camera</span>
                  </div>
                  <div class="vid-step" id="vs-record">
                    <div class="vid-step-dot" id="vsd-record">2</div>
                    <span>Record Video</span>
                  </div>
                  <div class="vid-step" id="vs-done">
                    <div class="vid-step-dot" id="vsd-done">3</div>
                    <span>Done</span>
                  </div>
                </div>

                <!-- Start camera btn -->
                <button type="button" class="cam-btn cam-btn-start" id="btn-start-cam" onclick="startCamera()">
                  <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" width="14" height="14"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
                  Start Camera
                </button>

                <!-- Start recording btn -->
                <button type="button" class="cam-btn cam-btn-start" id="btn-start-rec" onclick="startRecording()" style="display:none">
                  <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" width="14" height="14"><circle cx="12" cy="12" r="8" fill="white"/></svg>
                  Start Recording
                </button>

                <!-- Stop recording btn -->
                <button type="button" class="cam-btn cam-btn-stop" id="btn-stop-rec" onclick="stopRecording()" style="display:none">
                  <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" width="14" height="14"><rect x="6" y="6" width="12" height="12"/></svg>
                  Stop Recording
                </button>

                <!-- Re-record btn -->
                <button type="button" class="cam-btn cam-btn-retry" id="btn-rerecord" onclick="reRecord()" style="display:none">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.86"/></svg>
                  Re-record
                </button>

                <!-- Tips -->
                <div class="video-tips-box">
                  <div class="video-tips-title">Tips</div>
                  <div class="video-tips-list">
                    <div class="video-tip-row">
                      <svg viewBox="0 0 24 24" fill="none" stroke="#0369a1" stroke-width="2.5" width="11" height="11"><polyline points="20 6 9 17 4 12"/></svg>
                      Face directly in camera
                    </div>
                    <div class="video-tip-row">
                      <svg viewBox="0 0 24 24" fill="none" stroke="#0369a1" stroke-width="2.5" width="11" height="11"><polyline points="20 6 9 17 4 12"/></svg>
                      Well-lit, quiet room
                    </div>
                    <div class="video-tip-row">
                      <svg viewBox="0 0 24 24" fill="none" stroke="#0369a1" stroke-width="2.5" width="11" height="11"><polyline points="20 6 9 17 4 12"/></svg>
                      Speak clearly &amp; slowly
                    </div>
                    <div class="video-tip-row">
                      <svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" width="11" height="11"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                      No glasses or mask
                    </div>
                  </div>
                </div>
              </div>
            </div>

          </div>
        </div>

        <!-- STEP 4: Consent + Submit -->
        <div class="kyc-card" id="consent-card" style="opacity:.4;pointer-events:none;transition:opacity .25s">
          <div class="kyc-card-head">
            <div class="kyc-step-num">4</div>
            <span class="kyc-card-title">Consent &amp; Submit</span>
          </div>
          <div class="kyc-card-body">
            <label class="consent-block">
              <input type="checkbox" id="consent-check" onchange="updateSubmitBtn()">
              <span class="consent-text">
                I give my <strong>free and informed consent</strong> for <strong><?= htmlspecialchars($app_name) ?></strong> to collect and process the above document(s) and selfie video solely for KYC verification, as required under the
                <strong>Digital Personal Data Protection Act, 2023</strong> (DPDP), <strong>PMLA 2002</strong>, and
                <strong>RBI KYC Master Directions</strong>. I confirm the documents are genuine, valid, and belong to me.
              </span>
            </label>

            <button type="submit" class="kyc-submit" id="submit-btn" disabled>
              <span id="submit-icon">
                <svg viewBox="0 0 48 56" fill="none" width="18" height="21" xmlns="http://www.w3.org/2000/svg">
                  <path d="M24 2L4 10V26C4 38.6 12.8 50.4 24 54C35.2 50.4 44 38.6 44 26V10L24 2Z" fill="rgba(255,255,255,.3)" stroke="white" stroke-width="2"/>
                  <path d="M16 27L21 32L32 21" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </span>
              <span id="submit-spinner" style="display:none">
                <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" width="18" height="18" style="animation:spin .8s linear infinite"><path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/></svg>
              </span>
              <span id="submit-label">Submit KYC for Verification</span>
            </button>

            <!-- Upload Progress Panel -->
            <div id="upload-progress-panel" style="display:none;margin-top:14px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:16px 18px">
              <div style="font-size:12px;font-weight:800;color:#374151;margin-bottom:12px;display:flex;align-items:center;gap:7px">
                <svg viewBox="0 0 24 24" fill="none" stroke="#1346a8" stroke-width="2" width="14" height="14"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
                Uploading KYC Documents...
              </div>

              <!-- Documents progress -->
              <div style="margin-bottom:10px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
                  <span style="font-size:11.5px;font-weight:600;color:#64748b;display:flex;align-items:center;gap:5px">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="11" height="11"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    ID Documents
                  </span>
                  <span id="docs-pct" style="font-size:11px;font-weight:800;color:#1346a8">0%</span>
                </div>
                <div style="height:6px;background:#e2e8f0;border-radius:99px;overflow:hidden">
                  <div id="docs-bar" style="height:100%;width:0%;background:linear-gradient(90deg,#1346a8,#3b82f6);border-radius:99px;transition:width .3s ease"></div>
                </div>
              </div>

              <!-- Video progress -->
              <div style="margin-bottom:10px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
                  <span style="font-size:11.5px;font-weight:600;color:#64748b;display:flex;align-items:center;gap:5px">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="11" height="11"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
                    Selfie Video
                  </span>
                  <span id="video-pct" style="font-size:11px;font-weight:800;color:#1346a8">0%</span>
                </div>
                <div style="height:6px;background:#e2e8f0;border-radius:99px;overflow:hidden">
                  <div id="video-bar" style="height:100%;width:0%;background:linear-gradient(90deg,#7c3aed,#a78bfa);border-radius:99px;transition:width .3s ease"></div>
                </div>
              </div>

              <!-- Status message -->
              <div id="upload-status-msg" style="font-size:11.5px;color:#64748b;display:flex;align-items:center;gap:6px;margin-top:4px">
                <svg viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" width="11" height="11"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span id="upload-status-text">Preparing upload...</span>
              </div>
            </div>

            <div class="privacy-note">
              <svg viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" width="15" height="15"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              Your documents and selfie video are encrypted with <strong>AES-256</strong> before storage and are accessible only to authorised compliance staff. We never share your data with third parties. Retained only as required by law.
            </div>
          </div>
        </div>

      </form>
      <?php endif; ?>

    </div>
  </div>
</div>
<div class="overlay" id="overlay" onclick="document.getElementById('sidebar').classList.remove('open');this.classList.remove('open')"></div>

<script>
// ─── State ────────────────────────────────────────────────
var needsBack     = false;
var frontSelected = false;
var backSelected  = false;
var docSelected   = false;
var videoSelected = false;
var DOC_NEEDS_BACK = ['aadhaar','driving_license'];

// ─── Camera / Recorder state ──────────────────────────────
var mediaStream   = null;
var mediaRecorder = null;
var recordedChunks = [];
var recordedBlob  = null;
var timerInterval = null;
var recSeconds    = 0;
var camReady      = false;

// PHP-injected values (safe to embed — already htmlspecialchars'd at PHP level)
var USER_NAME = <?= json_encode($user_display_name) ?>;
var APP_NAME  = <?= json_encode($app_name) ?>;

// The teleprompter statement
var PROMPT_TEXT = 'मेरा नाम ' + USER_NAME + ' है। मैं ' + APP_NAME + ' की सभी guidelines और terms को agree करता/करती हूँ।';

// ─── Doc selector ─────────────────────────────────────────
function selectDoc(val) {
  ['aadhaar','driving_license','pan','passport'].forEach(function(d) {
    var card = document.getElementById('dc-' + d);
    if (card) {
      card.classList.remove('selected');
      var chk = card.querySelector('.doc-card-check svg');
      if (chk) chk.setAttribute('stroke','#cbd5e1');
    }
  });

  var card = document.getElementById('dc-' + val);
  card.classList.add('selected');
  var chk = card.querySelector('.doc-card-check svg');
  if (chk) chk.setAttribute('stroke','white');
  document.getElementById('dt-' + val).checked = true;

  needsBack = DOC_NEEDS_BACK.includes(val);

  var backSlot  = document.getElementById('back-slot');
  var uploadRow = document.getElementById('upload-row');
  backSlot.style.display = needsBack ? '' : 'none';
  if (needsBack) {
    uploadRow.classList.remove('single');
  } else {
    uploadRow.classList.add('single');
  }

  document.getElementById('front-label').textContent = needsBack ? 'Front Side' : 'Document Image';
  document.getElementById('upload-guide').textContent = needsBack
    ? 'Upload clear front & back images'
    : 'Upload one clear image';

  var uc = document.getElementById('upload-card');
  uc.style.opacity       = '1';
  uc.style.pointerEvents = 'auto';

  docSelected = true;
  clearFile('front','file_front');
  clearFile('back','file_back');
  document.getElementById('doc-type-error').style.display = 'none';
  updateSubmitBtn();
}

// ─── File upload helpers ───────────────────────────────────
function updateSubmitBtn() {
  var consentChecked = document.getElementById('consent-check')?.checked || false;
  var filesReady  = docSelected && frontSelected && (!needsBack || backSelected);
  var videoReady  = videoSelected;
  var allDocsReady = filesReady && videoReady;
  var ready = allDocsReady && consentChecked;

  document.getElementById('submit-btn').disabled = !ready;

  // Unlock video card when docs are ready
  var vc = document.getElementById('video-card');
  vc.style.opacity       = filesReady ? '1' : '.4';
  vc.style.pointerEvents = filesReady ? 'auto' : 'none';
  if (filesReady) {
    document.getElementById('video-guide').textContent = 'Record your selfie video';
  }

  // Unlock consent card when docs + video ready
  var cc = document.getElementById('consent-card');
  cc.style.opacity       = allDocsReady ? '1' : '.4';
  cc.style.pointerEvents = allDocsReady ? 'auto' : 'none';
}

function fileSelected(input, side) {
  var file = input.files[0];
  if (!file) return;
  if (file.size > 5 * 1024 * 1024) {
    showErr('File too large — maximum size is 5 MB.');
    input.value = '';
    return;
  }
  var dz   = document.getElementById('dz-' + side);
  var img  = document.getElementById('fp-' + side + '-img');
  var pdf  = document.getElementById('fp-' + side + '-pdf');
  var name = document.getElementById('fp-' + side + '-name');
  var siz  = document.getElementById('fp-' + side + '-size');

  dz.classList.add('has-file');
  name.textContent = file.name;
  siz.textContent  = file.size < 1048576
    ? Math.round(file.size / 1024) + ' KB'
    : (file.size / 1048576).toFixed(1) + ' MB';

  if (file.type.startsWith('image/')) {
    pdf.style.display = 'none'; img.style.display = '';
    var reader = new FileReader();
    reader.onload = function(e) { img.src = e.target.result; };
    reader.readAsDataURL(file);
  } else {
    img.style.display = 'none'; pdf.style.display = 'flex';
  }

  if (side === 'front') frontSelected = true;
  else                  backSelected  = true;
  updateSubmitBtn();
}

function clearFile(side, inputId) {
  var input = document.getElementById(inputId);
  if (input) {
    input.value = '';
    var dz = document.getElementById('dz-' + side);
    if (dz) dz.classList.remove('has-file');
    var img = document.getElementById('fp-' + side + '-img');
    var pdf = document.getElementById('fp-' + side + '-pdf');
    if (img) { img.src = ''; img.style.display = 'none'; }
    if (pdf) { pdf.style.display = 'none'; }
  }
  if (side === 'front') frontSelected = false;
  else                  backSelected  = false;
  updateSubmitBtn();
}

function dzDrag(e, side) { e.preventDefault(); document.getElementById('dz-'+side).classList.add('drag-over'); }
function dzLeave(side)   { document.getElementById('dz-'+side).classList.remove('drag-over'); }
function dzDrop(e, side, inputId) {
  e.preventDefault(); dzLeave(side);
  var inp = document.getElementById(inputId);
  if (e.dataTransfer.files.length) {
    var dt = new DataTransfer();
    dt.items.add(e.dataTransfer.files[0]);
    inp.files = dt.files;
    fileSelected(inp, side);
  }
}

function showErr(msg) {
  var el = document.getElementById('doc-type-error');
  el.textContent = msg;
  el.style.display = '';
  setTimeout(function(){ el.style.display = 'none'; }, 4500);
}

// ─── Camera & Recording ────────────────────────────────────
function startCamera() {
  navigator.mediaDevices.getUserMedia({ video: { facingMode:'user', width:{ ideal:1280 }, height:{ ideal:720 } }, audio: true })
    .then(function(stream) {
      mediaStream = stream;
      var vid = document.getElementById('cam-video');
      vid.srcObject = stream;
      vid.play();

      document.getElementById('cam-no-access').style.display   = 'none';
      document.getElementById('cam-teleprompter').style.display = '';

      // Set teleprompter text with highlighted parts
      document.getElementById('cam-prompt-text').innerHTML =
        'मेरा नाम <span class="highlight">' + escHtml(USER_NAME) + '</span> है। ' +
        'मैं <span class="highlight">' + escHtml(APP_NAME) + '</span> की सभी guidelines और terms को agree करता/करती हूँ।';

      // Update buttons & steps
      document.getElementById('btn-start-cam').style.display  = 'none';
      document.getElementById('btn-start-rec').style.display  = '';
      setVidStep('record');
      camReady = true;
    })
    .catch(function(err) {
      console.error('Camera error:', err);
      document.getElementById('cam-no-access').innerHTML =
        '<svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.5" width="40" height="40"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/><line x1="1" y1="1" x2="23" y2="23" stroke="white" stroke-width="2"/></svg><p style="color:#fca5a5">Camera access denied.<br>Please allow camera permission and try again.</p>';
    });
}

function startRecording() {
  if (!mediaStream) return;
  recordedChunks = [];
  recSeconds = 0;

  var options = { mimeType: 'video/webm;codecs=vp9,opus' };
  if (!MediaRecorder.isTypeSupported(options.mimeType)) {
    options.mimeType = 'video/webm';
  }
  if (!MediaRecorder.isTypeSupported(options.mimeType)) {
    options.mimeType = '';
  }

  try {
    mediaRecorder = new MediaRecorder(mediaStream, options);
  } catch(e) {
    mediaRecorder = new MediaRecorder(mediaStream);
  }

  mediaRecorder.ondataavailable = function(e) {
    if (e.data && e.data.size > 0) recordedChunks.push(e.data);
  };
  mediaRecorder.onstop = function() {
    recordedBlob = new Blob(recordedChunks, { type: mediaRecorder.mimeType || 'video/webm' });
    showRecordedPreview(recordedBlob);
    injectVideoFile(recordedBlob);
    videoSelected = true;
    updateSubmitBtn();
  };

  mediaRecorder.start(100);

  // UI
  document.getElementById('btn-start-rec').style.display = 'none';
  document.getElementById('btn-stop-rec').style.display  = '';
  document.getElementById('rec-indicator').classList.add('active');
  document.getElementById('cam-timer').classList.add('active');

  // Timer
  timerInterval = setInterval(function() {
    recSeconds++;
    var m = Math.floor(recSeconds / 60);
    var s = recSeconds % 60;
    var timerEl = document.getElementById('cam-timer');
    timerEl.textContent = m + ':' + (s < 10 ? '0' : '') + s;
    if (recSeconds >= 25) timerEl.classList.add('warning');
    // Auto-stop at 30s
    if (recSeconds >= 30) stopRecording();
  }, 1000);
}

function stopRecording() {
  if (!mediaRecorder || mediaRecorder.state === 'inactive') return;
  clearInterval(timerInterval);
  mediaRecorder.stop();

  document.getElementById('btn-stop-rec').style.display   = 'none';
  document.getElementById('btn-rerecord').style.display   = '';
  document.getElementById('rec-indicator').classList.remove('active');
  setVidStep('done');
}

function reRecord() {
  // Reset recorded state
  recordedBlob   = null;
  recordedChunks = [];
  videoSelected  = false;

  document.getElementById('recorded-wrap').classList.remove('show');
  document.getElementById('video-success-badge').style.display = 'none';
  document.getElementById('btn-rerecord').style.display   = 'none';
  document.getElementById('btn-start-rec').style.display  = '';
  document.getElementById('cam-timer').classList.remove('active','warning');
  document.getElementById('cam-timer').textContent = '0:00';
  recSeconds = 0;
  setVidStep('record');

  // Clear the hidden file input
  document.getElementById('file_video_input').value = '';
  updateSubmitBtn();
}

function showRecordedPreview(blob) {
  var url = URL.createObjectURL(blob);
  var vid = document.getElementById('recorded-video');
  vid.src = url;
  document.getElementById('recorded-wrap').classList.add('show');
  document.getElementById('video-success-badge').style.display = 'flex';
}

function injectVideoFile(blob) {
  // Transfer the blob to the hidden <input type="file"> so it submits with the form
  var ext  = 'webm';
  if (blob.type.includes('mp4')) ext = 'mp4';
  if (blob.type.includes('ogg')) ext = 'ogg';
  var file = new File([blob], 'kyc_selfie_video.' + ext, { type: blob.type || 'video/webm' });
  var dt   = new DataTransfer();
  dt.items.add(file);
  document.getElementById('file_video_input').files = dt.files;
}

// ─── Step indicator helper ─────────────────────────────────
function setVidStep(step) {
  var steps = { camera:'vs-camera', record:'vs-record', done:'vs-done' };
  var order = ['camera','record','done'];
  var idx   = order.indexOf(step);
  order.forEach(function(s, i) {
    var el  = document.getElementById('vs-' + s);
    var dot = document.getElementById('vsd-' + s);
    if (!el) return;
    el.classList.remove('active','done');
    if (i < idx) {
      el.classList.add('done');
      dot.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3" width="10" height="10"><polyline points="20 6 9 17 4 12"/></svg>';
    } else if (i === idx) {
      el.classList.add('active');
      dot.textContent = i + 1;
    } else {
      dot.textContent = i + 1;
    }
  });
}

// ─── HTML escape helper ────────────────────────────────────
function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ─── Form submit — XHR with progress ──────────────────────
document.getElementById('kyc-form')?.addEventListener('submit', function(e) {
  e.preventDefault(); // Always intercept — we do XHR manually

  if (!docSelected) {
    showErr('Please select a document type to continue.');
    document.getElementById('doc-type-grid').scrollIntoView({ behavior:'smooth', block:'center' });
    return;
  }
  if (!videoSelected) {
    showErr('Please record your selfie video before submitting.');
    document.getElementById('video-card').scrollIntoView({ behavior:'smooth', block:'center' });
    return;
  }

  var form = document.getElementById('kyc-form');
  var submitBtn = document.getElementById('submit-btn');

  // ── UI: lock button, show spinner ──
  submitBtn.disabled = true;
  document.getElementById('submit-icon').style.display   = 'none';
  document.getElementById('submit-spinner').style.display = '';
  document.getElementById('submit-label').textContent    = 'Submitting...';

  // ── Show progress panel ──
  var panel = document.getElementById('upload-progress-panel');
  panel.style.display = '';
  panel.scrollIntoView({ behavior:'smooth', block:'nearest' });

  function setBar(barId, pctId, pct) {
    document.getElementById(barId).style.width = pct + '%';
    document.getElementById(pctId).textContent = Math.round(pct) + '%';
  }
  function setStatus(txt) {
    document.getElementById('upload-status-text').textContent = txt;
  }

  // ── Build FormData ──
  var fd = new FormData(form);

  // Make sure video file is included (it's in the hidden input)
  var videoInput = document.getElementById('file_video_input');
  if (videoInput.files[0]) {
    fd.set('file_video', videoInput.files[0], videoInput.files[0].name);
  }

  // Calculate approx sizes for progress split
  var frontFile = document.getElementById('file_front').files[0];
  var backFile  = document.getElementById('file_back')?.files[0];
  var vidFile   = videoInput.files[0];

  var docSize   = (frontFile ? frontFile.size : 0) + (backFile ? backFile.size : 0);
  var vidSize   = vidFile ? vidFile.size : 0;
  var totalSize = docSize + vidSize;

  setStatus('Connecting to server...');

  var xhr = new XMLHttpRequest();

  xhr.upload.addEventListener('progress', function(ev) {
    if (!ev.lengthComputable) return;
    var loaded = ev.loaded;
    var total  = ev.total;
    var overallPct = (loaded / total) * 100;

    // Split progress: first docSize bytes = docs, rest = video
    if (totalSize > 0) {
      var docLoaded = Math.min(loaded, docSize);
      var vidLoaded = Math.max(0, loaded - docSize);

      var docPct = docSize > 0 ? Math.min(100, (docLoaded / docSize) * 100) : 100;
      var vidPct = vidSize > 0 ? Math.min(100, (vidLoaded / vidSize) * 100) : 0;

      setBar('docs-bar', 'docs-pct', docPct);
      setBar('video-bar', 'video-pct', vidPct);

      if (docPct < 100) {
        setStatus('Uploading ID documents (' + Math.round(docPct) + '%)...');
      } else if (vidPct < 100) {
        setStatus('Uploading selfie video (' + Math.round(vidPct) + '%)...');
      } else {
        setStatus('Finalising — please wait...');
      }
    } else {
      setBar('docs-bar', 'docs-pct', overallPct);
      setBar('video-bar', 'video-pct', overallPct);
    }
  });

  xhr.addEventListener('load', function() {
    // Complete bars to 100%
    setBar('docs-bar', 'docs-pct', 100);
    setBar('video-bar', 'video-pct', 100);

    if (xhr.status === 200) {
      // Server returned a full HTML page — just reload to show result
      setStatus('Upload complete! Redirecting...');
      document.getElementById('submit-label').textContent = 'Done!';

      // Replace page content with the response (which is our PHP page showing success/error)
      setTimeout(function() {
        document.open();
        document.write(xhr.responseText);
        document.close();
      }, 600);
    } else {
      uploadFailed('Server error (' + xhr.status + '). Please try again.');
    }
  });

  xhr.addEventListener('error', function() {
    uploadFailed('Network error. Please check your connection and try again.');
  });

  xhr.addEventListener('timeout', function() {
    uploadFailed('Upload timed out. Your file may be too large or connection too slow. Please try again.');
  });

  // No timeout limit — let large videos upload freely
  xhr.timeout = 0;

  xhr.open('POST', window.location.href, true);
  xhr.send(fd);

  setStatus('Uploading...');
});

function uploadFailed(msg) {
  var submitBtn = document.getElementById('submit-btn');
  submitBtn.disabled = false;
  document.getElementById('submit-icon').style.display    = '';
  document.getElementById('submit-spinner').style.display = 'none';
  document.getElementById('submit-label').textContent     = 'Submit KYC for Verification';
  document.getElementById('upload-status-text').textContent = '⚠ ' + msg;
  document.getElementById('upload-status-text').style.color = '#dc2626';
  // Re-enable so user can retry
  document.getElementById('upload-progress-panel').style.display = 'none';
}
</script>
</body>
</html>