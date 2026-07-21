<?php
/**
 * page.php — Premium Legal/Custom Page Renderer v2
 * Access: /page/{slug}  (Nginx rewrite: ^/page/([a-z0-9\-]+)/?$ → page.php?slug=$1)
 */
require_once __DIR__ . '/includes/bootstrap.php';

$slug = trim(preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['slug'] ?? '')));

if (!$slug) {
    http_response_code(404);
    $page = null;
} else {
    $st = db()->prepare("SELECT * FROM legal_pages WHERE slug=? AND is_published=1 LIMIT 1");
    $st->execute([$slug]);
    $page = $st->fetch();
}

if (!$page) http_response_code(404);

$app_name  = APP_NAME;
$site_name = htmlspecialchars(get_setting('site_name', $app_name));
$logo      = get_setting('site_logo', '');
$meta_t    = $page ? htmlspecialchars($page['meta_title'] ?: $page['title'] . ' — ' . $app_name) : '404 Not Found';
$meta_d    = $page ? htmlspecialchars($page['meta_desc']) : '';

$footer_pages = db()->query("SELECT title, slug FROM legal_pages WHERE is_published=1 AND show_in_footer=1 ORDER BY sort_order ASC, id ASC")->fetchAll();
$logged_in = is_logged_in();

// Page icon map
$page_icons = [
    'privacy-policy'        => ['🔐', '#6366f1', 'Privacy'],
    'terms-of-service'      => ['📋', '#0891b2', 'Terms'],
    'refund-policy'         => ['💳', '#10b981', 'Refund'],
    'cookie-policy'         => ['🍪', '#f59e0b', 'Cookies'],
    'acceptable-use-policy' => ['🛡️', '#ef4444', 'AUP'],
];
$icon_data  = $page_icons[$slug] ?? ['📄', '#6366f1', 'Page'];
[$page_icon, $page_color, $page_short] = $icon_data;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $meta_t ?></title>
<?php if ($meta_d): ?><meta name="description" content="<?= $meta_d ?>"><?php endif; ?>
<meta property="og:title"       content="<?= $meta_t ?>">
<meta property="og:description" content="<?= $meta_d ?>">
<meta property="og:type"        content="website">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&family=Lora:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
<?php inject_global_head(); ?>
<style>
/* ══════════════════════════════════════════════════════
   LEGAL PAGE — PREMIUM REDESIGN
   Aesthetic: Editorial dark-accented with glass cards
   Font: Sora (display) + Lora (body prose) + JetBrains (mono)
══════════════════════════════════════════════════════ */
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
:root {
  --page-color: <?= $page_color ?>;
  --bg: #f6f7fb;
  --surface: #ffffff;
  --surface2: #f0f2f8;
  --border: #e4e6f0;
  --text: #0f1117;
  --text2: #4a5068;
  --text3: #8b90a8;
  --nav-h: 64px;
  --radius: 16px;
  --shadow-sm: 0 1px 3px rgba(15,17,23,.06), 0 1px 2px rgba(15,17,23,.04);
  --shadow-md: 0 4px 16px rgba(15,17,23,.08), 0 2px 6px rgba(15,17,23,.05);
  --shadow-lg: 0 12px 40px rgba(15,17,23,.12), 0 4px 12px rgba(15,17,23,.07);
}

html { scroll-behavior: smooth; }
body {
  font-family: 'Sora', sans-serif;
  background: var(--bg);
  color: var(--text);
  -webkit-font-smoothing: antialiased;
  overflow-x: hidden;
}

/* ── Reading progress bar ───────────────────────── */
#read-progress {
  position: fixed; top: 0; left: 0; z-index: 9999;
  height: 3px; width: 0%;
  background: linear-gradient(90deg, var(--page-color), #a78bfa, #38bdf8);
  transition: width .1s linear;
  border-radius: 0 99px 99px 0;
}

