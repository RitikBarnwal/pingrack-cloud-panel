<?php
/**
 * providers/virtualizor/actions.php
 *
 * Virtualizor VPS power and management actions.
 *
 * Virtualizor API acts:
 *   vs_status    — get VPS status
 *   startvs      — start VPS
 *   stopvs       — stop VPS
 *   restartvs    — restart VPS
 *   rebuildhdd   — rebuild/reinstall OS
 *   changepass   — change root password
 *   console      — get VNC console details
 *   managevs     — suspend/unsuspend
 *   snapshot     — create snapshot
 *   listsnapshots— list snapshots
 *   delsnapshot  — delete snapshot
 *   restoresnapshot — restore snapshot
 *   addip        — add IP to VPS
 *   listips      — list IPs of VPS
 */

declare(strict_types=1);

class VirtualizorProviderActions
{
    private VirtualizorClient $http;

    private int $vpsId;

public function __construct(VirtualizorClient $http, array $server)
{
    $this->http  = $http;
    $this->vpsId = (int)($server['vps_id'] ?? $server['id'] ?? 0);
}

    // ── Power ─────────────────────────────────────────────────

    public function start(): array
{
    $r = $this->http->post('startvs', ['vpsid' => $this->vpsId]);
        return $this->ok($r, 'Server is starting.');
    }

    public function stop(): array
{
    $r = $this->http->post('stopvs', ['vpsid' => $this->vpsId]);
}

    public function reboot(int $vpsId): array
    {
        $r = $this->http->post('restartvs', ['vpsid' => $vpsId]);
        return $this->ok($r, 'Server is rebooting.');
    }

    public function forceStop(int $vpsId): array
    {
        // Virtualizor: same as stop for force
        $r = $this->http->post('stopvs', ['vpsid' => $vpsId, 'force' => 1]);
        return $this->ok($r, 'Server force-stopped.');
    }

    // ── Password reset ────────────────────────────────────────

    public function reset_root_password(int $vpsId): array
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
        $pass  = '';
        for ($i = 0; $i < 20; $i++) $pass .= $chars[random_int(0, strlen($chars)-1)];

        $r = $this->http->post('changepassword', ['vpsid' => $vpsId], [
            'vpsid'    => $vpsId,
            'newpass'  => $pass,
            'conf_pass'=> $pass,
        ]);

