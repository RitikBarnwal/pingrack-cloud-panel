<?php
/**
 * servers/actions/vultr.php
 *
 * Vultr-specific server action handler.
 * Called by api/server-action.php when provider_type = 'vultr'.
 *
 * Class: VultrActions
 *
 * Vultr API reference:
 *   start          → POST /instances/{id}/start
 *   stop           → POST /instances/{id}/halt
 *   shutdown       → POST /instances/{id}/halt
 *   reboot         → POST /instances/{id}/reboot
 *   reset          → POST /instances/{id}/reboot  (no separate hard reset)
 *   rebuild        → POST /instances/{id}/reinstall
 *   reset_password → PATCH /instances/{id}  (set label/tag — pw change not supported)
 *   snapshots      → GET/POST/DELETE /snapshots
 *   console        → GET /instances/{id}/vnc
 *   firewalls      → GET /firewalls, POST /firewalls/{id}/rules
 *   volumes        → GET/POST/DELETE /blocks
 *   floating ips   → GET/POST/DELETE /reserved-ips
 *   delete         → DELETE /instances/{id}
 */
declare(strict_types=1);

class VultrActions
{
    private object $cloud;
    private array  $server;
    private string $vid;   // Vultr instance UUID (provider_id in DB)

    public function __construct(object $cloud, array $server)
    {
        $this->cloud  = $cloud;
        $this->server = $server;
        $this->vid    = (string)($server['provider_id'] ?? '');
    }

    // ── Power ─────────────────────────────────────────────────

    public function start(): array
    {
        $r = $this->cloud->catalog->http_post('/instances/' . $this->vid . '/start');
        return $this->ok($r, 'Server is starting up.');
    }

    public function stop(): array
    {
        // Vultr /halt = force power off (immediate, no graceful)
        $r = $this->cloud->catalog->http_post('/instances/' . $this->vid . '/halt');
        return $this->ok($r, 'Server is powering off.');
    }

    public function shutdown(): array
    {
        // Vultr has no graceful ACPI shutdown via API; halt is the closest
        return $this->stop();
    }

    public function reboot(): array
    {
        $r = $this->cloud->catalog->http_post('/instances/' . $this->vid . '/reboot');
        return $this->ok($r, 'Server is rebooting.');
    }

    public function reset(): array
    {
        return $this->reboot();
    }

    // ── Rescue ────────────────────────────────────────────────

    public function enable_rescue(array $payload = []): array
    {
        // Vultr does not have a native rescue/recovery mode via API.
        // Best approximation: reinstall into rescue OS if available.
        return ['ok' => false, 'error' => 'Vultr does not support a rescue mode via API. Use the Vultr Control Panel to manage recovery.'];
    }

    public function enable_rescue_cycle(array $payload = []): array
    {
        return $this->enable_rescue($payload);
    }

    // ── Password Reset ────────────────────────────────────────

    public function reset_root_password(): array
    {
        // Vultr does not expose a root-password-reset endpoint.
        // Recommended flow: reinstall (rebuild) with a new password.
        return [
            'ok'    => false,
            'error' => 'Vultr does not support remote root-password reset via API. Use "Rebuild" to reinstall the OS with a new password, or connect via VNC console to change manually.',
        ];
    }

    // ── Rebuild (Reinstall) ───────────────────────────────────

    public function rebuild(array $payload): array
    {
        $image = trim($payload['image'] ?? '');

        // Vultr /reinstall keeps same OS by default; with os_id it changes OS
        $body = [];
        if ($image && is_numeric($image)) {
            $body['os_id'] = (int)$image;
        } elseif ($image && str_starts_with($image, 'app:')) {
            $body['app_id'] = (int)substr($image, 4);
        } elseif ($image && str_starts_with($image, 'snap:')) {
            $body['snapshot_id'] = substr($image, 5);
        }

        $r    = $this->cloud->catalog->http_post('/instances/' . $this->vid . '/reinstall', $body);
        $code = $r['_http_status'] ?? 0;

        if (in_array($code, [200, 201, 202, 204])) {
            return ['ok' => true, 'message' => 'Server reinstall started. It will be ready in a few minutes.'];
        }
        return ['ok' => false, 'error' => $this->errMsg($r, 'Reinstall failed.')];
    }

