<?php
/**
 * admin/announcement.php
 * Create, edit, delete announcements shown on dashboard.php
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin.php';
require_admin();

$user     = current_user();
$app_name = APP_NAME;
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$csrf     = csrf_token();
$msg = ''; $err = '';

// ── UPLOAD helper ────────────────────────────────────────────
function handle_image_upload(): ?string {
    if (empty($_FILES['image_file']['name'])) return null;
    $f   = $_FILES['image_file'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','gif','webp','svg'])) return null;
    if ($f['size'] > 5 * 1024 * 1024) return null; // 5MB max

    $dir  = __DIR__ . '/../assets/img/announcements/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = 'ann_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (move_uploaded_file($f['tmp_name'], $dir . $name)) {
        return BASE_URL . '/assets/img/announcements/' . $name;
    }
    return null;
}

// ── ACTIONS ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $title       = trim($_POST['title']       ?? '');
        $description = trim($_POST['description'] ?? '');
        $coupon      = trim($_POST['coupon_code']  ?? '') ?: null;
        $cta_label   = trim($_POST['cta_label']   ?? '') ?: null;
        $cta_url     = trim($_POST['cta_url']     ?? '') ?: null;
        $badge_text  = trim($_POST['badge_text']  ?? '') ?: null;
        $badge_color = trim($_POST['badge_color'] ?? '#2563eb');
        $start_at    = $_POST['start_at']  ?? '';
        $end_at      = $_POST['end_at']    ?? '';
        $is_active   = isset($_POST['is_active'])   ? 1 : 0;
        $dismiss_once= isset($_POST['dismiss_once']) ? 1 : 0;
        $target      = in_array($_POST['target']??'all', ['all','inr','usd']) ? $_POST['target'] : 'all';

        // Image: uploaded file takes priority, fallback to URL field
        $image_url = handle_image_upload()
                  ?? (trim($_POST['image_url'] ?? '') ?: null);

        if (!$title || !$description || !$start_at || !$end_at) {
            $err = 'Title, description, start date and end date are required.';
        } elseif (strtotime($end_at) <= strtotime($start_at)) {
            $err = 'End date must be after start date.';
        } else {
            if ($id) {
                // Keep existing image if no new one provided
                if (!$image_url) {
                    $ex = db()->prepare('SELECT image_url FROM announcements WHERE id=? LIMIT 1');
                    $ex->execute([$id]); $image_url = $ex->fetchColumn() ?: null;
                }
                db()->prepare(
                    'UPDATE announcements SET title=?,description=?,image_url=?,coupon_code=?,
                     cta_label=?,cta_url=?,badge_text=?,badge_color=?,start_at=?,end_at=?,
                     is_active=?,dismiss_once=?,target=? WHERE id=?'
                )->execute([$title,$description,$image_url,$coupon,
                            $cta_label,$cta_url,$badge_text,$badge_color,
                            $start_at,$end_at,$is_active,$dismiss_once,$target,$id]);
                $msg = 'Announcement updated.';
            } else {
                db()->prepare(
                    'INSERT INTO announcements (title,description,image_url,coupon_code,
                     cta_label,cta_url,badge_text,badge_color,start_at,end_at,
                     is_active,dismiss_once,target,created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([$title,$description,$image_url,$coupon,
                            $cta_label,$cta_url,$badge_text,$badge_color,
                            $start_at,$end_at,$is_active,$dismiss_once,$target,(int)$user['id']]);
                $msg = 'Announcement created.';
            }
        }
    }

    if ($act === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('UPDATE announcements SET is_active = NOT is_active WHERE id=?')->execute([$id]);
        $msg = 'Status toggled.';
    }

    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM announcements WHERE id=?')->execute([$id]);
        db()->prepare('DELETE FROM announcement_dismissals WHERE announcement_id=?')->execute([$id]);
        $msg = 'Announcement deleted.';
    }
}

// ── EDIT MODE ────────────────────────────────────────────────
$edit = null;
if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM announcements WHERE id=? LIMIT 1');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
}

// ── LIST ─────────────────────────────────────────────────────
try {
    $list = db()->query(
        'SELECT a.*, u.username as creator
         FROM announcements a
         LEFT JOIN users u ON u.id=a.created_by
         ORDER BY a.created_at DESC LIMIT 60'
    )->fetchAll();
} catch (Throwable $e) { $list = []; }

function ann_val(string $k, ?array $edit = null, string $default = ''): string {
    if ($edit && array_key_exists($k, $edit)) return htmlspecialchars((string)($edit[$k] ?? $default));
    return htmlspecialchars($default);
}
$now_str = date('Y-m-d\TH:i');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Announcements — <?= $app_name ?> Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/admin/admin.css">
  <style>
    .adm-shell{display:flex;min-height:100vh;background:#f8fafc}
    
    .adm-logo{padding:18px 16px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:8px}
    .adm-logo-mark{width:28px;height:28px;border-radius:6px;background:var(--primary);display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .adm-logo-text{font-weight:800;font-size:14px;color:white}
    .adm-badge{font-size:9px;font-weight:700;background:#dc2626;color:white;padding:1px 6px;border-radius:99px;margin-left:4px;text-transform:uppercase}
    .adm-nav{flex:1;padding:10px 8px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.1) transparent}
    .adm-nav-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:rgba(255,255,255,.3);padding:10px 8px 4px}
    .adm-link{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:7px;font-size:13px;font-weight:500;color:rgba(255,255,255,.6);text-decoration:none;transition:all .14s;margin-bottom:1px}
    .adm-link:hover{background:rgba(255,255,255,.08);color:rgba(255,255,255,.9)}
    .adm-link.active{background:#22293b;color:white;font-weight:700}
    .adm-link svg{width:15px;height:15px;flex-shrink:0}
    .adm-footer-bar{padding:12px 10px;border-top:1px solid rgba(255,255,255,.08)}
    .adm-av{width:30px;height:30px;border-radius:7px;background:var(--primary);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:white;flex-shrink:0;overflow:hidden}
    .adm-main{margin-left:232px;flex:1;min-height:100vh}
    .adm-topbar{background:white;border-bottom:1px solid #e2e8f0;height:56px;display:flex;align-items:center;padding:0 28px;position:sticky;top:0;z-index:30;gap:12px}
    .adm-topbar-title{font-size:15px;font-weight:800;color:#0f172a}

    .page{padding:24px 28px;max-width:1100px}

    /* Form card */
    .fcard{background:white;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;margin-bottom:24px}
    .fcard-head{padding:16px 22px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:10px;background:#fafbfd}
    .fcard-title{font-size:14px;font-weight:800;color:#0f172a}
    .fcard-body{padding:22px}
    .fg{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:14px}
    .fg3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px}
    .fgfull{display:grid;grid-template-columns:1fr;gap:0;margin-bottom:14px}
    .flbl{display:block;font-size:11.5px;font-weight:700;color:#475569;margin-bottom:5px;letter-spacing:.01em}
    .flbl span{font-weight:400;color:#94a3b8}
    .finp{width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:13px;color:#0f172a;outline:none;transition:border-color .14s,box-shadow .14s;background:white}
    .finp:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-ring)}
    .fnote{font-size:11px;color:#94a3b8;margin-top:4px;line-height:1.5}
    textarea.finp{resize:vertical;min-height:80px;line-height:1.6}
    .check-row{display:flex;align-items:center;gap:8px;font-size:13px;color:#374151;cursor:pointer;margin-bottom:8px}
    .check-row input{accent-color:var(--primary);width:15px;height:15px}
    .color-wrap{display:flex;gap:8px;align-items:center}
    .color-swatch{width:36px;height:36px;border-radius:7px;border:1.5px solid #e2e8f0;cursor:pointer;padding:0;flex-shrink:0}
    .divider{border:none;border-top:1px solid #f1f5f9;margin:18px 0}

    /* Upload zone */
    .upload-zone{border:1.5px dashed #cbd5e1;border-radius:10px;padding:18px;text-align:center;cursor:pointer;transition:all .14s;background:#f8fafc;position:relative}
    .upload-zone:hover,.upload-zone.drag{border-color:var(--primary);background:#f0f9ff}
    .upload-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
    .upload-zone-txt{font-size:13px;color:#64748b;pointer-events:none}
    .upload-preview{width:100%;max-height:120px;object-fit:cover;border-radius:7px;margin-top:10px;display:none}

    /* Buttons */
    .btn-save{display:inline-flex;align-items:center;gap:7px;padding:10px 24px;background:var(--primary);color:white;border:none;border-radius:9px;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .15s}
    .btn-save:hover{background:var(--primary-hover);transform:translateY(-1px)}
    .btn-cancel{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;background:white;color:#374151;border:1.5px solid #e2e8f0;border-radius:9px;font-size:14px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all .15s}
    .btn-cancel:hover{background:#f8fafc}

    /* List table */
    .ann-table{width:100%;border-collapse:collapse}
    .ann-table th{padding:9px 14px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:#94a3b8;background:#f8fafc;border-bottom:1px solid #e2e8f0}
    .ann-table td{padding:12px 14px;border-bottom:1px solid #f1f5f9;font-size:13px;vertical-align:middle}
    .ann-table tr:last-child td{border:none}
    .ann-table tr:hover td{background:#fafbfd}
    .status-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:99px;font-size:11px;font-weight:700;white-space:nowrap}
    .pill-live{background:#f0fdf4;color:#16a34a}
    .pill-scheduled{background:#eff6ff;color:#2563eb}
    .pill-expired{background:#f8fafc;color:#94a3b8}
    .pill-off{background:#fef2f2;color:#dc2626}
    .ann-thumb{width:44px;height:30px;object-fit:cover;border-radius:5px;border:1px solid #e2e8f0}
    .ann-no-img{width:44px;height:30px;background:#f1f5f9;border-radius:5px;display:inline-flex;align-items:center;justify-content:center;font-size:14px}
    .act-btn{padding:5px 11px;border-radius:7px;font-size:12px;font-weight:700;border:1.5px solid;cursor:pointer;font-family:inherit;transition:all .13s;text-decoration:none;display:inline-flex;align-items:center;gap:4px}
    .act-edit{border-color:#bfdbfe;color:#2563eb;background:#eff6ff}.act-edit:hover{background:#dbeafe}
    .act-del{border-color:#fca5a5;color:#dc2626;background:#fef2f2}.act-del:hover{background:#fee2e2}
    .act-tog{border-color:#e2e8f0;color:#64748b;background:white}.act-tog:hover{background:#f8fafc}

    /* Preview modal */
    #preview-modal{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:999;display:none;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px)}
    #preview-modal.show{display:flex}

    /* Mobile */
    .adm-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);backdrop-filter:blur(3px);z-index:45;opacity:0;pointer-events:none;transition:opacity .25s}
    .adm-overlay.open{opacity:1;pointer-events:auto}
    .adm-mobile-bar{display:none;background:white;border-bottom:1px solid #e2e8f0;padding:10px 14px;align-items:center;gap:12px;position:sticky;top:0;z-index:60}
    .adm-ham{width:34px;height:34px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center}
    @media(max-width:900px){
      .adm-mobile-bar{display:flex}.adm-topbar{display:none}
      .adm-sidebar.open{transform:translateX(0)}
      .adm-main{margin-left:0!important}.fg,.fg3{grid-template-columns:1fr!important}
      .page{padding:16px}
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
  <span style="font-weight:800;font-size:14px">Announcements</span>
</div>

<div class="adm-shell">
  <?php include 'sidebar.php'; ?>

  <!-- ── Main ──────────────────────────────────────────────── -->
  <div class="adm-main">
    <div class="adm-topbar">
      <span class="adm-topbar-title">📢 Announcements</span>
      <div style="margin-left:auto;font-size:12px;color:#94a3b8"><?= date('d M Y, H:i') ?></div>
    </div>

    <div class="page">

      <?php if ($msg): ?>
      <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:11px 16px;margin-bottom:18px;font-size:13px;font-weight:700;color:#15803d;display:flex;align-items:center;gap:8px">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        <?= htmlspecialchars($msg) ?>
      </div>
      <?php endif; ?>
      <?php if ($err): ?>
      <div style="background:#fef2f2;border:1.5px solid #fca5a5;border-radius:10px;padding:11px 16px;margin-bottom:18px;font-size:13px;font-weight:700;color:#dc2626;display:flex;align-items:center;gap:8px">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($err) ?>
      </div>
      <?php endif; ?>

      <!-- ═══ CREATE / EDIT FORM ═══════════════════════════════ -->
      <div class="fcard">
        <div class="fcard-head">
          <span style="font-size:16px"><?= $edit ? '✏️' : '➕' ?></span>
          <span class="fcard-title"><?= $edit ? 'Edit Announcement #'.$edit['id'] : 'New Announcement' ?></span>
          <?php if ($edit): ?>
          <a href="<?= BASE_URL ?>/admin/announcement.php" style="margin-left:auto;font-size:12px;color:#2563eb;text-decoration:none;font-weight:600">+ New instead</a>
          <?php endif; ?>
        </div>
        <div class="fcard-body">
          <form method="POST" enctype="multipart/form-data" id="ann-form">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">

            <!-- Title + Badge -->
            <div class="fg">
              <div>
                <label class="flbl">Title <span>*</span></label>
                <input name="title" class="finp" required placeholder="e.g. 🎉 Special Diwali Offer" value="<?= ann_val('title', $edit) ?>">
              </div>
              <div>
                <label class="flbl">Badge Text <span>(optional — shown as a chip)</span></label>
                <div style="display:flex;gap:8px">
                  <input name="badge_text" class="finp" placeholder="e.g. NEW, SALE, LIMITED" value="<?= ann_val('badge_text', $edit) ?>" style="flex:1">
                  <div class="color-wrap">
                    <input type="text" name="badge_color" id="badge_color_txt" class="finp" style="width:90px;font-family:monospace" value="<?= ann_val('badge_color', $edit, '#2563eb') ?>">
                    <input type="color" class="color-swatch" id="badge_color_pick" value="<?= ann_val('badge_color', $edit, '#2563eb') ?>" oninput="syncColor(this,'badge_color_txt')">
                  </div>
                </div>
              </div>
            </div>

            <!-- Description -->
            <div class="fgfull">
              <label class="flbl">Description <span>*</span></label>
              <textarea name="description" class="finp" rows="4" required placeholder="Write your announcement here. HTML is supported for basic formatting."><?= ann_val('description', $edit) ?></textarea>
            </div>

            <!-- Image -->
            <div class="fg" style="align-items:start">
              <div>
                <label class="flbl">Image (1536x1024)<span>(upload file — max 5MB)</span></label>
                <div class="upload-zone" id="upload-zone" ondragover="this.classList.add('drag')" ondragleave="this.classList.remove('drag')">
                  <input type="file" name="image_file" accept="image/*" onchange="previewImg(this)">
                  <div class="upload-zone-txt">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" style="display:block;margin:0 auto 6px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    Drag & drop or click to upload
                  </div>
                  <img id="img-preview" class="upload-preview" alt="">
                </div>
                <?php if ($edit && $edit['image_url']): ?>
                <div style="margin-top:8px;font-size:11.5px;color:#64748b">Current: <a href="<?= htmlspecialchars($edit['image_url']) ?>" target="_blank" style="color:#2563eb">View →</a> (upload new to replace)</div>
                <?php endif; ?>
              </div>
              <div>
                <label class="flbl">Image URL <span>(or paste external URL)</span></label>
                <input name="image_url" class="finp" type="url" placeholder="https://example.com/banner.jpg" value="<?= ann_val('image_url', $edit) ?>">
                <div class="fnote">Upload takes priority over URL. Leave both blank for text-only.</div>
              </div>
            </div>

            <!-- CTA Button -->
            <div class="divider"></div>
            <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#64748b;margin-bottom:12px">Call-to-Action Button <span style="font-weight:400;text-transform:none">(optional)</span></div>
            <div class="fg">
              <div>
                <label class="flbl">Button Label</label>
                <input name="cta_label" class="finp" placeholder="e.g. Claim Offer, Learn More" value="<?= ann_val('cta_label', $edit) ?>">
              </div>
              <div>
                <label class="flbl">Button URL</label>
                <input name="cta_url" class="finp" type="url" placeholder="https://... or /billing.php" value="<?= ann_val('cta_url', $edit) ?>">
              </div>
            </div>

            <!-- Coupon -->
            <div class="divider"></div>
            <div class="fg">
              <div>
                <label class="flbl">Coupon Code <span>(optional)</span></label>
                <input name="coupon_code" class="finp" placeholder="e.g. DIWALI25" value="<?= ann_val('coupon_code', $edit) ?>" style="font-family:monospace;text-transform:uppercase" oninput="this.value=this.value.toUpperCase()">
                <div class="fnote">Will show a copy-to-clipboard chip</div>
              </div>
              <div>
                <label class="flbl">Target Audience</label>
                <select name="target" class="finp">
                  <?php foreach (['all'=>'All Users','inr'=>'INR Users only','usd'=>'USD Users only'] as $v=>$l): ?>
                  <option value="<?= $v ?>" <?= ($edit&&$edit['target']===$v)||(!$edit&&$v==='all')?'selected':'' ?>><?= $l ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <!-- Dates -->
            <div class="divider"></div>
            <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#64748b;margin-bottom:12px">Schedule</div>
            <div class="fg3">
              <div>
                <label class="flbl">Start Date & Time <span>*</span></label>
                <input name="start_at" type="datetime-local" class="finp" required value="<?= $edit ? date('Y-m-d\TH:i', strtotime($edit['start_at'])) : $now_str ?>">
                <div class="fnote">Auto-shows when this time is reached</div>
              </div>
              <div>
                <label class="flbl">End Date & Time <span>*</span></label>
                <input name="end_at" type="datetime-local" class="finp" required value="<?= $edit ? date('Y-m-d\TH:i', strtotime($edit['end_at'])) : '' ?>">
                <div class="fnote">Auto-hides after this time</div>
              </div>
              <div>
                <label class="flbl">Options</label>
                <label class="check-row">
                  <input type="checkbox" name="is_active" value="1" <?= (!$edit||$edit['is_active'])?'checked':'' ?>>
                  Active (visible if within date range)
                </label>
                <label class="check-row">
                  <input type="checkbox" name="dismiss_once" value="1" <?= (!$edit||$edit['dismiss_once'])?'checked':'' ?>>
                  User can permanently dismiss
                </label>
              </div>
            </div>

            <!-- Actions -->
            <div style="display:flex;gap:10px;align-items:center;margin-top:20px">
              <button type="submit" class="btn-save">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                <?= $edit ? 'Update' : 'Create' ?> Announcement
              </button>
              <button type="button" class="btn-cancel" onclick="document.getElementById('preview-modal').classList.add('show')">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                Preview
              </button>
              <?php if ($edit): ?>
              <a href="<?= BASE_URL ?>/admin/announcement.php" class="btn-cancel">Cancel</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </div>

      <!-- ═══ ANNOUNCEMENTS LIST ════════════════════════════════ -->
      <div class="fcard">
        <div class="fcard-head">
          <span style="font-size:16px">📋</span>
          <span class="fcard-title">All Announcements</span>
          <span style="margin-left:auto;font-size:12px;color:#94a3b8"><?= count($list) ?> total</span>
        </div>
        <?php if (empty($list)): ?>
        <div style="padding:40px;text-align:center;color:#94a3b8;font-size:13px">No announcements yet. Create your first one above.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
          <table class="ann-table">
            <thead><tr>
              <th>Image</th><th>Title</th><th>Target</th><th>Schedule</th><th>Status</th><th>Coupon</th><th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($list as $ann):
              $now     = time();
              $start   = strtotime($ann['start_at']);
              $end     = strtotime($ann['end_at']);
              $is_live = $ann['is_active'] && $now >= $start && $now <= $end;
              $is_sched= $ann['is_active'] && $now < $start;
              $is_exp  = $now > $end;
              $is_off  = !$ann['is_active'];
            ?>
            <tr>
              <td>
                <?php if ($ann['image_url']): ?>
                <img src="<?= htmlspecialchars($ann['image_url']) ?>" class="ann-thumb" onerror="this.style.display='none'">
                <?php else: ?><span class="ann-no-img">🖼️</span><?php endif; ?>
              </td>
              <td>
                <div style="font-weight:700;color:#0f172a;margin-bottom:2px"><?= htmlspecialchars($ann['title']) ?></div>
                <div style="font-size:11px;color:#94a3b8"><?= htmlspecialchars(mb_strimwidth(strip_tags($ann['description']),0,60,'…')) ?></div>
              </td>
              <td>
                <?php $tc=['all'=>['#1e293b','All'],'inr'=>['#15803d','🇮🇳 INR'],'usd'=>['#1d4ed8','🌐 USD']]; [$tc_clr,$tc_lbl]=$tc[$ann['target']]??['#64748b','?']; ?>
                <span style="font-size:11.5px;font-weight:700;color:<?= $tc_clr ?>"><?= $tc_lbl ?></span>
              </td>
              <td style="font-size:12px;color:#64748b;white-space:nowrap">
                <div>▶ <?= date('d M Y H:i', $start) ?></div>
                <div>⏹ <?= date('d M Y H:i', $end) ?></div>
              </td>
              <td>
                <?php if ($is_off): ?>
                <span class="status-pill pill-off">Off</span>
                <?php elseif ($is_exp): ?>
                <span class="status-pill pill-expired">Expired</span>
                <?php elseif ($is_sched): ?>
                <span class="status-pill pill-scheduled">Scheduled</span>
                <?php else: ?>
                <span class="status-pill pill-live">
                  <span style="width:6px;height:6px;border-radius:50%;background:#16a34a;display:inline-block;animation:pulse 1.5s ease-in-out infinite"></span>
                  Live
                </span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($ann['coupon_code']): ?>
                <span style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:2px 8px;font-family:monospace;font-size:11.5px;color:#15803d;font-weight:700"><?= htmlspecialchars($ann['coupon_code']) ?></span>
                <?php else: ?><span style="color:#94a3b8;font-size:12px">—</span><?php endif; ?>
              </td>
              <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                  <a href="?edit=<?= $ann['id'] ?>" class="act-btn act-edit">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    Edit
                  </a>
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= $ann['id'] ?>">
                    <button type="submit" class="act-btn act-tog"><?= $ann['is_active'] ? 'Disable' : 'Enable' ?></button>
                  </form>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Delete this announcement?')">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $ann['id'] ?>">
                    <button type="submit" class="act-btn act-del">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                      Del
                    </button>
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

    </div><!-- /page -->
  </div><!-- /main -->
</div><!-- /shell -->

<!-- ═══ PREVIEW MODAL ════════════════════════════════════════ -->
<div id="preview-modal" onclick="if(event.target===this)this.classList.remove('show')">
  <div style="background:white;border-radius:16px;width:100%;max-width:520px;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.2)">
    <div style="padding:14px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between">
      <div style="font-size:13px;font-weight:800;color:#0f172a">Preview</div>
      <button onclick="document.getElementById('preview-modal').classList.remove('show')" style="background:none;border:none;font-size:20px;color:#94a3b8;cursor:pointer;line-height:1">×</button>
    </div>
    <div style="padding:20px">
      <div id="preview-content" style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">
        <!-- filled by JS -->
      </div>
    </div>
  </div>
</div>

<style>@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.4)}}</style>

<script>
function syncColor(picker, txtId) {
  document.getElementById(txtId).value = picker.value.toUpperCase();
}
document.getElementById('badge_color_txt').addEventListener('input', function() {
  if (this.value.length === 7) document.getElementById('badge_color_pick').value = this.value;
});

function previewImg(input) {
  var prev = document.getElementById('img-preview');
  if (input.files && input.files[0]) {
    prev.src = URL.createObjectURL(input.files[0]);
    prev.style.display = 'block';
  }
}

// Preview builder
document.getElementById('preview-modal').addEventListener('click', function(){});
document.querySelector('button[onclick*="preview-modal"]').addEventListener('click', buildPreview);

function buildPreview() {
  var title   = document.querySelector('[name=title]').value || 'Announcement Title';
  var desc    = document.querySelector('[name=description]').value || 'Description goes here.';
  var imgUrl  = document.querySelector('[name=image_url]').value;
  var badge   = document.querySelector('[name=badge_text]').value;
  var badgeClr= document.getElementById('badge_color_txt').value || '#2563eb';
  var coupon  = document.querySelector('[name=coupon_code]').value;
  var ctaLbl  = document.querySelector('[name=cta_label]').value;
  var ctaUrl  = document.querySelector('[name=cta_url]').value;

  // Check uploaded image
  var prev = document.getElementById('img-preview');
  if (prev.style.display !== 'none' && prev.src) imgUrl = prev.src;

  var html = '';
  if (imgUrl) html += '<img src="'+imgUrl+'" style="width:100%;height:160px;object-fit:cover;display:block">';
  html += '<div style="padding:18px 20px">';
  if (badge) html += '<span style="display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;background:'+badgeClr+'22;color:'+badgeClr+';margin-bottom:9px">'+badge+'</span>';
  html += '<div style="font-size:17px;font-weight:800;color:#0f172a;margin-bottom:8px">'+title+'</div>';
  html += '<div style="font-size:13px;color:#475569;line-height:1.7;margin-bottom:14px">'+desc+'</div>';
  if (coupon) {
    html += '<div style="display:flex;align-items:center;gap:10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 13px;margin-bottom:12px">';
    html += '<span style="font-size:12px;color:#15803d;font-weight:600">Coupon:</span>';
    html += '<span style="font-family:monospace;font-size:14px;font-weight:800;color:#166534;letter-spacing:.08em">'+coupon+'</span>';
    html += '<button style="margin-left:auto;padding:3px 10px;background:#16a34a;color:white;border:none;border-radius:5px;font-size:11px;font-weight:700;cursor:pointer">Copy</button>';
    html += '</div>';
  }
  if (ctaLbl) {
    html += '<a href="'+(ctaUrl||'#')+'" style="display:inline-flex;align-items:center;gap:6px;padding:9px 20px;background:var(--primary,#e0121b);color:white;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none">'+ctaLbl+' →</a>';
  }
  html += '</div>';
  document.getElementById('preview-content').innerHTML = html;
}
</script>
</body>
</html>
