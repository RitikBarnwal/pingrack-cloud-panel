<?php
// kb/article.php — Single KB Article
// URL routed via .htaccess: /kb/{slug} → /kb/article.php?slug={slug}
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

$slug = trim($_GET['slug'] ?? basename(strtok($_SERVER['REQUEST_URI'],'?')));

$st = db()->prepare(
    "SELECT a.*, c.name cat_name, c.icon cat_icon, c.color cat_color, c.slug cat_slug
     FROM kb_articles a
     JOIN kb_categories c ON c.id=a.category_id
     WHERE a.slug=? AND a.is_active=1 LIMIT 1"
);
$st->execute([$slug]);
$article = $st->fetch();
if (!$article) { http_response_code(404); echo '404 — Article not found'; exit; }

// Increment view count (once per session)
$sess_key = 'kb_viewed_' . $article['id'];
if (empty($_SESSION[$sess_key])) {
    db()->prepare("UPDATE kb_articles SET views=views+1 WHERE id=?")->execute([$article['id']]);
    $_SESSION[$sess_key] = 1;
}

// Related articles (same category)
$related = db()->query(
    "SELECT id,title,slug,excerpt,views FROM kb_articles
     WHERE category_id={$article['category_id']} AND id!={$article['id']} AND is_active=1
     ORDER BY views DESC LIMIT 4"
)->fetchAll();

// SEO
$seo_title = $article['seo_title'] ?: $article['title'] . ' — ' . APP_NAME;
$seo_desc  = $article['seo_description'] ?: $article['excerpt'] ?: '';
$seo_kw    = $article['seo_keywords'] ?: '';
$canon     = rtrim(BASE_URL,'/') . '/kb/' . $article['slug'];
$csrf      = csrf_token();
$app_name  = APP_NAME;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= htmlspecialchars($seo_title) ?></title>
<meta name="description" content="<?= htmlspecialchars($seo_desc) ?>">
<?php if ($seo_kw): ?><meta name="keywords" content="<?= htmlspecialchars($seo_kw) ?>"><?php endif; ?>
<link rel="canonical" href="<?= htmlspecialchars($canon) ?>">
<!-- Open Graph -->
<meta property="og:title"       content="<?= htmlspecialchars($seo_title) ?>">
<meta property="og:description" content="<?= htmlspecialchars($seo_desc) ?>">
<meta property="og:url"         content="<?= htmlspecialchars($canon) ?>">
<meta property="og:type"        content="article">
<!-- Twitter Card -->
<meta name="twitter:card"        content="summary">
<meta name="twitter:title"       content="<?= htmlspecialchars($seo_title) ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($seo_desc) ?>">
<!-- Schema.org Article -->
<script type="application/ld+json"><?= json_encode([
  '@context'        => 'https://schema.org',
  '@type'           => 'Article',
  'headline'        => $article['title'],
  'description'     => $seo_desc,
  'url'             => $canon,
  'dateModified'    => date('c', strtotime($article['updated_at'])),
  'datePublished'   => date('c', strtotime($article['created_at'])),
  'publisher'       => ['@type'=>'Organization','name'=>$app_name],
]) ?></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
<?php inject_global_head(); ?>
<style>
  body{background:var(--gray-50)}
  .kb-nav{padding:14px 24px;border-bottom:1px solid var(--border);background:white;font-size:13px}
  .kb-nav-inner{max-width:1100px;margin:0 auto;display:flex;align-items:center;gap:6px;color:var(--gray-500);flex-wrap:wrap}
  .kb-nav-inner a{color:var(--primary);font-weight:600;text-decoration:none}.kb-nav-inner a:hover{text-decoration:underline}

  .kb-wrap{max-width:1100px;margin:0 auto;padding:32px 24px;display:grid;grid-template-columns:1fr 260px;gap:28px}
  @media(max-width:768px){.kb-wrap{grid-template-columns:1fr}}

  /* Article */
  .art-main{background:white;border:1px solid var(--border);border-radius:14px;padding:32px 36px}
  @media(max-width:640px){.art-main{padding:20px}}
  .art-cat-badge{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;padding:4px 12px;border-radius:99px;margin-bottom:14px;text-decoration:none}
  .art-title{font-size:26px;font-weight:900;color:var(--gray-900);line-height:1.3;margin-bottom:10px}
  @media(max-width:640px){.art-title{font-size:20px}}
  .art-meta{display:flex;gap:16px;align-items:center;font-size:12px;color:var(--gray-400);margin-bottom:28px;padding-bottom:20px;border-bottom:1px solid var(--gray-100);flex-wrap:wrap}
  .art-meta span{display:flex;align-items:center;gap:4px}

  /* Article content */
  .art-content{font-size:15px;line-height:1.8;color:var(--gray-800)}
  .art-content h1{font-size:22px;font-weight:800;color:var(--gray-900);margin:28px 0 12px}
  .art-content h2{font-size:18px;font-weight:700;color:var(--gray-900);margin:24px 0 10px;padding-bottom:8px;border-bottom:1px solid var(--gray-100)}
  .art-content h3{font-size:15px;font-weight:700;color:var(--gray-900);margin:20px 0 8px}
  .art-content p{margin-bottom:14px}
  .art-content ul,.art-content ol{padding-left:22px;margin-bottom:14px}
  .art-content li{margin-bottom:5px}
  .art-content pre{background:var(--gray-50);border:1px solid var(--border);border-radius:8px;padding:14px 16px;font-family:'JetBrains Mono',monospace;font-size:13px;overflow-x:auto;margin-bottom:14px}
  .art-content code{background:var(--gray-100);padding:2px 6px;border-radius:4px;font-family:'JetBrains Mono',monospace;font-size:13px}
  .art-content pre code{background:none;padding:0}
  .art-content blockquote{border-left:3px solid var(--primary);padding-left:16px;color:var(--gray-600);margin:0 0 14px;font-style:italic}
  .art-content img{border-radius:8px;max-width:100%;margin:14px 0}
  .art-content table{width:100%;border-collapse:collapse;margin-bottom:14px;font-size:13.5px}
  .art-content table th{background:var(--gray-50);padding:10px 14px;border:1px solid var(--border);font-weight:700;text-align:left}
  .art-content table td{padding:10px 14px;border:1px solid var(--border)}
  .art-content a{color:var(--primary);text-decoration:underline}

  /* Helpful */
  .helpful-box{margin-top:32px;padding-top:24px;border-top:1px solid var(--gray-100);text-align:center}
  .helpful-box p{font-size:14px;font-weight:700;color:var(--gray-600);margin-bottom:12px}
  .helpful-btn{padding:9px 22px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;border:1.5px solid var(--border);background:white;font-family:inherit;transition:all .15s;margin:0 5px}
  .helpful-btn:hover{border-color:var(--primary);color:var(--primary)}
  .helpful-btn.voted{background:var(--primary);color:white;border-color:var(--primary)}
  .helpful-counts{font-size:12px;color:var(--gray-400);margin-top:10px}

  /* Sidebar */
  .kb-sidebar-card{background:white;border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:14px}
  .kb-sidebar-title{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--gray-400);margin-bottom:12px}
  .rel-link{display:block;padding:8px 0;border-bottom:1px solid var(--gray-100);text-decoration:none;font-size:13px;font-weight:600;color:var(--gray-700);line-height:1.35;transition:color .12s}
  .rel-link:last-child{border:none}
  .rel-link:hover{color:var(--primary)}

  /* TOC */
  .toc{background:var(--gray-50);border:1px solid var(--border);border-radius:10px;padding:14px 16px;margin-bottom:24px}
  .toc-title{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--gray-500);margin-bottom:10px}
  .toc a{display:block;font-size:13px;color:var(--gray-600);text-decoration:none;padding:3px 0;padding-left:12px;border-left:2px solid transparent;transition:all .12s}
  .toc a:hover,.toc a.active{color:var(--primary);border-color:var(--primary);padding-left:14px}
  .toc a.toc-h3{padding-left:24px;font-size:12.5px}
