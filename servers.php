<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/servers.php';
require_once __DIR__ . '/includes/os_icons.php';
require_login();

$user     = current_user();
$app_name = APP_NAME;
$currency = strtoupper($user['currency'] ?? 'USD');
$curr_sym = user_currency_symbol($currency);
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$uname    = htmlspecialchars($user['username']);
$balance  = number_format((float)$user['wallet_balance'], 2);
$uid      = (int)$user['id'];
$csrf     = csrf_token();
$page_msg = '';
$page_err = '';

// ── POST: Custom Order & Claim Server ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    // ── Custom Order submit ───────────────────────────────
    if ($action === 'custom_order') {
        $server_type = $_POST['server_type'] ?? 'vps';
        $ram_gb      = trim($_POST['ram_gb']    ?? '');
        $cpu_cores   = trim($_POST['cpu_cores'] ?? '');
        $disk_size   = trim($_POST['disk_size'] ?? '');
        $disk_type   = $_POST['disk_type']   ?? 'ssd';
        $cpu_brand   = $_POST['cpu_brand']   ?? 'any';
        $os_pref     = trim($_POST['os_pref']  ?? '');
        $message     = trim($_POST['message']  ?? '');

        if (!$ram_gb || !$cpu_cores || !$disk_size) {
            $page_err = 'Please fill in RAM, CPU and Disk fields.';
        } else {
            db()->prepare(
                'INSERT INTO custom_orders (user_id,server_type,ram_gb,cpu_cores,disk_size,disk_type,cpu_brand,os_pref,message)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([$user['id'], $server_type, $ram_gb, $cpu_cores, $disk_size, $disk_type, $cpu_brand, $os_pref, $message]);

            // Email to admin
            try {
                require_once __DIR__ . '/includes/mailer.php';
                $admin_email = get_setting('company_email', get_setting('SMTP_FROM', ''));
                if ($admin_email) {
                    $disk_labels = ['hdd'=>'HDD (Spinning Disk)','ssd'=>'SSD (SATA)','nvme'=>'NVMe SSD'];
                    $cpu_labels  = ['intel'=>'Intel','amd'=>'AMD','any'=>'Any Brand'];
                    $body = "
                    <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto'>
                    <div style='background:#1a1a2e;padding:24px;border-radius:12px 12px 0 0'>
                      <h2 style='color:white;margin:0'>⚙️ Custom Server Order</h2>
                      <p style='color:#94a3b8;margin:4px 0 0'>" . APP_NAME . "</p>
                    </div>
                    <div style='background:#f8fafc;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;padding:28px'>
                      <table style='width:100%;border-collapse:collapse;font-size:14px'>
                        <tr><td style='padding:8px 0;color:#64748b;width:140px'>User</td><td style='font-weight:700'>" . htmlspecialchars($user['full_name'] ?: $user['username']) . " (ID: {$user['id']})</td></tr>
                        <tr><td style='padding:8px 0;color:#64748b'>Email</td><td>" . htmlspecialchars($user['email']) . "</td></tr>
                        <tr><td style='padding:8px 0;color:#64748b'>Server Type</td><td style='font-weight:700;text-transform:uppercase'>" . htmlspecialchars($server_type) . "</td></tr>
                        <tr><td style='padding:8px 0;color:#64748b'>RAM</td><td>" . htmlspecialchars($ram_gb) . " GB</td></tr>
                        <tr><td style='padding:8px 0;color:#64748b'>CPU Cores</td><td>" . htmlspecialchars($cpu_cores) . " vCPU/Cores — " . htmlspecialchars($cpu_labels[$cpu_brand] ?? $cpu_brand) . "</td></tr>
                        <tr><td style='padding:8px 0;color:#64748b'>Disk</td><td>" . htmlspecialchars($disk_size) . " GB — " . htmlspecialchars($disk_labels[$disk_type] ?? $disk_type) . "</td></tr>
                        <tr><td style='padding:8px 0;color:#64748b'>OS Preference</td><td>" . htmlspecialchars($os_pref ?: 'Not specified') . "</td></tr>
                      </table>
                      " . ($message ? "<div style='margin-top:16px;background:white;border:1px solid #e2e8f0;border-radius:8px;padding:14px'><div style='font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;margin-bottom:6px'>Additional Notes</div><div style='font-size:14px;color:#374151;line-height:1.6'>" . nl2br(htmlspecialchars($message)) . "</div></div>" : '') . "
                      <div style='margin-top:20px;padding:12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:13px;color:#1d4ed8'>
                        💡 Create the VPS on Virtualizor, then go to Admin Panel → Generate Claim Token and send the token to the user.
                      </div>
                    </div></div>";
                    send_mail($admin_email, APP_NAME . ' Admin', '⚙️ Custom Server Order from ' . ($user['full_name'] ?: $user['username']) . ' (ID: ' . $user['id'] . ')', $body);
                }
            } catch (Throwable $e) { error_log('[custom_order] mail: ' . $e->getMessage()); }

            $page_msg = '__custom_order_sent__';
        }
    }

    // ── Claim Server ──────────────────────────────────────
    if ($action === 'claim_server') {
        $token = strtoupper(trim(preg_replace('/[^A-Z0-9\-]/i', '', $_POST['claim_token'] ?? '')));
        if (strlen($token) < 6) {
            $page_err = 'Invalid claim code.';
        } else {
            $tk = db()->prepare('SELECT * FROM server_claim_tokens WHERE token=? AND user_id IS NULL LIMIT 1');
            $tk->execute([$token]);
            $claim = $tk->fetch();

            if (!$claim) {
                // Check if already claimed by this user
                $mine = db()->prepare('SELECT * FROM server_claim_tokens WHERE token=? AND user_id=? LIMIT 1');
                $mine->execute([$token, $user['id']]);
                if ($mine->fetch()) {
                    $page_err = 'You have already claimed this server.';
                } else {
                    $page_err = 'Invalid or already claimed token. Please check and try again.';
                }
            } elseif ($claim['expires_at'] && strtotime($claim['expires_at']) < time()) {
                $page_err = 'This claim token has expired. Please contact support.';
            } else {
                // ── Fetch live VPS data from provider ───────────
                $vps_data   = [];
                $claim_ptype = strtolower($claim['provider_type'] ?? 'virtualizor');
                try {
                    $prov = db()->prepare('SELECT * FROM providers WHERE id=? LIMIT 1');
                    $prov->execute([$claim['provider_id']]);
                    $provider = $prov->fetch();
                    if ($provider && $provider['api_key']) {
                        $bs = __DIR__ . '/providers/' . $claim_ptype . '/bootstrap.php';
                        if (file_exists($bs)) {
                            require_once $bs;
                            CloudProvider::reset();
                            $cloud_claim = new CloudProvider($provider);
                            $vps_data = $cloud_claim->servers->get((int)$claim['vps_id']);
                        }
                    }
                } catch (Throwable $e) {
                    error_log('[claim] ' . $claim_ptype . ' fetch: ' . $e->getMessage());
                }

                // Build server row from claim data + live VPS data
                $ipv4       = $vps_data['ipv4']      ?? null;
                $ipv6       = $vps_data['ipv6']      ?? null;
                $vcpu       = $vps_data['vcpu']      ?? (int)$claim['vcpu'];
                $ram_gb     = $vps_data['ram_gb']    ?? (float)$claim['ram_gb'];
                $disk_gb    = $vps_data['disk_gb']   ?? (int)$claim['disk_gb'];
                $os_label   = $vps_data['os_label']  ?? $claim['os_label']  ?? 'Linux';
                $status     = $vps_data['status']    ?? 'running';
                $srv_name   = $vps_data['name']      ?? $claim['server_name'] ?? ('claimed-' . strtolower($token));
                $compSlug = $claim['region_slug']; // e.g. "Noida, India"
                $parts = array_map('trim', explode(',', $compSlug));
                $region_slug = $parts[0] ?? '';
                $region_lbl  = $claim['region_slug'];
                $region_flag = $claim['region_label'] ?? '';
                $plan_slug  = $vps_data['plan_slug'] ?? $claim['plan_slug']  ?? 'custom';
                $price_hr   = (float)$claim['price_hourly'];
                $currency   = $claim['currency'] ?? 'INR';
                $total_bandwidth_gb = isset($vps_data['bandwidth_gb']) ? (float)$vps_data['bandwidth_gb'] : 0;
                $used_bandwidth_gb = isset($vps_data['used_bandwidth_gb']) ? (float)$vps_data['used_bandwidth_gb'] : 0;

                // Insert into servers table
                db()->prepare(
                    'INSERT INTO servers
                     (user_id, provider_id, source_provider_id, name, status, plan_slug, image_slug,
                      region_slug, region_label, region_flag, vcpu, ram_gb, disk_gb, ipv4, ipv6,
                      os_label, price_hourly, price_monthly, currency,total_bandwidth_gb,used_bandwidth_gb)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $user['id'],
                    (int)$claim['vps_id'],         // provider_id = actual Virtualizor VPS ID (e.g. 4)
                    (int)$claim['provider_id'],    // source_provider_id = providers table row ID (e.g. 9)
                    $srv_name, $status, $plan_slug, strtolower(explode(' ', $os_label)[0]),
                    $region_slug, $region_lbl, $region_flag, $vcpu, $ram_gb, $disk_gb,
                    $ipv4, $ipv6, $os_label,
                    $price_hr, round($price_hr * 730, 2), $currency,$total_bandwidth_gb,$used_bandwidth_gb,
                ]);

                // Mark token as claimed
                db()->prepare('UPDATE server_claim_tokens SET user_id=?, claimed_at=NOW() WHERE id=?')
                   ->execute([$user['id'], $claim['id']]);

                $page_msg = '__claimed__';
            }
        }
    }
}

