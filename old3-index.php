<?php
require_once __DIR__ . '/includes/bootstrap.php';
session_start_safe();
$logged_in = is_logged_in();
$current   = $logged_in ? current_user() : null;
$uname     = $current ? htmlspecialchars($current['username']) : '';
$avatar    = $current ? strtoupper(mb_substr($current['full_name'] ?: $current['username'], 0, 1)) : '';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= APP_NAME ?> — Deploy VPS in 60 Seconds</title>
<meta name="description" content="Enterprise VPS hosting powered by <?= APP_NAME ?>. Deploy instantly, pay in INR.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700;800;900&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
/* ── TOKENS ─────────────────────────────────────────── */
:root{
  --bg:#fafafa;
  --surface:#ffffff;
  --border:#e4e4e7;
  --border-subtle:#f0f0f2;
  --text-primary:#09090b;
  --text-secondary:#52525b;
  --text-muted:#a1a1aa;
  --accent:#6366f1;
  --accent-hover:#4f46e5;
  --accent-light:#eef2ff;
  --accent-border:rgba(99,102,241,.2);
  --green:#16a34a;
  --green-light:#f0fdf4;
  --cyan:#0891b2;
  --amber:#d97706;
  --purple:#7c3aed;
  --shadow-sm:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);
  --shadow-md:0 4px 16px rgba(0,0,0,.07),0 2px 6px rgba(0,0,0,.04);
  --shadow-lg:0 12px 40px rgba(0,0,0,.09),0 4px 14px rgba(0,0,0,.05);
  --radius-sm:6px;
  --radius:10px;
  --radius-lg:14px;
  --radius-xl:20px;
  --font:'Geist',system-ui,sans-serif;
  --mono:'Geist Mono',monospace;
}

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth;font-size:16px}
body{font-family:var(--font);background:var(--bg);color:var(--text-primary);-webkit-font-smoothing:antialiased;overflow-x:hidden;line-height:1.5}
a{text-decoration:none;color:inherit}
img{max-width:100%;display:block}

/* ── UTILITIES ──────────────────────────────────────── */
.container{width:100%;max-width:1160px;margin:0 auto;padding:0 24px}
.tag{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:600;letter-spacing:.8px;text-transform:uppercase;color:var(--accent);margin-bottom:10px}
.tag::before{content:'';width:12px;height:1.5px;background:var(--accent);border-radius:2px}
.section-title{font-size:clamp(28px,3.8vw,44px);font-weight:800;letter-spacing:-1.6px;color:var(--text-primary);line-height:1.08;margin-bottom:12px}
.section-sub{font-size:15.5px;color:var(--text-secondary);line-height:1.7;max-width:500px}
.badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:99px;font-size:10.5px;font-weight:600;letter-spacing:.3px}
.badge-green{background:var(--green-light);color:var(--green);border:1px solid rgba(22,163,74,.2)}
.badge-indigo{background:var(--accent-light);color:var(--accent);border:1px solid var(--accent-border)}
.badge-purple{background:#f5f3ff;color:var(--purple);border:1px solid rgba(124,58,237,.2)}
.badge-amber{background:#fffbeb;color:var(--amber);border:1px solid rgba(217,119,6,.2)}
.dot-pulse{width:5px;height:5px;border-radius:50%;background:currentColor;animation:dotPulse 2s infinite}
@keyframes dotPulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(1.5)}}

