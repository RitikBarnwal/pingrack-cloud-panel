<?php
/**
 * providers/contabo/actions.php
 *
 * Contabo VPS action operations.
 *
 * API endpoints:
 *   POST /compute/instances/{id}/actions/start      — boot
 *   POST /compute/instances/{id}/actions/stop       — shutdown
 *   POST /compute/instances/{id}/actions/restart    — reboot
 *   POST /compute/instances/{id}/actions/rescue     — rescue
 *   POST /compute/instances/{id}/actions/resetPasswordRequest — reset pass
 *   POST /compute/instances/{id}/actions/reinstall  — rebuild
 *   GET  /compute/instances/{id}/actions            — list actions
 *   GET  /compute/snapshots?instanceId={id}         — list snapshots
 *   POST /compute/snapshots                         — create snapshot
 *   DELETE /compute/snapshots/{snapId}              — delete snapshot
 *   POST /compute/snapshots/{snapId}/actions/rollback — restore
 *   GET  /v1/secrets?type=ssh                       — list SSH keys
 *   POST /v1/secrets                                — add SSH key
 *   DELETE /v1/secrets/{id}                         — delete SSH key
 */

declare(strict_types=1);

class ContaboActions
{
    private ContaboClient $http;

    public function __construct(ContaboClient $http)
    {
        $this->http = $http;
    }

    // ── Power ─────────────────────────────────────────────────

    public function start(int $id): array
    {
        $r = $this->http->post('/compute/instances/' . $id . '/actions/start');
        return $this->ok($r, 'Server is starting.');
    }

    public function stop(int $id): array
    {
        $r = $this->http->post('/compute/instances/' . $id . '/actions/stop');
        return $this->ok($r, 'Shutdown signal sent.');
    }

    public function reboot(int $id): array
    {
        $r = $this->http->post('/compute/instances/' . $id . '/actions/restart');
        return $this->ok($r, 'Server is rebooting.');
    }

    // ── Rescue ────────────────────────────────────────────────

    public function rescue(int $id): array
    {
        $r = $this->http->post('/compute/instances/' . $id . '/actions/rescue');
        if (ContaboClient::isOk($r)) {
            $pass = $r['data'][0]['rootPassword'] ?? $r['rootPassword'] ?? null;
            return ['ok' => true, 'message' => 'Rescue mode enabled.', 'root_password' => $pass];
        }
        return ['ok' => false, 'error' => ContaboClient::errMsg($r, 'Rescue failed.')];
    }

    // ── Reset root password ───────────────────────────────────

    public function resetPassword(int $id): array
    {
        // Contabo: sends new password via email — does not return it in API
        $r = $this->http->post('/compute/instances/' . $id . '/actions/resetPasswordRequest');
        if (ContaboClient::isOk($r)) {
            return ['ok' => true, 'message' => 'Password reset requested. New password will be sent to your Contabo account email.'];
        }
        return ['ok' => false, 'error' => ContaboClient::errMsg($r, 'Password reset failed.')];
    }

    // ── Rebuild / Reinstall ───────────────────────────────────

    public function reinstall(int $id, string $imageId): array
    {
        if (!$imageId) return ['ok' => false, 'error' => 'Image ID is required.'];

        $pass = $this->generatePassword();
        $r = $this->http->put('/compute/instances/' . $id, [
            'imageId'      => $imageId,
            'rootPassword' => $pass,
            'defaultUser'  => 'root',
        ]);

        if (ContaboClient::isOk($r)) {
            return ['ok' => true, 'message' => 'Server reinstall started.', 'root_password' => $pass];
        }
        return ['ok' => false, 'error' => ContaboClient::errMsg($r, 'Reinstall failed.')];
    }

    // ── Snapshots ─────────────────────────────────────────────

    public function createSnapshot(int $instanceId, string $name = ''): array
    {
        if (!$name) $name = 'snapshot-' . date('Ymd-Hi');
        $r = $this->http->post('/compute/snapshots', [
            'instanceId' => $instanceId,
            'name'       => $name,
        ]);
        if (ContaboClient::isOk($r)) {
            return ['ok' => true, 'message' => "Snapshot '{$name}' created."];
        }
        return ['ok' => false, 'error' => ContaboClient::errMsg($r, 'Snapshot failed.')];
    }

    public function listSnapshots(int $instanceId): array
    {
        $r = $this->http->get('/compute/snapshots', ['instanceId' => $instanceId]);
        $snaps = array_map(fn($s) => [
            'id'          => $s['snapshotId'] ?? $s['id'] ?? null,
            'description' => $s['name']       ?? 'Snapshot',
            'created'     => $s['createdDate']?? null,
            'image_size'  => null,
            'status'      => 'available',
        ], $r['data'] ?? []);
        return ['ok' => true, 'snapshots' => $snaps];
    }

    public function deleteSnapshot(string $snapshotId): array
    {
        $r = $this->http->delete('/compute/snapshots/' . $snapshotId);
        return ContaboClient::isOk($r)
            ? ['ok' => true, 'message' => 'Snapshot deleted.']
            : ['ok' => false, 'error' => ContaboClient::errMsg($r, 'Delete failed.')];
    }

