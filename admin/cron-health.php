<?php
/**
 * admin/cron-health.php
 * Cron Health Dashboard — redesigned, unified graph with Chart.js interpolation
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin.php';
require_admin();

$user     = current_user();
$app_name = APP_NAME;
$csrf     = csrf_token();
$msg = ''; $err = '';

// ── POST handlers ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $act = $_POST['action'] ?? '';

    if ($act === 'add_task' || $act === 'edit_task') {
        $tid      = (int)($_POST['task_id'] ?? 0);
        $key      = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['task_key'] ?? '')));
        $label    = trim($_POST['label'] ?? '');
        $desc     = trim($_POST['description'] ?? '');
        $file     = trim($_POST['file'] ?? '');
        $interval = max(1, (int)($_POST['interval_seconds'] ?? 3600));
        $group    = trim($_POST['group_name'] ?? 'System');
        $sort     = (int)($_POST['sort_order'] ?? 0);
        $enabled  = isset($_POST['enabled']) ? 1 : 0;

        if (!$key || !$label || !$file) { $err = 'Key, Label, File required.'; }
        else {
            if ($act === 'add_task') {
                db()->prepare('INSERT INTO cron_tasks (task_key,label,description,file,interval_seconds,group_name,enabled,sort_order) VALUES (?,?,?,?,?,?,?,?)')->execute([$key,$label,$desc,$file,$interval,$group,$enabled,$sort]);
                $msg = "Task '{$label}' added.";
            } else {
                db()->prepare('UPDATE cron_tasks SET label=?,description=?,file=?,interval_seconds=?,group_name=?,enabled=?,sort_order=? WHERE id=?')->execute([$label,$desc,$file,$interval,$group,$enabled,$sort,$tid]);
                $msg = "Task '{$label}' updated.";
            }
        }
    }

    if ($act === 'delete_task') {
        $tid = (int)($_POST['task_id'] ?? 0);
        $row = db()->prepare('SELECT task_key,label FROM cron_tasks WHERE id=? LIMIT 1');
        $row->execute([$tid]); $row = $row->fetch();
        if ($row) {
            db()->prepare('DELETE FROM cron_tasks WHERE id=?')->execute([$tid]);
            db()->prepare('DELETE FROM cron_runs WHERE task_key=?')->execute([$row['task_key']]);
            $msg = "Task '{$row['label']}' deleted.";
        }
    }

    if ($act === 'toggle_task') {
        $tid = (int)($_POST['task_id'] ?? 0);
        db()->prepare('UPDATE cron_tasks SET enabled = 1-enabled WHERE id=?')->execute([$tid]);
        $msg = 'Task toggled.';
    }

    if ($act === 'run_task') {
        $tid = (int)($_POST['task_id'] ?? 0);
        $task = db()->prepare('SELECT * FROM cron_tasks WHERE id=? LIMIT 1');
        $task->execute([$tid]); $task = $task->fetch();
        if ($task) {
            define('CV_CRON', true);
            define('CV_MASTER_CRON', true);
            $file = __DIR__ . '/../cron/' . $task['file'];
            if (!file_exists($file)) { $err = 'File not found: ' . $task['file']; }
            else {
                $start = microtime(true);
                try {
                    if (str_contains($task['file'], 'db-backup')) {
                        $__backup_admin_require = true;
                        require_once $file;
                        $type = str_contains($task['task_key'], 'file') ? 'files' : 'db';
                        $res  = run_backup($type);
                        $ok   = $res['ok']; $note = $res['message'] ?? '';
                    } else {
                        ob_start(); require $file; $output = trim(ob_get_clean());
                        $lines = array_filter(array_map('trim', explode("\n", strip_tags($output))));
                        $ok = true; $note = mb_substr(end($lines) ?: 'done', 0, 300);
                    }
                    $ms = (int)((microtime(true)-$start)*1000);
                    db()->prepare("INSERT INTO cron_runs (task_key,started_at,duration_ms,status,note) VALUES (?,NOW(),?,?,?)")->execute([$task['task_key'], $ms, $ok?'ok':'error', $note]);
                    $msg = ($ok?'✓ ':'✗ ') . $task['label'] . ' — ' . $note . " ({$ms}ms)";
                } catch (Throwable $e) { $err = $e->getMessage(); }
            }
        }
    }

    if ($act === 'clear_history') {
        $key = $_POST['task_key'] ?? '';
        if ($key) { db()->prepare('DELETE FROM cron_runs WHERE task_key=?')->execute([$key]); $msg = 'History cleared.'; }
    }
}

// ── Load tasks ─────────────────────────────────────────────────
try {
    $all_tasks = db()->query("SELECT * FROM cron_tasks ORDER BY sort_order, id")->fetchAll() ?: [];
} catch (Throwable $e) { $all_tasks = []; $err = 'DB Error: cron_tasks table missing? Run sql/cron_schema.sql first.'; }

function get_task_stats(string $key): array {
    try {
        $last = db()->prepare("SELECT * FROM cron_runs WHERE task_key=? ORDER BY started_at DESC LIMIT 1");
        $last->execute([$key]); $last = $last->fetch();
        $counts = db()->prepare("SELECT status, COUNT(*) as n FROM cron_runs WHERE task_key=? AND started_at > DATE_SUB(NOW(),INTERVAL 24 HOUR) GROUP BY status");
        $counts->execute([$key]); $counts = $counts->fetchAll();
        $ok_24h = $err_24h = 0;
        foreach ($counts as $c) { if($c['status']==='ok') $ok_24h=$c['n']; elseif($c['status']==='error') $err_24h=$c['n']; }
        $total = db()->prepare("SELECT COUNT(*) FROM cron_runs WHERE task_key=?");
        $total->execute([$key]); $total = (int)$total->fetchColumn();
        return compact('last','ok_24h','err_24h','total');
    } catch (Throwable $e) { return ['last'=>null,'ok_24h'=>0,'err_24h'=>0,'total'=>0]; }
}

// Unified graph data: hourly buckets for ALL tasks combined
function get_all_graph_data(array $tasks): array {
    $result = [];
    foreach ($tasks as $t) {
        try {
            $rows = db()->prepare(
                "SELECT DATE_FORMAT(started_at,'%H') as hr,
                        SUM(status='ok') as ok,
                        SUM(status='error') as err,
                        ROUND(AVG(duration_ms)) as avg_ms
                 FROM cron_runs
                 WHERE task_key=? AND started_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                 GROUP BY hr ORDER BY hr"
            );
            $rows->execute([$t['task_key']]);
            $byHour = [];
            foreach ($rows->fetchAll() as $r) $byHour[(int)$r['hr']] = $r;
            $hourly = [];
            for ($h = 0; $h < 24; $h++) {
                $hourly[] = [
                    'hr'     => sprintf('%02d:00', $h),
                    'ok'     => (int)($byHour[$h]['ok'] ?? 0),
                    'err'    => (int)($byHour[$h]['err'] ?? 0),
                    'avg_ms' => (int)($byHour[$h]['avg_ms'] ?? 0),
                ];
            }
            $result[$t['task_key']] = ['label' => $t['label'], 'data' => $hourly];
        } catch (Throwable $e) {
            $result[$t['task_key']] = ['label' => $t['label'], 'data' => array_fill(0, 24, ['hr'=>'','ok'=>0,'err'=>0,'avg_ms'=>0])];
        }
    }
    return $result;
}

$groups = [];
foreach ($all_tasks as $t) $groups[$t['group_name']][] = $t;

$master_last = get_setting('master_cron_last_ping', '');
$master_age  = $master_last ? (time() - strtotime($master_last)) : null;
$master_ok   = $master_age !== null && $master_age < 180;

$total_tasks   = count($all_tasks);
$enabled_tasks = count(array_filter($all_tasks, fn($t) => $t['enabled']));
$ok_tasks = $err_tasks = $never_tasks = 0;
foreach ($all_tasks as $t) {
    if (!$t['enabled']) continue;
    $st = get_task_stats($t['task_key']);
    if (!$st['last']) $never_tasks++;
    elseif ($st['last']['status'] === 'error') $err_tasks++;
    else $ok_tasks++;
}

$edit_task = null;
if (isset($_GET['edit'])) {
    $et = db()->prepare('SELECT * FROM cron_tasks WHERE id=? LIMIT 1');
    $et->execute([(int)$_GET['edit']]); $edit_task = $et->fetch() ?: null;
}

$all_graph_data = get_all_graph_data($all_tasks);

// Palette for per-task lines
$palette = ['#185FA5','#3B6D11','#A32D2D','#854F0B','#533AB7','#0F6E56','#993556','#888780','#BA7517','#3266ad','#639922','#D85A30'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Cron Health — <?= $app_name ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/admin/admin.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f1f5f9; color: #0f172a; }

    /* ── Layout ── */
    .shell { display: flex; min-height: 100vh; }

    /* ── Sidebar ── */
    .sidebar { width: 220px; background: #0f172a; position: fixed; top: 0; left: 0; height: 100vh; display: flex; flex-direction: column; z-index: 50; overflow-y: auto; }
    .sb-logo { padding: 16px 14px; border-bottom: 1px solid rgba(255,255,255,.07); display: flex; align-items: center; gap: 9px; }
    .sb-mark { width: 28px; height: 28px; border-radius: 7px; background: #3b82f6; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .sb-name { font-size: 13.5px; font-weight: 800; color: #fff; }
    .sb-badge { font-size: 9px; font-weight: 700; background: #dc2626; color: #fff; padding: 1px 6px; border-radius: 99px; text-transform: uppercase; letter-spacing: .04em; }
    .sb-nav { flex: 1; padding: 8px; }
    .sb-section { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; color: rgba(255,255,255,.25); padding: 10px 8px 4px; }
    .sb-link { display: flex; align-items: center; gap: 8px; padding: 7px 9px; border-radius: 7px; font-size: 12.5px; font-weight: 500; color: rgba(255,255,255,.55); text-decoration: none; transition: all .12s; margin-bottom: 1px; }
    .sb-link:hover { background: rgba(255,255,255,.07); color: rgba(255,255,255,.85); }
    .sb-link.active { background: #1e293b; color: #fff; font-weight: 700; }
    .sb-link svg { width: 14px; height: 14px; flex-shrink: 0; }
    .sb-foot { padding: 10px 8px; border-top: 1px solid rgba(255,255,255,.07); }

    /* ── Main ── */
    .main { margin-left: 232px; flex: 1; }
    .topbar { background: #fff; border-bottom: 1px solid #e2e8f0; height: 54px; display: flex; align-items: center; padding: 0 26px; position: sticky; top: 0; z-index: 30; gap: 10px; }
    .topbar-title { font-size: 14.5px; font-weight: 800; color: #0f172a; }
    .topbar-right { margin-left: auto; display: flex; align-items: center; gap: 8px; }
    .tb-btn { display: inline-flex; align-items: center; gap: 5px; padding: 6px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 12px; font-weight: 600; color: #475569; text-decoration: none; cursor: pointer; font-family: inherit; transition: background .12s; }
    .tb-btn:hover { background: #f1f5f9; }
    .tb-muted { font-size: 12px; color: #94a3b8; }

    .page { padding: 22px 26px; max-width: 1140px; }

    /* ── Alerts ── */
    .alert { border-radius: 10px; padding: 10px 15px; margin-bottom: 14px; font-size: 13px; font-weight: 600; }
    .alert-ok  { background: #f0fdf4; border: 1.5px solid #86efac; color: #15803d; }
    .alert-err { background: #fef2f2; border: 1.5px solid #fca5a5; color: #dc2626; }

    /* ── Master banner ── */
    .master-bar { display: flex; align-items: center; gap: 14px; padding: 13px 18px; border-radius: 12px; border: 1.5px solid; margin-bottom: 20px; }
    .mb-ok   { background: #f0fdf4; border-color: #86efac; }
    .mb-warn { background: #fefce8; border-color: #fde047; }
    .mb-err  { background: #fef2f2; border-color: #fca5a5; }
    .mb-icon { font-size: 22px; }
    .mb-body { flex: 1; }
    .mb-title { font-size: 13.5px; font-weight: 700; color: #0f172a; }
    .mb-sub   { font-size: 12px; color: #64748b; margin-top: 2px; }
    .mb-cmd   { font-family: 'JetBrains Mono', monospace; font-size: 11.5px; background: #0d1117; color: #3fb950; padding: 7px 13px; border-radius: 8px; white-space: nowrap; }

    /* ── Stats ── */
    .stats-row { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; margin-bottom: 22px; }
    .stat-box { background: #fff; border: 1px solid #e2e8f0; border-radius: 11px; padding: 14px 16px; text-align: center; }
    .stat-n { font-size: 26px; font-weight: 800; letter-spacing: -1px; line-height: 1; color: #0f172a; }
    .stat-l { font-size: 11px; color: #94a3b8; margin-top: 4px; text-transform: uppercase; letter-spacing: .05em; }

    /* ── Unified Chart Card ── */
    .chart-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 13px; overflow: hidden; margin-bottom: 22px; }
    .chart-card-head { padding: 14px 18px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .chart-card-title { font-size: 13.5px; font-weight: 700; color: #0f172a; flex: 1; }
    .metric-tabs { display: flex; gap: 3px; background: #f1f5f9; border-radius: 8px; padding: 3px; }
    .metric-tab { padding: 4px 13px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; border: none; background: transparent; color: #64748b; font-family: inherit; transition: all .13s; }
    .metric-tab.active { background: #fff; color: #0f172a; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
    .interp-select { padding: 5px 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 12px; color: #475569; background: #fff; cursor: pointer; font-family: inherit; outline: none; }
    .chart-body { padding: 16px 18px 10px; }
    .chart-legend { display: flex; flex-wrap: wrap; gap: 12px; padding: 10px 18px 14px; border-top: 1px solid #f8fafc; }
    .legend-item { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #64748b; }
    .legend-swatch { width: 22px; height: 3px; border-radius: 2px; }

    /* ── Group heading ── */
    .group-label { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: #94a3b8; margin: 22px 0 9px 2px; }

    /* ── Task Cards ── */
    .tasks-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 10px; }
    .task-card { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; overflow: hidden; transition: box-shadow .15s; }
    .task-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.06); }
    .tc-ok       { border-left: 3px solid #22c55e; }
    .tc-error    { border-left: 3px solid #ef4444; }
    .tc-never    { border-left: 3px solid #94a3b8; }
    .tc-disabled { border-left: 3px solid #e2e8f0; opacity: .6; }

    .tc-head { padding: 12px 15px; display: flex; align-items: flex-start; gap: 10px; cursor: pointer; }
    .tc-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; margin-top: 4px; }
    .dot-ok    { background: #22c55e; animation: pulse-g 2s infinite; }
    .dot-err   { background: #ef4444; animation: pulse-r 1s infinite; }
    .dot-never { background: #94a3b8; }
    .dot-dis   { background: #e2e8f0; }
    @keyframes pulse-g { 0%,100% { box-shadow:0 0 0 0 rgba(34,197,94,.3); } 50% { box-shadow:0 0 0 4px rgba(34,197,94,.1); } }
    @keyframes pulse-r { 0%,100% { box-shadow:0 0 0 0 rgba(239,68,68,.4); } 50% { box-shadow:0 0 0 4px rgba(239,68,68,.15); } }

    .tc-info { flex: 1; min-width: 0; }
    .tc-name { font-size: 13px; font-weight: 700; color: #0f172a; }
    .tc-desc { font-size: 11.5px; color: #94a3b8; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .tc-right { text-align: right; flex-shrink: 0; min-width: 90px; }
    .tc-interval { font-family: 'JetBrains Mono', monospace; font-size: 11px; color: #64748b; }
    .tc-next { font-size: 11px; margin-top: 3px; }
    .tc-next.due  { color: #dc2626; font-weight: 700; }
    .tc-next.soon { color: #2563eb; }

    .tc-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 700; margin-top: 5px; }
    .tb-ok    { background: #f0fdf4; color: #16a34a; }
    .tb-err   { background: #fef2f2; color: #dc2626; }
    .tb-never { background: #f8fafc; color: #64748b; }
    .tb-dis   { background: #f8fafc; color: #94a3b8; }

    .tc-stats { display: flex; padding: 9px 15px; gap: 16px; border-top: 1px solid #f8fafc; background: #fafbfd; }
    .tcs-item { text-align: center; }
    .tcs-n { font-size: 13px; font-weight: 700; color: #0f172a; font-family: 'JetBrains Mono', monospace; }
    .tcs-l { font-size: 10px; color: #94a3b8; margin-top: 1px; }
    .tcs-sep { width: 1px; background: #f1f5f9; }

    .tc-note { padding: 8px 15px; font-family: 'JetBrains Mono', monospace; font-size: 11px; color: #64748b; background: #f8fafc; border-top: 1px solid #f1f5f9; white-space: pre-wrap; word-break: break-all; display: none; }
    .tc-note.err-note { background: #fef2f2; color: #dc2626; }

    .tc-actions { padding: 10px 15px; display: flex; gap: 6px; flex-wrap: wrap; border-top: 1px solid #f1f5f9; background: #fafbfd; display: none; }
    .task-card.tc-open .tc-note { display: block; }
    .task-card.tc-open .tc-actions { display: flex; }

    .act-btn { display: inline-flex; align-items: center; gap: 4px; padding: 5px 11px; border-radius: 7px; font-size: 12px; font-weight: 600; cursor: pointer; border: 1.5px solid; background: #fff; font-family: inherit; transition: all .12s; white-space: nowrap; }
    .ab-run  { color: #2563eb; border-color: #bfdbfe; } .ab-run:hover  { background: #eff6ff; }
    .ab-edit { color: #7c3aed; border-color: #e9d5ff; } .ab-edit:hover { background: #faf5ff; }
    .ab-tog-on  { color: #16a34a; border-color: #86efac; } .ab-tog-on:hover  { background: #f0fdf4; }
    .ab-tog-off { color: #dc2626; border-color: #fca5a5; } .ab-tog-off:hover { background: #fef2f2; }
    .ab-clr { color: #64748b; border-color: #e2e8f0; } .ab-clr:hover { background: #f1f5f9; }
    .ab-del { color: #dc2626; border-color: #fca5a5; margin-left: auto; } .ab-del:hover { background: #fef2f2; }

    /* ── Add Task Form ── */
    .add-card { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 13px; overflow: hidden; margin-bottom: 20px; }
    .add-head { padding: 13px 18px; border-bottom: 1px solid transparent; display: flex; align-items: center; gap: 8px; cursor: pointer; transition: border-color .13s; }
    .add-head.open { border-bottom-color: #f1f5f9; }
    .add-title { font-size: 13.5px; font-weight: 700; color: #0f172a; flex: 1; }
    .add-body { padding: 20px; display: none; }
    .add-body.open { display: block; }
    .fg  { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
    .fg3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 12px; }
    .flbl { display: block; font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .05em; }
    .finp { width: 100%; padding: 8px 10px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-family: inherit; font-size: 13px; color: #0f172a; outline: none; transition: border-color .13s; background: #fff; }
    .finp:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.1); }
    .btn-save { display: inline-flex; align-items: center; gap: 6px; padding: 9px 20px; background: #0f172a; color: #fff; border: none; border-radius: 8px; font-size: 13px; font-weight: 700; font-family: inherit; cursor: pointer; transition: background .13s; }
    .btn-save:hover { background: #1e293b; }

    /* ── cPanel box ── */
    .setup-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 13px; margin-top: 22px; overflow: hidden; }
    .setup-head { padding: 13px 18px; border-bottom: 1px solid #f1f5f9; font-size: 13.5px; font-weight: 700; background: #fafbfd; }
    .setup-body { padding: 18px; }
    .setup-sub { font-size: 13px; color: #64748b; margin-bottom: 10px; }
    .cron-box { background: #0d1117; border-radius: 9px; padding: 13px 16px; font-family: 'JetBrains Mono', monospace; font-size: 12.5px; color: #3fb950; position: relative; line-height: 1.7; }
    .cron-copy { position: absolute; top: 10px; right: 10px; padding: 4px 10px; background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.15); border-radius: 6px; font-size: 11.5px; color: #8b949e; cursor: pointer; font-family: inherit; transition: all .13s; }
    .cron-copy:hover { background: rgba(255,255,255,.15); color: #fff; }
    .setup-hint { font-size: 12px; color: #94a3b8; margin-top: 10px; }

    @media (max-width: 900px) {
      .main { margin-left: 0; }
      .sidebar { display: none; }
      .stats-row { grid-template-columns: repeat(3,1fr); }
      .tasks-grid { grid-template-columns: 1fr; }
      .fg, .fg3 { grid-template-columns: 1fr; }
    }
  
    @media(max-width:960px){
      .adm-main{margin-left:0 !important}
      .adm-topbar{display:none !important}
      .adm-mobile-bar{display:flex !important}
      .tbl-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
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
<div class="shell">

  <!-- ── Sidebar ── -->
  <?php include 'sidebar.php'; ?>

  <!-- ── Main ── -->
  <div class="main">
    <div class="topbar">
      <span class="topbar-title">⏱ Cron Health</span>
      <div class="topbar-right">
        <span class="tb-muted">Auto-refresh in <strong id="countdown">30</strong>s</span>
        <span id="live-time" class="tb-muted"><?= date('H:i:s') ?></span>
        <a href="" class="tb-btn">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>Refresh
        </a>
      </div>
    </div>

    <div class="page">

      <?php if ($msg): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="alert alert-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

      <!-- Master cron banner -->
      <?php
        $mb = !$master_last ? 'warn' : ($master_ok ? 'ok' : 'err');
        $mb_icon = !$master_last ? '⚠️' : ($master_ok ? '✅' : '🔴');
        $mb_txt  = !$master_last
          ? 'Master cron has never run. Add the cron command below to cPanel.'
          : ($master_ok
              ? "Master cron is running — last ping {$master_age}s ago (" . date('H:i:s', strtotime($master_last)) . ')'
              : '⚠ Master cron stopped! Last ping was ' . round($master_age/60, 1) . ' min ago. Check cPanel.');
      ?>
      <div class="master-bar mb-<?= $mb ?>">
        <span class="mb-icon"><?= $mb_icon ?></span>
        <div class="mb-body">
          <div class="mb-title">Master Cron Status</div>
          <div class="mb-sub"><?= $mb_txt ?></div>
        </div>
        <?php if (!$master_ok): ?>
        <div class="mb-cmd">* * * * * php <?= dirname(__DIR__) ?>/cron/master.php</div>
        <?php endif; ?>
      </div>

      <!-- Stats -->
      <div class="stats-row">
        <div class="stat-box"><div class="stat-n"><?= $total_tasks ?></div><div class="stat-l">Total Tasks</div></div>
        <div class="stat-box"><div class="stat-n" style="color:#16a34a"><?= $ok_tasks ?></div><div class="stat-l">Healthy</div></div>
        <div class="stat-box"><div class="stat-n" style="color:<?= $err_tasks>0?'#dc2626':'#94a3b8' ?>"><?= $err_tasks ?></div><div class="stat-l">Errors</div></div>
        <div class="stat-box"><div class="stat-n" style="color:#94a3b8"><?= $never_tasks ?></div><div class="stat-l">Never Ran</div></div>
        <div class="stat-box"><div class="stat-n" style="color:#94a3b8"><?= $enabled_tasks ?></div><div class="stat-l">Enabled</div></div>
      </div>

      <!-- ── Unified Chart ── -->
      <?php if (!empty($all_tasks)): ?>
      <div class="chart-card">
        <div class="chart-card-head">
          <div class="chart-card-title">All tasks — last 24h activity</div>
          <div class="metric-tabs">
            <button class="metric-tab active" onclick="switchMetric('runs',this)">Runs</button>
            <button class="metric-tab" onclick="switchMetric('errors',this)">Errors</button>
            <button class="metric-tab" onclick="switchMetric('duration',this)">Avg duration (ms)</button>
          </div>
          <select class="interp-select" id="interpSel" onchange="applyInterp()">
            <option value="default">Linear</option>
            <option value="monotone" selected>Monotone</option>
            <option value="basis">Basis (smooth)</option>
            <option value="step">Step</option>
          </select>
        </div>
        <div class="chart-body">
          <div style="position:relative;width:100%;height:260px">
            <canvas id="mainChart" role="img" aria-label="Line chart showing all cron task activity over the last 24 hours">Cron run history for all tasks over the last 24 hours.</canvas>
          </div>
        </div>
        <div class="chart-legend" id="chartLegend"></div>
      </div>
      <?php endif; ?>

      <!-- ── Task Groups ── -->
      <?php if (empty($all_tasks)): ?>
      <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:13px;padding:40px;text-align:center;color:#94a3b8;font-size:13.5px">
        No tasks yet. Run <code>sql/cron_schema.sql</code> in PhpMyAdmin to get started.
      </div>
      <?php endif; ?>

      <?php
      $colorIdx = 0;
      foreach ($groups as $group_name => $gtasks):
      ?>
      <div class="group-label"><?= htmlspecialchars($group_name) ?></div>
      <div class="tasks-grid">
      <?php foreach ($gtasks as $t):
        $stats   = get_task_stats($t['task_key']);
        $last    = $stats['last'];
        $health  = !$t['enabled'] ? 'disabled' : (!$last ? 'never' : ($last['status']==='error' ? 'error' : 'ok'));
        $dot_cls  = match($health) { 'ok'=>'dot-ok','error'=>'dot-err','never'=>'dot-never',default=>'dot-dis' };
        $card_cls = match($health) { 'ok'=>'tc-ok','error'=>'tc-error','never'=>'tc-never',default=>'tc-disabled' };
        $badge_cls= match($health) { 'ok'=>'tb-ok','error'=>'tb-err','never'=>'tb-never',default=>'tb-dis' };
        $badge_txt= match($health) { 'ok'=>'✓ OK','error'=>'✗ Error','never'=>'Never ran',default=>'Disabled' };

        $secs = (int)$t['interval_seconds'];
        $int_lbl = $secs < 120 ? "Every {$secs}s" : ($secs < 7200 ? 'Every '.round($secs/60).'m' : 'Every '.round($secs/3600).'h');

        $next_lbl = $next_cls = '';
        if ($last && $t['enabled']) {
          $diff = strtotime($last['started_at']) + $secs - time();
          $next_lbl = $diff <= 0 ? 'Due now' : ($diff < 3600 ? 'in '.round($diff/60).'m' : 'in '.round($diff/3600,1).'h');
          $next_cls = $diff <= 0 ? 'due' : 'soon';
        }
      ?>
      <div class="task-card <?= $card_cls ?>" id="tc_<?= $t['id'] ?>">
        <div class="tc-head" onclick="toggleCard(<?= $t['id'] ?>)">
          <div class="tc-dot <?= $dot_cls ?>"></div>
          <div class="tc-info">
            <div class="tc-name"><?= htmlspecialchars($t['label']) ?>
              <?php if ($stats['err_24h'] > 0): ?>
              <span style="font-size:10.5px;background:#fef2f2;color:#dc2626;padding:1px 6px;border-radius:5px;font-weight:700;margin-left:5px"><?= $stats['err_24h'] ?> err/24h</span>
              <?php endif; ?>
            </div>
            <div class="tc-desc"><?= htmlspecialchars($t['description'] ?: $t['file']) ?></div>
            <div class="tc-badge <?= $badge_cls ?>"><?= $badge_txt ?></div>
          </div>
          <div class="tc-right">
            <div class="tc-interval"><?= $int_lbl ?></div>
            <?php if ($next_lbl): ?><div class="tc-next <?= $next_cls ?>"><?= $next_lbl ?></div><?php endif; ?>
            <?php if ($last): ?><div style="font-size:10.5px;color:#94a3b8;margin-top:4px"><?= date('d M H:i', strtotime($last['started_at'])) ?></div><?php endif; ?>
          </div>
        </div>

        <div class="tc-stats">
          <div class="tcs-item"><div class="tcs-n" style="color:#16a34a"><?= $stats['ok_24h'] ?></div><div class="tcs-l">OK/24h</div></div>
          <div class="tcs-sep"></div>
          <div class="tcs-item"><div class="tcs-n" style="color:<?= $stats['err_24h']>0?'#dc2626':'#94a3b8' ?>"><?= $stats['err_24h'] ?></div><div class="tcs-l">Err/24h</div></div>
          <div class="tcs-sep"></div>
          <div class="tcs-item"><div class="tcs-n"><?= $stats['total'] ?></div><div class="tcs-l">Total runs</div></div>
          <div class="tcs-sep"></div>
          <div class="tcs-item"><div class="tcs-n"><?= $last ? $last['duration_ms'].'ms' : '—' ?></div><div class="tcs-l">Last dur.</div></div>
        </div>

        <?php if ($last && $last['note']): ?>
        <div class="tc-note <?= $last['status']==='error'?'err-note':'' ?>"><?= htmlspecialchars(mb_substr($last['note'],0,300)) ?></div>
        <?php endif; ?>

        <div class="tc-actions">
          <!-- Run now -->
          <form method="POST" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="run_task">
            <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
            <button type="submit" class="act-btn ab-run" <?= !$t['enabled']?'disabled style="opacity:.4"':'' ?>>▶ Run now</button>
          </form>
          <!-- Edit -->
          <a href="?edit=<?= $t['id'] ?>" class="act-btn ab-edit">✏ Edit</a>
          <!-- Toggle -->
          <form method="POST" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="toggle_task">
            <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
            <button type="submit" class="act-btn <?= $t['enabled']?'ab-tog-on':'ab-tog-off' ?>">
              <?= $t['enabled'] ? '⏸ Disable' : '▶ Enable' ?>
            </button>
          </form>
          <!-- Clear history -->
          <form method="POST" style="display:inline" onsubmit="return confirm('Clear run history?')">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="clear_history">
            <input type="hidden" name="task_key" value="<?= htmlspecialchars($t['task_key']) ?>">
            <button type="submit" class="act-btn ab-clr">🗑 Clear history</button>
          </form>
          <!-- Delete -->
          <form method="POST" style="display:inline;margin-left:auto" onsubmit="return confirm('Delete task \'<?= htmlspecialchars($t['label'],ENT_QUOTES) ?>\'? All history will be lost.')">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="delete_task">
            <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
            <button type="submit" class="act-btn ab-del">✕ Delete</button>
          </form>
        </div>
      </div>
      <?php $colorIdx++; endforeach; ?>
      </div>
      <?php endforeach; ?>

      <!-- ── Add / Edit Task ── -->
      <div class="group-label" style="margin-top:26px"><?= $edit_task ? 'Edit task' : 'Add new task' ?></div>
      <div class="add-card">
        <div class="add-head <?= $edit_task ? 'open' : '' ?>" id="addHead" onclick="toggleForm()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          <span class="add-title"><?= $edit_task ? '✏️ Edit: '.htmlspecialchars($edit_task['label']) : '+ Add new cron task' ?></span>
          <?php if ($edit_task): ?><a href="?" style="font-size:12px;color:#2563eb;text-decoration:none;font-weight:600;margin-right:8px">+ Add new instead</a><?php endif; ?>
          <svg id="addChev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" style="margin-left:<?= $edit_task?'0':'auto' ?>;transition:transform .2s"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <div class="add-body <?= $edit_task ? 'open' : '' ?>" id="addBody">
          <form method="POST" id="aadCronSec">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="<?= $edit_task ? 'edit_task' : 'add_task' ?>">
            <?php if ($edit_task): ?><input type="hidden" name="task_id" value="<?= $edit_task['id'] ?>"><?php endif; ?>
            <div class="fg">
              <div>
                <label class="flbl">Task key <span style="font-weight:400;color:#94a3b8;text-transform:none">(unique, lowercase, underscores)</span></label>
                <input name="task_key" class="finp" style="font-family:'JetBrains Mono',monospace" required placeholder="cron_my_task"
                       value="<?= htmlspecialchars($edit_task['task_key'] ?? '') ?>" <?= $edit_task ? 'readonly' : '' ?>>
              </div>
              <div>
                <label class="flbl">Label</label>
                <input name="label" class="finp" required placeholder="My Task" value="<?= htmlspecialchars($edit_task['label'] ?? '') ?>">
              </div>
            </div>
            <div class="fg">
              <div>
                <label class="flbl">PHP file <span style="font-weight:400;color:#94a3b8;text-transform:none">(relative to cron/)</span></label>
                <input name="file" class="finp" style="font-family:'JetBrains Mono',monospace" required placeholder="my-task.php" value="<?= htmlspecialchars($edit_task['file'] ?? '') ?>">
              </div>
              <div>
                <label class="flbl">Interval</label>
                <select name="interval_seconds" class="finp">
                  <?php foreach ([60=>'Every 1 min',120=>'Every 2 min',300=>'Every 5 min',600=>'Every 10 min',900=>'Every 15 min',1800=>'Every 30 min',3600=>'Every 1 hour',7200=>'Every 2 hours',10800=>'Every 3 hours',21600=>'Every 6 hours',43200=>'Every 12 hours',86400=>'Every 24 hours'] as $s=>$l): ?>
                  <option value="<?= $s ?>" <?= ($edit_task['interval_seconds']??3600)==$s?'selected':'' ?>><?= $l ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="fg3">
              <div>
                <label class="flbl">Group</label>
                <input name="group_name" class="finp" placeholder="System" value="<?= htmlspecialchars($edit_task['group_name'] ?? 'System') ?>">
              </div>
              <div>
                <label class="flbl">Sort order</label>
                <input type="number" name="sort_order" class="finp" value="<?= $edit_task['sort_order'] ?? 0 ?>">
              </div>
              <div>
                <label class="flbl">Status</label>
                <select name="enabled" class="finp">
                  <option value="1" <?= ($edit_task['enabled']??1)?'selected':'' ?>>Enabled</option>
                  <option value="0" <?= isset($edit_task)&&!$edit_task['enabled']?'selected':'' ?>>Disabled</option>
                </select>
              </div>
            </div>
            <div style="margin-bottom:16px">
              <label class="flbl">Description</label>
              <input name="description" class="finp" placeholder="What does this task do?" value="<?= htmlspecialchars($edit_task['description'] ?? '') ?>">
            </div>
            <div style="display:flex;gap:8px">
              <button type="submit" class="btn-save">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
                <?= $edit_task ? 'Update task' : 'Add task' ?>
              </button>
              <?php if ($edit_task): ?><a href="?" class="act-btn ab-edit" style="text-decoration:none">Cancel</a><?php endif; ?>
            </div>
          </form>
        </div>
      </div>

      <!-- cPanel Setup -->
      <div class="setup-card">
        <div class="setup-head">📋 cPanel — Sirf yeh ek cron daalo</div>
        <div class="setup-body">
          <div class="setup-sub">cPanel → Cron Jobs → Every Minute → paste this command:</div>
          <div class="cron-box" id="cronBox">
            <button class="cron-copy" onclick="copyCron(this)">Copy</button>
            * * * * * /usr/local/bin/php <?= dirname(__DIR__) ?>/cron/master.php
          </div>
          <div class="setup-hint">Naye tasks sirf "Add task" se daalo — cPanel mein dobara kuch nahi karna.</div>
        </div>
      </div>

    </div><!-- /page -->
  </div><!-- /main -->
</div><!-- /shell -->

<script>
// ── Graph data from PHP ─────────────────────────────────────────
const GRAPH_DATA = <?= json_encode($all_graph_data) ?>;
const PALETTE    = <?= json_encode($palette) ?>;
const HOUR_LABELS = Array.from({length:24}, (_,i) => String(i).padStart(2,'0')+':00');

let mainChart = null;
let currentMetric = 'runs';

function getDatasets(metric) {
  const keys = Object.keys(GRAPH_DATA);
  return keys.map((key, idx) => {
    const info = GRAPH_DATA[key];
    const color = PALETTE[idx % PALETTE.length];
    const vals = info.data.map(h => {
      if (metric === 'runs')     return h.ok + h.err;
      if (metric === 'errors')   return h.err;
      if (metric === 'duration') return h.avg_ms;
      return 0;
    });
    return {
      label: info.label,
      data: vals,
      borderColor: color,
      backgroundColor: color + '18',
      borderWidth: 1.8,
      pointRadius: 0,
      pointHoverRadius: 4,
      fill: false,
      tension: 0.4
    };
  });
}

function applyInterp() {
  if (!mainChart) return;
  const mode = document.getElementById('interpSel').value;
  mainChart.data.datasets.forEach(ds => {
    ds.tension = (mode === 'basis') ? 0.6 : (mode === 'default') ? 0 : 0.4;
    ds.stepped = (mode === 'step') ? true : false;
    ds.cubicInterpolationMode = (mode === 'monotone') ? 'monotone' : 'default';
  });
  mainChart.update();
}

function switchMetric(metric, btn) {
  currentMetric = metric;
  document.querySelectorAll('.metric-tab').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  if (!mainChart) return;
  mainChart.data.datasets = getDatasets(metric);
  mainChart.update();
  applyInterp();
}

function buildLegend() {
  const legend = document.getElementById('chartLegend');
  if (!legend) return;
  const keys = Object.keys(GRAPH_DATA);
  legend.innerHTML = keys.map((key, idx) => {
    const color = PALETTE[idx % PALETTE.length];
    return `<span class="legend-item"><span class="legend-swatch" style="background:${color}"></span>${GRAPH_DATA[key].label}</span>`;
  }).join('');
}

function initChart() {
  const canvas = document.getElementById('mainChart');
  if (!canvas) return;
  mainChart = new Chart(canvas, {
    type: 'line',
    data: { labels: HOUR_LABELS, datasets: getDatasets('runs') },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#fff',
          titleColor: '#0f172a',
          bodyColor: '#64748b',
          borderColor: '#e2e8f0',
          borderWidth: 1,
          padding: 10,
          callbacks: {
            label: ctx => ' ' + ctx.dataset.label + ': ' + ctx.parsed.y + (currentMetric === 'duration' ? 'ms' : '')
          }
        }
      },
      scales: {
        x: { grid: { display: false }, ticks: { font: { size: 10, family: "'JetBrains Mono',monospace" }, color: '#94a3b8', maxTicksLimit: 12 } },
        y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { font: { size: 10 }, color: '#94a3b8' } }
      },
      elements: { line: { cubicInterpolationMode: 'monotone' } }
    }
  });
  buildLegend();
}

// ── Task card expand ───────────────────────────────────────────
function toggleCard(id) {
  document.getElementById('tc_' + id).classList.toggle('tc-open');
}

// ── Add form toggle ────────────────────────────────────────────
function toggleForm() {
  const body = document.getElementById('addBody');
  const head = document.getElementById('addHead');
  const chev = document.getElementById('addChev');
  body.classList.toggle('open');
  head.classList.toggle('open');
  if (chev) chev.style.transform = body.classList.contains('open') ? 'rotate(180deg)' : '';
}

// ── Copy cron command ──────────────────────────────────────────
function copyCron(btn) {
  const cmd = '* * * * * /usr/local/bin/php <?= addslashes(dirname(__DIR__)) ?>/cron/master.php';
  navigator.clipboard.writeText(cmd).then(() => {
    btn.textContent = '✓ Copied!';
    btn.style.color = '#3fb950';
    setTimeout(() => { btn.textContent = 'Copy'; btn.style.color = ''; }, 2000);
  });
}

// ── Live clock + auto-refresh ──────────────────────────────────
var cd = 30;
var timer = setInterval(() => {
  cd--;
  document.getElementById('countdown').textContent = cd;
  if (cd <= 0) { clearInterval(timer); location.reload(); }
}, 1000);

setInterval(() => {
  const d = new Date();
  document.getElementById('live-time').textContent =
    String(d.getHours()).padStart(2,'0') + ':' +
    String(d.getMinutes()).padStart(2,'0') + ':' +
    String(d.getSeconds()).padStart(2,'0');
}, 1000);

// ── Init ───────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', initChart);
document.getElementById('aadCronSec').addEventListener('submit', function () {
    const btn = this.querySelector('.btn-save');
    const action = this.querySelector('[name="action"]').value;

    btn.disabled = true;

    btn.innerHTML = `
        <span class="spinner"></span>
        ${action === 'edit_task' ? 'Updating Cron...' : 'Adding Cron...'}
    `;
});
</script>
</body>
</html>