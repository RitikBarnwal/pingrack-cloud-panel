<?php
require_once __DIR__ . '/includes/bootstrap.php';
session_start_safe();
$logged_in = is_logged_in();
$current   = $logged_in ? current_user() : null;
$uname     = $current ? htmlspecialchars($current['username']) : '';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= APP_NAME ?> — Cloud Infrastructure, Reimagined</title>
<meta name="description" content="<?= APP_NAME ?> — Enterprise VPS. Deploy in 60s, pay in INR.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Instrument+Serif:ital@0;1&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   TOKENS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --cream:    #f5f0e8;
  --cream2:   #ede7d9;
  --cream3:   #e4dccb;
  --paper:    #faf7f2;
  --ink:      #1a1208;
  --ink2:     #3d3220;
  --ink3:     #6b5c42;
  --ink4:     #9c8a72;
  --ink5:     #c4b49a;
  --accent:   #c84b1f;
  --accent2:  #e86332;
  --accent3:  #f5e6de;
  --accent4:  #faf0eb;
  --green:    #2d6a4f;
  --green2:   #e8f5ee;
  --gold:     #b8860b;
  --gold2:    #fdf6e3;
  --font:     'Plus Jakarta Sans', sans-serif;
  --serif:    'Instrument Serif', Georgia, serif;
  --mono:     'JetBrains Mono', monospace;
  --border:   rgba(26,18,8,.1);
  --border2:  rgba(26,18,8,.16);
}
html{scroll-behavior:smooth}
body{
  font-family:var(--font);
  background:var(--paper);
  color:var(--ink);
  -webkit-font-smoothing:antialiased;
  overflow-x:hidden;
  cursor:default;
}

/* custom cursor */
.cursor{
  position:fixed;width:10px;height:10px;border-radius:50%;
  background:var(--accent);pointer-events:none;z-index:9999;
  transform:translate(-50%,-50%);transition:transform .1s,width .2s,height .2s,opacity .2s;
  mix-blend-mode:multiply;
}
.cursor-ring{
  position:fixed;width:36px;height:36px;border-radius:50%;
  border:1.5px solid rgba(200,75,31,.35);pointer-events:none;z-index:9998;
  transform:translate(-50%,-50%);transition:transform .18s ease,width .25s,height .25s,opacity .3s;
}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   NAV
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
.nav{
  position:fixed;top:0;left:0;right:0;z-index:400;
  height:58px;display:flex;align-items:center;
  padding:0 max(24px,calc((100% - 1200px)/2));
  background:rgba(250,247,242,.92);
  backdrop-filter:blur(16px);
  border-bottom:1px solid transparent;
  transition:border-color .3s;
}
.nav.scrolled{border-color:var(--border)}
.nav-logo{display:flex;align-items:center;gap:10px;text-decoration:none;flex-shrink:0}
.nav-mark{
  width:34px;height:34px;border-radius:8px;
  background:var(--ink);
  display:flex;align-items:center;justify-content:center;
  flex-shrink:0;
}
.nav-mark svg{width:16px;height:16px}
.nav-name{font-family:var(--serif);font-size:19px;color:var(--ink);letter-spacing:-.2px;font-style:italic}
.nav-links{display:flex;gap:0;margin:0 auto;padding:0 36px}
.nav-a{
  padding:6px 14px;border-radius:6px;
  font-size:13px;font-weight:600;color:var(--ink3);
  text-decoration:none;transition:all .15s;letter-spacing:.1px;
}
.nav-a:hover{color:var(--ink);background:var(--cream)}
.nav-r{display:flex;align-items:center;gap:10px;flex-shrink:0}
.nav-login{font-size:13px;font-weight:600;color:var(--ink3);text-decoration:none;padding:6px 12px;border-radius:6px;transition:all .15s}
.nav-login:hover{color:var(--ink);background:var(--cream)}
.nav-cta{
  display:inline-flex;align-items:center;gap:6px;
  padding:8px 18px;border-radius:8px;
  font-size:13px;font-weight:700;
  color:var(--paper);background:var(--ink);
  text-decoration:none;transition:all .2s;
  box-shadow:0 2px 8px rgba(26,18,8,.18);
}
.nav-cta:hover{background:var(--ink2);transform:translateY(-1px);box-shadow:0 4px 16px rgba(26,18,8,.22)}
.nav-dash{
  display:inline-flex;align-items:center;gap:6px;
  padding:7px 14px;border-radius:8px;font-size:13px;font-weight:700;
  color:var(--accent);background:var(--accent4);border:1px solid rgba(200,75,31,.2);
  text-decoration:none;transition:all .15s;
}
.nav-dash:hover{background:var(--accent3)}
.ham{display:none;margin-left:auto;width:38px;height:38px;border-radius:8px;background:var(--cream);border:1px solid var(--border);align-items:center;justify-content:center;cursor:pointer;flex-direction:column;gap:4.5px;flex-shrink:0}
.ham-l{width:16px;height:1.5px;background:var(--ink3);border-radius:2px;transition:all .22s;transform-origin:center}
.ham.open .ham-l:nth-child(1){transform:translateY(6px) rotate(45deg)}
.ham.open .ham-l:nth-child(2){opacity:0;transform:scaleX(0)}
.ham.open .ham-l:nth-child(3){transform:translateY(-6px) rotate(-45deg)}
.drawer{display:none;position:fixed;top:58px;left:0;right:0;z-index:390;background:var(--paper);border-bottom:1px solid var(--border);padding:10px 20px 20px;transform:translateY(-8px);opacity:0;pointer-events:none;transition:transform .22s,opacity .18s}
.drawer.open{transform:none;opacity:1;pointer-events:all}
.dl{display:flex;padding:10px 12px;border-radius:8px;font-size:14px;font-weight:600;color:var(--ink3);text-decoration:none;transition:all .13s}
.dl:hover{background:var(--cream);color:var(--ink)}
.ddiv{height:1px;background:var(--border);margin:8px 0}
.dacts{display:flex;flex-direction:column;gap:8px;margin-top:4px}
.da{display:flex;align-items:center;justify-content:center;gap:8px;padding:13px;border-radius:10px;font-size:14px;font-weight:700;text-decoration:none}
.da-g{background:var(--cream);color:var(--ink3);border:1px solid var(--border)}
.da-p{background:var(--ink);color:var(--paper)}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   HERO — Editorial asymmetric layout
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
.hero{
  min-height:100vh;
  padding:0;
  position:relative;
  overflow:hidden;
  display:grid;
  grid-template-columns:1fr 1fr;
}

/* Left panel */
.hero-left{
  background:var(--cream);
  padding:130px max(36px,calc((50vw - 600px)/1 + 48px)) 80px 48px;
  display:flex;flex-direction:column;justify-content:center;
  position:relative;overflow:hidden;
  min-height:100vh;
}
/* Subtle grain texture on left */
.hero-left::before{
  content:'';position:absolute;inset:0;pointer-events:none;z-index:0;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='300' height='300' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
  background-size:300px 300px;
}
/* Decorative rule top-left */
.hero-left::after{
  content:'';position:absolute;top:100px;left:48px;width:40px;height:2px;background:var(--accent);
}
.hero-issue{
  font-family:var(--mono);font-size:10px;font-weight:500;
  color:var(--ink4);letter-spacing:1.5px;text-transform:uppercase;
  margin-bottom:28px;display:flex;align-items:center;gap:10px;
  position:relative;z-index:1;
}
.hero-issue::before{content:'';width:6px;height:6px;border-radius:50%;background:var(--accent);flex-shrink:0;animation:blink-dot 2.5s infinite}
@keyframes blink-dot{0%,100%{opacity:1}50%{opacity:.3}}

.hero-h{
  font-family:var(--serif);
  font-size:clamp(52px,6.5vw,86px);
  line-height:.96;
  letter-spacing:-2px;
  color:var(--ink);
  margin-bottom:28px;
  position:relative;z-index:1;
}
.hero-h em{
  font-style:italic;
  color:var(--accent);
}

