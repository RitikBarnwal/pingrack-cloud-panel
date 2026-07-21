<?php
// kb/index.php — Knowledge Base Homepage
require_once __DIR__ . '/../includes/bootstrap.php';
session_start_safe();

$user     = current_user();
$logged_in = is_logged_in();
$current   = $logged_in ? current_user() : null;
$app_name  = APP_NAME;
$currency = strtoupper($user['currency'] ?? 'USD');
$curr_sym = user_currency_symbol($currency);
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$uname    = htmlspecialchars($user['username']);
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$balance  = (float)$user['wallet_balance'];

// Load categories with article count
$categories = db()->query(
    "SELECT c.*,
            COUNT(a.id)  total_articles,
            SUM(a.views) total_views
     FROM kb_categories c
     LEFT JOIN kb_articles a ON a.category_id=c.id AND a.is_active=1
     WHERE c.is_active=1
     GROUP BY c.id
     ORDER BY c.sort_order, c.id"
)->fetchAll();

// Featured articles
$featured = db()->query(
    "SELECT a.*, c.name cat_name, c.icon cat_icon
     FROM kb_articles a
     JOIN kb_categories c ON c.id=a.category_id
     WHERE a.is_active=1 AND a.is_featured=1
     ORDER BY a.views DESC LIMIT 6"
)->fetchAll();

// Recent articles
$recent = db()->query(
    "SELECT a.*, c.name cat_name, c.icon cat_icon, c.color cat_color
     FROM kb_articles a
     JOIN kb_categories c ON c.id=a.category_id
     WHERE a.is_active=1
     ORDER BY a.updated_at DESC LIMIT 8"
)->fetchAll();

