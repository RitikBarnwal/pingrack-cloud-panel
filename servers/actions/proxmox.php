<?php
/**
 * servers/actions/proxmox.php
 *
 * Proxmox VE action handler — called by api/server-action.php
 *
 * servers.provider_id  = Proxmox VMID (e.g. 100)
 * servers.region_slug  = Proxmox node name (e.g. "pve")
 */
declare(strict_types=1);

class ProxmoxActions
{
    private ProxmoxClient $api;
    private array         $server;
    private int           $vmid;
    private string        $node;

    public function __construct(object $cloud, array $server)
    {
        $this->server = $server;
        $this->vmid   = (int)($server['provider_id'] ?? 0);
        $this->node   = $server['region_slug'] ?? '';
        $this->api    = $cloud->catalog->getClient();

        // Auto-resolve node if not set in region_slug
        if (!$this->node) {
            $this->node = $this->api->resolveNode();
        }
    }

    // ── Power ─────────────────────────────────────────────────

    public function start(): array
    {
        try {
            $r = $this->api->post("nodes/{$this->node}/qemu/{$this->vmid}/status/start");
            return ['ok' => true, 'message' => 'VM start initiated.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function stop(): array
    {
        try {
            $this->api->post("nodes/{$this->node}/qemu/{$this->vmid}/status/stop");
            return ['ok' => true, 'message' => 'VM stopped.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function shutdown(): array
    {
        try {
            $this->api->post("nodes/{$this->node}/qemu/{$this->vmid}/status/shutdown");
            return ['ok' => true, 'message' => 'VM shutdown signal sent.'];
        } catch (Throwable $e) {
            return $this->stop(); // Fallback to hard stop
        }
    }

    public function reboot(): array
    {
        try {
            $this->api->post("nodes/{$this->node}/qemu/{$this->vmid}/status/reboot");
            return ['ok' => true, 'message' => 'VM rebooting.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function reset(): array
    {
        try {
            $this->api->post("nodes/{$this->node}/qemu/{$this->vmid}/status/reset");
            return ['ok' => true, 'message' => 'VM hard reset sent.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Password reset ────────────────────────────────────────
    // Proxmox: change password via guest agent

    public function reset_root_password(): array
    {
        $pass = $this->randomPass();
        try {
            $this->api->post("nodes/{$this->node}/qemu/{$this->vmid}/agent/set-user-password", [
                'username' => 'root',
                'password' => $pass,
            ]);
            return ['ok' => true, 'message' => 'Root password changed.', 'root_password' => $pass];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Password reset requires QEMU guest agent: ' . $e->getMessage()];
        }
    }

    // ── Rebuild / Reinstall OS ────────────────────────────────
    // Proxmox: reinstall by changing cdrom ISO and rebooting
    // image = ISO volid e.g. "local:iso/ubuntu-22.04.iso"

    public function rebuild(array $payload): array
    {
        $iso_volid = trim($payload['image'] ?? '');
        if (!$iso_volid) {
            return ['ok' => false, 'error' => 'ISO image required for reinstall.'];
        }

        $pass = $this->randomPass();

        try {
            // Stop VM first
            try {
                $this->api->post("nodes/{$this->node}/qemu/{$this->vmid}/status/stop");
                sleep(3);
            } catch (Throwable $e) {}

            // Set cdrom to selected ISO
            $this->api->put("nodes/{$this->node}/qemu/{$this->vmid}/config", [
                'ide2'  => $iso_volid . ',media=cdrom',
                'boot'  => 'order=ide2;scsi0',
            ]);

            // Start VM from ISO
            $this->api->post("nodes/{$this->node}/qemu/{$this->vmid}/status/start");

            return [
                'ok'            => true,
                'message'       => 'VM started from ISO. Complete OS install manually or via cloud-init.',
                'root_password' => $pass,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Rebuild failed: ' . $e->getMessage()];
        }
    }

    // ── Console ───────────────────────────────────────────────

    public function get_console(): array
    {
        try {
            // Get VNC ticket
            $r = $this->api->post("nodes/{$this->node}/qemu/{$this->vmid}/vncproxy", [
                'websocket' => 1,
            ]);
            $data = $r['data'] ?? [];

            if (!empty($data['ticket'])) {
                $host = parse_url($this->api->getHost(), PHP_URL_HOST);
                $port = $data['port'] ?? 5900;
                $pass = $data['ticket'] ?? '';

                // Build websocket URL for noVNC
                $wss = 'wss://' . $host . ':' . $this->api->getHost() . '/api2/json'
                     . "/nodes/{$this->node}/qemu/{$this->vmid}/vncwebsocket"
                     . "?port={$port}&vncticket=" . urlencode($pass);

                return ['ok' => true, 'url' => $wss, 'password' => $pass, 'type' => 'vnc'];
            }
        } catch (Throwable $e) {}

        // Fallback: direct Proxmox panel link
        $panel_url = $this->api->getHost();
        return [
            'ok'  => true,
            'url' => $panel_url . "/#v1:0:18:4::::::", // Proxmox web console URL
            'type'=> 'panel',
        ];
    }

    // ── Snapshots ─────────────────────────────────────────────

    public function create_snapshot(array $payload = []): array
    {
        $snapname = preg_replace('/[^a-zA-Z0-9_-]/', '', $payload['description'] ?? ('snap' . date('YmdHi')));
        $snapname = $snapname ?: ('snap' . date('YmdHi'));

        try {
            $this->api->post("nodes/{$this->node}/qemu/{$this->vmid}/snapshot", [
                'snapname'    => $snapname,
                'description' => $payload['description'] ?? date('Y-m-d H:i'),
                'vmstate'     => 0,
            ]);
            return ['ok' => true, 'message' => "Snapshot '{$snapname}' created."];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function list_snapshots(): array
    {
        try {
            $r     = $this->api->get("nodes/{$this->node}/qemu/{$this->vmid}/snapshot");
            $snaps = [];
            foreach ($r['data'] ?? [] as $s) {
                if (($s['name'] ?? '') === 'current') continue; // skip "current" pseudo-snapshot
                $snaps[] = [
                    'id'          => $s['name'] ?? '',
                    'description' => $s['description'] ?? $s['name'] ?? 'Snapshot',
                    'created'     => isset($s['snaptime']) ? date('Y-m-d H:i:s', (int)$s['snaptime']) : null,
                    'image_size'  => null,
                    'status'      => 'available',
                ];
            }
            return ['ok' => true, 'snapshots' => $snaps];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function delete_snapshot(array $payload): array
    {
        $snapname = $payload['image_id'] ?? $payload['snapshot_id'] ?? '';
        if (!$snapname) return ['ok' => false, 'error' => 'snapshot name required'];

        try {
            $this->api->delete("nodes/{$this->node}/qemu/{$this->vmid}/snapshot/{$snapname}");
            return ['ok' => true, 'message' => 'Snapshot deleted.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Volumes ───────────────────────────────────────────────

    public function list_volumes(): array
    {
        try {
            $r    = $this->api->get("nodes/{$this->node}/qemu/{$this->vmid}/config");
            $cfg  = $r['data'] ?? [];
            $vols = [];

            foreach ($cfg as $key => $val) {
                if (!preg_match('/^(scsi|virtio|ide|sata)\d+$/', $key)) continue;
                if (str_contains((string)$val, 'media=cdrom')) continue;

                $vols[] = [
                    'id'           => $key,
                    'name'         => $key,
                    'size'         => $this->parseVolSize($val),
                    'linux_device' => '/dev/' . $key,
                    'location'     => ['name' => $this->node],
                ];
            }
            return ['ok' => true, 'volumes' => $vols];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Stubs ─────────────────────────────────────────────────

    public function enable_rescue(array $p = []): array      { return ['ok'=>false,'error'=>'Use Proxmox panel for rescue mode.']; }
    public function enable_rescue_cycle(array $p = []): array{ return $this->enable_rescue($p); }
    public function create_volume(array $p): array            { return ['ok'=>false,'error'=>'Manage disks via Proxmox panel.']; }
    public function attach_volume(array $p): array            { return ['ok'=>false,'error'=>'Manage disks via Proxmox panel.']; }
    public function detach_volume(array $p): array            { return ['ok'=>false,'error'=>'Manage disks via Proxmox panel.']; }
    public function list_firewalls(): array                   { return ['ok'=>true,'firewalls'=>[]]; }
    public function apply_firewall(array $p): array           { return ['ok'=>false,'error'=>'Manage firewall via Proxmox panel.']; }
    public function remove_firewall(array $p): array          { return ['ok'=>false,'error'=>'Manage firewall via Proxmox panel.']; }
    public function list_floating_ips(): array                { return ['ok'=>true,'assigned'=>[],'available'=>[]]; }
    public function create_floating_ip(array $p): array       { return ['ok'=>false,'error'=>'Manage IPs via Proxmox panel.']; }
    public function assign_floating_ip(array $p): array       { return ['ok'=>false,'error'=>'Manage IPs via Proxmox panel.']; }
    public function unassign_floating_ip(array $p): array     { return ['ok'=>false,'error'=>'Manage IPs via Proxmox panel.']; }
    public function delete_floating_ip(array $p): array       { return ['ok'=>false,'error'=>'Manage IPs via Proxmox panel.']; }
    public function list_networks(): array                    { return ['ok'=>true,'networks'=>[]]; }
    public function list_all_networks(): array                { return ['ok'=>true,'networks'=>[]]; }
    public function create_network(array $p): array           { return ['ok'=>false,'error'=>'Manage networks via Proxmox panel.']; }
    public function attach_network(array $p): array           { return ['ok'=>false,'error'=>'Not supported.']; }
    public function detach_network(array $p): array           { return ['ok'=>false,'error'=>'Not supported.']; }

    public function delete_server(): array
    {
        try {
            // Stop first
            try { $this->api->post("nodes/{$this->node}/qemu/{$this->vmid}/status/stop"); sleep(2); } catch (Throwable $e) {}
            // Delete VM
            $this->api->delete("nodes/{$this->node}/qemu/{$this->vmid}");
            return ['ok' => true, 'message' => 'VM deleted.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Private helpers ───────────────────────────────────────

    private function randomPass(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$';
        $s = '';
        for ($i = 0; $i < 20; $i++) $s .= $chars[random_int(0, strlen($chars)-1)];
        return $s;
    }

    private function parseVolSize(string $volstr): ?int
    {
        // e.g. "local-lvm:vm-100-disk-0,size=32G"
        if (preg_match('/size=(\d+)([TGMK]?)/i', $volstr, $m)) {
            $num  = (int)$m[1];
            $unit = strtoupper($m[2] ?? 'G');
            return match($unit) {
                'T' => $num * 1024,
                'G' => $num,
                'M' => (int)ceil($num / 1024),
                'K' => 1,
                default => $num,
            };
        }
        return null;
    }
}
