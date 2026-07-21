<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/os_icons.php';
require_once __DIR__ . '/includes/servers.php';
require_login();

$user     = current_user();
$app_name = APP_NAME;
$currency = strtoupper($user['currency'] ?? 'USD');
$curr_sym = user_currency_symbol($currency);
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$uname    = htmlspecialchars($user['username']);
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$balance  = (float)$user['wallet_balance'];
$csrf     = csrf_token();

// ── Welcome confetti (from onboarding) ─────────────────────
$show_confetti = !empty($_SESSION['show_confetti']);
if ($show_confetti) unset($_SESSION['show_confetti']);

// ── Stats from DB — this user only ─────────────────────────
$uid = (int)$user['id'];

// ── Active announcements for this user ──────────────────────
$active_announcements = [];
try {
    $user_currency = strtoupper($user['currency'] ?? 'INR');
    $target_check  = $user_currency === 'USD' ? "'all','usd'" : "'all','inr'";

    $ann_st = db()->prepare(
        "SELECT a.* FROM announcements a
         WHERE a.is_active = 1
           AND a.target IN ({$target_check})
           AND NOW() BETWEEN a.start_at AND a.end_at
           AND a.id NOT IN (
               SELECT announcement_id FROM announcement_dismissals WHERE user_id=?
           )
         ORDER BY a.created_at DESC
         LIMIT 3"
    );
    $ann_st->execute([$uid]);
    $active_announcements = $ann_st->fetchAll() ?: [];
} catch (Throwable $e) {}

$total_servers = (int)db()->prepare(
    'SELECT COUNT(*) FROM servers WHERE user_id=? AND deleted_at IS NULL'
)->execute([$uid]) ? db()->query("SELECT COUNT(*) FROM servers WHERE user_id=$uid AND deleted_at IS NULL")->fetchColumn() : 0;

$running_count = (int)db()->query("SELECT COUNT(*) FROM servers WHERE user_id=$uid AND status='running' AND deleted_at IS NULL")->fetchColumn();
$stopped_count = (int)db()->query("SELECT COUNT(*) FROM servers WHERE user_id=$uid AND status='stopped' AND deleted_at IS NULL")->fetchColumn();
$suspended_count = (int)db()->query("SELECT COUNT(*) FROM servers WHERE user_id=$uid AND status='suspended' AND deleted_at IS NULL")->fetchColumn();

// Hourly burn rate
$hourly_burn = (float)db()->query("SELECT COALESCE(SUM(price_hourly),0) FROM servers WHERE user_id=$uid AND status='running' AND deleted_at IS NULL")->fetchColumn();

// ── Dashboard servers (first page, 10 per page) ─────────────
$per_page = 10;
$page     = max(1, (int)($_GET['p'] ?? 1));
$offset   = ($page - 1) * $per_page;

$st = db()->prepare(
    'SELECT * FROM servers WHERE user_id=? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT ? OFFSET ?'
);
$st->execute([$uid, $per_page, $offset]);
$servers = $st->fetchAll();

$total_pages = (int)ceil($total_servers / $per_page);

// ── Recent transactions (last 5) ────────────────────────────
$recent_tx = db()->prepare(
    'SELECT * FROM transactions WHERE user_id=? ORDER BY created_at DESC LIMIT 5'
);
$recent_tx->execute([$uid]);
$recent_tx = $recent_tx->fetchAll();

// ── Low balance warning ──────────────────────────────────────
$low_warn = (float)get_setting('low_balance_warn', '5');
$is_low   = $balance < $low_warn;

// ── OS icon helper delegated to includes/os_icons.php ────────
function os_icon(string $os_label, int $size = 20): string {
    return os_icon_img($os_label, $size, 'display:block');
}