/* ── BUTTONS ─────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:var(--radius);font-size:13.5px;font-weight:600;transition:all .15s;cursor:pointer;border:none;white-space:nowrap}
.btn-primary{background:var(--accent);color:#fff;box-shadow:0 2px 8px rgba(99,102,241,.3)}
.btn-primary:hover{background:var(--accent-hover);transform:translateY(-1px);box-shadow:0 4px 16px rgba(99,102,241,.4)}
.btn-ghost{background:var(--surface);color:var(--text-secondary);border:1.5px solid var(--border)}
.btn-ghost:hover{border-color:#c4c4cc;color:var(--text-primary);background:#fafafa}
.btn-lg{padding:12px 24px;font-size:15px;font-weight:700;border-radius:var(--radius-lg)}

/* ── NAV ─────────────────────────────────────────────── */
.nav{
  position:fixed;top:0;left:0;right:0;z-index:300;
  height:58px;display:flex;align-items:center;
  background:rgba(250,250,250,.85);
  backdrop-filter:blur(20px) saturate(160%);
  border-bottom:1px solid var(--border);
  transition:box-shadow .2s;
}
.nav.scrolled{box-shadow:0 2px 20px rgba(0,0,0,.06)}
.nav-inner{display:flex;align-items:center;gap:6px;width:100%;max-width:1160px;margin:0 auto;padding:0 24px}
.nav-logo{display:flex;align-items:center;gap:8px;margin-right:8px}
.nav-mark{width:30px;height:30px;border-radius:8px;background:var(--accent);display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(99,102,241,.35);flex-shrink:0}
.nav-mark svg{width:15px;height:15px}
.nav-brand{font-size:16px;font-weight:800;letter-spacing:-.4px;color:var(--text-primary)}
.nav-links{display:flex;gap:1px;margin-left:6px}
.nav-link{padding:5px 12px;border-radius:var(--radius-sm);font-size:13px;font-weight:500;color:var(--text-secondary);transition:all .12s}
.nav-link:hover{color:var(--text-primary);background:rgba(0,0,0,.04)}
.nav-right{margin-left:auto;display:flex;align-items:center;gap:8px}
.nav-avatar-link{display:inline-flex;align-items:center;gap:7px;font-size:13.5px;font-weight:600;color:var(--accent);padding:5px 12px;border-radius:var(--radius-sm);border:1.5px solid var(--accent-border);background:var(--accent-light);transition:all .15s}
.nav-avatar-link:hover{background:#e0e7ff;border-color:rgba(99,102,241,.35)}
.nav-avatar-img{width:22px;height:22px;border-radius:5px;object-fit:cover}
/* Hamburger */
.nav-ham{display:none;width:36px;height:36px;border-radius:var(--radius-sm);border:1.5px solid var(--border);background:var(--surface);align-items:center;justify-content:center;cursor:pointer;flex-direction:column;gap:4px;margin-left:auto}
.nav-hl{width:16px;height:1.5px;background:var(--text-secondary);border-radius:2px;transition:all .22s;transform-origin:center}
.nav-ham.open .nav-hl:nth-child(1){transform:translateY(5.5px) rotate(45deg)}
.nav-ham.open .nav-hl:nth-child(2){opacity:0;transform:scaleX(0)}
.nav-ham.open .nav-hl:nth-child(3){transform:translateY(-5.5px) rotate(-45deg)}
/* Drawer */
.nav-drawer{display:none;position:fixed;top:58px;left:0;right:0;z-index:290;background:var(--surface);border-bottom:1px solid var(--border);padding:10px 20px 20px;transform:translateY(-6px);opacity:0;pointer-events:none;transition:transform .22s cubic-bezier(.4,0,.2,1),opacity .18s;box-shadow:var(--shadow-lg)}
.nav-drawer.open{transform:translateY(0);opacity:1;pointer-events:all}
.drawer-link{display:flex;align-items:center;gap:9px;padding:10px 12px;border-radius:var(--radius-sm);font-size:13.5px;font-weight:500;color:var(--text-secondary);transition:all .12s}
.drawer-link:hover{background:var(--bg);color:var(--text-primary)}
.drawer-divider{height:1px;background:var(--border-subtle);margin:8px 0}
.drawer-actions{display:flex;flex-direction:column;gap:7px;margin-top:6px}
.drawer-btn{display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;border-radius:var(--radius);font-size:14px;font-weight:700}
.drawer-ghost{background:var(--bg);color:var(--text-secondary);border:1.5px solid var(--border)}
.drawer-primary{background:var(--accent);color:white;box-shadow:0 2px 10px rgba(99,102,241,.3)}

/* ── HERO ─────────────────────────────────────────────── */
.hero{
  min-height:100vh;display:flex;align-items:center;
  padding:80px 0 60px;
  position:relative;overflow:hidden;
  background:#fafafa;
}
/* Dot grid */
.hero-grid{
  position:absolute;inset:0;z-index:0;pointer-events:none;
  background-image:radial-gradient(circle,#d4d4d8 1px,transparent 1px);
  background-size:28px 28px;
  mask-image:radial-gradient(ellipse 80% 80% at 50% 40%,black 10%,transparent 100%);
  opacity:.45;
}
/* Top glow */
.hero-glow{position:absolute;top:-10%;left:50%;transform:translateX(-50%);width:800px;height:500px;background:radial-gradient(ellipse,rgba(99,102,241,.1) 0%,transparent 65%);z-index:0;pointer-events:none}
.hero-glow2{position:absolute;top:30%;right:-8%;width:400px;height:400px;background:radial-gradient(ellipse,rgba(124,58,237,.07) 0%,transparent 60%);z-index:0;pointer-events:none}
.hero-inner{display:grid;grid-template-columns:1fr 1fr;align-items:center;gap:60px;position:relative;z-index:1}
/* Left col */
.hero-eyebrow{display:inline-flex;align-items:center;gap:7px;background:var(--accent-light);border:1px solid var(--accent-border);color:var(--accent);font-size:11px;font-weight:700;padding:4px 12px;border-radius:99px;margin-bottom:20px;letter-spacing:.6px;text-transform:uppercase}
.hero-h1{font-size:clamp(40px,5.5vw,66px);font-weight:900;line-height:1.02;letter-spacing:-2.5px;color:var(--text-primary);margin-bottom:20px}
.hero-accent{background:linear-gradient(92deg,var(--accent),var(--purple));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero-accent2{background:linear-gradient(92deg,var(--cyan),#0e7490);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero-sub{font-size:16.5px;color:var(--text-secondary);line-height:1.7;margin-bottom:32px;max-width:460px}
.hero-actions{display:flex;gap:10px;flex-wrap:wrap}
.hero-stats{display:flex;gap:24px;margin-top:36px;flex-wrap:wrap;align-items:center}
.hero-stat-val{font-size:24px;font-weight:800;color:var(--text-primary);letter-spacing:-.8px;line-height:1}
.hero-stat-lbl{font-size:11px;color:var(--text-muted);margin-top:2px;font-weight:500}
.hero-divider{width:1px;height:36px;background:var(--border);flex-shrink:0}
/* Right col — globe */
.hero-visual{position:relative;width:460px;height:460px;display:flex;align-items:center;justify-content:center;margin-left:auto}
.globe-halo{position:absolute;width:310px;height:310px;border-radius:50%;background:radial-gradient(circle,rgba(99,102,241,.12) 0%,rgba(124,58,237,.06) 40%,transparent 70%);animation:halo 4.5s ease-in-out infinite}
@keyframes halo{0%,100%{transform:scale(1)}50%{transform:scale(1.06)}}
.globe-ring{position:absolute;width:370px;height:370px;border-radius:50%;border:1px solid rgba(99,102,241,.15);animation:spin1 24s linear infinite}
.globe-ring::after{content:'';position:absolute;width:8px;height:8px;border-radius:50%;background:var(--accent);top:-4px;left:50%;transform:translateX(-50%);box-shadow:0 0 12px rgba(99,102,241,.7)}
@keyframes spin1{to{transform:rotate(360deg)}}
.globe-ring2{position:absolute;width:400px;height:400px;border-radius:50%;border:1px dashed rgba(124,58,237,.12);animation:spin1 34s linear infinite reverse}
.globe-ring2::after{content:'';position:absolute;width:7px;height:7px;border-radius:50%;background:var(--purple);bottom:-3px;left:50%;transform:translateX(-50%);box-shadow:0 0 10px rgba(124,58,237,.6)}
/* Floating info cards */
.float-card{position:absolute;z-index:10;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:10px 14px;box-shadow:var(--shadow-lg);font-size:11.5px;white-space:nowrap;transition:transform .3s}
.float-card-1{top:7%;right:-2%;animation:flt 4s ease-in-out infinite}
.float-card-2{bottom:18%;left:-6%;animation:flt 5.5s ease-in-out infinite .7s}
.float-card-3{top:44%;right:-10%;animation:flt 3.8s ease-in-out infinite 1.3s}
@keyframes flt{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
.fc-dot{width:6px;height:6px;border-radius:50%;display:inline-block;margin-right:5px}

/* ── MARQUEE ─────────────────────────────────────────── */
.marquee-wrap{border-top:1px solid var(--border);border-bottom:1px solid var(--border);background:var(--surface);padding:15px 0;overflow:hidden}
.marquee-inner{display:flex;width:max-content;animation:marquee 38s linear infinite}
.marquee-inner:hover{animation-play-state:paused}
@keyframes marquee{to{transform:translateX(-50%)}}
.marquee-item{display:flex;align-items:center;gap:7px;padding:0 28px;font-size:12.5px;font-weight:500;color:var(--text-secondary);border-right:1px solid var(--border-subtle);white-space:nowrap}
.marquee-dot{width:5px;height:5px;border-radius:50%;flex-shrink:0}

/* ── STATS STRIP ──────────────────────────────────────── */
.stats-strip{display:grid;grid-template-columns:repeat(4,1fr);background:var(--surface);border-bottom:1px solid var(--border)}
.stat-cell{padding:34px 24px;text-align:center;border-right:1px solid var(--border);position:relative}
.stat-cell:last-child{border-right:none}
.stat-cell::after{content:'';position:absolute;top:0;left:25%;right:25%;height:2px;background:linear-gradient(90deg,transparent,var(--accent),transparent);opacity:.4}
.stat-num{font-size:34px;font-weight:900;letter-spacing:-1.8px;line-height:1;color:var(--text-primary)}
.stat-lbl{font-size:12px;color:var(--text-muted);margin-top:6px;font-weight:500}

/* ── SECTIONS ────────────────────────────────────────── */
.section{padding:90px 0}
.section-alt{background:var(--surface);border-top:1px solid var(--border);border-bottom:1px solid var(--border)}

/* ── FEATURES GRID ───────────────────────────────────── */
.features-grid{display:grid;grid-template-columns:repeat(3,1fr);margin-top:52px;border:1px solid var(--border);border-radius:var(--radius-xl);overflow:hidden}
.feat-card{background:var(--surface);padding:28px 24px;position:relative;border-right:1px solid var(--border);border-bottom:1px solid var(--border);overflow:hidden;transition:background .16s}
.feat-card:hover{background:#fdfdff}
.feat-card:nth-child(3n){border-right:none}
.feat-card:nth-child(4),.feat-card:nth-child(5),.feat-card:nth-child(6){border-bottom:none}
.feat-card::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--accent),transparent);transform:scaleX(0);transition:transform .28s}
.feat-card:hover::after{transform:scaleX(1)}
.feat-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;margin-bottom:16px;background:var(--accent-light);border:1px solid var(--accent-border)}
.feat-title{font-size:14.5px;font-weight:700;color:var(--text-primary);margin-bottom:7px}
.feat-desc{font-size:13px;color:var(--text-secondary);line-height:1.65}

/* ── TERMINAL SECTION ────────────────────────────────── */
.terminal-sec{padding:0 0 80px;display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center}
.terminal{background:#0d1117;border:1px solid #21262d;border-radius:var(--radius-lg);overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.2),0 0 0 1px rgba(255,255,255,.03)}
.terminal-bar{height:38px;background:#161b22;display:flex;align-items:center;padding:0 14px;gap:6px;border-bottom:1px solid #21262d}
.t-dot{width:10px;height:10px;border-radius:50%}
.t-title{font-family:var(--mono);font-size:11px;color:#484f58;margin-left:auto;letter-spacing:.3px}
.terminal-body{padding:18px 20px;font-family:var(--mono);font-size:12px;line-height:1.9}
.t-line{display:flex;gap:8px}
.tp{color:#3fb950;flex-shrink:0}.tc{color:#e6edf3}.tcom{color:#484f58}.to{color:#79c0ff}.ts{color:#3fb950}
.t-cursor{display:inline-block;width:6px;height:12px;background:#3fb950;border-radius:1px;animation:blink 1.1s step-end infinite;vertical-align:middle;margin-left:2px}
@keyframes blink{0%,100%{opacity:1}50%{opacity:0}}
.feat-points{list-style:none;margin-top:30px;display:flex;flex-direction:column;gap:14px}
.feat-points li{display:flex;align-items:flex-start;gap:11px;font-size:14px;color:var(--text-secondary);line-height:1.6}
.feat-point-icon{width:28px;height:28px;border-radius:7px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:13px}

/* ── PRICING ─────────────────────────────────────────── */
.pricing-sec{padding:80px 0;background:var(--surface);border-top:1px solid var(--border);border-bottom:1px solid var(--border)}
.pricing-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-top:50px}
.price-card{background:var(--bg);border:1.5px solid var(--border);border-radius:var(--radius-xl);padding:28px 24px;position:relative;transition:all .18s}
.price-card:hover{border-color:rgba(99,102,241,.3);transform:translateY(-3px);box-shadow:0 12px 40px rgba(99,102,241,.08)}
.price-card-hot{border-color:rgba(99,102,241,.4);background:var(--surface);box-shadow:0 0 0 4px rgba(99,102,241,.06)}
.price-card-hot:hover{box-shadow:0 12px 40px rgba(99,102,241,.14)}
.price-badge{position:absolute;top:-12px;left:50%;transform:translateX(-50%);background:var(--accent);color:white;font-size:10px;font-weight:700;padding:3px 13px;border-radius:99px;letter-spacing:.4px}
.price-plan{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);margin-bottom:9px}
.price-amount{font-size:42px;font-weight:900;color:var(--text-primary);letter-spacing:-2.5px;line-height:1}
.price-amount span{font-size:14px;font-weight:400;color:var(--text-muted);letter-spacing:0}
.price-desc{font-size:12.5px;color:var(--text-secondary);margin:9px 0 20px;line-height:1.55}
.price-specs{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:20px}
.price-chip{background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:6px 8px;font-family:var(--mono);font-size:11px;font-weight:600;color:var(--text-secondary);text-align:center}
.price-card-hot .price-chip{background:var(--accent-light);border-color:var(--accent-border);color:var(--accent)}
.price-features{list-style:none;margin-bottom:24px;display:flex;flex-direction:column;gap:8px}
.price-features li{font-size:13px;color:var(--text-secondary);display:flex;align-items:center;gap:7px}
.price-check{width:14px;height:14px;border-radius:4px;background:var(--accent-light);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.price-check svg{width:8px;height:8px}
.price-btn{display:block;width:100%;padding:11px;border-radius:var(--radius);font-size:13.5px;font-weight:700;text-align:center;transition:all .16s;cursor:pointer;border:none}
.price-btn-primary{background:var(--accent);color:white;box-shadow:0 2px 10px rgba(99,102,241,.3)}
.price-btn-primary:hover{background:var(--accent-hover);box-shadow:0 4px 18px rgba(99,102,241,.45);transform:translateY(-1px)}
.price-btn-ghost{background:transparent;color:var(--text-secondary);border:1.5px solid var(--border)}
.price-btn-ghost:hover{border-color:var(--accent);color:var(--accent);background:var(--accent-light)}

/* ── STEPS ───────────────────────────────────────────── */
.how-sec{padding:80px 0;background:var(--bg)}
.steps-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:0;position:relative;margin-top:50px}
.steps-grid::before{content:'';position:absolute;top:19px;left:12%;right:12%;height:1px;background:var(--border);z-index:0}
.step{text-align:center;padding:0 16px;position:relative;z-index:1}
.step-num{width:40px;height:40px;border-radius:10px;background:var(--accent);color:white;font-size:16px;font-weight:800;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;box-shadow:0 4px 14px rgba(99,102,241,.35)}
.step-title{font-size:14px;font-weight:700;color:var(--text-primary);margin-bottom:6px}
.step-desc{font-size:12.5px;color:var(--text-secondary);line-height:1.6}

/* ── VIDEO ───────────────────────────────────────────── */
.video-sec{padding:0 0 80px;text-align:center}
.video-wrap{position:relative;border-radius:var(--radius-lg);overflow:hidden;max-width:800px;margin:0 auto;aspect-ratio:16/9;border:1px solid var(--border);box-shadow:var(--shadow-lg)}
.video-wrap iframe{width:100%;height:100%;border:none;display:block}

/* ── TESTIMONIALS ────────────────────────────────────── */
.reviews-sec{padding:90px 0;background:var(--surface);border-top:1px solid var(--border)}
.reviews-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:52px;perspective:1200px}
.review-card{background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-xl);padding:24px;position:relative;overflow:hidden;transition:transform .3s cubic-bezier(.25,.46,.45,.94),box-shadow .3s,border-color .25s}
.review-card:hover{transform:translateY(-5px);box-shadow:var(--shadow-lg);border-color:rgba(99,102,241,.2)}
.review-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--accent),var(--purple));opacity:0;transition:opacity .22s}
.review-card:hover::before{opacity:1}
.review-card:nth-child(2),.review-card:nth-child(5){transform:translateY(12px)}
.review-card:nth-child(2):hover,.review-card:nth-child(5):hover{transform:translateY(7px)}
.review-plan{position:absolute;top:14px;right:14px}
.review-stars{display:flex;gap:2px;margin-bottom:12px}
.review-star{font-size:12px;color:#f59e0b}
.review-quote{font-size:13.5px;color:var(--text-secondary);line-height:1.72;margin-bottom:20px}
.review-author{display:flex;align-items:center;gap:10px;padding-top:16px;border-top:1px solid var(--border-subtle)}
.review-av{width:38px;height:38px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:900;color:white}
.review-name{font-size:13.5px;font-weight:700;color:var(--text-primary)}
.review-role{font-size:11px;color:var(--text-muted);margin-top:2px}

/* ── CTA ─────────────────────────────────────────────── */
.cta-sec{padding:0 0 80px}
.cta-box{
  background:linear-gradient(135deg,var(--accent) 0%,#4f46e5 40%,var(--purple) 100%);
  border-radius:var(--radius-xl);padding:72px 56px;text-align:center;position:relative;overflow:hidden;
  box-shadow:0 20px 60px rgba(99,102,241,.3);
}
.cta-grid{position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.07) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.07) 1px,transparent 1px);background-size:32px 32px;mask-image:radial-gradient(ellipse 100% 100% at 50% 50%,black 20%,transparent 100%);pointer-events:none}
.cta-h{font-size:clamp(26px,3.8vw,46px);font-weight:900;color:white;letter-spacing:-2px;margin-bottom:12px;position:relative;z-index:1}
.cta-sub{font-size:15.5px;color:rgba(255,255,255,.75);margin-bottom:34px;position:relative;z-index:1}
.cta-btn{display:inline-flex;align-items:center;gap:8px;padding:14px 30px;border-radius:var(--radius-lg);font-size:15px;font-weight:800;background:white;color:var(--accent);box-shadow:0 4px 20px rgba(0,0,0,.18);transition:all .18s;position:relative;z-index:1}
.cta-btn:hover{transform:translateY(-2px);box-shadow:0 10px 32px rgba(0,0,0,.25)}