    // ── Snapshots ─────────────────────────────────────────────

    public function create_snapshot(array $payload = []): array
    {
        $desc = trim($payload['description'] ?? ('snap-' . date('Ymd-Hi')));

        $r    = $this->cloud->catalog->http_post('/snapshots', [
            'instance_id' => $this->vid,
            'description' => $desc,
        ]);
        $code = $r['_http_status'] ?? 0;

        if (in_array($code, [200, 201, 202]) && !empty($r['snapshot']['id'])) {
            return ['ok' => true, 'message' => "Snapshot '{$desc}' is being created."];
        }
        return ['ok' => false, 'error' => $this->errMsg($r, 'Snapshot creation failed.')];
    }

    public function list_snapshots(): array
    {
        $r     = $this->cloud->catalog->http_get('/snapshots', ['per_page' => 100]);
        $snaps = [];

        foreach ($r['snapshots'] ?? [] as $s) {
            // Filter to snapshots of THIS instance
            if (($s['instance_id'] ?? '') !== $this->vid) continue;
            $snaps[] = [
                'id'          => $s['id']          ?? '',
                'description' => $s['description'] ?? 'Snapshot',
                'created'     => $s['date_created'] ?? null,
                'image_size'  => isset($s['size']) ? round($s['size'] / 1024, 1) : null,  // bytes → KB or GB
                'status'      => $s['status']      ?? 'pending',
                'type'        => 'snapshot',
            ];
        }

        return ['ok' => true, 'snapshots' => $snaps];
    }

    public function delete_snapshot(array $payload): array
    {
        $id = trim($payload['snapshot_id'] ?? $payload['id'] ?? '');
        if (!$id) return ['ok' => false, 'error' => 'Snapshot ID required.'];

        $r    = $this->cloud->catalog->http_delete('/snapshots/' . $id);
        $code = $r['_http_status'] ?? 0;

        return in_array($code, [200, 204])
            ? ['ok' => true, 'message' => 'Snapshot deleted.']
            : ['ok' => false, 'error' => $this->errMsg($r, 'Delete failed.')];
    }

    // ── Block Storage (Volumes) ───────────────────────────────

    public function list_volumes(): array
    {
        $r    = $this->cloud->catalog->http_get('/blocks', ['per_page' => 100]);
        $vols = [];

        foreach ($r['blocks'] ?? [] as $v) {
            if (($v['attached_to_instance'] ?? '') !== $this->vid) continue;
            $vols[] = $this->mapVolume($v);
        }

        return ['ok' => true, 'volumes' => $vols];
    }

    public function create_volume(array $payload): array
    {
        $name    = trim($payload['volume_name'] ?? '');
        $size_gb = (int)($payload['size_gb'] ?? 40);
        $region  = $this->server['region_slug'] ?? 'ewr';

        if (!$name)          return ['ok' => false, 'error' => 'Volume label required.'];
        if ($size_gb < 10)   return ['ok' => false, 'error' => 'Minimum volume size is 10 GB.'];
        if ($size_gb > 40000) return ['ok' => false, 'error' => 'Maximum volume size is 40,000 GB.'];

        $r = $this->cloud->catalog->http_post('/blocks', [
            'region'     => $region,
            'size_gb'    => $size_gb,
            'label'      => $name,
            'attached_to_instance' => $this->vid,
            'live'       => true,
        ]);
        $code = $r['_http_status'] ?? 0;

        if (in_array($code, [200, 201]) && !empty($r['block']['id'])) {
            return ['ok' => true, 'message' => "Volume '{$name}' created and attached.", 'volume' => $this->mapVolume($r['block'])];
        }
        return ['ok' => false, 'error' => $this->errMsg($r, 'Volume creation failed.')];
    }

    public function attach_volume(array $payload): array
    {
        $vol_id = trim($payload['volume_id'] ?? '');
        if (!$vol_id) return ['ok' => false, 'error' => 'Volume ID required.'];

        $r    = $this->cloud->catalog->http_post("/blocks/{$vol_id}/attach", [
            'instance_id' => $this->vid,
            'live'        => true,
        ]);
        $code = $r['_http_status'] ?? 0;

        return in_array($code, [200, 204])
            ? ['ok' => true, 'message' => 'Volume attached.']
            : ['ok' => false, 'error' => $this->errMsg($r, 'Attach failed.')];
    }

