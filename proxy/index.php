<?php
// proxy/index.php — User: Proxy Services
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/currency.php';
require_once __DIR__ . '/../includes/proxy_providers.php';
require_login();

if (get_setting('proxy_module_enabled', '1') !== '1') {
    http_response_code(404); die('Proxy module disabled.');
}

$user     = current_user();
$app_name = APP_NAME;
$currency = strtoupper($user['currency'] ?? 'USD');
$curr_sym = user_currency_symbol($currency);
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$uname    = htmlspecialchars($user['username']);
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$balance  = (float)$user['wallet_balance'];
$csrf     = csrf_token();
$tab      = $_GET['tab'] ?? 'plans';

// Plans
$plans_raw = db()->query(
    "SELECT pl.*, prov.name provider_name, prov.slug provider_slug
     FROM proxy_plans pl
     JOIN proxy_providers prov ON prov.id = pl.provider_id
     WHERE pl.is_active = 1
     ORDER BY pl.proxy_type, pl.sort_order, pl.id"
)->fetchAll();

$plans = ['datacenter'=>[], 'residential'=>[], 'mobile'=>[], 'static'=>[]];
foreach ($plans_raw as $p) {
    $p['features']  = json_decode($p['features']  ?? '[]', true) ?: [];
    $p['locations'] = json_decode($p['locations']  ?? '[]', true) ?: [];
    $p['price']     = $currency === 'INR' ? $p['price_inr'] : $p['price_usd'];
    $plans[$p['proxy_type']][] = $p;
}

// My orders with usage
$my_orders_st = db()->prepare(
    "SELECT po.*, pp.name plan_name, prov.name provider_name, prov.slug provider_slug,
            pc.password_plain
     FROM proxy_orders po
     JOIN proxy_plans pp ON pp.id = po.plan_id
     JOIN proxy_providers prov ON prov.id = po.provider_id
     LEFT JOIN proxy_credentials pc ON pc.order_id = po.id
     WHERE po.user_id = ?
     ORDER BY po.created_at DESC"
);
$my_orders_st->execute([$user['id']]);
$my_orders = $my_orders_st->fetchAll();

// Usage logs for active orders
$usage_map = [];
if (!empty($my_orders)) {
    $ids = implode(',', array_column($my_orders, 'id'));
    $ul  = db()->query(
        "SELECT order_id, log_date, total_mb, upload_mb, download_mb
         FROM proxy_usage_logs
         WHERE order_id IN ($ids)
         ORDER BY log_date DESC"
    );
    foreach ($ul->fetchAll() as $row) {
        $usage_map[$row['order_id']][] = $row;
    }
}

