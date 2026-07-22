<?php
/**
 * packages.php — Deploy VPS Server (SparrowHost-style order flow)
 * Location → Plan → Additional options + a sticky Order Summary rail with a
 * segmented billing cycle. VPS packages only. INR-only, prepaid cycles.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$user     = current_user();
$uid      = (int)$user['id'];
$app_name = APP_NAME;
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$uname    = htmlspecialchars($user['username']);
$balance  = number_format((float)$user['wallet_balance'], 2);
$balance_raw = (float)$user['wallet_balance'];
$csrf     = csrf_token();

$cycle_names = [1=>'Monthly',3=>'Quarterly',6=>'Semi-Annual',12=>'Annual',24=>'Biennial',36=>'Triennial'];
$cycle_lbl   = [1=>'1 mo',3=>'3 mo',6=>'6 mo',12=>'12 mo',24=>'24 mo',36=>'36 mo'];

// ── Load VPS packages + enabled cycles ────────────────────────
$packages = [];
$no_table = false;
try {
    $rows = db()->query("SELECT * FROM vps_packages WHERE is_active=1 AND ptype='vps' ORDER BY sort_order, price_inr")->fetchAll();
    $cyc = [];
    foreach (db()->query("SELECT * FROM package_cycles WHERE is_enabled=1 ORDER BY months")->fetchAll() as $c) {
        $cyc[(int)$c['package_id']][] = ['months'=>(int)$c['months'], 'price'=>(float)$c['price_inr']];
    }
    // Derive a processor label from the package name/description (Virtualizor
    // plans are named by the admin; there's no CPU-brand field in the API).
    $proc_of = function(string $s): string {
        $s = strtolower($s);
        if (preg_match('/\b(amd\s*)?epyc\b/', $s))          return 'AMD EPYC';
        if (preg_match('/\bryzen\b/', $s))                  return 'AMD Ryzen';
        if (preg_match('/\bamd\b/', $s))                    return 'AMD';
        if (preg_match('/\b(intel\s*)?xeon\b/', $s))        return 'Intel Xeon';
        if (preg_match('/\bintel\b/', $s))                  return 'Intel';
        return '';
    };
    foreach ($rows as $p) {
        $pid = (int)$p['id'];
        if (empty($cyc[$pid])) continue;
        $packages[] = [
            'id'=>$pid, 'name'=>$p['name'], 'desc'=>$p['description'] ?? '',
            'loc'=>trim($p['location'] ?? '') ?: 'Other', 'flag'=>strtolower($p['location_flag'] ?? ''),
            'os'=>$p['os_label'] ?? '',
            'proc'=>$proc_of(($p['name'] ?? '') . ' ' . ($p['description'] ?? '')),
            'vcpu'=>(int)$p['vcpu'], 'ram'=>(float)$p['ram_gb'], 'disk'=>(int)$p['disk_gb'], 'bw'=>(int)$p['bandwidth_gb'],
            'cycles'=>$cyc[$pid],
        ];
    }
} catch (Throwable $e) { $no_table = true; }

$locations = [];
foreach ($packages as $p) {
    $L = $p['loc'];
    if (!isset($locations[$L])) $locations[$L] = ['name'=>$L, 'flag'=>$p['flag'], 'count'=>0];
    $locations[$L]['count']++;
    if (!$locations[$L]['flag'] && $p['flag']) $locations[$L]['flag'] = $p['flag'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Deploy VPS — <?= $app_name ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    :root{--ink:#0f172a;--line:#e6eaf0;--muted:#94a3b8;}
    .dp-wrap{padding:24px 30px 90px;max-width:1240px;margin:0 auto}
    .dp-crumb{font-size:13px;color:var(--muted);margin-bottom:14px}
    .dp-crumb b{color:#334155;font-weight:700}
    .dp-h1{font-size:24px;font-weight:900;color:var(--ink);letter-spacing:-.5px}
    .dp-back{display:inline-flex;align-items:center;gap:7px;margin:14px 0 22px;padding:8px 14px;border:1px solid var(--line);background:#fff;border-radius:9px;font-size:13px;font-weight:700;color:#334155;cursor:pointer;text-decoration:none}
    .dp-back:hover{background:#f8fafc}
    .dp-grid{display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start}

    .step{background:#fff;border:1px solid var(--line);border-radius:16px;padding:22px 24px;margin-bottom:18px}
    .step.locked{opacity:.5;pointer-events:none}
    .step-hd{display:flex;align-items:flex-start;gap:13px;margin-bottom:18px}
    .step-n{width:28px;height:28px;border-radius:50%;background:var(--ink);color:#fff;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .step.locked .step-n{background:#cbd5e1}
    .step-t{font-size:16px;font-weight:800;color:var(--ink)}
    .step-s{font-size:13px;color:var(--muted);margin-top:1px}

    .lbl-small{font-size:12px;font-weight:700;color:#64748b;margin-bottom:9px}

    .loc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
    .loc-card{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 16px;background:#fff;border:1.5px solid var(--line);border-radius:12px;cursor:pointer;transition:all .13s;text-align:left}
    .loc-card:hover{border-color:#cbd5e1}
    .loc-card.on{border-color:var(--ink);box-shadow:0 0 0 1px var(--ink)}
    .loc-l{display:flex;align-items:center;gap:12px}
    .loc-flag{width:30px;height:22px;border-radius:4px;object-fit:cover;box-shadow:0 0 0 1px rgba(0,0,0,.08)}
    .loc-pin{width:30px;height:30px;border-radius:7px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;font-size:16px}
    .loc-nm{font-size:14.5px;font-weight:800;color:var(--ink)}
    .loc-sub{font-size:12px;color:var(--muted);margin-top:1px}
    .loc-tick{width:20px;height:20px;border-radius:50%;background:var(--ink);color:#fff;display:none;align-items:center;justify-content:center;font-size:12px;flex-shrink:0}
    .loc-card.on .loc-tick{display:flex}

    .plan-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px}
    .plan-card{position:relative;padding:18px;background:#fff;border:1.5px solid var(--line);border-radius:13px;cursor:pointer;transition:all .13s}
    .plan-card:hover{border-color:#cbd5e1;box-shadow:0 2px 10px rgba(15,23,42,.05)}
    .plan-card.on{border-color:var(--ink);border-width:1.8px;background:#fafafa}
    .plan-card.on .plan-tick{display:block}
    .plan-tick{position:absolute;top:14px;right:14px;color:var(--ink);display:none;font-size:15px;font-weight:900;line-height:1}
    .plan-nm{font-size:14.5px;font-weight:800;color:var(--ink);letter-spacing:-.2px;padding-right:22px}
    .plan-proc{display:inline-flex;align-items:center;gap:6px;margin-top:7px;font-size:12px;font-weight:700;color:#64748b}
    .plan-proc .mk{width:12px;height:12px;border-radius:3px;flex-shrink:0}
    .ded-pill{display:inline-flex;align-items:center;padding:2px 8px;border-radius:99px;background:#ede9fe;color:#6d28d9;font-size:10.5px;font-weight:800;margin-left:6px}
    /* Processor filter pills (Step 2) */
    .proc-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
    .proc-tab{padding:7px 14px;border:1.5px solid var(--line);border-radius:99px;background:#fff;font-size:12.5px;font-weight:700;color:#64748b;cursor:pointer;transition:all .13s;display:inline-flex;align-items:center;gap:6px}
    .proc-tab:hover{border-color:#cbd5e1}
    .proc-tab.on{background:var(--ink);border-color:var(--ink);color:#fff}
    .plan-specs{list-style:none;padding:0;margin:14px 0 0;display:flex;flex-direction:column;gap:9px}
    .plan-specs li{display:flex;align-items:center;gap:9px;font-size:13.5px;color:#475569}
    .plan-specs svg{width:15px;height:15px;color:#64748b;flex-shrink:0}
    .plan-price{margin-top:15px;padding-top:14px;border-top:1px solid #f1f5f9;font-size:20px;font-weight:900;color:var(--ink);letter-spacing:-.5px}
    .plan-price small{font-size:12px;font-weight:600;color:var(--muted)}

    .host-inp{width:100%;max-width:100%;padding:12px 14px;background:#fff;border:1.5px solid var(--line);border-radius:10px;font-size:14px;color:var(--ink);outline:none;transition:border-color .14s}
    .host-inp:focus{border-color:var(--ink)}
    .opt-row{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:16px 0;border-top:1px solid #f1f5f9}
    .opt-t{font-size:14px;font-weight:700;color:var(--ink)}
    .opt-s{font-size:12.5px;color:var(--muted);margin-top:2px}
    .switch{position:relative;width:42px;height:24px;background:#e2e8f0;border-radius:99px;flex-shrink:0;cursor:pointer;transition:background .16s}
    .switch.on{background:var(--ink)}
    .switch::after{content:'';position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:transform .16s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
    .switch.on::after{transform:translateX(18px)}

    /* Order summary rail */
    .sum{position:sticky;top:76px;background:#fff;border:1px solid var(--line);border-radius:16px;padding:22px 22px 24px}
    .sum-h{font-size:17px;font-weight:900;color:var(--ink);margin-bottom:18px}
    .sum-row{display:flex;justify-content:space-between;gap:10px;padding:7px 0;font-size:13px}
    .sum-k{color:#64748b}.sum-v{color:var(--ink);font-weight:700;text-align:right}
    .sum-empty{color:#cbd5e1;font-size:13px;font-style:italic;padding:6px 0}
    .sum-div{height:1px;background:var(--line);margin:14px 0}
    .cyc-lbl{font-size:12px;font-weight:700;color:#64748b;margin-bottom:8px}
    .cyc-seg{display:flex;background:#f1f5f9;border-radius:10px;padding:4px;gap:3px;margin-bottom:4px}
    .cyc-btn{flex:1;padding:8px 6px;border:none;background:transparent;border-radius:7px;font-size:12.5px;font-weight:700;color:#64748b;cursor:pointer;transition:all .13s;font-family:inherit;white-space:nowrap}
    .cyc-btn.on{background:#fff;color:var(--ink);box-shadow:0 1px 3px rgba(15,23,42,.12)}
    .sum-total{display:flex;justify-content:space-between;align-items:baseline;margin-top:16px}
    .sum-total-l{font-size:17px;font-weight:900;color:var(--ink)}
    .sum-total-v{font-size:24px;font-weight:900;color:var(--ink);letter-spacing:-.8px}
    .sum-billed{font-size:12px;color:var(--muted);margin-top:3px}
    .sum-wallet{display:flex;justify-content:space-between;align-items:center;padding:10px 13px;background:#f8fafc;border:1px solid var(--line);border-radius:9px;margin:16px 0 12px;font-size:12.5px}
    .sum-wallet b{font-weight:800;color:var(--ink);font-family:'JetBrains Mono',monospace}
    .wl-low{color:#dc2626!important}
    .btn-ink{width:100%;padding:14px;background:var(--ink);color:#fff;border:none;border-radius:11px;font-size:14.5px;font-weight:800;cursor:pointer;transition:all .16s;display:flex;align-items:center;justify-content:center;gap:8px}
    .btn-ink:hover:not(:disabled){background:#1e293b}
    .btn-ink:disabled{opacity:.4;cursor:not-allowed}
    .topup-note{font-size:11.5px;color:#dc2626;text-align:center;margin-top:9px;font-weight:600}
    .spin{animation:dvspin 1s linear infinite}@keyframes dvspin{to{transform:rotate(360deg)}}

    .dp-empty{background:#fff;border:1px solid var(--line);border-radius:14px;padding:56px 20px;text-align:center;color:var(--muted);max-width:560px}
    .dv-toast{position:fixed;bottom:24px;right:24px;z-index:1200;padding:13px 18px;border-radius:11px;font-size:13.5px;font-weight:700;box-shadow:0 8px 30px rgba(0,0,0,.15);transform:translateY(12px);opacity:0;transition:all .3s;pointer-events:none;max-width:360px}
    .dv-toast.show{transform:translateY(0);opacity:1}.dv-toast.ok{background:var(--ink);color:#fff}.dv-toast.fail{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
    @media(max-width:980px){.dp-grid{grid-template-columns:1fr}.sum{position:static}}
  </style>
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main-content" style="margin-left:260px;min-height:100vh;background:#f6f8fb">
    <div class="mobile-bar">
      <button class="ham-btn" onclick="toggleSidebar()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
    </div>

    <div class="dp-wrap">
      <div class="dp-crumb"><?= htmlspecialchars($app_name) ?> / Vps / <b>Deploy</b></div>
      <div class="dp-h1">Deploy VPS Server</div>
      <a href="<?= BASE_URL ?>/servers.php" class="dp-back">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5"/><polyline points="12 19 5 12 12 5"/></svg>
        Back to Servers
      </a>

      <?php if ($no_table || !$packages): ?>
        <div class="dp-empty">
          <h3 style="color:#334155;margin-bottom:6px;font-size:17px"><?= $no_table ? 'Not set up yet' : 'No VPS plans available' ?></h3>
          <p><?= $no_table ? 'Run install-db.php and add packages in Admin → VPS Packages.' : 'Please check back soon.' ?></p>
        </div>
      <?php else: ?>
      <div class="dp-grid">
        <!-- ── Main ── -->
        <div>
          <!-- 1. Location -->
          <div class="step" id="step-loc">
            <div class="step-hd"><div class="step-n">1</div><div><div class="step-t">Server Location</div><div class="step-s">Choose the datacenter closest to your users</div></div></div>
            <div class="lbl-small">Available</div>
            <div class="loc-grid">
              <?php foreach ($locations as $L): ?>
              <button type="button" class="loc-card" data-loc="<?= htmlspecialchars($L['name'], ENT_QUOTES) ?>" onclick="pickLoc(this)">
                <div class="loc-l">
                  <?php if (!empty($L['flag'])): ?><img class="loc-flag" src="https://flagcdn.com/w40/<?= htmlspecialchars($L['flag']) ?>.png" onerror="this.outerHTML='<div class=&quot;loc-pin&quot;>📍</div>'"><?php else: ?><div class="loc-pin">📍</div><?php endif; ?>
                  <div><div class="loc-nm"><?= htmlspecialchars($L['name']) ?></div><div class="loc-sub"><?= $L['count'] ?> plan<?= $L['count']==1?'':'s' ?></div></div>
                </div>
                <div class="loc-tick">✓</div>
              </button>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- 2. Plan -->
          <div class="step locked" id="step-plan">
            <div class="step-hd"><div class="step-n">2</div><div><div class="step-t">Server Plan</div><div class="step-s" id="planSub">Select a location first</div></div></div>
            <div class="proc-tabs" id="procTabs"></div>
            <div class="plan-grid" id="planGrid"></div>
          </div>

          <!-- 3. Additional options -->
          <div class="step locked" id="step-opts">
            <div class="step-hd"><div class="step-n">3</div><div><div class="step-t">Additional Options</div><div class="step-s">Hostname &amp; SSH access</div></div></div>
            <div class="lbl-small">Hostname <span style="color:var(--muted);font-weight:500">(optional)</span></div>
            <input class="host-inp" id="host" placeholder="e.g. web-server-01" maxlength="60" style="margin-bottom:16px">
            <div class="lbl-small">SSH Public Keys <span style="color:var(--muted);font-weight:500">(optional — one per line, installed at boot)</span></div>
            <textarea class="host-inp" id="sshkeys" rows="3" placeholder="ssh-ed25519 AAAA... user@host&#10;ssh-rsa AAAA..." style="font-family:'JetBrains Mono',monospace;font-size:12.5px;resize:vertical"></textarea>
            <div style="font-size:11.5px;color:var(--muted);margin-top:6px">Paste your public key(s) to log in without a password. Leave blank to use the root password (emailed after deploy).</div>
          </div>
        </div>

        <!-- ── Order Summary rail ── -->
        <div class="sum">
          <div class="sum-h">Order Summary</div>
          <div id="sumRows"><div class="sum-empty">Select a location and plan…</div></div>
          <div class="sum-div"></div>
          <div class="cyc-lbl">Billing Cycle</div>
          <div class="cyc-seg" id="cycSeg"></div>
          <div class="sum-total"><span class="sum-total-l">Total</span><span class="sum-total-v" id="sumTotal">₹0</span></div>
          <div class="sum-billed" id="sumBilled">—</div>
          <div class="sum-wallet"><span style="color:#64748b">Wallet balance</span><b class="<?= $balance_raw < 1 ? 'wl-low':'' ?>">₹<?= $balance ?></b></div>
          <button class="btn-ink" id="deployBtn" disabled onclick="doDeploy()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
            Deploy Now
          </button>
          <div class="topup-note" id="topupNote" style="display:none"></div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="dv-toast" id="dvToast"></div>
<script>
var PKGS = <?= json_encode($packages, JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
var CYCLE_NAMES = <?= json_encode($cycle_names) ?>, CYCLE_LBL = <?= json_encode($cycle_lbl) ?>;
var BAL = <?= json_encode($balance_raw) ?>, CSRF='<?= $csrf ?>', BASE='<?= BASE_URL ?>';
var sel = { loc:null, pkg:null, cyc:null, proc:'all' };
var curPlans = [];
function fmt(n){ return '₹'+(Math.round(n)==n?Number(n).toLocaleString('en-IN'):Number(n).toFixed(2)); }
function esc(s){ return String(s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
function minCycle(p){ return p.cycles.reduce(function(a,b){return b.price<a.price?b:a;},p.cycles[0]); }
function spec(path,txt){ return '<li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'+path+'</svg>'+esc(txt)+'</li>'; }
function toast(m,t){ var e=document.getElementById('dvToast'); e.textContent=m; e.className='dv-toast '+t; setTimeout(function(){e.classList.add('show');},10); setTimeout(function(){e.classList.remove('show');},4500); }
function lock(id){ document.getElementById(id).classList.add('locked'); }
function unlock(id){ document.getElementById(id).classList.remove('locked'); }

function pickLoc(el){
  document.querySelectorAll('.loc-card').forEach(function(c){c.classList.remove('on');});
  el.classList.add('on'); sel.loc=el.getAttribute('data-loc'); sel.pkg=null; sel.cyc=null; sel.proc='all';
  curPlans = PKGS.filter(function(p){return p.loc===sel.loc;});
  document.getElementById('planSub').textContent=curPlans.length+' plan'+(curPlans.length==1?'':'s')+' available in '+sel.loc;

  // Processor filter pills — built from the distinct processors in this location
  var procs = []; curPlans.forEach(function(p){ if(p.proc && procs.indexOf(p.proc)===-1) procs.push(p.proc); });
  var tabs=document.getElementById('procTabs'); tabs.innerHTML='';
  if(procs.length){
    tabs.appendChild(makeProcTab('all','All', true));
    procs.forEach(function(pr){ tabs.appendChild(makeProcTab(pr, pr, false)); });
  }
  renderPlans('all');

  unlock('step-plan'); lock('step-opts'); document.getElementById('cycSeg').innerHTML='';
  updateSummary();
  document.getElementById('step-plan').scrollIntoView({behavior:'smooth',block:'nearest'});
}

function procColor(proc){ return /amd|epyc|ryzen/i.test(proc)?'#ED1C24':(/intel|xeon/i.test(proc)?'#0071C5':'#64748b'); }
function makeProcTab(val, label, on){
  var b=document.createElement('button'); b.type='button'; b.className='proc-tab'+(on?' on':''); b.setAttribute('data-proc',val);
  var mk = val==='all' ? '' : '<span class="mk" style="width:11px;height:11px;border-radius:3px;background:'+procColor(val)+'"></span>';
  b.innerHTML = mk + esc(label);
  b.onclick=function(){ pickProc(val, b); };
  return b;
}
function pickProc(proc, b){
  document.querySelectorAll('.proc-tab').forEach(function(x){x.classList.remove('on');});
  b.classList.add('on'); sel.proc=proc; renderPlans(proc);
}
function renderPlans(proc){
  var grid=document.getElementById('planGrid'); grid.innerHTML=''; sel.pkg=null;
  var list = (proc==='all') ? curPlans : curPlans.filter(function(p){return p.proc===proc;});
  list.forEach(function(p){
    var mc=minCycle(p);
    var card=document.createElement('button'); card.type='button'; card.className='plan-card';
    card.onclick=function(){ pickPlan(p,card); };
    var cpuIco='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/></svg>';
    card.innerHTML='<div class="plan-tick">✓</div><div class="plan-nm">'+esc(p.name)+'</div>'+
      (p.proc?'<div class="plan-proc"><span class="mk" style="background:'+procColor(p.proc)+'"></span>'+esc(p.proc)+'</div>':'')+
      '<ul class="plan-specs">'+
        '<li>'+cpuIco+p.vcpu+' vCPU<span class="ded-pill">Dedicated</span></li>'+
        spec('<rect x="2" y="7" width="20" height="10" rx="2"/><line x1="6" y1="11" x2="6" y2="13"/><line x1="10" y1="11" x2="10" y2="13"/>',p.ram+' GB RAM')+
        spec('<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/>',p.disk+' GB NVMe')+
        (p.bw>0?spec('<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',p.bw+' GB Bandwidth'):'')+
      '</ul>'+
      '<div class="plan-price">'+fmt(mc.price)+' <small>/ '+(CYCLE_LBL[mc.months]||mc.months+' mo')+'</small></div>';
    grid.appendChild(card);
  });
  if(!list.length){ grid.innerHTML='<div style="grid-column:1/-1;color:#94a3b8;font-size:13px;padding:8px 0">No plans for this processor.</div>'; }
  document.getElementById('cycSeg').innerHTML=''; lock('step-opts'); updateSummary();
}

function pickPlan(p,card){
  document.querySelectorAll('.plan-card').forEach(function(c){c.classList.remove('on');});
  card.classList.add('on'); sel.pkg=p;
  // build billing-cycle segmented control
  var seg=document.getElementById('cycSeg'); seg.innerHTML='';
  p.cycles.forEach(function(c){
    var b=document.createElement('button'); b.type='button'; b.className='cyc-btn'; b.textContent=(CYCLE_NAMES[c.months]||c.months+' mo');
    b.onclick=function(){ pickCyc(c,b); };
    seg.appendChild(b);
  });
  unlock('step-opts');
  var first=seg.querySelector('.cyc-btn'); if(first) first.click();
}

function pickCyc(c,b){ document.querySelectorAll('.cyc-btn').forEach(function(x){x.classList.remove('on');}); b.classList.add('on'); sel.cyc=c; updateSummary(); }

function updateSummary(){
  var el=document.getElementById('sumRows');
  if(!sel.pkg){ el.innerHTML='<div class="sum-empty">Select a location and plan…</div>'; document.getElementById('sumTotal').textContent='₹0'; document.getElementById('sumBilled').textContent='—'; setDeploy(false,0); return; }
  var p=sel.pkg, rows='';
  rows+=row('Plan',p.name);
  rows+=row('Location',sel.loc);
  if(p.os) rows+=row('Image',p.os);
  rows+=row('vCPU',p.vcpu+' Cores (Dedicated)');
  rows+=row('Memory',p.ram+' GB');
  rows+=row('Disk',p.disk+' GB NVMe');
  if(sel.cyc){ rows+=row('Base Price',fmt(sel.cyc.price/sel.cyc.months)+'/mo'); }
  el.innerHTML=rows;
  if(sel.cyc){
    document.getElementById('sumTotal').textContent=fmt(sel.cyc.price);
    document.getElementById('sumBilled').textContent = sel.cyc.months===1 ? 'Billed monthly' : ('Billed every '+sel.cyc.months+' months · '+fmt(sel.cyc.price/sel.cyc.months)+'/mo');
    setDeploy(true, sel.cyc.price);
  } else { document.getElementById('sumTotal').textContent='₹0'; document.getElementById('sumBilled').textContent='—'; setDeploy(false,0); }
}
function row(k,v){ return '<div class="sum-row"><span class="sum-k">'+esc(k)+'</span><span class="sum-v">'+esc(v)+'</span></div>'; }
function setDeploy(ready,price){
  var btn=document.getElementById('deployBtn'), note=document.getElementById('topupNote');
  if(ready && price>BAL){ btn.disabled=true; note.style.display='block'; note.textContent='Insufficient balance — top up '+fmt(price-BAL)+' more.'; }
  else { btn.disabled=!ready; note.style.display='none'; }
}

function doDeploy(){
  if(!sel.pkg||!sel.cyc) return;
  var host=document.getElementById('host').value.trim();
  var ssh=document.getElementById('sshkeys').value.trim();
  if(!confirm('Deploy "'+sel.pkg.name+'" in '+sel.loc+' for '+document.getElementById('sumTotal').textContent+'?\n\nCharged from your wallet now (prepaid).')) return;
  var btn=document.getElementById('deployBtn'); btn.disabled=true; var orig=btn.innerHTML;
  btn.innerHTML='<svg class="spin" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" width="15" height="15"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.86"/></svg> Deploying…';
  fetch(BASE+'/api/vps-package-order.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({package_id:sel.pkg.id, cycle_months:sel.cyc.months, hostname:host, ssh_keys:ssh, csrf:CSRF})
  }).then(function(r){return r.json();}).then(function(d){
    if(d.ok){ toast('✅ '+(d.message||'Server ordered!'),'ok'); setTimeout(function(){window.location.href=d.redirect||(BASE+'/servers.php');},1400); }
    else{ toast('⚠ '+(d.error||'Order failed'),'fail'); btn.disabled=false; btn.innerHTML=orig; }
  }).catch(function(){ toast('Network error. Try again.','fail'); btn.disabled=false; btn.innerHTML=orig; });
}
</script>
</body>
</html>
