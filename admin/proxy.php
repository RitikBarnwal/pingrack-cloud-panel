<?php
// admin/proxy.php — Admin: Proxy Management (Orders + Plans + Provider Settings)
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/proxy_providers.php';
require_login();
require_admin();

$user     = current_user();
$app_name = APP_NAME;
$fname    = htmlspecialchars($user['full_name'] ?: $user['username']);
$msg = ''; $err = '';
$csrf    = csrf_token();
$tab      = $_GET['tab'] ?? 'orders';

$stats = db()->query(
    "SELECT COUNT(*) total, SUM(status='pending') pending, SUM(status='active') active_cnt,
            SUM(status='expired') expired_cnt, COALESCE(SUM(amount_paid),0) revenue
     FROM proxy_orders"
)->fetch();

$limit  = 25; $page = max(1,(int)($_GET['p']??1)); $offset = ($page-1)*$limit;
$search = trim($_GET['q']??''); $fs = $_GET['status']??''; $ft = $_GET['type']??''; $fp = $_GET['provider']??'';

$where = ['1=1']; $params = [];
if ($search)  { $where[]='(po.order_ref LIKE ? OR u.username LIKE ? OR u.email LIKE ?)'; $s="%$search%"; $params=array_merge($params,[$s,$s,$s]); }
if ($fs)      { $where[]='po.status=?';       $params[]=$fs; }
if ($ft)      { $where[]='po.proxy_type=?';   $params[]=$ft; }
if ($fp)      { $where[]='po.provider_id=?';  $params[]=$fp; }
$wsql = implode(' AND ',$where);

$cnt = db()->prepare("SELECT COUNT(*) FROM proxy_orders po JOIN users u ON u.id=po.user_id WHERE $wsql");
$cnt->execute($params);
$total_pages = max(1, ceil($cnt->fetchColumn()/$limit));

$ords = db()->prepare(
    "SELECT po.*, pp.name plan_name, prov.name provider_name, prov.slug provider_slug,
            u.username, u.email, u.full_name
     FROM proxy_orders po
     JOIN proxy_plans pp ON pp.id=po.plan_id
     JOIN proxy_providers prov ON prov.id=po.provider_id
     JOIN users u ON u.id=po.user_id
     WHERE $wsql ORDER BY po.created_at DESC LIMIT $limit OFFSET $offset"
);
$ords->execute($params); $orders = $ords->fetchAll();

$plans = db()->query(
    "SELECT pl.*, prov.name provider_name, prov.slug provider_slug
     FROM proxy_plans pl JOIN proxy_providers prov ON prov.id=pl.provider_id
     ORDER BY pl.proxy_type, pl.sort_order, pl.id"
)->fetchAll();

$providers = db()->query("SELECT * FROM proxy_providers ORDER BY id")->fetchAll();
$providers_map = []; foreach ($providers as $p) $providers_map[$p['id']] = $p;

