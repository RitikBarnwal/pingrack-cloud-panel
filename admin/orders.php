<?php
/**
 * admin/orders.php — Received Orders (WHMCS-style).
 * Lists every package order (VPS + Dedicated) and lets an admin process a
 * pending order either MANUALLY (enter server details) or AUTOMATICALLY
 * (provision on Virtualizor). Also supports refund.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/servers.php';
require_admin();

$user = current_user();
$csrf = csrf_token();
$msg = ''; $err = '';

// Helper: email the customer their server details (non-fatal)
function notify_order_ready(array $order, string $extra_html = ''): void {
    try {
        require_once __DIR__ . '/../includes/mailer.php';
        if (!function_exists('send_mail')) return;
        $u = db()->prepare("SELECT email, full_name, username FROM users WHERE id=? LIMIT 1");
        $u->execute([$order['user_id']]); $u = $u->fetch();
        if (!$u) return;
        send_mail($u['email'], $u['full_name'] ?: $u['username'],
            APP_NAME . ' — Your server is ready',
            '<h2>Your server is ready 🎉</h2>'
            . '<p>Your order <strong>#' . (int)$order['id'] . '</strong> has been provisioned.</p>'
            . $extra_html
            . '<p><a href="' . BASE_URL . '/servers.php">Open your dashboard →</a></p>');
    } catch (Throwable $e) { error_log('[orders] notify: ' . $e->getMessage()); }
}

// ── POST actions ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    $oid    = (int)($_POST['order_id'] ?? 0);

    // Load the order + its package
    $o = db()->prepare("SELECT o.*, p.name AS pkg_name, p.ptype, p.provider_id AS pkg_provider,
                               p.virt_plid, p.virt_serid, p.virt_osid, p.os_label,
                               p.vcpu, p.ram_gb, p.disk_gb, p.bandwidth_gb, p.slug
                        FROM vps_package_orders o JOIN vps_packages p ON p.id=o.package_id
                        WHERE o.id=? LIMIT 1");
    $o->execute([$oid]);
    $order = $o->fetch();

    if (!$order) { $err = 'Order not found.'; }
    elseif ($action === 'refund') {
        if ($order['status'] !== 'refunded') {
            wallet_credit((int)$order['user_id'], (float)$order['amount'], 'Refund — order #' . $oid . ' cancelled by admin', 'refund', (int)$order['package_id']);
            db()->prepare("UPDATE vps_package_orders SET status='refunded', error='Refunded by admin' WHERE id=?")->execute([$oid]);
            $msg = "Order #$oid refunded (₹" . number_format((float)$order['amount'],2) . " returned to wallet).";
        }
    }
    elseif ($action === 'process_manual') {
        $hostname = trim($_POST['hostname'] ?? '') ?: ($order['slug'] . '-' . $oid);
        $ipv4     = trim($_POST['ipv4'] ?? '');
        $ipv6     = trim($_POST['ipv6'] ?? '');
        $rootpass = trim($_POST['root_pass'] ?? '');
        $months   = max(1, (int)$order['cycle_months']);
        $price_mo = round((float)$order['amount'] / $months, 2);

        $sid = db_create_server((int)$order['user_id'], [
            'provider_id'        => 0,
            'source_provider_id' => (int)$order['pkg_provider'],
            'name'               => $hostname,
            'status'             => 'running',
            'plan_slug'          => (string)$order['slug'],
            'image_slug'         => (string)$order['virt_osid'],
            'region_slug'        => (string)$order['virt_serid'],
            'vcpu'               => (int)$order['vcpu'],
            'ram_gb'             => (float)$order['ram_gb'],
            'disk_gb'            => (int)$order['disk_gb'],
            'ipv4'               => $ipv4 ?: null,
            'ipv6'               => $ipv6 ?: null,
            'os_label'           => (string)$order['os_label'],
            'region_label'       => (string)$order['virt_serid'],
            'region_flag'        => 'in',
            'price_hourly'       => 0.0,
            'price_monthly'      => $price_mo,
            'currency'           => 'INR',
            'root_password'      => $rootpass ?: null,
            'total_bandwidth_gb' => (int)$order['bandwidth_gb'],
            'used_bandwidth_gb'  => 0,
        ]);
        try {
            db()->prepare("UPDATE servers SET billing_type='prepaid', expires_at=? WHERE id=?")
                ->execute([$order['expires_at'], $sid]);
        } catch (Throwable $e) {}
        db()->prepare("UPDATE vps_package_orders SET status='active', server_id=? WHERE id=?")->execute([$sid, $oid]);

        notify_order_ready($order,
            '<p><strong>IP:</strong> ' . htmlspecialchars($ipv4 ?: '—') . '</p>'
            . ($rootpass ? '<p><strong>Root password:</strong> ' . htmlspecialchars($rootpass) . '</p>' : ''));
        $msg = "Order #$oid fulfilled manually — server #$sid created for the customer.";
    }
    elseif ($action === 'process_auto') {
        if (($order['ptype'] ?? 'vps') !== 'vps') { $err = 'Automatic provisioning is only available for VPS packages.'; }
        else {
            $prov = db()->prepare("SELECT * FROM providers WHERE id=? AND provider_type='virtualizor' LIMIT 1");
            $prov->execute([(int)$order['pkg_provider']]);
            $prov = $prov->fetch();
            if (!$prov || empty($prov['api_key'])) { $err = 'Virtualizor provider not configured for this package.'; }
            else {
                try {
                    require_once __DIR__ . '/../providers/virtualizor/client.php';
                    $client = new VirtualizorClient($prov);
                    $cust = db()->prepare("SELECT email, full_name, username FROM users WHERE id=? LIMIT 1");
                    $cust->execute([(int)$order['user_id']]); $cust = $cust->fetch() ?: ['email'=>'','full_name'=>'','username'=>''];

                    $root_pass = bin2hex(random_bytes(8)) . 'V!1';
                    $name = $order['slug'] . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
                    $raw = $client->post('addvs', [], [
                        'hostname'=>$name, 'rootpass'=>$root_pass,
                        'osid'=>$order['virt_osid'], 'plid'=>$order['virt_plid'], 'serid'=>$order['virt_serid'],
                        'user_email'=>$cust['email'], 'user_fname'=>$cust['full_name'] ?: $cust['username'], 'user_lname'=>'',
                    ]);
                    if (($raw['done'] ?? 0) != 1) { throw new RuntimeException(VirtualizorClient::errMsg($raw, 'addvs failed')); }
                    $vpsid = $raw['vpsid'] ?? $raw['vps_info']['vpsid'] ?? null;
                    if (!$vpsid) throw new RuntimeException('No vpsid returned.');

                    $enc = base64_encode(openssl_encrypt($root_pass, 'AES-128-ECB', substr(hash('sha256', $prov['api_key']), 0, 16)));
                    $months = max(1, (int)$order['cycle_months']);
                    $sid = db_create_server((int)$order['user_id'], [
                        'provider_id'=>(int)$vpsid, 'source_provider_id'=>(int)$prov['id'],
                        'name'=>$name, 'status'=>'provisioning',
                        'plan_slug'=>(string)$order['virt_plid'], 'image_slug'=>(string)$order['virt_osid'], 'region_slug'=>(string)$order['virt_serid'],
                        'vcpu'=>(int)$order['vcpu'], 'ram_gb'=>(float)$order['ram_gb'], 'disk_gb'=>(int)$order['disk_gb'],
                        'os_label'=>(string)$order['os_label'], 'region_label'=>(string)$order['virt_serid'], 'region_flag'=>'in',
                        'price_hourly'=>0.0, 'price_monthly'=>round((float)$order['amount']/$months,2), 'currency'=>'INR',
                        'root_password'=>$enc, 'total_bandwidth_gb'=>(int)$order['bandwidth_gb'], 'used_bandwidth_gb'=>0,
                    ]);
                    try { db()->prepare("UPDATE servers SET billing_type='prepaid', expires_at=? WHERE id=?")->execute([$order['expires_at'], $sid]); } catch (Throwable $e) {}
                    db()->prepare("UPDATE vps_package_orders SET status='active', server_id=?, vpsid=? WHERE id=?")->execute([$sid, (string)$vpsid, $oid]);
                    $msg = "Order #$oid provisioned automatically — VPS $vpsid / server #$sid.";
                } catch (Throwable $e) {
                    $err = 'Auto-provision failed: ' . $e->getMessage();
                }
            }
        }
    }
}

// ── Load orders ───────────────────────────────────────────────
$filter = $_GET['status'] ?? 'all';
$where = $filter !== 'all' ? "WHERE o.status=" . db()->quote($filter) : '';
try {
    $orders = db()->query(
        "SELECT o.*, u.username, u.email, u.full_name, p.name AS pkg_name, p.ptype
         FROM vps_package_orders o
         JOIN users u ON u.id=o.user_id
         JOIN vps_packages p ON p.id=o.package_id
         $where ORDER BY o.created_at DESC LIMIT 300"
    )->fetchAll();
    $counts = [];
    foreach (db()->query("SELECT status, COUNT(*) c FROM vps_package_orders GROUP BY status")->fetchAll() as $r) $counts[$r['status']] = (int)$r['c'];
} catch (Throwable $e) { $orders = []; $counts = []; $err = 'Orders table not found. Run install-db.php.'; }
$pending_n = $counts['pending'] ?? 0;

function h($v){ return htmlspecialchars((string)$v); }
?><!DOCTYPE html>
<html lang="en"><head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Orders — <?= APP_NAME ?> Admin</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/admin/admin.css">
  <style>
    .ord-tabs{display:flex;gap:6px;margin-bottom:18px;flex-wrap:wrap}
    .ord-tab{padding:7px 14px;border:1px solid var(--border);border-radius:8px;background:#fff;font-size:13px;font-weight:700;color:var(--gray-600);text-decoration:none}
    .ord-tab.on{background:var(--gray-900);color:#fff;border-color:var(--gray-900)}
    .ord-tab .n{margin-left:6px;font-size:11px;background:#ef4444;color:#fff;padding:1px 7px;border-radius:99px}
    .badge-vps{background:#eff6ff;color:#2563eb}.badge-ded{background:#f5f3ff;color:#7c3aed}
    .modal-bd{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:200;align-items:center;justify-content:center;padding:16px}
    .modal-bd.open{display:flex}
    .modal-box{background:#fff;border-radius:14px;max-width:520px;width:100%;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.2)}
    .modal-hd{padding:16px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
    .modal-hd b{font-size:15px;color:var(--gray-900)}
    .modal-bdy{padding:20px 22px;max-height:70vh;overflow-y:auto}
    .seg{display:inline-flex;background:var(--gray-100);border-radius:9px;padding:3px;margin-bottom:16px}
    .seg button{padding:7px 14px;border:none;background:transparent;border-radius:6px;font-size:13px;font-weight:700;color:var(--gray-600);cursor:pointer}
    .seg button.on{background:#fff;color:var(--gray-900);box-shadow:0 1px 3px rgba(0,0,0,.1)}
  </style>
</head>
<div class="adm-mobile-bar">
  <button class="adm-ham" onclick="admToggleSidebar()" aria-label="Menu"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
  <span class="adm-mobile-title"><?= APP_NAME ?> <span style="font-size:9px;background:#dc2626;color:#fff;padding:2px 6px;border-radius:99px;font-weight:700;text-transform:uppercase;margin-left:4px">Admin</span></span>
</div>
<body>
<div class="adm-shell">
  <?php require_once __DIR__ . '/sidebar.php'; ?>
  <div class="adm-main">
    <div class="adm-topbar"><span class="adm-topbar-title">🧾 Orders</span></div>
    <div class="adm-content">

      <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

      <div class="ord-tabs">
        <?php foreach (['all'=>'All','pending'=>'Pending','active'=>'Active','refunded'=>'Refunded','failed'=>'Failed'] as $k=>$lbl): ?>
        <a href="?status=<?= $k ?>" class="ord-tab <?= $filter===$k?'on':'' ?>"><?= $lbl ?><?php if ($k==='pending' && $pending_n>0): ?><span class="n"><?= $pending_n ?></span><?php endif; ?></a>
        <?php endforeach; ?>
      </div>

      <div class="card">
        <div class="tbl-wrap"><table class="tbl">
          <thead><tr><th>#</th><th>Customer</th><th>Package</th><th>Type</th><th>Cycle</th><th>Amount</th><th>Status</th><th>Placed</th><th></th></tr></thead>
          <tbody>
          <?php if (!$orders): ?>
            <tr><td colspan="9" style="text-align:center;color:var(--gray-400);padding:26px">No orders<?= $filter!=='all' ? ' in this status' : ' yet' ?>.</td></tr>
          <?php else: foreach ($orders as $o):
            $ded = ($o['ptype'] ?? 'vps') === 'dedicated';
            $st = $o['status'];
            $stc = $st==='active'?'badge-green':($st==='pending'?'badge-yellow':($st==='refunded'?'badge-gray':'badge-red'));
          ?>
          <tr>
            <td style="font-weight:700">#<?= (int)$o['id'] ?></td>
            <td><?= h($o['full_name'] ?: $o['username']) ?><div style="font-size:11.5px;color:var(--gray-400)"><?= h($o['email']) ?></div></td>
            <td style="font-weight:600"><?= h($o['pkg_name']) ?></td>
            <td><span class="badge <?= $ded?'badge-ded':'badge-vps' ?>"><?= $ded?'Dedicated':'VPS' ?></span></td>
            <td><?= (int)$o['cycle_months'] ?> mo</td>
            <td style="font-family:var(--mono)">₹<?= number_format((float)$o['amount'],2) ?></td>
            <td><span class="badge <?= $stc ?>"><?= $st==='pending'?'Processing':ucfirst($st) ?></span></td>
            <td><?= date('d M Y, H:i', strtotime($o['created_at'])) ?></td>
            <td style="white-space:nowrap">
              <?php if ($st === 'pending' || $st === 'failed'): ?>
                <button class="btn btn-primary btn-sm" onclick='openProcess(<?= json_encode($o, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Process</button>
                <form method="POST" style="display:inline" onsubmit="return confirm('Refund this order to the customer wallet?')">
                  <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="refund"><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                  <button class="btn btn-ghost btn-sm">Refund</button>
                </form>
              <?php elseif ($st === 'active' && $o['server_id']): ?>
                <span style="font-size:12px;color:var(--gray-400)">Server #<?= (int)$o['server_id'] ?></span>
              <?php else: ?>—<?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>
  </div>
</div>

<!-- Process modal -->
<div class="modal-bd" id="procModal">
  <div class="modal-box">
    <div class="modal-hd"><b>Process Order <span id="pm_oid"></span></b><button onclick="document.getElementById('procModal').classList.remove('open')" style="background:none;border:none;font-size:20px;color:var(--gray-400);cursor:pointer">×</button></div>
    <div class="modal-bdy">
      <div style="font-size:13px;color:var(--gray-500);margin-bottom:14px" id="pm_info"></div>
      <div class="seg">
        <button type="button" id="seg-manual" class="on" onclick="setMode('manual')">✍️ Enter Manually</button>
        <button type="button" id="seg-auto" onclick="setMode('auto')">⚡ Automatic</button>
      </div>

      <!-- Manual -->
      <form method="POST" id="manualForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="process_manual"><input type="hidden" name="order_id" id="mf_oid">
        <div class="form-group"><label class="flabel">Hostname</label><input name="hostname" id="mf_host" class="form-control" placeholder="server-01"></div>
        <div class="form-group"><label class="flabel">IPv4 Address</label><input name="ipv4" class="form-control" placeholder="203.0.113.10"></div>
        <div class="form-group"><label class="flabel">IPv6 <span style="color:#94a3b8;font-weight:400">(optional)</span></label><input name="ipv6" class="form-control" placeholder="2001:db8::1"></div>
        <div class="form-group"><label class="flabel">Root Password <span style="color:#94a3b8;font-weight:400">(emailed to customer)</span></label><input name="root_pass" class="form-control" placeholder="Set the server's root password"></div>
        <div class="fnote" style="margin-bottom:14px">Creates the server record in the customer's account, marks the order active and emails them the details.</div>
        <button class="btn btn-primary btn-full">Create Server &amp; Mark Active</button>
      </form>

      <!-- Auto -->
      <form method="POST" id="autoForm" style="display:none">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="process_auto"><input type="hidden" name="order_id" id="af_oid">
        <div class="fnote" style="margin-bottom:14px" id="af_note">Provisions the server automatically on the package's Virtualizor provider (addvs), then assigns it to the customer.</div>
        <button class="btn btn-primary btn-full" id="af_btn">⚡ Provision Automatically</button>
      </form>
    </div>
  </div>
</div>

<script>
function openProcess(o){
  document.getElementById('pm_oid').textContent = '#' + o.id;
  document.getElementById('pm_info').innerHTML = '<b>'+esc(o.pkg_name)+'</b> · '+(o.ptype==='dedicated'?'Dedicated':'VPS')+' · '+o.cycle_months+' mo · ₹'+Number(o.amount).toLocaleString('en-IN');
  document.getElementById('mf_oid').value = o.id;
  document.getElementById('af_oid').value = o.id;
  document.getElementById('mf_host').value = (o.slug||'srv')+'-'+o.id;
  var af=document.getElementById('af_btn'), note=document.getElementById('af_note');
  if(o.ptype==='dedicated'){ af.disabled=true; af.style.opacity=.5; note.innerHTML='<span style="color:#dc2626">Automatic provisioning is only for VPS packages. Use manual entry for dedicated servers.</span>'; setMode('manual'); }
  else { af.disabled=false; af.style.opacity=1; note.textContent='Provisions the server automatically on the package’s Virtualizor provider (addvs), then assigns it to the customer.'; }
  document.getElementById('procModal').classList.add('open');
}
function setMode(m){
  document.getElementById('seg-manual').classList.toggle('on', m==='manual');
  document.getElementById('seg-auto').classList.toggle('on', m==='auto');
  document.getElementById('manualForm').style.display = m==='manual'?'block':'none';
  document.getElementById('autoForm').style.display   = m==='auto'?'block':'none';
}
function esc(s){ return String(s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
</script>
</body></html>
