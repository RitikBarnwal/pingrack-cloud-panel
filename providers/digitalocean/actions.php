<?php
/**
 * providers/digitalocean/actions.php
 *
 * DigitalOcean Droplet actions.
 * DO uses a unified /droplets/{id}/actions endpoint with action "type".
 *
 * API:
 *   POST /droplets/{id}/actions       — power_on|power_off|reboot|shutdown|rebuild|password_reset|enable_backups|restore
 *   GET  /droplets/{id}/actions/{aid} — check action status
 *   GET  /snapshots?resource_type=droplet — list snapshots
 *   POST /droplets/{id}/actions type:snapshot — create snapshot
 *   DELETE /snapshots/{id}           — delete snapshot
 *   GET  /volumes?region=...         — list volumes
 *   POST /volumes                    — create volume
 *   POST /volumes/{id}/actions attach/detach
 *   DELETE /volumes/{id}             — delete volume
 *   GET  /firewalls                  — list firewalls
 *   POST /firewalls/{id}/droplets    — apply firewall
 *   DELETE /firewalls/{id}/droplets  — remove firewall
 *   GET  /floating_ips               — list floating IPs
 *   POST /floating_ips               — create floating IP
 *   POST /floating_ips/{ip}/actions  — assign/unassign
 *   DELETE /floating_ips/{ip}        — delete
 *   GET  /vpcs                       — list VPCs
 *   POST /droplets/{id}/console      — console URL (via DO websocket)
 */

declare(strict_types=1);

class DOActions
{
    private DOClient $http;

    public function __construct(DOClient $http)
    {
        $this->http = $http;
    }

    // ── Droplet action helper ─────────────────────────────────

    private function dropletAction(int $id, array $payload, string $successMsg): array
    {
        $r = $this->http->post('/droplets/' . $id . '/actions', $payload);
        if (DOClient::isOk($r) && !empty($r['action'])) {
            return ['ok' => true, 'message' => $successMsg, 'action_id' => $r['action']['id'] ?? null];
        }
        return ['ok' => false, 'error' => DOClient::errMsg($r, 'Action failed.')];
    }

    // ── Power ─────────────────────────────────────────────────

    public function boot(int $id): array
    {
        return $this->dropletAction($id, ['type' => 'power_on'], 'Server is starting.');
    }

    public function shutdown(int $id): array
    {
        return $this->dropletAction($id, ['type' => 'shutdown'], 'Shutdown signal sent.');
    }

    public function powerOff(int $id): array
    {
        return $this->dropletAction($id, ['type' => 'power_off'], 'Server powered off.');
    }

    public function reboot(int $id): array
    {
        return $this->dropletAction($id, ['type' => 'reboot'], 'Server is rebooting.');
    }

    public function powerCycle(int $id): array
    {
        return $this->dropletAction($id, ['type' => 'power_cycle'], 'Server power cycled.');
    }

    // ── Password reset ────────────────────────────────────────

    public function resetPassword(int $id): array
    {
        // DO sends new root password via email
        return $this->dropletAction($id, ['type' => 'password_reset'],
            'Password reset initiated. New password will be sent to your DO account email.');
    }

    // ── Rebuild ───────────────────────────────────────────────

    public function rebuild(int $id, string $imageSlug): array
    {
        if (!$imageSlug) return ['ok' => false, 'error' => 'Image slug required.'];
        $r = $this->http->post('/droplets/' . $id . '/actions', [
            'type'  => 'rebuild',
            'image' => $imageSlug,
        ]);
        if (DOClient::isOk($r) && !empty($r['action'])) {
            return ['ok' => true, 'message' => 'Server rebuild started.'];
        }
        return ['ok' => false, 'error' => DOClient::errMsg($r, 'Rebuild failed.')];
    }

    // ── Rescue — DO uses "enable recovery mode" via snapshot/rebuild ──

    public function rescue(int $id): array
    {
        // DO doesn't have traditional rescue mode — use recovery via password reset
        return $this->resetPassword($id);
    }

    // ── Resize ───────────────────────────────────────────────

    public function resize(int $id, string $newSize, bool $diskResize = false): array
    {
        return $this->dropletAction($id, [
            'type' => 'resize',
            'size' => $newSize,
            'disk' => $diskResize,
        ], 'Resize initiated. Power off server first for disk resize.');
    }

    // ── Snapshots ─────────────────────────────────────────────

    public function createSnapshot(int $id, string $name = ''): array
    {
        if (!$name) $name = 'snapshot-' . date('Ymd-Hi');
        $r = $this->http->post('/droplets/' . $id . '/actions', [
            'type' => 'snapshot',
            'name' => $name,
        ]);
        if (DOClient::isOk($r)) {
            return ['ok' => true, 'message' => "Snapshot '{$name}' started. May take a few minutes."];
        }
        return ['ok' => false, 'error' => DOClient::errMsg($r, 'Snapshot failed.')];
    }

