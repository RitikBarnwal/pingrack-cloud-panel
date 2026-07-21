<?php
require_once __DIR__ . '/includes/bootstrap.php';

// ── Frontend toggle ───────────────────────────────────────────
// When the public frontend is disabled, this install acts purely as a
// management/backup panel: visitors skip the marketing landing page and
// go straight to login (or their dashboard if already signed in).
// Controlled per-install via the `frontend_enabled` setting (admin → Settings).
if (get_setting('frontend_enabled', '1') !== '1') {
    session_start_safe();
    header('Location: ' . BASE_URL . (is_logged_in() ? '/dashboard.php' : '/login.php'));
    exit;
}

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
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
<style>
/* ──────────────────────────────────────────────
   LANDING PAGE — LIGHT THEME
   All colors from existing --primary (green) + grays
─────────────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:'Plus Jakarta Sans',sans-serif;background:white;color:var(--gray-900);-webkit-font-smoothing:antialiased;overflow-x:hidden}

/* ── NAV ─────────────────────────────────────── */
.lp-nav{
  position:fixed;top:0;left:0;right:0;z-index:200;
  height:62px;display:flex;align-items:center;
  padding:0 max(20px,calc((100% - 1180px)/2));
  gap:26px;
  background:rgba(255,255,255,.94);
  backdrop-filter:blur(16px);
  border-bottom:1px solid rgba(229,231,235,.9);
  transition:box-shadow .2s;
}
.lp-nav.scrolled{box-shadow:0 2px 16px rgba(0,0,0,.07)}
.lp-logo{display:flex;align-items:center;gap:9px;text-decoration:none;flex-shrink:0}
.lp-mark{width:34px;height:34px;border-radius:9px;background:var(--primary);display:flex;align-items:center;justify-content:center;box-shadow:0 2px 10px rgba(22,163,74,.35);flex-shrink:0}
.lp-mark svg{width:16px;height:16px}
.lp-brand{font-weight:800;font-size:17px;color:var(--gray-900);letter-spacing:-.4px}
.lp-navlinks{display:flex;gap:2px;margin-left:8px}
.lp-nl{padding:6px 13px;border-radius:8px;font-size:13.5px;font-weight:500;color:var(--gray-600);text-decoration:none;transition:all .14s}
.lp-nl:hover{color:var(--gray-900);background:var(--gray-100)}
.lp-navr{margin-left:auto;display:flex;align-items:center;gap:10px;flex-shrink:0}
.lp-ng{padding:7px 16px;border-radius:9px;font-size:13.5px;font-weight:600;color:var(--gray-700);text-decoration:none;border:1.5px solid var(--gray-200);background:white;transition:all .14s}
.lp-ng:hover{background:var(--gray-50);border-color:var(--gray-300)}
.lp-np{padding:7px 18px;border-radius:9px;font-size:13.5px;font-weight:700;color:white;text-decoration:none;background:var(--primary);box-shadow:0 2px 10px rgba(22,163,74,.3);transition:all .16s;display:inline-flex;align-items:center;gap:6px}
.lp-np:hover{background:var(--primary-hover);transform:translateY(-1px);box-shadow:0 4px 16px rgba(22,163,74,.4)}
.lp-ndash.lp-ndash{font-size: 13.5px;font-weight: 700;color: var(--primary);text-decoration: none;display: inline-flex;align-items: center;gap: 8px;transition: all .16s}
.lp-ndash:hover{transform:translateY(-1px)}
.lp-nav-av{width:26px;height:26px;border-radius:6px;background:rgba(22,163,74,.2);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:var(--primary);flex-shrink:0}
/* Hamburger */
.lp-ham{display:none;width:38px;height:38px;border-radius:9px;background:var(--gray-100);border:1.5px solid var(--gray-200);align-items:center;justify-content:center;cursor:pointer;flex-direction:column;gap:5px;margin-left:auto;flex-shrink:0}
.lp-hl{width:17px;height:2px;background:var(--gray-500);border-radius:2px;transition:all .25s;transform-origin:center}
.lp-ham.open .lp-hl:nth-child(1){transform:translateY(7px) rotate(45deg);background:var(--gray-700)}
.lp-ham.open .lp-hl:nth-child(2){opacity:0;transform:scaleX(0)}
.lp-ham.open .lp-hl:nth-child(3){transform:translateY(-7px) rotate(-45deg);background:var(--gray-700)}
/* Drawer */
.lp-drawer{display:none;position:fixed;top:62px;left:0;right:0;z-index:190;background:white;border-bottom:1.5px solid var(--gray-200);padding:12px 20px 22px;transform:translateY(-8px);opacity:0;pointer-events:none;transition:transform .25s cubic-bezier(.4,0,.2,1),opacity .2s;box-shadow:0 12px 32px rgba(0,0,0,.08)}
.lp-drawer.open{transform:translateY(0);opacity:1;pointer-events:all}
.lp-dl{display:flex;align-items:center;gap:10px;padding:11px 12px;border-radius:9px;font-size:14px;font-weight:600;color:var(--gray-600);text-decoration:none;transition:all .14s}
.lp-dl:hover{background:var(--gray-100);color:var(--gray-900)}
.lp-dl svg{width:16px;height:16px;flex-shrink:0;color:var(--gray-400)}
.lp-ddiv{height:1px;background:var(--gray-200);margin:8px 0}
.lp-dact{display:flex;flex-direction:column;gap:8px;margin-top:4px}
.lp-da{display:flex;align-items:center;justify-content:center;gap:8px;padding:13px;border-radius:10px;font-size:14px;font-weight:700;text-decoration:none;transition:all .15s}
.lp-da-ghost{background:var(--gray-50);color:var(--gray-700);border:1.5px solid var(--gray-200)}
.lp-da-ghost:hover{background:var(--gray-100)}
.lp-da-pri{background:var(--primary);color:white;box-shadow:0 2px 10px rgba(22,163,74,.3)}
.lp-da-pri:hover{background:var(--primary-hover)}
.lp-duser{display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--primary-light);border:1px solid rgba(22,163,74,.2);border-radius:10px;margin-bottom:10px}
.lp-duav{width:34px;height:34px;border-radius:8px;background:var(--primary);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:white;flex-shrink:0}