/* ── FOOTER ──────────────────────────────────────────── */
.footer{border-top:1px solid var(--border);padding:24px 0;background:var(--surface)}
.footer-inner{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}
.footer-logo{display:flex;align-items:center;gap:7px;font-size:14.5px;font-weight:800;color:var(--text-primary)}
.footer-copy{font-size:12px;color:var(--text-muted)}
.footer-links{display:flex;gap:18px}
.footer-links a{font-size:12px;color:var(--text-muted);transition:color .12s}
.footer-links a:hover{color:var(--text-primary)}

/* ── RESPONSIVE ──────────────────────────────────────── */
@media(max-width:1040px){
  .hero-inner{grid-template-columns:1fr;text-align:center}
  .hero-sub,.hero-stats,.hero-actions{justify-content:center;margin-left:auto;margin-right:auto}
  .hero-visual{display:none}
  .terminal-sec{grid-template-columns:1fr}
  .features-grid{grid-template-columns:1fr 1fr}
  .feat-card:nth-child(2n){border-right:none}
  .feat-card:nth-child(3){border-right:1px solid var(--border)}
  .reviews-grid{grid-template-columns:1fr 1fr}
  .pricing-grid{grid-template-columns:1fr}
  .stats-strip{grid-template-columns:1fr 1fr}
  .stat-cell:nth-child(2){border-right:none}
  .stat-cell:nth-child(1),.stat-cell:nth-child(2){border-bottom:1px solid var(--border)}
}
@media(max-width:768px){
  .nav-links,.nav-right{display:none}
  .nav-ham{display:flex}
  .nav-drawer{display:block}
  .features-grid{grid-template-columns:1fr}
  .feat-card{border-right:none!important}
  .feat-card:nth-child(4),.feat-card:nth-child(5){border-bottom:1px solid var(--border)}
  .reviews-grid{grid-template-columns:1fr}
  .review-card:nth-child(2),.review-card:nth-child(5){transform:none}
  .steps-grid{grid-template-columns:1fr 1fr;gap:28px}
  .steps-grid::before{display:none}
  .cta-box{padding:40px 24px}
}
@media(max-width:480px){
  .steps-grid{grid-template-columns:1fr}
  .stats-strip{grid-template-columns:1fr 1fr}
  .pricing-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>

<!-- ══ NAV ══════════════════════════════════════════════ -->
<nav class="nav" id="mainNav">
  <div class="nav-inner">
    <a href="<?= BASE_URL ?>/" class="nav-logo">
      <div class="nav-mark">
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg>
      </div>
      <span class="nav-brand"><?= APP_NAME ?></span>
    </a>
    <nav class="nav-links">
      <a href="#features" class="nav-link">Features</a>
      <a href="#pricing"  class="nav-link">Pricing</a>
      <a href="#how"      class="nav-link">How It Works</a>
      <a href="#reviews"  class="nav-link">Reviews</a>
    </nav>
    <div class="nav-right">
      <?php if ($logged_in): ?>
        <a href="<?= BASE_URL ?>/dashboard.php" class="nav-avatar-link">
          <img class="nav-avatar-img" src="<?= getGravatar($current['email'], $current['user_profile']) ?>" alt="">
          Dashboard
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
      <?php else: ?>
        <a href="<?= BASE_URL ?>/login.php"    class="btn btn-ghost">Sign In</a>
        <a href="<?= BASE_URL ?>/register.php" class="btn btn-primary">
          Get Started
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
      <?php endif; ?>
    </div>
    <button class="nav-ham" id="navHam" onclick="toggleDrawer()">
      <div class="nav-hl"></div><div class="nav-hl"></div><div class="nav-hl"></div>
    </button>
  </div>
</nav>

<!-- ══ MOBILE DRAWER ════════════════════════════════════ -->
<div class="nav-drawer" id="navDrawer">
  <?php if ($logged_in): ?>
    <div style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--accent-light);border:1px solid var(--accent-border);border-radius:var(--radius);margin-bottom:10px">
      <img style="width:32px;height:32px;border-radius:7px;object-fit:cover" src="<?= getGravatar($current['email'], $current['user_profile']) ?>" alt="">
      <div>
        <div style="font-size:13px;font-weight:700;color:var(--text-primary)"><?= $uname ?></div>
        <div style="font-size:11px;color:var(--text-muted)">Signed in</div>
      </div>
    </div>
  <?php endif; ?>
  <a href="#features" class="drawer-link" onclick="closeDrawer()">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
    Features
  </a>
  <a href="#pricing" class="drawer-link" onclick="closeDrawer()">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
    Pricing
  </a>
  <a href="#how" class="drawer-link" onclick="closeDrawer()">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
    How It Works
  </a>
  <a href="#reviews" class="drawer-link" onclick="closeDrawer()">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
    Reviews
  </a>
  <div class="drawer-divider"></div>
  <div class="drawer-actions">
    <?php if ($logged_in): ?>
      <a href="<?= BASE_URL ?>/dashboard.php" class="drawer-btn drawer-primary" style="text-decoration:none;border-radius:var(--radius)">Go to Dashboard →</a>
    <?php else: ?>
      <a href="<?= BASE_URL ?>/login.php" class="drawer-btn drawer-ghost" style="text-decoration:none;border-radius:var(--radius)">Sign In</a>
      <a href="<?= BASE_URL ?>/register.php" class="drawer-btn drawer-primary" style="text-decoration:none;border-radius:var(--radius)">Get Started Free</a>
    <?php endif; ?>
  </div>
