<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/mailer.php';
require_admin();

$user     = current_user();
$app_name = APP_NAME;
$csrf     = csrf_token();
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));

$msg = ''; $err = '';
$tab = $_GET['tab'] ?? 'bulk-email';

// ── Handle send ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {

    $action = $_POST['action'] ?? '';

    if ($action === 'send_bulk') {
        $subject   = trim($_POST['subject'] ?? '');
        $html_body = trim($_POST['html_body'] ?? '');
        $target    = $_POST['target'] ?? 'all';
        $test_email= trim($_POST['test_email'] ?? '');
        $is_test   = !empty($_POST['is_test']);

        if (!$subject || !$html_body) {
            $err = 'Subject and email body are required.';
        } else {
            if ($is_test) {
                // Send test to admin email
                $to = $test_email ?: $user['email'];
                $body = str_replace(
                    ['{{name}}','{{email}}','{{app_name}}','{{base_url}}'],
                    [$fname, htmlspecialchars($user['email']), $app_name, BASE_URL],
                    $html_body
                );
                $ok = send_mail($to, 'Test Recipient', $subject, $body);
                if ($ok) $msg = "Test email sent to <strong>".htmlspecialchars($to)."</strong> successfully!";
                else     $err = "Failed to send test email. Check SMTP settings.";
            } else {
                // Build user query
                $where = 'status = \'active\'';
                if ($target === 'active_servers') {
                    $where .= ' AND id IN (SELECT DISTINCT user_id FROM servers WHERE deleted_at IS NULL AND status=\'running\')';
                } elseif ($target === 'no_servers') {
                    $where .= ' AND id NOT IN (SELECT DISTINCT user_id FROM servers WHERE deleted_at IS NULL)';
                } elseif ($target === 'low_balance') {
                    $where .= ' AND wallet_balance < 100';
                } elseif ($target === 'admins') {
                    $where .= ' AND role=\'admin\'';
                }

                $users = db()->query("SELECT id, full_name, email FROM users WHERE $where ORDER BY id ASC")->fetchAll();
                $sent = 0; $failed = 0;

                // Log the campaign
                try {
                    db()->prepare("INSERT INTO email_campaigns (subject, html_body, target_audience, sent_by, total_recipients, status, created_at)
                                   VALUES (?,?,?,?,?,'sending',NOW())")
                       ->execute([$subject, $html_body, $target, $user['id'], count($users)]);
                    $campaign_id = (int)db()->lastInsertId();
                } catch (Throwable $e) {
                    $campaign_id = 0;
                }

                foreach ($users as $u) {
                    $name = $u['full_name'] ?: explode('@', $u['email'])[0];
                    $body = str_replace(
                        ['{{name}}','{{email}}','{{app_name}}','{{base_url}}'],
                        [htmlspecialchars($name), htmlspecialchars($u['email']), $app_name, BASE_URL],
                        $html_body
                    );
                    $ok = send_mail($u['email'], $name, $subject, $body);
                    if ($ok) $sent++; else $failed++;
                    // Small delay to avoid SMTP rate limits
                    usleep(100000); // 100ms
                }

                // Update campaign status
                if ($campaign_id) {
                    db()->prepare("UPDATE email_campaigns SET status='sent', sent_count=?, failed_count=?, sent_at=NOW() WHERE id=?")
                       ->execute([$sent, $failed, $campaign_id]);
                }

                $msg = "Campaign sent! <strong>$sent</strong> delivered" . ($failed > 0 ? ", <strong>$failed</strong> failed" : "") . ".";
            }
        }
    }
}

// ── Load campaign history ───────────────────────────────────
$campaigns = [];
try {
    $campaigns = db()->query("SELECT c.*, u.full_name as sender_name FROM email_campaigns c LEFT JOIN users u ON u.id=c.sent_by ORDER BY c.created_at DESC LIMIT 20")->fetchAll();
} catch (Throwable $e) {}

