<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/servers.php';
require_once __DIR__ . '/includes/currency.php';
require_login();

$user      = current_user();
$currency  = strtoupper($user['currency'] ?? 'INR');
$curr_sym  = currency_symbol($currency);
$app_name  = APP_NAME;
$avatar    = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$fname     = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$uname     = htmlspecialchars($user['username']);
$balance   = (float)$user['wallet_balance'];
$csrf      = csrf_token();

// Admin can view any user's history
$view_uid = (int)($user['id']);
if ($user['role'] === 'admin' && !empty($_GET['uid'])) {
    $view_uid = (int)$_GET['uid'];
}
$is_own = ($view_uid === (int)$user['id']);
$viewed_user = $is_own ? $user : (function() use ($view_uid) {
    $st = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
    $st->execute([$view_uid]);
    return $st->fetch() ?: null;
})();
if (!$viewed_user) { header('Location: ' . BASE_URL . '/dashboard.php'); exit; }

$filter = $_GET['filter'] ?? 'all';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// ── Fetch all server actions for this user ──────────────────
$actions = db()->prepare(
    'SELECT sa.*, s.name AS server_name, s.plan_slug, s.region_label, s.os_label, s.ipv4, s.deleted_at AS server_deleted
     FROM server_actions sa
     LEFT JOIN servers s ON s.id = sa.server_id
     WHERE sa.user_id = ?
     ORDER BY sa.created_at DESC
     LIMIT ? OFFSET ?'
);
$actions->execute([$view_uid, $perPage, $offset]);
$all_actions = $actions->fetchAll() ?: [];
$total = db()->prepare('SELECT COUNT(*) FROM server_actions WHERE user_id=?');
$total->execute([$view_uid]);
$totalRows = $total->fetchColumn();

$totalPages = ceil($totalRows / $perPage);

// ── Action config ───────────────────────────────────────────
$action_cfg = [
    'create'               => ['🚀', 'Server Created',          '#f0fdf4', '#16a34a', 'server'],
    'delete'               => ['🗑️', 'Server Deleted',          '#fef2f2', '#dc2626', 'server'],
    'start'                => ['▶️',  'Server Started',          '#eff6ff', '#2563eb', 'power'],
    'stop'                 => ['⏹️', 'Server Stopped',           '#f9fafb', '#6b7280', 'power'],
    'shutdown'             => ['⏻',  'Server Shutdown',          '#f9fafb', '#6b7280', 'power'],
    'reboot'               => ['🔄', 'Server Rebooted',          '#fff7ed', '#d97706', 'power'],
    'reset'                => ['⚡', 'Hard Reset',               '#fff7ed', '#d97706', 'power'],
    'rebuild'              => ['🔨', 'Server Rebuilt',           '#faf5ff', '#7c3aed', 'server'],
    'reset_root_password'  => ['🔑', 'Password Reset',           '#fafafa', '#374151', 'security'],
    'enable_rescue'        => ['🛟', 'Rescue Mode Enabled',      '#fff7ed', '#d97706', 'security'],
    'enable_rescue_cycle'  => ['🛟', 'Rescue + Reboot',          '#fff7ed', '#d97706', 'security'],
    'create_snapshot'      => ['📸', 'Snapshot Created',         '#fef9c3', '#854d0e', 'snapshot'],
    'delete_snapshot'      => ['🗑️', 'Snapshot Deleted',         '#fef2f2', '#dc2626', 'snapshot'],
    'create_volume'        => ['💾', 'Volume Created',           '#eff6ff', '#2563eb', 'volume'],
    'attach_volume'        => ['🔗', 'Volume Attached',          '#eff6ff', '#2563eb', 'volume'],
    'detach_volume'        => ['🔌', 'Volume Detached',          '#f9fafb', '#6b7280', 'volume'],
    'delete_volume'        => ['🗑️', 'Volume Deleted',           '#fef2f2', '#dc2626', 'volume'],
    'apply_firewall'       => ['🛡️', 'Firewall Applied',         '#fff7ed', '#d97706', 'network'],
    'remove_firewall'      => ['🚫', 'Firewall Removed',         '#fef2f2', '#dc2626', 'network'],
    'create_floating_ip'   => ['🌐', 'Floating IP Created',      '#f0fdf4', '#16a34a', 'network'],
    'assign_floating_ip'   => ['🌐', 'Floating IP Assigned',     '#f0fdf4', '#16a34a', 'network'],
    'unassign_floating_ip' => ['🌐', 'Floating IP Unassigned',   '#f9fafb', '#6b7280', 'network'],
    'delete_floating_ip'   => ['🌐', 'Floating IP Deleted',      '#fef2f2', '#dc2626', 'network'],
    'create_network'       => ['🔒', 'Private Network Created',  '#f0fdf4', '#16a34a', 'network'],
    'attach_network'       => ['🔒', 'Network Attached',         '#f0fdf4', '#16a34a', 'network'],
    'detach_network'       => ['🔌', 'Network Detached',         '#f9fafb', '#6b7280', 'network'],
    'get_console'          => ['🖥️', 'Console Accessed',         '#f8fafc', '#64748b', 'security'],
    'list_firewalls'       => ['👁️', 'Firewalls Viewed',         '#f8fafc', '#64748b', 'network'],
    'list_volumes'         => ['👁️', 'Volumes Viewed',           '#f8fafc', '#64748b', 'volume'],
    'list_snapshots'       => ['👁️', 'Snapshots Viewed',         '#f8fafc', '#64748b', 'snapshot'],
    'list_floating_ips'    => ['👁️', 'Floating IPs Viewed',      '#f8fafc', '#64748b', 'network'],
    'list_networks'        => ['👁️', 'Networks Viewed',          '#f8fafc', '#64748b', 'network'],
];

