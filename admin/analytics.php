<?php
/**
 * admin/analytics.php — Enterprise Analytics & Security Log Panel
 * Access: Admin only
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/security.php';
require_admin();

$user     = current_user();
$app_name = APP_NAME;
$csrf     = csrf_token();
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$fname    = htmlspecialchars($user['account_type'] === 'organization' ? ($user['company_name'] ?: $user['username']) : ($user['full_name'] ?: $user['username']));

$tab = $_GET['tab'] ?? 'overview';

// ── AJAX handlers ────────────────────────────────────────────
if (!empty($_GET['ajax'])) {
    header('Content-Type: application/json');

    // Ensure tables exist
    sec_install_tables();

    $ajax = $_GET['ajax'];
    $pdo  = db();

    // ── Overview stats ───────────────────────────────────────
    if ($ajax === 'overview_stats') {
        $period = (int)($_GET['period'] ?? 24); // hours
        $since  = "NOW() - INTERVAL $period HOUR";

        $stats = [];

        // Activity counts
        $stats['total_actions']   = (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE created_at > $since")->fetchColumn();
        $stats['unique_ips']      = (int)$pdo->query("SELECT COUNT(DISTINCT ip) FROM activity_log WHERE created_at > $since")->fetchColumn();
        $stats['unique_users']    = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM activity_log WHERE created_at > $since AND user_id IS NOT NULL")->fetchColumn();

        // Error counts
        $stats['total_errors']    = (int)$pdo->query("SELECT COUNT(*) FROM error_log WHERE created_at > $since")->fetchColumn();
        $stats['critical_events'] = (int)$pdo->query("SELECT COUNT(*) FROM sec_event_log WHERE severity='critical' AND created_at > $since")->fetchColumn();
        $stats['blocked_attacks'] = (int)$pdo->query("SELECT COUNT(*) FROM sec_event_log WHERE created_at > $since")->fetchColumn();
        $stats['brute_force']     = (int)$pdo->query("SELECT COUNT(*) FROM sec_event_log WHERE event_type='brute_force_login' AND created_at > $since")->fetchColumn();
        $stats['rate_limited']    = (int)$pdo->query("SELECT COUNT(*) FROM sec_event_log WHERE event_type='api_rate_limit_exceeded' AND created_at > $since")->fetchColumn();

        // Banned IPs
        $stats['active_bans'] = (int)$pdo->query("SELECT COUNT(DISTINCT ip) FROM sec_login_attempts WHERE ban_until > NOW()")->fetchColumn();

        echo json_encode(['ok' => true, 'data' => $stats]);
        exit;
    }

    // ── Activity log (paginated) ─────────────────────────────
    if ($ajax === 'activity_log') {
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $limit   = 50;
        $offset  = ($page - 1) * $limit;
        $search  = trim($_GET['search'] ?? '');
        $user_id = (int)($_GET['user_id'] ?? 0);
        $period  = (int)($_GET['period'] ?? 24);
        $since   = "NOW() - INTERVAL $period HOUR";

        $where = ["a.created_at > $since"];
        $params = [];

        if ($search) {
            $where[] = "(a.action LIKE ? OR a.ip LIKE ? OR a.url LIKE ? OR u.username LIKE ?)";
            $s = "%$search%";
            $params = array_merge($params, [$s, $s, $s, $s]);
        }
        if ($user_id) {
            $where[] = "a.user_id = ?";
            $params[] = $user_id;
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = $pdo->prepare("SELECT COUNT(*) FROM activity_log a LEFT JOIN users u ON u.id=a.user_id $where_sql");
        $total->execute($params);
        $total = (int)$total->fetchColumn();

        $rows = $pdo->prepare("
            SELECT a.*, u.username, u.email
            FROM activity_log a
            LEFT JOIN users u ON u.id = a.user_id
            $where_sql
            ORDER BY a.created_at DESC
            LIMIT $limit OFFSET $offset
        ");
        $rows->execute($params);
        $rows = $rows->fetchAll();

        echo json_encode(['ok' => true, 'data' => $rows, 'total' => $total, 'pages' => ceil($total / $limit)]);
        exit;
    }

    // ── Error log ─────────────────────────────────────────────
    if ($ajax === 'error_log') {
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 50;
        $offset = ($page - 1) * $limit;
        $search = trim($_GET['search'] ?? '');
        $type   = trim($_GET['error_type'] ?? '');
        $period = (int)($_GET['period'] ?? 24);
        $since  = "NOW() - INTERVAL $period HOUR";

        $where  = ["e.created_at > $since"];
        $params = [];

        if ($search) {
            $where[] = "(e.url LIKE ? OR e.ip LIKE ? OR e.error_type LIKE ? OR JSON_EXTRACT(e.payload,'$.message') LIKE ?)";
            $s = "%$search%";
            $params = array_merge($params, [$s, $s, $s, $s]);
        }
        if ($type) {
            $where[] = "e.error_type = ?";
            $params[] = $type;
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = $pdo->prepare("SELECT COUNT(*) FROM error_log e $where_sql");
        $total->execute($params);
        $total = (int)$total->fetchColumn();

        $rows = $pdo->prepare("
            SELECT e.*, u.username
            FROM error_log e
            LEFT JOIN users u ON u.id = e.user_id
            $where_sql
            ORDER BY e.created_at DESC
            LIMIT $limit OFFSET $offset
        ");
        $rows->execute($params);
        $rows = $rows->fetchAll();

        echo json_encode(['ok' => true, 'data' => $rows, 'total' => $total, 'pages' => ceil($total / $limit)]);
        exit;
    }

    // ── Security events ───────────────────────────────────────
    if ($ajax === 'security_log') {
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $limit    = 50;
        $offset   = ($page - 1) * $limit;
        $search   = trim($_GET['search'] ?? '');
        $severity = trim($_GET['severity'] ?? '');
        $type     = trim($_GET['event_type'] ?? '');
        $period   = (int)($_GET['period'] ?? 24);
        $since    = "NOW() - INTERVAL $period HOUR";

        $where  = ["created_at > $since"];
        $params = [];

        if ($search) {
            $where[] = "(ip LIKE ? OR url LIKE ? OR event_type LIKE ?)";
            $s = "%$search%";
            $params = array_merge($params, [$s, $s, $s]);
        }
        if ($severity) { $where[] = "severity = ?"; $params[] = $severity; }
        if ($type)     { $where[] = "event_type = ?"; $params[] = $type; }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = $pdo->prepare("SELECT COUNT(*) FROM sec_event_log $where_sql");
        $total->execute($params);
        $total = (int)$total->fetchColumn();

        $rows = $pdo->prepare("
            SELECT * FROM sec_event_log
            $where_sql
            ORDER BY created_at DESC
            LIMIT $limit OFFSET $offset
        ");
        $rows->execute($params);
        $rows = $rows->fetchAll();

        echo json_encode(['ok' => true, 'data' => $rows, 'total' => $total, 'pages' => ceil($total / $limit)]);
        exit;
    }

    // ── Export CSV ────────────────────────────────────────────
    if ($ajax === 'export') {
        $log_type = $_GET['log_type'] ?? 'activity';
        $period   = (int)($_GET['period'] ?? 24);
        $since    = "NOW() - INTERVAL $period HOUR";

        $filename = $log_type . '_export_' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv');
        header("Content-Disposition: attachment; filename=\"$filename\"");

        $out = fopen('php://output', 'w');

        if ($log_type === 'activity') {
            fputcsv($out, ['ID', 'User', 'IP', 'Action', 'URL', 'Browser', 'Timestamp']);
            $rows = $pdo->query("SELECT a.id, u.username, a.ip, a.action, a.url, a.user_agent, a.created_at FROM activity_log a LEFT JOIN users u ON u.id=a.user_id WHERE a.created_at > $since ORDER BY a.created_at DESC LIMIT 10000");
            foreach ($rows as $r) {
                fputcsv($out, [$r['id'], $r['username'] ?? 'Guest', $r['ip'], $r['action'], $r['url'], $r['user_agent'], $r['created_at']]);
            }
        } elseif ($log_type === 'errors') {
            fputcsv($out, ['ID', 'Type', 'User', 'IP', 'URL', 'Message', 'Timestamp']);
            $rows = $pdo->query("SELECT e.id, e.error_type, u.username, e.ip, e.url, e.payload, e.created_at FROM error_log e LEFT JOIN users u ON u.id=e.user_id WHERE e.created_at > $since ORDER BY e.created_at DESC LIMIT 10000");
            foreach ($rows as $r) {
                $payload = json_decode($r['payload'] ?? '{}', true);
                fputcsv($out, [$r['id'], $r['error_type'], $r['username'] ?? 'Guest', $r['ip'], $r['url'], $payload['message'] ?? '', $r['created_at']]);
            }
        } elseif ($log_type === 'security') {
            fputcsv($out, ['ID', 'Type', 'Severity', 'IP', 'URL', 'Details', 'Timestamp']);
            $rows = $pdo->query("SELECT * FROM sec_event_log WHERE created_at > $since ORDER BY created_at DESC LIMIT 10000");
            foreach ($rows as $r) {
                fputcsv($out, [$r['id'], $r['event_type'], $r['severity'], $r['ip'], $r['url'], $r['payload'], $r['created_at']]);
            }
        }

        fclose($out);
        exit;
    }

    // ── Live activity stream (last 5 rows newer than given ID) ─
    if ($ajax === 'live_tail') {
        $last_id = (int)($_GET['last_id'] ?? 0);
        $rows = $pdo->prepare("SELECT a.*, u.username FROM activity_log a LEFT JOIN users u ON u.id=a.user_id WHERE a.id > ? ORDER BY a.id DESC LIMIT 20");
        $rows->execute([$last_id]);
        $rows = $rows->fetchAll();
        $max_id = $rows ? (int)$rows[0]['id'] : $last_id;
        echo json_encode(['ok' => true, 'data' => array_reverse($rows), 'max_id' => $max_id]);
        exit;
    }

    // ── Top IPs / Actions chart ───────────────────────────────
    if ($ajax === 'chart_data') {
        $period = (int)($_GET['period'] ?? 24);
        $since  = "NOW() - INTERVAL $period HOUR";
        $type   = $_GET['type'] ?? 'top_actions';

        if ($type === 'top_actions') {
            $rows = $pdo->query("SELECT action, COUNT(*) as cnt FROM activity_log WHERE created_at > $since GROUP BY action ORDER BY cnt DESC LIMIT 10")->fetchAll();
            echo json_encode(['ok' => true, 'data' => $rows]);
        } elseif ($type === 'top_ips') {
            $rows = $pdo->query("SELECT ip, COUNT(*) as cnt FROM activity_log WHERE created_at > $since GROUP BY ip ORDER BY cnt DESC LIMIT 10")->fetchAll();
            echo json_encode(['ok' => true, 'data' => $rows]);
        } elseif ($type === 'hourly') {
            $rows = $pdo->query("SELECT DATE_FORMAT(created_at,'%Y-%m-%d %H:00') as hour, COUNT(*) as cnt FROM activity_log WHERE created_at > $since GROUP BY hour ORDER BY hour ASC")->fetchAll();
            echo json_encode(['ok' => true, 'data' => $rows]);
        } elseif ($type === 'error_types') {
            $rows = $pdo->query("SELECT error_type, COUNT(*) as cnt FROM error_log WHERE created_at > $since GROUP BY error_type ORDER BY cnt DESC LIMIT 10")->fetchAll();
            echo json_encode(['ok' => true, 'data' => $rows]);
        } elseif ($type === 'security_types') {
            $rows = $pdo->query("SELECT event_type, severity, COUNT(*) as cnt FROM sec_event_log WHERE created_at > $since GROUP BY event_type, severity ORDER BY cnt DESC LIMIT 10")->fetchAll();
            echo json_encode(['ok' => true, 'data' => $rows]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown ajax action']);
    exit;
}

// Ensure tables exist
sec_install_tables();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Analytics — <?= htmlspecialchars($app_name) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/admin/admin.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<?php inject_global_head(); ?>
<style>
/* ── Analytics Panel Styles ─────────────────────────── */
.an-shell { display:flex; min-height:100vh; background:#f1f5f9; }
.an-main  { flex:1; margin-left:232px; padding:0; }
@media(max-width:768px){ .an-main { margin-left:0; } }

.an-topbar {
  background:#fff; border-bottom:1px solid #e2e8f0;
  padding:16px 28px; display:flex; align-items:center;
  justify-content:space-between; position:sticky; top:0; z-index:50;
}
.an-topbar h1 { font-size:20px; font-weight:700; color:#0f172a; margin:0; }

/* Tabs */
.an-tabs { display:flex; gap:4px; padding:20px 28px 0; flex-wrap:wrap; }
.an-tab  {
  padding:8px 18px; border-radius:8px 8px 0 0; font-size:14px; font-weight:500;
  cursor:pointer; border:none; background:transparent; color:#64748b;
  border-bottom:3px solid transparent; transition:all .2s;
}
.an-tab.active { background:#fff; color:#6366f1; border-bottom-color:#6366f1; }
.an-tab:hover:not(.active){ background:#e2e8f0; }

/* Content area */
.an-content { padding:20px 28px 40px; }

/* Stat cards */
.stat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:16px; margin-bottom:24px; }
.stat-card {
  background:#fff; border-radius:12px; padding:20px; border:1px solid #e2e8f0;
  box-shadow:0 1px 3px rgba(0,0,0,.04);
}
.stat-card .label { font-size:12px; font-weight:600; color:#94a3b8; text-transform:uppercase; letter-spacing:.05em; }
.stat-card .val   { font-size:28px; font-weight:700; color:#0f172a; margin:4px 0 0; }
.stat-card .badge { font-size:11px; padding:2px 8px; border-radius:20px; display:inline-block; margin-top:6px; }
.badge-red   { background:#fee2e2; color:#b91c1c; }
.badge-yellow{ background:#fef3c7; color:#92400e; }
.badge-green { background:#d1fae5; color:#065f46; }
.badge-blue  { background:#dbeafe; color:#1e40af; }
.badge-purple{ background:#ede9fe; color:#6d28d9; }

/* Table */
.log-table-wrap { background:#fff; border-radius:12px; border:1px solid #e2e8f0; overflow:hidden; }
.log-toolbar {
  padding:14px 18px; border-bottom:1px solid #f1f5f9;
  display:flex; align-items:center; gap:10px; flex-wrap:wrap;
}
.log-toolbar input, .log-toolbar select {
  border:1px solid #e2e8f0; border-radius:8px; padding:6px 12px;
  font-size:13px; outline:none; color:#0f172a;
}
.log-toolbar input:focus, .log-toolbar select:focus { border-color:#6366f1; }
.log-toolbar button {
  padding:6px 14px; border-radius:8px; border:none; cursor:pointer;
  font-size:13px; font-weight:500;
}
.btn-primary { background:#6366f1; color:#fff; }
.btn-outline { background:#fff; border:1px solid #e2e8f0 !important; color:#64748b; }
.btn-green   { background:#10b981; color:#fff; }
.btn-red     { background:#ef4444; color:#fff; }

table { width:100%; border-collapse:collapse; font-size:13px; }
thead th { background:#f8fafc; padding:10px 14px; text-align:left; font-weight:600; color:#64748b; border-bottom:1px solid #e2e8f0; white-space:nowrap; }
tbody td { padding:10px 14px; border-bottom:1px solid #f1f5f9; color:#1e293b; vertical-align:top; }
tbody tr:hover td { background:#f8fafc; }
.truncate { max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

.sev-critical { color:#dc2626; font-weight:700; }
.sev-warning  { color:#d97706; font-weight:600; }
.sev-info     { color:#3b82f6; }

.live-dot { width:8px; height:8px; border-radius:50%; background:#10b981; display:inline-block; animation:pulse 1.5s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.3} }

.chart-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:24px; }
@media(max-width:900px){ .chart-row { grid-template-columns:1fr; } }
.chart-box { background:#fff; border-radius:12px; padding:20px; border:1px solid #e2e8f0; }
.chart-box h3 { font-size:14px; font-weight:600; color:#64748b; margin:0 0 16px; }

.period-bar { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:20px; }
.period-btn {
  padding:5px 14px; border-radius:20px; font-size:12px; font-weight:500;
  border:1px solid #e2e8f0; background:#fff; color:#64748b; cursor:pointer; transition:all .15s;
}
.period-btn.active { background:#6366f1; border-color:#6366f1; color:#fff; }

.pagination { display:flex; gap:6px; align-items:center; padding:14px 18px; border-top:1px solid #f1f5f9; }
.pagination button { padding:5px 12px; border-radius:6px; border:1px solid #e2e8f0; background:#fff; color:#64748b; cursor:pointer; font-size:13px; }
.pagination button.active { background:#6366f1; border-color:#6366f1; color:#fff; }
.pagination span { font-size:13px; color:#94a3b8; }

.json-payload { font-family:monospace; font-size:11px; white-space:pre-wrap; word-break:break-all; max-height:80px; overflow:hidden; cursor:pointer; }
.json-payload.expanded { max-height:none; }
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

<!-- Topbar -->
<div class="an-topbar">
  <h1>📊 Analytics &amp; Security</h1>
  <div style="display:flex;align-items:center;gap:12px;">
    <span class="live-dot"></span>
    <span style="font-size:13px;color:#64748b;">Live monitoring active</span>
    <span style="font-size:13px;font-weight:600;color:#0f172a;"><?= $fname ?></span>
  </div>
</div>

<!-- Tabs -->
<div class="an-tabs">
  <button class="an-tab <?= $tab==='overview'?'active':'' ?>" onclick="switchTab('overview')">🏠 Overview</button>
  <button class="an-tab <?= $tab==='activity'?'active':'' ?>" onclick="switchTab('activity')">📋 Activity Log</button>
  <button class="an-tab <?= $tab==='errors'?'active':'' ?>"   onclick="switchTab('errors')">🔴 Error Log</button>
  <button class="an-tab <?= $tab==='security'?'active':'' ?>" onclick="switchTab('security')">🛡️ Security Events</button>
  <button class="an-tab <?= $tab==='live'?'active':'' ?>"     onclick="switchTab('live')">⚡ Live Feed</button>
</div>

<div class="an-content">

<!-- Period selector (global) -->
<div class="period-bar" id="period-bar">
  <span style="font-size:13px;color:#64748b;align-self:center;">Period:</span>
  <?php
  $periods = [1=>'1h',6=>'6h',24=>'24h',48=>'48h',168=>'7d',720=>'30d',2160=>'3mo',4320=>'6mo',8760=>'1yr'];
  foreach ($periods as $h=>$lbl): ?>
    <button class="period-btn <?= $h===24?'active':'' ?>" onclick="setPeriod(<?= $h ?>,this)"><?= $lbl ?></button>
  <?php endforeach; ?>
</div>

<!-- ─── OVERVIEW ─────────────────────────────────────────── -->
<div id="tab-overview" class="tab-panel" style="<?= $tab!=='overview'?'display:none':'' ?>">
  <div class="stat-grid" id="stat-grid">
    <div class="stat-card"><div class="label">Total Actions</div><div class="val" id="s-total-actions">…</div><span class="badge badge-blue">Last 24h</span></div>
    <div class="stat-card"><div class="label">Unique IPs</div><div class="val" id="s-unique-ips">…</div></div>
    <div class="stat-card"><div class="label">Active Users</div><div class="val" id="s-unique-users">…</div></div>
    <div class="stat-card"><div class="label">App Errors</div><div class="val" id="s-total-errors">…</div><span class="badge badge-red">Errors</span></div>
    <div class="stat-card"><div class="label">Blocked Attacks</div><div class="val" id="s-blocked-attacks">…</div><span class="badge badge-red">Security</span></div>
    <div class="stat-card"><div class="label">Brute Force</div><div class="val" id="s-brute-force">…</div><span class="badge badge-yellow">Login</span></div>
    <div class="stat-card"><div class="label">Rate Limited</div><div class="val" id="s-rate-limited">…</div><span class="badge badge-purple">API</span></div>
    <div class="stat-card"><div class="label">Active IP Bans</div><div class="val" id="s-active-bans">…</div><span class="badge badge-red">Banned</span></div>
  </div>

  <div class="chart-row">
    <div class="chart-box"><h3>⏱ Requests Over Time</h3><canvas id="chart-hourly" height="180"></canvas></div>
    <div class="chart-box"><h3>🎯 Top Actions</h3><canvas id="chart-actions" height="180"></canvas></div>
  </div>
  <div class="chart-row">
    <div class="chart-box"><h3>🌐 Top IPs</h3><canvas id="chart-ips" height="180"></canvas></div>
    <div class="chart-box"><h3>🚨 Error Types</h3><canvas id="chart-errors" height="180"></canvas></div>
  </div>
</div>

<!-- ─── ACTIVITY LOG ──────────────────────────────────────── -->
<div id="tab-activity" class="tab-panel" style="<?= $tab!=='activity'?'display:none':'' ?>">
  <div class="log-table-wrap">
    <div class="log-toolbar">
      <input id="act-search" type="text" placeholder="Search action, IP, URL, user…" style="flex:1;min-width:160px;">
      <input id="act-user"   type="number" placeholder="User ID" style="width:100px;">
      <button class="btn-primary" onclick="loadActivity(1)">Search</button>
      <button class="btn-green"   onclick="exportLog('activity')">⬇ Export CSV</button>
    </div>
    <div style="overflow-x:auto;">
    <table>
      <thead><tr>
        <th>#</th><th>Time</th><th>User</th><th>IP</th>
        <th>Action</th><th>URL</th><th>Browser</th>
      </tr></thead>
      <tbody id="act-tbody"><tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;">Loading…</td></tr></tbody>
    </table>
    </div>
    <div class="pagination" id="act-pagination"></div>
  </div>
</div>

<!-- ─── ERROR LOG ─────────────────────────────────────────── -->
<div id="tab-errors" class="tab-panel" style="<?= $tab!=='errors'?'display:none':'' ?>">
  <div class="log-table-wrap">
    <div class="log-toolbar">
      <input id="err-search" type="text" placeholder="Search URL, IP, message…" style="flex:1;min-width:160px;">
      <select id="err-type">
        <option value="">All Types</option>
        <option value="php_error">PHP Error</option>
        <option value="php_exception">PHP Exception</option>
        <option value="api_error">API Error</option>
        <option value="db_error">DB Error</option>
      </select>
      <button class="btn-primary" onclick="loadErrors(1)">Search</button>
      <button class="btn-green"   onclick="exportLog('errors')">⬇ Export CSV</button>
    </div>
    <div style="overflow-x:auto;">
    <table>
      <thead><tr>
        <th>#</th><th>Time</th><th>Type</th><th>User</th>
        <th>IP</th><th>URL</th><th>Message / Payload</th>
      </tr></thead>
      <tbody id="err-tbody"><tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;">Loading…</td></tr></tbody>
    </table>
    </div>
    <div class="pagination" id="err-pagination"></div>
  </div>
</div>

<!-- ─── SECURITY EVENTS ───────────────────────────────────── -->
<div id="tab-security" class="tab-panel" style="<?= $tab!=='security'?'display:none':'' ?>">
  <div class="log-table-wrap">
    <div class="log-toolbar">
      <input id="sec-search" type="text" placeholder="Search IP, type, URL…" style="flex:1;min-width:160px;">
      <select id="sec-severity">
        <option value="">All Severities</option>
        <option value="critical">Critical</option>
        <option value="warning">Warning</option>
        <option value="info">Info</option>
      </select>
      <select id="sec-type">
        <option value="">All Types</option>
        <option value="brute_force_login">Brute Force Login</option>
        <option value="api_rate_limit_exceeded">API Rate Limit</option>
        <option value="cors_blocked">CORS Blocked</option>
        <option value="api_oversized_body">Oversized Body</option>
      </select>
      <button class="btn-primary" onclick="loadSecurity(1)">Search</button>
      <button class="btn-green"   onclick="exportLog('security')">⬇ Export CSV</button>
    </div>
    <div style="overflow-x:auto;">
    <table>
      <thead><tr>
        <th>#</th><th>Time</th><th>Type</th><th>Severity</th>
        <th>IP</th><th>URL</th><th>Payload</th>
      </tr></thead>
      <tbody id="sec-tbody"><tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;">Loading…</td></tr></tbody>
    </table>
    </div>
    <div class="pagination" id="sec-pagination"></div>
  </div>
</div>

<!-- ─── LIVE FEED ─────────────────────────────────────────── -->
<div id="tab-live" class="tab-panel" style="<?= $tab!=='live'?'display:none':'' ?>">
  <div class="log-table-wrap">
    <div class="log-toolbar">
      <span class="live-dot"></span>
      <span style="font-size:13px;color:#10b981;font-weight:600;">Live Activity Stream</span>
      <span style="font-size:12px;color:#94a3b8;margin-left:8px;">Auto-refreshes every 5 seconds</span>
      <button class="btn-outline" style="margin-left:auto;" onclick="toggleLive()">⏸ Pause</button>
    </div>
    <div style="overflow-x:auto;max-height:600px;overflow-y:auto;" id="live-scroll">
    <table>
      <thead><tr>
        <th>#</th><th>Time</th><th>User</th><th>IP</th><th>Action</th><th>URL</th>
      </tr></thead>
      <tbody id="live-tbody"><tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8;">Connecting…</td></tr></tbody>
    </table>
    </div>
  </div>
</div>

</div><!-- .an-content -->
</div><!-- .an-main -->
</div><!-- .an-shell -->

<script>
const BASE = '<?= BASE_URL ?>/admin/analytics.php';
let currentPeriod = 24;
let liveRunning   = true;
let liveLastId    = 0;
let liveTimer     = null;
let charts        = {};

// ── Period ───────────────────────────────────────────────────
function setPeriod(h, btn) {
  currentPeriod = h;
  document.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  refreshCurrentTab();
}

function refreshCurrentTab() {
  const active = document.querySelector('.an-tab.active')?.dataset?.tab || 'overview';
  switchTab(active, true);
}

// ── Tab switch ───────────────────────────────────────────────
function switchTab(name, noHistory=false) {
  document.querySelectorAll('.tab-panel').forEach(p => p.style.display='none');
  document.querySelectorAll('.an-tab').forEach(t => t.classList.remove('active'));
  const panel = document.getElementById('tab-' + name);
  if (panel) panel.style.display = '';
  document.querySelectorAll('.an-tab').forEach(t => {
    if (t.textContent.toLowerCase().includes(name) || t.getAttribute('onclick')?.includes(name)) {
      t.classList.add('active');
      t.dataset.tab = name;
    }
  });
  if (!noHistory) history.replaceState(null,'','?tab='+name);

  if (name === 'overview')  loadOverview();
  if (name === 'activity')  loadActivity(1);
  if (name === 'errors')    loadErrors(1);
  if (name === 'security')  loadSecurity(1);
  if (name === 'live')      startLive();
}

// ── Overview ─────────────────────────────────────────────────
async function loadOverview() {
  const data = await apiFetch(`?ajax=overview_stats&period=${currentPeriod}`);
  if (!data?.ok) return;
  const d = data.data;
  document.getElementById('s-total-actions').textContent = fmt(d.total_actions);
  document.getElementById('s-unique-ips').textContent    = fmt(d.unique_ips);
  document.getElementById('s-unique-users').textContent  = fmt(d.unique_users);
  document.getElementById('s-total-errors').textContent  = fmt(d.total_errors);
  document.getElementById('s-blocked-attacks').textContent = fmt(d.blocked_attacks);
  document.getElementById('s-brute-force').textContent   = fmt(d.brute_force);
  document.getElementById('s-rate-limited').textContent  = fmt(d.rate_limited);
  document.getElementById('s-active-bans').textContent   = fmt(d.active_bans);
  await loadCharts();
}

async function loadCharts() {
  const p = currentPeriod;
  const [hourly, actions, ips, errors] = await Promise.all([
    apiFetch(`?ajax=chart_data&type=hourly&period=${p}`),
    apiFetch(`?ajax=chart_data&type=top_actions&period=${p}`),
    apiFetch(`?ajax=chart_data&type=top_ips&period=${p}`),
    apiFetch(`?ajax=chart_data&type=error_types&period=${p}`),
  ]);

  buildLineChart('chart-hourly', hourly?.data?.map(r=>r.hour), hourly?.data?.map(r=>+r.cnt), 'Requests');
  buildBarChart('chart-actions', actions?.data?.map(r=>r.action), actions?.data?.map(r=>+r.cnt), 'Actions', '#6366f1');
  buildBarChart('chart-ips',     ips?.data?.map(r=>r.ip),         ips?.data?.map(r=>+r.cnt),    'Hits', '#06b6d4');
  buildBarChart('chart-errors',  errors?.data?.map(r=>r.error_type), errors?.data?.map(r=>+r.cnt), 'Errors', '#ef4444');
}

function buildLineChart(id, labels, data, label) {
  const ctx = document.getElementById(id);
  if (!ctx) return;
  if (charts[id]) charts[id].destroy();
  charts[id] = new Chart(ctx, {
    type: 'line',
    data: { labels: labels||[], datasets: [{ label, data: data||[], borderColor:'#6366f1', backgroundColor:'rgba(99,102,241,.1)', fill:true, tension:.3, pointRadius:3 }] },
    options: { responsive:true, plugins:{ legend:{ display:false } }, scales:{ x:{ ticks:{ maxTicksLimit:8, font:{size:11} } }, y:{ beginAtZero:true, ticks:{ font:{size:11} } } } }
  });
}

function buildBarChart(id, labels, data, label, color='#6366f1') {
  const ctx = document.getElementById(id);
  if (!ctx) return;
  if (charts[id]) charts[id].destroy();
  charts[id] = new Chart(ctx, {
    type: 'bar',
    data: { labels: labels||[], datasets: [{ label, data: data||[], backgroundColor: color+'cc', borderRadius:4 }] },
    options: { responsive:true, indexAxis:'y', plugins:{ legend:{ display:false } }, scales:{ x:{ beginAtZero:true, ticks:{font:{size:11}} }, y:{ ticks:{font:{size:11}} } } }
  });
}

// ── Activity Log ─────────────────────────────────────────────
async function loadActivity(page=1) {
  const search  = document.getElementById('act-search')?.value||'';
  const user_id = document.getElementById('act-user')?.value||'';
  const data = await apiFetch(`?ajax=activity_log&page=${page}&search=${enc(search)}&user_id=${user_id}&period=${currentPeriod}`);
  if (!data?.ok) return;
  const tbody = document.getElementById('act-tbody');
  tbody.innerHTML = data.data.length ? data.data.map(r => `
    <tr>
      <td>${r.id}</td>
      <td style="white-space:nowrap;font-size:12px;">${r.created_at}</td>
      <td><a href="${BASE_URL}/admin/index.php?tab=users&uid=${r.user_id||''}" style="color:#6366f1;">${esc(r.username||'Guest')}</a></td>
      <td style="font-family:monospace;font-size:12px;">${esc(r.ip)}</td>
      <td><span style="background:#ede9fe;color:#6d28d9;padding:2px 8px;border-radius:20px;font-size:12px;font-weight:600;">${esc(r.action)}</span></td>
      <td class="truncate" title="${esc(r.url)}">${esc(r.url)}</td>
      <td class="truncate" title="${esc(r.user_agent)}" style="font-size:11px;color:#94a3b8;">${esc(r.user_agent)}</td>
    </tr>`).join('') : '<tr><td colspan="7" style="text-align:center;padding:30px;color:#94a3b8;">No records found.</td></tr>';
  renderPagination('act-pagination', page, data.pages, loadActivity);
}

// ── Error Log ─────────────────────────────────────────────────
async function loadErrors(page=1) {
  const search = document.getElementById('err-search')?.value||'';
  const type   = document.getElementById('err-type')?.value||'';
  const data = await apiFetch(`?ajax=error_log&page=${page}&search=${enc(search)}&error_type=${enc(type)}&period=${currentPeriod}`);
  if (!data?.ok) return;
  const tbody = document.getElementById('err-tbody');
  tbody.innerHTML = data.data.length ? data.data.map(r => {
    const payload = safeJson(r.payload);
    return `<tr>
      <td>${r.id}</td>
      <td style="white-space:nowrap;font-size:12px;">${r.created_at}</td>
      <td><span style="background:#fee2e2;color:#b91c1c;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;">${esc(r.error_type)}</span></td>
      <td>${esc(r.username||'Guest')}</td>
      <td style="font-family:monospace;font-size:12px;">${esc(r.ip)}</td>
      <td class="truncate" title="${esc(r.url)}">${esc(r.url)}</td>
      <td><div class="json-payload" onclick="this.classList.toggle('expanded')">${esc(payload?.message||JSON.stringify(payload))}</div></td>
    </tr>`;
  }).join('') : '<tr><td colspan="7" style="text-align:center;padding:30px;color:#94a3b8;">No errors found. 🎉</td></tr>';
  renderPagination('err-pagination', page, data.pages, loadErrors);
}

// ── Security Events ───────────────────────────────────────────
async function loadSecurity(page=1) {
  const search   = document.getElementById('sec-search')?.value||'';
  const severity = document.getElementById('sec-severity')?.value||'';
  const type     = document.getElementById('sec-type')?.value||'';
  const data = await apiFetch(`?ajax=security_log&page=${page}&search=${enc(search)}&severity=${enc(severity)}&event_type=${enc(type)}&period=${currentPeriod}`);
  if (!data?.ok) return;
  const tbody = document.getElementById('sec-tbody');
  tbody.innerHTML = data.data.length ? data.data.map(r => {
    const sevClass = r.severity === 'critical' ? 'sev-critical' : r.severity === 'warning' ? 'sev-warning' : 'sev-info';
    return `<tr>
      <td>${r.id}</td>
      <td style="white-space:nowrap;font-size:12px;">${r.created_at}</td>
      <td>${esc(r.event_type)}</td>
      <td class="${sevClass}">${r.severity.toUpperCase()}</td>
      <td style="font-family:monospace;font-size:12px;">${esc(r.ip)}</td>
      <td class="truncate" title="${esc(r.url)}">${esc(r.url)}</td>
      <td><div class="json-payload" onclick="this.classList.toggle('expanded')">${esc(r.payload)}</div></td>
    </tr>`;
  }).join('') : '<tr><td colspan="7" style="text-align:center;padding:30px;color:#94a3b8;">No security events. ✅</td></tr>';
  renderPagination('sec-pagination', page, data.pages, loadSecurity);
}

// ── Live Feed ─────────────────────────────────────────────────
function startLive() {
  liveLastId = 0;
  if (liveTimer) clearInterval(liveTimer);
  pollLive();
  liveTimer = setInterval(pollLive, 5000);
}

async function pollLive() {
  if (!liveRunning) return;
  const data = await apiFetch(`?ajax=live_tail&last_id=${liveLastId}`);
  if (!data?.ok || !data.data.length) return;
  liveLastId = data.max_id;
  const tbody = document.getElementById('live-tbody');
  const rows = data.data.map(r => `
    <tr style="animation:fadeIn .3s ease;">
      <td>${r.id}</td>
      <td style="font-size:11px;color:#94a3b8;">${r.created_at}</td>
      <td>${esc(r.username||'Guest')}</td>
      <td style="font-family:monospace;font-size:12px;">${esc(r.ip)}</td>
      <td><span style="background:#ede9fe;color:#6d28d9;padding:1px 8px;border-radius:20px;font-size:11px;">${esc(r.action)}</span></td>
      <td class="truncate">${esc(r.url)}</td>
    </tr>`).join('');
  tbody.insertAdjacentHTML('afterbegin', rows);
  // Keep max 200 rows
  while (tbody.rows.length > 200) tbody.deleteRow(tbody.rows.length - 1);
}

function toggleLive() {
  liveRunning = !liveRunning;
  document.querySelector('#tab-live .btn-outline').textContent = liveRunning ? '⏸ Pause' : '▶ Resume';
}

// ── Helpers ───────────────────────────────────────────────────
async function apiFetch(qs) {
  try {
    const r = await fetch(BASE + qs);
    return await r.json();
  } catch(e) { return null; }
}

function exportLog(type) {
  window.open(`${BASE}?ajax=export&log_type=${type}&period=${currentPeriod}`, '_blank');
}

function renderPagination(containerId, currentPage, totalPages, callback) {
  const el = document.getElementById(containerId);
  if (!el || totalPages <= 1) { if(el) el.innerHTML=''; return; }
  let html = `<span>${currentPage} of ${totalPages}</span>`;
  if (currentPage > 1) html += `<button onclick="${callback.name}(${currentPage-1})">← Prev</button>`;
  // Nearby pages
  for (let p = Math.max(1,currentPage-2); p <= Math.min(totalPages,currentPage+2); p++) {
    html += `<button class="${p===currentPage?'active':''}" onclick="${callback.name}(${p})">${p}</button>`;
  }
  if (currentPage < totalPages) html += `<button onclick="${callback.name}(${currentPage+1})">Next →</button>`;
  el.innerHTML = html;
}

function fmt(n) { return (n||0).toLocaleString(); }
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function enc(s) { return encodeURIComponent(s||''); }
function safeJson(s) { try { return JSON.parse(s||'{}'); } catch(e) { return {}; } }

// ── Init ──────────────────────────────────────────────────────
const BASE_URL = '<?= BASE_URL ?>';
document.addEventListener('DOMContentLoaded', () => {
  // Attach tab dataset
  document.querySelectorAll('.an-tab').forEach(t => {
    const m = t.getAttribute('onclick')?.match(/'([^']+)'/);
    if (m) t.dataset.tab = m[1];
  });
  switchTab('<?= $tab ?>');
});

// Auto-refresh overview every 60s
setInterval(() => {
  const active = document.querySelector('.an-tab.active')?.dataset?.tab;
  if (active === 'overview') loadOverview();
}, 60000);
</script>
</body>
</html>