</div>

<!-- ══ HERO ══════════════════════════════════════════════ -->
<section class="hero">
  <div class="hero-grid"></div>
  <div class="hero-glow"></div>
  <div class="hero-glow2"></div>
  <div class="container">
    <div class="hero-inner">
      <!-- Left -->
      <div>
        <div class="hero-eyebrow">
          <span class="dot-pulse"></span>
          Mumbai · 99.97% Uptime · Live
        </div>
        <h1 class="hero-h1">
          Deploy VPS<br>
          <span class="hero-accent">in 60 seconds.</span><br>
          <span class="hero-accent2">Built for India.</span>
        </h1>
        <p class="hero-sub">Enterprise VPS hosting with NVMe SSDs, full root access, and INR billing via UPI. No foreign exchange surprises — ever.</p>
        <div class="hero-actions">
          <a href="<?= BASE_URL ?>/register.php" class="btn btn-primary btn-lg">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
            Start Free Now
          </a>
          <a href="#pricing" class="btn btn-ghost btn-lg">View Pricing</a>
        </div>
        <div class="hero-stats">
          <div>
            <div class="hero-stat-val">99.9%</div>
            <div class="hero-stat-lbl">Uptime SLA</div>
          </div>
          <div class="hero-divider"></div>
          <div>
            <div class="hero-stat-val">&lt;60s</div>
            <div class="hero-stat-lbl">Boot Time</div>
          </div>
          <div class="hero-divider"></div>
          <div>
            <div class="hero-stat-val">₹0</div>
            <div class="hero-stat-lbl">Setup Fee</div>
          </div>
          <div class="hero-divider"></div>
          <div>
            <div class="hero-stat-val">24/7</div>
            <div class="hero-stat-lbl">Support</div>
          </div>
        </div>
      </div>

      <!-- Globe -->
      <div class="hero-visual">
        <div class="globe-halo"></div>
        <div class="globe-ring"></div>
        <div class="globe-ring2"></div>
        <canvas id="globe" width="310" height="310" style="position:relative;z-index:2;border-radius:50%"></canvas>
        <!-- Floating cards -->
        <div class="float-card float-card-1">
          <div style="display:flex;align-items:center;gap:5px">
            <span class="fc-dot" style="background:#16a34a"></span>
            <span style="color:#16a34a;font-weight:700;font-size:10.5px">LIVE · 99.97%</span>
          </div>
          <div style="font-size:10.5px;color:var(--text-muted);margin-top:2px;font-family:var(--mono)">Mumbai DC · 8ms avg</div>
        </div>
        <div class="float-card float-card-2">
          <div style="font-size:10.5px;color:var(--text-muted);font-family:var(--mono)">🖥 Ubuntu 24.04 LTS</div>
          <div style="display:flex;align-items:center;gap:5px;margin-top:3px">
            <span class="fc-dot" style="background:var(--cyan)"></span>
            <span style="font-size:10.5px;color:var(--cyan);font-weight:600">Booted in 38s</span>
          </div>
        </div>
        <div class="float-card float-card-3">
          <div style="font-size:10.5px;color:var(--text-muted)">Monthly cost</div>
          <div style="font-size:20px;font-weight:900;color:var(--text-primary);font-family:var(--mono);letter-spacing:-1px">₹299</div>
          <div style="font-size:10px;color:var(--green);margin-top:1px;font-weight:600">▼ No hidden fees</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══ MARQUEE ════════════════════════════════════════════ -->
