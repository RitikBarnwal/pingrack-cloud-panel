<?php
require_once __DIR__ . '/includes/bootstrap.php';
session_start_safe();
$logged_in = is_logged_in();
$current   = $logged_in ? current_user() : null;
$uname     = $current ? htmlspecialchars($current['username']) : '';
$avatar    = $current ? strtoupper(mb_substr($current['full_name'] ?: $current['username'], 0, 1)) : '';

// Fetch all active job openings
$jobs = db()->query("SELECT * FROM career_openings WHERE is_active = 1 ORDER BY created_at DESC")->fetchAll() ?: [];
$total_openings = count($jobs);
// Sum of all vacancies (openings_count column added in migration)
$total_vacancies = array_sum(array_column($jobs, 'openings_count')) ?: $total_openings;

// Group by department
$departments = [];
foreach ($jobs as $job) {
    $dept = $job['department'] ?: 'General';
    $departments[$dept][] = $job;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Careers — <?= APP_NAME ?></title>
<meta name="description" content="Join the <?= APP_NAME ?> team. Building enterprise cloud infrastructure in India.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
<?php inject_global_head(); ?>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:'Plus Jakarta Sans',sans-serif;background:#f5f7fa;color:#1e2433;-webkit-font-smoothing:antialiased;overflow-x:hidden}

/* ── VARS ──────────────────────────────────────── */
:root{
  --p:var(--primary,#16a34a);
  --p10:color-mix(in srgb,var(--primary,#16a34a) 10%,transparent);
  --p20:color-mix(in srgb,var(--primary,#16a34a) 20%,transparent);
  --p30:color-mix(in srgb,var(--primary,#16a34a) 30%,transparent);
  --surface:#ffffff;
  --border:rgba(0,0,0,.09);
  --border2:rgba(0,0,0,.06);
}

/* ── NAV ───────────────────────────────────────── */
.nav{position:fixed;top:0;left:0;right:0;z-index:300;height:62px;
  display:flex;align-items:center;
  padding:0 max(20px,calc((100% - 1180px)/2));gap:24px;
  background:rgba(245,247,250,.92);backdrop-filter:blur(20px);
  border-bottom:1px solid var(--border);transition:all .2s}
.nav.scrolled{box-shadow:0 4px 30px rgba(0,0,0,.08)}
.nav-logo{display:flex;align-items:center;gap:9px;text-decoration:none;flex-shrink:0}
.nav-mark{width:34px;height:34px;border-radius:9px;background:var(--p);display:flex;align-items:center;justify-content:center;box-shadow:0 2px 10px var(--p30)}
.nav-mark svg{width:16px;height:16px}
.nav-brand{font-weight:800;font-size:17px;color:#111827;letter-spacing:-.4px}
.nav-links{display:flex;gap:2px;margin-left:6px}
.nav-link{padding:6px 13px;border-radius:8px;font-size:13px;font-weight:500;color:rgba(0,0,0,.45);text-decoration:none;transition:all .14s}
.nav-link:hover{color:#111827;background:rgba(0,0,0,.06)}
.nav-link.active{color:var(--p);background:var(--p10);font-weight:600}
.nav-r{margin-left:auto;display:flex;align-items:center;gap:10px}
.nav-ghost{padding:7px 16px;border-radius:9px;font-size:13px;font-weight:600;color:rgba(0,0,0,.5);text-decoration:none;border:1px solid rgba(0,0,0,.14);transition:all .14s}
.nav-ghost:hover{border-color:rgba(0,0,0,.28);color:#111827}
.nav-pri{padding:7px 18px;border-radius:9px;font-size:13px;font-weight:700;color:#fff;text-decoration:none;background:var(--p);box-shadow:0 2px 10px var(--p30);transition:all .16s;display:inline-flex;align-items:center;gap:6px}
.nav-pri:hover{background:var(--primary-hover);transform:translateY(-1px)}
.nav-ham{display:none;width:36px;height:36px;border-radius:8px;background:rgba(0,0,0,.05);border:1px solid var(--border);align-items:center;justify-content:center;cursor:pointer;flex-direction:column;gap:4.5px;margin-left:auto}
.nav-hl{width:16px;height:1.8px;background:rgba(0,0,0,.4);border-radius:2px;transition:all .22s;transform-origin:center}
.nav-ham.open .nav-hl:nth-child(1){transform:translateY(6.3px) rotate(45deg)}
.nav-ham.open .nav-hl:nth-child(2){opacity:0}
.nav-ham.open .nav-hl:nth-child(3){transform:translateY(-6.3px) rotate(-45deg)}
.nav-drawer{position:fixed;top:62px;left:0;right:0;z-index:290;background:#fff;border-bottom:1px solid var(--border);padding:12px 20px 20px;transform:translateY(-6px);opacity:0;pointer-events:none;transition:all .22s}
.nav-drawer.open{transform:translateY(0);opacity:1;pointer-events:all}
.nav-dl{display:flex;align-items:center;gap:9px;padding:10px 12px;border-radius:9px;font-size:14px;font-weight:500;color:rgba(0,0,0,.45);text-decoration:none;transition:all .14s}
.nav-dl:hover{background:rgba(0,0,0,.05);color:#111827}

/* ── HERO ──────────────────────────────────────── */
.hero{
  min-height:100vh;display:flex;align-items:center;
  padding:100px max(24px,calc((100% - 1180px)/2)) 80px;
  position:relative;overflow:hidden;
}
.hero-bg{position:absolute;inset:0;z-index:0;
  background:
    radial-gradient(ellipse 70% 60% at 20% 40%,color-mix(in srgb,var(--primary,#16a34a) 8%,transparent) 0%,transparent 60%),
    radial-gradient(ellipse 50% 70% at 80% 60%,rgba(99,102,241,.05) 0%,transparent 55%),
    radial-gradient(ellipse 60% 40% at 50% 5%,rgba(6,182,212,.04) 0%,transparent 50%);
}
.hero-grid{position:absolute;inset:0;z-index:0;
  background-image:linear-gradient(rgba(0,0,0,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(0,0,0,.04) 1px,transparent 1px);
  background-size:56px 56px;
  mask-image:radial-gradient(ellipse 100% 100% at 50% 50%,black 10%,transparent 100%);
}
.hero-inner{position:relative;z-index:1;max-width:800px;margin:0 auto;text-align:center}

.chip{display:inline-flex;align-items:center;gap:7px;
  background:var(--p10);border:1px solid var(--p20);
  color:var(--p);font-size:11.5px;font-weight:700;letter-spacing:.6px;
  padding:5px 14px;border-radius:99px;margin-bottom:26px;text-transform:uppercase;
}
.chip-dot{width:5px;height:5px;border-radius:50%;background:var(--p);animation:cpulse 2s infinite}
@keyframes cpulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(1.6)}}

.hero-h1{font-size:clamp(44px,6.5vw,82px);font-weight:900;line-height:1.02;letter-spacing:-3px;color:#111827;margin-bottom:22px}
.hero-h1 .gr{background:linear-gradient(90deg,var(--p),#06b6d4);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero-sub{font-size:18px;color:rgba(0,0,0,.45);line-height:1.72;max-width:580px;margin:0 auto 40px}

.hero-stats{display:flex;align-items:center;justify-content:center;gap:0;
  background:rgba(0,0,0,.025);border:1px solid var(--border);
  border-radius:16px;overflow:hidden;max-width:520px;margin:0 auto 40px;
  backdrop-filter:blur(8px);
}
.hstat{padding:20px 28px;text-align:center;border-right:1px solid var(--border);flex:1}
.hstat:last-child{border-right:none}
.hstat-n{font-size:26px;font-weight:900;color:#111827;letter-spacing:-1px;line-height:1}
.hstat-l{font-size:11px;color:rgba(0,0,0,.35);margin-top:4px;font-weight:500;text-transform:uppercase;letter-spacing:.5px}

.hero-btns{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
.btn-pri{display:inline-flex;align-items:center;gap:8px;padding:13px 26px;border-radius:12px;font-size:14.5px;font-weight:800;background:var(--p);color:#fff;text-decoration:none;box-shadow:0 4px 20px var(--p30);transition:all .2s}
.btn-pri:hover{background:var(--primary-hover);transform:translateY(-2px);box-shadow:0 8px 32px var(--p30)}
.btn-ghost{display:inline-flex;align-items:center;gap:8px;padding:13px 22px;border-radius:12px;font-size:14.5px;font-weight:600;color:rgba(0,0,0,.55);text-decoration:none;border:1px solid rgba(0,0,0,.14);background:rgba(0,0,0,.03);transition:all .2s}
.btn-ghost:hover{border-color:rgba(0,0,0,.28);color:#111827}

.hero-scroll{margin-top:52px;display:flex;flex-direction:column;align-items:center;gap:6px;color:rgba(0,0,0,.25);font-size:12px;font-weight:500}
.hero-scroll-line{width:1px;height:40px;background:linear-gradient(to bottom,rgba(0,0,0,.2),transparent);animation:scrollLine 2s ease-in-out infinite}
@keyframes scrollLine{0%,100%{opacity:.4;transform:scaleY(1)}50%{opacity:1;transform:scaleY(.7)}}

/* ── PERKS BAND ────────────────────────────────── */
.perks-band{border-top:1px solid var(--border);border-bottom:1px solid var(--border);background:rgba(0,0,0,.015);padding:0 max(24px,calc((100% - 1180px)/2))}
.perks-grid{display:grid;grid-template-columns:repeat(5,1fr)}
.perk{padding:28px 16px;text-align:center;border-right:1px solid var(--border);position:relative;transition:background .18s}
.perk:last-child{border-right:none}
.perk:hover{background:rgba(0,0,0,.025)}
.perk-ico{width:42px;height:42px;border-radius:11px;background:var(--p10);border:1px solid var(--p20);display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:18px}
.perk-t{font-size:13px;font-weight:700;color:#111827;margin-bottom:3px}
.perk-s{font-size:11.5px;color:rgba(0,0,0,.4);line-height:1.45}

/* ── OPENINGS SECTION ──────────────────────────── */
.openings-sec{padding:80px max(24px,calc((100% - 1180px)/2))}
.sec-tag{display:inline-flex;align-items:center;gap:7px;font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--p);margin-bottom:12px}
.sec-tag::before{content:'';width:18px;height:1.5px;background:var(--p)}
.sec-h{font-size:clamp(28px,4vw,46px);font-weight:900;letter-spacing:-1.8px;color:#111827;margin-bottom:12px;line-height:1.05}
.sec-sub{font-size:16px;color:rgba(0,0,0,.4);line-height:1.65;max-width:500px}

.filters{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin:36px 0 32px}
.ftab{padding:7px 16px;border-radius:99px;font-size:12.5px;font-weight:600;border:1px solid var(--border);background:transparent;color:rgba(0,0,0,.45);cursor:pointer;transition:all .15s;font-family:inherit}
.ftab:hover{border-color:rgba(0,0,0,.25);color:rgba(0,0,0,.8)}
.ftab.active{border-color:var(--p);background:var(--p);color:#fff;box-shadow:0 2px 12px var(--p30)}
.ftab .cnt{font-family:'JetBrains Mono',monospace;font-size:10.5px;background:rgba(0,0,0,.1);padding:1px 6px;border-radius:99px;margin-left:4px}
.ftab.active .cnt{background:rgba(255,255,255,.25)}

.dept-head{display:flex;align-items:center;gap:12px;margin:36px 0 16px;padding-bottom:10px;border-bottom:1px solid var(--border2)}
.dept-head:first-child{margin-top:0}
.dept-name{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1.2px;color:rgba(0,0,0,.3)}
.dept-line{flex:1;height:1px;background:var(--border2)}
.dept-cnt{font-family:'JetBrains Mono',monospace;font-size:10.5px;color:rgba(0,0,0,.25)}

.job-card{
  background:var(--surface);border:1px solid var(--border);
  border-radius:16px;padding:22px 24px;
  display:grid;grid-template-columns:auto 1fr auto auto;
  align-items:center;gap:18px;
  cursor:pointer;transition:all .2s;
  position:relative;overflow:hidden;
  margin-bottom:10px;
  box-shadow:0 1px 4px rgba(0,0,0,.04);
}
.job-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--p);opacity:0;transition:opacity .2s;border-radius:0}
.job-card:hover{border-color:var(--p20);background:#fafffe;transform:translateX(4px);box-shadow:0 4px 20px rgba(0,0,0,.08)}
.job-card:hover::before{opacity:1}
.job-card:last-child{margin-bottom:0}
.job-icon{width:46px;height:46px;border-radius:12px;background:var(--p10);border:1px solid var(--p20);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.job-info{min-width:0}
.job-title{font-size:15.5px;font-weight:800;color:#111827;margin-bottom:6px;line-height:1.2}
.job-meta{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.jm{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:500;color:rgba(0,0,0,.4)}
.jm svg{width:11px;height:11px;flex-shrink:0}
.job-type-badge{padding:4px 12px;border-radius:99px;font-size:10.5px;font-weight:700;letter-spacing:.3px;text-transform:uppercase;flex-shrink:0}
.jt-full{background:var(--p10);color:var(--p);border:1px solid var(--p20)}
.jt-part{background:rgba(234,179,8,.1);color:#ca8a04;border:1px solid rgba(234,179,8,.2)}
.jt-contract{background:rgba(6,182,212,.1);color:#0e7490;border:1px solid rgba(6,182,212,.2)}
.jt-intern{background:rgba(168,85,247,.1);color:#7c3aed;border:1px solid rgba(168,85,247,.2)}
.jt-remote{background:rgba(59,130,246,.1);color:#1d4ed8;border:1px solid rgba(59,130,246,.2)}
.job-arrow{width:34px;height:34px;border-radius:9px;border:1px solid var(--border);background:transparent;display:flex;align-items:center;justify-content:center;color:rgba(0,0,0,.25);transition:all .2s;flex-shrink:0}
.job-card:hover .job-arrow{background:var(--p);border-color:var(--p);color:#fff;box-shadow:0 2px 12px var(--p30)}

.empty-state{text-align:center;padding:80px 24px;background:rgba(0,0,0,.015);border:1px dashed var(--border);border-radius:20px}
.empty-ic{font-size:44px;margin-bottom:18px}
.empty-t{font-size:18px;font-weight:800;color:rgba(0,0,0,.6);margin-bottom:8px}
.empty-s{font-size:14.5px;color:rgba(0,0,0,.35);line-height:1.65;margin-bottom:24px}

/* ── CTA ───────────────────────────────────────── */
.cta-sec{padding:0 max(24px,calc((100% - 1180px)/2)) 80px}
.cta-box{
  border-radius:24px;padding:72px 60px;text-align:center;position:relative;overflow:hidden;
  background:linear-gradient(135deg,rgba(22,163,74,.07) 0%,rgba(6,182,212,.05) 50%,rgba(99,102,241,.06) 100%);
  border:1px solid var(--border);
}
.cta-glow{position:absolute;top:-40%;left:50%;transform:translateX(-50%);width:600px;height:400px;background:radial-gradient(ellipse,var(--p10) 0%,transparent 65%);pointer-events:none}
.cta-grid{position:absolute;inset:0;background-image:linear-gradient(rgba(0,0,0,.03) 1px,transparent 1px),linear-gradient(90deg,rgba(0,0,0,.03) 1px,transparent 1px);background-size:40px 40px;mask-image:radial-gradient(ellipse 100% 100% at 50% 50%,black 20%,transparent 100%)}
.cta-h{font-size:clamp(26px,4vw,48px);font-weight:900;color:#111827;letter-spacing:-2px;margin-bottom:14px;position:relative;z-index:1}
.cta-s{font-size:16px;color:rgba(0,0,0,.45);margin-bottom:32px;position:relative;z-index:1;line-height:1.6}
.cta-btns{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;position:relative;z-index:1}

/* ── FOOTER ────────────────────────────────────── */
.cr-foot{border-top:1px solid var(--border);padding:28px max(24px,calc((100% - 1180px)/2));display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;background:#eef0f4}
.cr-foot-logo{display:flex;align-items:center;gap:8px;font-size:15px;font-weight:800;color:#111827}
.cr-foot-copy{font-size:12.5px;color:rgba(0,0,0,.3)}
.cr-foot-links{display:flex;gap:18px;flex-wrap:wrap}
.cr-foot-links a{font-size:12.5px;color:rgba(0,0,0,.35);text-decoration:none;transition:color .14s}
.cr-foot-links a:hover{color:rgba(0,0,0,.7)}

/* ══════════════════════════════════════════════════
   JOB DETAIL PANEL
══════════════════════════════════════════════════ */
.drawer-overlay{position:fixed;inset:0;z-index:400;background:rgba(0,0,0,.35);backdrop-filter:blur(6px);opacity:0;pointer-events:none;transition:opacity .3s}
.drawer-overlay.open{opacity:1;pointer-events:all}
.drawer{
  position:fixed;top:0;right:0;bottom:0;z-index:401;
  width:min(680px,100vw);
  background:#fff;
  display:flex;flex-direction:column;
  transform:translateX(100%);transition:transform .35s cubic-bezier(.4,0,.2,1);
  overflow:hidden;
  border-left:1px solid var(--border);
  box-shadow:-4px 0 40px rgba(0,0,0,.1);
}
.drawer.open{transform:translateX(0)}

.drawer-head{
  padding:22px 28px;
  border-bottom:1px solid var(--border);
  display:flex;align-items:flex-start;justify-content:space-between;gap:14px;
  background:#fff;flex-shrink:0;
}
.drawer-close{width:34px;height:34px;border-radius:8px;border:1px solid var(--border);background:transparent;display:flex;align-items:center;justify-content:center;cursor:pointer;color:rgba(0,0,0,.35);transition:all .15s;flex-shrink:0}
.drawer-close:hover{background:rgba(0,0,0,.05);color:#111827}

.drawer-tabs{display:flex;border-bottom:1px solid var(--border);background:#fff;flex-shrink:0}
.dtab{flex:1;padding:13px;font-size:13px;font-weight:600;text-align:center;color:rgba(0,0,0,.35);cursor:pointer;border-bottom:2px solid transparent;transition:all .15s;background:none;border-top:none;border-left:none;border-right:none;font-family:inherit}
.dtab:hover{color:rgba(0,0,0,.7)}
.dtab.active{color:var(--p);border-bottom-color:var(--p)}

.drawer-body{flex:1;overflow-y:auto;padding:28px;background:#fff}
.drawer-body::-webkit-scrollbar{width:4px}
.drawer-body::-webkit-scrollbar-track{background:transparent}
.drawer-body::-webkit-scrollbar-thumb{background:rgba(0,0,0,.1);border-radius:2px}

.jd-title{font-size:22px;font-weight:900;color:#111827;letter-spacing:-.6px;margin-bottom:6px}
.jd-meta{font-size:13px;color:rgba(0,0,0,.4);margin-bottom:14px}
.jd-tags{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px}
.jd-sal{display:inline-flex;align-items:center;gap:7px;background:var(--p10);border:1px solid var(--p20);border-radius:9px;padding:7px 14px;font-size:13.5px;font-weight:700;color:var(--p)}
.jd-sec{margin-bottom:24px}
.jd-sec-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1.2px;color:rgba(0,0,0,.3);margin-bottom:12px;display:flex;align-items:center;gap:8px}
.jd-sec-title::after{content:'';flex:1;height:1px;background:var(--border2)}
.jd-text{font-size:14px;color:rgba(0,0,0,.55);line-height:1.75}
.jd-list{list-style:none;display:flex;flex-direction:column;gap:9px}
.jd-list li{display:flex;align-items:flex-start;gap:10px;font-size:14px;color:rgba(0,0,0,.5);line-height:1.55}
.jd-list li::before{content:'';width:6px;height:6px;border-radius:50%;background:var(--p);flex-shrink:0;margin-top:7px}

.apply-panel{display:none}
.apply-panel.active{display:block}
.detail-panel{display:block}
.detail-panel.hidden{display:none}

.af-section{margin-bottom:20px}
.af-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.af-row.full{grid-template-columns:1fr}
.af-group{display:flex;flex-direction:column;gap:5px}
.af-label{font-size:11.5px;font-weight:600;color:rgba(0,0,0,.4);letter-spacing:.3px;text-transform:uppercase}
.af-inp,.af-sel,.af-ta{
  width:100%;padding:10px 13px;
  border:1.5px solid var(--border);border-radius:10px;
  font-size:13.5px;font-family:inherit;color:#111827;
  background:#f9fafb;outline:none;transition:all .15s;
  -webkit-appearance:none;
}
.af-inp:focus,.af-sel:focus,.af-ta:focus{border-color:var(--p);box-shadow:0 0 0 3px var(--p10);background:#fff}
.af-inp::placeholder,.af-ta::placeholder{color:rgba(0,0,0,.25)}
.af-ta{resize:vertical;min-height:90px}
.af-sel option{background:#fff;color:#111827}

.drop-zone{
  border:2px dashed rgba(0,0,0,.15);border-radius:12px;
  padding:32px 20px;text-align:center;cursor:pointer;
  transition:all .2s;background:#f9fafb;position:relative;
}
.drop-zone:hover,.drop-zone.drag{border-color:var(--p);background:var(--p10)}
.drop-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.drop-zone-ico{font-size:24px;margin-bottom:10px}
.drop-zone-t{font-size:13px;font-weight:600;color:rgba(0,0,0,.45)}
.drop-zone-s{font-size:11.5px;color:rgba(0,0,0,.3);margin-top:4px}
.drop-zone-chosen{font-size:12.5px;font-weight:600;color:var(--p);margin-top:8px;display:none}

.af-submit{
  width:100%;padding:13px;border-radius:11px;
  background:var(--p);color:#fff;border:none;
  font-size:14.5px;font-weight:800;font-family:inherit;
  cursor:pointer;transition:all .18s;margin-top:6px;
  display:flex;align-items:center;justify-content:center;gap:8px;
  box-shadow:0 3px 16px var(--p30);
}
.af-submit:hover:not(:disabled){background:var(--primary-hover);transform:translateY(-1px);box-shadow:0 6px 24px var(--p30)}
.af-submit:disabled{opacity:.5;cursor:not-allowed;transform:none}

.apply-success{display:none;text-align:center;padding:60px 24px}
.apply-success.show{display:block}
.as-icon{font-size:52px;margin-bottom:18px}
.as-t{font-size:20px;font-weight:900;color:#111827;margin-bottom:8px;letter-spacing:-.4px}
.as-s{font-size:14px;color:rgba(0,0,0,.45);line-height:1.7}

.spin{width:16px;height:16px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spinA .7s linear infinite;flex-shrink:0}
@keyframes spinA{to{transform:rotate(360deg)}}

/* ── RESPONSIVE ────────────────────────────────── */
@media(max-width:1050px){
  .perks-grid{grid-template-columns:repeat(3,1fr)}
  .perk:nth-child(3){border-right:none}
  .perk:nth-child(4),.perk:nth-child(5){border-top:1px solid var(--border)}
}
@media(max-width:768px){
  .nav-links,.nav-r{display:none}
  .nav-ham{display:flex}
  .perks-grid{grid-template-columns:1fr 1fr}
  .perk:nth-child(2){border-right:none}
  .perk:nth-child(3){border-right:1px solid var(--border);border-top:1px solid var(--border)}
  .perk:nth-child(4),.perk:nth-child(5){border-top:1px solid var(--border)}
  .perk:nth-child(4){border-right:none}
  .job-card{grid-template-columns:auto 1fr}
  .job-type-badge,.job-arrow{display:none}
  .cta-box{padding:44px 24px}
  .af-row{grid-template-columns:1fr}
}
@media(max-width:480px){
  .perks-grid{grid-template-columns:1fr}
  .perk{border-right:none!important;border-bottom:1px solid var(--border)}
  .perk:last-child{border-bottom:none}
  .hero-stats{flex-direction:column}
  .hstat{border-right:none;border-bottom:1px solid var(--border)}
  .hstat:last-child{border-bottom:none}
}
</style>
</head>
<body>

<!-- ── NAV ──────────────────────────────────────── -->
<nav class="nav" id="mainnav">
  <a href="<?= BASE_URL ?>/" class="nav-logo">
    <div class="nav-mark"><svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg></div>
    <span class="nav-brand"><?= APP_NAME ?></span>
  </a>
  <div class="nav-links">
    <a href="<?= BASE_URL ?>/#features" class="nav-link">Features</a>
    <a href="<?= BASE_URL ?>/#pricing"  class="nav-link">Pricing</a>
    <a href="<?= BASE_URL ?>/#how"      class="nav-link">How It Works</a>
    <a href="<?= BASE_URL ?>/careers.php" class="nav-link active">
      Careers
      <span style="background:var(--p10);color:var(--p);font-size:10px;font-weight:700;padding:1px 7px;border-radius:99px;margin-left:3px;font-family:'JetBrains Mono',monospace">+<?= $total_vacancies ?></span>
    </a>
  </div>
  <div class="nav-r">
    <?php if ($logged_in): ?>
    <a href="<?= BASE_URL ?>/dashboard.php" class="nav-pri">
      <img src="<?= getGravatar($current['email'],$current['user_profile']) ?>" style="width:20px;height:20px;border-radius:5px">
      Dashboard
    </a>
    <?php else: ?>
    <a href="<?= BASE_URL ?>/login.php"    class="nav-ghost">Sign In</a>
    <a href="<?= BASE_URL ?>/register.php" class="nav-pri">Get Started →</a>
    <?php endif; ?>
  </div>
  <button class="nav-ham" id="navham" onclick="navToggle()">
    <div class="nav-hl"></div><div class="nav-hl"></div><div class="nav-hl"></div>
  </button>
</nav>

<!-- Mobile drawer -->
<div class="nav-drawer" id="navdrawer">
  <a href="<?= BASE_URL ?>/#features"   class="nav-dl" onclick="navClose()">Features</a>
  <a href="<?= BASE_URL ?>/#pricing"    class="nav-dl" onclick="navClose()">Pricing</a>
  <a href="<?= BASE_URL ?>/careers.php" class="nav-dl" onclick="navClose()">Careers +<?= $total_vacancies ?></a>
  <div style="height:1px;background:var(--border);margin:8px 0"></div>
  <?php if($logged_in): ?>
  <a href="<?= BASE_URL ?>/dashboard.php" class="nav-dl" style="color:var(--p);font-weight:700">→ Dashboard</a>
  <?php else: ?>
  <a href="<?= BASE_URL ?>/login.php"    class="nav-dl">Sign In</a>
  <a href="<?= BASE_URL ?>/register.php" class="nav-dl" style="color:var(--p);font-weight:700">Get Started →</a>
  <?php endif; ?>
</div>

<!-- ── HERO ──────────────────────────────────────── -->
<section class="hero">
  <div class="hero-bg"></div>
  <div class="hero-grid"></div>
  <div class="hero-inner">
    <div class="chip"><span class="chip-dot"></span> We're Hiring · <?= $total_vacancies ?> Open Roles</div>
    <h1 class="hero-h1">
      Build the future of<br>
      <span class="gr">cloud in India</span>
    </h1>
    <p class="hero-sub">Join a passionate team making enterprise VPS infrastructure that developers actually love. Remote-friendly, fast-paced, high impact.</p>

    <div class="hero-stats">
      <div class="hstat">
        <div class="hstat-n" style="color:var(--p)"><?= $total_openings ?></div>
        <div class="hstat-l">Open Positions</div>
      </div>
      <div class="hstat">
        <div class="hstat-n"><?= count($departments) ?></div>
        <div class="hstat-l">Departments</div>
      </div>
      <div class="hstat">
        <div class="hstat-n" style="color:#06b6d4">100%</div>
        <div class="hstat-l">Remote OK</div>
      </div>
    </div>

    <div class="hero-btns">
      <a href="#openings" class="btn-pri">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="8 18 14 12 8 6"/></svg>
        View All Openings
      </a>
      <a href="mailto:<?= get_setting('company_email','careers@greathost.in') ?>" class="btn-ghost">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        Open Application
      </a>
    </div>

    <div class="hero-scroll">
      <div class="hero-scroll-line"></div>
      Scroll to explore
    </div>
  </div>
</section>

<!-- ── PERKS ─────────────────────────────────────── -->
<div class="perks-band">
  <div class="perks-grid">
    <div class="perk"><div class="perk-ico"><i data-lucide="map-pin"></i></div><div class="perk-t">Remote-First</div><div class="perk-s">Work from anywhere in India</div></div>
    <div class="perk"><div class="perk-ico"><i data-lucide="trending-up"></i></div><div class="perk-t">Equity + Bonus</div><div class="perk-s">ESOPs & performance rewards</div></div>
    <div class="perk"><div class="perk-ico"><i data-lucide="heart-pulse"></i></div><div class="perk-t">Health Cover</div><div class="perk-s">Medical for you & family</div></div>
    <div class="perk"><div class="perk-ico"><i data-lucide="book-open"></i></div><div class="perk-t">L&D Budget</div><div class="perk-s">Learning & conferences paid</div></div>
    <div class="perk"><div class="perk-ico"><i data-lucide="zap"></i></div><div class="perk-t">Ship Fast</div><div class="perk-s">Real impact, no bureaucracy</div></div>
  </div>
</div>

<!-- ── OPENINGS ───────────────────────────────────── -->
<section class="openings-sec" id="openings">
  <div class="sec-tag">Open Roles</div>
  <h2 class="sec-h">Current Openings</h2>
  <p class="sec-sub">All roles open to candidates across India. Click any role to read details and apply.</p>

  <!-- Filter tabs -->
  <div class="filters" id="filterBar">
    <button class="ftab active" onclick="filterJobs('all',this)">
      All Roles <span class="cnt"><?= $total_openings ?></span>
    </button>
    <?php foreach ($departments as $dept => $djobs): ?>
    <button class="ftab" onclick="filterJobs(<?= json_encode($dept) ?>,this)">
      <?= htmlspecialchars($dept) ?> <span class="cnt"><?= count($djobs) ?></span>
    </button>
    <?php endforeach; ?>
  </div>

  <!-- Job list -->
  <div id="jobsList">
    <?php if (empty($jobs)): ?>
    <div class="empty-state">
      <div class="empty-ic">🔭</div>
      <div class="empty-t">No openings right now</div>
      <div class="empty-s">We don't have any open positions at the moment.<br>Drop us your resume and we'll reach out when something fits.</div>
      <a href="mailto:<?= get_setting('company_email','careers@greathost.in') ?>" class="btn-pri" style="display:inline-flex;margin-top:0">Send Open Application</a>
    </div>
    <?php else:

      foreach ($departments as $dept => $djobs):
      $dept_icon = match(strtolower($dept)){
        'engineering','tech' => 'cpu',
        'design' => 'pen-tool',
        'marketing','growth' => 'megaphone',
        'sales','business' => 'briefcase',
        'support','customer' => 'message-circle',
        'finance','accounting' => 'bar-chart-2',
        'hr','people' => 'users',
        'product' => 'package',
        'data','analytics' => 'database',
        default => 'rocket'
      };
    ?>
    <div class="dept-block" data-dept="<?= htmlspecialchars($dept) ?>">
      <div class="dept-head">
        <span class="dept-name"><?= htmlspecialchars($dept) ?></span>
        <div class="dept-line"></div>
        <span class="dept-cnt"><?= count($djobs) ?> opening<?= count($djobs)!=1?'s':'' ?></span>
      </div>
      <?php foreach ($djobs as $job):
        $jtype = strtolower($job['job_type']??'');
        $type_class = match($jtype){ 'part-time','parttime'=>'jt-part','contract'=>'jt-contract','internship'=>'jt-intern','remote'=>'jt-remote',default=>'jt-full'};
        $type_label = $job['job_type'] ?: 'Full-time';
      ?>
      <div class="job-card" onclick="openDrawer(<?= $job['id'] ?>)">
        <div class="job-icon"><i data-lucide="<?= $dept_icon ?>"></i></div>
        <div class="job-info">
          <div class="job-title"><?= htmlspecialchars($job['title']) ?></div>
          <div class="job-meta">
            <span class="jm">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
              <?= htmlspecialchars($job['location']?:'Remote') ?>
            </span>
            <?php if (!empty($job['salary_range'])): ?>
            <span class="jm">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
              <?= htmlspecialchars($job['salary_range']) ?>
            </span>
            <?php endif; ?>
            <span class="jm">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              Posted <?= date('M j', strtotime($job['created_at'])) ?>
            </span>
          </div>
        </div>
        <span class="job-type-badge <?= $type_class ?>"><?= htmlspecialchars($type_label) ?></span>
        <div class="job-arrow">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; endif; ?>
  </div>
</section>

<!-- ── CTA ───────────────────────────────────────── -->
<section class="cta-sec">
  <div class="cta-box">
    <div class="cta-glow"></div>
    <div class="cta-grid"></div>
    <h2 class="cta-h">Don't see your role?</h2>
    <p class="cta-s">We're always looking for exceptional people who think differently.<br>Tell us who you are and how you can contribute.</p>
    <div class="cta-btns">
      <a href="mailto:<?= get_setting('company_email','careers@greathost.in') ?>" class="btn-pri">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        Send Open Application
      </a>
      <a href="<?= BASE_URL ?>/" class="btn-ghost">Learn About Us</a>
    </div>
  </div>
</section>

<!-- ── FOOTER ────────────────────────────────────── -->
<footer class="cr-foot">
  <div class="cr-foot-logo">
    <div class="nav-mark" style="width:26px;height:26px;border-radius:7px">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg>
    </div>
    <?= APP_NAME ?>
  </div>
  <div class="cr-foot-copy">© <?= date('Y') ?> <?= APP_NAME ?>. All rights reserved.</div>
  <div class="cr-foot-links">
    <a href="<?= BASE_URL ?>/login.php">Login</a>
    <a href="<?= BASE_URL ?>/register.php">Register</a>
    <a href="<?= BASE_URL ?>/careers.php" style="color:var(--p);font-weight:600">Careers +<?= $total_vacancies ?></a>
    <a href="mailto:<?= get_setting('company_email','support@greathost.in') ?>">Support</a>
  </div>
</footer>

<!-- ══ SIDE DRAWER ═══════════════════════════════════ -->
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
<div class="drawer" id="jobDrawer">

  <!-- Drawer head -->
  <div class="drawer-head">
    <div style="flex:1;min-width:0">
      <div class="jd-title" id="drTitle">—</div>
      <div class="jd-meta" id="drMeta">—</div>
    </div>
    <button class="drawer-close" onclick="closeDrawer()">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>

  <!-- Tab bar -->
  <div class="drawer-tabs">
    <button class="dtab active" onclick="switchTab('detail',this)">Job Details</button>
    <button class="dtab" onclick="switchTab('apply',this)">Apply Now</button>
  </div>

  <!-- Body -->
  <div class="drawer-body">

    <!-- Detail panel -->
    <div id="panel-detail">
      <div class="jd-tags" id="drTags"></div>
      <div id="drSalary"></div>
      <div id="drContent"></div>
    </div>

    <!-- Apply panel -->
    <div id="panel-apply" style="display:none">

      <div id="applyFormWrap">
        <div style="margin-bottom:22px">
          <div style="font-size:16px;font-weight:800;color:#111827;margin-bottom:5px">Apply for <span id="applyJobTitle" style="color:var(--p)"></span></div>
          <div style="font-size:13px;color:rgba(0,0,0,.4)">All fields marked * are required.</div>
        </div>

        <div class="af-row">
          <div class="af-group"><label class="af-label">Full Name *</label><input class="af-inp" type="text" id="af-name" placeholder="Rahul Sharma" required></div>
          <div class="af-group"><label class="af-label">Email *</label><input class="af-inp" type="email" id="af-email" placeholder="rahul@example.com" required></div>
        </div>
        <div class="af-row">
          <div class="af-group"><label class="af-label">Phone</label><input class="af-inp" type="tel" id="af-phone" placeholder="+91 98765 43210"></div>
          <div class="af-group"><label class="af-label">Years of Experience</label>
            <select class="af-sel" id="af-exp">
              <option value="">Select...</option>
              <option>0–1 years (Fresher)</option>
              <option>1–3 years</option>
              <option>3–5 years</option>
              <option>5–8 years</option>
              <option>8+ years</option>
            </select>
          </div>
        </div>
        <div class="af-row full">
          <div class="af-group"><label class="af-label">LinkedIn / Portfolio URL</label><input class="af-inp" type="url" id="af-link" placeholder="https://linkedin.com/in/..."></div>
        </div>
        <div class="af-row full">
          <div class="af-group">
            <label class="af-label">Why <?= APP_NAME ?>? *</label>
            <textarea class="af-ta" id="af-cover" rows="4" placeholder="Tell us about yourself and why you're excited about this role..."></textarea>
          </div>
        </div>
        <div class="af-row full">
          <div class="af-group">
            <label class="af-label">Resume / CV *</label>
            <div class="drop-zone" id="dropZone">
              <input type="file" id="af-resume" accept=".pdf,.doc,.docx" onchange="handleFile()">
              <div class="drop-zone-ico"><i data-lucide="paperclip" style="width:24px;height:24px;color:var(--p)"></i></div>
              <div class="drop-zone-t">Drop resume here or click to browse</div>
              <div class="drop-zone-s">PDF, DOC, DOCX · Max 5 MB</div>
              <div class="drop-zone-chosen" id="fileChosen"></div>
            </div>
          </div>
        </div>

        <button class="af-submit" id="afSubmit" onclick="submitApplication()">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          Submit Application
        </button>
      </div>

      <div class="apply-success" id="applySuccess">
        <div class="as-icon">🎉</div>
        <div class="as-t">Application Submitted!</div>
        <div class="as-s">Thanks for applying to <strong id="successJobTitle" style="color:var(--p)"></strong>.<br>Our team will review and get back to you within 5–7 business days.</div>
      </div>

    </div>
  </div>
</div>

<!-- Job data -->
<script>
const JOBS = <?= json_encode(array_values($jobs)) ?>;
const BASE = '<?= BASE_URL ?>';
let _activeJobId = null;
let _navOpen = false;

/* ── Nav ─────────────────────────────────────────── */
function navToggle(){
  _navOpen=!_navOpen;
  document.getElementById('navham').classList.toggle('open',_navOpen);
  document.getElementById('navdrawer').classList.toggle('open',_navOpen);
}
function navClose(){
  _navOpen=false;
  document.getElementById('navham').classList.remove('open');
  document.getElementById('navdrawer').classList.remove('open');
}
window.addEventListener('scroll',()=>document.getElementById('mainnav').classList.toggle('scrolled',scrollY>20));
window.addEventListener('resize',()=>{if(window.innerWidth>768)navClose();});

/* ── Filter ──────────────────────────────────────── */
function filterJobs(dept, btn) {
  document.querySelectorAll('.ftab').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('.dept-block').forEach(el=>{
    el.style.display=(dept==='all'||el.dataset.dept===dept)?'':'none';
  });
}

/* ── Drawer open ─────────────────────────────────── */
function openDrawer(id) {
  const job = JOBS.find(j=>j.id==id);
  if (!job) return;
  _activeJobId = id;

  // Reset tabs
  switchTab('detail', document.querySelector('.dtab'));

  // Head
  document.getElementById('drTitle').textContent = job.title;
  document.getElementById('drMeta').textContent  = (job.department||'General') + ' · ' + (job.location||'Remote');
  document.getElementById('applyJobTitle').textContent   = job.title;
  document.getElementById('successJobTitle').textContent = job.title;

  // Tags
  const typeMap = {'part-time':'jt-part','parttime':'jt-part','contract':'jt-contract','internship':'jt-intern','remote':'jt-remote'};
  const typeClass = typeMap[(job.job_type||'').toLowerCase()] || 'jt-full';
  let tags = `<span class="job-type-badge ${typeClass}" style="margin-right:4px">${job.job_type||'Full-time'}</span>`;
  if (job.location) tags += `<span class="jm" style="font-size:13px"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>${job.location}</span>`;
  document.getElementById('drTags').innerHTML = tags;

  // Salary
  document.getElementById('drSalary').innerHTML = job.salary_range
    ? `<div class="jd-sal" style="margin-bottom:22px"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg> ${job.salary_range}</div>`
    : '';

  // Content sections
  let content = '';
  if (job.description) {
    content += `<div class="jd-sec"><div class="jd-sec-title">About the Role</div><div class="jd-text">${job.description.replace(/\n/g,'<br>')}</div></div>`;
  }
  if (job.requirements) {
    const items = job.requirements.split('\n').filter(r=>r.trim());
    content += `<div class="jd-sec"><div class="jd-sec-title">Requirements</div><ul class="jd-list">${items.map(r=>`<li>${r}</li>`).join('')}</ul></div>`;
  }
  if (job.responsibilities) {
    const items = job.responsibilities.split('\n').filter(r=>r.trim());
    content += `<div class="jd-sec"><div class="jd-sec-title">Responsibilities</div><ul class="jd-list">${items.map(r=>`<li>${r}</li>`).join('')}</ul></div>`;
  }
  content += `<div style="margin-top:28px"><button class="af-submit" onclick="switchTab('apply',document.querySelectorAll('.dtab')[1])">Apply for This Role →</button></div>`;
  document.getElementById('drContent').innerHTML = content;

  // Reset apply form
  document.getElementById('applyFormWrap').style.display = '';
  document.getElementById('applySuccess').classList.remove('show');
  ['af-name','af-email','af-phone','af-link','af-cover'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
  document.getElementById('af-exp').value='';
  document.getElementById('fileChosen').style.display='none';
  document.getElementById('fileChosen').textContent='';

  // Open
  document.getElementById('drawerOverlay').classList.add('open');
  document.getElementById('jobDrawer').classList.add('open');
  document.body.style.overflow='hidden';
}

function closeDrawer(){
  document.getElementById('drawerOverlay').classList.remove('open');
  document.getElementById('jobDrawer').classList.remove('open');
  document.body.style.overflow='';
}

/* ── Tabs ────────────────────────────────────────── */
function switchTab(panel, btn) {
  document.querySelectorAll('.dtab').forEach(b=>b.classList.remove('active'));
  if(btn) btn.classList.add('active');
  document.getElementById('panel-detail').style.display = panel==='detail'?'':'none';
  document.getElementById('panel-apply').style.display  = panel==='apply' ?'':'none';
  document.querySelector('.drawer-body').scrollTop = 0;
}

/* ── File drop ───────────────────────────────────── */
function handleFile(){
  const f = document.getElementById('af-resume').files[0];
  const chosen = document.getElementById('fileChosen');
  if (f) { chosen.textContent='✅ '+f.name; chosen.style.display='block'; }
}
const dz = document.getElementById('dropZone');
if (dz) {
  dz.addEventListener('dragover',e=>{e.preventDefault();dz.classList.add('drag');});
  dz.addEventListener('dragleave',()=>dz.classList.remove('drag'));
  dz.addEventListener('drop',e=>{e.preventDefault();dz.classList.remove('drag');const f=e.dataTransfer.files[0];if(f){document.getElementById('af-resume').files;const dt=new DataTransfer();dt.items.add(f);document.getElementById('af-resume').files=dt.files;handleFile();}});
}

/* ── Submit ──────────────────────────────────────── */
async function submitApplication() {
  const name  = document.getElementById('af-name')?.value?.trim();
  const email = document.getElementById('af-email')?.value?.trim();
  const cover = document.getElementById('af-cover')?.value?.trim();
  const phone = document.getElementById('af-phone')?.value?.trim();
  const link  = document.getElementById('af-link')?.value?.trim();
  const exp   = document.getElementById('af-exp')?.value?.trim();
  const resume= document.getElementById('af-resume')?.files[0];

  if (!name)   { alert('Please enter your full name.'); return; }
  if (!email)  { alert('Please enter your email.'); return; }
  if (!cover)  { alert('Please tell us why you want to join.'); return; }
  if (!resume) { alert('Please upload your resume.'); return; }
  if (resume.size > 5*1024*1024) { alert('Resume must be under 5 MB.'); return; }

  const btn = document.getElementById('afSubmit');
  btn.disabled = true;
  btn.innerHTML = '<div class="spin"></div> Submitting...';

  const fd = new FormData();
  fd.append('job_id', _activeJobId);
  fd.append('name',  name);
  fd.append('email', email);
  fd.append('phone', phone||'');
  fd.append('portfolio_url', link||'');
  fd.append('experience', exp||'');
  fd.append('cover_letter', cover);
  fd.append('resume', resume);

  try {
    const r = await fetch(BASE+'/api/career-apply.php',{method:'POST',body:fd});
    const d = await r.json();
    if (d.ok) {
      document.getElementById('applyFormWrap').style.display='none';
      document.getElementById('applySuccess').classList.add('show');
    } else {
      alert(d.error||'Something went wrong. Please try again.');
      btn.disabled=false;
      btn.innerHTML='<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Submit Application';
    }
  } catch(e) {
    alert('Network error. Please try again.');
    btn.disabled=false;
    btn.innerHTML='<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Submit Application';
  }
}

/* ESC key */
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeDrawer();});

/* Smooth scroll */
document.querySelectorAll('a[href^="#"]').forEach(a=>{
  a.addEventListener('click',e=>{
    const t=document.querySelector(a.getAttribute('href'));
    if(t){e.preventDefault();t.scrollIntoView({behavior:'smooth',block:'start'});}
  });
});
</script>
<script>lucide.createIcons();</script>
</body>
</html>