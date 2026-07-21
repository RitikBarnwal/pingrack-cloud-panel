<?php
// kb/category/[slug].php — Category page
// URL: /kb/category/servers
require_once __DIR__ . '/../../includes/bootstrap.php';
session_start_safe();

$slug = basename($_SERVER['REQUEST_URI']);
$slug = strtok($slug, '?');

$cat = db()->prepare("SELECT * FROM kb_categories WHERE slug=? AND is_active=1");
$cat->execute([$slug]);
$cat = $cat->fetch();
if (!$cat) { http_response_code(404); echo '404 — Category not found'; exit; }

// Articles in this category
$articles = db()->query(
    "SELECT * FROM kb_articles WHERE category_id={$cat['id']} AND is_active=1 ORDER BY is_featured DESC, sort_order ASC, views DESC"
)->fetchAll();

// Other categories for sidebar
$other_cats = db()->query(
    "SELECT c.*, COUNT(a.id) art_count
     FROM kb_categories c LEFT JOIN kb_articles a ON a.category_id=c.id AND a.is_active=1
     WHERE c.is_active=1 GROUP BY c.id ORDER BY c.sort_order"
)->fetchAll();

$app_name = APP_NAME;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= htmlspecialchars($cat['name']) ?> — Knowledge Base — <?= htmlspecialchars($app_name) ?></title>
<meta name="description" content="<?= htmlspecialchars($cat['description'] ?? '') ?>">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
<?php inject_global_head(); ?>
<style>
  body{background:var(--gray-50)}
  .kb-nav{padding:14px 24px;border-bottom:1px solid var(--border);background:white;font-size:13px}
  .kb-nav-inner{max-width:1100px;margin:0 auto;display:flex;align-items:center;gap:6px;color:var(--gray-500);flex-wrap:wrap}
  .kb-nav-inner a{color:var(--primary);font-weight:600;text-decoration:none}.kb-nav-inner a:hover{text-decoration:underline}
  .kb-wrap{max-width:1100px;margin:0 auto;padding:32px 24px;display:grid;grid-template-columns:1fr 260px;gap:28px}
  @media(max-width:768px){.kb-wrap{grid-template-columns:1fr}}

  /* Category hero strip */
  .cat-hero{background:white;border:1.5px solid var(--border);border-radius:14px;padding:24px 28px;margin-bottom:24px;display:flex;align-items:center;gap:18px;position:relative;overflow:hidden}
  .cat-hero::before{content:'';position:absolute;left:0;top:0;bottom:0;width:5px;background:<?= htmlspecialchars($cat['color']) ?>}
  .cat-hero-icon{font-size:36px}
  .cat-hero-name{font-size:20px;font-weight:900;color:var(--gray-900);margin-bottom:3px}
  .cat-hero-desc{font-size:13px;color:var(--gray-500);line-height:1.5}
  .cat-hero-count{margin-left:auto;font-size:13px;font-weight:700;padding:6px 14px;border-radius:99px;white-space:nowrap}

  /* Article list */
  .art-item{background:white;border:1px solid var(--border);border-radius:11px;padding:16px 18px;text-decoration:none;display:flex;align-items:flex-start;gap:14px;margin-bottom:10px;transition:all .15s}
  .art-item:hover{border-color:var(--gray-300);box-shadow:var(--shadow);transform:translateX(2px)}
  .art-item-icon{font-size:20px;flex-shrink:0;margin-top:2px}
  .art-item-title{font-size:14px;font-weight:700;color:var(--gray-900);margin-bottom:4px;line-height:1.35}
  .art-item-excerpt{font-size:12.5px;color:var(--gray-500);line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
  .art-item-meta{display:flex;gap:10px;align-items:center;margin-top:8px}
  .art-item-views{font-size:11px;color:var(--gray-400)}
  .featured-pill{background:#ede9fe;color:#6d28d9;font-size:10px;font-weight:700;padding:1px 7px;border-radius:99px}

  /* Sidebar */
  .kb-sidebar-card{background:white;border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:14px}
  .kb-sidebar-title{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--gray-400);margin-bottom:12px}
  .cat-link{display:flex;align-items:center;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--gray-100);text-decoration:none;transition:color .12s}
  .cat-link:last-child{border:none}
  .cat-link:hover .cat-link-name{color:var(--primary)}
  .cat-link-name{font-size:13px;font-weight:600;color:var(--gray-700);display:flex;align-items:center;gap:7px}
  .cat-link-count{font-size:11px;font-weight:700;color:var(--gray-400)}
  .cat-link.current .cat-link-name{color:var(--primary)}
