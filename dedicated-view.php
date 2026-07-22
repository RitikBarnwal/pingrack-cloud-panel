<?php
/**
 * dedicated-view.php — Dedicated server detail page.
 * Shows IP, hostname, root password, monthly cost, billing cycle, expiry, specs.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$user = current_user();
$uid  = (int)$user['id'];
$app_name = APP_NAME;
$avatar = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$fname  = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$uname  = htmlspecialchars($user['username']);
$balance = number_format((float)$user['wallet_balance'], 2);

$sid = (int)($_GET['id'] ?? 0);
$srv = null;
$order = null;
try {
    $st = db()->prepare("SELECT * FROM servers WHERE id=? AND user_id=? AND deleted_at IS NULL LIMIT 1");
    $st->execute([$sid, $uid]);
    $srv = $st->fetch();
} catch (Throwable $e) {}

if (!$srv) { header('Location: ' . BASE_URL . '/dedicated.php'); exit; }

// A server is "dedicated" if it's linked to a dedicated package order OR
// tagged server_type='dedicated'. Robust even before install-db runs.
$is_ded = (($srv['server_type'] ?? '') === 'dedicated');
try {
    $oq = db()->prepare(
        "SELECT o.* FROM vps_package_orders o JOIN vps_packages p ON p.id=o.package_id
         WHERE o.server_id=? AND p.ptype='dedicated' LIMIT 1");
    $oq->execute([$sid]);
    if ($order = $oq->fetch()) $is_ded = true;
} catch (Throwable $e) {}

if (!$is_ded) { header('Location: ' . BASE_URL . '/servers.php'); exit; }

// Prefer the order's cycle/amount if the server columns aren't populated.
$months  = (int)($srv['billing_months'] ?? 0) ?: (int)($order['cycle_months'] ?? 1);
$srv_mo  = round((float)($srv['price_monthly'] ?? 0), 2);
$monthly = $srv_mo > 0 ? $srv_mo : round((float)($order['amount'] ?? 0) / max(1, $months), 2);
if (empty($srv['expires_at']) && !empty($order['expires_at'])) $srv['expires_at'] = $order['expires_at'];
$cycle_names = [1=>'Monthly',3=>'Quarterly',6=>'Semi-Annual',12=>'Annual',24=>'Biennial',36=>'Triennial'];
$cycle_lbl = $cycle_names[$months] ?? ($months . ' months');
$status = $srv['status'] ?? 'running';
$stc = $status==='running'?'ok':($status==='suspended'?'warn':'muted');
$rootpass = (string)($srv['root_password'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title><?= htmlspecialchars($srv['name']) ?> — <?= $app_name ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    :root{--ink:#0f172a;--line:#e6eaf0;--muted:#94a3b8;}
    .dv-wrap{padding:24px 30px 80px;max-width:1000px;margin:0 auto}
    .dv-back{display:inline-flex;align-items:center;gap:7px;margin-bottom:16px;padding:8px 14px;border:1px solid var(--line);background:#fff;border-radius:9px;font-size:13px;font-weight:700;color:#334155;text-decoration:none}
    .dv-back:hover{background:#f8fafc}
    .dv-hd{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:22px}
    .dv-title{font-size:23px;font-weight:900;color:var(--ink);letter-spacing:-.4px;display:flex;align-items:center;gap:12px}
    .dv-ico{width:44px;height:44px;border-radius:12px;background:#f5f3ff;display:flex;align-items:center;justify-content:center}
    .pill{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:99px;font-size:12.5px;font-weight:700}
    .pill.ok{background:#f0fdf4;color:#16a34a}.pill.warn{background:#fffbeb;color:#d97706}.pill.muted{background:#f1f5f9;color:#64748b}
    .pdot{width:7px;height:7px;border-radius:50%;background:currentColor}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
    @media(max-width:760px){.grid{grid-template-columns:1fr}}
    .card{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden}
    .card-hd{padding:15px 20px;border-bottom:1px solid var(--line);font-size:14px;font-weight:800;color:var(--ink)}
    .card-bd{padding:8px 20px 16px}
    .kv{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid #f4f6f9}
    .kv:last-child{border-bottom:none}
    .kv-k{font-size:13px;color:#64748b}
    .kv-v{font-size:13.5px;font-weight:700;color:var(--ink);font-family:'JetBrains Mono',monospace;text-align:right;word-break:break-all}
    .copy{margin-left:8px;cursor:pointer;color:#94a3b8;font-size:12px;font-weight:700;border:none;background:none}
    .copy:hover{color:var(--ink)}
    .reveal{cursor:pointer;color:#4f46e5;font-weight:700;font-size:12px;border:none;background:none;font-family:inherit}
    .price-hero{display:flex;align-items:baseline;gap:8px}
    .price-big{font-size:30px;font-weight:900;color:var(--ink);letter-spacing:-1px}
    .price-cyc{font-size:13px;color:var(--muted);font-weight:600}
    .note{margin-top:16px;font-size:12.5px;color:#64748b;background:#f8fafc;border:1px solid var(--line);border-radius:10px;padding:12px 14px}
  </style>
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main-content" style="margin-left:260px;min-height:100vh;background:#f6f8fb">
    <div class="mobile-bar"><button class="ham-btn" onclick="toggleSidebar()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button></div>

    <div class="dv-wrap">
      <a href="<?= BASE_URL ?>/dedicated.php" class="dv-back"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5"/><polyline points="12 19 5 12 12 5"/></svg> Back to Dedicated Servers</a>

      <div class="dv-hd">
        <div class="dv-title">
          <div class="dv-ico"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><rect x="3" y="4" width="18" height="8" rx="1"/><rect x="3" y="14" width="18" height="6" rx="1"/><line x1="7" y1="8" x2="7.01" y2="8"/><line x1="7" y1="17" x2="7.01" y2="17"/></svg></div>
          <?= htmlspecialchars($srv['name']) ?>
        </div>
        <span class="pill <?= $stc ?>"><span class="pdot"></span><?= ucfirst($status) ?></span>
      </div>

      <div class="grid">
        <!-- Overview -->
        <div class="card">
          <div class="card-hd">Server Details</div>
          <div class="card-bd">
            <div class="kv"><span class="kv-k">Hostname</span><span class="kv-v"><?= htmlspecialchars($srv['name']) ?><button class="copy" onclick="cp('<?= htmlspecialchars($srv['name'], ENT_QUOTES) ?>',this)">Copy</button></span></div>
            <div class="kv"><span class="kv-k">IPv4 Address</span><span class="kv-v"><?= $srv['ipv4'] ? htmlspecialchars($srv['ipv4']) : '<span style="color:#94a3b8">Assigning…</span>' ?><?php if ($srv['ipv4']): ?><button class="copy" onclick="cp('<?= htmlspecialchars($srv['ipv4'], ENT_QUOTES) ?>',this)">Copy</button><?php endif; ?></span></div>
            <?php if (!empty($srv['ipv6'])): ?><div class="kv"><span class="kv-k">IPv6 Address</span><span class="kv-v"><?= htmlspecialchars($srv['ipv6']) ?></span></div><?php endif; ?>
            <div class="kv"><span class="kv-k">Location</span><span class="kv-v"><?= htmlspecialchars($srv['region_label'] ?: $srv['region_slug'] ?: '—') ?></span></div>
            <div class="kv"><span class="kv-k">Operating System</span><span class="kv-v"><?= htmlspecialchars($srv['os_label'] ?: '—') ?></span></div>
          </div>
        </div>

        <!-- Credentials -->
        <div class="card">
          <div class="card-hd">Access Credentials</div>
          <div class="card-bd">
            <div class="kv"><span class="kv-k">Username</span><span class="kv-v">root</span></div>
            <div class="kv"><span class="kv-k">Root Password</span><span class="kv-v">
              <?php if ($rootpass !== ''): ?>
                <span id="pw" data-pw="<?= htmlspecialchars($rootpass, ENT_QUOTES) ?>">••••••••••</span>
                <button class="reveal" id="pwToggle" onclick="togglePw()">Show</button>
              <?php else: ?><span style="color:#94a3b8">Sent by email</span><?php endif; ?>
            </span></div>
            <div class="kv"><span class="kv-k">SSH Command</span><span class="kv-v"><?= $srv['ipv4'] ? 'ssh root@'.htmlspecialchars($srv['ipv4']) : '—' ?></span></div>
          </div>
          <div style="padding:0 20px 16px"><div class="note">Keep these credentials safe. Contact support for OS reinstall, IP changes or hardware issues.</div></div>
        </div>

        <!-- Billing -->
        <div class="card">
          <div class="card-hd">Billing</div>
          <div class="card-bd">
            <div style="padding:14px 0 6px"><div class="price-hero"><span class="price-big">₹<?= number_format($monthly, 2) ?></span><span class="price-cyc">/ month</span></div></div>
            <div class="kv"><span class="kv-k">Billing Cycle</span><span class="kv-v"><?= htmlspecialchars($cycle_lbl) ?> (<?= $months ?> mo)</span></div>
            <div class="kv"><span class="kv-k">Cycle Total</span><span class="kv-v">₹<?= number_format($monthly * $months, 2) ?></span></div>
            <div class="kv"><span class="kv-k">Renews / Expires</span><span class="kv-v"><?= !empty($srv['expires_at']) ? date('d M Y', strtotime($srv['expires_at'])) : '—' ?></span></div>
            <div class="kv"><span class="kv-k">Wallet Balance</span><span class="kv-v">₹<?= $balance ?></span></div>
          </div>
        </div>

        <!-- Specs -->
        <div class="card">
          <div class="card-hd">Specifications</div>
          <div class="card-bd">
            <?php if (!empty($srv['plan_slug'])): ?><div class="kv"><span class="kv-k">Plan</span><span class="kv-v"><?= htmlspecialchars($srv['plan_slug']) ?></span></div><?php endif; ?>
            <div class="kv"><span class="kv-k">CPU</span><span class="kv-v"><?= (int)$srv['vcpu'] ?> Cores</span></div>
            <div class="kv"><span class="kv-k">Memory</span><span class="kv-v"><?= htmlspecialchars($srv['ram_gb']) ?> GB</span></div>
            <div class="kv"><span class="kv-k">Storage</span><span class="kv-v"><?= (int)$srv['disk_gb'] ?> GB</span></div>
            <?php if ((int)$srv['total_bandwidth_gb'] > 0): ?><div class="kv"><span class="kv-k">Bandwidth</span><span class="kv-v"><?= (int)$srv['total_bandwidth_gb'] ?> GB</span></div><?php endif; ?>
            <div class="kv"><span class="kv-k">Deployed</span><span class="kv-v"><?= date('d M Y', strtotime($srv['created_at'])) ?></span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
function cp(t,b){ navigator.clipboard.writeText(t).then(function(){ var o=b.textContent; b.textContent='Copied'; setTimeout(function(){b.textContent=o;},1200); }); }
function togglePw(){ var p=document.getElementById('pw'), t=document.getElementById('pwToggle'); if(t.textContent==='Show'){ p.textContent=p.getAttribute('data-pw'); t.textContent='Hide'; } else { p.textContent='••••••••••'; t.textContent='Show'; } }
</script>
</body>
</html>
