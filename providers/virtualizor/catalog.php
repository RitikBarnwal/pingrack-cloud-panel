<?php
/**
 * providers/virtualizor/catalog.php
 *
 * Virtualizor catalog: nodes (regions), OS templates (images), plans, SSH keys.
 *
 * Virtualizor API acts:
 *   listservers  — list nodes/servers (our "regions")
 *   listostemplates / listtemplates — list OS templates
 *   listplans    — list VPS plans
 */

declare(strict_types=1);

class VirtualizorCatalog
{
    private VirtualizorClient $http;

    public function __construct(VirtualizorClient $http)
    {
        $this->http = $http;
    }

    // ── Raw HTTP pass-through ─────────────────────────────────

    public function getClient(): VirtualizorClient { return $this->http; }

    public function http_get(string $act, array $params = []): array  { return $this->http->get($act, $params); }
    public function http_post(string $act, array $params = [], array $body = []): array { return $this->http->post($act, $params, $body); }

    // ── Nodes / Regions ───────────────────────────────────────

    public function regions(): array
    {
        // Admin API: act=servers → { servers: { serid: { serid, server_name, location, ip, virt } } }
        $raw  = $this->http->get('servers');
        $rows = $raw['servers'] ?? $raw['servs'] ?? $raw['serverlist'] ?? [];
        if (empty($rows)) {
            try { $r2 = $this->http->get('listservers'); $rows = $r2['servers'] ?? $r2['servs'] ?? $r2['serverlist'] ?? []; }
            catch (Throwable $e) {}
        }

        $list = [];
        foreach ($rows as $id => $s) {
            if (!is_array($s)) continue;
            $name = $s['server_name'] ?? $s['name'] ?? 'Node ' . $id;
            // Virtualizor `location` is free text and often empty; keep it if present.
            $loc  = trim((string)($s['location'] ?? $s['city'] ?? ''));

            $list[] = [
                'slug'         => (string)($s['serid'] ?? $id),
                'label'        => $name,
                'city'         => $loc !== '' ? $loc : $name,
                'location'     => $loc,
                'ip'           => $s['ip'] ?? '',
            ];
        }
        return $list;
    }

    // ── OS Templates / Images ─────────────────────────────────
    // Admin API: act=os lists all OS templates WITHOUT needing a VPS id.
    // Response shapes vary by build, so we flatten recursively and collect
    // any entry that has an osid + name.

    public function images(int $vpsId = 0): array
    {
        $list = [];
        $seen = [];

        // Preferred: admin act=os (no VPS required)
        $rows = [];
        try {
            $raw = $this->http->get('os');
            $rows = $raw['oslist'] ?? $raw['os'] ?? $raw['ostemplates'] ?? [];
        } catch (Throwable $e) {}

        $collect = function ($node) use (&$collect, &$list, &$seen) {
            if (!is_array($node)) return;
            // Leaf OS entry: has a name and looks like an OS template
            $isLeaf = isset($node['name']) && (isset($node['osid']) || isset($node['fname']) || isset($node['distro']));
            if ($isLeaf) {
                $slug  = (string)($node['osid'] ?? '');
                $label = (string)($node['name'] ?? $node['fname'] ?? ('OS ' . $slug));
                if ($slug !== '' && !isset($seen[$slug])) {
                    $seen[$slug] = true;
                    [$os_name, $os_version] = $this->parseOs($label);
                    $list[] = [
                        'slug' => $slug, 'label' => $label,
                        'os' => $os_name, 'version' => $os_version,
                        'image_type' => 'system', 'app_description' => null,
                    ];
                }
                return;
            }
            // Otherwise recurse. Also handle {osid: "name"} flat maps.
            foreach ($node as $k => $v) {
                if (is_array($v)) { $collect($v); }
                elseif (is_string($v) && ctype_digit((string)$k)) {
                    $slug = (string)$k;
                    if (!isset($seen[$slug])) {
                        $seen[$slug] = true;
                        [$os_name, $os_version] = $this->parseOs($v);
                        $list[] = [
                            'slug' => $slug, 'label' => $v,
                            'os' => $os_name, 'version' => $os_version,
                            'image_type' => 'system', 'app_description' => null,
                        ];
                    }
                }
            }
        };
        $collect($rows);
        if (!empty($list)) return $list;

        // ── Fallback: enduser ostemplate (needs any VPS id) ──────────
        if ($vpsId <= 0) {
            foreach (['vs', 'listvs'] as $act) {
                try {
                    $sv = $this->http->get($act);
                    $vsList = $sv['vs'] ?? $sv['vpslist'] ?? [];
                    if (!empty($vsList)) {
                        $first = reset($vsList);
                        $vpsId = (int)($first['vpsid'] ?? $first['id'] ?? 0);
                        if ($vpsId > 0) break;
                    }
                } catch (Throwable $e) {}
            }
        }
        if ($vpsId <= 0) return $list;

        try {
            $raw = $this->http->get('ostemplate', ['svs' => $vpsId]);
            $collect($raw['oslist'] ?? []);
        } catch (Throwable $e) {}
        return $list;
    }