<div class="marquee-wrap">
  <div class="marquee-inner">
    <?php
    $items=[['🇮🇳','Mumbai Datacenter','#6366f1'],['⚡','NVMe SSD RAID','#0891b2'],['🌐','IPv4 & IPv6','#7c3aed'],['🔒','Cloud Firewall','#d97706'],['💳','INR Billing & UPI','#16a34a'],['🔑','SSH Key Auth','#0891b2'],['📊','Real-time Metrics','#6366f1'],['🚀','60s Deploy','#16a34a'],['🛡️','DDoS Protection','#d97706'],['🖥️','Full Root Access','#0891b2'],['📸','Instant Snapshots','#7c3aed'],['🔌','REST API','#6366f1'],['🇮🇳','Mumbai Datacenter','#6366f1'],['⚡','NVMe SSD RAID','#0891b2'],['🌐','IPv4 & IPv6','#7c3aed'],['🔒','Cloud Firewall','#d97706'],['💳','INR Billing & UPI','#16a34a'],['🔑','SSH Key Auth','#0891b2'],['📊','Real-time Metrics','#6366f1'],['🚀','60s Deploy','#16a34a'],['🛡️','DDoS Protection','#d97706'],['🖥️','Full Root Access','#0891b2']];
    foreach($items as[$e,$l,$c]):?>
    <div class="marquee-item"><span class="marquee-dot" style="background:<?=$c?>"></span><?=$e?> <?=$l?></div>
    <?php endforeach;?>
  </div>
</div>

<!-- ══ STATS STRIP ════════════════════════════════════════ -->
<div class="stats-strip">
  <div class="stat-cell"><div class="stat-num" style="color:var(--accent)">5,000+</div><div class="stat-lbl">Active Servers</div></div>
  <div class="stat-cell"><div class="stat-num" style="color:var(--cyan)">99.97%</div><div class="stat-lbl">Uptime Last 90 Days</div></div>
  <div class="stat-cell"><div class="stat-num" style="color:var(--accent)">38s</div><div class="stat-lbl">Average Boot Time</div></div>
  <div class="stat-cell"><div class="stat-num" style="color:var(--purple)">2,400+</div><div class="stat-lbl">Happy Customers</div></div>
</div>

<!-- ══ FEATURES ═══════════════════════════════════════════ -->
<section class="section" id="features">
  <div class="container">
    <div class="tag">Features</div>
    <h2 class="section-title">Everything you need,<br>nothing you don't</h2>
    <p class="section-sub">Powerful tools built for developers, designed for simplicity. No bloat, just raw performance.</p>
    <div class="features-grid">
      <?php
      $fts=[
        ['🚀','Instant Deploy','Launch a VPS in under 60 seconds. Choose OS, size, and region — we handle the rest.'],
        ['📊','Live Monitoring','Track CPU, RAM, disk, and bandwidth in real-time. Full visibility into your infrastructure.'],
        ['🔑','SSH Key Auth','Add and manage SSH keys from the dashboard. Secure passwordless access to all servers.'],
        ['🔥','Cloud Firewall','Protect servers with powerful firewall rules. Allow or block any traffic in one click.'],
        ['💳','Wallet Billing','Prepaid INR wallet — no surprises. Top up via UPI, cards, or net banking anytime.'],
        ['🔌','Full REST API','Automate everything via API. Integrate VPS deployments into your CI/CD pipeline.'],
      ];
      foreach($fts as[$ic,$t,$d]):?>
      <div class="feat-card">
        <div class="feat-icon"><?=$ic?></div>
        <div class="feat-title"><?=$t?></div>
        <div class="feat-desc"><?=$d?></div>
      </div>
      <?php endforeach;?>
    </div>
  </div>
