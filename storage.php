<?php
/**
 * storage.php — Object Storage
 * UI exactly matches servers.php (srv-card row design)
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/storage.php';
require_login();

$user     = current_user();
$uid      = (int)$user['id'];
$currency = strtoupper($user['currency'] ?? 'INR');
$curr_sym = user_currency_symbol($currency);
$app_name = APP_NAME;
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$uname    = htmlspecialchars($user['username']);
$balance  = number_format((float)$user['wallet_balance'], 2);
$csrf     = csrf_token();

try {
    $st = db()->prepare(
        'SELECT b.*, p.name as plan_name, p.storage_gb as plan_gb, p.bandwidth_gb as plan_bw
         FROM storage_buckets b JOIN storage_plans p ON p.id = b.plan_id
         WHERE b.user_id=? AND b.deleted_at IS NULL ORDER BY b.created_at DESC'
    );
    $st->execute([$uid]);
    $buckets = $st->fetchAll() ?: [];
} catch (Throwable $e) { $buckets = []; }

$total   = count($buckets);
$active  = count(array_filter($buckets, fn($b) => $b['status'] === 'active'));
$susp    = count(array_filter($buckets, fn($b) => $b['status'] === 'suspended'));
$used_gb = round(array_sum(array_column($buckets, 'used_gb')), 2);
$monthly = array_sum(array_column($buckets, 'price_monthly'));

function sbucket(string $s): string {
    return match($s) {
        'active'    => '<span class="badge badge-green"><span style="width:5px;height:5px;border-radius:50%;background:#16a34a;display:inline-block"></span> Active</span>',
        'suspended' => '<span class="badge badge-red">⚠ Suspended</span>',
        'deleting'  => '<span class="badge badge-yellow">Deleting</span>',
        default     => '<span class="badge badge-gray">'.ucfirst($s).'</span>',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Object Storage — <?= $app_name ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    .bkt-cards{display:flex;flex-direction:column;gap:10px}
    .bkt-card{background:white;border:1.5px solid var(--border);border-radius:13px;padding:16px 18px;display:flex;align-items:center;gap:14px;transition:all .16s}
    .bkt-card:hover{border-color:var(--gray-300);box-shadow:0 4px 16px rgba(0,0,0,.06);transform:translateY(-1px)}
    .bkt-icon{width:44px;height:44px;border-radius:11px;background:linear-gradient(135deg,#ede9fe,#ddd6fe);border:1px solid #c4b5fd;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .bkt-info{flex:1;min-width:0}
    .bkt-info-name{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:700;color:var(--gray-900);margin-bottom:3px}
    .bkt-info-name a{color:inherit;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .bkt-info-name a:hover{color:var(--primary)}
    .bkt-info-sub{display:content;align-items:center;gap:6px;font-size:12px;color:var(--gray-400)}
    .info-sep{color:var(--gray-300)}
    .bkt-specs{display:flex;align-items:center;gap:5px;flex-shrink:0}
    .bkt-usage{width:120px;flex-shrink:0}
    .bkt-usage-top{display:flex;justify-content:space-between;font-size:11px;color:var(--gray-500);margin-bottom:4px;font-weight:600}
    .bkt-price{text-align:right;flex-shrink:0}
    .bkt-price-main{font-size:13.5px;font-weight:700;color:var(--gray-900);font-family:var(--mono);white-space:nowrap}
    .bkt-price-sub{font-size:11px;color:var(--gray-400);margin-top:2px}
    .bkt-actions{display:flex;align-items:center;gap:6px;flex-shrink:0}
    .act-btn{width:32px;height:32px;border-radius:8px;border:1px solid var(--border);background:white;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--gray-500);transition:all .13s;text-decoration:none;flex-shrink:0}
    .act-btn:hover{background:var(--gray-100);color:var(--gray-900)}
    .act-btn svg{width:14px;height:14px;pointer-events:none}
    .page-topstrip{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;gap:12px;flex-wrap:wrap}
    .page-heading{font-size:20px;font-weight:900;color:var(--gray-900);letter-spacing:-.5px}
    .empty-wrap{background:white;border:1.5px solid var(--border);border-radius:13px;padding:52px 20px;text-align:center}
    .empty-h{font-size:17px;font-weight:800;color:var(--gray-900);margin-bottom:7px}
    .empty-p{font-size:13.5px;color:var(--gray-500);max-width:360px;margin:0 auto 22px;line-height:1.65}
    .btn-deploy{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:9px;font-size:13.5px;font-weight:700;background:var(--primary);color:white;border:none;cursor:pointer;font-family:var(--font);text-decoration:none;transition:all .15s;box-shadow:0 2px 8px rgba(103,61,230,.22)}
    .btn-deploy:hover{background:var(--primary-hover);transform:translateY(-1px)}
    .btn-deploy svg{width:14px;height:14px}
    @media(max-width:900px){.bkt-usage,.bkt-price{display:none}.bkt-card{gap:10px;padding:13px 14px}.bkt-icon{width:38px;height:38px}}
    @media(max-width:640px){.bkt-specs{display:none}}
  </style>
</head>
<body>
<div class="app-shell">

  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <div class="main-content" style="margin-left:260px;min-height:100vh;background:var(--gray-50)">

    <div class="mobile-bar">
      <button class="ham-btn" onclick="toggleSidebar()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div style="margin-left:auto">
        <a href="<?= BASE_URL ?>/storage/create.php" class="btn-deploy" style="padding:7px 12px;font-size:12px">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>New Bucket
        </a>
      </div>
    </div>

    <div class="topbar">
      <span class="topbar-title">Object Storage</span>
      <div style="display:flex;gap:8px;align-items:center;margin-left:auto">
        <a href="<?= BASE_URL ?>/billing.php" class="btn btn-secondary btn-sm">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
          <?= $curr_sym . $balance ?>
        </a>
        <a href="<?= BASE_URL ?>/storage/create.php" class="btn-deploy">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          New Bucket
        </a>
      </div>
    </div>

    <div style="padding:24px">
<style>
    /* Stat cards */
    .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
    .stat-card{background:white;border:1px solid var(--border);border-radius:13px;padding:18px 20px;display:flex;align-items:center;gap:14px;transition:box-shadow .16s,transform .16s;cursor:default}
    .stat-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.07);transform:translateY(-1px)}
    .stat-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .stat-val{font-size:26px;font-weight:900;color:var(--gray-900);letter-spacing:-1px;line-height:1}
    .stat-label{font-size:12px;color:var(--gray-500);font-weight:500;margin-top:3px}
    .stat-sub{font-size:11px;color:var(--gray-400);margin-top:1px}
