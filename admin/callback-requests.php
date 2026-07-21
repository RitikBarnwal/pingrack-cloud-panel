<?php
/**
 * admin/callback-requests.php
 * Manage callback requests: enable/disable feature, manage departments,
 * manage time slots, and view/update submitted requests.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin.php';
require_admin();

$user     = current_user();
$app_name = APP_NAME;
$csrf     = csrf_token();
$tab      = $_GET['tab'] ?? 'requests';
$msg      = '';
$err      = '';

// ── POST Handlers ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    // Toggle feature on/off
    if ($action === 'toggle_feature') {
        $val = ($_POST['callback_enabled'] ?? '0') === '1' ? '1' : '0';
        set_setting('callback_enabled', $val);
        $msg = 'Feature setting updated.';
    }

    // Add department
    if ($action === 'add_dept') {
        $name = trim($_POST['dept_name'] ?? '');
        if ($name === '') { $err = 'Department name cannot be empty.'; }
        else {
            $max = db()->query("SELECT COALESCE(MAX(sort_order),0) FROM callback_departments")->fetchColumn();
            db()->prepare("INSERT INTO callback_departments (name, sort_order, is_active) VALUES (?,?,1)")
               ->execute([$name, (int)$max + 1]);
            $msg = 'Department added.';
        }
    }

    // Toggle dept active
    if ($action === 'toggle_dept') {
        $id = (int)($_POST['dept_id'] ?? 0);
        $is = (int)($_POST['is_active'] ?? 0);
        db()->prepare("UPDATE callback_departments SET is_active=? WHERE id=?")->execute([$is ? 1 : 0, $id]);
        $msg = 'Department updated.';
    }

    // Delete dept
    if ($action === 'delete_dept') {
        $id = (int)($_POST['dept_id'] ?? 0);
        db()->prepare("DELETE FROM callback_departments WHERE id=?")->execute([$id]);
        $msg = 'Department deleted.';
    }

    // Add time slot
    if ($action === 'add_slot') {
        $label = trim($_POST['slot_label'] ?? '');
        if ($label === '') { $err = 'Time slot label cannot be empty.'; }
        else {
            $max = db()->query("SELECT COALESCE(MAX(sort_order),0) FROM callback_timeslots")->fetchColumn();
            db()->prepare("INSERT INTO callback_timeslots (label, sort_order, is_active) VALUES (?,?,1)")
               ->execute([$label, (int)$max + 1]);
            $msg = 'Time slot added.';
        }
    }

    // Toggle slot
    if ($action === 'toggle_slot') {
        $id = (int)($_POST['slot_id'] ?? 0);
        $is = (int)($_POST['is_active'] ?? 0);
        db()->prepare("UPDATE callback_timeslots SET is_active=? WHERE id=?")->execute([$is ? 1 : 0, $id]);
        $msg = 'Time slot updated.';
    }

    // Delete slot
    if ($action === 'delete_slot') {
        $id = (int)($_POST['slot_id'] ?? 0);
        db()->prepare("DELETE FROM callback_timeslots WHERE id=?")->execute([$id]);
        $msg = 'Time slot deleted.';
    }

    // Update request status / note
    if ($action === 'update_request') {
        $id     = (int)($_POST['req_id'] ?? 0);
        $status = $_POST['req_status'] ?? 'pending';
        $note   = trim($_POST['admin_note'] ?? '');
        if (!in_array($status, ['pending','called','cancelled'])) $status = 'pending';
        db()->prepare("UPDATE callback_requests SET status=?, admin_note=? WHERE id=?")
           ->execute([$status, $note, $id]);
        $msg = 'Request updated.';
    }

    if (!headers_sent()) {
        header("Location: /admin/callback-requests.php?tab={$tab}" . ($msg ? '&msg='.urlencode($msg) : '') . ($err ? '&err='.urlencode($err) : ''));
        exit;
    }
}

if (!$msg && !$err) {
    $msg = urldecode($_GET['msg'] ?? '');
    $err = urldecode($_GET['err'] ?? '');
}

// ── Fetch data ─────────────────────────────────────────────────────────────
$cb_enabled  = get_setting('callback_enabled', '1') === '1';
$departments = db()->query("SELECT * FROM callback_departments ORDER BY sort_order, id")->fetchAll();
$timeslots   = db()->query("SELECT * FROM callback_timeslots ORDER BY sort_order, id")->fetchAll();

// Requests with pagination
$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 20;
$offset   = ($page_num - 1) * $per_page;
$filter_status = $_GET['s'] ?? '';
$where = $filter_status ? "WHERE r.status=?" : "";
$params = $filter_status ? [$filter_status] : [];

$total_st = db()->prepare("SELECT COUNT(*) FROM callback_requests r $where");
$total_st->execute($params);
$total = (int)$total_st->fetchColumn();
$pages = max(1, ceil($total / $per_page));

$req_st = db()->prepare("
    SELECT r.*, u.username, u.email
    FROM callback_requests r
    LEFT JOIN users u ON u.id = r.user_id
    $where
    ORDER BY r.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$req_st->execute($params);
$requests = $req_st->fetchAll();

// Stats
$stats = db()->query("
    SELECT
      SUM(status='pending')   AS pending,
      SUM(status='called')    AS called,
      SUM(status='cancelled') AS cancelled,
      COUNT(*) AS total
    FROM callback_requests
")->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Callback Requests — <?= $app_name ?> Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/admin/admin.css">
  <style>
    /* ── Layout ─────────────────────────────────────────────── */
    .adm-shell{display:flex;min-height:100vh}
    
    .adm-logo{padding:18px 16px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:8px}
    .adm-logo-mark{width:28px;height:28px;border-radius:6px;background:var(--primary);display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .adm-logo-text{font-weight:800;font-size:14px;color:white;letter-spacing:-.3px}
    .adm-nav{flex:1;padding:10px 8px;overflow-y:auto}
    .adm-nav-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:rgba(255,255,255,.3);padding:10px 8px 4px}
    .adm-link{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:7px;font-size:13px;font-weight:500;color:rgba(255,255,255,.6);text-decoration:none;transition:all .14s;margin-bottom:1px}
    .adm-link:hover{background:rgba(255,255,255,.08);color:rgba(255,255,255,.9)}
    .adm-link.active{background:#22293b;color:white;font-weight:700}
    .adm-link svg{width:15px;height:15px;flex-shrink:0}
    .adm-footer-bar{padding:12px 10px;border-top:1px solid rgba(255,255,255,.08)}
    .adm-av{width:30px;height:30px;border-radius:7px;background:var(--primary);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:white;flex-shrink:0}
    .adm-main{margin-left:232px;flex:1;background:var(--gray-50);min-height:100vh}
    .adm-topbar{background:white;border-bottom:1px solid var(--border);height:56px;display:flex;align-items:center;padding:0 28px;gap:10px;position:sticky;top:0;z-index:30}
    .adm-topbar-title{font-size:15px;font-weight:800;color:var(--gray-900)}

    /* ── Page ───────────────────────────────────────────────── */
    .page{max-width:1050px;padding:24px 28px}
    .card{background:white;border:1px solid var(--border);border-radius:13px;overflow:hidden;margin-bottom:20px}
    .card-head{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:9px}
    .card-icon{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:14px}
    .card-title{font-size:14px;font-weight:800;color:var(--gray-900)}
    .card-body{padding:20px}
    .flabel{display:block;font-size:12px;font-weight:700;color:var(--gray-700);margin-bottom:5px}
    .form-control{width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:13px;color:var(--gray-900);outline:none;transition:border-color .13s;box-sizing:border-box}
    .form-control:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-ring)}
    .btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:8px;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;border:none;transition:all .15s}
    .btn-primary{background:var(--primary);color:white}.btn-primary:hover{background:var(--primary-hover)}
    .btn-sm{padding:5px 12px;font-size:12px;border-radius:6px}
    .btn-ghost{background:transparent;color:var(--gray-500);border:1.5px solid var(--border)}.btn-ghost:hover{border-color:#94a3b8;color:var(--gray-800)}
    .btn-danger{background:#fee2e2;color:#dc2626;border:none}.btn-danger:hover{background:#fecaca}

    /* ── Tabs ───────────────────────────────────────────────── */
    .tabs{display:flex;gap:6px;margin-bottom:22px;flex-wrap:wrap}
    .tab-btn{padding:7px 16px;border-radius:8px;font-size:12.5px;font-weight:700;border:1.5px solid #e2e8f0;background:white;color:#64748b;cursor:pointer;transition:all .14s;text-decoration:none}
    .tab-btn:hover{border-color:#94a3b8;color:#1e293b}
    .tab-btn.on{background:#0f172a;border-color:#0f172a;color:white}

    /* ── Stats ──────────────────────────────────────────────── */
    .stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
    .stat-card{background:white;border:1px solid var(--border);border-radius:11px;padding:14px 18px}
    .stat-val{font-size:24px;font-weight:900;color:var(--gray-900)}
    .stat-lbl{font-size:11.5px;font-weight:600;color:var(--gray-400);margin-top:2px}

    /* ── Table ──────────────────────────────────────────────── */
    .tbl{width:100%;border-collapse:collapse;font-size:13px}
    .tbl th{padding:9px 12px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gray-500);border-bottom:1px solid var(--border);background:var(--gray-50)}
    .tbl td{padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
    .tbl tr:last-child td{border-bottom:none}
    .tbl tr:hover td{background:#fafbfc}

    /* ── Badges ─────────────────────────────────────────────── */
    .badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:99px;font-size:11.5px;font-weight:700}
    .badge-green{background:#f0fdf4;color:#16a34a}
    .badge-yellow{background:#fffbeb;color:#d97706}
    .badge-red{background:#fef2f2;color:#dc2626}
    .badge-gray{background:var(--gray-100);color:var(--gray-500)}
    .badge-blue{background:#eff6ff;color:#2563eb}

    /* ── Toggle switch ──────────────────────────────────────── */
    .toggle-wrap{display:flex;align-items:center;gap:10px}
    .toggle{position:relative;display:inline-block;width:40px;height:22px}
    .toggle input{opacity:0;width:0;height:0}
    .slider{position:absolute;cursor:pointer;inset:0;background:#e2e8f0;transition:.3s;border-radius:22px}
    .slider:before{position:absolute;content:"";height:16px;width:16px;left:3px;bottom:3px;background:white;transition:.3s;border-radius:50%}
    input:checked+.slider{background:var(--primary)}
    input:checked+.slider:before{transform:translateX(18px)}

    /* ── List items for depts/slots ─────────────────────────── */
    .list-item{display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid var(--border);border-radius:9px;margin-bottom:8px;background:white}
    .list-item-name{flex:1;font-size:13px;font-weight:600;color:var(--gray-800)}

    /* ── Request detail modal ───────────────────────────────── */
    .modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.45);backdrop-filter:blur(3px);z-index:200;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s}
    .modal-bg.open{opacity:1;pointer-events:all}
    .modal-box{background:white;border-radius:14px;width:520px;max-width:95vw;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);transform:translateY(10px);transition:transform .2s}
    .modal-bg.open .modal-box{transform:translateY(0)}
    .modal-head{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
    .modal-head h3{font-size:15px;font-weight:800}
    .modal-body{padding:20px 22px}
    .modal-close{background:none;border:none;cursor:pointer;color:var(--gray-400);padding:4px;border-radius:5px}
    .modal-close:hover{color:var(--gray-700);background:var(--gray-100)}
    .detail-row{display:flex;gap:8px;margin-bottom:10px;font-size:13px}
    .detail-lbl{font-weight:700;color:var(--gray-500);width:110px;flex-shrink:0}
    .detail-val{color:var(--gray-800)}

    /* ── Flash ──────────────────────────────────────────────── */
    .flash{padding:10px 16px;border-radius:9px;font-size:13px;font-weight:600;margin-bottom:18px}
    .flash-ok{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0}
    .flash-err{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}

    /* ── Enable/disable big toggle ──────────────────────────── */
    .feature-toggle-card{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;background:white;border:1px solid var(--border);border-radius:13px;margin-bottom:20px}
    .ft-info h3{font-size:14px;font-weight:800;color:var(--gray-900)}
    .ft-info p{font-size:12.5px;color:var(--gray-500);margin-top:3px}
  
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
<div class="adm-overlay" id="adm-overlay" onclick="admCloseSidebar()"></div>
<div class="adm-mobile-bar">
  <button class="adm-ham" onclick="admToggleSidebar()">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
  </button>
  <span class="adm-mobile-title"><?= APP_NAME ?> <span style="font-size:9px;background:#dc2626;color:#fff;padding:2px 6px;border-radius:99px;font-weight:700;text-transform:uppercase;vertical-align:middle">Admin</span></span>
</div>
<div class="adm-shell">

  <?php include 'sidebar.php'; ?>

  <!-- ── Main Content ───────────────────────────────────────────── -->
  <main class="adm-main">
    <div class="adm-topbar">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 11.9 19.79 19.79 0 0 1 1.61 3.31 2 2 0 0 1 3.6 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.6a16 16 0 0 0 6 6l.96-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
      <span class="adm-topbar-title">Callback Requests</span>
      <span style="font-size:12px;color:var(--gray-400);margin-left:4px">— Manage & Configure</span>
    </div>

    <div class="page">

      <?php if ($msg): ?><div class="flash flash-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="flash flash-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

      <!-- ── Stats ─────────────────────────────────────────── -->
      <div class="stats-row">
        <div class="stat-card">
          <div class="stat-val"><?= (int)($stats['total'] ?? 0) ?></div>
          <div class="stat-lbl">Total Requests</div>
        </div>
        <div class="stat-card">
          <div class="stat-val" style="color:#d97706"><?= (int)($stats['pending'] ?? 0) ?></div>
          <div class="stat-lbl">Pending</div>
        </div>
        <div class="stat-card">
          <div class="stat-val" style="color:#16a34a"><?= (int)($stats['called'] ?? 0) ?></div>
          <div class="stat-lbl">Called</div>
        </div>
        <div class="stat-card">
          <div class="stat-val" style="color:#6b7280"><?= (int)($stats['cancelled'] ?? 0) ?></div>
          <div class="stat-lbl">Cancelled</div>
        </div>
      </div>

      <!-- ── Feature Toggle ────────────────────────────────── -->
      <div class="feature-toggle-card">
        <div class="ft-info">
          <h3>📞 Request a Callback Feature</h3>
          <p>When enabled, clients see a "Request Callback" button in their sidebar.</p>
        </div>
        <form method="post" style="display:flex;align-items:center;gap:10px">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="toggle_feature">
          <input type="hidden" name="callback_enabled" value="<?= $cb_enabled ? '0' : '1' ?>">
          <label class="toggle-wrap" style="cursor:pointer">
            <label class="toggle">
              <input type="checkbox" <?= $cb_enabled ? 'checked' : '' ?> onchange="this.closest('form').querySelector('[name=callback_enabled]').value=this.checked?'1':'0';this.closest('form').submit()">
              <span class="slider"></span>
            </label>
            <span style="font-size:13px;font-weight:700;color:<?= $cb_enabled ? '#16a34a' : '#6b7280' ?>"><?= $cb_enabled ? 'Enabled' : 'Disabled' ?></span>
          </label>
        </form>
      </div>

      <!-- ── Tabs ──────────────────────────────────────────── -->
      <div class="tabs">
        <a href="?tab=requests" class="tab-btn <?= $tab==='requests'?'on':'' ?>">📋 Requests</a>
        <a href="?tab=departments" class="tab-btn <?= $tab==='departments'?'on':'' ?>">🏢 Departments</a>
        <a href="?tab=timeslots" class="tab-btn <?= $tab==='timeslots'?'on':'' ?>">🕐 Time Slots</a>
      </div>

      <!-- ═══════════════════════════════════════════════════ -->
      <!-- TAB: Requests                                       -->
      <!-- ═══════════════════════════════════════════════════ -->
      <?php if ($tab === 'requests'): ?>

      <!-- Filter bar -->
      <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
        <?php foreach ([''=>'All','pending'=>'Pending','called'=>'Called','cancelled'=>'Cancelled'] as $sv=>$sl): ?>
          <a href="?tab=requests&s=<?= $sv ?>" style="padding:5px 14px;border-radius:8px;font-size:12.5px;font-weight:700;border:1.5px solid <?= $filter_status===$sv?'#0f172a':'#e2e8f0' ?>;background:<?= $filter_status===$sv?'#0f172a':'white' ?>;color:<?= $filter_status===$sv?'white':'#64748b' ?>;text-decoration:none"><?= $sl ?></a>
        <?php endforeach; ?>
      </div>

      <div class="card">
        <div style="overflow-x:auto">
        <table class="tbl">
          <thead>
            <tr>
              <th>#</th>
              <th>Name / User</th>
              <th>Phone</th>
              <th>Department</th>
              <th>Preferred Time</th>
              <th>Status</th>
              <th>Date</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($requests)): ?>
            <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--gray-400)">No callback requests found.</td></tr>
          <?php endif; ?>
          <?php foreach ($requests as $r): ?>
            <tr>
              <td style="font-weight:700;color:var(--gray-400)">#<?= $r['id'] ?></td>
              <td>
                <div style="font-weight:700;font-size:13px"><?= htmlspecialchars($r['name']) ?></div>
                <?php if ($r['username']): ?>
                  <div style="font-size:11px;color:var(--gray-400)">@<?= htmlspecialchars($r['username']) ?></div>
                <?php endif; ?>
              </td>
              <td style="font-family:monospace"><?= htmlspecialchars($r['phone']) ?></td>
              <td><?= htmlspecialchars($r['department']) ?></td>
              <td><?= $r['preferred_time'] ? htmlspecialchars($r['preferred_time']) : '<span style="color:#9ca3af">—</span>' ?></td>
              <td>
                <?php
                  $badges = ['pending'=>'badge-yellow','called'=>'badge-green','cancelled'=>'badge-gray'];
                  $labels = ['pending'=>'Pending','called'=>'Called','cancelled'=>'Cancelled'];
                ?>
                <span class="badge <?= $badges[$r['status']] ?? 'badge-gray' ?>"><?= $labels[$r['status']] ?? $r['status'] ?></span>
              </td>
              <td style="font-size:12px;color:var(--gray-400)"><?= date('d M y, h:i A', strtotime($r['created_at'])) ?></td>
              <td>
                <button class="btn btn-ghost btn-sm" onclick="openModal(<?= htmlspecialchars(json_encode($r)) ?>)">View</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php if ($pages > 1): ?>
        <div style="padding:12px 16px;border-top:1px solid var(--border);display:flex;gap:6px;flex-wrap:wrap">
          <?php for ($i=1;$i<=$pages;$i++): ?>
            <a href="?tab=requests&s=<?= $filter_status ?>&p=<?= $i ?>" style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;font-size:13px;font-weight:700;border:1.5px solid <?= $i===$page_num?'#0f172a':'#e2e8f0' ?>;background:<?= $i===$page_num?'#0f172a':'white' ?>;color:<?= $i===$page_num?'white':'#64748b' ?>;text-decoration:none"><?= $i ?></a>
          <?php endfor; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- ═══════════════════════════════════════════════════ -->
      <!-- TAB: Departments                                    -->
      <!-- ═══════════════════════════════════════════════════ -->
      <?php elseif ($tab === 'departments'): ?>
      <div class="card">
        <div class="card-head">
          <div class="card-icon" style="background:#eff6ff">🏢</div>
          <span class="card-title">Departments</span>
        </div>
        <div class="card-body">

          <!-- Add form -->
          <form method="post" style="display:flex;gap:10px;margin-bottom:20px">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="add_dept">
            <input type="text" name="dept_name" placeholder="e.g. Cloud Support" class="form-control" style="max-width:280px" required>
            <button type="submit" class="btn btn-primary">+ Add Department</button>
          </form>

          <!-- List -->
          <?php if (empty($departments)): ?>
            <p style="color:var(--gray-400);font-size:13px">No departments yet.</p>
          <?php endif; ?>
          <?php foreach ($departments as $d): ?>
          <div class="list-item">
            <span style="font-size:16px">🏢</span>
            <span class="list-item-name"><?= htmlspecialchars($d['name']) ?></span>
            <span class="badge <?= $d['is_active'] ? 'badge-green' : 'badge-gray' ?>"><?= $d['is_active'] ? 'Active' : 'Inactive' ?></span>
            <!-- Toggle -->
            <form method="post" style="margin:0">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="toggle_dept">
              <input type="hidden" name="dept_id" value="<?= $d['id'] ?>">
              <input type="hidden" name="is_active" value="<?= $d['is_active'] ? '0' : '1' ?>">
              <button type="submit" class="btn btn-ghost btn-sm"><?= $d['is_active'] ? 'Disable' : 'Enable' ?></button>
            </form>
            <!-- Delete -->
            <form method="post" style="margin:0" onsubmit="return confirm('Delete this department?')">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="delete_dept">
              <input type="hidden" name="dept_id" value="<?= $d['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- ═══════════════════════════════════════════════════ -->
      <!-- TAB: Time Slots                                     -->
      <!-- ═══════════════════════════════════════════════════ -->
      <?php elseif ($tab === 'timeslots'): ?>
      <div class="card">
        <div class="card-head">
          <div class="card-icon" style="background:#fefce8">🕐</div>
          <span class="card-title">Preferred Time Slots</span>
        </div>
        <div class="card-body">

          <!-- Add form -->
          <form method="post" style="display:flex;gap:10px;margin-bottom:20px">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="add_slot">
            <input type="text" name="slot_label" placeholder="e.g. Morning (9 AM - 12 PM)" class="form-control" style="max-width:320px" required>
            <button type="submit" class="btn btn-primary">+ Add Time Slot</button>
          </form>

          <!-- List -->
          <?php if (empty($timeslots)): ?>
            <p style="color:var(--gray-400);font-size:13px">No time slots yet.</p>
          <?php endif; ?>
          <?php foreach ($timeslots as $ts): ?>
          <div class="list-item">
            <span style="font-size:16px">🕐</span>
            <span class="list-item-name"><?= htmlspecialchars($ts['label']) ?></span>
            <span class="badge <?= $ts['is_active'] ? 'badge-green' : 'badge-gray' ?>"><?= $ts['is_active'] ? 'Active' : 'Inactive' ?></span>
            <form method="post" style="margin:0">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="toggle_slot">
              <input type="hidden" name="slot_id" value="<?= $ts['id'] ?>">
              <input type="hidden" name="is_active" value="<?= $ts['is_active'] ? '0' : '1' ?>">
              <button type="submit" class="btn btn-ghost btn-sm"><?= $ts['is_active'] ? 'Disable' : 'Enable' ?></button>
            </form>
            <form method="post" style="margin:0" onsubmit="return confirm('Delete this time slot?')">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="delete_slot">
              <input type="hidden" name="slot_id" value="<?= $ts['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /page -->
  </main>
</div>

<!-- ── Request Detail / Edit Modal ────────────────────────────────── -->
<div class="modal-bg" id="reqModal">
  <div class="modal-box">
    <div class="modal-head">
      <h3>📞 Callback Request Detail</h3>
      <button class="modal-close" onclick="closeModal()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="modal-body">
      <div id="modal-details"></div>
      <form method="post" id="modal-form" style="margin-top:16px">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="update_request">
        <input type="hidden" name="req_id" id="modal-req-id">
        <label class="flabel">Status</label>
        <select name="req_status" id="modal-status" class="form-control" style="margin-bottom:12px">
          <option value="pending">Pending</option>
          <option value="called">Called</option>
          <option value="cancelled">Cancelled</option>
        </select>
        <label class="flabel">Admin Note (optional)</label>
        <textarea name="admin_note" id="modal-note" class="form-control" rows="3" placeholder="Internal note..."></textarea>
        <div style="margin-top:14px;display:flex;gap:8px;justify-content:flex-end">
          <button type="button" class="btn btn-ghost" onclick="closeModal()">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openModal(r) {
  document.getElementById('modal-req-id').value = r.id;
  document.getElementById('modal-status').value = r.status;
  document.getElementById('modal-note').value = r.admin_note || '';

  const statusBadge = {pending:'#d97706',called:'#16a34a',cancelled:'#6b7280'};
  const statusLabel = {pending:'Pending',called:'Called',cancelled:'Cancelled'};

  document.getElementById('modal-details').innerHTML = `
    <div class="detail-row"><span class="detail-lbl">Name</span><span class="detail-val">${escHtml(r.name)}</span></div>
    <div class="detail-row"><span class="detail-lbl">Phone</span><span class="detail-val" style="font-family:monospace">${escHtml(r.phone)}</span></div>
    <div class="detail-row"><span class="detail-lbl">Department</span><span class="detail-val">${escHtml(r.department)}</span></div>
    <div class="detail-row"><span class="detail-lbl">Preferred Time</span><span class="detail-val">${escHtml(r.preferred_time || '—')}</span></div>
    <div class="detail-row"><span class="detail-lbl">Message</span><span class="detail-val" style="white-space:pre-wrap;word-break:break-word">${escHtml(r.message)}</span></div>
    <div class="detail-row"><span class="detail-lbl">Submitted</span><span class="detail-val">${escHtml(r.created_at)}</span></div>
    <hr style="border:none;border-top:1px solid #f1f5f9;margin:12px 0">
  `;

  document.getElementById('reqModal').classList.add('open');
}
function closeModal() {
  document.getElementById('reqModal').classList.remove('open');
}
function escHtml(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
document.getElementById('reqModal').addEventListener('click', function(e){
  if(e.target===this) closeModal();
});
document.addEventListener('keydown', function(e){if(e.key==='Escape') closeModal();});
</script>
</body>
</html>