// Status badge helper
function status_badge(string $status): string {
    return match($status) {
        'running'      => '<span class="badge badge-green"><span class="sdot sdot-green"></span>Running</span>',
        'stopped'      => '<span class="badge badge-gray"><span class="sdot sdot-gray"></span>Stopped</span>',
        'provisioning' => '<span class="badge badge-blue"><span class="sdot sdot-blue sdot-pulse"></span>Provisioning</span>',
        'starting'     => '<span class="badge badge-blue"><span class="sdot sdot-blue sdot-pulse"></span>Starting</span>',
        'stopping'     => '<span class="badge badge-yellow"><span class="sdot sdot-yellow sdot-pulse"></span>Stopping</span>',
        'suspended'    => '<span class="badge badge-red"><span class="sdot sdot-red"></span>Suspended</span>',
        'rebuilding'   => '<span class="badge badge-yellow"><span class="sdot sdot-yellow sdot-pulse"></span>Rebuilding</span>',
        'error'        => '<span class="badge badge-red"><span class="sdot sdot-red"></span>Error</span>',
        default        => '<span class="badge badge-gray">Unknown</span>',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Dashboard — <?= $app_name ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    /* Status dots */
    .sdot{display:inline-block;width:7px;height:7px;border-radius:50%;flex-shrink:0;margin-right:5px}
    .sdot-green{background:#16a34a}.sdot-gray{background:#9ca3af}
    .sdot-blue{background:#2563eb}.sdot-yellow{background:#d97706}.sdot-red{background:#dc2626}
    .sdot-pulse{animation:sdp 1.4s ease-in-out infinite}
    @keyframes sdp{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.4)}}

    /* Stat cards */
    .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
    .stat-card{background:white;border:1px solid var(--border);border-radius:13px;padding:18px 20px;display:flex;align-items:center;gap:14px;transition:box-shadow .16s,transform .16s;cursor:default}
    .stat-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.07);transform:translateY(-1px)}
    .stat-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .stat-val{font-size:26px;font-weight:900;color:var(--gray-900);letter-spacing:-1px;line-height:1}
    .stat-label{font-size:12px;color:var(--gray-500);font-weight:500;margin-top:3px}
    .stat-sub{font-size:11px;color:var(--gray-400);margin-top:1px}

    /* Two column layout */
    .dash-grid{display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start}

    /* Server table card */
    .card{background:white;border:1px solid var(--border);border-radius:13px;overflow:hidden;margin-bottom:18px}
    .card-header{padding:13px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px}
    .card-title{font-size:14px;font-weight:800;color:var(--gray-900);display:flex;align-items:center;gap:8px}

    /* Server rows */
    .srv-list{list-style:none}
    .srv-item{display:flex;align-items:center;gap:14px;padding:13px 18px;border-bottom:1px solid var(--gray-100);transition:background .12s}
    .srv-item:last-child{border:none}
    .srv-item:hover{background:var(--gray-50)}
    .srv-os-icon{width:36px;height:36px;border-radius:9px;background:var(--gray-100);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden}
    .srv-name{font-size:13.5px;font-weight:800;color:var(--gray-900);display:flex;align-items:center;gap:7px;flex-wrap:wrap;line-height:1.3}
    .srv-meta{font-size:11.5px;color:var(--gray-400);margin-top:3px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
    .srv-meta-ip{font-family:'JetBrains Mono',monospace;font-size:11.5px;color:var(--gray-600)}
    .srv-specs{display:flex;gap:6px;flex-wrap:wrap;margin-left:auto;flex-shrink:0}
    .spec-chip{display:inline-flex;align-items:center;padding:2px 7px;background:var(--gray-100);border-radius:5px;font-size:11.5px;font-weight:600;color:var(--gray-600);font-family:'JetBrains Mono',monospace;white-space:nowrap}
    .srv-price{font-size:12px;font-weight:700;color:var(--gray-700);white-space:nowrap;text-align:right;min-width:72px}
    .srv-price-sub{font-size:10.5px;color:var(--gray-400)}
    .srv-region{display:flex;align-items:center;gap:5px;font-size:12px;color:var(--gray-500);white-space:nowrap}
    .srv-region img{border-radius:2px;box-shadow:0 0 0 1px rgba(0,0,0,.08)}
    .srv-actions{display:flex;gap:5px;flex-shrink:0}
    .srv-btn{width:30px;height:30px;border-radius:7px;border:1px solid var(--border);background:white;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--gray-500);transition:all .13s;text-decoration:none;flex-shrink:0}
    .srv-btn:hover{background:var(--gray-100);color:var(--gray-900)}
    .srv-btn.del:hover{background:var(--danger-bg);color:var(--danger);border-color:#fca5a5}
    .srv-btn svg{width:13px;height:13px;pointer-events:none}

    /* Pagination */
    .pagination{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;border-top:1px solid var(--border)}
    .page-info{font-size:12px;color:var(--gray-400)}
    .page-btns{display:flex;gap:4px}
    .page-btn{width:32px;height:32px;border-radius:7px;border:1px solid var(--border);background:white;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:12.5px;font-weight:700;color:var(--gray-600);transition:all .13s;font-family:inherit}
    .page-btn:hover{background:var(--gray-100)}
    .page-btn.active{background:var(--primary);color:white;border-color:var(--primary)}
    .page-btn:disabled{opacity:.4;cursor:not-allowed}

    /* Empty */
    .empty-state{padding:48px 24px;text-align:center}
    .empty-icon{width:52px;height:52px;border-radius:14px;background:var(--gray-100);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;margin:0 auto 14px}
    .empty-title{font-size:15px;font-weight:800;color:var(--gray-800);margin-bottom:5px}
    .empty-sub{font-size:13px;color:var(--gray-500);margin-bottom:18px;line-height:1.6}

    /* Deploy button */
    .btn-deploy{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:9px;font-size:13px;font-weight:700;background:var(--primary);color:white;border:none;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .15s;box-shadow:0 10px 15px -3px #0000001a, 0 4px 6px -4px #0000001a;}
    .btn-deploy:hover{background:var(--primary-hover);transform:translateY(-1px)}
    .btn-deploy svg{width:14px;height:14px}

    /* Wallet card */
    .wallet-bal{font-size:30px;font-weight:900;color:var(--gray-900);letter-spacing:-1.5px;line-height:1}
    .wallet-currency{font-size:12px;color:var(--gray-400);font-weight:600;margin-bottom:12px}

    /* Activity feed */
    .tx-row{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--gray-100)}
    .tx-row:last-child{border:none}
    .tx-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
    .tx-amount{margin-left:auto;font-family:'JetBrains Mono',monospace;font-weight:800;font-size:12.5px}

    /* Quick links */
    .quick-link{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:8px;text-decoration:none;color:var(--gray-700);transition:background .13s;font-size:13px;font-weight:600}
    .quick-link:hover{background:var(--gray-50)}
    .quick-link-icon{width:30px;height:30px;border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .quick-link-icon svg{width:14px;height:14px}

    /* Skeleton loader */
    .skeleton{background:linear-gradient(90deg,var(--gray-100) 25%,var(--gray-50) 50%,var(--gray-100) 75%);background-size:200% 100%;animation:shimmer 1.5s infinite;border-radius:6px;display:inline-block}
    @keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

    /* Low balance alert */
    .low-balance-bar{background:#fef2f2;border:1.5px solid #fca5a5;border-radius:10px;padding:11px 16px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;gap:12px}

    /* Burn rate chip */
    .burn-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;background:var(--gray-100);border-radius:99px;font-size:11.5px;font-weight:600;color:var(--gray-600)}

    /* Responsive */
    @media(max-width:1100px){
      .stats-grid{grid-template-columns:repeat(2,1fr)}
      .dash-grid{grid-template-columns:1fr}
    }
    @media(max-width:600px){
      .stats-grid{grid-template-columns:repeat(2,1fr)}
      .srv-specs,.srv-region{display:none}
    }
    @media (max-width: 768px) {
  .sagargreat {
    display: inherit !important;
  }
}
  </style>
</head>
<body>
<div class="app-shell">

  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <div class="main-content" style="margin-left:260px;min-height:100vh;background:var(--gray-50)">

    <!-- Mobile bar -->
    <div class="mobile-bar">
      <button class="ham-btn" onclick="toggleSidebar()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <span style="font-weight:800;font-size:15px;color:var(--gray-900)"><?= $app_name ?></span>
      <a href="<?= BASE_URL ?>/servers/create.php" class="btn-deploy" style="margin-left:auto;padding:7px 12px;font-size:12px">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Deploy
      </a>
    </div>

    <!-- Topbar -->
    <div class="topbar">
      <span class="topbar-title">Dashboard</span>
      <div style="display:flex;align-items:center;gap:10px;margin-left:auto">
        <?php if ($hourly_burn > 0): ?>
        <span class="burn-chip">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          <?= $curr_sym . number_format($hourly_burn, 4) ?>/hr
        </span>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/billing.php" style="display:inline-flex;align-items:center;gap:6px;padding:7px 12px;background:var(--gray-100);border:1px solid var(--border);border-radius:8px;font-size:13px;font-weight:700;color:var(--gray-700);text-decoration:none;transition:background .13s" onmouseover="this.style.background='var(--gray-200)'" onmouseout="this.style.background='var(--gray-100)'">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
          <?= $curr_sym . number_format($balance, 2) ?>
        </a>
        <a href="<?= BASE_URL ?>/servers/create.php" class="btn-deploy">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Deploy Server
        </a>
      </div>
    </div>

    <!-- Content -->
    <div style="padding:24px">

      <?php if ($show_confetti): ?>
      <div style="background: linear-gradient(292deg, #171717ab, #171717bd, #171717);border-radius:13px;padding:16px 20px;display:flex;align-items:center;gap:14px;margin-bottom:20px;color:white">
        <div style="font-size:26px;flex-shrink:0"><img src="https://em-content.zobj.net/source/apple/453/party-popper_1f389.png" style="width: 30px;"></div>
        <div>
          <div style="font-size:14px;font-weight:800;margin-bottom:2px">Welcome to <?= $app_name ?>, <?= $fname ?>!</div>
          <div style="font-size:13px;opacity:.8">Your account is ready. Add funds and deploy your first server below.</div>
        </div>
        <a href="<?= BASE_URL ?>/servers/create.php" style="margin-left:auto;padding:8px 16px;background:rgba(255,255,255,.18);border:1.5px solid rgba(255,255,255,.3);border-radius:8px;font-size:13px;font-weight:700;color:white;text-decoration:none;white-space:nowrap">Deploy →</a>
      </div>
      <?php endif; ?>

      <?php if ($is_low): ?>
      <div class="low-balance-bar">
        <div style="display:flex;align-items:center;gap:9px">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--danger)" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          <span style="font-size:13px;font-weight:700;color:var(--danger)">Low wallet balance — <?= $curr_sym . number_format($balance, 2) ?> remaining.<?= $running_count > 0 ? ' Running servers may get suspended.' : '' ?></span>
        </div>
        <a href="<?= BASE_URL ?>/billing.php?action=topup" style="padding:7px 14px;background:var(--danger);color:white;border-radius:7px;font-size:12.5px;font-weight:700;text-decoration:none;white-space:nowrap">Add Funds</a>
      </div>
      <?php endif; ?>

      <!-- Stat cards -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon" style="background:#eff6ff">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
          </div>
          <div>
            <div class="stat-val"><?= $total_servers ?></div>
            <div class="stat-label">Total Servers</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#f0fdf4">
            <span style="width:12px;height:12px;background:#16a34a;border-radius:50%;display:block"></span>
          </div>
          <div>
            <div class="stat-val" style="color:#16a34a"><?= $running_count ?></div>
            <div class="stat-label">Running</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:var(--gray-100)">
            <span style="width:12px;height:12px;background:var(--gray-400);border-radius:50%;display:block"></span>
          </div>
          <div>
            <div class="stat-val" style="color:var(--gray-400)"><?= $stopped_count ?></div>
            <div class="stat-label">Stopped</div>
            <?php if ($suspended_count): ?>
            <div class="stat-sub" style="color:var(--danger)"><?= $suspended_count ?> suspended</div>
            <?php endif; ?>
          </div>
        </div>
        <div class="stat-card" style="cursor:pointer" onclick="window.location='<?= BASE_URL ?>/billing.php'">
          <div class="stat-icon" style="background:#fff7ed">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
          </div>
          <div>
            <div class="stat-val"><?= $curr_sym . number_format($balance) ?></div>
            <div class="stat-label">Wallet Balance</div>
            <div class="stat-sub"><?= $currency ?> · Tap to top up</div>
          </div>
        </div>
      </div>

      <!-- Main grid -->
      <div class="dash-grid">

        <!-- LEFT: Server list -->
        <div>
          <div class="card">
            <div class="card-header">
              <div class="card-title">
                My Servers
                <span class="badge badge-blue" style="font-size:11px"><?= $total_servers ?></span>
              </div>
              <a href="<?= BASE_URL ?>/servers.php" style="font-size:12.5px;color:var(--primary);font-weight:700;text-decoration:none">View all →</a>
            </div>

            <?php if (empty($servers)): ?>
            <div class="empty-state">
              <div class="empty-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gray-400)" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
              </div>
              <div class="empty-title">No servers yet</div>
              <div class="empty-sub">Deploy your first server in under 60 seconds.</div>
              <a href="<?= BASE_URL ?>/servers/create.php" class="btn-deploy">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Deploy First Server
              </a>
            </div>
            <?php else: ?>
            <ul class="srv-list" id="srv-list">
              <?php foreach ($servers as $s): ?>
              <li class="srv-item" data-id="<?= $s['id'] ?>">

                <!-- OS icon -->
                <div class="srv-os-icon">
                  <?= os_icon_img($s['os_label'] ?? '', 22, 'display:block') ?>
                </div>

                <!-- Name + meta -->
                <div style="min-width:0;flex:1">
                  <div class="srv-name">
                    <a href="<?= BASE_URL ?>/servers/view.php?id=<?= $s['id'] ?>" style="color:inherit;text-decoration:none;hover:underline"><?= htmlspecialchars($s['name']) ?></a>
                    <?= status_badge($s['status']) ?>
                  </div>
                  <div class="srv-meta">
                    <?php if ($s['ipv4']): ?>
                    <span class="srv-meta-ip"><?= htmlspecialchars($s['ipv4']) ?></span>
                    <span style="color:var(--gray-300)">·</span>
                    <?php endif; ?>
                    <span><?= htmlspecialchars($s['os_label'] ?: '—') ?></span>
                  </div>
                </div>

                <!-- Specs -->
                <div class="srv-specs">
                  <span class="spec-chip"><?= $s['vcpu'] ?>vCPU</span>
                  <span class="spec-chip"><?= (int)$s['ram_gb'] ?>GB</span>
                  <span class="spec-chip"><?= (int)$s['disk_gb'] ?>GB</span>
                </div>

                <!-- Region flag -->
                <div class="srv-region">
                  <img src="https://flagcdn.com/w20/<?= htmlspecialchars($s['region_flag'] ?? 'de') ?>.png"
                       srcset="https://flagcdn.com/w40/<?= htmlspecialchars($s['region_flag'] ?? 'de') ?>.png 2x"
                       width="18" height="13" alt="" onerror="this.style.display='none'" style="border-radius:2px">
                </div>

                <!-- Price -->
                <div class="srv-price">
                  <?= $curr_sym . number_format((float)$s['price_hourly'], 4) ?>/hr
                  <div class="srv-price-sub">~<?= $curr_sym . number_format((float)$s['price_monthly'], 2) ?>/mo</div>
                </div>

                <!-- Actions -->
                <div class="srv-actions">
                  <?php if ($s['status'] === 'running'): ?>
                  <button class="srv-btn" title="Reboot" onclick="doAction(<?= $s['id'] ?>,'reboot','<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.86"/></svg>
                  </button>
                  <button class="srv-btn" title="Power Off" onclick="doAction(<?= $s['id'] ?>,'stop','<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                  </button>
                  <?php elseif (in_array($s['status'], ['stopped','suspended'])): ?>
                  <button class="srv-btn" title="Power On" onclick="doAction(<?= $s['id'] ?>,'start','<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                  </button>
                  <?php endif; ?>
                  <a class="srv-btn" title="Manage" href="<?= BASE_URL ?>/servers/view.php?id=<?= $s['id'] ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                  </a>
                  <button class="srv-btn del" title="Delete" onclick="doDelete(<?= $s['id'] ?>,'<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6M9 6V4h6v2"/></svg>
                  </button>
                </div>
              </li>
              <?php endforeach; ?>
            </ul>

            <?php if ($total_pages > 1): ?>
            <div class="pagination">
              <span class="page-info">Showing <?= ($offset + 1) ?>–<?= min($offset + $per_page, $total_servers) ?> of <?= $total_servers ?></span>
              <div class="page-btns" id="page-btns">
                <button class="page-btn" id="prev-btn" <?= $page <= 1 ? 'disabled' : '' ?> onclick="loadPage(<?= $page - 1 ?>)">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                </button>
                <?php for ($p = 1; $p <= min($total_pages, 7); $p++): ?>
                <button class="page-btn <?= $p === $page ? 'active' : '' ?>" onclick="loadPage(<?= $p ?>)"><?= $p ?></button>
                <?php endfor; ?>
                <?php if ($total_pages > 7): ?>
                <span style="align-self:center;color:var(--gray-400);font-size:12px;padding:0 4px">…</span>
                <button class="page-btn <?= $page === $total_pages ? 'active' : '' ?>" onclick="loadPage(<?= $total_pages ?>)"><?= $total_pages ?></button>
                <?php endif; ?>
                <button class="page-btn" id="next-btn" <?= $page >= $total_pages ? 'disabled' : '' ?> onclick="loadPage(<?= $page + 1 ?>)">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
              </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- RIGHT: Wallet + Activity + Quick links -->
        <div class="sagargreat">

          <!-- Wallet card -->
          <div class="card" style="margin-bottom:16px">
            <div class="card-header">
              <span class="card-title">Wallet</span>
              <a href="<?= BASE_URL ?>/billing.php" style="font-size:12px;color:var(--primary);font-weight:700;text-decoration:none">History →</a>
            </div>
            <div style="padding:16px 18px">
              <div class="wallet-bal"><?= $curr_sym . number_format($balance, 2) ?></div>
              <div class="wallet-currency"><?= $currency ?> · Prepaid</div>
              <?php
              $pct   = min(100, ($balance / 50) * 100);
              $fcls  = $pct < 20 ? '#dc2626' : ($pct < 50 ? '#d97706' : '#2563eb');
              ?>
              <div style="height:4px;background:var(--gray-100);border-radius:99px;overflow:hidden;margin-bottom:5px">
                <div style="height:100%;width:<?= $pct ?>%;background:<?= $fcls ?>;border-radius:99px;transition:width .4s ease"></div>
              </div>
              <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--gray-400);margin-bottom:14px">
                <span><?= $is_low ? '⚠ Low' : 'Sufficient' ?></span>
                <span><?= $curr_sym ?>50 recommended</span>
              </div>
              <a href="<?= BASE_URL ?>/billing.php?action=topup" style="display:flex;align-items:center;box-shadow:0 10px 15px -3px #0000001a, 0 4px 6px -4px #0000001a;;justify-content:center;gap:6px;padding:9px;background:var(--primary);color:white;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;transition:background .14s" onmouseover="this.style.background='var(--primary-hover)'" onmouseout="this.style.background='var(--primary)'">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Funds
              </a>
            </div>
          </div>

          <!-- Recent activity -->
          <div class="card" style="margin-bottom:16px">
            <div class="card-header">
              <span class="card-title">Recent Activity</span>
              <a href="<?= BASE_URL ?>/billing.php?tab=transactions" style="font-size:12px;color:var(--primary);font-weight:700;text-decoration:none">All →</a>
            </div>
            <div style="padding:0 18px">
              <?php if (empty($recent_tx)): ?>
              <p style="padding:20px 0;text-align:center;color:var(--gray-400);font-size:13px">No transactions yet.</p>
              <?php else: ?>
              <?php
              $tx_icons = ['topup'=>'credit','server_billing'=>'debit','refund'=>'credit','adjustment'=>'credit'];
              foreach ($recent_tx as $tx):
                $is_credit = $tx['type'] === 'credit';
              ?>
              <div class="tx-row">
                <div class="tx-icon" style="background:<?= $is_credit?'#f0fdf4':'#fef2f2' ?>">
                  <?php if ($is_credit): ?>
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                  <?php else: ?>
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--danger)" stroke-width="2.5"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/></svg>
                  <?php endif; ?>
                </div>
                <div style="flex:1;min-width:0">
                  <div style="font-size:12.5px;font-weight:600;color:var(--gray-800);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($tx['description'] ?? ucfirst($tx['ref_type'])) ?></div>
                  <div style="font-size:11px;color:var(--gray-400)"><?= date('d M, H:i', strtotime($tx['created_at'])) ?></div>
                </div>
                <div class="tx-amount" style="color:<?= $is_credit?'#16a34a':'var(--danger)' ?>">
                  <?= ($is_credit?'+':'−') . $curr_sym . number_format((float)$tx['amount'], $currency==='INR'?2:4) ?>
                </div>
              </div>
              <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

          <!-- Quick actions -->
          <div class="card">
            <div class="card-header"><span class="card-title">Quick Actions</span></div>
            <div style="padding:6px 8px">
              <?php foreach ([
                ['/servers/create.php','Deploy New Server','#eff6ff','#2563eb','<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>'],
                ['/ssh-keys.php','SSH Keys','#f0fdf4','#16a34a','<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>'],
                ['/firewalls.php','Firewall Rules','#fff7ed','#d97706','<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>'],
                ['/billing.php?action=topup','Add Wallet Balance','#faf5ff','#9333ea','<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>'],
              ] as [$href, $label, $bg, $color, $path]): ?>
              <a href="<?= BASE_URL . $href ?>" class="quick-link">
                <div class="quick-link-icon" style="background:<?= $bg ?>">
                  <svg viewBox="0 0 24 24" fill="none" stroke="<?= $color ?>" stroke-width="2"><?= $path ?></svg>
                </div>
                <?= $label ?>
              </a>
              <?php endforeach; ?>
            </div>
          </div>

        </div><!-- /right -->
      </div><!-- /dash-grid -->
    </div><!-- /content -->
  </div><!-- /main-content -->