</style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content" style="padding:0">
  <div class="kb-nav">
    <div class="kb-nav-inner">
      <a href="<?= BASE_URL ?>">Home</a> ›
      <a href="<?= BASE_URL ?>/kb/">Knowledge Base</a> ›
      <a href="<?= BASE_URL ?>/kb/category/<?= htmlspecialchars($article['cat_slug']) ?>"><?= htmlspecialchars($article['cat_icon'].' '.$article['cat_name']) ?></a> ›
      <span style="color:var(--gray-900);font-weight:600"><?= htmlspecialchars($article['title']) ?></span>
    </div>
  </div>

  <div class="kb-wrap">
    <!-- Article -->
    <div>
      <div class="art-main">
        <a href="<?= BASE_URL ?>/kb/category/<?= htmlspecialchars($article['cat_slug']) ?>"
           class="art-cat-badge"
           style="background:<?= htmlspecialchars($article['cat_color']) ?>18;color:<?= htmlspecialchars($article['cat_color']) ?>">
          <?= htmlspecialchars($article['cat_icon'].' '.$article['cat_name']) ?>
        </a>
        <h1 class="art-title"><?= htmlspecialchars($article['title']) ?></h1>
        <div class="art-meta">
          <span>👁 <?= number_format($article['views']) ?> views</span>
          <span>🕐 Updated <?= date('d M Y', strtotime($article['updated_at'])) ?></span>
          <span>👍 <?= $article['helpful_yes'] ?> found helpful</span>
        </div>

        <!-- TOC (auto-generated from H2/H3 by JS) -->
        <div class="toc" id="tocBox" style="display:none">
          <div class="toc-title">On this page</div>
          <div id="tocLinks"></div>
        </div>

        <div class="art-content" id="artContent">
          <?= $article['content'] ?>
        </div>

        <!-- Helpful vote -->
        <div class="helpful-box" id="helpfulBox">
          <p>Was this article helpful?</p>
          <button class="helpful-btn" onclick="vote('yes')" id="voteYes">👍 Yes</button>
          <button class="helpful-btn" onclick="vote('no')"  id="voteNo">👎 No</button>
          <div class="helpful-counts" id="helpfulCounts"><?= $article['helpful_yes'] ?> of <?= $article['helpful_yes']+$article['helpful_no'] ?> found this helpful</div>
        </div>
      </div>
    </div>

    <!-- Sidebar -->
    <div>
      <?php if (!empty($related)): ?>
      <div class="kb-sidebar-card">
        <div class="kb-sidebar-title">Related Articles</div>
        <?php foreach ($related as $r): ?>
        <a href="<?= BASE_URL ?>/kb/<?= htmlspecialchars($r['slug']) ?>" class="rel-link"><?= htmlspecialchars($r['title']) ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div class="kb-sidebar-card">
        <div class="kb-sidebar-title">Still need help?</div>
        <p style="font-size:12.5px;color:var(--gray-500);line-height:1.5;margin-bottom:12px">Couldn't find what you were looking for? Our support team is here.</p>
        <a href="<?= BASE_URL ?>/tickets.php" style="display:flex;align-items:center;gap:8px;padding:10px 14px;background:var(--primary);color:white;border-radius:9px;font-size:13px;font-weight:700;text-decoration:none;justify-content:center">
          🎫 Open a Support Ticket
        </a>
      </div>
      <div class="kb-sidebar-card">
        <div class="kb-sidebar-title">Share</div>
        <div style="display:flex;gap:8px">
          <a href="https://twitter.com/intent/tweet?url=<?= urlencode($canon) ?>&text=<?= urlencode($article['title']) ?>" target="_blank" class="helpful-btn" style="flex:1;justify-content:center">𝕏 Share</a>
          <button onclick="navigator.clipboard.writeText('<?= addslashes($canon) ?>').then(()=>alert('Link copied!'))" class="helpful-btn" style="flex:1">🔗 Copy</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// ── TOC Generator ──
