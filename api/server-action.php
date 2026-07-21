<?php
/**
 * api/server-action.php
 *
 * Provider-agnostic action router.
 * Loads the correct provider action handler based on server's provider_type.
 *
 * Currently supported: hetzner
 * Future: digitalocean, vultr, linode etc. — just add servers/actions/{type}.php
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/servers.php';
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/mailer_invoice.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok'=>false,'error'=>'POST required.']); exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$sid    = (int)($body['id']     ?? 0);
$action = trim($body['action']  ?? '');
$csrf   = $body['csrf']         ?? $body['csrf_token'] ?? '';

if (!verify_csrf($csrf)) { echo json_encode(['ok'=>false,'error'=>'Invalid token.']); exit; }

$user   = current_user();
$server = get_server($sid, (int)$user['id']);
if (!$server) { echo json_encode(['ok'=>false,'error'=>'Server not found.']); exit; }
if (!$action) { echo json_encode(['ok'=>false,'error'=>'Action required.']); exit; }

// Get provider — server ke source_provider_id se
$prov = null;
$spid = (int)($server['source_provider_id'] ?? 0);
if ($spid) {
    $prov = get_provider($spid);
}
// Fallback: plan_slug se provider dhundo
if (!$prov) {
    $ps = db()->prepare('SELECT p.* FROM providers p JOIN plan_pricing pp ON pp.provider_id=p.id WHERE pp.slug=? AND p.is_active=1 LIMIT 1');
    $ps->execute([$server['plan_slug'] ?? '']);
    $prov = $ps->fetch() ?: null;
}
if (!$prov || !$prov['api_key']) {
    echo json_encode(['ok'=>false,'error'=>'Provider not configured.']); exit;
}

$provider_type = strtolower($prov['provider_type'] ?? 'hetzner');

// Load provider-specific action handler
$handler_file = __DIR__ . '/../servers/actions/' . $provider_type . '.php';
if (!file_exists($handler_file)) {
    echo json_encode(['ok'=>false,'error'=>"No action handler for provider '{$provider_type}'"]); exit;
}
require_once $handler_file;

// Connect to provider
require_once __DIR__ . '/../providers/' . $provider_type . '/bootstrap.php';
CloudProvider::reset();
$cloud = new CloudProvider($prov['api_key']);

// Instantiate handler
$handler_class = ucfirst($provider_type) . 'Actions';

if (!class_exists($handler_class)) {
    $alt = ucfirst($provider_type) . 'ProviderActions';
    if (class_exists($alt)) {
        $handler_class = $alt;
    }
}
if (!class_exists($handler_class)) {
    echo json_encode(['ok'=>false,'error'=>"Handler class {$handler_class} not found."]); exit;
}
$handler = new $handler_class($cloud, $server);

// ── Suspended server guard ───────────────────────────────────
// Only 'delete' is allowed on a suspended server.
// All other actions require: (1) server not suspended, OR (2) sufficient balance.
$ALLOWED_WHILE_SUSPENDED = ['delete', 'list_snapshots', 'list_volumes', 'list_firewalls', 'list_floating_ips', 'list_networks', 'list_all_networks'];

if ($server['status'] === 'suspended' && !in_array($action, $ALLOWED_WHILE_SUSPENDED)) {
    echo json_encode([
        'ok'    => false,
        'error' => 'Server is suspended due to insufficient balance. Please top up your wallet first.',
        'code'  => 'SERVER_SUSPENDED',
    ]);
    exit;
}

// ── Minimum balance check (5h) for power-on/start actions ────
// Blocked actions: start, reboot, reset, rebuild, enable_rescue, enable_rescue_cycle
$ACTIONS_NEED_BALANCE = ['start', 'reboot', 'reset', 'enable_rescue', 'enable_rescue_cycle'];

if (in_array($action, $ACTIONS_NEED_BALANCE)) {
    $price_hourly = (float)($server['price_hourly'] ?? 0);
    $min_balance  = $price_hourly * 5;
    $user_balance = (float)$user['wallet_balance'];

    if ($min_balance > 0 && $user_balance < $min_balance) {
        $sym = strtoupper($user['currency'] ?? 'INR') === 'USD' ? '$' : '₹';
        echo json_encode([
            'ok'    => false,
            'error' => 'Insufficient balance. Minimum ' . $sym . number_format($min_balance, 2) . ' (5 hours) required to start this server.',
            'code'  => 'INSUFFICIENT_BALANCE',
        ]);
        exit;
    }
}

// Execute action
try {
    $result = match($action) {
        'start'                => $handler->start(),
        'stop'                 => $handler->stop(),
        'shutdown'             => $handler->shutdown(),
        'reboot'               => $handler->reboot(),
        'reset'                => $handler->reset(),
        'enable_rescue'        => $handler->enable_rescue($body),
        'enable_rescue_cycle'  => $handler->enable_rescue_cycle($body),
        'reset_root_password'  => $handler->reset_root_password(),
        'rebuild'              => $handler->rebuild($body),
        'create_snapshot'      => $handler->create_snapshot($body),
        'list_snapshots'       => $handler->list_snapshots(),
        'delete_snapshot'      => $handler->delete_snapshot($body),
        'list_volumes'         => $handler->list_volumes(),
        'create_volume'        => $handler->create_volume($body),
        'attach_volume'        => $handler->attach_volume($body),
        'detach_volume'        => $handler->detach_volume($body),
        'resize_volume'        => $handler->resize_volume($body),
        'delete_volume'        => $handler->delete_volume($body),
        'list_firewalls'       => $handler->list_server_firewalls(),
        'apply_firewall'       => $handler->apply_firewall($body),
        'remove_firewall'      => $handler->remove_firewall($body),
        // Floating IPs
        'list_floating_ips'    => $handler->list_floating_ips(),
        'create_floating_ip'   => $handler->create_floating_ip($body),
        'assign_floating_ip'   => $handler->assign_floating_ip($body),
        'unassign_floating_ip' => $handler->unassign_floating_ip($body),
        'delete_floating_ip'   => $handler->delete_floating_ip($body),
        // Networks
        'list_networks'        => $handler->list_networks(),
        'list_all_networks'    => $handler->list_all_networks(),
        'create_network'       => $handler->create_network($body),
        'attach_network'       => $handler->attach_network($body),
        'detach_network'       => $handler->detach_network($body),
        // Console
        'get_console'          => $handler->get_console(),
        // Delete
        'delete'               => $handler->delete_server(),
        default                => ['ok'=>false,'error'=>"Unknown action: {$action}"],
    };

    // Post-action DB updates
    if ($result['ok'] ?? false) {
        $status_map = [
            'start'    => 'starting',
            'stop'     => 'stopping',
            'shutdown' => 'stopping',
            'reboot'   => 'starting',   // ENUM: no 'rebooting' — use 'starting'
            'reset'    => 'starting',
            'rebuild'  => 'rebuilding',
        ];
        if (isset($status_map[$action])) {
            db()->prepare('UPDATE servers SET status=? WHERE id=?')
               ->execute([$status_map[$action], $server['id']]);
        }
        if ($action === 'delete') {
            db()->prepare("UPDATE servers SET status='deleted',deleted_at=NOW() WHERE id=?")
               ->execute([$server['id']]);
        }
        if (isset($result['root_password']) && $result['root_password']) {
            // Encrypt and save new password
            $key = substr(hash('sha256', $prov['api_key']), 0, 16);
            $enc = base64_encode(openssl_encrypt($result['root_password'], 'AES-128-ECB', $key));
            db()->prepare('UPDATE servers SET root_password=? WHERE id=?')
               ->execute([$enc, $server['id']]);
            $result['password_updated'] = true;
        }

        // Rebuild: update OS image info in DB
        if ($action === 'rebuild') {
            $set_parts = [];
            $set_vals  = [];
            if (!empty($result['image_slug'])) {
                $set_parts[] = 'image_slug=?';
                $set_vals[]  = $result['image_slug'];
            }
            if (!empty($result['os_label'])) {
                $set_parts[] = 'os_label=?';
                $set_vals[]  = $result['os_label'];
            }
            if (!empty($set_parts)) {
                $set_vals[] = $server['id'];
                db()->prepare('UPDATE servers SET ' . implode(',', $set_parts) . ' WHERE id=?')
                   ->execute($set_vals);
            }

            // Send rebuild email with new credentials
            try {
                $fresh_server = get_server((int)$server['id'], (int)$user['id']) ?: $server;
                $plain_pass   = $result['root_password'] ?? null;
                send_rebuild_email($user, $fresh_server, $plain_pass);
            } catch (Throwable $mail_e) {
                error_log('[server-action] rebuild email error (non-fatal): ' . $mail_e->getMessage());
            }
        }

        // Log action
        log_server_action((int)$server['id'], (int)$user['id'], $action, 'success');
    }

    echo json_encode($result);

} catch (Throwable $e) {
    error_log('[server-action] ' . $e->getMessage());
    log_server_action((int)$server['id'], (int)$user['id'], $action, 'error');
    echo json_encode(['ok'=>false,'error'=>'Action failed: ' . $e->getMessage()]);
}