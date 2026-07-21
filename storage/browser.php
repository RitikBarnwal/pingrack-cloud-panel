<?php
/**
 * storage/browser.php — Advanced file browser (MinIO-backed)
 *
 * FIXED:  DB se list nahi hoti — MinIO se live listObjects() use hoti hai
 * ADDED:  Folder create karo (virtual .keep object)
 * ADDED:  Presigned URL (Share button — temp link)
 * ADDED:  Multi-select + bulk delete
 * ADDED:  Search/filter
 * ADDED:  Drag & drop upload with queue
 * ADDED:  Live refresh after upload/delete
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/storage.php';
require_login();

$user     = current_user();
$uid      = (int)$user['id'];
$app_name = APP_NAME;
$currency = strtoupper($user['currency'] ?? 'USD');
$curr_sym = user_currency_symbol($currency);
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$uname    = htmlspecialchars($user['username']);
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$balance  = (float)$user['wallet_balance'];
$csrf     = csrf_token();

$bid    = (int)($_GET['id'] ?? 0);
$bucket = storage_get_bucket($bid, $uid);
if (!$bucket) { header('Location: ' . BASE_URL . '/storage.php'); exit; }

$prefix = rtrim($_GET['prefix'] ?? '', '/');

// ── Fetch live from MinIO ─────────────────────────────────────
$minio_error = '';
$folders     = [];
$files       = [];
$total_size  = 0;

try {
    $minio   = storage_minio_for($bucket['region']);
    $objects = $minio->listObjects($bucket['name'], $prefix, 500);

    // Parse into folders + files (same as before but live)
    $pl = strlen($prefix) + ($prefix ? 1 : 0);
    $seen_folders = [];

    foreach ($objects as $obj) {
        $rel = substr($obj['key'], $pl);
        if ($rel === '' || $rel === '.keep') continue; // skip root .keep

        if (str_contains($rel, '/')) {
            $folder = explode('/', $rel)[0];
            if (!isset($seen_folders[$folder])) {
                $seen_folders[$folder] = true;
                $folders[] = $folder;
            }
        } else {
            // DB se id + etag lo (actions ke liye)
            $files[] = [
                'key'          => $obj['key'],
                'size_bytes'   => $obj['size_bytes'],
                'last_modified'=> $obj['last_modified'],
                'fname'        => basename($obj['key']),
            ];
            $total_size += $obj['size_bytes'];
        }
    }

    // DB objects se id match karo (delete action ke liye object_id chahiye)
    if (!empty($files)) {
        $keys = array_column($files, 'key');
        $ph   = implode(',', array_fill(0, count($keys), '?'));
        $st   = db()->prepare("SELECT id, object_key, etag FROM storage_objects WHERE bucket_id=? AND object_key IN ($ph)");
        $st->execute(array_merge([$bid], $keys));
        $db_map = [];
        foreach ($st->fetchAll() as $row) {
            $db_map[$row['object_key']] = $row;
        }
        foreach ($files as &$f) {
            $f['id']   = $db_map[$f['key']]['id'] ?? null;
            $f['etag'] = $db_map[$f['key']]['etag'] ?? '';
        }
        unset($f);
    }

} catch (Throwable $e) {
    $minio_error = $e->getMessage();
}

$crumbs = $prefix ? explode('/', $prefix) : [];

// ── File type → icon + color ──────────────────────────────────
function file_icon(string $fname): array {
    $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
    return match(true) {
        in_array($ext, ['jpg','jpeg','png','gif','webp','svg','avif']) => ['🖼️', '#0ea5e9'],
        in_array($ext, ['mp4','mov','avi','mkv','webm'])               => ['🎬', '#8b5cf6'],
        in_array($ext, ['mp3','wav','ogg','flac','aac'])               => ['🎵', '#ec4899'],
        in_array($ext, ['pdf'])                                         => ['📄', '#ef4444'],
        in_array($ext, ['zip','tar','gz','rar','7z'])                  => ['🗜️', '#f59e0b'],
        in_array($ext, ['js','ts','jsx','tsx','vue'])                  => ['⚡', '#eab308'],
        in_array($ext, ['py','rb','go','rs','java','php'])             => ['💻', '#22c55e'],
        in_array($ext, ['json','yaml','yml','toml','env','xml'])       => ['⚙️', '#6366f1'],
        in_array($ext, ['md','txt','log','csv'])                       => ['📝', '#64748b'],
        in_array($ext, ['html','css','scss'])                          => ['🌐', '#0284c7'],
        default                                                         => ['📃', '#94a3b8'],
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Browse — <?= htmlspecialchars($bucket['name']) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    /* ── Layout ── */
    .browser-wrap { display:flex; flex-direction:column; height:calc(100vh - 57px); }
    .browser-toolbar { padding:10px 16px; border-bottom:1px solid var(--gray-100); background:#fafbfd; display:flex; align-items:center; gap:8px; flex-wrap:wrap; flex-shrink:0; }
    .browser-body { flex:1; overflow-y:auto; position:relative; }

    /* ── Breadcrumbs ── */
    .bc { display:flex; align-items:center; gap:3px; font-size:12.5px; flex:1; min-width:0; overflow:hidden; }
    .bc-a { color:var(--primary); font-weight:700; text-decoration:none; white-space:nowrap; padding:2px 5px; border-radius:5px; }
    .bc-a:hover { background:#ede9fe; }
    .bc-sep { color:var(--gray-300); font-size:11px; }
    .bc-cur { color:var(--gray-800); font-weight:700; white-space:nowrap; }

    /* ── Toolbar buttons ── */
    .tb-btn { display:inline-flex; align-items:center; gap:5px; padding:6px 12px; border-radius:7px; font-size:12.5px; font-weight:700; font-family:inherit; cursor:pointer; border:1.5px solid; transition:all .13s; white-space:nowrap; }
    .tb-primary { background:var(--primary); border-color:var(--primary); color:white; }
    .tb-primary:hover { background:var(--primary-hover); }
    .tb-secondary { background:white; border-color:var(--border); color:var(--gray-700); }
    .tb-secondary:hover { background:var(--gray-50); }
    .tb-danger { background:#fef2f2; border-color:#fca5a5; color:#dc2626; }
    .tb-danger:hover { background:#fee2e2; }
    .tb-danger:disabled { opacity:.4; cursor:not-allowed; }

    /* ── Search ── */
    .tb-search { padding:6px 10px; border:1.5px solid var(--border); border-radius:7px; font-family:inherit; font-size:12.5px; outline:none; width:180px; transition:border .13s; }
    .tb-search:focus { border-color:var(--primary); }

    /* ── Drop zone ── */
    .drop-overlay { display:none; position:absolute; inset:0; background:rgba(79,70,229,.08); border:3px dashed #6366f1; border-radius:11px; z-index:10; align-items:center; justify-content:center; flex-direction:column; gap:10px; }
    .drop-overlay.show { display:flex; }
    .drop-overlay-txt { font-size:16px; font-weight:800; color:#4338ca; }

    /* ── Upload queue ── */
    .upload-queue { padding:10px 14px; border-bottom:1px solid var(--gray-100); display:none; background:white; }
    .up-item { display:flex; align-items:center; gap:9px; padding:7px 10px; background:var(--gray-50); border-radius:8px; margin-bottom:5px; font-size:12.5px; }
    .up-item:last-child { margin-bottom:0; }
    .up-fname { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-weight:600; }
    .up-bar-wrap { width:120px; height:4px; background:var(--gray-200); border-radius:99px; overflow:hidden; flex-shrink:0; }
    .up-bar { height:100%; background:linear-gradient(90deg,#6366f1,#4f46e5); border-radius:99px; transition:width .3s; }
    .up-status { font-size:11px; color:var(--gray-400); flex-shrink:0; min-width:34px; text-align:right; }
    .up-ok { color:#16a34a; font-weight:700; }
    .up-err { color:#dc2626; font-weight:700; }

    /* ── Empty state ── */
    .empty-state { padding:56px 20px; text-align:center; }
    .empty-icon { font-size:40px; margin-bottom:10px; }
    .empty-title { font-size:14px; font-weight:800; color:var(--gray-700); margin-bottom:4px; }
    .empty-sub { font-size:12.5px; color:var(--gray-400); }

    /* ── File table ── */
    .ftbl { width:100%; border-collapse:collapse; }
    .ftbl th { padding:8px 14px; text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:var(--gray-400); border-bottom:1px solid var(--gray-100); background:#fafbfd; white-space:nowrap; position:sticky; top:0; z-index:1; }
    .ftbl th.chk { width:36px; padding-left:14px; }
    .ftbl td { padding:9px 14px; border-bottom:1px solid var(--gray-50); font-size:13px; vertical-align:middle; }
    .ftbl tr:last-child td { border:none; }
    .ftbl tr:hover td { background:#fafbff; }
    .ftbl tr.selected td { background:#f5f3ff; }
    .ftbl tr.hidden-row { display:none; }

    /* ── Row elements ── */
    .row-name { display:flex; align-items:center; gap:9px; }
    .file-icon { font-size:18px; flex-shrink:0; line-height:1; }
    .fname-link { font-weight:700; color:var(--gray-900); cursor:pointer; }
    .fname-link:hover { color:var(--primary); }
    .folder-link { color:#0284c7; }
    .folder-link:hover { color:#0369a1; }
    .size-cell { font-family:'JetBrains Mono',monospace; font-size:12px; color:var(--gray-600); white-space:nowrap; }
    .type-badge { display:inline-block; padding:2px 7px; border-radius:5px; font-size:11px; font-weight:700; background:var(--gray-100); color:var(--gray-500); text-transform:uppercase; }
    .date-cell { font-size:12px; color:var(--gray-400); white-space:nowrap; }

    /* ── Action buttons (per row) ── */
    .row-acts { display:flex; gap:4px; opacity:0; transition:opacity .12s; }
    .ftbl tr:hover .row-acts { opacity:1; }
    .act-sm { padding:4px 9px; border-radius:6px; font-size:11.5px; font-weight:700; border:1.5px solid; cursor:pointer; font-family:inherit; transition:all .13s; }
    .act-link { border-color:var(--border); color:var(--gray-600); background:white; }
    .act-link:hover { background:var(--gray-50); }
    .act-share { border-color:#bae6fd; color:#0284c7; background:#f0f9ff; }
    .act-share:hover { background:#e0f2fe; }
    .act-del { border-color:#fca5a5; color:#dc2626; background:#fef2f2; }
    .act-del:hover { background:#fee2e2; }

    /* ── Modals ── */
    .modal-bg { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:900; align-items:center; justify-content:center; padding:16px; }
    .modal-bg.open { display:flex; }
    .modal-box { background:white; border-radius:14px; width:100%; max-width:400px; padding:22px; box-shadow:0 20px 60px rgba(0,0,0,.18); }
    .modal-title { font-size:15px; font-weight:800; margin-bottom:14px; }
    .modal-input { width:100%; padding:9px 12px; border:1.5px solid var(--border); border-radius:8px; font-family:inherit; font-size:13.5px; outline:none; box-sizing:border-box; }
    .modal-input:focus { border-color:var(--primary); }
    .modal-actions { display:flex; gap:8px; margin-top:16px; }
    .modal-hint { font-size:12px; color:var(--gray-400); margin-top:5px; }

    /* ── Share modal ── */
    .share-url { font-family:'JetBrains Mono',monospace; font-size:11.5px; background:var(--gray-50); border:1px solid var(--border); border-radius:7px; padding:8px 11px; word-break:break-all; color:var(--gray-700); margin-bottom:10px; line-height:1.6; }
    .expire-opts { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px; }
    .expire-btn { padding:5px 11px; border-radius:7px; font-size:12px; font-weight:700; border:1.5px solid var(--border); background:white; cursor:pointer; font-family:inherit; color:var(--gray-600); transition:all .12s; }
    .expire-btn.sel { background:#f5f3ff; border-color:#a5b4fc; color:#4338ca; }

    /* ── Toast ── */
    #toast { position:fixed; bottom:18px; right:18px; padding:10px 16px; background:#0f172a; color:white; border-radius:8px; font-size:13px; font-weight:700; z-index:9999; transform:translateY(60px); opacity:0; transition:all .3s; max-width:280px; }

    @media(max-width:640px) {
      .tb-search { width:120px; }
      .act-share { display:none; }
    }
  </style>
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>

  <div class="main-content">
    <!-- Mobile bar -->
    <div class="mobile-bar">
      <button class="ham-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <span style="font-weight:800;font-size:14px">Browse Files</span>
    </div>

    <!-- Topbar -->
    <div class="topbar">
      <a href="<?= BASE_URL ?>/storage/view.php?id=<?= $bid ?>" style="color:var(--gray-400);text-decoration:none;font-size:13px;white-space:nowrap">
        ← <?= htmlspecialchars($bucket['name']) ?>
      </a>
      <span style="color:var(--gray-300);margin:0 7px">/</span>
      <span class="topbar-title">File Browser</span>
      <span id="obj-count" style="margin-left:8px;font-size:12px;color:var(--gray-400)">
        <?= count($files) + count($folders) ?> items · <?= fmt_bytes($total_size) ?>
      </span>
    </div>

    <div class="browser-wrap">

      <!-- ── Toolbar ────────────────────────────────────────── -->
      <div class="browser-toolbar">
        <!-- Breadcrumbs -->
        <div class="bc">
          <a class="bc-a" href="?id=<?= $bid ?>">
            🪣 <?= htmlspecialchars($bucket['name']) ?>
          </a>
          <?php $built = ''; foreach ($crumbs as $ci => $crumb):
            $built .= ($built ? '/' : '') . $crumb; ?>
          <span class="bc-sep">›</span>
          <?php if ($ci < count($crumbs) - 1): ?>
            <a class="bc-a" href="?id=<?= $bid ?>&prefix=<?= urlencode($built) ?>"><?= htmlspecialchars($crumb) ?></a>
          <?php else: ?>
            <span class="bc-cur"><?= htmlspecialchars($crumb) ?></span>
          <?php endif; ?>
          <?php endforeach; ?>
        </div>

        <!-- Search -->
        <input class="tb-search" id="search-inp" placeholder="🔍 Filter files…" oninput="filterFiles(this.value)">

        <!-- New Folder -->
        <?php if ($bucket['status'] === 'active'): ?>
        <button class="tb-btn tb-secondary" onclick="openMkdir()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
          New Folder
        </button>

        <!-- Upload -->
        <label class="tb-btn tb-primary" style="cursor:pointer">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
          Upload
          <input type="file" multiple id="upload-input" style="display:none" onchange="queueUpload(this.files)">
        </label>
        <?php endif; ?>

        <!-- Bulk delete (hidden until selection) -->
        <button class="tb-btn tb-danger" id="bulk-del-btn" style="display:none" onclick="bulkDelete()" disabled>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
          Delete <span id="bulk-del-count"></span>
        </button>
      </div>

      <!-- ── Upload Queue ──────────────────────────────────── -->
      <div class="upload-queue" id="upload-queue"></div>

      <!-- ── Body (with drag overlay) ─────────────────────── -->
      <div class="browser-body" id="browser-body"
           ondragover="event.preventDefault();showDrop(true)"
           ondragleave="hideDrop(event)"
           ondrop="event.preventDefault();showDrop(false);queueUpload(event.dataTransfer.files)">

        <div class="drop-overlay" id="drop-overlay">
          <div style="font-size:40px">📤</div>
          <div class="drop-overlay-txt">Drop files to upload</div>
          <div style="font-size:13px;color:#6366f1">Release to start uploading</div>
        </div>

        <?php if ($minio_error): ?>
        <div style="margin:20px;background:#fef2f2;border:1.5px solid #fca5a5;border-radius:10px;padding:14px 18px;font-size:13px;color:#dc2626">
          <strong>MinIO connection error:</strong> <?= htmlspecialchars($minio_error) ?>
          <div style="margin-top:6px;font-size:12px;color:#9b1c1c">Check Admin → Storage → Regions for correct endpoint and credentials.</div>
        </div>

        <?php elseif (empty($folders) && empty($files)): ?>
        <div class="empty-state">
          <div class="empty-icon">📂</div>
          <div class="empty-title">This folder is empty</div>
          <div class="empty-sub">Drag & drop files here, or click Upload above</div>
        </div>

        <?php else: ?>
        <div style="overflow-x:auto">
          <table class="ftbl" id="file-table">
            <thead>
              <tr>
                <th class="chk"><input type="checkbox" id="select-all" onchange="toggleAll(this)"></th>
                <th>Name</th>
                <th>Size</th>
                <th>Type</th>
                <th>Modified</th>
                <th style="width:140px">Actions</th>
              </tr>
            </thead>
            <tbody id="ftbl-body">

              <!-- Folders -->
              <?php foreach ($folders as $folder):
                $folder_prefix = ($prefix ? $prefix . '/' : '') . $folder;
              ?>
              <tr class="folder-row" data-name="<?= htmlspecialchars(strtolower($folder)) ?>">
                <td><input type="checkbox" class="row-chk" data-type="folder" data-prefix="<?= htmlspecialchars($folder_prefix) ?>"></td>
                <td>
                  <div class="row-name">
                    <span class="file-icon">📁</span>
                    <a class="fname-link folder-link" href="?id=<?= $bid ?>&prefix=<?= urlencode($folder_prefix) ?>">
                      <?= htmlspecialchars($folder) ?>/
                    </a>
                  </div>
                </td>
                <td class="size-cell" style="color:var(--gray-300)">—</td>
                <td><span class="type-badge">Folder</span></td>
                <td class="date-cell">—</td>
                <td>
                  <div class="row-acts">
                    <button class="act-sm act-del" onclick="deleteFolder('<?= addslashes($folder_prefix) ?>', '<?= addslashes($folder) ?>')">Del</button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>

              <!-- Files -->
              <?php foreach ($files as $obj):
                [$icon, $icon_color] = file_icon($obj['fname']);
                $ext   = strtolower(pathinfo($obj['fname'], PATHINFO_EXTENSION));
                $dt    = date('d M Y, H:i', strtotime($obj['last_modified']));
                $is_img = in_array($ext, ['jpg','jpeg','png','gif','webp','svg']);
                $pub_url = storage_endpoint($bucket['name'], $bucket['region']) . '/' . $obj['key'];
              ?>
              <tr class="file-row" data-name="<?= htmlspecialchars(strtolower($obj['fname'])) ?>">
                <td>
                  <?php if ($obj['id']): ?>
                  <input type="checkbox" class="row-chk" data-type="file" data-id="<?= $obj['id'] ?>" data-name="<?= htmlspecialchars($obj['fname']) ?>">
                  <?php endif; ?>
                </td>
                <td>
                  <div class="row-name">
                    <span class="file-icon"><?= $icon ?></span>
                    <span class="fname-link" onclick="<?= $is_img ? "previewImg('" . addslashes($pub_url) . "','" . addslashes($obj['fname']) . "')" : "window.open('" . addslashes($pub_url) . "','_blank')" ?>">
                      <?= htmlspecialchars($obj['fname']) ?>
                    </span>
                  </div>
                </td>
                <td class="size-cell"><?= fmt_bytes((float)$obj['size_bytes']) ?></td>
                <td><span class="type-badge"><?= htmlspecialchars($ext ?: '—') ?></span></td>
                <td class="date-cell"><?= $dt ?></td>
                <td>
                  <div class="row-acts">
                    <button class="act-sm act-link" onclick="copyUrl('<?= addslashes($pub_url) ?>')">Link</button>
                    <?php if ($obj['id']): ?>
                    <button class="act-sm act-share" onclick="openShare(<?= $obj['id'] ?>, '<?= addslashes($obj['fname']) ?>')">Share</button>
                    <?php if ($bucket['status'] === 'active'): ?>
                    <button class="act-sm act-del" onclick="deleteFile(<?= $obj['id'] ?>, '<?= addslashes($obj['fname']) ?>')">Del</button>
                    <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>

            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div><!-- /browser-body -->
    </div><!-- /browser-wrap -->
  </div><!-- /main-content -->
</div><!-- /app-shell -->

<!-- ── New Folder Modal ─────────────────────────────────────── -->
<div class="modal-bg" id="mkdir-modal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal-box">
    <div class="modal-title">📁 New Folder</div>
    <input class="modal-input" id="mkdir-inp" placeholder="Folder name" maxlength="80"
           onkeydown="if(event.key==='Enter')confirmMkdir()">
    <div class="modal-hint">Letters, numbers, hyphens, underscores, spaces allowed.</div>
    <div class="modal-actions">
      <button class="tb-btn tb-primary" style="flex:1;justify-content:center" onclick="confirmMkdir()">Create Folder</button>
      <button class="tb-btn tb-secondary" onclick="document.getElementById('mkdir-modal').classList.remove('open')">Cancel</button>
    </div>
  </div>
</div>

<!-- ── Share Modal ──────────────────────────────────────────── -->
<div class="modal-bg" id="share-modal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal-box">
    <div class="modal-title">🔗 Share File</div>
    <div style="font-size:13px;color:var(--gray-600);margin-bottom:12px">Generate a temporary download link:</div>
    <div class="expire-opts">
      <button class="expire-btn sel" data-secs="3600" onclick="setExpiry(this,3600)">1 hour</button>
      <button class="expire-btn" data-secs="86400" onclick="setExpiry(this,86400)">1 day</button>
      <button class="expire-btn" data-secs="259200" onclick="setExpiry(this,259200)">3 days</button>
      <button class="expire-btn" data-secs="604800" onclick="setExpiry(this,604800)">7 days</button>
    </div>
    <div id="share-url-box" style="display:none">
      <div class="share-url" id="share-url-txt"></div>
      <button class="tb-btn tb-secondary" style="width:100%;justify-content:center" onclick="copyShareUrl()">
        📋 Copy Link
      </button>
    </div>
    <div id="share-loading" style="text-align:center;padding:12px;font-size:13px;color:var(--gray-400);display:none">Generating link…</div>
    <button class="tb-btn tb-primary" style="width:100%;justify-content:center;margin-top:10px" onclick="generateShare()">
      Generate Link
    </button>
  </div>
</div>

<!-- ── Image Preview Modal ──────────────────────────────────── -->
<div class="modal-bg" id="preview-modal" onclick="this.classList.remove('open')" style="background:rgba(0,0,0,.85)">
  <div style="max-width:90vw;max-height:90vh;text-align:center">
    <img id="preview-img" style="max-width:100%;max-height:85vh;border-radius:8px;box-shadow:0 20px 80px rgba(0,0,0,.5)" src="">
    <div id="preview-name" style="margin-top:10px;font-size:13px;color:rgba(255,255,255,.7);font-weight:600"></div>
  </div>
</div>

<!-- ── Toast ──────────────────────────────────────────────────── -->
<div id="toast"></div>

<script>
var BID    = <?= $bid ?>;
var PREFIX = '<?= addslashes($prefix) ?>';
var CSRF   = '<?= $csrf ?>';
var BASE   = '<?= BASE_URL ?>';
var ACTIVE = <?= $bucket['status'] === 'active' ? 'true' : 'false' ?>;

// ── Toast ─────────────────────────────────────────────────────
function toast(msg, ok) {
  var t = document.getElementById('toast');
  t.textContent = msg;
  t.style.background = ok === false ? '#dc2626' : ok === 'warn' ? '#d97706' : '#0f172a';
  t.style.transform = 'translateY(0)'; t.style.opacity = '1';
  clearTimeout(t._tid);
  t._tid = setTimeout(function(){ t.style.transform='translateY(60px)'; t.style.opacity='0'; }, 3500);
}

function copyUrl(url) {
  navigator.clipboard.writeText(url).then(function(){ toast('✓ Link copied'); });
}

// ── Search/filter ─────────────────────────────────────────────
function filterFiles(q) {
  q = q.toLowerCase().trim();
  document.querySelectorAll('.file-row, .folder-row').forEach(function(row) {
    var name = row.dataset.name || '';
    row.classList.toggle('hidden-row', q && !name.includes(q));
  });
}

// ── Multi-select ──────────────────────────────────────────────
function toggleAll(cb) {
  document.querySelectorAll('.row-chk').forEach(function(c){ c.checked = cb.checked; });
  updateBulkBar();
}
document.addEventListener('change', function(e) {
  if (e.target.classList.contains('row-chk')) updateBulkBar();
});
function updateBulkBar() {
  var checked = getChecked();
  var btn = document.getElementById('bulk-del-btn');
  if (!btn) return;
  if (checked.length > 0) {
    btn.style.display = 'inline-flex';
    btn.disabled = false;
    document.getElementById('bulk-del-count').textContent = '(' + checked.length + ')';
  } else {
    btn.style.display = 'none';
  }
}
function getChecked() {
  return Array.from(document.querySelectorAll('.row-chk:checked'));
}

// ── Upload ────────────────────────────────────────────────────
function queueUpload(files) {
  if (!ACTIVE) { toast('Bucket suspended', false); return; }
  var queue = document.getElementById('upload-queue');
  queue.style.display = 'block';
  Array.from(files).forEach(function(file) {
    var key   = (PREFIX ? PREFIX + '/' : '') + file.name;
    var rowId = 'upr-' + Math.random().toString(36).slice(2);
    var row   = document.createElement('div');
    row.className = 'up-item'; row.id = rowId;
    row.innerHTML =
      '<span class="up-fname" title="' + file.name + '">' + file.name + '</span>' +
      '<div class="up-bar-wrap"><div class="up-bar" style="width:0%"></div></div>' +
      '<span class="up-status">0%</span>';
    queue.appendChild(row);

    var fd = new FormData();
    fd.append('file', file); fd.append('key', key);
    fd.append('csrf', CSRF); fd.append('bucket_id', BID);

    var xhr = new XMLHttpRequest();
    xhr.upload.onprogress = function(e) {
      if (!e.lengthComputable) return;
      var pct = Math.round(e.loaded / e.total * 100);
      var el = document.getElementById(rowId);
      if (el) { el.querySelector('.up-bar').style.width = pct + '%'; el.querySelector('.up-status').textContent = pct + '%'; }
    };
    xhr.onload = function() {
      var res = {};
      try { res = JSON.parse(xhr.responseText); } catch(e) {}
      var el = document.getElementById(rowId);
      if (res.ok) {
        if (el) { el.querySelector('.up-bar').style.background='#22c55e'; el.querySelector('.up-status').innerHTML='<span class="up-ok">✓</span>'; }
        toast('✓ ' + file.name + ' uploaded');
        setTimeout(function(){ location.reload(); }, 1200);
      } else {
        if (el) { el.querySelector('.up-bar').style.background='#ef4444'; el.querySelector('.up-status').innerHTML='<span class="up-err">✗</span>'; }
        toast('✗ ' + (res.error || 'Upload failed: ' + file.name), false);
      }
    };
    xhr.onerror = function() { toast('✗ Network error during upload', false); };
    xhr.open('POST', BASE + '/api/storage-upload.php'); xhr.send(fd);
  });
}

// ── Drag overlay ──────────────────────────────────────────────
var _dropTimer;
function showDrop(show) {
  clearTimeout(_dropTimer);
  document.getElementById('drop-overlay').classList.toggle('show', show);
}
function hideDrop(e) {
  // Only hide if truly leaving the body
  var body = document.getElementById('browser-body');
  if (!body.contains(e.relatedTarget)) {
    _dropTimer = setTimeout(function(){ document.getElementById('drop-overlay').classList.remove('show'); }, 80);
  }
}

// ── Delete single file ────────────────────────────────────────
function deleteFile(id, name) {
  if (!confirm('Delete "' + name + '"?')) return;
  doAction({ action:'delete', object_id:id, bucket_id:BID, csrf:CSRF }, function(d) {
    if (d.ok) { toast('✓ Deleted'); setTimeout(function(){ location.reload(); }, 600); }
    else toast('✗ ' + (d.error || 'Delete failed'), false);
  });
}

// ── Delete folder ─────────────────────────────────────────────
function deleteFolder(prefix, name) {
  if (!confirm('Delete folder "' + name + '" and all its contents?')) return;
  doAction({ action:'delete_folder', prefix:prefix, bucket_id:BID, csrf:CSRF }, function(d) {
    if (d.ok) { toast('✓ Folder deleted (' + (d.deleted||0) + ' files)'); setTimeout(function(){ location.reload(); }, 600); }
    else toast('✗ ' + (d.error || 'Delete failed'), false);
  });
}

// ── Bulk delete ───────────────────────────────────────────────
function bulkDelete() {
  var checked = getChecked();
  if (!checked.length) return;
  if (!confirm('Delete ' + checked.length + ' selected item(s)?')) return;
  var pending = checked.length, failed = 0;
  checked.forEach(function(cb) {
    var type = cb.dataset.type;
    var payload = { bucket_id:BID, csrf:CSRF };
    if (type === 'file') { payload.action = 'delete'; payload.object_id = parseInt(cb.dataset.id); }
    else { payload.action = 'delete_folder'; payload.prefix = cb.dataset.prefix; }
    doAction(payload, function(d) {
      if (!d.ok) failed++;
      pending--;
      if (pending === 0) {
        toast(failed ? '⚠ ' + failed + ' failed' : '✓ Deleted', failed ? 'warn' : true);
        setTimeout(function(){ location.reload(); }, 700);
      }
    });
  });
}

// ── New Folder ────────────────────────────────────────────────
function openMkdir() {
  document.getElementById('mkdir-inp').value = '';
  document.getElementById('mkdir-modal').classList.add('open');
  setTimeout(function(){ document.getElementById('mkdir-inp').focus(); }, 80);
}
function confirmMkdir() {
  var name = document.getElementById('mkdir-inp').value.trim();
  if (!name) return;
  doAction({ action:'mkdir', folder_name:name, prefix:PREFIX, bucket_id:BID, csrf:CSRF }, function(d) {
    document.getElementById('mkdir-modal').classList.remove('open');
    if (d.ok) { toast('✓ Folder "' + name + '" created'); setTimeout(function(){ location.reload(); }, 600); }
    else toast('✗ ' + (d.error || 'Create failed'), false);
  });
}

// ── Share (presigned URL) ─────────────────────────────────────
var _shareObjId = null, _shareExpiry = 3600;
function openShare(id, name) {
  _shareObjId = id;
  _shareExpiry = 3600;
  document.querySelectorAll('.expire-btn').forEach(function(b){ b.classList.remove('sel'); });
  document.querySelector('.expire-btn[data-secs="3600"]').classList.add('sel');
  document.getElementById('share-url-box').style.display = 'none';
  document.getElementById('share-loading').style.display = 'none';
  document.getElementById('share-modal').classList.add('open');
}
function setExpiry(btn, secs) {
  _shareExpiry = secs;
  document.querySelectorAll('.expire-btn').forEach(function(b){ b.classList.remove('sel'); });
  btn.classList.add('sel');
}
function generateShare() {
  if (!_shareObjId) return;
  document.getElementById('share-url-box').style.display = 'none';
  document.getElementById('share-loading').style.display = 'block';
  doAction({ action:'presign', object_id:_shareObjId, expires:_shareExpiry, bucket_id:BID, csrf:CSRF }, function(d) {
    document.getElementById('share-loading').style.display = 'none';
    if (d.ok) {
      document.getElementById('share-url-txt').textContent = d.url;
      document.getElementById('share-url-box').style.display = 'block';
    } else {
      toast('✗ ' + (d.error || 'Could not generate link'), false);
    }
  });
}
function copyShareUrl() {
  var url = document.getElementById('share-url-txt').textContent;
  navigator.clipboard.writeText(url).then(function(){
    toast('✓ Link copied');
    document.getElementById('share-modal').classList.remove('open');
  });
}

// ── Image preview ─────────────────────────────────────────────
function previewImg(url, name) {
  document.getElementById('preview-img').src = url;
  document.getElementById('preview-name').textContent = name;
  document.getElementById('preview-modal').classList.add('open');
}

// ── Generic action helper ─────────────────────────────────────
function doAction(payload, cb) {
  fetch(BASE + '/api/storage-action.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  }).then(function(r){ return r.json(); }).then(cb).catch(function(){
    cb({ ok:false, error:'Network error' });
  });
}

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('open'); }
</script>
</body>
</html>