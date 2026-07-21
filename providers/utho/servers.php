<?php
/**
 * providers/utho/servers.php
 *
 * Utho Cloud Server (cloudinstance) operations.
 *
 * Utho terminology → Panel neutral:
 *   cloudid / id   → id (provider_id in DB)
 *   hostname       → name
 *   status         → mapped status
 *   ip             → ipv4
 *   ipv6           → ipv6
 *   cpu            → vcpu
 *   ram (MB)       → ram_gb
 *   disk (GB)      → disk_gb
 *   planid / plan  → plan_slug
 *   dcslug         → region_slug
 *   image / os     → os_label
 *
 * API endpoints:
 *   GET    /cloudinstances          — list all
 *   GET    /cloudinstances/{id}     — get single
 *   POST   /cloudinstances          — create
 *   DELETE /cloudinstances/{id}     — delete
 */

declare(strict_types=1);

class UthoServers
{
    private UthoClient $http;

    public function __construct(UthoClient $http)
    {
        $this->http = $http;
    }

    // ── List all ──────────────────────────────────────────────

    public function list(): array
    {
        $raw  = $this->http->get('/cloudinstances');
        $list = [];
        foreach ($raw['cloudinstances'] ?? $raw['data'] ?? [] as $s) {
            $list[] = $this->mapServer($s);
        }
        return ['servers' => $list, 'total' => count($list)];
    }

    // ── Get single ────────────────────────────────────────────

    public function get(int $cloudId): array
    {
        $raw = $this->http->get('/cloudinstances/' . $cloudId);
        $s   = $raw['cloudinstances'][0] ?? $raw['cloudinstance'] ?? $raw['data'][0] ?? null;
        if (!$s) throw new RuntimeException('Server not found.');
        return $this->mapServer($s);
    }

    // ── Create ────────────────────────────────────────────────

    /**
     * $opts keys (neutral):
     *   name        — hostname
     *   plan        — plan slug e.g. "10030" (Utho uses numeric plan IDs)
     *   image       — image slug e.g. "ubuntu-22.04-x86_64"
     *   region      — dcslug e.g. "innoida"
     *   ssh_key_ids — array of SSH key IDs (Utho uses provider IDs)
     *   root_pass   — root password (optional)
     *   backups     — bool
     */
    public function create(array $opts): array
    {
        $payload = [
            'dcslug'   => $opts['region'],
            'image'    => $opts['image'],
            'planid'   => $opts['plan'],
            'hostname' => $opts['name'],
        ];

        if (!empty($opts['ssh_key_ids'])) {
            $payload['sshkeys'] = implode(',', $opts['ssh_key_ids']);
        }

        if (!empty($opts['root_pass'])) {
            $payload['rootPassword'] = $opts['root_pass'];
        }

        if (!empty($opts['backups'])) {
            $payload['backups'] = 1;
        }

        $raw = $this->http->post('/cloudinstances', $payload);

        if (!UthoClient::isOk($raw)) {
            throw new RuntimeException(UthoClient::errMsg($raw, 'Failed to create server.'));
        }

        // Utho returns cloudid in response
        $server_data = $raw['cloudinstances'][0] ?? $raw['cloudinstance'] ?? $raw ?? [];
        $root_pass   = $raw['password'] ?? $raw['rootPassword'] ?? $opts['root_pass'] ?? null;

        return [
            'server'        => $this->mapServer($server_data),
            'root_password' => $root_pass,
            'action'        => ['id' => null, 'status' => 'running', 'progress' => 0],
        ];
    }

    // ── Delete ────────────────────────────────────────────────

    public function delete(int $cloudId): bool
    {
        $raw  = $this->http->delete('/cloudinstances/' . $cloudId);
        return UthoClient::isOk($raw);
    }

    // ── Field mapper: Utho → neutral ──────────────────────────