/* ── HERO ─────────────────────────────────────── */
.lp-hero{
  min-height:100vh;display:flex;align-items:center;
  padding:90px max(24px,calc((100% - 1180px)/2)) 60px;
  position:relative;overflow:hidden;
  background:#fff;
}
/* Subtle grid */
.lp-grid-bg{
  position:absolute;inset:0;z-index:0;pointer-events:none;
  background-image:linear-gradient(var(--gray-100) 1px,transparent 1px),linear-gradient(90deg,var(--gray-100) 1px,transparent 1px);
  background-size:48px 48px;
  mask-image:radial-gradient(ellipse 85% 85% at 50% 50%,black 20%,transparent 100%);
  opacity:.55;
}
/* Top radial */
.lp-hero-glow{position:absolute;top:-5%;left:50%;transform:translateX(-50%);width:900px;height:600px;background:radial-gradient(ellipse,rgba(22,163,74,.08) 0%,transparent 65%);z-index:0;pointer-events:none}
.lp-hero-glow2{position:absolute;top:25%;right:-5%;width:480px;height:480px;background:radial-gradient(ellipse,rgba(6,182,212,.07) 0%,transparent 60%);z-index:0;pointer-events:none}
.lp-hero-inner{display:grid;grid-template-columns:1fr 1fr;align-items:center;gap:64px;width:100%;position:relative;z-index:1}
/* Left */
.lp-chip{display:inline-flex;align-items:center;gap:7px;background:var(--primary-light);border:1px solid rgba(22,163,74,.22);color:var(--primary);font-size:11.5px;font-weight:700;padding:5px 12px;border-radius:99px;margin-bottom:22px;letter-spacing:.5px;text-transform:uppercase}
.lp-chip-dot{width:5px;height:5px;border-radius:50%;background:var(--primary);animation:lpPulse 2s infinite}
@keyframes lpPulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(1.5)}}
.lp-h1{font-size:clamp(42px,5.8vw,70px);font-weight:900;line-height:1.03;letter-spacing:-2.5px;color:var(--gray-900);margin-bottom:22px}
.lp-accent-g{background:linear-gradient(90deg,var(--primary),#059669);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.lp-accent-c{background:linear-gradient(90deg,#0891b2,var(--accent));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.lp-sub{font-size:17px;color:var(--gray-500);line-height:1.72;margin-bottom:34px;max-width:490px}
.lp-ctas{display:flex;gap:12px;flex-wrap:wrap}
.lp-cbp{display:inline-flex;align-items:center;gap:8px;padding:14px 26px;border-radius:12px;font-size:15px;font-weight:800;background:var(--primary);color:white;text-decoration:none;box-shadow:0 4px 18px rgba(22,163,74,.35);transition:all .2s;position:relative;overflow:hidden}
.lp-cbp::before{content:'';position:absolute;inset:0;background:linear-gradient(to right,rgba(255,255,255,.12),transparent);opacity:0;transition:opacity .2s}
.lp-cbp:hover{background:var(--primary-hover);transform:translateY(-2px);box-shadow:0 8px 28px rgba(22,163,74,.45)}
.lp-cbp:hover::before{opacity:1}
.lp-cbs{display:inline-flex;align-items:center;gap:8px;padding:14px 22px;border-radius:12px;font-size:15px;font-weight:600;color:var(--gray-700);text-decoration:none;border:1.5px solid var(--gray-200);background:white;transition:all .2s}
.lp-cbs:hover{border-color:var(--gray-300);background:var(--gray-50);transform:translateY(-1px)}
/* Hero stats */
.lp-hstats{display:flex;gap:26px;margin-top:40px;flex-wrap:wrap}
.lp-hsn{font-size:27px;font-weight:900;color:var(--gray-900);letter-spacing:-1px;line-height:1}
.lp-hsl{font-size:11.5px;color:var(--gray-400);margin-top:3px;font-weight:500}
.lp-hsd{width:1px;background:var(--gray-200);flex-shrink:0}

/* ── GLOBE SCENE ─────────────────────────────── */
.lp-globe-scene{position:relative;width:500px;height:500px;display:flex;align-items:center;justify-content:center}
.lp-globe-halo{position:absolute;width:340px;height:340px;border-radius:50%;background:radial-gradient(circle,rgba(22,163,74,.12) 0%,rgba(6,182,212,.06) 40%,transparent 70%);animation:lpHalo 4s ease-in-out infinite}
@keyframes lpHalo{0%,100%{transform:scale(1)}50%{transform:scale(1.07)}}
.lp-globe-ring{position:absolute;width:390px;height:390px;border-radius:50%;border:1px solid rgba(22,163,74,.12);animation:lpRing1 22s linear infinite}
.lp-globe-ring::after{content:'';position:absolute;width:10px;height:10px;border-radius:50%;background:var(--primary);top:-5px;left:50%;transform:translateX(-50%);box-shadow:0 0 12px rgba(22,163,74,.6)}
@keyframes lpRing1{to{transform:rotate(360deg)}}
.lp-globe-ring2{position:absolute;width:420px;height:420px;border-radius:50%;border:1px dashed rgba(6,182,212,.15);animation:lpRing1 32s linear infinite reverse}
.lp-globe-ring2::after{content:'';position:absolute;width:8px;height:8px;border-radius:50%;background:var(--accent);bottom:-4px;left:50%;transform:translateX(-50%);box-shadow:0 0 10px rgba(6,182,212,.5)}
/* Floating cards */
.lp-fc{position:absolute;z-index:10;background:white;border:1px solid var(--gray-200);border-radius:12px;padding:10px 14px;box-shadow:0 8px 24px rgba(0,0,0,.09),0 0 0 1px rgba(22,163,74,.06);font-size:12px;white-space:nowrap}
.lp-fcd{width:7px;height:7px;border-radius:50%;display:inline-block;margin-right:5px}
.lp-fc1{top:6%;right:0;animation:lpFlt 4s ease-in-out infinite}
.lp-fc2{bottom:16%;left:-4%;animation:lpFlt 5.5s ease-in-out infinite .8s}
.lp-fc3{top:42%;right:-8%;animation:lpFlt 3.8s ease-in-out infinite 1.4s}
@keyframes lpFlt{0%,100%{transform:translateY(0)}50%{transform:translateY(-9px)}}

/* ── MARQUEE ─────────────────────────────────── */
.lp-mq{border-top:1px solid var(--gray-200);border-bottom:1px solid var(--gray-200);overflow:hidden;background:var(--gray-50);padding:18px 0}
.lp-mqi{display:flex;width:max-content;animation:lpMq 34s linear infinite}
.lp-mqi:hover{animation-play-state:paused}
@keyframes lpMq{to{transform:translateX(-50%)}}
.lp-mitem{display:flex;align-items:center;gap:8px;padding:0 30px;font-size:13px;font-weight:600;color:var(--gray-500);border-right:1px solid var(--gray-200);white-space:nowrap}
.lp-md{width:6px;height:6px;border-radius:50%;flex-shrink:0}

/* ── STATS STRIP ─────────────────────────────── */
.lp-ss{display:grid;grid-template-columns:repeat(4,1fr);border-bottom:1px solid var(--gray-200);background:white}
.lp-ssi{padding:38px 28px;text-align:center;border-right:1px solid var(--gray-200);position:relative}
.lp-ssi:last-child{border-right:none}
.lp-ssi::before{content:'';position:absolute;top:0;left:20%;right:20%;height:2px;background:linear-gradient(90deg,transparent,var(--primary),transparent);opacity:.4}
.lp-ssn{font-size:38px;font-weight:900;letter-spacing:-2px;line-height:1;color:var(--gray-900)}
.lp-ssl{font-size:12.5px;color:var(--gray-400);margin-top:7px;font-weight:500}

/* ── SECTION ─────────────────────────────────── */
.lp-sec{padding:96px max(24px,calc((100% - 1180px)/2))}
.lp-stag{display:inline-flex;align-items:center;gap:7px;font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--primary);margin-bottom:12px}
.lp-stag::before{content:'';width:16px;height:1.5px;background:var(--primary);opacity:.6}
.lp-sh{font-size:clamp(30px,4.2vw,48px);font-weight:900;letter-spacing:-1.8px;color:var(--gray-900);margin-bottom:14px;line-height:1.06}
.lp-ss-sub{font-size:16px;color:var(--gray-500);line-height:1.65;max-width:520px}

/* ── FEATURES ─────────────────────────────────── */
.lp-feats{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;margin-top:56px;background:var(--gray-200);border-radius:18px;overflow:hidden;border:1px solid var(--gray-200)}
.lp-fc-card{background:white;padding:30px 26px;position:relative;overflow:hidden;transition:background .18s}
.lp-fc-card:hover{background:var(--primary-light)}
.lp-fc-card::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--primary),transparent);transform:scaleX(0);transition:transform .3s}
.lp-fc-card:hover::after{transform:scaleX(1)}
.lp-fi{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;margin-bottom:18px;background:var(--primary-light);border:1px solid rgba(22,163,74,.18);transition:box-shadow .2s}
.lp-fc-card:hover .lp-fi{box-shadow:0 0 16px rgba(22,163,74,.25)}
.lp-ft{font-size:15px;font-weight:700;color:var(--gray-900);margin-bottom:8px}
.lp-fd{font-size:13.5px;color:var(--gray-500);line-height:1.62}

