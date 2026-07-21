<?php
require_once __DIR__ . '/includes/bootstrap.php';
session_start_safe();

$logged_in = is_logged_in();
$current   = $logged_in ? current_user() : null;
$uname     = $current ? htmlspecialchars($current['username']) : '';
$avatar    = $current ? strtoupper(mb_substr($current['full_name'] ?: $current['username'], 0, 1)) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title><?= APP_NAME ?> — Deploy VPS in Seconds</title>
  <meta name="description" content="Blazing-fast VPS hosting powered by <?= APP_NAME ?> infrastructure. Deploy in seconds, scale instantly, pay as you go.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    /* ── RESET & BASE ─────────────────────────── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { scroll-behavior: smooth; }
    body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--white); color: var(--gray-900); -webkit-font-smoothing: antialiased; }

    /* ── NAV ──────────────────────────────────── */
    nav {
      position: fixed; top: 0; left: 0; right: 0; z-index: 200;
      backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
      background: rgba(255,255,255,0.92);
      border-bottom: 1px solid rgba(226,232,240,0.9);
      height: 60px; display: flex; align-items: center;
      padding: 0 max(20px, calc((100% - 1200px) / 2));
      gap: 28px;
    }
    .nav-logo { display: flex; align-items: center; gap: 9px; text-decoration: none; flex-shrink: 0; }
    .nav-logo-mark {
      width: 32px; height: 32px; border-radius: 8px; background: var(--primary);
      display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .nav-logo-mark svg { width: 17px; height: 17px; }
    .nav-logo-text { font-weight: 800; font-size: 16px; color: var(--gray-900); letter-spacing: -.3px; }
    .nav-links { display: flex; align-items: center; gap: 2px; margin-left: 6px; }
    .nav-link {
      padding: 6px 13px; border-radius: 7px; font-size: 13.5px; font-weight: 500;
      color: var(--gray-600); text-decoration: none; transition: all .15s;
    }
    .nav-link:hover { background: var(--gray-100); color: var(--gray-900); }
    .nav-actions { margin-left: auto; display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

    /* Ghost / Primary buttons */
    .nav-btn-ghost {
      padding: 7px 16px; border-radius: 8px; font-size: 13.5px; font-weight: 600;
      color: var(--gray-700); text-decoration: none; border: 1px solid var(--border);
      transition: all .15s; background: white; display: inline-flex; align-items: center; gap: 6px;
    }
    .nav-btn-ghost:hover { background: var(--gray-50); border-color: var(--gray-300); }
    .nav-btn-primary {
      padding: 7px 18px; border-radius: 8px; font-size: 13.5px; font-weight: 700;
      color: white; text-decoration: none; background: var(--primary);
      transition: all .15s; box-shadow: 0 1px 3px var(--primary-hover);
      display: inline-flex; align-items: center; gap: 6px;
    }
    .nav-btn-primary:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 4px 12px var(--primary-hover); }

    /* Dashboard button — logged in state */
    .nav-btn-dashboard {
      padding: 6px 14px 6px 8px; border-radius: 9px; font-size: 13.5px; font-weight: 700;
      color: white; text-decoration: none; background: var(--primary);
      transition: all .18s; box-shadow: 0 2px 8px rgba(37,99,235,.28);
      display: inline-flex; align-items: center; gap: 8px;
    }
    .nav-btn-dashboard:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 5px 14px rgba(37,99,235,.38); }
    .nav-avatar {
      width: 26px; height: 26px; border-radius: 6px;
      background: rgba(255,255,255,.25); border: 1.5px solid rgba(255,255,255,.4);
      display: flex; align-items: center; justify-content: center;
      font-size: 11px; font-weight: 800; color: white; flex-shrink: 0;
    }

    /* ── HAMBURGER BUTTON ─────────────────── */
    .nav-hamburger {
      display: none;
      width: 38px; height: 38px; border-radius: 9px;
      background: var(--gray-100); border: 1px solid var(--border);
      align-items: center; justify-content: center;
      cursor: pointer; flex-direction: column; gap: 5px;
      margin-left: auto; flex-shrink: 0;
      transition: background .15s;
    }
    .nav-hamburger:hover { background: var(--gray-200); }
    .ham-line {
      width: 18px; height: 2px; background: var(--gray-700); border-radius: 2px;
      transition: all .25s cubic-bezier(0.4, 0, 0.2, 1);
      transform-origin: center;
    }
    /* X animation */
    .nav-hamburger.open .ham-line:nth-child(1) { transform: translateY(7px) rotate(45deg); }
    .nav-hamburger.open .ham-line:nth-child(2) { opacity: 0; transform: scaleX(0); }
    .nav-hamburger.open .ham-line:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

    /* ── MOBILE DRAWER ────────────────────── */
    .mobile-drawer {
      display: none;
      position: fixed; top: 60px; left: 0; right: 0; z-index: 190;
      background: white; border-bottom: 1px solid var(--border);
      box-shadow: 0 12px 32px rgba(0,0,0,.10);
      padding: 12px 20px 20px;
      /* slide animation */
      transform: translateY(-8px); opacity: 0;
      transition: transform .25s cubic-bezier(0.4,0,0.2,1), opacity .2s ease;
      pointer-events: none;
    }
    .mobile-drawer.open {
      transform: translateY(0); opacity: 1;
      pointer-events: all;
    }
    .mob-link {
      display: flex; align-items: center; gap: 10px;
      padding: 11px 12px; border-radius: 9px; font-size: 14px; font-weight: 600;
      color: var(--gray-700); text-decoration: none; transition: all .14s;
    }
    .mob-link:hover { background: var(--gray-100); color: var(--gray-900); }
    .mob-link svg { width: 17px; height: 17px; flex-shrink: 0; color: var(--gray-400); }
    .mob-divider { height: 1px; background: var(--border); margin: 8px 0; }
    .mob-actions { display: flex; flex-direction: column; gap: 8px; margin-top: 4px; }
    .mob-btn {
      display: flex; align-items: center; justify-content: center; gap: 8px;
      padding: 12px; border-radius: 10px; font-size: 14px; font-weight: 700;
      text-decoration: none; transition: all .16s;
    }
    .mob-btn-ghost {
      background: white; color: var(--gray-700);
      border: 1.5px solid var(--border);
    }
    .mob-btn-ghost:hover { background: var(--gray-50); }
    .mob-btn-primary {
      background: var(--primary); color: white;
      box-shadow: 0 2px 8px rgba(37,99,235,.28);
    }
    .mob-btn-primary:hover { background: var(--primary-hover); }
    .mob-btn-dashboard {
      background: var(--primary); color: white;
      box-shadow: 0 2px 8px rgba(37,99,235,.28);
    }
    /* Logged-in user chip in drawer */
    .mob-user-chip {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 12px; background: var(--gray-50); border: 1px solid var(--border);
      border-radius: 10px; margin-bottom: 10px;
    }
    .mob-avatar {
      width: 34px; height: 34px; border-radius: 8px; background: var(--primary);
      display: flex; align-items: center; justify-content: center;
      font-size: 13px; font-weight: 800; color: white; flex-shrink: 0;
    }
    .mob-username { font-size: 13.5px; font-weight: 700; color: var(--gray-900); }
    .mob-user-label { font-size: 11px; color: var(--gray-400); font-weight: 500; }

    /* ── HERO ─────────────────────────────────── */
    .hero {
      padding: 140px max(24px, calc((100% - 1120px) / 2)) 80px;
      text-align: center; position: relative; overflow: hidden;
    }
    .hero-bg {
      position: absolute; inset: 0; z-index: 0;
      background:
        radial-gradient(ellipse 80% 50% at 50% -10%, rgba(37,99,235,0.10) 0%, transparent 70%),
        radial-gradient(ellipse 40% 30% at 80% 20%, rgba(6,182,212,0.08) 0%, transparent 60%);
    }
    .hero-badge {
      display: inline-flex; align-items: center; gap: 7px;
      background: var(--primary-light); border: 1px solid #bfdbfe;
      color: var(--primary); font-size: 12px; font-weight: 700;
      padding: 5px 13px; border-radius: 99px; margin-bottom: 24px;
      letter-spacing: .3px; text-transform: uppercase; position: relative; z-index: 1;
    }
    .hero-badge-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--primary); animation: pulse 2s infinite; }
    @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(1.3)} }
    .hero-title {
      font-size: clamp(38px, 6vw, 72px); font-weight: 900; line-height: 1.06;
      letter-spacing: -2.5px; color: var(--gray-900); margin-bottom: 20px;
      position: relative; z-index: 1;
    }
    .hero-title .accent-blue { color: var(--primary); }
    .hero-title .accent-cyan { color: var(--accent); }
    .hero-sub {
      font-size: 17px; color: var(--gray-500); max-width: 560px; margin: 0 auto 36px;
      line-height: 1.7; position: relative; z-index: 1; font-weight: 400;
    }
    .hero-cta { display: flex; align-items: center; justify-content: center; gap: 12px; flex-wrap: wrap; position: relative; z-index: 1; }
    .btn-hero-primary {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 14px 28px; border-radius: 10px; font-size: 15px; font-weight: 700;
      background: var(--primary); color: white; text-decoration: none;
      box-shadow: 0 4px 16px var(--primary-hover); transition: all .2s;
    }
    .btn-hero-primary:hover { background: var(--primary-hover); transform: translateY(-2px); box-shadow: 0 8px 24px rgba(37,99,235,.4); }
    .btn-hero-secondary {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 14px 24px; border-radius: 10px; font-size: 15px; font-weight: 600;
      background: white; color: var(--gray-700); text-decoration: none;
      border: 1.5px solid var(--border); transition: all .2s;
    }
    .btn-hero-secondary:hover { border-color: var(--gray-300); background: var(--gray-50); transform: translateY(-1px); }

    /* Hero Stats */
    .hero-stats {
      display: flex; align-items: center; justify-content: center; gap: 32px;
      margin-top: 52px; position: relative; z-index: 1; flex-wrap: wrap;
    }
    .hero-stat { text-align: center; }
    .hero-stat-num { font-size: 28px; font-weight: 900; color: var(--gray-900); letter-spacing: -1px; line-height: 1; }
    .hero-stat-label { font-size: 12px; color: var(--gray-500); font-weight: 500; margin-top: 3px; }
    .hero-divider { width: 1px; height: 36px; background: var(--border); }

    /* ── TERMINAL DEMO ───────────────────────── */
    .demo-section {
      padding: 0 max(24px, calc((100% - 1120px) / 2)) 80px;
      position: relative;
    }
    .terminal-wrap {
      max-width: 720px; margin: 0 auto;
      background: #0d1117; border-radius: 14px;
      overflow: hidden; box-shadow: 0 24px 64px rgba(0,0,0,.18), 0 0 0 1px rgba(255,255,255,.05);
    }
    .term-bar {
      height: 38px; background: #161b22; display: flex; align-items: center;
      padding: 0 14px; gap: 7px; border-bottom: 1px solid rgba(255,255,255,.06);
    }
    .term-dot { width: 11px; height: 11px; border-radius: 50%; }
    .term-title { font-size: 12px; color: #8b949e; margin-left: auto; font-family: 'JetBrains Mono', monospace; }
    .term-body { padding: 22px 26px; font-family: 'JetBrains Mono', monospace; font-size: 13px; line-height: 1.9; }
    .term-line { display: flex; gap: 10px; }
    .term-prompt { color: #3fb950; flex-shrink: 0; }
    .term-cmd { color: #e6edf3; }
    .term-comment { color: #8b949e; }
    .term-out { color: #58a6ff; }
    .term-success { color: #3fb950; }
    .term-warn { color: #d29922; }
    .term-cursor { display: inline-block; width: 8px; height: 14px; background: #3fb950; border-radius: 1px; animation: blink 1.1s step-end infinite; vertical-align: middle; margin-left: 2px; }
    @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0} }

    /* ── FEATURES ─────────────────────────────── */
    .section {
      padding: 80px max(24px, calc((100% - 1120px) / 2));
    }
    .section-tag {
      display: inline-block; font-size: 11px; font-weight: 700; letter-spacing: 1px;
      text-transform: uppercase; color: var(--primary); margin-bottom: 10px;
    }
    .section-title {
      font-size: clamp(28px, 4vw, 44px); font-weight: 900; letter-spacing: -1.5px;
      color: var(--gray-900); margin-bottom: 12px; line-height: 1.1;
    }
    .section-sub { font-size: 16px; color: var(--gray-500); max-width: 520px; line-height: 1.6; }
    .section-header { margin-bottom: 52px; }

    .features-grid {
      display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;
    }
    .feat-card {
      background: var(--white); border: 1px solid var(--border);
      border-radius: 14px; padding: 28px; transition: all .2s;
    }
    .feat-card:hover { border-color: var(--primary); transform: translateY(-3px); box-shadow: 0 12px 32px rgba(37,99,235,.08); }
    .feat-icon-wrap {
      width: 46px; height: 46px; border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
      font-size: 22px; margin-bottom: 18px;
    }
    .feat-card-title { font-size: 15px; font-weight: 800; color: var(--gray-900); margin-bottom: 7px; }
    .feat-card-desc { font-size: 13.5px; color: var(--gray-500); line-height: 1.6; }

    /* ── PRICING ──────────────────────────────── */
    .pricing-section {
      background: var(--gray-50); padding: 80px max(24px, calc((100% - 1120px) / 2));
      border-top: 1px solid var(--border); border-bottom: 1px solid var(--border);
    }
    .pricing-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
    .price-card {
      background: white; border: 1.5px solid var(--border); border-radius: 16px;
      padding: 32px; transition: all .2s; position: relative;
    }
    .price-card.popular {
      border-color: var(--primary); box-shadow: 0 0 0 4px rgba(37,99,235,.07);
    }
    .popular-badge {
      position: absolute; top: -12px; left: 50%; transform: translateX(-50%);
      background: var(--primary); color: white; font-size: 11px; font-weight: 700;
      padding: 3px 12px; border-radius: 99px; letter-spacing: .5px; text-transform: uppercase;
    }
    .price-plan { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--gray-500); margin-bottom: 8px; }
    .price-amount { font-size: 40px; font-weight: 900; color: var(--gray-900); letter-spacing: -2px; line-height: 1; }
    .price-amount span { font-size: 16px; font-weight: 500; color: var(--gray-400); letter-spacing: 0; }
    .price-desc { font-size: 13px; color: var(--gray-500); margin: 10px 0 24px; line-height: 1.5; }
    .price-feats { list-style: none; margin-bottom: 28px; }
    .price-feats li { font-size: 13.5px; color: var(--gray-600); padding: 7px 0; border-bottom: 1px solid var(--gray-100); display: flex; align-items: center; gap: 8px; }
    .price-feats li:last-child { border: none; }
    .price-feats li::before { content: '✓'; color: var(--primary); font-weight: 800; flex-shrink: 0; font-size: 12px; }
    .price-specs {
      display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 24px;
    }
    .spec-chip {
      background: var(--gray-50); border: 1px solid var(--border); border-radius: 8px;
      padding: 8px 10px; font-size: 12px; font-weight: 600; color: var(--gray-700);
      font-family: 'JetBrains Mono', monospace; text-align: center;
    }
    .price-btn {
      display: block; width: 100%; padding: 12px; border-radius: 9px; font-size: 14px;
      font-weight: 700; text-align: center; text-decoration: none; transition: all .2s;
    }
    .price-btn-primary { background: var(--primary); color: white; }
    .price-btn-primary:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(37,99,235,.3); }
    .price-btn-ghost { border: 1.5px solid var(--border); color: var(--gray-700); }
    .price-btn-ghost:hover { border-color: var(--primary); color: var(--primary); }

    /* ── INFRA BADGES ─────────────────────────── */
    .infra-section {
      padding: 64px max(24px, calc((100% - 1120px) / 2));
      text-align: center;
    }
    .infra-label { font-size: 12px; font-weight: 600; color: var(--gray-400); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 24px; }
    .infra-badges { display: flex; align-items: center; justify-content: center; gap: 12px; flex-wrap: wrap; }
    .infra-badge {
      background: var(--gray-50); border: 1px solid var(--border); border-radius: 10px;
      padding: 10px 18px; font-size: 13px; font-weight: 700; color: var(--gray-700);
      display: flex; align-items: center; gap: 7px;
    }

    /* ── HOW IT WORKS ──────────────────────────── */
    .steps-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; }
    .step-card { text-align: center; padding: 24px 16px; }
    .step-num {
      width: 42px; height: 42px; border-radius: 12px; background: var(--primary-light);
      color: var(--primary); font-size: 18px; font-weight: 900; display: flex;
      align-items: center; justify-content: center; margin: 0 auto 16px;
    }
    .step-title { font-size: 14px; font-weight: 800; color: var(--gray-900); margin-bottom: 6px; }
    .step-desc { font-size: 13px; color: var(--gray-500); line-height: 1.6; }

    /* ── CTA BANNER ───────────────────────────── */
    .cta-section {
      margin: 0 max(24px, calc((100% - 1120px) / 2)) 80px;
      background: var(--primary); border-radius: 20px;
      padding: 60px 48px; text-align: center; position: relative; overflow: hidden;
    }
    .cta-bg {
      position: absolute; inset: 0;
      background: radial-gradient(ellipse 60% 80% at 80% 50%, rgba(6,182,212,.25) 0%, transparent 70%),
                  radial-gradient(ellipse 50% 60% at 20% 50%, rgba(139,92,246,.2) 0%, transparent 70%);
    }
    .cta-title { font-size: clamp(26px, 4vw, 42px); font-weight: 900; color: white; letter-spacing: -1.5px; margin-bottom: 12px; position: relative; z-index: 1; }
    .cta-sub { font-size: 16px; color: rgba(255,255,255,.75); margin-bottom: 32px; position: relative; z-index: 1; }
    .btn-cta-white {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 13px 28px; border-radius: 10px; font-size: 15px; font-weight: 700;
      background: white; color: var(--primary); text-decoration: none;
      box-shadow: 0 4px 16px rgba(0,0,0,.15); transition: all .2s; position: relative; z-index: 1;
    }
    .btn-cta-white:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,.2); }

    /* ── FOOTER ───────────────────────────────── */
    footer {
      border-top: 1px solid var(--border);
      padding: 28px max(24px, calc((100% - 1120px) / 2));
      display: flex; align-items: center; justify-content: space-between;
      gap: 16px; flex-wrap: wrap;
    }
    .footer-logo { display: flex; align-items: center; gap: 8px; }
    .footer-logo-text { font-weight: 800; font-size: 14px; color: var(--gray-700); }
    .footer-copy { font-size: 12.5px; color: var(--gray-400); }
    .footer-links { display: flex; gap: 20px; }
    .footer-links a { font-size: 12.5px; color: var(--gray-500); text-decoration: none; }
    .footer-links a:hover { color: var(--gray-900); }

    /* ── RESPONSIVE ───────────────────────────── */
    @media (max-width: 900px) {
      .nav-links  { display: none; }
      .nav-actions { display: none; }
      .nav-hamburger { display: flex; }
      .mobile-drawer { display: block; }
      .features-grid, .pricing-grid { grid-template-columns: 1fr; }
      .steps-grid { grid-template-columns: repeat(2, 1fr); }
      .hero-divider { display: none; }
    }
    @media (max-width: 600px) {
      .steps-grid { grid-template-columns: 1fr; }
      .cta-section { padding: 40px 24px; }
      .price-specs { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<!-- ── NAV ─────────────────────────────────────── -->
<nav id="main-nav">
  <a href="<?= BASE_URL ?>/" class="nav-logo">
    <div class="nav-logo-mark">
      <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg>
    </div>
    <span class="nav-logo-text"><?= APP_NAME ?></span>
  </a>

  <!-- Desktop links -->
  <div class="nav-links">
    <a href="#features" class="nav-link">Features</a>
    <a href="#pricing"  class="nav-link">Pricing</a>
    <a href="#how"      class="nav-link">How It Works</a>
  </div>

  <!-- Desktop actions -->
  <div class="nav-actions">
    <?php if ($logged_in): ?>
      <a href="<?= BASE_URL ?>/dashboard.php" class="nav-btn-dashboard">
        <div class="nav-avatar"><?= $avatar ?></div>
        Go to Dashboard
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
      </a>
    <?php else: ?>
      <a href="<?= BASE_URL ?>/login.php"    class="nav-btn-ghost">Sign In</a>
      <a href="<?= BASE_URL ?>/register.php" class="nav-btn-primary">
        Get Started Free
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
      </a>
    <?php endif; ?>
  </div>

  <!-- Mobile hamburger -->
  <button class="nav-hamburger" id="ham-btn" aria-label="Toggle menu" onclick="toggleMenu()">
    <div class="ham-line"></div>
    <div class="ham-line"></div>
    <div class="ham-line"></div>
  </button>
</nav>

<!-- ── MOBILE DRAWER ─────────────────────────────── -->
<div class="mobile-drawer" id="mob-drawer">

  <?php if ($logged_in): ?>
  <!-- Logged-in user chip -->
  <div class="mob-user-chip">
    <div class="mob-avatar"><?= $avatar ?></div>
    <div>
      <div class="mob-username">@<?= $uname ?></div>
      <div class="mob-user-label">Logged in</div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Nav links -->
  <a href="#features" class="mob-link" onclick="closeMenu()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
    Features
  </a>
  <a href="#pricing" class="mob-link" onclick="closeMenu()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
    Pricing
  </a>
  <a href="#how" class="mob-link" onclick="closeMenu()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    How It Works
  </a>

  <div class="mob-divider"></div>

  <!-- CTA buttons -->
  <div class="mob-actions">
    <?php if ($logged_in): ?>
      <a href="<?= BASE_URL ?>/dashboard.php" class="mob-btn mob-btn-dashboard">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        Go to Dashboard
      </a>
    <?php else: ?>
      <a href="<?= BASE_URL ?>/login.php"    class="mob-btn mob-btn-ghost">Sign In</a>
      <a href="<?= BASE_URL ?>/register.php" class="mob-btn mob-btn-primary">
        Get Started Free →
      </a>
    <?php endif; ?>
  </div>
</div>

<!-- ── HERO ─────────────────────────────────────── -->
<section class="hero">
  <div class="hero-bg"></div>
  <div class="hero-badge">
    <div class="hero-badge-dot"></div>
    Powered by <?= APP_NAME ?> Cloud Infrastructure
  </div>
  <h1 class="hero-title">
    Deploy Your VPS<br>
    <span class="accent-blue">in Seconds.</span>
    <span class="accent-cyan"> Scale Instantly.</span>
  </h1>
  <p class="hero-sub">
    Enterprise-grade virtual servers with dedicated vCPUs, NVMe SSDs, and full root access — managed through one beautiful dashboard.
  </p>
  <div class="hero-cta">
    <a href="<?= BASE_URL ?>/register.php" class="btn-hero-primary">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="13 2 13 9 20 9"/><polygon points="13 2 2 9 13 16 20 9"/></svg>
      Start Deploying Free
    </a>
    <a href="#how" class="btn-hero-secondary">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>
      See How It Works
    </a>
  </div>
  <div class="hero-stats">
    <div class="hero-stat">
      <div class="hero-stat-num">99.9%</div>
      <div class="hero-stat-label">Uptime SLA</div>
    </div>
    <div class="hero-divider"></div>
    <div class="hero-stat">
      <div class="hero-stat-num">&lt; 60s</div>
      <div class="hero-stat-label">Boot Time</div>
    </div>
    <div class="hero-divider"></div>
    <div class="hero-stat">
      <div class="hero-stat-num">₹0</div>
      <div class="hero-stat-label">Setup Fee</div>
    </div>
    <div class="hero-divider"></div>
    <div class="hero-stat">
      <div class="hero-stat-num">24/7</div>
      <div class="hero-stat-label">Support</div>
    </div>
  </div>
</section>

<!-- ── TERMINAL DEMO ─────────────────────────────── -->
<div class="demo-section">
  <div class="terminal-wrap">
    <div class="term-bar">
      <div class="term-dot" style="background:#ff5f57"></div>
      <div class="term-dot" style="background:#febc2e"></div>
      <div class="term-dot" style="background:#28c840"></div>
      <div class="term-title">cloudvault — deploy</div>
    </div>
    <div class="term-body">
      <div class="term-line"><span class="term-prompt">$</span><span class="term-cmd">&nbsp;cloudvault server create --name my-app --type cx22 --image ubuntu-24.04</span></div>
      <div class="term-line"><span class="term-comment">&nbsp;&nbsp;# Contacting <?= APP_NAME ?> Cloud API...</span></div>
      <div class="term-line"><span class="term-out">&nbsp;&nbsp;✦ Server created · ID: 58291034 · Region: IN-MUM</span></div>
      <div class="term-line"><span class="term-out">&nbsp;&nbsp;✦ Assigning IPv4: 49.13.84.xx · IPv6: 2a01:4f8::1</span></div>
      <div class="term-line"><span class="term-out">&nbsp;&nbsp;✦ Installing OS image: Ubuntu 24.04 LTS</span></div>
      <div class="term-line"><span class="term-success">&nbsp;&nbsp;✓ Server is RUNNING · Boot time: 38s</span></div>
      <div class="term-line" style="margin-top:8px"><span class="term-prompt">$</span><span class="term-cmd">&nbsp;ssh root@49.13.84.xx</span></div>
      <div class="term-line"><span class="term-success">&nbsp;&nbsp;Welcome to Ubuntu 24.04 LTS — CloudVault Managed</span></div>
      <div class="term-line"><span class="term-prompt">root@my-app:~#</span><span class="term-cursor"></span></div>
    </div>
  </div>
</div>

<!-- ── INFRA POWERED BY ──────────────────────────── -->
<div class="infra-section">
  <div class="infra-label">Built on world-class infrastructure</div>
  <div class="infra-badges">
    <div class="infra-badge"><img src="https://flagcdn.com/w20/in.png"> <?= APP_NAME ?> Cloud</div>
    <div class="infra-badge">⚡ NVMe SSD Storage</div>
    <div class="infra-badge">🌐 IPv4 + IPv6</div>
    <div class="infra-badge">🔒 Firewall Rules</div>
    <div class="infra-badge">📊 Real-time Metrics</div>
    <div class="infra-badge">🔑 SSH Key Auth</div>
    <div class="infra-badge">💳 INR Billing</div>
  </div>
</div>

<!-- ── FEATURES ──────────────────────────────────── -->
<section class="section" id="features">
  <div class="section-header">
    <div class="section-tag">Features</div>
    <h2 class="section-title">Everything you need,<br>nothing you don't</h2>
    <p class="section-sub">Powerful tools built for developers, designed for simplicity.</p>
  </div>
  <div class="features-grid">
    <div class="feat-card">
      <div class="feat-icon-wrap" style="background:#eff6ff">🖥️</div>
      <div class="feat-card-title">Instant Server Deploy</div>
      <div class="feat-card-desc">Launch a VPS in under 60 seconds. Choose your OS, size, and region — we handle the rest.</div>
    </div>
    <div class="feat-card">
      <div class="feat-icon-wrap" style="background:#f0fdf4">📊</div>
      <div class="feat-card-title">Live Resource Monitoring</div>
      <div class="feat-card-desc">Track CPU, RAM, disk, and bandwidth in real-time from your dashboard. No guessing.</div>
    </div>
    <div class="feat-card">
      <div class="feat-icon-wrap" style="background:#faf5ff">🔑</div>
      <div class="feat-card-title">SSH Key Management</div>
      <div class="feat-card-desc">Add and manage SSH keys from the dashboard. Secure passwordless access to all your servers.</div>
    </div>
    <div class="feat-card">
      <div class="feat-icon-wrap" style="background:#fff7ed">🔥</div>
      <div class="feat-card-title">Cloud Firewall</div>
      <div class="feat-card-desc">Protect your servers with simple, powerful firewall rules. Allow or block traffic in seconds.</div>
    </div>
    <div class="feat-card">
      <div class="feat-icon-wrap" style="background:#fef2f2">💳</div>
      <div class="feat-card-title">Wallet-Based Billing</div>
      <div class="feat-card-desc">Prepaid wallet in INR — no surprises. Add funds via UPI, cards, or net banking.</div>
    </div>
    <div class="feat-card">
      <div class="feat-icon-wrap" style="background:#ecfdf5">🔌</div>
      <div class="feat-card-title">Full REST API</div>
      <div class="feat-card-desc">Automate everything via our API. Integrate servers into your CI/CD pipeline seamlessly.</div>
    </div>
  </div>
</section>

<!-- ── HOW IT WORKS ──────────────────────────────── -->
<section class="section" id="how" style="background:var(--gray-50);border-top:1px solid var(--border);border-bottom:1px solid var(--border)">
  <div class="section-header">
    <div class="section-tag">How It Works</div>
    <h2 class="section-title">From signup to SSH<br>in 4 steps</h2>
  </div>
  <div class="steps-grid">
    <div class="step-card">
      <div class="step-num">1</div>
      <div class="step-title">Create Account</div>
      <div class="step-desc">Sign up free in under a minute. No credit card required to get started.</div>
    </div>
    <div class="step-card">
      <div class="step-num">2</div>
      <div class="step-title">Add Credits</div>
      <div class="step-desc">Top up your wallet with INR using any payment method.</div>
    </div>
    <div class="step-card">
      <div class="step-num">3</div>
      <div class="step-title">Deploy Server</div>
      <div class="step-desc">Pick your plan, OS, and region. Server boots in under 60 seconds.</div>
    </div>
    <div class="step-card">
      <div class="step-num">4</div>
      <div class="step-title">Take Control</div>
      <div class="step-desc">SSH in, install your stack, go live. You have full root access.</div>
    </div>
  </div>
  <!-- VIDEO SECTION -->
  <div class="how-video">

  <!-- LEFT SIDE NOTE -->
  <div class="video-note">
    <img src="https://i.ibb.co/1f00kh8b/hand-drawn-dotted-arrow-line-clip-art-free-png.png" class="arrow-img" alt="arrow">
    <span>Watch this video</span>
  </div>

  <!-- VIDEO -->
  <div class="video-box">
    <iframe src="https://www.youtube.com/embed/oafxkMv4xnc"
      allowfullscreen>
    </iframe>
  </div>

</div>
</section>

<!-- ── PRICING ───────────────────────────────────── -->
<section class="pricing-section" id="pricing">
  <div class="section-header">
    <div class="section-tag">Pricing</div>
    <h2 class="section-title">Simple, transparent pricing</h2>
    <p class="section-sub">Pay only for what you use. No hidden fees, no lock-in.</p>
  </div>
  <div class="pricing-grid">
    <!-- Starter -->
    <div class="price-card">
      <div class="price-plan">Starter</div>
      <div class="price-amount">₹299<span>/mo</span></div>
      <div class="price-desc">Perfect for personal projects and experiments.</div>
      <div class="price-specs">
        <div class="spec-chip">2 vCPU</div>
        <div class="spec-chip">2 GB RAM</div>
        <div class="spec-chip">40 GB NVMe</div>
        <div class="spec-chip">20 TB BW</div>
      </div>
      <ul class="price-feats">
        <li>Ubuntu / Debian / CentOS</li>
        <li>IPv4 + IPv6 Included</li>
        <li>SSH Key Auth</li>
        <li>Basic Firewall</li>
      </ul>
      <a href="<?= BASE_URL ?>/register.php" class="price-btn price-btn-ghost">Get Started</a>
    </div>

    <!-- Pro -->
    <div class="price-card popular">
      <div class="popular-badge">Most Popular</div>
      <div class="price-plan">Professional</div>
      <div class="price-amount">₹799<span>/mo</span></div>
      <div class="price-desc">For production workloads and growing applications.</div>
      <div class="price-specs">
        <div class="spec-chip">4 vCPU</div>
        <div class="spec-chip">8 GB RAM</div>
        <div class="spec-chip">160 GB NVMe</div>
        <div class="spec-chip">20 TB BW</div>
      </div>
      <ul class="price-feats">
        <li>Everything in Starter</li>
        <li>Priority Support</li>
        <li>Automated Backups</li>
        <li>Private Network</li>
        <li>Advanced Firewall Rules</li>
      </ul>
      <a href="<?= BASE_URL ?>/register.php" class="price-btn price-btn-primary">Get Started →</a>
    </div>

    <!-- Enterprise -->
    <div class="price-card">
      <div class="price-plan">Enterprise</div>
      <div class="price-amount">₹1,999<span>/mo</span></div>
      <div class="price-desc">High-performance dedicated resources for serious workloads.</div>
      <div class="price-specs">
        <div class="spec-chip">8 vCPU</div>
        <div class="spec-chip">16 GB RAM</div>
        <div class="spec-chip">240 GB NVMe</div>
        <div class="spec-chip">30 TB BW</div>
      </div>
      <ul class="price-feats">
        <li>Everything in Pro</li>
        <li>Dedicated vCPU</li>
        <li>Load Balancer Included</li>
        <li>Custom DNS Zones</li>
        <li>SLA Guarantee</li>
      </ul>
      <a href="<?= BASE_URL ?>/register.php" class="price-btn price-btn-ghost">Contact Sales</a>
    </div>
  </div>
</section>

<!-- ── CTA ───────────────────────────────────────── -->
<section class="cta-section" style="margin-top:80px;">
  <div class="cta-bg"></div>
  <h2 class="cta-title">Ready to launch your server?</h2>
  <p class="cta-sub">Join thousands of developers deploying with <?= APP_NAME ?>. Free to start, scales with you.</p>
  <a href="<?= BASE_URL ?>/register.php" class="btn-cta-white">
    Create Free Account →
  </a>
</section>

<!-- ── FOOTER ────────────────────────────────────── -->
<footer>
  <div class="footer-logo">
    <div class="nav-logo-mark" style="width:26px;height:26px;border-radius:6px;background:var(--primary);display:flex;align-items:center;justify-content:center;">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg>
    </div>
    <span class="footer-logo-text"><?= APP_NAME ?></span>
  </div>
  <div class="footer-copy">© <?= date('Y') ?> <?= APP_NAME ?>. All rights reserved.</div>
  <div class="footer-links">
    <a href="<?= BASE_URL ?>/login.php">Login</a>
    <a href="<?= BASE_URL ?>/register.php">Register</a>
    <a href="mailto:<?= get_setting('company_email','support@cloudvault.in') ?>">Support</a>
  </div>
</footer>

<script>
// ── Hamburger menu ──────────────────────────────
var hamBtn  = document.getElementById('ham-btn');
var drawer  = document.getElementById('mob-drawer');
var menuOpen = false;

function toggleMenu() {
  menuOpen = !menuOpen;
  hamBtn.classList.toggle('open', menuOpen);
  drawer.classList.toggle('open', menuOpen);
  document.body.style.overflow = menuOpen ? 'hidden' : '';
}
function closeMenu() {
  menuOpen = false;
  hamBtn.classList.remove('open');
  drawer.classList.remove('open');
  document.body.style.overflow = '';
}

// Close on outside click
document.addEventListener('click', function(e) {
  if (menuOpen && !hamBtn.contains(e.target) && !drawer.contains(e.target)) closeMenu();
});

// Close on resize to desktop
window.addEventListener('resize', function() {
  if (window.innerWidth > 900) closeMenu();
});

// ── Smooth scroll ───────────────────────────────
document.querySelectorAll('a[href^="#"]').forEach(function(a) {
  a.addEventListener('click', function(e) {
    var target = document.querySelector(a.getAttribute('href'));
    if (target) {
      e.preventDefault();
      closeMenu();
      setTimeout(function() {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }, menuOpen ? 280 : 0);
    }
  });
});
</script>
</body>
</html>