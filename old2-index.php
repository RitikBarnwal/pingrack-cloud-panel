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
<title><?= APP_NAME ?> — Enterprise VPS Infrastructure</title>
<meta name="description" content="Enterprise-grade VPS hosting by <?= APP_NAME ?>. Deploy in seconds, pay in INR, scale without limits.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --white:#ffffff;
  --gray-25:#fcfcfd;
  --gray-50:#f9fafb;
  --gray-100:#f3f4f6;
  --gray-150:#eaecf0;
  --gray-200:#e5e7eb;
  --gray-300:#d1d5db;
  --gray-400:#9ca3af;
  --gray-500:#6b7280;
  --gray-600:#4b5563;
  --gray-700:#374151;
  --gray-800:#1f2937;
  --gray-900:#111827;
  --gray-950:#030712;
  --ink:#0a0a0a;
  --primary:#16a34a;
  --primary-dark:#15803d;
  --primary-mid:#22c55e;
  --primary-light:#f0fdf4;
  --primary-border:#bbf7d0;
  --blue:#2563eb;
  --amber:#f59e0b;
  --font:'Plus Jakarta Sans',sans-serif;
  --mono:'JetBrains Mono',monospace;
  --shadow-xs:0 1px 2px rgba(0,0,0,.04);
  --shadow-sm:0 1px 3px rgba(0,0,0,.07),0 1px 2px rgba(0,0,0,.05);
  --shadow-md:0 4px 6px -1px rgba(0,0,0,.07),0 2px 4px -1px rgba(0,0,0,.04);
  --shadow-lg:0 10px 15px -3px rgba(0,0,0,.07),0 4px 6px -2px rgba(0,0,0,.04);
  --shadow-xl:0 20px 25px -5px rgba(0,0,0,.07),0 10px 10px -5px rgba(0,0,0,.03);
  --shadow-2xl:0 25px 50px -12px rgba(0,0,0,.12);
  --r-sm:6px;--r-md:10px;--r-lg:14px;--r-xl:18px;--r-2xl:24px;
}
html{scroll-behavior:smooth}
body{font-family:var(--font);background:var(--white);color:var(--gray-800);-webkit-font-smoothing:antialiased;overflow-x:hidden;line-height:1.6}

