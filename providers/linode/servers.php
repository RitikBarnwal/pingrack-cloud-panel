<?php
/**
 * providers/linode/servers.php
 *
 * Linode "Linode" (instance) operations.
 * All Linode-specific field names are mapped to neutral panel keys.
 *
 * Linode terminology → Panel terminology:
 *   linode_type   → plan_slug
 *   region        → region_slug
 *   image         → image_slug
 *   label         → name
 *   status        → mapped status
 *   specs.vcpus   → vcpu
 *   specs.memory  → ram_gb (MB → GB)
 *   specs.disk    → disk_gb (MB → GB)
 *   ipv4[0]       → ipv4
 */

declare(strict_types=1);

class LinodeServers
{
    private LinodeClient $http;

    public function __construct(LinodeClient $http)
    {
        $this->http = $http;
    }

    // ── List all instances ────────────────────────────────────

    public function list(int $page = 1, int $perPage = 100): array
    {
        $raw  = $this->http->get('/linode/instances', ['page' => $page, 'page_size' => $perPage]);
        $list = [];
        foreach ($raw['data'] ?? [] as $s) {
            $list[] = $this->mapServer($s);
        }
        return [
            'servers'   => $list,
            'total'     => $raw['results'] ?? count($list),
            'page'      => $raw['page']    ?? $page,
            'per_page'  => $perPage,
        ];
    }

    // ── Get single instance ───────────────────────────────────

    public function get(int $linodeId): array
    {
        $raw = $this->http->get('/linode/instances/' . $linodeId);
        if (empty($raw['id'])) {
            throw new RuntimeException('Linode instance not found.');
        }
        return $this->mapServer($raw);
    }

    // ── Create instance ───────────────────────────────────────

    /**
     * $opts keys (neutral):
     *   name        — label (3–64 chars, alphanumeric + dash)
     *   plan        — linode_type slug e.g. "g6-nanode-1"
     *   image       — image slug e.g. "linode/ubuntu24.04"
     *   region      — region id e.g. "ap-south"
     *   ssh_key_ids — array of DB SSH key IDs (we resolve to Linode IDs)
     *   root_pass   — root password (optional, auto-generated if not set)
     *   backups     — bool
     *   user_data   — cloud-init (base64 or plain text)
     */
    public function create(array $opts): array
    {
        // Linode requires a root_password
        $root_pass = $opts['root_pass'] ?? $this->generatePassword();

        $payload = [
            'label'       => $opts['name'],
            'type'        => $opts['plan'],
            'image'       => $opts['image'],
            'region'      => $opts['region'],
            'root_pass'   => $root_pass,
            'booted'      => true,
        ];

        if (!empty($opts['ssh_key_ids'])) {
            // ssh_key_ids here are Linode authorized_keys strings
            // Panel passes provider_id (Linode SSH key IDs)
            $payload['authorized_keys'] = $opts['ssh_key_ids'];
        }

        if (!empty($opts['backups'])) {
            $payload['backups_enabled'] = true;
        }

        if (!empty($opts['user_data'])) {
            $payload['metadata'] = [
                'user_data' => base64_encode($opts['user_data']),
            ];
        }

        $raw = $this->http->post('/linode/instances', $payload);

        if (empty($raw['id'])) {
            $msg = LinodeClient::errorMessage($raw, 'Failed to create Linode instance.');
            throw new RuntimeException($msg);
        }

        return [
            'server'        => $this->mapServer($raw),
            'root_password' => $root_pass,
            'action'        => ['id' => null, 'status' => 'running', 'progress' => 0],
        ];
    }

    // ── Delete instance ───────────────────────────────────────

    public function delete(int $linodeId): bool
    {
        $raw = $this->http->delete('/linode/instances/' . $linodeId);
        return ($raw['_http_status'] ?? 0) === 204;
    }

    // ── Field mapper: Linode → neutral panel format ───────────

    public function mapServer(array $s): array
    {
        if (empty($s)) return [];

        $ipv4 = $s['ipv4'][0]  ?? null;  // first public IPv4
        $ipv6 = $s['ipv6']     ?? null;  // Linode gives full /128 address

        // Memory/disk come in MB, we convert to GB
        $ram_mb  = $s['specs']['memory'] ?? 0;
        $disk_mb = $s['specs']['disk']   ?? 0;
        $vcpu    = $s['specs']['vcpus']  ?? 0;
        $ram_gb  = $ram_mb  ? round($ram_mb  / 1024, 1) : 0;
        $disk_gb = $disk_mb ? (int)round($disk_mb / 1024)  : 0;

        // Region info
        $region_slug  = $s['region'] ?? '';
        $region_label = $this->regionLabel($region_slug);
        $region_flag  = $this->regionFlag($region_slug);

        // OS label from image
        $image_id  = $s['image'] ?? '';
        $os_label  = $this->osLabel($image_id);

        return [
            'id'          => $s['id']         ?? null,
            'name'        => $s['label']       ?? '',
            'status'      => $this->mapStatus($s['status'] ?? ''),
            'raw_status'  => $s['status']      ?? '',

            'ipv4'        => $ipv4,
            'ipv6'        => $ipv6 ? explode('/', $ipv6)[0] : null,

            'vcpu'        => (int)$vcpu,
            'ram_gb'      => (float)$ram_gb,
            'disk_gb'     => (int)$disk_gb,
            'plan_slug'   => $s['type']        ?? '',
            'plan_label'  => strtoupper($s['type'] ?? ''),

            'os_name'     => $this->osName($image_id),
            'os_version'  => '',
            'os_label'    => $os_label,
            'image_slug'  => $image_id,

            'region_slug'  => $region_slug,
            'region_label' => $region_label,
            'region_flag'  => $region_flag,

            'created_at'       => $s['created'] ?? null,
            'price_hourly_usd' => 0.0, // from plan_pricing DB

            // Bandwidth — Linode: specs.transfer = GB included/month
            // transfer.bytes_out = bytes used (from /instances/{id}/transfer endpoint, not here)
            // We store what we have: included from specs, used from transfer_total if available
            'bandwidth_gb'      => (int)($s['specs']['transfer'] ?? 0),
            'used_bandwidth_gb' => round(($s['transfer_total'] ?? 0) / (1024 ** 3), 3),
        ];
    }