$categories = [
    'all'      => ['All Activity',    '📋'],
    'server'   => ['Server',          '🖥️'],
    'power'    => ['Power',           '⚡'],
    'snapshot' => ['Snapshots',       '📸'],
    'volume'   => ['Volumes',         '💾'],
    'network'  => ['Networking',      '🌐'],
    'security' => ['Security',        '🔐'],
];

// Filter
$filtered = array_filter($all_actions, function($a) use ($filter, $action_cfg) {
    if ($filter === 'all') return true;
    $cat = $action_cfg[$a['action']]['4'] ?? ($action_cfg[$a['action']][4] ?? 'other');
    // Access by index
    $cfg = $action_cfg[$a['action']] ?? null;
    if (!$cfg) return $filter === 'other';
    return ($cfg[4] ?? 'other') === $filter;
});

// Stats
$stats = ['total' => count($all_actions), 'success' => 0, 'error' => 0];
foreach ($all_actions as $a) {
    if ($a['status'] === 'success') $stats['success']++;
    elseif ($a['status'] === 'error') $stats['error']++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Activity History — <?= $app_name ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    .main-content{background:#f0f2f5}
    .page-wrap{max-width:1000px;padding:24px 28px}
    .page-head{margin-bottom:22px}
    .page-title{font-size:22px;font-weight:900;color:#0f172a;letter-spacing:-.5px}
    .page-sub{font-size:13.5px;color:#64748b;margin-top:3px}

    /* Stats bar */
    .stats-bar{display:flex;gap:14px;margin-bottom:22px;flex-wrap:wrap}
    .stat-chip{background:white;border:1px solid #e2e8f0;border-radius:11px;padding:13px 18px;flex:1;min-width:130px}
    .stat-v{font-size:22px;font-weight:900;color:#0f172a;letter-spacing:-.5px}
    .stat-l{font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-top:2px}

    /* Filter tabs */
    .filter-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:20px}
    .ftab{padding:7px 14px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;color:#64748b;background:white;border:1.5px solid #e2e8f0;transition:all .13s;display:flex;align-items:center;gap:6px}
    .ftab:hover{border-color:#94a3b8;color:#1e293b}
    .ftab.active{background:#0f172a;color:white;border-color:#0f172a}
    .ftab .cnt{font-size:11px;background:rgba(0,0,0,.1);padding:1px 6px;border-radius:99px}
    .ftab.active .cnt{background:rgba(255,255,255,.2)}

    /* Timeline */
    .timeline{display:flex;flex-direction:column;gap:0}
    .tl-date-label{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.7px;color:#94a3b8;padding:18px 0 8px;display:flex;align-items:center;gap:10px}
    .tl-date-label::after{content:'';flex:1;height:1px;background:#e2e8f0}

    .tl-item{display:flex;gap:14px;padding:13px 0;border-bottom:1px solid #f1f5f9}
    .tl-item:last-child{border:none}
    .tl-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
    .tl-body{flex:1;min-width:0}
    .tl-top{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
    .tl-action{font-size:13.5px;font-weight:700;color:#1e293b}
    .tl-status{font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px}
    .tl-status.ok{background:#f0fdf4;color:#16a34a}
    .tl-status.err{background:#fef2f2;color:#dc2626}
    .tl-status.pend{background:#fff7ed;color:#d97706}
    .tl-server{font-size:12.5px;color:#64748b;margin-top:4px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
    .tl-server-name{font-weight:700;color:#475569;font-family:'JetBrains Mono',monospace;font-size:12px}
    .tl-server-del{font-size:11px;color:#94a3b8;background:#f1f5f9;padding:1px 7px;border-radius:99px}
    .tl-time{font-size:12px;color:#94a3b8;flex-shrink:0;white-space:nowrap}

    .empty-state{text-align:center;padding:60px 20px;color:#94a3b8}
    .empty-state .icon{font-size:48px;margin-bottom:12px}
    .empty-state .msg{font-size:15px;font-weight:600}

    @media(max-width:640px){.page-wrap{padding:16px}.stats-bar{flex-direction:column}}
  </style>
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <div class="main-content" style="margin-left:260px">
    <div class="mobile-bar">
      <button class="ham-btn" onclick="document.getElementById('sidebar').classList.toggle('open');document.getElementById('overlay').classList.toggle('open')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <span style="font-weight:800;font-size:14px">Activity History</span>
    </div>

    <div class="page-wrap">
      <div class="page-head">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
          <div>
            <div class="page-title">
              <?php if (!$is_own): ?>
              <span style="font-size:14px;color:#94a3b8;font-weight:600;display:block;margin-bottom:2px">Viewing history for</span>
              <?php endif; ?>
              Activity History
              <?php if (!$is_own): ?>
              <span style="font-size:14px;color:#2563eb;margin-left:8px">@<?= htmlspecialchars($viewed_user['username']) ?></span>
              <?php endif; ?>
            </div>
            <div class="page-sub">Complete log of all server operations and events</div>
          </div>
          <?php if (!$is_own && $user['role'] === 'admin'): ?>
          <a href="<?= BASE_URL ?>/admin/?tab=users" class="btn btn-ghost btn-sm" style="margin-left:auto">← Back to Users</a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Stats -->
      <div class="stats-bar">
        <div class="stat-chip">
          <div class="stat-v"><?= $stats['total'] ?></div>
          <div class="stat-l">Total Events</div>
        </div>
        <div class="stat-chip">
          <div class="stat-v" style="color:#16a34a"><?= $stats['success'] ?></div>
          <div class="stat-l">Successful</div>
        </div>
        <div class="stat-chip">
          <div class="stat-v" style="color:#dc2626"><?= $stats['error'] ?></div>
          <div class="stat-l">Errors</div>
        </div>
        <div class="stat-chip">
          <?php
          // Count unique servers
          $unique_srv = count(array_unique(array_column($all_actions, 'server_id')));
          ?>
          <div class="stat-v"><?= $unique_srv ?></div>
          <div class="stat-l">Servers Involved</div>
        </div>
      </div>

      <!-- Filter tabs -->
      <div class="filter-tabs">
        <?php foreach ($categories as $key => [$label, $icon]):
          $cnt = $key === 'all' ? count($all_actions) : count(array_filter($all_actions, function($a) use ($key, $action_cfg) {
            $cfg = $action_cfg[$a['action']] ?? null;
            return $cfg ? (($cfg[4] ?? 'other') === $key) : false;
          }));
          if ($cnt === 0 && $key !== 'all') continue;
        ?>
        <a href="?filter=<?= $key ?><?= !$is_own ? '&uid='.$view_uid : '' ?>"
           class="ftab <?= $filter === $key ? 'active' : '' ?>">
          <?= $icon ?> <?= $label ?> <span class="cnt"><?= $cnt ?></span>
        </a>
        <?php endforeach; ?>
      </div>

      <!-- Timeline -->
      <?php if (empty($filtered)): ?>
      <div class="empty-state">
        <div class="icon">📋</div>
        <div class="msg">No activity in this category</div>
      </div>
      <?php else:
        $prev_date = null;
      ?>
      <div style="background:white;border:1px solid #e2e8f0;border-radius:14px;padding:4px 18px">
        <div class="timeline">
        <?php foreach ($filtered as $a):
          $cfg = $action_cfg[$a['action']] ?? ['⚙️', ucfirst(str_replace('_',' ',$a['action'])), '#f8fafc', '#64748b', 'other'];
          [$icon, $label, $bg, $color, $cat] = $cfg;

          $date = date('d M Y', strtotime($a['created_at']));
          if ($date !== $prev_date):
            $prev_date = $date;
        ?>
        <div class="tl-date-label"><?= $date ?></div>
        <?php endif; ?>

        <div class="tl-item">
          <div class="tl-icon" style="background:<?= $bg ?>">
            <span style="color:<?= $color ?>"><?= $icon ?></span>
          </div>
          <div class="tl-body">
            <div class="tl-top">
              <span class="tl-action"><?= $label ?> <span class="tl-server-del"><?php if (!empty($a['ipv4'])): ?>(<?= htmlspecialchars($a['ipv4']) ?>)<?php endif; ?></span></span>
              <span class="tl-status <?= $a['status']==='success'?'ok':($a['status']==='error'?'err':'pend') ?>">
                <?= $a['status'] ?>
              </span>
            </div>
            <div class="tl-server">
              <?php if ($a['server_name']): ?>
              <span class="tl-server-name"><?= htmlspecialchars($a['server_name']) ?></span>
              <?php if ($a['server_deleted']): ?>
              <span class="tl-server-del">deleted</span>
              <?php endif; ?>
              <?php if ($a['plan_slug']): ?>
              <span style="font-size:11.5px;color:#94a3b8"><?= strtoupper(htmlspecialchars($a['plan_slug'])) ?></span>
              <?php endif; ?>
              <?php if ($a['region_label']): ?>
              <span style="font-size:11.5px;color:#94a3b8"><?= htmlspecialchars($a['region_label']) ?></span>
              <?php endif; ?>
              <?php else: ?>
              <span style="color:#94a3b8;font-size:12px">Server #<?= $a['server_id'] ?></span>
              <?php endif; ?>
              <?php if ($a['note']): ?>
              <span style="font-size:11.5px;color:#64748b;font-style:italic"><?= htmlspecialchars($a['note']) ?></span>
              <?php endif; ?>
            </div>
          </div>
          <div class="tl-time"><?= date('H:i:s', strtotime($a['created_at'])) ?></div>
        </div>
        <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($totalPages > 1): ?>
<div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap">
  <?php for ($i = 1; $i <= $totalPages; $i++): ?>
    <a href="?page=<?= $i ?>&filter=<?= $filter ?><?= !$is_own ? '&uid='.$view_uid : '' ?>"
       style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;
              <?= $i==$page?'background:#0f172a;color:#fff':'' ?>">
      <?= $i ?>
    </a>
  <?php endfor; ?>
</div>
<?php endif; ?>

    </div>
  </div>
</div>
<div class="overlay" id="overlay" onclick="document.getElementById('sidebar').classList.remove('open');this.classList.remove('open')"></div>
</body>
</html>
