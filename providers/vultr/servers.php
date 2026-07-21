<?php
/**
 * providers/vultr/servers.php
 *
 * Vultr instance operations.
 *
 * Vultr field → neutral field:
 *   id            → id / provider_id
 *   label         → name
 *   status        → status (mapped)
 *   main_ip       → ipv4
 *   v6_main_ip    → ipv6
 *   vcpu_count    → vcpu
 *   ram           → ram_gb  (MB → GB)
 *   disk          → disk_gb
 *   plan          → plan_slug
 *   region        → region_slug
 *   os            → os_label
 *   allowed_bandwidth → bandwidth_gb (GB)
 *   netout        → used_bandwidth_gb (bytes → GB, only in detailed fetch)
 */
declare(strict_types=1);

class VultrServers
{
    private VultrClient $http;

    public function __construct(VultrClient $http)
    {
        $this->http = $http;
    }

    // ── List all instances ────────────────────────────────────

    public function list(int $page = 1, int $perPage = 100): array
    {
        $raw     = $this->http->get('/instances', ['per_page' => $perPage]);
        $servers = [];
        foreach ($raw['instances'] ?? [] as $s) {
            $servers[] = $this->mapServer($s);
        }
        return [
            'servers'  => $servers,
            'total'    => $raw['meta']['total'] ?? count($servers),
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    // ── Get single instance ───────────────────────────────────

    public function get(string $instanceId): array
    {
        $raw = $this->http->get('/instances/' . $instanceId);
        if (empty($raw['instance'])) {
            throw new RuntimeException('Vultr instance not found: ' . $instanceId);
        }
        return $this->mapServer($raw['instance']);
    }

    // ── Create instance ───────────────────────────────────────

    /**
     * $opts keys (neutral):
     *   name        string   — label
     *   plan        string   — Vultr plan ID (e.g. "vc2-1c-1gb")
     *   image       string   — OS ID (int) or snapshot ID
     *   region      string   — Vultr region slug (e.g. "ewr")
     *   ssh_key_ids array    — Vultr SSH key IDs
     *   user_data   string   — base64 cloud-init
     */
    public function create(array $opts): array
    {
        $body = [
            'region'   => $opts['region']    ?? '',
            'plan'     => $opts['plan']       ?? '',
            'label'    => $opts['name']       ?? 'server-' . time(),
            'hostname' => $opts['name']       ?? 'server-' . time(),
            'backups'  => 'disabled',
        ];

        // OS ID (numeric) or app/snapshot slug
        $image = $opts['image'] ?? '';
        if (is_numeric($image)) {
            $body['os_id'] = (int)$image;
        } elseif (str_starts_with((string)$image, 'app:')) {
            $body['app_id'] = (int)substr($image, 4);
        } elseif (str_starts_with((string)$image, 'snap:')) {
            $body['snapshot_id'] = substr($image, 5);
        } else {
            $body['os_id'] = (int)$image;
        }

        if (!empty($opts['ssh_key_ids'])) {
            $body['sshkey_id'] = array_values($opts['ssh_key_ids']);
        }
        if (!empty($opts['user_data'])) {
            $body['user_data'] = base64_encode($opts['user_data']);
        }

        $raw = $this->http->post('/instances', $body);
        $inst = $raw['instance'] ?? null;
        if (!$inst) {
            throw new RuntimeException('Vultr create failed: ' . json_encode($raw));
        }
        return $this->mapServer($inst);
    }

    // ── Field mapper ──────────────────────────────────────────

    public function mapServer(array $s): array
    {
        if (empty($s)) return [];

        $vcpu    = (int)($s['vcpu_count']   ?? $s['cpus']  ?? 1);
        $ram_mb  = (int)($s['ram']           ?? 0);
        $ram_gb  = $ram_mb ? round($ram_mb / 1024, 1) : 0;
        $disk_gb = (int)($s['disk']          ?? 0);

        $ipv4 = $s['main_ip'] ?? null;
        if ($ipv4 === '0.0.0.0') $ipv4 = null;
        $ipv6 = $s['v6_main_ip'] ?? null;
        if ($ipv6 === '') $ipv6 = null;

        // Bandwidth
        $total_bw = (int)($s['allowed_bandwidth'] ?? 0);   // GB
        // net_in + net_out come from detailed GET response in bytes
        $used_bw  = round(
            (((int)($s['netout'] ?? 0)) + ((int)($s['netin'] ?? 0))) / (1024 ** 3),
            3
        );

        // OS label
        $os_label = $s['os'] ?? 'Linux';
        $os_name  = $this->osName($os_label);

        // Hourly price (Vultr gives hourly cost in dollars)
        $price_usd = (float)($s['cost_per_month_usd'] ?? ($s['plan_cost'] ?? 0));
        // Approximate hourly from monthly
        $hourly_usd = $price_usd > 0 ? round($price_usd / 730, 8) : 0.0;

        // Region label from region slug
        $region_slug = $s['region'] ?? '';

        return [
            'id'           => $s['id']     ?? '',
            'name'         => $s['label']  ?? 'Unnamed',
            'status'       => $this->mapStatus($s['status'] ?? 'pending', $s['power_status'] ?? ''),
            'raw_status'   => $s['status'] ?? '',

            'ipv4'         => $ipv4,
            'ipv6'         => $ipv6,

            'vcpu'         => $vcpu,
            'ram_gb'       => (float)$ram_gb,
            'disk_gb'      => $disk_gb,

            'plan_slug'    => $s['plan']   ?? '',
            'os_label'     => $os_label,
            'image_slug'   => $os_name,

            'region_slug'  => $region_slug,
            'region_label' => '',  // enriched by catalog.regions()
            'region_flag'  => '',

            'created_at'       => $s['date_created'] ?? null,
            'price_hourly_usd' => $hourly_usd,

            'bandwidth_gb'      => $total_bw,
            'used_bandwidth_gb' => $used_bw,
        ];
    }

    // ── Status mapper ─────────────────────────────────────────

    public function mapStatus(string $status, string $powerStatus = ''): string
    {
        // Vultr has `status` (instance lifecycle) + `power_status` (running/stopped)
        return match (strtolower($status)) {
            'active'  => strtolower($powerStatus) === 'stopped' ? 'stopped' : 'running',
            'pending', 'installing' => 'provisioning',
            'migrating'             => 'provisioning',
            'suspended'             => 'suspended',
            'resizing'              => 'provisioning',
            default                 => 'provisioning',
        };
    }

    // ── Private helpers ───────────────────────────────────────

    private function osName(string $label): string
    {
        $lower = strtolower($label);
        foreach (['ubuntu','debian','centos','fedora','rocky','alma','almalinux','windows','freebsd','arch','alpine'] as $os) {
            if (str_contains($lower, $os)) return $os === 'almalinux' ? 'alma' : $os;
        }
        return 'linux';
    }
}
