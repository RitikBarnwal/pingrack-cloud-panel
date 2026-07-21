<?php
/**
 * dns/manage.php — DNS Records Manager
 * Full CRUD for DNS records with inline editing
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/dns.php';
require_login();

$user     = current_user();
$uid      = (int)$user['id'];
$app_name = APP_NAME;
$csrf     = csrf_token();
$uname    = htmlspecialchars($user['username']);
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$balance  = (float)$user['wallet_balance'];
$curr_sym = user_currency_symbol(strtoupper($user['currency'] ?? 'INR'));

$zone_id = (int)($_GET['id'] ?? 0);
$zone    = dns_get_zone($zone_id, $uid);
if (!$zone) { header('Location: ' . BASE_URL . '/dns.php'); exit; }

$is_new  = isset($_GET['new']);
$msg = ''; $err = '';

// ── Check NS status if requested ─────────────────────────────
if (isset($_GET['check']) && $zone['cf_zone_id']) {
    try {
        $cf_status = dns_check_zone($zone['cf_zone_id']);
        $new_status = match($cf_status) {
            'active' => 'active',
            'pending', 'initializing' => 'pending',
            default  => 'pending',
        };
        db()->prepare('UPDATE dns_zones SET status=?, last_checked_at=NOW() WHERE id=?')
           ->execute([$new_status, $zone_id]);
        $zone['status'] = $new_status;
        $msg = $new_status === 'active'
            ? '✓ Domain is now active! Nameservers verified.'
            : '⏳ Nameservers not yet updated. Please update at your registrar and check again.';
    } catch (Throwable $e) { $err = $e->getMessage(); }
}

// ── POST: Add / Edit / Delete record ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $act = $_POST['action'] ?? '';

    // Add record
    if ($act === 'add_record') {
        $type     = strtoupper(trim($_POST['type'] ?? ''));
        $name     = trim($_POST['name'] ?? '');
        $content  = trim($_POST['content'] ?? '');
        $ttl      = (int)($_POST['ttl'] ?? 1);
        $priority = isset($_POST['priority']) ? (int)$_POST['priority'] : null;
        $proxied  = isset($_POST['proxied']) ? 1 : 0;

        if (!$type || !$name || !$content) {
            $err = 'Type, Name and Value are required.';
        } elseif ($zone['status'] !== 'active' && !$zone['cf_zone_id']) {
            $err = 'Zone not active yet.';
        } else {
            try {
                $record_data = compact('type','name','content','ttl','priority','proxied');
                $cf_record_id = dns_add_record($zone['cf_zone_id'], $record_data);
                db()->prepare(
                    'INSERT INTO dns_records (zone_id,user_id,cf_record_id,type,name,content,ttl,priority,proxied)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                )->execute([$zone_id,$uid,$cf_record_id,$type,$name,$content,$ttl,$priority,$proxied]);
                $msg = "Record added: {$type} {$name}";
            } catch (Throwable $e) { $err = $e->getMessage(); }
        }
    }

    // Update record
    if ($act === 'update_record') {
        $rid      = (int)($_POST['record_id'] ?? 0);
        $type     = strtoupper(trim($_POST['type']    ?? ''));
        $name     = trim($_POST['name']    ?? '');
        $content  = trim($_POST['content'] ?? '');
        $ttl      = (int)($_POST['ttl']    ?? 1);
        $priority = isset($_POST['priority']) && $_POST['priority'] !== '' ? (int)$_POST['priority'] : null;
        $proxied  = isset($_POST['proxied']) ? 1 : 0;

        if (!$type || !$name || !$content) {
            $err = 'Type, Name and Value are required.';
        } else {
            $rec = db()->prepare('SELECT * FROM dns_records WHERE id=? AND zone_id=? LIMIT 1');
            $rec->execute([$rid, $zone_id]);
            $rec = $rec->fetch();
            if ($rec) {
                try {
                    $record_data = compact('type','name','content','ttl','priority','proxied');
                    if ($rec['cf_record_id']) {
                        dns_update_record($zone['cf_zone_id'], $rec['cf_record_id'], $record_data);
                    }
                    db()->prepare(
                        'UPDATE dns_records SET type=?,name=?,content=?,ttl=?,priority=?,proxied=?,updated_at=NOW() WHERE id=?'
                    )->execute([$type,$name,$content,$ttl,$priority,$proxied,$rid]);
                    $msg = "Record updated: {$type} {$name}";
                } catch (Throwable $e) { $err = $e->getMessage(); }
            }
        }
    }

    // ── Purge Cache ──────────────────────────────────────────
    if ($act === 'purge_cache') {
        try {
            dns_cf_request('POST', '/zones/' . $zone['cf_zone_id'] . '/purge_cache', ['purge_everything' => true]);
            $msg = '✓ Cache purged successfully! All cached files cleared from Cloudflare edge.';
        } catch (Throwable $e) { $err = 'Purge failed: ' . $e->getMessage(); }
    }

    // ── Development Mode toggle ───────────────────────────────
    if ($act === 'dev_mode') {
        $enable = ($_POST['dev_mode_value'] ?? 'on') === 'on';
        try {
            dns_cf_request('PATCH', '/zones/' . $zone['cf_zone_id'] . '/settings/development_mode', [
                'value' => $enable ? 'on' : 'off'
            ]);
            $msg = $enable
                ? '🔧 Development Mode enabled! Cache bypassed for 3 hours — changes visible instantly.'
                : '✓ Development Mode disabled. Cloudflare caching resumed.';
        } catch (Throwable $e) { $err = 'Dev mode change failed: ' . $e->getMessage(); }
    }

    // Delete record
    if ($act === 'delete_record') {
        $rid = (int)($_POST['record_id'] ?? 0);
        $rec = db()->prepare('SELECT * FROM dns_records WHERE id=? AND zone_id=? LIMIT 1');
        $rec->execute([$rid, $zone_id]);
        $rec = $rec->fetch();
        if ($rec) {
            try {
                if ($rec['cf_record_id']) dns_delete_record($zone['cf_zone_id'], $rec['cf_record_id']);
                db()->prepare('DELETE FROM dns_records WHERE id=?')->execute([$rid]);
                $msg = 'Record deleted.';
            } catch (Throwable $e) { $err = $e->getMessage(); }
        }
    }
}

$records  = dns_get_records($zone_id);
$ns_list  = $zone['nameservers'] ? json_decode($zone['nameservers'], true) : [];
$ttl_opts = dns_ttl_options();

// Fetch Cloudflare Development Mode status (only if active zone)
$dev_mode_on = false;
if ($zone['cf_zone_id'] && $zone['status'] === 'active') {
    try {
        $dm = dns_cf_request('GET', '/zones/' . $zone['cf_zone_id'] . '/settings/development_mode');
        $dev_mode_on = ($dm['result']['value'] ?? 'off') === 'on';
    } catch (Throwable $e) { /* ignore */ }
}

