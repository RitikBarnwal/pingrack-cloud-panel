<?php
/**
 * admin/db-backup.php
 * Database backup settings and manual trigger
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin.php';
require_admin();

$user     = current_user();
$app_name = APP_NAME;
$fname    = htmlspecialchars($user['full_name'] ?: $user['username']);
$csrf     = csrf_token();
$msg = ''; $err = '';

// ── Save settings ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $act = $_POST['action'] ?? 'save';

    if ($act === 'save') {
        $allowed = [
            'backup_db_enabled',
            'backup_interval_hours',
            'backup_emails',
            'backup_retention_days',
            'backup_db_path',
        ];
        foreach ($allowed as $k) {
            $val = $_POST[$k] ?? '';
            if ($k === 'backup_db_enabled') {
                $val = isset($_POST[$k]) ? '1' : '0';
            }
            set_setting($k, trim($val));
        }
        $msg = 'Backup settings saved.';
    }

    if ($act === 'run_now') {
        try {
            $__backup_admin_require = true;
            require_once __DIR__ . '/../cron/db-backup.php';
            $result = run_backup('db');
            $msg = $result['message'] ?? 'Backup completed.';
        } catch (Throwable $e) {
            $err = 'Backup failed: ' . $e->getMessage();
        }
    }

    if ($act === 'delete_backup') {
        $file = basename($_POST['file'] ?? '');
        $backup_dir = rtrim(get_setting('backup_db_path', __DIR__ . '/../backups'), '/') . '/';
        $full_path  = $backup_dir . $file;
        if ($file && file_exists($full_path) && str_starts_with(realpath($full_path), realpath($backup_dir))) {
            unlink($full_path);
            $msg = "Deleted: $file";
        } else {
            $err = 'File not found or invalid.';
        }
    }
}

function bs(string $key, string $default = ''): string {
    return htmlspecialchars(get_setting($key, $default));
}

$backup_dir   = rtrim(get_setting('backup_db_path', dirname(__DIR__) . '/backups'), '/');
$backup_files = [];
if (is_dir($backup_dir)) {
    $files = glob($backup_dir . '/*.{sql,sql.gz,gz}', GLOB_BRACE) ?: [];
    usort($files, fn($a,$b) => filemtime($b) - filemtime($a));
    foreach (array_slice($files, 0, 30) as $f) {
        $backup_files[] = [
            'name' => basename($f),
            'size' => filesize($f),
            'time' => filemtime($f),
        ];
    }
}

$last_db = get_setting('backup_last_db_at', '');
$next_db = '';
if ($last_db) {
    $interval = (int)get_setting('backup_interval_hours', '3');
    $next_ts  = strtotime($last_db) + ($interval * 3600);
    $next_db  = date('d M Y H:i', $next_ts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Database Backup — <?= $app_name ?> Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/admin/admin.css">
  <style>
    .adm-shell{display:flex;min-height:100vh;background:#f8fafc}
    
    .adm-logo{padding:18px 16px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:8px}
    .adm-logo-mark{width:28px;height:28px;border-radius:6px;background:var(--primary);display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .adm-logo-text{font-weight:800;font-size:14px;color:white}
    .adm-badge{font-size:9px;font-weight:700;background:#dc2626;color:white;padding:1px 6px;border-radius:99px;margin-left:4px;text-transform:uppercase}
    .adm-nav{flex:1;padding:10px 8px;overflow-y:auto}
    .adm-nav-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:rgba(255,255,255,.3);padding:10px 8px 4px}
    .adm-link{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:7px;font-size:13px;font-weight:500;color:rgba(255,255,255,.6);text-decoration:none;transition:all .14s;margin-bottom:1px}
    .adm-link:hover{background:rgba(255,255,255,.08);color:rgba(255,255,255,.9)}
    .adm-link.active{background:#22293b;color:white;font-weight:700}
    .adm-link svg{width:15px;height:15px;flex-shrink:0}
    .adm-footer-bar{padding:12px 10px;border-top:1px solid rgba(255,255,255,.08)}
    .adm-av{width:30px;height:30px;border-radius:7px;overflow:hidden}
    .adm-main{margin-left:232px;flex:1}
    .adm-topbar{background:white;border-bottom:1px solid #e2e8f0;height:56px;display:flex;align-items:center;padding:0 28px;position:sticky;top:0;z-index:30}
    .adm-topbar-title{font-size:15px;font-weight:800;color:#0f172a}
    .page{padding:24px 28px;max-width:960px}
    .bcard{background:white;border:1px solid #e2e8f0;border-radius:13px;overflow:hidden;margin-bottom:18px}
    .bcard-head{padding:14px 20px;border-bottom:1px solid #f1f5f9;background:#fafbfd;display:flex;align-items:center;gap:9px}
    .bcard-title{font-size:13.5px;font-weight:800;color:#0f172a;flex:1}
    .bcard-body{padding:20px}
    .fg{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
    .fgfull{margin-bottom:14px}
    .flbl{display:block;font-size:11.5px;font-weight:700;color:#475569;margin-bottom:5px;letter-spacing:.01em}
    .finp{width:100%;padding:8px 11px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:13px;color:#0f172a;outline:none;transition:border-color .13s}
    .finp:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-ring)}
    .fnote{font-size:11px;color:#94a3b8;margin-top:4px;line-height:1.5}
    textarea.finp{resize:vertical;min-height:72px;line-height:1.6}
    .toggle-row{display:flex;align-items:center;justify-content:space-between;padding:12px 0}
    .toggle-info{flex:1}
    .toggle-title{font-size:13px;font-weight:700;color:#0f172a}
    .toggle-sub{font-size:12px;color:#94a3b8;margin-top:2px}
    .toggle{position:relative;width:42px;height:24px;flex-shrink:0}
    .toggle input{opacity:0;width:0;height:0}
    .toggle-slider{position:absolute;inset:0;background:#e2e8f0;border-radius:99px;cursor:pointer;transition:all .2s}
    .toggle-slider:before{content:'';position:absolute;width:18px;height:18px;left:3px;top:3px;background:white;border-radius:50%;transition:all .2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
    input:checked + .toggle-slider{background:var(--primary)}
    input:checked + .toggle-slider:before{transform:translateX(18px)}
    .btn-save{display:inline-flex;align-items:center;gap:6px;padding:10px 22px;background:var(--primary);color:white;border:none;border-radius:9px;font-size:13.5px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .15s}
    .btn-save:hover{background:var(--primary-hover)}
    .run-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .14s;border:1.5px solid}
    .run-db{background:#eff6ff;color:#2563eb;border-color:#bfdbfe}.run-db:hover{background:#dbeafe}
    .status-ok{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;color:#16a34a}
    .status-off{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;color:#94a3b8}
    .dot{width:6px;height:6px;border-radius:50%;display:inline-block}
    .btbl{width:100%;border-collapse:collapse}
    .btbl th{padding:9px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;border-bottom:1px solid #e2e8f0;text-align:left}
    .btbl td{padding:10px 14px;border-bottom:1px solid #f8fafc;font-size:12.5px;vertical-align:middle}
    .btbl tr:last-child td{border:none}
    .btbl tr:hover td{background:#fafbfd}
    .cron-box{background:#0d1117;color:#3fb950;padding:14px 16px;border-radius:9px;font-family:'JetBrains Mono',monospace;font-size:12.5px;line-height:1.9}
    @media(max-width:900px){.adm-main{margin-left:0}.fg{grid-template-columns:1fr}.page{padding:16px}}
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

  <!-- Main -->
  <div class="adm-main">
    <div class="adm-topbar">
      <span class="adm-topbar-title">🗄️ Database Backup</span>
      <div style="margin-left:auto;font-size:12px;color:#94a3b8"><?= date('d M Y, H:i') ?></div>
    </div>

    <div class="page">

      <?php if ($msg): ?>
      <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:11px 16px;margin-bottom:16px;font-size:13px;font-weight:700;color:#15803d;display:flex;gap:8px;align-items:center">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        <?= htmlspecialchars($msg) ?>
      </div>
      <?php endif; ?>
      <?php if ($err): ?>
      <div style="background:#fef2f2;border:1.5px solid #fca5a5;border-radius:10px;padding:11px 16px;margin-bottom:16px;font-size:13px;font-weight:700;color:#dc2626;display:flex;gap:8px;align-items:center">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($err) ?>
      </div>
      <?php endif; ?>

      <!-- Status overview -->
      <div class="bcard">
        <div class="bcard-head">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          <span class="bcard-title">Backup Status</span>
        </div>
        <div class="bcard-body">
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px">
            <div style="background:#f8fafc;border-radius:10px;padding:14px">
              <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:5px">Database Backup</div>
              <?php $db_on = get_setting('backup_db_enabled','0') === '1'; ?>
              <div class="<?= $db_on ? 'status-ok' : 'status-off' ?>">
                <span class="dot" style="background:<?= $db_on ? '#16a34a' : '#94a3b8' ?>;<?= $db_on ? 'animation:pulse 1.5s infinite' : '' ?>"></span>
                <?= $db_on ? 'Enabled' : 'Disabled' ?>
              </div>
              <?php if ($last_db): ?><div style="font-size:11px;color:#94a3b8;margin-top:4px">Last: <?= date('d M H:i', strtotime($last_db)) ?></div><?php endif; ?>
              <?php if ($next_db): ?><div style="font-size:11px;color:#2563eb;margin-top:2px">Next: <?= $next_db ?></div><?php endif; ?>
            </div>
            <div style="background:#f8fafc;border-radius:10px;padding:14px">
              <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:5px">Interval</div>
              <div style="font-size:22px;font-weight:900;color:#0f172a;line-height:1"><?= bs('backup_interval_hours','3') ?>h</div>
              <div style="font-size:11px;color:#94a3b8;margin-top:4px">Every <?= bs('backup_interval_hours','3') ?> hours</div>
            </div>
            <div style="background:#f8fafc;border-radius:10px;padding:14px">
              <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:5px">Saved Backups</div>
              <div style="font-size:22px;font-weight:900;color:#0f172a;line-height:1"><?= count($backup_files) ?></div>
              <?php $total_size = array_sum(array_column($backup_files, 'size')); if ($total_size > 0): ?>
              <div style="font-size:11px;color:#94a3b8;margin-top:4px"><?= round($total_size/1024/1024, 1) ?> MB total</div>
              <?php endif; ?>
            </div>
          </div>

          <form method="POST" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="run_now">
            <input type="hidden" name="type" value="db">
            <button type="submit" class="run-btn run-db">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg>
              Run DB Backup Now
            </button>
          </form>
        </div>
      </div>

      <!-- Settings form -->
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="save">

        <div class="bcard">
          <div class="bcard-head">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <span class="bcard-title">Enable Backup</span>
          </div>
          <div class="bcard-body">
            <div class="toggle-row">
              <div class="toggle-info">
                <div class="toggle-title">🗄️ Database Backup</div>
                <div class="toggle-sub">Exports full MySQL database as .sql.gz and emails it</div>
              </div>
              <label class="toggle">
                <input type="checkbox" name="backup_db_enabled" value="1" <?= get_setting('backup_db_enabled','0')==='1'?'checked':'' ?>>
                <span class="toggle-slider"></span>
              </label>
            </div>
          </div>
        </div>

        <div class="bcard">
          <div class="bcard-head">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#0369a1" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <span class="bcard-title">Schedule & Email</span>
          </div>
          <div class="bcard-body">
            <div class="fg">
              <div>
                <label class="flbl">Backup Interval (hours)</label>
                <select name="backup_interval_hours" class="finp">
                  <?php foreach ([1,2,3,6,12,24,48] as $h): ?>
                  <option value="<?= $h ?>" <?= bs('backup_interval_hours','3')===(string)$h?'selected':'' ?>><?= $h ?> hour<?= $h>1?'s':'' ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="fnote">Cron runs this often. Default: every 3 hours.</div>
              </div>
              <div>
                <label class="flbl">Retention (days)</label>
                <input type="number" name="backup_retention_days" min="1" max="365" class="finp" value="<?= bs('backup_retention_days','7') ?>">
                <div class="fnote">Old backups older than this are auto-deleted from server.</div>
              </div>
            </div>
            <div class="fgfull">
              <label class="flbl">Send Backup To Emails</label>
              <textarea name="backup_emails" class="finp" rows="3" placeholder="admin@example.com&#10;One email per line"><?= bs('backup_emails') ?></textarea>
              <div class="fnote">One email per line. Leave blank to skip email (saves to server only).</div>
            </div>
          </div>
        </div>

        <div class="bcard">
          <div class="bcard-head">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#065f46" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
            <span class="bcard-title">Storage Path</span>
          </div>
          <div class="bcard-body">
            <div class="fgfull">
              <label class="flbl">Backup Save Directory</label>
              <input type="text" name="backup_db_path" class="finp" style="font-family:'JetBrains Mono',monospace"
                     value="<?= bs('backup_db_path', dirname(__DIR__) . '/backups') ?>"
                     placeholder="/home/cloudgreat/public_html/backups">
              <div class="fnote">Absolute server path where backup files are saved. Must be writable.</div>
            </div>
          </div>
        </div>

        <div class="bcard">
          <div class="bcard-head">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#92400e" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span class="bcard-title">Cron Job Setup</span>
          </div>
          <div class="bcard-body">
            <p style="font-size:13px;color:#64748b;margin-bottom:12px">Add this to cPanel → Cron Jobs.</p>
            <div class="cron-box">
              <span style="color:#6e7681"># Run every hour (interval checked internally)</span><br>
              0 * * * * /usr/local/bin/php <?= htmlspecialchars(dirname(__DIR__)) ?>/cron/db-backup.php >> /tmp/backup.log 2>&amp;1
            </div>
          </div>
        </div>

        <button type="submit" class="btn-save">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          Save Settings
        </button>
      </form>

      <!-- Saved backups list -->
      <?php if (!empty($backup_files)): ?>
      <div class="bcard" style="margin-top:20px">
        <div class="bcard-head">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#374151" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
          <span class="bcard-title">Saved Backups (<?= count($backup_files) ?>)</span>
        </div>
        <div style="overflow-x:auto">
          <table class="btbl">
            <thead><tr><th>File</th><th>Size</th><th>Created</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($backup_files as $bf): ?>
            <tr>
              <td style="font-family:'JetBrains Mono',monospace;font-size:12px"><?= htmlspecialchars($bf['name']) ?></td>
              <td style="font-family:'JetBrains Mono',monospace"><?= round($bf['size']/1024/1024, 2) ?> MB</td>
              <td style="color:#64748b"><?= date('d M Y H:i', $bf['time']) ?></td>
              <td>
                <button onclick="location.href='/backups/<?= htmlspecialchars($bf['name']) ?>'" type="button" style="padding:3px 9px;background:#d7f5d8;border:1px solid #4CAF50;border-radius:6px;font-size:11.5px;font-weight:700;color:#4CAF50;cursor:pointer;font-family:inherit">Download</button>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this backup?')">
                  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="delete_backup">
                  <input type="hidden" name="file" value="<?= htmlspecialchars($bf['name']) ?>">
                  <button type="submit" style="padding:3px 9px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;font-size:11.5px;font-weight:700;color:#dc2626;cursor:pointer;font-family:inherit">Delete</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>
<style>@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}</style>
</body>
</html>