/* ── Navbar ─────────────────────────────────────── */
.pg-nav {
  position: sticky; top: 0; z-index: 100;
  height: var(--nav-h);
  background: rgba(246,247,251,.88);
  backdrop-filter: blur(20px) saturate(180%);
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center;
  padding: 0 max(24px, calc((100% - 1280px) / 2));
  gap: 16px;
}
.pg-nav-brand {
  display: flex; align-items: center; gap: 10px;
  text-decoration: none; flex-shrink: 0;
}
.pg-nav-mark {
  width: 36px; height: 36px; border-radius: 10px;
  background: var(--page-color);
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 2px 12px color-mix(in srgb, var(--page-color) 40%, transparent);
  font-size: 17px;
}
.pg-nav-name {
  font-size: 16px; font-weight: 800;
  color: var(--text); letter-spacing: -.4px;
}
.pg-nav-right {
  margin-left: auto; display: flex; align-items: center; gap: 10px;
}
.pg-nav-link {
  font-size: 13px; font-weight: 500; color: var(--text2);
  text-decoration: none; padding: 6px 14px;
  border-radius: 8px; transition: all .15s;
}
.pg-nav-link:hover { background: var(--surface2); color: var(--text); }
.pg-nav-btn {
  font-size: 13px; font-weight: 700;
  color: #fff; text-decoration: none;
  padding: 7px 18px; border-radius: 9px;
  background: var(--page-color);
  box-shadow: 0 2px 10px color-mix(in srgb, var(--page-color) 35%, transparent);
  transition: all .15s;
}
.pg-nav-btn:hover { opacity: .9; transform: translateY(-1px); }