// ── User counts for targeting ────────────────────────────────
try {
    $count_all    = (int)db()->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn();
    $count_active = (int)db()->query("SELECT COUNT(DISTINCT user_id) FROM servers WHERE deleted_at IS NULL AND status='running'")->fetchColumn();
    $count_none   = (int)db()->query("SELECT COUNT(*) FROM users WHERE status='active' AND id NOT IN (SELECT DISTINCT user_id FROM servers WHERE deleted_at IS NULL)")->fetchColumn();
    $count_low    = (int)db()->query("SELECT COUNT(*) FROM users WHERE status='active' AND wallet_balance < 100")->fetchColumn();
} catch (Throwable $e) {
    $count_all = $count_active = $count_none = $count_low = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script>(function(){var t=localStorage.getItem('cv_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Bulk Email — <?= $app_name ?> Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/admin/admin.css">
  <style>
    /* ── Admin shell (same as index.php) ───────── */
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

    /* ── Page layout ──────────────────────────── */
    .page-grid{display:grid;grid-template-columns:1fr 400px;gap:22px;align-items:start}

    /* ── Cards ────────────────────────────────── */
    .card{background:var(--surface);border:1px solid var(--border);border-radius:13px;overflow:hidden;margin-bottom:20px}
    .card-head{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
    .card-icon{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:15px}
    .card-title{font-size:14px;font-weight:800;color:var(--text)}
    .card-body{padding:20px}

    /* ── Form elements ────────────────────────── */
    .flabel{display:block;font-size:12px;font-weight:700;color:var(--gray-600);margin-bottom:5px;letter-spacing:.02em}
    [data-theme="dark"] .flabel{color:var(--gray-500)}
    .form-control{width:100%;padding:9px 12px;background:var(--gray-50);border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;color:var(--text);outline:none;transition:all .15s}
    .form-control:focus{background:var(--surface);border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-ring)}
    [data-theme="dark"] .form-control{background:var(--gray-100)}
    .form-group{margin-bottom:16px}
    textarea.form-control{resize:vertical;min-height:260px;font-family:'JetBrains Mono',monospace;font-size:12px;line-height:1.6}

    /* ── Target audience radio cards ─────────── */
    .target-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:4px}
    .target-card{
      border:1.5px solid var(--border);border-radius:10px;padding:12px 14px;
      cursor:pointer;transition:all .15s;background:var(--gray-50);
      display:flex;align-items:flex-start;gap:10px;
    }
    .target-card:hover{border-color:var(--primary)}
    .target-card input[type=radio]{margin-top:2px;accent-color:var(--primary);flex-shrink:0}
    .target-card.selected{border-color:var(--primary);background:var(--primary-light)}
    [data-theme="dark"] .target-card{background:var(--gray-100)}
    .target-card-label{font-size:13px;font-weight:700;color:var(--text);display:block}
    .target-card-count{font-size:11px;color:var(--text-muted);margin-top:2px}

    /* ── Template picker ──────────────────────── */
    .template-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px}
    .tmpl-card{
      border:1.5px solid var(--border);border-radius:10px;padding:12px;
      cursor:pointer;transition:all .15s;background:var(--gray-50);
      text-align:center;
    }
    .tmpl-card:hover{border-color:var(--primary);transform:translateY(-2px);box-shadow:0 6px 16px rgba(59,130,246,.1)}
    .tmpl-card.active{border-color:var(--primary);background:var(--primary-light)}
    [data-theme="dark"] .tmpl-card{background:var(--gray-100)}
    .tmpl-icon{font-size:22px;margin-bottom:6px}
    .tmpl-name{font-size:11.5px;font-weight:700;color:var(--text);line-height:1.3}

    /* ── Buttons ──────────────────────────────── */
    .btn-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;border:none;transition:all .15s;text-decoration:none}
    .btn-primary{background:var(--primary);color:white}
    .btn-primary:hover{background:var(--primary-hover);transform:translateY(-1px)}
    .btn-secondary{background:var(--gray-100);color:var(--text);border:1.5px solid var(--border)}
    .btn-secondary:hover{background:var(--gray-200)}
    .btn-danger{background:var(--danger);color:white}
    .btn-sm{padding:6px 12px;font-size:12px}
    .btn-success{background:var(--success);color:white}

    /* ── Alert ────────────────────────────────── */
    .alert{padding:12px 16px;border-radius:9px;font-size:13.5px;font-weight:500;margin-bottom:18px;display:flex;align-items:flex-start;gap:9px}
    .alert-success{background:var(--success-bg);color:var(--success);border:1px solid rgba(34,197,94,.25)}
    .alert-error{background:var(--danger-bg);color:var(--danger);border:1px solid rgba(220,38,38,.2)}
    .alert svg{width:16px;height:16px;flex-shrink:0;margin-top:1px}

    /* ── Preview panel ────────────────────────── */
    .preview-panel{
      background:var(--surface);border:1px solid var(--border);
      border-radius:13px;overflow:hidden;position:sticky;top:76px;
    }
    .preview-head{
      padding:12px 18px;border-bottom:1px solid var(--border);
      display:flex;align-items:center;justify-content:space-between;gap:10px;
    }
    .preview-title{font-size:13px;font-weight:700;color:var(--text)}
    .preview-tabs{display:flex;gap:4px}
    .preview-tab{padding:5px 12px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:none;font-family:inherit;transition:all .14s;background:transparent;color:var(--text-muted)}
    .preview-tab.active{background:var(--primary);color:white}
    .preview-frame{
      height:480px;overflow:hidden;position:relative;
      background:#f0f0f0;
    }
    .preview-frame iframe{width:100%;height:100%;border:none}
    .preview-mobile-wrap{
      display:none;
      height:480px;align-items:center;justify-content:center;
      background:var(--gray-50);padding:20px;
    }
    .preview-mobile-shell{
      width:260px;background:#1a1a1a;border-radius:24px;padding:12px;
      box-shadow:0 20px 48px rgba(0,0,0,.3);
    }
    .preview-mobile-screen{
      background:white;border-radius:16px;overflow:hidden;
      height:400px;
    }
    .preview-mobile-screen iframe{width:375px;height:400px;border:none;transform:scale(0.693);transform-origin:top left}

    /* ── Campaign history table ───────────────── */
    .hist-table{width:100%;border-collapse:collapse}
    .hist-table th{background:var(--gray-50);border-bottom:1px solid var(--border);padding:8px 12px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);white-space:nowrap}
    .hist-table td{padding:10px 12px;border-bottom:1px solid var(--border);font-size:13px;color:var(--text);vertical-align:middle}
    .hist-table tr:last-child td{border-bottom:none}
    .hist-table tr:hover td{background:var(--gray-50)}
    .status-pill{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700}
    .sp-sent{background:var(--success-bg);color:var(--success)}
    .sp-sending{background:#eff6ff;color:#2563eb}
    .sp-failed{background:var(--danger-bg);color:var(--danger)}
    [data-theme="dark"] .sp-sending{background:#1e3a5f;color:#93c5fd}

    /* ── Spinner ──────────────────────────────── */
    .spinner{width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:white;border-radius:50%;animation:spin .55s linear infinite;display:inline-block}
    @keyframes spin{to{transform:rotate(360deg)}}

    /* ── Responsive ───────────────────────────── */
    @media(max-width:1100px){
      .page-grid{grid-template-columns:1fr}
      .preview-panel{position:static}
    }
    @media(max-width:900px){
      .adm-mobile-bar{display:flex}
      .adm-topbar{display:none}
      
      .adm-sidebar.open{transform:translateX(0)}
      .adm-main{margin-left:0!important}
      .adm-content{padding:16px}
      .template-grid{grid-template-columns:repeat(2,1fr)}
      .target-grid{grid-template-columns:1fr}
    }
    @media(max-width:640px){
      .template-grid{grid-template-columns:repeat(2,1fr)}
      .btn-row{flex-direction:column;align-items:stretch}
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
  <button class="adm-ham" onclick="document.getElementById('adm-sidebar').classList.toggle('open');document.getElementById('adm-overlay').classList.toggle('open')">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
  </button>
  <span style="font-weight:800;font-size:14px;color:var(--text)"><?= $app_name ?> <span style="font-size:10px;background:#dc2626;color:white;padding:1px 6px;border-radius:99px;font-weight:700;text-transform:uppercase;vertical-align:middle">Admin</span></span>
</div>

<div class="adm-shell">
  <!-- Sidebar -->
  <?php include 'sidebar.php'; ?>

  <!-- Main -->
  <div class="adm-main">
    <div class="adm-topbar">
      <span class="adm-topbar-title">📧 Bulk Email Marketing</span>
    </div>

    <div class="adm-content">
      <?php if ($msg): ?>
      <div class="alert alert-success"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg><span><?= $msg ?></span></div>
      <?php endif; ?>
      <?php if ($err): ?>
      <div class="alert alert-error"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><span><?= htmlspecialchars($err) ?></span></div>
      <?php endif; ?>

      <div class="page-grid">
        <!-- LEFT: Compose form -->
        <div>
          <form method="POST" id="bulk-form" onsubmit="return confirmSend(this)">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="send_bulk">
            <input type="hidden" name="is_test" id="is_test_hidden" value="0">

            <!-- Template Picker -->
            <div class="card">
              <div class="card-head">
                <div class="card-icon" style="background:#fdf4ff">🎨</div>
                <span class="card-title">Choose a Template</span>
                <span style="margin-left:auto;font-size:11.5px;color:var(--text-muted)">Click to load into editor</span>
              </div>
              <div class="card-body" style="padding-bottom:12px">
                <div class="template-grid" id="template-grid">
                  <!-- Templates rendered by JS -->
                </div>
                <div style="font-size:11.5px;color:var(--text-muted)">💡 Variables available: <code style="background:var(--gray-100);padding:1px 5px;border-radius:4px;font-family:monospace">{{name}}</code> <code style="background:var(--gray-100);padding:1px 5px;border-radius:4px;font-family:monospace">{{email}}</code> <code style="background:var(--gray-100);padding:1px 5px;border-radius:4px;font-family:monospace">{{app_name}}</code> <code style="background:var(--gray-100);padding:1px 5px;border-radius:4px;font-family:monospace">{{base_url}}</code></div>
              </div>
            </div>

            <!-- Compose -->
            <div class="card">
              <div class="card-head">
                <div class="card-icon" style="background:#eff6ff">✍️</div>
                <span class="card-title">Compose Email</span>
              </div>
              <div class="card-body">
                <div class="form-group">
                  <label class="flabel">Subject Line <span style="color:var(--danger)">*</span></label>
                  <input type="text" name="subject" id="email-subject" class="form-control" placeholder="e.g. 🎉 Exclusive offer just for you!" required>
                </div>
                <div class="form-group" style="margin-bottom:6px">
                  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
                    <label class="flabel" style="margin:0">HTML Body <span style="color:var(--danger)">*</span></label>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="updatePreview()">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                      Refresh Preview
                    </button>
                  </div>
                  <textarea name="html_body" id="email-body" class="form-control" placeholder="Paste or type your HTML email here..." required oninput="debouncedPreview()"></textarea>
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
                      <span class="target-card-count"><?= $count_all ?> users</span>
                    </div>
                  </label>
                  <label class="target-card" onclick="selectTarget(this,'active_servers')">
                    <input type="radio" name="target" value="active_servers">
                    <div>
                      <span class="target-card-label">🟢 Running Servers</span>
                      <span class="target-card-count"><?= $count_active ?> users</span>
                    </div>
                  </label>
                  <label class="target-card" onclick="selectTarget(this,'no_servers')">
                    <input type="radio" name="target" value="no_servers">
                    <div>
                      <span class="target-card-label">💤 No Servers Yet</span>
                      <span class="target-card-count"><?= $count_none ?> users</span>
                    </div>
                  </label>
                  <label class="target-card" onclick="selectTarget(this,'low_balance')">
                    <input type="radio" name="target" value="low_balance">
                    <div>
                      <span class="target-card-label">⚠️ Low Balance (&lt;₹100)</span>
                      <span class="target-card-count"><?= $count_low ?> users</span>
                    </div>
                  </label>
                </div>
              </div>
            </div>

            <!-- Test + Send -->
            <div class="card">
              <div class="card-head">
                <div class="card-icon" style="background:#fff7ed">🚀</div>
                <span class="card-title">Send Campaign</span>
              </div>
              <div class="card-body">
                <div class="form-group">
                  <label class="flabel">Send Test Email To</label>
                  <input type="email" name="test_email" id="test-email" class="form-control" placeholder="<?= htmlspecialchars($user['email']) ?>">
                  <div style="font-size:11.5px;color:var(--text-muted);margin-top:4px">Leave blank to send test to your admin email.</div>
                </div>
                <div class="btn-row">
                  <button type="button" class="btn btn-secondary" onclick="sendTest()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    Send Test Email
                  </button>
                  <button type="submit" class="btn btn-primary" id="send-btn">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    Send to All <span id="send-count" style="background:rgba(255,255,255,.2);padding:1px 7px;border-radius:99px;font-size:11px;margin-left:2px"><?= $count_all ?></span>
                  </button>
                </div>
              </div>
            </div>

          </form>

          <!-- Campaign History -->
          <div class="card">
            <div class="card-head">
              <div class="card-icon" style="background:#f0f9ff">📊</div>
              <span class="card-title">Campaign History</span>
            </div>
            <?php if (empty($campaigns)): ?>
            <div style="padding:32px;text-align:center;color:var(--text-muted);font-size:13px">No campaigns sent yet. Send your first campaign above!</div>
            <?php else: ?>
            <div style="overflow-x:auto">
              <table class="hist-table">
                <thead>
                  <tr>
                    <th>Subject</th>
                    <th>Target</th>
                    <th>Recipients</th>
                    <th>Sent</th>
                    <th>Failed</th>
                    <th>Status</th>
                    <th>Date</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($campaigns as $c): ?>
                  <tr>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($c['subject']) ?></td>
                    <td><span style="font-size:11.5px;background:var(--gray-100);padding:2px 8px;border-radius:99px;font-weight:600"><?= htmlspecialchars($c['target_audience'] ?? 'all') ?></span></td>
                    <td><?= number_format((int)($c['total_recipients'] ?? 0)) ?></td>
                    <td style="color:var(--success);font-weight:700"><?= number_format((int)($c['sent_count'] ?? 0)) ?></td>
                    <td style="color:<?= (int)($c['failed_count'] ?? 0) > 0 ? 'var(--danger)' : 'var(--text-muted)' ?>;font-weight:700"><?= number_format((int)($c['failed_count'] ?? 0)) ?></td>
                    <td><span class="status-pill sp-<?= $c['status'] ?? 'sent' ?>"><?= ucfirst($c['status'] ?? 'sent') ?></span></td>
                    <td style="font-size:12px;color:var(--text-muted);white-space:nowrap"><?= date('d M y, H:i', strtotime($c['created_at'])) ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>
          </div>
        </div><!-- /left -->

        <!-- RIGHT: Preview panel -->
        <div>
          <div class="preview-panel">
            <div class="preview-head">
              <span class="preview-title">📱 Live Preview</span>
              <div class="preview-tabs">
                <button class="preview-tab active" onclick="switchPreview('desktop',this)">Desktop</button>
                <button class="preview-tab" onclick="switchPreview('mobile',this)">Mobile</button>
              </div>
            </div>
            <!-- Subject preview bar -->
            <div style="padding:10px 16px;border-bottom:1px solid var(--border);background:var(--gray-50)">
              <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:4px">Subject</div>
              <div id="preview-subject" style="font-size:13px;font-weight:600;color:var(--text)">— No subject yet —</div>
            </div>
            <!-- Desktop preview -->
            <div class="preview-frame" id="preview-desktop">
              <iframe id="preview-iframe" sandbox="allow-same-origin"></iframe>
            </div>
            <!-- Mobile preview -->
            <div class="preview-mobile-wrap" id="preview-mobile" style="display:none">
              <div class="preview-mobile-shell">
                <div style="width:32px;height:4px;background:#444;border-radius:2px;margin:0 auto 8px"></div>
                <div class="preview-mobile-screen">
                  <iframe id="preview-iframe-mobile" sandbox="allow-same-origin"></iframe>
                </div>
                <div style="width:28px;height:28px;border-radius:50%;border:2px solid #444;margin:8px auto 0"></div>
              </div>
            </div>
          </div>
        </div>
      </div><!-- /page-grid -->
    </div><!-- /adm-content -->
  </div><!-- /adm-main -->
</div><!-- /adm-shell -->

<script>
var BASE_URL = "<?= BASE_URL ?>";
var APP_NAME = "<?= addslashes($app_name) ?>";

// ══════════════════════════════════════════════════════════
// EMAIL TEMPLATES — 12 ready-made templates
// ══════════════════════════════════════════════════════════
var EMAIL_TEMPLATES = [

  { id:'welcome', icon:'👋', name:'Welcome', subject:'Welcome to {{app_name}}!',
    body: `<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Welcome</title></head><body style="margin:0;padding:0;background:#f8fafc;font-family:'Segoe UI',sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px">
<table width="580" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)">
  <tr><td style="background:linear-gradient(135deg,#1d4ed8,#0891b2);padding:40px 40px 36px;text-align:center">
    <div style="font-size:36px;margin-bottom:10px">☁️</div>
    <h1 style="color:white;font-size:26px;font-weight:900;margin:0;letter-spacing:-1px">Welcome to {{app_name}}!</h1>
    <p style="color:rgba(255,255,255,.8);font-size:15px;margin:10px 0 0">Your cloud journey starts now.</p>
  </td></tr>
  <tr><td style="padding:36px 40px">
    <p style="color:#1e293b;font-size:16px;font-weight:700;margin:0 0 10px">Hi {{name}},</p>
    <p style="color:#475569;font-size:14px;line-height:1.7;margin:0 0 24px">We're thrilled to have you on board. With {{app_name}}, you can deploy blazing-fast VPS servers in under 60 seconds across 6 cloud providers — all billed in INR with no setup fees.</p>
    <div style="background:#f8fafc;border-radius:12px;padding:20px 24px;margin-bottom:24px;border:1px solid #e2e8f0">
      <div style="font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-bottom:12px">What you can do</div>
      <div style="color:#334155;font-size:14px;line-height:1.8">✅ Deploy VPS in &lt; 60 seconds<br>✅ Manage firewalls &amp; SSH keys<br>✅ Pay by the hour in INR<br>✅ Access via REST API</div>
    </div>
    <table cellpadding="0" cellspacing="0"><tr><td style="background:linear-gradient(135deg,#1d4ed8,#2563eb);border-radius:10px">
      <a href="{{base_url}}/dashboard.php" style="display:block;padding:14px 32px;color:white;text-decoration:none;font-size:15px;font-weight:800;letter-spacing:-.3px">Launch Dashboard →</a>
    </td></tr></table>
  </td></tr>
  <tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:20px 40px;text-align:center">
    <p style="color:#94a3b8;font-size:12px;margin:0">© 2025 {{app_name}} · <a href="{{base_url}}" style="color:#64748b">Visit Website</a></p>
  </td></tr>
</table></td></tr></table></body></html>` },

  { id:'topup', icon:'💳', name:'Add Credits', subject:'Your {{app_name}} wallet needs a top-up',
    body: `<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f8fafc;font-family:'Segoe UI',sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px">
<table width="580" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)">
  <tr><td style="background:linear-gradient(135deg,#f59e0b,#ef4444);padding:36px 40px;text-align:center">
    <div style="font-size:40px;margin-bottom:8px">⚠️</div>
    <h1 style="color:white;font-size:24px;font-weight:900;margin:0">Low Wallet Balance</h1>
    <p style="color:rgba(255,255,255,.85);font-size:14px;margin:8px 0 0">Your servers may be suspended soon</p>
  </td></tr>
  <tr><td style="padding:36px 40px">
    <p style="color:#1e293b;font-size:15px;font-weight:700;margin:0 0 10px">Hi {{name}},</p>
    <p style="color:#475569;font-size:14px;line-height:1.7;margin:0 0 20px">Your {{app_name}} wallet balance is running low. To keep your servers running without interruption, please top up before your balance reaches ₹0.</p>
    <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:12px;padding:18px 22px;margin-bottom:24px;text-align:center">
      <div style="font-size:12px;font-weight:700;text-transform:uppercase;color:#92400e;margin-bottom:6px">Action Required</div>
      <div style="font-size:22px;font-weight:900;color:#92400e">Add Credits Now</div>
    </div>
    <table cellpadding="0" cellspacing="0"><tr><td style="background:linear-gradient(135deg,#f59e0b,#d97706);border-radius:10px">
      <a href="{{base_url}}/billing.php" style="display:block;padding:13px 30px;color:white;text-decoration:none;font-size:14px;font-weight:800">💳 Top Up Wallet →</a>
    </td></tr></table>
  </td></tr>
  <tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:18px 40px;text-align:center">
    <p style="color:#94a3b8;font-size:12px;margin:0">© 2025 {{app_name}} · <a href="{{base_url}}" style="color:#64748b">Visit Website</a></p>
  </td></tr>
</table></td></tr></table></body></html>` },

  { id:'promo', icon:'🎉', name:'Promotion', subject:'🎉 Special offer — {{app_name}}',
    body: `<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#0f172a;font-family:'Segoe UI',sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px">
<table width="580" cellpadding="0" cellspacing="0" style="background:#1e293b;border-radius:16px;overflow:hidden;border:1px solid rgba(255,255,255,.08)">
  <tr><td style="padding:48px 40px;text-align:center;background:linear-gradient(135deg,rgba(59,130,246,.2),rgba(139,92,246,.15))">
    <div style="font-size:44px;margin-bottom:12px">🎉</div>
    <h1 style="color:white;font-size:28px;font-weight:900;margin:0;letter-spacing:-1px">Exclusive Offer Inside</h1>
    <p style="color:rgba(255,255,255,.7);font-size:15px;margin:10px 0 0">Limited time for {{app_name}} users</p>
  </td></tr>
  <tr><td style="padding:36px 40px">
    <p style="color:#e2e8f0;font-size:15px;font-weight:700;margin:0 0 10px">Hi {{name}},</p>
    <p style="color:#94a3b8;font-size:14px;line-height:1.7;margin:0 0 24px">We have an exclusive offer just for you. Deploy more, pay less with our limited-time discount on all VPS plans.</p>
    <div style="background:rgba(59,130,246,.15);border:1px solid rgba(59,130,246,.3);border-radius:12px;padding:24px;margin-bottom:24px;text-align:center">
      <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#93c5fd;margin-bottom:8px">Use Coupon Code</div>
      <div style="font-size:32px;font-weight:900;color:white;letter-spacing:4px;font-family:monospace">SAVE20</div>
      <div style="color:#64748b;font-size:12px;margin-top:8px">20% off your next wallet top-up · Valid for 48 hours</div>
    </div>
    <table cellpadding="0" cellspacing="0"><tr><td style="background:linear-gradient(135deg,#2563eb,#7c3aed);border-radius:10px">
      <a href="{{base_url}}/billing.php" style="display:block;padding:14px 32px;color:white;text-decoration:none;font-size:15px;font-weight:800">🚀 Claim Your Discount →</a>
    </td></tr></table>
  </td></tr>
  <tr><td style="background:rgba(255,255,255,.03);border-top:1px solid rgba(255,255,255,.08);padding:18px 40px;text-align:center">
    <p style="color:#475569;font-size:12px;margin:0">© 2025 {{app_name}} · <a href="{{base_url}}" style="color:#64748b">Unsubscribe</a></p>
  </td></tr>
</table></td></tr></table></body></html>` },

  { id:'maintenance', icon:'🔧', name:'Maintenance', subject:'⚠️ Scheduled Maintenance — {{app_name}}',
    body: `<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f8fafc;font-family:'Segoe UI',sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px">
<table width="580" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)">
  <tr><td style="background:#1e293b;padding:36px 40px;text-align:center">
    <div style="font-size:38px;margin-bottom:10px">🔧</div>
    <h1 style="color:white;font-size:24px;font-weight:900;margin:0">Scheduled Maintenance</h1>
    <p style="color:rgba(255,255,255,.7);font-size:14px;margin:8px 0 0">Please read before it affects your servers</p>
  </td></tr>
  <tr><td style="padding:36px 40px">
    <p style="color:#1e293b;font-size:15px;font-weight:700;margin:0 0 10px">Hi {{name}},</p>
    <p style="color:#475569;font-size:14px;line-height:1.7;margin:0 0 20px">We will be performing scheduled maintenance on our infrastructure. During this window, some services may be temporarily unavailable.</p>
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #f59e0b;border-radius:8px;padding:18px 22px;margin-bottom:24px">
      <div style="font-size:12px;font-weight:700;color:#92400e;margin-bottom:8px">⏰ MAINTENANCE WINDOW</div>
      <div style="font-size:15px;font-weight:800;color:#1e293b">Date: [INSERT DATE]</div>
      <div style="font-size:14px;color:#475569;margin-top:4px">Duration: Approximately 2 hours</div>
      <div style="font-size:14px;color:#475569;margin-top:2px">Impact: Dashboard may be intermittently unavailable</div>
    </div>
    <p style="color:#475569;font-size:14px;line-height:1.7;margin:0 0 20px">Your running servers will <strong>not be affected</strong> during this maintenance window. Only the management dashboard will have limited access.</p>
    <table cellpadding="0" cellspacing="0"><tr><td style="background:#1e293b;border-radius:10px">
      <a href="{{base_url}}/dashboard.php" style="display:block;padding:13px 28px;color:white;text-decoration:none;font-size:14px;font-weight:700">Check Server Status →</a>
    </td></tr></table>
  </td></tr>
  <tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:18px 40px;text-align:center">
    <p style="color:#94a3b8;font-size:12px;margin:0">© 2025 {{app_name}} · <a href="{{base_url}}" style="color:#64748b">Visit Website</a></p>
  </td></tr>
</table></td></tr></table></body></html>` },

  { id:'newfeature', icon:'✨', name:'New Feature', subject:'✨ New feature just launched on {{app_name}}',
    body: `<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f8fafc;font-family:'Segoe UI',sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px">
<table width="580" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)">
  <tr><td style="background:linear-gradient(135deg,#6d28d9,#2563eb);padding:40px 40px 36px;text-align:center">
    <div style="font-size:40px;margin-bottom:10px">✨</div>
    <h1 style="color:white;font-size:26px;font-weight:900;margin:0">New Feature Alert</h1>
    <p style="color:rgba(255,255,255,.8);font-size:14px;margin:10px 0 0">We just shipped something awesome for you</p>
  </td></tr>
  <tr><td style="padding:36px 40px">
    <p style="color:#1e293b;font-size:15px;font-weight:700;margin:0 0 10px">Hi {{name}},</p>
    <p style="color:#475569;font-size:14px;line-height:1.7;margin:0 0 24px">We've been working hard to make {{app_name}} even better. Here's what's new:</p>
    <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:24px">
      <div style="display:flex;align-items:flex-start;gap:12px;padding:14px 16px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0">
        <div style="font-size:20px">🚀</div>
        <div><div style="font-size:14px;font-weight:700;color:#1e293b;margin-bottom:3px">[Feature Name]</div><div style="font-size:13px;color:#64748b;line-height:1.5">[Describe what this feature does and how it benefits the user]</div></div>
      </div>
      <div style="display:flex;align-items:flex-start;gap:12px;padding:14px 16px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0">
        <div style="font-size:20px">⚡</div>
        <div><div style="font-size:14px;font-weight:700;color:#1e293b;margin-bottom:3px">[Feature 2]</div><div style="font-size:13px;color:#64748b;line-height:1.5">[Description]</div></div>
      </div>
    </div>
    <table cellpadding="0" cellspacing="0"><tr><td style="background:linear-gradient(135deg,#6d28d9,#2563eb);border-radius:10px">
      <a href="{{base_url}}/dashboard.php" style="display:block;padding:14px 30px;color:white;text-decoration:none;font-size:14px;font-weight:800">Try It Now →</a>
    </td></tr></table>
  </td></tr>
  <tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:18px 40px;text-align:center">
    <p style="color:#94a3b8;font-size:12px;margin:0">© 2025 {{app_name}} · <a href="{{base_url}}" style="color:#64748b">Unsubscribe</a></p>
  </td></tr>
</table></td></tr></table></body></html>` },

  { id:'deploy', icon:'🖥️', name:'Deploy Now', subject:'Your server is just 60 seconds away — {{app_name}}',
    body: `<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#0f172a;font-family:'Segoe UI',sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px">
<table width="580" cellpadding="0" cellspacing="0" style="background:#1e293b;border-radius:16px;overflow:hidden;border:1px solid rgba(255,255,255,.08)">
  <tr><td style="padding:48px 40px;text-align:center">
    <div style="background:rgba(59,130,246,.15);width:64px;height:64px;border-radius:16px;margin:0 auto 16px;display:flex;align-items:center;justify-content:center;font-size:32px">🖥️</div>
    <h1 style="color:white;font-size:26px;font-weight:900;margin:0 0 10px;letter-spacing:-1px">Deploy Your VPS in 60 Seconds</h1>
    <p style="color:rgba(255,255,255,.6);font-size:14px;margin:0">You have credits — time to build something great</p>
  </td></tr>
  <tr><td style="padding:8px 40px 36px">
    <p style="color:#e2e8f0;font-size:14px;font-weight:600;margin:0 0 8px">Hey {{name}},</p>
    <p style="color:#94a3b8;font-size:14px;line-height:1.7;margin:0 0 24px">You haven't deployed a server yet. It's quick and easy — pick a plan, choose your OS and region, and you'll have a running VPS in under a minute.</p>
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px">
      <tr>
        <td style="width:30%;padding:14px;background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);border-radius:10px;text-align:center;vertical-align:top">
          <div style="font-size:20px;margin-bottom:6px">⚡</div>
          <div style="font-size:12px;font-weight:700;color:#93c5fd">Boot Time</div>
          <div style="font-size:18px;font-weight:900;color:white">&lt; 60s</div>
        </td>
        <td style="width:5%"></td>
        <td style="width:30%;padding:14px;background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.2);border-radius:10px;text-align:center;vertical-align:top">
          <div style="font-size:20px;margin-bottom:6px">💰</div>
          <div style="font-size:12px;font-weight:700;color:#4ade80">Starting</div>
          <div style="font-size:18px;font-weight:900;color:white">₹299/mo</div>
        </td>
        <td style="width:5%"></td>
        <td style="width:30%;padding:14px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:10px;text-align:center;vertical-align:top">
          <div style="font-size:20px;margin-bottom:6px">🌐</div>
          <div style="font-size:12px;font-weight:700;color:#fcd34d">Providers</div>
          <div style="font-size:18px;font-weight:900;color:white">6+</div>
        </td>
      </tr>
    </table>
    <table cellpadding="0" cellspacing="0"><tr><td style="background:linear-gradient(135deg,#1d4ed8,#2563eb);border-radius:10px">
      <a href="{{base_url}}/servers/create.php" style="display:block;padding:14px 32px;color:white;text-decoration:none;font-size:15px;font-weight:800">🚀 Deploy My First Server →</a>
    </td></tr></table>
  </td></tr>
  <tr><td style="background:rgba(255,255,255,.03);border-top:1px solid rgba(255,255,255,.08);padding:18px 40px;text-align:center">
    <p style="color:#475569;font-size:12px;margin:0">© 2025 {{app_name}} · <a href="{{base_url}}" style="color:#64748b">Unsubscribe</a></p>
  </td></tr>
</table></td></tr></table></body></html>` },

  { id:'invoice', icon:'🧾', name:'Invoice Ready', subject:'Your invoice from {{app_name}} is ready',
    body: `<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f8fafc;font-family:'Segoe UI',sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px">
<table width="580" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)">
  <tr><td style="padding:32px 40px;display:flex;align-items:center;justify-content:space-between;border-bottom:2px solid #e2e8f0">
    <div><div style="font-size:22px;font-weight:900;color:#1e293b">{{app_name}}</div><div style="font-size:12px;color:#64748b">Cloud Infrastructure</div></div>
    <div style="text-align:right"><div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase">Invoice</div><div style="font-size:20px;font-weight:900;color:#1e293b">#INV-[NUM]</div></div>
  </td></tr>
  <tr><td style="padding:32px 40px">
    <p style="color:#1e293b;font-size:14px;margin:0 0 6px"><strong>Billed to:</strong> {{name}}</p>
    <p style="color:#64748b;font-size:13px;margin:0 0 24px">{{email}}</p>
    <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-bottom:24px">
      <tr style="background:#f8fafc"><td style="padding:10px 16px;font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b">Description</td><td style="padding:10px 16px;font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;text-align:right">Amount</td></tr>
      <tr><td style="padding:12px 16px;border-top:1px solid #e2e8f0;font-size:13px;color:#1e293b">VPS Hosting — [Server Name]</td><td style="padding:12px 16px;border-top:1px solid #e2e8f0;font-size:13px;color:#1e293b;text-align:right;font-weight:700">₹[AMOUNT]</td></tr>
      <tr><td style="padding:12px 16px;border-top:1px solid #e2e8f0;font-size:13px;font-weight:800;color:#1e293b">Total</td><td style="padding:12px 16px;border-top:1px solid #e2e8f0;font-size:16px;font-weight:900;color:#1d4ed8;text-align:right">₹[AMOUNT]</td></tr>
    </table>
    <table cellpadding="0" cellspacing="0"><tr><td style="background:#1e293b;border-radius:10px">
      <a href="{{base_url}}/billing.php" style="display:block;padding:12px 26px;color:white;text-decoration:none;font-size:13px;font-weight:700">View Invoice →</a>
    </td></tr></table>
  </td></tr>
  <tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:18px 40px;text-align:center">
    <p style="color:#94a3b8;font-size:12px;margin:0">© 2025 {{app_name}} · <a href="{{base_url}}" style="color:#64748b">Visit Website</a></p>
  </td></tr>
</table></td></tr></table></body></html>` },

  { id:'security', icon:'🔒', name:'Security Alert', subject:'🔒 Security notice for your {{app_name}} account',
    body: `<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f8fafc;font-family:'Segoe UI',sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px">
<table width="580" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)">
  <tr><td style="background:linear-gradient(135deg,#dc2626,#b91c1c);padding:36px 40px;text-align:center">
    <div style="font-size:40px;margin-bottom:10px">🔒</div>
    <h1 style="color:white;font-size:24px;font-weight:900;margin:0">Security Notice</h1>
    <p style="color:rgba(255,255,255,.85);font-size:14px;margin:8px 0 0">Important information about your account</p>
  </td></tr>
  <tr><td style="padding:36px 40px">
    <p style="color:#1e293b;font-size:15px;font-weight:700;margin:0 0 10px">Hi {{name}},</p>
    <p style="color:#475569;font-size:14px;line-height:1.7;margin:0 0 20px">We detected [suspicious activity / a new login / a security update] on your {{app_name}} account. Please review the details below:</p>
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:18px 22px;margin-bottom:24px">
      <div style="font-size:13px;font-weight:700;color:#dc2626;margin-bottom:8px">⚠️ Security Event Details</div>
      <div style="font-size:13px;color:#7f1d1d;line-height:1.8">
        Time: [TIMESTAMP]<br>
        IP Address: [IP_ADDRESS]<br>
        Location: [LOCATION]<br>
        Action: [ACTION]
      </div>
    </div>
    <p style="color:#475569;font-size:14px;line-height:1.7;margin:0 0 20px">If this was you, no action is needed. If you don't recognize this activity, please secure your account immediately.</p>
    <table cellpadding="0" cellspacing="0"><tr><td style="background:linear-gradient(135deg,#dc2626,#b91c1c);border-radius:10px">
      <a href="{{base_url}}/profile.php" style="display:block;padding:13px 28px;color:white;text-decoration:none;font-size:14px;font-weight:800">Secure My Account →</a>
    </td></tr></table>
  </td></tr>
  <tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:18px 40px;text-align:center">
    <p style="color:#94a3b8;font-size:12px;margin:0">© 2025 {{app_name}} · <a href="{{base_url}}" style="color:#64748b">Visit Website</a></p>
  </td></tr>
</table></td></tr></table></body></html>` },

  { id:'reactivate', icon:'🔁', name:'Re-engage', subject:'We miss you — come back to {{app_name}}',
    body: `<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#0f172a;font-family:'Segoe UI',sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px">
<table width="580" cellpadding="0" cellspacing="0" style="background:#1e293b;border-radius:16px;overflow:hidden;border:1px solid rgba(255,255,255,.08)">
  <tr><td style="padding:48px 40px;text-align:center;background:linear-gradient(135deg,rgba(139,92,246,.2),rgba(59,130,246,.1))">
    <div style="font-size:48px;margin-bottom:12px">👋</div>
    <h1 style="color:white;font-size:26px;font-weight:900;margin:0;letter-spacing:-1px">We Miss You, {{name}}!</h1>
    <p style="color:rgba(255,255,255,.65);font-size:14px;margin:10px 0 0">It's been a while. Here's what's changed.</p>
  </td></tr>
  <tr><td style="padding:36px 40px">
    <p style="color:#94a3b8;font-size:14px;line-height:1.7;margin:0 0 24px">Since you last logged in, we've made {{app_name}} even better. Here's a quick recap of what's new for you:</p>
    <div style="margin-bottom:24px">
      <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;background:rgba(255,255,255,.04);border-radius:10px;margin-bottom:8px;border:1px solid rgba(255,255,255,.06)">
        <span style="font-size:18px">🚀</span><span style="color:#e2e8f0;font-size:13px">New providers added — deploy on even more cloud platforms</span>
      </div>
      <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;background:rgba(255,255,255,.04);border-radius:10px;margin-bottom:8px;border:1px solid rgba(255,255,255,.06)">
        <span style="font-size:18px">💡</span><span style="color:#e2e8f0;font-size:13px">Improved dashboard with real-time metrics</span>
      </div>
      <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;background:rgba(255,255,255,.04);border-radius:10px;border:1px solid rgba(255,255,255,.06)">
        <span style="font-size:18px">🎁</span><span style="color:#e2e8f0;font-size:13px">Special welcome-back credits for returning users</span>
      </div>
    </div>
    <table cellpadding="0" cellspacing="0"><tr><td style="background:linear-gradient(135deg,#7c3aed,#2563eb);border-radius:10px">
      <a href="{{base_url}}/dashboard.php" style="display:block;padding:14px 32px;color:white;text-decoration:none;font-size:15px;font-weight:800">Come Back &amp; Explore →</a>
    </td></tr></table>
  </td></tr>
  <tr><td style="background:rgba(255,255,255,.03);border-top:1px solid rgba(255,255,255,.08);padding:18px 40px;text-align:center">
    <p style="color:#475569;font-size:12px;margin:0">© 2025 {{app_name}} · <a href="{{base_url}}" style="color:#64748b">Unsubscribe</a></p>
  </td></tr>
</table></td></tr></table></body></html>` },

  { id:'downtime', icon:'🛑', name:'Downtime', subject:'🛑 Service disruption update — {{app_name}}',
    body: `<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f8fafc;font-family:'Segoe UI',sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px">
<table width="580" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)">
  <tr><td style="background:#7f1d1d;padding:36px 40px;text-align:center">
    <div style="font-size:40px;margin-bottom:10px">🛑</div>
    <h1 style="color:white;font-size:24px;font-weight:900;margin:0">Service Disruption</h1>
    <p style="color:rgba(255,255,255,.8);font-size:14px;margin:8px 0 0">We are aware and actively working on a fix</p>
  </td></tr>
  <tr><td style="padding:36px 40px">
    <p style="color:#1e293b;font-size:15px;font-weight:700;margin:0 0 10px">Hi {{name}},</p>
    <p style="color:#475569;font-size:14px;line-height:1.7;margin:0 0 20px">We are currently experiencing a service disruption that may be affecting your servers or dashboard access. We sincerely apologize for the inconvenience.</p>
    <div style="background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #dc2626;border-radius:8px;padding:18px 22px;margin-bottom:24px">
      <div style="font-size:13px;font-weight:700;color:#dc2626;margin-bottom:6px">Current Status</div>
      <div style="font-size:13px;color:#7f1d1d;line-height:1.8">Affected service: [SERVICE NAME]<br>Started at: [TIME]<br>Estimated resolution: [TIME]<br>Updates: Every 30 minutes</div>
    </div>
    <p style="color:#475569;font-size:14px;line-height:1.7;margin:0">Our engineering team is working around the clock to restore full service. We will send an update as soon as this is resolved.</p>
  </td></tr>
  <tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:18px 40px;text-align:center">
    <p style="color:#94a3b8;font-size:12px;margin:0">© 2025 {{app_name}} · <a href="{{base_url}}" style="color:#64748b">Visit Website</a></p>
  </td></tr>
</table></td></tr></table></body></html>` },

  { id:'newsletter', icon:'📰', name:'Newsletter', subject:'{{app_name}} Monthly Digest — [Month Year]',
    body: `<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f1f5f9;font-family:'Segoe UI',sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px">
<table width="600" cellpadding="0" cellspacing="0">
  <!-- Header -->
  <tr><td style="background:linear-gradient(135deg,#1e293b,#0f172a);border-radius:16px 16px 0 0;padding:32px 40px;text-align:center">
    <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:rgba(255,255,255,.5);margin-bottom:8px">{{app_name}} Newsletter</div>
    <h1 style="color:white;font-size:24px;font-weight:900;margin:0">Monthly Digest</h1>
    <div style="color:rgba(255,255,255,.5);font-size:13px;margin-top:6px">[Month Year]</div>
  </td></tr>
  <!-- Body -->
  <tr><td style="background:white;padding:36px 40px">
    <p style="color:#1e293b;font-size:14px;margin:0 0 6px">Hi {{name}},</p>
    <p style="color:#475569;font-size:14px;line-height:1.7;margin:0 0 28px">Here's your monthly roundup from {{app_name}} — platform updates, tips, and what's coming next.</p>
    <!-- Stats -->
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px">
      <tr>
        <td style="width:31%;padding:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;text-align:center">
          <div style="font-size:22px;font-weight:900;color:#1d4ed8">[X]</div>
          <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-top:4px">Servers Deployed</div>
        </td>
        <td style="width:4%"></td>
        <td style="width:31%;padding:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;text-align:center">
          <div style="font-size:22px;font-weight:900;color:#16a34a">[X]%</div>
          <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-top:4px">Uptime</div>
        </td>
        <td style="width:4%"></td>
        <td style="width:30%;padding:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;text-align:center">
          <div style="font-size:22px;font-weight:900;color:#7c3aed">[X]</div>
          <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-top:4px">New Users</div>
        </td>
      </tr>
    </table>
    <!-- Article -->
    <div style="border-left:3px solid #1d4ed8;padding-left:16px;margin-bottom:24px">
      <div style="font-size:12px;font-weight:700;text-transform:uppercase;color:#1d4ed8;margin-bottom:4px">Highlight</div>
      <div style="font-size:15px;font-weight:800;color:#1e293b;margin-bottom:6px">[Article Title]</div>
      <p style="color:#475569;font-size:13px;line-height:1.6;margin:0">[Brief summary of the article or update. Keep it 2-3 sentences.]</p>
    </div>
    <table cellpadding="0" cellspacing="0"><tr><td style="background:#1e293b;border-radius:10px">
      <a href="{{base_url}}/dashboard.php" style="display:block;padding:12px 26px;color:white;text-decoration:none;font-size:13px;font-weight:700">Visit Your Dashboard →</a>
    </td></tr></table>
  </td></tr>
  <tr><td style="background:#e2e8f0;border-radius:0 0 16px 16px;padding:18px 40px;text-align:center">
    <p style="color:#94a3b8;font-size:12px;margin:0">© 2025 {{app_name}} · <a href="{{base_url}}" style="color:#64748b">Unsubscribe</a> · <a href="{{base_url}}" style="color:#64748b">View Online</a></p>
  </td></tr>
</table></td></tr></table></body></html>` },

  { id:'referral', icon:'🤝', name:'Referral', subject:'Give ₹200, Get ₹200 — {{app_name}} Referral',
    body: `<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f8fafc;font-family:'Segoe UI',sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px">
<table width="580" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)">
  <tr><td style="background:linear-gradient(135deg,#16a34a,#059669);padding:40px 40px 36px;text-align:center">
    <div style="font-size:44px;margin-bottom:10px">🤝</div>
    <h1 style="color:white;font-size:26px;font-weight:900;margin:0;letter-spacing:-1px">Refer a Friend</h1>
    <p style="color:rgba(255,255,255,.85);font-size:15px;margin:10px 0 0">You both get ₹200 in wallet credits!</p>
  </td></tr>
  <tr><td style="padding:36px 40px">
    <p style="color:#1e293b;font-size:15px;font-weight:700;margin:0 0 10px">Hi {{name}},</p>
    <p style="color:#475569;font-size:14px;line-height:1.7;margin:0 0 24px">Know someone who needs reliable cloud hosting? Refer them to {{app_name}} and you <strong>both</strong> earn ₹200 in wallet credits — enough for a free month on our Starter plan!</p>
    <div style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1px solid #86efac;border-radius:12px;padding:24px;margin-bottom:24px;text-align:center">
      <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#166534;margin-bottom:8px">Your Referral Code</div>
      <div style="font-size:28px;font-weight:900;color:#16a34a;letter-spacing:4px;font-family:monospace">[REF_CODE]</div>
      <div style="font-size:12px;color:#4ade80;margin-top:6px">Share this with friends</div>
    </div>
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px">
      <tr>
        <td style="width:46%;padding:14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;text-align:center">
          <div style="font-size:20px;margin-bottom:4px">👤</div>
          <div style="font-size:12px;font-weight:700;color:#166534">Your friend gets</div>
          <div style="font-size:20px;font-weight:900;color:#16a34a">₹200</div>
        </td>
        <td style="width:8%;text-align:center;font-size:20px">+</td>
        <td style="width:46%;padding:14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;text-align:center">
          <div style="font-size:20px;margin-bottom:4px">🎁</div>
          <div style="font-size:12px;font-weight:700;color:#166534">You get</div>
          <div style="font-size:20px;font-weight:900;color:#16a34a">₹200</div>
        </td>
      </tr>
    </table>
    <table cellpadding="0" cellspacing="0"><tr><td style="background:linear-gradient(135deg,#16a34a,#059669);border-radius:10px">
      <a href="{{base_url}}/profile.php" style="display:block;padding:14px 30px;color:white;text-decoration:none;font-size:14px;font-weight:800">Get My Referral Link →</a>
    </td></tr></table>
  </td></tr>
  <tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:18px 40px;text-align:center">
    <p style="color:#94a3b8;font-size:12px;margin:0">© 2025 {{app_name}} · <a href="{{base_url}}" style="color:#64748b">Unsubscribe</a></p>
  </td></tr>
</table></td></tr></table></body></html>` }
];

// ══════════════════════════════════════════════════════════
// Target counts
// ══════════════════════════════════════════════════════════
var TARGET_COUNTS = {
  all: <?= $count_all ?>,
  active_servers: <?= $count_active ?>,
  no_servers: <?= $count_none ?>,
  low_balance: <?= $count_low ?>,
  admins: 1
};

// ══════════════════════════════════════════════════════════
// Init — render template cards
// ══════════════════════════════════════════════════════════
(function renderTemplates() {
  var grid = document.getElementById('template-grid');
  EMAIL_TEMPLATES.forEach(function(t, i) {
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
  // Auto-load first template
  loadTemplate(EMAIL_TEMPLATES[0]);
})();

function loadTemplate(t) {
  var appName = '<?= addslashes($app_name) ?>';
  var baseUrl = '<?= BASE_URL ?>';
  document.getElementById('email-subject').value = t.subject.replace(/{{app_name}}/g, appName);
  document.getElementById('email-body').value = t.body
    .replace(/{{app_name}}/g, appName)
    .replace(/{{base_url}}/g, baseUrl);
  updatePreview();
}

// ══════════════════════════════════════════════════════════
// Live Preview
// ══════════════════════════════════════════════════════════
var _previewTimer;
function debouncedPreview() {
  clearTimeout(_previewTimer);
  _previewTimer = setTimeout(updatePreview, 600);
}

function updatePreview() {
  var html = document.getElementById('email-body').value;
  var subject = document.getElementById('email-subject').value;
  var appName = '<?= addslashes($app_name) ?>';
  var baseUrl = '<?= BASE_URL ?>';

  // Replace vars with sample values
  html = html
    .replace(/{{name}}/g, '<?= addslashes($fname) ?>')
    .replace(/{{email}}/g, '<?= addslashes($user['email']) ?>')
    .replace(/{{app_name}}/g, appName)
    .replace(/{{base_url}}/g, baseUrl);

  subject = subject
    .replace(/{{app_name}}/g, appName)
    .replace(/{{name}}/g, '<?= addslashes($fname) ?>');

  document.getElementById('preview-subject').textContent = subject || '— No subject yet —';

  // Update desktop iframe
  var iframe = document.getElementById('preview-iframe');
  var doc = iframe.contentDocument || iframe.contentWindow.document;
  doc.open(); doc.write(html); doc.close();

  // Update mobile iframe
  var mobileIframe = document.getElementById('preview-iframe-mobile');
  var mDoc = mobileIframe.contentDocument || mobileIframe.contentWindow.document;
  mDoc.open(); mDoc.write(html); mDoc.close();
}

// ══════════════════════════════════════════════════════════
// Preview tab switch
// ══════════════════════════════════════════════════════════
function switchPreview(mode, btn) {
  document.querySelectorAll('.preview-tab').forEach(function(t) { t.classList.remove('active'); });
  btn.classList.add('active');
  if (mode === 'desktop') {
    document.getElementById('preview-desktop').style.display = '';
    document.getElementById('preview-mobile').style.display = 'none';
  } else {
    document.getElementById('preview-desktop').style.display = 'none';
    document.getElementById('preview-mobile').style.display = 'flex';
    updatePreview(); // refresh mobile iframe
  }
}

// ══════════════════════════════════════════════════════════
// Target selection
// ══════════════════════════════════════════════════════════
function selectTarget(card, value) {
  document.querySelectorAll('.target-card').forEach(function(c) { c.classList.remove('selected'); });
  card.classList.add('selected');
  var count = TARGET_COUNTS[value] || 0;
  var sendBtn = document.getElementById('send-btn');
  var cntSpan = document.getElementById('send-count');
  if (cntSpan) cntSpan.textContent = count;
}

// ══════════════════════════════════════════════════════════
// Confirm before send
// ══════════════════════════════════════════════════════════
function confirmSend(form) {
  var isTest = document.getElementById('is_test_hidden').value === '1';
  if (isTest) return true;
  var count = parseInt(document.getElementById('send-count')?.textContent || '0');
  if (!confirm('Send this campaign to ' + count + ' users?\n\nThis cannot be undone.')) return false;
  var btn = document.getElementById('send-btn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Sending...';
  return true;
}

function sendTest() {
  document.getElementById('is_test_hidden').value = '1';
  document.getElementById('bulk-form').submit();
}

// ══════════════════════════════════════════════════════════
// Mobile sidebar
// ══════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', function() {
  updatePreview();
});
</script>
<script>var BASE_URL_JS = "<?= BASE_URL ?>";</script>
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
</body>
</html>
