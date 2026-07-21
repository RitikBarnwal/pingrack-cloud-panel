<?php
/**
 * api/virt-create.php
 *
 * Create a new VPS on Virtualizor when user places an order from the panel.
 * Called by servers/create.php after payment check passes.
 *
 * POST JSON:
 *   {
 *     provider_id : 1,          // providers table ID
 *     plan        : "vz-2c-4g", // plan slug or numeric plid
 *     image       : "271",      // Virtualizor OS template ID (osid)
 *     region      : "node-1",   // serid (server/node ID on Virtualizor)
 *     name        : "my-vps",   // hostname
 *     user_id     : 5,          // which user to assign to
 *     ssh_keys    : [],         // array of public key strings (optional)
 *     csrf_token  : "..."
 *   }
 *
 * Returns:
 *   { ok:true, server_id:42, vpsid:1024, root_password:"..." }
 *   { ok:false, error:"..." }
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/servers.php';
require_once __DIR__ . '/../includes/admin.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required.']); exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$csrf = $body['csrf_token'] ?? $body['csrf'] ?? '';

if (!verify_csrf($csrf)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF.']); exit;
}

$me = current_user();

$provider_id = (int)($body['provider_id'] ?? 0);
$plan        = trim($body['plan']         ?? '');
$image       = trim($body['image']        ?? '');
$region      = trim($body['region']       ?? '');
$name        = trim($body['name']         ?? '');
$target_uid  = (int)($body['user_id']     ?? $me['id']);
$ssh_keys    = (array)($body['ssh_keys']  ?? []);

// ── KYC / Server limit — backend security check ─────────────
// Only applies when target user is the logged-in user (not admin)
if ($target_uid === (int)$me['id'] && ($me['role'] ?? 'user') === 'user') {
    $max_without_kyc = (int)get_setting('max_servers_without_kyc', '0');
    if ($max_without_kyc > 0) {
        $kyc_st = db()->prepare(
            "SELECT status FROM kyc_requests WHERE user_id=? ORDER BY submitted_at DESC LIMIT 1"
        );
        $kyc_st->execute([$target_uid]);
        $kyc_status_be = $kyc_st->fetchColumn() ?: 'none';

        if ($kyc_status_be !== 'approved') {
            $srv_cnt = db()->prepare(
                "SELECT COUNT(*) FROM servers WHERE user_id=? AND deleted_at IS NULL AND status != 'deleted'"
            );
            $srv_cnt->execute([$target_uid]);
            if ((int)$srv_cnt->fetchColumn() >= $max_without_kyc) {
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Server limit reached. Complete KYC verification to create more servers.',
                    'code'  => 'KYC_REQUIRED',
                ]);
                exit;
            }
        }
    }
}

// Validate
$missing = [];
if (!$provider_id) $missing[] = 'provider_id';
if (!$plan)        $missing[] = 'plan';
if (!$image)       $missing[] = 'image (Virtualizor osid)';
if (!$region)      $missing[] = 'region (Virtualizor serid/node)';
if (!$name)        $missing[] = 'name';
if ($missing) {
    echo json_encode(['ok' => false, 'error' => 'Missing fields: ' . implode(', ', $missing)]); exit;
}

// Admin-only: create for other user
if ($target_uid !== (int)$me['id'] && $me['role'] !== 'admin') {
    echo json_encode(['ok' => false, 'error' => 'Only admin can deploy for another user.']); exit;
}

// Load target user
$u_st = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
$u_st->execute([$target_uid]);
$target_user = $u_st->fetch();
if (!$target_user) {
    echo json_encode(['ok' => false, 'error' => "User #$target_uid not found."]); exit;
}

// Load provider
$p_st = db()->prepare('SELECT * FROM providers WHERE id=? AND is_active=1 LIMIT 1');
$p_st->execute([$provider_id]);
$prov = $p_st->fetch();
if (!$prov) {
    echo json_encode(['ok' => false, 'error' => 'Active provider not found.']); exit;
}

$prov_type = strtolower($prov['provider_type'] ?? 'virtualizor');

// ── Vultr server creation path ────────────────────────────────
if ($prov_type === 'vultr') {
    try {
        require_once __DIR__ . '/../providers/vultr/bootstrap.php';
        CloudProvider::reset();
        $cloud = new CloudProvider($prov['api_key']);

        $root_pass = bin2hex(random_bytes(8)) . 'Vu!';

        $created = $cloud->servers->create([
            'name'        => $name,
            'plan'        => $plan,
            'image'       => $image,
            'region'      => $region,
            'ssh_key_ids' => $ssh_keys,
        ]);

        $instance_id = $created['id'] ?? null;
        if (!$instance_id) throw new RuntimeException('Vultr create returned no instance ID.');

        // Wait briefly then re-fetch for IP
        sleep(3);
        try {
            $created = $cloud->servers->get($instance_id);
        } catch (Throwable $e) {}

        // Specs from created obj or plan table
        $vcpu    = (int)($created['vcpu']    ?? 0);
        $ram_gb  = (float)($created['ram_gb'] ?? 0);
        $disk_gb = (int)($created['disk_gb'] ?? 0);
        $ipv4    = $created['ipv4']          ?? null;
        $status  = $created['status']        ?? 'provisioning';
        $os_lbl  = $created['os_label']      ?? $image;

        $plan_row = db()->prepare('SELECT * FROM plan_pricing WHERE slug=? AND provider_id=? LIMIT 1');
        $plan_row->execute([$plan, $provider_id]);
        $plan_data = $plan_row->fetch() ?: [];
        if (!$vcpu)    $vcpu    = (int)($plan_data['vcpu']    ?? 1);
        if (!$ram_gb)  $ram_gb  = (float)($plan_data['ram_gb']  ?? 1);
        if (!$disk_gb) $disk_gb = (int)($plan_data['disk_gb'] ?? 25);

        $currency = strtoupper($target_user['currency'] ?? 'INR');
        $price_row = db()->prepare('SELECT price_inr, price_usd FROM plan_region_prices WHERE plan_slug=? AND provider_id=? LIMIT 1');
        $price_row->execute([$plan, $provider_id]);
        $pr = $price_row->fetch() ?: [];
        $price_hr = $pr ? ($currency === 'INR' ? (float)$pr['price_inr'] : (float)$pr['price_usd']) : 0.0;

        // Get region label
        $rg_row = db()->prepare('SELECT label, country_flag FROM region_catalog WHERE slug=? AND provider_id=? LIMIT 1');
        $rg_row->execute([$region, $provider_id]);
        $rg = $rg_row->fetch() ?: [];

        $server_id = db_create_server($target_uid, [
            'provider_id'        => $instance_id,
            'source_provider_id' => $provider_id,
            'name'               => $name,
            'status'             => $status,
            'plan_slug'          => $plan,
            'image_slug'         => strtolower(explode(' ', $os_lbl)[0] ?: 'linux'),
            'region_slug'        => $region,
            'region_label'       => $rg['label'] ?? $region,
            'region_flag'        => $rg['country_flag'] ?? 'us',
            'vcpu'               => $vcpu,
            'ram_gb'             => $ram_gb,
            'disk_gb'            => $disk_gb,
            'ipv4'               => $ipv4,
            'ipv6'               => null,
            'os_label'           => $os_lbl,
            'price_hourly'       => $price_hr,
            'price_monthly'      => round($price_hr * 730, 2),
            'currency'           => $currency,
            'root_password'      => $root_pass,
            'total_bandwidth_gb' => (int)($created['bandwidth_gb'] ?? 0),
            'used_bandwidth_gb'  => 0.0,
        ]);

        log_server_action($server_id, $target_uid, 'create', 'success');

        echo json_encode([
            'ok'           => true,
            'server_id'    => $server_id,
            'vpsid'        => $instance_id,
            'ipv4'         => $ipv4,
            'root_password'=> $root_pass,
            'status'       => $status,
            'message'      => "VPS created on Vultr (ID: $instance_id) and added to account.",
        ]);

    } catch (Throwable $e) {
        error_log('[vultr-create] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Vultr error: ' . $e->getMessage()]);
    }
    exit;
}

// ── Virtualizor server creation path (original) ───────────────
if ($prov_type !== 'virtualizor') {
    echo json_encode(['ok' => false, 'error' => "Server creation via panel not yet supported for provider type: {$prov_type}"]); exit;
}

try {
    require_once __DIR__ . '/../providers/virtualizor/client.php';
    require_once __DIR__ . '/../providers/virtualizor/servers.php';

    $client  = new VirtualizorClient($prov['api_key']);
    $servers = new VirtualizorServers($client);

    // Generate root password
    $root_pass = bin2hex(random_bytes(8)) . 'V!1';

    // Build addvs payload
    // Virtualizor addvs required fields:
    //   hostname, rootpass, osid, plid, serid, user_email
    $payload = [
        'hostname'   => $name,
        'rootpass'   => $root_pass,
        'osid'       => $image,          // OS template ID from Virtualizor
        'plid'       => $plan,           // Plan ID from Virtualizor
        'serid'      => $region,         // Node/server ID from Virtualizor
        'user_email' => $target_user['email'],
        'user_fname' => $target_user['full_name'] ?: $target_user['username'],
        'user_lname' => '',
    ];

    // SSH keys as newline-separated public keys
    if (!empty($ssh_keys)) {
        $payload['sshkeys'] = implode("\n", $ssh_keys);
    }

    // POST to Virtualizor addvs
    $raw = $client->post('addvs', [], $payload);

    if (($raw['done'] ?? 0) != 1) {
        $err = VirtualizorClient::errMsg($raw, 'Virtualizor returned done=0.');
        throw new RuntimeException("addvs failed: $err | Full response: " . json_encode($raw));
    }

    // Extract vpsid from response
    // Virtualizor puts it in different keys depending on version:
    $vpsid = $raw['vpsid']
           ?? $raw['vps_info']['vpsid']
           ?? $raw['vps']['vpsid']
           ?? null;

    if (!$vpsid) {
        throw new RuntimeException('VPS created on Virtualizor but no vpsid in response: ' . json_encode($raw));
    }

    // Fetch VPS details to get IP, specs etc.
    // Give Virtualizor 3 seconds to provision before fetching
    sleep(3);
    $vps = null;
    try {
        $detail = $client->get('listvs', ['vpsid' => $vpsid]);
        $vps    = $detail['vpslist'][$vpsid]
               ?? array_values($detail['vpslist'] ?? [])[0]
               ?? null;
    } catch (Throwable $e) {
        error_log('[virt-create] fetch after create failed: ' . $e->getMessage());
    }

    // Determine specs — from live fetch or from plan table
    $vcpu    = (int)($vps['cores']  ?? $vps['vcpu']   ?? 0);
    $ram_mb  = (int)($vps['ram']    ?? 0);
    $ram_gb  = $ram_mb > 0 ? round($ram_mb / 1024, 1) : 0;
    $disk_gb = (int)($vps['hdd']    ?? $vps['disk_gb'] ?? 0);
    $ipv4    = $vps['ips'][0]       ?? $vps['ipv4']    ?? null;
    $os_lbl  = $vps['os_name']      ?? $vps['os']      ?? $image;
    $status  = empty($vps) ? 'provisioning' : ($vps['status'] == 1 ? 'running' : 'provisioning');

    // If plan in DB, get specs from there as fallback
    $plan_row = db()->prepare('SELECT * FROM plan_pricing WHERE (slug=? OR plid=?) AND provider_id=? LIMIT 1');
    $plan_row->execute([$plan, $plan, $provider_id]);
    $plan_data = $plan_row->fetch() ?: [];

    if (!$vcpu)    $vcpu    = (int)($plan_data['vcpu']    ?? 1);
    if (!$ram_gb)  $ram_gb  = (float)($plan_data['ram_gb']  ?? 1);
    if (!$disk_gb) $disk_gb = (int)($plan_data['disk_gb'] ?? 25);

    $currency    = strtoupper($target_user['currency'] ?? 'INR');
    $price_hr    = 0.0;
    // Try to get price from plan_region_prices
    $price_row   = db()->prepare('SELECT price_inr, price_usd FROM plan_region_prices WHERE plan_slug=? AND provider_id=? LIMIT 1');
    $price_row->execute([$plan, $provider_id]);
    $pr          = $price_row->fetch() ?: [];
    if ($pr) {
        $price_hr = $currency === 'INR' ? (float)$pr['price_inr'] : (float)$pr['price_usd'];
    }

    // Save to servers table
    $server_id = db_create_server($target_uid, [
        'provider_id'        => (int)$vpsid,    // Virtualizor VPS ID (used for API calls like start/stop)
        'source_provider_id' => $provider_id,   // providers table row (for credentials)
        'name'               => $name,
        'status'             => $status,
        'plan_slug'          => $plan,
        'image_slug'         => strtolower(explode(' ', $os_lbl)[0] ?: 'linux'),
        'region_slug'        => $region,
        'region_label'       => $region,
        'region_flag'        => 'in',
        'vcpu'               => $vcpu,
        'ram_gb'             => $ram_gb,
        'disk_gb'            => $disk_gb,
        'ipv4'               => $ipv4,
        'ipv6'               => null,
        'os_label'           => $os_lbl,
        'price_hourly'       => $price_hr,
        'price_monthly'      => round($price_hr * 730, 2),
        'currency'           => $currency,
        'root_password'      => $root_pass,
        // Bandwidth from plan or live fetch (0 if not available yet — cron will update)
        'total_bandwidth_gb' => (int)($vps['bandwidth'] ?? 0),
        'used_bandwidth_gb'  => (float)($vps['used_bandwidth'] ?? 0),
            ? base64_encode(openssl_encrypt(
                $root_pass, 'AES-128-ECB',
                substr(hash('sha256', $prov['api_key']), 0, 16)
            ))
            : null,
    ]);

    log_server_action($server_id, $target_uid, 'create', 'success');

    echo json_encode([
        'ok'           => true,
        'server_id'    => $server_id,
        'vpsid'        => $vpsid,
        'ipv4'         => $ipv4,
        'root_password'=> $root_pass,
        'status'       => $status,
        'message'      => "VPS created on Virtualizor (ID: $vpsid) and added to account.",
    ]);

} catch (Throwable $e) {
    error_log('[virt-create] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}