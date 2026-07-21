<?php
/**
 * api/virt-import.php
 *
 * Import a Virtualizor VPS by ID directly into the logged-in user's account.
 * Called from admin panel (admin importing for a user) OR user panel (self-import via claim code).
 *
 * POST JSON:
 *   { vps_id: 123, provider_id: 1, user_id: 5, csrf_token: "..." }
 *
 * user_id is optional — if omitted, imports to current logged-in user.
 * Only admin can specify a different user_id.
 *
 * Returns:
 *   { ok: true, server_id: 42, server: {...} }
 *   { ok: false, error: "..." }
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/servers.php';
require_once __DIR__ . '/../includes/admin.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required.']); exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$csrf   = $body['csrf_token'] ?? $body['csrf'] ?? '';

if (!verify_csrf($csrf)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']); exit;
}

$me = current_user();

$vps_id      = (int)($body['vps_id']      ?? 0);
$provider_id = (int)($body['provider_id'] ?? 0);
$target_uid  = (int)($body['user_id']     ?? 0);  // admin only

if (!$vps_id || !$provider_id) {
    echo json_encode(['ok' => false, 'error' => 'vps_id and provider_id are required.']); exit;
}

// Determine target user
if ($target_uid && $target_uid !== (int)$me['id']) {
    if ($me['role'] !== 'admin') {
        echo json_encode(['ok' => false, 'error' => 'Only admin can import to another user.']); exit;
    }
    $user_st = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
    $user_st->execute([$target_uid]);
    $target_user = $user_st->fetch();
    if (!$target_user) {
        echo json_encode(['ok' => false, 'error' => "User ID $target_uid not found."]); exit;
    }
} else {
    $target_user = $me;
    $target_uid  = (int)$me['id'];
}

// Check if this VPS is already imported for this user
$exists = db()->prepare('SELECT id FROM servers WHERE provider_id=? AND user_id=? AND deleted_at IS NULL LIMIT 1');
$exists->execute([$vps_id, $target_uid]);
if ($exists->fetch()) {
    echo json_encode(['ok' => false, 'error' => "VPS #$vps_id is already in this user's account."]); exit;
}

// Load provider
$prov_st = db()->prepare('SELECT * FROM providers WHERE id=? AND is_active=1 LIMIT 1');
$prov_st->execute([$provider_id]);
$prov = $prov_st->fetch();
if (!$prov || !$prov['api_key']) {
    echo json_encode(['ok' => false, 'error' => 'Provider not found or not configured.']); exit;
}
if (strtolower($prov['provider_type']) !== 'virtualizor') {
    echo json_encode(['ok' => false, 'error' => 'Provider is not Virtualizor type.']); exit;
}

try {
    require_once __DIR__ . '/../providers/virtualizor/client.php';
    require_once __DIR__ . '/../providers/virtualizor/servers.php';

    $client  = new VirtualizorClient($prov);
    $servers = new VirtualizorServers($client);

    // Fetch VPS details from Virtualizor
    // Try vpsdetails first, fallback to listvs with vpsid filter
    $vps = null;

    try {
        $r = $client->get('vpsdetails', ['vpsid' => $vps_id]);

        // Virtualizor vpsdetails response structure varies by version:
        //   $r['vpsdetails'][$vps_id] — most common
        //   $r['vpsdetails']          — sometimes direct array
        //   $r['vps']                 — older versions
        if (!empty($r['vpsdetails']) && is_array($r['vpsdetails'])) {
            $vps = $r['vpsdetails'][$vps_id]
                ?? $r['vpsdetails'][array_key_first($r['vpsdetails'])]
                ?? null;
        }
        if (!$vps && !empty($r['vps'])) {
            $vps = $r['vps'];
        }
    } catch (Throwable $e) {
        error_log('[virt-import] vpsdetails failed: ' . $e->getMessage());
    }

    // Fallback: listvs filtered by vpsid
    if (!$vps) {
        try {
            $r2 = $client->get('listvs', ['vpsid' => $vps_id]);
            if (!empty($r2['vpslist'])) {
                $vps = $r2['vpslist'][$vps_id]
                    ?? array_values($r2['vpslist'])[0]
                    ?? null;
            }
        } catch (Throwable $e2) {
            error_log('[virt-import] listvs fallback failed: ' . $e2->getMessage());
        }
    }

    if (!$vps) {
        echo json_encode(['ok' => false, 'error' => "VPS ID $vps_id not found on Virtualizor panel. Check the ID and provider credentials."]); exit;
    }

    // Map to neutral format
    $mapped = $servers->mapServer($vps);

    // Insert into servers table
    $currency    = strtoupper($target_user['currency'] ?? 'INR');
    $server_id   = db_create_server($target_uid, [
        'provider_id'        => $vps_id,          // Virtualizor VPS ID (for API calls)
        'source_provider_id' => $provider_id,     // providers table row (for credentials)
        'name'               => $mapped['name']   ?: "vps-$vps_id",
        'status'             => $mapped['status'] ?: 'running',
        'plan_slug'          => $mapped['plan_slug']  ?: 'imported',
        'image_slug'         => $mapped['os_name']    ?: 'linux',
        'region_slug'        => $mapped['region_slug'] ?: '',
        'region_label'       => $mapped['region_label'] ?: '',
        'region_flag'        => 'in',
        'vcpu'               => $mapped['vcpu']    ?: 1,
        'ram_gb'             => $mapped['ram_gb']  ?: 0,
        'disk_gb'            => $mapped['disk_gb'] ?: 0,
        'ipv4'               => $mapped['ipv4']   ?? null,
        'ipv6'               => $mapped['ipv6']   ?? null,
        'os_label'           => $mapped['os_label'] ?: 'Linux',
        'price_hourly'       => 0,
        'price_monthly'      => 0,
        'currency'           => $currency,
        'root_password'      => null,
    ]);

    log_server_action($server_id, $target_uid, 'import', 'success');

    $server = get_server($server_id, $target_uid);

    echo json_encode([
        'ok'        => true,
        'server_id' => $server_id,
        'server'    => [
            'id'           => $server_id,
            'name'         => $mapped['name'],
            'status'       => $mapped['status'],
            'ipv4'         => $mapped['ipv4'],
            'vcpu'         => $mapped['vcpu'],
            'ram_gb'       => $mapped['ram_gb'],
            'disk_gb'      => $mapped['disk_gb'],
            'os_label'     => $mapped['os_label'],
            'region_label' => $mapped['region_label'],
        ],
        'message'   => "VPS #{$vps_id} imported successfully into " . htmlspecialchars($target_user['username']) . "'s account.",
    ]);

} catch (Throwable $e) {
    error_log('[virt-import] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}