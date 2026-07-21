<?php
/**
 * admin/storage.php — Admin: manage plans, view all buckets, stats
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/admin.php';
require_admin();

$user     = current_user();
$app_name = APP_NAME;
$fname    = htmlspecialchars($user['full_name'] ?: $user['username']);
$csrf     = csrf_token();
$msg = ''; $err = '';
$tab = $_GET['tab'] ?? 'plans';

// ── Region + Plan CRUD ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $act = $_POST['action'] ?? '';

    // ── Region: save ────────────────────────────────────────
    if ($act === 'save_region') {
        $rid          = (int)($_POST['rid'] ?? 0);
        $slug         = trim($_POST['slug']         ?? '');
        $label        = trim($_POST['label']        ?? '');
        $city         = trim($_POST['city']         ?? '');
        $country      = trim($_POST['country']      ?? '');
        $flag_code    = strtolower(trim($_POST['flag_code'] ?? 'in'));
        $minio_ep     = rtrim(trim($_POST['minio_endpoint'] ?? ''), '/');
        $minio_key    = trim($_POST['minio_admin_key']    ?? '');
        $minio_secret = trim($_POST['minio_admin_secret'] ?? '');
        $s3_public    = rtrim(trim($_POST['s3_public_endpoint'] ?? ''), '/');
        $is_active    = isset($_POST['is_active']) ? 1 : 0;
        $sort         = (int)($_POST['sort_order'] ?? 0);

        if (!$slug || !$label || !$city || !$country || !$minio_ep || !$minio_key || !$minio_secret || !$s3_public) {
            $err = 'All fields except Sort Order are required.';
        } else {
            if ($rid) {
                db()->prepare(
                    'UPDATE storage_regions SET slug=?,label=?,city=?,country=?,flag_code=?,
                     minio_endpoint=?,minio_admin_key=?,minio_admin_secret=?,
                     s3_public_endpoint=?,is_active=?,sort_order=? WHERE id=?'
                )->execute([$slug,$label,$city,$country,$flag_code,$minio_ep,$minio_key,$minio_secret,$s3_public,$is_active,$sort,$rid]);
                $msg = "Region '{$label}' updated.";
            } else {
                db()->prepare(
                    'INSERT INTO storage_regions
                     (slug,label,city,country,flag_code,minio_endpoint,minio_admin_key,minio_admin_secret,s3_public_endpoint,is_active,sort_order)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([$slug,$label,$city,$country,$flag_code,$minio_ep,$minio_key,$minio_secret,$s3_public,$is_active,$sort]);
                $msg = "Region '{$label}' created.";
            }
        }
        if (!$err) $tab = 'regions';
    }

    // ── Region: delete ──────────────────────────────────────
    if ($act === 'delete_region') {
        $rid = (int)($_POST['rid'] ?? 0);
        // Check if any active bucket uses this region
        $rslug = db()->prepare('SELECT slug FROM storage_regions WHERE id=? LIMIT 1');
        $rslug->execute([$rid]); $rslug = $rslug->fetchColumn();
        $in_use = $rslug ? (int)db()->prepare(
            "SELECT COUNT(*) FROM storage_buckets WHERE region=? AND deleted_at IS NULL"
        )->execute([$rslug]) : 0;
        if ($in_use) {
            $err = 'Cannot delete — active buckets exist in this region.';
        } else {
            db()->prepare('DELETE FROM storage_regions WHERE id=?')->execute([$rid]);
            $msg = 'Region deleted.';
        }
        $tab = 'regions';
    }

    if ($act === 'save_plan') {
        $pid         = (int)($_POST['pid'] ?? 0);
        $slug        = trim($_POST['slug'] ?? '');
        $name        = trim($_POST['name'] ?? '');
        $storage_gb  = (int)($_POST['storage_gb'] ?? 0);
        $bw_gb       = (int)($_POST['bandwidth_gb'] ?? 0);
        $price_inr   = (float)($_POST['price_inr'] ?? 0);
        $price_usd   = (float)($_POST['price_usd'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $sort        = (int)($_POST['sort_order'] ?? 0);
        $is_active   = isset($_POST['is_active']) ? 1 : 0;

        if (!$slug || !$name || $storage_gb <= 0) { $err = 'Slug, name and storage GB are required.'; }
        else {
            if ($pid) {
                db()->prepare('UPDATE storage_plans SET slug=?,name=?,storage_gb=?,bandwidth_gb=?,price_inr=?,price_usd=?,description=?,sort_order=?,is_active=? WHERE id=?')
                   ->execute([$slug,$name,$storage_gb,$bw_gb,$price_inr,$price_usd,$description,$sort,$is_active,$pid]);
                $msg = 'Plan updated.';
            } else {
                db()->prepare('INSERT INTO storage_plans (slug,name,storage_gb,bandwidth_gb,price_inr,price_usd,description,sort_order,is_active) VALUES (?,?,?,?,?,?,?,?,?)')
                   ->execute([$slug,$name,$storage_gb,$bw_gb,$price_inr,$price_usd,$description,$sort,$is_active]);
                $msg = 'Plan created.';
            }
        }
    }

    if ($act === 'delete_plan') {
        $pid = (int)($_POST['pid'] ?? 0);
        db()->prepare('UPDATE storage_plans SET is_active=0 WHERE id=?')->execute([$pid]);
        $msg = 'Plan disabled.';
    }

    if ($act === 'force_delete_bucket') {
        $bid = (int)($_POST['bid'] ?? 0);
        db()->prepare("UPDATE storage_buckets SET status='deleting', deleted_at=NOW() WHERE id=?")->execute([$bid]);
        $msg = 'Bucket marked for deletion.';
    }

    if ($act === 'suspend_bucket') {
        $bid = (int)($_POST['bid'] ?? 0);
        $bkt = db()->prepare("SELECT name,region FROM storage_buckets WHERE id=?")->execute([$bid]) && ($bkt = db()->prepare("SELECT name,region FROM storage_buckets WHERE id=?"));
        $bkt->execute([$bid]);
        $bkt = $bkt->fetch();
        if ($bkt) {
            db()->prepare("UPDATE storage_buckets SET status='suspended', suspended_at=NOW() WHERE id=?")->execute([$bid]);
            try {
                require_once __DIR__.'/../includes/storage.php';
                storage_minio_for($bkt['region'])->setBucketPublic($bkt['name'], false);
            } catch(Throwable $e) { error_log('[admin suspend] '.$e->getMessage()); }
            $msg = 'Bucket suspended and set to private.';
        }
    }

    if ($act === 'unsuspend_bucket') {
        $bid = (int)($_POST['bid'] ?? 0);
        $bkt = db()->prepare("SELECT name,region FROM storage_buckets WHERE id=?");
        $bkt->execute([$bid]);
        $bkt = $bkt->fetch();
        if ($bkt) {
            db()->prepare("UPDATE storage_buckets SET status='active', suspended_at=NULL WHERE id=?")->execute([$bid]);
            try {
                require_once __DIR__.'/../includes/storage.php';
                storage_minio_for($bkt['region'])->setBucketPublic($bkt['name'], true);
            } catch(Throwable $e) { error_log('[admin unsuspend] '.$e->getMessage()); }
            $msg = 'Bucket unsuspended and restored to public.';
        }
    }

    if ($act === 'save_settings') {
        set_setting('storage_endpoint_base',  trim($_POST['storage_endpoint_base']  ?? ''));
        set_setting('storage_max_buckets_free',(int)($_POST['storage_max_buckets_free'] ?? 0));
        $msg = 'Settings saved.';
    }
}

// Load data
$plans = db()->query("SELECT * FROM storage_plans ORDER BY sort_order, id")->fetchAll() ?: [];

$edit_plan = null;
if (isset($_GET['edit_plan'])) {
    $st = db()->prepare('SELECT * FROM storage_plans WHERE id=? LIMIT 1');
    $st->execute([(int)$_GET['edit_plan']]);
    $edit_plan = $st->fetch() ?: null;
}

// Regions
try {
    $all_regions = db()->query("SELECT * FROM storage_regions ORDER BY sort_order, id")->fetchAll() ?: [];
} catch (Throwable $e) { $all_regions = []; }

$edit_region = null;
if (isset($_GET['edit_region'])) {
    $st = db()->prepare('SELECT * FROM storage_regions WHERE id=? LIMIT 1');
    $st->execute([(int)$_GET['edit_region']]);
    $edit_region = $st->fetch() ?: null;
    $tab = 'regions';
}

// Stats
try {
    $stats_row = db()->query(
        "SELECT COUNT(*) as total, SUM(used_gb) as total_gb,
                SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status='suspended' THEN 1 ELSE 0 END) as suspended
         FROM storage_buckets WHERE deleted_at IS NULL"
    )->fetch();

    $rev_inr = db()->query("SELECT COALESCE(SUM(amount),0) FROM storage_billing WHERE status='success' AND currency='INR'")->fetchColumn();
    $rev_usd = db()->query("SELECT COALESCE(SUM(amount),0) FROM storage_billing WHERE status='success' AND currency='USD'")->fetchColumn();
} catch (Throwable $e) {
    $stats_row = ['total'=>0,'total_gb'=>0,'active'=>0,'suspended'=>0];
    $rev_inr = $rev_usd = 0;
}

// All buckets
try {
    $all_buckets = db()->query(
        "SELECT b.*, p.name as plan_name, u.username, u.email
         FROM storage_buckets b
         JOIN storage_plans p ON p.id = b.plan_id
         JOIN users u ON u.id = b.user_id
         WHERE b.deleted_at IS NULL
         ORDER BY b.created_at DESC LIMIT 100"
    )->fetchAll() ?: [];
} catch (Throwable $e) { $all_buckets = []; }

function sp(string $k, ?array $edit = null, string $def = ''): string {
    if ($edit && array_key_exists($k, $edit)) return htmlspecialchars((string)$edit[$k]);
    return htmlspecialchars($def);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Storage Admin — <?= $app_name ?></title>
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
    .adm-av{width:30px;height:30px;border-radius:7px;background:var(--primary);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:white;flex-shrink:0;overflow:hidden}
    .adm-main{margin-left:232px;flex:1}
    .adm-topbar{background:white;border-bottom:1px solid #e2e8f0;height:56px;display:flex;align-items:center;padding:0 28px;position:sticky;top:0;z-index:30;gap:12px}
    .adm-topbar-title{font-size:15px;font-weight:800;color:#0f172a}
    .page{padding:24px 28px;max-width:1100px}
    .kpi-row{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:22px}
    .kcard{background:white;border:1px solid #e2e8f0;border-radius:12px;padding:16px 18px}
    .kn{font-size:24px;font-weight:900;color:#0f172a;letter-spacing:-1px;line-height:1}
    .kl{font-size:11.5px;color:#94a3b8;margin-top:4px;font-weight:500}
    .tabs{display:flex;gap:4px;margin-bottom:20px;border-bottom:1px solid #e2e8f0;padding-bottom:0}
    .tb{padding:8px 16px;border-radius:8px 8px 0 0;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;color:#64748b;border:1px solid transparent;border-bottom:none;margin-bottom:-1px;background:white;transition:all .13s}
    .tb.on{color:#0f172a;border-color:#e2e8f0;background:white;border-bottom-color:white}
    .tb:not(.on):hover{background:#f8fafc}
    .scard{background:white;border:1px solid #e2e8f0;border-radius:13px;overflow:hidden;margin-bottom:18px}
    .scard-head{padding:13px 18px;border-bottom:1px solid #f1f5f9;background:#fafbfd;display:flex;align-items:center;gap:9px}
    .scard-title{font-size:13.5px;font-weight:800;color:#0f172a}
    .scard-body{padding:18px}
    .fg{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
    .fg3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:14px}
    .flbl{display:block;font-size:11.5px;font-weight:700;color:#475569;margin-bottom:5px}
    .finp{width:100%;padding:8px 11px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:13px;color:#0f172a;outline:none;transition:border-color .13s}
    .finp:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-ring)}
    .tbl{width:100%;border-collapse:collapse}
    .tbl th{padding:9px 14px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:#94a3b8;border-bottom:1px solid #e2e8f0;background:#fafbfd}
    .tbl td{padding:10px 14px;border-bottom:1px solid #f8fafc;font-size:13px;vertical-align:middle}
    .tbl tr:last-child td{border:none}
    .tbl tr:hover td{background:#fafbfd}
    .btn-save{display:inline-flex;align-items:center;gap:6px;padding:9px 22px;background:var(--primary);color:white;border:none;border-radius:9px;font-size:13.5px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .15s}
    .btn-save:hover{background:var(--primary-hover)}
    .tag{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700}
    .tag-active{background:#f0fdf4;color:#16a34a}
    .tag-suspended{background:#fef2f2;color:#dc2626}
    .tag-inactive{background:#f8fafc;color:#94a3b8}
    @media(max-width:900px){
      .adm-main{margin-left:0 !important}
      .adm-topbar{display:none !important}
      .adm-mobile-bar{display:flex !important}
.kpi-row{grid-template-columns:1fr 1fr}}
    @media(max-width:1100px){.adm-main{margin-left:232px}}
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
<div class="adm-shell">
  <?php include 'sidebar.php'; ?>

  <div class="adm-main">
    <div class="adm-topbar">
      <span class="adm-topbar-title">🗄️ Object Storage</span>
      <div style="margin-left:auto;font-size:12px;color:#94a3b8"><?= date('d M Y, H:i') ?></div>
    </div>
    <div class="page">

      <?php if ($msg): ?>
      <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:11px 16px;margin-bottom:16px;font-size:13px;font-weight:700;color:#15803d">✓ <?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>
      <?php if ($err): ?>
      <div style="background:#fef2f2;border:1.5px solid #fca5a5;border-radius:10px;padding:11px 16px;margin-bottom:16px;font-size:13px;font-weight:700;color:#dc2626">✗ <?= htmlspecialchars($err) ?></div>
      <?php endif; ?>

      <!-- KPI Row -->
      <div class="kpi-row">
        <div class="kcard"><div class="kn"><?= (int)$stats_row['total'] ?></div><div class="kl">Total Buckets</div></div>
        <div class="kcard"><div class="kn"><?= (int)$stats_row['active'] ?></div><div class="kl">Active</div></div>
        <div class="kcard"><div class="kn"><?= (int)$stats_row['suspended'] ?></div><div class="kl">Suspended</div></div>
        <div class="kcard"><div class="kn"><?= number_format((float)$stats_row['total_gb'], 1) ?></div><div class="kl">GB Used</div></div>
        <div class="kcard"><div class="kn" style="font-size:16px;letter-spacing:0">₹<?= number_format((float)$rev_inr, 0) ?> / $<?= number_format((float)$rev_usd, 2) ?></div><div class="kl">Total Revenue</div></div>
      </div>

      <!-- Tabs -->
      <div class="tabs">
        <a href="?tab=regions"  class="tb <?= $tab==='regions'?'on':'' ?>">🌍 Regions</a>
        <a href="?tab=plans"    class="tb <?= $tab==='plans'?'on':'' ?>">📦 Plans</a>
        <a href="?tab=buckets"  class="tb <?= $tab==='buckets'?'on':'' ?>">🪣 All Buckets</a>
        <a href="?tab=settings" class="tb <?= $tab==='settings'?'on':'' ?>">⚙️ Settings</a>
      </div>

      <!-- REGIONS TAB -->
      <?php if ($tab === 'regions'): ?>

      <!-- Region form -->
      <div class="scard">
        <div class="scard-head">
          <span style="font-size:15px"><?= $edit_region ? '✏️' : '➕' ?></span>
          <span class="scard-title"><?= $edit_region ? 'Edit Region: '.htmlspecialchars($edit_region['label']) : 'Add New Region' ?></span>
          <?php if ($edit_region): ?>
          <a href="?tab=regions" style="margin-left:auto;font-size:12px;color:#2563eb;text-decoration:none;font-weight:600">+ Add new</a>
          <?php endif; ?>
        </div>
        <div class="scard-body">
          <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:9px;padding:12px 15px;margin-bottom:18px;font-size:12.5px;color:#0369a1;line-height:1.7">
            <strong>One region = one MinIO server.</strong> Add one region per physical MinIO installation.
            Users will see these locations when creating a bucket (with flag icons via flagcdn.com).
          </div>

          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="save_region">
            <input type="hidden" name="rid" value="<?= $edit_region ? (int)$edit_region['id'] : 0 ?>">

            <div style="font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#64748b;margin-bottom:10px">📍 Location Info (shown to users)</div>
            <div class="fg3" style="margin-bottom:14px">
              <div>
                <label class="flbl">Region Slug <span style="font-weight:400;color:#94a3b8">(unique, no spaces)</span></label>
                <input name="slug" class="finp" style="font-family:monospace" required placeholder="ap-south-1"
                       value="<?= htmlspecialchars($edit_region['slug'] ?? '') ?>">
                <div style="font-size:11px;color:#94a3b8;margin-top:3px">Used internally. e.g. ap-south-1, us-east-1</div>
              </div>
              <div>
                <label class="flbl">Display Label</label>
                <input name="label" class="finp" required placeholder="India (Mumbai)"
                       value="<?= htmlspecialchars($edit_region['label'] ?? '') ?>">
                <div style="font-size:11px;color:#94a3b8;margin-top:3px">Shown in bucket create page</div>
              </div>
            </div>
            <div class="fg3" style="margin-bottom:18px">
              <div>
                <label class="flbl">City</label>
                <input name="city" class="finp" required placeholder="Mumbai"
                       value="<?= htmlspecialchars($edit_region['city'] ?? '') ?>">
              </div>
              <div>
                <label class="flbl">Country</label>
                <input name="country" class="finp" required placeholder="India"
                       value="<?= htmlspecialchars($edit_region['country'] ?? '') ?>">
              </div>
              <div>
                <label class="flbl">Flag Code <span style="font-weight:400;color:#94a3b8">(2-letter ISO)</span></label>
                <div style="display:flex;align-items:center;gap:8px">
                  <input name="flag_code" id="flag_code_inp" class="finp" required placeholder="in" maxlength="2"
                         style="font-family:monospace;text-transform:lowercase;flex:1"
                         value="<?= htmlspecialchars($edit_region['flag_code'] ?? 'in') ?>"
                         oninput="this.value=this.value.toLowerCase();updateFlagPreview(this.value)">
                  <div id="flag-preview" style="width:28px;height:21px;border-radius:4px;overflow:hidden;border:1px solid #e2e8f0;flex-shrink:0">
                    <img id="flag-img" src="https://flagcdn.com/w40/<?= htmlspecialchars($edit_region['flag_code'] ?? 'in') ?>.png" style="width:100%;height:100%;object-fit:cover">
                  </div>
                </div>
                <div style="font-size:11px;color:#94a3b8;margin-top:3px">
                  in=India, us=USA, sg=Singapore, de=Germany, jp=Japan, gb=UK
                  · <a href="https://flagcdn.com" target="_blank" style="color:#2563eb">All codes →</a>
                </div>
              </div>
            </div>

            <div style="border-top:1px solid #e2e8f0;padding-top:16px;margin-bottom:14px">
              <div style="font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#64748b;margin-bottom:10px">⚙️ MinIO Server Credentials (admin only — never shown to users)</div>
              <div class="fg" style="margin-bottom:12px">
                <div>
                  <label class="flbl">MinIO Server URL <span style="font-weight:400;color:#94a3b8">(internal)</span></label>
                  <input name="minio_endpoint" class="finp" style="font-family:monospace" required
                         placeholder="http://192.168.1.10:9000"
                         value="<?= htmlspecialchars($edit_region['minio_endpoint'] ?? '') ?>">
                  <div style="font-size:11px;color:#94a3b8;margin-top:3px">PHP uses this to create/delete buckets. Never exposed to users.</div>
                </div>
                <div>
                  <label class="flbl">Public S3 Endpoint <span style="font-weight:400;color:#94a3b8">(shown to users)</span></label>
                  <input name="s3_public_endpoint" class="finp" style="font-family:monospace" required
                         placeholder="https://s3.domain.com"
                         value="<?= htmlspecialchars($edit_region['s3_public_endpoint'] ?? '') ?>">
                  <div style="font-size:11px;color:#94a3b8;margin-top:3px">Users connect boto3/aws-cli to this URL.</div>
                </div>
              </div>
              <div class="fg" style="margin-bottom:12px">
                <div>
                  <label class="flbl">MinIO Admin Access Key</label>
                  <input name="minio_admin_key" class="finp" style="font-family:monospace" required
                         placeholder="admin"
                         value="<?= htmlspecialchars($edit_region['minio_admin_key'] ?? '') ?>">
                </div>
                <div>
                  <label class="flbl">MinIO Admin Secret Key</label>
                  <input name="minio_admin_secret" type="password" class="finp" required
                         autocomplete="new-password"
                         value="<?= htmlspecialchars($edit_region['minio_admin_secret'] ?? '') ?>">
                </div>
              </div>
              <div class="fg">
                <div>
                  <label class="flbl">Sort Order</label>
                  <input name="sort_order" type="number" min="0" class="finp"
                         value="<?= htmlspecialchars((string)($edit_region['sort_order'] ?? 0)) ?>">
                  <div style="font-size:11px;color:#94a3b8;margin-top:3px">Lower = shown first</div>
                </div>
                <div>
                  <label class="flbl">Status</label>
                  <div style="margin-top:6px;display:flex;gap:16px">
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
                      <input type="radio" name="is_active" value="1"
                             <?= (!$edit_region || $edit_region['is_active']) ? 'checked' : '' ?>
                             style="accent-color:var(--primary)">Active
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
                      <input type="radio" name="is_active" value="0"
                             <?= ($edit_region && !$edit_region['is_active']) ? 'checked' : '' ?>
                             style="accent-color:var(--primary)">Inactive
                    </label>
                  </div>
                </div>
              </div>
            </div>

            <div style="display:flex;gap:8px;align-items:center">
              <button type="submit" class="btn-save">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                <?= $edit_region ? 'Update Region' : 'Add Region' ?>
              </button>
              <?php if ($edit_region): ?>
              <a href="?tab=regions" class="btn btn-ghost btn-sm">Cancel</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </div>

      <!-- Regions list -->
      <div class="scard">
        <div class="scard-head">
          <span class="scard-title">All Regions (<?= count($all_regions) ?>)</span>
          <?php if (empty($all_regions)): ?>
          <span style="margin-left:auto;font-size:12px;color:#f59e0b;font-weight:700">⚠ No regions yet — add one above to enable bucket creation</span>
          <?php endif; ?>
        </div>
        <?php if (empty($all_regions)): ?>
        <div style="padding:32px;text-align:center;color:#94a3b8;font-size:13px">
          No regions configured yet. Add your first MinIO region above.
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
          <table class="tbl">
            <thead><tr>
              <th>Flag</th><th>Region</th><th>Slug</th><th>MinIO URL</th><th>S3 Public URL</th><th>Status</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($all_regions as $r): ?>
            <tr>
              <td>
                <img src="https://flagcdn.com/w40/<?= htmlspecialchars($r['flag_code']) ?>.png"
                     style="width:24px;height:18px;border-radius:3px;object-fit:cover;display:block"
                     onerror="this.style.opacity='.3'">
              </td>
              <td>
                <div style="font-weight:800;color:#0f172a"><?= htmlspecialchars($r['label']) ?></div>
                <div style="font-size:11.5px;color:#94a3b8"><?= htmlspecialchars($r['city']) ?>, <?= htmlspecialchars($r['country']) ?></div>
              </td>
              <td style="font-family:monospace;font-size:12px;color:#64748b"><?= htmlspecialchars($r['slug']) ?></td>
              <td style="font-family:monospace;font-size:11.5px;color:#94a3b8;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($r['minio_endpoint']) ?></td>
              <td style="font-family:monospace;font-size:11.5px;color:#2563eb;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <a href="<?= htmlspecialchars($r['s3_public_endpoint']) ?>" target="_blank" style="color:#2563eb"><?= htmlspecialchars($r['s3_public_endpoint']) ?></a>
              </td>
              <td><span class="tag <?= $r['is_active'] ? 'tag-active' : 'tag-inactive' ?>"><?= $r['is_active'] ? 'Active' : 'Inactive' ?></span></td>
              <td>
                <div style="display:flex;gap:6px">
                  <a href="?tab=regions&edit_region=<?= $r['id'] ?>" style="padding:4px 10px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;font-size:12px;font-weight:700;color:#2563eb;text-decoration:none">Edit</a>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Delete region '<?= addslashes($r['label']) ?>'?')">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="delete_region">
                    <input type="hidden" name="rid" value="<?= $r['id'] ?>">
                    <button type="submit" style="padding:4px 10px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;font-size:12px;font-weight:700;color:#dc2626;cursor:pointer;font-family:inherit">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <?php endif; // regions tab ?>

      <!-- PLANS TAB -->
      <?php if ($tab === 'plans'): ?>

      <!-- Plan form -->
      <div class="scard">
        <div class="scard-head">
          <span style="font-size:15px"><?= $edit_plan ? '✏️' : '➕' ?></span>
          <span class="scard-title"><?= $edit_plan ? 'Edit Plan: '.$edit_plan['name'] : 'New Storage Plan' ?></span>
          <?php if ($edit_plan): ?>
          <a href="?tab=plans" style="margin-left:auto;font-size:12px;color:#2563eb;text-decoration:none;font-weight:600">+ Create new</a>
          <?php endif; ?>
        </div>
        <div class="scard-body">
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="save_plan">
            <input type="hidden" name="pid" value="<?= $edit_plan ? (int)$edit_plan['id'] : 0 ?>">
            <div class="fg">
              <div><label class="flbl">Slug <span style="font-weight:400;color:#94a3b8">(unique, URL-safe)</span></label><input name="slug" class="finp" style="font-family:monospace" required placeholder="e.g. basic" value="<?= sp('slug',$edit_plan) ?>"></div>
              <div><label class="flbl">Display Name</label><input name="name" class="finp" required placeholder="e.g. Basic" value="<?= sp('name',$edit_plan) ?>"></div>
            </div>
            <div class="fg3">
              <div><label class="flbl">Storage GB</label><input name="storage_gb" type="number" min="1" class="finp" required value="<?= sp('storage_gb',$edit_plan,'100') ?>"></div>
              <div><label class="flbl">Bandwidth GB/mo</label><input name="bandwidth_gb" type="number" min="0" class="finp" value="<?= sp('bandwidth_gb',$edit_plan,'500') ?>"></div>
              <div><label class="flbl">Sort Order</label><input name="sort_order" type="number" class="finp" value="<?= sp('sort_order',$edit_plan,'0') ?>"></div>
            </div>
            <div class="fg">
              <div><label class="flbl">Price INR (₹/mo)</label><input name="price_inr" type="number" step="0.01" min="0" class="finp" required value="<?= sp('price_inr',$edit_plan,'0') ?>"></div>
              <div><label class="flbl">Price USD ($/mo)</label><input name="price_usd" type="number" step="0.000001" min="0" class="finp" required value="<?= sp('price_usd',$edit_plan,'0') ?>"></div>
            </div>
            <div style="margin-bottom:14px"><label class="flbl">Description</label><input name="description" class="finp" placeholder="Short plan description" value="<?= sp('description',$edit_plan) ?>"></div>
            <div style="display:flex;align-items:center;gap:14px;margin-bottom:14px">
              <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer">
                <input type="checkbox" name="is_active" value="1" <?= (!$edit_plan||$edit_plan['is_active'])?'checked':'' ?> style="accent-color:var(--primary)">
                Active (visible to users)
              </label>
            </div>
            <button type="submit" class="btn-save">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              <?= $edit_plan ? 'Update Plan' : 'Create Plan' ?>
            </button>
          </form>
        </div>
      </div>

      <!-- Plans list -->
      <div class="scard">
        <div class="scard-head"><span class="scard-title">All Plans (<?= count($plans) ?>)</span></div>
        <div style="overflow-x:auto">
          <table class="tbl">
            <thead><tr><th>Plan</th><th>Storage</th><th>Bandwidth</th><th>INR/mo</th><th>USD/mo</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($plans as $p): ?>
            <tr>
              <td>
                <div style="font-weight:800;color:#0f172a"><?= htmlspecialchars($p['name']) ?></div>
                <div style="font-size:11.5px;color:#94a3b8;font-family:monospace"><?= htmlspecialchars($p['slug']) ?></div>
              </td>
              <td style="font-family:monospace"><?= number_format($p['storage_gb']) ?> GB</td>
              <td style="font-family:monospace"><?= number_format($p['bandwidth_gb']) ?> GB</td>
              <td style="font-family:monospace;color:#15803d">₹<?= number_format((float)$p['price_inr'], 0) ?></td>
              <td style="font-family:monospace;color:#1d4ed8">$<?= number_format((float)$p['price_usd'], 2) ?></td>
              <td><span class="tag <?= $p['is_active']?'tag-active':'tag-inactive' ?>"><?= $p['is_active']?'Active':'Inactive' ?></span></td>
              <td>
                <div style="display:flex;gap:6px">
                  <a href="?tab=plans&edit_plan=<?= $p['id'] ?>" style="padding:4px 10px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;font-size:12px;font-weight:700;color:#2563eb;text-decoration:none">Edit</a>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Disable this plan?')">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="delete_plan">
                    <input type="hidden" name="pid" value="<?= $p['id'] ?>">
                    <button type="submit" style="padding:4px 10px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;font-size:12px;font-weight:700;color:#dc2626;cursor:pointer;font-family:inherit">Disable</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- BUCKETS TAB -->
      <?php elseif ($tab === 'buckets'): ?>
      <div class="scard">
        <div class="scard-head"><span class="scard-title">All Buckets (<?= count($all_buckets) ?>)</span></div>
        <div style="overflow-x:auto">
          <table class="tbl">
            <thead><tr><th>Bucket</th><th>User</th><th>Plan</th><th>Used</th><th>Status</th><th>Created</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($all_buckets as $b):
              $pct = storage_pct((float)$b['used_gb'], (int)($b['plan_gb'] ?? $b['storage_gb'] ?? 1));
            ?>
            <tr>
              <td><div style="font-weight:700;font-family:monospace"><?= htmlspecialchars($b['name']) ?></div><div style="font-size:11px;color:#94a3b8"><?= $b['region'] ?></div></td>
              <td><div style="font-weight:600"><?= htmlspecialchars($b['username']) ?></div><div style="font-size:11px;color:#94a3b8"><?= htmlspecialchars($b['email']) ?></div></td>
              <td><?= htmlspecialchars($b['plan_name']) ?></td>
              <td>
                <div style="font-size:12px;font-family:monospace"><?= number_format((float)$b['used_gb'],2) ?> GB</div>
                <div style="height:3px;background:#f1f5f9;border-radius:99px;margin-top:3px;overflow:hidden;width:80px">
                  <div style="height:100%;background:<?= $pct>90?'#ef4444':($pct>70?'#f59e0b':'#0ea5e9') ?>;width:<?= $pct ?>%"></div>
                </div>
              </td>
              <td><span class="tag tag-<?= $b['status'] === 'active' ? 'active' : 'suspended' ?>"><?= ucfirst($b['status']) ?></span></td>
              <td style="font-size:12px;color:#64748b"><?= date('d M Y', strtotime($b['created_at'])) ?></td>
              <td>
                <form method="POST" style="display:inline" onsubmit="return confirm('Force delete this bucket?')">
                  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="force_delete_bucket">
                  <input type="hidden" name="bid" value="<?= $b['id'] ?>">
                  <button type="submit" style="padding:4px 10px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;font-size:11.5px;font-weight:700;color:#dc2626;cursor:pointer;font-family:inherit">Delete</button>
                </form>
                <?php if ($b['status'] === 'active'): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Suspend bucket? Public URLs will stop working immediately.')">
                  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="suspend_bucket">
                  <input type="hidden" name="bid" value="<?= $b['id'] ?>">
                  <button type="submit" style="padding:4px 10px;background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;font-size:11.5px;font-weight:700;color:#d97706;cursor:pointer;font-family:inherit">Suspend</button>
                </form>
                <?php elseif ($b['status'] === 'suspended'): ?>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="unsuspend_bucket">
                  <input type="hidden" name="bid" value="<?= $b['id'] ?>">
                  <button type="submit" style="padding:4px 10px;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;font-size:11.5px;font-weight:700;color:#16a34a;cursor:pointer;font-family:inherit">Unsuspend</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- SETTINGS TAB -->
      <?php elseif ($tab === 'settings'): ?>
      <div class="scard">
        <div class="scard-head"><span class="scard-title">Storage Settings</span></div>
        <div class="scard-body">
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="save_settings">

            <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:9px;padding:12px 15px;margin-bottom:18px;font-size:13px;color:#15803d;display:flex;gap:8px;align-items:flex-start">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><polyline points="20 6 9 17 4 12"/></svg>
              <div>MinIO credentials are now managed per-region in the <a href="?tab=regions" style="color:#15803d;font-weight:700">Regions tab</a>. Each region has its own MinIO server, credentials, and public endpoint.</div>
            </div>

            <div class="fg">
              <div>
                <label class="flbl">Status</label>
                <?php $region_count = count(storage_get_regions()); ?>
                <div style="padding:9px 12px;background:<?= $region_count>0?'#f0fdf4':'#fef2f2' ?>;border:1.5px solid <?= $region_count>0?'#86efac':'#fca5a5' ?>;border-radius:8px;font-size:13px;font-weight:700;color:<?= $region_count>0?'#15803d':'#dc2626' ?>">
                  <?= $region_count > 0 ? "✓ {$region_count} region(s) active — storage ready" : '✗ No regions configured yet' ?>
                </div>
              </div>
            </div>

            <div class="fg">
              <div>
                <label class="flbl">Cron Command</label>
                <div style="background:#0d1117;color:#3fb950;padding:12px;border-radius:9px;font-family:monospace;font-size:12px;line-height:1.8">
                  # Storage hourly billing<br>
                  0 * * * * /usr/local/bin/php <?= htmlspecialchars(realpath(__DIR__ . '/cron/storage-billing.php')) ?><br>
                  <br>
                  # Or via HTTP (with cron secret)<br>
                  0 * * * * curl "<?= BASE_URL ?>/cron/storage-billing.php?secret=<?= htmlspecialchars(get_setting('billing_cron_secret','YOUR_SECRET')) ?>"
                </div>
              </div>
            </div>
            </div><!-- /extra div -->
            <button type="submit" class="btn-save">Save Settings</button>
          </form>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>
<script>
function updateFlagPreview(code) {
    if (code && code.length >= 2) {
        var img = document.getElementById('flag-img');
        if (img) {
            img.src = 'https://flagcdn.com/w40/' + code.toLowerCase().slice(0,2) + '.png';
        }
    }
}
</script>
</body>
</html>
