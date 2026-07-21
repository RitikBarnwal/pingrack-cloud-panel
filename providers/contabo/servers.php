<?php
/**
 * providers/contabo/servers.php
 *
 * Contabo VPS instance (compute instance) operations.
 *
 * Contabo terminology → Panel neutral:
 *   instanceId       → id (provider_id in DB)
 *   name/displayName → name
 *   status           → mapped status
 *   ipConfig.v4.ip   → ipv4
 *   ipConfig.v6.ip   → ipv6
 *   productId        → plan_slug
 *   cpuCores         → vcpu
 *   memoryMb         → ram_gb (÷1024)
 *   diskMb           → disk_gb (÷1024)
 *   region           → region_slug
 *   imageId / osType → os_label
 *
 * API endpoints:
 *   GET    /compute/instances              — list all
 *   GET    /compute/instances/{id}         — get single
 *   POST   /compute/instances              — create (provision)
 *   DELETE /compute/instances/{id}         — cancel (scheduled)
 *   PATCH  /compute/instances/{id}         — rename
 */

declare(strict_types=1);

class ContaboServers
{
    private ContaboClient $http;

    public function __construct(ContaboClient $http)
    {
        $this->http = $http;
    }

    // ── List all ──────────────────────────────────────────────

    public function list(int $page = 1, int $size = 100): array
    {
        $raw  = $this->http->get('/compute/instances', ['page' => $page, 'size' => $size]);
        $list = [];
        foreach ($raw['data'] ?? [] as $s) {
            $list[] = $this->mapServer($s);
        }
        return [
            'servers'  => $list,
            'total'    => $raw['_pagination']['totalElements'] ?? count($list),
            'page'     => $page,
            'per_page' => $size,
        ];
    }

    // ── Get single ────────────────────────────────────────────

    public function get(int $instanceId): array
    {
        $raw = $this->http->get('/compute/instances/' . $instanceId);
        $s   = $raw['data'][0] ?? null;
        if (!$s) throw new RuntimeException('Instance not found.');
        return $this->mapServer($s);
    }

    // ── Create / Provision ────────────────────────────────────

    /**
     * $opts keys (neutral):
     *   name        — displayName
     *   plan        — productId e.g. "V1"
     *   image       — imageId (UUID from /compute/images)
     *   region      — region slug e.g. "EU", "US-central", "SIN"
     *   ssh_key_ids — array of secretId UUIDs
     *   root_pass   — rootPassword
     *   user_data   — cloud-init script
     */
    public function create(array $opts): array
    {
        $payload = [
            'imageId'       => $opts['image'],
            'productId'     => $opts['plan'],
            'region'        => $this->mapRegionSlugToContabo($opts['region']),
            'rootPassword'  => $opts['root_pass'] ?? $this->generatePassword(),
            'displayName'   => $opts['name'],
            'userData'      => $opts['user_data'] ?? '',
            'defaultUser'   => 'root',
        ];

        if (!empty($opts['ssh_key_ids'])) {
            // Contabo uses secretId array for SSH keys
            $payload['sshKeys'] = array_map('intval', $opts['ssh_key_ids']);
        }

        $raw = $this->http->post('/compute/instances', $payload);

        // Contabo returns 201 with instanceId in data[0]
        if (!ContaboClient::isOk($raw)) {
            throw new RuntimeException(ContaboClient::errMsg($raw, 'Failed to create instance.'));
        }

        $inst = $raw['data'][0] ?? [];
        $root_pass = $payload['rootPassword'];

        return [
            'server'        => $this->mapServer($inst),
            'root_password' => $root_pass,
            'action'        => ['id' => null, 'status' => 'running', 'progress' => 0],
        ];
    }

    // ── Delete (cancel) ───────────────────────────────────────

    public function delete(int $instanceId): bool
    {
        $raw = $this->http->delete('/compute/instances/' . $instanceId);
        return ContaboClient::isOk($raw);
    }

    // ── Rename ────────────────────────────────────────────────

    public function rename(int $instanceId, string $name): array
    {
        $raw = $this->http->patch('/compute/instances/' . $instanceId, ['displayName' => $name]);
        return $this->mapServer($raw['data'][0] ?? []);
    }

    // ── Field mapper: Contabo → neutral ───────────────────────

