<?php
/**
 * packages.php — customer VPS package catalog.
 * Lists active WHMCS-style packages; ordering auto-provisions on Virtualizor.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$user     = current_user();
$uid      = (int)$user['id'];
$currency = 'INR';        // billing is INR-only
$curr_sym = '₹';
$app_name = APP_NAME;
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$uname    = htmlspecialchars($user['username']);
$balance  = number_format((float)$user['wallet_balance'], 2);
$csrf     = csrf_token();

$packages = [];
$cycles   = [];   // package_id => [ {months, price_inr, price_usd} ... enabled only ]
$no_table = false;
try {
    $packages = db()->query("SELECT * FROM vps_packages WHERE is_active=1 AND ptype='vps' ORDER BY sort_order, price_inr")->fetchAll();
    foreach (db()->query("SELECT * FROM package_cycles WHERE is_enabled=1 ORDER BY months")->fetchAll() as $c) {
        $cycles[(int)$c['package_id']][] = $c;
    }
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
    .loc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px}
    .loc-card{background:white;border:1.5px solid var(--border);border-radius:14px;padding:22px 18px;text-align:center;text-decoration:none;transition:all .16s;display:flex;flex-direction:column;align-items:center;gap:6px}
    .loc-card:hover{border-color:var(--primary);box-shadow:0 8px 24px rgba(15,23,42,.08);transform:translateY(-2px)}
    .loc-flag{width:44px;height:auto;border-radius:5px;box-shadow:0 1px 4px rgba(0,0,0,.15);margin-bottom:4px}
    .loc-pin{font-size:34px;line-height:1;margin-bottom:4px}
    .loc-name{font-size:15px;font-weight:800;color:var(--gray-900)}
    .loc-count{font-size:12.5px;color:var(--gray-400);font-weight:600}
    .loc-go{font-size:12.5px;color:var(--primary);font-weight:700;margin-top:6px}
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
      <?php else:
        // Orderable = has at least one enabled cycle. Group by location.
        $orderable = array_filter($packages, fn($p) => !empty($cycles[(int)$p['id']]));
        $by_loc = [];
        foreach ($orderable as $op) {
            $L = trim($op['location'] ?? '') ?: 'Other';
            $by_loc[$L]['flag']  = $op['location_flag'] ?? '';
            $by_loc[$L]['items'][] = $op;
        }
        $real_locs = array_values(array_filter(array_keys($by_loc), fn($k) => $k !== 'Other'));
        $sel = trim($_GET['location'] ?? '');
        $show_location_grid = count($real_locs) > 0 && ($sel === '' || !isset($by_loc[$sel]));
        $show_packages = ($sel !== '' && isset($by_loc[$sel])) ? $by_loc[$sel]['items'] : $orderable;
      ?>
        <?php if (!$orderable): ?>
          <div class="pk-empty"><h3 style="color:var(--gray-800);margin-bottom:6px">No packages available</h3><p>Please check back soon.</p></div>

        <?php elseif ($show_location_grid): ?>
          <!-- ── Step 1: choose a location ── -->
          <div style="font-size:15px;font-weight:800;color:var(--gray-900);margin-bottom:14px">Choose a location</div>
          <div class="loc-grid">
            <?php foreach ($by_loc as $L => $info): if ($L === 'Other') continue; $cnt = count($info['items']); ?>
            <a class="loc-card" href="?location=<?= urlencode($L) ?>">
              <?php if (!empty($info['flag'])): ?>
                <img class="loc-flag" src="https://flagcdn.com/w40/<?= htmlspecialchars($info['flag']) ?>.png" onerror="this.replaceWith(document.createTextNode('📍'))">
              <?php else: ?><div class="loc-pin">📍</div><?php endif; ?>
              <div class="loc-name"><?= htmlspecialchars($L) ?></div>
              <div class="loc-count"><?= $cnt ?> plan<?= $cnt == 1 ? '' : 's' ?></div>
              <span class="loc-go">View plans →</span>
            </a>
            <?php endforeach; ?>
            <?php if (isset($by_loc['Other'])): $cnt = count($by_loc['Other']['items']); ?>
            <a class="loc-card" href="?location=Other">
              <div class="loc-pin">🌐</div>
              <div class="loc-name">Other</div>
              <div class="loc-count"><?= $cnt ?> plan<?= $cnt == 1 ? '' : 's' ?></div>
              <span class="loc-go">View plans →</span>
            </a>
            <?php endif; ?>
          </div>

        <?php else: ?>
          <?php if ($sel !== ''): ?>
          <div style="margin-bottom:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <a href="packages.php" class="btn btn-secondary btn-sm">← All locations</a>
            <span style="font-size:16px;font-weight:800;color:var(--gray-900)">📍 <?= htmlspecialchars($sel) ?></span>
          </div>
          <?php endif; ?>
          <div class="pk-grid">
          <?php foreach ($show_packages as $p):
            $pcyc = $cycles[(int)$p['id']] ?? [];
            if (!$pcyc) continue; // no enabled cycle → not orderable
            // Build cycle options with per-cycle price in the user's currency
            $cyc_opts = [];
            foreach ($pcyc as $c) {
                $cp = $currency === 'USD' ? (float)$c['price_usd'] : (float)$c['price_inr'];
                $cyc_opts[] = ['m'=>(int)$c['months'], 'price'=>$cp, 'label'=>($cycle_names[(int)$c['months']] ?? ((int)$c['months'].' months'))];
            }
            $first = $cyc_opts[0];
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
              <?php if (count($cyc_opts) > 1): ?>
              <select class="pk-cycle" onchange="updateCyclePrice(this)" style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;margin-bottom:10px">
                <?php foreach ($cyc_opts as $co): ?>
                <option value="<?= $co['m'] ?>" data-price="<?= $co['price'] ?>"><?= htmlspecialchars($co['label']) ?> — <?= $curr_sym . number_format($co['price'], $currency==='INR'?0:2) ?></option>
                <?php endforeach; ?>
              </select>
              <?php endif; ?>
              <span class="pk-price-val"><?= $curr_sym . number_format($first['price'], $currency==='INR'?0:2) ?></span>
              <span class="pk-price-cyc">/ <?= htmlspecialchars($first['label']) ?></span>
              <button class="pk-order"
                data-id="<?= (int)$p['id'] ?>"
                data-cycle="<?= $first['m'] ?>"
                data-sym="<?= $curr_sym ?>"
                data-name="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>"
                onclick="orderPkg(this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:15px;height:15px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Deploy Now
              </button>
            </div>
          </div>
          <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="pk-toast" id="pkToast"></div>
<script>
var PK_CSRF = '<?= $csrf ?>';
var PK_BASE = '<?= BASE_URL ?>';
function pkToast(m,t){var e=document.getElementById('pkToast');e.textContent=m;e.className='pk-toast '+t;setTimeout(function(){e.classList.add('show')},10);setTimeout(function(){e.classList.remove('show')},4500);}
function fmt(sym, n){ return sym + (Math.round(n)==n ? n.toLocaleString() : n.toFixed(2)); }
function updateCyclePrice(sel){
  var card = sel.closest('.pk-card');
  var opt  = sel.options[sel.selectedIndex];
  var price = parseFloat(opt.getAttribute('data-price'));
  var months = opt.value;
  var btn = card.querySelector('.pk-order');
  var sym = btn.getAttribute('data-sym');
  card.querySelector('.pk-price-val').textContent = fmt(sym, price);
  card.querySelector('.pk-price-cyc').textContent = '/ ' + opt.textContent.split('—')[0].trim();
  btn.setAttribute('data-cycle', months);
}
function orderPkg(btn){
  var id=btn.getAttribute('data-id'), name=btn.getAttribute('data-name'), cycle=btn.getAttribute('data-cycle');
  var card=btn.closest('.pk-card');
  var priceTxt = card.querySelector('.pk-price-val').textContent + ' ' + card.querySelector('.pk-price-cyc').textContent;
  if(!confirm('Deploy "'+name+'" ('+priceTxt+')?\n\nThis amount will be charged from your wallet now (prepaid).')) return;
  var host=prompt('Hostname for this server (optional):','');
  btn.disabled=true; var orig=btn.innerHTML; btn.innerHTML='Deploying…';
  fetch(PK_BASE+'/api/vps-package-order.php',{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({package_id:id, cycle_months:parseInt(cycle,10)||1, hostname:host||'', csrf:PK_CSRF})
  }).then(function(r){return r.json();}).then(function(d){
    if(d.ok){ pkToast('✅ '+(d.message||'Server ordered!'),'ok'); setTimeout(function(){window.location.href=d.redirect||(PK_BASE+'/servers.php');},1400); }
    else{ pkToast('⚠ '+(d.error||'Order failed'),'fail'); btn.disabled=false; btn.innerHTML=orig; }
  }).catch(function(){ pkToast('Network error. Try again.','fail'); btn.disabled=false; btn.innerHTML=orig; });
}
</script>
</body>
</html>