.hero-rule{
  width:40px;height:1.5px;background:var(--ink5);
  margin-bottom:24px;position:relative;z-index:1;
}
.hero-sub{
  font-size:16px;line-height:1.75;color:var(--ink3);
  max-width:420px;margin-bottom:44px;
  position:relative;z-index:1;font-weight:400;
}
.hero-btns{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:56px;position:relative;z-index:1}
.btn-pri{
  display:inline-flex;align-items:center;gap:8px;
  padding:14px 26px;border-radius:10px;
  font-size:14px;font-weight:800;
  color:var(--paper);background:var(--ink);
  text-decoration:none;transition:all .2s;
  box-shadow:0 2px 8px rgba(26,18,8,.2);
  font-family:var(--font);
}
.btn-pri:hover{background:var(--accent);transform:translateY(-2px);box-shadow:0 6px 24px rgba(200,75,31,.28)}
.btn-sec{
  display:inline-flex;align-items:center;gap:8px;
  padding:14px 20px;border-radius:10px;
  font-size:14px;font-weight:600;
  color:var(--ink2);
  background:transparent;border:1.5px solid var(--border2);
  text-decoration:none;transition:all .2s;
  font-family:var(--font);
}
.btn-sec:hover{background:var(--cream);border-color:var(--ink4);transform:translateY(-1px)}

.hero-proof{
  display:flex;align-items:center;gap:16px;
  position:relative;z-index:1;
}
.hero-avs{display:flex}
.hero-av{
  width:34px;height:34px;border-radius:50%;
  border:2.5px solid var(--cream);
  display:flex;align-items:center;justify-content:center;
  font-size:11px;font-weight:800;
  margin-left:-10px;overflow:hidden;flex-shrink:0;
}
.hero-av:first-child{margin-left:0}
.hero-proof-t{font-size:12.5px;color:var(--ink4);font-weight:500}
.hero-proof-t strong{color:var(--ink);font-weight:700}

/* Decorative column number */
.hero-colnum{
  position:absolute;bottom:40px;left:48px;
  font-family:var(--mono);font-size:10px;color:var(--ink5);letter-spacing:1px;
  z-index:1;
}

/* Right panel */
.hero-right{
  background:var(--ink);
  position:relative;overflow:hidden;
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  padding:90px 40px;
  min-height:100vh;
}
/* Dot grid dark */
.hero-right::before{
  content:'';position:absolute;inset:0;pointer-events:none;
  background-image:radial-gradient(circle,rgba(255,255,255,.06) 1px,transparent 1px);
  background-size:24px 24px;
}
/* Large editorial number watermark */
.hero-watermark{
  position:absolute;
  font-family:var(--serif);
  font-size:clamp(200px,20vw,280px);
  font-style:italic;
  color:rgba(255,255,255,.03);
  line-height:1;
  top:50%;left:50%;
  transform:translate(-50%,-50%);
  pointer-events:none;
  white-space:nowrap;
  z-index:0;
}

/* 3D Isometric server illustration */
.iso-wrap{position:relative;z-index:2;width:100%;max-width:440px}

.iso-svg{
  width:100%;
  filter:drop-shadow(0 32px 64px rgba(0,0,0,.5)) drop-shadow(0 8px 16px rgba(200,75,31,.15));
}