    public function detach_volume(array $payload): array
    {
        $vol_id = trim($payload['volume_id'] ?? '');
        if (!$vol_id) return ['ok' => false, 'error' => 'Volume ID required.'];

        $r    = $this->cloud->catalog->http_post("/blocks/{$vol_id}/detach", ['live' => true]);
        $code = $r['_http_status'] ?? 0;

        return in_array($code, [200, 204])
            ? ['ok' => true, 'message' => 'Volume detached.']
            : ['ok' => false, 'error' => $this->errMsg($r, 'Detach failed.')];
    }

    public function resize_volume(array $payload): array
    {
        $vol_id  = trim($payload['volume_id'] ?? '');
        $size_gb = (int)($payload['size_gb'] ?? 0);
        if (!$vol_id || $size_gb < 10) return ['ok' => false, 'error' => 'Invalid volume ID or size (min 10 GB).'];

        $r    = $this->cloud->catalog->http_patch("/blocks/{$vol_id}", ['size_gb' => $size_gb]);
        $code = $r['_http_status'] ?? 0;

        return in_array($code, [200, 204])
            ? ['ok' => true, 'message' => 'Volume resize initiated.']
            : ['ok' => false, 'error' => $this->errMsg($r, 'Resize failed.')];
    }

    public function delete_volume(array $payload): array
    {
        $vol_id = trim($payload['volume_id'] ?? '');
        if (!$vol_id) return ['ok' => false, 'error' => 'Volume ID required.'];

        // Detach first (non-fatal if already detached)
        try {
            $this->cloud->catalog->http_post("/blocks/{$vol_id}/detach", ['live' => true]);
        } catch (Throwable $e) {}

        $r    = $this->cloud->catalog->http_delete("/blocks/{$vol_id}");
        $code = $r['_http_status'] ?? 0;

        return in_array($code, [200, 204])
            ? ['ok' => true, 'message' => 'Volume deleted.']
            : ['ok' => false, 'error' => $this->errMsg($r, 'Delete failed.')];
    }

    // ── Firewalls ─────────────────────────────────────────────

    public function list_server_firewalls(): array
    {
        // Vultr firewalls are groups — list all and find ones assigned to this instance
        $r  = $this->cloud->catalog->http_get('/firewalls', ['per_page' => 100]);
        $fw = [];

        foreach ($r['firewall_groups'] ?? [] as $f) {
            $fw[] = [
                'id'     => $f['id'],
                'name'   => $f['description'] ?? $f['id'],
                'rules'  => [],
                'status' => 'enabled',
            ];
        }

        return ['ok' => true, 'firewalls' => $fw];
    }

    public function apply_firewall(array $payload): array
    {
        $fw_id = trim($payload['firewall_id'] ?? '');
        if (!$fw_id) return ['ok' => false, 'error' => 'Firewall group ID required.'];

        // Assign firewall group to instance via PATCH
        $r    = $this->cloud->catalog->http_patch('/instances/' . $this->vid, [
            'firewall_group_id' => $fw_id,
        ]);
        $code = $r['_http_status'] ?? 0;

        return in_array($code, [200, 202, 204])
            ? ['ok' => true, 'message' => 'Firewall group applied to server.']
            : ['ok' => false, 'error' => $this->errMsg($r, 'Apply failed.')];
    }

    public function remove_firewall(array $payload): array
    {
        // Clear firewall group by setting to empty string
        $r    = $this->cloud->catalog->http_patch('/instances/' . $this->vid, [
            'firewall_group_id' => '',
        ]);
        $code = $r['_http_status'] ?? 0;

        return in_array($code, [200, 202, 204])
            ? ['ok' => true, 'message' => 'Firewall removed from server.']
            : ['ok' => false, 'error' => $this->errMsg($r, 'Remove failed.')];
    }

    // ── Reserved / Floating IPs ───────────────────────────────