</style>
</head>
<body>
<?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

<div class="main-content" style="padding:0">
  <div class="kb-nav">
    <div class="kb-nav-inner">
      <a href="<?= BASE_URL ?>">Home</a> ›
      <a href="<?= BASE_URL ?>/kb/">Knowledge Base</a> ›
      <span style="color:var(--gray-900);font-weight:600"><?= htmlspecialchars($cat['name']) ?></span>
    </div>
  </div>

  <div class="kb-wrap">
    <!-- Main -->
    <div>
      <div class="cat-hero">
        <div class="cat-hero-icon"><?= htmlspecialchars($cat['icon']) ?></div>
        <div>
          <div class="cat-hero-name"><?= htmlspecialchars($cat['name']) ?></div>
          <div class="cat-hero-desc"><?= htmlspecialchars($cat['description'] ?? '') ?></div>
        </div>
        <span class="cat-hero-count" style="background:<?= htmlspecialchars($cat['color']) ?>18;color:<?= htmlspecialchars($cat['color']) ?>">
          <?= count($articles) ?> article<?= count($articles)!=1?'s':'' ?>
        </span>
      </div>

      <?php if (empty($articles)): ?>
      <div style="background:white;border:1px solid var(--border);border-radius:12px;padding:40px;text-align:center;color:var(--gray-400)">
        No articles in this category yet.
      </div>
      <?php else: foreach ($articles as $a): ?>
      <a href="<?= BASE_URL ?>/kb/<?= htmlspecialchars($a['slug']) ?>" class="art-item">
        <div class="art-item-icon"><?= $a['is_featured'] ? '⭐' : '📄' ?></div>
        <div style="flex:1;min-width:0">
          <div class="art-item-title"><?= htmlspecialchars($a['title']) ?></div>
          <?php if ($a['excerpt']): ?>
          <div class="art-item-excerpt"><?= htmlspecialchars($a['excerpt']) ?></div>
          <?php endif; ?>
          <div class="art-item-meta">
            <?php if ($a['is_featured']): ?><span class="featured-pill">Featured</span><?php endif; ?>
            <span class="art-item-views">👁 <?= number_format($a['views']) ?> views · Updated <?= date('d M Y', strtotime($a['updated_at'])) ?></span>
          </div>
        </div>
        <span style="color:var(--gray-300);font-size:16px;flex-shrink:0;margin-top:2px">→</span>
      </a>
      <?php endforeach; endif; ?>
    </div>

    <!-- Sidebar -->
    <div>
      <div class="kb-sidebar-card">
        <div class="kb-sidebar-title">All Categories</div>
        <?php foreach ($other_cats as $c): ?>
        <a href="<?= BASE_URL ?>/kb/category/<?= htmlspecialchars($c['slug']) ?>" class="cat-link <?= $c['id']==$cat['id']?'current':'' ?>">
          <span class="cat-link-name">
            <span><?= htmlspecialchars($c['icon']) ?></span>
            <?= htmlspecialchars($c['name']) ?>
          </span>
          <span class="cat-link-count"><?= $c['art_count'] ?></span>
        </a>
        <?php endforeach; ?>
      </div>
      <div class="kb-sidebar-card">
        <div class="kb-sidebar-title">Need more help?</div>
        <a href="<?= BASE_URL ?>/tickets.php" style="display:flex;align-items:center;gap:8px;padding:10px 14px;background:var(--primary);color:white;border-radius:9px;font-size:13px;font-weight:700;text-decoration:none;justify-content:center">
          🎫 Open a Support Ticket
        </a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
