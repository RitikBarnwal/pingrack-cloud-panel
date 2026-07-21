<?php
/**
 * cron/server-status.php
 *
 * * * * * * /usr/local/bin/php /home/cloudgreat/public_html/cron/server-status.php >> /var/log/cv_server-status.log 2>&1
 *
 * Multi-provider aware: groups servers by source_provider_id,
 * loads the correct provider bootstrap per group.
 */


require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/servers.php';

if (!function_exists('clog')) {
    function clog(string $msg): void {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
        if (ob_get_level() > 0) ob_flush();
        flush();
    }
}

// 1. Fetch servers to check:
//    - Pending servers: status/IP update
//    - Running servers: bandwidth update (every run, ~every minute via cron)
$pending_statuses = ['provisioning', 'starting', 'stopping', 'rebuilding'];

try {
    // All active non-deleted servers (pending for status, running for bandwidth)
    $stmt = db()->prepare(
        "SELECT * FROM servers WHERE status != 'deleted' AND deleted_at IS NULL"
    );
    $stmt->execute();
    $servers = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    clog("FATAL DB ERROR: " . $e->getMessage()); exit;
}

if (empty($servers)) { clog('No active servers. Done.'); exit; }

// Separate: pending need full status check, running only need bandwidth
$pending_servers = array_filter($servers, fn($s) => in_array($s['status'], $pending_statuses));
$running_servers = array_filter($servers, fn($s) => $s['status'] === 'running');

clog('Found ' . count($pending_servers) . ' pending + ' . count($running_servers) . ' running server(s)...');

// 2. Group ALL servers by source_provider_id (pending → status check, running → bandwidth)
$all_servers_to_process = array_values(array_merge(
    array_values($pending_servers),
    array_values($running_servers)
));
$groups = [];
foreach ($all_servers_to_process as $srv) {
    $spid = (int)($srv['source_provider_id'] ?? 0);
    if (!$spid) {
        // Fallback: plan_slug se provider dhundo
        $ps = db()->prepare('SELECT p.id FROM providers p JOIN plan_pricing pp ON pp.provider_id=p.id WHERE pp.slug=? AND p.is_active=1 LIMIT 1');
        $ps->execute([$srv['plan_slug'] ?? '']);
        $row = $ps->fetch();
        $spid = $row ? (int)$row['id'] : 0;
    }
    if (!$spid) { clog("  SKIP #{$srv['id']} — Cannot determine provider."); continue; }
    $groups[$spid][] = $srv;
}

