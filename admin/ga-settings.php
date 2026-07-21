<?php
/**
 * admin/ga-settings.php — Google Analytics Settings Page
 * Admin configures GA4 Measurement ID + API credentials here.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/security.php';
require_admin();

$user   = current_user();
$csrf   = csrf_token();
$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $allowed_keys = [
        'ga_measurement_id',        // G-XXXXXXXX
        'ga_property_id',           // Numeric property ID for API
        'ga_client_email',          // Service account email
        'ga_private_key',           // Service account private key (PEM)
        'ga_enabled',               // 1 or 0
        'ga_realtime_enabled',      // 1 or 0
    ];
    foreach ($allowed_keys as $k) {
        if (array_key_exists($k, $_POST)) {
            set_setting($k, trim($_POST[$k]));
        }
    }
    $msg = 'Google Analytics settings saved.';
}

function gs(string $key, string $default=''): string {
    return htmlspecialchars(get_setting($key, $default));
}

$app_name = APP_NAME;
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>GA Settings — <?= htmlspecialchars($app_name) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/admin/admin.css">
<?php inject_global_head(); ?>
<style>
.an-shell { display:flex; min-height:100vh; background:#f1f5f9; }
.an-main  { flex:1; margin-left:232px; padding:0; }
@media(max-width:768px){ .an-main { margin-left:0; } }
.an-topbar { background:#fff; border-bottom:1px solid #e2e8f0; padding:16px 28px; display:flex; align-items:center; justify-content:space-between; }
.an-topbar h1 { font-size:20px; font-weight:700; color:#0f172a; margin:0; }
.settings-wrap { max-width:760px; margin:32px auto; padding:0 28px; }
.settings-card { background:#fff; border-radius:16px; border:1px solid #e2e8f0; padding:32px; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.settings-card h2 { font-size:18px; font-weight:700; color:#0f172a; margin:0 0 6px; }
.settings-card p.sub { font-size:13px; color:#64748b; margin:0 0 28px; }
.field-group { margin-bottom:22px; }
.field-group label { display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:6px; }
.field-group input, .field-group textarea, .field-group select {
  width:100%; border:1px solid #e2e8f0; border-radius:8px; padding:9px 12px;
  font-size:14px; outline:none; color:#0f172a; box-sizing:border-box;
  transition:border-color .15s;
}
.field-group input:focus, .field-group textarea:focus { border-color:#6366f1; }
.field-group textarea { min-height:140px; font-family:monospace; font-size:12px; resize:vertical; }
.field-group .hint { font-size:12px; color:#94a3b8; margin-top:4px; }
.toggle-row { display:flex; align-items:center; gap:12px; margin-bottom:22px; }
.toggle-row input[type=checkbox] { width:20px; height:20px; accent-color:#6366f1; cursor:pointer; }
.toggle-row label { font-size:14px; font-weight:600; color:#374151; cursor:pointer; margin:0; }
.btn-save { background:#6366f1; color:#fff; border:none; border-radius:10px; padding:11px 28px; font-size:15px; font-weight:600; cursor:pointer; transition:background .15s; }
.btn-save:hover { background:#4f46e5; }
.msg-ok  { background:#d1fae5; color:#065f46; border-radius:8px; padding:10px 16px; margin-bottom:20px; font-size:14px; }
.msg-err { background:#fee2e2; color:#b91c1c; border-radius:8px; padding:10px 16px; margin-bottom:20px; font-size:14px; }
.divider { border:none; border-top:1px solid #f1f5f9; margin:24px 0; }
.ga-link { display:inline-flex; align-items:center; gap:6px; color:#6366f1; font-size:13px; font-weight:500; text-decoration:none; }
.ga-link:hover { text-decoration:underline; }
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
<div class="an-shell">
<?php require_once __DIR__ . '/sidebar.php'; ?>
<div class="an-main">

<div class="an-topbar">
  <h1>⚙️ Google Analytics Settings</h1>
  <a href="<?= BASE_URL ?>/admin/ga-dashboard.php" class="ga-link">
    📊 Open Analytics Dashboard →
  </a>
</div>

<div class="settings-wrap">
<form method="post" action="">
<input type="hidden" name="csrf_token" value="<?= $csrf ?>">

<?php if ($msg): ?><div class="msg-ok">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="msg-err">❌ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="settings-card">
  <h2>🔧 Google Analytics 4 Configuration</h2>
  <p class="sub">
    Connect your GA4 property to enable the analytics dashboard.<br>
    Need help? <a class="ga-link" href="https://analytics.google.com" target="_blank">Open Google Analytics ↗</a>
  </p>

  <div class="toggle-row">
    <input type="checkbox" name="ga_enabled" id="ga_enabled" value="1" <?= gs('ga_enabled')==='1'?'checked':'' ?>>
    <label for="ga_enabled">Enable Google Analytics Integration</label>
  </div>

  <div class="toggle-row">
    <input type="checkbox" name="ga_realtime_enabled" id="ga_rt" value="1" <?= gs('ga_realtime_enabled')==='1'?'checked':'' ?>>
    <label for="ga_rt">Enable Real-Time Data (requires API credentials)</label>
  </div>

  <hr class="divider">

  <div class="field-group">
    <label>GA4 Measurement ID <span style="color:#ef4444;">*</span></label>
    <input type="text" name="ga_measurement_id" value="<?= gs('ga_measurement_id') ?>" placeholder="G-XXXXXXXXXX">
    <div class="hint">Found in GA4 → Admin → Data Streams → Measurement ID</div>
  </div>

  <hr class="divider">
  <p style="font-size:13px;font-weight:600;color:#374151;margin:0 0 16px;">
    🔑 API Credentials (for server-side real-time data)
  </p>
  <p style="font-size:12px;color:#94a3b8;margin:0 0 20px;">
    Create a Google Cloud Service Account with "Viewer" role on your GA4 property.
    Download the JSON key and paste the values below.
    <a class="ga-link" href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank">Open Google Cloud Console ↗</a>
  </p>

  <div class="field-group">
    <label>GA4 Property ID</label>
    <input type="text" name="ga_property_id" value="<?= gs('ga_property_id') ?>" placeholder="123456789">
    <div class="hint">Numeric only. GA4 → Admin → Property Settings → Property ID</div>
  </div>

  <div class="field-group">
    <label>Service Account Email</label>
    <input type="email" name="ga_client_email" value="<?= gs('ga_client_email') ?>" placeholder="your-sa@your-project.iam.gserviceaccount.com">
  </div>

  <div class="field-group">
    <label>Service Account Private Key (PEM)</label>
    <textarea name="ga_private_key" placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----"><?= gs('ga_private_key') ?></textarea>
    <div class="hint">Paste the full private_key value from your service account JSON file.</div>
  </div>

  <button type="submit" class="btn-save">💾 Save Settings</button>
</div>

</form>

<div class="settings-card" style="margin-top:20px;">
  <h2>📋 Quick Setup Guide</h2>
  <p class="sub">3 steps to connect Google Analytics</p>
  <ol style="font-size:14px;color:#374151;line-height:1.9;padding-left:20px;">
    <li>Create a <strong>GA4 Property</strong> at <a class="ga-link" href="https://analytics.google.com" target="_blank">analytics.google.com</a></li>
    <li>Add the <strong>Tracking Code</strong> (Measurement ID like G-XXXXXXXX)</li>
    <li>For real-time API data: create a <strong>Service Account</strong> in Google Cloud Console, grant it Viewer access to your GA4 property, and paste credentials above.</li>
  </ol>
  <p style="font-size:12px;color:#94a3b8;">The tracking script is automatically injected into all pages when GA is enabled.</p>
</div>

</div><!-- .settings-wrap -->
</div><!-- .an-main -->
</div><!-- .an-shell -->

<script>
// GA tracking script injection preview
document.getElementById('ga_enabled')?.addEventListener('change', function() {
  console.log('GA enabled:', this.checked);
});
</script>
</body>
</html>
