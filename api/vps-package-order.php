<?php
/**
 * api/vps-package-order.php
 * User orders a VPS package → wallet is charged (first month) → a server is
 * auto-provisioned on Virtualizor (WHMCS-style). Server then bills hourly
 * through the existing cron/suspend system (price_hourly = monthly / 730).
 *
 * POST JSON: { package_id: 5, hostname?: "my-vps", csrf: "..." }
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/servers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'error'=>'POST required']); exit; }

require_login();
$user = current_user();

$body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
if (!verify_csrf($body['csrf'] ?? $body['csrf_token'] ?? '')) {
    echo json_encode(['ok'=>false,'error'=>'Invalid CSRF token']); exit;
}

$package_id = (int)($body['package_id'] ?? 0);
$hostname   = trim($body['hostname'] ?? '');
if (!$package_id) { echo json_encode(['ok'=>false,'error'=>'Package required']); exit; }

$cycle_months = (int)($body['cycle_months'] ?? 1);

// ── Load package ──────────────────────────────────────────────
$pkg = db()->prepare("SELECT * FROM vps_packages WHERE id=? AND is_active=1 LIMIT 1");
$pkg->execute([$package_id]);
$pkg = $pkg->fetch();
if (!$pkg) { echo json_encode(['ok'=>false,'error'=>'Package not available']); exit; }

$ptype    = ($pkg['ptype'] ?? 'vps') === 'dedicated' ? 'dedicated' : 'vps';
$currency = 'INR';       // billing is INR-only
$sym      = '₹';

// ── Resolve the chosen billing cycle (must be enabled) ────────
$cyc = db()->prepare("SELECT * FROM package_cycles WHERE package_id=? AND months=? AND is_enabled=1 LIMIT 1");
$cyc->execute([$package_id, $cycle_months]);
$cyc = $cyc->fetch();
if (!$cyc) { echo json_encode(['ok'=>false,'error'=>'Selected billing cycle is not available for this package.']); exit; }

$price = (float)$cyc['price_inr'];
if ($price <= 0) { echo json_encode(['ok'=>false,'error'=>'This cycle has no price set. Contact support.']); exit; }

// ── Server-limit gate (VPS only; dedicated is manual) ─────────
if ($ptype === 'vps') {
    $max_servers = (int)get_setting('max_servers_per_user', '0');
    if ($max_servers > 0 && ($user['role'] ?? 'user') === 'user') {
        $cnt = db()->prepare("SELECT COUNT(*) FROM servers WHERE user_id=? AND deleted_at IS NULL");
        $cnt->execute([(int)$user['id']]);
        if ((int)$cnt->fetchColumn() >= $max_servers) {
            echo json_encode(['ok'=>false,'error'=>"Server limit reached ($max_servers). Contact support."]); exit;
        }
    }
}

$cycle_label = $cycle_months === 1 ? '1 month' : $cycle_months . ' months';

// ── Charge wallet (full cycle upfront — prepaid) ──────────────
$ok = wallet_deduct((int)$user['id'], $price, 'VPS package: ' . $pkg['name'] . ' (' . $cycle_label . ')', 'package_order', $package_id);
if (!$ok) {
    echo json_encode(['ok'=>false,'error'=>'Insufficient balance. Please top up '.$sym.number_format($price,2).' and try again.','code'=>'INSUFFICIENT_BALANCE']); exit;
}

$expires_at = date('Y-m-d H:i:s', strtotime("+{$cycle_months} months"));

// ── Record order (pending) ────────────────────────────────────
db()->prepare("INSERT INTO vps_package_orders (user_id,package_id,status,amount,currency,cycle_months,expires_at) VALUES (?,?, 'pending', ?, ?, ?, ?)")
    ->execute([(int)$user['id'], $package_id, $price, $currency, $cycle_months, $expires_at]);
$order_id = (int)db()->lastInsertId();

// Helper: refund + mark failed, then respond
$fail = function(string $err) use ($order_id, $price, $user, $package_id) {
    wallet_credit((int)$user['id'], $price, 'Refund — provisioning failed', 'refund', $package_id);
    db()->prepare("UPDATE vps_package_orders SET status='refunded', error=? WHERE id=?")
        ->execute([substr($err, 0, 500), $order_id]);
    echo json_encode(['ok'=>false,'error'=>$err]); exit;
};

// ════════════════════════════════════════════════════════════
//  DEDICATED — no panel: charge + pending order + notify admin
// ════════════════════════════════════════════════════════════
if ($ptype === 'dedicated') {
    // Order stays 'pending' for manual fulfilment. Notify admin by email (non-fatal).
    try {
        require_once __DIR__ . '/../includes/mailer.php';
        $admin_email = get_setting('company_email', '') ?: get_setting('SMTP_FROM', '');
        if ($admin_email && function_exists('send_mail')) {
            send_mail($admin_email, 'Admin',
                'New Dedicated Server order #' . $order_id,
                '<h2>New Dedicated Server Order</h2>'
                . '<p><strong>Customer:</strong> ' . htmlspecialchars($user['full_name'] ?: $user['username']) . ' (' . htmlspecialchars($user['email']) . ')</p>'
                . '<p><strong>Package:</strong> ' . htmlspecialchars($pkg['name']) . '</p>'
                . '<p><strong>Cycle:</strong> ' . $cycle_label . '</p>'
                . '<p><strong>Paid:</strong> ' . $sym . number_format($price, 2) . '</p>'
                . '<p><strong>Specs:</strong> ' . (int)$pkg['vcpu'] . ' cores / ' . htmlspecialchars($pkg['ram_gb']) . ' GB / ' . (int)$pkg['disk_gb'] . ' GB'
                . (!empty($pkg['cpu_label']) ? ' — ' . htmlspecialchars($pkg['cpu_label']) : '') . '</p>'
                . '<p>Provision the box manually, then mark the order active in Admin.</p>');
        }
    } catch (Throwable $e) { error_log('[pkg-order] dedicated notify: ' . $e->getMessage()); }

    error_log('[pkg-order] DEDICATED order #'.$order_id.' user='.$user['id'].' pkg='.$package_id);
    echo json_encode([
        'ok'       => true,
        'message'  => 'Order placed! Our team will set up your dedicated server and update you shortly.',
        'pending'  => true,
        'redirect' => BASE_URL . '/dedicated.php',
    ]);
    exit;
}

// ── Provisioning mode: automatic (instant) or manual (admin processes) ──
// Admin setting 'vps_provision_mode' = 'auto' (default) | 'manual'.
if (get_setting('vps_provision_mode', 'auto') === 'manual') {
    // Leave the order pending in the admin Orders queue; notify admin.
    try {
        require_once __DIR__ . '/../includes/mailer.php';
        $admin_email = get_setting('company_email', '') ?: get_setting('SMTP_FROM', '');
        if ($admin_email && function_exists('send_mail')) {
            send_mail($admin_email, 'Admin', 'New VPS order #' . $order_id . ' — awaiting provisioning',
                '<p>New VPS order <strong>#' . $order_id . '</strong> from ' . htmlspecialchars($user['email'])
                . ' for <strong>' . htmlspecialchars($pkg['name']) . '</strong> (' . $cycle_label . ', ' . $sym . number_format($price,2) . ').</p>'
                . '<p>Process it in Admin → Orders.</p>');
        }
    } catch (Throwable $e) { error_log('[pkg-order] manual notify: '.$e->getMessage()); }
    echo json_encode([
        'ok'      => true,
        'message' => 'Order placed! Your server will be set up shortly and appear in your dashboard.',
        'pending' => true,
        'redirect'=> BASE_URL . '/servers.php',
    ]);
    exit;
}

// ── Load Virtualizor provider (VPS path) ──────────────────────
$prov = db()->prepare("SELECT * FROM providers WHERE id=? AND provider_type='virtualizor' LIMIT 1");
$prov->execute([(int)$pkg['provider_id']]);
$prov = $prov->fetch();
if (!$prov || empty($prov['api_key'])) { $fail('Provider not configured.'); }

// ── Provision on Virtualizor (mirrors api/virt-create.php) ─────
try {
    require_once __DIR__ . '/../providers/virtualizor/client.php';
    $client = new VirtualizorClient($prov);

    $root_pass = bin2hex(random_bytes(8)) . 'V!1';
    $name = $hostname !== ''
        ? preg_replace('/[^a-zA-Z0-9\-\.]/', '', $hostname)
        : ($pkg['slug'] . '-' . substr(bin2hex(random_bytes(3)), 0, 5));

    $payload = [
        'hostname'   => $name,
        'rootpass'   => $root_pass,
        'osid'       => $pkg['virt_osid'],
        'plid'       => $pkg['virt_plid'],
        'serid'      => $pkg['virt_serid'],
        'user_email' => $user['email'],
        'user_fname' => $user['full_name'] ?: $user['username'],
        'user_lname' => '',
    ];

    $raw = $client->post('addvs', [], $payload);
    if (($raw['done'] ?? 0) != 1) {
        $fail('Virtualizor: ' . VirtualizorClient::errMsg($raw, 'provisioning failed (done=0)'));
    }

    $vpsid = $raw['vpsid'] ?? $raw['vps_info']['vpsid'] ?? $raw['vps']['vpsid'] ?? null;
    if (!$vpsid) { $fail('VPS created but no vpsid returned. Contact support.'); }

    // Fetch details for IP / live specs
    sleep(3);
    $vps = null;
    try {
        $detail = $client->get('listvs', ['vpsid' => $vpsid]);
        $vps = $detail['vpslist'][$vpsid] ?? array_values($detail['vpslist'] ?? [])[0] ?? null;
    } catch (Throwable $e) { error_log('[pkg-order] listvs: '.$e->getMessage()); }

    $ram_mb = (int)($vps['ram'] ?? 0);
    $vcpu   = (int)($vps['cores'] ?? $vps['vcpu'] ?? 0) ?: (int)$pkg['vcpu'];
    $ram_gb = $ram_mb > 0 ? round($ram_mb / 1024, 1) : (float)$pkg['ram_gb'];
    $disk   = (int)($vps['hdd'] ?? $vps['disk_gb'] ?? 0) ?: (int)$pkg['disk_gb'];
    $ipv4   = $vps['ips'][0] ?? $vps['ipv4'] ?? null;
    $status = empty($vps) ? 'provisioning' : (($vps['status'] ?? 0) == 1 ? 'running' : 'provisioning');

    // Encrypt root password (same scheme as servers/create.php)
    $enc = base64_encode(openssl_encrypt($root_pass, 'AES-128-ECB', substr(hash('sha256', $prov['api_key']), 0, 16)));

    // PREPAID: hourly billing OFF (cron skips price_hourly=0); expiry governs it.
    $price_monthly = round($price / max(1, $cycle_months), 2);

    $server_id = db_create_server((int)$user['id'], [
        'provider_id'        => (int)$vpsid,
        'source_provider_id' => (int)$prov['id'],
        'name'               => $name,
        'status'             => $status,
        'plan_slug'          => (string)$pkg['virt_plid'],
        'image_slug'         => (string)$pkg['virt_osid'],
        'region_slug'        => (string)$pkg['virt_serid'],
        'vcpu'               => $vcpu,
        'ram_gb'             => $ram_gb,
        'disk_gb'            => $disk,
        'ipv4'               => $ipv4,
        'os_label'           => $pkg['os_label'] ?: (string)($vps['os_name'] ?? ''),
        'region_label'       => (string)$pkg['virt_serid'],
        'region_flag'        => 'in',
        'price_hourly'       => 0.0,           // prepaid — no hourly charge
        'price_monthly'      => $price_monthly,
        'currency'           => $currency,
        'root_password'      => $enc,
        'total_bandwidth_gb' => (int)$pkg['bandwidth_gb'],
        'used_bandwidth_gb'  => 0,
    ]);

    // Mark server prepaid + set expiry (columns added by install-db.php)
    try {
        db()->prepare("UPDATE servers SET billing_type='prepaid', expires_at=? WHERE id=?")
            ->execute([$expires_at, $server_id]);
    } catch (Throwable $e) { error_log('[pkg-order] set prepaid failed: '.$e->getMessage()); }

    db()->prepare("UPDATE vps_package_orders SET status='active', server_id=?, vpsid=? WHERE id=?")
        ->execute([$server_id, (string)$vpsid, $order_id]);

    error_log('[pkg-order] SUCCESS user='.$user['id'].' pkg='.$package_id.' server='.$server_id.' vpsid='.$vpsid.' expires='.$expires_at);

    echo json_encode([
        'ok'        => true,
        'message'   => 'Server ordered! Provisioning has started.',
        'server_id' => $server_id,
        'redirect'  => BASE_URL . '/servers.php',
    ]);
} catch (Throwable $e) {
    error_log('[pkg-order] EXCEPTION: ' . $e->getMessage());
    $fail('Provisioning error: ' . $e->getMessage());
}
