<?php
/**
 * api/v1/server-actions.php
 *
 * POST /api/v1/server-actions?id=N
 * Body: {"action": "start|stop|reboot|rebuild|reset_root_password"}
 *
 * Also handles:
 * GET  /api/v1/server-actions?id=N   — list action history for a server
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/servers.php';
require_once __DIR__ . '/../../includes/admin.php';
require_once __DIR__ . '/_auth.php';

$auth = api_auth();
$uid  = (int)$auth['user_id'];
$id   = (int)($_GET['id'] ?? 0);
if (!$id) api_error('Server id required as query param ?id=N');

$server = get_server($id, $uid);
if (!$server) api_error('Server not found.', 404);

// ── GET: action history ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit = min((int)($_GET['limit'] ?? 20), 100);
    $st = db()->prepare(
        'SELECT id, action, status, note, created_at, finished_at
         FROM server_actions WHERE server_id=? AND user_id=? ORDER BY created_at DESC LIMIT ?'
    );
    $st->execute([$id, $uid, $limit]);
    api_ok(['actions' => $st->fetchAll() ?: []]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('Method not allowed.', 405);

// ── POST: perform action ─────────────────────────────────────
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim($body['action'] ?? '');

$allowed = ['start','stop','reboot','shutdown','rebuild','reset_root_password','enable_rescue'];
if (!$action) api_error('action field required. Allowed: ' . implode(', ', $allowed));
if (!in_array($action, $allowed)) api_error('Unknown action. Allowed: ' . implode(', ', $allowed));

// Get provider for this server
$spid = (int)($server['source_provider_id'] ?? 0);
$prov = null;
if ($spid) {
    $prov = get_provider($spid);
}
if (!$prov) {
    $ps = db()->prepare('SELECT p.* FROM providers p JOIN plan_pricing pp ON pp.provider_id=p.id WHERE pp.slug=? AND p.is_active=1 LIMIT 1');
    $ps->execute([$server['plan_slug'] ?? '']);
    $prov = $ps->fetch() ?: null;
}
if (!$prov || !$prov['api_key']) api_error('Provider not configured.', 503);

$prov_type   = strtolower($prov['provider_type'] ?? 'virtualizor');
$handler_file = __DIR__ . '/../../servers/actions/' . $prov_type . '.php';
if (!file_exists($handler_file)) api_error("No action handler for provider '{$prov_type}'", 503);

require_once $handler_file;
require_once __DIR__ . '/../../providers/' . $prov_type . '/bootstrap.php';
CloudProvider::reset();
$cloud   = new CloudProvider($prov['api_key']);
$handler = new HetznerActions($cloud, $server);

try {
    $result = match($action) {
        'rebuild'             => $handler->rebuild($body),
        'reset_root_password' => $handler->reset_root_password(),
        'enable_rescue'       => $handler->enable_rescue(),
        default               => $handler->$action(),
    };
} catch (Throwable $e) {
    log_server_action($id, $uid, $action, 'error');
    api_error('Action failed: ' . $e->getMessage(), 502);
}

if (!($result['ok'] ?? false)) {
    log_server_action($id, $uid, $action, 'error');
    api_error($result['error'] ?? 'Action failed.', 502);
}

// Update status in DB
$status_map = ['start'=>'starting','stop'=>'stopping','shutdown'=>'stopping','reboot'=>'provisioning','rebuild'=>'rebuilding'];
if (isset($status_map[$action])) {
    db()->prepare('UPDATE servers SET status=? WHERE id=?')->execute([$status_map[$action], $id]);
}

// Handle new password
if (!empty($result['root_password'])) {
    $key = substr(hash('sha256', $prov['api_key']), 0, 16);
    $enc = base64_encode(openssl_encrypt($result['root_password'], 'AES-128-ECB', $key));
    db()->prepare('UPDATE servers SET root_password=? WHERE id=?')->execute([$enc, $id]);
}

log_server_action($id, $uid, $action, 'success');

api_ok([
    'message' => $result['message'] ?? 'Action queued.',
    'action'  => $action,
    'server_id' => $id,
]);