/* ── TERMINAL ─────────────────────────────────── */
.lp-term-sec{padding:0 max(24px,calc((100% - 1180px)/2)) 80px;display:grid;grid-template-columns:1fr 1fr;gap:64px;align-items:center}
.lp-term{background:#0d1117;border:1px solid #30363d;border-radius:14px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.18),0 0 0 1px rgba(255,255,255,.04)}
.lp-tb{height:40px;background:#161b22;display:flex;align-items:center;padding:0 14px;gap:7px;border-bottom:1px solid #30363d}
.lp-tdot{width:11px;height:11px;border-radius:50%}
.lp-ttitle{font-size:11.5px;color:#8b949e;margin-left:auto;font-family:'JetBrains Mono',monospace}
.lp-tbody{padding:20px 22px;font-family:'JetBrains Mono',monospace;font-size:12.5px;line-height:1.95}
.lp-tl{display:flex;gap:8px}
.tp{color:#3fb950;flex-shrink:0}.tc{color:#e6edf3}.tcom{color:#586069}.to{color:#79c0ff}.ts{color:#3fb950}
.lp-tcur{display:inline-block;width:7px;height:13px;background:#3fb950;border-radius:1px;animation:lpBlink 1.1s step-end infinite;vertical-align:middle;margin-left:2px}
@keyframes lpBlink{0%,100%{opacity:1}50%{opacity:0}}
.lp-tpts{list-style:none;margin-top:34px;display:flex;flex-direction:column;gap:16px}
.lp-tpts li{display:flex;align-items:flex-start;gap:12px;font-size:14.5px;color:var(--gray-500);line-height:1.55}
.lp-tpi{width:30px;height:30px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:14px}

/* ── PRICING ─────────────────────────────────── */
.lp-psec{padding:80px max(24px,calc((100% - 1180px)/2));background:var(--gray-50);border-top:1px solid var(--gray-200);border-bottom:1px solid var(--gray-200)}
.lp-pgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-top:52px}
.lp-pcard{background:white;border:1.5px solid var(--gray-200);border-radius:18px;padding:30px 26px;position:relative;transition:all .2s}
.lp-pcard:hover{border-color:rgba(22,163,74,.3);transform:translateY(-3px);box-shadow:0 12px 40px rgba(22,163,74,.08)}
.lp-phot{border-color:rgba(22,163,74,.4);box-shadow:0 0 0 4px rgba(22,163,74,.07)}
.lp-phot:hover{box-shadow:0 12px 40px rgba(22,163,74,.15)}
.lp-pbadge{position:absolute;top:-13px;left:50%;transform:translateX(-50%);background:var(--primary);color:white;font-size:10.5px;font-weight:700;padding:3px 14px;border-radius:99px;letter-spacing:.5px}
.lp-pplan{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--gray-400);margin-bottom:10px}
.lp-pamt{font-size:44px;font-weight:900;color:var(--gray-900);letter-spacing:-2.5px;line-height:1}
.lp-pamt span{font-size:15px;font-weight:400;color:var(--gray-400);letter-spacing:0}
.lp-pdesc{font-size:13px;color:var(--gray-500);margin:10px 0 22px;line-height:1.5}
.lp-pspecs{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-bottom:22px}
.lp-pchip{background:var(--gray-50);border:1px solid var(--gray-200);border-radius:7px;padding:7px 10px;font-family:'JetBrains Mono',monospace;font-size:11.5px;font-weight:600;color:var(--gray-700);text-align:center}
.lp-pfts{list-style:none;margin-bottom:26px;display:flex;flex-direction:column;gap:8px}
.lp-pfts li{font-size:13.5px;color:var(--gray-600);display:flex;align-items:center;gap:8px}
.lp-pfts li::before{content:'✓';color:var(--primary);font-weight:800;font-size:12px;flex-shrink:0}
.lp-pbtn{display:block;width:100%;padding:12px;border-radius:10px;font-size:14px;font-weight:700;text-align:center;text-decoration:none;transition:all .18s}
.lp-pbpri{background:var(--primary);color:white;box-shadow:0 2px 12px rgba(22,163,74,.3)}
.lp-pbpri:hover{background:var(--primary-hover);box-shadow:0 4px 20px rgba(22,163,74,.45);transform:translateY(-1px)}
.lp-pbghost{background:white;color:var(--gray-700);border:1.5px solid var(--gray-200)}
.lp-pbghost:hover{border-color:var(--primary);color:var(--primary);background:var(--primary-light)}

/* ── TESTIMONIALS ─────────────────────────────── */
.lp-tsec{padding:96px max(24px,calc((100% - 1180px)/2))}
.lp-tgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-top:56px;perspective:1200px}
.lp-tcard{
  background:white;border:1px solid var(--gray-200);border-radius:18px;
  padding:26px 24px;position:relative;overflow:hidden;
  transition:transform .35s cubic-bezier(.25,.46,.45,.94),box-shadow .35s,border-color .3s;
  transform-style:preserve-3d;
}
.lp-tcard::before{content:'';position:absolute;top:0;left:0;right:0;height:2.5px;background:linear-gradient(90deg,var(--primary),var(--accent));opacity:0;transition:opacity .25s}
.lp-tcard::after{content:'';position:absolute;inset:0;background:radial-gradient(circle at 50% 0%,rgba(22,163,74,.05),transparent 55%);opacity:0;transition:opacity .3s;pointer-events:none}
.lp-tcard:hover{transform:translateY(-6px) rotateX(2.5deg);box-shadow:0 20px 50px rgba(0,0,0,.1),0 0 0 1px rgba(22,163,74,.1);border-color:rgba(22,163,74,.2)}
.lp-tcard:hover::before{opacity:1}
.lp-tcard:hover::after{opacity:1}
/* Offset every 2nd card */
.lp-tcard:nth-child(2){transform:translateY(14px)}
.lp-tcard:nth-child(2):hover{transform:translateY(8px) rotateX(2.5deg)}
.lp-tcard:nth-child(5){transform:translateY(14px)}
.lp-tcard:nth-child(5):hover{transform:translateY(8px) rotateX(2.5deg)}
.lp-tstars{display:flex;gap:3px;margin-bottom:14px}
.lp-tstar{font-size:14px;color:#f59e0b}
.lp-tqmark{font-size:42px;line-height:0;color:rgba(22,163,74,.15);font-weight:900;vertical-align:middle;margin-right:2px}
.lp-ttext{font-size:14px;color:var(--gray-600);line-height:1.72;margin-bottom:22px}
.lp-tauthor{display:flex;align-items:center;gap:11px;padding-top:16px;border-top:1px solid var(--gray-100)}
.lp-tav{width:42px;height:42px;border-radius:12px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:900;color:white}
.lp-tname{font-size:14px;font-weight:800;color:var(--gray-900)}
.lp-trole{font-size:11.5px;color:var(--gray-400);margin-top:2px}
.lp-tbadge{position:absolute;top:16px;right:16px;background:var(--primary-light);border:1px solid rgba(22,163,74,.2);color:var(--primary);font-size:10px;font-weight:700;padding:3px 9px;border-radius:99px}

/* ── HOW IT WORKS ─────────────────────────────── */
.lp-howsec{padding:80px max(24px,calc((100% - 1180px)/2));background:var(--gray-50);border-top:1px solid var(--gray-200);border-bottom:1px solid var(--gray-200)}
.lp-steps{display:grid;grid-template-columns:repeat(4,1fr);gap:0;position:relative;margin-top:52px}
.lp-steps::before{content:'';position:absolute;top:22px;left:12.5%;right:12.5%;height:1.5px;background:linear-gradient(90deg,transparent,var(--gray-200),var(--gray-200),var(--gray-200),transparent);z-index:0}
.lp-step{text-align:center;padding:0 16px;position:relative;z-index:1}
.lp-stepn{width:44px;height:44px;border-radius:12px;background:var(--primary);color:white;font-size:18px;font-weight:900;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;box-shadow:0 4px 16px rgba(22,163,74,.35)}
.lp-stept{font-size:15px;font-weight:700;color:var(--gray-900);margin-bottom:7px}
.lp-stepd{font-size:13px;color:var(--gray-500);line-height:1.6}

/* ── VIDEO ───────────────────────────────────── */
.lp-vid-sec{padding:0 max(24px,calc((100% - 1180px)/2)) 80px;text-align:center}
.lp-vframe{position:relative;border-radius:16px;overflow:hidden;max-width:820px;margin:0 auto;aspect-ratio:16/9;border:1px solid var(--gray-200);box-shadow:0 20px 60px rgba(0,0,0,.1)}
.lp-vframe iframe{width:100%;height:100%;border:none;display:block}

/* ── CTA ─────────────────────────────────────── */
.lp-cta-sec{padding:0 max(24px,calc((100% - 1180px)/2)) 80px}
.lp-cta-box{
  background:linear-gradient(135deg,var(--primary) 0%,#059669 50%,#0891b2 100%);
  border-radius:24px;padding:80px 60px;text-align:center;position:relative;overflow:hidden;
}
.lp-cta-grid{position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.08) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.08) 1px,transparent 1px);background-size:36px 36px;mask-image:radial-gradient(ellipse 100% 100% at 50% 50%,black 20%,transparent 100%);pointer-events:none}
.lp-ctah{font-size:clamp(28px,4vw,48px);font-weight:900;color:white;letter-spacing:-2px;margin-bottom:14px;position:relative;z-index:1}
.lp-ctas2{font-size:16px;color:rgba(255,255,255,.8);margin-bottom:36px;position:relative;z-index:1}
.lp-ctabtn{display:inline-flex;align-items:center;gap:9px;padding:15px 32px;border-radius:13px;font-size:15.5px;font-weight:800;background:white;color:var(--primary);text-decoration:none;box-shadow:0 4px 22px rgba(0,0,0,.18);transition:all .2s;position:relative;z-index:1}
.lp-ctabtn:hover{transform:translateY(-2px);box-shadow:0 10px 34px rgba(0,0,0,.25)}

/* ── FOOTER ──────────────────────────────────── */
.lp-foot{border-top:1px solid var(--gray-200);padding:28px max(24px,calc((100% - 1180px)/2));display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;background:white}
.lp-flogo{display:flex;align-items:center;gap:8px;font-size:15px;font-weight:800;color:var(--gray-900)}
.lp-fcopy{font-size:12.5px;color:var(--gray-400)}
.lp-flinks{display:flex;gap:20px}
.lp-flinks a{font-size:12.5px;color:var(--gray-500);text-decoration:none;transition:color .14s}
.lp-flinks a:hover{color:var(--gray-900)}

/* ── RESPONSIVE ──────────────────────────────── */
@media(max-width:1050px){
  .lp-hero-inner{grid-template-columns:1fr;text-align:center}
  .lp-sub,.lp-hstats,.lp-ctas{justify-content:center;margin-left:auto;margin-right:auto}
  .lp-chip{display:inline-flex}
  .lp-globe-scene{display:none}
  .lp-term-sec{grid-template-columns:1fr}
  .lp-feats{grid-template-columns:1fr 1fr}
  .lp-tgrid{grid-template-columns:1fr 1fr}
  .lp-pgrid{grid-template-columns:1fr}
  .lp-ss{grid-template-columns:1fr 1fr}
  .lp-ssi:nth-child(2){border-right:none}
  .lp-ssi:nth-child(1),.lp-ssi:nth-child(2){border-bottom:1px solid var(--gray-200)}
}
@media(max-width:768px){
  .lp-navlinks,.lp-navr{display:none}
  .lp-ham{display:flex}
  .lp-drawer{display:block}
  .lp-feats{grid-template-columns:1fr}
  .lp-tgrid{grid-template-columns:1fr}
  .lp-tcard:nth-child(2),.lp-tcard:nth-child(5){transform:none}
  .lp-steps{grid-template-columns:1fr 1fr;gap:28px}
  .lp-steps::before{display:none}
  .lp-cta-box{padding:40px 24px}
}
@media(max-width:480px){
  .lp-steps{grid-template-columns:1fr}
  .lp-ss{grid-template-columns:1fr 1fr}
}
</style>
</head>
<body>

<!-- ══ NAV ══════════════════════════════════════ -->
<nav class="lp-nav" id="lpnav">
  <a href="<?= BASE_URL ?>/" class="lp-logo">
    <?php if (!empty(get_setting('site_logo', ''))) : ?>
    <img src="<?= htmlspecialchars(get_setting('site_logo', '')) ?>" alt="Logo" style="width: 200px;">
<?php else: ?>
    <div class="lp-mark">
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5">
            <path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/>
        </svg>
    </div>
    <span class="lp-brand"><?= htmlspecialchars(APP_NAME) ?></span>
<?php endif; ?>
  </a>
  <div class="lp-navlinks">
    <a href="#features" class="lp-nl">Features</a>
    <a href="#pricing"  class="lp-nl">Pricing</a>
    <a href="#how"      class="lp-nl">How It Works</a>
    <a href="#reviews"  class="lp-nl">Reviews</a>
  </div>
  <div class="lp-navr">
    <?php if ($logged_in): ?>
    <a href="<?= BASE_URL ?>/dashboard.php" class="lp-ndash">
      <div class="lp-nav-av"><img style="border-radius:5px;" src="<?= getGravatar($current['email'], $current['user_profile']) ?>"></div>
      Goto Dashboard
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
    </a>
    <?php else: ?>
    <a href="<?= BASE_URL ?>/login.php"    class="lp-ng">Sign In</a>
    <a href="<?= BASE_URL ?>/register.php" class="lp-np">
      Get Started
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
    </a>
    <?php endif; ?>
  </div>
  <button class="lp-ham" id="lp-ham" onclick="lpToggle()">
    <div class="lp-hl"></div><div class="lp-hl"></div><div class="lp-hl"></div>
  </button>
</nav>

<!-- ══ DRAWER ════════════════════════════════════ -->
<div class="lp-drawer" id="lp-drawer">
  <?php if ($logged_in): ?>
  <div class="lp-duser">
    <div class="lp-duav"><img style="border-radius:5px;" src="<?= getGravatar($current['email'], $current['user_profile']) ?>"></div>
    <div><div style="font-size:13.5px;font-weight:700;color:var(--gray-900)">@<?= $uname ?></div><div style="font-size:11px;color:var(--gray-500)">Logged in</div></div>
  </div>
  <?php endif; ?>
  <a href="#features" class="lp-dl" onclick="lpClose()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg> Features</a>
  <a href="#pricing"  class="lp-dl" onclick="lpClose()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg> Pricing</a>
  <a href="#how"      class="lp-dl" onclick="lpClose()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> How It Works</a>
  <a href="#reviews"  class="lp-dl" onclick="lpClose()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg> Reviews</a>
  <div class="lp-ddiv"></div>
  <div class="lp-dact">
    <?php if ($logged_in): ?>
    <a href="<?= BASE_URL ?>/dashboard.php" class="lp-da lp-da-pri">Go to Dashboard →</a>
    <?php else: ?>
    <a href="<?= BASE_URL ?>/login.php"    class="lp-da lp-da-ghost">Sign In</a>
    <a href="<?= BASE_URL ?>/register.php" class="lp-da lp-da-pri">Get Started Free →</a>
    <?php endif; ?>
  </div>
</div>

<!-- ══ HERO ══════════════════════════════════════ -->
<section class="lp-hero">
  <div class="lp-grid-bg"></div>
  <div class="lp-hero-glow"></div>
  <div class="lp-hero-glow2"></div>
  <div class="lp-hero-inner">
    <!-- Left -->
    <div>
      <div class="lp-chip"><div class="lp-chip-dot"></div><?= APP_NAME ?> Cloud Infrastructure</div>
      <h1 class="lp-h1">
        Deploy Your VPS<br>
        <span class="lp-accent-g">In Seconds.</span><br>
        <span class="lp-accent-c">Scale Instantly.</span>
      </h1>
      <p class="lp-sub">Enterprise-grade virtual servers with dedicated vCPUs, NVMe SSDs, and full root access — managed through one powerful dashboard. Pay in INR, zero surprises.</p>
      <div class="lp-ctas">
        <a href="<?= BASE_URL ?>/register.php" class="lp-cbp">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
          Start Deploying Free
        </a>
        <a href="#how" class="lp-cbs">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>
          Watch Demo
        </a>
      </div>
      <div class="lp-hstats">
        <div><div class="lp-hsn">99.9%</div><div class="lp-hsl">Uptime SLA</div></div>
        <div class="lp-hsd"></div>
        <div><div class="lp-hsn">&lt;60s</div><div class="lp-hsl">Boot Time</div></div>
        <div class="lp-hsd"></div>
        <div><div class="lp-hsn">₹0</div><div class="lp-hsl">Setup Fee</div></div>
        <div class="lp-hsd"></div>
        <div><div class="lp-hsn">24/7</div><div class="lp-hsl">Support</div></div>
      </div>
    </div>

    <!-- Globe -->
    <div class="lp-globe-scene">
      <div class="lp-globe-halo"></div>
      <div class="lp-globe-ring"></div>
      <div class="lp-globe-ring2"></div>
      <canvas id="lp-globe" width="330" height="330" style="position:relative;z-index:2;border-radius:50%"></canvas>
      <!-- Floating cards -->
      <div class="lp-fc lp-fc1">
        <div style="display:flex;align-items:center;gap:5px">
          <div class="lp-fcd" style="background:#16a34a"></div>
          <span style="color:#16a34a;font-weight:700;font-size:11px">LIVE · 99.97%</span>
        </div>
        <div style="font-size:11px;color:var(--gray-500);margin-top:2px">Mumbai DC · 8ms avg</div>
      </div>
      <div class="lp-fc lp-fc2">
        <div style="font-size:11px;color:var(--gray-500)">🖥️ · Ubuntu 24.04</div>
        <div style="display:flex;align-items:center;gap:5px;margin-top:3px">
          <div class="lp-fcd" style="background:#0891b2"></div>
          <span style="font-size:11px;color:#0891b2;font-weight:600">Booted in 38s</span>
        </div>
      </div>
      <div class="lp-fc lp-fc3">
        <div style="font-size:11px;color:var(--gray-500)">Monthly cost</div>
        <div style="font-size:18px;font-weight:900;color:var(--gray-900);font-family:'JetBrains Mono',monospace">₹299</div>
        <div style="font-size:10.5px;color:#16a34a;margin-top:1px;font-weight:600">▼ No hidden fees</div>
      </div>
    </div>
  </div>
</section>

<!-- ══ MARQUEE ════════════════════════════════════ -->
<div class="lp-mq">
  <div class="lp-mqi">
    <?php
    $mitems=[['🇮🇳','Mumbai Datacenter','#16a34a'],['⚡','NVMe SSD RAID','#0891b2'],['🌐','IPv4 & IPv6','#8b5cf6'],['🔒','Cloud Firewall','#f59e0b'],['💳','INR Billing & UPI','#16a34a'],['🔑','SSH Key Auth','#0891b2'],['📊','Real-time Metrics','#8b5cf6'],['🚀','60s Deploy','#16a34a'],['🛡️','DDoS Protection','#f59e0b'],['🖥️','Full Root Access','#0891b2'],['📸','Instant Snapshots','#8b5cf6'],['🔌','REST API','#16a34a'],['🇮🇳','Mumbai Datacenter','#16a34a'],['⚡','NVMe SSD RAID','#0891b2'],['🌐','IPv4 & IPv6','#8b5cf6'],['🔒','Cloud Firewall','#f59e0b'],['💳','INR Billing & UPI','#16a34a'],['🔑','SSH Key Auth','#0891b2'],['📊','Real-time Metrics','#8b5cf6'],['🚀','60s Deploy','#16a34a'],['🛡️','DDoS Protection','#f59e0b'],['🖥️','Full Root Access','#0891b2']];
    foreach($mitems as[$e,$l,$c]):
    ?><div class="lp-mitem"><span class="lp-md" style="background:<?=$c?>"></span><?=$e?> <?=$l?></div><?php endforeach;?>
  </div>
</div>

<!-- ══ STATS ═════════════════════════════════════ -->
<div class="lp-ss">
  <div class="lp-ssi"><div class="lp-ssn" style="color:var(--primary)">5,000+</div><div class="lp-ssl">Active Servers</div></div>
  <div class="lp-ssi"><div class="lp-ssn" style="color:#0891b2">99.97%</div><div class="lp-ssl">Uptime Last 90 Days</div></div>
  <div class="lp-ssi"><div class="lp-ssn" style="color:var(--primary)">38s</div><div class="lp-ssl">Average Boot Time</div></div>
  <div class="lp-ssi"><div class="lp-ssn" style="color:#8b5cf6">2,400+</div><div class="lp-ssl">Happy Customers</div></div>
</div>

<!-- ══ FEATURES ══════════════════════════════════ -->
<section class="lp-sec" id="features">
  <div class="lp-stag">Features</div>
  <h2 class="lp-sh">Everything you need,<br>nothing you don't</h2>
  <p class="lp-ss-sub">Powerful tools built for developers, designed for simplicity. No bloat, just raw performance.</p>
  <div class="lp-feats">
    <?php
    $fts=[['🚀','Instant Deploy','Launch a VPS in under 60 seconds. Choose OS, size, and region — we handle the rest.'],['📊','Live Monitoring','Track CPU, RAM, disk, and bandwidth in real-time. Full visibility into your infrastructure.'],['🔑','SSH Key Auth','Add and manage SSH keys from the dashboard. Secure passwordless access to all servers.'],['🔥','Cloud Firewall','Protect servers with powerful firewall rules. Allow or block any traffic in one click.'],['💳','Wallet Billing','Prepaid INR wallet — no surprises. Top up via UPI, cards, or net banking anytime.'],['🔌','Full REST API','Automate everything via API. Integrate VPS deployments into your CI/CD pipeline seamlessly.']];
    foreach($fts as[$i,$t,$d]):?><div class="lp-fc-card"><div class="lp-fi"><?=$i?></div><div class="lp-ft"><?=$t?></div><div class="lp-fd"><?=$d?></div></div><?php endforeach;?>
  </div>
</section>

<!-- ══ TERMINAL ═══════════════════════════════════ -->
<div class="lp-term-sec">
  <div>
    <div class="lp-stag">Developer First</div>
    <h2 class="lp-sh">Built for<br>power users</h2>
    <p class="lp-ss-sub" style="margin-bottom:0">Full control from terminal to dashboard. No black boxes, no lock-in.</p>
    <ul class="lp-tpts">
      <li><div class="lp-tpi" style="background:var(--primary-light);border:1px solid rgba(22,163,74,.2)">🐧</div><div><strong style="color:var(--gray-800)">Ubuntu, Debian, CentOS, AlmaLinux</strong> — latest stable releases always available</div></li>
      <li><div class="lp-tpi" style="background:#f0fdfa;border:1px solid rgba(6,182,212,.2)">⚡</div><div><strong style="color:var(--gray-800)">NVMe SSD storage</strong> — 10x faster than traditional SATA drives</div></li>
      <li><div class="lp-tpi" style="background:#faf5ff;border:1px solid rgba(139,92,246,.2)">🌐</div><div><strong style="color:var(--gray-800)">IPv4 + IPv6 included</strong> — dedicated IPs on every server</div></li>
      <li><div class="lp-tpi" style="background:#fffbeb;border:1px solid rgba(245,158,11,.2)">📸</div><div><strong style="color:var(--gray-800)">Snapshots & backups</strong> — one-click restore anytime</div></li>
    </ul>
  </div>
  <div>
    <div class="lp-term">
      <div class="lp-tb">
        <div class="lp-tdot" style="background:#ff5f57"></div>
        <div class="lp-tdot" style="background:#febc2e"></div>
        <div class="lp-tdot" style="background:#28c840"></div>
        <div class="lp-ttitle"><?= APP_NAME ?> — deploy</div>
      </div>
      <div class="lp-tbody">
        <div class="lp-tl"><span class="tp">$</span><span class="tc">&nbsp;vps create --plan cx22 --os ubuntu-24.04</span></div>
        <div class="lp-tl"><span class="tcom">&nbsp;&nbsp;# Contacting <?= APP_NAME ?> API...</span></div>
        <div class="lp-tl"><span class="to">&nbsp;&nbsp;✦ Server created · ID: 58291034</span></div>
        <div class="lp-tl"><span class="to">&nbsp;&nbsp;✦ Region: IN-MUM · IPv4: 49.13.84.xx</span></div>
        <div class="lp-tl"><span class="to">&nbsp;&nbsp;✦ Installing Ubuntu 24.04 LTS...</span></div>
        <div class="lp-tl"><span class="ts">&nbsp;&nbsp;✓ Server RUNNING · Boot: 38s</span></div>
        <div class="lp-tl" style="margin-top:8px"><span class="tp">$</span><span class="tc">&nbsp;ssh root@49.13.84.xx</span></div>
        <div class="lp-tl"><span class="ts">&nbsp;&nbsp;Welcome to Ubuntu 24.04 — <?= APP_NAME ?></span></div>
        <div class="lp-tl"><span class="tp">root@my-app:~#</span><span class="lp-tcur"></span></div>
      </div>
    </div>
  </div>
</div>

<!-- ══ PRICING ════════════════════════════════════ -->
<div class="lp-psec" id="pricing">
  <div style="text-align:center">
    <div class="lp-stag" style="display:inline-flex">Pricing</div>
    <h2 class="lp-sh" style="max-width:560px;margin:0 auto 12px">Simple, transparent pricing</h2>
    <p class="lp-ss-sub" style="margin:0 auto">Pay only for what you use. All prices in INR. No lock-in.</p>
  </div>
  <div class="lp-pgrid">
    <div class="lp-pcard">
      <div class="lp-pplan">Starter</div>
      <div class="lp-pamt">₹299<span>/mo</span></div>
      <div class="lp-pdesc">Perfect for personal projects and experiments.</div>
      <div class="lp-pspecs"><div class="lp-pchip">2 vCPU</div><div class="lp-pchip">2 GB RAM</div><div class="lp-pchip">40 GB NVMe</div><div class="lp-pchip">20 TB BW</div></div>
      <ul class="lp-pfts"><li>Ubuntu / Debian / CentOS</li><li>IPv4 + IPv6</li><li>SSH Key Auth</li><li>Basic Firewall</li></ul>
      <a href="<?= BASE_URL ?>/register.php" class="lp-pbtn lp-pbghost">Get Started</a>
    </div>
    <div class="lp-pcard lp-phot">
      <div class="lp-pbadge">Most Popular</div>
      <div class="lp-pplan">Professional</div>
      <div class="lp-pamt">₹799<span>/mo</span></div>
      <div class="lp-pdesc">For production workloads and growing applications.</div>
      <div class="lp-pspecs"><div class="lp-pchip">4 vCPU</div><div class="lp-pchip">8 GB RAM</div><div class="lp-pchip">160 GB NVMe</div><div class="lp-pchip">20 TB BW</div></div>
      <ul class="lp-pfts"><li>Everything in Starter</li><li>Priority Support</li><li>Automated Backups</li><li>Private Network</li><li>Advanced Firewall</li></ul>
      <a href="<?= BASE_URL ?>/register.php" class="lp-pbtn lp-pbpri">Get Started →</a>
    </div>
    <div class="lp-pcard">
      <div class="lp-pplan">Enterprise</div>
      <div class="lp-pamt">₹1,999<span>/mo</span></div>
      <div class="lp-pdesc">Dedicated resources for serious, high-traffic workloads.</div>
      <div class="lp-pspecs"><div class="lp-pchip">8 vCPU</div><div class="lp-pchip">16 GB RAM</div><div class="lp-pchip">240 GB NVMe</div><div class="lp-pchip">30 TB BW</div></div>
      <ul class="lp-pfts"><li>Everything in Pro</li><li>Dedicated vCPU</li><li>Load Balancer</li><li>Custom DNS Zones</li><li>SLA Guarantee</li></ul>
      <a href="<?= BASE_URL ?>/register.php" class="lp-pbtn lp-pbghost">Contact Sales</a>
    </div>
  </div>
</div>

<!-- ══ HOW IT WORKS ══════════════════════════════ -->
<div class="lp-howsec" id="how">
  <div style="text-align:center">
    <div class="lp-stag" style="display:inline-flex">How It Works</div>
    <h2 class="lp-sh" style="max-width:480px;margin:0 auto 8px">SSH access in 4 simple steps</h2>
  </div>
  <div class="lp-steps">
    <div class="lp-step"><div class="lp-stepn">1</div><div class="lp-stept">Create Account</div><div class="lp-stepd">Sign up in under a minute. No credit card needed to get started.</div></div>
    <div class="lp-step"><div class="lp-stepn">2</div><div class="lp-stept">Add Credits</div><div class="lp-stepd">Top up your INR wallet via UPI, debit/credit cards, or net banking.</div></div>
    <div class="lp-step"><div class="lp-stepn">3</div><div class="lp-stept">Deploy Server</div><div class="lp-stepd">Pick plan, OS, and region. Your VPS boots in under 60 seconds.</div></div>
    <div class="lp-step"><div class="lp-stepn">4</div><div class="lp-stept">Take Control</div><div class="lp-stepd">SSH in with full root access. Install your stack and go live.</div></div>
  </div>
</div>

<!-- ══ VIDEO ══════════════════════════════════════ -->
<div class="how-video">

  <!-- LEFT SIDE NOTE -->
  <div class="video-note">
    <img src="https://i.ibb.co/1f00kh8b/hand-drawn-dotted-arrow-line-clip-art-free-png.png" class="arrow-img" alt="arrow">
    <span>Watch this video</span>
  </div>

  <!-- VIDEO -->
  <div class="video-box">
   <iframe src="https://www.youtube.com/embed/4CKpFcQdJXk?si=9IYBXKEt8JnOPQjr" allowfullscreen title="<?= APP_NAME ?> Demo"></iframe>
  </div>

</div>

<!-- ══ TESTIMONIALS ═══════════════════════════════ -->
<section class="lp-tsec" id="reviews">
  <div style="text-align:center">
    <div class="lp-stag" style="display:inline-flex">Customer Reviews</div>
    <h2 class="lp-sh" style="max-width:500px;margin:0 auto 12px">Trusted by developers<br>across India</h2>
    <p class="lp-ss-sub" style="margin:0 auto">Real feedback from real customers who deploy with us every day.</p>
  </div>
  <div class="lp-tgrid">
    <?php
    $reviews=[
      ['R','Rahul M.','Full-Stack Dev · Mumbai','linear-gradient(135deg,#16a34a,#059669)','Deploying is insanely fast. I had my production VPS running in under a minute. The INR billing with UPI support is a massive plus — no forex surprises ever.','Pro Plan'],
      ['P','Priya S.','DevOps Engineer · Bangalore','linear-gradient(135deg,#8b5cf6,#7c3aed)','The dashboard is clean and powerful. Managing 8 servers from one place with real-time metrics is exactly what I needed. The support team is genuinely fast.','Enterprise'],
      ['A','Arjun K.','Startup Founder · Hyderabad','linear-gradient(135deg,#0891b2,#0e7490)','Switched from a foreign provider and saved 40% while getting better latency for our Indian users. The Mumbai DC performance is unmatched. Highly recommend.','Pro Plan'],
      ['N','Nitesh T.','Backend Engineer · Delhi','linear-gradient(135deg,#16a34a,#059669)','The REST API is super clean and well documented. Automated our entire VPS lifecycle in CI/CD. Zero downtime deployments in 8 months straight.','Verified'],
      ['S','Sneha R.','Cloud Architect · Pune','linear-gradient(135deg,#f59e0b,#d97706)','Finally a provider that understands Indian developers. UPI payments, INR invoices, responsive support. The whole experience is smooth from day one.','Pro Plan'],
      ['V','Vikram B.','SaaS Founder · Chennai','linear-gradient(135deg,#ec4899,#db2777)','Migrated our entire infrastructure here. Firewall rules are intuitive, snapshots work flawlessly, and the pricing is transparent. Best infra decision we made.','Enterprise'],
    ];
    foreach($reviews as[$av,$name,$role,$grad,$text,$badge]):
    ?>
    <div class="lp-tcard">
      <div class="lp-tbadge"><?=$badge?></div>
      <div class="lp-tstars">
        <span class="lp-tstar">★</span><span class="lp-tstar">★</span><span class="lp-tstar">★</span><span class="lp-tstar">★</span><span class="lp-tstar">★</span>
      </div>
      <p class="lp-ttext"><span class="lp-tqmark">"</span><?=$text?>"</p>
      <div class="lp-tauthor">
        <div class="lp-tav" style="background:<?=$grad?>"><?=$av?></div>
        <div>
          <div class="lp-tname"><?=$name?></div>
          <div class="lp-trole"><?=$role?></div>
        </div>
      </div>
    </div>
    <?php endforeach;?>
  </div>
</section>

<!-- ══ CTA ════════════════════════════════════════ -->
<div class="lp-cta-sec">
  <div class="lp-cta-box">
    <div class="lp-cta-grid"></div>
    <h2 class="lp-ctah">Ready to launch your server?</h2>
    <p class="lp-ctas2">Join thousands of developers deploying with <?= APP_NAME ?>. Free to start, scales with you.</p>
    <a href="<?= BASE_URL ?>/register.php" class="lp-ctabtn">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
      Create Free Account Now
    </a>
  </div>
</div>

<!-- ══ FOOTER ═════════════════════════════════════ -->
<style>
.lf-link{display:flex;align-items:center;gap:9px;font-size:13px;font-weight:500;color:rgba(255,255,255,.42);text-decoration:none;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04);transition:color .14s}
.lf-link:last-child{border-bottom:none}
.lf-link:hover{color:rgba(255,255,255,.88)}
.lf-link:hover .lfi{opacity:1}
.lfi{width:16px;height:16px;flex-shrink:0;opacity:.6;transition:opacity .14s}
.lf-heading{font-size:10px;font-weight:800;letter-spacing:1.4px;text-transform:uppercase;color:rgba(255,255,255,.22);margin-bottom:16px;display:flex;align-items:center;gap:8px}
.lf-heading::after{content:'';flex:1;height:1px;background:linear-gradient(90deg,rgba(255,255,255,.1),transparent)}
.lf-social{width:36px;height:36px;border-radius:9px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;text-decoration:none;transition:all .18s}
.lf-social svg{width:15px;height:15px;color:rgba(255,255,255,.4);transition:color .18s}
.lf-social:hover{background:rgba(22,163,74,.12);border-color:rgba(22,163,74,.3);transform:translateY(-2px);box-shadow:0 4px 14px rgba(22,163,74,.15)}
.lf-social:hover svg{color:var(--primary)}
.lf-pay{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:600;color:rgba(255,255,255,.32);background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);padding:5px 11px;border-radius:7px;transition:all .15s}
.lf-pay:hover{background:rgba(255,255,255,.07);color:rgba(255,255,255,.55)}
.lf-pay svg{width:13px;height:13px;color:var(--primary);opacity:.8}
.lf-trust{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);border-radius:7px;padding:5px 10px}
.lf-trust svg{width:13px;height:13px;color:var(--primary)}
.lf-trust span{font-size:11px;font-weight:600;color:rgba(255,255,255,.3)}
@media(max-width:900px){.lp-footer-grid{grid-template-columns:1fr 1fr !important}}
@media(max-width:560px){.lp-footer-grid{grid-template-columns:1fr !important}}
</style>

