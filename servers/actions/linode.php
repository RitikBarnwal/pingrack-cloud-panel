<?php
/**
 * servers/actions/linode.php
 *
 * Linode-specific server action handler.
 * Called by api/server-action.php when provider_type = 'linode'.
 *
 * Class: LinodeActions
 * Constructor: ($cloud, $server)
 *   $cloud  — CloudProvider instance (from linode/bootstrap.php)
 *   $server — DB servers row array
 *
 * All methods return: ['ok'=>bool, 'message'=>string, ...]
 *
 * Panel actions → Linode API mapping:
 *   start               → POST /linode/instances/{id}/boot
 *   stop                → POST /linode/instances/{id}/shutdown
 *   shutdown            → POST /linode/instances/{id}/shutdown
 *   reboot              → POST /linode/instances/{id}/reboot
 *   reset               → POST /linode/instances/{id}/reboot (force)
 *   rebuild             → POST /linode/instances/{id}/rebuild
 *   reset_root_password → POST /linode/instances/{id}/password
 *   enable_rescue       → POST /linode/instances/{id}/rescue
 *   create_snapshot     → POST /linode/instances/{id}/backups
 *   list_snapshots      → GET  /linode/instances/{id}/backups
 *   delete_snapshot     → (not supported — mark locally)
 *   list_volumes        → GET  /volumes?linode_id={id}
 *   create_volume       → POST /volumes
 *   attach_volume       → POST /volumes/{id}/attach
 *   detach_volume       → POST /volumes/{id}/detach
 *   delete_volume       → DELETE /volumes/{id}
 *   list_firewalls      → GET  /linode/instances/{id}/firewalls
 *   apply_firewall      → POST /networking/firewalls/{id}/devices
 *   remove_firewall     → DELETE /networking/firewalls/{id}/devices/{device_id}
 *   list_floating_ips   → GET  /linode/instances/{id}/ips  (no real floating IPs)
 *   list_networks       → GET  /linode/instances/{id}/ips (private)
 *   get_console         → POST /linode/instances/{id}/lish_token
 *   delete              → DELETE /linode/instances/{id}
 */

declare(strict_types=1);

class LinodeActions
{
    private object $cloud;   // CloudProvider (linode)
    private array  $server;  // DB row
    private int    $lid;     // Linode instance ID (provider_id in DB)

    public function __construct(object $cloud, array $server)
    {
        $this->cloud  = $cloud;
        $this->server = $server;
        $this->lid    = (int)($server['provider_id'] ?? 0);
    }

    // ── Power ─────────────────────────────────────────────────

    public function start(): array
    {
        $r = $this->cloud->catalog->http_post('/linode/instances/' . $this->lid . '/boot');
        return $this->ok($r, 'Server is booting up.');
    }

    public function stop(): array
    {
        $r = $this->cloud->catalog->http_post('/linode/instances/' . $this->lid . '/shutdown');
        return $this->ok($r, 'Shutdown signal sent to server.');
    }

    public function shutdown(): array
    {
        return $this->stop();
    }

    public function reboot(): array
    {
        $r = $this->cloud->catalog->http_post('/linode/instances/' . $this->lid . '/reboot');
        return $this->ok($r, 'Server is rebooting.');
    }

    public function reset(): array
    {
        // Linode has no hard reset separate from reboot
        return $this->reboot();
    }

    // ── Rescue ────────────────────────────────────────────────

    public function enable_rescue(array $payload = []): array
    {
        // Linode rescue boots into Finnix (Linux rescue distro)
        $r = $this->cloud->catalog->http_post('/linode/instances/' . $this->lid . '/rescue', [
            'devices' => (object)[], // use default disk layout
        ]);
        return $this->ok($r, 'Rescue boot initiated. Server will boot into Finnix rescue environment. Use Lish/Weblish console to access.');
    }

    public function enable_rescue_cycle(array $payload = []): array
    {
        return $this->enable_rescue($payload);
    }

    // ── Password ──────────────────────────────────────────────

    public function reset_root_password(): array
    {
        // Generate secure password
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
        $pass  = '';
        for ($i = 0; $i < 20; $i++) {
            $pass .= $chars[random_int(0, strlen($chars) - 1)];
        }

        $r = $this->cloud->catalog->http_post('/linode/instances/' . $this->lid . '/password', [
            'password' => $pass,
        ]);

        $code = $r['_http_status'] ?? 0;
        if (in_array($code, [200, 204]) && empty($r['errors'])) {
            return ['ok' => true, 'message' => 'Root password reset. Server must be rebooted for new password to take effect.', 'root_password' => $pass];
        }
        return ['ok' => false, 'error' => $this->errMsg($r, 'Password reset failed. Server must be offline first.')];
    }