// ── Counts ──────────────────────────────────────────────────
// My Servers = VPS only. A server is "dedicated" if it's linked to a dedicated
// package order (robust — works even before the server_type column exists).
$ded_ids = [];
try {
    $ded_ids = db()->query(
        "SELECT DISTINCT o.server_id FROM vps_package_orders o
         JOIN vps_packages p ON p.id=o.package_id
         WHERE p.ptype='dedicated' AND o.server_id IS NOT NULL"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}
// Also respect the server_type column if present.
$has_type_col = false;
try { foreach (db()->query("SHOW COLUMNS FROM servers LIKE 'server_type'")->fetchAll() as $c) $has_type_col = true; } catch (Throwable $e) {}
$excl_conds = [];
if ($ded_ids)      $excl_conds[] = 'id NOT IN (' . implode(',', array_map('intval', $ded_ids)) . ')';
if ($has_type_col) $excl_conds[] = "COALESCE(server_type,'vps') <> 'dedicated'";
$type_and = $excl_conds ? ' AND ' . implode(' AND ', $excl_conds) : '';

$counts = [
    'all'         => (int)db()->query("SELECT COUNT(*) FROM servers WHERE user_id=$uid AND deleted_at IS NULL$type_and")->fetchColumn(),
    'running'     => (int)db()->query("SELECT COUNT(*) FROM servers WHERE user_id=$uid AND status='running' AND deleted_at IS NULL$type_and")->fetchColumn(),
    'stopped'     => (int)db()->query("SELECT COUNT(*) FROM servers WHERE user_id=$uid AND status='stopped' AND deleted_at IS NULL$type_and")->fetchColumn(),
    'suspended'   => (int)db()->query("SELECT COUNT(*) FROM servers WHERE user_id=$uid AND status='suspended' AND deleted_at IS NULL$type_and")->fetchColumn(),
    'provisioning'=> (int)db()->query("SELECT COUNT(*) FROM servers WHERE user_id=$uid AND status='provisioning' AND deleted_at IS NULL$type_and")->fetchColumn(),
];

// ── Filters ──────────────────────────────────────────────────
$filter = $_GET['status'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['p'] ?? 1));
$per    = 10;
$offset = ($page - 1) * $per;

// ── Query ────────────────────────────────────────────────────
$where  = array_merge(['user_id = ?', 'deleted_at IS NULL'], $excl_conds);
$params = [$uid];

if ($filter !== 'all') {
    $where[]  = 'status = ?';
    $params[] = $filter;
}
if ($search !== '') {
    $where[]  = '(name LIKE ? OR ipv4 LIKE ? OR os_label LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

// Total for current filter
$count_st = db()->prepare("SELECT COUNT(*) FROM servers $where_sql");
$count_st->execute($params);
$total = (int)$count_st->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per));
$page = min($page, $total_pages);