<footer style="background:#0a0f1a;border-top:1px solid rgba(255,255,255,.06);padding:60px max(24px,calc((100% - 1180px)/2)) 0">

  <div class="lp-footer-grid" style="display:grid;grid-template-columns:2.2fr 1fr 1fr 1fr;gap:52px;padding-bottom:48px;border-bottom:1px solid rgba(255,255,255,.07)">

    <!-- Brand -->
    <div>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
          <?php if (!empty(get_setting('site_logo_d', ''))) : ?>
    <img src="<?= htmlspecialchars(get_setting('site_logo_d', '')) ?>" alt="Logo" style="width: 200px;">
<?php else: ?>
    <div class="lp-mark">
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5">
            <path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/>
        </svg>
    </div>
    <span class="lp-brand"><?= htmlspecialchars(APP_NAME) ?></span>
<?php endif; ?>
      </div>
      <p style="font-size:13.5px;color:rgba(255,255,255,.38);line-height:1.8;max-width:268px;margin-bottom:20px">Enterprise-grade cloud infrastructure for Indian developers. Deploy in 60 seconds, pay in INR.</p>
      <!-- Status -->
      <div style="display:inline-flex;align-items:center;gap:8px;background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.18);border-radius:9px;padding:8px 14px;margin-bottom:22px">
        <span style="width:8px;height:8px;border-radius:50%;background:#4CAF50;box-shadow:0 0 0 3px rgba(22,163,74,.2);animation:lpPulse 2s infinite;flex-shrink:0;display:inline-block"></span>
        <span style="font-size:12px;font-weight:600;color:rgba(22,163,74,.85)">All systems operational</span>
      </div>
      <!-- Social -->
      <div style="display:flex;gap:8px;margin-bottom:22px">
        <a href="#" class="lf-social" title="Twitter/X">
          <svg viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.741l7.73-8.835L1.254 2.25H8.08l4.259 5.636zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
        </a>
        <a href="#" class="lf-social" title="GitHub">
          <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0 1 12 6.844a9.59 9.59 0 0 1 2.504.337c1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.02 10.02 0 0 0 22 12.017C22 6.484 17.522 2 12 2z"/></svg>
        </a>
        <a href="#" class="lf-social" title="LinkedIn">
          <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 0 1-2.063-2.065 2.064 2.064 0 1 1 2.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
        </a>
        <a href="#" class="lf-social" title="YouTube">
          <svg viewBox="0 0 24 24" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
        </a>
      </div>
      <!-- Trust -->
      <!--div style="display:flex;gap:8px;flex-wrap:wrap">
        <div class="lf-trust">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          <span>SSL Secured</span>
        </div>
        <div class="lf-trust">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <span>GDPR</span>
        </div>
        <div class="lf-trust">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
          <span>99.9% Uptime</span>
        </div>
      </div-->
    </div>

    <!-- Product -->
    <div>
      <div class="lf-heading">Product</div>
      <a href="#features" class="lf-link">
        <svg class="lfi" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        Features
      </a>
      <a href="#pricing" class="lf-link">
        <svg class="lfi" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        Pricing
      </a>
      <a href="#how" class="lf-link">
        <svg class="lfi" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        How It Works
      </a>
      <a href="<?= BASE_URL ?>/login.php" class="lf-link">
        <svg class="lfi" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        Sign In
      </a>
      <a href="<?= BASE_URL ?>/register.php" class="lf-link">
        <svg class="lfi" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>
        Get Started Free
      </a>
      <a href="<?= BASE_URL ?>/dashboard.php" class="lf-link">
        <svg class="lfi" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
        Dashboard
      </a>
    </div>

    <!-- Company -->
    <div>
      <div class="lf-heading">Company</div>
      <a href="#" class="lf-link">
        <svg class="lfi" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        About Us
      </a>
      <a href="#" class="lf-link">
        <svg class="lfi" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
        Blog
      </a>
      <?php try{$career_count=(int)db()->query("SELECT COUNT(*) FROM career_openings WHERE is_active=1")->fetchColumn();}catch(\Exception $e){$career_count=0;} ?>
      <a href="<?= BASE_URL ?>/careers.php" class="lf-link">
        <svg class="lfi" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
        Careers <?php if($career_count>0):?><span style="font-size:9px;font-weight:700;background:var(--primary);color:#fff;padding:1px 7px;border-radius:99px"><?=$career_count?> open</span><?php endif;?>
      </a>
      <a href="<?= BASE_URL ?>/tickets.php" class="lf-link">
        <svg class="lfi" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        Support
      </a>
    </div>

    <!-- Legal -->
    <div>
      <div class="lf-heading">Legal</div>
      <a href="<?= BASE_URL ?>/page/privacy-policy" class="lf-link">
        <svg class="lfi" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        Privacy Policy
      </a>
      <a href="<?= BASE_URL ?>/page/terms-of-service" class="lf-link">
        <svg class="lfi" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        Terms of Service
      </a>
      <a href="<?= BASE_URL ?>/page/refund-policy" class="lf-link">
        <svg class="lfi" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
        Refund Policy
      </a>
      <a href="<?= BASE_URL ?>/page/cookie-policy" class="lf-link">
        <svg class="lfi" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8.56 2.75c4.37 6.03 6.02 9.42 8.03 17.72m2.54-15.38c-3.72 4.35-8.94 5.66-16.88 5.85m19.5 1.9c-3.5-.93-6.63-.82-8.94 0-2.58.92-5.01 2.86-7.44 6.32"/></svg>
        Cookie Policy
      </a>
      <a href="<?= BASE_URL ?>/page/acceptable-use-policy" class="lf-link">
        <svg class="lfi" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Acceptable Use
      </a>
    </div>

  </div>

  <!-- Payment row -->
  <div style="padding:20px 0;border-bottom:1px solid rgba(255,255,255,.06);display:flex;align-items:center;gap:8px;flex-wrap:wrap">
    <div style="display:flex;align-items:center;gap:6px;margin-right:4px;flex-shrink:0">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      <span style="font-size:11px;font-weight:700;color:rgba(255,255,255,.22);letter-spacing:.6px;text-transform:uppercase">Secure Payments:</span>
    </div>
    <?php
    $pays=[
      ['<path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>','UPI'],
      ['<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/>','Net Banking'],
      ['<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>','Cards'],
      ['<circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/>','Razorpay'],
      ['<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>','Stripe'],
      ['<path d="M20 12V6H4v6m16 0v6H4v-6m16 0H4"/>','PayPal'],
    ];
    foreach($pays as[$svg,$label]):
    ?>
    <div class="lf-pay">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><?= $svg ?></svg>
      <?= $label ?>
    </div>
    <?php endforeach; ?>
    <div style="margin-left:auto;display:flex;align-items:center;gap:6px">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      <span style="font-size:11.5px;font-weight:600;color:rgba(255,255,255,.28)">SSL Secured · GDPR Compliant</span>
    </div>
  </div>

  <!-- Bottom bar -->
  <div style="padding:18px 0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div style="display:flex;align-items:center;gap:7px">
      <span style="font-size:12px;color:rgba(255,255,255,.18)">Copyright &copy; 2021 - <?= date('Y') ?> <?= APP_NAME ?>. All rights reserved.</span>
    </div>
    <div style="display:flex;align-items:center;gap:8px;font-size:12px;color:rgba(255,255,255,.25)">
  <span>Made with</span>
  <svg width="14" height="14" viewBox="0 0 24 24" fill="#ef4444" stroke="none"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
  <span>in</span>
  <!-- Indian Flag SVG -->
  <svg width="22" height="15" viewBox="0 0 900 600" style="border-radius:2px;vertical-align:middle">
    <rect width="900" height="200" fill="#FF9933"/>
    <rect y="200" width="900" height="200" fill="#fff"/>
    <rect y="400" width="900" height="200" fill="#138808"/>
    <circle cx="450" cy="300" r="60" fill="none" stroke="#000080" stroke-width="6"/>
    <?php for($i=0;$i<24;$i++): $a=$i*15*M_PI/180; ?>
    <line x1="450" y1="300"
          x2="<?=round(450+60*sin($a),1)?>"
          y2="<?=round(300-60*cos($a),1)?>"
          stroke="#000080" stroke-width="3"/>
    <?php endfor; ?>
    <circle cx="450" cy="300" r="8" fill="#000080"/>
  </svg>
  <span>India</span>
