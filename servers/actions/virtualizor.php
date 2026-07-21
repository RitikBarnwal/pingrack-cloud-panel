<?php
/**
 * servers/actions/virtualizor.php
 *
 * Virtualizor action handler — called by api/server-action.php
 *
 * server-action.php loads this file then providers/virtualizor/bootstrap.php.
 * bootstrap.php defines CloudProvider which exposes $cloud->catalog (VirtualizorCatalog).
 * BUT: VirtualizorCatalog does NOT have http_post/http_get.
 * We must use the VirtualizorClient directly via $cloud->catalog->client
 * OR just instantiate a new VirtualizorClient from provider credentials.
 *
 * This handler grabs the raw VirtualizorClient and calls it directly.
 */
declare(strict_types=1);

class VirtualizorActions
{
    private VirtualizorClient $api;   // raw client — direct access to Virtualizor API
    private array             $server;
    private int               $vid;   // Virtualizor VPS ID (= servers.provider_id in DB)

    public function __construct(object $cloud, array $server)
    {
        $this->server = $server;
        $this->vid    = (int)($server['provider_id'] ?? 0);

        // Extract the raw VirtualizorClient from the CloudProvider object.
        // VirtualizorCatalog and VirtualizorServers both hold a reference to it.
        // We expose it via catalog->getClient() or servers->getClient() — see below.
        // Fallback: re-instantiate from provider credentials stored in DB.
        if (method_exists($cloud->catalog, 'getClient')) {
            $this->api = $cloud->catalog->getClient();
        } else {
            // Re-load credentials from DB using source_provider_id
            $spid = (int)($server['source_provider_id'] ?? 0);
            if (!$spid) throw new RuntimeException('Virtualizor: source_provider_id missing on server record.');
            try {
                $st = db()->prepare('SELECT * FROM providers WHERE id=? AND is_active=1 LIMIT 1');
                $st->execute([$spid]);
                $cred = $st->fetch();
                if (!$cred) throw new RuntimeException("Provider #$spid not found or inactive.");
                $this->api = new VirtualizorClient($cred);
            } catch (Throwable $e) {
                throw new RuntimeException('Virtualizor: could not load API credentials — ' . $e->getMessage());
            }
        }
    }

    // ── Power ─────────────────────────────────────────────────

    public function start(): array
{
    $r = $this->api->post('start', [], [
        'svs' => $this->vid,
        'do'  => 1
    ]);

    if (!empty($r['done']['msg'])) {
        return [
            'ok' => true,
            'message' => $r['done']['msg']
        ];
    }

    return [
        'ok' => false,
        'error' => $r['error'][0] ?? 'Start failed',
        'raw' => $r
    ];
}

    public function stop(): array
{
    $r = $this->api->post('stop', [], [
        'svs' => $this->vid,
        'do'  => 1
    ]);

    if (!empty($r['done']['msg'])) {
        return [
            'ok' => true,
            'message' => $r['done']['msg']
        ];
    }

    return [
        'ok' => false,
        'error' => $r['error'][0] ?? 'Stop failed',
        'raw' => $r
    ];
}

    public function shutdown(): array { return $this->stop(); }

    public function reboot(): array
{
    $r = $this->api->post('restart', [], [
        'svs' => $this->vid,
        'do'  => 1
    ]);

    if (!empty($r['done']['msg'])) {
        return [
            'ok' => true,
            'message' => $r['done']['msg']
        ];
    }

    return [
        'ok' => false,
        'error' => $r['error'][0] ?? 'Reboot failed',
        'raw' => $r
    ];
}

    public function reset(): array
{
    $r = $this->api->post('poweroff', [], [
        'svs' => $this->vid,
        'do'  => 1
    ]);

    if (!empty($r['done']['msg'])) {
        return [
            'ok' => true,
            'message' => $r['done']['msg'] ?? 'Server force stopped (poweroff).'
        ];
    }

    return [
        'ok' => false,
        'error' => $r['error'][0] ?? 'Force stop failed',
        'raw' => $r
    ];
}

    // ── Password ──────────────────────────────────────────────