// Fetch page
$fetch_params = array_merge($params, [$per, $offset]);
$st = db()->prepare("SELECT * FROM servers $where_sql ORDER BY created_at DESC LIMIT ? OFFSET ?");
$st->execute($fetch_params);
$servers = $st->fetchAll();

// OS icon
function os_icon_srv(string $os_label, int $size = 24): string {
    return os_icon_img($os_label, $size);
}

function sbadge(string $status): string {
    return match($status) {
        'running'      => '<span class="badge badge-green"><span class="sdot sdot-green"></span>Running</span>',
        'stopped'      => '<span class="badge badge-gray"><span class="sdot sdot-gray"></span>Stopped</span>',
        'provisioning' => '<span class="badge badge-blue"><span class="sdot sdot-blue sdot-pulse"></span>Provisioning</span>',
        'starting'     => '<span class="badge badge-blue"><span class="sdot sdot-blue sdot-pulse"></span>Starting</span>',
        'stopping'     => '<span class="badge badge-yellow"><span class="sdot sdot-yellow sdot-pulse"></span>Stopping</span>',
        'suspended'    => '<span class="badge badge-red"><span class="sdot sdot-red"></span>Suspended</span>',
        'rebuilding'   => '<span class="badge badge-yellow"><span class="sdot sdot-yellow sdot-pulse"></span>Rebuilding</span>',
        'error'        => '<span class="badge badge-red"><span class="sdot sdot-red"></span>Error</span>',
        default        => '<span class="badge badge-gray">Unknown</span>',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>My Servers — <?= $app_name ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    /* Status dots */
    .sdot{display:inline-block;width:7px;height:7px;border-radius:50%;flex-shrink:0;margin-right:4px}
    .sdot-green{background:#16a34a}.sdot-gray{background:#9ca3af}
    .sdot-blue{background:#2563eb}.sdot-yellow{background:#d97706}.sdot-red{background:#dc2626}
    .sdot-pulse{animation:sdp 1.4s ease-in-out infinite}
    @keyframes sdp{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.4)}}

    /* Top strip */
    .page-topstrip{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;gap:12px;flex-wrap:wrap}
    .page-heading{font-size:20px;font-weight:900;color:var(--gray-900);letter-spacing:-.5px}

    /* Filter + search bar */
    .filter-wrap{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap}
    .filter-tabs{display:flex;background:var(--gray-100);border-radius:9px;padding:3px;gap:2px}
    .ftab{display:inline-flex;align-items:center;gap:5px;padding:6px 13px;border-radius:7px;font-size:13px;font-weight:600;color:var(--gray-600);cursor:pointer;text-decoration:none;transition:all .13s;white-space:nowrap}
    .ftab:hover{background:rgba(255,255,255,.7);color:var(--gray-800)}
    .ftab.active{background:white;color:var(--gray-900);box-shadow:0 1px 4px rgba(0,0,0,.08)}
    .ftab-count{background:var(--gray-200);border-radius:99px;padding:1px 7px;font-size:10.5px;font-weight:700;line-height:1.5}
    .ftab.active .ftab-count{background:var(--primary);color:white}
    .search-wrap{position:relative;flex:1;min-width:180px;max-width:280px}
    .search-wrap svg{position:absolute;left:10px;top:50%;transform:translateY(-50%);width:14px;height:14px;color:var(--gray-400);pointer-events:none}
    .search-input{width:100%;padding:7px 10px 7px 32px;border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:13px;color:var(--gray-900);background:white;outline:none;transition:border-color .13s}
    .search-input:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-ring)}
    .btn-clear-search{display:none;position:absolute;right:8px;top:50%;transform:translateY(-50%);background:var(--gray-200);border:none;border-radius:50%;width:18px;height:18px;cursor:pointer;align-items:center;justify-content:center;font-size:12px;color:var(--gray-600);line-height:1}

    /* Server cards */
    .srv-cards{display:flex;flex-direction:column;gap:10px}
    .srv-card{background:white;border:1.5px solid var(--border);border-radius:13px;padding:16px 18px;display:flex;align-items:center;gap:14px;transition:all .16s;cursor:default}
    .srv-card:hover{border-color:var(--gray-300);box-shadow:0 4px 16px rgba(0,0,0,.06);transform:translateY(-1px)}

    /* OS icon col */
    .os-icon-wrap{width:44px;height:44px;border-radius:11px;background:var(--gray-50);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden}

    /* Main info */
    .srv-info{flex:1;min-width:0}
    .srv-info-name{font-size:14px;font-weight:800;color:var(--gray-900);display:flex;align-items:center;gap:7px;flex-wrap:wrap;line-height:1.3}
    .srv-info-name a{color:inherit;text-decoration:none}
    .srv-info-name a:hover{text-decoration:underline;text-underline-offset:2px}
    .srv-info-sub{display:flex;align-items:center;gap:8px;margin-top:5px;flex-wrap:wrap}
    .srv-info-ip{font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--gray-600);font-weight:500}
    .srv-info-os{font-size:12px;color:var(--gray-400)}
    .srv-info-date{font-size:11.5px;color:var(--gray-400)}
    .info-sep{color:var(--gray-200);font-size:12px}

    /* Specs chips */
    .specs-group{display:flex;gap:5px;flex-wrap:wrap;flex-shrink:0}
    .spec-chip{padding:3px 8px;background:var(--gray-100);border-radius:5px;font-size:11.5px;font-weight:600;color:var(--gray-600);font-family:'JetBrains Mono',monospace;white-space:nowrap}

    /* Region */
    .region-col{display:flex;align-items:center;gap:6px;font-size:12.5px;color:var(--gray-600);font-weight:500;flex-shrink:0;min-width:110px}
    .region-col img{border-radius:2px;box-shadow:0 0 0 1px rgba(0,0,0,.08);flex-shrink:0}

    /* Price */
    .price-col{text-align:right;flex-shrink:0;min-width:80px}
    .price-main{font-size:13px;font-weight:800;color:var(--gray-800)}
    .price-sub{font-size:11px;color:var(--gray-400);margin-top:1px}

    /* Actions */
    .actions-col{display:flex;gap:5px;flex-shrink:0}
    .act-btn{width:32px;height:32px;border-radius:8px;border:1px solid var(--border);background:white;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--gray-500);transition:all .13s;text-decoration:none;flex-shrink:0}
    .act-btn:hover{background:var(--gray-100);color:var(--gray-900)}
    .act-btn.danger:hover{background:#fef2f2;color:var(--danger);border-color:#fca5a5}
    .act-btn.power-on{border-color:#bbf7d0;color:#16a34a}
    .act-btn.power-on:hover{background:#f0fdf4}
    .act-btn svg{width:14px;height:14px;pointer-events:none}

    /* Pagination */
    .paging{display:flex;align-items:center;justify-content:space-between;margin-top:14px;flex-wrap:wrap;gap:10px}
    .paging-info{font-size:12.5px;color:var(--gray-400)}
    .paging-btns{display:flex;gap:4px;align-items:center}
    .pbtn{min-width:34px;height:34px;padding:0 6px;border-radius:8px;border:1px solid var(--border);background:white;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:var(--gray-600);transition:all .13s;font-family:inherit}
    .pbtn:hover:not(:disabled){background:var(--gray-100)}
    .pbtn.active{background:var(--primary);color:white;border-color:var(--primary)}
    .pbtn:disabled{opacity:.35;cursor:not-allowed}
    .pbtn-ellipsis{min-width:22px;display:flex;align-items:center;justify-content:center;color:var(--gray-400);font-size:13px}

    /* Empty / loading states */
    .empty-wrap{background:white;border:1px solid var(--border);border-radius:13px;padding:60px 24px;text-align:center}
    .empty-ico{width:54px;height:54px;border-radius:14px;background:var(--gray-100);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;margin:0 auto 14px}
    .empty-ico svg{width:24px;height:24px;color:var(--gray-400)}
    .empty-h{font-size:16px;font-weight:800;color:var(--gray-800);margin-bottom:5px}
    .empty-p{font-size:13.5px;color:var(--gray-500);margin-bottom:20px;line-height:1.6}

    /* Loading shimmer */
    .shimmer{background:linear-gradient(90deg,var(--gray-100) 25%,var(--gray-50) 50%,var(--gray-100) 75%);background-size:200% 100%;animation:shim 1.4s infinite;border-radius:8px}
    @keyframes shim{0%{background-position:200% 0}100%{background-position:-200% 0}}

    .btn-deploy{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:9px;font-size:13.5px;font-weight:700;background:var(--primary);color:white;border:none;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .15s;box-shadow:0 2px 8px rgba(37,99,235,.22)}
    .btn-deploy:hover{background:var(--primary-hover);transform:translateY(-1px)}
    .btn-deploy svg{width:14px;height:14px}

    /* Responsive */
    @media(max-width:900px){
      .specs-group,.region-col,.price-col{display:none}
    }
    @media(max-width:600px){
      .filter-tabs{display:grid;grid-template-columns:1fr 1fr;gap:4px}
      .srv-card{gap:10px;padding:13px 14px}
      .os-icon-wrap{width:38px;height:38px}
      .actions-col .act-btn:not(:last-child):not(:first-child){display:none}
    }
  </style>
</head>
<body>
<div class="app-shell">

  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <div class="main-content" style="margin-left:260px;min-height:100vh;background:var(--gray-50)">

    <!-- Mobile bar -->
    <div class="mobile-bar">
      <button class="ham-btn" onclick="toggleSidebar()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <!--span style="font-weight:800;font-size:15px">My Servers</span-->
      <div style="margin-left:auto;display:flex;gap:6px">
        <button onclick="document.getElementById('claim-modal').classList.add('open')" class="btn btn-ghost btn-sm" style="border-color:var(--primary);color:var(--primary)">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg>
          Claim Server
        </button>
<button onclick="document.getElementById('custom-order-modal').classList.add('open')" class="btn btn-ghost btn-sm">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.07 4.93a10 10 0 0 1 0 14.14"></path><path d="M4.93 4.93a10 10 0 0 0 0 14.14"></path></svg>
          Custom Order
        </button>
        <a href="<?= BASE_URL ?>/servers/create.php" class="btn-deploy" style="padding:7px 12px;font-size:12px">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Deploy
        </a>
      </div>
    </div>

    <!-- Topbar -->
    <div class="topbar">
      <span class="topbar-title">My Servers</span>
      <div style="display:flex;gap:8px;align-items:center;margin-left:auto">
        <button onclick="document.getElementById('claim-modal').classList.add('open')" class="btn btn-ghost btn-sm" style="border-color:var(--primary);color:var(--primary)">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
          Claim Server
        </button>
        <button onclick="document.getElementById('custom-order-modal').classList.add('open')" class="btn btn-ghost btn-sm">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/><path d="M4.93 4.93a10 10 0 0 0 0 14.14"/></svg>
          Custom Order
        </button>
        <a href="<?= BASE_URL ?>/servers/create.php" class="btn-deploy" style="margin-left:0">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Deploy New Server
        </a>
      </div>
    </div>

    <div style="padding:24px">

      <!-- Top strip -->
      <div class="page-topstrip">
        <div>
          <div class="page-heading">My Servers <span style="font-size:14px;font-weight:500;color:var(--gray-400)">(<?= $counts['all'] ?>)</span></div>
          <div style="font-size:13px;color:var(--gray-500);margin-top:2px">
            <?php if ($counts['running'] > 0): ?><span style="color:#16a34a;font-weight:700"><?= $counts['running'] ?> running</span><?php endif; ?>
            <?php if ($counts['suspended'] > 0): ?> · <span style="color:var(--danger);font-weight:700"><?= $counts['suspended'] ?> suspended</span><?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Filters + search -->
      <form method="GET" id="filter-form">
        <div class="filter-wrap">
          <div class="filter-tabs">
            <?php
            $tabs = [
              ['all','All',$counts['all']],
              ['running','Running',$counts['running']],
              ['stopped','Stopped',$counts['stopped']],
            ];
            if ($counts['suspended'] > 0) $tabs[] = ['suspended','Suspended',$counts['suspended']];
            if ($counts['provisioning'] > 0) $tabs[] = ['provisioning','Provisioning',$counts['provisioning']];
            foreach ($tabs as [$val, $lbl, $cnt]):
            ?>
            <a href="?status=<?= $val ?><?= $search ? '&q='.urlencode($search) : '' ?>"
               class="ftab <?= $filter === $val ? 'active' : '' ?>">
              <?= $lbl ?>
              <span class="ftab-count"><?= $cnt ?></span>
            </a>
            <?php endforeach; ?>
          </div>

          <div class="search-wrap">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="hidden" name="status" value="<?= htmlspecialchars($filter) ?>">
            <input type="text" name="q" class="search-input" id="search-input"
                   placeholder="Search name, IP, OS…"
                   value="<?= htmlspecialchars($search) ?>"
                   autocomplete="off"
                   oninput="toggleClearBtn(this)">
            <button type="button" class="btn-clear-search" id="clear-search" onclick="clearSearch()" style="display:<?= $search ? 'flex' : 'none' ?>">×</button>
          </div>
        </div>
      </form>

      <!-- Server list -->
      <div id="servers-wrap">

        <?php if (empty($servers)): ?>
        <div class="empty-wrap">
          <div class="empty-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
          </div>
          <?php if ($search || $filter !== 'all'): ?>
          <div class="empty-h">No servers found</div>
          <div class="empty-p">Try a different search or filter.<br><a href="<?= BASE_URL ?>/servers.php" style="color:var(--primary);font-weight:700">Clear filters →</a></div>
          <?php else: ?>
          <div class="empty-h">No servers yet</div>
          <div class="empty-p">Deploy your first virtual server in under 60 seconds.<br>Choose a plan, OS, and region — we handle the rest.</div>
          <a href="<?= BASE_URL ?>/servers/create.php" class="btn-deploy">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Deploy First Server
          </a>
          <?php endif; ?>
        </div>

        <?php else: ?>
        <div class="srv-cards" id="srv-cards">
          <?php foreach ($servers as $s): ?>
          <div class="srv-card" data-id="<?= $s['id'] ?>">

            <!-- OS icon -->
            <div class="os-icon-wrap">
              <?= os_icon_srv($s['os_label'] ?? '', 26) ?>
            </div>

            <!-- Info -->
            <div class="srv-info">
              <div class="srv-info-name">
                <a href="<?= BASE_URL ?>/servers/view.php?id=<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></a>
                <?= sbadge($s['status']) ?>
              </div>
              <div class="srv-info-sub">
                <?php if ($s['ipv4']): ?>
                <span class="srv-info-ip"><?= htmlspecialchars($s['ipv4']) ?></span>
                <span class="info-sep">·</span>
                <?php endif; ?>
                <span class="srv-info-os"><?= htmlspecialchars($s['os_label'] ?: '—') ?></span>
                <span class="info-sep">·</span>
                <span class="srv-info-date"><?= date('d M Y', strtotime($s['created_at'])) ?></span>
              </div>
            </div>

            <!-- Specs -->
            <div class="specs-group">
              <span class="spec-chip"><?= $s['vcpu'] ?>vCPU</span>
              <span class="spec-chip"><?= (int)$s['ram_gb'] ?>GB RAM</span>
              <span class="spec-chip"><?= (int)$s['disk_gb'] ?>GB SSD</span>
            </div>

            <!-- Region + flag -->
            <div class="region-col">
              <img src="https://flagcdn.com/w20/<?= htmlspecialchars($s['region_flag'] ?? 'de') ?>.png"
                   srcset="https://flagcdn.com/w40/<?= htmlspecialchars($s['region_flag'] ?? 'de') ?>.png 2x"
                   width="20" height="15" alt="" onerror="this.style.display='none'">
              <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($s['region_label'] ?: $s['region_slug']) ?></span>
            </div>

            <!-- Price -->
            <div class="price-col">
              <div class="price-main"><?= $curr_sym . number_format((float)$s['price_hourly'], 4) ?>/hr</div>
              <div class="price-sub">~<?= $curr_sym . number_format((float)$s['price_monthly'], 2) ?>/mo</div>
            </div>

            <!-- Actions -->
            <div class="actions-col">
              <?php if ($s['status'] === 'running'): ?>
              <button class="act-btn" title="Reboot"
                onclick="srvAction(<?= $s['id'] ?>,'reboot','<?= htmlspecialchars($s['name'],ENT_QUOTES) ?>')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.86"/></svg>
              </button>
              <button class="act-btn" title="Power Off"
                onclick="srvAction(<?= $s['id'] ?>,'stop','<?= htmlspecialchars($s['name'],ENT_QUOTES) ?>')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
              </button>
              <?php elseif (in_array($s['status'], ['stopped','suspended'])): ?>
              <button class="act-btn power-on" title="Power On"
                onclick="srvAction(<?= $s['id'] ?>,'start','<?= htmlspecialchars($s['name'],ENT_QUOTES) ?>')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
              </button>
              <?php else: ?>
              <div style="width:32px"></div>
              <?php endif; ?>

              <a class="act-btn" title="Manage server" href="<?= BASE_URL ?>/servers/view.php?id=<?= $s['id'] ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
              </a>

              <button class="act-btn danger" title="Delete server"
                onclick="srvDelete(<?= $s['id'] ?>,'<?= htmlspecialchars($s['name'],ENT_QUOTES) ?>')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6M9 6V4h6v2"/></svg>
              </button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="paging" id="paging">
          <span class="paging-info">Showing <?= ($offset + 1) ?>–<?= min($offset + $per, $total) ?> of <?= $total ?> servers</span>
          <div class="paging-btns">
            <!-- Prev -->
            <button class="pbtn" <?= $page <= 1 ? 'disabled' : '' ?> onclick="goPage(<?= $page - 1 ?>)">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
            </button>

            <?php
            // Smart page range: always show first, last, and pages around current
            $range = 2;
            $shown = [];
            for ($i = 1; $i <= $total_pages; $i++) {
                if ($i === 1 || $i === $total_pages || abs($i - $page) <= $range) {
                    $shown[] = $i;
                }
            }
            $prev_p = null;
            foreach ($shown as $p):
                if ($prev_p !== null && $p - $prev_p > 1): ?>
                <span class="pbtn-ellipsis">…</span>
                <?php endif; ?>
                <button class="pbtn <?= $p === $page ? 'active' : '' ?>" onclick="goPage(<?= $p ?>)"><?= $p ?></button>
            <?php $prev_p = $p; endforeach; ?>

            <!-- Next -->
            <button class="pbtn" <?= $page >= $total_pages ? 'disabled' : '' ?> onclick="goPage(<?= $page + 1 ?>)">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
          </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>
      </div><!-- /servers-wrap -->
    </div><!-- /padding -->
  </div><!-- /main-content -->
</div><!-- /app-shell -->

<div class="overlay" id="overlay" onclick="toggleSidebar()"></div>
<div class="toast-wrap" style="position:fixed;bottom:20px;right:20px;z-index:999;display:flex;flex-direction:column;gap:8px"></div>

<script>
var CSRF = '<?= $csrf ?>';
var BASE = '<?= BASE_URL ?>';
var currentFilter = '<?= htmlspecialchars($filter) ?>';
var currentSearch = '<?= htmlspecialchars(addslashes($search)) ?>';

function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('open');
}

// ── AJAX pagination ──────────────────────────────────────────
function goPage(page) {
  var wrap = document.getElementById('servers-wrap');
  wrap.style.opacity = '.5';
  wrap.style.pointerEvents = 'none';

  var params = new URLSearchParams({ p: page, status: currentFilter, csrf: CSRF });
  if (currentSearch) params.set('q', currentSearch);

  fetch(BASE + '/api/servers-list.php?' + params.toString(), {
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) {
      document.getElementById('srv-cards').innerHTML = d.cards;
      document.getElementById('paging').outerHTML = d.paging || '';
      wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
      toast(d.error || 'Failed.', 'err');
    }
    wrap.style.opacity = '1';
    wrap.style.pointerEvents = 'auto';
  })
  .catch(() => {
    wrap.style.opacity = '1';
    wrap.style.pointerEvents = 'auto';
    toast('Failed to load page.', 'err');
  });
}

// ── Live search with debounce ────────────────────────────────
var searchTimer;
document.getElementById('search-input').addEventListener('input', function(e) {
  currentSearch = e.target.value.trim();
  toggleClearBtn(e.target);
  clearTimeout(searchTimer);
  searchTimer = setTimeout(function() {
    var url = new URL(window.location.href);
    url.searchParams.set('q', currentSearch);
    url.searchParams.set('p', '1');
    window.location.href = url.toString();
  }, 500);
});

function toggleClearBtn(input) {
  var btn = document.getElementById('clear-search');
  btn.style.display = input.value ? 'flex' : 'none';
}
function clearSearch() {
  document.getElementById('search-input').value = '';
  currentSearch = '';
  var url = new URL(window.location.href);
  url.searchParams.delete('q');
  url.searchParams.set('p', '1');
  window.location.href = url.toString();
}

// ── Server actions ───────────────────────────────────────────
function srvAction(id, action, name) {
  var msgs = { start:'Power on "'+name+'"?', stop:'Shut down "'+name+'"?', reboot:'Reboot "'+name+'"?' };
  if (!confirm(msgs[action] || 'Confirm?')) return;
  doFetch(id, action);
}
function srvDelete(id, name) {
  if (!confirm('DELETE "'+name+'"?\n\nAll data will be permanently lost. Cannot be undone.')) return;
  doFetch(id, 'delete');
}
function doFetch(id, action) {
  fetch(BASE + '/api/server-action.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id, action, csrf: CSRF })
  })
  .then(r => r.json())
  .then(d => {
    toast(d.ok ? 'Done — refreshing.' : (d.error || 'Failed.'), d.ok ? 'ok' : 'err');
    if (d.ok) setTimeout(() => location.reload(), 3000);
  })
  .catch(() => toast('Request failed.', 'err'));
}