</div>
  </div>

</footer>
<script>
/* ── Nav scroll shadow ────────────── */
window.addEventListener('scroll',function(){document.getElementById('lpnav').classList.toggle('scrolled',window.scrollY>20);});

/* ── Hamburger ─────────────────────── */
var _o=false;
function lpToggle(){_o=!_o;document.getElementById('lp-ham').classList.toggle('open',_o);document.getElementById('lp-drawer').classList.toggle('open',_o);document.body.style.overflow=_o?'hidden':'';}
function lpClose(){_o=false;document.getElementById('lp-ham').classList.remove('open');document.getElementById('lp-drawer').classList.remove('open');document.body.style.overflow='';}
document.addEventListener('click',function(e){if(_o&&!document.getElementById('lp-ham').contains(e.target)&&!document.getElementById('lp-drawer').contains(e.target))lpClose();});
window.addEventListener('resize',function(){if(window.innerWidth>768)lpClose();});
document.querySelectorAll('a[href^="#"]').forEach(function(a){a.addEventListener('click',function(e){var t=document.querySelector(a.getAttribute('href'));if(t){e.preventDefault();lpClose();setTimeout(function(){t.scrollIntoView({behavior:'smooth',block:'start'});},_o?280:0);}});});

/* ── 3D Tilt on testimonials ─────────── */
document.querySelectorAll('.lp-tcard').forEach(function(card){
  card.addEventListener('mousemove',function(e){
    var r=card.getBoundingClientRect();
    var x=(e.clientX-r.left)/r.width-.5;
    var y=(e.clientY-r.top)/r.height-.5;
    var base=card.classList.contains('lp-tcard:nth-child(2)')?14:0;
    card.style.transform='translateY(-6px) rotateX('+(y*-7)+'deg) rotateY('+(x*7)+'deg) scale(1.02)';
    card.style.boxShadow='0 '+(18+y*8)+'px '+(44+Math.abs(x)*16)+'px rgba(0,0,0,.12), 0 0 0 1px rgba(22,163,74,.1)';
  });
  card.addEventListener('mouseleave',function(){card.style.transform='';card.style.boxShadow='';});
});