// Group records by type for display
$grouped = [];
foreach ($records as $r) $grouped[$r['type']][] = $r;
ksort($grouped);

$record_types = ['A','AAAA','CNAME','MX','TXT','NS','SRV','CAA','PTR'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title><?= htmlspecialchars($zone['domain']) ?> — DNS — <?= $app_name ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    /* Records table */
    .dns-table{width:100%;border-collapse:collapse}
    .dns-table th{padding:9px 13px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--gray-500);border-bottom:1px solid var(--border);text-align:left;background:#fafbfd;white-space:nowrap}
    .dns-table td{padding:10px 13px;border-bottom:1px solid var(--gray-50);font-size:13px;vertical-align:middle}
    .dns-table tr:last-child td{border:none}
    .dns-table tr:hover td{background:#fafbfd}
    .type-badge{display:inline-block;padding:2px 8px;border-radius:5px;font-size:11px;font-weight:800;font-family:var(--mono)}
    .type-A{background:#eff6ff;color:#2563eb}
    .type-AAAA{background:#f5f3ff;color:#7c3aed}
    .type-CNAME{background:#f0fdf4;color:#16a34a}
    .type-MX{background:#fff7ed;color:#c2410c}
    .type-TXT{background:#fef9c3;color:#92400e}
    .type-NS{background:#f0f9ff;color:#0369a1}
    .type-SRV,.type-CAA,.type-PTR{background:#f8fafc;color:#64748b}
    .mono-val{font-family:var(--mono);font-size:12px;word-break:break-all;max-width:280px}
    .proxied-pill{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:6px;white-space:nowrap}
    .proxied-on{background:#fff7ed;border:1px solid #fed7aa;color:#c2410c}
    .proxied-off{background:#f8fafc;border:1px solid #e2e8f0;color:#94a3b8}
    .proxied-svg{width:18px;height:auto;display:inline-block;vertical-align:middle}

    /* Add record form card */
    .add-card{background:white;border:1.5px solid var(--border);border-radius:13px;overflow:hidden;margin-bottom:18px}
    .add-card-head{padding:12px 18px;border-bottom:1px solid var(--gray-100);background:#fafbfd;display:flex;align-items:center;gap:8px;cursor:pointer;user-select:none}
    .add-card-title{font-size:13.5px;font-weight:800;color:var(--gray-900);flex:1}
    .add-card-body{padding:18px}
    .fg-dns{display:grid;grid-template-columns:120px 1fr 2fr 100px;gap:10px;align-items:end;margin-bottom:10px}
    .fg-dns2{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px}
    .flbl{display:block;font-size:11px;font-weight:700;color:var(--gray-600);margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em}
    .finp{width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:13px;color:var(--gray-900);outline:none;transition:border-color .13s}
    .finp:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-ring)}
    select.finp{cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%2364748b'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:28px;appearance:none}

    /* NS banner */
    .ns-banner{background:#fef9c3;border:1.5px solid #fde047;border-radius:12px;padding:16px 18px;margin-bottom:16px}
    .ns-banner-head{font-size:14px;font-weight:800;color:#78350f;margin-bottom:10px;display:flex;align-items:center;gap:8px}
    .ns-row{display:flex;align-items:center;gap:10px;background:white;border:1px solid #e5e7eb;border-radius:8px;padding:9px 13px;margin-bottom:6px}
    .ns-val{font-family:var(--mono);font-size:13px;font-weight:700;color:#1f2937;flex:1}
    .ns-copy{padding:4px 10px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .13s;flex-shrink:0}
    .ns-copy:hover{background:#e2e8f0}

    /* Delete btn */
    .del-btn{width:28px;height:28px;border-radius:7px;border:1px solid var(--border);background:white;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--gray-400);transition:all .13s}
    .del-btn:hover{background:#fef2f2;color:#dc2626;border-color:#fca5a5}
    .del-btn svg{width:13px;height:13px;pointer-events:none}
    .edit-rec-btn{width:28px;height:28px;border-radius:7px;border:1px solid #bfdbfe;background:#eff6ff;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#2563eb;transition:all .13s;flex-shrink:0}
    .edit-rec-btn:hover{background:#dbeafe;border-color:#93c5fd}
    .edit-rec-btn svg{pointer-events:none}

    /* Section header */
    .rec-group-hd{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--gray-400);margin:18px 0 6px;padding-left:2px}

    /* Records wrapper card */
    .rec-card{background:white;border:1.5px solid var(--border);border-radius:13px;overflow:hidden;margin-bottom:8px}
    .rec-card-head{padding:11px 16px;border-bottom:1px solid var(--gray-50);background:#fafbfd;display:flex;align-items:center;gap:8px}

    /* Btn save */
    .btn-save-rec{display:inline-flex;align-items:center;gap:5px;padding:9px 18px;background:var(--primary);color:white;border:none;border-radius:8px;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .15s}
    .btn-save-rec:hover{background:var(--primary-hover)}

    @media(max-width:900px){.fg-dns{grid-template-columns:100px 1fr;}.fg-dns .span-all{grid-column:1/-1}.mono-val{max-width:160px}}
    @media(max-width:640px){.dns-table .hide-sm{display:none}}
    @keyframes cf-pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.55;transform:scale(1.3)}}
    @keyframes cf-spin{to{transform:rotate(360deg)}}
  </style>
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-content" style="margin-left:260px;min-height:100vh;background:var(--gray-50)">

    <div class="mobile-bar">
      <button class="ham-btn" onclick="toggleSidebar()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
    </div>

    <div class="topbar">
      <a href="<?= BASE_URL ?>/dns.php" style="color:var(--gray-400);text-decoration:none;font-size:13px">← DNS</a>
      <span style="color:var(--gray-300);margin:0 8px">/</span>
      <span class="topbar-title"><?= htmlspecialchars($zone['domain']) ?></span>
      <div style="display:inline-flex;align-items:center;gap:7px;padding:4px 12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:7px;margin-left:8px">
        <svg style="width:20px;height:auto" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M27.2 13.5c-.3-4.8-4.3-8.5-9.2-8.5a9.2 9.2 0 0 0-8.5 5.7A5.5 5.5 0 0 0 4 16a5.5 5.5 0 0 0 5.5 5.5h16.4a4 4 0 0 0 1.3-7.9z" fill="#F6821F"/><path d="M19.8 20.5l.2-.7c.2-.6.1-1.2-.2-1.6-.3-.4-.8-.6-1.4-.7l-7.5-.1c-.1 0-.1-.1 0-.1l.2-.7c.2-.6.1-1.2-.2-1.6-.3-.4-.8-.6-1.4-.7l-7.5-.1c-.1 0-.2.1-.1.2l.2.6c.2.6.1 1.2-.2 1.6-.3.4-.8.6-1.4.7l7.5.1c.1 0 .1.1 0 .1l-.2.7c-.2.6-.1 1.2.2 1.6.3.4.8.6 1.4.7l7.5.1c.1 0 .2-.1.1-.2z" fill="#FBAD41"/></svg>
        <span style="font-size:11.5px;font-weight:700;color:#c2410c">Managed by Cloudflare</span>
      </div>
      <?php if ($zone['status'] === 'active'): ?>
      <span style="margin-left:6px" class="badge badge-green"><span style="width:5px;height:5px;border-radius:50%;background:#16a34a;display:inline-block"></span> Active</span>
      <?php else: ?>
      <span style="margin-left:6px" class="badge badge-yellow">⏳ Pending NS</span>
      <?php endif; ?>
      <div style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <a href="?id=<?= $zone_id ?>&check=1" style="padding:6px 12px;background:white;border:1.5px solid var(--border);border-radius:8px;font-size:12.5px;font-weight:700;color:var(--gray-700);text-decoration:none;display:inline-flex;align-items:center;gap:5px">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
          Check NS
        </a>
        <?php if ($zone['cf_zone_id'] && $zone['status'] === 'active'): ?>
        <!-- Purge Cache -->
        <button onclick="doCfAction('purge_cache', this)"
          style="padding:6px 12px;background:white;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12.5px;font-weight:700;color:#374151;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:5px;transition:all .15s"
          onmouseover="this.style.background='#fef9c3';this.style.borderColor='#fde047'"
          onmouseout="this.style.background='white';this.style.borderColor='#e2e8f0'">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3"/></svg>
          Purge Cache
        </button>
        <!-- Development Mode -->
        <button onclick="doCfAction('dev_mode', this, '<?= $dev_mode_on ? 'off' : 'on' ?>')"
          id="devmode-btn"
          style="padding:6px 12px;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:6px;transition:all .15s;border:1.5px solid <?= $dev_mode_on ? '#f97316' : '#e2e8f0' ?>;background:<?= $dev_mode_on ? '#fff7ed' : 'white' ?>;color:<?= $dev_mode_on ? '#c2410c' : '#374151' ?>">
          <span style="width:8px;height:8px;border-radius:50%;background:<?= $dev_mode_on ? '#f97316' : '#94a3b8' ?>;display:inline-block;flex-shrink:0;<?= $dev_mode_on ? 'animation:cf-pulse 1.4s ease-in-out infinite' : '' ?>"></span>
          Dev Mode <?= $dev_mode_on ? 'ON' : 'OFF' ?>
        </button>
        <?php endif; ?>
      </div>
    </div>

    <div style="padding:24px">

      <?php if ($msg): ?><div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:10px 15px;margin-bottom:14px;font-size:13px;font-weight:700;color:#15803d"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div style="background:#fef2f2;border:1.5px solid #fca5a5;border-radius:10px;padding:10px 15px;margin-bottom:14px;font-size:13px;font-weight:700;color:#dc2626"><?= htmlspecialchars($err) ?></div><?php endif; ?>
      <!-- Hidden elements for AJAX response parsing -->
      <?php if ($msg): ?><div class="cf-flash-ok" style="display:none"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="cf-flash-err" style="display:none"><?= htmlspecialchars($err) ?></div><?php endif; ?>

      <!-- NEW DOMAIN: Nameserver instructions -->
      <?php if ($zone['status'] === 'pending' && !empty($ns_list)): ?>
      <div class="ns-banner">
        <div class="ns-banner-head">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#92400e" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          Update Nameservers at Your Registrar
        </div>
        <div style="font-size:13px;color:#78350f;margin-bottom:12px;line-height:1.65">
          Login to your domain registrar (GoDaddy, Namecheap, BigRock etc.) and replace the existing nameservers with these two:
        </div>
        <?php foreach ($ns_list as $ns): ?>
        <div class="ns-row">
          <div class="ns-val"><?= htmlspecialchars($ns) ?></div>
          <button class="ns-copy" onclick="copyText('<?= htmlspecialchars($ns,ENT_QUOTES) ?>', this)">Copy</button>
        </div>
        <?php endforeach; ?>
        <div style="font-size:12px;color:#92400e;margin-top:10px;line-height:1.6">
          ⏱ After updating, propagation usually takes <strong>5–30 minutes</strong>. Click <strong>"Check NS"</strong> button above to verify.
        </div>
      </div>
      <?php endif; ?>

      <!-- Add Record Form -->
      <div class="add-card">
        <div class="add-card-head" onclick="toggleAddForm()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          <span class="add-card-title">Add DNS Record</span>
          <svg id="add-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--gray-400)" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <div class="add-card-body" id="add-form-body" style="<?= ($is_new || $err) ? '' : 'display:none' ?>">
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="add_record">

            <div class="fg-dns">
              <div>
                <label class="flbl">Type</label>
                <select name="type" id="rec-type" class="finp" onchange="onTypeChange(this.value)">
                  <?php foreach ($record_types as $t): ?>
                  <option value="<?= $t ?>" <?= ($_POST['type']??'')===$t?'selected':'' ?>><?= $t ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="flbl">Name (subdomain)</label>
                <input name="name" class="finp" placeholder="@ or www or sub" value="<?= htmlspecialchars($_POST['name'] ?? '@') ?>" required>
              </div>
              <div>
                <label class="flbl">Value / Content</label>
                <input name="content" id="rec-content" class="finp" placeholder="1.2.3.4" value="<?= htmlspecialchars($_POST['content'] ?? '') ?>" required>
              </div>
              <div>
                <label class="flbl">TTL</label>
                <select name="ttl" class="finp">
                  <?php foreach ($ttl_opts as $val => $lbl): ?>
                  <option value="<?= $val ?>" <?= ($val==1)?'selected':'' ?>><?= $lbl ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div style="display:flex;align-items:center;gap:16px;margin-bottom:14px;flex-wrap:wrap">
              <!-- Priority (MX/SRV) -->
              <div id="priority-wrap" style="display:none">
                <label class="flbl">Priority</label>
                <input type="number" name="priority" class="finp" style="width:90px" value="10" min="0" max="65535">
              </div>
              <!-- Proxied (A/AAAA/CNAME) -->
              <div id="proxied-wrap">
                <label style="display:flex;align-items:center;gap:9px;cursor:pointer;font-size:13px;font-weight:600;color:var(--gray-700)">
                  <input type="checkbox" name="proxied" value="1" style="width:15px;height:15px;accent-color:#f97316">
                  <div style="display:flex;align-items:center;gap:7px">
                    <svg style="width:28px;height:auto" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 104 39.5"><polygon fill="#999" points="104 20.12 94 10.62 94 16.12 0 16.12 0 24.12 94 24.12 94 29.62 104 20.12"/><path fill="#f68a1d" d="M74.5,39c-2.08,0-15.43-.13-28.34-.25-12.62-.12-25.68-.25-27.66-.25a8,8,0,0,1-1-15.93c0-.19,0-.38,0-.57a9.49,9.49,0,0,1,14.9-7.81,19.48,19.48,0,0,1,38.05,4.63A10.5,10.5,0,1,1,74.5,39Z"/><path fill="#fff" d="M51,1A19,19,0,0,1,70,19.59,10,10,0,1,1,74.5,38.5c-4.11,0-52-.5-56-.5a7.5,7.5,0,0,1-.44-15A8.47,8.47,0,0,1,18,22a9,9,0,0,1,14.68-7A19,19,0,0,1,51,1m0-1A20,20,0,0,0,32.13,13.42,10,10,0,0,0,17,22v.14A8.5,8.5,0,0,0,18.5,39c2,0,15,.13,27.66.25,12.91.12,26.26.25,28.34.25a11,11,0,1,0-3.61-21.39A20.1,20.1,0,0,0,51,0Z"/></svg>
                    <span>Cloudflare Proxy (Orange Cloud)</span>
                  </div>
                </label>
                <div style="font-size:11px;color:var(--gray-400);margin-top:5px;margin-left:24px">Hides origin IP · DDoS protection · Free SSL · Only for A/AAAA/CNAME</div>
              </div>
            </div>

            <button type="submit" data-loading="Adding Record..." class="btn-save-rec">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Save Record
            </button>
          </form>
        </div>
      </div>

      <!-- Records List -->
      <?php if (empty($records)): ?>
      <div style="background:white;border:1.5px solid var(--border);border-radius:13px;padding:40px;text-align:center;color:var(--gray-400);font-size:13px">
        No DNS records yet. Add your first record above.
      </div>
      <?php else: ?>
      <?php foreach ($grouped as $type => $recs): ?>
      <div class="rec-card">
        <div class="rec-card-head">
          <span class="type-badge type-<?= $type ?>"><?= $type ?></span>
          <span style="font-size:13px;font-weight:700;color:var(--gray-700)"><?= count($recs) ?> record<?= count($recs)>1?'s':'' ?></span>
        </div>
        <div style="overflow-x:auto">
          <table class="dns-table">
            <thead><tr>
              <th>Name</th>
              <th>Value</th>
              <th class="hide-sm">TTL</th>
              <th class="hide-sm">Proxy</th>
              <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($recs as $r): ?>
            <tr>
              <td style="font-family:var(--mono);font-weight:700;font-size:12.5px"><?= htmlspecialchars($r['name']) ?></td>
              <td>
                <div class="mono-val"><?= htmlspecialchars($r['content']) ?></div>
                <?php if ($r['priority']): ?><div style="font-size:11px;color:var(--gray-400)">Priority: <?= $r['priority'] ?></div><?php endif; ?>
              </td>
              <td class="hide-sm" style="font-size:12px;color:var(--gray-500)"><?= $ttl_opts[(int)$r['ttl']] ?? $r['ttl'] . 's' ?></td>
              <td class="hide-sm">
                <?php if (in_array($r['type'], ['A','AAAA','CNAME'])): ?>
                <span class="proxied-pill <?= $r['proxied'] ? 'proxied-on' : 'proxied-off' ?>">
                  <?php if ($r['proxied']): ?>
                  <svg class="proxied-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 104 39.5"><g><polygon fill="#999" points="104 20.12 94 10.62 94 16.12 0 16.12 0 24.12 94 24.12 94 29.62 104 20.12"/><path fill="#f68a1d" d="M74.5,39c-2.08,0-15.43-.13-28.34-.25-12.62-.12-25.68-.25-27.66-.25a8,8,0,0,1-1-15.93c0-.19,0-.38,0-.57a9.49,9.49,0,0,1,14.9-7.81,19.48,19.48,0,0,1,38.05,4.63A10.5,10.5,0,1,1,74.5,39Z"/><path fill="#fff" d="M51,1A19,19,0,0,1,70,19.59,10,10,0,1,1,74.5,38.5c-4.11,0-52-.5-56-.5a7.5,7.5,0,0,1-.44-15A8.47,8.47,0,0,1,18,22a9,9,0,0,1,14.68-7A19,19,0,0,1,51,1m0-1A20,20,0,0,0,32.13,13.42,10,10,0,0,0,17,22v.14A8.5,8.5,0,0,0,18.5,39c2,0,15,.13,27.66.25,12.91.12,26.26.25,28.34.25a11,11,0,1,0-3.61-21.39A20.1,20.1,0,0,0,51,0Z"/></g></svg>
                  Proxied
                  <?php else: ?>
                  <svg class="proxied-svg" fill="#92979b" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 90.5 59"><g><path d="M49,13.5V19L59,9.5,49,0V5.5H40.78a12.43,12.43,0,0,0-9.5,4.42L17.65,27.16a8.83,8.83,0,0,1-6.91,3.34H5l-5,8H13.39a11.27,11.27,0,0,0,9-4.48L35.05,17.18a9.81,9.81,0,0,1,7.66-3.68Z"/><path d="M80.5,39A10,10,0,0,0,76,40.09a19,19,0,0,0-37.3-4.57A9,9,0,0,0,24,42.5a8.47,8.47,0,0,0,.06,1,7.5,7.5,0,0,0,.44,15c4,0,51.89.5,56,.5a10,10,0,0,0,0-20Z"/></g></svg>
                  DNS only
                  <?php endif; ?>
                </span>
                <?php else: ?>
                <span style="color:var(--gray-300);font-size:12px">—</span>
                <?php endif; ?>
              </td>
              <td>
                <div style="display:flex;gap:5px;align-items:center">
                  <!-- Edit button — standalone, no form wrapper -->
                  <button type="button" class="edit-rec-btn" title="Edit record"
                    data-id="<?= $r['id'] ?>"
                    data-type="<?= htmlspecialchars($r['type'],ENT_QUOTES) ?>"
                    data-name="<?= htmlspecialchars($r['name'],ENT_QUOTES) ?>"
                    data-content="<?= htmlspecialchars($r['content'],ENT_QUOTES) ?>"
                    data-ttl="<?= (int)$r['ttl'] ?>"
                    data-priority="<?= $r['priority'] !== null ? (int)$r['priority'] : '' ?>"
                    data-proxied="<?= (int)$r['proxied'] ?>">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  </button>
                  <!-- Delete button -->
                  <form method="POST" style="display:inline" onsubmit="return confirm('Delete this record?')">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="delete_record">
                    <input type="hidden" name="record_id" value="<?= $r['id'] ?>">
                    <button type="submit" class="del-btn" title="Delete">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>

    </div>
  </div>
</div>

<div id="toast" style="position:fixed;bottom:18px;right:18px;padding:10px 16px;background:#0f172a;color:white;border-radius:8px;font-size:13px;font-weight:700;z-index:9999;transform:translateY(60px);opacity:0;transition:all .3s"></div>

<!-- ═══ EDIT RECORD MODAL ════════════════════════════════════ -->
<div id="edit-modal"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;padding:16px"
     onclick="if(event.target===this)closeEdit()">
  <div style="background:white;border-radius:14px;width:100%;max-width:500px;box-shadow:0 24px 64px rgba(0,0,0,.22);overflow:hidden">

    <!-- Modal header -->
    <div style="padding:16px 20px;border-bottom:1px solid var(--gray-100);background:#fafbfd;display:flex;align-items:center;gap:9px">
      <div style="width:30px;height:30px;background:#eff6ff;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
      </div>
      <div>
        <div style="font-size:14px;font-weight:800;color:var(--gray-900)">Edit DNS Record</div>
        <div style="font-size:12px;color:var(--gray-400);margin-top:1px">Changes sync to Cloudflare instantly</div>
      </div>
      <button onclick="closeEdit()" style="margin-left:auto;width:30px;height:30px;border:none;background:var(--gray-100);border-radius:7px;cursor:pointer;font-size:16px;color:var(--gray-500);display:flex;align-items:center;justify-content:center">×</button>
    </div>

    <!-- Modal form -->
    <form method="POST" id="edit-form">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="update_record">
      <input type="hidden" name="record_id" id="edit-record-id">

      <div style="padding:18px 20px;display:flex;flex-direction:column;gap:13px">

        <!-- Type + Name row -->
        <div style="display:grid;grid-template-columns:110px 1fr;gap:10px">
          <div>
            <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--gray-600);margin-bottom:4px">Type</label>
            <select name="type" id="edit-type" class="finp" onchange="editTypeChange(this.value)">
              <?php foreach (['A','AAAA','CNAME','MX','TXT','NS','SRV','CAA','PTR'] as $t): ?>
              <option value="<?= $t ?>"><?= $t ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--gray-600);margin-bottom:4px">Name</label>
            <input name="name" id="edit-name" class="finp" placeholder="@ or www or subdomain" required>
          </div>
        </div>

        <!-- Value -->
        <div>
          <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--gray-600);margin-bottom:4px">Value / Content</label>
          <input name="content" id="edit-content" class="finp" placeholder="1.2.3.4" required>
        </div>

        <!-- TTL + Priority -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div>
            <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--gray-600);margin-bottom:4px">TTL</label>
            <select name="ttl" id="edit-ttl" class="finp">
              <?php foreach (dns_ttl_options() as $val => $lbl): ?>
              <option value="<?= $val ?>"><?= $lbl ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div id="edit-priority-wrap" style="display:none">
            <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--gray-600);margin-bottom:4px">Priority</label>
            <input type="number" name="priority" id="edit-priority" class="finp" min="0" max="65535" value="10">
          </div>
        </div>

        <!-- Proxy toggle (A/AAAA/CNAME only) -->
        <div id="edit-proxied-wrap">
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:10px 13px;background:var(--gray-50);border:1.5px solid var(--border);border-radius:9px;transition:all .13s" id="edit-proxy-label">
            <input type="checkbox" name="proxied" id="edit-proxied" value="1" style="width:15px;height:15px;accent-color:#f97316">
            <div style="display:flex;align-items:center;gap:8px">
              <svg style="width:26px;height:auto" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 104 39.5"><polygon fill="#999" points="104 20.12 94 10.62 94 16.12 0 16.12 0 24.12 94 24.12 94 29.62 104 20.12"/><path fill="#f68a1d" d="M74.5,39c-2.08,0-15.43-.13-28.34-.25-12.62-.12-25.68-.25-27.66-.25a8,8,0,0,1-1-15.93c0-.19,0-.38,0-.57a9.49,9.49,0,0,1,14.9-7.81,19.48,19.48,0,0,1,38.05,4.63A10.5,10.5,0,1,1,74.5,39Z"/><path fill="#fff" d="M51,1A19,19,0,0,1,70,19.59,10,10,0,1,1,74.5,38.5c-4.11,0-52-.5-56-.5a7.5,7.5,0,0,1-.44-15A8.47,8.47,0,0,1,18,22a9,9,0,0,1,14.68-7A19,19,0,0,1,51,1m0-1A20,20,0,0,0,32.13,13.42,10,10,0,0,0,17,22v.14A8.5,8.5,0,0,0,18.5,39c2,0,15,.13,27.66.25,12.91.12,26.26.25,28.34.25a11,11,0,1,0-3.61-21.39A20.1,20.1,0,0,0,51,0Z"/></svg>
              <div>
                <div style="font-size:13px;font-weight:700;color:var(--gray-800)">Cloudflare Proxy</div>
                <div style="font-size:11.5px;color:var(--gray-400)">Hides IP · DDoS protection · Free SSL</div>
              </div>
            </div>
          </label>
        </div>

      </div>

      <!-- Modal footer -->
      <div style="padding:14px 20px;border-top:1px solid var(--gray-100);display:flex;gap:8px;align-items:center;background:#fafbfd">
        <button type="submit" data-loading="Updating..." style="flex:1;padding:10px;background:var(--primary);color:white;border:none;border-radius:9px;font-size:13.5px;font-weight:700;font-family:inherit;cursor:pointer;transition:background .13s;display:flex;align-items:center;justify-content:center;gap:6px">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
          Save Changes
        </button>
        <button type="button" onclick="closeEdit()" style="padding:10px 18px;background:white;border:1.5px solid var(--border);border-radius:9px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}

// ── Cloudflare AJAX Actions (Purge Cache / Dev Mode) ─────────
function doCfAction(action, btn, devModeVal) {
    var origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation:cf-spin 1s linear infinite"><path d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0"/></svg> Working...';

    var fd = new FormData();
    fd.append('csrf_token', '<?= $csrf ?>');
    fd.append('action', action);
    if (action === 'dev_mode') fd.append('dev_mode_value', devModeVal || 'on');

    fetch('?id=<?= $zone_id ?>', { method: 'POST', body: fd })
        .then(function(r){ return r.text(); })
        .then(function(html) {
            // Extract msg/err from returned page
            var parser = new DOMParser();
            var doc = parser.parseFromString(html, 'text/html');
            var msgEl = doc.querySelector('.cf-flash-ok');
            var errEl = doc.querySelector('.cf-flash-err');
            if (msgEl) showCfToast(msgEl.textContent.trim(), 'ok');
            else if (errEl) showCfToast(errEl.textContent.trim(), 'err');
            else showCfToast('Done!', 'ok');

            // Update Dev Mode button state
            if (action === 'dev_mode') {
                var isNowOn = devModeVal === 'on';
                btn.style.background    = isNowOn ? '#fff7ed' : 'white';
                btn.style.borderColor   = isNowOn ? '#f97316' : '#e2e8f0';
                btn.style.color         = isNowOn ? '#c2410c' : '#374151';
                btn.innerHTML = '<span style="width:8px;height:8px;border-radius:50%;background:' + (isNowOn?'#f97316':'#94a3b8') + ';display:inline-block;flex-shrink:0;' + (isNowOn?'animation:cf-pulse 1.4s ease-in-out infinite':'') + '"></span> Dev Mode ' + (isNowOn ? 'ON' : 'OFF');
                btn.setAttribute('onclick', "doCfAction('dev_mode', this, '" + (isNowOn ? 'off' : 'on') + "')");
            } else {
                btn.innerHTML = origHtml;
            }
            btn.disabled = false;
        })
        .catch(function() {
            showCfToast('Network error. Try again.', 'err');
            btn.innerHTML = origHtml;
            btn.disabled = false;
        });
}

function showCfToast(msg, type) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.style.background = type === 'ok' ? '#0f172a' : '#dc2626';
    t.style.transform = 'translateY(0)';
    t.style.opacity = '1';
    clearTimeout(window._cfToastTimer);
    window._cfToastTimer = setTimeout(function(){
        t.style.transform = 'translateY(60px)';
        t.style.opacity = '0';
    }, 4000);
}
function toggleAddForm(){
    var body = document.getElementById('add-form-body');
    var chev = document.getElementById('add-chevron');
    var open = body.style.display !== 'none';
    body.style.display = open ? 'none' : 'block';
    chev.style.transform = open ? '' : 'rotate(180deg)';
}
var PLACEHOLDERS = {A:'1.2.3.4',AAAA:'2001:db8::1',CNAME:'target.example.com',
    MX:'mail.example.com',TXT:'v=spf1 include:_spf.google.com ~all',
    NS:'ns1.example.com',SRV:'0 443 target.example.com',CAA:'0 issue "letsencrypt.org"',PTR:'hostname.example.com'};
var PROXIABLE = ['A','AAAA','CNAME'];

function onTypeChange(type) {
    var inp = document.getElementById('rec-content');
    if (inp) inp.placeholder = PLACEHOLDERS[type] || 'value';
    var pw   = document.getElementById('proxied-wrap');
    var priw = document.getElementById('priority-wrap');
    if (pw)   pw.style.display   = PROXIABLE.includes(type) ? 'block' : 'none';
    if (priw) priw.style.display = ['MX','SRV'].includes(type) ? 'block' : 'none';
}
onTypeChange('A');

// ── Edit modal — event delegation (reliable) ─────────────────
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.edit-rec-btn');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();

    var id      = btn.dataset.id;
    var type    = btn.dataset.type;
    var name    = btn.dataset.name;
    var content = btn.dataset.content;
    var ttl     = btn.dataset.ttl;
    var prio    = btn.dataset.priority;
    var proxied = btn.dataset.proxied;

    // Fill form
    document.getElementById('edit-record-id').value = id;
    document.getElementById('edit-type').value      = type;
    document.getElementById('edit-name').value      = name;
    document.getElementById('edit-content').value   = content;
    document.getElementById('edit-ttl').value       = ttl || 1;
    document.getElementById('edit-priority').value  = prio || 10;
    document.getElementById('edit-proxied').checked = proxied == '1';

    // Show/hide conditional fields
    editTypeChange(type);

    // Show modal
    document.getElementById('edit-modal').style.display = 'flex';
    setTimeout(function(){ document.getElementById('edit-content').focus(); }, 80);
});

function closeEdit() {
    document.getElementById('edit-modal').style.display = 'none';
}

function editTypeChange(type) {
    var priw = document.getElementById('edit-priority-wrap');
    var pw   = document.getElementById('edit-proxied-wrap');
    var inp  = document.getElementById('edit-content');
    if (priw) priw.style.display = ['MX','SRV'].includes(type) ? 'block' : 'none';
    if (pw)   pw.style.display   = PROXIABLE.includes(type) ? 'block' : 'none';
    if (inp)  inp.placeholder    = PLACEHOLDERS[type] || 'value';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeEdit();
});
function copyText(txt, btn) {
    navigator.clipboard.writeText(txt).then(function(){
        if(btn){var o=btn.textContent;btn.textContent='✓';setTimeout(function(){btn.textContent=o;},1500);}
        var t=document.getElementById('toast');
        t.textContent='Copied!';t.style.transform='translateY(0)';t.style.opacity='1';
        setTimeout(function(){t.style.transform='translateY(60px)';t.style.opacity='0';},2000);
    });
}
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function () {

        const btn = this.querySelector('button[type="submit"]');

        if (!btn) return;

        btn.disabled = true;

        const text = btn.dataset.loading || '';

        btn.innerHTML = `
            <span class="spinner"></span>
            ${text}
        `;
    });
});
</script>
</body>
</html>
