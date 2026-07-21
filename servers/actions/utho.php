<?php
/**
 * servers/actions/utho.php
 *
 * Utho-specific server action handler.
 * Called by api/server-action.php when provider_type = 'utho'.
 *
 * Class: UthoActions (panel handler)
 * Constructor: ($cloud, $server)
 *
 * All methods return: ['ok'=>bool, 'message'=>string, ...]
 *
 * Panel action → Utho API mapping:
 *   start               → POST /cloudinstances/{id}/power/start
 *   stop                → POST /cloudinstances/{id}/power/stop
 *   shutdown            → POST /cloudinstances/{id}/power/stop
 *   reboot              → POST /cloudinstances/{id}/power/restart
 *   reset               → POST /cloudinstances/{id}/power/forcestop
 *   rebuild             → POST /cloudinstances/{id}/rebuild
 *   reset_root_password → POST /cloudinstances/{id}/resetpassword
 *   enable_rescue       → POST /cloudinstances/{id}/rescue
 *   create_snapshot     → POST /cloudinstances/{id}/snapshot
 *   list_snapshots      → GET  /cloudinstances/{id}/snapshots
 *   delete_snapshot     → DELETE /cloudinstances/{id}/snapshots/{sid}
 *   list_volumes        → GET  /cloudinstances/{id}/volumes
 *   create_volume       → POST /volumes
 *   attach_volume       → POST /volumes/{vid}/attach
 *   detach_volume       → POST /volumes/{vid}/detach
 *   delete_volume       → DELETE /volumes/{vid}
 *   list_firewalls      → GET  /cloudinstances/{id}/firewalls
 *   apply_firewall      → POST /firewall/{fid}/instances
 *   remove_firewall     → DELETE /firewall/{fid}/instances/{id}
 *   get_console         → GET  /cloudinstances/{id}/console
 *   delete              → DELETE /cloudinstances/{id}
 *   list_floating_ips   → (not supported — Utho uses static IPs)
 *   list_networks       → GET  /cloudinstances/{id}/ips
 */

declare(strict_types=1);

class UthoActions
{
    private object $cloud;   // CloudProvider (utho)
    private array  $server;  // DB row
    private int    $uid;     // Utho cloudid (provider_id in DB)

    public function __construct(object $cloud, array $server)
    {
        $this->cloud  = $cloud;
        $this->server = $server;
        $this->uid    = (int)($server['provider_id'] ?? 0);
    }

    // ── Power ─────────────────────────────────────────────────

    public function start(): array
    {
        $r = $this->cloud->catalog->http_post('/cloudinstances/' . $this->uid . '/power/start');
        return $this->ok($r, 'Server is starting.');
    }

    public function stop(): array
    {
        $r = $this->cloud->catalog->http_post('/cloudinstances/' . $this->uid . '/power/stop');
        return $this->ok($r, 'Shutdown signal sent.');
    }

    public function shutdown(): array
    {
        return $this->stop();
    }

    public function reboot(): array
    {
        $r = $this->cloud->catalog->http_post('/cloudinstances/' . $this->uid . '/power/restart');
        return $this->ok($r, 'Server is rebooting.');
    }

    public function reset(): array
    {
        $r = $this->cloud->catalog->http_post('/cloudinstances/' . $this->uid . '/power/forcestop');
        return $this->ok($r, 'Server force-stopped.');
    }

    // ── Rescue ────────────────────────────────────────────────

    public function enable_rescue(array $payload = []): array
    {
        $r = $this->cloud->catalog->http_post('/cloudinstances/' . $this->uid . '/rescue');
        return $this->ok($r, 'Rescue mode enabled. Reboot to activate.');
    }

    public function enable_rescue_cycle(array $payload = []): array
    {
        $res = $this->enable_rescue($payload);
        if (!$res['ok']) return $res;
        $this->reboot();
        return ['ok' => true, 'message' => 'Rescue enabled and server rebooted.'];
    }

    // ── Password ──────────────────────────────────────────────