    // ── Status mapper ─────────────────────────────────────────

    public function mapStatus(string $raw): string
    {
        return match($raw) {
            'running'         => 'running',
            'offline'         => 'stopped',
            'booting'         => 'starting',
            'shutting_down'   => 'stopping',
            'rebooting'       => 'starting',
            'provisioning'    => 'provisioning',
            'deleting'        => 'stopping',
            'migrating'       => 'provisioning',
            'rebuilding'      => 'rebuilding',
            'cloning'         => 'provisioning',
            'restoring'       => 'provisioning',
            default           => 'provisioning',
        };
    }

    // ── Region helpers ────────────────────────────────────────

    private function regionLabel(string $slug): string
    {
        $map = [
            'ap-south'      => 'Mumbai, India',
            'ap-southeast'  => 'Singapore, Singapore',
            'ap-northeast'  => 'Tokyo, Japan',
            'ap-west'       => 'Mumbai, India',
            'us-east'       => 'Newark, United States',
            'us-west'       => 'Fremont, United States',
            'us-central'    => 'Dallas, United States',
            'us-southeast'  => 'Atlanta, United States',
            'us-ord'        => 'Chicago, United States',
            'us-lax'        => 'Los Angeles, United States',
            'us-mia'        => 'Miami, United States',
            'us-sea'        => 'Seattle, United States',
            'eu-west'       => 'London, United Kingdom',
            'eu-central'    => 'Frankfurt, Germany',
            'ca-central'    => 'Toronto, Canada',
            'br-gru'        => 'São Paulo, Brazil',
            'id-cgk'        => 'Jakarta, Indonesia',
            'in-maa'        => 'Chennai, India',
            'jp-osa'        => 'Osaka, Japan',
            'nl-ams'        => 'Amsterdam, Netherlands',
            'se-sto'        => 'Stockholm, Sweden',
            'es-mad'        => 'Madrid, Spain',
            'fr-par'        => 'Paris, France',
            'au-mel'        => 'Melbourne, Australia',
        ];
        return $map[$slug] ?? ucwords(str_replace('-', ' ', $slug));
    }

    private function regionFlag(string $slug): string
    {
        $map = [
            'ap-south'    => 'in', 'ap-west'    => 'in', 'in-maa' => 'in',
            'ap-southeast'=> 'sg', 'id-cgk'     => 'id',
            'ap-northeast'=> 'jp', 'jp-osa'     => 'jp',
            'us-east'     => 'us', 'us-west'    => 'us', 'us-central' => 'us',
            'us-southeast'=> 'us', 'us-ord'     => 'us', 'us-lax'     => 'us',
            'us-mia'      => 'us', 'us-sea'     => 'us',
            'eu-west'     => 'gb', 'eu-central' => 'de',
            'nl-ams'      => 'nl', 'se-sto'     => 'se',
            'es-mad'      => 'es', 'fr-par'     => 'fr',
            'ca-central'  => 'ca', 'br-gru'     => 'br',
            'au-mel'      => 'au',
        ];
        return $map[$slug] ?? 'us';
    }

    // ── OS helpers ────────────────────────────────────────────

    private function osLabel(string $imageId): string
    {
        // Linode image IDs: "linode/ubuntu24.04", "linode/debian12", etc.
        if (!$imageId) return 'Linux';
        $name = str_replace('linode/', '', $imageId);
        $map = [
            'ubuntu22.04'     => 'Ubuntu 22.04',
            'ubuntu24.04'     => 'Ubuntu 24.04',
            'debian11'        => 'Debian 11',
            'debian12'        => 'Debian 12',
            'centos-stream-9' => 'CentOS Stream 9',
            'centos-stream-8' => 'CentOS Stream 8',
            'fedora38'        => 'Fedora 38',
            'fedora39'        => 'Fedora 39',
            'rocky-9'         => 'Rocky Linux 9',
            'rocky-8'         => 'Rocky Linux 8',
            'almalinux-9'     => 'AlmaLinux 9',
            'almalinux-8'     => 'AlmaLinux 8',
            'arch'            => 'Arch Linux',
            'opensuse15.6'    => 'openSUSE 15.6',
            'gentoo'          => 'Gentoo',
            'alpine3.19'      => 'Alpine 3.19',
            'kali'            => 'Kali Linux',
        ];
        return $map[$name] ?? ucfirst(str_replace(['-', '.'], [' ', '.'], $name));
    }

    private function osName(string $imageId): string
    {
        $name = strtolower(str_replace('linode/', '', $imageId));
        foreach (['ubuntu','debian','centos','fedora','rocky','alma','arch','opensuse','alpine','kali','gentoo'] as $os) {
            if (str_contains($name, $os)) return $os;
        }
        return 'linux';
    }

    // ── Password generator ────────────────────────────────────

    private function generatePassword(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
        $pass  = '';
        for ($i = 0; $i < 20; $i++) {
            $pass .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $pass;
    }
}