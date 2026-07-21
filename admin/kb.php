<?php
// admin/kb.php — Knowledge Base Management
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin.php';
require_admin();

$csrf     = csrf_token();
$app_name = APP_NAME;
$tab      = $_GET['tab'] ?? 'articles';

// ── Load data ──────────────────────────────────────────────────
$categories = db()->query("SELECT *, (SELECT COUNT(*) FROM kb_articles WHERE category_id=kb_categories.id AND is_active=1) art_count FROM kb_categories ORDER BY sort_order,id")->fetchAll();
$cats_map   = array_column($categories, null, 'id');

$articles = db()->query(
    "SELECT a.*, c.name cat_name FROM kb_articles a
     LEFT JOIN kb_categories c ON c.id=a.category_id
     ORDER BY a.updated_at DESC"
)->fetchAll();

$total_arts      = count($articles);
$total_published = count(array_filter($articles, fn($a)=>$a['is_active']));
$total_views     = array_sum(array_column($articles, 'views'));
$total_cats      = count($categories);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Knowledge Base — Admin — <?= $app_name ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/admin/admin.css">
  <!-- Quill rich text editor -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.snow.min.css" rel="stylesheet">
  <style>
    body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--gray-50);color:var(--gray-900);margin:0}
    .pw{max-width:1300px;margin:0 auto;padding:28px 24px}
    .ph h1{font-size:20px;font-weight:800;margin:0 0 4px}
    .ph p{color:var(--gray-500);font-size:13px;margin:0 0 24px}

    .stats-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:24px}
    .sc{background:white;border:1px solid var(--border);border-radius:12px;padding:14px 16px}
    .sc .lbl{font-size:11px;font-weight:700;color:var(--gray-400);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
    .sc .val{font-size:22px;font-weight:900}

    .tab-bar{display:flex;gap:4px;background:var(--gray-100);border-radius:10px;padding:4px;width:fit-content;margin-bottom:22px}
    .tb{padding:7px 18px;border-radius:7px;font-size:13px;font-weight:600;color:var(--gray-500);text-decoration:none;transition:.15s}
    .tb.active{background:white;color:var(--gray-900);box-shadow:0 1px 4px rgba(0,0,0,.08)}

    .tbl-wrap{background:white;border:1px solid var(--border);border-radius:13px;overflow:hidden}
    .tbl{width:100%;border-collapse:collapse;font-size:13px}
    .tbl thead th{padding:10px 14px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gray-400);background:var(--gray-50);border-bottom:1px solid var(--border)}
    .tbl tbody tr{border-bottom:1px solid var(--gray-100);transition:background .1s}
    .tbl tbody tr:last-child{border:none}
    .tbl tbody tr:hover{background:var(--gray-50)}
    .tbl td{padding:11px 14px;vertical-align:middle}

    .btn{display:inline-flex;align-items:center;gap:5px;padding:6px 13px;border-radius:7px;font-size:12.5px;font-weight:700;cursor:pointer;border:none;font-family:inherit;transition:all .14s;text-decoration:none}
    .btn-primary{background:var(--primary);color:white}.btn-primary:hover{background:var(--primary-hover)}
    .btn-ghost{background:white;color:var(--gray-700);border:1px solid var(--border)}.btn-ghost:hover{background:var(--gray-50)}
    .btn-success{background:#16a34a;color:white}.btn-success:hover{background:#15803d}
    .btn-danger{background:#dc2626;color:white}.btn-danger:hover{background:#b91c1c}
    .btn-warn{background:#d97706;color:white}.btn-warn:hover{background:#b45309}

    /* Article editor modal — fullscreen feel */
    .modal-bd{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto}
    .modal-bd.open{display:flex}
    .modal-box{background:white;border-radius:14px;width:100%;max-width:920px;box-shadow:0 24px 60px rgba(0,0,0,.18);margin:auto}
    .mh{padding:16px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;border-radius:14px 14px 0 0;position:sticky;top:0;background:white;z-index:10}
    .mh-title{font-size:15px;font-weight:800}
    .mc{background:none;border:none;color:var(--gray-400);cursor:pointer;font-size:18px;padding:3px 7px;border-radius:5px}.mc:hover{background:var(--gray-100)}
    .mb{padding:22px}
    .mf{padding:14px 22px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end;border-radius:0 0 14px 14px;background:white}

    .fg{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
    .fg.full{grid-template-columns:1fr}
    .fg.three{grid-template-columns:1fr 1fr 1fr}
    .flbl{display:block;font-size:11.5px;font-weight:700;color:var(--gray-600);margin-bottom:5px;text-transform:uppercase;letter-spacing:.4px}
    .flbl span{font-weight:400;text-transform:none;letter-spacing:0;color:var(--gray-400)}
    .finp{width:100%;box-sizing:border-box;padding:9px 12px;background:white;border:1.5px solid var(--border);border-radius:8px;color:var(--gray-900);font-size:13px;font-family:inherit}
    .finp:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(103,61,230,.1)}
    textarea.finp{resize:vertical;font-size:13px}
    .fnote{font-size:11.5px;color:var(--gray-400);margin-top:4px}

    /* SEO section box */
    .seo-box{border:1.5px solid var(--border);border-radius:10px;padding:16px;background:var(--gray-50);margin-bottom:4px}
    .seo-box-lbl{font-size:11px;font-weight:800;color:#0891b2;text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px;display:flex;align-items:center;gap:6px}
    .seo-preview{background:white;border:1px solid var(--border);border-radius:8px;padding:14px;margin-top:12px}
    .seo-prev-url{font-size:12px;color:#188038;margin-bottom:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .seo-prev-title{font-size:15px;color:#1a0dab;font-weight:500;margin-bottom:4px;line-height:1.3}
    .seo-prev-desc{font-size:13px;color:#4d5156;line-height:1.5}

    /* Quill editor */
    .ql-container{font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;min-height:280px;border-radius:0 0 8px 8px}
    .ql-toolbar{border-radius:8px 8px 0 0;border-color:var(--border)!important;background:var(--gray-50)}
    .ql-container{border-color:var(--border)!important}
    .ql-editor{min-height:280px;line-height:1.75}
    .ql-editor h1{font-size:22px;font-weight:800;margin-bottom:12px}
    .ql-editor h2{font-size:18px;font-weight:700;margin:20px 0 8px}
    .ql-editor h3{font-size:15px;font-weight:700;margin:16px 0 6px}
    .ql-editor p{margin-bottom:10px}
    .ql-editor pre{background:var(--gray-50);border:1px solid var(--border);border-radius:6px;padding:12px;font-family:'JetBrains Mono',monospace;font-size:12.5px}

    /* Category cards */
    .cats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px}
    .cat-card{background:white;border:1.5px solid var(--border);border-radius:12px;padding:18px;position:relative;transition:border-color .15s}
    .cat-card:hover{border-color:var(--gray-300)}
    .cat-left-bar{position:absolute;left:0;top:16px;bottom:16px;width:3px;border-radius:0 3px 3px 0}
    .cat-icon{font-size:22px;margin-bottom:8px}
    .cat-name{font-size:14px;font-weight:800;color:var(--gray-900);margin-bottom:3px}
    .cat-desc{font-size:12px;color:var(--gray-500);margin-bottom:10px;line-height:1.5}
    .cat-count{font-size:12px;font-weight:700;padding:2px 9px;border-radius:99px;background:var(--gray-100);color:var(--gray-600)}

    /* char counters */
    .char-counter{font-size:11px;color:var(--gray-400);text-align:right;margin-top:3px}
    .char-counter.warn{color:#d97706}
    .char-counter.over{color:#dc2626}

    /* publish badge */
    .pub-badge{font-size:10px;font-weight:700;padding:2px 9px;border-radius:99px}
  
    @media(max-width:960px){
      .adm-main{margin-left:0 !important}
      .adm-topbar{display:none !important}
      .adm-mobile-bar{display:flex !important}
      .tbl-wrap,table{overflow-x:auto;-webkit-overflow-scrolling:touch}
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
<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="adm-main">
<div class="pw">

  <div class="ph">
    <h1>📚 Knowledge Base</h1>
    <p>Articles, categories and SEO management</p>
  </div>

  <!-- Stats -->
  <div class="stats-row">
    <div class="sc"><div class="lbl">Categories</div><div class="val" style="color:var(--primary)"><?= $total_cats ?></div></div>
    <div class="sc"><div class="lbl">Total Articles</div><div class="val"><?= $total_arts ?></div></div>
    <div class="sc" style="border-color:#86efac"><div class="lbl">Published</div><div class="val" style="color:#16a34a"><?= $total_published ?></div></div>
    <div class="sc" style="border-color:var(--primary)"><div class="lbl">Total Views</div><div class="val" style="color:var(--primary)"><?= number_format($total_views) ?></div></div>
  </div>

  <!-- Tabs -->
  <div class="tab-bar">
    <a href="?tab=articles"   class="tb <?= $tab==='articles'  ?'active':'' ?>">Articles (<?= $total_arts ?>)</a>
    <a href="?tab=categories" class="tb <?= $tab==='categories'?'active':'' ?>">Categories (<?= $total_cats ?>)</a>
  </div>

  <!-- ════ ARTICLES ════ -->
  <?php if ($tab === 'articles'): ?>

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <select onchange="filterArticles(this.value,'cat')" class="finp" style="width:auto;padding:6px 12px">
        <option value="">All Categories</option>
        <?php foreach ($categories as $c): ?>
        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= $c['art_count'] ?>)</option>
        <?php endforeach; ?>
      </select>
      <select onchange="filterArticles(this.value,'status')" class="finp" style="width:auto;padding:6px 12px">
        <option value="">All Status</option>
        <option value="1">Published</option>
        <option value="0">Draft</option>
      </select>
    </div>
    <button onclick="openArticleModal(null)" class="btn btn-primary">+ New Article</button>
  </div>

  <div class="tbl-wrap">
    <table class="tbl" id="articlesTable">
      <thead><tr>
        <th>Title</th><th>Category</th><th>Views</th><th>Helpful</th><th>Status</th><th>Updated</th><th>Actions</th>
      </tr></thead>
      <tbody>
        <?php if (empty($articles)): ?>
        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--gray-400)">No articles yet. Create your first one!</td></tr>
        <?php else: foreach ($articles as $a): ?>
        <tr data-cat="<?= $a['category_id'] ?>" data-status="<?= $a['is_active'] ?>">
          <td>
            <div style="font-weight:700;color:var(--gray-900);margin-bottom:2px"><?= htmlspecialchars($a['title']) ?></div>
            <?php if ($a['is_featured']): ?><span style="background:#ede9fe;color:#6d28d9;font-size:10px;font-weight:700;padding:1px 7px;border-radius:99px">★ Featured</span><?php endif; ?>
            <div style="font-size:11px;color:var(--gray-400);margin-top:2px;font-family:'JetBrains Mono',monospace">/kb/<?= htmlspecialchars($a['slug']) ?></div>
          </td>
          <td>
            <span style="font-size:12px;font-weight:600;color:var(--gray-600)"><?= $cats_map[$a['category_id']]['icon'] ?? '📁' ?> <?= htmlspecialchars($a['cat_name'] ?? '—') ?></span>
          </td>
          <td style="font-weight:700;color:var(--gray-700)"><?= number_format($a['views']) ?></td>
          <td>
            <span style="font-size:12px;color:#16a34a;font-weight:600">👍 <?= $a['helpful_yes'] ?></span>
            <span style="font-size:12px;color:#dc2626;font-weight:600;margin-left:6px">👎 <?= $a['helpful_no'] ?></span>
          </td>
          <td>
            <?php if ($a['is_active']): ?>
              <span class="pub-badge" style="background:#dcfce7;color:#166534">● Published</span>
            <?php else: ?>
              <span class="pub-badge" style="background:var(--gray-100);color:var(--gray-500)">○ Draft</span>
            <?php endif; ?>
          </td>
          <td style="font-size:11.5px;color:var(--gray-400)"><?= date('d M Y', strtotime($a['updated_at'])) ?></td>
          <td>
            <div style="display:flex;gap:5px;flex-wrap:wrap">
              <a href="<?= BASE_URL ?>/kb/<?= htmlspecialchars($a['slug']) ?>" target="_blank" class="btn btn-ghost" title="Preview">👁</a>
              <button onclick='openArticleModal(<?= htmlspecialchars(json_encode($a)) ?>)' class="btn btn-primary">Edit</button>
              <button onclick="toggleArticle(<?= $a['id'] ?>,<?= $a['is_active']?0:1 ?>)" class="btn <?= $a['is_active']?'btn-warn':'btn-success' ?>"><?= $a['is_active']?'Unpublish':'Publish' ?></button>
              <button onclick="deleteArticle(<?= $a['id'] ?>,'<?= addslashes(htmlspecialchars($a['title'])) ?>')" class="btn btn-danger">Del</button>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- ════ CATEGORIES ════ -->
  <?php else: ?>

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <div style="font-size:13px;color:var(--gray-500)"><?= $total_cats ?> categories configured</div>
    <button onclick="openCatModal(null)" class="btn btn-primary">+ Add Category</button>
  </div>

  <div class="cats-grid">
    <?php foreach ($categories as $c): ?>
    <div class="cat-card" style="<?= $c['is_active']?'':'opacity:.55' ?>">
      <div class="cat-left-bar" style="background:<?= htmlspecialchars($c['color']) ?>"></div>
      <div class="cat-icon"><?= htmlspecialchars($c['icon']) ?></div>
      <div class="cat-name"><?= htmlspecialchars($c['name']) ?></div>
      <div class="cat-desc"><?= htmlspecialchars($c['description'] ?? '') ?></div>
      <div style="display:flex;justify-content:space-between;align-items:center">
        <span class="cat-count"><?= $c['art_count'] ?> article<?= $c['art_count']!=1?'s':'' ?></span>
        <div style="display:flex;gap:5px">
          <button onclick='openCatModal(<?= htmlspecialchars(json_encode($c)) ?>)' class="btn btn-ghost">Edit</button>
          <button onclick="toggleCat(<?= $c['id'] ?>,<?= $c['is_active']?0:1 ?>)" class="btn <?= $c['is_active']?'btn-warn':'btn-success' ?>"><?= $c['is_active']?'Hide':'Show' ?></button>
          <button onclick="deleteCat(<?= $c['id'] ?>,'<?= addslashes($c['name']) ?>')" class="btn btn-danger">Del</button>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>
</div>

<!-- ══ ARTICLE EDITOR MODAL ══ -->
<div class="modal-bd" id="articleModal">
  <div class="modal-box">
    <div class="mh">
      <span class="mh-title" id="artModalTitle">New Article</span>
      <button class="mc" onclick="closeModal('articleModal')">✕</button>
    </div>
    <div class="mb">
      <input type="hidden" id="am_id">

      <div class="fg">
        <div>
          <label class="flbl">Title <span>*</span></label>
          <input type="text" id="am_title" class="finp" placeholder="How to SSH into your VPS…" oninput="autoFillSeo()">
          <div class="char-counter" id="am_title_cc">0 / 70</div>
        </div>
        <div>
          <label class="flbl">Category <span>*</span></label>
          <select id="am_cat" class="finp">
            <option value="">— Select —</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['icon'].' '.$c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="fg full" style="margin-bottom:8px">
        <div>
          <label class="flbl">Excerpt <span>Short summary shown in listings</span></label>
          <textarea id="am_excerpt" class="finp" style="height:65px" placeholder="A brief 1-2 line description…" oninput="countChars(this,'am_exc_cc',160)"></textarea>
          <div class="char-counter" id="am_exc_cc">0 / 160</div>
        </div>
      </div>

      <label class="flbl" style="margin-bottom:6px">Content <span>*</span></label>
      <div id="quillEditor"></div>
      <input type="hidden" id="am_content">

      <!-- SEO Section -->
      <div class="seo-box" style="margin-top:18px">
        <div class="seo-box-lbl">🔍 SEO Settings</div>
        <div class="fg full">
          <div>
            <label class="flbl">SEO Title <span>auto-filled from article title</span></label>
            <input type="text" id="am_seo_title" class="finp" placeholder="SEO title (50–60 chars ideal)" oninput="updateSeoPreview();countChars(this,'am_seo_t_cc',60)">
            <div class="char-counter" id="am_seo_t_cc">0 / 60</div>
          </div>
        </div>
        <div class="fg full">
          <div>
            <label class="flbl">Meta Description</label>
            <textarea id="am_seo_desc" class="finp" style="height:70px" placeholder="150–160 chars. Describe this article for search engines…" oninput="updateSeoPreview();countChars(this,'am_seo_d_cc',160)"></textarea>
            <div class="char-counter" id="am_seo_d_cc">0 / 160</div>
          </div>
        </div>
        <div class="fg full">
          <div>
            <label class="flbl">Keywords <span>comma separated</span></label>
            <input type="text" id="am_seo_kw" class="finp" placeholder="ssh, vps, linux, server access">
          </div>
        </div>
        <!-- Live SEO Preview -->
        <div class="seo-preview">
          <div class="seo-prev-url" id="seo_url"><?= rtrim(BASE_URL,'/') ?>/kb/article-slug</div>
          <div class="seo-prev-title" id="seo_title_prev">Article Title — <?= $app_name ?></div>
          <div class="seo-prev-desc" id="seo_desc_prev">Meta description will appear here. Keep it between 150–160 characters for best results in Google.</div>
        </div>
      </div>

      <div class="fg three" style="margin-top:16px">
        <div>
          <label class="flbl">Status</label>
          <select id="am_active" class="finp">
            <option value="1">✓ Published</option>
            <option value="0">○ Draft</option>
          </select>
        </div>
        <div>
          <label class="flbl">Sort Order</label>
          <input type="number" id="am_sort" class="finp" value="0" placeholder="0">
        </div>
        <div style="display:flex;flex-direction:column;justify-content:flex-end">
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:var(--gray-700);cursor:pointer;padding-bottom:2px">
            <input type="checkbox" id="am_featured" style="width:15px;height:15px;accent-color:var(--primary)"> Mark as Featured
          </label>
        </div>
      </div>
    </div>
    <div class="mf">
      <button onclick="closeModal('articleModal')" class="btn btn-ghost">Cancel</button>
      <button onclick="saveArticle(0)" class="btn btn-ghost" id="saveDraftBtn">Save Draft</button>
      <button onclick="saveArticle(1)" class="btn btn-primary" id="saveArtBtn">Publish Article</button>
    </div>
  </div>
</div>

<!-- ══ CATEGORY MODAL ══ -->
<div class="modal-bd" id="catModal">
  <div class="modal-box" style="max-width:520px">
    <div class="mh">
      <span class="mh-title" id="catModalTitle">Add Category</span>
      <button class="mc" onclick="closeModal('catModal')">✕</button>
    </div>
    <div class="mb">
      <input type="hidden" id="cm_id">
      <div class="fg">
        <div>
          <label class="flbl">Category Name</label>
          <input type="text" id="cm_name" class="finp" placeholder="Getting Started">
        </div>
        <div>
          <label class="flbl">Icon <span>(emoji)</span></label>
          <input type="text" id="cm_icon" class="finp" placeholder="🚀" maxlength="4">
        </div>
      </div>
      <div class="fg">
        <div>
          <label class="flbl">Accent Color</label>
          <div style="display:flex;gap:8px;align-items:center">
            <input type="color" id="cm_color" value="#673de6" style="width:44px;height:38px;border-radius:7px;border:1.5px solid var(--border);cursor:pointer;padding:2px">
            <input type="text" id="cm_color_hex" class="finp" value="#673de6" placeholder="#673de6" oninput="document.getElementById('cm_color').value=this.value">
          </div>
        </div>
        <div>
          <label class="flbl">Sort Order</label>
          <input type="number" id="cm_sort" class="finp" value="0">
        </div>
      </div>
      <div class="fg full">
        <div>
          <label class="flbl">Description</label>
          <textarea id="cm_desc" class="finp" style="height:75px" placeholder="Short description shown on Knowledge Base homepage…"></textarea>
        </div>
      </div>
    </div>
    <div class="mf">
      <button onclick="closeModal('catModal')" class="btn btn-ghost">Cancel</button>
      <button onclick="saveCat()" class="btn btn-primary" id="saveCatBtn">Save Category</button>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.min.js"></script>
<script>
const CSRF = '<?= $csrf ?>';
const BASE = '<?= BASE_URL ?>';
const KB_BASE = BASE + '/kb/';

// ── Quill Init ──────────────────────────────────────────────
const quill = new Quill('#quillEditor', {
  theme: 'snow',
  placeholder: 'Write your article here…',
  modules: {
    toolbar: [
      [{ header: [1,2,3,false] }],
      ['bold','italic','underline','strike'],
      [{ color:[] },{ background:[] }],
      [{ list:'ordered' },{ list:'bullet' }],
      ['blockquote','code-block'],
      ['link','image'],
      [{ align:[] }],
      ['clean']
    ]
  }
});

// ── Helpers ─────────────────────────────────────────────────
function countChars(el, ccId, max) {
  const len = el.value.length;
  const cc = document.getElementById(ccId);
  cc.textContent = len + ' / ' + max;
  cc.className = 'char-counter' + (len > max ? ' over' : len > max*0.85 ? ' warn' : '');
}

function autoFillSeo() {
  const title = document.getElementById('am_title').value;
  const st    = document.getElementById('am_seo_title');
  if (!st.dataset.userEdited) {
    st.value = title ? title + ' — <?= addslashes($app_name) ?>' : '';
    updateSeoPreview();
    countChars(st,'am_seo_t_cc',60);
  }
  countChars(document.getElementById('am_title'),'am_title_cc',70);
}

document.getElementById('am_seo_title').addEventListener('input', function() {
  this.dataset.userEdited = '1';
  updateSeoPreview();
  countChars(this,'am_seo_t_cc',60);
});

function updateSeoPreview() {
  const title = document.getElementById('am_seo_title').value || document.getElementById('am_title').value || 'Article Title';
  const desc  = document.getElementById('am_seo_desc').value  || 'Meta description will appear here.';
  const slug  = document.getElementById('am_id').value ? '' : 'article-slug';
  document.getElementById('seo_title_prev').textContent = title + (title.includes('<?= addslashes($app_name) ?>') ? '' : ' — <?= addslashes($app_name) ?>');
  document.getElementById('seo_desc_prev').textContent  = desc;
}

function filterArticles(val, type) {
  document.querySelectorAll('#articlesTable tbody tr').forEach(tr => {
    const catOk    = type==='cat'    ? (!val || tr.dataset.cat === val)    : true;
    const statusOk = type==='status' ? (!val || tr.dataset.status === val) : true;
    tr.style.display = (catOk && statusOk) ? '' : 'none';
  });
}

// ── Article Modal ────────────────────────────────────────────
function openArticleModal(a) {
  document.getElementById('artModalTitle').textContent = a ? 'Edit Article' : 'New Article';
  document.getElementById('am_id').value       = a?.id || '';
  document.getElementById('am_title').value    = a?.title || '';
  document.getElementById('am_cat').value      = a?.category_id || '';
  document.getElementById('am_excerpt').value  = a?.excerpt || '';
  document.getElementById('am_seo_title').value= a?.seo_title || '';
  document.getElementById('am_seo_desc').value = a?.seo_description || '';
  document.getElementById('am_seo_kw').value   = a?.seo_keywords || '';
  document.getElementById('am_active').value   = a ? (a.is_active ? '1' : '0') : '1';
  document.getElementById('am_sort').value     = a?.sort_order || 0;
  document.getElementById('am_featured').checked = a?.is_featured == 1;
  delete document.getElementById('am_seo_title').dataset.userEdited;

  // Set quill content
  if (a?.content) {
    quill.root.innerHTML = a.content;
  } else {
    quill.setText('');
  }

  // SEO URL preview
  document.getElementById('seo_url').textContent = KB_BASE + (a?.slug || 'article-slug');
  updateSeoPreview();

  // Char counters
  ['am_title','am_excerpt','am_seo_title','am_seo_desc'].forEach(id => {
    const el = document.getElementById(id);
    const ccId = {am_title:'am_title_cc',am_excerpt:'am_exc_cc',am_seo_title:'am_seo_t_cc',am_seo_desc:'am_seo_d_cc'}[id];
    const max  = {am_title:70,am_excerpt:160,am_seo_title:60,am_seo_desc:160}[id];
    if (el && ccId) countChars(el, ccId, max);
  });

  document.getElementById('articleModal').classList.add('open');
}

function saveArticle(publish) {
  // Sync quill HTML
  document.getElementById('am_content').value = quill.root.innerHTML;

  const btn = publish ? document.getElementById('saveArtBtn') : document.getElementById('saveDraftBtn');
  btn.disabled=true; btn.textContent='Saving…';

  fetch(BASE+'/api/kb-action.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      csrf:            CSRF,
      action:          'save_article',
      id:              document.getElementById('am_id').value,
      category_id:     document.getElementById('am_cat').value,
      title:           document.getElementById('am_title').value,
      excerpt:         document.getElementById('am_excerpt').value,
      content:         document.getElementById('am_content').value,
      seo_title:       document.getElementById('am_seo_title').value,
      seo_description: document.getElementById('am_seo_desc').value,
      seo_keywords:    document.getElementById('am_seo_kw').value,
      is_featured:     document.getElementById('am_featured').checked ? 1 : 0,
      is_active:       publish,
      sort_order:      document.getElementById('am_sort').value,
    })
  }).then(r=>r.json()).then(d=>{
    if(d.ok) location.reload();
    else { alert(d.error||'Failed'); btn.disabled=false; btn.textContent=publish?'Publish Article':'Save Draft'; }
  });
}

function toggleArticle(id,val) {
  fetch(BASE+'/api/kb-action.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({csrf:CSRF,action:'toggle_article',id,is_active:val})
  }).then(r=>r.json()).then(d=>{if(d.ok)location.reload();else alert(d.error);});
}

function deleteArticle(id,title) {
  if(!confirm('Delete "'+title+'"?')) return;
  fetch(BASE+'/api/kb-action.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({csrf:CSRF,action:'delete_article',id})
  }).then(r=>r.json()).then(d=>{if(d.ok)location.reload();else alert(d.error);});
}

// ── Category Modal ───────────────────────────────────────────
function openCatModal(c) {
  document.getElementById('catModalTitle').textContent = c ? 'Edit Category' : 'Add Category';
  document.getElementById('cm_id').value    = c?.id || '';
  document.getElementById('cm_name').value  = c?.name || '';
  document.getElementById('cm_icon').value  = c?.icon || '📁';
  document.getElementById('cm_color').value = c?.color || '#673de6';
  document.getElementById('cm_color_hex').value = c?.color || '#673de6';
  document.getElementById('cm_sort').value  = c?.sort_order || 0;
  document.getElementById('cm_desc').value  = c?.description || '';
  document.getElementById('catModal').classList.add('open');
}

document.getElementById('cm_color').addEventListener('input',function(){
  document.getElementById('cm_color_hex').value = this.value;
});

function saveCat() {
  const btn=document.getElementById('saveCatBtn');
  btn.disabled=true; btn.textContent='Saving…';
  fetch(BASE+'/api/kb-action.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({
      csrf:CSRF, action:'save_category',
      id:          document.getElementById('cm_id').value,
      name:        document.getElementById('cm_name').value,
      description: document.getElementById('cm_desc').value,
      icon:        document.getElementById('cm_icon').value,
      color:       document.getElementById('cm_color').value,
      sort_order:  document.getElementById('cm_sort').value,
    })
  }).then(r=>r.json()).then(d=>{
    if(d.ok) location.reload();
    else { alert(d.error||'Failed'); btn.disabled=false; btn.textContent='Save Category'; }
  });
}

function toggleCat(id,val) {
  fetch(BASE+'/api/kb-action.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({csrf:CSRF,action:'toggle_category',id,is_active:val})
  }).then(r=>r.json()).then(d=>{if(d.ok)location.reload();else alert(d.error);});
}

function deleteCat(id,name) {
  if(!confirm('Delete category "'+name+'"? (Move articles first)')) return;
  fetch(BASE+'/api/kb-action.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({csrf:CSRF,action:'delete_category',id})
  }).then(r=>r.json()).then(d=>{if(d.ok)location.reload();else alert(d.error||'Has articles — move them first');});
}

function closeModal(id){document.getElementById(id).classList.remove('open');}
document.querySelectorAll('.modal-bd').forEach(m=>{
  m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open');});
});
</script>
</body>
</html>
