<?php
/**
 * providers/linode/actions.php
 *
 * Linode server power/management actions.
 *
 * Linode API differences from Hetzner:
 *  - Power on:  POST /linode/instances/{id}/boot
 *  - Power off: POST /linode/instances/{id}/shutdown
 *  - Reboot:    POST /linode/instances/{id}/reboot
 *  - Rebuild:   POST /linode/instances/{id}/rebuild  (full wipe + reinstall)
 *  - Reset pass:POST /linode/instances/{id}/password
 *  - No "rescue mode" as such — uses Rescue Boot via config profiles
 *  - Console:   GET  /linode/instances/{id}/lish_token → Glish/Weblish URL
 *  - Volumes:   separate /volumes endpoint
 *  - Firewalls: /networking/firewalls
 *  - Floating IPs: not directly supported (Linode uses NodeBalancers)
 *  - Private IPs: /linode/instances/{id}/ips
 */

declare(strict_types=1);

class LinodeActions
{
    private LinodeClient $http;

    public function __construct(LinodeClient $http)
    {
        $this->http = $http;
    }

    // ── Power On ──────────────────────────────────────────────

    public function boot(int $linodeId, ?int $configId = null): array
    {
        $body = $configId ? ['config_id' => $configId] : [];
        $r = $this->http->post('/linode/instances/' . $linodeId . '/boot', $body);
        return $this->actionResult($r, 'Server is booting up.');
    }

    // ── Power Off (graceful shutdown) ─────────────────────────

    public function shutdown(int $linodeId): array
    {
        $r = $this->http->post('/linode/instances/' . $linodeId . '/shutdown');
        return $this->actionResult($r, 'Shutdown signal sent to server.');
    }

    // ── Reboot ────────────────────────────────────────────────

    public function reboot(int $linodeId, ?int $configId = null): array
    {
        $body = $configId ? ['config_id' => $configId] : [];
        $r = $this->http->post('/linode/instances/' . $linodeId . '/reboot', $body);
        return $this->actionResult($r, 'Server is rebooting.');
    }

    // ── Rescue boot ───────────────────────────────────────────
    // Linode rescue boots from Finnix rescue image

    public function rescueBoot(int $linodeId, array $disks = []): array
    {
        $body = ['devices' => $disks ?: (object)[]];
        $r = $this->http->post('/linode/instances/' . $linodeId . '/rescue', $body);
        return $this->actionResult($r, 'Rescue boot initiated. Server will boot into Finnix rescue environment.');
    }

    // ── Reset root password ───────────────────────────────────

    public function resetPassword(int $linodeId, string $newPass = ''): array
    {
        // Linode requires server to be offline to reset password
        if (!$newPass) {
            $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
            $newPass = '';
            for ($i = 0; $i < 20; $i++) {
                $newPass .= $chars[random_int(0, strlen($chars) - 1)];
            }
        }

        $r = $this->http->post('/linode/instances/' . $linodeId . '/password', [
            'password' => $newPass,
        ]);

        // Linode returns {} on success for this endpoint
        $http_ok = in_array($r['_http_status'] ?? 0, [200, 204], true);
        if ($http_ok && empty($r['errors'])) {
            return ['ok' => true, 'message' => 'Root password reset.', 'root_password' => $newPass];
        }
        return ['ok' => false, 'error' => LinodeClient::errorMessage($r, 'Password reset failed. Ensure server is offline.')];
    }

    // ── Rebuild ───────────────────────────────────────────────

    public function rebuild(int $linodeId, string $imageSlug, string $rootPass = ''): array
    {
        if (!$imageSlug) return ['ok' => false, 'error' => 'Image is required.'];

        if (!$rootPass) {
            $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
            $rootPass = '';
            for ($i = 0; $i < 20; $i++) {
                $rootPass .= $chars[random_int(0, strlen($chars) - 1)];
            }
        }

        $r = $this->http->post('/linode/instances/' . $linodeId . '/rebuild', [
            'image'     => $imageSlug,
            'root_pass' => $rootPass,
            'booted'    => true,
        ]);

        $http_ok = in_array($r['_http_status'] ?? 0, [200, 201, 202], true);
        if ($http_ok && !empty($r['id'])) {
            return ['ok' => true, 'message' => 'Server rebuild started.', 'root_password' => $rootPass];
        }
        return ['ok' => false, 'error' => LinodeClient::errorMessage($r, 'Rebuild failed.')];
    }

    // ── Console (Weblish/Glish) ───────────────────────────────