    public function reset_root_password(): array
{
    $pass = $this->randomPass();

    $r = $this->api->post('changepassword', [], [
        'changepass' => 1,
        'newpass'    => $pass,
        'conf'       => $pass,
        'vpsid'      => $this->vid
    ]);

    if (!empty($r['done']['msg'])) {
        return [
            'ok' => true,
            'message' => 'Root password changed',
            'root_password' => $pass
        ];
    }

    return [
        'ok' => false,
        'error' => VirtualizorClient::errMsg($r['error'][0], 'Password reset failed.')
    ];
}

    // ── Rescue ────────────────────────────────────────────────

    public function enable_rescue(array $payload = []): array
{
    $pass = $payload['password'] ?? $this->randomPass();

    $r = $this->api->post('rescue', [], [
        'svs'           => $this->vid,
        'do'            => 1,
        'enablerescue'  => 1,
        'password'      => $pass,
        'conf_password' => $pass
    ]);

    if (!empty($r['done']['msg'])) {
        return [
            'ok' => true,
            'message' => $r['done']['msg'] ?? 'Rescue mode enabled.',
            'root_password' => $pass
        ];
    }

    return [
        'ok' => false,
        'error' => $r['error'][0] ?? 'Rescue enable failed',
        'raw' => $r
    ];
}

    public function enable_rescue_cycle(array $payload = []): array { return $this->enable_rescue($payload); }

    // ── Rebuild ───────────────────────────────────────────────
    // Virtualizor Enduser API (port 4083 HTTPS / 4082 HTTP):
    //   GET  ?act=ostemplate&svs=VPSID&api=json&apikey=KEY&apipass=PASS
    //   POST reinsos=1&newos=OSID&newpass=PASS&conf=PASS&vid=VPSID
    // Ref: https://www.virtualizor.com/docs/enduser-api/