    public function list_floating_ips(): array
    {
        $r = $this->cloud->catalog->http_get('/reserved-ips', ['per_page' => 100]);

        $assigned  = [];
        $available = [];

        foreach ($r['reserved_ips'] ?? [] as $ip) {
            $entry = [
                'id'            => $ip['id']         ?? null,
                'ip'            => $ip['subnet']      ?? null,
                'type'          => $ip['ip_type']      ?? 'v4',
                'home_location' => ['name' => $ip['region'] ?? ''],
            ];
            if (($ip['instance_id'] ?? '') === $this->vid) {
                $assigned[] = $entry;
            } else {
                $available[] = $entry;
            }
        }

        return ['ok' => true, 'assigned' => $assigned, 'available' => $available];
    }

    public function create_floating_ip(array $payload): array
    {
        $region = $this->server['region_slug'] ?? 'ewr';

        $r    = $this->cloud->catalog->http_post('/reserved-ips', [
            'region'  => $region,
            'ip_type' => 'v4',
        ]);
        $code = $r['_http_status'] ?? 0;

        if (in_array($code, [200, 201]) && !empty($r['reserved_ip']['id'])) {
            $ip_id = $r['reserved_ip']['id'];
            // Immediately attach
            $this->cloud->catalog->http_post("/reserved-ips/{$ip_id}/attach", ['instance_id' => $this->vid]);
            return ['ok' => true, 'message' => 'Reserved IP created and attached.'];
        }
        return ['ok' => false, 'error' => $this->errMsg($r, 'Reserved IP creation failed.')];
    }

    public function assign_floating_ip(array $payload): array
    {
        $ip_id = trim($payload['ip_id'] ?? $payload['floating_ip_id'] ?? '');
        if (!$ip_id) return ['ok' => false, 'error' => 'Reserved IP ID required.'];

        $r    = $this->cloud->catalog->http_post("/reserved-ips/{$ip_id}/attach", ['instance_id' => $this->vid]);
        $code = $r['_http_status'] ?? 0;

        return in_array($code, [200, 204])
            ? ['ok' => true, 'message' => 'Reserved IP assigned to server.']
            : ['ok' => false, 'error' => $this->errMsg($r, 'Assignment failed.')];
    }

    public function unassign_floating_ip(array $payload): array
    {
        $ip_id = trim($payload['ip_id'] ?? $payload['floating_ip_id'] ?? '');
        if (!$ip_id) return ['ok' => false, 'error' => 'Reserved IP ID required.'];

        $r    = $this->cloud->catalog->http_post("/reserved-ips/{$ip_id}/detach");
        $code = $r['_http_status'] ?? 0;

        return in_array($code, [200, 204])
            ? ['ok' => true, 'message' => 'Reserved IP detached.']
            : ['ok' => false, 'error' => $this->errMsg($r, 'Detach failed.')];
    }

    public function delete_floating_ip(array $payload): array
    {
        $ip_id = trim($payload['ip_id'] ?? $payload['floating_ip_id'] ?? '');
        if (!$ip_id) return ['ok' => false, 'error' => 'Reserved IP ID required.'];

        // Detach first
        try {
            $this->cloud->catalog->http_post("/reserved-ips/{$ip_id}/detach");
        } catch (Throwable $e) {}

        $r    = $this->cloud->catalog->http_delete("/reserved-ips/{$ip_id}");
        $code = $r['_http_status'] ?? 0;

        return in_array($code, [200, 204])
            ? ['ok' => true, 'message' => 'Reserved IP deleted.']
            : ['ok' => false, 'error' => $this->errMsg($r, 'Delete failed.')];
    }

    // ── Private Networks ──────────────────────────────────────

    public function list_networks(): array
    {
        $r = $this->cloud->catalog->http_get('/private-networks', ['per_page' => 100]);
        $nets = [];

        foreach ($r['private_networks'] ?? [] as $n) {
            // Check if this instance is in the network (need per-instance endpoint)
            $nets[] = [
                'ip'          => null,
                'network_id'  => $n['id'],
                'mac_address' => null,
                'network'     => ['name' => $n['description'] ?? $n['id']],
            ];
        }

        return ['ok' => true, 'networks' => $nets];
    }