    public function rollbackSnapshot(string $snapshotId): array
    {
        $r = $this->http->post('/compute/snapshots/' . $snapshotId . '/actions/rollback');
        return $this->ok($r, 'Snapshot rollback started.');
    }

    // ── Volumes — Contabo uses "additional storage" ───────────

    public function listVolumes(int $instanceId): array
    {
        // Contabo doesn't have traditional block volumes like Hetzner
        // Check for additional disks on the instance
        $r  = $this->http->get('/compute/instances/' . $instanceId);
        $inst = $r['data'][0] ?? [];
        $vols = [];
        if (!empty($inst['addOnIds'])) {
            foreach ($inst['addOnIds'] as $addon) {
                if (str_contains(strtolower($addon), 'disk') || str_contains(strtolower($addon), 'storage')) {
                    $vols[] = ['id' => $addon, 'name' => 'Additional Storage', 'size' => 0, 'status' => 'active', 'linux_device' => '/dev/vdb', 'location' => ['name' => '']];
                }
            }
        }
        return ['ok' => true, 'volumes' => $vols];
    }

    public function createVolume(int $instanceId, string $name, int $sizeGb): array
    {
        return ['ok' => false, 'error' => 'Contabo does not support on-demand block volumes. Use Contabo console to order additional storage add-ons.'];
    }

    public function attachVolume(array $payload): array
    {
        return ['ok' => false, 'error' => 'Volume management is handled via Contabo console.'];
    }

    public function detachVolume(array $payload): array
    {
        return ['ok' => false, 'error' => 'Volume management is handled via Contabo console.'];
    }

    public function deleteVolume(array $payload): array
    {
        return ['ok' => false, 'error' => 'Volume management is handled via Contabo console.'];
    }

    // ── Firewall — Contabo uses security groups ───────────────

    public function listFirewalls(int $instanceId): array
    {
        // Contabo doesn't have traditional firewall API in v1 — uses iptables/security groups
        return ['ok' => true, 'firewalls' => []];
    }

    public function applyFirewall(int $firewallId, int $instanceId): array
    {
        return ['ok' => false, 'error' => 'Contabo firewall management is handled via the Contabo console. Use iptables on the server directly.'];
    }

    public function removeFirewall(int $firewallId, int $instanceId): array
    {
        return ['ok' => false, 'error' => 'Contabo firewall management is handled via the Contabo console.'];
    }

    // ── Floating IPs — Contabo additional IPs ────────────────

    public function listFloatingIps(int $instanceId): array
    {
        $r    = $this->http->get('/compute/instances/' . $instanceId);
        $inst = $r['data'][0] ?? [];
        $ips  = [];
        if (!empty($inst['ipConfig']['v4']['ip'])) {
            $ips[] = [
                'id'            => $inst['ipConfig']['v4']['ip'],
                'ip'            => $inst['ipConfig']['v4']['ip'],
                'type'          => 'ipv4',
                'home_location' => ['name' => strtolower($inst['region'] ?? '')],
            ];
        }
        return ['ok' => true, 'assigned' => $ips, 'available' => []];
    }

    // ── Networks ──────────────────────────────────────────────

    public function listNetworks(int $instanceId): array
    {
        $r    = $this->http->get('/compute/instances/' . $instanceId);
        $inst = $r['data'][0] ?? [];
        $nets = [];
        if (!empty($inst['ipConfig']['v4']['gateway'])) {
            $nets[] = ['ip' => $inst['ipConfig']['v4']['gateway'], 'network_id' => null, 'mac_address' => null, 'network' => ['name' => 'Default Network']];
        }
        return ['ok' => true, 'networks' => $nets];
    }

    // ── Console ───────────────────────────────────────────────

    public function console(int $id): array
    {
        // Contabo VNC console via API
        $r   = $this->http->get('/compute/instances/' . $id . '/console');
        $url = $r['data'][0]['url'] ?? $r['url'] ?? null;
        if ($url) {
            return ['ok' => true, 'url' => $url, 'password' => $r['data'][0]['password'] ?? null];
        }
        return ['ok' => false, 'error' => ContaboClient::errMsg($r, 'Console not available. Use Contabo console panel.')];
    }

    // ── Delete ────────────────────────────────────────────────

    public function deleteInstance(int $id): array
    {
        $r    = $this->http->delete('/compute/instances/' . $id);
        $code = $r['_http_status'] ?? 0;
        if (ContaboClient::isOk($r) || $code === 404) {
            return ['ok' => true, 'message' => 'Instance cancellation scheduled.'];
        }
        return ['ok' => false, 'error' => ContaboClient::errMsg($r, 'Cancel failed.')];
    }

    // ── Private helpers ───────────────────────────────────────

    private function ok(array $r, string $msg): array
    {
        return ContaboClient::isOk($r)
            ? ['ok' => true, 'message' => $msg]
            : ['ok' => false, 'error' => ContaboClient::errMsg($r, 'Action failed.')];
    }

    private function generatePassword(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
        $pass  = '';
        for ($i = 0; $i < 20; $i++) $pass .= $chars[random_int(0, strlen($chars) - 1)];
        return $pass;
    }
}
