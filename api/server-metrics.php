<?php
/**
 * api/server-metrics.php
 * VPS Metrics/Stats API — Har provider ke liye real-time graphs data
 *
 * GET  ?id=<server_id>&range=<1h|24h|7d|30d>&csrf=<token>
 *
 * Returns JSON:
 * {
 *   ok: true,
 *   provider: "hetzner",
 *   range: "24h",
 *   cpu:       { labels:[], data:[] },       // % 0-100
 *   network_in:  { labels:[], data:[] },     // Mbps
 *   network_out: { labels:[], data:[] },     // Mbps
 *   disk_read:   { labels:[], data:[] },     // MB/s (if available)
 *   disk_write:  { labels:[], data:[] },     // MB/s (if available)
 *   bandwidth_used_gb: 0,
 *   bandwidth_total_gb: 0,
 *   note: "..."   // if provider doesn't support live metrics
 * }
 */
declare(strict_types=1);
define('ROOT', dirname(__DIR__));
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

// ── Auth ─────────────────────────────────────────────────────────
require_login();

$user = current_user();

$uid = (int)$user['id'];

// ── Input ────────────────────────────────────────────────────────
$server_id = (int)($_GET['id']    ?? 0);
$range     = in_array($_GET['range'] ?? '24h', ['1h','24h','7d','30d']) ? ($_GET['range'] ?? '24h') : '24h';

if (!$server_id) { echo json_encode(['ok'=>false,'error'=>'Invalid server ID']); exit; }

// ── Load server (ownership check) ────────────────────────────────
$st = db()->prepare(
    "SELECT *
     FROM servers
     WHERE id = ?
     LIMIT 1"
);

$st->execute([$server_id]);

$server = $st->fetch(PDO::FETCH_ASSOC);
if (!$server) { echo json_encode(['ok'=>false,'error'=>'Server not found']); exit; }

// Admin can see any server; user only their own
$is_admin = (($_SESSION['role'] ?? '') === 'admin');

if (!$is_admin && (int)$server['user_id'] !== $uid) {
    echo json_encode([
        'ok'    => false,
        'error' => 'Access denied'
    ]);

    exit;
}

// ── Load provider credentials ─────────────────────────────────────
$prov_type  = $server['provider_type'] ?? 'virtualizor';
$prov_id = (int)($server['source_provider_id'] ?? 0);
$st = db()->prepare(
    "SELECT *
     FROM providers
     WHERE id = ?
     LIMIT 1"
);

$st->execute([$prov_id]);

$prov_creds = $st->fetch(PDO::FETCH_ASSOC);
if (!$prov_creds) { echo json_encode(['ok'=>false,'error'=>'Provider credentials not found']); exit; }

$remote_id  = $server['provider_id'] ?? '';

// ── Range → timestamps ────────────────────────────────────────────
$now   = time();
$delta = match($range) {
    '1h'  => 3600,
    '7d'  => 86400 * 7,
    '30d' => 86400 * 30,
    default => 86400, // 24h
};
$start = $now - $delta;

// ── Fetch metrics per provider ────────────────────────────────────
$result = ['ok'=>true, 'provider'=>APP_NAME, 'range'=>$range,
           'cpu'=>[], 'network_in'=>[], 'network_out'=>[],
           'disk_read'=>[], 'disk_write'=>[],
           'bandwidth_used_gb'=>(float)($server['used_bandwidth_gb']  ?? 0),
           'bandwidth_total_gb'=>(float)($server['total_bandwidth_gb'] ?? 0),
           'note'=>''];

