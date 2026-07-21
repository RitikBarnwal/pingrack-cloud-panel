<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/servers.php';
require_once __DIR__ . '/../includes/currency.php';
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/os_icons.php';
require_login();

$server_id  = (int)($_GET['id']  ?? 0);
$active_tab = $_GET['tab']        ?? 'overview';
$is_new     = !empty($_GET['new']);
$user       = current_user();
$currency   = strtoupper($user['currency'] ?? 'USD');
$curr_sym   = currency_symbol($currency);
$csrf       = csrf_token();
$app_name   = APP_NAME;
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$uname    = htmlspecialchars($user['username']);
$balance  = (float)$user['wallet_balance'];

$server = get_server($server_id, (int)$user['id']);
if (!$server) { header('Location: ' . BASE_URL . '/servers.php'); exit; }

// Provider info — server ke source_provider_id se fetch karo
$prov_row = null;
$prov_type = 'hetzner';
try {
    $spid = (int)($server['source_provider_id'] ?? 0);
    if ($spid) {
        $ps = db()->prepare('SELECT * FROM providers WHERE id=? LIMIT 1');
        $ps->execute([$spid]);
        $prov_row = $ps->fetch() ?: null;
    }
    // Fallback: plan_slug se provider dhundo
    if (!$prov_row) {
        $ps = db()->prepare('SELECT p.* FROM providers p JOIN plan_pricing pp ON pp.provider_id=p.id WHERE pp.slug=? AND p.is_active=1 LIMIT 1');
        $ps->execute([$server['plan_slug'] ?? '']);
        $prov_row = $ps->fetch() ?: null;
    }
    if ($prov_row) $prov_type = strtolower($prov_row['provider_type'] ?? 'hetzner');
} catch (Throwable $e) {}

// Decrypt root password
$root_pass = null;
if (!empty($server['root_password']) && $prov_row) {
    try {
        $key = substr(hash('sha256', $prov_row['api_key']), 0, 16);
        $dec = openssl_decrypt(base64_decode($server['root_password']), 'AES-128-ECB', $key);
        if ($dec) $root_pass = $dec;
    } catch (Throwable $e) {}
}

// OS images for rebuild — only show images from THIS server's provider
$os_images = [];
try {
    if ($prov_row && !empty($prov_row['id'])) {
        $st = db()->prepare('SELECT * FROM image_catalog WHERE provider_id=? AND is_active=1 ORDER BY os_name, os_version DESC');
        $st->execute([(int)$prov_row['id']]);
    } else {
        $st = db()->query('SELECT * FROM image_catalog WHERE is_active=1 ORDER BY os_name, os_version DESC');
    }
    $os_images = $st->fetchAll() ?: [];
} catch (Throwable $e) {}

// User firewalls for apply
$user_firewalls = [];
try {
    $st = db()->prepare('SELECT * FROM firewalls WHERE user_id=? ORDER BY name');
    $st->execute([$user['id']]);
    $user_firewalls = $st->fetchAll() ?: [];
} catch (Throwable $e) {}

// Action history
$history = [];
try {
    $st = db()->prepare('SELECT * FROM server_actions WHERE server_id=? ORDER BY created_at DESC LIMIT 40');
    $st->execute([$server_id]);
    $history = $st->fetchAll() ?: [];
} catch (Throwable $e) {}

// Status helpers
$status      = $server['status'] ?? 'unknown';
$is_running  = $status === 'running';
$is_stopped  = in_array($status, ['stopped','suspended','off']);
$is_pending  = in_array($status, ['provisioning','starting','stopping','rebuilding']);

$status_cfg = [
    'running'      => ['#16a34a', 'Running',      'badge-green'],
    'stopped'      => ['#6b7280', 'Stopped',      'badge-gray'],
    'off'          => ['#6b7280', 'Off',           'badge-gray'],
    'suspended'    => ['#dc2626', 'Suspended',     'badge-red'],
    'provisioning' => ['#d97706', 'Provisioning',  'badge-yellow'],
    'starting'     => ['#d97706', 'Starting',      'badge-yellow'],
    'stopping'     => ['#d97706', 'Stopping',      'badge-yellow'],
    'rebuilding'   => ['#2563eb', 'Rebuilding',    'badge-blue'],
];
[$sc, $sl, $sb] = $status_cfg[$status] ?? ['#6b7280', ucfirst($status), 'badge-gray'];

// OS icon: delegated to includes/os_icons.php
function srv_os_icon(string $os): string {
    if (function_exists('get_os_icon_url')) {
        return get_os_icon_url($os);
    }
    
    // Fallback to a default icon or an empty string if function is missing
    return BASE_URL . '/assets/img/os/linux.png'; 
}

// Tabs — defined per provider_type so future providers can have different tabs
$provider_tabs = [
    'hetzner' => [
        ['overview',   'Overview'],
        ['graphs',     'Graphs 📈'],
        ['networking', 'Networking'],
        ['firewalls',  'Firewalls'],
        ['volumes',    'Volumes'],
        ['power',      'Power'],
        ['rescue',     'Rescue'],
        ['snapshots',  'Snapshots'],
        ['rebuild',    'Rebuild'],
        ['delete',     'Delete'],
    ],
    'linode' => [
        ['overview',   'Overview'],
        ['networking', 'Networking'],
        ['power',      'Power'],
        ['snapshots',  'Snapshots'],
        ['rebuild',    'Rebuild'],
        ['graphs',     'Graphs 📈'],
        ['delete',     'Delete'],
    ],
    'vultr' => [
        ['overview',   'Overview'],
        ['networking', 'Networking'],
        ['power',      'Power'],
        ['snapshots',  'Snapshots'],
        ['rebuild',    'Rebuild'],
        ['graphs',     'Graphs 📈'],
        ['delete',     'Delete'],
    ],
    'digitalocean' => [
        ['overview',   'Overview'],
        ['networking', 'Networking'],
        ['firewalls',  'Firewalls'],
        ['power',      'Power'],
        ['snapshots',  'Snapshots'],
        ['rebuild',    'Rebuild'],
        ['graphs',     'Graphs 📈'],
        ['delete',     'Delete'],
    ],
    'contabo' => [
        ['overview',   'Overview'],
        ['networking', 'Networking'],
        ['power',      'Power'],
        ['rescue',     'Rescue'],
        ['snapshots',  'Snapshots'],
        ['rebuild',    'Rebuild'],
        ['graphs',     'Graphs 📈'],
        ['delete',     'Delete'],
    ],
    'utho' => [
        ['overview',   'Overview'],
        ['networking', 'Networking'],
        ['power',      'Power'],
        ['rescue',     'Rescue'],
        ['snapshots',  'Snapshots'],
        ['rebuild',    'Rebuild'],
        ['graphs',     'Graphs 📈'],
        ['delete',     'Delete'],
    ],
    'virtualizor' => [
        ['overview',   'Overview'],
        ['networking', 'Networking'],
        ['power',      'Power'],
        ['rescue',     'Rescue'],
        ['snapshots',  'Snapshots'],
        ['rebuild',    'Rebuild'],
        ['graphs',     'Graphs 📈'],
        ['delete',     'Delete'],
    ],
    // Future providers
];
$tabs = $provider_tabs[$prov_type] ?? $provider_tabs['hetzner'];