    public function reset_root_password(): array
    {
        $r = $this->cloud->catalog->http_post('/cloudinstances/' . $this->uid . '/resetpassword');
        if (UthoClient::isOk($r)) {
            $pass = $r['password'] ?? $r['rootPassword'] ?? null;
            return ['ok' => true, 'message' => 'Root password reset.', 'root_password' => $pass];
        }
        return ['ok' => false, 'error' => UthoClient::errMsg($r, 'Password reset failed.')];
    }

    // ── Rebuild ───────────────────────────────────────────────

    public function rebuild(array $payload): array
    {
        $image = trim($payload['image'] ?? '');
        if (!$image) return ['ok' => false, 'error' => 'Image is required.'];

        $r = $this->cloud->catalog->http_post('/cloudinstances/' . $this->uid . '/rebuild', [
            'image' => $image,
        ]);

        if (UthoClient::isOk($r)) {
            $pass = $r['password'] ?? $r['rootPassword'] ?? null;
            return ['ok' => true, 'message' => 'Server rebuild started.', 'root_password' => $pass];
        }
        return ['ok' => false, 'error' => UthoClient::errMsg($r, 'Rebuild failed.')];
    }

    // ── Snapshots ─────────────────────────────────────────────

    public function create_snapshot(array $payload = []): array
    {
        $label = trim($payload['description'] ?? ('snapshot-' . date('Ymd-Hi')));
        $r = $this->cloud->catalog->http_post('/cloudinstances/' . $this->uid . '/snapshot', [
            'snapshotname' => $label,
        ]);
        return $this->ok($r, "Snapshot '{$label}' created.");
    }

    public function list_snapshots(): array
    {
        $r = $this->cloud->catalog->http_get('/cloudinstances/' . $this->uid . '/snapshots');
        $snaps = array_map(fn($s) => [
            'id'          => $s['id']           ?? $s['snapshotid'] ?? null,
            'description' => $s['snapshotname'] ?? $s['name']       ?? 'Snapshot',
            'created'     => $s['created_at']   ?? null,
            'image_size'  => $s['size']          ?? null,
            'status'      => 'available',
        ], $r['snapshots'] ?? $r['data'] ?? []);
        return ['ok' => true, 'snapshots' => $snaps];
    }

    public function delete_snapshot(array $payload): array
    {
        $snap_id = (int)($payload['image_id'] ?? $payload['snapshot_id'] ?? 0);
        if (!$snap_id) return ['ok' => false, 'error' => 'Snapshot ID required.'];
        $r = $this->cloud->catalog->http_delete('/cloudinstances/' . $this->uid . '/snapshots/' . $snap_id);
        return UthoClient::isOk($r)
            ? ['ok' => true, 'message' => 'Snapshot deleted.']
            : ['ok' => false, 'error' => UthoClient::errMsg($r, 'Delete failed.')];
    }

    // ── Volumes ───────────────────────────────────────────────

    public function list_volumes(): array
    {
        $r    = $this->cloud->catalog->http_get('/cloudinstances/' . $this->uid . '/volumes');
        $vols = array_map(fn($v) => $this->mapVolume($v), $r['volumes'] ?? $r['data'] ?? []);
        return ['ok' => true, 'volumes' => $vols];
    }

    public function create_volume(array $payload): array
    {
        $name    = trim($payload['volume_name'] ?? '');
        $size_gb = (int)($payload['size_gb']    ?? 20);
        $dc      = $this->server['region_slug'] ?? 'innoida';

        if (!$name)   return ['ok' => false, 'error' => 'Volume name required.'];
        if ($size_gb < 1) return ['ok' => false, 'error' => 'Invalid size.'];

        $r = $this->cloud->catalog->http_post('/volumes', [
            'name'            => $name,
            'size'            => $size_gb,
            'dcslug'          => $dc,
            'cloudinstanceid' => $this->uid,
        ]);

        if (UthoClient::isOk($r)) {
            return ['ok' => true, 'message' => "Volume '{$name}' created and attached.", 'volume' => $this->mapVolume($r['volume'] ?? $r)];
        }
        return ['ok' => false, 'error' => UthoClient::errMsg($r, 'Volume creation failed.')];
    }