    public function list_all_networks(): array
    {
        return $this->list_networks();
    }

    public function create_network(array $payload): array
    {
        $desc   = trim($payload['network_name'] ?? ('vpc-' . date('Ymd')));
        $region = $this->server['region_slug'] ?? 'ewr';

        $r    = $this->cloud->catalog->http_post('/private-networks', [
            'region'      => $region,
            'description' => $desc,
        ]);
        $code = $r['_http_status'] ?? 0;

        if (in_array($code, [200, 201]) && !empty($r['network']['id'])) {
            $net_id = $r['network']['id'];
            // Attach to instance
            $this->cloud->catalog->http_post('/instances/' . $this->vid . '/private-networks/attach', [
                'network_id' => $net_id,
            ]);
            return ['ok' => true, 'message' => "Private network '{$desc}' created and attached."];
        }
        return ['ok' => false, 'error' => $this->errMsg($r, 'Network creation failed.')];
    }

    public function attach_network(array $payload): array
    {
        $net_id = trim($payload['network_id'] ?? '');
        if (!$net_id) return ['ok' => false, 'error' => 'Network ID required.'];

        $r    = $this->cloud->catalog->http_post('/instances/' . $this->vid . '/private-networks/attach', [
            'network_id' => $net_id,
        ]);
        $code = $r['_http_status'] ?? 0;

        return in_array($code, [200, 204])
            ? ['ok' => true, 'message' => 'Network attached.']
            : ['ok' => false, 'error' => $this->errMsg($r, 'Attach failed.')];
    }

    public function detach_network(array $payload): array
    {
        $net_id = trim($payload['network_id'] ?? '');
        if (!$net_id) return ['ok' => false, 'error' => 'Network ID required.'];

        $r    = $this->cloud->catalog->http_post('/instances/' . $this->vid . '/private-networks/detach', [
            'network_id' => $net_id,
        ]);
        $code = $r['_http_status'] ?? 0;

        return in_array($code, [200, 204])
            ? ['ok' => true, 'message' => 'Network detached.']
            : ['ok' => false, 'error' => $this->errMsg($r, 'Detach failed.')];
    }

    // ── Console (VNC) ─────────────────────────────────────────

    public function get_console(): array
    {
        $r    = $this->cloud->catalog->http_get('/instances/' . $this->vid . '/vnc');
        $code = $r['_http_status'] ?? 0;
        $vnc  = $r['vnc'] ?? null;

        if ($code === 200 && !empty($vnc['url'])) {
            return ['ok' => true, 'url' => $vnc['url'], 'password' => null];
        }
        return ['ok' => false, 'error' => $this->errMsg($r, 'Console URL not available. Server must be running.')];
    }

    // ── Delete ────────────────────────────────────────────────

    public function delete_server(): array
    {
        $r    = $this->cloud->catalog->http_delete('/instances/' . $this->vid);
        $code = $r['_http_status'] ?? 0;

        if (in_array($code, [200, 204])) return ['ok' => true, 'message' => 'Server deleted from Vultr.'];
        if ($code === 404)               return ['ok' => true, 'message' => 'Server already deleted.'];

        return ['ok' => false, 'error' => $this->errMsg($r, 'Delete failed.')];
    }

    // ── Private helpers ───────────────────────────────────────

    private function ok(array $r, string $msg): array
    {
        $code = $r['_http_status'] ?? 0;
        if (in_array($code, [200, 201, 202, 204])) {
            return ['ok' => true, 'message' => $msg];
        }
        return ['ok' => false, 'error' => $this->errMsg($r, 'Action failed.')];
    }

    private function errMsg(array $r, string $fallback): string
    {
        return $r['error'] ?? $r['message'] ?? $fallback;
    }

    private function mapVolume(array $v): array
    {
        return [
            'id'           => $v['id']          ?? null,
            'name'         => $v['label']        ?? '',
            'size'         => $v['size_gb']      ?? 0,
            'status'       => $v['status']       ?? '',
            'linux_device' => '/dev/vdb',
            'location'     => ['name' => $v['region'] ?? ''],
        ];
    }
}
