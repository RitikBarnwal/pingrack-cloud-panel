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

// ── Load package + its Virtualizor provider ───────────────────
$pkg = db()->prepare("SELECT * FROM vps_packages WHERE id=? AND is_active=1 LIMIT 1");
$pkg->execute([$package_id]);
$pkg = $pkg->fetch();
if (!$pkg) { echo json_encode(['ok'=>false,'error'=>'Package not available']); exit; }

$prov = db()->prepare("SELECT * FROM providers WHERE id=? AND provider_type='virtualizor' LIMIT 1");
$prov->execute([(int)$pkg['provider_id']]);
$prov = $prov->fetch();
if (!$prov || empty($prov['api_key'])) { echo json_encode(['ok'=>false,'error'=>'Provider not configured']); exit; }

// ── Price in the user's currency (monthly) ────────────────────
$currency = strtoupper($user['currency'] ?? 'INR');
$price    = $currency === 'USD' ? (float)$pkg['price_usd'] : (float)$pkg['price_inr'];
$sym      = $currency === 'USD' ? '$' : '₹';

// ── Optional server-limit / KYC gate (same rules as panel) ────
$max_servers = (int)get_setting('max_servers_per_user', '0');
if ($max_servers > 0 && ($user['role'] ?? 'user') === 'user') {
    $cnt = db()->prepare("SELECT COUNT(*) FROM servers WHERE user_id=? AND deleted_at IS NULL");
    $cnt->execute([(int)$user['id']]);
    if ((int)$cnt->fetchColumn() >= $max_servers) {
        echo json_encode(['ok'=>false,'error'=>"Server limit reached ($max_servers). Contact support."]); exit;
    }
}

// ── Charge wallet (first month) ───────────────────────────────
if ($price > 0) {
    $ok = wallet_deduct((int)$user['id'], $price, 'VPS package: ' . $pkg['name'], 'package_order', $package_id);
    if (!$ok) {
        echo json_encode(['ok'=>false,'error'=>'Insufficient balance. Please top up '.$sym.number_format($price,2).' and try again.','code'=>'INSUFFICIENT_BALANCE']); exit;
    }
}

// ── Record pending order ──────────────────────────────────────
db()->prepare("INSERT INTO vps_package_orders (user_id,package_id,status,amount,currency) VALUES (?,?, 'pending', ?, ?)")
    ->execute([(int)$user['id'], $package_id, $price, $currency]);
$order_id = (int)db()->lastInsertId();

// Helper: refund + mark failed, then respond
$fail = function(string $err) use ($order_id, $price, $user, $package_id) {
    if ($price > 0) {
        wallet_credit((int)$user['id'], $price, 'Refund — VPS provisioning failed', 'refund', $package_id);
    }
    db()->prepare("UPDATE vps_package_orders SET status='refunded', error=? WHERE id=?")
        ->execute([substr($err, 0, 500), $order_id]);
    echo json_encode(['ok'=>false,'error'=>$err]); exit;
};

// ── Provision on Virtualizor (mirrors api/virt-create.php) ─────
try {
    require_once __DIR__ . '/../providers/virtualizor/client.php';
    $client = new VirtualizorClient($prov['api_key']);

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

    $price_hourly  = $price > 0 ? round($price / 730, 6) : 0.0;

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
        'price_hourly'       => $price_hourly,
        'price_monthly'      => $price,
        'currency'           => $currency,
        'root_password'      => $enc,
        'total_bandwidth_gb' => (int)$pkg['bandwidth_gb'],
        'used_bandwidth_gb'  => 0,
    ]);

    db()->prepare("UPDATE vps_package_orders SET status='active', server_id=?, vpsid=? WHERE id=?")
        ->execute([$server_id, (string)$vpsid, $order_id]);

    error_log('[pkg-order] SUCCESS user='.$user['id'].' pkg='.$package_id.' server='.$server_id.' vpsid='.$vpsid);

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
