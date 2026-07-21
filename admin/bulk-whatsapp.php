<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin.php';
require_admin();

$user     = current_user();
$app_name = APP_NAME;
$csrf     = csrf_token();
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));

$tab = $_GET['tab'] ?? 'whatsapp';

// ── Ensure DB tables exist ──────────────────────────────────
try {
    db()->exec("
        CREATE TABLE IF NOT EXISTS wa_campaigns (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            message       TEXT NOT NULL,
            target        VARCHAR(50) NOT NULL DEFAULT 'all',
            custom_numbers TEXT NULL COMMENT 'JSON array for custom target',
            delay_seconds INT UNSIGNED NOT NULL DEFAULT 3,
            sent_by       INT UNSIGNED NOT NULL,
            total         INT UNSIGNED NOT NULL DEFAULT 0,
            sent          INT UNSIGNED NOT NULL DEFAULT 0,
            failed        INT UNSIGNED NOT NULL DEFAULT 0,
            failed_numbers TEXT NULL COMMENT 'JSON array of failed numbers',
            status        ENUM('queued','running','completed','stopped') NOT NULL DEFAULT 'queued',
            created_at    DATETIME NOT NULL,
            started_at    DATETIME NULL,
            finished_at   DATETIME NULL,
            INDEX idx_status (status),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    db()->exec("
        CREATE TABLE IF NOT EXISTS wa_queue (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campaign_id INT UNSIGNED NOT NULL,
            phone       VARCHAR(20) NOT NULL,
            name        VARCHAR(120) NOT NULL DEFAULT '',
            message     TEXT NOT NULL,
            status      ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
            attempted_at DATETIME NULL,
            INDEX idx_campaign_status (campaign_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) { /* tables may already exist */ }

// ── AJAX endpoints ──────────────────────────────────────────
if (!empty($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax'];

    // Status poll
    if ($action === 'status') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) { echo json_encode(['error'=>'no id']); exit; }
        $row = db()->prepare("SELECT id,total,sent,failed,failed_numbers,status,started_at,finished_at FROM wa_campaigns WHERE id=?")->execute([$id]) ? null : null;
        $stmt = db()->prepare("SELECT id,total,sent,failed,failed_numbers,status,started_at,finished_at FROM wa_campaigns WHERE id=?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($row ?: ['error'=>'not found']);
        exit;
    }

    // Stop campaign
    if ($action === 'stop') {
        verify_csrf($_POST['csrf_token'] ?? '') || (http_response_code(403) && exit);
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("UPDATE wa_campaigns SET status='stopped',finished_at=NOW() WHERE id=? AND status IN ('queued','running')")->execute([$id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // Active running campaigns (for auto-resume indicator)
    if ($action === 'running') {
        $rows = db()->query("SELECT id,total,sent,failed,status FROM wa_campaigns WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        exit;
    }
    exit;
}

// ── Handle POST: launch campaign ───────────────────────────
$toast = '';
$toast_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'launch') {
        $message      = trim($_POST['message'] ?? '');
        $target       = $_POST['target'] ?? 'all';
        $custom_nums  = trim($_POST['custom_numbers'] ?? '');
        $delay        = max(1, (int)($_POST['delay_seconds'] ?? 3));

        if (!$message) {
            $toast = 'Message is required.'; $toast_type = 'error';
        } else {
            // Build phone list
            $phones = [];

            if ($target === 'custom') {
                // Parse custom numbers
                foreach (preg_split('/[\s,;\n]+/', $custom_nums) as $num) {
                    $num = preg_replace('/\D/', '', trim($num));
                    if (strlen($num) >= 10) $phones[] = ['phone'=>$num,'name'=>'User'];
                }
            } else {
                $where = "status='active' AND phone IS NOT NULL AND phone != ''";
                if ($target === 'active_servers') {
                    $where .= " AND id IN (SELECT DISTINCT user_id FROM servers WHERE deleted_at IS NULL AND status='running')";
                } elseif ($target === 'no_servers') {
                    $where .= " AND id NOT IN (SELECT DISTINCT user_id FROM servers WHERE deleted_at IS NULL)";
                } elseif ($target === 'low_balance') {
                    $where .= " AND wallet_balance < 100";
                }
                $users = db()->query("SELECT full_name, username, phone FROM users WHERE $where ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($users as $u) {
                    $num = preg_replace('/\D/', '', $u['phone'] ?? '');
                    if (strlen($num) >= 10) $phones[] = ['phone'=>$num,'name'=>($u['full_name']?:$u['username'])];
                }
            }

            if (empty($phones)) {
                $toast = 'No users with phone numbers found for this target.'; $toast_type = 'error';
            } else {
                // Create campaign
                $stmt = db()->prepare("INSERT INTO wa_campaigns (message,target,custom_numbers,delay_seconds,sent_by,total,status,created_at)
                                       VALUES (?,?,?,?,?,?,'queued',NOW())");
                $stmt->execute([$message, $target, $target==='custom'?$custom_nums:null, $delay, $user['id'], count($phones)]);
                $cid = (int)db()->lastInsertId();

                // Enqueue rows
                $ins = db()->prepare("INSERT INTO wa_queue (campaign_id,phone,name,message,status) VALUES (?,?,?,?,'pending')");
                foreach ($phones as $p) {
                    $msg_rendered = str_replace(['{{name}}','{{app_name}}'], [$p['name'], $app_name], $message);
                    $ins->execute([$cid, $p['phone'], $p['name'], $msg_rendered]);
                }

                // Kick off background worker via cron-safe PHP CLI (non-blocking)
                $worker = escapeshellarg(__DIR__ . '/wa-queue.php');
                $php    = PHP_BINARY ?: 'php';
                @shell_exec("$php $worker > /dev/null 2>&1 &");

                $toast = "Campaign #$cid launched for " . count($phones) . " recipients!";
            }
        }
    }
}

// ── User counts ─────────────────────────────────────────────
try {
    $count_all    = (int)db()->query("SELECT COUNT(*) FROM users WHERE status='active' AND phone IS NOT NULL AND phone!=''")->fetchColumn();
    $count_active = (int)db()->query("SELECT COUNT(DISTINCT s.user_id) FROM servers s JOIN users u ON u.id=s.user_id WHERE s.deleted_at IS NULL AND s.status='running' AND u.phone IS NOT NULL AND u.phone!=''")->fetchColumn();
    $count_none   = (int)db()->query("SELECT COUNT(*) FROM users WHERE status='active' AND phone IS NOT NULL AND phone!='' AND id NOT IN (SELECT DISTINCT user_id FROM servers WHERE deleted_at IS NULL)")->fetchColumn();
    $count_low    = (int)db()->query("SELECT COUNT(*) FROM users WHERE status='active' AND wallet_balance < 100 AND phone IS NOT NULL AND phone!=''")->fetchColumn();
} catch (Throwable $e) {
    $count_all = $count_active = $count_none = $count_low = 0;
}

// ── Campaign history ────────────────────────────────────────
$campaigns = [];
try {
    $campaigns = db()->query("SELECT c.*, u.full_name as sender_name FROM wa_campaigns c LEFT JOIN users u ON u.id=c.sent_by ORDER BY c.created_at DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Running campaigns (for live progress on load)
$running = [];
try {
    $running = db()->query("SELECT id FROM wa_campaigns WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

$wa_api   = get_setting('wa_api')   ?? '';
$wa_token = get_setting('wa_token') ?? '';
$api_ok   = !empty($wa_api) && !empty($wa_token);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script>(function(){var t=localStorage.getItem('cv_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Bulk WhatsApp — <?= $app_name ?> Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/admin/admin.css">
  <style>
    /* ── Admin shell ──────────────────────────── */
    .adm-shell{display:flex;min-height:100vh}
    
    .adm-logo{padding:18px 16px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:8px}
    .adm-logo-mark{width:28px;height:28px;border-radius:6px;background:var(--primary);display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .adm-logo-text{font-weight:800;font-size:14px;color:white;letter-spacing:-.3px}
    .adm-badge{font-size:9px;font-weight:700;background:#dc2626;color:white;padding:1px 6px;border-radius:99px;margin-left:4px;text-transform:uppercase}
    .adm-nav{flex:1;padding:10px 8px;overflow-y:auto}
    .adm-nav-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:rgba(255,255,255,.3);padding:10px 8px 4px}
    .adm-link{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:7px;font-size:13px;font-weight:500;color:rgba(255,255,255,.6);text-decoration:none;transition:all .14s;margin-bottom:1px}
    .adm-link:hover{background:rgba(255,255,255,.08);color:rgba(255,255,255,.9)}
    .adm-link.active{background:#22293b;color:white;font-weight:700}
    .adm-link svg{width:15px;height:15px;flex-shrink:0}
    .adm-footer-bar{padding:12px 10px;border-top:1px solid rgba(255,255,255,.08)}
    .adm-av{width:30px;height:30px;border-radius:7px;background:var(--primary);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:white;flex-shrink:0}
    .adm-main{margin-left:232px;flex:1;background:var(--bg);min-height:100vh}
    .adm-topbar{background:var(--surface);border-bottom:1px solid var(--border);height:56px;display:flex;align-items:center;padding:0 28px;position:sticky;top:0;z-index:30;gap:12px}
    .adm-topbar-title{font-size:15px;font-weight:800;color:var(--text)}
    .adm-content{padding:24px 28px}
    .adm-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);backdrop-filter:blur(3px);z-index:45;opacity:0;pointer-events:none;transition:opacity .25s ease}
    .adm-overlay.open{opacity:1;pointer-events:auto}
    .adm-mobile-bar{display:none;background:var(--surface);border-bottom:1px solid var(--border);padding:10px 14px;align-items:center;gap:12px;position:sticky;top:0;z-index:60}
    .adm-ham{width:34px;height:34px;background:var(--gray-100);border:1px solid var(--border);border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--text-muted);flex-shrink:0}

    /* ── Layout ───────────────────────────────── */
    .page-grid{display:grid;grid-template-columns:1fr 360px;gap:22px;align-items:start}
    .card{background:var(--surface);border:1px solid var(--border);border-radius:13px;overflow:hidden;margin-bottom:20px}
    .card-head{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
    .card-icon{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:15px}
    .card-title{font-size:14px;font-weight:800;color:var(--text)}
    .card-body{padding:20px}

    /* ── Form ─────────────────────────────────── */
    .flabel{display:block;font-size:12px;font-weight:700;color:var(--gray-600);margin-bottom:5px;letter-spacing:.02em}
    [data-theme="dark"] .flabel{color:var(--gray-500)}
    .form-control{width:100%;padding:9px 12px;background:var(--gray-50);border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;color:var(--text);outline:none;transition:all .15s;box-sizing:border-box}
    .form-control:focus{background:var(--surface);border-color:#25d366;box-shadow:0 0 0 3px rgba(37,211,102,.15)}
    [data-theme="dark"] .form-control{background:var(--gray-100)}
    .form-group{margin-bottom:16px}
    textarea.form-control{resize:vertical;min-height:160px;font-family:'JetBrains Mono',monospace;font-size:12px;line-height:1.6}

    /* ── Target cards ─────────────────────────── */
    .target-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:4px}
    .target-card{border:1.5px solid var(--border);border-radius:10px;padding:12px 14px;cursor:pointer;transition:all .15s;background:var(--gray-50);display:flex;align-items:flex-start;gap:10px}
    .target-card:hover{border-color:#25d366}
    .target-card input[type=radio]{margin-top:2px;accent-color:#25d366;flex-shrink:0}
    .target-card.selected{border-color:#25d366;background:rgba(37,211,102,.07)}
    [data-theme="dark"] .target-card{background:var(--gray-100)}
    .target-card-label{font-size:13px;font-weight:700;color:var(--text);display:block}
    .target-card-count{font-size:11px;color:var(--text-muted);margin-top:2px}

    /* ── Template cards ───────────────────────── */
    .tmpl-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px}
    .tmpl-card{border:1.5px solid var(--border);border-radius:10px;padding:12px;cursor:pointer;transition:all .15s;background:var(--gray-50);text-align:center}
    .tmpl-card:hover{border-color:#25d366;transform:translateY(-2px);box-shadow:0 6px 16px rgba(37,211,102,.1)}
    .tmpl-card.active{border-color:#25d366;background:rgba(37,211,102,.07)}
    [data-theme="dark"] .tmpl-card{background:var(--gray-100)}
    .tmpl-icon{font-size:22px;margin-bottom:6px}
    .tmpl-name{font-size:11.5px;font-weight:700;color:var(--text);line-height:1.3}

    /* ── Buttons ──────────────────────────────── */
    .btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;border:none;transition:all .15s;text-decoration:none}
    .btn-wa{background:#25d366;color:white}
    .btn-wa:hover{background:#128c5a;transform:translateY(-1px)}
    .btn-secondary{background:var(--gray-100);color:var(--text);border:1.5px solid var(--border)}
    .btn-secondary:hover{background:var(--gray-200)}
    .btn-danger{background:var(--danger);color:white}
    .btn-danger:hover{background:#b91c1c}
    .btn-sm{padding:6px 12px;font-size:12px}
    .btn-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}

    /* ── Alert ────────────────────────────────── */
    .alert{padding:12px 16px;border-radius:9px;font-size:13.5px;font-weight:500;margin-bottom:18px;display:flex;align-items:flex-start;gap:9px}
    .alert-success{background:rgba(37,211,102,.1);color:#16a34a;border:1px solid rgba(37,211,102,.3)}
    .alert-error{background:var(--danger-bg);color:var(--danger);border:1px solid rgba(220,38,38,.2)}
    .alert-warn{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
    [data-theme="dark"] .alert-warn{background:#451a03;color:#fbbf24;border-color:#92400e}
    .alert svg{width:16px;height:16px;flex-shrink:0;margin-top:1px}

    /* ── WA Preview ───────────────────────────── */
    .wa-preview-wrap{background:var(--surface);border:1px solid var(--border);border-radius:13px;overflow:hidden;position:sticky;top:76px}
    .wa-preview-head{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
    .wa-preview-title{font-size:13px;font-weight:800;color:var(--text)}
    .wa-mock{background:#e5ddd5;padding:16px;min-height:280px}
    [data-theme="dark"] .wa-mock{background:#1a1a2e}
    .wa-bubble{background:white;border-radius:0 10px 10px 10px;padding:10px 14px;max-width:90%;box-shadow:0 1px 3px rgba(0,0,0,.12);position:relative;margin-bottom:8px}
    [data-theme="dark"] .wa-bubble{background:#202c33}
    .wa-bubble-text{font-size:13px;line-height:1.5;color:#111;white-space:pre-wrap;word-break:break-word}
    [data-theme="dark"] .wa-bubble-text{color:#e9edef}
    .wa-bubble-time{font-size:10px;color:#667781;text-align:right;margin-top:4px}
    [data-theme="dark"] .wa-bubble-time{color:#8696a0}
    .wa-char-count{font-size:11px;color:var(--text-muted);text-align:right;margin-top:4px}

    /* ── Progress card ────────────────────────── */
    .progress-card{background:var(--surface);border:1px solid var(--border);border-radius:13px;overflow:hidden;margin-bottom:20px}
    .prog-bar-wrap{background:var(--gray-100);border-radius:99px;height:8px;overflow:hidden;margin:12px 0}
    .prog-bar-fill{height:100%;background:linear-gradient(90deg,#25d366,#128c5a);border-radius:99px;transition:width .4s ease}
    .prog-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:12px}
    .prog-stat{text-align:center;padding:10px;background:var(--gray-50);border-radius:9px;border:1px solid var(--border)}
    [data-theme="dark"] .prog-stat{background:var(--gray-100)}
    .prog-stat-val{font-size:22px;font-weight:900;color:var(--text)}
    .prog-stat-lbl{font-size:11px;color:var(--text-muted);margin-top:2px;font-weight:600}
    .prog-stat-val.green{color:#25d366}
    .prog-stat-val.red{color:var(--danger)}

    /* ── History table ────────────────────────── */
    .hist-table{width:100%;border-collapse:collapse}
    .hist-table th{background:var(--gray-50);border-bottom:1px solid var(--border);padding:8px 12px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);white-space:nowrap}
    .hist-table td{padding:10px 12px;border-bottom:1px solid var(--border);font-size:13px;color:var(--text);vertical-align:middle}
    .hist-table tr:last-child td{border-bottom:none}
    .hist-table tr:hover td{background:var(--gray-50)}
    .status-pill{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700}
    .sp-completed{background:rgba(37,211,102,.1);color:#16a34a}
    .sp-running{background:#eff6ff;color:#2563eb}
    .sp-queued{background:#fffbeb;color:#92400e}
    .sp-stopped{background:var(--gray-100);color:var(--text-muted)}
    [data-theme="dark"] .sp-running{background:#1e3a5f;color:#93c5fd}
    [data-theme="dark"] .sp-queued{background:#451a03;color:#fbbf24}
    .dot-pulse{width:7px;height:7px;border-radius:50%;background:currentColor;animation:pulse-dot 1.2s infinite}
    @keyframes pulse-dot{0%,100%{opacity:1}50%{opacity:.3}}

    /* ── Spinner ──────────────────────────────── */
    .spinner{width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:white;border-radius:50%;animation:spin .55s linear infinite;display:inline-block}
    @keyframes spin{to{transform:rotate(360deg)}}

    /* ── Failed numbers ───────────────────────── */
    .failed-list{background:var(--gray-50);border:1px solid var(--border);border-radius:8px;padding:10px 14px;font-size:12px;font-family:monospace;max-height:100px;overflow-y:auto;color:var(--danger)}
    [data-theme="dark"] .failed-list{background:var(--gray-100)}

    /* ── Custom numbers textarea ──────────────── */
    #custom-numbers-wrap{display:none;margin-top:12px}

    /* ── API missing warn ─────────────────────── */
    .api-missing{background:#fef2f2;border:1.5px solid #fca5a5;border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px;font-size:13px;color:#991b1b}
    [data-theme="dark"] .api-missing{background:#450a0a;border-color:#7f1d1d;color:#fca5a5}

    /* ── Responsive ───────────────────────────── */
    @media(max-width:1100px){.page-grid{grid-template-columns:1fr}.wa-preview-wrap{position:static}}
    @media(max-width:900px){
      .adm-mobile-bar{display:flex}
      .adm-topbar{display:none}
      
      .adm-sidebar.open{transform:translateX(0)}
      .adm-main{margin-left:0!important}
      .adm-content{padding:16px}
      .tmpl-grid{grid-template-columns:repeat(2,1fr)}
    }
    @media(max-width:640px){.target-grid{grid-template-columns:1fr}.btn-row{flex-direction:column;align-items:stretch}}
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
  <button class="adm-ham" onclick="document.getElementById('adm-sidebar').classList.toggle('open');document.getElementById('adm-overlay').classList.toggle('open')">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
  </button>
  <span style="font-weight:800;font-size:14px;color:var(--text)"><?= $app_name ?> <span style="font-size:10px;background:#dc2626;color:white;padding:1px 6px;border-radius:99px;font-weight:700;text-transform:uppercase;vertical-align:middle">Admin</span></span>
</div>

<div class="adm-shell">
  <!-- Sidebar -->
  <?php include 'sidebar.php'; ?>

  <!-- Main Content -->
  <div class="adm-main">
    <div class="adm-topbar">
      <span style="font-size:20px">💬</span>
      <span class="adm-topbar-title">Bulk WhatsApp Marketing</span>
      <?php if (!$api_ok): ?>
        <a href="<?= BASE_URL ?>/admin/settings.php" class="btn btn-sm btn-danger" style="margin-left:auto">⚠ Configure WA API</a>
      <?php endif; ?>
    </div>

    <div class="adm-content">

      <!-- Toast -->
      <?php if ($toast): ?>
      <div class="alert alert-<?= $toast_type === 'error' ? 'error' : 'success' ?>">
        <?php if ($toast_type === 'error'): ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?php else: ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        <?php endif; ?>
        <span><?= htmlspecialchars($toast) ?></span>
      </div>
      <?php endif; ?>

      <!-- API Warning -->
      <?php if (!$api_ok): ?>
      <div class="api-missing">
        <span style="font-size:22px">⚠️</span>
        <div>
          <strong>WhatsApp API not configured.</strong>
          Go to <a href="<?= BASE_URL ?>/admin/settings.php" style="color:inherit;text-decoration:underline">Settings</a> and set <code>wa_api</code> and <code>wa_token</code>.
        </div>
      </div>
      <?php endif; ?>

      <!-- Live Progress (shown when campaign running) -->
      <div class="progress-card" id="live-progress-card" style="display:none">
        <div class="card-head">
          <div class="card-icon" style="background:rgba(37,211,102,.15)">📡</div>
          <span class="card-title">Live Progress</span>
          <div id="prog-status-pill" class="status-pill sp-running" style="margin-left:auto">
            <span class="dot-pulse"></span> Running
          </div>
        </div>
        <div class="card-body">
          <div class="prog-stats">
            <div class="prog-stat">
              <div class="prog-stat-val" id="prog-total">0</div>
              <div class="prog-stat-lbl">Total</div>
            </div>
            <div class="prog-stat">
              <div class="prog-stat-val green" id="prog-sent">0</div>
              <div class="prog-stat-lbl">✅ Sent</div>
            </div>
            <div class="prog-stat">
              <div class="prog-stat-val red" id="prog-failed">0</div>
              <div class="prog-stat-lbl">❌ Failed</div>
            </div>
          </div>
          <div class="prog-bar-wrap">
            <div class="prog-bar-fill" id="prog-bar" style="width:0%"></div>
          </div>
          <div style="display:flex;align-items:center;justify-content:space-between;font-size:12px;color:var(--text-muted)">
            <span id="prog-label">Initializing...</span>
            <span id="prog-pct" style="font-weight:700">0%</span>
          </div>
          <div id="prog-failed-nums" style="margin-top:12px;display:none">
            <div class="flabel">Failed Numbers</div>
            <div class="failed-list" id="prog-failed-list"></div>
          </div>
          <div style="margin-top:14px;display:flex;gap:10px">
            <button class="btn btn-danger btn-sm" id="stop-btn" onclick="stopCampaign()">
              ⏹ Stop Campaign
            </button>
            <span style="font-size:12px;color:var(--text-muted);line-height:1.4;align-self:center">This will halt sending after the current message.</span>
          </div>
        </div>
      </div>

      <div class="page-grid">
        <!-- LEFT: Compose -->
        <div>
          <!-- Template Picker -->
          <div class="card">
            <div class="card-head">
              <div class="card-icon" style="background:#fdf4ff">🎨</div>
              <span class="card-title">Message Templates</span>
              <span style="margin-left:auto;font-size:11.5px;color:var(--text-muted)">Click to load</span>
            </div>
            <div class="card-body" style="padding-bottom:12px">
              <div class="tmpl-grid" id="tmpl-grid">
                <!-- Rendered by JS -->
              </div>
              <div style="font-size:11.5px;color:var(--text-muted)">
                💡 Variables: <code style="background:var(--gray-100);padding:1px 5px;border-radius:4px;font-family:monospace">{{name}}</code>
                <code style="background:var(--gray-100);padding:1px 5px;border-radius:4px;font-family:monospace">{{app_name}}</code>
              </div>
            </div>
          </div>

          <!-- Compose + Settings -->
          <form method="POST" id="wa-form" onsubmit="return confirmLaunch()">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="launch">

            <div class="card">
              <div class="card-head">
                <div class="card-icon" style="background:rgba(37,211,102,.15)">✍️</div>
                <span class="card-title">Compose Message</span>
              </div>
              <div class="card-body">
                <div class="form-group">
                  <label class="flabel">Message <span style="color:var(--danger)">*</span></label>
                  <textarea name="message" id="wa-message" class="form-control" placeholder="Type your WhatsApp message here...&#10;&#10;Supports {{name}} and {{app_name}} variables." required oninput="updatePreview()"></textarea>
                  <div class="wa-char-count"><span id="char-count">0</span> characters</div>
                </div>
              </div>
            </div>

            <!-- Target Audience -->
            <div class="card">
              <div class="card-head">
                <div class="card-icon" style="background:#f0fdf4">🎯</div>
                <span class="card-title">Target Audience</span>
              </div>
              <div class="card-body">
                <div class="target-grid">
                  <label class="target-card selected" onclick="selectTarget(this,'all')">
                    <input type="radio" name="target" value="all" checked>
                    <div>
                      <span class="target-card-label">👥 All Active Users</span>
                      <span class="target-card-count"><?= number_format($count_all) ?> users w/ phone</span>
                    </div>
                  </label>
                  <label class="target-card" onclick="selectTarget(this,'active_servers')">
                    <input type="radio" name="target" value="active_servers">
                    <div>
                      <span class="target-card-label">🟢 Running Servers</span>
                      <span class="target-card-count"><?= number_format($count_active) ?> users</span>
                    </div>
                  </label>
                  <label class="target-card" onclick="selectTarget(this,'no_servers')">
                    <input type="radio" name="target" value="no_servers">
                    <div>
                      <span class="target-card-label">💤 No Servers Yet</span>
                      <span class="target-card-count"><?= number_format($count_none) ?> users</span>
                    </div>
                  </label>
                  <label class="target-card" onclick="selectTarget(this,'low_balance')">
                    <input type="radio" name="target" value="low_balance">
                    <div>
                      <span class="target-card-label">⚠️ Low Balance (&lt;₹100)</span>
                      <span class="target-card-count"><?= number_format($count_low) ?> users</span>
                    </div>
                  </label>
                  <label class="target-card" onclick="selectTarget(this,'custom')" style="grid-column:span 2">
                    <input type="radio" name="target" value="custom">
                    <div style="width:100%">
                      <span class="target-card-label">✏️ Custom Numbers</span>
                      <span class="target-card-count">Paste specific phone numbers</span>
                    </div>
                  </label>
                </div>
                <!-- Custom numbers input -->
                <div id="custom-numbers-wrap">
                  <label class="flabel">Phone Numbers <span style="color:var(--text-muted);font-weight:400">(one per line, or comma-separated, with country code)</span></label>
                  <textarea name="custom_numbers" id="custom-numbers" class="form-control" style="min-height:90px" placeholder="919876543210&#10;918765432109&#10;917654321098"></textarea>
                </div>
              </div>
            </div>

            <!-- Delay & Send Settings -->
            <div class="card">
              <div class="card-head">
                <div class="card-icon" style="background:#fffbeb">⏱️</div>
                <span class="card-title">Send Settings</span>
              </div>
              <div class="card-body">
                <div class="form-group">
                  <label class="flabel">Delay Between Messages (seconds)</label>
                  <div style="display:flex;align-items:center;gap:12px">
                    <input type="range" name="delay_seconds" id="delay-range" min="1" max="60" value="3"
                      oninput="document.getElementById('delay-val').textContent=this.value"
                      style="flex:1;accent-color:#25d366">
                    <span id="delay-val" style="font-size:20px;font-weight:900;color:var(--text);min-width:30px;text-align:right">3</span>
                    <span style="font-size:12px;color:var(--text-muted)">sec</span>
                  </div>
                  <div style="font-size:11.5px;color:var(--text-muted);margin-top:6px">
                    ⚠️ Recommended: 3–10 seconds to avoid WhatsApp spam detection.
                    Lower = faster but riskier.
                  </div>
                </div>
                <div style="background:var(--gray-50);border:1px solid var(--border);border-radius:9px;padding:12px 16px;font-size:12.5px;color:var(--text-muted);line-height:1.6">
                  <strong style="color:var(--text)">🔒 Background Process:</strong><br>
                  Sending runs as a background PHP worker. It continues even if you close/reload this page.
                  Use the <strong>Stop</strong> button or check history to manage campaigns.
                </div>
              </div>
            </div>

            <!-- Launch -->
            <div class="card">
              <div class="card-body">
                <div class="btn-row">
                  <button type="submit" class="btn btn-wa" id="launch-btn" <?= !$api_ok ? 'disabled title="Configure WA API first"' : '' ?>>
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    Launch Campaign
                  </button>
                  <span id="launch-count" style="font-size:13px;color:var(--text-muted)">
                    <?= number_format($count_all) ?> recipients selected
                  </span>
                </div>
              </div>
            </div>
          </form>
        </div>

        <!-- RIGHT: Preview + Quick Stats -->
        <div>
          <!-- WA Preview -->
          <div class="wa-preview-wrap" style="margin-bottom:20px">
            <div class="wa-preview-head">
              <span style="font-size:18px">💬</span>
              <span class="wa-preview-title">WhatsApp Preview</span>
            </div>
            <!-- WA Header bar mock -->
            <div style="background:#075e54;padding:10px 14px;display:flex;align-items:center;gap:10px">
              <div style="width:34px;height:34px;border-radius:50%;background:#25d366;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;color:white"><?= $avatar ?></div>
              <div>
                <div style="font-size:13px;font-weight:700;color:white"><?= $app_name ?></div>
                <div style="font-size:11px;color:rgba(255,255,255,.7)">online</div>
              </div>
            </div>
            <div class="wa-mock" id="wa-mock">
              <div class="wa-bubble">
                <div class="wa-bubble-text" id="wa-preview-text">Your message will appear here...</div>
                <div class="wa-bubble-time"><?= date('h:i A') ?> ✓✓</div>
              </div>
            </div>
          </div>

          <!-- Quick Info -->
          <div class="card">
            <div class="card-head">
              <div class="card-icon" style="background:#eff6ff">ℹ️</div>
              <span class="card-title">API Configuration</span>
            </div>
            <div class="card-body" style="padding:14px 20px">
              <div style="display:grid;gap:8px;font-size:12.5px">
                <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)">
                  <span style="color:var(--text-muted);font-weight:600">WA API URL</span>
                  <span style="color:<?= $wa_api ? 'var(--success)' : 'var(--danger)' ?>;font-weight:700">
                    <?= $wa_api ? '✅ Set' : '❌ Not Set' ?>
                  </span>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)">
                  <span style="color:var(--text-muted);font-weight:600">WA Token</span>
                  <span style="color:<?= $wa_token ? 'var(--success)' : 'var(--danger)' ?>;font-weight:700">
                    <?= $wa_token ? '✅ Set' : '❌ Not Set' ?>
                  </span>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0">
                  <span style="color:var(--text-muted);font-weight:600">Admin Number</span>
                  <span style="font-weight:700;color:var(--text)">
                    <?= get_setting('wa_admin_number') ? '✅ Set' : '⚠️ Not Set' ?>
                  </span>
                </div>
              </div>
              <a href="<?= BASE_URL ?>/admin/settings.php" class="btn btn-secondary btn-sm" style="margin-top:12px;width:100%;justify-content:center">
                ⚙️ Edit Settings
              </a>
            </div>
          </div>
        </div>
      </div>

      <!-- Campaign History -->
      <div class="card">
        <div class="card-head">
          <div class="card-icon" style="background:#fdf4ff">📋</div>
          <span class="card-title">Campaign History</span>
          <span style="margin-left:auto;font-size:12px;color:var(--text-muted)">Last 30 campaigns</span>
        </div>
        <?php if (empty($campaigns)): ?>
        <div class="card-body" style="text-align:center;padding:40px;color:var(--text-muted)">
          <div style="font-size:36px;margin-bottom:10px">📭</div>
          <div style="font-size:14px;font-weight:600">No campaigns yet</div>
          <div style="font-size:12px;margin-top:4px">Launch your first WhatsApp campaign above!</div>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
          <table class="hist-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Date / Time</th>
                <th>Target</th>
                <th>Message</th>
                <th>Total</th>
                <th>✅ Sent</th>
                <th>❌ Failed</th>
                <th>Delay</th>
                <th>Status</th>
                <th>By</th>
              </tr>
            </thead>
            <tbody id="hist-tbody">
              <?php foreach ($campaigns as $c): ?>
              <tr id="hist-row-<?= $c['id'] ?>">
                <td style="font-weight:700;color:var(--text-muted)">#<?= $c['id'] ?></td>
                <td style="white-space:nowrap;font-size:12px"><?= date('d M Y, h:i A', strtotime($c['created_at'])) ?></td>
                <td>
                  <?php $t_labels = ['all'=>'👥 All','active_servers'=>'🟢 Running','no_servers'=>'💤 No Servers','low_balance'=>'⚠️ Low Bal','custom'=>'✏️ Custom']; ?>
                  <span style="font-size:12px"><?= $t_labels[$c['target']] ?? htmlspecialchars($c['target']) ?></span>
                </td>
                <td style="max-width:220px">
                  <div style="font-size:12px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:200px" title="<?= htmlspecialchars($c['message']) ?>">
                    <?= htmlspecialchars(mb_substr($c['message'],0,60)) ?><?= mb_strlen($c['message'])>60?'…':'' ?>
                  </div>
                </td>
                <td style="font-weight:700"><?= number_format($c['total']) ?></td>
                <td style="font-weight:700;color:#16a34a"><?= number_format($c['sent']) ?></td>
                <td>
                  <?php $fn = json_decode($c['failed_numbers']??'[]',true) ?: []; ?>
                  <?php if ($c['failed'] > 0): ?>
                  <span style="color:var(--danger);font-weight:700;cursor:pointer" title="<?= htmlspecialchars(implode(', ',$fn)) ?>">
                    <?= number_format($c['failed']) ?>
                  </span>
                  <?php else: ?>
                  <span style="color:var(--text-muted)">0</span>
                  <?php endif; ?>
                </td>
                <td style="font-size:12px"><?= $c['delay_seconds'] ?>s</td>
                <td>
                  <?php
                    $pill_class = ['completed'=>'sp-completed','running'=>'sp-running','queued'=>'sp-queued','stopped'=>'sp-stopped'][$c['status']] ?? 'sp-stopped';
                    $pill_icons = ['completed'=>'✅','running'=>'📡','queued'=>'⏳','stopped'=>'⏹'];
                  ?>
                  <span class="status-pill <?= $pill_class ?>" id="hist-status-<?= $c['id'] ?>">
                    <?php if ($c['status']==='running'): ?><span class="dot-pulse"></span><?php else: echo $pill_icons[$c['status']]??''; endif; ?>
                    <?= ucfirst($c['status']) ?>
                  </span>
                </td>
                <td style="font-size:12px"><?= htmlspecialchars($c['sender_name'] ?: '—') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

    </div><!-- /adm-content -->
  </div><!-- /adm-main -->
</div><!-- /adm-shell -->

<script>
'use strict';

// ══ Templates ══════════════════════════════════════════════
var WA_TEMPLATES = [
  {
    icon: '👋',
    name: 'Welcome',
    body: 'Hello {{name}}! 👋\n\nWelcome to *{{app_name}}*! Your account is ready.\n\nGet started: {{base_url}}\n\nNeed help? Just reply here! 💬'
  },
  {
    icon: '🔔',
    name: 'Low Balance',
    body: 'Hi {{name}} 👋\n\n⚠️ *Low Balance Alert!*\n\nYour {{app_name}} wallet is running low.\n\nTopup now to keep your servers running smoothly:\n👉 {{base_url}}/billing.php'
  },
  {
    icon: '🎁',
    name: 'Offer',
    body: '🎉 *Special Offer for You, {{name}}!*\n\nGet 20% BONUS on your next topup at *{{app_name}}*.\n\n💰 Topup now: {{base_url}}/billing.php\n\nOffer valid for 48 hours only!'
  },
  {
    icon: '📢',
    name: 'Announcement',
    body: '📢 *Important Update from {{app_name}}*\n\nHi {{name}},\n\nWe have exciting news for you!\n\n[Your announcement here]\n\nVisit: {{base_url}}'
  },
  {
    icon: '🔧',
    name: 'Maintenance',
    body: '🔧 *Scheduled Maintenance*\n\nDear {{name}},\n\n{{app_name}} will undergo scheduled maintenance on [DATE] from [TIME].\n\nServices may be briefly interrupted.\n\nSorry for any inconvenience. 🙏'
  },
  {
    icon: '🚀',
    name: 'Upsell',
    body: '🚀 *Upgrade Your Server, {{name}}!*\n\nNeed more power for your projects?\n\nCheck out our latest VPS plans on *{{app_name}}* — faster CPUs, more RAM, SSD storage!\n\n👉 {{base_url}}/plans.php'
  }
];

var TARGET_COUNTS = {
  all: <?= $count_all ?>,
  active_servers: <?= $count_active ?>,
  no_servers: <?= $count_none ?>,
  low_balance: <?= $count_low ?>,
  custom: null
};

var BASE_URL = '<?= BASE_URL ?>';
var APP_NAME = '<?= addslashes($app_name) ?>';
var ADMIN_NAME = '<?= addslashes($fname) ?>';

// ══ Render Templates ═══════════════════════════════════════
(function() {
  var grid = document.getElementById('tmpl-grid');
  WA_TEMPLATES.forEach(function(t, i) {
    var div = document.createElement('div');
    div.className = 'tmpl-card' + (i === 0 ? ' active' : '');
    div.innerHTML = '<div class="tmpl-icon">' + t.icon + '</div><div class="tmpl-name">' + t.name + '</div>';
    div.addEventListener('click', function() {
      document.querySelectorAll('.tmpl-card').forEach(function(c) { c.classList.remove('active'); });
      div.classList.add('active');
      loadTemplate(t);
    });
    grid.appendChild(div);
  });
  loadTemplate(WA_TEMPLATES[0]);
})();

function loadTemplate(t) {
  var msg = t.body
    .replace(/{{app_name}}/g, APP_NAME)
    .replace(/{{base_url}}/g, BASE_URL)
    .replace(/{{name}}/g, ADMIN_NAME);
  document.getElementById('wa-message').value = msg;
  updatePreview();
}

// ══ Preview ═════════════════════════════════════════════════
function updatePreview() {
  var raw = document.getElementById('wa-message').value;
  var displayed = raw
    .replace(/{{name}}/g, ADMIN_NAME)
    .replace(/{{app_name}}/g, APP_NAME)
    .replace(/{{base_url}}/g, BASE_URL);

  // Bold: *text*
  var html = escapeHtml(displayed)
    .replace(/\*(.*?)\*/g, '<strong>$1</strong>');

  document.getElementById('wa-preview-text').innerHTML = html || '<span style="color:#aaa">Your message will appear here...</span>';
  document.getElementById('char-count').textContent = raw.length;
}

function escapeHtml(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ══ Target Selection ════════════════════════════════════════
function selectTarget(card, value) {
  document.querySelectorAll('.target-card').forEach(function(c) { c.classList.remove('selected'); });
  card.classList.add('selected');

  var wrap = document.getElementById('custom-numbers-wrap');
  if (value === 'custom') {
    wrap.style.display = 'block';
    document.getElementById('launch-count').textContent = 'Enter phone numbers above';
  } else {
    wrap.style.display = 'none';
    var cnt = TARGET_COUNTS[value];
    document.getElementById('launch-count').textContent = (cnt !== null ? cnt.toLocaleString() : '?') + ' recipients selected';
  }
}

// ══ Confirm Launch ══════════════════════════════════════════
function confirmLaunch() {
  var target = document.querySelector('input[name=target]:checked').value;
  var cnt = TARGET_COUNTS[target];
  var cntLabel = cnt !== null ? cnt.toLocaleString() : 'selected';
  var delay = document.getElementById('delay-range').value;
  if (!confirm('Launch WhatsApp campaign to ' + cntLabel + ' recipients?\n\nDelay: ' + delay + 's per message.\n\nThis runs in background. Continue?')) return false;
  var btn = document.getElementById('launch-btn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Launching...';
  return true;
}

// ══ Live Progress Polling ═══════════════════════════════════
var pollTimer = null;
var currentCampaignId = null;
var RUNNING_IDS = <?= json_encode($running) ?>;

function startPolling(id) {
  currentCampaignId = id;
  document.getElementById('live-progress-card').style.display = '';
  clearInterval(pollTimer);
  pollTimer = setInterval(function() { pollStatus(id); }, 2500);
  pollStatus(id);
}

function pollStatus(id) {
  fetch('bulk-whatsapp.php?ajax=status&id=' + id)
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (!d || d.error) { clearInterval(pollTimer); return; }
      updateProgressUI(d);
      if (d.status === 'completed' || d.status === 'stopped') {
        clearInterval(pollTimer);
        // Refresh history row
        updateHistoryRow(d);
      }
    })
    .catch(function() { clearInterval(pollTimer); });
}

function updateProgressUI(d) {
  var total = parseInt(d.total) || 0;
  var sent  = parseInt(d.sent)  || 0;
  var failed= parseInt(d.failed)|| 0;
  var pct   = total > 0 ? Math.round(((sent+failed)/total)*100) : 0;

  document.getElementById('prog-total').textContent  = total.toLocaleString();
  document.getElementById('prog-sent').textContent   = sent.toLocaleString();
  document.getElementById('prog-failed').textContent = failed.toLocaleString();
  document.getElementById('prog-bar').style.width    = pct + '%';
  document.getElementById('prog-pct').textContent    = pct + '%';

  var lbl = d.status === 'completed' ? '✅ Completed!' :
            d.status === 'stopped'   ? '⏹ Stopped'    :
            'Sending... (' + (sent+failed) + ' / ' + total + ')';
  document.getElementById('prog-label').textContent = lbl;

  // Status pill
  var pill = document.getElementById('prog-status-pill');
  var classes = {running:'sp-running',queued:'sp-queued',completed:'sp-completed',stopped:'sp-stopped'};
  pill.className = 'status-pill ' + (classes[d.status]||'sp-stopped');
  pill.innerHTML = (d.status==='running'||d.status==='queued') ? '<span class="dot-pulse"></span> ' + d.status.charAt(0).toUpperCase()+d.status.slice(1) :
    (d.status==='completed'?'✅ Completed':'⏹ Stopped');

  // Failed numbers
  if (failed > 0 && d.failed_numbers) {
    try {
      var nums = JSON.parse(d.failed_numbers);
      if (nums && nums.length) {
        document.getElementById('prog-failed-nums').style.display = '';
        document.getElementById('prog-failed-list').textContent = nums.join('\n');
      }
    } catch(e) {}
  }
}

function updateHistoryRow(d) {
  var row = document.getElementById('hist-row-' + d.id);
  if (!row) { location.reload(); return; }
  var pill = document.getElementById('hist-status-' + d.id);
  if (!pill) return;
  if (d.status === 'completed') {
    pill.className = 'status-pill sp-completed';
    pill.innerHTML = '✅ Completed';
  } else if (d.status === 'stopped') {
    pill.className = 'status-pill sp-stopped';
    pill.innerHTML = '⏹ Stopped';
  }
}

function stopCampaign() {
  if (!currentCampaignId) return;
  if (!confirm('Stop this campaign? Messages already sent cannot be recalled.')) return;
  var btn = document.getElementById('stop-btn');
  btn.disabled = true; btn.textContent = 'Stopping...';
  var fd = new FormData();
  fd.append('id', currentCampaignId);
  fd.append('csrf_token', '<?= $csrf ?>');
  fetch('bulk-whatsapp.php?ajax=stop', { method:'POST', body:fd })
    .then(function(r) { return r.json(); })
    .then(function() { pollStatus(currentCampaignId); btn.disabled=false; btn.innerHTML='⏹ Stop Campaign'; })
    .catch(function() { btn.disabled=false; btn.innerHTML='⏹ Stop Campaign'; });
}

// ══ Auto-resume polling on load ═════════════════════════════
document.addEventListener('DOMContentLoaded', function() {
  updatePreview();
  if (RUNNING_IDS && RUNNING_IDS.length > 0) {
    startPolling(RUNNING_IDS[0]);
  }
  // Also check periodically for any newly started campaigns
  setTimeout(function() {
    fetch('bulk-whatsapp.php?ajax=running')
      .then(function(r) { return r.json(); })
      .then(function(rows) {
        if (rows && rows.length > 0 && !pollTimer) {
          startPolling(rows[0].id);
        }
      }).catch(function(){});
  }, 1000);
});
</script>
<script>var BASE_URL_JS = "<?= BASE_URL ?>";</script>
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
</body>
</html>