</section>

<!-- ══ TERMINAL ════════════════════════════════════════════ -->
<div style="padding:0 0 80px;background:var(--surface);border-top:1px solid var(--border)">
  <div class="container">
    <div class="terminal-sec">
      <div>
        <div class="tag">Developer First</div>
        <h2 class="section-title">Built for<br>power users</h2>
        <p class="section-sub" style="margin-bottom:0">Full control from terminal to dashboard. No black boxes, no lock-in.</p>
        <ul class="feat-points">
          <li>
            <div class="feat-point-icon" style="background:var(--accent-light);border:1px solid var(--accent-border)">🐧</div>
            <div><strong style="color:var(--text-primary)">Ubuntu, Debian, CentOS, AlmaLinux</strong> — latest stable releases always available</div>
          </li>
          <li>
            <div class="feat-point-icon" style="background:#ecfdf5;border:1px solid rgba(6,182,212,.2)">⚡</div>
            <div><strong style="color:var(--text-primary)">NVMe SSD storage</strong> — 10x faster than traditional SATA drives</div>
          </li>
          <li>
            <div class="feat-point-icon" style="background:#f5f3ff;border:1px solid rgba(124,58,237,.2)">🌐</div>
            <div><strong style="color:var(--text-primary)">IPv4 + IPv6 included</strong> — dedicated IPs on every server</div>
          </li>
          <li>
            <div class="feat-point-icon" style="background:#fffbeb;border:1px solid rgba(245,158,11,.2)">📸</div>
            <div><strong style="color:var(--text-primary)">Snapshots & backups</strong> — one-click restore anytime</div>
          </li>
        </ul>
      </div>
      <div>
        <div class="terminal">
          <div class="terminal-bar">
            <div class="t-dot" style="background:#ff5f57"></div>
            <div class="t-dot" style="background:#febc2e"></div>
            <div class="t-dot" style="background:#28c840"></div>
            <div class="t-title"><?= APP_NAME ?> — deploy</div>
          </div>
          <div class="terminal-body">
            <div class="t-line"><span class="tp">$</span><span class="tc">&nbsp;vps create --plan cx22 --os ubuntu-24.04</span></div>
            <div class="t-line"><span class="tcom">&nbsp;&nbsp;# Contacting <?= APP_NAME ?> API...</span></div>
            <div class="t-line"><span class="to">&nbsp;&nbsp;✦ Server created · ID: 58291034</span></div>
            <div class="t-line"><span class="to">&nbsp;&nbsp;✦ Region: IN-MUM · IPv4: 49.13.84.xx</span></div>
            <div class="t-line"><span class="to">&nbsp;&nbsp;✦ Installing Ubuntu 24.04 LTS...</span></div>
            <div class="t-line"><span class="ts">&nbsp;&nbsp;✓ Server RUNNING · Boot: 38s</span></div>
            <div class="t-line" style="margin-top:8px"><span class="tp">$</span><span class="tc">&nbsp;ssh root@49.13.84.xx</span></div>
            <div class="t-line"><span class="ts">&nbsp;&nbsp;Welcome to Ubuntu 24.04 — <?= APP_NAME ?></span></div>
            <div class="t-line"><span class="tp">root@my-app:~#</span><span class="t-cursor"></span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ══ PRICING ═════════════════════════════════════════════ -->
<div class="pricing-sec" id="pricing">
  <div class="container">
    <div style="text-align:center">
      <div class="tag" style="justify-content:center">Pricing</div>
      <h2 class="section-title" style="max-width:520px;margin:0 auto 12px">Simple, transparent pricing</h2>
      <p class="section-sub" style="margin:0 auto;text-align:center">Pay only for what you use. All prices in INR. No lock-in.</p>
    </div>
    <div class="pricing-grid">

      <div class="price-card">
        <div class="price-plan">Starter</div>
        <div class="price-amount">₹299<span>/mo</span></div>
        <div class="price-desc">Perfect for personal projects and experiments.</div>
        <div class="price-specs">
          <div class="price-chip">2 vCPU</div><div class="price-chip">2 GB RAM</div>
          <div class="price-chip">40 GB NVMe</div><div class="price-chip">20 TB BW</div>
        </div>
        <ul class="price-features">
          <?php foreach(['Ubuntu / Debian / CentOS','IPv4 + IPv6','SSH Key Auth','Basic Firewall'] as $f):?>
          <li>
            <span class="price-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="color:var(--accent)"><polyline points="20 6 9 17 4 12"/></svg></span>
            <?=$f?>
          </li>
          <?php endforeach;?>
        </ul>
        <a href="<?= BASE_URL ?>/register.php" class="price-btn price-btn-ghost">Get Started</a>
      </div>

      <div class="price-card price-card-hot">
        <div class="price-badge">Most Popular</div>
        <div class="price-plan">Professional</div>
        <div class="price-amount">₹799<span>/mo</span></div>
        <div class="price-desc">For production workloads and growing applications.</div>
        <div class="price-specs">
          <div class="price-chip">4 vCPU</div><div class="price-chip">8 GB RAM</div>
          <div class="price-chip">160 GB NVMe</div><div class="price-chip">20 TB BW</div>
        </div>
        <ul class="price-features">
          <?php foreach(['Everything in Starter','Priority Support','Automated Backups','Private Network','Advanced Firewall'] as $f):?>
          <li>
            <span class="price-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="color:var(--accent)"><polyline points="20 6 9 17 4 12"/></svg></span>
            <?=$f?>
          </li>
          <?php endforeach;?>
        </ul>
        <a href="<?= BASE_URL ?>/register.php" class="price-btn price-btn-primary">Get Started →</a>
      </div>

      <div class="price-card">
        <div class="price-plan">Enterprise</div>
        <div class="price-amount">₹1,999<span>/mo</span></div>
        <div class="price-desc">Dedicated resources for serious, high-traffic workloads.</div>
        <div class="price-specs">
          <div class="price-chip">8 vCPU</div><div class="price-chip">16 GB RAM</div>
          <div class="price-chip">240 GB NVMe</div><div class="price-chip">30 TB BW</div>
        </div>
        <ul class="price-features">
          <?php foreach(['Everything in Pro','Dedicated vCPU','Load Balancer','Custom DNS Zones','SLA Guarantee'] as $f):?>
          <li>
            <span class="price-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="color:var(--accent)"><polyline points="20 6 9 17 4 12"/></svg></span>
            <?=$f?>
          </li>
          <?php endforeach;?>
        </ul>
        <a href="<?= BASE_URL ?>/register.php" class="price-btn price-btn-ghost">Contact Sales</a>
      </div>

    </div>
  </div>
</div>