/* Stats cards stack */
.stats-stack{
  position:absolute;
  right:-16px;bottom:60px;
  z-index:10;
  display:flex;flex-direction:column;gap:8px;
}
.stat-card{
  background:rgba(250,247,242,.06);
  backdrop-filter:blur(16px);
  border:1px solid rgba(255,255,255,.1);
  border-radius:10px;padding:10px 16px;
  min-width:160px;
  animation:float-card 4s ease-in-out infinite;
}
.stat-card:nth-child(2){animation-delay:1.2s}
.stat-card:nth-child(3){animation-delay:2.4s}
@keyframes float-card{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}
.sc-top{display:flex;align-items:center;gap:7px;margin-bottom:4px}
.sc-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}
.sc-label{font-size:10px;font-weight:600;color:rgba(255,255,255,.45);font-family:var(--mono);letter-spacing:.4px;text-transform:uppercase}
.sc-val{font-family:var(--serif);font-size:22px;color:#fff;letter-spacing:-.5px;line-height:1}
.sc-sub{font-size:10px;color:rgba(255,255,255,.35);font-family:var(--mono);margin-top:3px}

/* Editorial caption */
.hero-caption{
  position:absolute;bottom:32px;left:0;right:0;
  text-align:center;
  font-family:var(--mono);font-size:9.5px;color:rgba(255,255,255,.2);
  letter-spacing:1.5px;text-transform:uppercase;z-index:2;
}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   SECTION BASE
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
.W{padding-left:max(24px,calc((100% - 1200px)/2));padding-right:max(24px,calc((100% - 1200px)/2))}
.tag{
  display:inline-flex;align-items:center;gap:8px;
  font-family:var(--mono);font-size:10px;font-weight:500;
  letter-spacing:1.5px;text-transform:uppercase;
  color:var(--accent);margin-bottom:14px;
}
.tag::before{content:'';width:18px;height:1px;background:var(--accent)}
.sh{
  font-family:var(--serif);
  font-size:clamp(32px,4vw,52px);
  line-height:1.02;letter-spacing:-1.5px;
  color:var(--ink);margin-bottom:14px;
}
.sh em{font-style:italic;color:var(--accent)}
.sh-sub{font-size:16px;color:var(--ink3);line-height:1.75;max-width:500px;font-weight:400}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   TICKER STRIP
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
.ticker{
  background:var(--ink);
  overflow:hidden;padding:13px 0;border-top:1px solid rgba(255,255,255,.06);
}
.ticker-track{display:flex;width:max-content;animation:tick 35s linear infinite}
.ticker-track:hover{animation-play-state:paused}
@keyframes tick{to{transform:translateX(-50%)}}
.t-item{
  display:flex;align-items:center;gap:10px;
  padding:0 28px;font-size:12px;font-weight:600;
  color:rgba(255,255,255,.35);
  border-right:1px solid rgba(255,255,255,.07);
  white-space:nowrap;font-family:var(--mono);letter-spacing:.3px;
}
.t-item.hot{color:var(--accent2)}
.t-dot{width:4px;height:4px;border-radius:50%;background:currentColor;flex-shrink:0}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   STAT ROW — newspaper column style
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
.stat-row{
  display:grid;grid-template-columns:repeat(4,1fr);
  background:var(--cream2);
  border-bottom:2px solid var(--ink);
}
.sc2{
  padding:40px 32px;
  border-right:1px solid var(--border2);
  position:relative;
}
.sc2:last-child{border-right:none}
/* Serif editorial number */
.sc2-n{
  font-family:var(--serif);font-size:52px;font-style:italic;
  color:var(--ink);letter-spacing:-3px;line-height:1;margin-bottom:6px;
}
.sc2-n span{color:var(--accent);font-size:.6em}
.sc2-l{font-size:11px;font-weight:600;color:var(--ink4);font-family:var(--mono);letter-spacing:.8px;text-transform:uppercase}
/* Corner index number */
.sc2::before{
  content:attr(data-n);
  position:absolute;top:16px;right:16px;
  font-family:var(--mono);font-size:9px;color:var(--ink5);letter-spacing:.5px;
}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   FEATURES — magazine column grid
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
.features-sec{padding:100px 0;background:var(--paper)}
.feat-inner{W}
.feat-header{
  display:grid;grid-template-columns:1fr 1fr;gap:60px;
  align-items:end;margin-bottom:60px;
  padding-bottom:40px;border-bottom:1px solid var(--border);
}
.feat-cols{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:var(--border)}
.feat-col{
  background:var(--paper);padding:36px 28px;
  position:relative;transition:background .2s;
}
.feat-col:hover{background:var(--cream)}
/* Running top number */
.feat-col-n{
  font-family:var(--mono);font-size:10px;color:var(--ink5);
  letter-spacing:.5px;margin-bottom:20px;
}
.feat-ico{
  width:44px;height:44px;border-radius:10px;
  display:flex;align-items:center;justify-content:center;
  font-size:18px;margin-bottom:16px;
  background:var(--cream2);border:1px solid var(--border);
  transition:all .2s;
}
.feat-col:hover .feat-ico{background:var(--accent4);border-color:rgba(200,75,31,.2)}
.feat-t{font-family:var(--serif);font-size:19px;color:var(--ink);margin-bottom:8px;line-height:1.2}
.feat-d{font-size:13px;color:var(--ink3);line-height:1.7}
/* Bottom line on hover */
.feat-col::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;background:var(--accent);transform:scaleX(0);transition:transform .3s;transform-origin:left}
.feat-col:hover::after{transform:scaleX(1)}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   OS STRIP — horizontal scroll ticker
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
.os-strip{
  background:var(--cream2);
  border-top:1px solid var(--border);border-bottom:1px solid var(--border);
  overflow:hidden;padding:18px 0;
}
.os-track{display:flex;width:max-content;animation:tick 28s linear infinite reverse}
.os-item{
  display:flex;align-items:center;gap:9px;padding:0 26px;
  font-size:12.5px;font-weight:700;color:var(--ink3);
  border-right:1px solid var(--border);white-space:nowrap;
}
.os-badge{
  font-family:var(--mono);font-size:9.5px;font-weight:600;
  padding:2px 7px;border-radius:4px;
  background:var(--ink);color:var(--paper);letter-spacing:.3px;
}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   TERMINAL SECTION — editorial split
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
.term-sec{
  background:var(--ink);
  padding:100px max(24px,calc((100% - 1200px)/2));
  display:grid;grid-template-columns:1fr 1fr;gap:80px;align-items:center;
  position:relative;overflow:hidden;
}
/* Large background letter */
.term-sec::before{
  content:'>';
  position:absolute;right:-40px;top:50%;transform:translateY(-50%);
  font-family:var(--mono);font-size:400px;color:rgba(255,255,255,.02);
  pointer-events:none;line-height:1;
}
.term-text .tag{color:var(--accent2)}
.term-text .tag::before{background:var(--accent2)}
.term-h{
  font-family:var(--serif);font-size:clamp(32px,4vw,48px);
  color:#fff;line-height:1.04;letter-spacing:-1.5px;margin-bottom:14px;
}
.term-h em{color:var(--accent2);font-style:italic}
.term-p{font-size:15px;color:rgba(255,255,255,.5);line-height:1.75;margin-bottom:32px;font-weight:400}
.term-pts{list-style:none;display:flex;flex-direction:column;gap:14px}
.term-pt{display:flex;align-items:flex-start;gap:12px;font-size:14px;color:rgba(255,255,255,.55);line-height:1.55}
.tpt-ico{width:28px;height:28px;border-radius:7px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
.term-pt strong{color:rgba(255,255,255,.9);font-weight:700;display:block;margin-bottom:2px}

/* Terminal window */
.term-window{
  background:#060a0f;
  border-radius:14px;overflow:hidden;
  border:1px solid rgba(255,255,255,.08);
  box-shadow:0 40px 80px rgba(0,0,0,.5),0 0 0 1px rgba(255,255,255,.04);
}
.tw-bar{height:42px;background:#0a0e15;border-bottom:1px solid rgba(255,255,255,.06);display:flex;align-items:center;padding:0 16px;gap:8px}
.tw-dot{width:11px;height:11px;border-radius:50%}
.tw-title{margin-left:auto;font-size:11px;color:rgba(255,255,255,.2);font-family:var(--mono);letter-spacing:.2px}
.tw-body{padding:22px 24px;font-family:var(--mono);font-size:12.5px;line-height:2}
.tl{display:flex;gap:8px}
.tp{color:#4ade80}.tc{color:#e2e8f0}.tco{color:#374151}.to{color:#60a5fa}.ts{color:#4ade80}
.tcur{display:inline-block;width:7px;height:13px;background:#4ade80;border-radius:1px;animation:blink 1.1s step-end infinite;vertical-align:middle;margin-left:1px}
@keyframes blink{0%,100%{opacity:1}50%{opacity:0}}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   PRICING — tabular editorial style
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
.pricing-sec{
  padding:100px 0;
  background:var(--cream);
  border-top:2px solid var(--ink);
}
.pricing-inner{W}
.pricing-top{display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:end;margin-bottom:60px}
/* Table-style pricing */
.price-table{border:1px solid var(--border2);border-radius:0;overflow:hidden}
.pt-head{
  display:grid;grid-template-columns:2fr 1fr 1fr 1fr;
  background:var(--ink);padding:16px 24px;
  font-family:var(--mono);font-size:10px;font-weight:600;
  color:rgba(255,255,255,.4);letter-spacing:1px;text-transform:uppercase;
}
.pt-row{
  display:grid;grid-template-columns:2fr 1fr 1fr 1fr;
  padding:0;border-bottom:1px solid var(--border);
  transition:background .15s;
}
.pt-row:last-child{border-bottom:none}
.pt-row:hover{background:var(--cream2)}
.pt-row.featured{background:var(--accent4);border-left:3px solid var(--accent)}
.pt-cell{padding:20px 24px;display:flex;align-items:center;font-size:13.5px}
.pt-cell:first-child{flex-direction:column;align-items:flex-start;gap:4px}
.pt-plan{font-family:var(--serif);font-size:18px;color:var(--ink);letter-spacing:-.3px}
.pt-plan-sub{font-size:11px;color:var(--ink4);font-family:var(--mono)}
.pt-price{font-family:var(--mono);font-size:18px;font-weight:600;color:var(--ink);letter-spacing:-.5px}
.pt-price small{font-size:11px;color:var(--ink4);font-weight:400}
.pt-specs-cell{flex-direction:column;align-items:flex-start;gap:3px}
.pt-spec{font-size:11.5px;color:var(--ink3);font-family:var(--mono)}
.pt-btn{
  display:inline-flex;align-items:center;gap:5px;
  padding:8px 16px;border-radius:7px;
  font-size:12px;font-weight:700;text-decoration:none;
  transition:all .18s;font-family:var(--font);
}
.pt-btn-pri{background:var(--ink);color:var(--paper)}
.pt-btn-pri:hover{background:var(--accent)}
.pt-btn-ghost{background:var(--cream);color:var(--ink2);border:1px solid var(--border2)}
.pt-btn-ghost:hover{background:var(--cream2)}
.pt-badge{
  font-size:10px;font-weight:800;padding:2px 8px;border-radius:4px;
  background:var(--accent);color:var(--paper);font-family:var(--mono);
  letter-spacing:.4px;margin-left:8px;
}
/* Pricing sidebar note */
.pricing-note{
  background:var(--ink);color:rgba(255,255,255,.65);
  border-radius:14px;padding:32px 28px;
  font-size:13.5px;line-height:1.8;
}
.pricing-note strong{color:#fff;font-weight:700;display:block;margin-bottom:8px;font-family:var(--serif);font-size:20px;font-style:italic}
.pricing-note ul{list-style:none;margin-top:16px;display:flex;flex-direction:column;gap:8px}
.pricing-note li{display:flex;align-items:center;gap:8px;font-size:12.5px;color:rgba(255,255,255,.5)}
.pricing-note li::before{content:'→';color:var(--accent2);font-family:var(--mono);flex-shrink:0}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   HOW IT WORKS — horizontal timeline
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
.how-sec{padding:100px 0;background:var(--paper);border-top:1px solid var(--border)}
.how-inner{W}
.how-head{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:60px;padding-bottom:28px;border-bottom:1px solid var(--border)}
.timeline{display:grid;grid-template-columns:repeat(4,1fr);gap:0;position:relative}
.timeline::before{content:'';position:absolute;top:22px;left:0;right:0;height:1px;background:var(--border2);z-index:0}
.tl-step{padding:0 24px 0 0;position:relative;z-index:1}
.tl-step:last-child{padding-right:0}
.tl-num{
  width:44px;height:44px;border-radius:8px;
  background:var(--cream);border:1.5px solid var(--border2);
  display:flex;align-items:center;justify-content:center;
  font-family:var(--mono);font-size:14px;font-weight:600;color:var(--ink);
  margin-bottom:24px;transition:all .25s;
}
.tl-step:hover .tl-num{background:var(--ink);color:var(--paper);border-color:var(--ink)}
.tl-t{font-family:var(--serif);font-size:18px;color:var(--ink);margin-bottom:8px;letter-spacing:-.3px}
.tl-d{font-size:13px;color:var(--ink3);line-height:1.65}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   REVIEWS — editorial pull-quote style
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
.reviews-sec{
  padding:100px 0;
  background:var(--cream2);
  border-top:2px solid var(--ink);
}
.reviews-inner{W}
.rev-head{display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:end;margin-bottom:60px;padding-bottom:32px;border-bottom:1px solid var(--border2)}
.rev-grid{
  display:grid;grid-template-columns:repeat(3,1fr);
  gap:1px;background:var(--border);
}
.rev-card{
  background:var(--cream2);
  padding:36px 28px;
  position:relative;transition:background .2s;
}
.rev-card:hover{background:var(--paper)}
/* Large pull-quote number */
.rev-n{
  font-family:var(--serif);font-size:80px;font-style:italic;
  color:rgba(26,18,8,.07);line-height:.8;
  position:absolute;top:20px;right:24px;
}
.rev-stars{display:flex;gap:3px;margin-bottom:16px}
.rev-star{font-size:12px;color:var(--gold)}
.rev-q{
  font-family:var(--serif);font-size:16px;font-style:italic;
  color:var(--ink2);line-height:1.7;margin-bottom:24px;
}
.rev-q::before{content:'\201C';color:var(--accent);margin-right:2px;font-size:20px;line-height:0;vertical-align:-.15em}
.rev-q::after{content:'\201D';color:var(--accent);margin-left:2px;font-size:20px;line-height:0;vertical-align:-.15em}
.rev-au{display:flex;align-items:center;gap:11px;padding-top:20px;border-top:1px solid var(--border)}
.rev-av{width:38px;height:38px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:900;flex-shrink:0}
.rev-name{font-family:var(--serif);font-size:15px;font-style:italic;color:var(--ink)}
.rev-role{font-size:10.5px;color:var(--ink4);font-family:var(--mono);letter-spacing:.2px;margin-top:1px}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   FAQ — accordion, minimal
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
.faq-sec{padding:80px 0;background:var(--paper);border-top:1px solid var(--border)}
.faq-inner{W;display:grid;grid-template-columns:1fr 2fr;gap:80px}
.faq-left .sh{max-width:280px}
.faq-items{border-top:1px solid var(--border)}
.faq-item{border-bottom:1px solid var(--border)}
.faq-q{
  width:100%;display:flex;justify-content:space-between;align-items:center;
  padding:20px 0;font-size:15px;font-weight:700;color:var(--ink);
  background:none;border:none;text-align:left;cursor:pointer;gap:16px;
  font-family:var(--font);transition:color .15s;
}
.faq-q:hover{color:var(--accent)}
.faq-ico{
  width:24px;height:24px;flex-shrink:0;border-radius:6px;
  background:var(--cream2);border:1px solid var(--border);
  display:flex;align-items:center;justify-content:center;
  transition:all .22s;
}
.faq-item.open .faq-ico{background:var(--accent);border-color:var(--accent);transform:rotate(45deg)}
.faq-ico svg{width:10px;height:10px;stroke:var(--ink3)}
.faq-item.open .faq-ico svg{stroke:#fff}
.faq-a{font-size:14px;color:var(--ink3);line-height:1.78;max-height:0;overflow:hidden;transition:max-height .3s ease,padding .3s ease;padding-bottom:0}
.faq-item.open .faq-a{max-height:200px;padding-bottom:20px}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   CTA — full-bleed editorial
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
.cta-sec{
  background:var(--accent);
  padding:100px max(24px,calc((100% - 1200px)/2));
  display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center;
  position:relative;overflow:hidden;
}
/* Watermark text bg */
.cta-sec::before{
  content:'GO';
  position:absolute;right:-20px;top:50%;transform:translateY(-50%);
  font-family:var(--serif);font-size:360px;font-style:italic;
  color:rgba(255,255,255,.06);pointer-events:none;line-height:1;
  letter-spacing:-20px;
}
.cta-left .sh{color:#fff;margin-bottom:0}
.cta-left .sh em{color:rgba(255,255,255,.65);font-style:italic}
.cta-right{display:flex;flex-direction:column;gap:20px;z-index:1}
.cta-sub{font-size:16px;color:rgba(255,255,255,.75);line-height:1.7;margin-bottom:4px}
.cta-btns{display:flex;gap:10px;flex-wrap:wrap}
.btn-cta-a{
  display:inline-flex;align-items:center;gap:7px;
  padding:14px 26px;border-radius:10px;
  font-size:14px;font-weight:800;text-decoration:none;
  background:#fff;color:var(--accent);
  transition:all .2s;box-shadow:0 4px 20px rgba(0,0,0,.15);
}
.btn-cta-a:hover{transform:translateY(-2px);box-shadow:0 8px 32px rgba(0,0,0,.2)}
.btn-cta-b{
  display:inline-flex;align-items:center;gap:7px;
  padding:14px 22px;border-radius:10px;
  font-size:14px;font-weight:600;text-decoration:none;
  color:rgba(255,255,255,.8);border:1.5px solid rgba(255,255,255,.3);
  transition:all .2s;
}
.btn-cta-b:hover{color:#fff;border-color:#fff}
.cta-assurances{display:flex;gap:20px;flex-wrap:wrap}
.cta-as{display:flex;align-items:center;gap:6px;font-size:12px;color:rgba(255,255,255,.65);font-weight:600}
.cta-as::before{content:'✓';color:rgba(255,255,255,.85);font-weight:800}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   VIDEO
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
.video-sec{
  padding:0 max(24px,calc((100% - 1200px)/2)) 80px;
  background:var(--paper);border-top:1px solid var(--border);
  padding-top:80px;
}
.video-wrap{
  position:relative;border-radius:16px;overflow:hidden;
  max-width:800px;margin:40px auto 0;aspect-ratio:16/9;
  border:1px solid var(--border2);
  box-shadow:0 24px 60px rgba(26,18,8,.12);
}
.video-wrap iframe{width:100%;height:100%;border:none;display:block}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   FOOTER
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
.footer{
  background:var(--ink);
  padding:28px max(24px,calc((100% - 1200px)/2));
  display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;
}
.foot-logo{display:flex;align-items:center;gap:8px;text-decoration:none}
.foot-name{font-family:var(--serif);font-size:17px;font-style:italic;color:rgba(255,255,255,.9)}
.foot-copy{font-size:11.5px;color:rgba(255,255,255,.25);font-family:var(--mono)}
.foot-links{display:flex;gap:18px}
.foot-links a{font-size:12px;color:rgba(255,255,255,.3);text-decoration:none;font-family:var(--mono);transition:color .15s}
.foot-links a:hover{color:rgba(255,255,255,.7)}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   SCROLL REVEAL
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
.rv{opacity:0;transform:translateY(22px);transition:opacity .65s ease,transform .65s ease}
.rv.in{opacity:1;transform:none}
.rv-left{opacity:0;transform:translateX(-22px);transition:opacity .65s ease,transform .65s ease}
.rv-left.in{opacity:1;transform:none}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   RESPONSIVE
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
@media(max-width:1060px){
  .hero{grid-template-columns:1fr}
  .hero-right{display:none}
  .hero-left{min-height:auto;padding:120px 24px 80px}
  .feat-header,.pricing-top,.rev-head,.how-head,.cta-sec{grid-template-columns:1fr}
  .cta-sec::before{display:none}
  .faq-inner{grid-template-columns:1fr}
  .stat-row{grid-template-columns:1fr 1fr}
  .sc2:nth-child(2){border-right:none}
  .sc2:nth-child(1),.sc2:nth-child(2){border-bottom:1px solid var(--border2)}
  .feat-cols{grid-template-columns:1fr 1fr}
  .rev-grid{grid-template-columns:1fr 1fr}
  .timeline{grid-template-columns:1fr 1fr;gap:32px}
  .timeline::before{display:none}
  .pt-head,.pt-row{grid-template-columns:1fr 1fr}
  .pt-cell:nth-child(3),.pt-cell:nth-child(4),.pt-head div:nth-child(3),.pt-head div:nth-child(4){display:none}
}
@media(max-width:768px){
  .nav-links,.nav-r{display:none}
  .ham{display:flex}
  .drawer{display:block}
  .feat-cols{grid-template-columns:1fr}
  .rev-grid{grid-template-columns:1fr}
  .cta-sec{padding:60px 24px}
  .timeline{grid-template-columns:1fr}
}
@media(max-width:480px){
  .stat-row{grid-template-columns:1fr 1fr}
  .hero-btns{flex-direction:column}
}
</style>
</head>
<body>

<!-- CURSOR -->
<div class="cursor" id="cur"></div>
<div class="cursor-ring" id="cur-ring"></div>

<!-- NAV -->
<nav class="nav" id="topnav">
  <a href="<?= BASE_URL ?>/" class="nav-logo">
    <div class="nav-mark">
      <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg>
    </div>
    <span class="nav-name"><?= APP_NAME ?></span>
  </a>
  <div class="nav-links">
    <a href="#features" class="nav-a">Features</a>
    <a href="#pricing"  class="nav-a">Pricing</a>
    <a href="#how"      class="nav-a">Process</a>
    <a href="#reviews"  class="nav-a">Reviews</a>
    <a href="#faq"      class="nav-a">FAQ</a>
  </div>
  <div class="nav-r">
    <?php if ($logged_in): ?>
      <a href="<?= BASE_URL ?>/dashboard.php" class="nav-dash">Dashboard →</a>
    <?php else: ?>
      <a href="<?= BASE_URL ?>/login.php"    class="nav-login">Sign in</a>
      <a href="<?= BASE_URL ?>/register.php" class="nav-cta">
        Deploy now
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
      </a>
    <?php endif; ?>
  </div>
  <button class="ham" id="nav-ham" onclick="navToggle()">
    <div class="ham-l"></div><div class="ham-l"></div><div class="ham-l"></div>
  </button>
</nav>

<!-- DRAWER -->
<div class="drawer" id="nav-drawer">
  <a href="#features" class="dl" onclick="navClose()">Features</a>
  <a href="#pricing"  class="dl" onclick="navClose()">Pricing</a>
  <a href="#how"      class="dl" onclick="navClose()">Process</a>
  <a href="#reviews"  class="dl" onclick="navClose()">Reviews</a>
  <a href="#faq"      class="dl" onclick="navClose()">FAQ</a>
  <div class="ddiv"></div>
  <div class="dacts">
    <?php if ($logged_in): ?>
      <a href="<?= BASE_URL ?>/dashboard.php" class="da da-p">Dashboard →</a>
    <?php else: ?>
      <a href="<?= BASE_URL ?>/login.php"    class="da da-g">Sign In</a>
      <a href="<?= BASE_URL ?>/register.php" class="da da-p">Deploy Now</a>
    <?php endif; ?>
  </div>
</div>

<!-- ═══════════════════════════════════════
     HERO
═══════════════════════════════════════ -->
<section class="hero">
  <!-- LEFT -->
  <div class="hero-left">
    <div class="hero-issue">
      <span class="hero-issue-dot"></span>
      Vol. 01 — Mumbai Datacenter — Live Now
    </div>

    <h1 class="hero-h">
      Cloud<br>
      infra<br>
      <em>done right.</em>
    </h1>

    <div class="hero-rule"></div>

    <p class="hero-sub">
      Enterprise VPS with dedicated vCPUs, NVMe SSDs &amp; full root access. Built for Indian developers — pay in INR, deploy in 60 seconds, zero surprises.
    </p>

    <div class="hero-btns">
      <a href="<?= BASE_URL ?>/register.php" class="btn-pri">
        Start deploying
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
      </a>
      <a href="#how" class="btn-sec">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>
        Watch demo
      </a>
    </div>

    <div class="hero-proof">
      <div class="hero-avs">
        <div class="hero-av" style="background:#d1fae5;color:#065f46">R</div>
        <div class="hero-av" style="background:#ede9fe;color:#5b21b6">P</div>
        <div class="hero-av" style="background:#fef3c7;color:#92400e">A</div>
        <div class="hero-av" style="background:#fee2e2;color:#991b1b">S</div>
        <div class="hero-av" style="background:#e0f2fe;color:#075985">N</div>
      </div>
      <span class="hero-proof-t">Trusted by <strong>2,400+ developers</strong> in India</span>
    </div>

    <div class="hero-colnum">01 / <?= APP_NAME ?> CLOUD — 2025</div>
  </div>

  <!-- RIGHT — dark panel with 3D illustration -->
  <div class="hero-right">
    <div class="hero-watermark"><?= APP_NAME ?></div>

    <!-- 3D Isometric Server Rack -->
    <div class="iso-wrap">
      <svg class="iso-svg" viewBox="0 0 500 480" fill="none" xmlns="http://www.w3.org/2000/svg">
        <defs>
          <linearGradient id="rg_top"  x1="0" y1="0" x2="1" y2="0"><stop offset="0%" stop-color="#1e2d40"/><stop offset="100%" stop-color="#152030"/></linearGradient>
          <linearGradient id="rg_face" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#142030"/><stop offset="100%" stop-color="#0a1520"/></linearGradient>
          <linearGradient id="rg_side" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#0c1828"/><stop offset="100%" stop-color="#060e18"/></linearGradient>
          <linearGradient id="rg_accent" x1="0" y1="0" x2="1" y2="0"><stop offset="0%" stop-color="#c84b1f"/><stop offset="100%" stop-color="#e86332"/></linearGradient>
          <linearGradient id="rg_green"  x1="0" y1="0" x2="1" y2="0"><stop offset="0%" stop-color="#22c55e"/><stop offset="100%" stop-color="#4ade80"/></linearGradient>
          <filter id="glow_r"><feGaussianBlur stdDeviation="2.5" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
          <filter id="glow_g"><feGaussianBlur stdDeviation="2" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
        </defs>

        <!-- Shadow -->
        <ellipse cx="250" cy="452" rx="160" ry="18" fill="rgba(0,0,0,.3)"/>

        <!-- ── RACK UNIT 3 (top, highlighted) ── -->
        <polygon points="110,210 250,138 390,210 250,282" fill="#1a2d44" stroke="rgba(200,75,31,.35)" stroke-width="1"/>
        <polyline points="110,210 250,138 390,210" stroke="url(#rg_accent)" stroke-width="2" opacity=".9"/>
        <polygon points="110,210 110,258 250,330 250,282" fill="#0d1e30" stroke="rgba(200,75,31,.1)" stroke-width=".5"/>
        <polygon points="250,282 250,330 390,258 390,210" fill="#081624" stroke="rgba(200,75,31,.08)" stroke-width=".5"/>
        <!-- LED bar accent -->
        <line x1="118" y1="212" x2="242" y2="280" stroke="url(#rg_accent)" stroke-width="2" filter="url(#glow_r)" opacity=".8"/>
        <!-- Drive bays front -->
        <polygon points="126,226 162,246 162,253 126,233" fill="rgba(200,75,31,.1)" stroke="rgba(200,75,31,.15)" stroke-width=".5"/>
        <polygon points="166,248 202,268 202,275 166,255" fill="rgba(200,75,31,.1)" stroke="rgba(200,75,31,.15)" stroke-width=".5"/>
        <polygon points="206,270 228,282 228,289 206,277" fill="rgba(200,75,31,.07)"/>
        <!-- Status lights -->
        <circle cx="133" cy="218" r="3.5" fill="#c84b1f" opacity="1" filter="url(#glow_r)"/>
        <circle cx="143" cy="224" r="2.5" fill="#e86332" opacity=".8" filter="url(#glow_r)"/>
        <circle cx="152" cy="229" r="2" fill="#22c55e" opacity=".7" filter="url(#glow_g)"/>
        <!-- Port cluster right -->
        <polygon points="286,267 328,245 328,252 286,274" fill="rgba(200,75,31,.1)" stroke="rgba(200,75,31,.2)" stroke-width=".7"/>
        <polygon points="332,243 364,226 364,233 332,250" fill="rgba(200,75,31,.1)" stroke="rgba(200,75,31,.2)" stroke-width=".7"/>
        <circle cx="340" cy="232" r="3.5" fill="#c84b1f" opacity=".4" filter="url(#glow_r)"/>

        <!-- ── RACK UNIT 2 (middle) ── -->
        <polygon points="110,258 250,186 390,258 250,330" fill="url(#rg_top)" stroke="rgba(255,255,255,.08)" stroke-width=".5"/>
        <polygon points="110,258 110,306 250,378 250,330" fill="url(#rg_face)" stroke="rgba(255,255,255,.05)" stroke-width=".5"/>
        <polygon points="250,330 250,378 390,306 390,258" fill="url(#rg_side)" stroke="rgba(255,255,255,.04)" stroke-width=".5"/>
        <line x1="116" y1="260" x2="242" y2="328" stroke="url(#rg_green)" stroke-width="1.5" opacity=".6"/>
        <polygon points="124,274 160,294 160,301 124,281" fill="rgba(34,197,94,.08)"/>
        <polygon points="164,296 200,316 200,323 164,303" fill="rgba(34,197,94,.08)"/>
        <circle cx="131" cy="266" r="2.5" fill="#22c55e" opacity=".7" filter="url(#glow_g)"/>
        <circle cx="140" cy="271" r="2" fill="#22c55e" opacity=".5"/>
        <polygon points="284,315 322,295 322,302 284,322" fill="rgba(255,255,255,.05)" stroke="rgba(255,255,255,.07)" stroke-width=".5"/>

        <!-- ── RACK UNIT 1 (bottom) ── -->
        <polygon points="110,306 250,234 390,306 250,378" fill="url(#rg_top)" stroke="rgba(255,255,255,.06)" stroke-width=".5"/>
        <polygon points="110,306 110,354 250,426 250,378" fill="url(#rg_face)" stroke="rgba(255,255,255,.04)" stroke-width=".5"/>
        <polygon points="250,378 250,426 390,354 390,306" fill="url(#rg_side)" stroke="rgba(255,255,255,.03)" stroke-width=".5"/>
        <line x1="116" y1="308" x2="240" y2="374" stroke="rgba(255,255,255,.12)" stroke-width="1" opacity=".5"/>
        <circle cx="128" cy="314" r="2" fill="#c84b1f" opacity=".5"/>
        <circle cx="136" cy="319" r="1.5" fill="#22c55e" opacity=".4"/>

        <!-- Vertical frame lines -->
        <line x1="110" y1="210" x2="110" y2="354" stroke="rgba(255,255,255,.06)" stroke-width="1"/>
        <line x1="390" y1="210" x2="390" y2="354" stroke="rgba(255,255,255,.04)" stroke-width="1"/>

        <!-- Animated data lines -->
        <line x1="164" y1="138" x2="164" y2="104" stroke="rgba(200,75,31,.3)" stroke-width="1" stroke-dasharray="4 4"><animate attributeName="stroke-dashoffset" values="0;-16" dur="1s" repeatCount="indefinite"/></line>
        <line x1="250" y1="138" x2="250" y2="96" stroke="rgba(34,197,94,.25)" stroke-width="1" stroke-dasharray="4 4"><animate attributeName="stroke-dashoffset" values="0;-16" dur="1.4s" repeatCount="indefinite"/></line>
        <line x1="320" y1="162" x2="320" y2="120" stroke="rgba(200,75,31,.2)" stroke-width="1" stroke-dasharray="4 4"><animate attributeName="stroke-dashoffset" values="0;-16" dur=".9s" repeatCount="indefinite"/></line>

        <!-- Top nodes -->
        <circle cx="164" cy="100" r="4.5" fill="#c84b1f" opacity=".6"><animate attributeName="r" values="4.5;6.5;4.5" dur="2.2s" repeatCount="indefinite"/><animate attributeName="opacity" values=".6;.2;.6" dur="2.2s" repeatCount="indefinite"/></circle>
        <circle cx="250" cy="92" r="4" fill="#22c55e" opacity=".55"><animate attributeName="r" values="4;6;4" dur="2.8s" repeatCount="indefinite"/><animate attributeName="opacity" values=".55;.15;.55" dur="2.8s" repeatCount="indefinite"/></circle>
        <circle cx="320" cy="116" r="3.5" fill="#c84b1f" opacity=".5"><animate attributeName="r" values="3.5;5;3.5" dur="1.9s" repeatCount="indefinite"/><animate attributeName="opacity" values=".5;.15;.5" dur="1.9s" repeatCount="indefinite"/></circle>

        <!-- Floating particles -->
        <circle cx="190" cy="168" r="1.5" fill="#c84b1f" opacity=".5"><animate attributeName="cy" values="168;156;168" dur="3.4s" repeatCount="indefinite"/></circle>
        <circle cx="286" cy="152" r="1" fill="#22c55e" opacity=".4"><animate attributeName="cy" values="152;140;152" dur="4.1s" repeatCount="indefinite"/></circle>
      </svg>

      <!-- Floating stat cards on right side -->
      <div class="stats-stack">
        <div class="stat-card">
          <div class="sc-top"><div class="sc-dot" style="background:#22c55e"></div><span class="sc-label">Uptime</span></div>
          <div class="sc-val">99.97%</div>
          <div class="sc-sub">last 90 days</div>
        </div>
        <div class="stat-card">
          <div class="sc-top"><div class="sc-dot" style="background:#c84b1f"></div><span class="sc-label">Boot</span></div>
          <div class="sc-val">38s</div>
          <div class="sc-sub">avg deploy</div>
        </div>
        <div class="stat-card">
          <div class="sc-top"><div class="sc-dot" style="background:#b8860b"></div><span class="sc-label">Cost</span></div>
          <div class="sc-val">₹299</div>
          <div class="sc-sub">per month</div>
        </div>
      </div>
    </div>

    <div class="hero-caption"><?= APP_NAME ?> Cloud Infrastructure · Mumbai · IN</div>
  </div>
</section>

<!-- TICKER -->
<div class="ticker">
  <div class="ticker-track">
    <?php
    $ti=[['Mumbai DC · 8ms','hot'],['NVMe SSD RAID-10',''],['IPv4 + IPv6 Free',''],['Cloud Firewall',''],['INR Billing · Zero FX','hot'],['UPI Accepted',''],['SSH Key Auth',''],['60s Deploy','hot'],['DDoS Protected',''],['Full Root Access',''],['Snapshots + Backups',''],['REST API',''],['Mumbai DC · 8ms','hot'],['NVMe SSD RAID-10',''],['IPv4 + IPv6 Free',''],['Cloud Firewall',''],['INR Billing · Zero FX','hot'],['UPI Accepted',''],['SSH Key Auth',''],['60s Deploy','hot'],['DDoS Protected',''],['Full Root Access','']];
    foreach($ti as[$l,$h]):?><div class="t-item <?=$h?>"><span class="t-dot"></span><?=$l?></div><?php endforeach;?>
  </div>
</div>

<!-- STATS ROW -->
<div class="stat-row">
  <div class="sc2 rv" data-n="01"><div class="sc2-n">5,000<span>+</span></div><div class="sc2-l">Active Servers</div></div>
  <div class="sc2 rv" data-n="02"><div class="sc2-n">99.97<span>%</span></div><div class="sc2-l">Uptime · 90 Days</div></div>
  <div class="sc2 rv" data-n="03"><div class="sc2-n">38<span>s</span></div><div class="sc2-l">Avg Boot Time</div></div>
  <div class="sc2 rv" data-n="04"><div class="sc2-n">2,400<span>+</span></div><div class="sc2-l">Customers</div></div>
</div>

<!-- FEATURES -->
<section class="features-sec" id="features">
  <div class="feat-inner">
    <div class="feat-header">
      <div class="rv-left">
        <div class="tag">Features</div>
        <h2 class="sh">Everything your infra <em>needs.</em></h2>
      </div>
      <p class="sh-sub rv" style="margin-bottom:0">Built for developers who value speed, reliability, and full control — without the enterprise complexity.</p>
    </div>
    <div class="feat-cols">
      <?php
      $fts=[
        ['⚡','Instant Deploy','Spin up any VPS in under 60 seconds. Pick OS, plan, region — done.'],
        ['📊','Live Monitoring','Real-time CPU, RAM, disk, bandwidth. Full visibility, always.'],
        ['🔑','SSH Key Auth','Manage keys from the dashboard. Passwordless root access everywhere.'],
        ['🔥','Cloud Firewall','Granular ingress/egress rules. Enterprise protection, zero iptables.'],
        ['💳','INR Wallet','No forex fees. UPI, cards, net banking. Prepaid, zero surprises.'],
        ['🔌','REST API','Full lifecycle automation. Integrate VPS into any CI/CD pipeline.'],
      ];
      foreach($fts as$i=>[$ic,$t,$d]):?>
      <div class="feat-col rv">
        <div class="feat-col-n"><?= str_pad($i+1,2,'0',STR_PAD_LEFT) ?></div>
        <div class="feat-ico"><?=$ic?></div>
        <div class="feat-t"><?=$t?></div>
        <div class="feat-d"><?=$d?></div>
      </div>
      <?php endforeach;?>
    </div>
  </div>
</section>

<!-- OS STRIP -->
<div class="os-strip">
  <div class="os-track">
    <?php
    $os=[['Ubuntu 24.04 LTS','LTS'],['Debian 12','Stable'],['CentOS 9','Stream'],['AlmaLinux 9','Free'],['Rocky Linux 9','RHEL'],['Ubuntu 22.04','LTS'],['Debian 11','LTS'],['Ubuntu 20.04','LTS'],['Ubuntu 24.04 LTS','LTS'],['Debian 12','Stable'],['CentOS 9','Stream'],['AlmaLinux 9','Free'],['Rocky Linux 9','RHEL'],['Ubuntu 22.04','LTS']];
    foreach($os as[$n,$b]):?><div class="os-item"><?=$n?><span class="os-badge"><?=$b?></span></div><?php endforeach;?>
  </div>
</div>

<!-- TERMINAL SECTION -->
<section class="term-sec">
  <div class="term-text rv-left">
    <div class="tag">Developer First</div>
    <h2 class="term-h">Built for<br><em>power users.</em></h2>
    <p class="term-p">Full root access, clean API, and a dashboard that doesn't get in your way. Your stack, your rules.</p>
    <ul class="term-pts">
      <li class="term-pt"><div class="tpt-ico">🐧</div><div><strong>Ubuntu, Debian, CentOS, AlmaLinux</strong>Latest stable releases always available.</div></li>
      <li class="term-pt"><div class="tpt-ico">⚡</div><div><strong>NVMe SSD storage</strong>10× faster I/O vs traditional SATA.</div></li>
      <li class="term-pt"><div class="tpt-ico">🌐</div><div><strong>IPv4 + IPv6 included</strong>Dedicated IPs on every server, no upcharge.</div></li>
      <li class="term-pt"><div class="tpt-ico">📸</div><div><strong>Snapshots & backups</strong>One-click restore. Sleep soundly.</div></li>
    </ul>
  </div>
  <div class="rv">
    <div class="term-window">
      <div class="tw-bar">
        <div class="tw-dot" style="background:#ff5f57"></div>
        <div class="tw-dot" style="background:#febc2e"></div>
        <div class="tw-dot" style="background:#28c840"></div>
        <span class="tw-title"><?= APP_NAME ?> CLI — deploy</span>
      </div>
      <div class="tw-body">
        <div class="tl"><span class="tp">$</span><span class="tc">&nbsp;vps create --plan cx22 --os ubuntu-24.04</span></div>
        <div class="tl"><span class="tco">&nbsp;&nbsp;# Connecting to <?= APP_NAME ?> API...</span></div>
        <div class="tl"><span class="to">&nbsp;&nbsp;✦ Server provisioned · ID: 58291034</span></div>
        <div class="tl"><span class="to">&nbsp;&nbsp;✦ Region: IN-MUM · IPv4: 49.13.84.22</span></div>
        <div class="tl"><span class="to">&nbsp;&nbsp;✦ Installing Ubuntu 24.04 LTS...</span></div>
        <div class="tl"><span class="ts">&nbsp;&nbsp;✓ RUNNING · Boot: 38s</span></div>
        <div class="tl" style="margin-top:6px"><span class="tp">$</span><span class="tc">&nbsp;ssh root@49.13.84.22</span></div>
        <div class="tl"><span class="ts">&nbsp;&nbsp;Welcome to Ubuntu 24.04 LTS — <?= APP_NAME ?></span></div>
        <div class="tl"><span class="tp">root@prod-01:~#</span><span class="tcur"></span></div>
      </div>
    </div>
  </div>
</section>

<!-- PRICING -->
<section class="pricing-sec" id="pricing">
  <div class="pricing-inner">
    <div class="pricing-top">
      <div class="rv-left">
        <div class="tag">Pricing</div>
        <h2 class="sh">Simple,<br><em>transparent</em> pricing.</h2>
        <p class="sh-sub">All prices in INR. No forex fees. Cancel anytime. No long-term contracts.</p>
      </div>
      <div class="pricing-note rv">
        <strong>Why <?= APP_NAME ?>?</strong>
        We're built for Indian developers from the ground up — INR billing, Mumbai latency, and support that actually responds.
        <ul>
          <li>Zero foreign transaction fees</li>
          <li>UPI, cards &amp; net banking accepted</li>
          <li>Dedicated Mumbai datacenter</li>
          <li>Priority support on Pro &amp; Enterprise</li>
        </ul>
      </div>
    </div>

    <div class="price-table rv">
      <div class="pt-head">
        <div>Plan</div>
        <div>Price</div>
        <div>Resources</div>
        <div>Action</div>
      </div>

      <div class="pt-row">
        <div class="pt-cell">
          <div class="pt-plan">Starter</div>
          <div class="pt-plan-sub">Side projects &amp; staging</div>
        </div>
        <div class="pt-cell"><span class="pt-price">₹299<small>/mo</small></span></div>
        <div class="pt-cell pt-specs-cell">
          <div class="pt-spec">2 vCPU · 2 GB RAM</div>
          <div class="pt-spec">40 GB NVMe · 20 TB BW</div>
        </div>
        <div class="pt-cell"><a href="<?= BASE_URL ?>/register.php" class="pt-btn pt-btn-ghost">Get started</a></div>
      </div>

      <div class="pt-row featured">
        <div class="pt-cell">
          <div class="pt-plan">Professional <span class="pt-badge">Popular</span></div>
          <div class="pt-plan-sub">Production workloads</div>
        </div>
        <div class="pt-cell"><span class="pt-price">₹799<small>/mo</small></span></div>
        <div class="pt-cell pt-specs-cell">
          <div class="pt-spec">4 vCPU · 8 GB RAM</div>
          <div class="pt-spec">160 GB NVMe · 20 TB BW</div>
        </div>
        <div class="pt-cell"><a href="<?= BASE_URL ?>/register.php" class="pt-btn pt-btn-pri">Get started →</a></div>
      </div>

      <div class="pt-row">
        <div class="pt-cell">
          <div class="pt-plan">Enterprise</div>
          <div class="pt-plan-sub">Mission-critical infra</div>
        </div>
        <div class="pt-cell"><span class="pt-price">₹1,999<small>/mo</small></span></div>
        <div class="pt-cell pt-specs-cell">
          <div class="pt-spec">8 vCPU · 16 GB RAM</div>
          <div class="pt-spec">240 GB NVMe · 30 TB BW</div>
        </div>
        <div class="pt-cell"><a href="<?= BASE_URL ?>/register.php" class="pt-btn pt-btn-ghost">Contact sales</a></div>
      </div>
    </div>
  </div>
</section>

<!-- HOW IT WORKS -->
<section class="how-sec" id="how">
  <div class="how-inner">
    <div class="how-head">
      <div class="rv-left">
        <div class="tag">Process</div>
        <h2 class="sh" style="margin-bottom:0">From signup to SSH<br><em>in four steps.</em></h2>
      </div>
      <p class="sh-sub rv" style="margin-bottom:0">No complex setup. No upfront credit card. Just infrastructure that works.</p>
    </div>
    <div class="timeline">
      <?php
      $steps=[
        ['01','Create Account','Sign up in under 60 seconds. Verify email and you\'re in.'],
        ['02','Add Credits','Top up your INR wallet — UPI, cards, or net banking.'],
        ['03','Deploy Server','Pick plan, OS, region. Your VPS launches in seconds.'],
        ['04','Take Control','SSH in with full root. Your stack, your rules, your server.'],
      ];
      foreach($steps as[$n,$t,$d]):?>
      <div class="tl-step rv">
        <div class="tl-num"><?=$n?></div>
        <div class="tl-t"><?=$t?></div>
        <div class="tl-d"><?=$d?></div>
      </div>
      <?php endforeach;?>
    </div>
  </div>
</section>

<!-- VIDEO -->
<div class="video-sec">
  <div class="tag">Demo</div>
  <h2 class="sh">See it <em>in action.</em></h2>
  <div class="video-wrap">
    <iframe src="https://www.youtube.com/embed/oafxkMv4xnc" allowfullscreen title="<?= APP_NAME ?> Demo"></iframe>
  </div>
</div>

<!-- REVIEWS -->
<section class="reviews-sec" id="reviews">
  <div class="reviews-inner">
    <div class="rev-head">
      <div class="rv-left">
        <div class="tag">Reviews</div>
        <h2 class="sh">What our customers<br><em>actually say.</em></h2>
      </div>
      <p class="sh-sub rv" style="margin-bottom:0">Real developers. Real workloads. Real results. No marketing copy.</p>
    </div>
    <div class="rev-grid">
      <?php
      $revs=[
        ['R','Rahul M.','Full-Stack Dev · Mumbai','#c84b1f','#fae8e2','Deploying is insanely fast. Production VPS in under a minute. INR + UPI billing is a massive plus — no forex surprises ever again.','Pro Plan'],
        ['P','Priya S.','DevOps Engineer · Bangalore','#7c3aed','#ede9fe','Clean, powerful dashboard. Managing 8 servers with real-time metrics from one place is exactly what I needed.','Enterprise'],
        ['A','Arjun K.','Startup Founder · Hyderabad','#0891b2','#e0f2fe','Switched from a foreign provider. Saved 40%, better Mumbai latency for Indian users. Performance is unmatched.','Pro Plan'],
        ['N','Nitesh T.','Backend Engineer · Delhi','#065f46','#d1fae5','The REST API is clean and well-documented. Automated entire VPS lifecycle in CI/CD. Zero downtime in 8 months.','Verified'],
        ['S','Sneha R.','Cloud Architect · Pune','#92400e','#fef3c7','Finally a provider that gets Indian developers. UPI, INR invoices, responsive support. Smooth from day one.','Pro Plan'],
        ['V','Vikram B.','SaaS Founder · Chennai','#1e40af','#dbeafe','Migrated our entire infra. Firewall intuitive, snapshots flawless, pricing fully transparent. Best infra decision.','Enterprise'],
      ];
      foreach($revs as$i=>[$av,$name,$role,$c,$bg,$t,$badge]):?>
      <div class="rev-card rv">
        <div class="rev-n"><?= str_pad($i+1,2,'0',STR_PAD_LEFT) ?></div>
        <div class="rev-stars"><span class="rev-star">★</span><span class="rev-star">★</span><span class="rev-star">★</span><span class="rev-star">★</span><span class="rev-star">★</span></div>
        <p class="rev-q"><?=$t?></p>
        <div class="rev-au">
          <div class="rev-av" style="background:<?=$bg?>;color:<?=$c?>"><?=$av?></div>
          <div><div class="rev-name"><?=$name?></div><div class="rev-role"><?=$role?> · <?=$badge?></div></div>
        </div>
      </div>
      <?php endforeach;?>
    </div>
  </div>
</section>

<!-- FAQ -->
<section class="faq-sec" id="faq">
  <div class="faq-inner">
    <div class="rv-left">
      <div class="tag">FAQ</div>
      <h2 class="sh">Common<br><em>questions.</em></h2>
    </div>
    <div class="faq-items">
      <?php
      $faqs=[
        ['Can I upgrade my plan later?','Yes — resize anytime from the dashboard. Takes effect within minutes, no data loss.'],
        ['Do you support UPI payments?','Absolutely. UPI, all major debit/credit cards, and net banking. All in INR, no forex fees.'],
        ['What operating systems are available?','Ubuntu 20/22/24 LTS, Debian 11/12, CentOS 9, AlmaLinux 8/9, Rocky Linux 9, and one-click apps.'],
        ['Is there a free trial?','New accounts get ₹200 credit — enough to run a Starter server for several days and evaluate fully.'],
        ['What support do you provide?','Starter gets community support. Pro and Enterprise get priority tickets with guaranteed response SLAs.'],
        ['Can I cancel anytime?','Yes. No contracts. Wallet balance is refundable. Cancel with one click.'],
      ];
      foreach($faqs as$i=>[$q,$a]):?>
      <div class="faq-item rv" id="faq-<?=$i?>">
        <button class="faq-q" onclick="toggleFaq(<?=$i?>)">
          <?=$q?>
          <div class="faq-ico"><svg viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="5" y1="1" x2="5" y2="9"/><line x1="1" y1="5" x2="9" y2="5"/></svg></div>
        </button>
        <div class="faq-a"><?=$a?></div>
      </div>
      <?php endforeach;?>
    </div>
  </div>
</section>

<!-- CTA -->
<section class="cta-sec">
  <div class="cta-left rv-left">
    <div class="tag" style="color:rgba(255,255,255,.6)"><span style="background:rgba(255,255,255,.3);display:inline-block;width:14px;height:1px;margin-right:0;vertical-align:middle;margin-bottom:1px"></span> Ready?</div>
    <h2 class="sh">Deploy your<br>first server<br><em>today.</em></h2>
  </div>
  <div class="cta-right rv">
    <p class="cta-sub">Join 2,400+ developers running production workloads on <?= APP_NAME ?>. Start free, scale on demand, pay in INR.</p>
    <div class="cta-btns">
      <a href="<?= BASE_URL ?>/register.php" class="btn-cta-a">
        Create free account
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
      </a>
      <a href="mailto:<?= get_setting('company_email','support@greathost.in') ?>" class="btn-cta-b">Talk to sales</a>
    </div>
    <div class="cta-assurances">
      <span class="cta-as">₹200 free credit</span>
      <span class="cta-as">No credit card needed</span>
      <span class="cta-as">Cancel anytime</span>
    </div>
  </div>
</section>

<!-- FOOTER -->
<footer class="footer">
  <a href="<?= BASE_URL ?>/" class="foot-logo">
    <div class="nav-mark" style="width:28px;height:28px;border-radius:7px">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg>
    </div>
    <span class="foot-name"><?= APP_NAME ?></span>
  </a>
  <div class="foot-copy">© <?= date('Y') ?> <?= APP_NAME ?>. All rights reserved.</div>
  <div class="foot-links">
    <a href="<?= BASE_URL ?>/login.php">login</a>
    <a href="<?= BASE_URL ?>/register.php">register</a>
    <a href="mailto:<?= get_setting('company_email','support@greathost.in') ?>">support</a>
  </div>
</footer>

<script>
/* ── Custom cursor ── */
var cur=document.getElementById('cur'),ring=document.getElementById('cur-ring');
var mx=0,my=0,rx=0,ry=0;
document.addEventListener('mousemove',function(e){mx=e.clientX;my=e.clientY;cur.style.left=mx+'px';cur.style.top=my+'px';});
(function lerp(){rx+=(mx-rx)*.12;ry+=(my-ry)*.12;ring.style.left=rx+'px';ring.style.top=ry+'px';requestAnimationFrame(lerp);})();
document.querySelectorAll('a,button,.feat-col,.rev-card,.tl-step').forEach(function(el){
  el.addEventListener('mouseenter',function(){cur.style.width='6px';cur.style.height='6px';ring.style.width='52px';ring.style.height='52px';ring.style.opacity='.6';});
  el.addEventListener('mouseleave',function(){cur.style.width='10px';cur.style.height='10px';ring.style.width='36px';ring.style.height='36px';ring.style.opacity='1';});
});

/* ── Nav ── */
window.addEventListener('scroll',function(){document.getElementById('topnav').classList.toggle('scrolled',window.scrollY>10);},{passive:true});
var _o=false;
function navToggle(){_o=!_o;document.getElementById('nav-ham').classList.toggle('open',_o);document.getElementById('nav-drawer').classList.toggle('open',_o);document.body.style.overflow=_o?'hidden':'';}
function navClose(){_o=false;document.getElementById('nav-ham').classList.remove('open');document.getElementById('nav-drawer').classList.remove('open');document.body.style.overflow='';}
document.addEventListener('click',function(e){if(_o&&!document.getElementById('nav-ham').contains(e.target)&&!document.getElementById('nav-drawer').contains(e.target))navClose();});
window.addEventListener('resize',function(){if(window.innerWidth>768)navClose();});
document.querySelectorAll('a[href^="#"]').forEach(function(a){a.addEventListener('click',function(e){var t=document.querySelector(a.getAttribute('href'));if(t){e.preventDefault();navClose();setTimeout(function(){t.scrollIntoView({behavior:'smooth',block:'start'});},_o?260:0);}});});

/* ── FAQ ── */
function toggleFaq(i){var item=document.getElementById('faq-'+i);var was=item.classList.contains('open');document.querySelectorAll('.faq-item.open').forEach(function(el){el.classList.remove('open');});if(!was)item.classList.add('open');}

/* ── Scroll reveal ── */
var io=new IntersectionObserver(function(entries){entries.forEach(function(e){if(e.isIntersecting){e.target.classList.add('in');io.unobserve(e.target);}});},{threshold:.1,rootMargin:'0px 0px -40px 0px'});
document.querySelectorAll('.rv,.rv-left').forEach(function(el,i){el.style.transitionDelay=(i%4)*.07+'s';io.observe(el);});
</script>
</body>
</html>
