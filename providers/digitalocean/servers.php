<?php
/**
 * providers/digitalocean/servers.php
 *
 * DigitalOcean Droplet operations.
 *
 * DO terminology → Panel neutral:
 *   id             → id (provider_id in DB)
 *   name           → name
 *   status         → mapped status
 *   networks.v4[0].ip_address → ipv4
 *   networks.v6[0].ip_address → ipv6
 *   size_slug      → plan_slug
 *   vcpus          → vcpu
 *   memory (MB)    → ram_gb
 *   disk (GB)      → disk_gb
 *   region.slug    → region_slug
 *   region.name    → region_label
 *   image.slug/distribution → os info
 */

declare(strict_types=1);

class DOServers
{
    private DOClient $http;

    public function __construct(DOClient $http)
    {
        $this->http = $http;
    }

    public function list(int $page = 1, int $perPage = 100): array
    {
        $raw  = $this->http->get('/droplets', ['page' => $page, 'per_page' => $perPage]);
        $list = array_map([$this, 'mapServer'], $raw['droplets'] ?? []);
        return [
            'servers'  => $list,
            'total'    => $raw['meta']['total'] ?? count($list),
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    public function get(int $dropletId): array
    {
        $raw = $this->http->get('/droplets/' . $dropletId);
        if (empty($raw['droplet'])) throw new RuntimeException('Droplet not found.');
        return $this->mapServer($raw['droplet']);
    }

    /**
     * $opts keys (neutral):
     *   name        — droplet name
     *   plan        — size slug e.g. "s-1vcpu-1gb"
     *   image       — image slug e.g. "ubuntu-22-04-x64"
     *   region      — region slug e.g. "blr1"
     *   ssh_key_ids — array of DO SSH key IDs (integers)
     *   backups     — bool
     *   user_data   — cloud-init script
     */
    public function create(array $opts): array
    {
        $payload = [
            'name'     => $opts['name'],
            'region'   => $opts['region'],
            'size'     => $opts['plan'],
            'image'    => $opts['image'],
            'backups'  => (bool)($opts['backups'] ?? false),
            'ipv6'     => true,
            'monitoring'=> true,
        ];

        if (!empty($opts['ssh_key_ids'])) {
            $payload['ssh_keys'] = array_map('intval', $opts['ssh_key_ids']);
        }
        if (!empty($opts['user_data'])) {
            $payload['user_data'] = $opts['user_data'];
        }

        $raw = $this->http->post('/droplets', $payload);

        if (empty($raw['droplet'])) {
            throw new RuntimeException(DOClient::errMsg($raw, 'Failed to create droplet.'));
        }

        return [
            'server'        => $this->mapServer($raw['droplet']),
            'root_password' => null, // DO uses SSH keys; password sent via email if no SSH key
            'action'        => ['id' => $raw['links']['actions'][0]['id'] ?? null, 'status' => 'in-progress'],
        ];
    }

    public function delete(int $dropletId): bool
    {
        $raw = $this->http->delete('/droplets/' . $dropletId);
        return ($raw['_http_status'] ?? 0) === 204;
    }

    public function mapServer(array $s): array
    {
        if (empty($s)) return [];

        // IPs
        $ipv4 = null;
        $ipv6 = null;
        foreach ($s['networks']['v4'] ?? [] as $net) {
            if ($net['type'] === 'public') { $ipv4 = $net['ip_address']; break; }
        }
        foreach ($s['networks']['v6'] ?? [] as $net) {
            if ($net['type'] === 'public') { $ipv6 = $net['ip_address']; break; }
        }

        // Region
        $region_slug  = $s['region']['slug'] ?? '';
        $region_name  = $s['region']['name'] ?? $region_slug;

        // OS
        $image = $s['image'] ?? [];

        return [
            'id'          => (int)($s['id'] ?? 0),
            'name'        => $s['name'] ?? '',
            'status'      => $this->mapStatus($s['status'] ?? ''),
            'raw_status'  => $s['status'] ?? '',

            'ipv4'        => $ipv4,
            'ipv6'        => $ipv6,

            'vcpu'        => (int)($s['vcpus']  ?? 0),
            'ram_gb'      => round(($s['memory'] ?? 0) / 1024, 1),
            'disk_gb'     => (int)($s['disk']   ?? 0),
            'plan_slug'   => $s['size_slug']    ?? '',
            'plan_label'  => strtoupper($s['size_slug'] ?? ''),

            'os_name'     => $this->osName($image['distribution'] ?? ''),
            'os_version'  => $image['name'] ?? '',
            'os_label'    => trim(($image['distribution'] ?? '') . ' ' . ($image['name'] ?? '')),
            'image_slug'  => $image['slug'] ?? (string)($image['id'] ?? ''),

            'region_slug'  => $region_slug,
            'region_label' => $region_name . ', ' . $this->regionCountry($region_slug),
            'region_flag'  => $this->regionFlag($region_slug),

            'created_at'        => $s['created_at'] ?? null,
            'price_hourly_usd'  => (float)($s['size']['price_hourly'] ?? 0),

            // Bandwidth — DO: size.transfer = TB included/month → convert to GB
            // networks.v4.transfer is not in the droplet object; used comes from /v2/droplets/{id}/bandwidth
            // For now we store included from plan, used_bandwidth = 0 (updated separately if DO supports it)
            'bandwidth_gb'      => (int)(($s['size']['transfer'] ?? 0) * 1024),
            'used_bandwidth_gb' => round(($s['outbound_bandwidth_bytes'] ?? 0) / (1024 ** 3), 3),
        ];
    }

    public function mapStatus(string $raw): string
    {
        return match($raw) {
            'active'    => 'running',
            'off'       => 'stopped',
            'new'       => 'provisioning',
            'archive'   => 'stopped',
            default     => 'provisioning',
        };
    }

    private function regionCountry(string $slug): string
    {
        $map = [
            'nyc1'=>'United States','nyc2'=>'United States','nyc3'=>'United States',
            'sfo1'=>'United States','sfo2'=>'United States','sfo3'=>'United States',
            'ams2'=>'Netherlands','ams3'=>'Netherlands',
            'sgp1'=>'Singapore',
            'lon1'=>'United Kingdom',
            'fra1'=>'Germany',
            'tor1'=>'Canada',
            'blr1'=>'India',
            'syd1'=>'Australia',
        ];
        return $map[$slug] ?? '';
    }

    private function regionFlag(string $slug): string
    {
        if (in_array($slug, ['nyc1','nyc2','nyc3','sfo1','sfo2','sfo3'])) return 'us';
        if (in_array($slug, ['ams2','ams3'])) return 'nl';
        if ($slug === 'sgp1') return 'sg';
        if ($slug === 'lon1') return 'gb';
        if ($slug === 'fra1') return 'de';
        if ($slug === 'tor1') return 'ca';
        if ($slug === 'blr1') return 'in';
        if ($slug === 'syd1') return 'au';
        return 'us';
    }

    private function osName(string $distro): string
    {
        $map = ['Ubuntu'=>'ubuntu','Debian'=>'debian','CentOS'=>'centos',
                'Fedora'=>'fedora','Rocky Linux'=>'rocky','AlmaLinux'=>'alma',
                'Windows'=>'windows','Arch Linux'=>'arch'];
        return $map[$distro] ?? strtolower($distro) ?: 'linux';
    }
}