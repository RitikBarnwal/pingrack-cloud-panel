<?php
/**
 * dedicated.php — customer Dedicated Server catalog.
 * Dedicated packages have no panel: ordering charges the wallet and creates
 * a pending order that admin fulfils manually.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$user     = current_user();
$uid      = (int)$user['id'];
$currency = strtoupper($user['currency'] ?? 'INR');
$curr_sym = user_currency_symbol($currency);
$app_name = APP_NAME;
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$uname    = htmlspecialchars($user['username']);
$balance  = number_format((float)$user['wallet_balance'], 2);
$csrf     = csrf_token();

$packages = [];
$cycles   = [];
$my_orders = [];
$no_table = false;
try {
    $packages = db()->query("SELECT * FROM vps_packages WHERE is_active=1 AND ptype='dedicated' ORDER BY sort_order, price_inr")->fetchAll();
    foreach (db()->query("SELECT * FROM package_cycles WHERE is_enabled=1 ORDER BY months")->fetchAll() as $c) {
        $cycles[(int)$c['package_id']][] = $c;
    }
    $mo = db()->prepare(
        "SELECT o.*, p.name AS pkg_name FROM vps_package_orders o
         JOIN vps_packages p ON p.id=o.package_id
         WHERE o.user_id=? AND p.ptype='dedicated' ORDER BY o.created_at DESC LIMIT 20"
    );
    $mo->execute([$uid]);
    $my_orders = $mo->fetchAll();
} catch (Throwable $e) {
    $no_table = true;
}
$cycle_names = [1=>'1 month',3=>'3 months',6=>'6 months',12=>'12 months',24=>'24 months',36=>'36 months'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Dedicated Servers — <?= $app_name ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    .pk-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}
    .pk-card{background:white;border:1.5px solid var(--border);border-radius:14px;padding:20px;display:flex;flex-direction:column;transition:all .16s}
    .pk-card:hover{border-color:var(--primary);box-shadow:0 8px 24px rgba(15,23,42,.08);transform:translateY(-2px)}
    .pk-name{font-size:16px;font-weight:800;color:var(--gray-900);letter-spacing:-.3px}
    .pk-cpu{font-size:12px;color:var(--primary);font-weight:600;margin-top:3px}
    .pk-desc{font-size:12.5px;color:var(--gray-500);margin-top:4px;min-height:18px}
    .pk-specs{list-style:none;padding:0;margin:16px 0;display:flex;flex-direction:column;gap:9px}
    .pk-specs li{display:flex;align-items:center;gap:9px;font-size:13.5px;color:var(--gray-700)}
    .pk-specs svg{width:15px;height:15px;color:var(--primary);flex-shrink:0}
    .pk-price{margin-top:auto;padding-top:14px;border-top:1px solid var(--border)}
    .pk-price-val{font-size:24px;font-weight:900;color:var(--gray-900);letter-spacing:-1px}
    .pk-price-cyc{font-size:12.5px;color:var(--gray-400);font-weight:600}
    .pk-order{margin-top:14px;width:100%;display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:11px;border-radius:10px;font-size:14px;font-weight:700;background:var(--primary);color:#fff;border:none;cursor:pointer;font-family:var(--font);transition:all .15s}
    .pk-order:hover{background:var(--primary-hover)}
    .pk-order:disabled{opacity:.6;cursor:not-allowed}
    .pk-empty{background:white;border:1.5px solid var(--border);border-radius:14px;padding:48px 20px;text-align:center;color:var(--gray-500)}
    .ord-tbl{width:100%;border-collapse:collapse;font-size:13px;background:white;border:1px solid var(--border);border-radius:12px;overflow:hidden}
    .ord-tbl th{text-align:left;padding:10px 14px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--gray-400);background:var(--gray-50);border-bottom:1px solid var(--border)}
    .ord-tbl td{padding:11px 14px;border-bottom:1px solid var(--gray-100)}
    .st{display:inline-block;padding:3px 9px;border-radius:99px;font-size:11px;font-weight:700}
    .st-pending{background:#fffbeb;color:#d97706}.st-active{background:#f0fdf4;color:#16a34a}.st-refunded{background:#f1f5f9;color:#64748b}
    .pk-toast{position:fixed;bottom:24px;right:24px;z-index:1200;padding:13px 18px;border-radius:11px;font-size:13.5px;font-weight:700;box-shadow:0 8px 30px rgba(0,0,0,.15);transform:translateY(12px);opacity:0;transition:all .3s;pointer-events:none;max-width:360px}
    .pk-toast.show{transform:translateY(0);opacity:1}
    .pk-toast.ok{background:#0f172a;color:#fff}.pk-toast.fail{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
    .sec-h{font-size:15px;font-weight:800;color:var(--gray-900);margin:26px 0 12px}
  </style>
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main-content" style="margin-left:260px;min-height:100vh;background:var(--gray-50)">

    <div class="mobile-bar">
      <button class="ham-btn" onclick="toggleSidebar()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
    </div>

    <div class="topbar">
      <span class="topbar-title">Dedicated Servers</span>
      <div style="display:flex;gap:8px;align-items:center;margin-left:auto">
        <a href="<?= BASE_URL ?>/billing.php" class="btn btn-secondary btn-sm">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
          <?= $curr_sym . $balance ?>
        </a>
      </div>
    </div>

    <div style="padding:24px">
      <?php if ($no_table): ?>
        <div class="pk-empty"><h3 style="color:var(--gray-800);margin-bottom:6px">Not set up yet</h3><p>Run <code>install-db.php</code>, then add dedicated packages in Admin → VPS Packages.</p></div>
      <?php else: ?>

        <?php
          $orderable = array_filter($packages, fn($p) => !empty($cycles[(int)$p['id']]));
        ?>
        <?php if (!$orderable): ?>
          <div class="pk-empty"><h3 style="color:var(--gray-800);margin-bottom:6px">No dedicated servers available</h3><p>Please check back soon.</p></div>
        <?php else: ?>
        <div class="pk-grid">
          <?php foreach ($packages as $p):
            $pcyc = $cycles[(int)$p['id']] ?? [];
            if (!$pcyc) continue;
            $cyc_opts = [];
            foreach ($pcyc as $c) {
                $cp = $currency === 'USD' ? (float)$c['price_usd'] : (float)$c['price_inr'];
                $cyc_opts[] = ['m'=>(int)$c['months'], 'price'=>$cp, 'label'=>($cycle_names[(int)$c['months']] ?? ((int)$c['months'].' months'))];
            }
            $first = $cyc_opts[0];
          ?>
          <div class="pk-card">
            <div class="pk-name"><?= htmlspecialchars($p['name']) ?></div>
            <?php if (!empty($p['cpu_label'])): ?><div class="pk-cpu"><?= htmlspecialchars($p['cpu_label']) ?></div><?php endif; ?>
            <div class="pk-desc"><?= htmlspecialchars($p['description'] ?? '') ?></div>
            <ul class="pk-specs">
              <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/></svg><?= (int)$p['vcpu'] ?> CPU Cores</li>
              <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="10" rx="2"/><line x1="6" y1="11" x2="6" y2="13"/><line x1="10" y1="11" x2="10" y2="13"/></svg><?= htmlspecialchars($p['ram_gb']) ?> GB RAM</li>
              <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/></svg><?= (int)$p['disk_gb'] ?> GB Storage</li>
              <?php if ((int)$p['bandwidth_gb'] > 0): ?>
              <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg><?= (int)$p['bandwidth_gb'] ?> GB Bandwidth</li>
              <?php endif; ?>
            </ul>
            <div class="pk-price">
              <?php if (count($cyc_opts) > 1): ?>
              <select class="pk-cycle" onchange="updateCyclePrice(this)" style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;margin-bottom:10px">
                <?php foreach ($cyc_opts as $co): ?>
                <option value="<?= $co['m'] ?>" data-price="<?= $co['price'] ?>"><?= htmlspecialchars($co['label']) ?> — <?= $curr_sym . number_format($co['price'], $currency==='INR'?0:2) ?></option>
                <?php endforeach; ?>
              </select>
              <?php endif; ?>
              <span class="pk-price-val"><?= $curr_sym . number_format($first['price'], $currency==='INR'?0:2) ?></span>
              <span class="pk-price-cyc">/ <?= htmlspecialchars($first['label']) ?></span>
              <button class="pk-order" data-id="<?= (int)$p['id'] ?>" data-cycle="<?= $first['m'] ?>" data-sym="<?= $curr_sym ?>" data-name="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>" onclick="orderPkg(this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:15px;height:15px"><path d="M20 6L9 17l-5-5"/></svg>
                Order Now
              </button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($my_orders): ?>
        <div class="sec-h">My Dedicated Orders</div>
        <table class="ord-tbl">
          <thead><tr><th>Package</th><th>Cycle</th><th>Amount</th><th>Status</th><th>Expires</th><th>Placed</th></tr></thead>
          <tbody>
          <?php foreach ($my_orders as $o):
            $st = $o['status']; $stc = $st==='active'?'st-active':($st==='pending'?'st-pending':'st-refunded');
          ?>
          <tr>
            <td style="font-weight:600"><?= htmlspecialchars($o['pkg_name']) ?></td>
            <td><?= (int)$o['cycle_months'] ?> mo</td>
            <td style="font-family:var(--mono)"><?= ($o['currency']==='USD'?'$':'₹') . number_format((float)$o['amount'],2) ?></td>
            <td><span class="st <?= $stc ?>"><?= $st==='pending'?'Processing':ucfirst($st) ?></span></td>
            <td><?= $o['expires_at'] ? date('d M Y', strtotime($o['expires_at'])) : '—' ?></td>
            <td><?= date('d M Y', strtotime($o['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>

      <?php endif; ?>
    </div>
  </div>
</div>

<div class="pk-toast" id="pkToast"></div>
<script>
var PK_CSRF = '<?= $csrf ?>';
var PK_BASE = '<?= BASE_URL ?>';
function pkToast(m,t){var e=document.getElementById('pkToast');e.textContent=m;e.className='pk-toast '+t;setTimeout(function(){e.classList.add('show')},10);setTimeout(function(){e.classList.remove('show')},5000);}
function fmt(sym,n){return sym+(Math.round(n)==n?n.toLocaleString():n.toFixed(2));}
function updateCyclePrice(sel){
  var card=sel.closest('.pk-card'), opt=sel.options[sel.selectedIndex];
  var price=parseFloat(opt.getAttribute('data-price')), btn=card.querySelector('.pk-order'), sym=btn.getAttribute('data-sym');
  card.querySelector('.pk-price-val').textContent=fmt(sym,price);
  card.querySelector('.pk-price-cyc').textContent='/ '+opt.textContent.split('—')[0].trim();
  btn.setAttribute('data-cycle', opt.value);
}
function orderPkg(btn){
  var id=btn.getAttribute('data-id'), name=btn.getAttribute('data-name'), cycle=btn.getAttribute('data-cycle');
  var card=btn.closest('.pk-card');
  var priceTxt=card.querySelector('.pk-price-val').textContent+' '+card.querySelector('.pk-price-cyc').textContent;
  if(!confirm('Order "'+name+'" ('+priceTxt+')?\n\nThis amount will be charged now. Our team will provision and hand over the server.')) return;
  btn.disabled=true; var orig=btn.innerHTML; btn.innerHTML='Placing order…';
  fetch(PK_BASE+'/api/vps-package-order.php',{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({package_id:id, cycle_months:parseInt(cycle,10)||1, csrf:PK_CSRF})
  }).then(function(r){return r.json();}).then(function(d){
    if(d.ok){ pkToast('✅ '+(d.message||'Order placed!'),'ok'); setTimeout(function(){window.location.reload();},1600); }
    else{ pkToast('⚠ '+(d.error||'Order failed'),'fail'); btn.disabled=false; btn.innerHTML=orig; }
  }).catch(function(){ pkToast('Network error. Try again.','fail'); btn.disabled=false; btn.innerHTML=orig; });
}
</script>
</body>
</html>