    // ── Rebuild ───────────────────────────────────────────────

    public function rebuild(array $payload): array
    {
        $image = trim($payload['image'] ?? '');
        if (!$image) return ['ok' => false, 'error' => 'Image is required.'];

        // Generate root password
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
        $pass  = '';
        for ($i = 0; $i < 20; $i++) {
            $pass .= $chars[random_int(0, strlen($chars) - 1)];
        }

        $r = $this->cloud->catalog->http_post('/linode/instances/' . $this->lid . '/rebuild', [
            'image'     => $image,
            'root_pass' => $pass,
            'booted'    => true,
        ]);

        $code = $r['_http_status'] ?? 0;
        if (in_array($code, [200, 201, 202]) && !empty($r['id'])) {
            return ['ok' => true, 'message' => 'Server rebuild started.', 'root_password' => $pass];
        }
        return ['ok' => false, 'error' => $this->errMsg($r, 'Rebuild failed.')];
    }

    // ── Snapshots ─────────────────────────────────────────────

    public function create_snapshot(array $payload = []): array
    {
        $label = trim($payload['description'] ?? ('snapshot-' . date('Ymd-Hi')));
        $r = $this->cloud->catalog->http_post('/linode/instances/' . $this->lid . '/backups', [
            'label' => $label,
        ]);

        if (!empty($r['id'])) {
            return ['ok' => true, 'message' => "Snapshot '{$label}' created."];
        }
        // Backups may not be enabled
        return ['ok' => false, 'error' => $this->errMsg($r, 'Snapshot failed. Ensure backups are enabled for this server.')];
    }

    public function list_snapshots(): array
    {
        $r = $this->cloud->catalog->http_get('/linode/instances/' . $this->lid . '/backups');
        $snaps = [];

        foreach ($r['automatic'] ?? [] as $b) {
            $snaps[] = $this->mapBackup($b, 'automatic');
        }
        $current = $r['snapshot']['current'] ?? null;
        if ($current) $snaps[] = $this->mapBackup($current, 'snapshot');

        return ['ok' => true, 'snapshots' => $snaps];
    }

    public function delete_snapshot(array $payload): array
    {
        // Linode doesn't allow deleting individual automatic backups
        // Manual snapshot can't be deleted via API directly
        return ['ok' => false, 'error' => 'Linode does not support individual backup deletion via API. Disable backups to remove all backups.'];
    }

    // ── Volumes ───────────────────────────────────────────────

    public function list_volumes(): array
    {
        $r    = $this->cloud->catalog->http_get('/volumes', ['page_size' => 100]);
        $vols = array_filter($r['data'] ?? [], fn($v) => (int)($v['linode_id'] ?? 0) === $this->lid);

        return [
            'ok'      => true,
            'volumes' => array_values(array_map([$this, 'mapVolume'], $vols)),
        ];
    }

    public function create_volume(array $payload): array
    {
        $name    = trim($payload['volume_name'] ?? '');
        $size_gb = (int)($payload['size_gb'] ?? 20);
        $region  = $this->server['region_slug'] ?? 'us-east';

        if (!$name) return ['ok' => false, 'error' => 'Volume label required.'];
        if ($size_gb < 20 || $size_gb > 10240) return ['ok' => false, 'error' => 'Size must be 20–10240 GB.'];

        $r = $this->cloud->catalog->http_post('/volumes', [
            'label'     => $name,
            'size'      => $size_gb,
            'linode_id' => $this->lid,
            'region'    => $region,
        ]);

        if (!empty($r['id'])) {
            return ['ok' => true, 'message' => "Volume '{$name}' created and attached.", 'volume' => $this->mapVolume($r)];
        }
        return ['ok' => false, 'error' => $this->errMsg($r, 'Volume creation failed.')];
    }

    public function attach_volume(array $payload): array
    {
        $vol_id = (int)($payload['volume_id'] ?? 0);
        if (!$vol_id) return ['ok' => false, 'error' => 'Volume ID required.'];

        $r = $this->cloud->catalog->http_post('/volumes/' . $vol_id . '/attach', [
            'linode_id' => $this->lid,
        ]);
        $code = $r['_http_status'] ?? 0;
        return in_array($code, [200, 204]) && empty($r['errors'])
            ? ['ok' => true, 'message' => 'Volume attached.']
            : ['ok' => false, 'error' => $this->errMsg($r, 'Attach failed.')];
    }

    public function detach_volume(array $payload): array
    {
        $vol_id = (int)($payload['volume_id'] ?? 0);
        if (!$vol_id) return ['ok' => false, 'error' => 'Volume ID required.'];

        $r = $this->cloud->catalog->http_post('/volumes/' . $vol_id . '/detach');
        $code = $r['_http_status'] ?? 0;
        return in_array($code, [200, 204]) && empty($r['errors'])
            ? ['ok' => true, 'message' => 'Volume detached.']
            : ['ok' => false, 'error' => $this->errMsg($r, 'Detach failed.')];
    }