// 3. Process each provider group
foreach ($groups as $prov_id => $group_servers) {
    try {
        $prov = db()->prepare('SELECT * FROM providers WHERE id=? LIMIT 1');
        $prov->execute([$prov_id]);
        $provider = $prov->fetch();

        if (!$provider || empty($provider['api_key'])) {
            clog("  ERROR: Provider #$prov_id not found or no API key. Skipping " . count($group_servers) . " server(s).");
            continue;
        }

        $prov_type = strtolower($provider['provider_type'] ?? 'virtualizor');
        $bootstrap = __DIR__ . '/../providers/' . $prov_type . '/bootstrap.php';

        if (!file_exists($bootstrap)) {
            clog("  ERROR: No bootstrap for provider type '$prov_type'. Skipping.");
            continue;
        }

        require_once $bootstrap;
        CloudProvider::reset();
        $cloud = new CloudProvider($provider);

        clog("  Provider #{$prov_id} [{$provider['display_name']}] — " . count($group_servers) . " server(s)");

        foreach ($group_servers as $srv) {
            $remote_id = (int)$srv['provider_id']; // provider ka internal server ID
            if (!$remote_id) {
                clog("    SKIP #{$srv['id']} — No remote provider_id.");
                continue;
            }

            $is_pending_srv = in_array($srv['status'], $pending_statuses);

            try {
                // Provider-specific status endpoint
                $ptype = strtolower($provider['provider_type'] ?? 'virtualizor');
                $total_bw = 0; $used_bw = 0;
                if ($ptype === 'linode') {
                    $raw = $cloud->catalog->http_get('/linode/instances/' . $remote_id);
                    if (!empty($raw['id'])) {
                        $mapped     = $cloud->servers->mapServer($raw);
                        $new_status = $mapped['status'] ?? 'provisioning';
                        $ipv4       = $mapped['ipv4']   ?? $srv['ipv4'];
                        $ipv6       = $mapped['ipv6']   ?? $srv['ipv6'];
                        $total_bw   = $mapped['bandwidth_gb']      ?? 0;
                        $used_bw    = $mapped['used_bandwidth_gb']  ?? 0;
                        // Linode: fetch transfer usage separately
                        try {
                            $tr = $cloud->catalog->http_get('/linode/instances/' . $remote_id . '/transfer');
                            $used_bw = round(($tr['bytes_out'] ?? 0) / (1024 ** 3), 3);
                        } catch (Throwable $te) {}
                    } else {
                        clog("    WARN #{$srv['id']} ({$srv['name']}) — Not found in Linode API");
                        continue;
                    }
                } elseif ($ptype === 'virtualizor') {

    $v = $cloud->servers->get($remote_id);

    if (!empty($v)) {
        $new_status = $v['status'] ?? 'provisioning';
        $ipv4       = $v['ipv4']   ?? $srv['ipv4'];
        $total_bw   = $v['bandwidth_gb']     ?? 0;
        $used_bw    = $v['used_bandwidth_gb'] ?? 0;
    } else {
        clog("    WARN #{$srv['id']} — VPS not found in Virtualizor");
        continue;
    }
} else {
                    // Hetzner
                    $raw = $cloud->catalog->http_get('/servers/' . $remote_id);
                    $s   = $raw['server'] ?? null;

                    // Linode returns instance directly (no 'server' wrapper)
                    if (!$s && !empty($raw['id'])) {
                        // It's a Linode response — use servers mapper
                        $mapped     = $cloud->servers->mapServer($raw);
                        $new_status = $mapped['status'] ?? 'provisioning';
                        $ipv4       = $mapped['ipv4']   ?? $srv['ipv4'];
                        $ipv6       = $mapped['ipv6']   ?? $srv['ipv6'];
                    } elseif ($s) {
                        // Hetzner response
                        $api_status = $s['status'] ?? 'unknown';
                        $new_status = match($api_status) {
                            'running'                  => 'running',
                            'off'                      => 'stopped',
                            'stopping'                 => 'stopping',
                            'starting'                 => 'starting',
                            'rebuilding'               => 'rebuilding',
                            'migrating','initializing' => 'provisioning',
                            default                    => 'provisioning',
                        };
                        $ipv4     = $srv['ipv4'] ?: ($s['public_net']['ipv4']['ip'] ?? null);
                        $ipv6     = $srv['ipv6'] ?: ($s['public_net']['ipv6']['ip'] ?? null);
                        // Hetzner: outgoing_traffic bytes → GB used, included_traffic bytes → GB total
                        $used_bw  = round(($s['outgoing_traffic']  ?? 0) / (1024 ** 3), 3);
                        $total_bw = (int)round(($s['included_traffic'] ?? 0) / (1024 ** 3));
                    } else {
                        clog("    WARN #{$srv['id']} ({$srv['name']}) — Not found in API (remote ID: $remote_id)");
                        continue;
                    }
                }

                // Bandwidth update (only if provider returned non-zero values)
                $bw_set  = '';
                $bw_vals = [];
                if ($total_bw > 0 || $used_bw > 0) {
                    $bw_set  = ', total_bandwidth_gb=?, used_bandwidth_gb=?';
                    $bw_vals = [(int)$total_bw, (float)$used_bw];
                }

                if ($is_pending_srv) {
                    // Pending server: update status + IP + bandwidth
                    if ($new_status !== $srv['status'] || ($ipv4 && !$srv['ipv4']) || !empty($bw_vals)) {
                        db()->prepare("UPDATE servers SET status=?, ipv4=?, ipv6=?{$bw_set} WHERE id=". $srv['id'])
                           ->execute(array_merge([$new_status, $ipv4 ?? $srv['ipv4'], $ipv6 ?? $srv['ipv6']], $bw_vals));
                        clog("    UPDATED #{$srv['id']} ({$srv['name']}): {$srv['status']} → $new_status"
                            . ($ipv4 != $srv['ipv4'] ? " (IP: $ipv4)" : "")
                            . (!empty($bw_vals) ? " (BW: {$used_bw}/{$total_bw} GB)" : ""));
                    } else {
                        clog("    OK #{$srv['id']} ({$srv['name']}) still $new_status");
                    }
                } else {
                    // Running server: only update bandwidth (don't touch status/IP)
                    if (!empty($bw_vals)) {
                        db()->prepare('UPDATE servers SET total_bandwidth_gb=?, used_bandwidth_gb=? WHERE id=?')
                           ->execute([(int)$total_bw, (float)$used_bw, $srv['id']]);
                        clog("    BW #{$srv['id']} ({$srv['name']}): {$used_bw}/{$total_bw} GB");
                    } else {
                        clog("    BW #{$srv['id']} ({$srv['name']}): no bandwidth data from API, skipping");
                    }
                }

            } catch (Throwable $e) {
                clog("    ERROR #{$srv['id']} ({$srv['name']}): " . $e->getMessage());
            }
        }

    } catch (Throwable $e) {
        clog("  PROVIDER #$prov_id ERROR: " . $e->getMessage());
    }
}

clog('Status check done.');
