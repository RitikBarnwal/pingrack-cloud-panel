<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/servers.php';
require_once __DIR__ . '/includes/currency.php';
require_login();

$user     = current_user();
$app_name = APP_NAME;
$currency = strtoupper($user['currency'] ?? 'USD');
$curr_sym = currency_symbol($currency);
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$uname    = htmlspecialchars($user['username']);
$balance  = number_format((float)$user['wallet_balance'], 2);
$csrf     = csrf_token();
$msg = ''; $err = '';

// Load user servers for attach dropdown
$user_servers = db()->prepare(
    "SELECT id,name,region_slug,status FROM servers WHERE user_id=? AND deleted_at IS NULL AND status='running' ORDER BY name"
);
$user_servers->execute([$user['id']]);
$user_servers = $user_servers->fetchAll() ?: [];

// Load volumes from DB
$volumes_table_exists = false;
try {
    db()->query('SELECT 1 FROM volumes LIMIT 1');
    $volumes_table_exists = true;
} catch (Throwable $e) {}

$volumes = [];
if ($volumes_table_exists) {
    $st = db()->prepare('SELECT * FROM volumes WHERE user_id=? AND deleted_at IS NULL ORDER BY created_at DESC');
    $st->execute([$user['id']]);
    $volumes = $st->fetchAll() ?: [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Volumes — <?= $app_name ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    .page-wrap{padding:24px;max-width:780px}
    .card{background:white;border:1px solid var(--border);border-radius:13px;overflow:hidden;margin-bottom:18px}
    .card-head{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
    .card-title{font-size:14px;font-weight:800;color:var(--gray-900)}
    .card-body{padding:20px}
    .coming-soon-banner{background:linear-gradient(135deg,#eff6ff,#f0fdf4);border:1.5px solid #bfdbfe;border-radius:12px;padding:28px 24px;text-align:center;margin-bottom:20px}
    .cs-icon{font-size:40px;margin-bottom:12px;line-height:1}
    .cs-title{font-size:17px;font-weight:800;color:var(--gray-800);margin-bottom:6px}
    .cs-sub{font-size:13.5px;color:var(--gray-500);line-height:1.6}
    .vol-item{display:flex;align-items:center;gap:14px;padding:13px 0;border-bottom:1px solid var(--gray-100)}
    .vol-item:last-child{border:none}
    .vol-icon{width:38px;height:38px;border-radius:9px;background:var(--gray-100);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;flex-shrink:0}
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
      <span style="font-weight:800;font-size:15px">Volumes</span>
    </div>
    <div class="topbar"><span class="topbar-title">Volumes</span></div>

    <div class="page-wrap">
      <!-- Coming soon banner -->
      <div class="coming-soon-banner">
        <div class="cs-icon">💾</div>
        <div class="cs-title">Block Storage Volumes — Coming Soon</div>
        <div class="cs-sub">
          Attach persistent NVMe volumes to your servers.<br>
          Resize anytime, move between servers, survive server deletions.<br>
          <span style="font-size:12px;color:var(--gray-400);margin-top:6px;display:block">Available soon — <a href="<?= BASE_URL ?>/billing.php" style="color:var(--primary);font-weight:600">check back here</a></span>
        </div>
      </div>

      <!-- Info card -->
      <div class="card">
        <div class="card-head"><span class="card-title">About Volumes</span></div>
        <div class="card-body">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
            <?php foreach ([
              ['Persistent Storage','Data survives server deletions and power cycles.'],
              ['Flexible Sizing','Create from 10GB up to 10TB. Expand anytime.'],
              ['NVMe SSDs','Same fast storage as server root disks.'],
              ['Region-Specific','Volumes must be in the same region as the server.'],
            ] as [$title,$desc]): ?>
            <div style="padding:14px;background:var(--gray-50);border:1px solid var(--border);border-radius:9px">
              <div style="font-size:13.5px;font-weight:700;color:var(--gray-900);margin-bottom:4px"><?= $title ?></div>
              <div style="font-size:12.5px;color:var(--gray-500);line-height:1.5"><?= $desc ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<div class="overlay" id="overlay" onclick="document.getElementById('sidebar').classList.remove('open');this.classList.remove('open')"></div>
</body>
</html>
