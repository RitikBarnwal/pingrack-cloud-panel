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
        $raw  = $this->http->get('listservers');
        $list = [];

        foreach ($raw['servers'] ?? $raw['serverlist'] ?? [] as $id => $s) {
            $name = $s['server_name'] ?? $s['name'] ?? 'Server ' . $id;
            $city = $s['city']        ?? $s['location'] ?? $name;
            $cc   = strtolower($s['country_code'] ?? $s['country'] ?? 'in');

            $list[] = [
                'slug'         => (string)($s['serid'] ?? $id),
                'label'        => $name,
                'city'         => $city,
                'country'      => $this->countryName($cc),
                'country_code' => $cc,
                'country_flag' => $cc,
            ];
        }
        return $list;
    }

    // ── OS Templates / Images ─────────────────────────────────
    // Virtualizor user panel API: act=ostemplate&svs=VPSID
    // Returns: oslist.{virt_type}.{distro}.{osid} => {...}
    // We need any valid VPS ID to call this endpoint.

    public function images(int $vpsId = 0): array
    {
        $list = [];
        $seen = [];

        // Fetch ostemplate list — requires a VPS ID (any active VPS on this panel)
        // If vpsId not provided, try to get one from listservers
        if ($vpsId <= 0) {
            try {
                $sv = $this->http->get('listvs');
                $vsList = $sv['vs'] ?? $sv['vpslist'] ?? [];
                if (!empty($vsList)) {
                    $first = reset($vsList);
                    $vpsId = (int)($first['vpsid'] ?? $first['id'] ?? 0);
                }
            } catch (Throwable $e) {}
        }

        if ($vpsId <= 0) return $list; // Can't fetch without a VPS ID

        $raw    = $this->http->get('ostemplate', ['svs' => $vpsId]);
        $oslist = $raw['oslist'] ?? [];

        // oslist structure: {virt_type: {distro: {osid: {osid,name,distro,...}}}}
        foreach ($oslist as $virt_type => $distros) {
            if (!is_array($distros)) continue;
            foreach ($distros as $distro => $os_entries) {
                if (!is_array($os_entries)) continue;
                foreach ($os_entries as $osid => $t) {
                    if (!is_array($t)) continue;
                    $slug  = (string)($t['osid'] ?? $osid);
                    $label = $t['name'] ?? 'OS ' . $slug;
                    if (isset($seen[$slug])) continue;
                    $seen[$slug] = true;

                    [$os_name, $os_version] = $this->parseOs($label);

                    $list[] = [
                        'slug'            => $slug,
                        'label'           => $label,
                        'os'              => $os_name,
                        'version'         => $os_version,
                        'image_type'      => 'system',
                        'app_description' => null,
                    ];
                }
            }
        }
        return $list;
    }

    // ── Plans ─────────────────────────────────────────────────

    public function plans(): array
    {
        $raw  = $this->http->get('listplans');
        $list = [];

        foreach ($raw['plans'] ?? $raw['planlist'] ?? [] as $id => $p) {
            $planid = (string)($p['plid'] ?? $p['id'] ?? $id);
            $name   = $p['plan_name']  ?? $p['name'] ?? 'Plan ' . $planid;
            $ram_mb = (int)($p['ram']  ?? 0);
            $disk_mb= (int)($p['disk_space'] ?? $p['disk'] ?? 0);
            $vcpu   = (int)($p['num_cores']  ?? $p['cores'] ?? $p['cpu'] ?? 1);
            $ram_gb = $ram_mb  ? round($ram_mb  / 1024, 1) : 0;
            $disk_gb= $disk_mb ? (int)round($disk_mb / 1024) : 0;

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