switch ($prov_type) {

    // ══════════════════════════════════════════════════════════════
    // HETZNER — GET /servers/{id}/metrics?type=cpu,network,disk
    // ══════════════════════════════════════════════════════════════
    case 'hetzner':
        require_once ROOT . '/providers/hetzner/client.php';
        $client = new CloudProviderClient($prov_creds['api_key']);

        // Step = nice granularity
        $step = match($range) { '1h' => 60, '7d' => 1800, '30d' => 7200, default => 300 };

        $params = [
            'type'  => 'cpu,network,disk',
            'start' => date('c', $start),
            'end'   => date('c', $now),
            'step'  => $step,
        ];
        
        $result['alternate'] = [
    'remote_id' => base64_encode((string)$remote_id),
    'provider'  => APP_NAME . "(" . base64_encode((string)APP_NAME) . ")",
];

        $r = $client->get('/servers/' . $remote_id . '/metrics', $params);

        if (($r['_http_status'] ?? 0) !== 200) {
            $result['note'] = 'Hetzner metrics unavailable: HTTP ' . ($r['_http_status'] ?? 0);
            break;
        }

        $ts_series = $r['metrics']['time_series'] ?? [];

        // CPU %
        if (isset($ts_series['cpu']['values'])) {
            [$labels, $data] = _parse_hetzner_series($ts_series['cpu']['values']);
            $result['cpu'] = ['labels'=>$labels, 'data'=>$data];
        }

        // Network in/out — Bytes/s → Mbps
        if (isset($ts_series['network.0.bandwidth.in']['values'])) {
            [$labels, $data] = _parse_hetzner_series($ts_series['network.0.bandwidth.in']['values'], fn($v) => round($v * 8 / 1_000_000, 4));
            $result['network_in'] = ['labels'=>$labels, 'data'=>$data];
        }
        if (isset($ts_series['network.0.bandwidth.out']['values'])) {
            [$labels, $data] = _parse_hetzner_series($ts_series['network.0.bandwidth.out']['values'], fn($v) => round($v * 8 / 1_000_000, 4));
            $result['network_out'] = ['labels'=>$labels, 'data'=>$data];
        }

        // Disk read/write — IOPS or bytes
        if (isset($ts_series['disk.0.iops.read']['values'])) {
            [$labels, $data] = _parse_hetzner_series($ts_series['disk.0.iops.read']['values']);
            $result['disk_read'] = ['labels'=>$labels, 'data'=>$data];
        }
        if (isset($ts_series['disk.0.iops.write']['values'])) {
            [$labels, $data] = _parse_hetzner_series($ts_series['disk.0.iops.write']['values']);
            $result['disk_write'] = ['labels'=>$labels, 'data'=>$data];
        }
        break;

    // ══════════════════════════════════════════════════════════════
    // LINODE — GET /linode/instances/{id}/stats  (last 24h always)
    //          GET /linode/instances/{id}/stats/{year}/{month}
    // ══════════════════════════════════════════════════════════════
    case 'linode':
        require_once ROOT . '/providers/linode/client.php';
        $client = new LinodeClient($prov_creds['api_key']);

        if (in_array($range, ['7d','30d'])) {
            // Use monthly stats (returns full month, we'll slice later)
            $month_path = '/linode/instances/' . $remote_id . '/stats/' . date('Y') . '/' . date('m');
            $r = $client->get($month_path);
        } else {
            $r = $client->get('/linode/instances/' . $remote_id . '/stats');
        }

        if (($r['_http_status'] ?? 0) !== 200) {
            $result['note'] = 'Linode stats unavailable: HTTP ' . ($r['_http_status'] ?? 0);
            break;
        }

        $stats = $r['data'] ?? $r;

        // CPU — array of [timestamp_ms, value%]
        if (!empty($stats['cpu'])) {
            [$labels, $data] = _parse_linode_series($stats['cpu'], $range);
            $result['cpu'] = ['labels'=>$labels, 'data'=>$data];
        }

        // Network in/out — bits/s → Mbps
        if (!empty($stats['netv4']['in'])) {
            [$labels, $data] = _parse_linode_series($stats['netv4']['in'], $range, fn($v) => round($v / 1_000_000, 4));
            $result['network_in'] = ['labels'=>$labels, 'data'=>$data];
        }
        if (!empty($stats['netv4']['out'])) {
            [$labels, $data] = _parse_linode_series($stats['netv4']['out'], $range, fn($v) => round($v / 1_000_000, 4));
            $result['network_out'] = ['labels'=>$labels, 'data'=>$data];
        }

        // Disk IO
        if (!empty($stats['io']['io'])) {
            [$labels, $data] = _parse_linode_series($stats['io']['io'], $range);
            $result['disk_read'] = ['labels'=>$labels, 'data'=>$data];
        }
        if (!empty($stats['io']['swap'])) {
            [$labels, $data] = _parse_linode_series($stats['io']['swap'], $range);
            $result['disk_write'] = ['labels'=>$labels, 'data'=>$data];
        }
        break;

    // ══════════════════════════════════════════════════════════════
    // VULTR — GET /instances/{id}/bandwidth
    //         GET /instances/{id}/neighbors  (no CPU metrics in free tier)
    // ══════════════════════════════════════════════════════════════
    case 'vultr':
        require_once ROOT . '/providers/vultr/client.php';
        $client = new VultrClient($prov_creds['api_key']);

        try {
            $r = $client->get('/instances/' . $remote_id . '/bandwidth');
        } catch (Exception $e) {
            $result['note'] = 'Vultr bandwidth fetch failed: ' . $e->getMessage();
            break;
        }

        $bw = $r['bandwidth'] ?? [];

        if (empty($bw)) {
            $result['note'] = 'No bandwidth data available yet.';
            break;
        }

        // Vultr returns daily data keyed by date "2024-01-15"
        // Filter to requested range
        $cutoff = date('Y-m-d', $start);
        $net_in_labels = $net_out_labels = [];
        $net_in_data   = $net_out_data   = [];

        foreach ($bw as $date => $vals) {
            if ($date < $cutoff) continue;
            $net_in_labels[]  = $date;
            $net_in_data[]    = round(($vals['incoming_bytes'] ?? 0) / (1024**3), 3); // GB
            $net_out_labels[] = $date;
            $net_out_data[]   = round(($vals['outgoing_bytes'] ?? 0) / (1024**3), 3);
        }

        $result['network_in']  = ['labels'=>$net_in_labels,  'data'=>$net_in_data,  'unit'=>'GB/day'];
        $result['network_out'] = ['labels'=>$net_out_labels, 'data'=>$net_out_data, 'unit'=>'GB/day'];
        $result['note'] = 'Vultr provides daily bandwidth data. CPU metrics not available via API.';
        break;

    // ══════════════════════════════════════════════════════════════
    // DIGITAL OCEAN — Monitoring API
    // GET /v2/monitoring/metrics/droplet/cpu
    // GET /v2/monitoring/metrics/droplet/bandwidth
    // ══════════════════════════════════════════════════════════════
    case 'digitalocean':
        require_once ROOT . '/providers/digitalocean/client.php';
        $do_api_key = $prov_creds['api_key'];

        $do_start = (string)$start;
        $do_end   = (string)$now;

        // CPU
        $cpu_r = _do_monitoring_fetch($do_api_key, 'cpu', $remote_id, $do_start, $do_end);
        if ($cpu_r !== null) {
            [$labels, $data] = _parse_do_series($cpu_r, 'idle', true); // idle → 100-x = usage
            $result['cpu'] = ['labels'=>$labels, 'data'=>$data];
        }

        // Bandwidth inbound
        $bw_in_r = _do_monitoring_fetch($do_api_key, 'bandwidth', $remote_id, $do_start, $do_end, ['direction'=>'inbound','interface'=>'public']);
        if ($bw_in_r !== null) {
            [$labels, $data] = _parse_do_series($bw_in_r, null, false, fn($v) => round($v * 8 / 1_000_000, 4)); // bytes/s → Mbps
            $result['network_in'] = ['labels'=>$labels, 'data'=>$data];
        }

        // Bandwidth outbound
        $bw_out_r = _do_monitoring_fetch($do_api_key, 'bandwidth', $remote_id, $do_start, $do_end, ['direction'=>'outbound','interface'=>'public']);
        if ($bw_out_r !== null) {
            [$labels, $data] = _parse_do_series($bw_out_r, null, false, fn($v) => round($v * 8 / 1_000_000, 4));
            $result['network_out'] = ['labels'=>$labels, 'data'=>$data];
        }

        if (empty($result['cpu']['data']) && empty($result['network_in']['data'])) {
            $result['note'] = 'DigitalOcean monitoring requires "Monitoring" enabled on the droplet.';
        }
        break;

    // ══════════════════════════════════════════════════════════════
    // CONTABO / UTHO / VIRTUALIZOR / PROXMOX — No live metrics API
    // Show bandwidth bar only
    // ══════════════════════════════════════════════════════════════
    case 'contabo':
    case 'utho':
    case 'virtualizor':
    case 'proxmox':
        $result['note'] = ucfirst($prov_type) . ' does not provide a real-time metrics API. Bandwidth usage is shown below.';
        break;

    default:
        $result['note'] = 'Metrics not available for this provider.';
        break;
}

