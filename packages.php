<?php
/**
 * packages.php — customer VPS package catalog.
 * Lists active WHMCS-style packages; ordering auto-provisions on Virtualizor.
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
$no_table = false;
try {
    $packages = db()->query("SELECT * FROM vps_packages WHERE is_active=1 ORDER BY sort_order, price_inr")->fetchAll();
} catch (Throwable $e) {
    $no_table = true;
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
    .pk-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}
    .pk-card{background:white;border:1.5px solid var(--border);border-radius:14px;padding:20px;display:flex;flex-direction:column;transition:all .16s}
    .pk-card:hover{border-color:var(--primary);box-shadow:0 8px 24px rgba(15,23,42,.08);transform:translateY(-2px)}
    .pk-name{font-size:16px;font-weight:800;color:var(--gray-900);letter-spacing:-.3px}
    .pk-desc{font-size:12.5px;color:var(--gray-500);margin-top:4px;min-height:18px}
    .pk-specs{list-style:none;padding:0;margin:16px 0;display:flex;flex-direction:column;gap:9px}
    .pk-specs li{display:flex;align-items:center;gap:9px;font-size:13.5px;color:var(--gray-700)}
    .pk-specs svg{width:15px;height:15px;color:var(--primary);flex-shrink:0}
    .pk-price{margin-top:auto;padding-top:14px;border-top:1px solid var(--border)}
    .pk-price-val{font-size:26px;font-weight:900;color:var(--gray-900);letter-spacing:-1px}
    .pk-price-cyc{font-size:12.5px;color:var(--gray-400);font-weight:600}
    .pk-order{margin-top:14px;width:100%;display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:11px;border-radius:10px;font-size:14px;font-weight:700;background:var(--primary);color:#fff;border:none;cursor:pointer;font-family:var(--font);transition:all .15s}
    .pk-order:hover{background:var(--primary-hover)}
    .pk-order:disabled{opacity:.6;cursor:not-allowed}
    .pk-empty{background:white;border:1.5px solid var(--border);border-radius:14px;padding:48px 20px;text-align:center;color:var(--gray-500)}
    .pk-toast{position:fixed;bottom:24px;right:24px;z-index:1200;padding:13px 18px;border-radius:11px;font-size:13.5px;font-weight:700;box-shadow:0 8px 30px rgba(0,0,0,.15);transform:translateY(12px);opacity:0;transition:all .3s;pointer-events:none;max-width:360px}
    .pk-toast.show{transform:translateY(0);opacity:1}
    .pk-toast.ok{background:#0f172a;color:#fff}
    .pk-toast.fail{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
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
      <span class="topbar-title">Deploy VPS</span>
      <div style="display:flex;gap:8px;align-items:center;margin-left:auto">
        <a href="<?= BASE_URL ?>/billing.php" class="btn btn-secondary btn-sm">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
          <?= $curr_sym . $balance ?>
        </a>
      </div>
    </div>

    <div style="padding:24px">
      <?php if ($no_table): ?>
        <div class="pk-empty">
          <h3 style="color:var(--gray-800);margin-bottom:6px">Packages not set up yet</h3>
          <p>Run <code>install-db.php</code> and add packages in Admin → VPS Packages.</p>
        </div>
      <?php elseif (!$packages): ?>
        <div class="pk-empty">
          <h3 style="color:var(--gray-800);margin-bottom:6px">No packages available</h3>
          <p>Please check back soon.</p>
        </div>
      <?php else: ?>
        <div class="pk-grid">
          <?php foreach ($packages as $p):
            $price = $currency === 'USD' ? (float)$p['price_usd'] : (float)$p['price_inr'];
          ?>
          <div class="pk-card">
            <div class="pk-name"><?= htmlspecialchars($p['name']) ?></div>
            <div class="pk-desc"><?= htmlspecialchars($p['description'] ?? '') ?></div>
            <ul class="pk-specs">
              <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/><line x1="20" y1="9" x2="23" y2="9"/><line x1="20" y1="14" x2="23" y2="14"/><line x1="1" y1="9" x2="4" y2="9"/><line x1="1" y1="14" x2="4" y2="14"/></svg><?= (int)$p['vcpu'] ?> vCPU Cores</li>
              <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="10" rx="2"/><line x1="6" y1="11" x2="6" y2="13"/><line x1="10" y1="11" x2="10" y2="13"/><line x1="14" y1="11" x2="14" y2="13"/><line x1="18" y1="11" x2="18" y2="13"/></svg><?= htmlspecialchars($p['ram_gb']) ?> GB RAM</li>
              <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg><?= (int)$p['disk_gb'] ?> GB Disk</li>
              <?php if ((int)$p['bandwidth_gb'] > 0): ?>
              <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg><?= (int)$p['bandwidth_gb'] ?> GB Bandwidth</li>
              <?php endif; ?>
            </ul>
            <div class="pk-price">
              <span class="pk-price-val"><?= $curr_sym . number_format($price, $currency==='INR'?0:2) ?></span>
              <span class="pk-price-cyc">/month</span>
              <button class="pk-order" data-id="<?= (int)$p['id'] ?>" data-price="<?= $curr_sym . number_format($price,$currency==='INR'?0:2) ?>" data-name="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>" onclick="orderPkg(this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:15px;height:15px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Deploy Now
              </button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="pk-toast" id="pkToast"></div>
<script>
var PK_CSRF = '<?= $csrf ?>';
var PK_BASE = '<?= BASE_URL ?>';
function pkToast(m,t){var e=document.getElementById('pkToast');e.textContent=m;e.className='pk-toast '+t;setTimeout(function(){e.classList.add('show')},10);setTimeout(function(){e.classList.remove('show')},4500);}
function orderPkg(btn){
  var id=btn.getAttribute('data-id'), name=btn.getAttribute('data-name'), price=btn.getAttribute('data-price');
  if(!confirm('Deploy "'+name+'" for '+price+'/month?\n\n'+price+' will be charged from your wallet now.')) return;
  var host=prompt('Hostname for this server (optional):','');
  btn.disabled=true; var orig=btn.innerHTML; btn.innerHTML='Deploying…';
  fetch(PK_BASE+'/api/vps-package-order.php',{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({package_id:id, hostname:host||'', csrf:PK_CSRF})
  }).then(function(r){return r.json();}).then(function(d){
    if(d.ok){ pkToast('✅ '+(d.message||'Server ordered!'),'ok'); setTimeout(function(){window.location.href=d.redirect||(PK_BASE+'/servers.php');},1200); }
    else{ pkToast('⚠ '+(d.error||'Order failed'),'fail'); btn.disabled=false; btn.innerHTML=orig; }
  }).catch(function(){ pkToast('Network error. Try again.','fail'); btn.disabled=false; btn.innerHTML=orig; });
}
</script>
</body>
</html>