<!-- ══ HOW IT WORKS ════════════════════════════════════════ -->
<div class="how-sec" id="how">
  <div class="container">
    <div style="text-align:center">
      <div class="tag" style="justify-content:center">How It Works</div>
      <h2 class="section-title" style="max-width:460px;margin:0 auto 8px">SSH access in 4 simple steps</h2>
    </div>
    <div class="steps-grid">
      <div class="step">
        <div class="step-num">1</div>
        <div class="step-title">Create Account</div>
        <div class="step-desc">Sign up in under a minute. No credit card needed to get started.</div>
      </div>
      <div class="step">
        <div class="step-num">2</div>
        <div class="step-title">Add Credits</div>
        <div class="step-desc">Top up your INR wallet via UPI, debit/credit cards, or net banking.</div>
      </div>
      <div class="step">
        <div class="step-num">3</div>
        <div class="step-title">Deploy Server</div>
        <div class="step-desc">Pick plan, OS, and region. Your VPS boots in under 60 seconds.</div>
      </div>
      <div class="step">
        <div class="step-num">4</div>
        <div class="step-title">Take Control</div>
        <div class="step-desc">SSH in with full root access. Install your stack and go live.</div>
      </div>
    </div>
  </div>
</div>

<!-- ══ VIDEO ═══════════════════════════════════════════════ -->
<div class="video-sec" style="padding-top:64px;background:var(--surface);border-top:1px solid var(--border)">
  <div class="container">
    <div class="tag" style="justify-content:center">Demo</div>
    <h2 class="section-title" style="text-align:center;margin-bottom:34px">See it in action</h2>
    <div class="video-wrap">
      <iframe src="https://www.youtube.com/embed/oafxkMv4xnc" allowfullscreen title="<?= APP_NAME ?> Demo"></iframe>
    </div>
  </div>
</div>

<!-- ══ TESTIMONIALS ════════════════════════════════════════ -->
<section class="reviews-sec" id="reviews">
  <div class="container">
    <div style="text-align:center">
      <div class="tag" style="justify-content:center">Customer Reviews</div>
      <h2 class="section-title" style="max-width:480px;margin:0 auto 12px">Trusted by developers<br>across India</h2>
      <p class="section-sub" style="margin:0 auto;text-align:center">Real feedback from real customers who deploy with us every day.</p>
    </div>
    <div class="reviews-grid">
      <?php
      $reviews=[
        ['R','Rahul M.','Full-Stack Dev · Mumbai','linear-gradient(135deg,#16a34a,#059669)','Deploying is insanely fast. I had my production VPS running in under a minute. The INR billing with UPI support is a massive plus — no forex surprises ever.','Pro Plan','badge-green'],
        ['P','Priya S.','DevOps Engineer · Bangalore','linear-gradient(135deg,#6366f1,#4f46e5)','The dashboard is clean and powerful. Managing 8 servers from one place with real-time metrics is exactly what I needed. The support team is genuinely fast.','Enterprise','badge-indigo'],
        ['A','Arjun K.','Startup Founder · Hyderabad','linear-gradient(135deg,#0891b2,#0e7490)','Switched from a foreign provider and saved 40% while getting better latency for our Indian users. The Mumbai DC performance is unmatched. Highly recommend.','Pro Plan','badge-green'],
        ['N','Nitesh T.','Backend Engineer · Delhi','linear-gradient(135deg,#16a34a,#059669)','The REST API is super clean and well documented. Automated our entire VPS lifecycle in CI/CD. Zero downtime deployments in 8 months straight.','Verified','badge-purple'],
        ['S','Sneha R.','Cloud Architect · Pune','linear-gradient(135deg,#d97706,#b45309)','Finally a provider that understands Indian developers. UPI payments, INR invoices, responsive support. The whole experience is smooth from day one.','Pro Plan','badge-amber'],
        ['V','Vikram B.','SaaS Founder · Chennai','linear-gradient(135deg,#7c3aed,#6d28d9)','Migrated our entire infrastructure here. Firewall rules are intuitive, snapshots work flawlessly, and the pricing is transparent. Best infra decision we made.','Enterprise','badge-purple'],
      ];
      foreach($reviews as[$av,$name,$role,$grad,$text,$badge,$badgecls]):?>
      <div class="review-card">
        <div class="review-plan"><span class="badge <?=$badgecls?>"><?=$badge?></span></div>
        <div class="review-stars">
          <span class="review-star">★</span><span class="review-star">★</span><span class="review-star">★</span><span class="review-star">★</span><span class="review-star">★</span>
        </div>
        <p class="review-quote">"<?=$text?>"</p>
        <div class="review-author">
          <div class="review-av" style="background:<?=$grad?>"><?=$av?></div>
          <div>
            <div class="review-name"><?=$name?></div>
            <div class="review-role"><?=$role?></div>
          </div>
        </div>
      </div>
      <?php endforeach;?>
    </div>
  </div>
</section>

<!-- ══ CTA ══════════════════════════════════════════════════ -->
<div class="cta-sec">
  <div class="container">
    <div class="cta-box">
      <div class="cta-grid"></div>
      <h2 class="cta-h">Ready to launch your server?</h2>
      <p class="cta-sub">Join thousands of developers deploying with <?= APP_NAME ?>. Free to start, scales with you.</p>
      <a href="<?= BASE_URL ?>/register.php" class="cta-btn">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
        Create Free Account
      </a>
    </div>
  </div>
</div>

<!-- ══ FOOTER ════════════════════════════════════════════════ -->
<footer class="footer">
  <div class="container">
    <div class="footer-inner">
      <div class="footer-logo">
        <div class="nav-mark" style="width:26px;height:26px;border-radius:7px">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg>
        </div>
        <?= APP_NAME ?>
      </div>
      <div class="footer-copy">© <?= date('Y') ?> <?= APP_NAME ?>. All rights reserved.</div>
      <div class="footer-links">
        <a href="<?= BASE_URL ?>/login.php">Login</a>
        <a href="<?= BASE_URL ?>/register.php">Register</a>
        <a href="mailto:<?= get_setting('company_email','support@greathost.in') ?>">Support</a>
      </div>
    </div>
  </div>
</footer>

<script>
/* ── Nav scroll ─────────────────────── */
window.addEventListener('scroll',function(){
  document.getElementById('mainNav').classList.toggle('scrolled',window.scrollY>16);
});

/* ── Mobile drawer ──────────────────── */
var _open=false;
function toggleDrawer(){
  _open=!_open;
  document.getElementById('navHam').classList.toggle('open',_open);
  document.getElementById('navDrawer').classList.toggle('open',_open);
  document.body.style.overflow=_open?'hidden':'';
}
function closeDrawer(){
  _open=false;
  document.getElementById('navHam').classList.remove('open');
  document.getElementById('navDrawer').classList.remove('open');
  document.body.style.overflow='';
}
document.addEventListener('click',function(e){
  if(_open&&!document.getElementById('navHam').contains(e.target)&&!document.getElementById('navDrawer').contains(e.target))closeDrawer();
});
window.addEventListener('resize',function(){if(window.innerWidth>768)closeDrawer();});

/* ── Smooth anchor scroll ────────────── */
document.querySelectorAll('a[href^="#"]').forEach(function(a){
  a.addEventListener('click',function(e){
    var t=document.querySelector(a.getAttribute('href'));
    if(t){e.preventDefault();closeDrawer();setTimeout(function(){t.scrollIntoView({behavior:'smooth',block:'start'});},_open?260:0);}
  });
});