    // ── Plans ─────────────────────────────────────────────────

    public function plans(): array
    {
        // Virtualizor admin API lists VPS plans via act=plans (returns key `plans`).
        // Older/edge builds may answer to `listplans` / return `planlist`, so try
        // both acts and accept any of the known response keys.
        $raw = $this->http->get('plans');
        $rows = $raw['plans'] ?? $raw['planlist'] ?? $raw['plan'] ?? [];
        if (empty($rows)) {
            try {
                $raw2 = $this->http->get('listplans');
                $rows = $raw2['plans'] ?? $raw2['planlist'] ?? $raw2['plan'] ?? [];
            } catch (Throwable $e) { /* keep first result */ }
        }

        $list = [];
        foreach ($rows as $id => $p) {
            if (!is_array($p)) continue;
            // Per Virtualizor docs: plid, plan_name, ram (MB), cores, space (GB), bandwidth
            $planid = (string)($p['plid'] ?? $p['id'] ?? $id);
            $name   = $p['plan_name']  ?? $p['name'] ?? 'Plan ' . $planid;
            $ram_mb = (int)($p['ram']  ?? 0);                                  // RAM in MB
            $vcpu   = (int)($p['cores'] ?? $p['num_cores'] ?? $p['cpu'] ?? 1); // CPU cores
            // `space` is in GB; fall back to legacy disk_space/disk (MB) if present
            if (isset($p['space'])) {
                $disk_gb = (int)round((float)$p['space']);
                $disk_mb = $disk_gb * 1024;
            } else {
                $disk_mb = (int)($p['disk_space'] ?? $p['disk'] ?? 0);
                $disk_gb = $disk_mb ? (int)round($disk_mb / 1024) : 0;
            }
            $ram_gb = $ram_mb ? round($ram_mb / 1024, 1) : 0;

            // Virtualizor pricing may be INR or USD — depends on admin config
            // Store as base price, admin sets margin separately
            $price  = (float)($p['price']   ?? $p['bandwidth_price'] ?? 0);

            $list[] = [
                'slug'              => $planid,
                'label'             => $name,
                'vcpu'              => $vcpu,
                'ram_mb'            => $ram_mb,
                'ram_gb'            => $ram_gb,
                'disk_mb'           => $disk_mb,
                'disk_gb'           => $disk_gb,
                'price_monthly_inr' => $price,
                'price_hourly_inr'  => $price > 0 ? round($price / 730, 6) : 0,
                'class'             => 'shared',
            ];
        }
        return $list;
    }

    // ── SSH Keys — stored on individual VPS via cloud-init ────

    public function addSshKey(string $name, string $publicKey): array
    {
        // Virtualizor doesn't have a global SSH key store like cloud providers
        // SSH keys are passed during VPS creation
        return ['id' => $name, 'name' => $name, 'fingerprint' => '', 'public_key' => $publicKey];
    }

    public function deleteSshKey(int $id): bool { return true; }
    public function listSshKeys(): array         { return []; }

    // ── Firewall ─────────────────────────────────────────────

    public function createFirewall(string $name, array $rules = []): array { return ['id' => 0, 'name' => $name, 'rules' => $rules]; }
    public function deleteFirewall(int $id): bool { return true; }
    public function listFirewalls(): array         { return []; }

    // ── Status helpers ────────────────────────────────────────

    public function getVpsStatus(int $vpsId): array
    {
        $r = $this->http->get('vs_status', ['vpsid' => $vpsId]);
        return $r;
    }

    // ── Private helpers ───────────────────────────────────────

    private function parseOs(string $name): array
    {
        $lower = strtolower($name);
        $os_map = ['ubuntu','debian','centos','fedora','rocky','alma','almalinux',
                   'opensuse','windows','arch','alpine','kali'];
        foreach ($os_map as $os) {
            if (str_contains($lower, $os)) {
                preg_match('/(\d+\.?\d*)/', $name, $m);
                return [$os === 'almalinux' ? 'alma' : $os, $m[1] ?? ''];
            }
        }
        return ['linux', ''];
    }

    private function countryName(string $cc): string
    {
        $map = ['in'=>'India','us'=>'United States','sg'=>'Singapore',
                'gb'=>'United Kingdom','de'=>'Germany','au'=>'Australia',
                'jp'=>'Japan','nl'=>'Netherlands','fr'=>'France'];
        return $map[strtolower($cc)] ?? strtoupper($cc);
    }
}