<?php
/**
 * admin/legal-pages.php — Legal & Custom Pages Manager v2
 * Editor: TinyMCE 6 (CDN) — WYSIWYG, no CodeMirror issues
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin.php';
require_admin();

$user     = current_user();
$app_name = APP_NAME;
$csrf     = csrf_token();
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$msg = ''; $err = '';
$pdo = db();

// ── Slug sanitizer ──────────────────────────────
function lp_slug(string $t): string {
    $s = strtolower(trim($t));
    $s = preg_replace('/[^a-z0-9\s\-]/', '', $s);
    $s = preg_replace('/[\s\-]+/', '-', $s);
    return trim($s, '-');
}

// ── POST handlers ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    // ── Save page ──────────────────────────────
    if ($action === 'save_page') {
        $id      = (int)($_POST['page_id'] ?? 0);
        $title   = trim(strip_tags($_POST['title']   ?? ''));
        $slug    = lp_slug($_POST['slug'] ?? $title);
        $content = $_POST['content'] ?? '';  // TinyMCE HTML — admin-only trusted
        $meta_t  = strip_tags($_POST['meta_title'] ?? $title);
        $meta_d  = strip_tags($_POST['meta_desc']  ?? '');
        $pub     = (int)!empty($_POST['is_published']);
        $footer  = (int)!empty($_POST['show_in_footer']);
        $sort    = (int)($_POST['sort_order'] ?? 0);

        if (!$title || !$slug) {
            $err = 'Title and slug are required.';
        } else {
            $chk = $pdo->prepare("SELECT id FROM legal_pages WHERE slug=? AND id!=?");
            $chk->execute([$slug, $id]);
            if ($chk->fetch()) {
                $err = "Slug '$slug' already in use.";
            } else {
                if ($id) {
                    $pdo->prepare("UPDATE legal_pages SET title=?,slug=?,content=?,meta_title=?,meta_desc=?,is_published=?,show_in_footer=?,sort_order=?,updated_at=NOW() WHERE id=?")
                        ->execute([$title,$slug,$content,$meta_t,$meta_d,$pub,$footer,$sort,$id]);
                    $msg = '✅ Page updated successfully.';
                } else {
                    $pdo->prepare("INSERT INTO legal_pages (title,slug,content,meta_title,meta_desc,is_published,show_in_footer,sort_order) VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([$title,$slug,$content,$meta_t,$meta_d,$pub,$footer,$sort]);
                    $id  = (int)$pdo->lastInsertId();
                    $msg = '✅ Page created successfully.';
                }
                // Redirect to edit so user can continue editing
                if (!$err) {
                    header("Location: legal-pages.php?edit=$id&saved=1");
                    exit;
                }
            }
        }
    }

    // ── AJAX toggles ───────────────────────────
    if (in_array($action, ['toggle_publish','toggle_footer'])) {
        header('Content-Type: application/json');
        $id  = (int)($_POST['page_id'] ?? 0);
        $col = $action === 'toggle_publish' ? 'is_published' : 'show_in_footer';
        $pdo->prepare("UPDATE legal_pages SET $col = 1 - $col WHERE id=?")->execute([$id]);
        $row = $pdo->prepare("SELECT $col as v FROM legal_pages WHERE id=?");
        $row->execute([$id]);
        echo json_encode(['ok'=>true,'value'=>(int)$row->fetchColumn()]);
        exit;
    }

    // ── Delete ──────────────────────────────────
    if ($action === 'delete_page') {
        header('Content-Type: application/json');
        $id = (int)($_POST['page_id'] ?? 0);
        $pdo->prepare("DELETE FROM legal_pages WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]);
        exit;
    }
}

// ── Saved flash ─────────────────────────────────
if (!empty($_GET['saved'])) $msg = '✅ Page saved successfully.';

// ── Load edit page ──────────────────────────────
$edit_page = null;
if (!empty($_GET['edit'])) {
    $st = $pdo->prepare("SELECT * FROM legal_pages WHERE id=? LIMIT 1");
    $st->execute([(int)$_GET['edit']]);
    $edit_page = $st->fetch();
}

$pages = $pdo->query("SELECT * FROM legal_pages ORDER BY sort_order ASC, id ASC")->fetchAll();
$view  = ($edit_page || !empty($_GET['new'])) ? 'editor' : 'list';

// Page icon map
$icons = [
    'privacy-policy'=>'🔐','terms-of-service'=>'📋',
    'refund-policy'=>'💳','cookie-policy'=>'🍪','acceptable-use-policy'=>'🛡️',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Legal Pages — <?= htmlspecialchars($app_name) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/admin/admin.css">
<!-- Quill.js — free WYSIWYG, no API key, cdnjs hosted -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.snow.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.min.js"></script>
<?php inject_global_head(); ?>
<style>
/* ── Shell ─────────────────────────────────────── */
.lp-shell { display:flex; min-height:100vh; background:#f1f5f9; }
.lp-main  { flex:1; margin-left:232px; }
@media(max-width:768px){ .lp-main{ margin-left:0; } }

/* ── Topbar ────────────────────────────────────── */
.lp-topbar {
  background:#fff; border-bottom:1px solid #e2e8f0;
  padding:0 28px; height:56px;
  display:flex; align-items:center; justify-content:space-between; gap:12px;
  position:sticky; top:0; z-index:50;
}
.lp-topbar h1 { font-size:18px; font-weight:800; color:#0f172a; margin:0; display:flex; align-items:center; gap:8px; }
.lp-content { padding:24px 28px 60px; }

/* ── Alerts ────────────────────────────────────── */
.alert-ok  { background:#d1fae5; color:#065f46; border-radius:10px; padding:12px 16px; margin-bottom:20px; font-size:14px; font-weight:500; display:flex; align-items:center; gap:8px; }
.alert-err { background:#fee2e2; color:#b91c1c; border-radius:10px; padding:12px 16px; margin-bottom:20px; font-size:14px; font-weight:500; display:flex; align-items:center; gap:8px; }

/* ── Buttons ───────────────────────────────────── */
.btn { display:inline-flex; align-items:center; gap:6px; padding:8px 18px; border-radius:9px; border:none; cursor:pointer; font-size:13px; font-weight:600; text-decoration:none; transition:all .15s; white-space:nowrap; }
.btn-primary { background:#6366f1; color:#fff; box-shadow:0 2px 8px rgba(99,102,241,.25); }
.btn-primary:hover { background:#4f46e5; transform:translateY(-1px); }
.btn-outline { background:#fff; border:1.5px solid #e2e8f0; color:#64748b; }
.btn-outline:hover { border-color:#6366f1; color:#6366f1; background:#f5f3ff; }
.btn-red    { background:#ef4444; color:#fff; }
.btn-red:hover { background:#dc2626; }
.btn-green  { background:#10b981; color:#fff; }
.btn-green:hover { background:#059669; }
.btn-sm { padding:5px 12px; font-size:12px; }
.btn-lg { padding:11px 24px; font-size:14px; }

/* ── Pages list ────────────────────────────────── */
.pages-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:16px; margin-bottom:20px; }
.page-card {
  background:#fff; border:1.5px solid #e2e8f0; border-radius:14px;
  padding:20px; transition:all .18s;
  display:flex; flex-direction:column; gap:12px;
}
.page-card:hover { border-color:#6366f1; box-shadow:0 4px 20px rgba(99,102,241,.1); transform:translateY(-1px); }
.pc-head { display:flex; align-items:flex-start; gap:12px; }
.pc-icon { width:42px; height:42px; border-radius:10px; background:#f5f3ff; border:1.5px solid #ede9fe; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
.pc-title { font-size:15px; font-weight:700; color:#0f172a; margin-bottom:3px; }
.pc-slug  { font-family:monospace; font-size:11px; color:#6366f1; background:#f5f3ff; padding:2px 8px; border-radius:20px; display:inline-block; }
.pc-meta  { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.pc-badge { font-size:10px; font-weight:700; padding:3px 10px; border-radius:20px; }
.badge-pub   { background:#d1fae5; color:#065f46; }
.badge-draft { background:#f1f5f9; color:#64748b; }
.badge-footer{ background:#dbeafe; color:#1e40af; }
.pc-actions  { display:flex; gap:8px; margin-top:4px; }

/* Toggle switch */
.tog { position:relative; display:inline-block; width:40px; height:22px; flex-shrink:0; }
.tog input { opacity:0; width:0; height:0; }
.tog .sl { position:absolute; inset:0; background:#cbd5e1; border-radius:22px; transition:.2s; cursor:pointer; }
.tog .sl::before { content:''; position:absolute; width:16px; height:16px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.2s; box-shadow:0 1px 3px rgba(0,0,0,.2); }
.tog input:checked + .sl { background:#6366f1; }
.tog input:checked + .sl::before { transform:translateX(18px); }

/* ── Editor layout ─────────────────────────────── */
.editor-wrap { display:grid; grid-template-columns:1fr 300px; gap:20px; align-items:start; }
@media(max-width:1100px){ .editor-wrap { grid-template-columns:1fr; } }

/* ── Editor card ───────────────────────────────── */
.card { background:#fff; border:1.5px solid #e2e8f0; border-radius:14px; padding:24px; margin-bottom:16px; }
.card h3 { font-size:14px; font-weight:700; color:#0f172a; margin:0 0 16px; display:flex; align-items:center; gap:7px; }

/* ── Fields ────────────────────────────────────── */
.field { margin-bottom:18px; }
.field label { display:block; font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.06em; margin-bottom:6px; }
.field input[type=text], .field input[type=number], .field textarea, .field select {
  width:100%; border:1.5px solid #e2e8f0; border-radius:9px; padding:9px 13px;
  font-size:14px; outline:none; color:#0f172a; background:#fff; transition:border-color .15s; box-sizing:border-box;
}
.field input:focus, .field textarea:focus, .field select:focus { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.08); }
.field .hint { font-size:11px; color:#94a3b8; margin-top:4px; }
.field .slug-live { font-size:12px; color:#6366f1; margin-top:5px; font-family:monospace; }

/* ── Quill editor wrapper ──────────────────────── */
.ql-toolbar { border-radius:10px 10px 0 0 !important; border:1.5px solid #e2e8f0 !important; background:#f8fafc; flex-wrap:wrap; }
.ql-container { border-radius:0 0 10px 10px !important; border:1.5px solid #e2e8f0 !important; border-top:none !important; font-size:15px; }
.ql-editor { min-height:420px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; line-height:1.75; color:#1e293b; padding:20px 24px; }
.ql-editor:focus { outline:none; }
.ql-container:focus-within { border-color:#6366f1 !important; }
.ql-toolbar:focus-within { border-color:#6366f1 !important; }
.ql-editor h1 { font-size:26px; font-weight:800; color:#0f172a; border-bottom:2px solid #e2e8f0; padding-bottom:8px; }
.ql-editor h2 { font-size:20px; font-weight:700; color:#0f172a; border-bottom:1px solid #e2e8f0; padding-bottom:6px; margin-top:28px; }
.ql-editor h3 { font-size:16px; font-weight:600; color:#0f172a; }
.ql-editor blockquote { border-left:4px solid #6366f1; background:#f5f3ff; padding:12px 20px; border-radius:0 8px 8px 0; }
.ql-editor pre { background:#0f172a; color:#e2e8f0; border-radius:8px; }
.ql-editor a { color:#6366f1; }
#editor-loading {
  min-height:100px; background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:10px;
  display:flex; align-items:center; justify-content:center; gap:12px; color:#94a3b8; padding:20px;
}

/* ── Template Library ──────────────────────────── */
.template-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.tpl-btn {
  padding:12px; border:1.5px solid #e2e8f0; border-radius:10px;
  background:#fff; cursor:pointer; text-align:left; transition:all .15s;
  font-size:12px;
}
.tpl-btn:hover { border-color:#6366f1; background:#f5f3ff; }
.tpl-btn .tpl-icon { font-size:20px; display:block; margin-bottom:6px; }
.tpl-btn .tpl-name { font-weight:700; color:#0f172a; font-size:12px; }
.tpl-btn .tpl-desc { color:#94a3b8; font-size:11px; margin-top:2px; }

/* ── Toggle rows ───────────────────────────────── */
.tog-row { display:flex; align-items:center; justify-content:space-between; padding:10px 0; border-bottom:1px solid #f1f5f9; }
.tog-row:last-child { border-bottom:none; }
.tog-label { font-size:13px; font-weight:600; color:#374151; }
.tog-sub   { font-size:11px; color:#94a3b8; margin-top:2px; }

/* ── Save bar ──────────────────────────────────── */
.save-bar {
  position:sticky; bottom:0; z-index:40;
  background:rgba(255,255,255,.95); backdrop-filter:blur(12px);
  border-top:1px solid #e2e8f0; padding:14px 0;
  display:flex; align-items:center; gap:12px; flex-wrap:wrap;
}

/* ── Empty state ───────────────────────────────── */
.empty-state { text-align:center; padding:64px 24px; }
.empty-state .es-icon { font-size:56px; margin-bottom:16px; }
.empty-state h2 { font-size:20px; font-weight:700; color:#0f172a; margin-bottom:8px; }
.empty-state p  { color:#64748b; margin-bottom:24px; font-size:14px; }

/* ── Word count bar ────────────────────────────── */
.wc-bar { font-size:12px; color:#94a3b8; display:flex; gap:16px; margin-top:8px; }
.wc-bar span { font-weight:600; color:#6366f1; }
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
<div class="lp-shell">
<?php require_once __DIR__ . '/sidebar.php'; ?>
<div class="lp-main">

<!-- Topbar -->
<div class="lp-topbar">
  <h1>
    <?php if ($view === 'editor'): ?>
      <?= $edit_page ? '✏️ Edit Page' : '➕ New Page' ?>
    <?php else: ?>
      📄 Legal & Custom Pages
    <?php endif; ?>
  </h1>
  <div style="display:flex;gap:10px;">
    <?php if ($view === 'editor'): ?>
      <?php if ($edit_page): ?>
        <a href="<?= BASE_URL ?>/page/<?= htmlspecialchars($edit_page['slug']) ?>" target="_blank" class="btn btn-outline btn-sm">↗ View Live</a>
      <?php endif; ?>
      <a href="legal-pages.php" class="btn btn-outline btn-sm">← All Pages</a>
    <?php else: ?>
      <a href="legal-pages.php?new=1" class="btn btn-primary">+ New Page</a>
    <?php endif; ?>
  </div>
</div>

<div class="lp-content">

<?php if ($msg): ?><div class="alert-ok"><?= $msg ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert-err">❌ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- ══ LIST VIEW ════════════════════════════════ -->
<?php if ($view === 'list'): ?>

<?php if (!$pages): ?>
<div class="empty-state">
  <div class="es-icon">📄</div>
  <h2>No pages yet</h2>
  <p>Create your first legal page — Privacy Policy, Terms of Service, etc.</p>
  <a href="legal-pages.php?new=1" class="btn btn-primary btn-lg">+ Create First Page</a>
</div>

<?php else: ?>
<div class="pages-grid">
<?php foreach ($pages as $pg):
  $pg_icon = $icons[$pg['slug']] ?? '📄';
?>
<div class="page-card">
  <div class="pc-head">
    <div class="pc-icon"><?= $pg_icon ?></div>
    <div style="flex:1;min-width:0;">
      <div class="pc-title"><?= htmlspecialchars($pg['title']) ?></div>
      <div class="pc-slug">/page/<?= htmlspecialchars($pg['slug']) ?></div>
    </div>
  </div>

  <div class="pc-meta">
    <span class="pc-badge <?= $pg['is_published'] ? 'badge-pub' : 'badge-draft' ?>">
      <?= $pg['is_published'] ? '● Published' : '○ Draft' ?>
    </span>
    <?php if ($pg['show_in_footer']): ?>
    <span class="pc-badge badge-footer">Footer ✓</span>
    <?php endif; ?>
    <span style="font-size:11px;color:#94a3b8;margin-left:auto;"><?= date('d M Y', strtotime($pg['updated_at'])) ?></span>
  </div>

  <!-- Toggles -->
  <div style="display:flex;gap:16px;align-items:center;font-size:12px;color:#64748b;">
    <label style="display:flex;align-items:center;gap:7px;cursor:pointer;">
      <label class="tog">
        <input type="checkbox" <?= $pg['is_published'] ? 'checked' : '' ?>
               onchange="ajaxToggle(<?= $pg['id'] ?>,'toggle_publish',this)">
        <span class="sl"></span>
      </label>
      Published
    </label>
    <label style="display:flex;align-items:center;gap:7px;cursor:pointer;">
      <label class="tog">
        <input type="checkbox" <?= $pg['show_in_footer'] ? 'checked' : '' ?>
               onchange="ajaxToggle(<?= $pg['id'] ?>,'toggle_footer',this)">
        <span class="sl"></span>
      </label>
      Footer
    </label>
  </div>

  <div class="pc-actions">
    <a href="legal-pages.php?edit=<?= $pg['id'] ?>" class="btn btn-primary btn-sm">✏️ Edit</a>
    <a href="<?= BASE_URL ?>/page/<?= htmlspecialchars($pg['slug']) ?>" target="_blank" class="btn btn-outline btn-sm">↗ View</a>
    <button class="btn btn-red btn-sm" onclick="deletePage(<?= $pg['id'] ?>,'<?= htmlspecialchars(addslashes($pg['title'])) ?>')">🗑</button>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══ EDITOR VIEW ═══════════════════════════════ -->
<?php else: ?>

<form method="post" action="" id="lp-form">
<input type="hidden" name="csrf_token" value="<?= $csrf ?>">
<input type="hidden" name="action"   value="save_page">
<input type="hidden" name="page_id"  value="<?= (int)($edit_page['id'] ?? 0) ?>">
<!-- TinyMCE syncs to this textarea on submit -->
<textarea name="content" id="content-field" style="display:none;"><?= htmlspecialchars($edit_page['content'] ?? '') ?></textarea>

<div class="editor-wrap">

  <!-- LEFT: Title + Editor -->
  <div>

    <!-- Title + Slug -->
    <div class="card">
      <div class="field">
        <label>Page Title <span style="color:#ef4444">*</span></label>
        <input type="text" name="title" id="inp-title"
               value="<?= htmlspecialchars($edit_page['title'] ?? '') ?>"
               placeholder="e.g. Privacy Policy"
               oninput="autoSlug(this.value)" required>
      </div>
      <div class="field" style="margin-bottom:0;">
        <label>URL Slug <span style="color:#ef4444">*</span></label>
        <input type="text" name="slug" id="inp-slug"
               value="<?= htmlspecialchars($edit_page['slug'] ?? '') ?>"
               placeholder="e.g. privacy-policy"
               oninput="onSlugInput(this.value)">
        <div class="slug-live" id="slug-preview">
          <?= BASE_URL ?>/page/<strong id="slug-live-text"><?= htmlspecialchars($edit_page['slug'] ?? '') ?></strong>
        </div>
      </div>
    </div>

    <!-- TinyMCE Editor -->
    <div class="card">
      <h3>
        ✍️ Content Editor
        <div class="wc-bar" style="margin:0 0 0 auto;">
          Words: <span id="wc-words">0</span> &nbsp; Chars: <span id="wc-chars">0</span>
        </div>
      </h3>

      <!-- Quill editor container -->
      <div id="quill-editor"></div>
    </div>

    <!-- SEO Meta -->
    <div class="card">
      <h3>🔍 SEO Meta</h3>
      <div class="field">
        <label>Meta Title</label>
        <input type="text" name="meta_title"
               value="<?= htmlspecialchars($edit_page['meta_title'] ?? '') ?>"
               placeholder="Leave blank to auto-use page title">
        <div class="hint">Shown in browser tab and search results (50-60 chars ideal)</div>
      </div>
      <div class="field" style="margin-bottom:0;">
        <label>Meta Description</label>
        <textarea name="meta_desc" rows="3"
                  placeholder="Brief description for Google (150-160 chars)"><?= htmlspecialchars($edit_page['meta_desc'] ?? '') ?></textarea>
      </div>
    </div>

  </div><!-- /left -->

  <!-- RIGHT: Settings + Templates -->
  <div>

    <!-- Page settings -->
    <div class="card">
      <h3>⚙️ Page Settings</h3>

      <div class="tog-row">
        <div>
          <div class="tog-label">Published</div>
          <div class="tog-sub">Visible to public</div>
        </div>
        <label class="tog">
          <input type="checkbox" name="is_published" id="tog-pub" value="1"
                 <?= ($edit_page['is_published'] ?? 1) ? 'checked' : '' ?>>
          <span class="sl"></span>
        </label>
      </div>

      <div class="tog-row">
        <div>
          <div class="tog-label">Show in Footer</div>
          <div class="tog-sub">Link appears in site footer</div>
        </div>
        <label class="tog">
          <input type="checkbox" name="show_in_footer" value="1"
                 <?= ($edit_page['show_in_footer'] ?? 1) ? 'checked' : '' ?>>
          <span class="sl"></span>
        </label>
      </div>

      <div class="field" style="margin-top:14px;margin-bottom:0;">
        <label>Footer Sort Order</label>
        <input type="number" name="sort_order" value="<?= (int)($edit_page['sort_order'] ?? 0) ?>" min="0" max="999" style="width:100px;">
        <div class="hint">Lower number = appears first in footer</div>
      </div>
    </div>

    <!-- Template Library -->
    <div class="card">
      <h3>📚 Template Library</h3>
      <p style="font-size:12px;color:#94a3b8;margin-bottom:14px;">Click to load a pre-written template. <strong style="color:#ef4444;">Replaces current content.</strong></p>
      <div class="template-grid">
        <button type="button" class="tpl-btn" onclick="loadTemplate('privacy')">
          <span class="tpl-icon">🔐</span>
          <div class="tpl-name">Privacy Policy</div>
          <div class="tpl-desc">GDPR-ready template</div>
        </button>
        <button type="button" class="tpl-btn" onclick="loadTemplate('terms')">
          <span class="tpl-icon">📋</span>
          <div class="tpl-name">Terms of Service</div>
          <div class="tpl-desc">SaaS / hosting focused</div>
        </button>
        <button type="button" class="tpl-btn" onclick="loadTemplate('refund')">
          <span class="tpl-icon">💳</span>
          <div class="tpl-name">Refund Policy</div>
          <div class="tpl-desc">Cloud service refund</div>
        </button>
        <button type="button" class="tpl-btn" onclick="loadTemplate('cookie')">
          <span class="tpl-icon">🍪</span>
          <div class="tpl-name">Cookie Policy</div>
          <div class="tpl-desc">GDPR cookie notice</div>
        </button>
        <button type="button" class="tpl-btn" onclick="loadTemplate('aup')">
          <span class="tpl-icon">🛡️</span>
          <div class="tpl-name">Acceptable Use</div>
          <div class="tpl-desc">VPS/hosting AUP</div>
        </button>
        <button type="button" class="tpl-btn" onclick="loadTemplate('sla')">
          <span class="tpl-icon">📊</span>
          <div class="tpl-name">SLA / Uptime</div>
          <div class="tpl-desc">Service level agreement</div>
        </button>
      </div>
    </div>

    <!-- Quick actions -->
    <div class="card">
      <h3>⚡ Quick Actions</h3>
      <div style="display:flex;flex-direction:column;gap:8px;">
        <button type="submit" class="btn btn-primary btn-lg" style="width:100%;justify-content:center;">
          💾 Save Page
        </button>
        <?php if ($edit_page): ?>
        <a href="<?= BASE_URL ?>/page/<?= htmlspecialchars($edit_page['slug']) ?>" target="_blank"
           class="btn btn-outline" style="width:100%;justify-content:center;">↗ View Live Page</a>
        <?php endif; ?>
        <a href="legal-pages.php" class="btn btn-outline" style="width:100%;justify-content:center;">← Back to List</a>
      </div>
    </div>

  </div><!-- /right -->

</div><!-- .editor-wrap -->

<!-- Sticky save bar -->
<div class="save-bar">
  <button type="submit" class="btn btn-primary btn-lg">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
    Save Page
  </button>
  <?php if ($edit_page): ?>
  <a href="<?= BASE_URL ?>/page/<?= htmlspecialchars($edit_page['slug']) ?>" target="_blank" class="btn btn-outline">↗ View Live</a>
  <?php endif; ?>
  <span style="font-size:12px;color:#94a3b8;margin-left:4px;">Ctrl+S to save</span>
  <?php if ($edit_page): ?>
  <span style="font-size:12px;color:#94a3b8;margin-left:auto;">Last saved: <?= date('d M Y H:i', strtotime($edit_page['updated_at'])) ?></span>
  <?php endif; ?>
</div>

</form>
<?php endif; ?>

</div><!-- .lp-content -->
</div><!-- .lp-main -->
</div><!-- .lp-shell -->

<!-- Delete form -->
<form id="del-form" method="post" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <input type="hidden" name="action"   value="delete_page">
  <input type="hidden" name="page_id"  id="del-id">
</form>

<script>
const BASE_URL  = '<?= BASE_URL ?>';
const CSRF      = '<?= $csrf ?>';
let slugTouched = <?= ($edit_page && $edit_page['slug']) ? 'true' : 'false' ?>;
let quill;

// ── Quill init ────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  quill = new Quill('#quill-editor', {
    theme: 'snow',
    placeholder: 'Start writing your page content here…\n\nTip: Use the toolbar above for headings, lists, links, bold, etc.',
    modules: {
      toolbar: [
        [{ header: [1,2,3,false] }],
        ['bold','italic','underline','strike'],
        [{ color:[] },{ background:[] }],
        [{ list:'ordered' },{ list:'bullet' }],
        [{ indent:'-1' },{ indent:'+1' }],
        [{ align:[] }],
        ['link','blockquote','code-block'],
        ['clean']
      ]
    }
  });

  // Load existing content
  const existing = document.getElementById('content-field').value;
  if (existing) quill.clipboard.dangerouslyPasteHTML(existing);

  updateWordCount();
  quill.on('text-change', updateWordCount);
});

// ── Word count ────────────────────────────────────
function updateWordCount() {
  if (!quill) return;
  const text  = quill.getText().trim();
  const words = text ? text.split(/\s+/).length : 0;
  const chars = text.replace(/\s/g,'').length;
  document.getElementById('wc-words').textContent = words.toLocaleString();
  document.getElementById('wc-chars').textContent = chars.toLocaleString();
}

// ── Sync Quill → hidden textarea on submit ────────
document.getElementById('lp-form')?.addEventListener('submit', function() {
  if (quill) document.getElementById('content-field').value = quill.root.innerHTML;
});

function saveForm() {
  if (quill) document.getElementById('content-field').value = quill.root.innerHTML;
  document.getElementById('lp-form').submit();
}

// Ctrl+S / Cmd+S
document.addEventListener('keydown', function(e) {
  if ((e.ctrlKey || e.metaKey) && e.key === 's') {
    e.preventDefault();
    saveForm();
  }
});

// ── Slug ──────────────────────────────────────────
function autoSlug(title) {
  if (slugTouched) return;
  const s = title.toLowerCase().replace(/[^a-z0-9\s\-]/g,'').replace(/[\s\-]+/g,'-').replace(/^-|-$/g,'');
  document.getElementById('inp-slug').value = s;
  document.getElementById('slug-live-text').textContent = s;
}
function onSlugInput(val) {
  slugTouched = true;
  const s = val.toLowerCase().replace(/[^a-z0-9\-]/g,'').replace(/-+/g,'-');
  document.getElementById('inp-slug').value = s;
  document.getElementById('slug-live-text').textContent = s;
}

// ── AJAX toggle ───────────────────────────────────
async function ajaxToggle(id, action, cb) {
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', action);
  fd.append('page_id', id);
  try {
    const r = await fetch('', { method:'POST', body:fd });
    const d = await r.json();
    if (!d.ok) cb.checked = !cb.checked;
  } catch(e) { cb.checked = !cb.checked; }
}

// ── Delete ────────────────────────────────────────
function deletePage(id, title) {
  if (!confirm(`Delete "${title}"?\nThis CANNOT be undone.`)) return;
  document.getElementById('del-id').value = id;
  document.getElementById('del-form').submit();
}

// ── Template Library ──────────────────────────────
const TEMPLATES = {
  privacy: `<h1>Privacy Policy</h1>
<p><em>Last updated: 16 May 2026</em></p>
<p>This Privacy Policy explains how <strong><?= htmlspecialchars($app_name) ?></strong> ("we", "us", "our") collects, uses, and protects your personal information when you use our services at <strong><?= BASE_URL ?></strong>.</p>

<h2>1. Information We Collect</h2>
<p>We collect information you provide directly, including:</p>
<ul>
<li><strong>Account Information:</strong> Name, email address, and password when you register.</li>
<li><strong>Billing Information:</strong> Payment details processed securely via our payment partners.</li>
<li><strong>Usage Data:</strong> Server logs, IP addresses, and activity data for security and service improvement.</li>
<li><strong>Communications:</strong> Support tickets and emails you send us.</li>
</ul>

<h2>2. How We Use Your Information</h2>
<p>We use collected information to:</p>
<ul>
<li>Provide, maintain, and improve our cloud hosting services</li>
<li>Process transactions and send billing notifications</li>
<li>Send service-related communications and security alerts</li>
<li>Comply with legal obligations and protect against fraud</li>
</ul>

<h2>3. Data Security</h2>
<p>We implement industry-standard security measures including SSL encryption, secure data centers, and regular security audits to protect your personal information against unauthorized access, alteration, or destruction.</p>

<h2>4. Data Retention</h2>
<p>We retain your personal data for as long as your account is active or as needed to provide services. You may request deletion of your account and associated data by contacting support.</p>

<h2>5. Your Rights</h2>
<p>You have the right to access, correct, or delete your personal information. Contact us at <strong><?= get_setting('company_email','support@greathost.in') ?></strong> to exercise these rights.</p>

<h2>6. Contact Us</h2>
<p>For privacy-related questions, contact us at: <strong><?= get_setting('company_email','support@greathost.in') ?></strong></p>`,

  terms: `<h1>Terms of Service</h1>
<p><em>Last updated: 16 May 2026</em></p>
<p>By accessing or using <strong><?= htmlspecialchars($app_name) ?></strong> services, you agree to be bound by these Terms of Service.</p>

<h2>1. Account Responsibility</h2>
<p>You are responsible for maintaining the confidentiality of your account credentials and for all activities that occur under your account. Notify us immediately of any unauthorized access.</p>

<h2>2. Acceptable Use</h2>
<p>You agree NOT to use our services for:</p>
<ul>
<li>Illegal activities or content prohibited by applicable law</li>
<li>Sending spam, phishing, or unsolicited bulk communications</li>
<li>Mining cryptocurrency without prior written permission</li>
<li>Distributed Denial of Service (DDoS) attacks</li>
<li>Hosting malware, ransomware, or harmful software</li>
<li>Violating third-party intellectual property rights</li>
</ul>

<h2>3. Payment Terms</h2>
<p>All services are prepaid via wallet balance. Servers are billed hourly and will be suspended when your wallet balance reaches zero. We are not liable for data loss resulting from non-payment.</p>

<h2>4. Service Level</h2>
<p>We target 99.9% monthly uptime for our infrastructure. Scheduled maintenance windows are announced in advance. Credits may be issued for verified downtime exceeding our SLA.</p>

<h2>5. Termination</h2>
<p>We reserve the right to suspend or terminate accounts that violate these Terms, with or without prior notice for severe violations. Data is retained for 7 days post-termination for eligible recovery.</p>

<h2>6. Limitation of Liability</h2>
<p>Our liability is limited to the amount paid for services in the preceding 30 days. We are not liable for indirect, incidental, or consequential damages.</p>`,

  refund: `<h1>Refund Policy</h1>
<p><em>Last updated: 16 May 2026</em></p>

<h2>1. Wallet Deposits</h2>
<p>Wallet top-ups are <strong>non-refundable</strong> once consumed for services. Unused wallet balance may be refunded within 7 days of deposit by contacting support, subject to a processing fee.</p>

<h2>2. Service Charges</h2>
<p>Cloud server charges are billed hourly and are <strong>non-refundable</strong> once the service is provisioned and delivered, as computing resources have been allocated and consumed.</p>

<h2>3. Eligible Refund Cases</h2>
<ul>
<li>Service not delivered due to a technical error on our end</li>
<li>Duplicate payment charged due to a payment gateway error</li>
<li>New account refund request within 48 hours with zero server usage</li>
</ul>

<h2>4. Refund Process</h2>
<p>To request a refund, open a support ticket within the eligible window with your transaction ID and reason. Approved refunds are processed within 5-7 business days to the original payment method.</p>

<h2>5. Contact</h2>
<p>For refund requests: <strong><?= get_setting('company_email','support@greathost.in') ?></strong></p>`,

  cookie: `<h1>Cookie Policy</h1>
<p><em>Last updated: 16 May 2026</em></p>
<p>This Cookie Policy explains how <?= htmlspecialchars($app_name) ?> uses cookies and similar tracking technologies on our website.</p>

<h2>1. What Are Cookies?</h2>
<p>Cookies are small text files stored on your device when you visit our website. They help us remember your preferences, keep you logged in, and improve your overall experience.</p>

<h2>2. Types of Cookies We Use</h2>
<ul>
<li><strong>Essential Cookies:</strong> Required for authentication, security, and core site functionality. Cannot be disabled.</li>
<li><strong>Analytics Cookies:</strong> Help us understand site usage patterns (e.g., Google Analytics). Enabled only with your consent.</li>
<li><strong>Preference Cookies:</strong> Remember your settings like language, theme, and display preferences.</li>
<li><strong>Marketing Cookies:</strong> Used for targeted advertising. Disabled by default — requires explicit consent.</li>
</ul>

<h2>3. Managing Cookies</h2>
<p>You can manage cookie preferences via our cookie consent panel (shown on first visit) or through your browser settings. Disabling essential cookies may affect site functionality.</p>

<h2>4. Third-Party Cookies</h2>
<p>We use Google Analytics for website traffic analysis. Google's privacy policy applies to data collected by their cookies.</p>`,

  aup: `<h1>Acceptable Use Policy</h1>
<p><em>Last updated: 16 May 2026</em></p>
<p>This Acceptable Use Policy (AUP) governs the use of <?= htmlspecialchars($app_name) ?> cloud infrastructure services.</p>

<h2>1. Prohibited Activities</h2>
<p>The following activities are strictly prohibited on our platform:</p>
<ul>
<li>Hosting or distributing malware, ransomware, spyware, or viruses</li>
<li>Conducting or facilitating DDoS/DoS attacks against any target</li>
<li>Sending spam, phishing emails, or unsolicited bulk messages</li>
<li>Cryptocurrency mining without explicit written approval</li>
<li>Hosting illegal content including CSAM</li>
<li>Port scanning or network probing without authorization</li>
<li>Unauthorized access to third-party systems</li>
<li>Trademark or copyright infringement</li>
</ul>

<h2>2. Network Usage</h2>
<p>Fair use of bandwidth is expected. Sustained high-traffic usage that impacts other customers may result in throttling or suspension. DDoS mitigation is included but intentional attack traffic is prohibited.</p>

<h2>3. Enforcement</h2>
<p>Violations may result in immediate service suspension without refund. We cooperate with law enforcement for illegal activities.</p>

<h2>4. Reporting Abuse</h2>
<p>Report AUP violations to: <strong><?= get_setting('company_email','support@greathost.in') ?></strong></p>`,

  sla: `<h1>Service Level Agreement (SLA)</h1>
<p><em>Last updated: 16 May 2026</em></p>

<h2>1. Uptime Commitment</h2>
<p>We commit to <strong>99.9% monthly uptime</strong> for our cloud infrastructure, measured on a rolling 30-day basis, excluding scheduled maintenance.</p>

<h2>2. Scheduled Maintenance</h2>
<p>Scheduled maintenance windows are announced at least 24 hours in advance via email and dashboard notification. Emergency maintenance may occur without prior notice to address critical security issues.</p>

<h2>3. Incident Response</h2>
<ul>
<li><strong>Critical (service down):</strong> Response within 1 hour</li>
<li><strong>High (degraded performance):</strong> Response within 4 hours</li>
<li><strong>Medium (partial issues):</strong> Response within 24 hours</li>
<li><strong>Low (minor issues):</strong> Response within 72 hours</li>
</ul>

<h2>4. SLA Credits</h2>
<p>If uptime falls below 99.9% in a calendar month, eligible customers may receive service credits:</p>
<ul>
<li>99.0% – 99.9%: 10% credit of monthly charge</li>
<li>95.0% – 99.0%: 25% credit of monthly charge</li>
<li>Below 95.0%: 50% credit of monthly charge</li>
</ul>
<p>Credits are applied to wallet balance and must be claimed within 30 days of the incident.</p>

<h2>5. Exclusions</h2>
<p>SLA does not apply to outages caused by customer actions, third-party services, force majeure events, or scheduled maintenance windows.</p>`
};

function loadTemplate(key) {
  if (!TEMPLATES[key]) return;
  if (!confirm('Load template? This will replace your current content.')) return;
  if (quill) {
    quill.clipboard.dangerouslyPasteHTML(TEMPLATES[key]);
    updateWordCount();
  }
}
</script>
</body>
</html>