/* ── 3D tilt on review cards ─────────── */
document.querySelectorAll('.review-card').forEach(function(card){
  card.addEventListener('mousemove',function(e){
    var r=card.getBoundingClientRect();
    var x=(e.clientX-r.left)/r.width-.5;
    var y=(e.clientY-r.top)/r.height-.5;
    card.style.transform='translateY(-5px) rotateX('+(y*-6)+'deg) rotateY('+(x*6)+'deg) scale(1.01)';
  });
  card.addEventListener('mouseleave',function(){card.style.transform='';});
});

/* ── Globe Canvas ────────────────────── */
(function(){
  var c=document.getElementById('globe');
  if(!c)return;
  var ctx=c.getContext('2d');
  var W=310,H=310,R=130,cx=W/2,cy=H/2;
  var dots=[];
  for(var i=0;i<1400;i++){
    var la=(Math.random()-.5)*Math.PI,lo=Math.random()*2*Math.PI;
    var x=Math.cos(la)*Math.cos(lo),y=Math.sin(la),z=Math.cos(la)*Math.sin(lo);
    var r=Math.random();
    dots.push({x,y,z,s:Math.random()*.9+.25,
      c:r<.05?'rgba(99,102,241,.9)':r<.08?'rgba(124,58,237,.85)':r<.10?'rgba(8,145,178,.8)':'rgba(113,113,122,'+(Math.random()*.3+.08)+')'
    });
  }
  var cities=[{la:19.08,lo:72.88},{la:12.97,lo:77.59},{la:28.63,lo:77.22},{la:1.35,lo:103.82},{la:51.51,lo:-.12},{la:37.77,lo:-122.42},{la:35.68,lo:139.69},{la:40.71,lo:-74.01},{la:-33.87,lo:151.21}]
    .map(function(c){var la=c.la*Math.PI/180,lo=c.lo*Math.PI/180;return{x:Math.cos(la)*Math.cos(lo),y:Math.sin(la),z:Math.cos(la)*Math.sin(lo)};});
  var ang=0;
  function proj(x,y,z){var ca=Math.cos(ang),sa=Math.sin(ang);return{px:cx+(ca*x+sa*z)*R,py:cy-y*R,d:-sa*x+ca*z};}
  function draw(){
    ctx.clearRect(0,0,W,H);
    var g=ctx.createRadialGradient(cx-26,cy-26,8,cx,cy,R);
    g.addColorStop(0,'rgba(99,102,241,.07)');g.addColorStop(.6,'rgba(99,102,241,.02)');g.addColorStop(1,'rgba(0,0,0,0)');
    ctx.beginPath();ctx.arc(cx,cy,R,0,2*Math.PI);ctx.fillStyle=g;ctx.fill();
    ctx.lineWidth=.35;
    for(var lt=-60;lt<=60;lt+=30){
      var la=lt*Math.PI/180;ctx.beginPath();var f=true;
      for(var loi=0;loi<=360;loi+=3){var lo2=loi*Math.PI/180;var xx=Math.cos(la)*Math.cos(lo2),yy=Math.sin(la),zz=Math.cos(la)*Math.sin(lo2);var p=proj(xx,yy,zz);if(p.d<0){var a=.03+(-p.d/R)*.06;ctx.strokeStyle='rgba(99,102,241,'+a+')';if(f){ctx.moveTo(p.px,p.py);f=false;}else ctx.lineTo(p.px,p.py);}else f=true;}ctx.stroke();
    }
    for(var loi2=0;loi2<360;loi2+=30){
      var lo3=loi2*Math.PI/180;ctx.beginPath();var f2=true;
      for(var lti=-90;lti<=90;lti+=3){var la2=lti*Math.PI/180;var xx2=Math.cos(la2)*Math.cos(lo3),yy2=Math.sin(la2),zz2=Math.cos(la2)*Math.sin(lo3);var p2=proj(xx2,yy2,zz2);if(p2.d<0){var a2=.03+(-p2.d/R)*.06;ctx.strokeStyle='rgba(99,102,241,'+a2+')';if(f2){ctx.moveTo(p2.px,p2.py);f2=false;}else ctx.lineTo(p2.px,p2.py);}else f2=true;}ctx.stroke();
    }
    var vis=dots.filter(function(d){return proj(d.x,d.y,d.z).d<0;});
    vis.sort(function(a,b){return proj(a.x,a.y,a.z).d-proj(b.x,b.y,b.z).d;});
    vis.forEach(function(d){var p=proj(d.x,d.y,d.z);var sc=.4+(-p.d/R)*.6;ctx.beginPath();ctx.arc(p.px,p.py,d.s*sc,0,2*Math.PI);ctx.fillStyle=d.c;ctx.fill();});
    for(var i=0;i<cities.length;i++){for(var j=i+1;j<cities.length;j++){
      var p1=proj(cities[i].x,cities[i].y,cities[i].z),p2=proj(cities[j].x,cities[j].y,cities[j].z);
      if(p1.d<0&&p2.d<0&&Math.hypot(p2.px-p1.px,p2.py-p1.py)>26){
        var mx=(p1.px+p2.px)/2,my=(p1.py+p2.py)/2,dist=Math.hypot(p2.px-p1.px,p2.py-p1.py);
        var t2=Date.now()/1200+i*.4+j*.2,al=(.5+.5*Math.sin(t2))*.13;
        ctx.beginPath();ctx.moveTo(p1.px,p1.py);ctx.quadraticCurveTo(mx,my-dist*.2,p2.px,p2.py);
        ctx.strokeStyle='rgba(99,102,241,'+al+')';ctx.lineWidth=.6;ctx.stroke();
      }
    }}
    cities.forEach(function(city,idx){
      var p=proj(city.x,city.y,city.z);
      if(p.d<0){
        var pulse=.5+.5*Math.sin(Date.now()/700+idx*1.1);
        ctx.beginPath();ctx.arc(p.px,p.py,2.5+pulse*3.5,0,2*Math.PI);ctx.strokeStyle='rgba(99,102,241,'+(pulse*.35)+')';ctx.lineWidth=.9;ctx.stroke();
        ctx.beginPath();ctx.arc(p.px,p.py,2.2,0,2*Math.PI);ctx.fillStyle='#6366f1';ctx.fill();
        ctx.beginPath();ctx.arc(p.px,p.py,1,0,2*Math.PI);ctx.fillStyle='white';ctx.fill();
      }
    });
    var edge=ctx.createRadialGradient(cx,cy,R-4,cx,cy,R+4);
    edge.addColorStop(0,'rgba(99,102,241,0)');edge.addColorStop(.5,'rgba(99,102,241,.14)');edge.addColorStop(1,'rgba(99,102,241,0)');
    ctx.beginPath();ctx.arc(cx,cy,R,0,2*Math.PI);ctx.strokeStyle=edge;ctx.lineWidth=7;ctx.stroke();
    ang+=.0022;requestAnimationFrame(draw);
  }
  draw();
})();
</script>
</body>
</html>
