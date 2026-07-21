<?php
/**
 * admin/full-backup.php
 * Full backup admin — uses backup_config, backup_profiles, backup_jobs tables.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin.php';
require_admin();

$user     = current_user();
$app_name = APP_NAME;
$uname    = htmlspecialchars($user['full_name'] ?: $user['username']);
$csrf     = csrf_token();
$msg = ''; $err = '';

// ── Load config row ───────────────────────────────────────────
function fb_cfg(): array {
    $r = db()->query("SELECT * FROM backup_config WHERE id=1 LIMIT 1")->fetch();
    return $r ?: [];
}
function fb_cfg_save(array $data): void {
    $sets = implode(', ', array_map(fn($k) => "`{$k}`=?", array_keys($data)));
    $vals = array_values($data);
    $vals[] = null; // for WHERE id=1 (no placeholder needed, hardcoded)
    db()->prepare("UPDATE backup_config SET {$sets}, updated_at=NOW() WHERE id=1")
       ->execute(array_values($data));
}

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $act = $_POST['action'] ?? '';

    // ── Save global config
    if ($act === 'save_config') {
        fb_cfg_save([
            'enabled'            => isset($_POST['enabled']) ? 1 : 0,
            'backup_db'          => isset($_POST['backup_db']) ? 1 : 0,
            'backup_files'       => isset($_POST['backup_files']) ? 1 : 0,
            'interval_hours'     => (int)($_POST['interval_hours'] ?? 24),
            'retention_days'     => (int)($_POST['retention_days'] ?? 7),
            'project_root'       => trim($_POST['project_root'] ?? ''),
            'local_staging_path' => trim($_POST['local_staging_path'] ?? ''),
            'excludes'           => trim($_POST['excludes'] ?? ''),
            'notify_emails'      => trim($_POST['notify_emails'] ?? ''),
        ]);
        $msg = 'Config saved.';
    }

    // ── Save / add SSH profile
    if ($act === 'save_profile') {
        $pid  = (int)($_POST['profile_id'] ?? 0);
        $data = [
            'name'          => trim($_POST['name'] ?? ''),
            'ssh_host'      => trim($_POST['ssh_host'] ?? ''),
            'ssh_port'      => (int)($_POST['ssh_port'] ?? 22),
            'ssh_user'      => trim($_POST['ssh_user'] ?? ''),
            'ssh_key_path'  => trim($_POST['ssh_key_path'] ?? ''),
            'ssh_password'  => trim($_POST['ssh_password'] ?? ''),
            'remote_path'   => trim($_POST['remote_path'] ?? '/backup/cloudvault'),
            'is_active'     => isset($_POST['is_active']) ? 1 : 0,
        ];
        if (!$data['name'] || !$data['ssh_host'] || !$data['ssh_user']) {
            $err = 'Name, SSH Host, and SSH User are required.';
        } else {
            if ($pid) {
                $sets = implode(', ', array_map(fn($k) => "`{$k}`=?", array_keys($data)));
                $vals = array_merge(array_values($data), [$pid]);
                db()->prepare("UPDATE backup_profiles SET {$sets}, updated_at=NOW() WHERE id=?")->execute($vals);
                $msg = 'Profile updated.';
            } else {
                $cols = implode(', ', array_map(fn($k) => "`{$k}`", array_keys($data)));
                $phs  = implode(', ', array_fill(0, count($data), '?'));
                db()->prepare("INSERT INTO backup_profiles ({$cols}) VALUES ({$phs})")->execute(array_values($data));
                $msg = 'Profile added.';
            }
        }
    }

    // ── Delete profile
    if ($act === 'delete_profile') {
        $pid = (int)($_POST['profile_id'] ?? 0);
        if ($pid) { db()->prepare("DELETE FROM backup_profiles WHERE id=?")->execute([$pid]); $msg = 'Profile deleted.'; }
    }

    // ── Toggle profile active
    if ($act === 'toggle_profile') {
        $pid = (int)($_POST['profile_id'] ?? 0);
        db()->prepare("UPDATE backup_profiles SET is_active = 1 - is_active WHERE id=?")->execute([$pid]);
        $msg = 'Profile toggled.';
    }

    // ── Test SSH
    if ($act === 'test_ssh') {
        $pid = (int)($_POST['profile_id'] ?? 0);
        $p   = db()->prepare("SELECT * FROM backup_profiles WHERE id=? LIMIT 1");
        $p->execute([$pid]); $profile = $p->fetch();
        if (!$profile) { $err = 'Profile not found.'; }
        else {
            $__fullbackup_admin_require = true;
            require_once __DIR__ . '/../cron/full-backup.php';
            $out = fb_ssh_cmd($profile, 'pwd');
            $ok  = !empty(trim($out));
            db()->prepare("UPDATE backup_profiles SET last_tested_at=NOW(), last_test_ok=?, last_test_output=? WHERE id=?")
               ->execute([$ok ? 1 : 0, $out, $pid]);
            if ($ok) $msg = 'SSH OK! Output: <code style="font-size:12px">' . nl2br(htmlspecialchars(str_replace('SSH_OK', '', trim($out)))) . '</code>';
            else     $err = 'SSH failed: ' . htmlspecialchars($out ?: 'no output');
        }
    }

    // ── Run backup now
    if ($act === 'run_now') {
        $btype = $_POST['backup_type'] ?? 'full';
        try {
            $__fullbackup_admin_require = true;
            require_once __DIR__ . '/../cron/full-backup.php';
            $result = run_full_backup($btype);
            if ($result['ok']) $msg = 'Backup done: ' . htmlspecialchars($result['message']);
            else               $err = 'Backup failed: ' . htmlspecialchars($result['message']);
        } catch (Throwable $e) { $err = $e->getMessage(); }
    }

    // ── Delete local backup file
    if ($act === 'delete_local') {
        $file      = basename($_POST['file'] ?? '');
        $cfg       = fb_cfg();
        $local_dir = rtrim($cfg['local_staging_path'] ?: dirname(__DIR__) . '/backups/full', '/');
        $full      = $local_dir . '/' . $file;
        if ($file && file_exists($full) && str_starts_with(realpath($full), realpath($local_dir))) {
            unlink($full); $msg = "Deleted: $file";
        } else { $err = 'File not found.'; }
    }
    // ── Delete ALL local backup files
    if ($act === 'delete_all_local') {
        $cfg       = fb_cfg();
        $local_dir = rtrim($cfg['local_staging_path'] ?: dirname(__DIR__) . '/backups/full', '/');
        $deleted   = 0;
        if (is_dir($local_dir)) {
            $real_dir = realpath($local_dir);
            foreach (glob($local_dir . '/*') ?: [] as $f) {
                if (is_file($f) && str_starts_with(realpath($f), $real_dir)) {
                    unlink($f);
                    $deleted++;
                }
            }
        }
        $msg = "Deleted {$deleted} backup file(s).";
    }
    // ── Clean old job logs (keep latest 2)
    if ($act === 'clean_jobs') {
        $ids = db()->query("SELECT id FROM backup_jobs ORDER BY started_at DESC")->fetchAll(PDO::FETCH_COLUMN);
        $keep = array_slice($ids, 0, 2);
        if (count($ids) > 2) {
            $del_ids = array_slice($ids, 2);
            $ph = implode(',', array_fill(0, count($del_ids), '?'));
            db()->prepare("DELETE FROM backup_jobs WHERE id IN ({$ph})")->execute($del_ids);
            $msg = 'Cleaned job logs. Kept latest 2, deleted ' . count($del_ids) . ' old record(s).';
        } else {
            $msg = 'Nothing to clean. Only ' . count($ids) . ' record(s) exist.';
        }
    }
}

// ── Data ─────────────────────────────────────────────────────
$cfg      = fb_cfg();
$profiles = db()->query("SELECT * FROM backup_profiles ORDER BY is_active DESC, id")->fetchAll();
$jobs     = db()->query("SELECT * FROM backup_jobs ORDER BY started_at DESC LIMIT 20")->fetchAll();

$local_dir    = rtrim($cfg['local_staging_path'] ?? dirname(__DIR__) . '/backups/full', '/');
$local_files  = [];
if (is_dir($local_dir)) {
    $ff = array_filter(glob($local_dir . '/*') ?: [], 'is_file');
    usort($ff, fn($a,$b) => filemtime($b)-filemtime($a));
    foreach (array_slice($ff, 0, 30) as $f) {
        $local_files[] = ['name'=>basename($f),'size'=>filesize($f),'time'=>filemtime($f),
            'type'=>str_starts_with(basename($f),'db_')?'db':(str_starts_with(basename($f),'files_')?'files':'other')];
    }
}

$edit_profile = null;
if (isset($_GET['edit_profile'])) {
    $ep = db()->prepare("SELECT * FROM backup_profiles WHERE id=? LIMIT 1");
    $ep->execute([(int)$_GET['edit_profile']]); $edit_profile = $ep->fetch() ?: null;
}

function fbe(mixed $v): string { return htmlspecialchars((string)($v ?? '')); }
function fb_hs(int $b): string {
    if ($b>=1073741824) return round($b/1073741824,2).' GB';
    if ($b>=1048576)    return round($b/1048576,2).' MB';
    if ($b>=1024)       return round($b/1024,1).' KB';
    return $b.' B';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Full Backup — <?= $app_name ?> Admin</title>
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
    .adm-main{margin-left:232px;flex:1}
    .adm-topbar{background:white;border-bottom:1px solid #e2e8f0;height:56px;display:flex;align-items:center;padding:0 28px;position:sticky;top:0;z-index:30}
    .adm-topbar-title{font-size:15px;font-weight:800;color:#0f172a}
    .page{padding:24px 28px;max-width:1040px}
    .bcard{background:white;border:1px solid #e2e8f0;border-radius:13px;overflow:hidden;margin-bottom:18px}
    .bcard-head{padding:14px 20px;border-bottom:1px solid #f1f5f9;background:#fafbfd;display:flex;align-items:center;gap:9px}
    .bcard-title{font-size:13.5px;font-weight:800;color:#0f172a;flex:1}
    .bcard-body{padding:20px}
    .fg{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
    .fg3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px}
    .fg4{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:14px}
    .fgfull{margin-bottom:14px}
    .flbl{display:block;font-size:11.5px;font-weight:700;color:#475569;margin-bottom:5px}
    .finp{width:100%;padding:8px 11px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:13px;color:#0f172a;outline:none;transition:border-color .13s;box-sizing:border-box}
    .finp:focus{border-color:var(--primary)}
    .finp.mono{font-family:'JetBrains Mono',monospace;font-size:12px}
    .fnote{font-size:11px;color:#94a3b8;margin-top:4px;line-height:1.5}
    textarea.finp{resize:vertical;min-height:80px;line-height:1.6}
    .toggle{position:relative;width:42px;height:24px;flex-shrink:0;display:inline-block}
    .toggle input{opacity:0;width:0;height:0}
    .toggle-slider{position:absolute;inset:0;background:#e2e8f0;border-radius:99px;cursor:pointer;transition:all .2s}
    .toggle-slider:before{content:'';position:absolute;width:18px;height:18px;left:3px;top:3px;background:white;border-radius:50%;transition:all .2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
    input:checked + .toggle-slider{background:var(--primary)}
    input:checked + .toggle-slider:before{transform:translateX(18px)}
    .trow{display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f8fafc}
    .trow:last-child{border:none;padding-bottom:0}
    .trow-info .tlbl{font-size:13px;font-weight:700;color:#0f172a}
    .trow-info .tsub{font-size:12px;color:#94a3b8;margin-top:1px}
    .btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;border:none;transition:all .14s}
    .btn-primary{background:var(--primary);color:white}.btn-primary:hover{opacity:.9}
    .btn-outline{background:white;color:#374151;border:1.5px solid #e2e8f0}.btn-outline:hover{background:#f8fafc}
    .btn-danger{background:#fef2f2;color:#dc2626;border:1.5px solid #fca5a5}.btn-danger:hover{background:#fee2e2}
    .btn-blue{background:#eff6ff;color:#2563eb;border:1.5px solid #bfdbfe}.btn-blue:hover{background:#dbeafe}
    .btn-orange{background:#fff7ed;color:#ea580c;border:1.5px solid #fed7aa}.btn-orange:hover{background:#ffedd5}
    .btn-sm{padding:5px 11px;font-size:11.5px;border-radius:6px}
    .stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
    .stat-box{background:#f8fafc;border-radius:10px;padding:14px}
    .stat-lbl{font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:5px}
    .stat-val{font-size:22px;font-weight:900;color:#0f172a;line-height:1}
    .stat-sub{font-size:11px;color:#94a3b8;margin-top:3px}
    .btbl{width:100%;border-collapse:collapse}
    .btbl th{padding:9px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;border-bottom:1px solid #e2e8f0;text-align:left;white-space:nowrap}
    .btbl td{padding:9px 14px;border-bottom:1px solid #f8fafc;font-size:12.5px;vertical-align:middle}
    .btbl tr:last-child td{border:none}
    .btbl tr:hover td{background:#fafbfd}
    .badge{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:99px;font-size:10.5px;font-weight:700;white-space:nowrap}
    .badge-green{background:#f0fdf4;color:#16a34a}
    .badge-blue{background:#eff6ff;color:#2563eb}
    .badge-purple{background:#fdf4ff;color:#9333ea}
    .badge-red{background:#fef2f2;color:#dc2626}
    .badge-gray{background:#f1f5f9;color:#64748b}
    .badge-orange{background:#fff7ed;color:#ea580c}
    .cron-box{background:#0d1117;color:#3fb950;padding:14px 16px;border-radius:9px;font-family:'JetBrains Mono',monospace;font-size:12.5px;line-height:1.9}
    .ssh-note{background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:10px;padding:14px 16px;font-size:12.5px;color:#0369a1;line-height:1.8;margin-top:14px}
    .profile-card{border:1.5px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:12px;background:#fafbfd}
    .profile-card.active-profile{border-color:#86efac;background:#f0fdf4}
    .alert-ok{background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:11px 16px;margin-bottom:16px;font-size:13px;font-weight:600;color:#15803d;display:flex;gap:8px;align-items:flex-start}
    .alert-err{background:#fef2f2;border:1.5px solid #fca5a5;border-radius:10px;padding:11px 16px;margin-bottom:16px;font-size:13px;font-weight:600;color:#dc2626;display:flex;gap:8px;align-items:flex-start}
    .run-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
    @media(max-width:900px){.adm-main{margin-left:0}.fg,.fg3,.fg4,.stat-grid{grid-template-columns:1fr}.page{padding:16px}}
    @keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
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
      <span class="adm-topbar-title">🗂️ Full Backup (SSH)</span>
      <div style="margin-left:auto;font-size:12px;color:#94a3b8"><?= date('d M Y, H:i') ?></div>
    </div>

    <div class="page">

      <?php if ($msg && !$err): ?>
      <div class="alert-ok"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0;margin-top:1px"><polyline points="20 6 9 17 4 12"/></svg><span><?= $msg ?></span></div>
      <?php endif; ?>
      <?php if ($err): ?>
      <div class="alert-err"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><span><?= $err ?></span></div>
      <?php endif; ?>

      <!-- ── Status + Quick Run ── -->
      <div class="bcard">
        <div class="bcard-head">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          <span class="bcard-title">Status</span>
        </div>
        <div class="bcard-body">
          <div class="stat-grid">
            <div class="stat-box">
              <div class="stat-lbl">Backup</div>
              <?php $on = (bool)($cfg['enabled'] ?? 0); ?>
              <div style="font-size:14px;font-weight:800;color:<?= $on?'#16a34a':'#94a3b8' ?>;display:flex;align-items:center;gap:6px">
                <span style="width:7px;height:7px;border-radius:50%;background:<?= $on?'#16a34a':'#cbd5e1' ?>;display:inline-block;<?= $on?'animation:pulse 1.5s infinite':'' ?>"></span>
                <?= $on ? 'Enabled' : 'Disabled' ?>
              </div>
            </div>
            <div class="stat-box">
              <div class="stat-lbl">Last Run</div>
              <div style="font-size:13px;font-weight:700;color:#0f172a"><?= $cfg['last_run_at'] ? date('d M H:i', strtotime($cfg['last_run_at'])) : '—' ?></div>
              <?php if ($cfg['next_run_at']): ?><div class="stat-sub">Next: <?= date('d M H:i', strtotime($cfg['next_run_at'])) ?></div><?php endif; ?>
            </div>
            <div class="stat-box">
              <div class="stat-lbl">SSH Profiles</div>
              <div class="stat-val"><?= count($profiles) ?></div>
              <div class="stat-sub"><?= count(array_filter($profiles, fn($p)=>$p['is_active'])) ?> active</div>
            </div>
            <div class="stat-box">
              <div class="stat-lbl">Local Files</div>
              <div class="stat-val"><?= count($local_files) ?></div>
              <?php if ($local_files): ?><div class="stat-sub"><?= fb_hs(array_sum(array_column($local_files,'size'))) ?> total</div><?php endif; ?>
            </div>
          </div>
          <div class="run-bar">
            <form method="POST" style="display:contents">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="run_now">
              <button data-loading="Running..." name="backup_type" value="full"  class="btn btn-primary"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Run Full Backup</button>
              <button data-loading="Running..." name="backup_type" value="db"    class="btn btn-blue"   ><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/></svg> DB Only</button>
              <button data-loading="Running..." name="backup_type" value="files" class="btn btn-outline" ><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/></svg> Files Only</button>
            </form>
          </div>
        </div>
      </div>

      <!-- ── Global Config ── -->
      <div class="bcard">
        <div class="bcard-head">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          <span class="bcard-title">Backup Configuration</span>
        </div>
        <div class="bcard-body">
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="save_config">

            <div class="trow">
              <div class="trow-info"><div class="tlbl">Enable Automated Backup</div><div class="tsub">Cron will trigger based on interval below</div></div>
              <label class="toggle"><input type="checkbox" name="enabled" value="1" <?= ($cfg['enabled']??0)?'checked':'' ?>><span class="toggle-slider"></span></label>
            </div>
            <div class="trow">
              <div class="trow-info"><div class="tlbl">Include Database</div><div class="tsub">mysqldump → .sql.gz</div></div>
              <label class="toggle"><input type="checkbox" name="backup_db" value="1" <?= ($cfg['backup_db']??1)?'checked':'' ?>><span class="toggle-slider"></span></label>
            </div>
            <div class="trow" style="margin-bottom:16px">
              <div class="trow-info"><div class="tlbl">Include Files</div><div class="tsub">Project files → .tar.gz</div></div>
              <label class="toggle"><input type="checkbox" name="backup_files" value="1" <?= ($cfg['backup_files']??1)?'checked':'' ?>><span class="toggle-slider"></span></label>
            </div>

            <div class="fg">
              <div>
                <label class="flbl">Interval (hours)</label>
                <select name="interval_hours" class="finp">
                  <?php foreach ([3,6,12,24,48,72,168] as $h): ?>
                  <option value="<?= $h ?>" <?= ($cfg['interval_hours']??24)==$h?'selected':'' ?>>
                    <?= $h>=168?'7 days':($h>=24?($h/24).' day'.($h>24?'s':''):$h.' hours') ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="flbl">Retention (days)</label>
                <input type="number" name="retention_days" class="finp" min="1" max="365" value="<?= fbe($cfg['retention_days']??7) ?>">
                <div class="fnote">Old backups on both local & remote auto-deleted after this many days.</div>
              </div>
            </div>
            <div class="fg">
              <div>
                <label class="flbl">Project Root</label>
                <input type="text" name="project_root" class="finp mono" value="<?= fbe($cfg['project_root']) ?>" placeholder="/home/cloudgreat/public_html">
                <div class="fnote">Root dir to archive for files backup.</div>
              </div>
              <div>
                <label class="flbl">Local Staging Dir</label>
                <input type="text" name="local_staging_path" class="finp mono" value="<?= fbe($cfg['local_staging_path']) ?>" placeholder="/home/cloudgreat/public_html/backups/full">
                <div class="fnote">Temp dir on this VPS. Must be writable.</div>
              </div>
            </div>
            <div class="fg">
              <div>
                <label class="flbl">Exclude Paths (one per line)</label>
                <textarea name="excludes" class="finp mono" rows="4" placeholder="backups&#10;uploads/tickets&#10;node_modules&#10;.git"><?= fbe($cfg['excludes'] ?? "backups\nuploads/tickets\nnode_modules\n.git\nvendor") ?></textarea>
                <div class="fnote">Relative to project root. These dirs won't be included in tar.</div>
              </div>
              <div>
                <label class="flbl">Notify Emails (optional)</label>
                <textarea name="notify_emails" class="finp" rows="4" placeholder="admin@example.com&#10;One per line"><?= fbe($cfg['notify_emails'] ?? '') ?></textarea>
              </div>
            </div>
            <button data-loading="Saving..." type="submit" class="btn btn-primary">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              Save Config
            </button>
          </form>
        </div>
      </div>

      <!-- ── SSH Profiles ── -->
      <div class="bcard">
        <div class="bcard-head">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#c2410c" stroke-width="2"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
          <span class="bcard-title">SSH Remote Profiles</span>
          <a href="?add_profile=1" class="btn btn-outline btn-sm">+ Add Profile</a>
        </div>
        <div class="bcard-body">
          <?php if (empty($profiles) && !isset($_GET['add_profile']) && !$edit_profile): ?>
            <p style="font-size:13px;color:#94a3b8;text-align:center;padding:20px 0">No SSH profiles yet. <a href="?add_profile=1" style="color:var(--primary)">Add one →</a></p>
          <?php endif; ?>

          <?php foreach ($profiles as $p): ?>
          <div class="profile-card <?= $p['is_active'] ? 'active-profile' : '' ?>">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
              <div style="font-size:14px;font-weight:800;color:#0f172a"><?= fbe($p['name']) ?></div>
              <?php if ($p['is_active']): ?><span class="badge badge-green">● Active</span><?php else: ?><span class="badge badge-gray">Inactive</span><?php endif; ?>
              <?php if ($p['last_test_ok'] === '1'): ?><span class="badge badge-green">SSH ✓</span>
              <?php elseif ($p['last_test_ok'] === '0'): ?><span class="badge badge-red">SSH ✗</span>
              <?php else: ?><span class="badge badge-gray">Not tested</span><?php endif; ?>
              <?php if ($p['last_tested_at']): ?><span style="font-size:11px;color:#94a3b8">tested <?= date('d M H:i', strtotime($p['last_tested_at'])) ?></span><?php endif; ?>
            </div>
            <div style="font-size:12px;font-family:'JetBrains Mono',monospace;color:#475569;margin-bottom:10px">
              <?= fbe($p['ssh_user']) ?>@<?= fbe($p['ssh_host']) ?>:<?= fbe($p['ssh_port']) ?> → <?= fbe($p['remote_path']) ?>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <a href="?edit_profile=<?= $p['id'] ?>" class="btn btn-outline btn-sm">✏️ Edit</a>
              <form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="test_ssh"><input type="hidden" name="profile_id" value="<?= $p['id'] ?>"><button data-loading="Testing..." type="submit" class="btn btn-orange btn-sm">⚡ Test SSH</button></form>
              <form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="toggle_profile"><input type="hidden" name="profile_id" value="<?= $p['id'] ?>"><button type="submit" class="btn btn-outline btn-sm"><?= $p['is_active'] ? '⏸ Disable' : '▶ Enable' ?></button></form>
              <form method="POST" style="display:inline" onsubmit="return confirm('Delete profile?')"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="delete_profile"><input type="hidden" name="profile_id" value="<?= $p['id'] ?>"><button type="submit" class="btn btn-danger btn-sm">🗑 Delete</button></form>
            </div>
          </div>
          <?php endforeach; ?>

          <!-- Add / Edit form -->
          <?php if (isset($_GET['add_profile']) || $edit_profile): ?>
          <?php $ep = $edit_profile ?: []; ?>
          <div style="border-top:1.5px solid #e2e8f0;margin-top:16px;padding-top:20px">
            <div style="font-size:13.5px;font-weight:800;color:#0f172a;margin-bottom:16px"><?= $edit_profile ? '✏️ Edit Profile' : '➕ Add SSH Profile' ?></div>
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="save_profile">
              <?php if ($edit_profile): ?><input type="hidden" name="profile_id" value="<?= $ep['id'] ?>"><?php endif; ?>
              <div class="fg">
                <div>
                  <label class="flbl">Profile Name</label>
                  <input type="text" name="name" class="finp" value="<?= fbe($ep['name']??'') ?>" placeholder="Hetzner Storage Box" required>
                </div>
                <div>
                  <label class="flbl">SSH Host / IP</label>
                  <input type="text" name="ssh_host" class="finp mono" value="<?= fbe($ep['ssh_host']??'') ?>" placeholder="u123456.your-storagebox.de" required>
                </div>
              </div>
              <div class="fg3">
                <div>
                  <label class="flbl">SSH Port</label>
                  <input type="number" name="ssh_port" class="finp" value="<?= fbe($ep['ssh_port']??22) ?>" min="1" max="65535">
                  <div class="fnote">Hetzner Storage Box = 23</div>
                </div>
                <div>
                  <label class="flbl">SSH Username</label>
                  <input type="text" name="ssh_user" class="finp mono" value="<?= fbe($ep['ssh_user']??'') ?>" placeholder="u123456" required>
                </div>
                <div style="display:flex;align-items:flex-start;gap:10px;padding-top:22px">
                  <label class="toggle" style="margin-top:2px"><input type="checkbox" name="is_active" value="1" <?= ($ep['is_active']??1)?'checked':'' ?>><span class="toggle-slider"></span></label>
                  <div class="trow-info"><div class="tlbl">Active</div><div class="tsub">Use this profile for backups</div></div>
                </div>
              </div>
              <div class="fg">
                <div>
                  <label class="flbl">Private Key Path (on this VPS) — Recommended</label>
                  <input type="text" name="ssh_key_path" class="finp mono" value="<?= fbe($ep['ssh_key_path']??'') ?>" placeholder="/root/.ssh/backup_key">
                  <div class="fnote">Abs path to SSH private key. Leave blank to use password.</div>
                </div>
                <div>
                  <label class="flbl">SSH Password (fallback)</label>
                  <input type="password" name="ssh_password" class="finp" value="<?= fbe($ep['ssh_password']??'') ?>" placeholder="Only if not using key" autocomplete="new-password">
                  <div class="fnote">Needs <code>sshpass</code> installed: <code>apt install sshpass</code></div>
                </div>
              </div>
              <div class="fgfull">
                <label class="flbl">Remote Backup Path</label>
                <input type="text" name="remote_path" class="finp mono" value="<?= fbe($ep['remote_path']??'/backup/cloudvault') ?>" placeholder="/backup/cloudvault">
                <div class="fnote">Auto-created if not exists. DB goes to <code>remote_path/db/</code> and Files to <code>remote_path/files/</code></div>
              </div>
              <div style="display:flex;gap:10px">
                <button type="submit" class="btn btn-primary"><?= $edit_profile ? '💾 Update Profile' : '➕ Add Profile' ?></button>
                <a href="<?= BASE_URL ?>/admin/full-backup.php" class="btn btn-outline">Cancel</a>
              </div>
            </form>

            <div class="ssh-note">
              <b>🔑 Key-based auth setup (recommended):</b><br>
              <code>ssh-keygen -t ed25519 -f ~/.ssh/backup_key -N ""</code><br>
              <code>ssh-copy-id -i ~/.ssh/backup_key.pub -p PORT user@remote-host</code><br>
              Then set <b>Private Key Path</b> = <code>/root/.ssh/backup_key</code><br><br>
              <b>📦 Hetzner Storage Box:</b> Port <code>23</code>, user like <code>u123456</code>, host like <code>u123456.your-storagebox.de</code>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ── Job History ── -->
      <?php if (!empty($jobs)): ?>
      <div class="bcard">
        <div class="bcard-head">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#374151" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          <span class="bcard-title">Recent Jobs (last 20)</span>
          <form method="POST" style="margin-left:auto" onsubmit="return confirm('Latest 2 records chhodke sab purane job logs delete kar dein?')">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="clean_jobs">
            <button data-loading="Cleaning..." type="submit" class="btn btn-danger btn-sm">🧹 Clean Old Logs</button>
          </form>
        </div>
        <div style="overflow-x:auto">
          <table class="btbl">
            <thead><tr><th>#</th><th>Type</th><th>Triggered</th><th>Status</th><th>DB</th><th>Files</th><th>SSH</th><th>Duration</th><th>Started</th></tr></thead>
            <tbody>
            <?php foreach ($jobs as $j): ?>
            <tr>
              <td style="font-family:'JetBrains Mono',monospace;font-size:12px;color:#94a3b8">#<?= $j['id'] ?></td>
              <td>
                <?php if ($j['type']==='full'): ?><span class="badge badge-green">full</span>
                <?php elseif ($j['type']==='db'): ?><span class="badge badge-blue">db</span>
                <?php else: ?><span class="badge badge-purple">files</span><?php endif; ?>
              </td>
              <td>
                <?php if ($j['triggered_by']==='manual'): ?><span class="badge badge-orange">manual</span>
                <?php else: ?><span class="badge badge-gray">cron</span><?php endif; ?>
              </td>
              <td>
                <?php
                $sc = ['success'=>'badge-green','failed'=>'badge-red','partial'=>'badge-orange','running'=>'badge-blue'];
                echo '<span class="badge ' . ($sc[$j['status']] ?? 'badge-gray') . '">' . $j['status'] . '</span>';
                ?>
              </td>
              <td style="font-size:12px;font-family:'JetBrains Mono',monospace;max-width:180px;overflow:hidden;text-overflow:ellipsis"><?= $j['db_file'] ? htmlspecialchars(basename($j['db_file'])) . '<br><span style="color:#94a3b8">' . fb_hs((int)$j['db_size']) . '</span>' : '—' ?></td>
              <td style="font-size:12px;font-family:'JetBrains Mono',monospace;max-width:180px;overflow:hidden;text-overflow:ellipsis"><?= $j['files_file'] ? htmlspecialchars(basename($j['files_file'])) . '<br><span style="color:#94a3b8">' . fb_hs((int)$j['files_size']) . '</span>' : '—' ?></td>
              <td><?= $j['ssh_uploaded'] ? '<span class="badge badge-green">✓ uploaded</span>' : '<span class="badge badge-gray">local</span>' ?></td>
              <td style="color:#64748b"><?= $j['duration_sec'] ? $j['duration_sec'].'s' : '—' ?></td>
              <td style="color:#64748b;white-space:nowrap"><?= date('d M H:i', strtotime($j['started_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <!-- ── Local Files ── -->
      <?php if (!empty($local_files)): ?>
      <div class="bcard">
        <div class="bcard-head">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#374151" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
          <span class="bcard-title">Local Staging Files (<?= count($local_files) ?>)</span>
          <span style="font-size:11.5px;color:#94a3b8"><?= fb_hs(array_sum(array_column($local_files,'size'))) ?></span>
          <form method="POST" style="margin-left:auto" onsubmit="return confirm('Saare local backup files delete kar dein? Yeh action undo nahi ho sakta.')">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="delete_all_local">
            <button data-loading="Deleting..." type="submit" class="btn btn-danger btn-sm">🗑 Delete All</button>
          </form>
        </div>
        <div style="overflow-x:auto">
          <table class="btbl">
            <thead><tr><th>File</th><th>Type</th><th>Size</th><th>Created</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($local_files as $lf): ?>
            <tr>
              <td style="font-family:'JetBrains Mono',monospace;font-size:11.5px"><?= fbe($lf['name']) ?></td>
              <td><?php
                if ($lf['type']==='db') echo '<span class="badge badge-blue">🗄️ DB</span>';
                elseif ($lf['type']==='files') echo '<span class="badge badge-purple">📁 Files</span>';
                else echo '<span class="badge badge-gray">other</span>';
              ?></td>
              <td style="font-family:'JetBrains Mono',monospace;font-size:12px"><?= fb_hs($lf['size']) ?></td>
              <td style="color:#64748b"><?= date('d M Y H:i', $lf['time']) ?></td>
              <td>
                  <button data-loading="Downloading..." onclick="location.href='/backups/<?= fbe($lf['name']) ?>'" type="button" class="btn btn-success btn-sm">Download</button>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete?')">
                  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="delete_local">
                  <input type="hidden" name="file" value="<?= fbe($lf['name']) ?>">
                  <button data-loading="Deleting..." type="submit" class="btn btn-danger btn-sm">Delete</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <!-- ── Cron ── -->
      <div class="bcard">
        <div class="bcard-head">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#92400e" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          <span class="bcard-title">Cron Setup — aaPanel</span>
        </div>
        <div class="bcard-body">
          <p style="font-size:13px;color:#64748b;margin-bottom:12px">aaPanel → Cron → Add Task → Shell Script → Every <b>1 hour</b></p>
          <div class="cron-box">
            /usr/local/bin/php <?= htmlspecialchars(dirname(__DIR__)) ?>/cron/full-backup.php >> /tmp/full-backup.log 2>&amp;1
          </div>
        </div>
      </div>

    </div>
  </div>
</div>
<style>@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}</style>
<script>
document.querySelectorAll('button[data-loading]').forEach(btn => {

    btn.addEventListener('click', function () {

        const text = this.dataset.loading || 'Loading...';

        this.disabled = true;

        this.innerHTML = `
            <span class="spinner"></span>
            ${text}
        `;

        // agar form button hai
        if (this.type === 'submit') {
            this.closest('form')?.submit();
        }
    });

});

document.querySelectorAll('form').forEach(form => {

    form.addEventListener('submit', function () {

        const btn = this.querySelector('button[type="submit"][data-loading]');

        if (!btn || btn.disabled) return;

        const text = btn.dataset.loading || 'Loading...';

        btn.disabled = true;

        btn.innerHTML = `
            <span class="spinner"></span>
            ${text}
        `;
    });

});
</script>
</body>
</html>