function toast(msg, type) {
  var w = document.querySelector('.toast-wrap');
  var t = document.createElement('div');
  t.className = 'toast toast-' + (type==='ok'?'ok':'err');
  t.textContent = msg;
  w.appendChild(t);
  setTimeout(() => t.remove(), 4000);
}
</script>

<?php
// Show page alerts via toast on load
if ($page_msg === '__custom_order_sent__' || $page_msg === '__claimed__'): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  <?php if ($page_msg === '__custom_order_sent__'): ?>
  toast('✓ Custom order submitted! We will contact you shortly.', 'ok');
  <?php elseif ($page_msg === '__claimed__'): ?>
  toast('✓ Server claimed and added to your account!', 'ok');
  setTimeout(()=>location.reload(), 1800);
  <?php endif; ?>
});
</script>
<?php endif; ?>
<?php if ($page_err): ?>
<script>document.addEventListener('DOMContentLoaded', function() { toast(<?= json_encode($page_err) ?>, 'err'); }); </script>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     CUSTOM ORDER MODAL
═══════════════════════════════════════════════════════════ -->
<div class="modal-bd" id="custom-order-modal">
  <div class="modal-box" style="max-width:580px;max-height:92vh;overflow-y:auto">
    <div class="modal-head" style="position:sticky;top:0;background:white;z-index:2;padding-bottom:12px;border-bottom:1px solid var(--border);margin-bottom:18px">
      <div>
        <div style="font-size:18px;font-weight:900;color:var(--gray-900)">⚙️ Custom Server Order</div>
        <div style="font-size:12px;color:var(--gray-400);margin-top:2px">Configure your ideal server — our team will build it for you</div>
      </div>
      <button onclick="document.getElementById('custom-order-modal').classList.remove('open')"
              style="width:30px;height:30px;border:none;background:var(--gray-100);border-radius:7px;cursor:pointer;font-size:16px;color:var(--gray-500);flex-shrink:0">✕</button>
    </div>

    <form method="POST" id="custom-order-form">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="custom_order">

      <!-- Server Type -->
      <div style="margin-bottom:18px">
        <label class="flabel" style="margin-bottom:10px;display:block">Server Type</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <label id="st-vps" onclick="selectType('vps')"
                 style="border:2px solid var(--primary);background:var(--primary-light);border-radius:10px;padding:14px;cursor:pointer;text-align:center;transition:all .13s">
            <input type="radio" name="server_type" value="vps" checked style="display:none">
            <div style="font-size:24px">🖥️</div>
            <div style="font-weight:800;font-size:14px;margin-top:6px;color:var(--primary)">VPS</div>
            <div style="font-size:11.5px;color:var(--gray-500);margin-top:3px">Virtual Private Server</div>
          </label>
          <label id="st-dedicated" onclick="selectType('dedicated')"
                 style="border:2px solid var(--border);background:white;border-radius:10px;padding:14px;cursor:pointer;text-align:center;transition:all .13s">
            <input type="radio" name="server_type" value="dedicated" style="display:none">
            <div style="font-size:24px">🗄️</div>
            <div style="font-weight:800;font-size:14px;margin-top:6px;color:var(--gray-700)">Dedicated</div>
            <div style="font-size:11.5px;color:var(--gray-500);margin-top:3px">Bare Metal Server</div>
          </label>
        </div>
      </div>

      <!-- RAM + CPU -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
        <div class="form-group" style="margin-bottom:0">
          <label class="flabel">RAM (GB) <span style="color:var(--danger)">*</span></label>
          <select name="ram_gb" class="form-control">
            <option value="1">1 GB</option>
            <option value="2">2 GB</option>
            <option value="4" selected>4 GB</option>
            <option value="8">8 GB</option>
            <option value="16">16 GB</option>
            <option value="32">32 GB</option>
            <option value="64">64 GB</option>
            <option value="128">128 GB</option>
            <option value="custom">Custom...</option>
          </select>
          <input type="text" id="ram_custom" name="ram_gb_custom" class="form-control" style="display:none;margin-top:6px" placeholder="Enter GB e.g. 24">
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label class="flabel">CPU Cores <span style="color:var(--danger)">*</span></label>
          <select name="cpu_cores" class="form-control">
            <option value="1">1 Core</option>
            <option value="2" selected>2 Cores</option>
            <option value="4">4 Cores</option>
            <option value="6">6 Cores</option>
            <option value="8">8 Cores</option>
            <option value="12">12 Cores</option>
            <option value="16">16 Cores</option>
            <option value="24">24 Cores</option>
            <option value="32">32 Cores</option>
            <option value="custom">Custom...</option>
          </select>
          <input type="text" id="cpu_custom" name="cpu_cores_custom" class="form-control" style="display:none;margin-top:6px" placeholder="Enter cores e.g. 10">
        </div>
      </div>

      <!-- Disk Size + Type -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
        <div class="form-group" style="margin-bottom:0">
          <label class="flabel">Disk Size (GB) <span style="color:var(--danger)">*</span></label>
          <select name="disk_size" class="form-control">
            <option value="25">25 GB</option>
            <option value="50">50 GB</option>
            <option value="100" selected>100 GB</option>
            <option value="200">200 GB</option>
            <option value="500">500 GB</option>
            <option value="1000">1 TB</option>
            <option value="2000">2 TB</option>
            <option value="custom">Custom...</option>
          </select>
          <input type="text" id="disk_custom" name="disk_size_custom" class="form-control" style="display:none;margin-top:6px" placeholder="Enter GB e.g. 750">
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label class="flabel">Disk Type</label>
          <div style="display:flex;flex-direction:column;gap:6px;margin-top:2px">
            <?php foreach (['hdd'=>['💿','HDD','Spinning Disk'],'ssd'=>['💽','SSD','SATA SSD'],'nvme'=>['⚡','NVMe SSD','Fastest']] as $val=>[$icon,$lbl,$sub]): ?>
            <label style="display:flex;align-items:center;gap:8px;padding:7px 10px;border:1.5px solid var(--border);border-radius:8px;cursor:pointer;font-size:13px;transition:border-color .12s" class="disk-opt" id="dt-<?= $val ?>">
              <input type="radio" name="disk_type" value="<?= $val ?>" <?= $val==='ssd'?'checked':'' ?> style="accent-color:var(--primary)" onchange="highlightDisk('<?= $val ?>')">
              <span style="font-size:16px"><?= $icon ?></span>
              <div><div style="font-weight:700;color:var(--gray-800)"><?= $lbl ?></div><div style="font-size:10.5px;color:var(--gray-400)"><?= $sub ?></div></div>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- CPU Brand -->
      <div style="margin-bottom:14px">
        <label class="flabel" style="margin-bottom:8px;display:block">CPU Brand Preference</label>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px">
          <?php foreach (['intel'=>['🔵','Intel'],'amd'=>['🔴','AMD'],'any'=>['🟢','Any Brand']] as $val=>[$icon,$lbl]): ?>
          <label id="cb-<?= $val ?>" onclick="selectCPU('<?= $val ?>')"
                 style="border:2px solid <?= $val==='any'?'var(--primary)':'var(--border)' ?>;background:<?= $val==='any'?'var(--primary-light)':'white' ?>;border-radius:9px;padding:10px;cursor:pointer;text-align:center;transition:all .12s">
            <input type="radio" name="cpu_brand" value="<?= $val ?>" <?= $val==='any'?'checked':'' ?> style="display:none">
            <div style="font-size:20px"><?= $icon ?></div>
            <div style="font-size:12px;font-weight:700;margin-top:4px"><?= $lbl ?></div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- OS Preference -->
      <div class="form-group">
        <label class="flabel">OS Preference</label>
        <select name="os_pref" class="form-control">
          <option value="">— No preference —</option>
          <optgroup label="Linux">
            <option value="Ubuntu 22.04">Ubuntu 22.04 LTS</option>
            <option value="Ubuntu 24.04">Ubuntu 24.04 LTS</option>
            <option value="Debian 12">Debian 12</option>
            <option value="CentOS Stream 9">CentOS Stream 9</option>
            <option value="AlmaLinux 9">AlmaLinux 9</option>
            <option value="Rocky Linux 9">Rocky Linux 9</option>
          </optgroup>
          <optgroup label="Windows">
            <option value="Windows Server 2022">Windows Server 2022</option>
            <option value="Windows Server 2019">Windows Server 2019</option>
          </optgroup>
          <optgroup label="macOS / Other">
            <option value="macOS (Hackintosh)">macOS (Hackintosh)</option>
            <option value="Custom / Other">Custom / Other (specify in notes)</option>
          </optgroup>
        </select>
      </div>

      <!-- Message -->
      <div class="form-group">
        <label class="flabel">Additional Notes <span style="font-size:11px;color:var(--gray-400)">(optional)</span></label>
        <textarea name="message" class="form-control" rows="3" placeholder="Any specific requirements, use case, bandwidth needs, location preference, etc."></textarea>
      </div>

      <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px;margin-bottom:16px;font-size:12.5px;color:#92400e">
        ⏱️ Our team will review your order and reach out within <strong>24 hours</strong> with a quote and setup timeline.
      </div>

      <div style="display:flex;gap:8px">
        <button type="submit" data-loading="Submitting Order..." class="btn btn-primary btn-full">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          Submit Custom Order
        </button>
        <button type="button" onclick="document.getElementById('custom-order-modal').classList.remove('open')" class="btn btn-ghost">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     CLAIM SERVER MODAL