</style>
      <!-- Stats — exact same as servers.php -->
      <!-- Stats cards -->
<div class="stats-grid">
  
  <div class="stat-card">
    <div class="stat-icon" style="background:#eff6ff">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2">
        <rect x="3" y="4" width="18" height="16" rx="2"></rect>
        <path d="M3 9h18"></path>
      </svg>
    </div>
    <div>
      <div class="stat-val"><?= $total ?></div>
      <div class="stat-label">Total Buckets</div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon" style="background:#f0fdf4">
      <span style="width:12px;height:12px;background:#16a34a;border-radius:50%;display:block"></span>
    </div>
    <div>
      <div class="stat-val" style="color:<?= $active > 0 ? 'var(--success)' : 'var(--gray-900)' ?>">
        <?= $active ?>
      </div>
      <div class="stat-label">Active</div>

      <?php if ($susp > 0): ?>
        <div class="stat-sub" style="color:var(--danger)">
          <?= $susp ?> suspended
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon" style="background:#f5f3ff">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2">
        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
      </svg>
    </div>
    <div>
      <div class="stat-val">
        <?= $used_gb ?>
        <span style="font-size:14px;font-weight:500;color:var(--gray-400)">GB</span>
      </div>
      <div class="stat-label">Total Used</div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon" style="background:#fff7ed">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
    </div>
    <div>
      <div class="stat-val">
        <?= $curr_sym ?><?= number_format($monthly, $currency==='INR'?0:2) ?>
      </div>
      <div class="stat-label">Est. Monthly</div>
    </div>
  </div>