    public function attach_volume(array $payload): array
    {
        $vol_id = (int)($payload['volume_id'] ?? 0);
        if (!$vol_id) return ['ok' => false, 'error' => 'Volume ID required.'];
        $r = $this->cloud->catalog->http_post('/volumes/' . $vol_id . '/attach', [
            'cloudinstanceid' => $this->uid,
        ]);
        return $this->ok($r, 'Volume attached.');
    }

    public function detach_volume(array $payload): array
    {
        $vol_id = (int)($payload['volume_id'] ?? 0);
        if (!$vol_id) return ['ok' => false, 'error' => 'Volume ID required.'];
        $r = $this->cloud->catalog->http_post('/volumes/' . $vol_id . '/detach');
        return $this->ok($r, 'Volume detached.');
    }

    public function resize_volume(array $payload): array
    {
        $vol_id  = (int)($payload['volume_id'] ?? 0);
        $size_gb = (int)($payload['size_gb']   ?? 0);
        if (!$vol_id || $size_gb < 1) return ['ok' => false, 'error' => 'Invalid volume ID or size.'];
        $r = $this->cloud->catalog->http_post('/volumes/' . $vol_id . '/resize', ['size' => $size_gb]);
        return $this->ok($r, 'Volume resize started.');
    }

    public function delete_volume(array $payload): array
    {
        $vol_id = (int)($payload['volume_id'] ?? 0);
        if (!$vol_id) return ['ok' => false, 'error' => 'Volume ID required.'];
        $this->cloud->catalog->http_post('/volumes/' . $vol_id . '/detach');
        $r = $this->cloud->catalog->http_delete('/volumes/' . $vol_id);
        return UthoClient::isOk($r)
            ? ['ok' => true, 'message' => 'Volume deleted.']
            : ['ok' => false, 'error' => UthoClient::errMsg($r, 'Delete failed.')];
    }

    // ── Firewalls ─────────────────────────────────────────────

    public function list_server_firewalls(): array
    {
        $r  = $this->cloud->catalog->http_get('/cloudinstances/' . $this->uid . '/firewalls');
        $fw = array_map(fn($f) => [
            'id'     => $f['id']   ?? null,
            'name'   => $f['name'] ?? '',
            'rules'  => [],
            'status' => 'applied',
        ], $r['firewalls'] ?? $r['data'] ?? []);
        return ['ok' => true, 'firewalls' => $fw];
    }

    public function apply_firewall(array $payload): array
    {
        $fw_id = (int)($payload['firewall_id'] ?? 0);
        if (!$fw_id) return ['ok' => false, 'error' => 'Firewall ID required.'];
        $r = $this->cloud->catalog->http_post('/firewall/' . $fw_id . '/instances', [
            'cloudinstanceid' => $this->uid,
        ]);
        return $this->ok($r, 'Firewall applied to server.');
    }

    public function remove_firewall(array $payload): array
    {
        $fw_id = (int)($payload['firewall_id'] ?? 0);
        if (!$fw_id) return ['ok' => false, 'error' => 'Firewall ID required.'];
        $r = $this->cloud->catalog->http_delete('/firewall/' . $fw_id . '/instances/' . $this->uid);
        return UthoClient::isOk($r)
            ? ['ok' => true, 'message' => 'Firewall removed from server.']
            : ['ok' => false, 'error' => UthoClient::errMsg($r, 'Remove failed.')];
    }

    // ── Floating IPs — Utho uses static IPs, no floating ─────