$total_articles = array_sum(array_column($categories, 'total_articles'));
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Knowledge Base — <?= htmlspecialchars($app_name) ?></title>
<meta name="description" content="Find answers, tutorials and guides for <?= htmlspecialchars($app_name) ?> — servers, billing, DNS, storage and more.">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
<?php inject_global_head(); ?>
<style>
  body{background:var(--gray-50)}

  /* Search hero */
  .kb-hero{background:linear-gradient(135deg,var(--primary) 0%,#4f46e5 100%);padding:52px 24px 60px;text-align:center;position:relative;overflow:hidden}
  .kb-hero::before{content:'';position:absolute;inset:0;background:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E")}
  .kb-hero h1{font-size:30px;font-weight:900;color:white;margin:0 0 10px;position:relative}
  .kb-hero p{color:rgba(255,255,255,.8);font-size:15px;margin:0 0 28px;position:relative}
  .kb-search-wrap{max-width:560px;margin:0 auto;position:relative}
  .kb-search-input{width:100%;box-sizing:border-box;padding:14px 56px 14px 20px;border-radius:12px;border:none;font-size:15px;font-family:inherit;box-shadow:0 8px 32px rgba(0,0,0,.2);outline:none}
  .kb-search-btn{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:var(--primary);border:none;color:white;width:38px;height:38px;border-radius:8px;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center}
  .kb-stats{display:flex;justify-content:center;gap:24px;margin-top:20px;position:relative}
  .kb-stats span{color:rgba(255,255,255,.75);font-size:13px;font-weight:600}
  .kb-stats strong{color:white}

  /* Layout */
  .kb-wrap{max-width:1100px;margin:0 auto;padding:40px 24px}
  .kb-section-title{font-size:16px;font-weight:800;color:var(--gray-900);margin:0 0 16px;display:flex;align-items:center;gap:8px}

  /* Category cards */
  .cats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;margin-bottom:44px}
  .cat-card{background:white;border:1.5px solid var(--border);border-radius:13px;padding:20px;text-decoration:none;display:block;transition:all .18s;position:relative;overflow:hidden}
  .cat-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px}
  .cat-card:hover{border-color:var(--gray-300);transform:translateY(-2px);box-shadow:var(--shadow-md)}
  .cat-icon{font-size:26px;margin-bottom:10px}
  .cat-name{font-size:14px;font-weight:800;color:var(--gray-900);margin-bottom:4px}
  .cat-desc{font-size:12px;color:var(--gray-500);line-height:1.5;margin-bottom:12px}
  .cat-footer{display:flex;align-items:center;justify-content:space-between}
  .art-count-badge{font-size:11px;font-weight:700;padding:2px 9px;border-radius:99px;background:var(--gray-100);color:var(--gray-600)}
  .cat-arrow{font-size:14px;color:var(--gray-300)}

  /* Article list */
  .arts-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px;margin-bottom:44px}
  .art-card{background:white;border:1px solid var(--border);border-radius:11px;padding:16px;text-decoration:none;display:block;transition:all .15s}
  .art-card:hover{border-color:var(--gray-300);box-shadow:var(--shadow)}
  .art-card-cat{font-size:11px;font-weight:700;color:var(--gray-500);margin-bottom:6px;display:flex;align-items:center;gap:4px}
  .art-card-title{font-size:13.5px;font-weight:700;color:var(--gray-900);line-height:1.4;margin-bottom:6px}
  .art-card-excerpt{font-size:12px;color:var(--gray-500);line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
  .art-card-footer{display:flex;align-items:center;justify-content:space-between;margin-top:10px;padding-top:10px;border-top:1px solid var(--gray-100)}
  .art-views{font-size:11px;color:var(--gray-400)}

  /* Search results */
  #searchResults{display:none;background:white;border:1px solid var(--border);border-radius:12px;position:absolute;top:100%;left:0;right:0;margin-top:6px;box-shadow:var(--shadow-lg);z-index:100;max-height:360px;overflow-y:auto}
  .sr-item{display:block;padding:12px 16px;text-decoration:none;border-bottom:1px solid var(--gray-100);transition:background .1s}
  .sr-item:last-child{border:none}
  .sr-item:hover{background:var(--gray-50)}
  .sr-title{font-size:13.5px;font-weight:700;color:var(--gray-900);margin-bottom:3px}
  .sr-cat{font-size:11px;color:var(--gray-400)}
  .sr-empty{padding:20px;text-align:center;color:var(--gray-400);font-size:13px}

  /* Breadcrumb */
  .kb-nav{padding:14px 24px;border-bottom:1px solid var(--border);background:white;font-size:13px}
  .kb-nav-inner{max-width:1100px;margin:0 auto;display:flex;align-items:center;gap:6px;color:var(--gray-500)}
  .kb-nav-inner a{color:var(--primary);font-weight:600;text-decoration:none}
  .kb-nav-inner a:hover{text-decoration:underline}
</style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content" style="padding:0">

  <!-- Nav -->
  <div class="kb-nav">
    <div class="kb-nav-inner">
      <a href="<?= BASE_URL ?>">Home</a>
      <span>›</span>
      <span style="color:var(--gray-900);font-weight:600">Knowledge Base</span>
    </div>
  </div>

  <!-- Hero + Search -->
  <div class="kb-hero">
    <h1>How can we help you?</h1>
    <p>Search our knowledge base or browse categories below</p>
    <div class="kb-search-wrap">
      <input type="text" class="kb-search-input" id="kbSearch" placeholder="Search articles… e.g. SSH access, billing, DNS setup"
             autocomplete="off" oninput="kbSearchLive(this.value)">
      <button class="kb-search-btn" onclick="goSearch()">🔍</button>
      <div id="searchResults"></div>
    </div>
    <div class="kb-stats">
      <span><strong><?= count($categories) ?></strong> Categories</span>
      <span><strong><?= $total_articles ?></strong> Articles</span>
    </div>
  </div>

  <div class="kb-wrap">

    <!-- Categories -->
    <div class="kb-section-title">📂 Browse by Category</div>
    <div class="cats-grid">
      <?php foreach ($categories as $c): ?>
      <a href="<?= BASE_URL ?>/kb/category/<?= htmlspecialchars($c['slug']) ?>" class="cat-card">
        <div class="cat-card" style="border:none;padding:0" onclick=""><!-- inner not needed --></div>
        <style>.cat-card[href$="<?= htmlspecialchars($c['slug']) ?>"]::before{background:<?= htmlspecialchars($c['color']) ?>}</style>
        <div class="cat-icon"><?= htmlspecialchars($c['icon']) ?></div>
        <div class="cat-name"><?= htmlspecialchars($c['name']) ?></div>
        <div class="cat-desc"><?= htmlspecialchars($c['description'] ?? '') ?></div>
        <div class="cat-footer">
          <span class="art-count-badge" style="background:<?= htmlspecialchars($c['color']) ?>18;color:<?= htmlspecialchars($c['color']) ?>">
            <?= (int)$c['total_articles'] ?> article<?= $c['total_articles']!=1?'s':'' ?>
          </span>
          <span class="cat-arrow">→</span>
        </div>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Featured -->
    <?php if (!empty($featured)): ?>
    <div class="kb-section-title">⭐ Featured Articles</div>
    <div class="arts-grid" style="margin-bottom:44px">
      <?php foreach ($featured as $a): ?>
      <a href="<?= BASE_URL ?>/kb/<?= htmlspecialchars($a['slug']) ?>" class="art-card">
        <div class="art-card-cat"><?= htmlspecialchars($a['cat_icon']) ?> <?= htmlspecialchars($a['cat_name']) ?></div>
        <div class="art-card-title"><?= htmlspecialchars($a['title']) ?></div>
        <?php if ($a['excerpt']): ?>
        <div class="art-card-excerpt"><?= htmlspecialchars($a['excerpt']) ?></div>
        <?php endif; ?>
        <div class="art-card-footer">
          <span class="art-views">👁 <?= number_format($a['views']) ?> views</span>
          <span style="font-size:11px;color:var(--primary);font-weight:700">Read →</span>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Recent -->
    <?php if (!empty($recent)): ?>
    <div class="kb-section-title">🕐 Recently Updated</div>
    <div class="arts-grid">
      <?php foreach ($recent as $a): ?>
      <a href="<?= BASE_URL ?>/kb/<?= htmlspecialchars($a['slug']) ?>" class="art-card">
        <div class="art-card-cat" style="color:<?= htmlspecialchars($a['cat_color']) ?>"><?= htmlspecialchars($a['cat_icon']) ?> <?= htmlspecialchars($a['cat_name']) ?></div>
        <div class="art-card-title"><?= htmlspecialchars($a['title']) ?></div>
        <?php if ($a['excerpt']): ?>
        <div class="art-card-excerpt"><?= htmlspecialchars($a['excerpt']) ?></div>
        <?php endif; ?>
        <div class="art-card-footer">
          <span class="art-views">👁 <?= number_format($a['views']) ?> · <?= date('d M', strtotime($a['updated_at'])) ?></span>
          <span style="font-size:11px;color:var(--primary);font-weight:700">Read →</span>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>
</div>

<script>
let _searchTimer = null;
function kbSearchLive(q) {
  clearTimeout(_searchTimer);
  const box = document.getElementById('searchResults');
  if (q.trim().length < 2) { box.style.display='none'; return; }
  _searchTimer = setTimeout(() => {
    fetch('<?= BASE_URL ?>/api/kb-search.php?q=' + encodeURIComponent(q))
      .then(r=>r.json()).then(d=>{
        if (!d.results || !d.results.length) {
          box.innerHTML='<div class="sr-empty">No results found for "'+q+'"</div>';
        } else {
          box.innerHTML = d.results.map(r=>`
            <a href="<?= BASE_URL ?>/kb/${r.slug}" class="sr-item">
              <div class="sr-title">${r.title}</div>
              <div class="sr-cat">${r.cat_icon} ${r.cat_name}</div>
            </a>`).join('');
        }
        box.style.display='block';
      });
  }, 250);
}
function goSearch() {
  const q = document.getElementById('kbSearch').value.trim();
  if (q) location.href = '<?= BASE_URL ?>/kb/search.php?q='+encodeURIComponent(q);
}
document.getElementById('kbSearch').addEventListener('keydown',e=>{ if(e.key==='Enter') goSearch(); });
document.addEventListener('click',e=>{ if(!e.target.closest('.kb-search-wrap')) document.getElementById('searchResults').style.display='none'; });
</script>
</body>
</html>