</div>

      <div class="page-topstrip">
        <div>
          <div class="page-heading"><svg enable-background="new 0 0 72 72" style="vertical-align:middle;width:25px" version="1.1" viewBox="0 0 72 72" xml:space="preserve" xmlns="http://www.w3.org/2000/svg"><path d="m40 1.5c-16 0-29 3.6-29 8v0.4l0.8 7c0.8-0.7 1.4-1.1 1.5-1.2-2 1.6-14 11.6-11.2 26.6 0.9 4.8 3.6 8 7.7 9 0.8 0.2 1.7 0.3 2.6 0.3 1 0 2-0.1 3.1-0.3l-0.5-4.9c-1.5 0.3-3 0.4-4.3 0.1-2.3-0.5-3.6-2.2-4.2-5.2-1.5-8 2.6-14.5 5.9-18.2l2.6 23.4c7.1-1.7 16-9.5 22-17.9-0.2-0.4-0.2-0.9-0.2-1.4 0-2.3 1.7-4.1 3.7-4.1s3.7 1.8 3.7 4.1c0 2.1-1.5 3.9-3.4 4.1-7.5 10.7-17.3 18.4-25.3 20.1l0.4 3.4v0.4c0.7 4.1 11 7.4 23.7 7.4 13.1 0 23.7-3.5 23.7-7.8l5.6-44.6c0-0.2 0.1-0.4 0.1-0.5 0-4.6-13-8.2-29-8.2zm0 13.8c-12.1 0-21.8-2.1-21.8-4.7s9.8-4.7 21.8-4.7c12.1 0 21.8 2.1 21.8 4.7 0 2.5-9.8 4.7-21.8 4.7z" fill="#C3C8CD"/></svg> My Buckets <span style="font-size:14px;font-weight:500;color:var(--gray-400)">(<?= $total ?>)</span></div>
          <div style="font-size:13px;color:var(--gray-500);margin-top:2px">
            <?php if ($active > 0): ?><span style="color:var(--success);font-weight:700"><?= $active ?> active</span><?php endif; ?>
            <?php if ($susp > 0): ?> · <span style="color:var(--danger);font-weight:700"><?= $susp ?> suspended</span><?php endif; ?>
          </div>
        </div>
      </div>

      <?php if (empty($buckets)): ?>
      <div class="empty-wrap">
        <div style="width:52px;height:52px;background:linear-gradient(135deg,#ede9fe,#ddd6fe);border-radius:13px;display:flex;align-items:center;justify-content:center;margin:0 auto 16px">
          <svg version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" viewBox="0 0 72 72" style="enable-background:new 0 0 72 72;width:30px" xml:space="preserve"><style type="text/css">.st0{fill:var(--primary)}</style><path class="st0" d="M40,1.5c-16,0-29,3.6-29,8c0,0.1,0,0.3,0,0.4l0,0l0.8,7c0.8-0.7,1.4-1.1,1.5-1.2l0,0
	C11.3,17.3-0.7,27.3,2.1,42.3c0.9,4.8,3.6,8,7.7,9c0.8,0.2,1.7,0.3,2.6,0.3c1,0,2-0.1,3.1-0.3L15,46.4c-1.5,0.3-3,0.4-4.3,0.1
	c-2.3-0.5-3.6-2.2-4.2-5.2c-1.5-8,2.6-14.5,5.9-18.2L15,46.5c7.1-1.7,16-9.5,22-17.9c-0.2-0.4-0.2-0.9-0.2-1.4
	c0-2.3,1.7-4.1,3.7-4.1s3.7,1.8,3.7,4.1c0,2.1-1.5,3.9-3.4,4.1c-7.5,10.7-17.3,18.4-25.3,20.1l0.4,3.4v0.4l0,0
	c0.7,4.1,11,7.4,23.7,7.4c13.1,0,23.7-3.5,23.7-7.8l5.6-44.6c0-0.2,0.1-0.4,0.1-0.5C69,5.1,56,1.5,40,1.5z M40,15.3
	c-12.1,0-21.8-2.1-21.8-4.7c0-2.6,9.8-4.7,21.8-4.7c12.1,0,21.8,2.1,21.8,4.7C61.8,13.1,52,15.3,40,15.3z"></path></svg>
        </div>
        <div class="empty-h">No buckets yet</div>
        <div class="empty-p">Create your first bucket and start storing files with S3-compatible APIs. Works with boto3, aws-cli, rclone and more.</div>
        <button style="padding: 12px 18px;" type="button" data-loading="Creating..." class="btn-deploy" onclick="window.location.href='<?= BASE_URL ?>/storage/create.php'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Create First Bucket</button>
      </div>

      <?php else: ?>
      <div class="bkt-cards">
        <?php foreach ($buckets as $b):
          $pct     = storage_pct((float)$b['used_gb'], (int)$b['plan_gb']);
          $bar_cls = $pct >= 90 ? 'crit' : ($pct >= 70 ? 'warn' : '');
        ?>
        <div class="bkt-card">

          <div class="bkt-icon">
            <svg version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" viewBox="0 0 72 72" style="enable-background:new 0 0 72 72;width:30px" xml:space="preserve"><style type="text/css">.st0{fill:var(--primary)}</style><path class="st0" d="M40,1.5c-16,0-29,3.6-29,8c0,0.1,0,0.3,0,0.4l0,0l0.8,7c0.8-0.7,1.4-1.1,1.5-1.2l0,0
	C11.3,17.3-0.7,27.3,2.1,42.3c0.9,4.8,3.6,8,7.7,9c0.8,0.2,1.7,0.3,2.6,0.3c1,0,2-0.1,3.1-0.3L15,46.4c-1.5,0.3-3,0.4-4.3,0.1
	c-2.3-0.5-3.6-2.2-4.2-5.2c-1.5-8,2.6-14.5,5.9-18.2L15,46.5c7.1-1.7,16-9.5,22-17.9c-0.2-0.4-0.2-0.9-0.2-1.4
	c0-2.3,1.7-4.1,3.7-4.1s3.7,1.8,3.7,4.1c0,2.1-1.5,3.9-3.4,4.1c-7.5,10.7-17.3,18.4-25.3,20.1l0.4,3.4v0.4l0,0
	c0.7,4.1,11,7.4,23.7,7.4c13.1,0,23.7-3.5,23.7-7.8l5.6-44.6c0-0.2,0.1-0.4,0.1-0.5C69,5.1,56,1.5,40,1.5z M40,15.3
	c-12.1,0-21.8-2.1-21.8-4.7c0-2.6,9.8-4.7,21.8-4.7c12.1,0,21.8,2.1,21.8,4.7C61.8,13.1,52,15.3,40,15.3z"></path></svg>
          </div>

          <div class="bkt-info">
            <div class="bkt-info-name">
              <a href="<?= BASE_URL ?>/storage/view.php?id=<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></a>
              <?= sbucket($b['status']) ?>
            </div>
            <div class="bkt-info-sub">
              <span><?= htmlspecialchars($b['plan_name']) ?></span>
              <span class="info-sep">·</span>
              <span><?= htmlspecialchars($b['region']) ?></span>
              <span class="info-sep">·</span>
              <span><?= date('d M Y', strtotime($b['created_at'])) ?></span>
              <span class="badge badge-purple"><?= $b['plan_gb'] ?> GB</span>
            </div>
          </div>

          <!--div class="bkt-specs">
            <span class="spec-chip"><?= $b['plan_gb'] ?> GB</span>
            <span class="spec-chip"><?= $b['plan_bw'] >= 1000 ? round($b['plan_bw']/1000,1).'TB' : $b['plan_bw'].'GB' ?> BW</span>
          </div-->

          <div class="bkt-usage">
            <div class="bkt-usage-top">
              <span><?= number_format((float)$b['used_gb'],1) ?> GB</span>
              <span><?= $pct ?>%</span>
            </div>
            <div class="bar-track">
              <div class="bar-fill <?= $bar_cls ?>" style="width:<?= $pct ?>%"></div>
            </div>
          </div>

          <div class="bkt-price">
            <div class="bkt-price-main"><?= $curr_sym . number_format((float)$b['price_hourly'],4) ?>/hr</div>
            <div class="bkt-price-sub">~<?= $curr_sym . number_format((float)$b['price_monthly'],$currency==='INR'?0:2) ?>/mo</div>
          </div>

          <div class="bkt-actions">
            <a class="act-btn" title="S3 Credentials" href="<?= BASE_URL ?>/storage/credentials.php?id=<?= $b['id'] ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </a>
            <a class="act-btn" title="Browse files" href="<?= BASE_URL ?>/storage/browser.php?id=<?= $b['id'] ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
            </a>
            <a class="act-btn" title="Manage" href="<?= BASE_URL ?>/storage/view.php?id=<?= $b['id'] ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            </a>
          </div>

        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>
<script>
    function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
    document.addEventListener('click', e => {
    const btn = e.target.closest('[data-loading]');

    if (!btn) return;

    btn.disabled = true;

    btn.innerHTML = `
        <span class="spinner"></span>
        ${btn.dataset.loading || 'Loading...'}
    `;
});
</script>
</body>
</html>