function proxyTypeBadge(string $t): string {
    $m = ['datacenter'=>'badge-blue','residential'=>'badge-green','mobile'=>'badge-purple','static'=>'badge-yellow'];
    $l = ['datacenter'=>'Datacenter','residential'=>'Residential','mobile'=>'Mobile','static'=>'Static'];
    return "<span class='badge ".($m[$t]??'badge-gray')."'>".($l[$t]??ucfirst($t))."</span>";
}
function proxyStatusBadge(string $s): string {
    $m = ['pending'=>'badge-yellow','active'=>'badge-green','expired'=>'badge-gray','cancelled'=>'badge-red','suspended'=>'badge-red'];
    return "<span class='badge ".($m[$s]??'badge-gray')."'>".ucfirst($s)."</span>";
}
function bwBar(float $used, float $total): string {
    if ($total <= 0) return '<span style="font-size:11px;color:var(--gray-400)">Unlimited</span>';
    $pct = min(100, round($used / $total * 100));
    $color = $pct >= 90 ? 'var(--danger)' : ($pct >= 70 ? 'var(--warning)' : 'var(--success)');
    return "<div style='font-size:11.5px;color:var(--gray-500);margin-bottom:3px'>
                ".round($used, 2)." / {$total} GB used ({$pct}%)
            </div>
            <div style='background:var(--gray-200);border-radius:99px;height:5px;overflow:hidden'>
                <div style='background:{$color};width:{$pct}%;height:100%;border-radius:99px;transition:.3s'></div>
            </div>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Proxy Services — <?= $app_name ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    .seg-tabs{display:flex;gap:2px;background:var(--gray-100);border-radius:9px;padding:3px;width:fit-content;margin-bottom:20px}
    .seg-tab{padding:7px 18px;border-radius:7px;font-size:13px;font-weight:600;color:var(--gray-500);text-decoration:none;transition:.15s}
    .seg-tab.active{background:var(--white);color:var(--gray-900);box-shadow:var(--shadow-sm)}
    .seg-tab:hover:not(.active){color:var(--gray-700)}

    .chip-row{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:18px}
    .chip{padding:5px 14px;border-radius:99px;font-size:12.5px;font-weight:600;cursor:pointer;border:1.5px solid var(--gray-200);color:var(--gray-500);background:var(--white);transition:.14s;white-space:nowrap}
    .chip.active{border-color:var(--primary);color:var(--primary);background:#f4f0ff}
    .chip:hover:not(.active){border-color:var(--gray-300);color:var(--gray-700)}

    .plans-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:14px}
    .plan-card{background:var(--white);border:1.5px solid var(--border);border-radius:var(--radius-lg);padding:20px;display:flex;flex-direction:column;box-shadow:var(--shadow-sm);transition:.18s;position:relative;overflow:hidden}
    .plan-card:hover{box-shadow:var(--shadow-md);border-color:var(--gray-300);transform:translateY(-1px)}
    .plan-card.featured{border-color:var(--primary)}
    .plan-card.featured::before{content:'Most Popular';position:absolute;top:0;right:0;background:var(--primary);color:#fff;font-size:10px;font-weight:800;letter-spacing:.04em;padding:4px 12px;border-radius:0 12px 0 8px}
    .plan-hdr{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;gap:4px}
    .plan-name{font-size:15px;font-weight:800;color:var(--gray-900);margin:6px 0 2px}
    .plan-price{font-size:26px;font-weight:900;color:var(--gray-900);letter-spacing:-.5px;margin:4px 0 1px}
    .plan-price span{font-size:13px;font-weight:500;color:var(--gray-400)}
    .plan-meta{font-size:11.5px;color:var(--gray-400);margin-bottom:14px;display:flex;flex-wrap:wrap;gap:4px;align-items:center}
    .plan-meta-dot{color:var(--gray-300)}
    .plan-feats{list-style:none;padding:0;margin:0 0 18px;flex:1}
    .plan-feats li{font-size:13px;color:var(--gray-600);padding:3px 0;display:flex;align-items:flex-start;gap:7px;line-height:1.5}
    .plan-feats li::before{content:'';width:14px;height:14px;min-width:14px;margin-top:2px;border-radius:50%;background:var(--success-bg) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2316a34a' stroke-width='2.5'%3E%3Cpolyline points='20 6 9 17 4 12'/%3E%3C/svg%3E") center/10px no-repeat}

    /* Order cards */
    .order-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px}
    .order-card{background:var(--white);border:1.5px solid var(--border);border-radius:var(--radius-lg);padding:18px;box-shadow:var(--shadow-sm)}
    .order-card-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px}
    .order-card-ref{font-family:var(--mono);font-size:12px;font-weight:600;color:var(--primary)}
    .order-card-meta{font-size:12px;color:var(--gray-500);margin-bottom:10px;display:flex;gap:6px;flex-wrap:wrap;align-items:center}
    .order-card-creds{background:var(--gray-50);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 12px;margin-bottom:10px}
    .cred-row{display:flex;justify-content:space-between;align-items:center;padding:4px 0;border-bottom:1px solid var(--border);gap:8px}
    .cred-row:last-child{border-bottom:none}
    .cred-lbl{font-size:11.5px;color:var(--gray-500);flex-shrink:0}
    .cred-val{font-family:var(--mono);font-size:11.5px;font-weight:600;color:var(--gray-900);word-break:break-all;text-align:right}
    .copy-btn{flex-shrink:0;padding:1px 7px;font-size:10.5px;font-weight:600;border:1px solid var(--border);border-radius:4px;background:var(--white);color:var(--gray-500);cursor:pointer;transition:.12s;margin-left:4px}
    .copy-btn:hover{border-color:var(--primary);color:var(--primary)}
    .proxy-ta{width:100%;box-sizing:border-box;height:90px;font-family:var(--mono);font-size:11px;line-height:1.7;padding:8px 10px;resize:vertical;border:1.5px solid var(--gray-300);border-radius:var(--radius-sm);color:var(--gray-900);outline:none;background:var(--white)}
    .proxy-ta:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-ring)}
    .pending-notice{background:var(--warning-bg);border:1px solid #fde68a;border-radius:var(--radius-sm);padding:11px 13px;font-size:13px;color:var(--warning);display:flex;gap:8px;align-items:flex-start}

    /* Usage chart */
    .usage-chart{background:var(--gray-50);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px;margin-top:10px}
    .usage-bars{display:flex;align-items:flex-end;gap:3px;height:50px;margin-top:6px}
    .u-bar{flex:1;background:var(--primary);border-radius:3px 3px 0 0;min-height:2px;transition:.3s;cursor:default;position:relative}
    .u-bar:hover::after{content:attr(data-tip);position:absolute;bottom:105%;left:50%;transform:translateX(-50%);background:var(--gray-900);color:#fff;font-size:10px;padding:3px 7px;border-radius:5px;white-space:nowrap;pointer-events:none}

    /* Detail rows in buy modal */
    .detail-rows{background:var(--gray-50);border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden;margin-bottom:14px}
    .detail-row{display:flex;justify-content:space-between;align-items:center;padding:9px 13px;border-bottom:1px solid var(--border);font-size:13px}
    .detail-row:last-child{border-bottom:none}
    .detail-row .lbl{color:var(--gray-500)}
    .detail-row .val{font-weight:700;color:var(--gray-900)}

    @media(max-width:640px){.plans-grid,.order-cards{grid-template-columns:1fr}}
  </style>
</head>
<body>
<div class="app-shell">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-content">
    <div class="mobile-bar">
      <button class="ham-btn" onclick="document.querySelector('.sidebar').classList.toggle('open');document.querySelector('.overlay').classList.toggle('open')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <span style="font-weight:700;font-size:14px">Proxy Services</span>
    </div>
    <div class="overlay" onclick="this.classList.remove('open');document.querySelector('.sidebar').classList.remove('open')"></div>

    <div class="topbar">
      <span class="topbar-title">🌐 Proxy Services</span>
      <span style="font-size:12.5px;color:var(--gray-500)">
        Wallet: <strong style="color:var(--gray-900)"><?= $curr_sym ?><?= number_format($user['wallet_balance'], 2) ?></strong>
      </span>
    </div>

    <div class="page-body">

      <!-- Tabs -->
      <div class="seg-tabs">
        <a href="?tab=plans"  class="seg-tab <?= $tab==='plans'  ? 'active':'' ?>">Browse Plans</a>
        <a href="?tab=orders" class="seg-tab <?= $tab==='orders' ? 'active':'' ?>">
          My Orders<?php if(count($my_orders)): ?>
            <span class="badge badge-gray" style="margin-left:4px;font-size:10px"><?= count($my_orders) ?></span>
          <?php endif; ?>
        </a>
      </div>

      <?php if ($tab === 'plans'): ?>
      <!-- ══ PLANS ══════════════════════════════════════════════ -->
      <div class="chip-row">
        <button class="chip active" data-type="all">All Types</button>
        <button class="chip" data-type="datacenter">🖥 Datacenter</button>
        <button class="chip" data-type="residential">🏘 Residential</button>
        <button class="chip" data-type="mobile">📱 Mobile</button>
        <button class="chip" data-type="static">🔒 Static</button>
      </div>

      <?php $all_empty = !array_filter($plans, fn($g) => !empty($g)); ?>
      <?php if ($all_empty): ?>
        <div class="empty-state">
          <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 10px;opacity:.3"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10"/></svg>
          <p style="font-size:14px">No plans available yet. Check back soon.</p>
        </div>
      <?php else: ?>
      <div class="plans-grid" id="plansGrid">
        <?php foreach ($plans as $type => $type_plans): ?>
          <?php foreach ($type_plans as $p): ?>
          <div class="plan-card <?= $p['is_featured']?'featured':'' ?>" data-type="<?= $type ?>">
            <div class="plan-hdr">
              <?= proxyTypeBadge($type) ?>
              <span class="badge badge-gray" style="font-size:10px"><?= ucfirst($p['rotation']) ?></span>
            </div>
            <div class="plan-name"><?= htmlspecialchars($p['name']) ?></div>
            <div class="plan-price"><?= $curr_sym ?><?= number_format($p['price'], 0) ?><span>/<?= $p['duration_days'] ?>d</span></div>
            <div class="plan-meta">
              <span><?= $p['bandwidth_gb'] > 0 ? $p['bandwidth_gb'].' GB' : 'Unlimited' ?></span>
              <span class="plan-meta-dot">·</span>
              <span><?= $p['max_ips'] ?> IP<?= $p['max_ips']>1?'s':'' ?></span>
              <span class="plan-meta-dot">·</span>
              <span><?= strtoupper($p['protocol']) ?></span>
              <span class="plan-meta-dot">·</span>
              <span><?= htmlspecialchars($p['provider_name']) ?></span>
            </div>
            <ul class="plan-feats">
              <?php foreach ($p['features'] as $f): ?>
                <li><?= htmlspecialchars($f) ?></li>
              <?php endforeach; ?>
            </ul>
            <button class="btn btn-primary btn-full"
              onclick='openBuyModal(<?= htmlspecialchars(json_encode([
                'id'       => $p['id'],
                'name'     => $p['name'],
                'type'     => $p['proxy_type'],
                'protocol' => $p['protocol'],
                'price'    => $p['price'],
                'sym'      => $curr_sym,
                'bw'       => $p['bandwidth_gb'],
                'days'     => $p['duration_days'],
                'ips'      => $p['max_ips'],
                'locs'     => $p['locations'],
                'rotation' => $p['rotation'],
                'provider' => $p['provider_name'],
              ]), ENT_QUOTES) ?>)'>Buy Now</button>
          </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php else: ?>
      <!-- ══ MY ORDERS ══════════════════════════════════════════ -->
      <?php if (empty($my_orders)): ?>
        <div class="empty-state" style="padding:60px 20px">
          <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 10px;opacity:.3"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
          <p style="font-size:14px;margin-bottom:14px">No proxy orders yet.</p>
          <a href="?tab=plans" class="btn btn-primary btn-sm">Browse Plans →</a>
        </div>
      <?php else: ?>
        <div class="order-cards">
          <?php foreach ($my_orders as $o):
            $usage_days = $usage_map[$o['id']] ?? [];
            $is_active  = $o['status'] === 'active';
            $has_creds  = $is_active && ($o['username'] || $o['gateway_host'] || $o['proxy_list']);
          ?>
          <div class="order-card">
            <!-- Head -->
            <div class="order-card-head">
              <div>
                <div class="order-card-ref"><?= htmlspecialchars($o['order_ref']) ?></div>
                <div style="font-size:13px;font-weight:700;color:var(--gray-900);margin-top:2px"><?= htmlspecialchars($o['plan_name']) ?></div>
              </div>
              <?= proxyStatusBadge($o['status']) ?>
            </div>

            <!-- Meta -->
            <div class="order-card-meta">
              <?= proxyTypeBadge($o['proxy_type']) ?>
              <span><?= strtoupper($o['protocol']) ?></span>
              <span class="plan-meta-dot">·</span>
              <span><?= htmlspecialchars($o['provider_name']) ?></span>
              <?php if ($o['expires_at']): ?>
              <span class="plan-meta-dot">·</span>
              <span>Expires <?= date('d M Y', strtotime($o['expires_at'])) ?></span>
              <?php endif; ?>
            </div>

            <!-- Bandwidth bar -->
            <?php if ($o['bandwidth_gb'] > 0): ?>
              <div style="margin-bottom:10px">
                <?= bwBar((float)$o['bandwidth_used_gb'], (float)$o['bandwidth_gb']) ?>
              </div>
            <?php endif; ?>

            <!-- Credentials -->
            <?php if ($has_creds): ?>
            <div class="order-card-creds">
              <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--gray-400);margin-bottom:6px">🔐 Credentials</div>
              <?php if ($o['username']): ?>
              <div class="cred-row">
                <span class="cred-lbl">Username</span>
                <div style="display:flex;align-items:center">
                  <span class="cred-val"><?= htmlspecialchars($o['username']) ?></span>
                  <button class="copy-btn" onclick="cp('<?= htmlspecialchars(addslashes($o['username'])) ?>')">Copy</button>
                </div>
              </div>
              <?php endif; ?>
              <?php if ($o['password_plain']): ?>
              <div class="cred-row">
                <span class="cred-lbl">Password</span>
                <div style="display:flex;align-items:center">
                  <span class="cred-val" id="pw-<?= $o['id'] ?>">••••••••</span>
                  <button class="copy-btn" onclick="togglePw(<?= $o['id'] ?>,'<?= htmlspecialchars(addslashes($o['password_plain'])) ?>')">Show</button>
                  <button class="copy-btn" onclick="cp('<?= htmlspecialchars(addslashes($o['password_plain'])) ?>')">Copy</button>
                </div>
              </div>
              <?php endif; ?>
              <?php if ($o['gateway_host']): ?>
              <div class="cred-row">
                <span class="cred-lbl">Host:Port</span>
                <div style="display:flex;align-items:center">
                  <span class="cred-val"><?= htmlspecialchars($o['gateway_host']) ?>:<?= $o['gateway_port'] ?></span>
                  <button class="copy-btn" onclick="cp('<?= htmlspecialchars(addslashes($o['gateway_host'])) ?>:<?= $o['gateway_port'] ?>')">Copy</button>
                </div>
              </div>
              <?php endif; ?>
              <?php if ($o['whitelist_ip']): ?>
              <div class="cred-row">
                <span class="cred-lbl">Whitelist IP</span>
                <span class="cred-val"><?= htmlspecialchars($o['whitelist_ip']) ?></span>
              </div>
              <?php endif; ?>
            </div>

            <?php if ($o['proxy_list']): ?>
            <div style="margin-bottom:10px">
              <label class="flabel">Proxy List</label>
              <textarea class="proxy-ta" readonly><?= htmlspecialchars($o['proxy_list']) ?></textarea>
              <button class="btn btn-ghost btn-sm btn-full" style="margin-top:5px" onclick="cp(`<?= htmlspecialchars(addslashes($o['proxy_list'])) ?>`)">📋 Copy All Proxies</button>
            </div>
            <?php endif; ?>

            <?php elseif ($o['status'] === 'pending'): ?>
            <div class="pending-notice">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              <span><strong>Pending Activation</strong> — Your proxies are being set up. Credentials will appear here once activated (usually within 24h).</span>
            </div>
            <?php elseif ($o['status'] === 'suspended'): ?>
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:var(--radius-sm);padding:11px 13px;font-size:13px;color:var(--danger);margin-bottom:10px">
              ⛔ <strong>Suspended</strong> — Contact support for assistance.
            </div>
            <?php endif; ?>

            <!-- Whitelist IP update (mobile/static only, active) -->
            <?php if ($is_active && in_array($o['proxy_type'], ['mobile','static']) && $o['provider_slug'] === 'hydraproxy'): ?>
            <div style="background:var(--gray-50);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 12px;margin-bottom:10px">
              <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--gray-400);margin-bottom:6px">📍 Update Whitelist IP</div>
              <div style="display:flex;gap:6px">
                <input type="text" id="wl-<?= $o['id'] ?>" class="form-control" style="font-size:12.5px" placeholder="Enter your new IP">
                <button class="btn btn-secondary btn-sm" style="flex-shrink:0" onclick="updateWhitelist(<?= $o['id'] ?>)">Update</button>
              </div>
              <?php if ($o['whitelist_unlock_at']): ?>
              <div style="font-size:11px;color:var(--gray-400);margin-top:4px">Unlock at: <?= date('d M Y H:i', strtotime($o['whitelist_unlock_at'])) ?></div>
              <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Usage chart -->
            <?php if (!empty($usage_days)): ?>
            <div class="usage-chart">
              <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--gray-400);margin-bottom:2px">📊 Bandwidth (last <?= min(14, count($usage_days)) ?> days)</div>
              <?php
                $recent  = array_slice($usage_days, 0, 14);
                $max_mb  = max(array_column($recent, 'total_mb')) ?: 1;
                $recent  = array_reverse($recent);
              ?>
              <div class="usage-bars">
                <?php foreach ($recent as $u):
                  $h   = max(4, round($u['total_mb'] / $max_mb * 50));
                  $tip = date('d M', strtotime($u['log_date'])) . ': ' . round($u['total_mb'], 1) . ' MB';
                ?>
                <div class="u-bar" style="height:<?= $h ?>px" data-tip="<?= htmlspecialchars($tip) ?>"></div>
                <?php endforeach; ?>
              </div>
              <div style="display:flex;justify-content:space-between;font-size:10.5px;color:var(--gray-400);margin-top:3px">
                <span><?= date('d M', strtotime($recent[0]['log_date'])) ?></span>
                <span><?= date('d M', strtotime(end($recent)['log_date'])) ?></span>
              </div>
              <div style="font-size:12px;color:var(--gray-600);margin-top:6px">
                Total used: <strong><?= round(array_sum(array_column($usage_days,'total_mb'))/1024, 2) ?> GB</strong>
              </div>
            </div>
            <?php endif; ?>

            <!-- Last synced -->
            <?php if ($o['last_synced_at']): ?>
            <div style="font-size:11px;color:var(--gray-400);margin-top:8px;text-align:right">
              Synced <?= date('d M H:i', strtotime($o['last_synced_at'])) ?>
              <?php if ($o['sync_error']): ?>
                · <span style="color:var(--danger)">Sync error</span>
              <?php endif; ?>
            </div>
            <?php endif; ?>

          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php endif; ?>

    </div>
  </div>
