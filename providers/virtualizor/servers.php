<?php
/**
 * providers/virtualizor/servers.php
 *
 * Virtualizor VPS operations.
 *
 * Virtualizor terminology → Panel neutral:
 *   vpsid / vps_info.vpsid → id (provider_id in DB)
 *   hostname               → name
 *   status (1=on, 0=off)   → mapped status
 *   ips[0]                 → ipv4
 *   ips_info               → ip details
 *   num_cores              → vcpu
 *   ram (MB)               → ram_gb
 *   disk_space (MB)        → disk_gb
 *   virt                   → virtualization type (kvm/openvz/xen etc.)
 *   os_name / osid         → os info
 *   serid / server_name    → node/location info
 *
 * Virtualizor API acts:
 *   listvs      — list VPS
 *   addvs       — create VPS
 *   deletevs    — delete VPS
 *   vpsdetails  — single VPS details
 */

declare(strict_types=1);

class VirtualizorServers
{
    private VirtualizorClient $http;

    public function __construct(VirtualizorClient $http)
    {
        $this->http = $http;
    }

    // ── List all VPS ──────────────────────────────────────────

    public function list(int $page = 1): array
    {
        $raw  = $this->http->get('listvs', ['page' => $page]);
        $list = [];

        foreach ($raw['vpslist'] ?? [] as $vpsid => $s) {
            $list[] = $this->mapServer($s);
        }

        return [
            'servers'  => $list,
            'total'    => count($list),
        ];
    }

    // ── Get single VPS ────────────────────────────────────────

    public function get(int $vpsId): array
{
    $raw = $this->http->post('vpsmanage', [], [
        'svs' => $vpsId
    ]);

    $info = $raw['info'] ?? null;

    if (!$info) {
        throw new RuntimeException('VPS not found.');
    }

    $s = $info['vps'] ?? [];

    // Normalize fields
    $s['vpsid']       = $s['vpsid'] ?? $vpsId;
    $s['hostname']    = $s['hostname'] ?? ($info['hostname'] ?? '');
    $s['status']      = $info['status'] ?? 0;
    $s['ips']         = $info['ip'] ?? [];

    // FIXES
    $s['disk_space']  = ((int)($s['space'] ?? 0)) * 1024;
    $s['server_name'] = $info['server_name'] ?? '';
    $s['serid']       = $info['serid'] ?? ($s['serid'] ?? '');

    return $this->mapServer($s);
}

    // ── Create VPS ────────────────────────────────────────────

    /**
     * $opts keys (neutral):
     *   name        — hostname
     *   plan        — plan name/id from Virtualizor plans
     *   image       — osid (OS template ID)
     *   region      — serid (server/node ID)
     *   ssh_key_ids — array of SSH public keys
     *   root_pass   — root password
     *   user_email  — user email for Virtualizor user
     */
    public function create(array $opts): array
    {
        $root_pass = $opts['root_pass'] ?? $this->generatePassword();

        $payload = [
            'hostname'    => $opts['name'],
            'rootpass'    => $root_pass,
            'osid'        => $opts['image'],
            'plid'        => $opts['plan'],
            'serid'       => $opts['region'],
            'user_email'  => $opts['user_email'] ?? '',
            'user_fname'  => 'Customer',
            'user_lname'  => '',
        ];

        if (!empty($opts['ssh_key_ids'])) {
            $payload['sshkeys'] = implode("\n", $opts['ssh_key_ids']);
        }

        $raw = $this->http->post('addvs', [], $payload);

        if (empty($raw['done']) || $raw['done'] != 1) {
            throw new RuntimeException(VirtualizorClient::errMsg($raw, 'Failed to create VPS.'));
        }

        $vpsid = $raw['vpsid'] ?? $raw['vps_info']['vpsid'] ?? null;

        return [
            'server'        => $vpsid ? $this->mapServer(['vpsid' => $vpsid, 'hostname' => $opts['name'], 'status' => 0]) : [],
            'root_password' => $root_pass,
            'action'        => ['id' => null, 'status' => 'in-progress'],
        ];
    }

    // ── Delete VPS ────────────────────────────────────────────

    public function delete(int $vpsId): bool
    {
        $raw = $this->http->post('deletevs', ['vpsid' => $vpsId]);
        return ($raw['done'] ?? 0) == 1;
    }

    // ── Field mapper: Virtualizor → neutral ───────────────────

    public function mapServer(array $s): array
{
    if (empty($s)) return [];

    // IP address
    $ipv4 = null;

    if (!empty($s['ips']) && is_array($s['ips'])) {
        $ipv4 = is_array($s['ips'][0])
            ? ($s['ips'][0]['ip'] ?? null)
            : $s['ips'][0];
    }

    if (!$ipv4 && !empty($s['ip'])) {
        $ipv4 = $s['ip'];
    }

    // RAM/DISK
    $ram_mb  = (int)($s['ram'] ?? $s['memory'] ?? 0);
    $ram_gb  = $ram_mb ? round($ram_mb / 1024, 1) : 0;

    // Virtualizor already gives disk in GB via "space"
    $disk_gb = (int)($s['space'] ?? 0);

    // Node/server
    $node_id   = (string)($s['serid'] ?? $s['server_id'] ?? '');
    $node_name = $s['server_name'] ?? $s['node'] ?? $node_id;

    return [
        'id'          => (int)($s['vpsid'] ?? 0),
        'name'        => $s['hostname'] ?? '',

        'status'      => $this->mapStatus((int)($s['status'] ?? 0)),
        'raw_status'  => $s['status'] ?? 0,

        'ipv4'        => $ipv4,
        'ipv6'        => null,

        'vcpu'        => (int)($s['num_cores'] ?? $s['cores'] ?? $s['cpu'] ?? 1),

        'ram_gb'      => (float)$ram_gb,
        'disk_gb'     => (int)$disk_gb,

        // Bandwidth
        'bandwidth_gb'      => (int)($s['bandwidth'] ?? 0),
        'used_bandwidth_gb' => (float)($s['used_bandwidth'] ?? 0),

        'plan_slug'   => (string)($s['plid'] ?? $s['plan_name'] ?? ''),
        'plan_label'  => $s['plan_name'] ?? strtoupper((string)($s['plid'] ?? '')),

        'os_name'     => $this->osName($s['os_name'] ?? $s['distro'] ?? ''),
        'os_version'  => '',
        'os_label'    => $s['os_name'] ?? $s['distro'] ?? '',
        'image_slug'  => (string)($s['osid'] ?? ''),

        'region_slug'  => $node_id,
        'region_label' => $node_name,
        'region_flag'  => 'in',

        'created_at'       => isset($s['time'])
            ? date('Y-m-d H:i:s', (int)$s['time'])
            : null,

        'price_hourly_usd' => 0.0,

        // Extra
        'virt'        => $s['virt'] ?? 'kvm',
        'node_id'     => $node_id,
    ];
}

    // ── Status mapper ─────────────────────────────────────────

    public function mapStatus(int $raw): string
    {
        // Virtualizor: 1=running, 0=stopped, -1=suspended, 2=in progress
        return match($raw) {
            1       => 'running',
            0       => 'stopped',
            -1      => 'suspended',
            2, 3    => 'provisioning',
            default => 'provisioning',
        };
    }

    // ── Helpers ───────────────────────────────────────────────

    private function osName(string $os): string
    {
        $os = strtolower($os);
        foreach (['ubuntu','debian','centos','fedora','rocky','alma','windows','arch','opensuse'] as $n) {
            if (str_contains($os, $n)) return $n;
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
