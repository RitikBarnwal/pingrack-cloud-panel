<?php
/**
 * api/server-status-poll.php
 * Called by view.php every few seconds via AJAX when server is provisioning.
 * Checks provider API and updates DB status.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/servers.php';
require_login();

header('Content-Type: application/json');

$id   = (int)($_GET['id'] ?? 0);
$csrf = $_GET['csrf'] ?? '';

if (!verify_csrf($csrf)) { echo json_encode(['ok'=>false]); exit; }

$user = current_user();
$srv  = get_server($id, (int)$user['id']);
if (!$srv) { echo json_encode(['ok'=>false,'error'=>'Not found']); exit; }

// Always fetch from provider API — never trust DB cache for status

// Poll provider API
try {
    // Server ke source_provider_id se provider fetch karo
    $spid = (int)($srv['source_provider_id'] ?? 0);
    $prov = null;
    if ($spid) {
        $ps = db()->prepare('SELECT * FROM providers WHERE id=? LIMIT 1');
        $ps->execute([$spid]);
        $prov = $ps->fetch() ?: null;
    }
    // Fallback: plan_slug se dhundo
    if (!$prov) {
        $ps = db()->prepare('SELECT p.* FROM providers p JOIN plan_pricing pp ON pp.provider_id=p.id WHERE pp.slug=? AND p.is_active=1 LIMIT 1');
        $ps->execute([$srv['plan_slug'] ?? '']);
        $prov = $ps->fetch() ?: null;
    }

    if (!$prov || !$prov['api_key']) throw new Exception('No provider');

    $provider_type = strtolower($prov['provider_type'] ?? 'virtualizor');
    require_once __DIR__ . '/../providers/' . $provider_type . '/bootstrap.php';
    CloudProvider::reset();
    $cloud = new CloudProvider($prov);

    // Provider-specific status fetch
    $total_bw = 0;
    $used_bw  = 0;
    if ($provider_type === 'linode') {
        $raw = $cloud->catalog->http_get('/linode/instances/' . (int)$srv['provider_id']);
        $mapped = $cloud->servers->mapServer($raw);
        $new_status = $mapped['status'] ?? 'provisioning';
        $ipv4 = $mapped['ipv4'] ?? $srv['ipv4'] ?? null;
        $ipv6 = $mapped['ipv6'] ?? $srv['ipv6'] ?? null;
        $total_bw = $mapped['bandwidth_gb']     ?? 0;
        try {
            $tr = $cloud->catalog->http_get('/linode/instances/' . (int)$srv['provider_id'] . '/transfer');
            $used_bw = round(($tr['bytes_out'] ?? 0) / (1024 ** 3), 3);
        } catch (Throwable $te) { $used_bw = $mapped['used_bandwidth_gb'] ?? 0; }
    } elseif ($provider_type === 'utho') {
        $raw = $cloud->catalog->http_get('/cloudinstances/' . (int)$srv['provider_id']);
        $s   = $raw['cloudinstances'][0] ?? $raw['cloudinstance'] ?? null;
        if (!$s) throw new Exception('Server not found in Utho API');
        $mapped     = $cloud->servers->mapServer($s);
        $new_status = $mapped['status'] ?? 'provisioning';
        $ipv4       = $mapped['ipv4']   ?? $srv['ipv4'] ?? null;
        $ipv6       = $mapped['ipv6']   ?? $srv['ipv6'] ?? null;
        $total_bw   = $mapped['bandwidth_gb']      ?? 0;
        $used_bw    = $mapped['used_bandwidth_gb']  ?? 0;
    } elseif ($provider_type === 'contabo') {
        $raw  = $cloud->catalog->http_get('/compute/instances/' . (int)$srv['provider_id']);
        $s    = $raw['data'][0] ?? null;
        if (!$s) throw new Exception('Instance not found in Contabo API');
        $mapped     = $cloud->servers->mapServer($s);
        $new_status = $mapped['status'] ?? 'provisioning';
        $ipv4       = $mapped['ipv4']   ?? $srv['ipv4'] ?? null;
        $ipv6       = $mapped['ipv6']   ?? $srv['ipv6'] ?? null;
        $total_bw   = $mapped['bandwidth_gb']      ?? 0;
        $used_bw    = $mapped['used_bandwidth_gb']  ?? 0;
    } elseif ($provider_type === 'digitalocean') {
        $raw     = $cloud->catalog->http_get('/droplets/' . (int)$srv['provider_id']);
        $droplet = $raw['droplet'] ?? null;
        if (!$droplet) throw new Exception('Droplet not found in DigitalOcean API');
        $mapped     = $cloud->servers->mapServer($droplet);
        $new_status = $mapped['status'] ?? 'provisioning';
        $ipv4       = $mapped['ipv4']   ?? $srv['ipv4'] ?? null;
        $ipv6       = $mapped['ipv6']   ?? $srv['ipv6'] ?? null;
        $total_bw   = $mapped['bandwidth_gb']      ?? 0;
        $used_bw    = $mapped['used_bandwidth_gb']  ?? 0;
    } elseif ($provider_type === 'proxmox') {

    $vmid      = (int)$srv['provider_id'];
    $node      = $srv['region_slug'] ?: $cloud->catalog->getClient()->resolveNode();

    try {
        $r      = $cloud->catalog->http_get("nodes/{$node}/qemu/{$vmid}/status/current");
        $vm     = $r['data'] ?? [];
        $raw_st = $vm['status'] ?? 'stopped';

        $new_status = match(strtolower($raw_st)) {
            'running'         => 'running',
            'stopped','shutoff','paused' => 'stopped',
            default           => $srv['status'],
        };

        // Get IP from guest agent
        try {
            $ifaces = $cloud->catalog->http_get("nodes/{$node}/qemu/{$vmid}/agent/network-get-interfaces");
            foreach ($ifaces['data']['result'] ?? [] as $iface) {
                if (($iface['name'] ?? '') === 'lo') continue;
                foreach ($iface['ip-addresses'] ?? [] as $ip) {
                    if (($ip['ip-address-type'] ?? '') === 'ipv4') {
                        $addr = $ip['ip-address'] ?? '';
                        if ($addr && !str_starts_with($addr, '127.') && !str_starts_with($addr, '169.254.')) {
                            $ipv4 = $addr;
                            break 2;
                        }
                    }
                }
            }
        } catch (Throwable $ae) {}

        $ipv4 = $ipv4 ?? $srv['ipv4'] ?? null;
        $ipv6 = $srv['ipv6'] ?? null;

        // Bandwidth counters (bytes since reset)
        $net_in  = (int)($vm['netin']  ?? 0);
        $net_out = (int)($vm['netout'] ?? 0);
        if ($net_in > 0 || $net_out > 0) {
            $used_bw  = round(($net_in + $net_out) / (1024 ** 3), 3);
            $total_bw = (int)($srv['total_bandwidth_gb'] ?: 0);
        }

        // If running, also refresh OS label from config
        if ($new_status === 'running') {
            try {
                $cfg = $cloud->catalog->http_get("nodes/{$node}/qemu/{$vmid}/config");
                $ostype = $cfg['data']['ostype'] ?? '';
                if ($ostype) {
                    $os_map = ['l26'=>'Linux','l24'=>'Linux','win11'=>'Windows 11','win10'=>'Windows 10',
                               'win2k22'=>'Windows Server 2022','win2k19'=>'Windows Server 2019',
                               'win2k16'=>'Windows Server 2016'];
                    $new_os_resp = $os_map[$ostype] ?? ucfirst($ostype);
                    // Update os_label in DB
                    db()->prepare('UPDATE servers SET os_label=?, image_slug=? WHERE id=?')
                       ->execute([$new_os_resp, strtolower(explode(' ', $new_os_resp)[0]), $srv['id']]);
                }
            } catch (Throwable $ce) {}
        }

    } catch (Throwable $pe) {
        $new_status = $srv['status'];
        $ipv4 = $srv['ipv4'] ?? null;
        $ipv6 = $srv['ipv6'] ?? null;
    }

    } elseif ($provider_type === 'vultr') {
        // Vultr: GET /instances/{id}
        // instance.status = active|pending|installing|suspended
        // instance.power_status = running|stopped
        $raw  = $cloud->catalog->http_get('/instances/' . $srv['provider_id']);
        $inst = $raw['instance'] ?? null;

        if (!$inst) throw new Exception('Vultr instance not found: ' . $srv['provider_id']);

        $mapped     = $cloud->servers->mapServer($inst);
        $new_status = $mapped['status'] ?? $srv['status'];
        $ipv4       = $mapped['ipv4']   ?? $srv['ipv4'] ?? null;
        $ipv6       = $mapped['ipv6']   ?? $srv['ipv6'] ?? null;
        $total_bw   = $mapped['bandwidth_gb']      ?? 0;
        // Vultr bandwidth: allowed_bandwidth in GB, netout+netin in bytes
        $used_bw    = round(
            (((int)($inst['netout'] ?? 0)) + ((int)($inst['netin'] ?? 0))) / (1024 ** 3),
            3
        );
        $new_os_resp = $inst['os'] ?? null;

    } elseif ($provider_type === 'virtualizor') {

    // Virtualizor Enduser API — MUST use port 4083 (not admin port 4085)
    // act=vpsmanage&svs=VPSID → info.status: 1=running, 0=stopped, -1=suspended
    $vps_id = (int)$srv['provider_id'];
    $creds  = $cloud->catalog->getClient()->getCredentials();
    $panel  = $cloud->catalog->getClient()->getPanelUrl();
    $eu_port = str_starts_with($panel, 'https') ? 4083 : 4082;

    $qs = http_build_query([
        'act'     => 'vpsmanage',
        'svs'     => $vps_id,
        'api'     => 'json',
        'apikey'  => $creds['apikey'],
        'apipass' => $creds['apipass'],
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $panel . ':' . $eu_port . '/index.php?' . $qs,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
    ]);
    $raw_v = curl_exec($ch);
    curl_close($ch);

    $rv     = is_string($raw_v) ? (json_decode($raw_v, true) ?? []) : [];
    $raw_s  = (int)($rv['info']['status'] ?? -99);

    $new_status = match(true) {
        $raw_s === 1  => 'running',
        $raw_s === 0  => 'stopped',
        $raw_s === -1 => 'suspended',
        default       => $srv['status'],
    };

    $vps_ips = $rv['info']['ip'] ?? [];
    $ipv4    = is_array($vps_ips) && !empty($vps_ips) ? $vps_ips[0] : ($srv['ipv4'] ?? null);
    $ipv6    = $srv['ipv6'] ?? null;

    $bw = $rv['info']['bandwidth'] ?? [];
    if (!empty($bw)) {
        $total_bw = (float)($bw['limit_gb'] ?? 0);
        $used_bw  = (float)($bw['used_gb']  ?? 0);
    }
} else {
        // Hetzner (and future Hetzner-like providers)
        $raw = $cloud->catalog->http_get('/servers/' . (int)$srv['provider_id']);
        $s   = $raw['server'] ?? null;
        if (!$s) throw new Exception('Server not found in API');

        $api_status = $s['status'] ?? 'unknown';
        $new_status = match($api_status) {
            'running'      => 'running',
            'off'          => 'stopped',
            'stopping'     => 'stopping',
            'starting'     => 'starting',
            'rebuilding'   => 'rebuilding',
            'restarting'   => 'starting',
            'initializing','migrating' => 'provisioning',
            default        => $srv['status'], // keep current if unknown
        };
        $ipv4     = $s['public_net']['ipv4']['ip'] ?? $srv['ipv4'] ?? null;
        $ipv6     = $s['public_net']['ipv6']['ip'] ?? $srv['ipv6'] ?? null;
        $used_bw  = round(($s['outgoing_traffic']  ?? 0) / (1024 ** 3), 3);
        $total_bw = (int)round(($s['included_traffic'] ?? 0) / (1024 ** 3));

        // Hetzner 'running' status reliable nahi hai reboot ke dauran
        // 'locked: true' = server pe action chal raha hai (reboot/rebuild etc)
        $is_locked = !empty($s['locked']);
        if ($new_status === 'running' && $is_locked) {
            $new_status = 'starting'; // locked = still rebooting
        }
    }

    // Update DB if changed — include bandwidth when available
    $bw_set  = '';
    $bw_vals = [];
    if ($total_bw > 0 || $used_bw > 0) {
        $bw_set  = ', total_bandwidth_gb=?, used_bandwidth_gb=?';
        $bw_vals = [(int)$total_bw, (float)$used_bw];
    }
    if ($new_status !== $srv['status'] || (!$srv['ipv4'] && $ipv4) || !empty($bw_vals)) {
        db()->prepare("UPDATE servers SET status=?, ipv4=?, ipv6=?{$bw_set} WHERE id=?")
           ->execute(array_merge([$new_status, $ipv4, $ipv6], $bw_vals, [$srv['id']]));
    }

    $final = in_array($new_status, ['running','stopped','error']);

    // Virtualizor: jab server running ho jaye to full details fetch karke DB update karo
    // (OS image rebuild ke baad change ho sakti hai, hostname bhi)
    if ($provider_type === 'virtualizor' && $new_status === 'running') {
        try {
            $vps_id  = (int)$srv['provider_id'];
            $creds   = $cloud->catalog->getClient()->getCredentials();
            $panel   = $cloud->catalog->getClient()->getPanelUrl();
            $eu_port = str_starts_with($panel, 'https') ? 4083 : 4082;

            $qs = http_build_query([
                'act'     => 'vpsmanage',
                'svs'     => $vps_id,
                'api'     => 'json',
                'apikey'  => $creds['apikey'],
                'apipass' => $creds['apipass'],
            ]);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $panel . ':' . $eu_port . '/index.php?' . $qs,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT      => 'Mozilla/5.0',
            ]);
            $raw_detail = curl_exec($ch);
            curl_close($ch);

            $rd = is_string($raw_detail) ? (json_decode($raw_detail, true) ?? []) : [];

            if (!empty($rd['info'])) {
                $info = $rd['info'];
                $vps  = $info['vps'] ?? [];

                // OS label — from vps.os_name
                $new_os_label = $vps['os_name'] ?? null;

                // image_slug — distro name from os_name e.g. "ubuntu-22.04-x86_64" → "ubuntu"
                $new_image_slug = null;
                if ($new_os_label) {
                    $os_lower = strtolower($new_os_label);
                    foreach (['ubuntu','debian','centos','rocky','alma','almalinux','windows','fedora'] as $d) {
                        if (str_contains($os_lower, $d)) {
                            $new_image_slug = $d === 'almalinux' ? 'alma' : $d;
                            break;
                        }
                    }
                }

                // IP
                $live_ips = $info['ip'] ?? [];
                $live_ipv4 = is_array($live_ips) && !empty($live_ips) ? $live_ips[0] : null;

                // Hostname
                $new_hostname = $vps['hostname'] ?? $info['hostname'] ?? null;

                // Bandwidth
                $bw_info = $info['bandwidth'] ?? [];

                // Build dynamic UPDATE
                $set_parts = ['status=?', 'ipv4=COALESCE(?,ipv4)'];
                $set_vals  = [$new_status, $live_ipv4 ?: $ipv4];

                if ($new_os_label) {
                    $set_parts[] = 'os_label=?';
                    $set_vals[]  = $new_os_label;
                }
                if ($new_image_slug) {
                    $set_parts[] = 'image_slug=?';
                    $set_vals[]  = $new_image_slug;
                }
                if ($new_hostname) {
                    $set_parts[] = 'name=?';
                    $set_vals[]  = $new_hostname;
                }
                if (!empty($bw_info)) {
                    $set_parts[] = 'total_bandwidth_gb=?';
                    $set_parts[] = 'used_bandwidth_gb=?';
                    $set_vals[]  = (float)($bw_info['limit_gb'] ?? 0);
                    $set_vals[]  = (float)($bw_info['used_gb']  ?? 0);
                }

                $set_vals[] = $srv['id'];
                db()->prepare('UPDATE servers SET ' . implode(', ', $set_parts) . ' WHERE id=?')
                   ->execute($set_vals);

                // Response mein updated values bhejo
                $ipv4        = $live_ipv4 ?: $ipv4;
                $new_os_resp = $new_os_label;
            }
        } catch (Throwable $ve) {
            // Non-fatal — status update already hua, sirf detail fetch fail hua
            error_log('[poll] Virtualizor detail fetch failed: ' . $ve->getMessage());
        }
    }

    echo json_encode([
        'ok'     => true,
        'status' => $new_status,
        'ipv4'   => $ipv4,
        'ipv6'   => $ipv6,
        'final'  => $final,
        'os'     => $new_os_resp ?? null,
    ]);

} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'status'=>$srv['status']]);
}