<?php
/**
 * admin/cookie-consent.php — Cookie Consent Settings
 * Admin can enable/disable and customize the cookie banner.
 * Individual cookie categories can be toggled (not deleted).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin.php';
require_admin();

$user     = current_user();
$app_name = APP_NAME;
$csrf     = csrf_token();
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$msg = ''; $err = '';

// ── Save settings ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $fields = [
        'cookie_consent_enabled',
        'cookie_consent_title',
        'cookie_consent_message',
        'cookie_consent_btn_accept',
        'cookie_consent_btn_decline',
        'cookie_consent_btn_manage',
        'cookie_consent_position',
        'cookie_consent_policy_slug',
        // Categories (enable/disable only — never delete)
        'cookie_analytics_enabled',
        'cookie_marketing_enabled',
        'cookie_preferences_enabled',
    ];

    // Checkboxes — must handle "off" state
    $checkbox_fields = [
        'cookie_consent_enabled',
        'cookie_analytics_enabled',
        'cookie_marketing_enabled',
        'cookie_preferences_enabled',
    ];

    foreach ($fields as $k) {
        if (in_array($k, $checkbox_fields)) {
            set_setting($k, !empty($_POST[$k]) ? '1' : '0');
        } else {
            if (isset($_POST[$k])) {
                set_setting($k, trim(strip_tags($_POST[$k])));
            }
        }
    }
    $msg = 'Cookie consent settings saved.';
}

function cs(string $key, string $default=''): string {
    return htmlspecialchars(get_setting($key, $default));
}

// Footer pages list (for policy slug selector)
$footer_pages = db()->query("SELECT title, slug FROM legal_pages WHERE is_published=1 ORDER BY sort_order ASC, id ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cookie Consent — <?= htmlspecialchars($app_name) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/admin/admin.css">
<?php inject_global_head(); ?>
<style>
.cc-shell { display:flex; min-height:100vh; background:#f1f5f9; }
.cc-main  { flex:1; margin-left:232px; padding:0; }
@media(max-width:768px){ .cc-main{ margin-left:0; } }
.cc-topbar { background:#fff; border-bottom:1px solid #e2e8f0; padding:14px 28px; display:flex; align-items:center; justify-content:space-between; }
.cc-topbar h1 { font-size:19px; font-weight:700; color:#0f172a; margin:0; }
.cc-content { padding:24px 28px 60px; max-width:900px; }

.settings-card { background:#fff; border-radius:14px; border:1px solid #e2e8f0; padding:28px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.settings-card h2 { font-size:15px; font-weight:700; color:#0f172a; margin:0 0 20px; display:flex; align-items:center; gap:8px; }

.field-row { margin-bottom:18px; }
.field-row label { display:block; font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.05em; margin-bottom:6px; }
.field-row input[type=text], .field-row textarea, .field-row select {
  width:100%; border:1px solid #e2e8f0; border-radius:8px; padding:9px 12px;
  font-size:14px; outline:none; color:#0f172a; box-sizing:border-box; transition:border-color .15s;
}
.field-row input:focus, .field-row textarea:focus, .field-row select:focus { border-color:#6366f1; }
.field-row .hint { font-size:11px; color:#94a3b8; margin-top:4px; }
.field-row textarea { min-height:80px; resize:vertical; }

/* Toggle switch */
.toggle-row { display:flex; align-items:center; gap:12px; margin-bottom:18px; }
.toggle-sw { position:relative; display:inline-block; width:44px; height:24px; flex-shrink:0; }
.toggle-sw input { opacity:0; width:0; height:0; }
.toggle-sw .slider { position:absolute; inset:0; background:#cbd5e1; border-radius:24px; transition:.2s; cursor:pointer; }
.toggle-sw .slider::before { content:''; position:absolute; width:18px; height:18px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.2s; box-shadow:0 1px 3px rgba(0,0,0,.2); }
.toggle-sw input:checked + .slider { background:#6366f1; }
.toggle-sw input:checked + .slider::before { transform:translateX(20px); }
.toggle-lbl { font-size:14px; font-weight:600; color:#374151; }
.toggle-sub { font-size:12px; color:#94a3b8; }

/* Category cards */
.cookie-cats { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
@media(max-width:700px){ .cookie-cats { grid-template-columns:1fr; } }
.cookie-cat-card {
  border:1px solid #e2e8f0; border-radius:12px; padding:18px;
  display:flex; justify-content:space-between; align-items:flex-start; gap:12px;
}
.cookie-cat-card.mandatory { background:#f0fdf4; border-color:#bbf7d0; }
.cookie-cat-card h3 { font-size:14px; font-weight:700; color:#0f172a; margin:0 0 4px; }
.cookie-cat-card p  { font-size:12px; color:#64748b; margin:0; line-height:1.5; }
.badge-mandatory { background:#d1fae5; color:#065f46; font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; }

/* Buttons */
.btn-save { background:#6366f1; color:#fff; border:none; border-radius:10px; padding:11px 32px; font-size:15px; font-weight:600; cursor:pointer; transition:background .15s; }
.btn-save:hover { background:#4f46e5; }

.alert-ok { background:#d1fae5; color:#065f46; border-radius:8px; padding:10px 16px; margin-bottom:18px; font-size:14px; }

/* Live preview */
#preview-banner {
  position:relative; background:#1e293b; color:#e2e8f0;
  border-radius:14px; padding:24px; margin-top:20px;
  box-shadow:0 8px 32px rgba(0,0,0,.2);
  font-size:14px; line-height:1.6;
  border:2px dashed #334155;
}
#preview-banner .preview-label { position:absolute; top:-10px; left:20px; background:#6366f1; color:#fff; font-size:10px; font-weight:700; padding:2px 10px; border-radius:20px; letter-spacing:.05em; }
#preview-banner .pb-title { font-size:16px; font-weight:700; color:#f1f5f9; margin-bottom:6px; }
#preview-banner .pb-msg   { color:#94a3b8; margin-bottom:16px; font-size:13px; }
#preview-banner .pb-btns  { display:flex; gap:10px; flex-wrap:wrap; }
#preview-banner .pb-btn   { padding:8px 18px; border-radius:8px; font-size:13px; font-weight:600; border:none; cursor:pointer; }
#preview-banner .pb-accept  { background:#6366f1; color:#fff; }
#preview-banner .pb-decline { background:#334155; color:#94a3b8; }
#preview-banner .pb-manage  { background:transparent; border:1px solid #475569 !important; color:#94a3b8; }
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
<div class="cc-shell">
<?php require_once __DIR__ . '/sidebar.php'; ?>
<div class="cc-main">

<div class="cc-topbar">
  <h1>🍪 Cookie Consent Settings</h1>
</div>

<div class="cc-content">

<?php if ($msg): ?><div class="alert-ok">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>

<form method="post" action="" id="cc-form">
<input type="hidden" name="csrf_token" value="<?= $csrf ?>">

<!-- ── Master toggle ──────────────────────────────────────── -->
<div class="settings-card">
  <h2>🔧 General</h2>

  <div class="toggle-row">
    <label class="toggle-sw">
      <input type="checkbox" name="cookie_consent_enabled" id="cc-enabled" value="1"
             <?= cs('cookie_consent_enabled','1') === '1' ? 'checked' : '' ?>>
      <span class="slider"></span>
    </label>
    <div>
      <div class="toggle-lbl">Enable Cookie Consent Banner</div>
      <div class="toggle-sub">When disabled, banner is hidden site-wide</div>
    </div>
  </div>

  <div class="field-row">
    <label>Banner Position</label>
    <select name="cookie_consent_position">
      <?php foreach (['bottom'=>'Bottom (Full Width)','bottom-left'=>'Bottom Left','bottom-right'=>'Bottom Right'] as $v=>$l): ?>
        <option value="<?= $v ?>" <?= cs('cookie_consent_position','bottom')===$v?'selected':'' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="field-row">
    <label>Cookie Policy Page</label>
    <select name="cookie_consent_policy_slug" id="policy-slug-sel">
      <option value="">— No link —</option>
      <?php foreach ($footer_pages as $fp): ?>
        <option value="<?= htmlspecialchars($fp['slug']) ?>"
          <?= cs('cookie_consent_policy_slug') === $fp['slug'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($fp['title']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <div class="hint">This page link appears in the consent banner</div>
  </div>
</div>

<!-- ── Banner text ────────────────────────────────────────── -->
<div class="settings-card">
  <h2>✏️ Banner Text</h2>

  <div class="field-row">
    <label>Banner Title</label>
    <input type="text" name="cookie_consent_title" id="cc-title"
           value="<?= cs('cookie_consent_title','We use cookies 🍪') ?>"
           oninput="updatePreview()">
  </div>

  <div class="field-row">
    <label>Banner Message</label>
    <textarea name="cookie_consent_message" id="cc-msg"
              oninput="updatePreview()"><?= cs('cookie_consent_message','We use cookies to enhance your browsing experience, serve personalized content, and analyze our traffic.') ?></textarea>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;">
    <div class="field-row">
      <label>Accept Button</label>
      <input type="text" name="cookie_consent_btn_accept" id="cc-btn-accept"
             value="<?= cs('cookie_consent_btn_accept','Accept All') ?>"
             oninput="updatePreview()">
    </div>
    <div class="field-row">
      <label>Decline Button</label>
      <input type="text" name="cookie_consent_btn_decline" id="cc-btn-decline"
             value="<?= cs('cookie_consent_btn_decline','Decline') ?>"
             oninput="updatePreview()">
    </div>
    <div class="field-row">
      <label>Manage Button</label>
      <input type="text" name="cookie_consent_btn_manage" id="cc-btn-manage"
             value="<?= cs('cookie_consent_btn_manage','Manage Preferences') ?>"
             oninput="updatePreview()">
    </div>
  </div>

  <!-- Live preview -->
  <div id="preview-banner">
    <span class="preview-label">PREVIEW</span>
    <div class="pb-title" id="pv-title"><?= cs('cookie_consent_title','We use cookies 🍪') ?></div>
    <div class="pb-msg"   id="pv-msg"><?= cs('cookie_consent_message','We use cookies...') ?></div>
    <div class="pb-btns">
      <button class="pb-btn pb-accept"  id="pv-accept"><?= cs('cookie_consent_btn_accept','Accept All') ?></button>
      <button class="pb-btn pb-decline" id="pv-decline"><?= cs('cookie_consent_btn_decline','Decline') ?></button>
      <button class="pb-btn pb-manage"  id="pv-manage"><?= cs('cookie_consent_btn_manage','Manage Preferences') ?></button>
    </div>
  </div>
</div>

<!-- ── Cookie Categories ──────────────────────────────────── -->
<div class="settings-card">
  <h2>🗂️ Cookie Categories</h2>
  <p style="font-size:13px;color:#64748b;margin-bottom:20px;">
    Enable or disable each category. <strong>Essential cookies cannot be disabled</strong> — they are required for the site to function.
    You can only toggle visibility; categories are never deleted.
  </p>

  <div class="cookie-cats">

    <!-- Essential — always on, non-toggleable -->
    <div class="cookie-cat-card mandatory">
      <div>
        <h3>🔒 Essential <span class="badge-mandatory">Always On</span></h3>
        <p>Required for authentication, security, and core functionality. Cannot be disabled.</p>
      </div>
      <label class="toggle-sw" style="cursor:not-allowed;">
        <input type="checkbox" checked disabled>
        <span class="slider" style="opacity:.6;cursor:not-allowed;"></span>
      </label>
    </div>

    <!-- Analytics -->
    <div class="cookie-cat-card">
      <div>
        <h3>📊 Analytics</h3>
        <p>Help us understand how visitors interact with the website (Google Analytics, etc).</p>
      </div>
      <label class="toggle-sw">
        <input type="checkbox" name="cookie_analytics_enabled" value="1"
               id="cat-analytics"
               <?= cs('cookie_analytics_enabled','1')==='1'?'checked':'' ?>>
        <span class="slider"></span>
      </label>
    </div>

    <!-- Preferences -->
    <div class="cookie-cat-card">
      <div>
        <h3>⚙️ Preferences</h3>
        <p>Remember user preferences like language, theme, and display settings.</p>
      </div>
      <label class="toggle-sw">
        <input type="checkbox" name="cookie_preferences_enabled" value="1"
               id="cat-pref"
               <?= cs('cookie_preferences_enabled','1')==='1'?'checked':'' ?>>
        <span class="slider"></span>
      </label>
    </div>

    <!-- Marketing -->
    <div class="cookie-cat-card">
      <div>
        <h3>📣 Marketing</h3>
        <p>Used to show relevant ads and track conversions across advertising platforms.</p>
      </div>
      <label class="toggle-sw">
        <input type="checkbox" name="cookie_marketing_enabled" value="1"
               id="cat-marketing"
               <?= cs('cookie_marketing_enabled','0')==='1'?'checked':'' ?>>
        <span class="slider"></span>
      </label>
    </div>

  </div>
</div>

<button type="submit" class="btn-save">💾 Save Cookie Settings</button>

</form>
</div><!-- .cc-content -->
</div><!-- .cc-main -->
</div><!-- .cc-shell -->

<script>
function updatePreview() {
  document.getElementById('pv-title').textContent  = document.getElementById('cc-title').value;
  document.getElementById('pv-msg').textContent    = document.getElementById('cc-msg').value;
  document.getElementById('pv-accept').textContent = document.getElementById('cc-btn-accept').value;
  document.getElementById('pv-decline').textContent= document.getElementById('cc-btn-decline').value;
  document.getElementById('pv-manage').textContent = document.getElementById('cc-btn-manage').value;
}
</script>
</body>
</html>