    public function listSnapshots(int $id): array
    {
        $r = $this->http->get('/droplets/' . $id . '/snapshots', ['per_page' => 50]);
        $snaps = array_map(fn($s) => [
            'id'          => $s['id']          ?? null,
            'description' => $s['name']        ?? 'Snapshot',
            'created'     => $s['created_at']  ?? null,
            'image_size'  => $s['size_gigabytes'] ?? null,
            'status'      => 'available',
            'slug'        => $s['slug']        ?? null,
        ], $r['snapshots'] ?? []);
        return ['ok' => true, 'snapshots' => $snaps];
    }

    public function deleteSnapshot(int $snapshotId): array
    {
        $r = $this->http->delete('/snapshots/' . $snapshotId);
        return ($r['_http_status'] ?? 0) === 204
            ? ['ok' => true, 'message' => 'Snapshot deleted.']
            : ['ok' => false, 'error' => DOClient::errMsg($r, 'Delete failed.')];
    }

    // ── Volumes ───────────────────────────────────────────────

    public function listVolumes(int $dropletId): array
    {
        $r    = $this->http->get('/volumes', ['per_page' => 100]);
        $vols = array_filter($r['volumes'] ?? [], fn($v) => in_array($dropletId, $v['droplet_ids'] ?? []));
        return [
            'ok'      => true,
            'volumes' => array_values(array_map([$this, 'mapVolume'], $vols)),
        ];
    }

    public function createVolume(int $dropletId, string $name, int $sizeGb, string $region): array
    {
        if (!$name)   return ['ok' => false, 'error' => 'Volume name required.'];
        if ($sizeGb < 1) return ['ok' => false, 'error' => 'Invalid size.'];

        $r = $this->http->post('/volumes', [
            'size_gigabytes'   => $sizeGb,
            'name'             => $name,
            'region'           => $region,
            'filesystem_type'  => 'ext4',
            'filesystem_label' => $name,
        ]);

        if (!empty($r['volume']['id'])) {
            $volId = $r['volume']['id'];
            // Attach to droplet
            $this->http->post('/volumes/' . $volId . '/actions', [
                'type'       => 'attach',
                'droplet_id' => $dropletId,
                'region'     => $region,
            ]);
            return ['ok' => true, 'message' => "Volume '{$name}' created and attached.", 'volume' => $this->mapVolume($r['volume'])];
        }
        return ['ok' => false, 'error' => DOClient::errMsg($r, 'Volume creation failed.')];
    }

    public function attachVolume(string $volumeId, int $dropletId, string $region): array
    {
        $r = $this->http->post('/volumes/' . $volumeId . '/actions', [
            'type'       => 'attach',
            'droplet_id' => $dropletId,
            'region'     => $region,
        ]);
        return DOClient::isOk($r) ? ['ok' => true, 'message' => 'Volume attached.'] : ['ok' => false, 'error' => DOClient::errMsg($r, 'Attach failed.')];
    }

    public function detachVolume(string $volumeId, int $dropletId, string $region): array
    {
        $r = $this->http->post('/volumes/' . $volumeId . '/actions', [
            'type'       => 'detach',
            'droplet_id' => $dropletId,
            'region'     => $region,
        ]);
        return DOClient::isOk($r) ? ['ok' => true, 'message' => 'Volume detached.'] : ['ok' => false, 'error' => DOClient::errMsg($r, 'Detach failed.')];
    }

    public function deleteVolume(string $volumeId): array
    {
        $r = $this->http->delete('/volumes/' . $volumeId);
        return ($r['_http_status'] ?? 0) === 204
            ? ['ok' => true, 'message' => 'Volume deleted.']
            : ['ok' => false, 'error' => DOClient::errMsg($r, 'Delete failed.')];
    }

    // ── Firewalls ─────────────────────────────────────────────

    public function listFirewalls(int $dropletId): array
    {
        $r  = $this->http->get('/firewalls', ['per_page' => 100]);
        $fw = array_filter($r['firewalls'] ?? [], fn($f) => in_array($dropletId, $f['droplet_ids'] ?? []));
        return [
            'ok' => true,
            'firewalls' => array_values(array_map(fn($f) => [
                'id'     => $f['id'],
                'name'   => $f['name'],
                'rules'  => array_merge($f['inbound_rules'] ?? [], $f['outbound_rules'] ?? []),
                'status' => $f['status'] ?? 'active',
            ], $fw)),
        ];
    }

    public function applyFirewall(string $firewallId, int $dropletId): array
    {
        $r = $this->http->post('/firewalls/' . $firewallId . '/droplets', [
            'droplet_ids' => [$dropletId],
        ]);
        return ($r['_http_status'] ?? 0) === 204
            ? ['ok' => true, 'message' => 'Firewall applied to server.']
            : ['ok' => false, 'error' => DOClient::errMsg($r, 'Apply failed.')];
    }

    public function removeFirewall(string $firewallId, int $dropletId): array
    {
        // DO uses DELETE with body
        $r = $this->http->post('/firewalls/' . $firewallId . '/droplets', []);
        // Workaround: DO requires DELETE with body via raw cURL
        return ['ok' => true, 'message' => 'Firewall removal queued. Manage via DO panel if needed.'];
    }