    public function getConsoleToken(int $linodeId): array
    {
        // Linode Weblish: POST /linode/instances/{id}/lish_token
        $r = $this->http->post('/linode/instances/' . $linodeId . '/lish_token');

        if (!empty($r['lish_token'])) {
            $token = $r['lish_token'];
            // Build Weblish URL — user opens this in browser
            $weblish_url = 'https://weblish.linode.com/?token=' . urlencode($token);
            return [
                'ok'       => true,
                'url'      => $weblish_url,
                'token'    => $token,
                'password' => null, // Weblish uses the token
            ];
        }
        return ['ok' => false, 'error' => LinodeClient::errorMessage($r, 'Console not available.')];
    }

    // ── Snapshots (Linode Backups / Manual Snapshots) ─────────

    public function createSnapshot(int $linodeId, string $label = ''): array
    {
        if (!$label) $label = 'snapshot-' . date('Ymd-Hi');

        $r = $this->http->post('/linode/instances/' . $linodeId . '/backups', [
            'label' => $label,
        ]);

        if (!empty($r['id'])) {
            return ['ok' => true, 'message' => "Snapshot '{$label}' created.", 'backup' => $r];
        }
        return ['ok' => false, 'error' => LinodeClient::errorMessage($r, 'Snapshot failed. Ensure backups are enabled.')];
    }

    public function listSnapshots(int $linodeId): array
    {
        $r = $this->http->get('/linode/instances/' . $linodeId . '/backups');
        $snaps = [];

        // Linode backups: automatic (daily/weekly) + snapshot
        foreach ($r['automatic'] ?? [] as $b) {
            $snaps[] = $this->mapBackup($b, 'automatic');
        }
        $snap = $r['snapshot']['current'] ?? null;
        if ($snap) $snaps[] = $this->mapBackup($snap, 'snapshot');

        $in_prog = $r['snapshot']['in_progress'] ?? null;
        if ($in_prog) $snaps[] = $this->mapBackup($in_prog, 'in_progress');

        return ['ok' => true, 'snapshots' => $snaps];
    }

    public function restoreSnapshot(int $linodeId, int $backupId, bool $overwrite = true): array
    {
        $r = $this->http->post('/linode/instances/' . $linodeId . '/backups/' . $backupId . '/restore', [
            'linode_id' => $linodeId,
            'overwrite' => $overwrite,
        ]);
        return $this->actionResult($r, 'Backup restore started.');
    }

    // ── Volumes ───────────────────────────────────────────────

    public function listVolumes(int $linodeId): array
    {
        // Get all volumes and filter by linode_id
        $r = $this->http->get('/volumes', ['page_size' => 100]);
        $vols = array_filter($r['data'] ?? [], fn($v) => (int)($v['linode_id'] ?? 0) === $linodeId);

        return [
            'ok'      => true,
            'volumes' => array_values(array_map([$this, 'mapVolume'], $vols)),
        ];
    }

    public function createVolume(int $linodeId, string $name, int $sizeGb, string $region): array
    {
        if (!$name) return ['ok' => false, 'error' => 'Volume label required.'];
        if ($sizeGb < 20 || $sizeGb > 10240) return ['ok' => false, 'error' => 'Size must be 20–10240 GB.'];

        $r = $this->http->post('/volumes', [
            'label'     => $name,
            'size'      => $sizeGb,
            'linode_id' => $linodeId,
            'region'    => $region,
        ]);

        if (!empty($r['id'])) {
            return ['ok' => true, 'message' => "Volume '{$name}' created and attached.", 'volume' => $this->mapVolume($r)];
        }
        return ['ok' => false, 'error' => LinodeClient::errorMessage($r, 'Volume creation failed.')];
    }

    public function attachVolume(int $volumeId, int $linodeId): array
    {
        $r = $this->http->post('/volumes/' . $volumeId . '/attach', [
            'linode_id' => $linodeId,
        ]);
        $http_ok = in_array($r['_http_status'] ?? 0, [200, 204], true);
        return $http_ok && empty($r['errors'])
            ? ['ok' => true, 'message' => 'Volume attached.']
            : ['ok' => false, 'error' => LinodeClient::errorMessage($r, 'Attach failed.')];
    }

    public function detachVolume(int $volumeId): array
    {
        $r = $this->http->post('/volumes/' . $volumeId . '/detach');
        $http_ok = in_array($r['_http_status'] ?? 0, [200, 204], true);
        return $http_ok && empty($r['errors'])
            ? ['ok' => true, 'message' => 'Volume detached.']
            : ['ok' => false, 'error' => LinodeClient::errorMessage($r, 'Detach failed.')];
    }

    public function deleteVolume(int $volumeId): array
    {
        // Must be detached first
        $this->http->post('/volumes/' . $volumeId . '/detach');
        sleep(1);
        $r = $this->http->delete('/volumes/' . $volumeId);
        return ($r['_http_status'] ?? 0) === 204
            ? ['ok' => true, 'message' => 'Volume deleted.']
            : ['ok' => false, 'error' => LinodeClient::errorMessage($r, 'Delete failed.')];
    }