/* ── Globe Canvas ───────────────────── */
(function(){
  var c=document.getElementById('lp-globe');
  if(!c)return;
  var ctx=c.getContext('2d');
  var W=330,H=330,R=140,cx=W/2,cy=H/2;
  // 1600 dots
  var dots=[];
  for(var i=0;i<1600;i++){
    var la=(Math.random()-.5)*Math.PI,lo=Math.random()*2*Math.PI;
    var x=Math.cos(la)*Math.cos(lo),y=Math.sin(la),z=Math.cos(la)*Math.sin(lo);
    var r=Math.random();
    dots.push({x,y,z,s:Math.random()*1.1+.3,
      c:r<.05?'rgba(22,163,74,.9)':r<.08?'rgba(6,182,212,.9)':r<.10?'rgba(139,92,246,.8)':'rgba(100,116,139,'+(Math.random()*.35+.1)+')'
    });
  }
  // City markers
  var cities=[{la:19.08,lo:72.88},{la:12.97,lo:77.59},{la:28.63,lo:77.22},{la:1.35,lo:103.82},{la:51.51,lo:-.12},{la:37.77,lo:-122.42},{la:35.68,lo:139.69},{la:40.71,lo:-74.01},{la:-33.87,lo:151.21}]
    .map(function(c){var la=c.la*Math.PI/180,lo=c.lo*Math.PI/180;return{x:Math.cos(la)*Math.cos(lo),y:Math.sin(la),z:Math.cos(la)*Math.sin(lo)};});
  var ang=0;
  function proj(x,y,z){var ca=Math.cos(ang),sa=Math.sin(ang);return{px:cx+(ca*x+sa*z)*R,py:cy-y*R,d:-sa*x+ca*z};}
  function draw(){
    ctx.clearRect(0,0,W,H);
    // Background circle
    var g=ctx.createRadialGradient(cx-30,cy-30,10,cx,cy,R);
    g.addColorStop(0,'rgba(22,163,74,.06)');g.addColorStop(.6,'rgba(22,163,74,.02)');g.addColorStop(1,'rgba(0,0,0,0)');
    ctx.beginPath();ctx.arc(cx,cy,R,0,2*Math.PI);ctx.fillStyle=g;ctx.fill();
    // Grid lines
    ctx.lineWidth=.4;
    for(var lt=-60;lt<=60;lt+=30){
      var la=lt*Math.PI/180;ctx.beginPath();var f=true;
      for(var loi=0;loi<=360;loi+=3){var lo2=loi*Math.PI/180;var xx=Math.cos(la)*Math.cos(lo2),yy=Math.sin(la),zz=Math.cos(la)*Math.sin(lo2);var p=proj(xx,yy,zz);if(p.d<0){var a=.04+(-p.d/R)*.07;ctx.strokeStyle='rgba(22,163,74,'+a+')';if(f){ctx.moveTo(p.px,p.py);f=false;}else ctx.lineTo(p.px,p.py);}else f=true;}
      ctx.stroke();
    }
    for(var loi2=0;loi2<360;loi2+=30){
      var lo3=loi2*Math.PI/180;ctx.beginPath();var f2=true;
      for(var lti=-90;lti<=90;lti+=3){var la2=lti*Math.PI/180;var xx2=Math.cos(la2)*Math.cos(lo3),yy2=Math.sin(la2),zz2=Math.cos(la2)*Math.sin(lo3);var p2=proj(xx2,yy2,zz2);if(p2.d<0){var a2=.04+(-p2.d/R)*.07;ctx.strokeStyle='rgba(22,163,74,'+a2+')';if(f2){ctx.moveTo(p2.px,p2.py);f2=false;}else ctx.lineTo(p2.px,p2.py);}else f2=true;}
      ctx.stroke();
    }
    // Dots
    var vis=dots.filter(function(d){return proj(d.x,d.y,d.z).d<0;});
    vis.sort(function(a,b){return proj(a.x,a.y,a.z).d-proj(b.x,b.y,b.z).d;});
    vis.forEach(function(d){var p=proj(d.x,d.y,d.z);var sc=.4+(-p.d/R)*.6;ctx.beginPath();ctx.arc(p.px,p.py,d.s*sc,0,2*Math.PI);ctx.fillStyle=d.c;ctx.fill();});
    // City arcs
    for(var i=0;i<cities.length;i++){for(var j=i+1;j<cities.length;j++){
      var p1=proj(cities[i].x,cities[i].y,cities[i].z),p2=proj(cities[j].x,cities[j].y,cities[j].z);
      if(p1.d<0&&p2.d<0&&Math.hypot(p2.px-p1.px,p2.py-p1.py)>28){
        var mx=(p1.px+p2.px)/2,my=(p1.py+p2.py)/2,dist=Math.hypot(p2.px-p1.px,p2.py-p1.py);
        var t2=Date.now()/1200+i*.4+j*.2,al=(.5+.5*Math.sin(t2))*.14;
        ctx.beginPath();ctx.moveTo(p1.px,p1.py);ctx.quadraticCurveTo(mx,my-dist*.22,p2.px,p2.py);
        ctx.strokeStyle='rgba(22,163,74,'+al+')';ctx.lineWidth=.7;ctx.stroke();
      }
    }}
    // City dots pulse
    cities.forEach(function(city,idx){
      var p=proj(city.x,city.y,city.z);
      if(p.d<0){
        var pulse=.5+.5*Math.sin(Date.now()/700+idx*1.1);
        ctx.beginPath();ctx.arc(p.px,p.py,3+pulse*4,0,2*Math.PI);ctx.strokeStyle='rgba(22,163,74,'+(pulse*.4)+')';ctx.lineWidth=1;ctx.stroke();
        ctx.beginPath();ctx.arc(p.px,p.py,2+pulse*2,0,2*Math.PI);ctx.strokeStyle='rgba(22,163,74,'+(pulse*.2)+')';ctx.lineWidth=.6;ctx.stroke();
        ctx.beginPath();ctx.arc(p.px,p.py,2.5,0,2*Math.PI);ctx.fillStyle='#16a34a';ctx.fill();
        ctx.beginPath();ctx.arc(p.px,p.py,1.2,0,2*Math.PI);ctx.fillStyle='white';ctx.fill();
      }
    });
    // Edge glow
    var edge=ctx.createRadialGradient(cx,cy,R-5,cx,cy,R+5);
    edge.addColorStop(0,'rgba(22,163,74,0)');edge.addColorStop(.5,'rgba(22,163,74,.15)');edge.addColorStop(1,'rgba(22,163,74,0)');
    ctx.beginPath();ctx.arc(cx,cy,R,0,2*Math.PI);ctx.strokeStyle=edge;ctx.lineWidth=8;ctx.stroke();
    ang+=.0025;requestAnimationFrame(draw);
  }
  draw();
})();
</script>
</body>
</html>