</div><!-- /app-shell -->

<div class="overlay" id="overlay" onclick="toggleSidebar()"></div>
<div class="toast-wrap" style="position:fixed;bottom:20px;right:20px;z-index:999;display:flex;flex-direction:column;gap:8px"></div>

<script>
var CSRF = '<?= csrf_token() ?>';

function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('open');
}

// ── AJAX pagination — no full page reload ───────────────────
function loadPage(page) {
  var list = document.getElementById('srv-list');
  if (!list) return;

  // Skeleton while loading
  list.style.opacity = '.5';

  fetch('<?= BASE_URL ?>/api/servers-page.php?p=' + page + '&csrf=<?= csrf_token() ?>', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(d => {
    if (!d.ok) { toast(d.error || 'Failed to load.', 'err'); list.style.opacity = '1'; return; }
    list.innerHTML = d.html;
    list.style.opacity = '1';
    // Update pagination info
    if (d.pagination) {
      document.querySelector('.pagination').outerHTML = d.pagination;
    }
    // Scroll servers into view smoothly
    list.closest('.card').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  })
  .catch(() => { list.style.opacity = '1'; toast('Failed to load page.', 'err'); });
}

// ── Server actions ──────────────────────────────────────────
function doAction(id, action, name) {
  var msgs = { start: 'Power on "'+name+'"?', stop: 'Shut down "'+name+'"?', reboot: 'Reboot "'+name+'"?' };
  if (!confirm(msgs[action] || 'Confirm?')) return;

  fetch('<?= BASE_URL ?>/api/server-action.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id, action, csrf: CSRF })
  })
  .then(r => r.json())
  .then(d => {
    toast(d.ok ? 'Action sent — refreshing shortly.' : (d.error || 'Failed.'), d.ok ? 'ok' : 'err');
    if (d.ok) setTimeout(() => location.reload(), 3000);
  })
  .catch(() => toast('Request failed.', 'err'));
}