</div>

<!-- BUY MODAL -->
<div class="modal-bd" id="buyModal">
  <div class="modal-box" style="max-width:430px">
    <div class="modal-head">
      <span class="modal-title">Purchase Proxy Plan</span>
      <button onclick="closeModal('buyModal')" style="background:none;border:none;cursor:pointer;color:var(--gray-400);font-size:20px;line-height:1;padding:0">&times;</button>
    </div>
    <div id="buyDetails"></div>
    <div class="form-group">
      <label class="flabel">Location / Country</label>
      <select id="buyLocation" class="form-control"></select>
    </div>
    <div class="form-group">
      <label class="flabel">Protocol</label>
      <select id="buyProtocol" class="form-control">
        <option value="http">HTTP</option>
        <option value="socks5">SOCKS5</option>
        <option value="https">HTTPS</option>
      </select>
    </div>
    <div class="form-group" style="margin-bottom:0">
      <label class="flabel">Rotation</label>
      <select id="buyRotation" class="form-control">
        <option value="rotating">Rotating</option>
        <option value="sticky">Sticky</option>
      </select>
    </div>
    <div style="background:var(--gray-50);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 13px;margin:14px 0;font-size:12.5px;color:var(--gray-600)">
      💳 Deducted from wallet · Balance:
      <strong style="color:var(--gray-900)"><?= $curr_sym ?><?= number_format($user['wallet_balance'], 2) ?></strong>
    </div>
    <div class="modal-footer">
      <button onclick="closeModal('buyModal')" class="btn btn-ghost">Cancel</button>
      <button class="btn btn-primary" id="confirmBuyBtn" onclick="confirmPurchase()">Confirm Purchase</button>
    </div>
  </div>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<script>
