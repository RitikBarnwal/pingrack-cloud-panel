<?php
/**
 * dedicated.php — Dedicated Servers (SparrowHost-style order flow).
 * Charges wallet + files a pending order for manual fulfilment. INR-only.
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
        $packages[] = ['id'=>$pid, 'name'=>$p['name'], 'desc'=>$p['description'] ?? '', 'cpu_label'=>$p['cpu_label'] ?? '',
            'vcpu'=>(int)$p['vcpu'], 'ram'=>(float)$p['ram_gb'], 'disk'=>(int)$p['disk_gb'], 'bw'=>(int)$p['bandwidth_gb'], 'cycles'=>$cyc[$pid]];
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
    :root{--ink:#0f172a;--line:#e6eaf0;--muted:#94a3b8;}
    .dp-wrap{padding:24px 30px 90px;max-width:1240px;margin:0 auto}
    .dp-crumb{font-size:13px;color:var(--muted);margin-bottom:14px}.dp-crumb b{color:#334155;font-weight:700}
    .dp-h1{font-size:24px;font-weight:900;color:var(--ink);letter-spacing:-.5px}
    .dp-sub{font-size:13.5px;color:var(--muted);margin-top:3px;margin-bottom:22px}
    .dp-grid{display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start}
    .step{background:#fff;border:1px solid var(--line);border-radius:16px;padding:22px 24px;margin-bottom:18px}
    .step.locked{opacity:.5;pointer-events:none}
    .step-hd{display:flex;align-items:flex-start;gap:13px;margin-bottom:18px}
    .step-n{width:28px;height:28px;border-radius:50%;background:var(--ink);color:#fff;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .step-t{font-size:16px;font-weight:800;color:var(--ink)}.step-s{font-size:13px;color:var(--muted);margin-top:1px}
    .plan-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px}
    .plan-card{position:relative;padding:18px;background:#fff;border:1.5px solid var(--line);border-radius:13px;cursor:pointer;transition:all .13s}
    .plan-card:hover{border-color:#cbd5e1;box-shadow:0 2px 10px rgba(15,23,42,.05)}
    .plan-card.on{border-color:var(--ink);box-shadow:0 0 0 1px var(--ink)}
    .plan-card.on .plan-tick{display:flex}
    .plan-tick{position:absolute;top:14px;right:14px;width:20px;height:20px;border-radius:50%;background:var(--ink);color:#fff;display:none;align-items:center;justify-content:center;font-size:12px}
    .plan-nm{font-size:15px;font-weight:800;color:var(--ink);padding-right:24px}
    .plan-cpu{font-size:12px;color:#64748b;font-weight:700;margin-top:3px}
    .plan-specs{list-style:none;padding:0;margin:14px 0 0;display:flex;flex-direction:column;gap:9px}
    .plan-specs li{display:flex;align-items:center;gap:9px;font-size:13.5px;color:#475569}.plan-specs svg{width:15px;height:15px;color:#64748b;flex-shrink:0}
    .plan-price{margin-top:15px;padding-top:14px;border-top:1px solid #f1f5f9;font-size:20px;font-weight:900;color:var(--ink)}
    .plan-price small{font-size:12px;font-weight:600;color:var(--muted)}
    .sum{position:sticky;top:76px;background:#fff;border:1px solid var(--line);border-radius:16px;padding:22px 22px 24px}
    .sum-h{font-size:17px;font-weight:900;color:var(--ink);margin-bottom:18px}
    .sum-row{display:flex;justify-content:space-between;gap:10px;padding:7px 0;font-size:13px}.sum-k{color:#64748b}.sum-v{color:var(--ink);font-weight:700;text-align:right}
    .sum-empty{color:#cbd5e1;font-size:13px;font-style:italic;padding:6px 0}
    .sum-div{height:1px;background:var(--line);margin:14px 0}
    .cyc-lbl{font-size:12px;font-weight:700;color:#64748b;margin-bottom:8px}
    .cyc-seg{display:flex;background:#f1f5f9;border-radius:10px;padding:4px;gap:3px}
    .cyc-btn{flex:1;padding:8px 6px;border:none;background:transparent;border-radius:7px;font-size:12.5px;font-weight:700;color:#64748b;cursor:pointer;font-family:inherit;white-space:nowrap}
    .cyc-btn.on{background:#fff;color:var(--ink);box-shadow:0 1px 3px rgba(15,23,42,.12)}
    .sum-total{display:flex;justify-content:space-between;align-items:baseline;margin-top:16px}
    .sum-total-l{font-size:17px;font-weight:900;color:var(--ink)}.sum-total-v{font-size:24px;font-weight:900;color:var(--ink);letter-spacing:-.8px}
    .sum-billed{font-size:12px;color:var(--muted);margin-top:3px}
    .sum-wallet{display:flex;justify-content:space-between;align-items:center;padding:10px 13px;background:#f8fafc;border:1px solid var(--line);border-radius:9px;margin:16px 0 12px;font-size:12.5px}
    .sum-wallet b{font-weight:800;color:var(--ink);font-family:'JetBrains Mono',monospace}.wl-low{color:#dc2626!important}
    .btn-ink{width:100%;padding:14px;background:var(--ink);color:#fff;border:none;border-radius:11px;font-size:14.5px;font-weight:800;cursor:pointer;transition:all .16s;display:flex;align-items:center;justify-content:center;gap:8px}
    .btn-ink:hover:not(:disabled){background:#1e293b}.btn-ink:disabled{opacity:.4;cursor:not-allowed}
    .topup-note{font-size:11.5px;color:#dc2626;text-align:center;margin-top:9px;font-weight:600}
    .spin{animation:dvspin 1s linear infinite}@keyframes dvspin{to{transform:rotate(360deg)}}
    .sec-h{font-size:17px;font-weight:900;color:var(--ink);margin:32px 0 12px}
    .ord-tbl{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden}
    .ord-tbl th{text-align:left;padding:12px 16px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);background:#f8fafc;border-bottom:1px solid var(--line)}
    .ord-tbl td{padding:13px 16px;border-bottom:1px solid #f1f5f9}
    .st{display:inline-block;padding:3px 9px;border-radius:99px;font-size:11px;font-weight:700}
    .st-pending{background:#fffbeb;color:#d97706}.st-active{background:#f0fdf4;color:#16a34a}.st-refunded{background:#f1f5f9;color:#64748b}
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
      <div class="dp-crumb"><?= htmlspecialchars($app_name) ?> / <b>Dedicated</b></div>
      <div class="dp-h1">Dedicated Servers</div>
      <div class="dp-sub">Bare-metal power. Order and our team provisions &amp; hands over your server.</div>

      <?php if ($no_table || (!$packages && !$my_orders)): ?>
        <div class="dp-empty"><h3 style="color:#334155;margin-bottom:6px;font-size:17px">No dedicated servers available</h3>
        <p><?= $no_table ? 'Run install-db.php and add dedicated packages in Admin → VPS Packages.' : 'Please check back soon.' ?></p></div>
      <?php else: ?>
      <div class="dp-grid">
        <div>
          <?php if ($packages): ?>
          <div class="step" id="step-plan">
            <div class="step-hd"><div class="step-n">1</div><div><div class="step-t">Select a Server</div><div class="step-s">Bare-metal configurations, full root access</div></div></div>
            <div class="plan-grid" id="planGrid"></div>
          </div>
          <?php endif; ?>

          <?php if ($my_orders): ?>
          <div class="sec-h">My Dedicated Orders</div>
          <table class="ord-tbl">
            <thead><tr><th>Package</th><th>Cycle</th><th>Amount</th><th>Status</th><th>Expires</th><th>Placed</th></tr></thead>
            <tbody>
            <?php foreach ($my_orders as $o): $st=$o['status']; $stc=$st==='active'?'st-active':($st==='pending'?'st-pending':'st-refunded'); ?>
            <tr>
              <td style="font-weight:700"><?= htmlspecialchars($o['pkg_name']) ?></td>
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
        <div class="sum">
          <div class="sum-h">Order Summary</div>
          <div id="sumRows"><div class="sum-empty">Select a server…</div></div>
          <div class="sum-div"></div>
          <div class="cyc-lbl">Billing Cycle</div>
          <div class="cyc-seg" id="cycSeg"></div>
          <div class="sum-total"><span class="sum-total-l">Total</span><span class="sum-total-v" id="sumTotal">₹0</span></div>
          <div class="sum-billed" id="sumBilled">—</div>
          <div class="sum-wallet"><span style="color:#64748b">Wallet balance</span><b class="<?= $balance_raw < 1 ? 'wl-low':'' ?>">₹<?= $balance ?></b></div>
          <button class="btn-ink" id="deployBtn" disabled onclick="doOrder()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
            Place Order
          </button>
          <div class="topup-note" id="topupNote" style="display:none"></div>
        </div>
        <?php endif; ?>
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
var sel = { pkg:null, cyc:null };
function fmt(n){ return '₹'+(Math.round(n)==n?Number(n).toLocaleString('en-IN'):Number(n).toFixed(2)); }
function esc(s){ return String(s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
function minCycle(p){ return p.cycles.reduce(function(a,b){return b.price<a.price?b:a;},p.cycles[0]); }
function spec(path,txt){ return '<li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'+path+'</svg>'+esc(txt)+'</li>'; }
function toast(m,t){ var e=document.getElementById('dvToast'); e.textContent=m; e.className='dv-toast '+t; setTimeout(function(){e.classList.add('show');},10); setTimeout(function(){e.classList.remove('show');},5000); }

(function(){
  var grid=document.getElementById('planGrid'); if(!grid) return;
  PKGS.forEach(function(p){
    var mc=minCycle(p);
    var card=document.createElement('button'); card.type='button'; card.className='plan-card';
    card.onclick=function(){ pickPlan(p,card); };
    card.innerHTML='<div class="plan-tick">✓</div><div class="plan-nm">'+esc(p.name)+'</div>'+
      (p.cpu_label?'<div class="plan-cpu">'+esc(p.cpu_label)+'</div>':'')+
      '<ul class="plan-specs">'+
        spec('<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/>',p.vcpu+' CPU Cores')+
        spec('<rect x="2" y="7" width="20" height="10" rx="2"/><line x1="6" y1="11" x2="6" y2="13"/>',p.ram+' GB RAM')+
        spec('<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/>',p.disk+' GB Storage')+
        (p.bw>0?spec('<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',p.bw+' GB Bandwidth'):'')+
      '</ul>'+
      '<div class="plan-price">'+fmt(mc.price)+' <small>/ '+(CYCLE_LBL[mc.months]||mc.months+' mo')+'</small></div>';
    grid.appendChild(card);
  });
})();

function pickPlan(p,card){
  document.querySelectorAll('.plan-card').forEach(function(c){c.classList.remove('on');});
  card.classList.add('on'); sel.pkg=p;
  var seg=document.getElementById('cycSeg'); seg.innerHTML='';
  p.cycles.forEach(function(c){
    var b=document.createElement('button'); b.type='button'; b.className='cyc-btn'; b.textContent=(CYCLE_NAMES[c.months]||c.months+' mo');
    b.onclick=function(){ pickCyc(c,b); }; seg.appendChild(b);
  });
  var first=seg.querySelector('.cyc-btn'); if(first) first.click();
}
function pickCyc(c,b){ document.querySelectorAll('.cyc-btn').forEach(function(x){x.classList.remove('on');}); b.classList.add('on'); sel.cyc=c; updateSummary(); }
function updateSummary(){
  var el=document.getElementById('sumRows'); if(!sel.pkg){ el.innerHTML='<div class="sum-empty">Select a server…</div>'; return; }
  var p=sel.pkg, rows=row('Server',p.name);
  if(p.cpu_label) rows+=row('CPU',p.cpu_label);
  rows+=row('Cores',p.vcpu)+row('RAM',p.ram+' GB')+row('Storage',p.disk+' GB');
  if(sel.cyc) rows+=row('Base Price',fmt(sel.cyc.price/sel.cyc.months)+'/mo');
  el.innerHTML=rows;
  var btn=document.getElementById('deployBtn'), note=document.getElementById('topupNote');
  if(sel.cyc){
    document.getElementById('sumTotal').textContent=fmt(sel.cyc.price);
    document.getElementById('sumBilled').textContent = sel.cyc.months===1?'Billed monthly':('Billed every '+sel.cyc.months+' months');
    if(sel.cyc.price>BAL){ btn.disabled=true; note.style.display='block'; note.textContent='Insufficient balance — top up '+fmt(sel.cyc.price-BAL)+' more.'; }
    else { btn.disabled=false; note.style.display='none'; }
  }
}
function row(k,v){ return '<div class="sum-row"><span class="sum-k">'+esc(k)+'</span><span class="sum-v">'+esc(v)+'</span></div>'; }
function doOrder(){
  if(!sel.pkg||!sel.cyc) return;
  if(!confirm('Order "'+sel.pkg.name+'" for '+document.getElementById('sumTotal').textContent+'?\n\nCharged now. Our team provisions and hands over the server.')) return;
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