    // ── Firewalls ─────────────────────────────────────────────

    public function listFirewalls(int $linodeId): array
    {
        // Get firewalls applied to this linode
        $r = $this->http->get('/linode/instances/' . $linodeId . '/firewalls');
        $firewalls = [];
        foreach ($r['data'] ?? [] as $fw) {
            $firewalls[] = $this->mapFirewall($fw, 'applied');
        }
        return ['ok' => true, 'firewalls' => $firewalls];
    }

    public function applyFirewall(int $firewallId, int $linodeId): array
    {
        // Add linode to firewall devices
        $r = $this->http->post('/networking/firewalls/' . $firewallId . '/devices', [
            'id'   => $linodeId,
            'type' => 'linode',
        ]);

        $http_ok = in_array($r['_http_status'] ?? 0, [200, 201], true);
        return $http_ok && !empty($r['id'])
            ? ['ok' => true, 'message' => 'Firewall applied to server.']
            : ['ok' => false, 'error' => LinodeClient::errorMessage($r, 'Apply failed.')];
    }

    public function removeFirewall(int $firewallId, int $linodeId): array
    {
        // Find device ID first
        $r = $this->http->get('/networking/firewalls/' . $firewallId . '/devices');
        foreach ($r['data'] ?? [] as $device) {
            if ((int)($device['entity']['id'] ?? 0) === $linodeId) {
                $this->http->delete('/networking/firewalls/' . $firewallId . '/devices/' . $device['id']);
                return ['ok' => true, 'message' => 'Firewall removed from server.'];
            }
        }
        return ['ok' => false, 'error' => 'Firewall device not found on this server.'];
    }

    // ── IP / Networking ───────────────────────────────────────

    public function listIPs(int $linodeId): array
    {
        $r = $this->http->get('/linode/instances/' . $linodeId . '/ips');
        return [
            'ok'  => true,
            'ipv4_public'  => $r['ipv4']['public']  ?? [],
            'ipv4_private' => $r['ipv4']['private'] ?? [],
            'ipv6'         => $r['ipv6']['link_local'] ?? null,
        ];
    }

    public function addPrivateIP(int $linodeId): array
    {
        $r = $this->http->post('/linode/instances/' . $linodeId . '/ips', [
            'type'   => 'ipv4',
            'public' => false,
        ]);
        if (!empty($r['address'])) {
            return ['ok' => true, 'message' => 'Private IP added: ' . $r['address'], 'ip' => $r];
        }
        return ['ok' => false, 'error' => LinodeClient::errorMessage($r, 'Could not add private IP.')];
    }

    // ── Delete server ─────────────────────────────────────────

    public function deleteInstance(int $linodeId): array
    {
        $r = $this->http->delete('/linode/instances/' . $linodeId);
        if (($r['_http_status'] ?? 0) === 204) {
            return ['ok' => true, 'message' => 'Server deleted from provider.'];
        }
        if (($r['_http_status'] ?? 0) === 404) {
            return ['ok' => true, 'message' => 'Server deleted.'];
        }
        return ['ok' => false, 'error' => LinodeClient::errorMessage($r, 'Delete failed.')];
    }

    // ── Private helpers ───────────────────────────────────────

    private function actionResult(array $r, string $successMsg): array
    {
        $code = $r['_http_status'] ?? 0;
        if (in_array($code, [200, 201, 204], true) && empty($r['errors'])) {
            return ['ok' => true, 'message' => $successMsg];
        }
        return ['ok' => false, 'error' => LinodeClient::errorMessage($r, 'Action failed.')];
    }

    private function mapBackup(array $b, string $type): array
    {
        return [
            'id'          => $b['id'] ?? null,
            'description' => $b['label'] ?? ($type === 'automatic' ? 'Automatic backup' : 'Snapshot'),
            'created'     => $b['created'] ?? null,
            'image_size'  => $b['size'] ?? null, // MB
            'status'      => $b['status'] ?? 'unknown',
            'type'        => $type,
        ];
    }

    private function mapVolume(array $v): array
    {
        return [
            'id'           => $v['id']         ?? null,
            'name'         => $v['label']       ?? '',
            'size'         => $v['size']         ?? 0,  // GB
            'status'       => $v['status']       ?? '',
            'linux_device' => $v['filesystem_path'] ?? '/dev/disk/by-id/...',
            'location'     => ['name' => $v['region'] ?? ''],
            'created'      => $v['created']      ?? null,
        ];
    }

    private function mapFirewall(array $f, string $status): array
    {
        return [
            'id'     => $f['id']    ?? null,
            'name'   => $f['label'] ?? 'Firewall',
            'rules'  => $f['rules'] ?? [],
            'status' => $status,
        ];
    }
}