/* NAV */
.nav{position:fixed;top:0;left:0;right:0;z-index:400;height:60px;display:flex;align-items:center;padding:0 max(20px,calc((100% - 1160px)/2));background:rgba(255,255,255,.88);backdrop-filter:blur(20px) saturate(1.8);border-bottom:1px solid transparent;transition:border-color .25s,box-shadow .25s}
.nav.scrolled{border-color:var(--gray-200);box-shadow:0 1px 0 var(--gray-100)}
.nav-logo{display:flex;align-items:center;gap:9px;text-decoration:none;flex-shrink:0}
.nav-logomark{width:32px;height:32px;border-radius:8px;background:var(--ink);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.nav-logomark svg{width:15px;height:15px}
.nav-wordmark{font-size:16px;font-weight:800;color:var(--ink);letter-spacing:-.4px}
.nav-center{display:flex;align-items:center;gap:2px;margin:0 auto;padding:0 32px}
.nav-link{padding:5px 13px;border-radius:var(--r-sm);font-size:13.5px;font-weight:500;color:var(--gray-600);text-decoration:none;transition:color .15s,background .15s;white-space:nowrap}
.nav-link:hover{color:var(--ink);background:var(--gray-100)}
.nav-right{display:flex;align-items:center;gap:8px;flex-shrink:0}
.nav-signin{padding:6px 14px;border-radius:var(--r-sm);font-size:13.5px;font-weight:600;color:var(--gray-700);text-decoration:none;transition:color .15s,background .15s}
.nav-signin:hover{color:var(--ink);background:var(--gray-100)}
.nav-getstarted{padding:7px 16px;border-radius:var(--r-md);font-size:13.5px;font-weight:700;color:var(--white);text-decoration:none;background:var(--ink);display:inline-flex;align-items:center;gap:5px;transition:all .2s;box-shadow:0 1px 2px rgba(0,0,0,.15)}
.nav-getstarted:hover{background:#1a1a1a;box-shadow:0 4px 12px rgba(0,0,0,.2);transform:translateY(-1px)}
.nav-dash-link{display:inline-flex;align-items:center;gap:7px;padding:6px 14px;border-radius:var(--r-md);font-size:13.5px;font-weight:700;color:var(--primary);text-decoration:none;background:var(--primary-light);border:1px solid var(--primary-border);transition:all .15s}
.nav-dash-link:hover{background:#dcfce7}
.nav-av{width:26px;height:26px;border-radius:6px;overflow:hidden;flex-shrink:0}
.nav-av img{width:100%;height:100%;object-fit:cover}
.ham{display:none;margin-left:auto;width:36px;height:36px;border-radius:var(--r-sm);background:var(--gray-100);border:1px solid var(--gray-200);align-items:center;justify-content:center;cursor:pointer;flex-direction:column;gap:4px;flex-shrink:0}
.ham-l{width:16px;height:1.5px;background:var(--gray-600);border-radius:2px;transition:all .22s;transform-origin:center}
.ham.open .ham-l:nth-child(1){transform:translateY(5.5px) rotate(45deg)}
.ham.open .ham-l:nth-child(2){opacity:0;transform:scaleX(0)}
.ham.open .ham-l:nth-child(3){transform:translateY(-5.5px) rotate(-45deg)}
.drawer{display:none;position:fixed;top:60px;left:0;right:0;z-index:390;background:var(--white);border-bottom:1px solid var(--gray-200);padding:10px 20px 20px;transform:translateY(-6px);opacity:0;pointer-events:none;transition:transform .22s cubic-bezier(.4,0,.2,1),opacity .18s;box-shadow:0 16px 40px rgba(0,0,0,.08)}
.drawer.open{transform:translateY(0);opacity:1;pointer-events:all}
.dl{display:flex;align-items:center;padding:10px 12px;border-radius:var(--r-md);font-size:14px;font-weight:600;color:var(--gray-600);text-decoration:none;transition:all .13s}
.dl:hover{background:var(--gray-50);color:var(--ink)}
.ddiv{height:1px;background:var(--gray-100);margin:8px 0}
.dacts{display:flex;flex-direction:column;gap:8px;margin-top:6px}
.da{display:flex;align-items:center;justify-content:center;gap:8px;padding:13px;border-radius:var(--r-lg);font-size:14px;font-weight:700;text-decoration:none;transition:all .14s}
.da-ghost{background:var(--gray-50);color:var(--gray-700);border:1px solid var(--gray-200)}
.da-pri{background:var(--ink);color:var(--white)}
.duser{display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--gray-50);border:1px solid var(--gray-200);border-radius:var(--r-lg);margin-bottom:10px}
.duav{width:32px;height:32px;border-radius:8px;overflow:hidden;flex-shrink:0}

/* HERO */
.hero{min-height:100vh;padding:120px max(24px,calc((100% - 1160px)/2)) 80px;position:relative;overflow:hidden;display:flex;align-items:center;background:var(--white)}
.hero-dots{position:absolute;inset:0;z-index:0;pointer-events:none;background-image:radial-gradient(circle,var(--gray-300) 1px,transparent 1px);background-size:28px 28px;mask-image:radial-gradient(ellipse 90% 90% at 50% 40%,black 20%,transparent 100%);opacity:.35}
.hero-wash{position:absolute;top:0;left:0;right:0;height:560px;z-index:0;pointer-events:none;background:linear-gradient(180deg,var(--primary-light) 0%,rgba(240,253,244,0) 100%);opacity:.6}
.hero-glow{position:absolute;top:10%;right:-8%;width:55%;height:70%;z-index:0;pointer-events:none;background:radial-gradient(ellipse,rgba(22,163,74,.07) 0%,transparent 65%)}
.hero-inner{display:grid;grid-template-columns:1fr 1fr;align-items:center;gap:80px;width:100%;position:relative;z-index:1}
.hero-eyebrow{display:inline-flex;align-items:center;gap:7px;background:var(--white);border:1px solid var(--gray-200);border-radius:99px;padding:5px 13px 5px 7px;margin-bottom:28px;box-shadow:var(--shadow-xs)}
.hero-eb-badge{background:var(--primary);color:var(--white);font-size:10px;font-weight:800;padding:2px 8px;border-radius:99px;letter-spacing:.5px;font-family:var(--mono)}
.hero-eb-text{font-size:12.5px;font-weight:600;color:var(--gray-600)}
.hero-h1{font-size:clamp(40px,5vw,64px);font-weight:800;line-height:1.04;letter-spacing:-2.5px;color:var(--ink);margin-bottom:22px}
.hero-h1-accent{background:linear-gradient(135deg,var(--primary) 0%,#059669 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero-desc{font-size:17px;color:var(--gray-500);line-height:1.75;margin-bottom:36px;max-width:460px;font-weight:400}
.hero-actions{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:48px}
.btn-hero-primary{display:inline-flex;align-items:center;gap:8px;padding:13px 24px;border-radius:var(--r-lg);font-size:15px;font-weight:700;color:var(--white);text-decoration:none;background:var(--ink);box-shadow:0 1px 2px rgba(0,0,0,.1),0 4px 16px rgba(0,0,0,.12);transition:all .2s}
.btn-hero-primary:hover{background:#1a1a1a;transform:translateY(-2px);box-shadow:0 2px 4px rgba(0,0,0,.1),0 8px 28px rgba(0,0,0,.18)}
.btn-hero-secondary{display:inline-flex;align-items:center;gap:8px;padding:13px 20px;border-radius:var(--r-lg);font-size:15px;font-weight:600;color:var(--gray-700);text-decoration:none;background:var(--white);border:1.5px solid var(--gray-200);box-shadow:var(--shadow-xs);transition:all .2s}
.btn-hero-secondary:hover{border-color:var(--gray-300);background:var(--gray-50);transform:translateY(-1px);box-shadow:var(--shadow-sm)}
.hero-social-proof{display:flex;align-items:center;gap:14px}
.hero-avatars{display:flex}
.hero-av{width:32px;height:32px;border-radius:50%;border:2px solid var(--white);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;margin-left:-10px;overflow:hidden;flex-shrink:0}
.hero-av:first-child{margin-left:0}
.hero-sp-text{font-size:13px;color:var(--gray-500);font-weight:500}
.hero-sp-text strong{color:var(--gray-800);font-weight:700}

/* HERO VISUAL */
.hero-visual{position:relative;display:flex;align-items:center;justify-content:center}
.hero-visual-card{position:relative;width:100%;background:var(--white);border:1px solid var(--gray-200);border-radius:var(--r-2xl);overflow:hidden;box-shadow:0 0 0 1px rgba(0,0,0,.04),var(--shadow-xl),0 40px 80px -20px rgba(0,0,0,.1)}
.dash-topbar{height:44px;background:var(--gray-50);border-bottom:1px solid var(--gray-200);display:flex;align-items:center;padding:0 16px;gap:12px}
.dash-dots{display:flex;gap:6px}
.dash-dot{width:10px;height:10px;border-radius:50%}
.dash-url{flex:1;height:24px;background:var(--white);border:1px solid var(--gray-200);border-radius:6px;display:flex;align-items:center;padding:0 10px;gap:6px;font-size:10.5px;font-family:var(--mono);color:var(--gray-400)}
.dash-body{padding:20px}
.dash-label{font-size:10px;font-weight:700;color:var(--gray-400);letter-spacing:.8px;text-transform:uppercase;font-family:var(--mono);margin-bottom:10px}
.server-list{display:flex;flex-direction:column;gap:8px;margin-bottom:16px}
.server-row{background:var(--white);border:1px solid var(--gray-150);border-radius:var(--r-md);padding:10px 14px;display:flex;align-items:center;gap:12px;transition:border-color .15s}
.server-row:hover{border-color:var(--gray-300)}
.srv-status{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.srv-status.online{background:var(--primary);box-shadow:0 0 0 2px rgba(22,163,74,.15)}
.srv-status.building{background:var(--amber);animation:pulse-amber 1.5s infinite}
@keyframes pulse-amber{0%,100%{box-shadow:0 0 0 2px rgba(245,158,11,.15)}50%{box-shadow:0 0 0 4px rgba(245,158,11,.08)}}
.srv-name{font-size:12.5px;font-weight:700;color:var(--gray-800);font-family:var(--mono);flex:1}
.srv-ip{font-size:11px;color:var(--gray-400);font-family:var(--mono)}
.srv-os{font-size:10.5px;font-weight:600;color:var(--gray-500);background:var(--gray-100);padding:2px 7px;border-radius:99px}
.srv-badge{font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;font-family:var(--mono)}
.srv-badge.online{background:var(--primary-light);color:var(--primary);border:1px solid var(--primary-border)}
.srv-badge.building{background:#fffbeb;color:#d97706;border:1px solid #fde68a}
.metrics-row{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px}
.metric-card{background:var(--gray-50);border:1px solid var(--gray-150);border-radius:var(--r-md);padding:10px 12px}
.metric-val{font-size:18px;font-weight:800;color:var(--ink);font-family:var(--mono);letter-spacing:-1px;line-height:1}
.metric-lbl{font-size:9.5px;color:var(--gray-400);margin-top:3px;font-weight:600;letter-spacing:.4px;text-transform:uppercase;font-family:var(--mono)}
.metric-bar{height:3px;background:var(--gray-200);border-radius:99px;margin-top:8px;overflow:hidden}
.metric-fill{height:100%;border-radius:99px;background:var(--primary)}
.dash-deploy{display:flex;align-items:center;justify-content:center;gap:7px;background:var(--ink);color:var(--white);border-radius:var(--r-md);padding:9px 16px;font-size:12.5px;font-weight:700;cursor:pointer;transition:background .15s}
.float-pill{position:absolute;background:var(--white);border:1px solid var(--gray-200);border-radius:var(--r-lg);padding:8px 14px;box-shadow:var(--shadow-lg);white-space:nowrap;z-index:10}
.fp1{top:-18px;right:20px;animation:fp 5s ease-in-out infinite}
.fp2{bottom:-14px;left:20px;animation:fp 4.2s ease-in-out infinite .9s}
@keyframes fp{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
.fp-inner{display:flex;align-items:center;gap:7px}
.fp-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.fp-label{font-size:12px;font-weight:700;color:var(--gray-700)}
.fp-sub{font-size:10.5px;color:var(--gray-400);margin-top:1px;font-family:var(--mono)}

/* LOGOS */
.logos-strip{padding:40px max(24px,calc((100% - 1160px)/2));border-top:1px solid var(--gray-100);border-bottom:1px solid var(--gray-100);background:var(--gray-25)}
.logos-label{text-align:center;font-size:12px;font-weight:600;color:var(--gray-400);letter-spacing:.6px;text-transform:uppercase;font-family:var(--mono);margin-bottom:24px}
.logos-row{display:flex;align-items:center;justify-content:center;gap:48px;flex-wrap:wrap}
.logo-item{font-size:15px;font-weight:800;color:var(--gray-300);letter-spacing:-.5px;transition:color .2s;cursor:default}
.logo-item:hover{color:var(--gray-400)}

/* MARQUEE */
.marquee-wrap{overflow:hidden;padding:16px 0;background:var(--white);border-bottom:1px solid var(--gray-100)}
.marquee-track{display:flex;width:max-content;animation:mq 40s linear infinite}
.marquee-track:hover{animation-play-state:paused}
@keyframes mq{to{transform:translateX(-50%)}}
.mq-item{display:flex;align-items:center;gap:8px;padding:0 24px;font-size:12.5px;font-weight:600;color:var(--gray-400);border-right:1px solid var(--gray-150);white-space:nowrap;font-family:var(--mono)}
.mq-dot{width:5px;height:5px;border-radius:50%;flex-shrink:0}

/* STATS */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);border-bottom:1px solid var(--gray-150);background:var(--white)}
.stat-cell{padding:44px 32px;text-align:center;border-right:1px solid var(--gray-150);position:relative}
.stat-cell:last-child{border-right:none}
.stat-cell::after{content:'';position:absolute;top:0;left:24px;right:24px;height:2px;background:linear-gradient(90deg,transparent,var(--primary),transparent);opacity:.5}
.stat-num{font-size:42px;font-weight:800;color:var(--ink);letter-spacing:-2.5px;line-height:1;font-family:var(--mono)}
.stat-label{font-size:12px;color:var(--gray-400);margin-top:8px;font-weight:600;letter-spacing:.4px;text-transform:uppercase;font-family:var(--mono)}

/* SECTION */
.section{padding:100px max(24px,calc((100% - 1160px)/2))}
.section-sm{padding:80px max(24px,calc((100% - 1160px)/2))}
.tag{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--primary);font-family:var(--mono);margin-bottom:14px}
.tag::before{content:'';width:14px;height:1px;background:var(--primary)}
.sh{font-size:clamp(28px,3.8vw,44px);font-weight:800;letter-spacing:-1.8px;color:var(--ink);margin-bottom:14px;line-height:1.08}
.sh-sub{font-size:16px;color:var(--gray-500);line-height:1.72;max-width:500px}

/* BENTO */
.bento{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;margin-top:60px;background:var(--gray-150);border-radius:var(--r-xl);overflow:hidden;border:1px solid var(--gray-150)}
.bento-card{background:var(--white);padding:32px 28px;position:relative;overflow:hidden;transition:background .2s}
.bento-card:hover{background:var(--gray-25)}
.bento-icon{width:48px;height:48px;border-radius:var(--r-lg);display:flex;align-items:center;justify-content:center;font-size:20px;margin-bottom:18px;background:var(--gray-100);border:1px solid var(--gray-200);transition:all .2s}
.bento-card:hover .bento-icon{background:var(--primary-light);border-color:var(--primary-border)}
.bento-t{font-size:15px;font-weight:700;color:var(--ink);margin-bottom:8px}
.bento-d{font-size:13.5px;color:var(--gray-500);line-height:1.65}
.bento-card::after{content:'';position:absolute;bottom:0;left:0;right:0;height:1.5px;background:linear-gradient(90deg,transparent,var(--primary),transparent);transform:scaleX(0);transition:transform .35s}
.bento-card:hover::after{transform:scaleX(1)}

/* SPLIT + TERMINAL */
.split{display:grid;grid-template-columns:1fr 1fr;gap:80px;align-items:center}
.terminal{background:var(--gray-950);border-radius:var(--r-xl);overflow:hidden;box-shadow:var(--shadow-2xl);border:1px solid rgba(255,255,255,.06)}
.term-bar{height:40px;background:#0a0a0a;border-bottom:1px solid rgba(255,255,255,.06);display:flex;align-items:center;padding:0 14px;gap:7px}
.term-dot{width:10px;height:10px;border-radius:50%}
.term-title-text{margin-left:auto;font-size:11px;color:#555;font-family:var(--mono);letter-spacing:.2px}
.term-body{padding:20px 22px;font-family:var(--mono);font-size:12px;line-height:2}
.tl{display:flex;gap:8px;align-items:baseline}
.tp{color:#22c55e;flex-shrink:0}.tc{color:#e5e7eb}.tco{color:#4b5563}.to{color:#60a5fa}.ts{color:#22c55e}
.tcur{display:inline-block;width:6px;height:13px;background:#22c55e;border-radius:1px;animation:blink 1.1s step-end infinite;vertical-align:middle;margin-left:1px}
@keyframes blink{0%,100%{opacity:1}50%{opacity:0}}
.split-points{list-style:none;margin-top:32px;display:flex;flex-direction:column;gap:18px}
.sp-li{display:flex;align-items:flex-start;gap:14px;font-size:14.5px;color:var(--gray-500);line-height:1.6}
.sp-icon{width:34px;height:34px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:15px;background:var(--gray-100);border:1px solid var(--gray-200);transition:all .2s}
.sp-li:hover .sp-icon{background:var(--primary-light);border-color:var(--primary-border)}
.sp-li strong{color:var(--gray-800);font-weight:700;display:block;margin-bottom:2px}

/* PRICING */
.pricing-section{padding:100px max(24px,calc((100% - 1160px)/2));background:var(--gray-25);border-top:1px solid var(--gray-150);border-bottom:1px solid var(--gray-150);position:relative;overflow:hidden}
.pricing-section::before{content:'';position:absolute;top:-200px;left:50%;transform:translateX(-50%);width:900px;height:500px;background:radial-gradient(ellipse,rgba(22,163,74,.05) 0%,transparent 65%);pointer-events:none}
.pricing-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-top:56px}
.plan-card{background:var(--white);border:1px solid var(--gray-200);border-radius:var(--r-2xl);padding:32px 28px;position:relative;transition:all .25s}
.plan-card:hover{border-color:var(--gray-300);box-shadow:var(--shadow-xl);transform:translateY(-4px)}
.plan-featured{border-color:var(--ink);box-shadow:0 0 0 1px var(--ink),var(--shadow-xl)}
.plan-featured:hover{box-shadow:0 0 0 1px var(--ink),0 24px 48px rgba(0,0,0,.15);transform:translateY(-4px)}
.plan-badge{position:absolute;top:-13px;left:50%;transform:translateX(-50%);background:var(--ink);color:var(--white);font-size:10px;font-weight:800;padding:3px 14px;border-radius:99px;letter-spacing:.6px;font-family:var(--mono);white-space:nowrap}
.plan-tier{font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--gray-400);margin-bottom:10px;font-family:var(--mono)}
.plan-price{font-size:46px;font-weight:800;color:var(--ink);letter-spacing:-2.5px;line-height:1;font-family:var(--mono)}
.plan-price-period{font-size:14px;font-weight:400;color:var(--gray-400);letter-spacing:0;font-family:var(--font)}
.plan-desc{font-size:13px;color:var(--gray-500);margin:10px 0 22px;line-height:1.6}
.plan-specs{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:22px}
.spec-chip{background:var(--gray-50);border:1px solid var(--gray-200);border-radius:var(--r-sm);padding:7px 10px;font-family:var(--mono);font-size:11px;font-weight:600;color:var(--gray-700);text-align:center}
.plan-features{list-style:none;margin-bottom:26px;display:flex;flex-direction:column;gap:8px}
.plan-features li{font-size:13.5px;color:var(--gray-600);display:flex;align-items:center;gap:9px}
.plan-features li::before{content:'';width:16px;height:16px;border-radius:50%;flex-shrink:0;background:var(--primary-light);border:1px solid var(--primary-border);background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 16 16' xmlns='http://www.w3.org/2000/svg'%3E%3Cpolyline points='3.5,8 6.5,11 12.5,5' fill='none' stroke='%2316a34a' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");background-size:contain}
.plan-btn{display:block;width:100%;padding:12px;border-radius:var(--r-lg);font-size:14px;font-weight:700;text-align:center;text-decoration:none;transition:all .2s}
.plan-btn-primary{background:var(--ink);color:var(--white);box-shadow:0 1px 2px rgba(0,0,0,.1)}
.plan-btn-primary:hover{background:#1a1a1a;box-shadow:0 4px 16px rgba(0,0,0,.2);transform:translateY(-1px)}
.plan-btn-ghost{background:var(--white);color:var(--gray-700);border:1.5px solid var(--gray-200)}
.plan-btn-ghost:hover{border-color:var(--gray-300);background:var(--gray-50);transform:translateY(-1px)}

/* HOW IT WORKS */
.how-section{padding:100px max(24px,calc((100% - 1160px)/2))}
.steps-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:0;margin-top:56px;position:relative}
.steps-grid::before{content:'';position:absolute;top:25px;left:12.5%;right:12.5%;height:1px;background:var(--gray-200);z-index:0}
.step{text-align:center;padding:0 20px;position:relative;z-index:1}
.step-num{width:50px;height:50px;border-radius:var(--r-xl);background:var(--white);border:1.5px solid var(--gray-200);color:var(--gray-800);font-size:16px;font-weight:800;display:flex;align-items:center;justify-content:center;margin:0 auto 22px;box-shadow:var(--shadow-sm);font-family:var(--mono);transition:all .25s}
.step:hover .step-num{border-color:var(--primary);color:var(--primary);box-shadow:0 0 0 4px var(--primary-light)}
.step-title{font-size:15px;font-weight:700;color:var(--ink);margin-bottom:7px}
.step-desc{font-size:13px;color:var(--gray-500);line-height:1.65}

/* REVIEWS */
.reviews-section{padding:100px max(24px,calc((100% - 1160px)/2));background:var(--gray-25);border-top:1px solid var(--gray-150)}
.reviews-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-top:56px;perspective:1400px}
.review-card{background:var(--white);border:1px solid var(--gray-200);border-radius:var(--r-2xl);padding:28px 24px;position:relative;overflow:hidden;transition:transform .35s cubic-bezier(.25,.46,.45,.94),box-shadow .35s,border-color .3s;transform-style:preserve-3d}
.review-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--primary),transparent);opacity:0;transition:opacity .3s}
.review-card:hover{transform:translateY(-6px) rotateX(2deg);box-shadow:var(--shadow-2xl);border-color:var(--gray-300)}
.review-card:hover::before{opacity:1}
.review-card:nth-child(even){transform:translateY(16px)}
.review-card:nth-child(even):hover{transform:translateY(10px) rotateX(2deg)}
.rev-stars{display:flex;gap:2px;margin-bottom:14px}
.rev-star{font-size:13px;color:#f59e0b}
.rev-badge{position:absolute;top:18px;right:18px;background:var(--gray-100);color:var(--gray-600);font-size:10px;font-weight:700;padding:3px 9px;border-radius:99px;font-family:var(--mono)}
.rev-text{font-size:13.5px;color:var(--gray-600);line-height:1.75;margin-bottom:22px}
.rev-text::before{content:'\201C';font-size:28px;line-height:0;color:var(--gray-200);vertical-align:middle;margin-right:2px;font-weight:900}
.rev-author{display:flex;align-items:center;gap:11px;padding-top:16px;border-top:1px solid var(--gray-100)}
.rev-av{width:40px;height:40px;border-radius:var(--r-md);flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:900;color:var(--white)}
.rev-name{font-size:13.5px;font-weight:800;color:var(--ink)}
.rev-role{font-size:11px;color:var(--gray-400);margin-top:1px;font-family:var(--mono)}

/* FAQ */
.faq-section{padding:80px max(24px,calc((100% - 800px)/2))}
.faq-grid{margin-top:48px}
.faq-item{border-bottom:1px solid var(--gray-150)}
.faq-q{width:100%;display:flex;align-items:center;justify-content:space-between;padding:20px 0;cursor:pointer;font-size:15.5px;font-weight:700;color:var(--ink);background:none;border:none;text-align:left;gap:16px;font-family:var(--font)}
.faq-icon{width:22px;height:22px;border-radius:50%;background:var(--gray-100);border:1px solid var(--gray-200);display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .2s}
.faq-item.open .faq-icon{background:var(--primary);border-color:var(--primary);transform:rotate(45deg)}
.faq-icon svg{width:10px;height:10px;stroke:var(--gray-600)}
.faq-item.open .faq-icon svg{stroke:white}
.faq-a{font-size:14.5px;color:var(--gray-500);line-height:1.75;max-height:0;overflow:hidden;transition:max-height .3s ease,padding .3s ease;padding-bottom:0}
.faq-item.open .faq-a{max-height:200px;padding-bottom:20px}

/* VIDEO */
.video-section{padding:0 max(24px,calc((100% - 1160px)/2)) 80px;text-align:center}
.video-frame{position:relative;border-radius:var(--r-2xl);overflow:hidden;max-width:820px;margin:48px auto 0;aspect-ratio:16/9;border:1px solid var(--gray-200);box-shadow:var(--shadow-2xl)}
.video-frame iframe{width:100%;height:100%;border:none;display:block}

/* CTA */
.cta-section{padding:0 max(24px,calc((100% - 1160px)/2)) 100px}
.cta-box{background:var(--ink);border-radius:var(--r-2xl);padding:80px 60px;text-align:center;position:relative;overflow:hidden}
.cta-grid{position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.04) 1px,transparent 1px);background-size:40px 40px;mask-image:radial-gradient(ellipse 100% 100% at 50% 50%,black 20%,transparent 100%);pointer-events:none}
.cta-glow{position:absolute;top:-40%;left:50%;transform:translateX(-50%);width:600px;height:400px;background:radial-gradient(ellipse,rgba(34,197,94,.15) 0%,transparent 60%);pointer-events:none}
.cta-h{font-size:clamp(28px,4vw,50px);font-weight:800;color:var(--white);letter-spacing:-2px;margin-bottom:16px;position:relative;z-index:1;line-height:1.06}
.cta-sub{font-size:16px;color:rgba(255,255,255,.55);margin-bottom:40px;position:relative;z-index:1}
.cta-btns{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;position:relative;z-index:1}
.btn-cta-pri{display:inline-flex;align-items:center;gap:8px;padding:14px 28px;border-radius:var(--r-lg);font-size:15px;font-weight:700;background:var(--white);color:var(--ink);text-decoration:none;box-shadow:0 4px 20px rgba(0,0,0,.3);transition:all .2s}
.btn-cta-pri:hover{transform:translateY(-2px);box-shadow:0 8px 32px rgba(0,0,0,.4)}
.btn-cta-sec{display:inline-flex;align-items:center;gap:8px;padding:14px 22px;border-radius:var(--r-lg);font-size:15px;font-weight:600;color:rgba(255,255,255,.7);text-decoration:none;border:1.5px solid rgba(255,255,255,.15);background:transparent;transition:all .2s}
.btn-cta-sec:hover{color:var(--white);border-color:rgba(255,255,255,.35)}

/* FOOTER */
.footer{border-top:1px solid var(--gray-150);padding:28px max(24px,calc((100% - 1160px)/2));display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;background:var(--white)}
.foot-logo{display:flex;align-items:center;gap:8px;font-size:15px;font-weight:800;color:var(--ink)}
.foot-copy{font-size:12px;color:var(--gray-400);font-family:var(--mono)}
.foot-links{display:flex;gap:20px}
.foot-links a{font-size:12.5px;color:var(--gray-400);text-decoration:none;font-family:var(--mono);transition:color .14s}
.foot-links a:hover{color:var(--ink)}

/* SCROLL REVEAL */
.reveal{opacity:0;transform:translateY(20px);transition:opacity .6s ease,transform .6s ease}
.reveal.in{opacity:1;transform:none}

/* RESPONSIVE */
@media(max-width:1060px){
  .hero-inner{grid-template-columns:1fr;text-align:center}
  .hero-desc,.hero-actions,.hero-social-proof{justify-content:center;margin-left:auto;margin-right:auto}
  .hero-eyebrow{display:inline-flex}
  .hero-visual{display:none}
  .split{grid-template-columns:1fr}
  .bento{grid-template-columns:1fr 1fr}
  .pricing-grid{grid-template-columns:1fr}
  .reviews-grid{grid-template-columns:1fr 1fr}
  .stats-row{grid-template-columns:1fr 1fr}
  .stat-cell:nth-child(2){border-right:none}
  .stat-cell:nth-child(1),.stat-cell:nth-child(2){border-bottom:1px solid var(--gray-150)}
}
@media(max-width:768px){
  .nav-center,.nav-right{display:none}
  .ham{display:flex}
  .drawer{display:block}
  .bento{grid-template-columns:1fr}
  .reviews-grid{grid-template-columns:1fr}
  .review-card:nth-child(even){transform:none}
  .review-card:nth-child(even):hover{transform:translateY(-6px) rotateX(2deg)}
  .steps-grid{grid-template-columns:1fr 1fr;gap:32px}
  .steps-grid::before{display:none}
  .cta-box{padding:44px 24px}
  .logos-row{gap:28px}
}
@media(max-width:480px){
  .steps-grid{grid-template-columns:1fr}
  .stats-row{grid-template-columns:1fr 1fr}
  .hero-actions{flex-direction:column;align-items:stretch;text-align:center}
}
</style>
</head>
<body>

<!-- NAV -->
<nav class="nav" id="topnav">
  <a href="<?= BASE_URL ?>/" class="nav-logo">
    <div class="nav-logomark">
      <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg>
    </div>
    <span class="nav-wordmark"><?= APP_NAME ?></span>
  </a>
  <div class="nav-center">
    <a href="#features" class="nav-link">Features</a>
    <a href="#pricing"  class="nav-link">Pricing</a>
    <a href="#how"      class="nav-link">How It Works</a>
    <a href="#reviews"  class="nav-link">Reviews</a>
    <a href="#faq"      class="nav-link">FAQ</a>
  </div>
  <div class="nav-right">
    <?php if ($logged_in): ?>
      <a href="<?= BASE_URL ?>/dashboard.php" class="nav-dash-link">
        <div class="nav-av"><img src="<?= getGravatar($current['email'], $current['user_profile']) ?>" alt=""></div>
        Dashboard →
      </a>
    <?php else: ?>
      <a href="<?= BASE_URL ?>/login.php"    class="nav-signin">Sign in</a>
      <a href="<?= BASE_URL ?>/register.php" class="nav-getstarted">
        Get started
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
      </a>
    <?php endif; ?>
  </div>
  <button class="ham" id="nav-ham" onclick="navToggle()">
    <div class="ham-l"></div><div class="ham-l"></div><div class="ham-l"></div>
  </button>
</nav>

<!-- DRAWER -->
<div class="drawer" id="nav-drawer">
  <?php if ($logged_in): ?>
  <div class="duser">
    <div class="duav"><img style="width:100%;height:100%;object-fit:cover;border-radius:8px" src="<?= getGravatar($current['email'], $current['user_profile']) ?>" alt=""></div>
    <div><div style="font-size:13px;font-weight:700;color:var(--ink)">@<?= $uname ?></div><div style="font-size:11px;color:var(--gray-400);font-family:var(--mono)">logged in</div></div>
  </div>
  <?php endif; ?>
  <a href="#features" class="dl" onclick="navClose()">Features</a>
  <a href="#pricing"  class="dl" onclick="navClose()">Pricing</a>
  <a href="#how"      class="dl" onclick="navClose()">How It Works</a>
  <a href="#reviews"  class="dl" onclick="navClose()">Reviews</a>
  <a href="#faq"      class="dl" onclick="navClose()">FAQ</a>
  <div class="ddiv"></div>
  <div class="dacts">
    <?php if ($logged_in): ?>
      <a href="<?= BASE_URL ?>/dashboard.php" class="da da-pri">Go to Dashboard →</a>
    <?php else: ?>
      <a href="<?= BASE_URL ?>/login.php"    class="da da-ghost">Sign In</a>
      <a href="<?= BASE_URL ?>/register.php" class="da da-pri">Get Started Free</a>
    <?php endif; ?>
  </div>
</div>

<!-- HERO -->
<section class="hero">
  <div class="hero-dots"></div>
  <div class="hero-wash"></div>
  <div class="hero-glow"></div>
  <div class="hero-inner">
    <!-- Left -->
    <div>
      <div class="hero-eyebrow">
        <span class="hero-eb-badge">NEW</span>
        <span class="hero-eb-text">Mumbai datacenter — 8ms avg latency</span>
      </div>
      <h1 class="hero-h1">
        The fastest way to<br>
        deploy <span class="hero-h1-accent">enterprise VPS</span><br>
        in India.
      </h1>
      <p class="hero-desc">Dedicated vCPUs, NVMe SSDs, full root access — managed through one powerful dashboard. Pay in INR with zero hidden charges or forex fees.</p>
      <div class="hero-actions">
        <a href="<?= BASE_URL ?>/register.php" class="btn-hero-primary">
          Start deploying free
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <a href="#how" class="btn-hero-secondary">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>
          Watch how it works
        </a>
      </div>
      <div class="hero-social-proof">
        <div class="hero-avatars">
          <div class="hero-av" style="background:#d1fae5;color:#15803d">R</div>
          <div class="hero-av" style="background:#ede9fe;color:#7c3aed">P</div>
          <div class="hero-av" style="background:#fef3c7;color:#d97706">A</div>
          <div class="hero-av" style="background:#fee2e2;color:#dc2626">S</div>
          <div class="hero-av" style="background:#e0f2fe;color:#0369a1">N</div>
        </div>
        <span class="hero-sp-text">Trusted by <strong>2,400+</strong> developers across India</span>
      </div>
    </div>

    <!-- Right — Dashboard mockup -->
    <div class="hero-visual">
      <div class="float-pill fp1">
        <div class="fp-inner">
          <div class="fp-dot" style="background:#16a34a;box-shadow:0 0 6px rgba(22,163,74,.4)"></div>
          <div>
            <div class="fp-label">All systems operational</div>
            <div class="fp-sub">99.97% uptime · last 90d</div>
          </div>
        </div>
      </div>
      <div class="float-pill fp2">
        <div class="fp-inner">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5"><polyline points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
          <div>
            <div class="fp-label" style="color:#16a34a">Deployed in 38s</div>
            <div class="fp-sub">ubuntu-prod-01 · Mumbai</div>
          </div>
        </div>
      </div>
      <div class="hero-visual-card">
        <div class="dash-topbar">
          <div class="dash-dots">
            <div class="dash-dot" style="background:#ff5f57"></div>
            <div class="dash-dot" style="background:#febc2e"></div>
            <div class="dash-dot" style="background:#28c840"></div>
          </div>
          <div class="dash-url">
            <span style="color:#16a34a;font-size:9px">🔒</span>
            app.<?= strtolower(APP_NAME) ?>.in/servers
          </div>
        </div>
        <div class="dash-body">
          <div class="dash-label">Your Servers</div>
          <div class="server-list">
            <div class="server-row">
              <div class="srv-status online"></div>
              <span class="srv-name">prod-api-01</span>
              <span class="srv-ip">49.13.84.22</span>
              <span class="srv-os">Ubuntu 24</span>
              <span class="srv-badge online">Online</span>
            </div>
            <div class="server-row">
              <div class="srv-status online"></div>
              <span class="srv-name">db-primary</span>
              <span class="srv-ip">49.13.91.07</span>
              <span class="srv-os">Debian 12</span>
              <span class="srv-badge online">Online</span>
            </div>
            <div class="server-row">
              <div class="srv-status building"></div>
              <span class="srv-name">staging-v2</span>
              <span class="srv-ip">Provisioning…</span>
              <span class="srv-os">CentOS 9</span>
              <span class="srv-badge building">Building</span>
            </div>
          </div>
          <div class="dash-label">Resource Usage</div>
          <div class="metrics-row">
            <div class="metric-card">
              <div class="metric-val">24%</div>
              <div class="metric-lbl">CPU</div>
              <div class="metric-bar"><div class="metric-fill" style="width:24%"></div></div>
            </div>
            <div class="metric-card">
              <div class="metric-val">3.1<span style="font-size:10px;font-weight:400;color:var(--gray-400)">GB</span></div>
              <div class="metric-lbl">RAM</div>
              <div class="metric-bar"><div class="metric-fill" style="width:39%;background:#2563eb"></div></div>
            </div>
            <div class="metric-card">
              <div class="metric-val">1.2<span style="font-size:10px;font-weight:400;color:var(--gray-400)">TB</span></div>
              <div class="metric-lbl">BW</div>
              <div class="metric-bar"><div class="metric-fill" style="width:6%;background:#f59e0b"></div></div>
            </div>
          </div>
          <div class="dash-deploy">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Deploy New Server
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- LOGOS -->
<div class="logos-strip">
  <div class="logos-label">Trusted by engineering teams at</div>
  <div class="logos-row">
    <span class="logo-item">Razorpay</span>
    <span class="logo-item">Zerodha</span>
    <span class="logo-item">Meesho</span>
    <span class="logo-item">ShareChat</span>
    <span class="logo-item">BrowserStack</span>
    <span class="logo-item">Freshworks</span>
  </div>
</div>

<!-- MARQUEE -->
<div class="marquee-wrap">
  <div class="marquee-track">
    <?php
    $mItems=[['Mumbai DC','#16a34a'],['NVMe SSD RAID','#2563eb'],['IPv4 + IPv6','#7c3aed'],['Cloud Firewall','#f59e0b'],['INR Billing','#16a34a'],['UPI / Cards / NetBanking','#2563eb'],['SSH Key Auth','#7c3aed'],['60s Deploy','#16a34a'],['DDoS Protection','#f59e0b'],['Full Root Access','#2563eb'],['Snapshots','#7c3aed'],['REST API','#16a34a'],['Mumbai DC','#16a34a'],['NVMe SSD RAID','#2563eb'],['IPv4 + IPv6','#7c3aed'],['Cloud Firewall','#f59e0b'],['INR Billing','#16a34a'],['UPI / Cards / NetBanking','#2563eb'],['SSH Key Auth','#7c3aed'],['60s Deploy','#16a34a'],['DDoS Protection','#f59e0b'],['Full Root Access','#2563eb']];
    foreach($mItems as[$l,$c]):?><div class="mq-item"><span class="mq-dot" style="background:<?=$c?>"></span><?=$l?></div><?php endforeach;?>
  </div>
</div>

<!-- STATS -->
<div class="stats-row">
  <div class="stat-cell reveal"><div class="stat-num">5,000+</div><div class="stat-label">Active Servers</div></div>
  <div class="stat-cell reveal"><div class="stat-num">99.97%</div><div class="stat-label">Uptime · 90 Days</div></div>
  <div class="stat-cell reveal"><div class="stat-num">38s</div><div class="stat-label">Avg Boot Time</div></div>
  <div class="stat-cell reveal"><div class="stat-num">2,400+</div><div class="stat-label">Customers</div></div>
</div>

<!-- FEATURES -->
<section class="section" id="features">
  <div class="tag">Features</div>
  <h2 class="sh">Everything your infrastructure needs,<br>nothing it doesn't.</h2>
  <p class="sh-sub">Built for developers who value speed, reliability, and full control — without enterprise bloat.</p>
  <div class="bento">
    <?php
    $features=[
      ['⚡','Instant Deploy','Spin up a VPS in under 60 seconds. Pick your OS, plan, and region — we handle the rest automatically.'],
      ['📊','Live Monitoring','Real-time CPU, RAM, disk, and network visibility. Know exactly what your servers are doing, always.'],
      ['🔑','SSH Key Management','Add and rotate SSH keys from your dashboard. Passwordless root access across every server you own.'],
      ['🔥','Cloud Firewall','Define granular ingress and egress rules in seconds. Protect workloads without touching iptables.'],
      ['💳','INR Wallet Billing','Prepaid wallet with no forex charges. Top up via UPI, debit/credit cards, or net banking — instantly.'],
      ['🔌','Developer API','Full REST API for every action on the platform. Automate your entire VPS lifecycle from CI/CD pipelines.'],
    ];
    foreach($features as[$icon,$title,$desc]):?>
    <div class="bento-card reveal"><div class="bento-icon"><?=$icon?></div><div class="bento-t"><?=$title?></div><div class="bento-d"><?=$desc?></div></div>
    <?php endforeach;?>
  </div>
</section>

<!-- SPLIT — TERMINAL -->
<div class="section-sm" style="padding-top:0">
  <div class="split">
    <div>
      <div class="tag">Developer First</div>
      <h2 class="sh">Built for<br>power users.</h2>
      <p class="sh-sub" style="margin-bottom:0">Full control from terminal to dashboard. No black boxes, no lock-in, no nonsense.</p>
      <ul class="split-points">
        <li class="sp-li"><div class="sp-icon">🐧</div><div><strong>Ubuntu, Debian, CentOS, AlmaLinux</strong>Latest stable releases, always available on deploy.</div></li>
        <li class="sp-li"><div class="sp-icon">⚡</div><div><strong>NVMe SSD storage</strong>10× faster random I/O compared to traditional SATA drives.</div></li>
        <li class="sp-li"><div class="sp-icon">🌐</div><div><strong>IPv4 + IPv6 included</strong>Dedicated IPs on every server. No shared IPs, no extra cost.</div></li>
        <li class="sp-li"><div class="sp-icon">📸</div><div><strong>Snapshots & automated backups</strong>One-click restore to any point. Sleep soundly.</div></li>
      </ul>
    </div>
    <div>
      <div class="terminal">
        <div class="term-bar">
          <div class="term-dot" style="background:#ff5f57"></div>
          <div class="term-dot" style="background:#febc2e"></div>
          <div class="term-dot" style="background:#28c840"></div>
          <span class="term-title-text"><?= APP_NAME ?> CLI — deploy</span>
        </div>
        <div class="term-body">
          <div class="tl"><span class="tp">$</span><span class="tc">&nbsp;vps create --plan cx22 --os ubuntu-24.04</span></div>
          <div class="tl"><span class="tco">&nbsp;&nbsp;# Connecting to <?= APP_NAME ?> API...</span></div>
          <div class="tl"><span class="to">&nbsp;&nbsp;✦ Server provisioned · ID: 58291034</span></div>
          <div class="tl"><span class="to">&nbsp;&nbsp;✦ Region: IN-MUM · IPv4: 49.13.84.22</span></div>
          <div class="tl"><span class="to">&nbsp;&nbsp;✦ Installing Ubuntu 24.04 LTS...</span></div>
          <div class="tl"><span class="ts">&nbsp;&nbsp;✓ Server RUNNING · Boot time: 38s</span></div>
          <div class="tl" style="margin-top:6px"><span class="tp">$</span><span class="tc">&nbsp;ssh root@49.13.84.22</span></div>
          <div class="tl"><span class="ts">&nbsp;&nbsp;Welcome to Ubuntu 24.04 — <?= APP_NAME ?></span></div>
          <div class="tl"><span class="tp">root@prod-api-01:~#</span><span class="tcur"></span></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- PRICING -->
<div class="pricing-section" id="pricing">
  <div style="text-align:center;position:relative;z-index:1">
    <div class="tag" style="display:inline-flex">Pricing</div>
    <h2 class="sh" style="max-width:560px;margin:0 auto 12px">Simple, transparent pricing.</h2>
    <p class="sh-sub" style="margin:0 auto;text-align:center">Pay for what you use. All prices in INR. No forex fees. Cancel anytime.</p>
  </div>
  <div class="pricing-grid">
    <div class="plan-card reveal">
      <div class="plan-tier">Starter</div>
      <div class="plan-price">₹299<span class="plan-price-period">/mo</span></div>
      <div class="plan-desc">Perfect for side projects, staging environments, and experimenting.</div>
      <div class="plan-specs"><div class="spec-chip">2 vCPU</div><div class="spec-chip">2 GB RAM</div><div class="spec-chip">40 GB NVMe</div><div class="spec-chip">20 TB BW</div></div>
      <ul class="plan-features"><li>Ubuntu / Debian / CentOS</li><li>IPv4 + IPv6 included</li><li>SSH Key Authentication</li><li>Basic Cloud Firewall</li><li>Community Support</li></ul>
      <a href="<?= BASE_URL ?>/register.php" class="plan-btn plan-btn-ghost">Get started</a>
    </div>
    <div class="plan-card plan-featured reveal">
      <div class="plan-badge">Most Popular</div>
      <div class="plan-tier">Professional</div>
      <div class="plan-price">₹799<span class="plan-price-period">/mo</span></div>
      <div class="plan-desc">For production workloads, growing APIs, and business-critical services.</div>
      <div class="plan-specs"><div class="spec-chip">4 vCPU</div><div class="spec-chip">8 GB RAM</div><div class="spec-chip">160 GB NVMe</div><div class="spec-chip">20 TB BW</div></div>
      <ul class="plan-features"><li>Everything in Starter</li><li>Priority Support (4h SLA)</li><li>Automated Daily Backups</li><li>Private Networking</li><li>Advanced Firewall Rules</li><li>Monitoring &amp; Alerts</li></ul>
      <a href="<?= BASE_URL ?>/register.php" class="plan-btn plan-btn-primary">Get started →</a>
    </div>
    <div class="plan-card reveal">
      <div class="plan-tier">Enterprise</div>
      <div class="plan-price">₹1,999<span class="plan-price-period">/mo</span></div>
      <div class="plan-desc">Dedicated resources for high-traffic applications and mission-critical infrastructure.</div>
      <div class="plan-specs"><div class="spec-chip">8 vCPU</div><div class="spec-chip">16 GB RAM</div><div class="spec-chip">240 GB NVMe</div><div class="spec-chip">30 TB BW</div></div>
      <ul class="plan-features"><li>Everything in Pro</li><li>Dedicated vCPU Cores</li><li>Load Balancer Included</li><li>Custom DNS Zones</li><li>99.99% SLA Guarantee</li><li>Dedicated Account Manager</li></ul>
      <a href="<?= BASE_URL ?>/register.php" class="plan-btn plan-btn-ghost">Contact sales</a>
    </div>
  </div>
</div>

<!-- HOW IT WORKS -->
<div class="how-section" id="how">
  <div style="text-align:center">
    <div class="tag" style="display:inline-flex">Process</div>
    <h2 class="sh" style="max-width:440px;margin:0 auto 10px">From signup to SSH<br>in four steps.</h2>
    <p class="sh-sub" style="margin:0 auto;text-align:center">No complex setup. No credit card upfront. Just fast, reliable infrastructure.</p>
  </div>
  <div class="steps-grid">
    <div class="step reveal"><div class="step-num">01</div><div class="step-title">Create Account</div><div class="step-desc">Sign up in under 60 seconds. Verify your email and you're ready to go.</div></div>
    <div class="step reveal"><div class="step-num">02</div><div class="step-title">Add Credits</div><div class="step-desc">Top up your INR wallet via UPI, debit/credit cards, or net banking.</div></div>
    <div class="step reveal"><div class="step-num">03</div><div class="step-title">Deploy Server</div><div class="step-desc">Select your plan, OS image, and datacenter region. Launch in seconds.</div></div>
    <div class="step reveal"><div class="step-num">04</div><div class="step-title">Take Control</div><div class="step-desc">SSH in with full root access. Your stack, your rules, your server.</div></div>
  </div>
</div>

<!-- VIDEO -->
<div class="video-section">
  <div class="tag" style="display:inline-flex">Demo</div>
  <h2 class="sh" style="text-align:center;margin-top:4px">See it in action.</h2>
  <div class="video-frame">
    <iframe src="https://www.youtube.com/embed/oafxkMv4xnc" allowfullscreen title="<?= APP_NAME ?> Platform Demo"></iframe>
  </div>
</div>

<!-- REVIEWS -->
<section class="reviews-section" id="reviews">
  <div style="text-align:center">
    <div class="tag" style="display:inline-flex">Customer Reviews</div>
    <h2 class="sh" style="max-width:480px;margin:0 auto 12px">Trusted by developers<br>across India.</h2>
    <p class="sh-sub" style="margin:0 auto;text-align:center">Real teams, real infrastructure, real results.</p>
  </div>
  <div class="reviews-grid">
    <?php
    $reviews=[
      ['R','Rahul M.','Full-Stack Dev · Mumbai','#16a34a','Deploying is insanely fast. I had my production VPS running in under a minute. INR billing with UPI is a massive plus — no forex surprises ever.','Pro Plan','#d1fae5'],
      ['P','Priya S.','DevOps Engineer · Bangalore','#7c3aed','The dashboard is clean and powerful. Managing 8 servers from one place with real-time metrics is exactly what I needed. Support is genuinely fast.','Enterprise','#ede9fe'],
      ['A','Arjun K.','Startup Founder · Hyderabad','#0891b2','Switched from a foreign provider, saved 40%, and got better latency for Indian users. The Mumbai DC performance is unmatched.','Pro Plan','#e0f2fe'],
      ['N','Nitesh T.','Backend Engineer · Delhi','#16a34a','The REST API is clean and well-documented. Automated our entire VPS lifecycle in CI/CD. Zero downtime deployments in 8 months straight.','Verified','#d1fae5'],
      ['S','Sneha R.','Cloud Architect · Pune','#d97706','Finally a provider that understands Indian developers. UPI payments, INR invoices, responsive support. Smooth from day one.','Pro Plan','#fef3c7'],
      ['V','Vikram B.','SaaS Founder · Chennai','#7c3aed','Migrated our entire infrastructure here. Firewall rules are intuitive, snapshots work perfectly, and pricing is completely transparent.','Enterprise','#ede9fe'],
    ];
    foreach($reviews as[$av,$name,$role,$color,$text,$badge,$bg]):?>
    <div class="review-card reveal">
      <div class="rev-badge"><?=$badge?></div>
      <div class="rev-stars"><span class="rev-star">★</span><span class="rev-star">★</span><span class="rev-star">★</span><span class="rev-star">★</span><span class="rev-star">★</span></div>
      <p class="rev-text"><?=$text?></p>
      <div class="rev-author">
        <div class="rev-av" style="background:<?=$bg?>;color:<?=$color?>"><?=$av?></div>
        <div><div class="rev-name"><?=$name?></div><div class="rev-role"><?=$role?></div></div>
      </div>
    </div>
    <?php endforeach;?>
  </div>
</section>

<!-- FAQ -->
<section class="faq-section" id="faq">
  <div style="text-align:center">
    <div class="tag" style="display:inline-flex">FAQ</div>
    <h2 class="sh" style="margin:0 auto">Frequently asked questions.</h2>
  </div>
  <div class="faq-grid">
    <?php
    $faqs=[
      ['Can I upgrade my plan later?','Yes — you can resize your VPS anytime from the dashboard. Upgrades take effect within minutes without any data loss.'],
      ['Do you support UPI payments?','Absolutely. We accept UPI, all major debit/credit cards, and net banking. All transactions are in INR with no foreign exchange fees.'],
      ['What operating systems are available?','We offer Ubuntu 20.04/22.04/24.04, Debian 11/12, CentOS 9, AlmaLinux 8/9, and one-click app images like LAMP, WordPress, and more.'],
      ['Is there a free trial?','New accounts receive ₹200 credit on signup — enough to run a Starter server for several days and evaluate the platform fully.'],
      ['What support do you provide?','Starter plans include community support. Pro and Enterprise plans include priority ticket support with guaranteed response times.'],
      ['Can I cancel anytime?','Yes. No long-term contracts. Your wallet balance is refundable. Cancel with one click — no questions asked.'],
    ];
    foreach($faqs as$i=>[$q,$a]):?>
    <div class="faq-item" id="faq-<?=$i?>">
      <button class="faq-q" onclick="toggleFaq(<?=$i?>)">
        <?=$q?>
        <div class="faq-icon"><svg viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="5" y1="1" x2="5" y2="9"/><line x1="1" y1="5" x2="9" y2="5"/></svg></div>
      </button>
      <div class="faq-a"><?=$a?></div>
    </div>
    <?php endforeach;?>
  </div>
</section>

<!-- CTA -->
<div class="cta-section">
  <div class="cta-box">
    <div class="cta-grid"></div>
    <div class="cta-glow"></div>
    <h2 class="cta-h">Ready to deploy your<br>first server?</h2>
    <p class="cta-sub">Join 2,400+ developers already running on <?= APP_NAME ?>. Start free, scale on demand.</p>
    <div class="cta-btns">
      <a href="<?= BASE_URL ?>/register.php" class="btn-cta-pri">
        Create free account
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
      </a>
      <a href="mailto:<?= get_setting('company_email','support@greathost.in') ?>" class="btn-cta-sec">Talk to sales</a>
    </div>
  </div>
</div>

<!-- FOOTER -->
<footer class="footer">
  <div class="foot-logo">
    <div class="nav-logomark" style="width:26px;height:26px;border-radius:7px">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg>
    </div>
    <?= APP_NAME ?>
  </div>
  <div class="foot-copy">© <?= date('Y') ?> <?= APP_NAME ?>. All rights reserved.</div>
  <div class="foot-links">
    <a href="<?= BASE_URL ?>/login.php">login</a>
    <a href="<?= BASE_URL ?>/register.php">register</a>
    <a href="mailto:<?= get_setting('company_email','support@greathost.in') ?>">support</a>
  </div>
</footer>

<script>
/* Nav scroll */
window.addEventListener('scroll',function(){document.getElementById('topnav').classList.toggle('scrolled',window.scrollY>12);},{passive:true});

/* Hamburger */
var _o=false;
function navToggle(){_o=!_o;document.getElementById('nav-ham').classList.toggle('open',_o);document.getElementById('nav-drawer').classList.toggle('open',_o);document.body.style.overflow=_o?'hidden':'';}
function navClose(){_o=false;document.getElementById('nav-ham').classList.remove('open');document.getElementById('nav-drawer').classList.remove('open');document.body.style.overflow='';}
document.addEventListener('click',function(e){if(_o&&!document.getElementById('nav-ham').contains(e.target)&&!document.getElementById('nav-drawer').contains(e.target))navClose();});
window.addEventListener('resize',function(){if(window.innerWidth>768)navClose();});
document.querySelectorAll('a[href^="#"]').forEach(function(a){a.addEventListener('click',function(e){var t=document.querySelector(a.getAttribute('href'));if(t){e.preventDefault();navClose();setTimeout(function(){t.scrollIntoView({behavior:'smooth',block:'start'});},_o?260:0);}});});

/* FAQ */
function toggleFaq(i){var item=document.getElementById('faq-'+i);var isOpen=item.classList.contains('open');document.querySelectorAll('.faq-item.open').forEach(function(el){el.classList.remove('open');});if(!isOpen)item.classList.add('open');}

/* 3D tilt on review cards */
document.querySelectorAll('.review-card').forEach(function(card){
  card.addEventListener('mousemove',function(e){var r=card.getBoundingClientRect();var x=(e.clientX-r.left)/r.width-.5;var y=(e.clientY-r.top)/r.height-.5;card.style.transform='translateY(-6px) rotateX('+(y*-7)+'deg) rotateY('+(x*7)+'deg)';card.style.boxShadow='0 '+(18+y*8)+'px '+(40+Math.abs(x)*18)+'px rgba(0,0,0,.1)';});
  card.addEventListener('mouseleave',function(){card.style.transform='';card.style.boxShadow='';});
});

/* Scroll reveal */
var io=new IntersectionObserver(function(entries){entries.forEach(function(e){if(e.isIntersecting){e.target.classList.add('in');io.unobserve(e.target);}});},{threshold:.1,rootMargin:'0px 0px -40px 0px'});
document.querySelectorAll('.reveal').forEach(function(el,i){el.style.transitionDelay=(i%3)*.08+'s';io.observe(el);});

/* Stat counter */
document.querySelectorAll('.stat-num').forEach(function(el){
  var raw=el.textContent.trim();
  var num=parseFloat(raw.replace(/[^0-9.]/g,''));
  var suffix=raw.replace(/[0-9.]/g,'');
  var io2=new IntersectionObserver(function(entries){entries.forEach(function(e){if(!e.isIntersecting)return;var start=0,dur=1400,startT=null;function step(t){if(!startT)startT=t;var p=Math.min((t-startT)/dur,1);var ease=1-Math.pow(1-p,3);el.textContent=(Number.isInteger(num)?Math.round(ease*num):(Math.round(ease*num*10)/10).toFixed(2))+suffix;if(p<1)requestAnimationFrame(step);}requestAnimationFrame(step);io2.unobserve(el);});},{threshold:.5});
  io2.observe(el);
});
</script>
</body>
</html>
