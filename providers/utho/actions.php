<?php
/**
 * providers/utho/actions.php
 *
 * Utho Cloud server actions.
 *
 * API endpoints:
 *   POST /cloudinstances/{id}/power/start       — boot
 *   POST /cloudinstances/{id}/power/stop        — shutdown
 *   POST /cloudinstances/{id}/power/restart     — reboot
 *   POST /cloudinstances/{id}/power/forcestop   — force off
 *   POST /cloudinstances/{id}/rebuild           — rebuild
 *   POST /cloudinstances/{id}/resetpassword     — reset root password
 *   GET  /cloudinstances/{id}/console           — console URL
 *   POST /cloudinstances/{id}/snapshot          — create snapshot
 *   GET  /cloudinstances/{id}/snapshots         — list snapshots
 *   DELETE /cloudinstances/{id}/snapshots/{sid} — delete snapshot
 *   GET  /cloudinstances/{id}/volumes           — list volumes
 *   POST /volumes                               — create volume
 *   POST /volumes/{vid}/attach                  — attach volume
 *   POST /volumes/{vid}/detach                  — detach volume
 *   DELETE /volumes/{vid}                       — delete volume
 *   GET  /firewall                              — list firewalls
 *   POST /firewall/{fid}/instances              — apply to server
 *   DELETE /firewall/{fid}/instances/{id}       — remove from server
 */

declare(strict_types=1);

class UthoActions
{
    private UthoClient $http;

    public function __construct(UthoClient $http)
    {
        $this->http = $http;
    }

    // ── Power ─────────────────────────────────────────────────

    public function boot(int $id): array
    {
        $r = $this->http->post('/cloudinstances/' . $id . '/power/start');
        return $this->ok($r, 'Server is starting.');
    }

    public function shutdown(int $id): array
    {
        $r = $this->http->post('/cloudinstances/' . $id . '/power/stop');
        return $this->ok($r, 'Shutdown signal sent.');
    }

    public function forceStop(int $id): array
    {
        $r = $this->http->post('/cloudinstances/' . $id . '/power/forcestop');
        return $this->ok($r, 'Server force-stopped.');
    }

    public function reboot(int $id): array
    {
        $r = $this->http->post('/cloudinstances/' . $id . '/power/restart');
        return $this->ok($r, 'Server is rebooting.');
    }

    // ── Reset root password ───────────────────────────────────

    public function resetPassword(int $id): array
    {
        $r = $this->http->post('/cloudinstances/' . $id . '/resetpassword');
        if (UthoClient::isOk($r)) {
            $pass = $r['password'] ?? $r['rootPassword'] ?? null;
            return ['ok' => true, 'message' => 'Root password reset.', 'root_password' => $pass];
        }
        return ['ok' => false, 'error' => UthoClient::errMsg($r, 'Password reset failed.')];
    }

    // ── Rebuild / Reinstall ───────────────────────────────────

    public function rebuild(int $id, string $imageSlug): array
    {
        if (!$imageSlug) return ['ok' => false, 'error' => 'Image is required.'];

        $r = $this->http->post('/cloudinstances/' . $id . '/rebuild', [
            'image' => $imageSlug,
        ]);

        if (UthoClient::isOk($r)) {
            $pass = $r['password'] ?? $r['rootPassword'] ?? null;
            return ['ok' => true, 'message' => 'Server rebuild started.', 'root_password' => $pass];
        }
        return ['ok' => false, 'error' => UthoClient::errMsg($r, 'Rebuild failed.')];
    }

    // ── Rescue boot ───────────────────────────────────────────

    public function rescue(int $id): array
    {
        // Utho: boot into rescue mode
        $r = $this->http->post('/cloudinstances/' . $id . '/rescue');
        return $this->ok($r, 'Rescue mode enabled. Reboot to activate.');
    }

    // ── Console ───────────────────────────────────────────────

    public function console(int $id): array
    {
        $r = $this->http->get('/cloudinstances/' . $id . '/console');
        $url = $r['consoleurl'] ?? $r['url'] ?? $r['console_url'] ?? null;
        if (!$url && !empty($r['data']['url'])) $url = $r['data']['url'];

        if ($url) {
            return ['ok' => true, 'url' => $url, 'password' => $r['password'] ?? null];
        }
        return ['ok' => false, 'error' => UthoClient::errMsg($r, 'Console not available.')];
    }

    // ── Snapshots ─────────────────────────────────────────────

    public function createSnapshot(int $id, string $label = ''): array
    {
        if (!$label) $label = 'snapshot-' . date('Ymd-Hi');
        $r = $this->http->post('/cloudinstances/' . $id . '/snapshot', [
            'snapshotname' => $label,
        ]);
        if (UthoClient::isOk($r)) {
            return ['ok' => true, 'message' => "Snapshot '{$label}' created."];
        }
        return ['ok' => false, 'error' => UthoClient::errMsg($r, 'Snapshot failed.')];
    }