(function() {
  const content = document.getElementById('artContent');
  const tocBox  = document.getElementById('tocBox');
  const tocLinks= document.getElementById('tocLinks');
  const headings= content.querySelectorAll('h2,h3');
  if (headings.length < 2) return;
  headings.forEach((h,i) => {
    h.id = 'heading-' + i;
    const a = document.createElement('a');
    a.href = '#heading-' + i;
    a.textContent = h.textContent;
    if (h.tagName==='H3') a.className='toc-h3';
    a.addEventListener('click', e => {
      e.preventDefault();
      h.scrollIntoView({behavior:'smooth',block:'start'});
    });
    tocLinks.appendChild(a);
  });
  tocBox.style.display='block';

  // Highlight active TOC item on scroll
  const observer = new IntersectionObserver(entries => {
    entries.forEach(e => {
      if (e.isIntersecting) {
        tocLinks.querySelectorAll('a').forEach(a => a.classList.remove('active'));
        const link = tocLinks.querySelector('a[href="#'+e.target.id+'"]');
        if (link) link.classList.add('active');
      }
    });
  }, {rootMargin:'-80px 0px -60% 0px'});
  headings.forEach(h => observer.observe(h));
})();

// ── Helpful Vote ──
let voted = sessionStorage.getItem('kb_voted_<?= $article['id'] ?>');
if (voted) {
  document.getElementById('vote'+voted.charAt(0).toUpperCase()+voted.slice(1))?.classList.add('voted');
}

function vote(v) {
  if (sessionStorage.getItem('kb_voted_<?= $article['id'] ?>')) return;
  sessionStorage.setItem('kb_voted_<?= $article['id'] ?>', v);
  document.getElementById('vote'+(v==='yes'?'Yes':'No')).classList.add('voted');

  fetch('<?= BASE_URL ?>/api/kb-action.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({csrf:'<?= $csrf ?>',action:'helpful_vote',id:<?= $article['id'] ?>,vote:v})
  });

  document.querySelector('.helpful-box p').textContent = v==='yes' ? '🎉 Thanks for the feedback!' : '😕 Thanks — we\'ll improve this article.';
}
</script>
</body>
</html>