const CSRF = '<?= $csrf ?>';
const BASE = '<?= BASE_URL ?>';
const COUNTRY_NAMES = {
  IN:'🇮🇳 India',US:'🇺🇸 United States',GB:'🇬🇧 United Kingdom',
  DE:'🇩🇪 Germany',SG:'🇸🇬 Singapore',NL:'🇳🇱 Netherlands',
  JP:'🇯🇵 Japan',AU:'🇦🇺 Australia',CA:'🇨🇦 Canada',FR:'🇫🇷 France',
  NG:'🇳🇬 Nigeria',BR:'🇧🇷 Brazil',ZA:'🇿🇦 South Africa'
};
let _buyPlan = null;

// Type chips
document.querySelectorAll('.chip').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.chip').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const t = btn.dataset.type;
    document.querySelectorAll('.plan-card').forEach(c => {
      c.style.display = (t === 'all' || c.dataset.type === t) ? '' : 'none';
    });
  });
});

function openBuyModal(plan) {
  _buyPlan = plan;
  document.getElementById('buyDetails').innerHTML = `
    <div class="detail-rows">
      <div class="detail-row"><span class="lbl">Plan</span><span class="val">${plan.name}</span></div>
      <div class="detail-row"><span class="lbl">Provider</span><span class="val">${plan.provider}</span></div>
      <div class="detail-row"><span class="lbl">Bandwidth</span><span class="val">${plan.bw > 0 ? plan.bw+' GB' : 'Unlimited'}</span></div>
      <div class="detail-row"><span class="lbl">Duration</span><span class="val">${plan.days} days</span></div>
      <div class="detail-row"><span class="lbl">Amount</span><span class="val" style="color:var(--primary);font-size:15px">${plan.sym}${Number(plan.price).toLocaleString()}</span></div>
    </div>`;
  const sel = document.getElementById('buyLocation');
  sel.innerHTML = '<option value="ANY">🌍 Any Location</option>';
  (plan.locs || []).forEach(c => { sel.innerHTML += `<option value="${c}">${COUNTRY_NAMES[c]||c}</option>`; });
  document.getElementById('buyProtocol').value = plan.protocol;
  document.getElementById('buyRotation').value  = plan.rotation;
  const btn = document.getElementById('confirmBuyBtn');
  btn.disabled = false; btn.textContent = 'Confirm Purchase';
  document.getElementById('buyModal').classList.add('open');
}

