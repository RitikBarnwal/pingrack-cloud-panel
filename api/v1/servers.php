<?php
/**
 * api/v1/servers.php
 *
 * GET  /api/v1/servers          — list all servers
 * GET  /api/v1/servers?id=N     — single server
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/servers.php';
require_once __DIR__ . '/_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') api_error('Method not allowed.', 405);

$auth = api_auth();
$uid  = (int)$auth['user_id'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

// ── Single server ────────────────────────────────────────────
if ($id) {
    $srv = get_server($id, $uid);
    if (!$srv) api_error('Server not found.', 404);
    api_ok(['server' => format_server($srv)]);
}

// ── List servers ─────────────────────────────────────────────
$status = $_GET['status'] ?? null;
$limit  = min((int)($_GET['limit'] ?? 20), 100);
$offset = (int)($_GET['offset'] ?? 0);

$where  = ['user_id = ?', 'deleted_at IS NULL'];
$params = [$uid];
if ($status) { $where[] = 'status = ?'; $params[] = $status; }

$wsql = 'WHERE ' . implode(' AND ', $where);

$cnt_st = db()->prepare("SELECT COUNT(*) FROM servers $wsql");
$cnt_st->execute($params);
$total = (int)$cnt_st->fetchColumn();

$params[] = $limit;
$params[] = $offset;
$st = db()->prepare("SELECT * FROM servers $wsql ORDER BY created_at DESC LIMIT ? OFFSET ?");
$st->execute($params);
$rows = $st->fetchAll() ?: [];

api_ok([
    'servers' => array_map('format_server', $rows),
    'meta'    => ['total' => $total, 'offset' => $offset],
]);

// ── Format helper ────────────────────────────────────────────
function format_server(array $s): array {
    $currency = strtoupper($s['currency'] ?? 'INR');
    return [
        'id'           => (int)$s['id'],
        'name'         => $s['name'],
        'status'       => $s['status'],
        'plan'         => $s['plan_slug'],
        'os'           => $s['os_label'] ?? null,
        'region'       => $s['region_label'] ?? $s['region_slug'],
        'region_slug'  => $s['region_slug'],
        'vcpu'         => (int)$s['vcpu'],
        'ram_gb'       => (float)$s['ram_gb'],
        'disk_gb'      => (int)$s['disk_gb'],
        'ipv4'         => $s['ipv4'] ?? null,
        'ipv6'         => $s['ipv6'] ?? null,
        'price_hourly' => (float)$s['price_hourly'],
        'price_monthly'=> (float)$s['price_monthly'],
        'currency'     => $currency,
        'created_at'   => $s['created_at'],
        'deleted_at'   => $s['deleted_at'] ?? null,
    ];
}