    public function resize_volume(array $payload): array
    {
        $vol_id  = (int)($payload['volume_id'] ?? 0);
        $size_gb = (int)($payload['size_gb']   ?? 0);
        if (!$vol_id || $size_gb < 20) return ['ok' => false, 'error' => 'Invalid volume ID or size.'];

        $r = $this->cloud->catalog->http_post('/volumes/' . $vol_id . '/resize', ['size' => $size_gb]);
        $code = $r['_http_status'] ?? 0;
        return in_array($code, [200, 204]) && empty($r['errors'])
            ? ['ok' => true, 'message' => 'Volume resize started.']
            : ['ok' => false, 'error' => $this->errMsg($r, 'Resize failed.')];
    }

    public function delete_volume(array $payload): array
    {
        $vol_id = (int)($payload['volume_id'] ?? 0);
        if (!$vol_id) return ['ok' => false, 'error' => 'Volume ID required.'];

        // Detach first
        $this->cloud->catalog->http_post('/volumes/' . $vol_id . '/detach');
        sleep(1);
        $r = $this->cloud->catalog->http_delete('/volumes/' . $vol_id);
        return ($r['_http_status'] ?? 0) === 204
            ? ['ok' => true, 'message' => 'Volume deleted.']
            : ['ok' => false, 'error' => $this->errMsg($r, 'Delete failed.')];
    }

    // ── Firewalls ─────────────────────────────────────────────

    public function list_server_firewalls(): array
    {
        $r  = $this->cloud->catalog->http_get('/linode/instances/' . $this->lid . '/firewalls');
        $fw = array_map(fn($f) => [
            'id'     => $f['id'],
            'name'   => $f['label'],
            'rules'  => [],
            'status' => $f['status'] ?? 'enabled',
        ], $r['data'] ?? []);
        return ['ok' => true, 'firewalls' => $fw];
    }

    public function apply_firewall(array $payload): array
    {
        $fw_id = (int)($payload['firewall_id'] ?? 0);
        if (!$fw_id) return ['ok' => false, 'error' => 'Firewall ID required.'];

        $r = $this->cloud->catalog->http_post('/networking/firewalls/' . $fw_id . '/devices', [
            'id'   => $this->lid,
            'type' => 'linode',
        ]);

        $code = $r['_http_status'] ?? 0;
        return in_array($code, [200, 201]) && !empty($r['id'])
            ? ['ok' => true, 'message' => 'Firewall applied to server.']
            : ['ok' => false, 'error' => $this->errMsg($r, 'Apply failed.')];
    }

    public function remove_firewall(array $payload): array
    {
        $fw_id = (int)($payload['firewall_id'] ?? 0);
        if (!$fw_id) return ['ok' => false, 'error' => 'Firewall ID required.'];

        // Find device ID for this linode
        $r = $this->cloud->catalog->http_get('/networking/firewalls/' . $fw_id . '/devices');
        foreach ($r['data'] ?? [] as $device) {
            if ((int)($device['entity']['id'] ?? 0) === $this->lid) {
                $del = $this->cloud->catalog->http_delete('/networking/firewalls/' . $fw_id . '/devices/' . $device['id']);
                return ($del['_http_status'] ?? 0) === 204
                    ? ['ok' => true, 'message' => 'Firewall removed from server.']
                    : ['ok' => false, 'error' => 'Could not remove firewall device.'];
            }
        }
        return ['ok' => false, 'error' => 'This firewall is not applied to this server.'];
    }

    // ── Floating IPs — Linode uses shared IPs, no true floating ─

    public function list_floating_ips(): array
    {
        $r = $this->cloud->catalog->http_get('/linode/instances/' . $this->lid . '/ips');
        $public = $r['ipv4']['public'] ?? [];

        // Format to match Hetzner panel expectations
        $assigned  = [];
        $available = [];

        foreach ($public as $ip) {
            $assigned[] = [
                'id'            => $ip['address'] ?? null,
                'ip'            => $ip['address'] ?? null,
                'type'          => 'ipv4',
                'home_location' => ['name' => $this->server['region_slug'] ?? ''],
            ];
        }

        return ['ok' => true, 'assigned' => $assigned, 'available' => $available];
    }

    public function create_floating_ip(array $payload): array
    {
        // Linode additional public IPs require a support ticket / are not API-provisionable freely
        return ['ok' => false, 'error' => 'Additional public IPs on Linode require opening a support ticket. Use Linode Manager to request additional IPs.'];
    }