═══════════════════════════════════════════════════════════ -->
<div class="modal-bd" id="claim-modal">
  <div class="modal-box" style="max-width:440px">
    <div class="modal-head">
      <div>
        <div style="font-size:18px;font-weight:900;color:var(--gray-900)">🏷️ Claim Your Server</div>
        <div style="font-size:12px;color:var(--gray-400);margin-top:2px">Enter the claim code provided by our team</div>
      </div>
      <button onclick="document.getElementById('claim-modal').classList.remove('open')"
              style="width:30px;height:30px;border:none;background:var(--gray-100);border-radius:7px;cursor:pointer;font-size:16px;color:var(--gray-500)">✕</button>
    </div>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="claim_server">

      <div style="background:linear-gradient(135deg,#1a1a2e,#16213e);border-radius:10px;padding:18px;margin-bottom:18px;text-align:center">
        <div style="font-size:28px;margin-bottom:8px">🎁</div>
        <div style="font-size:13px;color:#94a3b8;line-height:1.6">
          After placing a custom order, our team will send you a unique <strong style="color:white">Claim Code</strong>. Enter it below to add the server to your account.
        </div>
      </div>

      <div class="form-group">
        <label class="flabel">Claim Code</label>
        <input type="text" name="claim_token" class="form-control"
               placeholder="e.g. CLAIM-A1B2C3D4"
               style="font-family:monospace;font-size:18px;text-align:center;letter-spacing:3px;text-transform:uppercase"
               maxlength="32" autocomplete="off" required
               oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9\-]/g,'')">
        <div style="font-size:11.5px;color:var(--gray-400);margin-top:6px">
          Claim codes are case-insensitive. Received via email after your custom order is fulfilled.
        </div>
      </div>

      <button data-loading="Claiming..." type="submit" class="btn btn-primary btn-full">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
        Claim Server
      </button>
    </form>
  </div>