    public function listSnapshots(int $id): array
    {
        $r = $this->http->get('/cloudinstances/' . $id . '/snapshots');
        $snaps = array_map(fn($s) => [
            'id'          => $s['id']           ?? $s['snapshotid'] ?? null,
            'description' => $s['snapshotname'] ?? $s['name']       ?? 'Snapshot',
            'created'     => $s['created_at']   ?? $s['createdat']  ?? null,
            'image_size'  => $s['size']          ?? null,
            'status'      => 'available',
        ], $r['snapshots'] ?? $r['data'] ?? []);
        return ['ok' => true, 'snapshots' => $snaps];
    }

    public function deleteSnapshot(int $id, int $snapId): array
    {
        $r = $this->http->delete('/cloudinstances/' . $id . '/snapshots/' . $snapId);
        return UthoClient::isOk($r)
            ? ['ok' => true, 'message' => 'Snapshot deleted.']
            : ['ok' => false, 'error' => UthoClient::errMsg($r, 'Delete failed.')];
    }

    // ── Volumes ───────────────────────────────────────────────

    public function listVolumes(int $id): array
    {
        $r    = $this->http->get('/cloudinstances/' . $id . '/volumes');
        $vols = array_map(fn($v) => $this->mapVolume($v), $r['volumes'] ?? $r['data'] ?? []);
        return ['ok' => true, 'volumes' => $vols];
    }

    public function createVolume(int $id, string $name, int $sizeGb, string $dcSlug): array
    {
        if (!$name)   return ['ok' => false, 'error' => 'Volume name required.'];
        if ($sizeGb < 1) return ['ok' => false, 'error' => 'Invalid size.'];

        $r = $this->http->post('/volumes', [
            'name'          => $name,
            'size'          => $sizeGb,
            'dcslug'        => $dcSlug,
            'cloudinstanceid' => $id,
        ]);

        if (UthoClient::isOk($r) && !empty($r['volume']['id'] ?? $r['id'] ?? null)) {
            return ['ok' => true, 'message' => "Volume '{$name}' created and attached.", 'volume' => $this->mapVolume($r['volume'] ?? $r)];
        }
        return ['ok' => false, 'error' => UthoClient::errMsg($r, 'Volume creation failed.')];
    }

    public function attachVolume(int $volumeId, int $cloudId): array
    {
        $r = $this->http->post('/volumes/' . $volumeId . '/attach', [
            'cloudinstanceid' => $cloudId,
        ]);
        return $this->ok($r, 'Volume attached.');
    }

    public function detachVolume(int $volumeId): array
    {
        $r = $this->http->post('/volumes/' . $volumeId . '/detach');
        return $this->ok($r, 'Volume detached.');
    }

    public function deleteVolume(int $volumeId): array
    {
        $this->http->post('/volumes/' . $volumeId . '/detach');
        $r = $this->http->delete('/volumes/' . $volumeId);
        return UthoClient::isOk($r)
            ? ['ok' => true, 'message' => 'Volume deleted.']
            : ['ok' => false, 'error' => UthoClient::errMsg($r, 'Delete failed.')];
    }

    // ── Firewalls ─────────────────────────────────────────────

    public function listServerFirewalls(int $id): array
    {
        // Get firewalls applied to this server
        $r  = $this->http->get('/cloudinstances/' . $id . '/firewalls');
        $fw = array_map(fn($f) => [
            'id'     => $f['id']    ?? null,
            'name'   => $f['name']  ?? '',
            'rules'  => $f['rules'] ?? [],
            'status' => 'applied',
        ], $r['firewalls'] ?? $r['data'] ?? []);
        return ['ok' => true, 'firewalls' => $fw];
    }

    public function applyFirewall(int $firewallId, int $cloudId): array
    {
        $r = $this->http->post('/firewall/' . $firewallId . '/instances', [
            'cloudinstanceid' => $cloudId,
        ]);
        return $this->ok($r, 'Firewall applied to server.');
    }

    public function removeFirewall(int $firewallId, int $cloudId): array
    {
        $r = $this->http->delete('/firewall/' . $firewallId . '/instances/' . $cloudId);
        return UthoClient::isOk($r)
            ? ['ok' => true, 'message' => 'Firewall removed from server.']
            : ['ok' => false, 'error' => UthoClient::errMsg($r, 'Remove failed.')];
    }

    // ── Delete server ─────────────────────────────────────────

    public function deleteInstance(int $id): array
    {
        $r    = $this->http->delete('/cloudinstances/' . $id);
        $code = $r['_http_status'] ?? 0;
        if (UthoClient::isOk($r) || $code === 404) {
            return ['ok' => true, 'message' => 'Server deleted.'];
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
            'id'           => $v['id']         ?? null,
            'name'         => $v['name']        ?? '',
            'size'         => $v['size']        ?? 0,
            'status'       => $v['status']      ?? '',
            'linux_device' => $v['disk_path']   ?? '/dev/vdb',
            'location'     => ['name' => $v['dcslug'] ?? ''],
        ];
    }
}
