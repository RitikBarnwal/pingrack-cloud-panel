<?php
/**
 * providers/hetzner/servers.php
 *
 * Server (compute instance) operations.
 * All provider-specific field names are mapped to
 * neutral keys before returning to callers.
 */

declare(strict_types=1);

require_once __DIR__ . '/client.php';

class CloudServers
{
    private CloudProviderClient $http;

    public function __construct(CloudProviderClient $http)
    {
        $this->http = $http;
    }

    /* ────────────────────────────────────────────
       List all servers
    ──────────────────────────────────────────── */

    /**
     * Returns array of mapped server objects.
     */
    public function list(int $page = 1, int $perPage = 50): array
    {
        $raw = $this->http->get('/servers', [
            'page'     => $page,
            'per_page' => $perPage,
        ]);

        $servers = [];
        foreach ($raw['servers'] ?? [] as $s) {
            $servers[] = $this->mapServer($s);
        }

        return [
            'servers'    => $servers,
            'total'      => $raw['meta']['pagination']['total_entries'] ?? count($servers),
            'page'       => $page,
            'per_page'   => $perPage,
        ];
    }

    /* ────────────────────────────────────────────
       Get single server
    ──────────────────────────────────────────── */

    public function get(int $serverId): array
    {
        $raw = $this->http->get('/servers/' . $serverId);

        if (empty($raw['server'])) {
            throw new RuntimeException('Server not found.');
        }

        return $this->mapServer($raw['server']);
    }

    /* ────────────────────────────────────────────
       Create server
    ──────────────────────────────────────────── */

    /**
     * $opts keys (all neutral):
     *   name        string  — server hostname
     *   plan        string  — plan slug (e.g. "cx22")
     *   image       string  — OS image slug (e.g. "ubuntu-24.04")
     *   region      string  — datacenter slug (e.g. "nbg1")
     *   ssh_key_ids array   — list of SSH key IDs
     *   user_data   string  — cloud-init script (optional)
     *   backups     bool    — enable automated backups
     */
    public function create(array $opts): array
    {
        $payload = [
            'name'        => $opts['name'],
            'server_type' => $opts['plan'],
            'image'       => $opts['image'],
            'location'    => $opts['region'],
            'ssh_keys'    => $opts['ssh_key_ids'] ?? [],
            'user_data'   => $opts['user_data']   ?? '',
            'automount'   => false,
            'start_after_create' => true,
        ];

        if (!empty($opts['backups'])) {
            $payload['backups'] = true;
        }

        $raw = $this->http->post('/servers', $payload);

        if (empty($raw['server'])) {
            $msg = $raw['error']['message'] ?? 'Failed to create server.';
            throw new RuntimeException($msg);
        }

        return [
            'server'       => $this->mapServer($raw['server']),
            'root_password'=> $raw['root_password'] ?? null,
            'action'       => $this->mapAction($raw['action'] ?? []),
        ];
    }

    /* ────────────────────────────────────────────
       Delete server
    ──────────────────────────────────────────── */

    public function delete(int $serverId): bool
    {
        $raw = $this->http->delete('/servers/' . $serverId);
        return ($raw['_http_status'] ?? 0) === 200;
    }

    /* ────────────────────────────────────────────
       Rename server
    ──────────────────────────────────────────── */

    public function rename(int $serverId, string $name): array
    {
        $raw = $this->http->put('/servers/' . $serverId, ['name' => $name]);
        return $this->mapServer($raw['server'] ?? []);
    }

    /* ────────────────────────────────────────────
       Field mapper — provider keys → neutral keys
    ──────────────────────────────────────────── */

    public function mapServer(array $s): array
    {
        if (empty($s)) return [];

        // Determine primary IPv4
        $ipv4 = $s['public_net']['ipv4']['ip']        ?? null;
        $ipv6 = $s['public_net']['ipv6']['ip']        ?? null;

        // CPU/RAM/Disk from server_type
        $st   = $s['server_type'] ?? [];

        // Location
        $loc  = $s['datacenter']['location'] ?? [];

        return [
            'id'          => $s['id']          ?? null,
            'name'        => $s['name']         ?? '',
            'status'      => $this->mapStatus($s['status'] ?? ''),
            'raw_status'  => $s['status']       ?? '',

            // Network
            'ipv4'        => $ipv4,
            'ipv6'        => $ipv6,

            // Specs
            'vcpu'        => $st['cores']       ?? 0,
            'ram_gb'      => $st['memory']      ?? 0,
            'disk_gb'     => $st['disk']        ?? 0,
            'plan_slug'   => $st['name']        ?? '',
            'plan_label'  => strtoupper($st['name'] ?? ''),

            // OS
            'os_name'     => $s['image']['os_flavor']  ?? '',
            'os_version'  => $s['image']['os_version'] ?? '',
            'os_label'    => $this->osLabel($s['image'] ?? []),

            // Location
            'region_slug' => $loc['name']        ?? '',
            'region_label'=> $loc['city'] . ', ' . strtoupper($loc['country'] ?? ''),
            'region_flag' => strtolower($loc['country'] ?? 'de'),

            // Timestamps
            'created_at'  => $s['created']      ?? null,

            // Pricing (hourly, provider gives EUR — we convert if needed)
            'price_hourly_eur' => (float)($st['prices'][0]['price_hourly']['gross'] ?? 0),

            // Bandwidth — Hetzner gives bytes, convert to GB
            // outgoing_traffic = bytes used this billing period
            // included_traffic = bytes included in plan
            'bandwidth_gb'      => (int)round(($s['included_traffic'] ?? 0) / (1024 ** 3)),
            'used_bandwidth_gb' => round(($s['outgoing_traffic']  ?? 0) / (1024 ** 3), 3),
        ];
    }

    /* ─── Status mapper ─────────────────────── */

    private function mapStatus(string $raw): string
    {
        return match($raw) {
            'running'     => 'running',
            'off'         => 'stopped',
            'stopping'    => 'stopping',
            'starting'    => 'starting',
            'migrating'   => 'migrating',
            'rebuilding'  => 'rebuilding',
            'deleting'    => 'deleting',
            default       => 'unknown',
        };
    }

    /* ─── OS label ──────────────────────────── */

    private function osLabel(array $image): string
    {
        $flavor  = ucfirst($image['os_flavor']  ?? '');
        $version = $image['os_version'] ?? '';
        return trim("$flavor $version");
    }

    /* ─── Action mapper ─────────────────────── */

    private function mapAction(array $a): array
    {
        return [
            'id'       => $a['id']       ?? null,
            'status'   => $a['status']   ?? '',
            'progress' => $a['progress'] ?? 0,
        ];
    }
}