</div>

<script>
// Server type selection
function selectType(val) {
  ['vps','dedicated'].forEach(function(t) {
    var el = document.getElementById('st-' + t);
    if (!el) return;
    var sel = t === val;
    el.style.borderColor = sel ? 'var(--primary)' : 'var(--border)';
    el.style.background  = sel ? 'var(--primary-light)' : 'white';
    el.querySelector('div:nth-child(2)').style.color = sel ? 'var(--primary)' : 'var(--gray-700)';
    el.querySelector('input').checked = sel;
  });
}

// CPU brand selection
function selectCPU(val) {
  ['intel','amd','any'].forEach(function(b) {
    var el = document.getElementById('cb-' + b);
    if (!el) return;
    var sel = b === val;
    el.style.borderColor = sel ? 'var(--primary)' : 'var(--border)';
    el.style.background  = sel ? 'var(--primary-light)' : 'white';
    el.querySelector('input').checked = sel;
  });
}

// Disk type highlight
function highlightDisk(val) {
  document.querySelectorAll('.disk-opt').forEach(function(el) {
    el.style.borderColor = 'var(--border)';
    el.style.background  = 'white';
  });
  var sel = document.getElementById('dt-' + val);
  if (sel) { sel.style.borderColor = 'var(--primary)'; sel.style.background = 'var(--primary-light)'; }
}
highlightDisk('ssd');

// Custom dropdowns — show text input when "Custom" selected
['ram', 'cpu', 'disk'].forEach(function(field) {
  var sel = document.querySelector('[name="' + (field==='cpu'?'cpu_cores':field==='ram'?'ram_gb':'disk_size') + '"]');
  var inp = document.getElementById(field + '_custom');
  if (!sel || !inp) return;
  sel.addEventListener('change', function() {
    if (this.value === 'custom') {
      inp.style.display = '';
      inp.required = true;
      inp.name = sel.name;
      sel.name = sel.name + '_orig';
    } else {
      inp.style.display = 'none';
      inp.required = false;
    }
  });
});

// Close modals on backdrop
['custom-order-modal','claim-modal'].forEach(function(id) {
  var el = document.getElementById(id);
  if (el) el.addEventListener('click', function(e) { if(e.target===this) this.classList.remove('open'); });
});
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function () {

        const btn = this.querySelector('button[type="submit"]');

        if (!btn) return;

        btn.disabled = true;

        const text = btn.dataset.loading || 'Loading...';

        btn.innerHTML = `
            <span class="spinner"></span>
            ${text}
        `;
    });
});
</script>
</body>
</html>
