<?php
/**
 * providers/proxmox/servers.php
 *
 * Proxmox VE VM operations.
 *
 * Proxmox terminology → Panel neutral:
 *   vmid          → provider_id (our DB)
 *   node          → region_slug
 *   name/hostname → name
 *   status        → running/stopped etc.
 *   cores         → vcpu
 *   memory (MB)   → ram_gb
 *   maxdisk (bytes) → disk_gb
 *   net0 ip       → ipv4 (from agent if available)
 */
declare(strict_types=1);

class ProxmoxServers
{
    private ProxmoxClient $http;

    public function __construct(ProxmoxClient $http)
    {
        $this->http = $http;
    }

    // ── List all VMs across all nodes ─────────────────────────

    public function list(): array
    {
        $node = $this->http->resolveNode();
        try {
            $raw = $this->http->get("nodes/{$node}/qemu");
        } catch (Throwable $e) {
            return ['servers' => [], 'total' => 0];
        }

        $servers = [];
        foreach ($raw['data'] ?? [] as $vm) {
            $servers[] = $this->mapServer($vm, $node);
        }

        return ['servers' => $servers, 'total' => count($servers)];
    }

    // ── Get single VM ─────────────────────────────────────────

    public function get(int $vmid): array
    {
        $node = $this->http->resolveNode();

        try {
            $raw = $this->http->get("nodes/{$node}/qemu/{$vmid}/status/current");
            $vm  = $raw['data'] ?? [];
        } catch (Throwable $e) {
            throw new RuntimeException("VM {$vmid} not found: " . $e->getMessage());
        }

        // Get config for OS info
        try {
            $cfg = $this->http->get("nodes/{$node}/qemu/{$vmid}/config");
            $vm  = array_merge($vm, $cfg['data'] ?? []);
        } catch (Throwable $e) {}

        // Get IP from guest agent
        try {
            $ifaces = $this->http->get("nodes/{$node}/qemu/{$vmid}/agent/network-get-interfaces");
            $vm['_agent_ips'] = $ifaces['data']['result'] ?? [];
        } catch (Throwable $e) {
            $vm['_agent_ips'] = [];
        }

        $vm['vmid'] = $vm['vmid'] ?? $vmid;
        return $this->mapServer($vm, $node);
    }

    // ── Field mapper ──────────────────────────────────────────

    public function mapServer(array $vm, string $node = ''): array
    {
        if (empty($vm)) return [];

        $vmid   = (int)($vm['vmid'] ?? 0);
        $name   = $vm['name'] ?? $vm['hostname'] ?? ('vm-' . $vmid);
        $status = $this->mapStatus($vm['status'] ?? 'stopped');

        // RAM: Proxmox gives MB
        $ram_mb = (int)($vm['maxmem']  ?? $vm['memory'] ?? 0);
        $ram_gb = $ram_mb ? round($ram_mb / (1024 * 1024), 1) : 0;

        // Disk: maxdisk in bytes
        $disk_b  = (int)($vm['maxdisk'] ?? 0);
        $disk_gb = $disk_b ? (int)round($disk_b / (1024 ** 3)) : 0;

        // CPU
        $vcpu = (int)($vm['cpus'] ?? $vm['cores'] ?? 1);

        // IP — from agent interfaces first, then net0 config
        $ipv4 = $this->extractIpv4($vm['_agent_ips'] ?? []);
        if (!$ipv4 && !empty($vm['net0'])) {
            // net0 = "virtio=XX:XX:XX:XX:XX:XX,bridge=vmbr0" — no IP here
            // IP only from agent or DHCP
        }

        // OS label — from description or ostype
        $os_label = $this->osLabel($vm['ostype'] ?? '', $vm['description'] ?? '');
        $os_name  = $this->osName($os_label);

        // Bandwidth — Proxmox does not expose per-VM bandwidth limit via API
        // netin/netout are current counters in bytes since last reset
        $net_in  = (int)($vm['netin']  ?? 0);
        $net_out = (int)($vm['netout'] ?? 0);
        $used_bw = round(($net_in + $net_out) / (1024 ** 3), 3);

        return [
            'id'          => $vmid,
            'name'        => $name,
            'status'      => $status,
            'raw_status'  => $vm['status'] ?? '',

            'ipv4'        => $ipv4,
            'ipv6'        => null,

            'vcpu'        => $vcpu,
            'ram_gb'      => (float)$ram_gb,
            'disk_gb'     => $disk_gb,

            'plan_slug'   => (string)$vmid,
            'plan_label'  => 'VM-' . $vmid,

            'os_name'     => $os_name,
            'os_version'  => '',
            'os_label'    => $os_label,
            'image_slug'  => $os_name,

            'region_slug'  => $node ?: ($vm['node'] ?? ''),
            'region_label' => ucfirst($node ?: ($vm['node'] ?? '')),
            'region_flag'  => 'in',

            'created_at'       => null,
            'price_hourly_usd' => 0.0,

            'bandwidth_gb'      => 0,
            'used_bandwidth_gb' => $used_bw,
        ];
    }

    // ── Status mapper ─────────────────────────────────────────

    public function mapStatus(string $raw): string
    {
        return match(strtolower($raw)) {
            'running'                    => 'running',
            'stopped', 'shutoff'        => 'stopped',
            'paused'                     => 'stopped',
            'prelaunch', 'wait'         => 'provisioning',
            default                      => 'stopped',
        };
    }

    // ── Private helpers ───────────────────────────────────────

    private function extractIpv4(array $ifaces): ?string
    {
        foreach ($ifaces as $iface) {
            if (($iface['name'] ?? '') === 'lo') continue;
            foreach ($iface['ip-addresses'] ?? [] as $ip) {
                if (($ip['ip-address-type'] ?? '') === 'ipv4') {
                    $addr = $ip['ip-address'] ?? '';
                    if ($addr && !str_starts_with($addr, '127.') && !str_starts_with($addr, '169.254.')) {
                        return $addr;
                    }
                }
            }
        }
        return null;
    }

    private function osLabel(string $ostype, string $desc): string
    {
        // Try description first for human-readable label
        if ($desc) {
            $first_line = trim(explode("\n", $desc)[0]);
            if (strlen($first_line) < 60 && $first_line) return $first_line;
        }

        return match(strtolower($ostype)) {
            'l26', 'l24' => 'Linux',
            'win11'      => 'Windows 11',
            'win10'      => 'Windows 10',
            'win2k22'    => 'Windows Server 2022',
            'win2k19'    => 'Windows Server 2019',
            'win2k16'    => 'Windows Server 2016',
            'win2k12r2'  => 'Windows Server 2012 R2',
            'wxp','w2k' => 'Windows XP/2000',
            'solaris'    => 'Solaris',
            'other'      => 'Other OS',
            default      => $ostype ?: 'Linux',
        };
    }

    private function osName(string $label): string
    {
        $lower = strtolower($label);
        foreach (['ubuntu','debian','centos','rocky','alma','fedora','windows','arch','opensuse'] as $os) {
            if (str_contains($lower, $os)) return $os;
        }
        return 'linux';
    }
}