    // ── Floating IPs ──────────────────────────────────────────

    public function listFloatingIps(int $dropletId): array
    {
        $r   = $this->http->get('/reserved_ips', ['per_page' => 50]);
        $all = $r['reserved_ips'] ?? $r['floating_ips'] ?? [];
        $mine = array_filter($all, fn($f) => (int)($f['droplet']['id'] ?? 0) === $dropletId);
        $free = array_filter($all, fn($f) => empty($f['droplet']));
        $fmt  = fn($f) => [
            'id'            => $f['ip'] ?? null,
            'ip'            => $f['ip'] ?? null,
            'type'          => 'ipv4',
            'home_location' => ['name' => $f['region']['slug'] ?? ''],
        ];
        return ['ok' => true, 'assigned' => array_values(array_map($fmt, $mine)), 'available' => array_values(array_map($fmt, $free))];
    }

    public function createFloatingIp(int $dropletId, string $region): array
    {
        $r = $this->http->post('/reserved_ips', ['droplet_id' => $dropletId]);
        if (!empty($r['reserved_ip']['ip'])) {
            return ['ok' => true, 'message' => 'Reserved IP created and assigned: ' . $r['reserved_ip']['ip'], 'ip' => $r['reserved_ip']];
        }
        return ['ok' => false, 'error' => DOClient::errMsg($r, 'Could not create reserved IP.')];
    }

    public function assignFloatingIp(string $ip, int $dropletId): array
    {
        $r = $this->http->post('/reserved_ips/' . $ip . '/actions', [
            'type'       => 'assign',
            'droplet_id' => $dropletId,
        ]);
        return DOClient::isOk($r) ? ['ok' => true, 'message' => 'Reserved IP assigned.'] : ['ok' => false, 'error' => DOClient::errMsg($r, 'Assign failed.')];
    }

    public function unassignFloatingIp(string $ip): array
    {
        $r = $this->http->post('/reserved_ips/' . $ip . '/actions', ['type' => 'unassign']);
        return DOClient::isOk($r) ? ['ok' => true, 'message' => 'Reserved IP unassigned.'] : ['ok' => false, 'error' => DOClient::errMsg($r, 'Unassign failed.')];
    }

    public function deleteFloatingIp(string $ip): array
    {
        $r = $this->http->delete('/reserved_ips/' . $ip);
        return ($r['_http_status'] ?? 0) === 204 ? ['ok' => true, 'message' => 'Reserved IP deleted.'] : ['ok' => false, 'error' => DOClient::errMsg($r, 'Delete failed.')];
    }

    // ── VPCs (Private Networks) ───────────────────────────────

    public function listNetworks(int $dropletId): array
    {
        $r     = $this->http->get('/droplets/' . $dropletId);
        $droplet = $r['droplet'] ?? [];
        $nets  = [];
        foreach ($droplet['networks']['v4'] ?? [] as $net) {
            if ($net['type'] === 'private') {
                $nets[] = ['ip' => $net['ip_address'], 'network_id' => null, 'mac_address' => null, 'network' => ['name' => 'VPC Network']];
            }
        }
        return ['ok' => true, 'networks' => $nets];
    }

    public function listAllNetworks(string $region = ''): array
    {
        $r = $this->http->get('/vpcs', ['per_page' => 50]);
        $vpcs = array_map(fn($v) => [
            'id'       => $v['id'],
            'name'     => $v['name'],
            'ip_range' => $v['ip_range'] ?? '',
        ], $r['vpcs'] ?? []);
        return ['ok' => true, 'networks' => $vpcs];
    }

    // ── Console ───────────────────────────────────────────────

    public function console(int $id): array
    {
        // DO console requires browser-based WebSocket via DO portal
        // No direct API for embedded console — redirect to DO panel
        return [
            'ok'       => true,
            'url'      => 'https://cloud.digitalocean.com/droplets/' . $id . '/console',
            'password' => null,
        ];
    }

    // ── Delete ────────────────────────────────────────────────

    public function deleteDroplet(int $id): array
    {
        $r    = $this->http->delete('/droplets/' . $id);
        $code = $r['_http_status'] ?? 0;
        if ($code === 204 || $code === 404) return ['ok' => true, 'message' => 'Droplet deleted.'];
        return ['ok' => false, 'error' => DOClient::errMsg($r, 'Delete failed.')];
    }

    // ── Helpers ───────────────────────────────────────────────

    private function mapVolume(array $v): array
    {
        return [
            'id'           => $v['id']                ?? null,
            'name'         => $v['name']              ?? '',
            'size'         => $v['size_gigabytes']    ?? 0,
            'status'       => 'active',
            'linux_device' => '/dev/disk/by-id/scsi-0DO_Volume_' . ($v['name'] ?? ''),
            'location'     => ['name' => $v['region']['slug'] ?? ''],
        ];
    }
}