function pxBadge(string $t, string $type='type'): string {
    if ($type==='type') {
        $m=['datacenter'=>'badge-blue','residential'=>'badge-green','mobile'=>'badge-purple','static'=>'badge-yellow'];
        $l=['datacenter'=>'Datacenter','residential'=>'Residential','mobile'=>'Mobile','static'=>'Static'];
        return "<span class='badge ".($m[$t]??'badge-gray')."'>".($l[$t]??ucfirst($t))."</span>";
    }
    $m=['pending'=>'badge-yellow','active'=>'badge-green','expired'=>'badge-gray','cancelled'=>'badge-red','suspended'=>'badge-red'];
    return "<span class='badge ".($m[$t]??'badge-gray')."'>".ucfirst($t)."</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Proxy Management — <?= $app_name ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/admin/admin.css">
</head>
<!-- ── Mobile top bar ────────────────────────────────────── -->
<div class="adm-mobile-bar">
  <button class="adm-ham" onclick="admToggleSidebar()" aria-label="Menu">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
      <line x1="3" y1="6"  x2="21" y2="6"/>
      <line x1="3" y1="12" x2="21" y2="12"/>
      <line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
  </button>
  <?php if (!empty(get_setting('site_logo', ''))) : ?>
    <img src="<?= htmlspecialchars(get_setting('site_logo', '')) ?>" alt="Logo" style="width: 130px;">
    <span style="font-size:9px;background:#dc2626;color:#fff;padding:2px 6px;border-radius:99px;font-weight:700;text-transform:uppercase;vertical-align:middle;margin-left:4px">Admin</span>
<?php else: ?>
    <span class="adm-mobile-title">
    <?= APP_NAME ?>
    <span style="font-size:9px;background:#dc2626;color:#fff;padding:2px 6px;border-radius:99px;font-weight:700;text-transform:uppercase;vertical-align:middle;margin-left:4px">Admin</span>
  </span>
<?php endif; ?>
</div>
<body>
<div class="adm-shell">
  <?php require_once __DIR__ . '/sidebar.php'; ?>
  <div class="adm-main">

    <div class="adm-topbar">
      <span class="adm-topbar-title">🌐 Proxy Management</span>
    </div>

    <div class="adm-content">

      <!-- Stats -->
      <div class="stats-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:20px">
        <div class="stat-card">
          <div class="stat-val"><?= number_format($stats['total']??0) ?></div>
          <div class="stat-lbl">Total Orders</div>
        </div>
        <div class="stat-card" style="border-top:3px solid var(--warn)">
          <div class="stat-val" style="color:var(--warn)"><?= number_format($stats['pending']??0) ?></div>
          <div class="stat-lbl">Pending</div>
        </div>
        <div class="stat-card" style="border-top:3px solid var(--success)">
          <div class="stat-val" style="color:var(--success)"><?= number_format($stats['active_cnt']??0) ?></div>
          <div class="stat-lbl">Active</div>
        </div>
        <div class="stat-card">
          <div class="stat-val" style="color:var(--gray-400)"><?= number_format($stats['expired_cnt']??0) ?></div>
          <div class="stat-lbl">Expired</div>
        </div>
        <div class="stat-card" style="border-top:3px solid var(--primary)">
          <div class="stat-val" style="color:var(--primary)">₹<?= number_format($stats['revenue']??0,0) ?></div>
          <div class="stat-lbl">Revenue</div>
        </div>
      </div>

      <!-- Tabs -->
      <div class="seg-tabs">
        <a href="?tab=orders" class="seg-tab <?= $tab==='orders'?'active':'' ?>">
          Orders<?php if(($stats['pending']??0)>0): ?><span class="badge badge-red" style="margin-left:4px;font-size:10px"><?=$stats['pending']?></span><?php endif; ?>
        </a>
        <a href="?tab=plans"     class="seg-tab <?= $tab==='plans'?'active':'' ?>">Plans</a>
        <a href="?tab=providers" class="seg-tab <?= $tab==='providers'?'active':'' ?>">Provider Settings</a>
      </div>

      <?php if($tab==='providers'): ?>
      <p style="font-size:13px;color:var(--gray-500);margin:0 0 16px">Enter API keys for each provider. VPS IP must be whitelisted with HydraProxy.</p>
      <div class="prov-grid">
        <?php foreach($providers as $pv): ?>
        <div class="prov-card">
          <div class="prov-head">
            <div style="flex:1">
              <div style="font-size:14px;font-weight:800;color:var(--gray-900)"><?= htmlspecialchars($pv['name']) ?></div>
              <?php if($pv['slug']!=='manual'): ?>
              <div style="font-size:22px;font-weight:900;color:var(--primary);margin:4px 0 2px"><?= $pv['account_balance'] !== null ? '$'.number_format($pv['account_balance'],2) : '—' ?></div>
              <div style="font-size:12px;color:var(--gray-400)">Last synced: <?= $pv['last_synced_at'] ? date('d M Y H:i',strtotime($pv['last_synced_at'])) : 'Never' ?></div>
              <?php else: ?>
              <div style="font-size:12px;color:var(--gray-400);margin:6px 0">Manual orders — no API sync</div>
              <?php endif; ?>
            </div>
            <?php if($pv['is_active']): ?><span class="badge badge-green">Active</span><?php else: ?><span class="badge badge-gray">Disabled</span><?php endif; ?>
          </div>
          <div class="prov-footer">
            <button onclick="openProviderModal(<?= htmlspecialchars(json_encode($pv),ENT_QUOTES) ?>)" class="btn btn-secondary btn-sm">Edit API Key</button>
            <?php if($pv['slug']!=='manual'): ?><button onclick="testProvider(<?=$pv['id']?>)" class="btn btn-ghost btn-sm" id="test-<?=$pv['id']?>">Test Connection</button><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php elseif($tab==='plans'): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px">
        <span style="font-size:13px;color:var(--gray-500)"><?=count($plans)?> plan<?=count($plans)!==1?'s':''?> configured</span>
        <button onclick="openPlanModal(null)" class="btn btn-primary btn-sm">+ Add Plan</button>
      </div>
      <?php if(empty($plans)): ?>
        <div class="empty-state">
          <p style="color:var(--gray-400);margin-bottom:14px">No plans yet.</p>
          <button onclick="openPlanModal(null)" class="btn btn-primary btn-sm">+ Add Plan</button>
        </div>
      <?php else: ?>
      <div class="plans-grid">
        <?php foreach($plans as $p): ?>
        <div class="plan-card <?= !$p['is_active']?'inactive':'' ?>">
          <div class="card-top" style="margin-bottom:6px">
            <div style="display:flex;gap:4px;flex-wrap:wrap">
              <?= pxBadge($p['proxy_type']) ?>
              <span class="badge badge-gray" style="font-size:10px"><?= htmlspecialchars($p['provider_name']) ?></span>
            </div>
            <?php if($p['is_featured']): ?><span class="badge badge-purple">★</span><?php endif; ?>
          </div>
          <h4 style="font-size:14px;font-weight:800;color:var(--gray-900);margin:0 0 3px"><?= htmlspecialchars($p['name']) ?></h4>
          <p style="font-size:12px;color:var(--gray-500);margin:0 0 12px;line-height:1.6">
            ₹<?=number_format($p['price_inr'],0)?>/<?=$p['duration_days']?>d &middot; <?=$p['bandwidth_gb']>0?$p['bandwidth_gb'].' GB':'Unlimited'?> &middot; <?=strtoupper($p['protocol'])?>
            <?php if(!$p['is_active']): ?> &middot; <span style="color:var(--danger)">Disabled</span><?php endif; ?>
          </p>
          <div class="card-actions">
            <button onclick="openPlanModal(<?= htmlspecialchars(json_encode($p),ENT_QUOTES) ?>)" class="btn btn-secondary btn-sm">Edit</button>
            <button onclick="togglePlan(<?=$p['id']?>,<?=$p['is_active']?0:1?>)" class="btn btn-ghost btn-sm"><?=$p['is_active']?'Disable':'Enable'?></button>
            <button onclick="deletePlan(<?=$p['id']?>,'<?=htmlspecialchars(addslashes($p['name']))?>')" class="btn btn-danger btn-sm">Delete</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php else: ?>
      <form method="get" style="margin:0">
        <input type="hidden" name="tab" value="orders">
        <div class="filter-row">
          <input name="q" value="<?=htmlspecialchars($search)?>" placeholder="Search ref, user, email…" class="form-control">
          <select name="status" class="form-control">
            <option value="">All Status</option>
            <?php foreach(['pending','active','expired','cancelled','suspended'] as $s): ?><option value="<?=$s?>" <?=$fs===$s?'selected':''?>><?=ucfirst($s)?></option><?php endforeach; ?>
          </select>
          <select name="type" class="form-control">
            <option value="">All Types</option>
            <?php foreach(['datacenter','residential','mobile','static'] as $t): ?><option value="<?=$t?>" <?=$ft===$t?'selected':''?>><?=ucfirst($t)?></option><?php endforeach; ?>
          </select>
          <select name="provider" class="form-control">
            <option value="">All Providers</option>
            <?php foreach($providers as $pv): ?><option value="<?=$pv['id']?>" <?=$fp==$pv['id']?'selected':''?>><?=htmlspecialchars($pv['name'])?></option><?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
          <?php if($search||$fs||$ft||$fp): ?><a href="?tab=orders" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?>
        </div>
      </form>
      <div class="card">
        <div class="tbl-wrap">
          <table class="tbl">
            <thead><tr><th>Order Ref</th><th>Customer</th><th>Plan</th><th>Provider</th><th>Provider ID</th><th>Amount</th><th>Status</th><th>Last Synced</th><th>Actions</th></tr></thead>
            <tbody>
              <?php if(empty($orders)): ?>
              <tr><td colspan="9" style="padding:40px;text-align:center;color:var(--gray-400)">No orders found.</td></tr>
              <?php else: foreach($orders as $o): ?>
              <tr>
                <td><code style="font-family:var(--mono);font-size:12px;color:var(--primary);font-weight:600"><?=htmlspecialchars($o['order_ref'])?></code></td>
                <td>
                  <div style="font-weight:600;font-size:13px"><?=htmlspecialchars($o['full_name']?:$o['username'])?></div>
                  <div style="font-size:11px;color:var(--gray-400)"><?=htmlspecialchars($o['email'])?></div>
                </td>
                <td style="font-size:13px"><?=htmlspecialchars($o['plan_name'])?></td>
                <td><span class="badge badge-gray" style="font-size:10px"><?=htmlspecialchars($o['provider_name'])?></span></td>
                <td><?php if($o['provider_order_id']): ?><code style="font-family:var(--mono);font-size:11px;color:var(--gray-700)"><?=htmlspecialchars($o['provider_order_id'])?></code><?php else: ?><span style="font-size:11px;color:var(--warn)">⚠ Not set</span><?php endif; ?></td>
                <td style="font-weight:700;font-size:13px"><?=$o['currency']?> <?=number_format($o['amount_paid'],2)?></td>
                <td><?= pxBadge($o['status'],'status') ?><?php if($o['sync_error']): ?><div style="font-size:10px;color:var(--danger);margin-top:2px">Sync error</div><?php endif; ?></td>
                <td><?php if($o['last_synced_at']): ?><span class="sync-badge sync-ok">✓ <?=date('H:i',strtotime($o['last_synced_at']))?></span><?php elseif($o['provider_order_id']): ?><span class="sync-badge sync-none">Not synced</span><?php else: ?><span class="sync-badge sync-none">—</span><?php endif; ?></td>
                <td style="white-space:nowrap">
                  <button onclick="openManage(<?=htmlspecialchars(json_encode($o),ENT_QUOTES)?>)" class="btn btn-secondary btn-sm">Manage</button>
                  <?php if($o['provider_slug']!=='manual' && $o['provider_order_id']): ?>
                  <button onclick="syncOrder(<?=$o['id']?>,this)" class="btn btn-ghost btn-sm" style="margin-left:4px">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg> Sync
                  </button>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php if($total_pages>1): ?>
      <div class="pagination">
        <?php for($i=1;$i<=$total_pages;$i++): ?><a href="?tab=orders&p=<?=$i?>&q=<?=urlencode($search)?>&status=<?=$fs?>&type=<?=$ft?>&provider=<?=$fp?>" class="<?=$i===$page?'active':''?>"><?=$i?></a><?php endfor; ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>

    </div>
  </div>
</div>

<!-- MANAGE ORDER MODAL -->
<div class="modal-bg" id="manageModal" style="display:none">
  <div class="modal-box" style="max-width:600px">
    <div class="modal-head">
      <span class="modal-title">Manage Proxy Order</span>
      <button onclick="closeModal('manageModal')" class="modal-close">&times;</button>
    </div>
    <div class="modal-body">
      <div id="manageStrip" style="display:grid;grid-template-columns:1fr 1fr;gap:8px 16px;background:var(--gray-50);border:1px solid var(--border);border-radius:var(--r-md);padding:14px;margin-bottom:16px"></div>
      <input type="hidden" id="m_id">
      <div class="group-label">Provider Integration</div>
      <div class="fg">
        <div class="form-group">
          <label class="flabel">Provider</label>
          <select id="m_provider_id" class="form-control">
            <?php foreach($providers as $pv): ?><option value="<?=$pv['id']?>"><?=htmlspecialchars($pv['name'])?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="flabel">Provider Order ID <span style="font-weight:400;color:var(--gray-400)">(from provider dashboard)</span></label>
          <div style="display:flex;gap:6px">
            <input type="text" id="m_provider_order_id" class="form-control" placeholder="e.g. 29139">
            <button onclick="syncManageOrder()" class="btn btn-secondary btn-sm" id="syncBtn" style="white-space:nowrap;flex-shrink:0">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg> Sync
            </button>
          </div>
        </div>
      </div>
      <div id="syncResult" style="display:none;font-size:12.5px;padding:10px 12px;border-radius:var(--r-sm);margin-bottom:14px"></div>
      <div class="group-label">Order Status</div>
      <div class="fg">
        <div class="form-group"><label class="flabel">Status</label><select id="m_status" class="form-control"><?php foreach(['pending','active','expired','cancelled','suspended'] as $s): ?><option value="<?=$s?>"><?=ucfirst($s)?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="flabel">Expiry Date</label><input type="date" id="m_expires" class="form-control"></div>
      </div>
      <div class="group-label">Credentials <span style="font-weight:400;color:var(--gray-400);text-transform:none;letter-spacing:0;font-size:11px">(auto-filled by Sync, or enter manually)</span></div>
      <div class="fg">
        <div class="form-group"><label class="flabel">Username</label><input type="text" id="m_username" class="form-control" placeholder="proxy_user"></div>
        <div class="form-group"><label class="flabel">Password</label><input type="text" id="m_password" class="form-control" placeholder="proxyPass123"></div>
      </div>
      <div class="fg">
        <div class="form-group"><label class="flabel">Gateway Host</label><input type="text" id="m_gateway_host" class="form-control" placeholder="proxy.hydraproxy.com"></div>
        <div class="form-group"><label class="flabel">Gateway Port</label><input type="number" id="m_gateway_port" class="form-control" placeholder="1234"></div>
      </div>
      <div class="form-group">
        <label class="flabel">Proxy List <span style="font-weight:400;color:var(--gray-400)">(datacenter — ip:port, one per line)</span></label>
        <textarea id="m_proxy_list" class="form-control" style="height:100px;font-family:var(--mono);font-size:11.5px" placeholder="1.2.3.4:8080&#10;5.6.7.8:3128"></textarea>
      </div>
      <div class="group-label">Whitelist IP <span style="font-weight:400;color:var(--gray-400);text-transform:none;letter-spacing:0;font-size:11px">(Mobile / Static proxies only)</span></div>
      <div class="fg">
        <div class="form-group"><label class="flabel">Current Whitelisted IP</label><input type="text" id="m_whitelist_ip" class="form-control" readonly style="background:var(--gray-50)"></div>
        <div class="form-group">
          <label class="flabel">New Whitelist IP</label>
          <div style="display:flex;gap:6px"><input type="text" id="m_new_whitelist_ip" class="form-control" placeholder="User's new IP"><button onclick="updateWhitelistIp()" class="btn btn-secondary btn-sm" style="flex-shrink:0;white-space:nowrap">Update IP</button></div>
        </div>
      </div>
      <div class="form-group"><label class="flabel">Admin Notes</label><textarea id="m_notes" class="form-control" style="height:60px" placeholder="Internal notes…"></textarea></div>
    </div>
    <div class="modal-foot">
      <button onclick="closeModal('manageModal')" class="btn btn-ghost">Cancel</button>
      <button onclick="saveOrder()" class="btn btn-primary" id="saveOrderBtn">Save Changes</button>
    </div>
  </div>
</div>

<!-- PLAN MODAL -->
<div class="modal-bg" id="planModal" style="display:none">
  <div class="modal-box" style="max-width:600px">
    <div class="modal-head">
      <span class="modal-title" id="planModalTitle">Add Proxy Plan</span>
      <button onclick="closeModal('planModal')" class="modal-close">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="pm_id">
      <div class="fg"><div class="form-group"><label class="flabel">Plan Name</label><input type="text" id="pm_name" class="form-control" placeholder="Resi Basic"></div><div class="form-group"><label class="flabel">Slug</label><input type="text" id="pm_slug" class="form-control" placeholder="resi-basic"></div></div>
      <div class="fg3">
        <div class="form-group"><label class="flabel">Proxy Type</label><select id="pm_type" class="form-control"><option value="datacenter">Datacenter</option><option value="residential">Residential</option><option value="mobile">Mobile</option><option value="static">Static</option></select></div>
        <div class="form-group"><label class="flabel">Protocol</label><select id="pm_protocol" class="form-control"><option value="http">HTTP</option><option value="socks5">SOCKS5</option><option value="https">HTTPS</option></select></div>
        <div class="form-group"><label class="flabel">Provider</label><select id="pm_provider_id" class="form-control"><?php foreach($providers as $pv): ?><option value="<?=$pv['id']?>"><?=htmlspecialchars($pv['name'])?></option><?php endforeach; ?></select></div>
      </div>
      <div class="fg"><div class="form-group"><label class="flabel">Price INR (₹)</label><input type="number" id="pm_price_inr" class="form-control" step="0.01" placeholder="799"></div><div class="form-group"><label class="flabel">Price USD ($)</label><input type="number" id="pm_price_usd" class="form-control" step="0.01" placeholder="9.99"></div></div>
      <div class="fg3"><div class="form-group"><label class="flabel">Bandwidth GB <small style="color:var(--gray-400)">(0=∞)</small></label><input type="number" id="pm_bw" class="form-control" step="0.01" placeholder="10"></div><div class="form-group"><label class="flabel">Duration (days)</label><input type="number" id="pm_days" class="form-control" placeholder="30"></div><div class="form-group"><label class="flabel">Max IPs</label><input type="number" id="pm_max_ips" class="form-control" placeholder="1"></div></div>
      <div class="fg"><div class="form-group"><label class="flabel">Rotation</label><select id="pm_rotation" class="form-control"><option value="rotating">Rotating</option><option value="sticky">Sticky</option></select></div><div class="form-group"><label class="flabel">Threads</label><input type="number" id="pm_threads" class="form-control" placeholder="100"></div></div>
      <div class="form-group"><label class="flabel">Features <span style="color:var(--gray-400);font-weight:400">(one per line)</span></label><textarea id="pm_features" class="form-control" style="height:80px" placeholder="10 GB Residential Traffic&#10;City-Level Targeting&#10;Rotating IPs"></textarea></div>
      <div class="fg"><div class="form-group"><label class="flabel">Country Codes <span style="color:var(--gray-400);font-weight:400">(comma sep.)</span></label><input type="text" id="pm_locations" class="form-control" placeholder="IN,US,GB,SG"></div><div class="form-group"><label class="flabel">Sort Order</label><input type="number" id="pm_sort" class="form-control" placeholder="0"></div></div>
      <div class="form-group"><label class="check-row"><input type="checkbox" id="pm_featured"><span>Mark as Featured / Most Popular</span></label></div>
    </div>
    <div class="modal-foot">
      <button onclick="closeModal('planModal')" class="btn btn-ghost">Cancel</button>
      <button onclick="savePlan()" class="btn btn-primary" id="savePlanBtn">Save Plan</button>
    </div>
  </div>
</div>

<!-- PROVIDER MODAL -->
<div class="modal-bg" id="providerModal" style="display:none">
  <div class="modal-box" style="max-width:480px">
    <div class="modal-head">
      <span class="modal-title" id="provModalTitle">Provider Settings</span>
      <button onclick="closeModal('providerModal')" class="modal-close">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="pv_id">
      <div class="form-group"><label class="flabel">API Key / Token</label><input type="text" id="pv_api_key" class="form-control" placeholder="Your API key"></div>
      <div class="form-group" id="pv_secret_row"><label class="flabel">API Secret <span style="color:var(--gray-400);font-weight:400">(ProxyCheap only)</span></label><input type="text" id="pv_api_secret" class="form-control" placeholder="API secret"></div>
      <div class="form-group"><label class="flabel">Whitelisted VPS IPs</label><textarea id="pv_whitelist_ips" class="form-control" style="height:70px" placeholder="Your VPS public IPs (one per line)"></textarea></div>
      <div class="form-group"><label class="flabel">Notes</label><textarea id="pv_notes" class="form-control" style="height:55px" placeholder="e.g. Account login, contact info…"></textarea></div>
    </div>
    <div class="modal-foot">
      <button onclick="closeModal('providerModal')" class="btn btn-ghost">Cancel</button>
      <button onclick="saveProvider()" class="btn btn-primary" id="saveProvBtn">Save</button>
    </div>
  </div>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<script>
const CSRF = '<?= $csrf ?>';
const BASE = '<?= BASE_URL ?>';

function openModal(id)  { const m=document.getElementById(id); m.style.display='flex'; }
function closeModal(id) { document.getElementById(id).style.display='none'; }
document.querySelectorAll('.modal-bg').forEach(m=>{ m.addEventListener('click',e=>{ if(e.target===m) closeModal(m.id); }); });

function openManage(o) {
  document.getElementById('m_id').value               = o.id;
  document.getElementById('m_provider_id').value      = o.provider_id;
  document.getElementById('m_provider_order_id').value= o.provider_order_id || '';
  document.getElementById('m_status').value           = o.status;
  document.getElementById('m_expires').value          = o.expires_at ? o.expires_at.substr(0,10) : '';
  document.getElementById('m_username').value         = o.username || '';
  document.getElementById('m_password').value         = o.password || '';
  document.getElementById('m_gateway_host').value     = o.gateway_host || '';
  document.getElementById('m_gateway_port').value     = o.gateway_port || '';
  document.getElementById('m_proxy_list').value       = o.proxy_list || '';
  document.getElementById('m_whitelist_ip').value     = o.whitelist_ip || '';
  document.getElementById('m_new_whitelist_ip').value = '';
  document.getElementById('m_notes').value            = o.notes || '';
  document.getElementById('syncResult').style.display = 'none';
  document.getElementById('manageStrip').innerHTML = `
    <div><div style="font-size:11px;color:var(--gray-400);font-weight:700;text-transform:uppercase;letter-spacing:.05em">Order Ref</div><div style="font-family:var(--mono);font-size:12px;color:var(--primary);font-weight:700;margin-top:2px">${o.order_ref}</div></div>
    <div><div style="font-size:11px;color:var(--gray-400);font-weight:700;text-transform:uppercase;letter-spacing:.05em">Customer</div><div style="font-size:13px;font-weight:700;margin-top:2px">${o.full_name||o.username} <span style="font-weight:400;color:var(--gray-400);font-size:12px">${o.email||''}</span></div></div>
    <div><div style="font-size:11px;color:var(--gray-400);font-weight:700;text-transform:uppercase;letter-spacing:.05em">Plan</div><div style="font-size:13px;font-weight:700;margin-top:2px">${o.plan_name}</div></div>
    <div><div style="font-size:11px;color:var(--gray-400);font-weight:700;text-transform:uppercase;letter-spacing:.05em">Type</div><div style="font-size:13px;font-weight:700;margin-top:2px">${o.proxy_type} / ${o.protocol.toUpperCase()}</div></div>
    <div><div style="font-size:11px;color:var(--gray-400);font-weight:700;text-transform:uppercase;letter-spacing:.05em">Location</div><div style="font-size:13px;font-weight:700;margin-top:2px">${o.location}</div></div>
    <div><div style="font-size:11px;color:var(--gray-400);font-weight:700;text-transform:uppercase;letter-spacing:.05em">Amount</div><div style="font-size:13px;font-weight:700;margin-top:2px">${o.currency} ${parseFloat(o.amount_paid).toFixed(2)}</div></div>
  `;
  document.getElementById('saveOrderBtn').disabled = false;
  document.getElementById('saveOrderBtn').textContent = 'Save Changes';
  openModal('manageModal');
}

function syncManageOrder() {
  const id=document.getElementById('m_id').value, provider_id=document.getElementById('m_provider_id').value, poid=document.getElementById('m_provider_order_id').value.trim();
  if (!poid) { toast('Enter provider order ID first','err'); return; }
  const btn=document.getElementById('syncBtn'); btn.disabled=true; btn.innerHTML='<span class="spinner"></span>';
  fetch(BASE+'/api/proxy-admin-action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:CSRF,action:'sync_order',order_id:id,provider_id,provider_order_id:poid})})
  .then(r=>r.json()).then(d=>{
    btn.disabled=false; btn.innerHTML='<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg> Sync';
    const res=document.getElementById('syncResult'); res.style.display='block';
    if(d.success){
      res.style.cssText='display:block;background:var(--success-light);color:var(--success);border:1px solid var(--success-border);font-size:12.5px;padding:10px 12px;border-radius:var(--r-sm);margin-bottom:14px';
      res.textContent='✓ Synced! Credentials auto-filled. Review and Save.';
      const nd=d.data||{};
      if(nd.username) document.getElementById('m_username').value=nd.username;
      if(nd.password) document.getElementById('m_password').value=nd.password;
      if(nd.gateway_host) document.getElementById('m_gateway_host').value=nd.gateway_host;
      if(nd.gateway_port) document.getElementById('m_gateway_port').value=nd.gateway_port;
      if(nd.proxy_list) document.getElementById('m_proxy_list').value=nd.proxy_list;
      if(nd.whitelist_ip) document.getElementById('m_whitelist_ip').value=nd.whitelist_ip;
      if(nd.expires_at&&!document.getElementById('m_expires').value) document.getElementById('m_expires').value=nd.expires_at.substr(0,10);
      if(nd.provider_status){const pm={active:'active',expired:'expired','Expired':'expired','Active':'active'},s=pm[nd.provider_status]||nd.provider_status.toLowerCase();if(['active','expired','suspended','cancelled'].includes(s))document.getElementById('m_status').value=s;}
    } else {
      res.style.cssText='display:block;background:var(--danger-light);color:var(--danger);border:1px solid var(--danger-border);font-size:12.5px;padding:10px 12px;border-radius:var(--r-sm);margin-bottom:14px';
      res.textContent='✗ '+(d.error||'Sync failed');
    }
  }).catch(()=>{btn.disabled=false;btn.textContent='Sync';toast('Network error','err');});
}

function syncOrder(orderId,btn){
  btn.disabled=true;btn.innerHTML='<span class="spinner"></span>';
  fetch(BASE+'/api/proxy-admin-action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:CSRF,action:'sync_order_by_id',order_id:orderId})})
  .then(r=>r.json()).then(d=>{btn.disabled=false;btn.innerHTML='<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg> Sync';if(d.success){toast('Synced — '+(d.msg||'OK'),'ok');setTimeout(()=>location.reload(),900);}else toast(d.error||'Sync failed','err');}).catch(()=>{btn.disabled=false;btn.textContent='Sync';toast('Network error','err');});
}

function saveOrder(){
  const id=document.getElementById('m_id').value; if(!id) return;
  const btn=document.getElementById('saveOrderBtn'); btn.disabled=true; btn.innerHTML='<span class="spinner"></span> Saving…';
  fetch(BASE+'/api/proxy-admin-action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:CSRF,action:'update_order',order_id:id,provider_id:document.getElementById('m_provider_id').value,provider_order_id:document.getElementById('m_provider_order_id').value,status:document.getElementById('m_status').value,expires_at:document.getElementById('m_expires').value,username:document.getElementById('m_username').value,password:document.getElementById('m_password').value,gateway_host:document.getElementById('m_gateway_host').value,gateway_port:document.getElementById('m_gateway_port').value,proxy_list:document.getElementById('m_proxy_list').value,notes:document.getElementById('m_notes').value})})
  .then(r=>r.json()).then(d=>{if(d.success){toast('Order saved!','ok');setTimeout(()=>location.reload(),900);}else{toast(d.error||'Failed','err');btn.disabled=false;btn.textContent='Save Changes';}}).catch(()=>{toast('Network error','err');btn.disabled=false;btn.textContent='Save Changes';});
}

function updateWhitelistIp(){
  const id=document.getElementById('m_id').value,ip=document.getElementById('m_new_whitelist_ip').value.trim(),pid=document.getElementById('m_provider_id').value,poi=document.getElementById('m_provider_order_id').value.trim();
  if(!ip){toast('Enter new IP first','err');return;}if(!poi){toast('Provider order ID required','err');return;}if(!confirm('Update whitelist IP to '+ip+'?'))return;
  fetch(BASE+'/api/proxy-admin-action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:CSRF,action:'update_whitelist',order_id:id,provider_id:pid,provider_order_id:poi,whitelist_ip:ip})})
  .then(r=>r.json()).then(d=>{if(d.success){document.getElementById('m_whitelist_ip').value=ip;toast('Whitelist IP updated!','ok');}else toast(d.error||'Failed','err');}).catch(()=>toast('Network error','err'));
}

function openPlanModal(p){
  document.getElementById('planModalTitle').textContent=p?'Edit Plan':'Add Plan';
  ['pm_id','pm_name','pm_slug'].forEach(id=>document.getElementById(id).value=p?p[id.replace('pm_','')]||'':'');
  document.getElementById('pm_id').value=p?p.id:'';
  document.getElementById('pm_name').value=p?p.name:'';
  document.getElementById('pm_slug').value=p?p.slug:'';
  document.getElementById('pm_type').value=p?p.proxy_type:'datacenter';
  document.getElementById('pm_protocol').value=p?p.protocol:'http';
  document.getElementById('pm_provider_id').value=p?p.provider_id:1;
  document.getElementById('pm_price_inr').value=p?p.price_inr:'';
  document.getElementById('pm_price_usd').value=p?p.price_usd:'';
  document.getElementById('pm_bw').value=p?p.bandwidth_gb:'';
  document.getElementById('pm_days').value=p?p.duration_days:30;
  document.getElementById('pm_max_ips').value=p?p.max_ips:1;
  document.getElementById('pm_rotation').value=p?p.rotation:'rotating';
  document.getElementById('pm_threads').value=p?p.threads:100;
  document.getElementById('pm_sort').value=p?p.sort_order:0;
  document.getElementById('pm_featured').checked=p?p.is_featured==1:false;
  let feats=[],locs=[];
  if(p&&p.features){try{feats=JSON.parse(p.features);}catch(e){}}
  if(p&&p.locations){try{locs=JSON.parse(p.locations);}catch(e){}}
  document.getElementById('pm_features').value=feats.join('\n');
  document.getElementById('pm_locations').value=locs.join(',');
  document.getElementById('savePlanBtn').disabled=false;document.getElementById('savePlanBtn').textContent='Save Plan';
  openModal('planModal');
}

function savePlan(){
  const btn=document.getElementById('savePlanBtn');btn.disabled=true;btn.innerHTML='<span class="spinner"></span> Saving…';
  const feats=document.getElementById('pm_features').value.split('\n').map(f=>f.trim()).filter(Boolean);
  const locs=document.getElementById('pm_locations').value.split(',').map(l=>l.trim().toUpperCase()).filter(Boolean);
  fetch(BASE+'/api/proxy-admin-action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:CSRF,action:'save_plan',id:document.getElementById('pm_id').value,name:document.getElementById('pm_name').value,slug:document.getElementById('pm_slug').value,proxy_type:document.getElementById('pm_type').value,protocol:document.getElementById('pm_protocol').value,provider_id:document.getElementById('pm_provider_id').value,price_inr:document.getElementById('pm_price_inr').value,price_usd:document.getElementById('pm_price_usd').value,bandwidth_gb:document.getElementById('pm_bw').value||0,duration_days:document.getElementById('pm_days').value,max_ips:document.getElementById('pm_max_ips').value,rotation:document.getElementById('pm_rotation').value,threads:document.getElementById('pm_threads').value,sort_order:document.getElementById('pm_sort').value,is_featured:document.getElementById('pm_featured').checked?1:0,features:feats,locations:locs})})
  .then(r=>r.json()).then(d=>{if(d.success){toast('Plan saved!','ok');setTimeout(()=>location.reload(),900);}else{toast(d.error||'Failed','err');btn.disabled=false;btn.textContent='Save Plan';}}).catch(()=>{toast('Network error','err');btn.disabled=false;btn.textContent='Save Plan';});
}
function togglePlan(id,val){fetch(BASE+'/api/proxy-admin-action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:CSRF,action:'toggle_plan',id,is_active:val})}).then(r=>r.json()).then(d=>{if(d.success)location.reload();else toast(d.error||'Failed','err');});}
function deletePlan(id,name){if(!confirm('Delete plan "'+name+'"?'))return;fetch(BASE+'/api/proxy-admin-action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:CSRF,action:'delete_plan',id})}).then(r=>r.json()).then(d=>{if(d.success){toast('Deleted','ok');setTimeout(()=>location.reload(),800);}else toast(d.error||'Failed','err');});}
document.getElementById('pm_name').addEventListener('input',function(){if(!document.getElementById('pm_id').value)document.getElementById('pm_slug').value=this.value.toLowerCase().replace(/\s+/g,'-').replace(/[^a-z0-9-]/g,'');});

function openProviderModal(pv){
  document.getElementById('provModalTitle').textContent=pv.name+' Settings';
  document.getElementById('pv_id').value=pv.id;document.getElementById('pv_api_key').value=pv.api_key||'';document.getElementById('pv_api_secret').value=pv.api_secret||'';document.getElementById('pv_whitelist_ips').value=pv.whitelisted_ips||'';document.getElementById('pv_notes').value=pv.notes||'';
  document.getElementById('pv_secret_row').style.display=pv.slug==='proxycheap'?'':'none';
  openModal('providerModal');
}
function saveProvider(){
  const btn=document.getElementById('saveProvBtn');btn.disabled=true;btn.innerHTML='<span class="spinner"></span> Saving…';
  fetch(BASE+'/api/proxy-admin-action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:CSRF,action:'save_provider',id:document.getElementById('pv_id').value,api_key:document.getElementById('pv_api_key').value,api_secret:document.getElementById('pv_api_secret').value,whitelisted_ips:document.getElementById('pv_whitelist_ips').value,notes:document.getElementById('pv_notes').value})})
  .then(r=>r.json()).then(d=>{if(d.success){toast('Provider settings saved!','ok');setTimeout(()=>location.reload(),900);}else{toast(d.error||'Failed','err');btn.disabled=false;btn.textContent='Save';}}).catch(()=>{toast('Network error','err');btn.disabled=false;btn.textContent='Save';});
}
function testProvider(id){const btn=document.getElementById('test-'+id);btn.disabled=true;btn.textContent='Testing…';fetch(BASE+'/api/proxy-admin-action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:CSRF,action:'test_provider',id})}).then(r=>r.json()).then(d=>{btn.disabled=false;btn.textContent='Test Connection';if(d.success)toast('✓ Connection OK! Balance: $'+(d.balance||'—'),'ok');else toast('✗ '+d.error,'err');}).catch(()=>{btn.disabled=false;btn.textContent='Test Connection';toast('Network error','err');});}

function toast(msg,type){
  const c={ok:'background:var(--success-light);color:var(--success);border:1px solid var(--success-border)',err:'background:var(--danger-light);color:var(--danger);border:1px solid var(--danger-border)',inf:'background:var(--info-light);color:var(--info);border:1px solid var(--info-border)'};
  const el=document.createElement('div');
  el.style.cssText=(c[type]||c.inf)+';padding:10px 16px;border-radius:var(--r-md);font-size:13px;font-weight:600;box-shadow:var(--shadow-md);display:flex;align-items:center;gap:8px;max-width:320px';
  const icon=type==='ok'?'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>':'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
  el.innerHTML=icon+msg;document.getElementById('toastWrap').appendChild(el);
  setTimeout(()=>{el.style.opacity='0';el.style.transition='opacity .3s';setTimeout(()=>el.remove(),300);},3200);
}
</script>
</body>
</html>