    public function rebuild(array $payload): array
    {
        $osid = (int)($payload['image'] ?? $payload['osid'] ?? 0);

        if (!$osid) {
            return ['ok' => false, 'error' => 'OS ID (osid) required for reinstall.'];
        }

        $pass = $this->randomPass();

        // Build enduser API URL directly — always port 4083 (HTTPS) or 4082 (HTTP)
        // We cannot rely on the shared client's base URL (it may point to admin port 4085)
        $panelUrl  = $this->api->getPanelUrl(); // e.g. "https://vps.example.com"
        $is_https  = str_starts_with($panelUrl, 'https');
        $eu_port   = $is_https ? 4083 : 4082;
        $eu_base   = $panelUrl . ':' . $eu_port . '/index.php';

        // Reflect credentials from the existing client via authQs helper
        // We call cURL directly here to ensure correct endpoint
        $creds     = $this->api->getCredentials(); // returns ['apikey'=>..., 'apipass'=>...]
        $qs = http_build_query([
            'act'     => 'ostemplate',
            'svs'     => $this->vid,
            'api'     => 'json',
            'apikey'  => $creds['apikey'],
            'apipass' => $creds['apipass'],
        ]);
        $url = $eu_base . '?' . $qs;

        $post_data = http_build_query([
            'reinsos' => 1,
            'newos'   => $osid,
            'newpass' => $pass,
            'conf'    => $pass,
            'vid'     => $this->vid,
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post_data,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $err) {
            return ['ok' => false, 'error' => 'cURL error: ' . $err];
        }

        $r = json_decode((string)$raw, true);
        if (!is_array($r)) {
            return ['ok' => false, 'error' => 'Non-JSON response: ' . substr((string)$raw, 0, 200)];
        }

        // Success: done key exists and has a msg (background reinstall)
        if (!empty($r['done']['msg']) || (isset($r['done']) && $r['done'] == 1)) {
            return [
                'ok'            => true,
                'message'       => (is_array($r['done']) ? $r['done']['msg'] : null)
                                    ?? 'OS reinstall started in background.',
                'root_password' => $pass,
            ];
        }

        $api_err = $r['error'] ?? null;
        if (is_array($api_err)) {
            $api_err = implode(' | ', array_map('strval', $api_err));
        }

        return [
            'ok'    => false,
            'error' => $api_err ?: 'Reinstall failed',
            'raw'   => $r,
        ];
    }

    // ── Snapshots ─────────────────────────────────────────────

    public function create_snapshot(array $payload = []): array
{
    $label = trim($payload['description'] ?? ('snap-' . date('Ymd-Hi')));

    $r = $this->api->post('snapshot', [], [
        'svs'            => $this->vid,
        'do'             => 1,
        'createsnapshot' => 1,
        'snapname'       => $label
    ]);

    if (!empty($r['done']['msg'])) {
        return [
            'ok' => true,
            'message' => $r['done']['msg'] ?? "Snapshot created"
        ];
    }

    return [
        'ok' => false,
        'error' => $r['error'][0] ?? 'Snapshot failed',
        'raw' => $r
    ];
}

public function list_snapshots(): array
{
    $r = $this->api->post('snapshot', [], [
        'svs' => $this->vid
    ]);

    $snaps = [];

    foreach ($r['snaps'] ?? $r['snapshots'] ?? [] as $sid => $s) {
        $snaps[] = [
            'id'          => $s['snap_id'] ?? $sid,
            'description' => $s['snap_name'] ?? 'Snapshot',
            'created'     => isset($s['time']) ? date('Y-m-d H:i:s', (int)$s['time']) : null,
            'image_size'  => $s['size'] ?? null,
            'status'      => 'available'
        ];
    }

    return [
        'ok' => true,
        'snapshots' => $snaps
    ];
}

public function delete_snapshot(array $payload): array
{
    $snap_id = (int)($payload['image_id'] ?? $payload['snapshot_id'] ?? 0);

    if (!$snap_id) {
        return ['ok' => false, 'error' => 'snapshot_id required'];
    }

    $r = $this->api->post('snapshot', [], [
        'svs'         => $this->vid,
        'do'          => 1,
        'delsnapshot' => 1,
        'snapid'      => $snap_id
    ]);

    if (!empty($r['done']['msg'])) {
        return [
            'ok' => true,
            'message' => $r['done']['msg'] ?? 'Snapshot deleted'
        ];
    }

    return [
        'ok' => false,
        'error' => $r['error'][0] ?? 'Delete snapshot failed',
        'raw' => $r
    ];
}

    // ── Console ───────────────────────────────────────────────

    public function get_console(): array
{
    // Try VNC API
    $r = $this->api->post('vnc', [], [
        'svs'   => $this->vid,
        'novnc' => $this->vid,
        'do'    => 1
    ]);

    // If VNC available → return raw details (frontend handle kare)
    if (!empty($r['ip']) && !empty($r['port'])) {
        return [
            'ok'       => true,
            'host'     => $r['ip'],
            'port'     => $r['port'],
            'password' => $r['password'] ?? null,
            'type'     => 'vnc'
        ];
    }

    // Fallback → panel console (always works)
    return [
        'ok'  => true,
        'url' => $this->panelUrl . ':4083/index.php?act=vpsmanage&svs=' . $this->vid,
        'type'=> 'panel'
    ];
}

    // ── Delete ────────────────────────────────────────────────

    public function delete_server(): array
{
    $r = $this->api->post('deletevs', [], [
        'vpsid' => $this->vid,
        'do'    => 1
    ]);

    if (!empty($r['done']) || !empty($r['done']['msg'])) {
        return [
            'ok' => true,
            'message' => $r['done']['msg'] ?? 'VPS delete initiated'
        ];
    }

    return [
        'ok' => false,
        'error' => $r['error'][0] ?? 'Delete failed',
        'raw' => $r
    ];
}

    // ── Floating IPs ──────────────────────────────────────────

    public function list_floating_ips(): array
{
    $r = $this->api->post('vpsmanage', [], [
        'svs' => $this->vid
    ]);

    $ips = [];

    $list = $r['info']['ip'] ?? [];

    foreach ($list as $ip) {
        $addr = is_array($ip) ? ($ip['ip'] ?? null) : $ip;

        if ($addr) {
            $ips[] = [
                'id'   => $addr,
                'ip'   => $addr,
                'type' => 'ipv4'
            ];
        }
    }

    return [
        'ok' => true,
        'assigned' => $ips,
        'available' => [],
        'message' => 'Floating IP not supported in Virtualizor.'
    ];
}

    // ── Stubs (not supported on Virtualizor) ─────────────────

    public function list_volumes(): array
{
    $r = $this->api->post('volume', [], [
        'vpsid' => $this->vid
    ]);

    $vols = [];

    foreach ($r ?? [] as $v) {
        if (!is_array($v) || empty($v['did'])) continue;

        $vols[] = [
            'id'    => $v['did'],
            'name'  => $v['disk_name'] ?? 'volume',
            'size'  => $v['size'] ?? null,
            'path'  => $v['path'] ?? null,
            'type'  => $v['type'] ?? 'block'
        ];
    }

    return ['ok' => true, 'volumes' => $vols];
}
    public function create_volume(array $p): array
{
    $r = $this->api->post('volume', [], [
        'addvolume' => 1,
        'vol_size'  => $p['size'] ?? 1,
        'vps_sel'   => $this->vid,
        'format'    => 'ext4',
        'attach_vol'=> 0,
        'volname'   => $p['name'] ?? 'vol'
    ]);

    if (!empty($r['done'])) {
        return ['ok'=>true,'message'=>$r['done']];
    }

    return ['ok'=>false,'error'=>$r['error'][0] ?? 'Create volume failed'];
}
    public function attach_volume(array $p): array
{
    $r = $this->api->post('volume', [], [
        'perform_action' => 1,
        'vpsid_vol'      => $this->vid,
        'e_vol_did'      => $p['volume_id']
    ]);

    return !empty($r['msg'])
        ? ['ok'=>true,'message'=>$r['msg']]
        : ['ok'=>false,'error'=>$r['error'][0] ?? 'Attach failed'];
}
    public function detach_volume(array $p): array
{
    $r = $this->api->post('volume', [], [
        'perform_action' => 2,
        'vpsid_vol'      => $this->vid,
        'e_vol_did'      => $p['volume_id']
    ]);

    return !empty($r['msg'])
        ? ['ok'=>true,'message'=>$r['msg']]
        : ['ok'=>false,'error'=>$r['error'][0] ?? 'Detach failed'];
}
    public function resize_volume(array $p): array          { return ['ok'=>false,'error'=>'Not supported on Virtualizor.']; }
    public function delete_volume(array $p): array
{
    $r = $this->api->post('volume', [], [
        'deletevolume' => 1,
        'did'          => $p['volume_id']
    ]);

    return !empty($r['msg'])
        ? ['ok'=>true,'message'=>$r['msg']]
        : ['ok'=>false,'error'=>$r['error'][0] ?? 'Delete failed'];
}
    public function list_server_firewalls(): array
{
    $r = $this->api->post('firewall', [], []);

    return [
        'ok' => true,
        'firewalls' => $r['plans'] ?? []
    ];
}
    public function apply_firewall(array $p): array
{
    $r = $this->api->post('firewall', [], [
        'apply_plan' => 1,
        'vpsid'      => $this->vid,
        'planid'     => $p['firewall_id']
    ]);

    return !empty($r['msg'])
        ? ['ok'=>true,'message'=>$r['msg']]
        : ['ok'=>false,'error'=>$r['error'][0] ?? 'Apply firewall failed'];
}
    public function remove_firewall(array $p): array        { return ['ok'=>false,'error'=>'Use iptables inside VPS.']; }
    public function create_floating_ip(array $p): array     { return ['ok'=>false,'error'=>'Manage IPs via Virtualizor panel.']; }
    public function assign_floating_ip(array $p): array     { return ['ok'=>false,'error'=>'Manage IPs via Virtualizor panel.']; }
    public function unassign_floating_ip(array $p): array   { return ['ok'=>false,'error'=>'Manage IPs via Virtualizor panel.']; }
    public function delete_floating_ip(array $p): array     { return ['ok'=>false,'error'=>'Manage IPs via Virtualizor panel.']; }
    public function list_networks(): array                  { return ['ok'=>true,'networks'=>[]]; }
    public function list_all_networks(): array              { return ['ok'=>true,'networks'=>[]]; }
    public function create_network(array $p): array         { return ['ok'=>false,'error'=>'Manage networks via Virtualizor panel.']; }
    public function attach_network(array $p): array         { return ['ok'=>false,'error'=>'Not supported.']; }
    public function detach_network(array $p): array         { return ['ok'=>false,'error'=>'Not supported.']; }

    // ── Private helpers ───────────────────────────────────────

    private function ok(array $r, string $msg): array
    {
        if (($r['done'] ?? 0) == 1) return ['ok' => true, 'message' => $msg];
        return ['ok' => false, 'error' => VirtualizorClient::errMsg($r, 'Action failed.')];
    }

    private function randomPass(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$';
        $s = '';
        for ($i = 0; $i < 20; $i++) $s .= $chars[random_int(0, strlen($chars)-1)];
        return $s;
    }
}