function turl(int $id, string $tab): string {
    return BASE_URL . '/servers/view.php?id=' . $id . '&tab=' . $tab;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <?php if(($active_tab??'overview')==='graphs'): ?>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
  <?php endif; ?>
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title><?= htmlspecialchars($server['name']) ?> — <?= $app_name ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    /* ── Base ────────────────────────────────────────────────── */
    .main-content{background:#f0f2f5}
    *{box-sizing:border-box}

    /* ── Server header ───────────────────────────────────────── */
    .sv-header{background:white;border-bottom:1px solid #e2e8f0}
    .sv-header-inner{padding:20px 28px 0;max-width:1200px}
    .sv-top{display:flex;align-items:flex-start;gap:14px;padding-bottom:16px;flex-wrap:wrap}
    .sv-plan{background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:6px 14px;font-size:14px;font-weight:800;color:#475569;font-family:'JetBrains Mono',monospace;flex-shrink:0;align-self:flex-start;margin-top:3px}
    .sv-center{flex:auto;min-width:0}
    .sv-name-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
    .sv-dot{width:11px;height:11px;border-radius:50%;flex-shrink:0}
    .sv-name{font-size:22px;font-weight:900;color:#0f172a;letter-spacing:-.5px;line-height:1.2}
    .sv-meta{display:flex;align-items:center;gap:14px;margin-top:7px;flex-wrap:wrap}
    .sv-meta-chip{display:flex;align-items:center;gap:5px;font-size:12.5px;color:#64748b;font-family:'JetBrains Mono',monospace}
    .sv-meta-chip svg{width:12px;height:12px;color:#94a3b8;flex-shrink:0}
    .sv-status-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:99px;font-size:11.5px;font-weight:700}
    .sv-right{display:flex;align-items:center;gap:8px;flex-shrink:0;align-self:flex-start;margin-top:3px}
    .sv-icon-btn{display:flex;align-items:center;gap:5px;padding:7px 13px;border:1.5px solid #e2e8f0;border-radius:8px;background:white;font-size:12.5px;font-weight:700;font-family:inherit;cursor:pointer;color:#475569;transition:all .14s;text-decoration:none}
    .sv-icon-btn:hover{background:#f8fafc;border-color:#cbd5e1}
    .sv-icon-btn svg{width:13px;height:13px}
    .sv-power-btn{padding:7px 14px;border-radius:8px;font-size:12.5px;font-weight:800;font-family:inherit;cursor:pointer;border:2px solid;transition:all .14s;display:flex;align-items:center;gap:5px}
    .sv-power-on{border-color:#16a34a;background:#f0fdf4;color:#16a34a}
    .sv-power-on:hover{background:#dcfce7}
    .sv-power-off{border-color:#9ca3af;background:#f9fafb;color:#6b7280}
    .sv-power-off:hover{background:#f3f4f6}
    .sv-power-pending{border-color:#d97706;background:#fffbeb;color:#d97706}

    /* ── Tab nav ─────────────────────────────────────────────── */
    .sv-tabs{display:flex;overflow-x:auto;scrollbar-width:none;margin-top:0;border-top:1px solid #f1f5f9}
    .sv-tabs::-webkit-scrollbar{display:none}
    .sv-tab{padding:13px 17px;font-size:13px;font-weight:600;color:#64748b;text-decoration:none;border-bottom:2.5px solid transparent;margin-bottom:-1px;white-space:nowrap;transition:color .13s,border-color .13s;flex-shrink:0}
    .sv-tab:hover{color:#1e293b}
    .sv-tab.active{color:var(--primary);border-bottom-color:var(--primary);font-weight:700}
    .sv-tab.danger-tab{color:#94a3b8}
    .sv-tab.danger-tab:hover,.sv-tab.danger-tab.active{color:#dc2626;border-bottom-color:#dc2626}

    /* ── Content ─────────────────────────────────────────────── */
    .sv-body{padding:24px 28px;max-width:1200px}

    /* ── Alert banners ───────────────────────────────────────── */
    .banner{border-radius:12px;padding:14px 18px;display:flex;align-items:flex-start;gap:12px;margin-bottom:20px;border:1.5px solid}
    .banner-warn{background:#fffbeb;border-color:#fde68a}
    .banner-danger{background:#fef2f2;border-color:#fca5a5}
    .banner-success{background:#f0fdf4;border-color:#86efac}
    .banner-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0;margin-top:3px}
    @keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.4)}}
    .pulse{animation:pulse 1.4s ease-in-out infinite}
    @keyframes spin{to{transform:rotate(360deg)}}
    @keyframes sdp{0%,100%{opacity:1}50%{opacity:.35}}

    /* ── Specs bar ───────────────────────────────────────────── */
    .spec-bar{display:grid;grid-template-columns:repeat(5,1fr);background:white;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;margin-bottom:22px}
    .spec-cell{padding:16px 20px;border-right:1px solid #e2e8f0;display:flex;align-items:center;gap:11px}
    .spec-cell:last-child{border:none}
    .spec-ic{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
    .spec-v{font-size:17px;font-weight:900;color:#0f172a;letter-spacing:-.4px;line-height:1}
    .spec-l{font-size:10.5px;color:#94a3b8;margin-top:3px;font-weight:700;text-transform:uppercase;letter-spacing:.5px}

    /* ── Two-col layout ──────────────────────────────────────── */
    .two-col{display:grid;grid-template-columns:1fr 320px;gap:20px}
    .full-col{display:grid;grid-template-columns:1fr;gap:20px}

    /* ── Card ────────────────────────────────────────────────── */
    .card{background:white;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden}
    .card+.card{margin-top:0}
    .ch{padding:14px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
    .ct{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.9px;color:#94a3b8}
    .cb{padding:18px}

    /* ── Info rows ───────────────────────────────────────────── */
    .ir{display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid #f8fafc;font-size:13px}
    .ir:last-child{border:none}
    .il{color:#64748b;font-weight:500}
    .iv{color:#1e293b;font-weight:700;font-family:'JetBrains Mono',monospace;font-size:12.5px;text-align:right;word-break:break-all}

    /* ── Activity ────────────────────────────────────────────── */
    .act-item{display:flex;align-items:flex-start;gap:12px;padding:11px 18px;border-bottom:1px solid #f8fafc}
    .act-item:last-child{border:none}
    .act-ic{width:32px;height:32px;border-radius:8px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
    .act-name{font-size:13px;font-weight:700;color:#1e293b}
    .act-time{font-size:11.5px;color:#94a3b8;margin-top:2px}

    /* ── SSH box ─────────────────────────────────────────────── */
    .ssh-box{background:#0d1117;border-radius:10px;padding:12px 16px;display:flex;align-items:center;gap:10px;margin-bottom:12px}
    .ssh-cmd{font-family:'JetBrains Mono',monospace;font-size:12.5px;color:#3fb950;flex:1;word-break:break-all}

    /* ── Pass box ────────────────────────────────────────────── */
    .pass-wrap{background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:11px 14px;display:flex;align-items:center;gap:10px}
    .pass-v{font-family:'JetBrains Mono',monospace;font-size:14px;font-weight:700;color:#1e293b;flex:1;letter-spacing:1px}

    /* ── Buttons ─────────────────────────────────────────────── */
    .btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:9px;font-size:13.5px;font-weight:700;font-family:inherit;cursor:pointer;border:none;transition:all .15s}
    .btn svg{width:14px;height:14px;flex-shrink:0}
    .btn-red{background:#dc2626;color:white}.btn-red:hover{background:#b91c1c}
    .btn-orange{background:#d97706;color:white}.btn-orange:hover{background:#b45309}
    .btn-green{background:#16a34a;color:white}.btn-green:hover{background:#15803d}
    .btn-blue{background:#2563eb;color:white}.btn-blue:hover{background:#1d4ed8}
    .btn-ghost{background:white;color:#374151;border:1.5px solid #e2e8f0}.btn-ghost:hover{background:#f8fafc}
    .btn-sm{padding:6px 13px;font-size:12.5px;border-radius:8px}
    .btn:disabled{opacity:.4;cursor:not-allowed}
    .copy-btn{padding:5px 11px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:6px;font-size:12px;font-weight:700;color:white;cursor:pointer;font-family:inherit;transition:background .13s;white-space:nowrap}
    .copy-btn:hover{background:rgba(255,255,255,.2)}
    .copy-btn-light{padding:5px 11px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;font-weight:700;color:#374151;cursor:pointer;font-family:inherit;transition:background .13s;white-space:nowrap}
    .copy-btn-light:hover{background:#e2e8f0}

    /* ── Power section ───────────────────────────────────────── */
    .power-sec{background:white;border:1px solid #e2e8f0;border-radius:14px;padding:22px;margin-bottom:16px}
    .power-sec-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.9px;color:#64748b;margin-bottom:8px}
    .power-sec-desc{font-size:13px;color:#64748b;line-height:1.7;margin-bottom:16px}
    .btn-row{display:flex;gap:10px;flex-wrap:wrap}

    /* ── Network table ───────────────────────────────────────── */
    .ntbl{width:100%;border-collapse:collapse}
    .ntbl thead th{padding:9px 14px;text-align:left;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:#94a3b8;background:#f8fafc;border-bottom:1px solid #e2e8f0}
    .ntbl tbody tr{border-bottom:1px solid #f1f5f9}
    .ntbl tbody tr:last-child{border:none}
    .ntbl td{padding:12px 14px;font-size:13px;vertical-align:middle}
    .proto{display:inline-block;padding:2px 8px;border-radius:5px;font-size:10.5px;font-weight:700;background:#f1f5f9;color:#475569}

    /* ── Firewall list ───────────────────────────────────────── */
    .fw-item{display:flex;align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid #f1f5f9}
    .fw-item:last-child{border:none}
    .fw-ic{width:34px;height:34px;border-radius:9px;background:#fff7ed;border:1px solid #fed7aa;display:flex;align-items:center;justify-content:center;flex-shrink:0}

    /* ── Volumes ─────────────────────────────────────────────── */
    .vol-item{display:flex;align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid #f1f5f9}
    .vol-item:last-child{border:none}
    .vol-ic{width:34px;height:34px;border-radius:9px;background:#eff6ff;border:1px solid #bfdbfe;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}

    /* ── Snapshots ───────────────────────────────────────────── */
    .snap-item{display:flex;align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid #f1f5f9}
    .snap-item:last-child{border:none}

    /* ── Rebuild OS grid ─────────────────────────────────────── */
    .os-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px}
    .os-card{border:1.5px solid #e2e8f0;border-radius:10px;padding:14px 10px 12px;cursor:pointer;transition:all .14s;background:white;display:flex;flex-direction:column;align-items:center;gap:7px;text-align:center}
    .os-card:hover{border-color:#94a3b8;background:#f8fafc}
    .os-card.sel{border-color:var(--primary);background:#fff0f0;box-shadow:0 0 0 3px rgba(224,18,27,.07)}
    .os-name{font-size:13px;font-weight:700;color:#1e293b}
    .os-ver-sel{width:100%;padding:4px 6px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;color:#475569;background:white;outline:none;font-family:inherit}
    .os-ver-sel:focus{border-color:var(--primary)}

    /* ── Delete zone ─────────────────────────────────────────── */
    .delete-zone{background:white;border:1.5px solid #fca5a5;border-radius:14px;padding:24px 26px;max-width:660px}

    /* ── Loading overlay ─────────────────────────────────────── */
    .loading-overlay{position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:500;display:none;align-items:center;justify-content:center}
    .loading-overlay.show{display:flex}
    .loading-box{background:white;border-radius:14px;padding:28px 36px;text-align:center;min-width:200px}
    .loading-spin{width:36px;height:36px;border:3.5px solid #e2e8f0;border-top-color:var(--primary);border-radius:50%;animation:spin .7s linear infinite;margin:0 auto 12px}
    .loading-msg{font-size:14px;font-weight:700;color:#1e293b}

    /* ── Toast ───────────────────────────────────────────────── */
    .toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:10px;font-size:13.5px;font-weight:700;color:white;z-index:9999;box-shadow:0 8px 24px rgba(0,0,0,.15);transform:translateY(80px);opacity:0;transition:all .3s ease}
    .toast.show{transform:translateY(0);opacity:1}
    .toast.ok{background:#16a34a}.toast.err{background:#dc2626}.toast.info{background:#2563eb}

    /* ── Responsive ──────────────────────────────────────────── */
    @media(max-width:1000px){.two-col{grid-template-columns:1fr}.spec-bar{grid-template-columns:repeat(3,1fr)}.spec-cell:nth-child(3){border-right:none}.spec-cell:nth-child(4){border-top:1px solid #e2e8f0}}
    @media(max-width:640px){.sv-body{padding:16px}.sv-header-inner{padding:16px 16px 0}.spec-bar{grid-template-columns:1fr 1fr}.os-grid{grid-template-columns:1fr 1fr}.sv-name{font-size:17px}}
        .desktop-only{display:block!important}.mobb-only{display:none!important}@media(max-width:767px){.desktop-only{display:none!important}.mobb-only{display:block!important}}
  </style>
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>

  <div class="main-content" style="margin-left:260px">
    <!-- Mobile bar -->
    <div class="mobile-bar">
      <button class="ham-btn" onclick="document.getElementById('sidebar').classList.toggle('open');document.getElementById('overlay').classList.toggle('open')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
                  <span style="font-weight:800;font-size:14px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis">Server ID: #<?= htmlspecialchars($server['id']) ?></span>
    </div>

    <!-- ═══ SERVER HEADER ═══ -->
    <div class="sv-header">
      <div class="sv-header-inner">
        <div class="sv-top">
          <!-- Plan -->
          <div class="sv-plan desktop-only">
    <img src="<?= srv_os_icon($server['os_label'] ?? '') ?>" style="object-fit:contain;width:35px;" onerror="this.style.display='none'">
</div>
          <!-- Name + meta -->
          <div class="sv-center">
            <div class="sv-name-row">
                <img class="mobb-only" src="<?= srv_os_icon($server['os_label'] ?? '') ?>" style="object-fit:contain;width:35px;" onerror="this.style.display='none'">
              <div class="sv-dot" id="hdr-dot" style="background:<?= $sc ?>;<?= $is_running?'animation:sdp 2s ease-in-out infinite':'' ?>"></div>
              <div class="sv-name"><?= htmlspecialchars($server['name']) ?></div>
              <div class="sv-status-pill" id="hdr-status-pill" style="background:<?= $sc ?>18;color:<?= $sc ?>">
                <span id="hdr-status-txt"><?= $sl ?></span>
              </div>
            </div>
            <div class="sv-meta">
              <?php if ($server['ipv4']): ?>
              <div class="sv-meta-chip">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                <span id="hdr-ipv4"><?= htmlspecialchars($server['ipv4']) ?></span>
              </div>
              <?php endif; ?>
              <?php if ($server['ipv6']): ?>
              <div class="sv-meta-chip">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>
                <?= htmlspecialchars(explode('/', $server['ipv6'])[0] . '/64') ?>
              </div>
              <?php endif; ?>
              <?php if ($server['region_flag']): ?>
              <div class="sv-meta-chip">
                <img src="https://flagcdn.com/w20/<?= htmlspecialchars($server['region_flag']) ?>.png" width="14" height="10" style="border-radius:2px" onerror="this.style.display='none'">
                <?= htmlspecialchars($server['region_label'] ?? $server['region_slug']) ?>
              </div>
              <?php endif; ?>
              <div class="sv-meta-chip">
                <img src="<?= srv_os_icon($server['os_label'] ?? '') ?>" width="12" height="12" style="object-fit:contain" onerror="this.style.display='none'">
                <?= htmlspecialchars($server['os_label'] ?? 'Linux') ?>
              </div>
            </div>
          </div>

          <!-- Actions right -->
          <div class="sv-right">
            <button class="sv-icon-btn" onclick="openConsole()">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
              Console
            </button>
            <?php if ($is_running): ?>
            <button class="sv-power-btn sv-power-on" onclick="actionConfirm('stop','Power off the server?')">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><rect x="9" y="9" width="6" height="6"/></svg>
              ON
            </button>
            <?php elseif ($is_stopped): ?>
            <button class="sv-power-btn sv-power-off" onclick="actionConfirm('start','Power on the server?')">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="5 3 19 12 5 21 5 3"/></svg>
              OFF
            </button>
            <?php else: ?>
            <button class="sv-power-btn sv-power-pending" disabled>
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation:spin .8s linear infinite"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.86"/></svg>
              <?= strtoupper($status) ?>
            </button>
            <?php endif; ?>
          </div>
        </div>

        <!-- Tab nav -->
        <nav class="sv-tabs">
          <?php foreach ($tabs as [$key, $label]): ?>
          <a href="<?= turl($server_id, $key) ?>"
             class="sv-tab <?= $active_tab===$key?'active':'' ?> <?= $key==='delete'?'danger-tab':'' ?>">
            <?= $label ?>
          </a>
          <?php endforeach; ?>
        </nav>
      </div>
    </div>

    <!-- ═══ TAB CONTENT ═══ -->
    <div class="sv-body">

      <?php if ($is_pending): ?>
      <div class="banner banner-warn">
        <div class="banner-dot pulse" style="background:#d97706"></div>
        <div>
          <div style="font-size:13.5px;font-weight:800;color:#92400e"><?= $sl ?>... checking every 5 seconds</div>
          <div style="font-size:12px;color:#b45309;margin-top:2px">Page will refresh automatically when server is ready.</div>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($status === 'suspended'): ?>
      <?php
        $susp_price_hr  = (float)$server['price_hourly'];
        $susp_balance   = (float)$user['wallet_balance'];
        $susp_min_need  = $susp_price_hr * 5;
        $susp_still_need = max(0, $susp_min_need - $susp_balance);
        $susp_sym       = $currency === 'INR' ? '₹' : '$';

        // Check 48h warning status
        $susp_warned    = !empty($server['suspend_warning_sent_at']);
        $susp_since     = $server['suspended_at'] ?? null;
        $susp_hours     = $susp_since ? round((time() - strtotime($susp_since)) / 3600, 1) : 0;
        $delete_in_hrs  = $susp_warned ? max(0, round(60 - $susp_hours, 1)) : null;
      ?>
      <div style="background:#fef2f2;border:1.5px solid #fca5a5;border-radius:12px;padding:16px 20px;margin-bottom:20px">
        <div style="display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap">
          <div style="flex:1;min-width:240px">
            <div style="font-size:14px;font-weight:800;color:#991b1b;display:flex;align-items:center;gap:8px;margin-bottom:6px">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              Server Suspended — Insufficient Balance
            </div>
            <div style="font-size:13px;color:#9a3412;line-height:1.6">
              Current balance: <strong><?= $susp_sym . number_format($susp_balance, 2) ?></strong>
              &nbsp;·&nbsp; Hourly rate: <strong><?= $susp_sym . number_format($susp_price_hr, 4) ?></strong>
              &nbsp;·&nbsp; Need (5h min): <strong><?= $susp_sym . number_format($susp_min_need, 2) ?></strong>
            </div>
            <?php if ($delete_in_hrs !== null): ?>
            <div style="font-size:13px;color:#dc2626;font-weight:700;margin-top:6px">
              ⚠️ Server will be permanently deleted in ~<?= $delete_in_hrs ?> hours if not topped up!
            </div>
            <?php elseif ($susp_hours > 0): ?>
            <div style="font-size:12px;color:#9a3412;margin-top:4px">
              Suspended for <?= $susp_hours ?> hours. After 48h a final warning is sent; after 60h server is deleted.
            </div>
            <?php endif; ?>
          </div>
          <div style="display:flex;flex-direction:column;gap:8px;flex-shrink:0">
            <a href="<?= BASE_URL ?>/billing.php?action=topup" style="display:inline-flex;align-items:center;gap:7px;padding:10px 20px;background:#dc2626;color:white;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;white-space:nowrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Add <?= $susp_sym . number_format($susp_still_need, 2) ?> to reactivate
            </a>
            <div style="font-size:11px;color:#9a3412;text-align:center">Only Delete is available while suspended</div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- ════ OVERVIEW ════ -->
      <?php if ($active_tab === 'overview'): ?>

      <div class="spec-bar">
        <?php
$trafficUsed = ($server['used_bandwidth_gb'] >= 1024)
    ? round($server['used_bandwidth_gb'] / 1024, 2) . ' TB'
    : $server['used_bandwidth_gb'] . ' GB';

$trafficTotal = ($server['total_bandwidth_gb'] >= 1024)
    ? round($server['total_bandwidth_gb'] / 1024, 2) . ' TB'
    : $server['total_bandwidth_gb'] . ' GB';

$specs = [
  ['💻','#eff6ff', $server['vcpu'], 'vCPU'],
  ['🧠','#f0fdf4', $server['ram_gb'].' GB', 'RAM'],
  ['💾','#fff7ed', $server['disk_gb'].' GB', 'Disk NVMe'],
  ['📶','#faf5ff', $trafficUsed . ' / ' . $trafficTotal, 'Traffic'],
  ['💰','#f0fdf4', $curr_sym.number_format((float)$server['price_hourly'],4), 'Per Hour'],
];

foreach ($specs as [$ic,$bg,$v,$l]):
?>
        <div class="spec-cell">
          <div class="spec-ic" style="background:<?= $bg ?>"><?= $ic ?></div>
          <div><div class="spec-v"><?= htmlspecialchars((string)$v) ?></div><div class="spec-l"><?= $l ?></div></div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="two-col">
        <div style="display:flex;flex-direction:column;gap:18px">

          <!-- Activity -->
          <div class="card">
            <div class="ch"><span class="ct">Activities</span><span style="font-size:11px;color:#94a3b8">Last 40 actions</span></div>
            <?php if (empty($history)): ?>
            <div style="padding:28px;text-align:center;color:#94a3b8;font-size:13px">No actions yet.</div>
            <?php else:
              $act_map = ['create'=>['🚀','Server created'],'start'=>['▶️','Server started'],'stop'=>['⏹️','Server stopped'],'shutdown'=>['⏻','Server shutdown'],'reboot'=>['🔄','Server rebooted'],'reset'=>['⚡','Hard reset'],'rebuild'=>['🔨','Server rebuilt'],'delete'=>['🗑️','Server deleted'],'suspend'=>['⛔','Suspended'],'enable_rescue'=>['🛟','Rescue enabled'],'reset_root_password'=>['🔑','Password reset'],'create_snapshot'=>['📸','Snapshot created']];
              foreach ($history as $act):
                [$ic,$nm] = $act_map[$act['action']] ?? ['⚙️', ucfirst($act['action'])];
            ?>
            <div class="act-item">
              <div class="act-ic"><?= $ic ?></div>
              <div style="flex:1">
                <div class="act-name"><?= $nm ?></div>
                <div class="act-time"><?= date('d M Y, H:i', strtotime($act['created_at'])) ?></div>
              </div>
              <span style="font-size:11.5px;font-weight:700;color:<?= $act['status']==='success'?'#16a34a':'#dc2626' ?>">
                <?= $act['status']==='success' ? '✓' : '✗' ?>
              </span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <!-- Server info -->
          <div class="card">
            <div class="ch"><span class="ct">Server Info</span></div>
            <div class="cb">
              <?php foreach ([
                ['Name',       $server['name']],
                ['Plan',       strtoupper($server['plan_slug'] ?? '—')],
                ['OS',         $server['os_label'] ?? '—'],
                ['vCPU',       $server['vcpu'] . ' Cores'],
                ['RAM',        $server['ram_gb'] . ' GB'],
                ['Disk',       $server['disk_gb'] . ' GB NVMe SSD'],
                ['IPv4',       $server['ipv4'] ?? 'Not assigned'],
                ['IPv6',       $server['ipv6'] ? explode('/',$server['ipv6'])[0].'/64' : 'Not assigned'],
                ['Region',     ($server['region_label'] ?? $server['region_slug'])],
                ['Price/hr',   $curr_sym . number_format((float)$server['price_hourly'], 6)],
                ['~Price/mo',  $curr_sym . number_format((float)$server['price_monthly'], 2)],
                ['Created',    date('d M Y, H:i', strtotime($server['created_at']))],
              ] as [$l,$v]): ?>
              <div class="ir"><span class="il"><?= $l ?></span><span class="iv"><?= htmlspecialchars((string)$v) ?></span></div>
              <?php endforeach; ?>
            </div>
          </div>

        </div><!-- /left -->

        <div style="display:flex;flex-direction:column;gap:18px">

          <!-- Quick actions -->
          <div class="card">
            <div class="ch"><span class="ct">Quick Actions</span></div>
            <div class="cb" style="display:flex;flex-wrap:wrap;gap:8px">
              <?php
              $is_suspended = ($status === 'suspended');
              // When suspended: only Delete is allowed. Balance check happens in server-action.php.
              $qa = [
                ['⚡','Reboot',   'reboot',   '#eff6ff', !$is_running || $is_suspended],
                ['⏹️','Power Off','stop',     '#fff7ed', !$is_running || $is_suspended],
                ['▶️','Power On', 'start',    '#f0fdf4', !$is_stopped || $is_suspended],
                ['🔑','Reset PW', 'reset_root_password','#faf5ff', $is_suspended],
                ['📸','Snapshot', 'create_snapshot','#fef9c3', $is_suspended],
              ];
              foreach ($qa as [$ic,$lbl,$act,$bg,$dis]):
              ?>
              <button style="display:flex;flex-direction:column;align-items:center;gap:5px;padding:11px 14px;background:white;border:1.5px solid #e2e8f0;border-radius:10px;cursor:pointer;font-family:inherit;transition:all .14s;min-width:80px;<?= $dis?'opacity:.4;cursor:not-allowed':'' ?>"
                      onclick="<?= $dis?'':($act==='create_snapshot'?"snapshotModal()"."":"actionConfirm('{$act}','".(match($act){'stop'=>'Hard power off server?','reboot'=>'Power cycle (hard reboot)?','start'=>'Power on server?','reset_root_password'=>'Reset root password? Current password will be replaced.', default=>'Confirm?'})."')") ?>"
                      <?= $dis?'disabled':'' ?>>
                <div style="width:32px;height:32px;border-radius:8px;background:<?= $bg ?>;display:flex;align-items:center;justify-content:center;font-size:15px"><?= $ic ?></div>
                <div style="font-size:11.5px;font-weight:700;color:#374151"><?= $lbl ?></div>
              </button>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- SSH access -->
          <div class="card">
            <div class="ch"><span class="ct">SSH Access</span></div>
            <div class="cb">
              <?php if ($server['ipv4']): ?>
              <div class="ssh-box">
                <span class="ssh-cmd">ssh root@<?= htmlspecialchars($server['ipv4']) ?></span>
                <button class="copy-btn" onclick="copyText('ssh root@<?= htmlspecialchars($server['ipv4']) ?>')">Copy</button>
              </div>
              <?php else: ?>
              <div style="text-align:center;padding:8px 0;color:#94a3b8;font-size:13px">IP not assigned yet</div>
              <?php endif; ?>

              <?php if ($root_pass): ?>
              <div style="margin-top:10px">
                <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.6px;margin-bottom:7px">Root Password</div>
                <div class="pass-wrap">
                  <span class="pass-v" id="pval">••••••••••••</span>
                  <button class="copy-btn-light" id="ptoggle" onclick="togglePass()">Show</button>
                  <button class="copy-btn-light" onclick="copyText('<?= addslashes($root_pass) ?>')">Copy</button>
                </div>
                <div style="font-size:11px;color:#94a3b8;margin-top:5px">Change this after first login.</div>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Location -->
          <div class="card">
            <div class="ch"><span class="ct">Location</span></div>
            <div style="padding:16px 18px;display:flex;align-items:center;gap:14px">
              <img src="https://flagcdn.com/w40/<?= htmlspecialchars($server['region_flag']??'de') ?>.png"
                   width="36" height="26" style="border-radius:5px;border:1px solid #e2e8f0;flex-shrink:0"
                   onerror="this.style.display='none'">
              <div>
                <div style="font-size:15px;font-weight:800;color:#1e293b"><?= htmlspecialchars($server['region_label'] ?? $server['region_slug']) ?></div>
                <div style="font-size:12px;color:#94a3b8;font-family:monospace;margin-top:2px"><?= htmlspecialchars($server['region_slug']) ?></div>
              </div>
            </div>
          </div>

        </div><!-- /right -->
      </div>

      <!-- ════ NETWORKING ════ -->
      <?php elseif ($active_tab === 'networking'): ?>
      <div style="max-width:900px;display:flex;flex-direction:column;gap:18px">

        <!-- Public Network -->
        <div class="card">
          <div class="ch">
            <span class="ct" style="display:flex;align-items:center;gap:7px">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
              PUBLIC NETWORK
            </span>
          </div>
          <div style="overflow-x:auto">
            <table class="ntbl">
              <thead><tr><th>IP Address</th><th>Protocol</th><th>Reverse DNS</th></tr></thead>
              <tbody>
              <?php if ($server['ipv4']): ?>
              <tr>
                <td><span style="font-family:monospace;font-weight:700;font-size:13.5px"><?= htmlspecialchars($server['ipv4']) ?></span></td>
                <td><span class="proto">IPv4</span></td>
                <td><span style="font-size:12px;color:#64748b;font-family:monospace"><?= 'static.'.implode('.', array_reverse(explode('.', $server['ipv4']))).'.clients.your-server.de' ?></span></td>
              </tr>
              <?php endif; ?>
              <?php if ($server['ipv6']): ?>
              <tr>
                <td><span style="font-family:monospace;font-weight:700;font-size:13px"><?= htmlspecialchars($server['ipv6']) ?></span></td>
                <td><span class="proto">IPv6</span></td>
                <td><span style="font-size:12px;color:#94a3b8">0 Entries</span></td>
              </tr>
              <?php endif; ?>
              <?php if (!$server['ipv4'] && !$server['ipv6']): ?>
              <tr><td colspan="3" style="text-align:center;padding:22px;color:#94a3b8">No IPs assigned.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Private Network -->
        <div class="card">
          <div class="ch">
            <span class="ct">PRIVATE NETWORK</span>
            <div style="display:flex;gap:8px">
              <button class="btn btn-blue btn-sm" onclick="netModal('create')">Create network</button>
              <button class="btn btn-ghost btn-sm" onclick="netModal('attach')">Attach to network</button>
            </div>
          </div>
          <div style="padding:12px 0 4px;font-size:13px;color:#64748b;line-height:1.6;padding:14px 18px 0">
            Private IPs identify your server in a network. Private networks allow your servers to talk to each other over a dedicated link.
          </div>
          <div id="net-list-wrap" style="padding:0 18px 14px;margin-top:10px">
            <table class="ntbl">
              <thead><tr><th>Private IP</th><th>Network</th><th>MAC</th><th></th></tr></thead>
              <tbody id="net-tbody">
                <tr><td colspan="4" style="text-align:center;padding:18px;color:#94a3b8">
                  <button class="btn btn-ghost btn-sm" onclick="loadNetworks()">Load networks</button>
                </td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Floating IPs -->
        <div class="card">
          <div class="ch">
            <span class="ct">FLOATING IPs</span>
            <div style="display:flex;gap:8px">
              <button class="btn btn-blue btn-sm" onclick="floatingModal('create')">Add Floating IP</button>
              <button class="btn btn-ghost btn-sm" onclick="floatingModal('assign')">Assign Floating IP</button>
            </div>
          </div>
          <div style="padding:14px 18px 0;font-size:13px;color:#64748b;line-height:1.6">
            Floating IPs help you create highly flexible setups. A Floating IP can be assigned and reassigned to any server at any time as long as they are in the same network zone.
          </div>
          <div id="fip-list-wrap" style="padding:14px 18px">
            <button class="btn btn-ghost btn-sm" onclick="loadFloatingIps()">Load Floating IPs</button>
          </div>
        </div>

        <?php
$usedTraffic = (float)$server['used_bandwidth_gb'];
$totalTraffic = (float)$server['total_bandwidth_gb'];

$trafficPercent = $totalTraffic > 0
    ? min(100, round(($usedTraffic / $totalTraffic) * 100, 1))
    : 0;

$usedTrafficText = $usedTraffic >= 1024
    ? round($usedTraffic / 1024, 3) . ' TB'
    : round($usedTraffic, 2) . ' GB';

$totalTrafficText = $totalTraffic >= 1024
    ? round($totalTraffic / 1024, 2) . ' TB'
    : round($totalTraffic, 2) . ' GB';
?>

<!-- Traffic -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px">
  
  <div class="card">
    <div class="ch">
      <span class="ct">OUTGOING TRAFFIC</span>
      <span style="font-size:12px;color:#94a3b8">
        <?= $usedTrafficText ?> / <?= $totalTrafficText ?>
      </span>
    </div>

    <div class="cb">
      <div style="height:8px;background:#f1f5f9;border-radius:99px;margin-bottom:8px">
        <div style="height:100%;width:<?= $trafficPercent ?>%;background:var(--primary);border-radius:99px"></div>
      </div>

      <div style="font-size:12px;color:#64748b">
        <?= $trafficPercent ?>%
      </div>

      <div style="font-size:12px;color:#94a3b8;margin-top:8px">
        If you exceed the included traffic, additional bandwidth charges may apply. Please Contact Admin
      </div>
    </div>
  </div>

  <div class="card">
    <div class="ch">
      <span class="ct">INCOMING TRAFFIC</span>
      <span style="font-size:12px;color:#94a3b8">Unlimited</span>
    </div>

    <div class="cb">
      <div style="font-size:13px;color:#64748b">
        Any incoming traffic is free and does not cause additional charges.
      </div>
    </div>
  </div>

</div>

      </div>

      <!-- ════ FIREWALLS ════ -->
      <?php elseif ($active_tab === 'firewalls'): ?>
      <div style="max-width:800px;display:flex;flex-direction:column;gap:18px">

        <!-- Attached firewalls — auto loaded on tab open -->
        <div class="card">
          <div class="ch">
            <span class="ct">ATTACHED FIREWALLS</span>
            <button class="btn btn-blue btn-sm" onclick="showApplyFwModal()">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Apply Firewall
            </button>
          </div>
          <div id="fw-list-wrap">
            <div style="padding:24px;text-align:center">
              <div class="loading-spin" style="width:22px;height:22px;border-width:2.5px;margin:0 auto 8px"></div>
              <div style="font-size:12.5px;color:#94a3b8">Loading attached firewalls...</div>
            </div>
          </div>
        </div>

        <!-- User firewall library -->
        <div class="card">
          <div class="ch">
            <span class="ct">YOUR FIREWALL LIBRARY</span>
            <a href="<?= BASE_URL ?>/firewalls.php" class="btn btn-ghost btn-sm">Manage →</a>
          </div>
          <div>
            <?php if (empty($user_firewalls)): ?>
            <div style="color:#94a3b8;font-size:13px;text-align:center;padding:24px">
              No firewalls created yet.
              <a href="<?= BASE_URL ?>/firewalls.php" style="color:var(--primary);font-weight:700;margin-left:5px">Create one →</a>
            </div>
            <?php else: ?>
            <?php foreach ($user_firewalls as $fw): ?>
            <div class="fw-item">
              <div class="fw-ic">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              </div>
              <div style="flex:1">
                <div style="font-size:13.5px;font-weight:700;color:#1e293b"><?= htmlspecialchars($fw['name']) ?></div>
                <div style="font-size:12px;color:#94a3b8;margin-top:2px">
                  <?= count(json_decode($fw['rules']??'[]',true)) ?> rules
                  <?php if ($fw['provider_id']): ?> &middot; Provider ID: <?= (int)$fw['provider_id'] ?><?php endif; ?>
                </div>
              </div>
              <button class="btn btn-ghost btn-sm"
                      onclick="modal_confirm('Apply firewall &quot;<?= htmlspecialchars(addslashes($fw['name'])) ?>&quot; to this server?', function(){ applyFw(<?= (int)$fw['provider_id'] ?>); })">
                Apply
              </button>
              <button class="btn btn-ghost btn-sm" style="color:#dc2626;border-color:#fca5a5;margin-left:6px"
                      onclick="modal_confirm('Remove firewall &quot;<?= htmlspecialchars(addslashes($fw['name'])) ?>&quot; from this server?', function(){ removeFw(<?= (int)$fw['provider_id'] ?>); })">
                Remove
              </button>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

      </div>

            <!-- ════ VOLUMES ════ -->
      <?php elseif ($active_tab === 'volumes'): ?>
      <div style="max-width:760px">
        <div class="card" style="margin-bottom:18px">
          <div class="ch">
            <span class="ct">Attached Volumes</span>
            <button class="btn btn-blue btn-sm" onclick="document.getElementById('create-vol-modal').style.display='flex'">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Create Volume
            </button>
          </div>
          <div id="vol-list-wrap">
            <div style="padding:28px;text-align:center">
              <button class="btn btn-ghost btn-sm" onclick="loadVolumes()">Load volumes</button>
            </div>
          </div>
        </div>

        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 16px;font-size:12.5px;color:#1d4ed8">
          Volumes offer highly available NVMe SSD storage. They can be expanded anytime up to 10TB. You can detach and reattach volumes to different servers.
        </div>
      </div>

      <!-- ════ POWER ════ -->
      <?php elseif ($active_tab === 'power'): ?>
      <div style="max-width:700px">

        <div class="power-sec">
          <div class="power-sec-title">Power</div>
          <div class="power-sec-desc">
            <strong>Power off</strong> — Hard shutdown, same as pulling the power cord. May cause data loss.<br>
            <strong>Shutdown</strong> — Sends ACPI signal for graceful shutdown. Recommended method.
          </div>
          <div class="btn-row">
            <button class="btn btn-red" onclick="actionConfirm('stop','Hard power off? Unsaved data may be lost.')" <?= (!$is_running||$status==='suspended')?'disabled':'' ?>>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><rect x="9" y="9" width="6" height="6"/></svg>
              Power off
            </button>
            <button class="btn btn-ghost" onclick="actionConfirm('shutdown','Send graceful shutdown signal?')" <?= (!$is_running||$status==='suspended')?'disabled':'' ?>>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
              Shutdown
            </button>
          </div>
        </div>

        <div class="power-sec">
          <div class="power-sec-title">Power Reset</div>
          <div class="power-sec-desc">Issues a hard reset (power cycle). Like hitting the reset button. May cause data loss.</div>
          <div class="btn-row">
            <button class="btn btn-red" onclick="actionConfirm('reboot','Hard power cycle the server? Unsaved data may be lost.')" <?= (!$is_running||$status==='suspended')?'disabled':'' ?>>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.86"/></svg>
              Power cycle
            </button>
          </div>
        </div>

        <?php if ($is_stopped): ?>
        <div class="power-sec">
          <div class="power-sec-title">Power On</div>
          <div class="power-sec-desc">Start this server.</div>
          <div class="btn-row">
            <button class="btn btn-green" onclick="actionConfirm('start','Power on this server?')">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
              Power on
            </button>
          </div>
        </div>
        <?php endif; ?>

        <div style="background:#fef9c3;border:1px solid #fde047;border-radius:10px;padding:12px 16px;font-size:12.5px;color:#854d0e;margin-top:4px">
          ⚠ Powered off servers are still billed. Delete the server to stop billing.
        </div>

      </div>

      <!-- ════ RESCUE ════ -->
      <?php elseif ($active_tab === 'rescue'): ?>
      <div style="display:grid;grid-template-columns:1fr 260px;gap:18px;max-width:860px">
        <div style="display:flex;flex-direction:column;gap:16px">

          <div class="power-sec">
            <div class="power-sec-title">Rescue System</div>
            <div class="power-sec-desc">
              The rescue system is a network-based environment for fixing boot issues or installing custom distributions.<br><br>
              After enabling rescue, reboot the server within <strong>60 minutes</strong> to activate it. After another reboot, the server returns to its normal disk.
            </div>
            <div class="btn-row">
              <button class="btn btn-red" onclick="actionConfirm('enable_rescue','Enable rescue mode?')">Enable rescue</button>
              <button class="btn btn-ghost" onclick="actionConfirm('enable_rescue_cycle','Enable rescue mode and power cycle now?')">Enable rescue &amp; power cycle</button>
            </div>
          </div>

          <div class="power-sec">
            <div class="power-sec-title">Root Password</div>
            <div class="power-sec-desc">Reset your server's root password. If you removed the qemu-guest-agent, this operation will fail.</div>
            <div class="btn-row">
              <button class="btn btn-ghost" onclick="actionConfirm('reset_root_password','Reset root password? A new password will be generated.')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                Reset Root Password
              </button>
            </div>
          </div>

          <?php if ($root_pass): ?>
          <div class="card">
            <div class="ch"><span class="ct">Current Root Password</span></div>
            <div class="cb">
              <div class="pass-wrap">
                <span class="pass-v" id="rescue-pval">••••••••••••</span>
                <button class="copy-btn-light" id="rescue-ptoggle" onclick="toggleRescuePass()">Show</button>
                <button class="copy-btn-light" onclick="copyText('<?= addslashes($root_pass) ?>')">Copy</button>
              </div>
            </div>
          </div>
          <?php endif; ?>

        </div>
        <div style="display:flex;flex-direction:column;gap:12px">
          <div class="card">
            <div class="ch"><span class="ct">Info</span></div>
            <div class="cb" style="font-size:13px;color:#64748b;line-height:1.7">
              Rescue mode boots the server from a network disk. Use it to:<br>
              • Fix broken bootloaders<br>
              • Recover files<br>
              • Install custom OSes
            </div>
          </div>
        </div>
      </div>

      <!-- ════ SNAPSHOTS ════ -->
      <?php elseif ($active_tab === 'snapshots'): ?>
      <div style="max-width:760px">
        <div class="card" style="margin-bottom:18px">
          <div class="ch">
            <span class="ct">Snapshots</span>
            <button class="btn btn-blue btn-sm" onclick="snapshotModal()">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Take Snapshot
            </button>
          </div>
          <div id="snap-list-wrap">
            <div style="padding:28px;text-align:center"><button class="btn btn-ghost btn-sm" onclick="loadSnapshots()">Load snapshots</button></div>
          </div>
        </div>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;font-size:12.5px;color:#64748b">
          Snapshots are instant copies of your server's disk. Power off your server before taking a snapshot for data consistency. Snapshots cost €0.0143/GB/month.
        </div>
      </div>

      <!-- ════ REBUILD ════ -->
      <?php elseif ($active_tab === 'rebuild'): ?>
      <div style="max-width:760px">
        <div class="card">
          <div class="ch"><span class="ct">Rebuild Server</span></div>
          <div class="cb">
            <div style="background:#fef2f2;border:1.5px solid #fca5a5;border-radius:9px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#991b1b;font-weight:600">
              ⚠ All data on this server will be permanently destroyed. This cannot be undone.
            </div>

            <div style="font-size:13.5px;font-weight:700;color:#1e293b;margin-bottom:12px">Select OS Image</div>

            <?php
            // Group images
            $grouped = [];
            foreach ($os_images as $img) { $grouped[strtolower($img['os_name'])][] = $img; }
            ?>
            <div class="os-grid" id="rebuild-os-grid">
              <?php foreach ($grouped as $os_key => $versions):
                $icon_url = get_os_icon_url($os_key);
                $os_label = match($os_key){'alma'=>'AlmaLinux','opensuse'=>'openSUSE','rocky'=>'Rocky Linux',default=>ucfirst($os_key)};
              ?>
              <div class="os-card" id="rb-oscard-<?= $os_key ?>" onclick="selectRebuildOs('<?= $os_key ?>')">
                <img src="<?= $icon_url ?>" width="36" height="36" style="object-fit:contain" onerror="this.style.display='none'">
                <div class="os-name"><?= $os_label ?></div>
                <select class="os-ver-sel" id="rb-ver-<?= $os_key ?>" onclick="event.stopPropagation()" onchange="selectRebuildOsVer('<?= $os_key ?>',this.value)">
                  <?php foreach ($versions as $v): ?>
                  <option value="<?= htmlspecialchars($v['slug']) ?>"><?= htmlspecialchars($v['os_version'] ?: $v['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php endforeach; ?>
            </div>

            <?php if (!empty($grouped)): ?>
            <div style="margin-top:16px;display:flex;align-items:center;gap:12px">
              <div style="font-size:13px;color:#64748b">Selected: <strong id="rebuild-sel-lbl" style="color:#1e293b">None</strong></div>
              <button class="btn btn-red" id="rebuild-btn" onclick="doRebuild()" disabled>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="16 12 12 8 8 12"/><line x1="12" y1="16" x2="12" y2="8"/></svg>
                Rebuild Server
              </button>
            </div>
            <?php else: ?>
            <div style="color:#94a3b8;font-size:13px">No OS images available. Sync provider first.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- ════ DELETE ════ -->

      <?php elseif ($active_tab === 'graphs'): ?>
      <!-- ═══════════ GRAPHS TAB ═══════════ -->
      <div id="graphs-wrap" style="padding:6px 0">

        <!-- Range selector -->
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:18px">
          <div style="font-size:13px;font-weight:700;color:#1e293b">📈 Server Metrics</div>
          <div style="display:flex;gap:6px">
            <?php foreach(['1h'=>'1H','24h'=>'24H','7d'=>'7D','30d'=>'30D'] as $rv=>$rl): ?>
            <a href="<?= turl($server['id'],'graphs') ?>&range=<?= $rv ?>"
               style="padding:4px 12px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;
                      <?= ($_GET['range']??'24h')===$rv ? 'background:var(--primary);color:white' : 'background:#f1f5f9;color:#475569' ?>">
              <?= $rl ?>
            </a>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Charts grid -->
        <div id="charts-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:14px">

          <!-- CPU -->
          <div class="card" style="padding:16px 18px" id="chart-cpu-card">
            <div style="font-size:12px;font-weight:700;color:#475569;margin-bottom:12px;display:flex;align-items:center;gap:6px">
              <span style="width:8px;height:8px;border-radius:50%;background:#6366f1;display:inline-block"></span>
              CPU Usage (%)
            </div>
            <div style="position:relative;height:160px">
              <canvas id="chart-cpu"></canvas>
              <div id="chart-cpu-empty" style="display:none;position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:13px">No data</div>
            </div>
          </div>

          <!-- Network In -->
          <div class="card" style="padding:16px 18px" id="chart-netin-card">
            <div style="font-size:12px;font-weight:700;color:#475569;margin-bottom:12px;display:flex;align-items:center;gap:6px">
              <span style="width:8px;height:8px;border-radius:50%;background:#10b981;display:inline-block"></span>
              Network In <span id="netin-unit" style="font-weight:400;color:#94a3b8">(Mbps)</span>
            </div>
            <div style="position:relative;height:160px">
              <canvas id="chart-netin"></canvas>
            </div>
          </div>

          <!-- Network Out -->
          <div class="card" style="padding:16px 18px" id="chart-netout-card">
            <div style="font-size:12px;font-weight:700;color:#475569;margin-bottom:12px;display:flex;align-items:center;gap:6px">
              <span style="width:8px;height:8px;border-radius:50%;background:#f59e0b;display:inline-block"></span>
              Network Out <span id="netout-unit" style="font-weight:400;color:#94a3b8">(Mbps)</span>
            </div>
            <div style="position:relative;height:160px">
              <canvas id="chart-netout"></canvas>
            </div>
          </div>

          <!-- Disk IOPS -->
          <div class="card" style="padding:16px 18px" id="chart-disk-card">
            <div style="font-size:12px;font-weight:700;color:#475569;margin-bottom:12px;display:flex;align-items:center;gap:6px">
              <span style="width:8px;height:8px;border-radius:50%;background:#ef4444;display:inline-block"></span>
              Disk I/O (IOPS)
            </div>
            <div style="position:relative;height:160px">
              <canvas id="chart-disk"></canvas>
            </div>
          </div>

        </div>

        <!-- Bandwidth summary card -->
        <div class="card" style="padding:16px 18px;margin-top:14px" id="chart-bw-card">
          <div style="font-size:12px;font-weight:700;color:#475569;margin-bottom:12px">📶 Bandwidth Usage This Month</div>
          <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
            <div style="flex:1;min-width:180px">
              <div style="height:10px;background:#f1f5f9;border-radius:99px;overflow:hidden">
                <div id="bw-bar" style="height:100%;background:var(--primary);border-radius:99px;transition:width .5s;width:0%"></div>
              </div>
              <div style="display:flex;justify-content:space-between;margin-top:6px">
                <span style="font-size:12px;color:#475569">Used: <strong id="bw-used">—</strong></span>
                <span style="font-size:12px;color:#94a3b8">Total: <strong id="bw-total">—</strong></span>
              </div>
            </div>
            <div id="bw-pct" style="font-size:22px;font-weight:800;color:var(--primary)">—</div>
          </div>
        </div>

        <!-- Note / warning -->
        <div id="graphs-note" style="display:none;margin-top:12px;padding:10px 14px;background:#fefce8;border:1px solid #fde68a;border-radius:8px;font-size:12.5px;color:#92400e"></div>

        <!-- Loading overlay -->
        <div id="graphs-loading" style="display:none;text-align:center;padding:40px 0">
          <div class="loading-spin" style="width:28px;height:28px;border-width:2.5px;margin:0 auto 10px"></div>
          <div style="font-size:13px;color:#94a3b8">Loading metrics...</div>
        </div>

      </div>

      <style>
      @media(max-width:640px){ #charts-grid{grid-template-columns:1fr!important} }
      </style>

      <?php elseif ($active_tab === 'delete'): ?>
      <div class="delete-zone">
        <div style="font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#dc2626;margin-bottom:10px">Delete Server</div>
        <p style="font-size:13.5px;color:#374151;line-height:1.7;margin-bottom:6px">
          Deleting your server will stop all running processes and permanently destroy all disk data and backups.
        </p>
        <p style="font-size:13.5px;color:#374151;line-height:1.7;margin-bottom:22px">
          This action <strong>cannot be undone</strong>. Snapshots of the server remain intact after deletion.
        </p>
        <button class="btn btn-red" onclick="confirmDelete()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
          Delete server
        </button>
      </div>

      <?php endif; ?>

    </div><!-- /sv-body -->
  </div><!-- /main-content -->
</div><!-- /app-shell -->

<div class="overlay" id="overlay" onclick="document.getElementById('sidebar').classList.remove('open');this.classList.remove('open')"></div>

<!-- ═══ SNAPSHOT MODAL ═══ -->
<div id="snap-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:200;align-items:center;justify-content:center">
  <div style="background:white;border-radius:13px;width:100%;max-width:420px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.15)">
    <div style="padding:15px 20px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between">
      <div style="font-size:14px;font-weight:800;color:#1e293b">Take Snapshot</div>
      <button onclick="document.getElementById('snap-modal').style.display='none'" style="background:none;border:none;cursor:pointer;font-size:20px;color:#94a3b8;line-height:1">×</button>
    </div>
    <div style="padding:20px">
      <div style="font-size:12px;font-weight:700;color:#64748b;margin-bottom:6px">Description (optional)</div>
      <input type="text" id="snap-desc" class="form-control" placeholder="<?= date('Y-m-d H:i') ?>" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:13px;outline:none">
      <div style="font-size:12px;color:#94a3b8;margin-top:8px">Recommended: power off server first for data consistency.</div>
    </div>
    <div style="padding:13px 20px;border-top:1px solid #e2e8f0;display:flex;gap:8px;justify-content:flex-end">
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('snap-modal').style.display='none'">Cancel</button>
      <button class="btn btn-blue btn-sm" onclick="doSnapshot()">Take Snapshot</button>
    </div>
  </div>
</div>

<!-- ═══ CREATE VOLUME MODAL ═══ -->
<div id="create-vol-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:200;align-items:center;justify-content:center">
  <div style="background:white;border-radius:13px;width:100%;max-width:440px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.15)">
    <div style="padding:15px 20px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between">
      <div style="font-size:14px;font-weight:800;color:#1e293b">Create Volume</div>
      <button onclick="document.getElementById('create-vol-modal').style.display='none'" style="background:none;border:none;cursor:pointer;font-size:20px;color:#94a3b8;line-height:1">×</button>
    </div>
    <div style="padding:20px;display:flex;flex-direction:column;gap:14px">
      <div>
        <div style="font-size:12px;font-weight:700;color:#64748b;margin-bottom:6px">Volume Name</div>
        <input type="text" id="vol-name" class="form-control" placeholder="my-volume" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:monospace;font-size:13px;outline:none">
      </div>
      <div>
        <div style="font-size:12px;font-weight:700;color:#64748b;margin-bottom:6px">Size (GB) — min 10, max 10240</div>
        <input type="number" id="vol-size" class="form-control" value="20" min="10" max="10240" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:13px;outline:none">
      </div>
      <div>
        <div style="font-size:12px;font-weight:700;color:#64748b;margin-bottom:6px">Format</div>
        <select id="vol-format" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:13px;outline:none">
          <option value="ext4">ext4 (recommended)</option>
          <option value="xfs">xfs</option>
        </select>
      </div>
    </div>
    <div style="padding:13px 20px;border-top:1px solid #e2e8f0;display:flex;gap:8px;justify-content:flex-end">
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('create-vol-modal').style.display='none'">Cancel</button>
      <button class="btn btn-blue btn-sm" onclick="doCreateVolume()">Create &amp; Attach</button>
    </div>
  </div>
</div>

<!-- ═══ APPLY FIREWALL MODAL ═══ -->
<div id="apply-fw-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:200;align-items:center;justify-content:center">
  <div style="background:white;border-radius:13px;width:100%;max-width:420px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.15)">
    <div style="padding:15px 20px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between">
      <div style="font-size:14px;font-weight:800;color:#1e293b">Apply Firewall</div>
      <button onclick="document.getElementById('apply-fw-modal').style.display='none'" style="background:none;border:none;cursor:pointer;font-size:20px;color:#94a3b8;line-height:1">×</button>
    </div>
    <div style="padding:20px">
      <?php if (empty($user_firewalls)): ?>
      <div style="text-align:center;color:#94a3b8;font-size:13px;padding:10px 0">
        No firewalls available. <a href="<?= BASE_URL ?>/firewalls.php" style="color:var(--primary);font-weight:700">Create one →</a>
      </div>
      <?php else: ?>
      <div style="font-size:12px;font-weight:700;color:#64748b;margin-bottom:10px">Select firewall to apply:</div>
      <div style="display:flex;flex-direction:column;gap:7px">
        <?php foreach ($user_firewalls as $fw): ?>
        <label style="display:flex;align-items:center;gap:10px;padding:10px 13px;border:1.5px solid #e2e8f0;border-radius:9px;cursor:pointer;transition:all .13s" onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='#e2e8f0'">
          <input type="radio" name="modal-fw" value="<?= $fw['provider_id']?:(int)($fw['id']) ?>" style="accent-color:var(--primary)">
          <div>
            <div style="font-size:13.5px;font-weight:700;color:#1e293b"><?= htmlspecialchars($fw['name']) ?></div>
            <div style="font-size:12px;color:#94a3b8"><?= count(json_decode($fw['rules']??'[]',true)) ?> rules</div>
          </div>
        </label>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php if (!empty($user_firewalls)): ?>
    <div style="padding:13px 20px;border-top:1px solid #e2e8f0;display:flex;gap:8px;justify-content:flex-end">
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('apply-fw-modal').style.display='none'">Cancel</button>
      <button class="btn btn-blue btn-sm" onclick="doApplyFwFromModal()">Apply</button>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ═══ LOADING OVERLAY ═══ -->
<div class="loading-overlay" id="loading-overlay">
  <div class="loading-box">
    <div class="loading-spin"></div>
    <div class="loading-msg" id="loading-msg">Processing...</div>
  </div>
</div>

<!-- ═══ TOAST ═══ -->
<div class="toast" id="toast"></div>

<script>
var SID  = <?= $server_id ?>;
var CSRF = '<?= csrf_token() ?>';
var BASE = '<?= BASE_URL ?>';
var PASS = '<?= addslashes($root_pass ?? '') ?>';
var ACTIVE_TAB = '<?= $active_tab ?>';

/* ══ MODAL SYSTEM ══════════════════════════════════════════════ */
(function(){
  var d = document.createElement('div');
  d.id = 'cv-modal-overlay';
  d.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9000;display:none;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(2px)';
  d.innerHTML =
    '<div id="cv-modal-box" style="background:white;border-radius:16px;width:100%;max-width:440px;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.2);animation:modalIn .18s ease">'
    +'<div id="cv-modal-head" style="padding:18px 22px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:12px">'
    +'<div id="cv-modal-icon" style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0"></div>'
    +'<div id="cv-modal-title" style="font-size:15px;font-weight:800;color:#1e293b"></div>'
    +'<button onclick="closeModal()" style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:20px;color:#94a3b8;line-height:1;flex-shrink:0">&times;</button>'
    +'</div>'
    +'<div id="cv-modal-body" style="padding:18px 22px;font-size:13.5px;color:#64748b;line-height:1.7;max-height:60vh;overflow-y:auto"></div>'
    +'<div id="cv-modal-foot" style="padding:14px 22px;border-top:1px solid #f1f5f9;display:flex;gap:8px;justify-content:flex-end"></div>'
    +'</div>';
  document.body.appendChild(d);
  d.addEventListener('click', function(e){ if(e.target===d) closeModal(); });
})();

var modalStyle = document.createElement('style');
modalStyle.textContent = '@keyframes modalIn{from{opacity:0;transform:scale(.96) translateY(8px)}to{opacity:1;transform:scale(1) translateY(0)}}';
document.head.appendChild(modalStyle);

function showModal(opts) {
  var icon   = opts.icon   || '\u2139\uFE0F';
  var iconBg = opts.iconBg || '#eff6ff';
  var title  = opts.title  || 'Confirm';
  var body   = opts.body   || '';
  var btns   = opts.buttons|| [{label:'OK', cls:'btn-cv-primary', cb:function(){closeModal();}}];

  document.getElementById('cv-modal-icon').textContent      = icon;
  document.getElementById('cv-modal-icon').style.background = iconBg;
  document.getElementById('cv-modal-title').textContent     = title;
  document.getElementById('cv-modal-body').innerHTML        = body;

  var foot = document.getElementById('cv-modal-foot');
  foot.innerHTML = '';

  if (!opts.noCancel) {
    var cbtn = document.createElement('button');
    cbtn.style.cssText = 'padding:8px 18px;border-radius:8px;border:1.5px solid #e2e8f0;background:white;color:#374151;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit';
    cbtn.textContent = opts.cancelLabel || 'Cancel';
    cbtn.onclick = closeModal;
    foot.appendChild(cbtn);
  }

  btns.forEach(function(b){
    var btn = document.createElement('button');
    btn.style.cssText = (b.css || 'padding:8px 18px;border-radius:8px;border:none;background:#1e293b;color:white;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit');
    btn.textContent = b.label;
    if (b.id) btn.id = b.id;
    btn.onclick = function(){ closeModal(); if(b.cb) b.cb(); };
    foot.appendChild(btn);
  });

  var box = document.getElementById('cv-modal-box');
  box.style.animation = 'none';
  setTimeout(function(){ box.style.animation = 'modalIn .18s ease'; }, 10);
  document.getElementById('cv-modal-overlay').style.display = 'flex';
}

function closeModal() {
  document.getElementById('cv-modal-overlay').style.display = 'none';
}

function modal_confirm(msg, cb, opts) {
  opts = opts || {};
  showModal({
    icon:   opts.icon   || '\u26A0\uFE0F',
    iconBg: opts.iconBg || '#fff7ed',
    title:  opts.title  || 'Confirm Action',
    body:   msg,
    buttons:[{label: opts.ok || 'Confirm', css:'padding:8px 18px;border-radius:8px;border:none;background:#1e293b;color:white;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit', cb:cb}]
  });
}

function modal_input(title, body_html, placeholder, expected, cb) {
  showModal({
    icon:'\uD83D\uDDD1\uFE0F', iconBg:'#fef2f2', title:title,
    body: body_html + '<br><input id="cv-modal-input" placeholder="'+esc(placeholder)+'"'
      + ' style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:13.5px;outline:none;box-sizing:border-box;margin-top:6px"'
      + ' oninput="var ok=this.value===\''+expected.replace(/\\/g,'\\\\').replace(/'/g,"\\'")+'\';"'
      + '>',
    buttons:[{
      id:'cv-modal-confirm-btn',
      label:'Delete Server',
      css:'padding:8px 18px;border-radius:8px;border:none;background:#dc2626;color:white;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit',
      cb:function(){
        var val = document.getElementById('cv-modal-input');
        if (val && val.value === expected) cb();
        else toast_show('Name did not match. Cancelled.','err');
      }
    }],
    noCancel: false
  });
  setTimeout(function(){
    var inp = document.getElementById('cv-modal-input');
    if (inp) { inp.focus(); inp.addEventListener('keydown', function(e){ if(e.key==='Enter'){ var b=document.getElementById('cv-modal-confirm-btn'); if(b) b.click(); } }); }
  }, 80);
}

/* ══ TOAST ═════════════════════════════════════════════════════ */
function toast_show(msg, type) {
  var t = document.getElementById('toast');
  t.textContent = msg;
  t.className   = 'toast ' + (type||'ok');
  setTimeout(function(){ t.classList.add('show'); }, 10);
  setTimeout(function(){ t.classList.remove('show'); }, 3800);
}

/* ══ LOADING ════════════════════════════════════════════════════ */
function showLoading(msg) {
  document.getElementById('loading-msg').textContent = msg || 'Processing...';
  document.getElementById('loading-overlay').classList.add('show');
}
function hideLoading() { document.getElementById('loading-overlay').classList.remove('show'); }

/* ══ UTILS ══════════════════════════════════════════════════════ */
function copyText(t) { navigator.clipboard.writeText(t).then(function(){ toast_show('Copied!','ok'); }); }
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

/* ══ PASSWORD ═══════════════════════════════════════════════════ */
var passVis = false;
function togglePass() {
  passVis = !passVis;
  var el = document.getElementById('pval'); var btn = document.getElementById('ptoggle');
  if(el) el.textContent  = passVis ? PASS : '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022';
  if(btn) btn.textContent = passVis ? 'Hide' : 'Show';
}
var rPassVis = false;
function toggleRescuePass() {
  rPassVis = !rPassVis;
  var el = document.getElementById('rescue-pval'); var btn = document.getElementById('rescue-ptoggle');
  if(el) el.textContent  = rPassVis ? PASS : '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022';
  if(btn) btn.textContent = rPassVis ? 'Hide' : 'Show';
}

/* ══ CORE ACTION ════════════════════════════════════════════════ */
var NO_RELOAD_ACTIONS = ['list_snapshots','list_volumes','list_firewalls','list_floating_ips','list_networks','list_all_networks','get_console','apply_firewall','remove_firewall','attach_volume','detach_volume','create_volume','assign_floating_ip','unassign_floating_ip','delete_floating_ip','create_floating_ip','attach_network','detach_network','create_network','delete_snapshot'];

function doAction(action, payload, successCb) {
  var msgs = {start:'Starting server...',stop:'Powering off...',shutdown:'Shutting down...',
    reboot:'Rebooting...',reset:'Hard resetting...',enable_rescue:'Enabling rescue...',
    enable_rescue_cycle:'Enabling rescue & rebooting...',reset_root_password:'Resetting password...',
    delete:'Deleting server...',create_snapshot:'Taking snapshot...',rebuild:'Rebuilding server...',
    create_volume:'Creating volume...',create_floating_ip:'Creating Floating IP...',
    create_network:'Creating network...',apply_firewall:'Applying firewall...',remove_firewall:'Removing firewall...'
  };
  showLoading(msgs[action] || 'Processing...');

  fetch(BASE + '/api/server-action.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify(Object.assign({id:SID, action:action, csrf:CSRF}, payload||{}))
  })
  .then(function(r){ return r.json(); })
  .then(function(d){
    hideLoading();
    if (d.ok) {
      // ── New password received → show prominent modal ─────────
      if (d.root_password) {
        PASS = d.root_password;
        var isRebuild = (action === 'rebuild');
        showModal({
          icon: isRebuild ? '🔨' : '🔑',
          iconBg: isRebuild ? '#fef2f2' : '#faf5ff',
          title: isRebuild ? 'Server Rebuilding — New Password' : 'New Root Password',
          body: '<div style="background:#fff7ed;border:1.5px solid #fed7aa;border-radius:9px;padding:12px 15px;margin-bottom:16px;font-size:13px;color:#9a3412;font-weight:600">'
              + '⚠️ Save this password now! It will NOT be shown again after you close this dialog.'
              + '</div>'
              + '<div style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px">Root Password</div>'
              + '<div style="background:#0d1117;border-radius:9px;padding:14px 16px;display:flex;align-items:center;gap:12px;margin-bottom:4px">'
              + '<span id="modal-pass-val" style="font-family:monospace;font-size:18px;font-weight:700;color:#3fb950;flex:1;letter-spacing:2px">' + esc(d.root_password) + '</span>'
              + '<button onclick="navigator.clipboard.writeText(\'' + d.root_password.replace(/\\/g,'\\\\').replace(/'/g,"\\'") + '\').then(function(){this.textContent=\'✓ Copied!\';}.bind(this))" '
              + 'style="padding:6px 12px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);border-radius:6px;font-size:12px;font-weight:700;color:white;cursor:pointer;font-family:inherit;white-space:nowrap">Copy</button>'
              + '</div>'
              + (isRebuild ? '<div style="font-size:12.5px;color:#64748b;margin-top:10px;line-height:1.6">The server is now rebuilding in the background. It will be ready in 1–3 minutes.</div>' : ''),
          noCancel: false,
          cancelLabel: 'Close',
          buttons: [{
            label: '📋 Copy & Close',
            css: 'padding:8px 18px;border-radius:8px;border:none;background:#16a34a;color:white;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit',
            cb: function(){
              navigator.clipboard.writeText(d.root_password).then(function(){ toast_show('Password copied!','ok'); });
              if (successCb) { successCb(d); return; }
              if (NO_RELOAD_ACTIONS.indexOf(action) === -1) { setTimeout(function(){ location.reload(); }, 1500); }
            }
          }]
        });
        // Also update pass display on page if visible
        var pval = document.getElementById('pval');
        if (pval && passVis) pval.textContent = PASS;
        return; // don't fall through to normal flow
      }

      toast_show(d.message || 'Done.', 'ok');
      if (successCb) { successCb(d); return; }
      if (action === 'delete') { setTimeout(function(){ window.location.href = BASE+'/servers.php'; }, 2000); return; }
      if (NO_RELOAD_ACTIONS.indexOf(action) === -1) { setTimeout(function(){ location.reload(); }, 2000); }
    } else {
      toast_show(d.error || 'Action failed.', 'err');
    }
  })
  .catch(function(){ hideLoading(); toast_show('Request failed.', 'err'); });
}

function actionConfirm(action, msg, icon, iconBg) {
  modal_confirm(msg, function(){ doAction(action, {}); }, {icon:icon||'\u26A0\uFE0F', iconBg:iconBg||'#fff7ed'});
}

/* ══ CONSOLE ════════════════════════════════════════════════════ */
function openConsole() {
  showLoading('Requesting console access...');
  fetch(BASE+'/api/server-action.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({id:SID, action:'get_console', csrf:CSRF})
  })
  .then(function(r){ return r.json(); })
  .then(function(d){
    hideLoading();
    if (d.ok && d.url) {
      // Hetzner returns wss:// URL — use noVNC to render it
      var wssUrl  = d.url;
      var pass    = d.password || '';
      var novncBase = 'https://cdn.jsdelivr.net/npm/@novnc/novnc@1.4.0/core/';

      var w = window.open('', '_blank', 'width=1100,height=750,menubar=no,toolbar=no,scrollbars=no');
      if (!w) { toast_show('Pop-up blocked. Please allow pop-ups for this site.','err'); return; }

      w.document.write('<!DOCTYPE html><html><head>'
        + '<meta charset="UTF-8"><title>Console — <?= addslashes($server['name']) ?><\/title>'
        + '<style>'
        + '*{margin:0;padding:0;box-sizing:border-box}'
        + 'body{background:#0d1117;display:flex;flex-direction:column;height:100vh;font-family:monospace}'
        + '.hdr{background:#161b22;padding:8px 16px;display:flex;align-items:center;gap:14px;border-bottom:1px solid #30363d;flex-shrink:0}'
        + '.hdr-name{color:#3fb950;font-size:13px;font-weight:700}'
        + '.hdr-meta{color:#8b949e;font-size:12px}'
        + '.hdr-pass{color:#d29922;font-size:12px;font-weight:700}'
        + '#screen{flex:1;position:relative;overflow:hidden}'
        + '#noVNC_canvas{width:100%;height:100%}'
        + '.conn-msg{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#8b949e;font-size:14px;gap:12px}'
        + '.spin{width:32px;height:32px;border:3px solid #30363d;border-top-color:#3fb950;border-radius:50%;animation:sp .7s linear infinite}'
        + '@keyframes sp{to{transform:rotate(360deg)}}'
        + '.err-box{background:#2d1b1b;border:1px solid #7f1d1d;border-radius:8px;padding:14px 20px;color:#fca5a5;font-size:13px;text-align:center;max-width:480px;line-height:1.6}'
        + '.copy-pass{padding:4px 10px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);border-radius:5px;color:#d29922;font-size:11px;cursor:pointer;font-family:monospace}'
        + '<\/style>'
        + '<\/head><body>'
        + '<div class="hdr">'
        + '<span style="font-size:16px">🖥️<\/span>'
        + '<span class="hdr-name"><?= addslashes($server['name']) ?><\/span>'
        + '<span class="hdr-meta">IP: <?= $server['ipv4']??'' ?><\/span>'
        + (pass ? '<span class="hdr-pass">Pass: <span id="hp">' + pass.replace(/</g,'&lt;') + '<\/span><\/span>'
                + '<button class="copy-pass" onclick="navigator.clipboard.writeText(\'' + pass.replace(/\\/g,'\\\\').replace(/'/g,"\\'") + '\')">Copy<\/button>' : '')
        + '<span style="color:#30363d;margin-left:auto;font-size:11px">Ctrl+Alt+Del: right-click canvas<\/span>'
        + '<\/div>'
        + '<div id="screen">'
        + '<div class="conn-msg" id="conn-msg"><div class="spin"><\/div><span>Connecting to console...</span><\/div>'
        + '<canvas id="noVNC_canvas" style="display:none"><\/canvas>'
        + '<\/div>'
        + '<script type="module">'
        + 'import RFB from "' + novncBase + 'rfb.js";'
        + 'var wssUrl = ' + JSON.stringify(wssUrl) + ';'
        + 'var pass   = ' + JSON.stringify(pass) + ';'
        + 'var msg    = document.getElementById("conn-msg");'
        + 'var canvas = document.getElementById("noVNC_canvas");'
        + 'var screen = document.getElementById("screen");'
        + 'try {'
        + '  var rfb = new RFB(screen, wssUrl, pass ? {credentials:{password:pass}} : {});'
        + '  rfb.scaleViewport = true;'
        + '  rfb.resizeSession = true;'
        + '  rfb.addEventListener("connect", function(){'
        + '    msg.style.display="none";'
        + '    canvas.style.display="block";'
        + '    document.title = "Connected — <?= addslashes($server['name']) ?>";'
        + '  });'
        + '  rfb.addEventListener("disconnect", function(e){'
        + '    msg.innerHTML = "<div class=\\"err-box\\">🔌 Disconnected from console.<br><small style=\\"opacity:.7\\">"+(e.detail&&e.detail.reason||"Connection closed")+"<\/small><\/div>";'
        + '    msg.style.display="flex";'
        + '  });'
        + '  rfb.addEventListener("credentialsrequired", function(){'
        + '    rfb.sendCredentials({password:pass});'
        + '  });'
        + '} catch(err) {'
        + '  msg.innerHTML = "<div class=\\"err-box\\">❌ Failed to start console.<br><small style=\\"opacity:.7\\">"+err.message+"<\/small><\/div>";'
        + '}'
        + '<\/script>'
        + '<\/body><\/html>');
      w.document.close();
    } else {
      toast_show(d.error || 'Console not available.', 'err');
    }
  })
  .catch(function(){ hideLoading(); toast_show('Console request failed.','err'); });
}

/* ══ REBUILD ════════════════════════════════════════════════════ */
var rebuildSel = null;
function selectRebuildOs(key) {
  document.querySelectorAll('.os-card').forEach(function(c){ c.classList.remove('sel'); });
  var card = document.getElementById('rb-oscard-'+key);
  var sel  = document.getElementById('rb-ver-'+key);
  if (card) { card.classList.add('sel'); rebuildSel = sel ? sel.value : key; }
  var lbl = document.getElementById('rebuild-sel-lbl'); var btn = document.getElementById('rebuild-btn');
  if (lbl && sel) lbl.textContent = sel.value; if (btn) btn.disabled = false;
}
function selectRebuildOsVer(key, val) {
  document.querySelectorAll('.os-card').forEach(function(c){ c.classList.remove('sel'); });
  var card = document.getElementById('rb-oscard-'+key);
  if (card) card.classList.add('sel');
  rebuildSel = val;
  var lbl = document.getElementById('rebuild-sel-lbl'); var btn = document.getElementById('rebuild-btn');
  if (lbl) lbl.textContent = val; if (btn) btn.disabled = false;
}
function doRebuild() {
  if (!rebuildSel) { toast_show('Select an OS image.','err'); return; }
  modal_confirm(
    '<strong style="color:#dc2626">\u26A0 ALL DATA WILL BE PERMANENTLY DESTROYED.<\/strong><br><br>'
    +'Reinstalling with: <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px">'+esc(rebuildSel)+'<\/code><br><br>This cannot be undone.',
    function(){ doAction('rebuild', {image:rebuildSel}); },
    {icon:'\uD83D\uDD28', iconBg:'#fef2f2', ok:'Rebuild Now'}
  );
}

/* ══ SNAPSHOTS ══════════════════════════════════════════════════ */
function snapshotModal() { document.getElementById('snap-modal').style.display = 'flex'; }
function doSnapshot() {
  var desc = document.getElementById('snap-desc').value.trim() || '<?= date('Y-m-d H:i') ?>';
  document.getElementById('snap-modal').style.display = 'none';
  doAction('create_snapshot', {description:desc});
}
function loadSnapshots() {
  var wrap = document.getElementById('snap-list-wrap');
  wrap.innerHTML = spinner();
  fetch(BASE+'/api/server-action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:SID,action:'list_snapshots',csrf:CSRF})})
  .then(function(r){return r.json();}).then(function(d){
    if (!d.ok) { wrap.innerHTML = err_row(d.error); return; }
    var s = d.snapshots||[];
    if (!s.length) { wrap.innerHTML = empty_row('No snapshots yet.'); return; }
    wrap.innerHTML = s.map(function(x){
      return '<div style="display:flex;align-items:center;gap:12px;padding:13px 18px;border-bottom:1px solid #f1f5f9">'
        +'<div style="font-size:22px">\uD83D\uDCF8<\/div>'
        +'<div style="flex:1"><div style="font-size:13.5px;font-weight:700;color:#1e293b">'+esc(x.description||'Snapshot')+'<\/div>'
        +'<div style="font-size:12px;color:#94a3b8;margin-top:2px">'+(x.image_size||'—')+' GB &middot; '+esc(x.created||'')+'<\/div><\/div>'
        +'<button class="btn btn-ghost btn-sm" style="color:#dc2626;border-color:#fca5a5" onclick="modal_confirm(\'Delete snapshot?\',function(){doAction(\'delete_snapshot\',{image_id:'+x.id+'},function(){loadSnapshots();})},{icon:\'\uD83D\uDDD1\uFE0F\',iconBg:\'#fef2f2\',ok:\'Delete\'})">Delete<\/button>'
        +'<\/div>';
    }).join('');
  }).catch(function(){ wrap.innerHTML = err_row('Request failed.'); });
}

/* ══ VOLUMES ════════════════════════════════════════════════════ */
function loadVolumes() {
  var wrap = document.getElementById('vol-list-wrap');
  wrap.innerHTML = spinner();
  fetch(BASE+'/api/server-action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:SID,action:'list_volumes',csrf:CSRF})})
  .then(function(r){return r.json();}).then(function(d){
    if (!d.ok) { wrap.innerHTML = err_row(d.error); return; }
    var v = d.volumes||[];
    if (!v.length) { wrap.innerHTML = empty_row('No volumes attached to this server.'); return; }
    wrap.innerHTML = v.map(function(x){
      return '<div style="display:flex;align-items:center;gap:12px;padding:13px 18px;border-bottom:1px solid #f1f5f9">'
        +'<div style="width:34px;height:34px;border-radius:9px;background:#eff6ff;border:1px solid #bfdbfe;display:flex;align-items:center;justify-content:center;font-size:16px">\uD83D\uDCBE<\/div>'
        +'<div style="flex:1"><div style="font-size:13.5px;font-weight:700;color:#1e293b">'+esc(x.name)+'<\/div>'
        +'<div style="font-size:12px;color:#94a3b8;margin-top:2px">'+x.size+' GB &middot; '+esc((x.location&&x.location.name)||'—')+' &middot; '+esc(x.linux_device||'/dev/disk')+'<\/div><\/div>'
        +'<button class="btn btn-ghost btn-sm" style="color:#dc2626;border-color:#fca5a5" onclick="modal_confirm(\'Detach volume &quot;'+esc(x.name)+'&quot;?\',function(){doAction(\'detach_volume\',{volume_id:'+x.id+'},function(){loadVolumes();})},{icon:\'\uD83D\uDCBE\',ok:\'Detach\'})">Detach<\/button>'
        +'<\/div>';
    }).join('');
  }).catch(function(){ wrap.innerHTML = err_row('Request failed.'); });
}
function doCreateVolume() {
  var name=document.getElementById('vol-name').value.trim();
  var size=parseInt(document.getElementById('vol-size').value)||20;
  var fmt =document.getElementById('vol-format').value;
  if (!name) { toast_show('Volume name required.','err'); return; }
  document.getElementById('create-vol-modal').style.display='none';
  doAction('create_volume',{volume_name:name,size_gb:size,format:fmt,automount:true},function(){ setTimeout(loadVolumes,1500); });
}

/* ══ FIREWALLS ══════════════════════════════════════════════════ */
function loadFirewalls() {
  var wrap = document.getElementById('fw-list-wrap');
  if (!wrap) return;
  wrap.innerHTML = spinner();
  fetch(BASE+'/api/server-action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:SID,action:'list_firewalls',csrf:CSRF})})
  .then(function(r){return r.json();}).then(function(d){
    if (!d.ok) { wrap.innerHTML = err_row(d.error); return; }
    var fw = d.firewalls||[];
    if (!fw.length) {
      wrap.innerHTML = '<div style="padding:24px;text-align:center;color:#94a3b8;font-size:13px">No firewalls attached to this server.<br><span style="font-size:12px;margin-top:4px;display:block">Use your library below to apply one.</span><\/div>';
      return;
    }
    wrap.innerHTML = fw.map(function(f){
      var rules = f.rules||[];
      return '<div class="fw-item">'
        +'<div class="fw-ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"\/><\/svg><\/div>'
        +'<div style="flex:1"><div style="font-size:13.5px;font-weight:700;color:#1e293b">'+esc(f.name)+'<\/div>'
        +'<div style="font-size:12px;color:#94a3b8;margin-top:2px">'+rules.length+' rules &middot; Status: '+esc(f.status||'applied')+' &middot; ID: '+f.id+'<\/div><\/div>'
        +'<button class="btn btn-ghost btn-sm" style="color:#dc2626;border-color:#fca5a5" '
        +'onclick="modal_confirm(\'Remove firewall &quot;'+esc(f.name)+'&quot; from server?\',function(){doAction(\'remove_firewall\',{firewall_id:'+f.id+'},function(){loadFirewalls();})},{icon:\'\uD83D\uDEE1\uFE0F\',iconBg:\'#fef2f2\',ok:\'Remove\'})">Remove<\/button>'
        +'<\/div>';
    }).join('');
  }).catch(function(){ wrap.innerHTML = err_row('Request failed.'); });
}
function applyFw(fw_id) {
  if (!fw_id) { toast_show('Invalid firewall ID — sync provider first.','err'); return; }
  doAction('apply_firewall',{firewall_id:fw_id},function(){ setTimeout(loadFirewalls,1500); });
}
function removeFw(fw_id) {
  if (!fw_id) { toast_show('Invalid firewall ID.','err'); return; }
  doAction('remove_firewall',{firewall_id:fw_id},function(){ setTimeout(loadFirewalls,1500); });
}
function showApplyFwModal() { document.getElementById('apply-fw-modal').style.display='flex'; }
function doApplyFwFromModal() {
  var sel = document.querySelector('input[name="modal-fw"]:checked');
  if (!sel) { toast_show('Select a firewall.','err'); return; }
  document.getElementById('apply-fw-modal').style.display='none';
  applyFw(parseInt(sel.value));
}

/* ══ FLOATING IPs ═══════════════════════════════════════════════ */
function loadFloatingIps() {
  var wrap = document.getElementById('fip-list-wrap');
  if (!wrap) return;
  wrap.innerHTML = spinner('small');
  fetch(BASE+'/api/server-action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:SID,action:'list_floating_ips',csrf:CSRF})})
  .then(function(r){return r.json();}).then(function(d){
    if (!d.ok) { wrap.innerHTML = err_row(d.error); return; }
    var assigned=d.assigned||[], available=d.available||[];
    var html = '';
    if (!assigned.length) {
      html += '<div style="font-size:13px;color:#94a3b8;padding:0 0 12px">There is no Floating IP assigned to this server.</div>';
    } else {
      html += '<table class="ntbl" style="margin-bottom:12px"><thead><tr><th>IP Address<\/th><th>Type<\/th><th>Location<\/th><th><\/th><\/tr><\/thead><tbody>';
      assigned.forEach(function(f){
        html += '<tr>'
          +'<td style="font-family:monospace;font-weight:700">'+esc(f.ip)+'<\/td>'
          +'<td><span class="proto">'+esc(f.type)+'<\/span><\/td>'
          +'<td>'+esc((f.home_location&&f.home_location.name)||'—')+'<\/td>'
          +'<td style="display:flex;gap:6px;padding:8px 12px">'
          +'<button class="btn btn-ghost btn-sm" onclick="modal_confirm(\'Unassign Floating IP '+esc(f.ip)+'?\',function(){doAction(\'unassign_floating_ip\',{fip_id:'+f.id+'},function(){loadFloatingIps();})},{icon:\'\uD83C\uDF10\',ok:\'Unassign\'})">Unassign<\/button>'
          +'<button class="btn btn-ghost btn-sm" style="color:#dc2626;border-color:#fca5a5" onclick="modal_confirm(\'Delete Floating IP '+esc(f.ip)+'? This cannot be undone.\',function(){doAction(\'delete_floating_ip\',{fip_id:'+f.id+'},function(){loadFloatingIps();})},{icon:\'\uD83D\uDDD1\uFE0F\',iconBg:\'#fef2f2\',ok:\'Delete\'})">Delete<\/button>'
          +'<\/td><\/tr>';
      });
      html += '<\/tbody><\/table>';
    }
    if (available.length) {
      html += '<div style="font-size:12px;color:#64748b;padding:8px 0 0">'+available.length+' unassigned Floating IP(s) in your account &mdash; <button class="btn btn-ghost btn-sm" onclick="floatingModal(\'assign\')">Assign one<\/button><\/div>';
    }
    wrap.innerHTML = html;
  }).catch(function(){ wrap.innerHTML = err_row('Request failed.'); });
}

function floatingModal(type) {
  if (type === 'create') {
    showModal({
      icon:'\uD83C\uDF10', iconBg:'#eff6ff', title:'Add Floating IP',
      body:'<div style="margin-bottom:12px"><label style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:5px">Type<\/label>'
        +'<select id="fip-type" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:13px;outline:none">'
        +'<option value="ipv4">IPv4<\/option><option value="ipv6">IPv6<\/option><\/select><\/div>'
        +'<div style="font-size:12px;color:#94a3b8">A new Floating IP will be created and assigned to this server in the same location.<\/div>',
      buttons:[{label:'Create & Assign', cb:function(){
        var t=document.getElementById('fip-type');
        doAction('create_floating_ip',{type:t?t.value:'ipv4'},function(){setTimeout(loadFloatingIps,1500);});
      }}]
    });
  } else {
    fetch(BASE+'/api/server-action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:SID,action:'list_floating_ips',csrf:CSRF})})
    .then(function(r){return r.json();}).then(function(d){
      var avail = d.available||[];
      if (!avail.length) { toast_show('No unassigned Floating IPs available.','info'); return; }
      var opts = avail.map(function(f,i){
        return '<label style="display:flex;align-items:center;gap:10px;padding:10px 13px;border:1.5px solid #e2e8f0;border-radius:9px;cursor:pointer;margin-bottom:7px">'
          +'<input type="radio" name="fip-assign-sel" value="'+f.id+'"'+(i===0?' checked':'')+' style="accent-color:var(--primary)">'
          +'<div><div style="font-size:13.5px;font-weight:700;color:#1e293b">'+esc(f.ip)+'<\/div>'
          +'<div style="font-size:12px;color:#94a3b8">'+esc(f.type)+' &middot; '+esc((f.home_location&&f.home_location.name)||'—')+'<\/div><\/div>'
          +'<\/label>';
      }).join('');
      showModal({
        icon:'\uD83C\uDF10', iconBg:'#eff6ff', title:'Assign Floating IP', body:opts,
        buttons:[{label:'Assign', cb:function(){
          var sel=document.querySelector('input[name="fip-assign-sel"]:checked');
          if(sel) doAction('assign_floating_ip',{fip_id:parseInt(sel.value)},function(){setTimeout(loadFloatingIps,1500);});
        }}]
      });
    });
  }
}

/* ══ PRIVATE NETWORKS ═══════════════════════════════════════════ */
function loadNetworks() {
  var tbody = document.getElementById('net-tbody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:18px"><div class="loading-spin" style="width:20px;height:20px;border-width:2px;margin:0 auto"><\/div><\/td><\/tr>';
  fetch(BASE+'/api/server-action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:SID,action:'list_networks',csrf:CSRF})})
  .then(function(r){return r.json();}).then(function(d){
    if (!d.ok) { tbody.innerHTML='<tr><td colspan="4" style="text-align:center;padding:16px;color:#dc2626;font-size:13px">'+esc(d.error)+'<\/td><\/tr>'; return; }
    var nets=d.networks||[];
    if (!nets.length) { tbody.innerHTML='<tr><td colspan="4" style="text-align:center;padding:20px;color:#94a3b8;font-size:13px">There is no private IP assigned to this server.<\/td><\/tr>'; return; }
    tbody.innerHTML = nets.map(function(n){
      return '<tr>'
        +'<td style="font-family:monospace;font-weight:700">'+esc(n.ip||'—')+'<\/td>'
        +'<td>'+esc((n.network&&n.network.name)||'Network #'+(n.network_id||'?'))+'<\/td>'
        +'<td style="font-family:monospace;font-size:12px">'+esc(n.mac_address||'—')+'<\/td>'
        +'<td><button class="btn btn-ghost btn-sm" style="color:#dc2626;border-color:#fca5a5" onclick="modal_confirm(\'Detach from network?\',function(){doAction(\'detach_network\',{network_id:'+n.network_id+'},function(){loadNetworks();})},{icon:\'\uD83D\uDD12\',ok:\'Detach\'})">Detach<\/button><\/td>'
        +'<\/tr>';
    }).join('');
  }).catch(function(){ tbody.innerHTML='<tr><td colspan="4" style="text-align:center;padding:16px;color:#dc2626;font-size:13px">Request failed.<\/td><\/tr>'; });
}

function netModal(type) {
  if (type === 'create') {
    showModal({
      icon:'\uD83D\uDD12', iconBg:'#f0fdf4', title:'Create Private Network',
      body:'<div style="margin-bottom:12px"><label style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:5px">Network Name<\/label>'
        +'<input id="net-name" placeholder="my-network" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:13px;outline:none;box-sizing:border-box"><\/div>'
        +'<div><label style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:5px">IP Range<\/label>'
        +'<input id="net-range" value="10.0.0.0/16" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:monospace;font-size:13px;outline:none;box-sizing:border-box"><\/div>',
      buttons:[{label:'Create & Attach', cb:function(){
        var nm=document.getElementById('net-name');var rg=document.getElementById('net-range');
        var name=nm?nm.value.trim():''; var range=rg?rg.value.trim():'10.0.0.0/16';
        if(!name){toast_show('Network name required.','err');return;}
        doAction('create_network',{network_name:name,ip_range:range},function(){setTimeout(loadNetworks,1500);});
      }}]
    });
  } else {
    fetch(BASE+'/api/server-action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:SID,action:'list_all_networks',csrf:CSRF})})
    .then(function(r){return r.json();}).then(function(d){
      var nets=d.networks||[];
      if(!nets.length){toast_show('No networks available. Create one first.','info');return;}
      var opts=nets.map(function(n,i){
        return '<label style="display:flex;align-items:center;gap:10px;padding:10px 13px;border:1.5px solid #e2e8f0;border-radius:9px;cursor:pointer;margin-bottom:7px">'
          +'<input type="radio" name="net-attach-sel" value="'+n.id+'"'+(i===0?' checked':'')+' style="accent-color:var(--primary)">'
          +'<div><div style="font-size:13.5px;font-weight:700;color:#1e293b">'+esc(n.name)+'<\/div>'
          +'<div style="font-size:12px;color:#94a3b8">'+esc(n.ip_range||'—')+'<\/div><\/div><\/label>';
      }).join('');
      showModal({
        icon:'\uD83D\uDD12', iconBg:'#f0fdf4', title:'Attach to Network', body:opts,
        buttons:[{label:'Attach', cb:function(){
          var sel=document.querySelector('input[name="net-attach-sel"]:checked');
          if(sel) doAction('attach_network',{network_id:parseInt(sel.value)},function(){setTimeout(loadNetworks,1500);});
        }}]
      });
    });
  }
}

/* ══ DELETE ═════════════════════════════════════════════════════ */
function confirmDelete() {
  var name = '<?= addslashes($server['name']) ?>';
  modal_input(
    'Delete Server',
    '<span style="color:#dc2626;font-weight:700">\u26A0 ALL DATA WILL BE PERMANENTLY DESTROYED.<\/span><br><br>'
    +'Type the server name <strong><?= htmlspecialchars($server['name']) ?><\/strong> to confirm deletion:',
    name, name,
    function(){ doAction('delete', {}); }
  );
}

/* ══ STATUS POLLING ═════════════════════════════════════════════ */
<?php if ($is_pending): ?>
var pollCount = 0;
var pollTimer = setInterval(function() {
  if (++pollCount > 120) { clearInterval(pollTimer); return; }
  fetch(BASE+'/api/server-status-poll.php?id='+SID+'&csrf='+CSRF)
  .then(function(r){return r.json();}).then(function(d){
    if(!d.ok) return;
    var colors={running:'#16a34a',stopped:'#6b7280',suspended:'#dc2626',provisioning:'#d97706',starting:'#d97706',stopping:'#d97706',rebuilding:'#2563eb'};
    var c=colors[d.status]||'#6b7280';
    var dot=document.getElementById('hdr-dot'); var pill=document.getElementById('hdr-status-pill'); var txt=document.getElementById('hdr-status-txt');
    if(dot){dot.style.background=c; dot.style.animation=d.status==='running'?'sdp 2s ease-in-out infinite':'none';}
    if(pill){pill.style.background=c+'18'; pill.style.color=c;}
    if(txt) txt.textContent=d.status.charAt(0).toUpperCase()+d.status.slice(1);
    if(d.ipv4){var ipEl=document.getElementById('hdr-ipv4'); if(ipEl) ipEl.textContent=d.ipv4;}
    if(d.final){clearInterval(pollTimer); toast_show(d.status==='running'?'\u2713 Server is running!':'Status: '+d.status,'ok'); setTimeout(function(){location.reload();},1800);}
  }).catch(function(){});
}, 5000);
<?php endif; ?>

/* ══ HELPERS ════════════════════════════════════════════════════ */
function spinner(size) {
  var s = size==='small' ? 'width:20px;height:20px;border-width:2px' : 'width:24px;height:24px;border-width:2.5px';
  return '<div style="padding:24px;text-align:center"><div class="loading-spin" style="'+s+';margin:0 auto 8px"><\/div><div style="font-size:12.5px;color:#94a3b8">Loading...<\/div><\/div>';
}
function err_row(msg) {
  return '<div style="padding:18px;text-align:center;color:#dc2626;font-size:13px">'+esc(msg||'Error')+'<\/div>';
}
function empty_row(msg) {
  return '<div style="padding:24px;text-align:center;color:#94a3b8;font-size:13px">'+esc(msg)+'<\/div>';
}

/* ══ MODAL CLOSE + AUTO LOAD ════════════════════════════════════ */
['snap-modal','create-vol-modal','apply-fw-modal'].forEach(function(id){
  var el=document.getElementById(id);
  if(el) el.addEventListener('click',function(e){if(e.target===el) el.style.display='none';});
});


/* ══ GRAPHS TAB ══════════════════════════════════════════════════ */
var _charts = {};

function _destroyChart(id) {
  if (_charts[id]) { try { _charts[id].destroy(); } catch(e){} delete _charts[id]; }
}

function _mkChart(id, labels, data, color, yLabel, fill) {
  _destroyChart(id);
  var ctx = document.getElementById(id);
  if (!ctx) return;
  _charts[id] = new Chart(ctx.getContext('2d'), {
    type: 'line',
    data: {
      labels: labels,
      datasets: [{
        data: data,
        borderColor: color,
        backgroundColor: fill ? color.replace(')',',0.10)').replace('rgb','rgba') : 'transparent',
        borderWidth: 1.8,
        pointRadius: labels.length > 60 ? 0 : 2,
        pointHoverRadius: 4,
        tension: 0.35,
        fill: !!fill,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 400 },
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: function(ctx) { return ' ' + ctx.parsed.y + (yLabel ? ' ' + yLabel : ''); }
          }
        }
      },
      scales: {
        x: {
          grid: { color: '#f1f5f9' },
          ticks: { color: '#94a3b8', font: { size: 10 }, maxTicksLimit: 8, maxRotation: 0 }
        },
        y: {
          grid: { color: '#f1f5f9' },
          ticks: { color: '#94a3b8', font: { size: 10 }, maxTicksLimit: 5 },
          beginAtZero: true
        }
      }
    }
  });
}

function loadGraphs() {
  var range = (new URLSearchParams(window.location.search).get('range')) || '24h';
  var wrap  = document.getElementById('graphs-wrap');
  var grid  = document.getElementById('charts-grid');
  var bwCard = document.getElementById('chart-bw-card');
  var loader = document.getElementById('graphs-loading');
  var note   = document.getElementById('graphs-note');

  if (!wrap) return;

  // Show loader
  if(loader) loader.style.display = 'block';
  if(grid)   grid.style.opacity   = '0.4';
  if(note)   { note.style.display='none'; note.textContent=''; }

  fetch(BASE + '/api/server-metrics.php?id=' + SID + '&range=' + range + '&csrf=' + CSRF)
  .then(function(r){ return r.json(); })
  .then(function(d) {
    if(loader) loader.style.display = 'none';
    if(grid)   grid.style.opacity   = '1';

    if (!d.ok) {
      if(note){ note.style.display='block'; note.textContent = '⚠ ' + (d.error||'Failed to load metrics'); }
      return;
    }

    // CPU
    if (d.cpu && d.cpu.data && d.cpu.data.length > 0) {
      _mkChart('chart-cpu', d.cpu.labels, d.cpu.data, 'rgb(99,102,241)', '%', true);
      var empty = document.getElementById('chart-cpu-empty');
      if(empty) empty.style.display = 'none';
    } else {
      _destroyChart('chart-cpu');
      var empty = document.getElementById('chart-cpu-empty');
      if(empty){ empty.style.display='flex'; empty.textContent='CPU data not available'; }
    }

    // Network In
    var netInUnit = (d.network_in && d.network_in.unit) ? d.network_in.unit : 'Mbps';
    var netInEl = document.getElementById('netin-unit');
    if(netInEl) netInEl.textContent = '(' + netInUnit + ')';
    if (d.network_in && d.network_in.data && d.network_in.data.length > 0) {
      _mkChart('chart-netin', d.network_in.labels, d.network_in.data, 'rgb(16,185,129)', netInUnit, true);
    }

    // Network Out
    var netOutUnit = (d.network_out && d.network_out.unit) ? d.network_out.unit : 'Mbps';
    var netOutEl = document.getElementById('netout-unit');
    if(netOutEl) netOutEl.textContent = '(' + netOutUnit + ')';
    if (d.network_out && d.network_out.data && d.network_out.data.length > 0) {
      _mkChart('chart-netout', d.network_out.labels, d.network_out.data, 'rgb(245,158,11)', netOutUnit, true);
    }

    // Disk
    var hasDisk = d.disk_read && d.disk_read.data && d.disk_read.data.length > 0;
    var hasDiskW = d.disk_write && d.disk_write.data && d.disk_write.data.length > 0;
    if (hasDisk || hasDiskW) {
      var dLabels = hasDisk ? d.disk_read.labels : d.disk_write.labels;
      _destroyChart('chart-disk');
      var ctx = document.getElementById('chart-disk');
      if(ctx) {
        _charts['chart-disk'] = new Chart(ctx.getContext('2d'), {
          type: 'line',
          data: {
            labels: dLabels,
            datasets: [
              hasDisk  ? { label:'Read',  data:d.disk_read.data,  borderColor:'rgb(239,68,68)',  backgroundColor:'rgba(239,68,68,0.08)',  borderWidth:1.8, pointRadius:dLabels.length>60?0:2, tension:0.35, fill:true } : null,
              hasDiskW ? { label:'Write', data:d.disk_write.data, borderColor:'rgb(168,85,247)', backgroundColor:'rgba(168,85,247,0.08)', borderWidth:1.8, pointRadius:dLabels.length>60?0:2, tension:0.35, fill:true } : null,
            ].filter(Boolean)
          },
          options: {
            responsive:true, maintainAspectRatio:false, animation:{duration:400},
            plugins:{ legend:{display:hasDisk&&hasDiskW, labels:{font:{size:11},boxWidth:10}} },
            scales:{
              x:{grid:{color:'#f1f5f9'}, ticks:{color:'#94a3b8',font:{size:10},maxTicksLimit:8,maxRotation:0}},
              y:{grid:{color:'#f1f5f9'}, ticks:{color:'#94a3b8',font:{size:10},maxTicksLimit:5}, beginAtZero:true}
            }
          }
        });
      }
    }

    // Bandwidth bar
    var used  = parseFloat(d.bandwidth_used_gb  || 0);
    var total = parseFloat(d.bandwidth_total_gb || 0);
    var pct   = total > 0 ? Math.min(100, Math.round(used/total*100)) : 0;

    var usedStr  = used  >= 1024 ? (used/1024).toFixed(2)+' TB'  : used.toFixed(2)+' GB';
    var totalStr = total >= 1024 ? (total/1024).toFixed(2)+' TB' : total+' GB';

    var bwUsedEl  = document.getElementById('bw-used');
    var bwTotalEl = document.getElementById('bw-total');
    var bwPctEl   = document.getElementById('bw-pct');
    var bwBar     = document.getElementById('bw-bar');

    if(bwUsedEl)  bwUsedEl.textContent  = usedStr;
    if(bwTotalEl) bwTotalEl.textContent = total > 0 ? totalStr : 'Unlimited';
    if(bwPctEl)   bwPctEl.textContent   = total > 0 ? pct+'%' : '—';
    if(bwBar)     bwBar.style.width     = pct + '%';
    if(bwBar)     bwBar.style.background = pct > 85 ? '#ef4444' : pct > 60 ? '#f59e0b' : 'var(--primary)';

    // Note
    if (d.note) {
      if(note){ note.style.display='block'; note.textContent='ℹ ' + d.note; }
    }
  })
  .catch(function(err) {
    if(loader) loader.style.display='none';
    if(grid)   grid.style.opacity='1';
    if(note){ note.style.display='block'; note.textContent='⚠ Failed to load metrics. Please try again.'; }
  });
}

document.addEventListener('DOMContentLoaded', function(){
  if (ACTIVE_TAB==='firewalls')  loadFirewalls();
  if (ACTIVE_TAB==='volumes')    loadVolumes();
  if (ACTIVE_TAB==='snapshots')  loadSnapshots();
  if (ACTIVE_TAB==='networking') { loadFloatingIps(); loadNetworks(); }
  if (ACTIVE_TAB==='graphs')     loadGraphs();
});
</script></body>
</html>