function doDelete(id, name) {
  if (!confirm('DELETE "' + name + '"?\n\nAll data will be permanently lost. This cannot be undone.')) return;
  fetch('<?= BASE_URL ?>/api/server-action.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id, action: 'delete', csrf: CSRF })
  })
  .then(r => r.json())
  .then(d => {
    toast(d.ok ? 'Server deleted.' : (d.error || 'Failed.'), d.ok ? 'ok' : 'err');
    if (d.ok) setTimeout(() => location.reload(), 2000);
  })
  .catch(() => toast('Request failed.', 'err'));
}

// ── Toast ───────────────────────────────────────────────────
function toast(msg, type) {
  var w = document.querySelector('.toast-wrap');
  var t = document.createElement('div');
  t.className = 'toast toast-' + (type === 'ok' ? 'ok' : 'err');
  t.textContent = msg;
  w.appendChild(t);
  setTimeout(() => t.remove(), 4000);
}
</script>

<?php if (!empty($active_announcements)): ?>
<!-- ═══ ANNOUNCEMENT MODAL ════════════════════════════════════
     Auto-shows active announcements. Multi-slide if multiple.
     Dismisses via AJAX; respects dismiss_once flag.
═════════════════════════════════════════════════════════════ -->
<style>
#ann-overlay{position:fixed;inset:0;z-index:9000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(0,0,0,.5);backdrop-filter:blur(6px);animation:annFadeIn .25s ease}
@keyframes annFadeIn{from{opacity:0}to{opacity:1}}
#ann-box{background:white;border-radius:20px;width:100%;max-width:500px;overflow:hidden;box-shadow:0 32px 80px rgba(0,0,0,.25);animation:annSlideUp .3s cubic-bezier(.16,1,.3,1);position:relative}
@keyframes annSlideUp{from{opacity:0;transform:translateY(24px) scale(.97)}to{opacity:1;transform:none}}
[data-theme="dark"] #ann-box{background:#1a1a2e;border:1px solid rgba(255,255,255,.08)}
.ann-img{width:100%;height:330px;object-fit:cover;display:block}
.ann-body{padding:22px 24px}
.ann-badge{display:inline-block;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px}
.ann-title{font-size:20px;font-weight:900;color:#0f172a;margin-bottom:9px;line-height:1.2;letter-spacing:-.3px}
[data-theme="dark"] .ann-title{color:#f1f5f9}
.ann-desc{font-size:14px;color:#475569;line-height:1.75;margin-bottom:16px}
[data-theme="dark"] .ann-desc{color:#94a3b8}
.ann-coupon{display:flex;align-items:center;gap:10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:11px 14px;margin-bottom:14px;cursor:pointer;transition:background .13s}
.ann-coupon:hover{background:#dcfce7}
[data-theme="dark"] .ann-coupon{background:#052e16;border-color:#166534}
.ann-coupon-code{font-family:monospace;font-size:16px;font-weight:800;color:#15803d;letter-spacing:.08em}
[data-theme="dark"] .ann-coupon-code{color:#4ade80}
.ann-coupon-copy{margin-left:auto;padding:4px 11px;background:#16a34a;color:white;border:none;border-radius:6px;font-size:11.5px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .13s;flex-shrink:0}
.ann-coupon-copy:hover{background:#15803d}
.ann-cta{display:inline-flex;align-items:center;gap:7px;padding:11px 22px;background:var(--primary,#e0121b);color:white;border-radius:10px;font-size:14px;font-weight:700;text-decoration:none;transition:all .15s;border:none;cursor:pointer;font-family:inherit}
.ann-cta:hover{opacity:.9;transform:translateY(-1px)}
.ann-foot{display:flex;align-items:center;justify-content:space-between;padding:12px 24px;border-top:1px solid #f1f5f9;background:#fafbfd}
[data-theme="dark"] .ann-foot{background:#14141f;border-color:rgba(255,255,255,.06)}
.ann-dismiss{font-size:12.5px;color:#94a3b8;cursor:pointer;font-family:inherit;background:none;border:none;padding:4px 0;transition:color .13s}
.ann-dismiss:hover{color:#475569}
/* Dots indicator */
.ann-dots{display:flex;gap:5px;justify-content:center;margin-bottom:0}
.ann-dot{width:6px;height:6px;border-radius:50%;background:#e2e8f0;transition:all .2s;cursor:pointer}
.ann-dot.active{background:var(--primary,#e0121b);width:18px;border-radius:3px}
/* Close button */
.ann-close{position:absolute;top:12px;right:12px;width:30px;height:30px;border-radius:50%;background:rgba(0,0,0,.35);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;color:white;transition:background .13s;z-index:1}
.ann-close:hover{background:rgba(0,0,0,.55)}
/* Countdown bar */
.ann-timer{height:3px;background:linear-gradient(90deg,var(--primary,#e0121b),var(--primary-hover,#b91c1c));width:100%;transform-origin:left;animation:annTimer var(--timer-duration,8s) linear forwards}
@keyframes annTimer{from{transform:scaleX(1)}to{transform:scaleX(0)}}
@media(max-width:520px){#ann-box{border-radius:16px}.ann-img{height:auto;width:auto;}.ann-body{padding:18px 18px}.ann-foot{padding:10px 18px}}
</style>

<div id="ann-overlay" style="display:none">
  <div id="ann-box">
    <button class="ann-close" id="ann-close-btn" onclick="closeAnnouncement()">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
    <div id="ann-timer-bar"><div class="ann-timer" id="ann-timer" style="--timer-duration:8s"></div></div>
    <div id="ann-slides"><!-- filled by JS --></div>
    <div class="ann-foot">
      <div class="ann-dots" id="ann-dots"></div>
      <button class="ann-dismiss" id="ann-dismiss-btn">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px"><polyline points="20 6 9 17 4 12"/></svg>
        Don't show again
      </button>
    </div>
  </div>
</div>

<script>
(function(){
  var ANN_DATA = <?= json_encode(array_values($active_announcements)) ?>;
  if (!ANN_DATA.length) return;

  var cur = 0;
  var dismissed = false;
  var BASE = '<?= BASE_URL ?>';
  var CSRF = '<?= $csrf ?>';

  function render(idx) {
    var a = ANN_DATA[idx];
    var html = '';

    if (a.image_url) {
  html += '<img class="ann-img" src="'+esc(a.image_url)+'" alt="" onerror="this.style.display=&quot;none&quot;">';
}

    html += '<div class="ann-body">';
    if (a.badge_text) {
      var bc = a.badge_color || '#2563eb';
      html += '<div class="ann-badge" style="background:'+bc+'22;color:'+bc+'">'+esc(a.badge_text)+'</div>';
    }
    html += '<div class="ann-title">'+esc(a.title)+'</div>';
    html += '<div class="ann-desc">'+a.description+'</div>';

    if (a.coupon_code) {
  html += '<div class="ann-coupon" onclick="copyCoupon(\''+esc(a.coupon_code)+'\')" title="Click to copy">';
  
  html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#15803d" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>';

  html += '<div><div style="font-size:11px;color:#16a34a;font-weight:600;margin-bottom:2px">Promo Code</div><div class="ann-coupon-code">'+esc(a.coupon_code)+'</div></div>';

  html += '<button class="ann-coupon-copy" id="copy-btn-'+a.id+'" onclick="event.stopPropagation();copyCoupon(\''+esc(a.coupon_code)+'\',\'copy-btn-'+a.id+'\')">Copy</button>';

  html += '</div>';
}

    if (a.cta_label && a.cta_url) {
      html += '<a href="'+esc(a.cta_url)+'" class="ann-cta">'+esc(a.cta_label)+' <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg></a>';
    }
    html += '</div>';

    document.getElementById('ann-slides').innerHTML = html;

    // Dots
    var dotsEl = document.getElementById('ann-dots');
    dotsEl.innerHTML = '';
    ANN_DATA.forEach(function(_, i) {
      var d = document.createElement('div');
      d.className = 'ann-dot' + (i === idx ? ' active' : '');
      d.onclick = function(){ goTo(i); };
      dotsEl.appendChild(d);
    });

    // Reset timer
    var timer = document.getElementById('ann-timer');
    var dur = ANN_DATA.length > 1 ? 6 : 8;
    timer.style.animation = 'none';
    timer.offsetHeight; // reflow
    timer.style.setProperty('--timer-duration', dur+'s');
    timer.style.animation = '';

    // Dismiss label
    document.getElementById('ann-dismiss-btn').textContent = a.dismiss_once == 1
      ? "Don't show again"
      : 'Close';
  }

  function goTo(idx) {
    cur = idx;
    render(cur);
    clearTimeout(window._ann_timer);
    if (ANN_DATA.length > 1) window._ann_timer = setTimeout(nextAnn, 6000);
  }

  function nextAnn() {
    if (ANN_DATA.length < 2) return;
    goTo((cur + 1) % ANN_DATA.length);
  }

  window.closeAnnouncement = function() {
    document.getElementById('ann-overlay').style.display = 'none';
    document.body.style.overflow = '';
    clearTimeout(window._ann_timer);
  };

  window.copyCoupon = function(code, btnId) {
    navigator.clipboard.writeText(code).then(function() {
      if (btnId) {
        var b = document.getElementById(btnId);
        if (b) { b.textContent = '✓ Copied!'; setTimeout(function(){ b.textContent='Copy'; },2000); }
      }
    }).catch(function() {
      // fallback
      var ta = document.createElement('textarea');
      ta.value = code; document.body.appendChild(ta); ta.select();
      document.execCommand('copy'); document.body.removeChild(ta);
    });
  };

  document.getElementById('ann-dismiss-btn').addEventListener('click', function() {
    var a = ANN_DATA[cur];
    closeAnnouncement();

    // AJAX dismiss
    if (a.dismiss_once == 1) {
      fetch(BASE + '/api/dismiss-announcement.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: a.id, csrf: CSRF })
      }).catch(function(){});
    }
  });

  function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // Show after 600ms
  setTimeout(function() {
    document.getElementById('ann-overlay').style.display = 'flex';
    document.body.style.overflow = 'hidden';
    render(0);
    if (ANN_DATA.length > 1) window._ann_timer = setTimeout(nextAnn, 6000);
  }, 600);

  // Close on overlay click
  document.getElementById('ann-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeAnnouncement();
  });
})();
</script>
<?php endif; ?>
</body>
</html>