/* ── HERO ────────────────────────────────────────── */
.pg-hero {
  position: relative; overflow: hidden;
  padding: 72px max(24px, calc((100% - 1280px) / 2)) 80px;
  background: linear-gradient(160deg, #0d0f1a 0%, #111827 60%, #0a0f1e 100%);
}
/* Animated mesh background */
.pg-hero::before {
  content: '';
  position: absolute; inset: 0; z-index: 0;
  background:
    radial-gradient(ellipse 60% 80% at 20% 50%, color-mix(in srgb, var(--page-color) 18%, transparent) 0%, transparent 60%),
    radial-gradient(ellipse 40% 60% at 80% 30%, rgba(99,102,241,.12) 0%, transparent 50%),
    radial-gradient(ellipse 50% 40% at 60% 90%, rgba(6,182,212,.08) 0%, transparent 50%);
}
/* Grid overlay */
.pg-hero::after {
  content: '';
  position: absolute; inset: 0; z-index: 0;
  background-image:
    linear-gradient(rgba(255,255,255,.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.03) 1px, transparent 1px);
  background-size: 52px 52px;
  mask-image: radial-gradient(ellipse 100% 100% at 50% 50%, black 30%, transparent 100%);
}

.pg-hero-inner {
  position: relative; z-index: 1;
  display: flex; flex-direction: column; align-items: flex-start;
  max-width: 720px;
}

/* Category pill */
.pg-pill {
  display: inline-flex; align-items: center; gap: 8px;
  background: color-mix(in srgb, var(--page-color) 15%, transparent);
  border: 1px solid color-mix(in srgb, var(--page-color) 30%, transparent);
  color: color-mix(in srgb, var(--page-color) 90%, #fff);
  font-size: 11px; font-weight: 700; letter-spacing: .8px;
  text-transform: uppercase; padding: 6px 14px;
  border-radius: 99px; margin-bottom: 24px;
  animation: fadeUp .5s ease both;
}
.pg-pill-dot {
  width: 6px; height: 6px; border-radius: 50%;
  background: var(--page-color);
  box-shadow: 0 0 8px var(--page-color);
  animation: glow 2s ease-in-out infinite;
}
@keyframes glow { 0%,100%{opacity:1} 50%{opacity:.3} }

.pg-hero-title {
  font-size: clamp(36px, 5.5vw, 64px);
  font-weight: 800; letter-spacing: -2.5px;
  color: #fff; line-height: 1.04;
  margin-bottom: 18px;
  animation: fadeUp .5s .1s ease both;
}
.pg-hero-title .accent {
  background: linear-gradient(90deg, var(--page-color), #a78bfa);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
  background-clip: text;
}

.pg-hero-sub {
  font-size: 15px; color: rgba(255,255,255,.5);
  line-height: 1.7; margin-bottom: 28px;
  animation: fadeUp .5s .2s ease both;
}

/* Meta badges row */
.pg-meta-row {
  display: flex; gap: 10px; flex-wrap: wrap;
  animation: fadeUp .5s .3s ease both;
}
.pg-meta-badge {
  display: inline-flex; align-items: center; gap: 6px;
  background: rgba(255,255,255,.06);
  border: 1px solid rgba(255,255,255,.1);
  border-radius: 8px; padding: 6px 12px;
  font-size: 12px; font-weight: 500; color: rgba(255,255,255,.6);
}
.pg-meta-badge svg { width: 13px; height: 13px; opacity: .7; }

/* Hero bottom fade */
.pg-hero-fade {
  position: absolute; bottom: 0; left: 0; right: 0; height: 60px;
  background: linear-gradient(to bottom, transparent, var(--bg));
  z-index: 2;
}

@keyframes fadeUp {
  from { opacity:0; transform:translateY(18px); }
  to   { opacity:1; transform:translateY(0); }
}

/* ── BODY LAYOUT ─────────────────────────────────── */
.pg-layout {
  display: grid;
  grid-template-columns: 1fr 260px;
  gap: 28px;
  max-width: 1100px;
  margin: 0 auto;
  padding: 40px 24px 80px;
  align-items: start;
}
@media (max-width: 960px) {
  .pg-layout { grid-template-columns: 1fr; }
  .pg-sidebar { display: none; }
}

/* ── ARTICLE CARD ────────────────────────────────── */
.pg-article {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  overflow: hidden;
  animation: fadeUp .4s .1s ease both;
}

/* Article top stripe */
.pg-article-stripe {
  height: 4px;
  background: linear-gradient(90deg, var(--page-color), #a78bfa, #38bdf8);
}

.pg-article-body {
  padding: 48px 56px 60px;
}
@media (max-width: 640px) {
  .pg-article-body { padding: 28px 24px 40px; }
}

/* ── CONTENT TYPOGRAPHY ──────────────────────────── */
.pg-content {
  font-family: 'Lora', Georgia, serif;
  font-size: 16px; line-height: 1.85;
  color: var(--text2);
}
.pg-content h1 {
  font-family: 'Sora', sans-serif;
  font-size: 28px; font-weight: 800;
  color: var(--text); letter-spacing: -1px;
  margin: 0 0 20px;
}
.pg-content h2 {
  font-family: 'Sora', sans-serif;
  font-size: 20px; font-weight: 700;
  color: var(--text); letter-spacing: -.5px;
  margin: 48px 0 14px;
  padding-bottom: 10px;
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: 10px;
  position: relative;
}
.pg-content h2::before {
  content: '';
  display: inline-block; width: 4px; height: 18px;
  background: var(--page-color);
  border-radius: 2px; flex-shrink: 0;
}
.pg-content h3 {
  font-family: 'Sora', sans-serif;
  font-size: 16px; font-weight: 700;
  color: var(--text); margin: 28px 0 10px;
}
.pg-content p {
  margin-bottom: 18px;
}
.pg-content a {
  color: var(--page-color); text-decoration: none;
  font-style: normal; font-weight: 600;
  border-bottom: 1px solid color-mix(in srgb, var(--page-color) 30%, transparent);
  transition: border-color .15s;
}
.pg-content a:hover {
  border-bottom-color: var(--page-color);
}
.pg-content strong {
  font-weight: 700; color: var(--text);
  font-style: normal;
}
.pg-content em { color: var(--text); }

/* Lists */
.pg-content ul, .pg-content ol {
  margin: 16px 0 20px 0; padding: 0;
  list-style: none;
}
.pg-content ul li, .pg-content ol li {
  position: relative; padding: 6px 0 6px 26px;
  font-size: 15px; color: var(--text2);
  border-bottom: 1px solid var(--border);
}
.pg-content ul li:last-child, .pg-content ol li:last-child {
  border-bottom: none;
}
.pg-content ul li::before {
  content: '';
  position: absolute; left: 0; top: 14px;
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--page-color);
  box-shadow: 0 0 6px color-mix(in srgb, var(--page-color) 40%, transparent);
}
.pg-content ol { counter-reset: item; }
.pg-content ol li { counter-increment: item; }
.pg-content ol li::before {
  content: counter(item);
  position: absolute; left: 0; top: 5px;
  width: 20px; height: 20px; border-radius: 6px;
  background: color-mix(in srgb, var(--page-color) 12%, transparent);
  color: var(--page-color); font-size: 11px; font-weight: 800;
  font-family: 'Sora', sans-serif;
  display: flex; align-items: center; justify-content: center;
}

/* Blockquote */
.pg-content blockquote {
  margin: 24px 0;
  padding: 20px 24px;
  background: color-mix(in srgb, var(--page-color) 6%, transparent);
  border-left: 4px solid var(--page-color);
  border-radius: 0 12px 12px 0;
  font-style: italic; color: var(--text);
}

/* Code */
.pg-content code {
  font-family: 'JetBrains Mono', monospace;
  font-size: 13px; font-weight: 500;
  background: var(--surface2);
  color: var(--page-color);
  padding: 2px 7px; border-radius: 5px;
  border: 1px solid var(--border);
}
.pg-content pre {
  font-family: 'JetBrains Mono', monospace;
  font-size: 13px;
  background: #0d0f1a; color: #e2e8f0;
  padding: 22px; border-radius: 12px;
  overflow-x: auto; margin: 20px 0;
  border: 1px solid #1e2236;
  line-height: 1.65;
}

/* Tables */
.pg-content table {
  width: 100%; border-collapse: collapse;
  margin: 20px 0; font-size: 14px;
  border-radius: 10px; overflow: hidden;
  border: 1px solid var(--border);
}
.pg-content thead th {
  background: var(--surface2);
  padding: 11px 16px; text-align: left;
  font-family: 'Sora', sans-serif;
  font-size: 12px; font-weight: 700;
  color: var(--text); text-transform: uppercase;
  letter-spacing: .5px;
}
.pg-content tbody td {
  padding: 11px 16px;
  border-top: 1px solid var(--border);
  color: var(--text2);
}
.pg-content tbody tr:hover td { background: var(--surface2); }

/* HR */
.pg-content hr {
  border: none; border-top: 1px solid var(--border);
  margin: 36px 0;
}

/* ── HIGHLIGHT BOXES (special blockquotes) ────────── */
/* Usage in content: <div class="note"> ... </div> */
.pg-content .note, .pg-content .warning, .pg-content .tip {
  padding: 16px 20px; border-radius: 12px;
  margin: 20px 0; font-size: 14px; font-style: normal;
}
.pg-content .note    { background: rgba(99,102,241,.08); border: 1px solid rgba(99,102,241,.2); color: var(--text); }
.pg-content .warning { background: rgba(245,158,11,.08); border: 1px solid rgba(245,158,11,.2); color: var(--text); }
.pg-content .tip     { background: rgba(16,185,129,.08); border: 1px solid rgba(16,185,129,.2); color: var(--text); }

/* ── Article footer ──────────────────────────────── */
.pg-article-footer {
  padding: 20px 56px 28px;
  border-top: 1px solid var(--border);
  display: flex; align-items: center; gap: 14px;
  flex-wrap: wrap;
}
@media (max-width: 640px) { .pg-article-footer { padding: 16px 24px; } }
.pg-af-badge {
  display: inline-flex; align-items: center; gap: 7px;
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 8px; padding: 7px 14px;
  font-size: 12px; font-weight: 600; color: var(--text2);
}
.pg-af-badge span { color: var(--page-color); font-weight: 700; }
.pg-af-share {
  margin-left: auto; display: flex; gap: 8px;
}
.pg-af-share button {
  width: 34px; height: 34px; border-radius: 8px;
  background: var(--surface2); border: 1px solid var(--border);
  cursor: pointer; display: flex; align-items: center; justify-content: center;
  color: var(--text3); transition: all .15s;
}
.pg-af-share button:hover { background: var(--page-color); color: #fff; border-color: var(--page-color); }

/* ── SIDEBAR ─────────────────────────────────────── */
.pg-sidebar {
  position: sticky; top: calc(var(--nav-h) + 20px);
  display: flex; flex-direction: column; gap: 16px;
  animation: fadeUp .4s .2s ease both;
}

/* TOC card */
.pg-toc-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  overflow: hidden;
  box-shadow: var(--shadow-sm);
}
.pg-toc-head {
  padding: 14px 18px;
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: 8px;
  background: var(--surface2);
}
.pg-toc-head span {
  font-size: 11px; font-weight: 700; color: var(--text2);
  text-transform: uppercase; letter-spacing: .8px;
}
.pg-toc-list { padding: 10px 0; }
.pg-toc-item {
  display: flex; align-items: center; gap: 8px;
  padding: 7px 18px;
  font-size: 13px; font-weight: 500; color: var(--text2);
  text-decoration: none; transition: all .15s;
  border-left: 3px solid transparent;
  cursor: pointer;
}
.pg-toc-item:hover, .pg-toc-item.active {
  color: var(--page-color);
  border-left-color: var(--page-color);
  background: color-mix(in srgb, var(--page-color) 5%, transparent);
}
.pg-toc-num {
  width: 18px; height: 18px; border-radius: 5px;
  background: var(--surface2); border: 1px solid var(--border);
  font-size: 9px; font-weight: 800; font-family: 'JetBrains Mono', monospace;
  color: var(--text3); display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; transition: all .15s;
}
.pg-toc-item.active .pg-toc-num,
.pg-toc-item:hover .pg-toc-num {
  background: var(--page-color); color: #fff; border-color: var(--page-color);
}

/* Page info card */
.pg-info-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 18px;
  box-shadow: var(--shadow-sm);
  font-size: 13px;
}
.pg-info-row {
  display: flex; align-items: center; justify-content: space-between;
  padding: 8px 0; border-bottom: 1px solid var(--border);
}
.pg-info-row:last-child { border-bottom: none; padding-bottom: 0; }
.pg-info-label { color: var(--text3); font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; }
.pg-info-val   { color: var(--text); font-weight: 600; font-size: 12px; text-align: right; }

/* Related pages card */
.pg-related-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  overflow: hidden;
  box-shadow: var(--shadow-sm);
}
.pg-related-head {
  padding: 12px 18px;
  background: var(--surface2);
  border-bottom: 1px solid var(--border);
  font-size: 11px; font-weight: 700; color: var(--text2);
  text-transform: uppercase; letter-spacing: .8px;
}
.pg-related-link {
  display: flex; align-items: center; gap: 10px;
  padding: 11px 18px;
  border-bottom: 1px solid var(--border);
  text-decoration: none; transition: background .15s;
}
.pg-related-link:last-child { border-bottom: none; }
.pg-related-link:hover { background: var(--surface2); }
.pg-related-icon {
  width: 28px; height: 28px; border-radius: 7px;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; flex-shrink: 0;
  background: var(--surface2); border: 1px solid var(--border);
}
.pg-related-title {
  font-size: 12.5px; font-weight: 600; color: var(--text);
  line-height: 1.3;
}
.pg-related-link.current .pg-related-title { color: var(--page-color); }
.pg-related-arrow {
  margin-left: auto; color: var(--text3); opacity: .5;
  font-size: 12px;
}

/* ── 404 ─────────────────────────────────────────── */
.pg-404 {
  text-align: center; padding: 100px 24px;
  max-width: 500px; margin: 0 auto;
}
.pg-404-code {
  font-size: 100px; font-weight: 900; font-family: 'JetBrains Mono', monospace;
  color: var(--border); line-height: 1; margin-bottom: 16px;
}
.pg-404 h2 { font-size: 24px; font-weight: 800; color: var(--text); margin-bottom: 10px; }
.pg-404 p  { color: var(--text2); margin-bottom: 28px; }
.pg-404-btn {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 11px 24px; border-radius: 10px;
  background: var(--page-color); color: #fff;
  text-decoration: none; font-weight: 700; font-size: 14px;
}

/* ── FOOTER ─────────────────────────────────────── */
.pg-footer {
  background: #0d0f1a;
  border-top: 1px solid rgba(255,255,255,.06);
  padding: 40px max(24px, calc((100% - 1280px) / 2));
}
.pg-footer-inner {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 20px;
}
.pg-footer-brand {
  display: flex; align-items: center; gap: 10px;
}
.pg-footer-mark {
  width: 30px; height: 30px; border-radius: 8px;
  background: var(--page-color);
  display: flex; align-items: center; justify-content: center;
  font-size: 14px;
}
.pg-footer-name { font-size: 15px; font-weight: 800; color: #fff; }
.pg-footer-copy { font-size: 12px; color: rgba(255,255,255,.25); margin-top: 4px; }
.pg-footer-links { display: flex; gap: 6px; flex-wrap: wrap; }
.pg-footer-link {
  font-size: 12.5px; font-weight: 500;
  color: rgba(255,255,255,.4); text-decoration: none;
  padding: 5px 12px; border-radius: 7px;
  border: 1px solid rgba(255,255,255,.07);
  transition: all .15s;
}
.pg-footer-link:hover { color: #fff; border-color: rgba(255,255,255,.2); background: rgba(255,255,255,.05); }
.pg-footer-link.active-page {
  color: var(--page-color);
  border-color: color-mix(in srgb, var(--page-color) 30%, transparent);
  background: color-mix(in srgb, var(--page-color) 8%, transparent);
}

/* ── Scroll-to-top ──────────────────────────────── */
#scroll-top {
  position: fixed; bottom: 28px; right: 28px; z-index: 500;
  width: 42px; height: 42px; border-radius: 12px;
  background: var(--text); color: #fff;
  border: none; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  box-shadow: var(--shadow-md);
  opacity: 0; transform: translateY(10px);
  transition: all .25s; pointer-events: none;
}
#scroll-top.visible { opacity: 1; transform: translateY(0); pointer-events: all; }
#scroll-top:hover { background: var(--page-color); transform: translateY(-2px); }
</style>
</head>
<body>

<!-- Reading progress -->
<div id="read-progress"></div>

<!-- Navbar -->
<nav class="pg-nav">
  <a href="<?= BASE_URL ?>" class="pg-nav-brand">
    <?php if ($logo): ?>
      <img src="<?= htmlspecialchars($logo) ?>" alt="<?= $site_name ?>" style="height:32px;object-fit:contain;">
    <?php else: ?>
      <div class="pg-nav-mark"><?= $page_icon ?></div>
    <?php endif; ?>
    <span class="pg-nav-name"><?= $site_name ?></span>
  </a>
  <div class="pg-nav-right">
    <a href="<?= BASE_URL ?>" class="pg-nav-link">Home</a>
    <?php if ($logged_in): ?>
      <a href="<?= BASE_URL ?>/dashboard.php" class="pg-nav-btn">Dashboard →</a>
    <?php else: ?>
      <a href="<?= BASE_URL ?>/login.php"    class="pg-nav-link">Sign In</a>
      <a href="<?= BASE_URL ?>/register.php" class="pg-nav-btn">Get Started</a>
    <?php endif; ?>
  </div>
</nav>

<?php if ($page): ?>

<!-- Hero -->
<div class="pg-hero">
  <div class="pg-hero-inner">
    <div class="pg-pill">
      <span class="pg-pill-dot"></span>
      <?= $page_short ?> · Legal Document
    </div>
    <h1 class="pg-hero-title">
      <?= htmlspecialchars($page['title']) ?>
    </h1>
    <p class="pg-hero-sub">
      <?= $page['meta_desc'] ? htmlspecialchars($page['meta_desc']) : 'Please read this document carefully. It outlines the terms, policies, and guidelines that apply when using our services.' ?>
    </p>
    <div class="pg-meta-row">
      <div class="pg-meta-badge">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Last updated: <?= date('d M Y', strtotime($page['updated_at'])) ?>
      </div>
      <div class="pg-meta-badge">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        ~<?= max(1, round(str_word_count(strip_tags($page['content'])) / 200)) ?> min read
      </div>
      <div class="pg-meta-badge">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <?= $site_name ?> Legal
      </div>
    </div>
  </div>
  <div class="pg-hero-fade"></div>
</div>

<!-- Body -->
<div class="pg-layout">

  <!-- Article -->
  <main>
    <div class="pg-article">
      <div class="pg-article-stripe"></div>
      <div class="pg-article-body">
        <div class="pg-content" id="pg-content">
          <?= $page['content'] ?>
        </div>
      </div>
      <div class="pg-article-footer">
        <div class="pg-af-badge">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          Updated <span><?= date('d M Y', strtotime($page['updated_at'])) ?></span>
        </div>
        <div class="pg-af-badge">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          <span><?= $site_name ?></span>
        </div>
        <div class="pg-af-share">
          <button onclick="copyLink()" title="Copy link">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
          </button>
          <button onclick="window.print()" title="Print">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
          </button>
        </div>
      </div>
    </div>
  </main>

  <!-- Sidebar -->
  <aside class="pg-sidebar">

    <!-- TOC -->
    <div class="pg-toc-card">
      <div class="pg-toc-head">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/><line x1="3" y1="18" x2="18" y2="18"/></svg>
        <span>Table of Contents</span>
      </div>
      <div class="pg-toc-list" id="toc-list">
        <div style="padding:14px 18px;font-size:12px;color:var(--text3);">Loading…</div>
      </div>
    </div>

    <!-- Page info -->
    <div class="pg-info-card">
      <div class="pg-info-row">
        <span class="pg-info-label">Document</span>
        <span class="pg-info-val"><?= htmlspecialchars($page['title']) ?></span>
      </div>
      <div class="pg-info-row">
        <span class="pg-info-label">Version</span>
        <span class="pg-info-val">v<?= date('Y.m', strtotime($page['updated_at'])) ?></span>
      </div>
      <div class="pg-info-row">
        <span class="pg-info-label">Words</span>
        <span class="pg-info-val"><?= number_format(str_word_count(strip_tags($page['content']))) ?></span>
      </div>
      <div class="pg-info-row">
        <span class="pg-info-label">Read Time</span>
        <span class="pg-info-val">~<?= max(1, round(str_word_count(strip_tags($page['content'])) / 200)) ?> min</span>
      </div>
    </div>

    <!-- Related pages -->
    <?php if (count($footer_pages) > 1): ?>
    <div class="pg-related-card">
      <div class="pg-related-head">Other Policies</div>
      <?php foreach ($footer_pages as $fp):
        $fp_icon = $page_icons[$fp['slug']][0] ?? '📄';
        $is_cur  = $page['slug'] === $fp['slug'];
      ?>
      <a href="<?= BASE_URL ?>/page/<?= htmlspecialchars($fp['slug']) ?>"
         class="pg-related-link <?= $is_cur ? 'current' : '' ?>">
        <div class="pg-related-icon"><?= $fp_icon ?></div>
        <div class="pg-related-title"><?= htmlspecialchars($fp['title']) ?></div>
        <?php if (!$is_cur): ?><span class="pg-related-arrow">›</span><?php endif; ?>
        <?php if ($is_cur): ?><span style="font-size:10px;color:var(--page-color);font-weight:700;">Current</span><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </aside>

</div>

<?php else: ?>
<!-- 404 -->
<div class="pg-404">
  <div class="pg-404-code">404</div>
  <h2>Page Not Found</h2>
  <p>This page doesn't exist or has been removed.</p>
  <a href="<?= BASE_URL ?>" class="pg-404-btn">← Go Home</a>
</div>
<?php endif; ?>

<!-- Footer -->
<footer class="pg-footer">
  <div class="pg-footer-inner">
    <div>
      <div class="pg-footer-brand">
        <div class="pg-footer-mark"><?= $page_icon ?></div>
        <div>
          <div class="pg-footer-name"><?= $site_name ?></div>
          <div class="pg-footer-copy">© <?= date('Y') ?> <?= $site_name ?>. All rights reserved.</div>
        </div>
      </div>
    </div>
    <div class="pg-footer-links">
      <?php foreach ($footer_pages as $fp): ?>
        <a href="<?= BASE_URL ?>/page/<?= htmlspecialchars($fp['slug']) ?>"
           class="pg-footer-link <?= ($page && $page['slug'] === $fp['slug']) ? 'active-page' : '' ?>">
          <?= $page_icons[$fp['slug']][0] ?? '📄' ?> <?= htmlspecialchars($fp['title']) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</footer>

<!-- Scroll to top -->
<button id="scroll-top" onclick="window.scrollTo({top:0,behavior:'smooth'})" title="Back to top">
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="18 15 12 9 6 15"/></svg>
</button>

<script>
// ── Reading progress ──────────────────────────────
const prog = document.getElementById('read-progress');
window.addEventListener('scroll', () => {
  const docH = document.documentElement.scrollHeight - window.innerHeight;
  prog.style.width = (window.scrollY / docH * 100) + '%';
  // Scroll-to-top visibility
  document.getElementById('scroll-top').classList.toggle('visible', window.scrollY > 400);
});

// ── Auto-generate TOC from h2 tags ────────────────
(function buildTOC() {
  const headings = document.querySelectorAll('#pg-content h2');
  const tocList  = document.getElementById('toc-list');
  if (!headings.length) {
    tocList.innerHTML = '<div style="padding:14px 18px;font-size:12px;color:var(--text3);">No sections found.</div>';
    return;
  }
  let html = '';
  headings.forEach((h, i) => {
    const id = 'sec-' + i;
    h.id = id;
    const text = h.textContent.replace(/^\d+\.\s*/, '').trim();
    html += `<a class="pg-toc-item" href="#${id}" data-id="${id}">
      <span class="pg-toc-num">${String(i+1).padStart(2,'0')}</span>
      ${text}
    </a>`;
  });
  tocList.innerHTML = html;

  // Active TOC item on scroll (IntersectionObserver)
  const obs = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      const link = tocList.querySelector(`[data-id="${entry.target.id}"]`);
      if (link) link.classList.toggle('active', entry.isIntersecting);
    });
  }, { rootMargin: '-20% 0px -75% 0px' });

  headings.forEach(h => obs.observe(h));
})();

// ── Copy link ─────────────────────────────────────
function copyLink() {
  navigator.clipboard.writeText(window.location.href).then(() => {
    const btn = event.currentTarget;
    btn.style.background = 'var(--page-color)';
    btn.style.color = '#fff';
    setTimeout(() => { btn.style.background=''; btn.style.color=''; }, 1500);
  });
}
</script>
</body>
</html>