    public function list_floating_ips(): array
    {
        $r   = $this->cloud->catalog->http_get('/cloudinstances/' . $this->uid);
        $srv = $r['cloudinstances'][0] ?? $r['cloudinstance'] ?? [];
        $ips = [];
        if (!empty($srv['ip'])) {
            $ip = is_array($srv['ip']) ? $srv['ip'][0]['ipaddress'] ?? null : $srv['ip'];
            if ($ip) $ips[] = ['id' => $ip, 'ip' => $ip, 'type' => 'ipv4', 'home_location' => ['name' => $srv['dcslug'] ?? '']];
        }
        return ['ok' => true, 'assigned' => $ips, 'available' => []];
    }

    public function create_floating_ip(array $payload): array
    {
        return ['ok' => false, 'error' => 'Additional IPs on Utho require contacting support. Use Utho console to request additional IPs.'];
    }

    public function assign_floating_ip(array $payload): array
    {
        return ['ok' => false, 'error' => 'Floating IP management is handled via Utho console.'];
    }

    public function unassign_floating_ip(array $payload): array
    {
        return ['ok' => false, 'error' => 'Floating IP management is handled via Utho console.'];
    }

    public function delete_floating_ip(array $payload): array
    {
        return ['ok' => false, 'error' => 'Floating IP management is handled via Utho console.'];
    }

    // ── Networks ──────────────────────────────────────────────

    public function list_networks(): array
    {
        $r   = $this->cloud->catalog->http_get('/cloudinstances/' . $this->uid);
        $srv = $r['cloudinstances'][0] ?? $r['cloudinstance'] ?? [];
        $nets = [];
        if (!empty($srv['private_ip']) || !empty($srv['privateip'])) {
            $pip = $srv['private_ip'] ?? $srv['privateip'] ?? null;
            if ($pip) $nets[] = ['ip' => $pip, 'network_id' => null, 'mac_address' => null, 'network' => ['name' => 'Private Network']];
        }
        return ['ok' => true, 'networks' => $nets];
    }

    public function list_all_networks(): array
    {
        return ['ok' => true, 'networks' => []];
    }

    public function create_network(array $payload): array
    {
        return ['ok' => false, 'error' => 'Private network management is handled via Utho console.'];
    }

    public function attach_network(array $payload): array
    {
        return ['ok' => false, 'error' => 'Network management is handled via Utho console.'];
    }

    public function detach_network(array $payload): array
    {
        return ['ok' => false, 'error' => 'Network management is handled via Utho console.'];
    }

    // ── Console ───────────────────────────────────────────────

    public function get_console(): array
    {
        $r = $this->cloud->catalog->http_get('/cloudinstances/' . $this->uid . '/console');
        $url = $r['consoleurl'] ?? $r['url'] ?? $r['console_url'] ?? null;
        if (!$url && !empty($r['data']['url'])) $url = $r['data']['url'];

        if ($url) {
            return ['ok' => true, 'url' => $url, 'password' => $r['password'] ?? null];
        }
        return ['ok' => false, 'error' => UthoClient::errMsg($r, 'Console not available.')];
    }

    // ── Delete ────────────────────────────────────────────────

    public function delete_server(): array
    {
        $r    = $this->cloud->catalog->http_delete('/cloudinstances/' . $this->uid);
        $code = $r['_http_status'] ?? 0;
        if (UthoClient::isOk($r) || $code === 404) {
            return ['ok' => true, 'message' => 'Server deleted from provider.'];
        }
        return ['ok' => false, 'error' => UthoClient::errMsg($r, 'Delete failed.')];
    }

    // ── Private helpers ───────────────────────────────────────

    private function ok(array $r, string $msg): array
    {
        return UthoClient::isOk($r)
            ? ['ok' => true, 'message' => $msg]
            : ['ok' => false, 'error' => UthoClient::errMsg($r, 'Action failed.')];
    }

    private function mapVolume(array $v): array
    {
        return [
            'id'           => $v['id']       ?? null,
            'name'         => $v['name']     ?? '',
            'size'         => $v['size']     ?? 0,
            'status'       => $v['status']   ?? '',
            'linux_device' => $v['disk_path'] ?? '/dev/vdb',
            'location'     => ['name' => $v['dcslug'] ?? ''],
        ];
    }
}