        if (($r['done'] ?? 0) == 1) {
            return ['ok' => true, 'message' => 'Root password changed.', 'root_password' => $pass];
        }
        return ['ok' => false, 'error' => VirtualizorClient::errMsg($r, 'Password reset failed.')];
    }

    // ── Rebuild / Reinstall OS ────────────────────────────────

    public function rebuild(int $vpsId, int $osId): array
    {
        if (!$osId) return ['ok' => false, 'error' => 'OS ID required.'];

        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
        $pass  = '';
        for ($i = 0; $i < 20; $i++) $pass .= $chars[random_int(0, strlen($chars)-1)];

        $r = $this->http->post('rebuildhdd', ['vpsid' => $vpsId], [
            'vpsid'   => $vpsId,
            'osid'    => $osId,
            'newpass' => $pass,
            'conf_pass'=> $pass,
        ]);

        if (($r['done'] ?? 0) == 1) {
            return ['ok' => true, 'message' => 'Server rebuild started.', 'root_password' => $pass];
        }
        return ['ok' => false, 'error' => VirtualizorClient::errMsg($r, 'Rebuild failed.')];
    }

    // ── Console ───────────────────────────────────────────────

    public function console(int $vpsId): array
    {
        $r = $this->http->get('console', ['vpsid' => $vpsId]);

        // Virtualizor returns VNC host/port/password
        if (!empty($r['novnc']) || !empty($r['novnchost'])) {
            $host = $r['novnchost'] ?? $r['vnc_ip']   ?? '';
            $port = $r['novncport'] ?? $r['novnc_port']?? '';
            $pass = $r['novncpass'] ?? $r['vnc_pass']  ?? '';

            // Build noVNC URL using the panel
            $panel  = $this->http->getPanelUrl();
            $url    = $panel . ':4083/index.php?api=json&act=console&vpsid=' . $vpsId
                    . '&apikey=' . urlencode($r['apikey'] ?? '');

            // Better: direct noVNC websocket
            if ($host && $port) {
                $wss_url = 'wss://' . $host . ':' . $port;
                return ['ok' => true, 'url' => $url, 'wss_url' => $wss_url, 'password' => $pass];
            }
            return ['ok' => true, 'url' => $url, 'password' => $pass];
        }

        if (($r['done'] ?? 0) == 1 && !empty($r['console'])) {
            return ['ok' => true, 'url' => $r['console'], 'password' => $r['novncpass'] ?? null];
        }

        // Fallback: direct link to Virtualizor panel console page
        $panel = $this->http->getPanelUrl();
        return ['ok' => true, 'url' => $panel . ':4083/index.php?act=console&vpsid=' . $vpsId, 'password' => null];
    }

    // ── Snapshots ─────────────────────────────────────────────

    public function createSnapshot(int $vpsId, string $label = ''): array
    {
        if (!$label) $label = 'snapshot-' . date('Ymd-Hi');
        $r = $this->http->post('snapshot', ['vpsid' => $vpsId], [
            'vpsid'   => $vpsId,
            'snap_name'=> $label,
        ]);
        return $this->ok($r, "Snapshot '{$label}' created.");
    }

    public function listSnapshots(int $vpsId): array
    {
        $r = $this->http->get('listsnapshots', ['vpsid' => $vpsId]);
        $snaps = [];
        foreach ($r['snaps'] ?? $r['snapshots'] ?? [] as $sid => $s) {
            $snaps[] = [
                'id'          => $s['snap_id']   ?? $sid,
                'description' => $s['snap_name'] ?? $s['label'] ?? 'Snapshot',
                'created'     => isset($s['time']) ? date('Y-m-d H:i:s', (int)$s['time']) : null,
                'image_size'  => $s['size']       ?? null,
                'status'      => 'available',
            ];
        }
        return ['ok' => true, 'snapshots' => $snaps];
    }

    public function deleteSnapshot(int $vpsId, int $snapId): array
    {
        $r = $this->http->post('delsnapshot', ['vpsid' => $vpsId], [
            'vpsid'   => $vpsId,
            'snap_id' => $snapId,
        ]);
        return $this->ok($r, 'Snapshot deleted.');
    }

    public function restoreSnapshot(int $vpsId, int $snapId): array
    {
        $r = $this->http->post('restoresnapshot', ['vpsid' => $vpsId], [
            'vpsid'   => $vpsId,
            'snap_id' => $snapId,
        ]);
        return $this->ok($r, 'Snapshot restore started.');
    }

    // ── Manage (suspend/unsuspend) ────────────────────────────

    public function suspend(int $vpsId): array
    {
        $r = $this->http->post('managevs', ['vpsid' => $vpsId], ['vpsid' => $vpsId, 'suspend' => 1]);
        return $this->ok($r, 'VPS suspended.');
    }

    public function unsuspend(int $vpsId): array
    {
        $r = $this->http->post('managevs', ['vpsid' => $vpsId], ['vpsid' => $vpsId, 'unsuspend' => 1]);
        return $this->ok($r, 'VPS unsuspended.');
    }

    // ── IPs ───────────────────────────────────────────────────

    public function listIPs(int $vpsId): array
    {
        $r   = $this->http->get('vpsdetails', ['vpsid' => $vpsId]);
        $srv = $r['vpsdetails'] ?? $r['vps'] ?? [];
        $ips = [];
        foreach ($srv['ips'] ?? [] as $ip) {
            $ips[] = [
                'id'            => is_array($ip) ? ($ip['ip'] ?? null) : $ip,
                'ip'            => is_array($ip) ? ($ip['ip'] ?? null) : $ip,
                'type'          => 'ipv4',
                'home_location' => ['name' => $srv['server_name'] ?? ''],
            ];
        }
        return ['ok' => true, 'assigned' => $ips, 'available' => []];
    }

    // ── Volumes — not supported in Virtualizor ────────────────

    public function listVolumes(int $vpsId): array
    {
        return ['ok' => true, 'volumes' => []];
    }

    public function createVolume(array $payload): array
    {
        return ['ok' => false, 'error' => 'Block volumes not supported on Virtualizor. Use disk space in the VPS plan.'];
    }

    public function attachVolume(array $p): array  { return ['ok'=>false,'error'=>'Not supported on Virtualizor.']; }
    public function detachVolume(array $p): array  { return ['ok'=>false,'error'=>'Not supported on Virtualizor.']; }
    public function resizeVolume(array $p): array  { return ['ok'=>false,'error'=>'Not supported on Virtualizor.']; }
    public function deleteVolume(array $p): array  { return ['ok'=>false,'error'=>'Not supported on Virtualizor.']; }

    // ── Firewalls — Virtualizor uses iptables rules ───────────

    public function listFirewalls(int $vpsId): array { return ['ok'=>true,'firewalls'=>[]]; }
    public function applyFirewall(array $p): array   { return ['ok'=>false,'error'=>'Virtualizor firewalls are managed via iptables on each VPS.']; }
    public function removeFirewall(array $p): array  { return ['ok'=>false,'error'=>'Virtualizor firewalls are managed via iptables on each VPS.']; }

    // ── Floating IPs ──────────────────────────────────────────

    public function listFloatingIps(int $vpsId): array
    {
        return $this->listIPs($vpsId);
    }

    public function createFloatingIp(array $p): array   { return ['ok'=>false,'error'=>'Additional IPs managed via Virtualizor admin panel.']; }
    public function assignFloatingIp(array $p): array   { return ['ok'=>false,'error'=>'IP management via Virtualizor admin panel.']; }
    public function unassignFloatingIp(array $p): array { return ['ok'=>false,'error'=>'IP management via Virtualizor admin panel.']; }
    public function deleteFloatingIp(array $p): array   { return ['ok'=>false,'error'=>'IP management via Virtualizor admin panel.']; }

    // ── Networks ──────────────────────────────────────────────

    public function listNetworks(int $vpsId): array    { return ['ok'=>true,'networks'=>[]]; }
    public function listAllNetworks(): array           { return ['ok'=>true,'networks'=>[]]; }
    public function createNetwork(array $p): array     { return ['ok'=>false,'error'=>'Network management via Virtualizor admin panel.']; }
    public function attachNetwork(array $p): array     { return ['ok'=>false,'error'=>'Network management via Virtualizor admin panel.']; }
    public function detachNetwork(array $p): array     { return ['ok'=>false,'error'=>'Network management via Virtualizor admin panel.']; }

    // ── Delete ────────────────────────────────────────────────

    public function deleteVps(int $vpsId): array
    {
        $r    = $this->http->post('deletevs', ['vpsid' => $vpsId], ['vpsid' => $vpsId, 'conf' => 'yes']);
        $code = $r['_http_status'] ?? 0;
        if (($r['done'] ?? 0) == 1 || $code === 404) {
            return ['ok' => true, 'message' => 'VPS deleted.'];
        }
        return ['ok' => false, 'error' => VirtualizorClient::errMsg($r, 'Delete failed.')];
    }

    // ── Private ───────────────────────────────────────────────

    private function ok(array $r, string $msg): array
    {
        return ($r['done'] ?? 0) == 1
            ? ['ok' => true, 'message' => $msg]
            : ['ok' => false, 'error' => VirtualizorClient::errMsg($r, 'Action failed.')];
    }
}