echo json_encode($result);

// ══════════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ══════════════════════════════════════════════════════════════════

/**
 * Parse Hetzner time_series values array [[timestamp, "value"], ...]
 */
function _parse_hetzner_series(array $values, ?callable $transform = null): array
{
    $labels = [];
    $data   = [];
    foreach ($values as $point) {
        $ts  = (int)($point[0] ?? 0);
        $val = is_numeric($point[1] ?? null) ? (float)$point[1] : null;
        if ($val === null) continue;
        $labels[] = date('H:i', $ts);
        $data[]   = $transform ? ($transform)($val) : round($val, 2);
    }
    return [$labels, $data];
}

/**
 * Parse Linode stats arrays [[timestamp_ms, value], ...]
 * Downsample for performance if range is large
 */
function _parse_linode_series(array $values, string $range, ?callable $transform = null): array
{
    $labels = [];
    $data   = [];

    // Downsample: max 120 points
    $count  = count($values);
    $step   = max(1, (int)ceil($count / 120));

    foreach ($values as $i => $point) {
        if ($i % $step !== 0) continue;
        $ts  = (int)(($point[0] ?? 0) / 1000); // ms → s
        $val = is_numeric($point[1] ?? null) ? (float)$point[1] : null;
        if ($val === null) continue;
        $fmt    = in_array($range, ['7d','30d']) ? 'M d' : 'H:i';
        $labels[] = date($fmt, $ts);
        $data[]   = $transform ? ($transform)($val) : round($val, 2);
    }
    return [$labels, $data];
}