    public function mapServer(array $s): array
    {
        if (empty($s)) return [];

        // IP addresses
        $ipv4 = $s['ipConfig']['v4']['ip']   ?? null;
        $ipv6 = $s['ipConfig']['v6']['ip']   ?? null;

        // Specs — Contabo gives MB values
        $ram_mb  = (int)($s['memoryMb']  ?? 0);
        $disk_mb = (int)($s['diskMb']    ?? 0);
        $ram_gb  = $ram_mb  ? round($ram_mb  / 1024, 1) : 0;
        $disk_gb = $disk_mb ? (int)round($disk_mb / 1024)  : 0;

        // Region
        $region_slug = strtolower($s['region'] ?? '');

        return [
            'id'          => (int)($s['instanceId'] ?? 0),
            'name'        => $s['displayName'] ?? $s['name'] ?? '',
            'status'      => $this->mapStatus($s['status'] ?? ''),
            'raw_status'  => $s['status'] ?? '',

            'ipv4'        => $ipv4,
            'ipv6'        => $ipv6,

            'vcpu'        => (int)($s['cpuCores']   ?? 0),
            'ram_gb'      => (float)$ram_gb,
            'disk_gb'     => (int)$disk_gb,
            'plan_slug'   => $s['productId']   ?? '',
            'plan_label'  => strtoupper($s['productId'] ?? ''),

            'os_name'     => $this->osName($s['osType'] ?? ''),
            'os_version'  => '',
            'os_label'    => $s['osType'] ?? $s['imageId'] ?? '',
            'image_slug'  => $s['imageId'] ?? '',

            'region_slug'  => $region_slug,
            'region_label' => $this->regionLabel($region_slug),
            'region_flag'  => $this->regionFlag($region_slug),

            'created_at'        => $s['createdDate']     ?? null,
            'price_hourly_usd'  => 0.0,

            // Bandwidth — Contabo returns GiB values directly
            'bandwidth_gb'      => (int)($s['trafficLimitGiB'] ?? 0),
            'used_bandwidth_gb' => round((float)($s['trafficUsedGiB'] ?? 0), 3),
        ];
    }

    // ── Status mapper ─────────────────────────────────────────

    public function mapStatus(string $raw): string
    {
        return match(strtolower($raw)) {
            'running', 'started'                        => 'running',
            'stopped', 'shutoff', 'shutdown'            => 'stopped',
            'provisioning', 'deploying', 'installing'   => 'provisioning',
            'rebooting', 'restarting'                   => 'starting',
            'stopping', 'pausing'                       => 'stopping',
            'rebuilding', 'reinstalling'                => 'rebuilding',
            'error'                                     => 'stopped',
            default                                     => 'provisioning',
        };
    }

    // ── Region helpers ────────────────────────────────────────

    public function mapRegionSlugToContabo(string $slug): string
    {
        // Our DB stores lowercase slugs, Contabo uses uppercase region identifiers
        $map = [
            'eu'          => 'EU',
            'eu-1'        => 'EU',
            'de'          => 'EU',
            'germany'     => 'EU',
            'us-central'  => 'US-central',
            'us'          => 'US-central',
            'us-east'     => 'US-east',
            'us-west'     => 'US-west',
            'sin'         => 'SIN',
            'sg'          => 'SIN',
            'singapore'   => 'SIN',
            'aus'         => 'AUS',
            'australia'   => 'AUS',
            'jap'         => 'JPN',
            'japan'       => 'JPN',
            'jpn'         => 'JPN',
            'gbr'         => 'GBR',
            'uk'          => 'GBR',
        ];
        return $map[strtolower($slug)] ?? strtoupper($slug);
    }

    private function regionLabel(string $slug): string
    {
        $map = [
            'eu'         => 'Nuremberg, Germany',
            'us-central' => 'St. Louis, United States',
            'us-east'    => 'New York, United States',
            'us-west'    => 'Los Angeles, United States',
            'sin'        => 'Singapore',
            'aus'        => 'Sydney, Australia',
            'jpn'        => 'Tokyo, Japan',
            'gbr'        => 'London, United Kingdom',
        ];
        return $map[$slug] ?? ucwords(str_replace('-', ' ', $slug));
    }

    private function regionFlag(string $slug): string
    {
        $map = [
            'eu' => 'de', 'us-central' => 'us', 'us-east' => 'us',
            'us-west' => 'us', 'sin' => 'sg', 'aus' => 'au',
            'jpn' => 'jp', 'gbr' => 'gb',
        ];
        return $map[$slug] ?? 'de';
    }

    private function osName(string $osType): string
    {
        $t = strtolower($osType);
        foreach (['ubuntu','debian','centos','fedora','rocky','alma','windows','arch','opensuse'] as $os) {
            if (str_contains($t, $os)) return $os;
        }
        return 'linux';
    }

    private function generatePassword(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
        $pass  = '';
        for ($i = 0; $i < 20; $i++) $pass .= $chars[random_int(0, strlen($chars) - 1)];
        return $pass;
    }
}