function confirmPurchase() {
  if (!_buyPlan) return;
  const btn = document.getElementById('confirmBuyBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Processing…';
  fetch(BASE + '/api/proxy-order.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      csrf: CSRF, plan_id: _buyPlan.id,
      location: document.getElementById('buyLocation').value,
      protocol: document.getElementById('buyProtocol').value,
      rotation: document.getElementById('buyRotation').value,
    })
  }).then(r=>r.json()).then(d => {
    if (d.success) {
      closeModal('buyModal');
      toast('Order placed! Ref: ' + d.order_ref, 'ok');
      setTimeout(() => location.href = '?tab=orders', 1400);
    } else {
      toast(d.error || 'Order failed', 'err');
      btn.disabled = false; btn.textContent = 'Confirm Purchase';
    }
  }).catch(() => { toast('Network error','err'); btn.disabled=false; btn.textContent='Confirm Purchase'; });
}

function togglePw(id, plain) {
  const el  = document.getElementById('pw-'+id);
  const btn = event.target;
  if (el.textContent === '••••••••') { el.textContent = plain; btn.textContent = 'Hide'; }
  else { el.textContent = '••••••••'; btn.textContent = 'Show'; }
}

function updateWhitelist(orderId) {
  const ip  = document.getElementById('wl-' + orderId).value.trim();
  if (!ip) { toast('Enter an IP address first','err'); return; }
  if (!confirm('Update whitelist IP to ' + ip + '?')) return;
  fetch(BASE + '/api/proxy-user-action.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ csrf:CSRF, action:'update_whitelist', order_id:orderId, ip })
  }).then(r=>r.json()).then(d => {
    if (d.success) { toast('Whitelist IP updated!','ok'); setTimeout(()=>location.reload(),1200); }
    else toast(d.error || 'Failed','err');
  }).catch(()=>toast('Network error','err'));
}

function cp(text) { navigator.clipboard.writeText(text).then(()=>toast('Copied!','ok')); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-bd').forEach(m => {
  m.addEventListener('click', e => { if(e.target===m) m.classList.remove('open'); });
});
function toast(msg, type) {
  const cls  = {ok:'toast-ok',err:'toast-err',inf:'toast-inf'}[type]||'toast-inf';
  const icon = type==='ok'
    ? `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>`
    : `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`;
  const el = document.createElement('div');
  el.className = 'toast ' + cls;
  el.innerHTML = icon + msg;
  document.getElementById('toastWrap').appendChild(el);
  setTimeout(() => el.remove(), 3500);
}
</script>
</body>
</html>