    public function mapServer(array $s): array
    {
        if (empty($s)) return [];

        // RAM: Utho gives in MB
        $ram_mb  = (int)($s['ram']  ?? $s['memory'] ?? 0);
        $ram_gb  = $ram_mb >= 1024 ? round($ram_mb / 1024, 1) : (float)$ram_mb; // sometimes already GB

        // Disk
        $disk_gb = (int)($s['disk'] ?? $s['storage'] ?? 0);

        // IPv4 — Utho may return array or string
        $ipv4 = null;
        if (!empty($s['ip'])) {
            $ipv4 = is_array($s['ip']) ? ($s['ip'][0]['ipaddress'] ?? null) : $s['ip'];
        }
        if (!$ipv4 && !empty($s['ips'])) {
            foreach ($s['ips'] as $ip) {
                if (($ip['type'] ?? '') === 'public' || !isset($ip['type'])) {
                    $ipv4 = $ip['ipaddress'] ?? $ip['ip'] ?? null;
                    break;
                }
            }
        }

        $region_slug = $s['dcslug'] ?? $s['dc'] ?? '';

        return [
            'id'          => (int)($s['cloudid'] ?? $s['id'] ?? 0),
            'name'        => $s['hostname'] ?? $s['name'] ?? '',
            'status'      => $this->mapStatus($s['status'] ?? ''),
            'raw_status'  => $s['status'] ?? '',

            'ipv4'        => $ipv4,
            'ipv6'        => $s['ipv6'] ?? null,

            'vcpu'        => (int)($s['cpu'] ?? $s['vcpu'] ?? 0),
            'ram_gb'      => (float)$ram_gb,
            'disk_gb'     => (int)$disk_gb,
            'plan_slug'   => (string)($s['planid'] ?? $s['plan'] ?? ''),
            'plan_label'  => strtoupper((string)($s['planid'] ?? $s['plan'] ?? '')),

            'os_name'     => $this->osName($s['image'] ?? $s['os'] ?? ''),
            'os_version'  => '',
            'os_label'    => $s['image'] ?? $s['os'] ?? '',
            'image_slug'  => $s['image'] ?? '',

            'region_slug'  => $region_slug,
            'region_label' => $this->regionLabel($region_slug),
            'region_flag'  => $this->regionFlag($region_slug),

            'created_at'        => $s['created_at'] ?? $s['createdat'] ?? null,
            'price_hourly_usd'  => 0.0, // from plan_pricing DB

            // Bandwidth — Utho provides GB values
            'bandwidth_gb'      => (int)($s['bandwidth'] ?? 0),
            'used_bandwidth_gb' => round((float)($s['used_bandwidth'] ?? $s['bandwidth_usage'] ?? 0), 3),
        ];
    }

    // ── Status mapper ─────────────────────────────────────────

    public function mapStatus(string $raw): string
    {
        return match(strtolower($raw)) {
            'active', 'running', 'on'        => 'running',
            'stopped', 'off', 'shutdown'     => 'stopped',
            'pending', 'build', 'building',
            'installing', 'creating'         => 'provisioning',
            'rebooting', 'reboot'            => 'starting',
            'stopping', 'shutting_down'      => 'stopping',
            'rebuilding', 'reinstalling'     => 'rebuilding',
            'suspended', 'locked'            => 'suspended',
            default                          => 'provisioning',
        };
    }

    // ── Region helpers ────────────────────────────────────────

    private function regionLabel(string $slug): string
    {
        $map = [
            'innoida'    => 'Noida, India',
            'inmumbai'   => 'Mumbai, India',
            'inbangalore'=> 'Bangalore, India',
            'inhyderabad'=> 'Hyderabad, India',
            'inchennai'  => 'Chennai, India',
            'inkolkata'  => 'Kolkata, India',
            'indelhincr' => 'Delhi NCR, India',
            'inpune'     => 'Pune, India',
            'innoida2'   => 'Noida 2, India',
            'us-east-1'  => 'Virginia, United States',
            'us-west-2'  => 'Oregon, United States',
            'sgsingapore'=> 'Singapore',
            'uk-london'  => 'London, United Kingdom',
            'de-frankfurt'=> 'Frankfurt, Germany',
        ];
        return $map[$slug] ?? ucwords(str_replace(['-','_'], ' ', $slug));
    }

    private function regionFlag(string $slug): string
    {
        if (str_starts_with($slug, 'in')) return 'in';
        if (str_starts_with($slug, 'us')) return 'us';
        if (str_starts_with($slug, 'sg')) return 'sg';
        if (str_starts_with($slug, 'uk')) return 'gb';
        if (str_starts_with($slug, 'de')) return 'de';
        if (str_contains($slug, 'mumbai') || str_contains($slug, 'noida') ||
            str_contains($slug, 'bangalore') || str_contains($slug, 'india')) return 'in';
        return 'in'; // Utho is Indian provider, default IN
    }

    // ── OS helpers ────────────────────────────────────────────

    private function osName(string $image): string
    {
        $image = strtolower($image);
        foreach (['ubuntu','debian','centos','fedora','rocky','alma','windows','arch','opensuse'] as $os) {
            if (str_contains($image, $os)) return $os;
        }
        return 'linux';
    }
}