/**
 * DigitalOcean monitoring API fetch
 */
function _do_monitoring_fetch(string $api_key, string $metric, string $droplet_id, string $start, string $end, array $extra = []): ?array
{
    $params = array_merge([
        'host_id' => $droplet_id,
        'start'   => $start,
        'end'     => $end,
    ], $extra);

    $url = 'https://api.digitalocean.com/v2/monitoring/metrics/droplet/' . $metric . '?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json',
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$raw) return null;
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

/**
 * Parse DigitalOcean monitoring API response
 * Result: { data: { result: [{ metric:{}, values:[[ts,val],...] }] } }
 */
function _parse_do_series(array $response, ?string $metric_filter, bool $invert_cpu = false, ?callable $transform = null): array
{
    $labels = [];
    $data   = [];

    $results = $response['data']['result'] ?? [];
    foreach ($results as $series) {
        // For CPU: find the 'idle' mode metric
        if ($metric_filter !== null) {
            $mode = $series['metric']['mode'] ?? '';
            if ($mode !== $metric_filter) continue;
        }
        $values = $series['values'] ?? [];
        $count  = count($values);
        $step   = max(1, (int)ceil($count / 120));
        foreach ($values as $i => $point) {
            if ($i % $step !== 0) continue;
            $ts  = (int)($point[0] ?? 0);
            $val = is_numeric($point[1] ?? null) ? (float)$point[1] : null;
            if ($val === null) continue;
            if ($invert_cpu) $val = round(100 - $val, 2); // idle → usage
            $labels[] = date('H:i', $ts);
            $data[]   = $transform ? ($transform)($val) : round($val, 2);
        }
        break; // first matching series only
    }
    return [$labels, $data];
}
