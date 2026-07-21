<?php
/**
 * dedicated.php — Dedicated Servers (polished order flow).
 * Dedicated packages have no panel: ordering charges the wallet and files a
 * pending order for the team to fulfil manually. INR-only, prepaid cycles.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$user     = current_user();
$uid      = (int)$user['id'];
$currency = 'INR';
$curr_sym = '₹';
$app_name = APP_NAME;
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$uname    = htmlspecialchars($user['username']);
$balance  = number_format((float)$user['wallet_balance'], 2);
$balance_raw = (float)$user['wallet_balance'];
$csrf     = csrf_token();

$cycle_names = [1=>'Monthly',3=>'Quarterly',6=>'Semi-Annual',12=>'Annual',24=>'Biennial',36=>'Triennial'];
$cycle_lbl   = [1=>'1 mo',3=>'3 mo',6=>'6 mo',12=>'12 mo',24=>'24 mo',36=>'36 mo'];

$packages = [];
$my_orders = [];
$no_table = false;
try {
    $rows = db()->query("SELECT * FROM vps_packages WHERE is_active=1 AND ptype='dedicated' ORDER BY sort_order, price_inr")->fetchAll();
    $cyc = [];
    foreach (db()->query("SELECT * FROM package_cycles WHERE is_enabled=1 ORDER BY months")->fetchAll() as $c) {
        $cyc[(int)$c['package_id']][] = ['months'=>(int)$c['months'], 'price'=>(float)$c['price_inr']];
    }
    foreach ($rows as $p) {
        $pid = (int)$p['id'];
        if (empty($cyc[$pid])) continue;
        $packages[] = [
            'id'=>$pid, 'name'=>$p['name'], 'desc'=>$p['description'] ?? '',
            'cpu_label'=>$p['cpu_label'] ?? '',
            'vcpu'=>(int)$p['vcpu'], 'ram'=>(float)$p['ram_gb'], 'disk'=>(int)$p['disk_gb'], 'bw'=>(int)$p['bandwidth_gb'],
            'cycles'=>$cyc[$pid],
        ];
    }
    $mo = db()->prepare("SELECT o.*, p.name AS pkg_name FROM vps_package_orders o JOIN vps_packages p ON p.id=o.package_id WHERE o.user_id=? AND p.ptype='dedicated' ORDER BY o.created_at DESC LIMIT 20");
    $mo->execute([$uid]); $my_orders = $mo->fetchAll();
} catch (Throwable $e) { $no_table = true; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Dedicated Servers — <?= $app_name ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    :root{--dv:#0891b2;--dv2:#0e7490;}
    .dv-shell{display:grid;grid-template-columns:1fr 320px;min-height:calc(100vh - 56px)}
    .dv-main{padding:26px 30px 90px;max-width:900px}
    .dv-rail{position:sticky;top:56px;align-self:start;background:#fff;border-left:1.5px solid #e8edf3;min-height:calc(100vh - 56px)}
    .dv-head{margin-bottom:24px}
    .dv-title{font-size:22px;font-weight:900;color:#0f172a;letter-spacing:-.5px}
    .dv-sub{font-size:13px;color:#94a3b8;margin-top:3px}
    .dv-sec{margin-bottom:26px}
    .dv-sec.locked{opacity:.45;pointer-events:none;filter:grayscale(.4)}
    .dv-sec-hd{display:flex;align-items:center;gap:10px;margin-bottom:14px}
    .dv-num{width:24px;height:24px;border-radius:7px;background:var(--dv);color:#fff;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .dv-sec.locked .dv-num{background:#cbd5e1}
    .dv-sec-title{font-size:15px;font-weight:800;color:#0f172a}
    .plan-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px}
    .plan-card{position:relative;padding:16px;background:#fff;border:1.5px solid #e8edf3;border-radius:13px;cursor:pointer;transition:all .14s}
    .plan-card:hover{border-color:#67e8f9;box-shadow:0 3px 14px rgba(8,145,178,.09)}
    .plan-card.on{border-color:var(--dv);background:#f0fdff;box-shadow:0 3px 16px rgba(8,145,178,.16)}
    .plan-card.on::after{content:'✓';position:absolute;top:12px;right:12px;width:20px;height:20px;border-radius:50%;background:var(--dv);color:#fff;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center}
    .plan-name{font-size:15px;font-weight:800;color:#0f172a;padding-right:22px}
    .plan-cpu{font-size:12px;color:var(--dv);font-weight:700;margin-top:2px}
    .plan-desc{font-size:12px;color:#94a3b8;margin-top:2px}
    .plan-specs{display:flex;flex-direction:column;gap:7px;margin:13px 0}
    .plan-specs li{list-style:none;display:flex;align-items:center;gap:8px;font-size:13px;color:#475569}
    .plan-specs svg{width:14px;height:14px;color:var(--dv);flex-shrink:0}
    .plan-from{padding-top:11px;border-top:1px solid #f1f5f9;display:flex;align-items:baseline;gap:5px}
    .plan-price{font-size:19px;font-weight:900;color:#0f172a}.plan-per{font-size:11.5px;color:#94a3b8;font-weight:600}
    .cyc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}
    .cyc-opt{padding:13px 15px;background:#fff;border:1.5px solid #e8edf3;border-radius:11px;cursor:pointer;transition:all .14s}
    .cyc-opt:hover{border-color:#67e8f9}.cyc-opt.on{border-color:var(--dv);background:#f0fdff}
    .cyc-opt-top{display:flex;align-items:center;justify-content:space-between}
    .cyc-name{font-size:13px;font-weight:800;color:#1e293b}.cyc-save{font-size:10px;font-weight:800;color:#15803d;background:#dcfce7;padding:2px 7px;border-radius:99px}
    .cyc-price{font-size:16px;font-weight:900;color:#0f172a;margin-top:6px}.cyc-eq{font-size:11px;color:#94a3b8;margin-top:1px}
    .rail-body{padding:20px;display:flex;flex-direction:column}
    .rail-sec{padding-bottom:15px;margin-bottom:15px;border-bottom:1px solid #f1f5f9}
    .rail-lbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.9px;color:#94a3b8;margin-bottom:9px}
    .rail-row{display:flex;justify-content:space-between;gap:8px;padding:3px 0;font-size:12.5px}
    .rail-k{color:#64748b}.rail-v{color:#1e293b;font-weight:700;text-align:right}.rail-empty{font-size:12.5px;color:#cbd5e1;font-style:italic}
    .price-big{font-size:28px;font-weight:900;color:#0f172a;letter-spacing:-1.3px;line-height:1}.price-unit{font-size:11.5px;color:#94a3b8;margin-top:3px}
    .walletbar{display:flex;justify-content:space-between;align-items:center;padding:10px 13px;background:#f8fafc;border:1.5px solid #e8edf3;border-radius:9px;margin-bottom:12px}
    .walletlbl{font-size:12.5px;color:#64748b}.walletval{font-size:13px;font-weight:800;color:#1e293b;font-family:'JetBrains Mono',monospace}.wallet-low{color:#dc2626!important}
    .deploy-btn{width:100%;padding:13px;background:linear-gradient(135deg,var(--dv),var(--dv2));color:#fff;border:none;border-radius:11px;font-size:14.5px;font-weight:800;cursor:pointer;transition:all .18s;display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 4px 18px rgba(8,145,178,.3)}
    .deploy-btn:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 6px 24px rgba(8,145,178,.42)}.deploy-btn:disabled{opacity:.4;cursor:not-allowed;box-shadow:none}
    .terms{font-size:10.5px;color:#94a3b8;text-align:center;margin-top:9px;line-height:1.5}.topup-note{font-size:11.5px;color:#dc2626;text-align:center;margin-top:9px;font-weight:600}
    .spin{animation:dvspin 1s linear infinite}@keyframes dvspin{to{transform:rotate(360deg)}}
    .sec-h{font-size:16px;font-weight:800;color:#0f172a;margin:34px 0 12px}
    .ord-tbl{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid #e8edf3;border-radius:12px;overflow:hidden}
    .ord-tbl th{text-align:left;padding:10px 14px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;background:#f8fafc;border-bottom:1px solid #e8edf3}
    .ord-tbl td{padding:11px 14px;border-bottom:1px solid #f1f5f9}
    .st{display:inline-block;padding:3px 9px;border-radius:99px;font-size:11px;font-weight:700}
    .st-pending{background:#fffbeb;color:#d97706}.st-active{background:#f0fdf4;color:#16a34a}.st-refunded{background:#f1f5f9;color:#64748b}
    .dv-empty{background:#fff;border:1.5px solid #e8edf3;border-radius:14px;padding:52px 20px;text-align:center;color:#94a3b8;max-width:520px}
    .dv-toast{position:fixed;bottom:24px;right:24px;z-index:1200;padding:13px 18px;border-radius:11px;font-size:13.5px;font-weight:700;box-shadow:0 8px 30px rgba(0,0,0,.15);transform:translateY(12px);opacity:0;transition:all .3s;pointer-events:none;max-width:360px}
    .dv-toast.show{transform:translateY(0);opacity:1}.dv-toast.ok{background:#0f172a;color:#fff}.dv-toast.fail{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
    @media(max-width:900px){.dv-shell{display:block}.dv-main{max-width:100%;padding:18px 16px 70px}.dv-rail{position:static;border-left:none;border-top:1.5px solid #e8edf3;min-height:0}}
  </style>
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main-content" style="margin-left:260px;min-height:100vh;background:#f8fafc">
    <div class="mobile-bar">
      <button class="ham-btn" onclick="toggleSidebar()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
    </div>

    <?php if ($no_table || (!$packages && !$my_orders)): ?>
      <div style="padding:40px 30px"><div class="dv-empty">
        <h3 style="color:#334155;margin-bottom:6px;font-size:17px">No dedicated servers available</h3>
        <p><?= $no_table ? 'Run install-db.php and add dedicated packages in Admin → VPS Packages.' : 'Please check back soon.' ?></p>
      </div></div>
    <?php else: ?>
    <div class="dv-shell">
      <div class="dv-main">
        <div class="dv-head">
          <div class="dv-title">Dedicated Servers</div>
          <div class="dv-sub">Bare-metal power. Order and our team provisions &amp; hands over your server.</div>
        </div>

        <?php if ($packages): ?>
        <div class="dv-sec" id="sec-plan">
          <div class="dv-sec-hd"><div class="dv-num">1</div><div class="dv-sec-title">Select a server</div></div>
          <div class="plan-grid" id="planGrid"></div>
        </div>
        <div class="dv-sec locked" id="sec-cyc">
          <div class="dv-sec-hd"><div class="dv-num">2</div><div class="dv-sec-title">Billing cycle</div></div>
          <div class="cyc-grid" id="cycGrid"></div>
        </div>
        <?php endif; ?>

        <?php if ($my_orders): ?>
        <div class="sec-h">My Dedicated Orders</div>
        <table class="ord-tbl">
          <thead><tr><th>Package</th><th>Cycle</th><th>Amount</th><th>Status</th><th>Expires</th><th>Placed</th></tr></thead>
          <tbody>
          <?php foreach ($my_orders as $o): $st=$o['status']; $stc=$st==='active'?'st-active':($st==='pending'?'st-pending':'st-refunded'); ?>
          <tr>
            <td style="font-weight:600"><?= htmlspecialchars($o['pkg_name']) ?></td>
            <td><?= (int)$o['cycle_months'] ?> mo</td>
            <td style="font-family:var(--mono)">₹<?= number_format((float)$o['amount'],2) ?></td>
            <td><span class="st <?= $stc ?>"><?= $st==='pending'?'Processing':ucfirst($st) ?></span></td>
            <td><?= $o['expires_at'] ? date('d M Y', strtotime($o['expires_at'])) : '—' ?></td>
            <td><?= date('d M Y', strtotime($o['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

      <?php if ($packages): ?>
      <div class="dv-rail">
        <div class="rail-body">
          <div class="rail-sec">
            <div class="rail-lbl">Order Summary</div>
            <div id="sumRows"><div class="rail-empty">Select a server…</div></div>
          </div>
          <div class="rail-sec">
            <div class="rail-lbl">Total (prepaid)</div>
            <div class="price-big" id="sumPrice">₹0</div><div class="price-unit" id="sumPer">—</div>
          </div>
          <div class="rail-sec" style="border:none;margin:0;padding:0">
            <div class="walletbar"><span class="walletlbl">Wallet balance</span><span class="walletval <?= $balance_raw < 1 ? 'wallet-low':'' ?>">₹<?= $balance ?></span></div>
            <button class="deploy-btn" id="deployBtn" disabled onclick="doOrder()">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="16" height="16"><path d="M20 6L9 17l-5-5"/></svg>
              Place Order
            </button>
            <div class="terms">Charged now · our team sets it up and updates you</div>
            <div class="topup-note" id="topupNote" style="display:none"></div>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="dv-toast" id="dvToast"></div>
<script>
var PKGS = <?= json_encode($packages, JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
var CYCLE_NAMES = <?= json_encode($cycle_names) ?>, CYCLE_LBL = <?= json_encode($cycle_lbl) ?>;
var BAL = <?= json_encode($balance_raw) ?>, CSRF='<?= $csrf ?>', BASE='<?= BASE_URL ?>';
var sel = { pkg:null, cyc:null };
function fmt(n){ return '₹'+(Math.round(n)==n?Number(n).toLocaleString('en-IN'):Number(n).toFixed(2)); }
function minCycle(p){ return p.cycles.reduce(function(a,b){return b.price<a.price?b:a;},p.cycles[0]); }
function esc(s){ return String(s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
function spec(path,txt){ return '<li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'+path+'</svg>'+esc(txt)+'</li>'; }
function toast(m,t){ var e=document.getElementById('dvToast'); e.textContent=m; e.className='dv-toast '+t; setTimeout(function(){e.classList.add('show');},10); setTimeout(function(){e.classList.remove('show');},5000); }

(function renderPlans(){
  var grid=document.getElementById('planGrid'); if(!grid) return;
  PKGS.forEach(function(p){
    var mc=minCycle(p);
    var card=document.createElement('button'); card.type='button'; card.className='plan-card';
    card.onclick=function(){ pickPlan(p,card); };
    card.innerHTML='<div class="plan-name">'+esc(p.name)+'</div>'+
      (p.cpu_label?'<div class="plan-cpu">'+esc(p.cpu_label)+'</div>':'')+
      '<div class="plan-desc">'+esc(p.desc||'')+'</div>'+
      '<ul class="plan-specs">'+
        spec('<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/>',p.vcpu+' CPU Cores')+
        spec('<rect x="2" y="7" width="20" height="10" rx="2"/><line x1="6" y1="11" x2="6" y2="13"/>',p.ram+' GB RAM')+
        spec('<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/>',p.disk+' GB Storage')+
        (p.bw>0?spec('<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',p.bw+' GB Bandwidth'):'')+
      '</ul>'+
      '<div class="plan-from"><span class="plan-price">'+fmt(mc.price)+'</span><span class="plan-per">/ '+(CYCLE_LBL[mc.months]||mc.months+' mo')+'</span></div>';
    grid.appendChild(card);
  });
})();

function pickPlan(p,card){
  document.querySelectorAll('.plan-card').forEach(function(c){c.classList.remove('on');});
  card.classList.add('on'); sel.pkg=p; sel.cyc=null;
  var grid=document.getElementById('cycGrid'); grid.innerHTML='';
  var base=p.cycles.find(function(c){return c.months===1;})||minCycle(p);
  p.cycles.forEach(function(c){
    var perMo=c.price/c.months, save=base?Math.round((1-perMo/(base.price/base.months))*100):0;
    var o=document.createElement('button'); o.type='button'; o.className='cyc-opt';
    o.onclick=function(){ pickCyc(c,o); };
    o.innerHTML='<div class="cyc-opt-top"><span class="cyc-name">'+(CYCLE_NAMES[c.months]||c.months+' mo')+'</span>'+(save>0?'<span class="cyc-save">Save '+save+'%</span>':'')+'</div>'+
      '<div class="cyc-price">'+fmt(c.price)+'</div><div class="cyc-eq">'+fmt(perMo)+'/mo · '+c.months+' month'+(c.months>1?'s':'')+'</div>';
    grid.appendChild(o);
  });
  document.getElementById('sec-cyc').classList.remove('locked');
  var first=grid.querySelector('.cyc-opt'); if(first) first.click();
  document.getElementById('sec-cyc').scrollIntoView({behavior:'smooth',block:'nearest'});
}
function pickCyc(c,o){ document.querySelectorAll('.cyc-opt').forEach(function(x){x.classList.remove('on');}); o.classList.add('on'); sel.cyc=c; updateSummary(); }
function updateSummary(){
  var el=document.getElementById('sumRows');
  if(!sel.pkg){ el.innerHTML='<div class="rail-empty">Select a server…</div>'; return; }
  var p=sel.pkg, rows='';
  rows+=row('Server',p.name);
  if(p.cpu_label) rows+=row('CPU',p.cpu_label);
  rows+=row('Cores',p.vcpu)+row('RAM',p.ram+' GB')+row('Storage',p.disk+' GB');
  if(p.bw>0) rows+=row('Bandwidth',p.bw+' GB');
  if(sel.cyc) rows+=row('Cycle',(CYCLE_NAMES[sel.cyc.months]||sel.cyc.months+' mo'));
  el.innerHTML=rows;
  var btn=document.getElementById('deployBtn'), note=document.getElementById('topupNote');
  if(sel.cyc){
    document.getElementById('sumPrice').textContent=fmt(sel.cyc.price);
    document.getElementById('sumPer').textContent='for '+sel.cyc.months+' month'+(sel.cyc.months>1?'s':'')+' · '+fmt(sel.cyc.price/sel.cyc.months)+'/mo';
    if(sel.cyc.price>BAL){ btn.disabled=true; note.style.display='block'; note.textContent='Insufficient balance — top up '+fmt(sel.cyc.price-BAL)+' more.'; }
    else { btn.disabled=false; note.style.display='none'; }
  }
}
function row(k,v){ return '<div class="rail-row"><span class="rail-k">'+esc(k)+'</span><span class="rail-v">'+esc(v)+'</span></div>'; }
function doOrder(){
  if(!sel.pkg||!sel.cyc) return;
  var priceTxt=document.getElementById('sumPrice').textContent;
  if(!confirm('Order "'+sel.pkg.name+'" for '+priceTxt+'?\n\nCharged now. Our team will provision and hand over the server.')) return;
  var btn=document.getElementById('deployBtn'); btn.disabled=true; var orig=btn.innerHTML;
  btn.innerHTML='<svg class="spin" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" width="15" height="15"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.86"/></svg> Placing…';
  fetch(BASE+'/api/vps-package-order.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({package_id:sel.pkg.id, cycle_months:sel.cyc.months, csrf:CSRF})
  }).then(function(r){return r.json();}).then(function(d){
    if(d.ok){ toast('✅ '+(d.message||'Order placed!'),'ok'); setTimeout(function(){window.location.reload();},1600); }
    else{ toast('⚠ '+(d.error||'Order failed'),'fail'); btn.disabled=false; btn.innerHTML=orig; }
  }).catch(function(){ toast('Network error. Try again.','fail'); btn.disabled=false; btn.innerHTML=orig; });
}
</script>
</body>
</html>