    public function assign_floating_ip(array $payload): array
    {
        return ['ok' => false, 'error' => 'Floating IP assignment is not supported on Linode. Use IP sharing via Linode Manager.'];
    }

    public function unassign_floating_ip(array $payload): array
    {
        return ['ok' => false, 'error' => 'Floating IP management is not supported on Linode via this panel.'];
    }

    public function delete_floating_ip(array $payload): array
    {
        return ['ok' => false, 'error' => 'Floating IP deletion is not supported on Linode via this panel.'];
    }

    // ── Networks / Private IPs ────────────────────────────────

    public function list_networks(): array
    {
        $r       = $this->cloud->catalog->http_get('/linode/instances/' . $this->lid . '/ips');
        $private = $r['ipv4']['private'] ?? [];

        $networks = array_map(fn($ip) => [
            'ip'         => $ip['address'] ?? null,
            'network_id' => null,
            'mac_address'=> null,
            'network'    => ['name' => 'Private Network'],
        ], $private);

        return ['ok' => true, 'networks' => $networks];
    }

    public function list_all_networks(): array
    {
        return ['ok' => true, 'networks' => []]; // Linode uses VLAN, not traditional networks
    }

    public function create_network(array $payload): array
    {
        // Add private IP to linode
        $r = $this->cloud->catalog->http_post('/linode/instances/' . $this->lid . '/ips', [
            'type'   => 'ipv4',
            'public' => false,
        ]);
        if (!empty($r['address'])) {
            return ['ok' => true, 'message' => 'Private IP added: ' . $r['address']];
        }
        return ['ok' => false, 'error' => $this->errMsg($r, 'Could not add private IP.')];
    }

    public function attach_network(array $payload): array
    {
        return ['ok' => false, 'error' => 'Network attach is managed through private IPs on Linode.'];
    }

    public function detach_network(array $payload): array
    {
        return ['ok' => false, 'error' => 'Network detach is managed through private IPs on Linode.'];
    }

    // ── Console (Weblish) ─────────────────────────────────────

    public function get_console(): array
    {
        $r = $this->cloud->catalog->http_post('/linode/instances/' . $this->lid . '/lish_token');

        if (!empty($r['lish_token'])) {
            $token = $r['lish_token'];
            // Weblish URL — opens in browser popup
            $url = 'https://weblish.linode.com/?token=' . urlencode($token);
            return ['ok' => true, 'url' => $url, 'password' => null];
        }
        return ['ok' => false, 'error' => $this->errMsg($r, 'Console not available.')];
    }

    // ── Delete ────────────────────────────────────────────────

    public function delete_server(): array
    {
        $r = $this->cloud->catalog->http_delete('/linode/instances/' . $this->lid);
        $code = $r['_http_status'] ?? 0;

        if ($code === 200) return ['ok' => true, 'message' => 'Server deleted from provider.'];
        if ($code === 404) return ['ok' => true, 'message' => 'Server deleted.'];

        return ['ok' => false, 'error' => $this->errMsg($r, 'Delete failed.')];
    }

    // ── Private helpers ───────────────────────────────────────

    private function ok(array $r, string $msg): array
    {
        $code = $r['_http_status'] ?? 0;
        if (in_array($code, [200, 201, 204]) && empty($r['errors'])) {
            return ['ok' => true, 'message' => $msg];
        }
        return ['ok' => false, 'error' => $this->errMsg($r, 'Action failed.')];
    }

    private function errMsg(array $r, string $fallback): string
    {
        if (!empty($r['errors']) && is_array($r['errors'])) {
            $msgs = array_map(fn($e) => trim((($e['field'] ?? '') ? $e['field'] . ': ' : '') . ($e['reason'] ?? '')), $r['errors']);
            return implode(' | ', $msgs);
        }
        return $r['error'] ?? $fallback;
    }

    private function mapVolume(array $v): array
    {
        return [
            'id'           => $v['id']              ?? null,
            'name'         => $v['label']            ?? '',
            'size'         => $v['size']             ?? 0,
            'status'       => $v['status']           ?? '',
            'linux_device' => $v['filesystem_path']  ?? '/dev/disk/by-id/...',
            'location'     => ['name' => $v['region'] ?? ''],
        ];
    }

    private function mapBackup(array $b, string $type): array
    {
        return [
            'id'          => $b['id']      ?? null,
            'description' => $b['label']   ?? ($type === 'automatic' ? 'Automatic backup' : 'Snapshot'),
            'created'     => $b['created'] ?? null,
            'image_size'  => isset($b['size']) ? round($b['size'] / 1024, 1) : null, // MB→GB
            'status'      => $b['status']  ?? 'unknown',
            'type'        => $type,
        ];
    }
}
