<?php
/**
 * providers/linode/catalog.php
 *
 * Linode catalog: regions, images, plans (types), SSH keys.
 * Also exposes raw HTTP methods for the action handler.
 */

declare(strict_types=1);

class LinodeCatalog
{
    private LinodeClient $http;

    public function __construct(LinodeClient $http)
    {
        $this->http = $http;
    }

    // ── Raw HTTP pass-through (used by action handler) ────────

    public function http_get(string $path, array $params = []): array
    {
        return $this->http->get($path, $params);
    }

    public function http_post(string $path, array $body = []): array
    {
        return $this->http->post($path, $body);
    }

    public function http_put(string $path, array $body = []): array
    {
        return $this->http->put($path, $body);
    }

    public function http_delete(string $path): array
    {
        return $this->http->delete($path);
    }

    // ── Regions ───────────────────────────────────────────────

    public function regions(): array
    {
        $raw  = $this->http->get('/regions', ['page_size' => 100]);
        $list = [];

        foreach ($raw['data'] ?? [] as $r) {
            $country = $this->regionCountry($r['id'] ?? '');
            $list[] = [
                'slug'         => $r['id']          ?? '',
                'label'        => $r['label']        ?? '',
                'city'         => $this->regionCity($r['id'] ?? ''),
                'country'      => $country['name'],
                'country_code' => $country['code'],
                'country_flag' => $country['code'],
                'status'       => $r['status']       ?? 'ok',
                'capabilities' => $r['capabilities'] ?? [],
            ];
        }
        return $list;
    }

    // ── OS Images ─────────────────────────────────────────────

    public function images(): array
    {
        $raw  = $this->http->get('/images', ['page_size' => 100, 'type' => 'official', 'is_public' => 'true']);
        $list = [];

        foreach ($raw['data'] ?? [] as $img) {
            if (($img['deprecated'] ?? false) || ($img['status'] ?? '') !== 'available') continue;

            $id    = $img['id'] ?? '';
            $label = $img['label'] ?? $id;

            // Map to our OS name/version format
            [$os_name, $os_version] = $this->parseImageId($id, $label);

            $list[] = [
                'slug'       => $id,
                'label'      => $label,
                'os'         => $os_name,
                'version'    => $os_version,
                'os_name'    => $os_name,
                'os_version' => $os_version,
                'size_mb'    => $img['size'] ?? 0,
            ];
        }

        // Sort by OS name then version
        usort($list, fn($a, $b) => strcmp($a['os'] . $a['version'], $b['os'] . $b['version']));
        return $list;
    }

    // ── Plans (Linode Types) ──────────────────────────────────

    public function plans(): array
    {
        $raw  = $this->http->get('/linode/types', ['page_size' => 100]);
        $list = [];

        foreach ($raw['data'] ?? [] as $t) {
            // Skip deprecated types
            if (($t['successor'] ?? null) !== null) continue;

            $hourly_usd = (float)($t['addons']['backups']['price']['hourly'] ?? 0);
            // Actual plan hourly price
            $price_hourly = (float)($t['price']['hourly'] ?? 0);

            $list[] = [
                'slug'         => $t['id']     ?? '',
                'label'        => $t['label']  ?? '',
                'vcpu'         => (int)($t['vcpus']  ?? 0),
                'ram_mb'       => (int)($t['memory'] ?? 0),
                'ram_gb'       => round(($t['memory'] ?? 0) / 1024, 1),
                'disk_mb'      => (int)($t['disk']   ?? 0),
                'disk_gb'      => (int)round(($t['disk'] ?? 0) / 1024),
                'transfer_tb'  => round(($t['transfer'] ?? 0) / 1024, 1), // GB → TB
                'price_hourly_usd' => $price_hourly,
                'price_monthly_usd'=> (float)($t['price']['monthly'] ?? 0),
                'class'        => $t['class'] ?? 'standard', // nanode, standard, highmem, dedicated, gpu
            ];
        }

        return $list;
    }

    // ── SSH Keys ──────────────────────────────────────────────

    public function addSshKey(string $name, string $publicKey): array
    {
        $r = $this->http->post('/profile/sshkeys', [
            'label'   => $name,
            'ssh_key' => $publicKey,
        ]);

        if (!empty($r['id'])) {
            return [
                'id'          => $r['id'],
                'name'        => $r['label']     ?? $name,
                'fingerprint' => $r['fingerprint'] ?? '',
                'public_key'  => $r['ssh_key']   ?? $publicKey,
            ];
        }
        throw new RuntimeException(LinodeClient::errorMessage($r, 'SSH key error.'));
    }

    public function deleteSshKey(int $id): bool
    {
        $r = $this->http->delete('/profile/sshkeys/' . $id);
        return ($r['_http_status'] ?? 0) === 204;
    }

    public function listSshKeys(): array
    {
        $r = $this->http->get('/profile/sshkeys', ['page_size' => 100]);
        return array_map(fn($k) => [
            'id'          => $k['id'],
            'name'        => $k['label']       ?? '',
            'fingerprint' => $k['fingerprint'] ?? '',
        ], $r['data'] ?? []);
    }

    // ── Firewall management (project-level) ───────────────────

    public function createFirewall(string $label, array $rules = []): array
    {
        $linodeRules = $this->mapRulesToLinode($rules);
        $r = $this->http->post('/networking/firewalls', [
            'label' => $label,
            'rules' => $linodeRules,
        ]);

        if (!empty($r['id'])) {
            return ['id' => $r['id'], 'name' => $r['label'], 'rules' => $rules];
        }
        throw new RuntimeException(LinodeClient::errorMessage($r, 'Firewall error.'));
    }

    public function deleteFirewall(int $id): bool
    {
        $r = $this->http->delete('/networking/firewalls/' . $id);
        return ($r['_http_status'] ?? 0) === 204;
    }

    public function listFirewalls(): array
    {
        $r = $this->http->get('/networking/firewalls', ['page_size' => 100]);
        return array_map(fn($f) => [
            'id'     => $f['id'],
            'name'   => $f['label'],
            'status' => $f['status'] ?? 'enabled',
        ], $r['data'] ?? []);
    }

    // ── Private helpers ───────────────────────────────────────

    private function parseImageId(string $id, string $label): array
    {
        // id like "linode/ubuntu24.04", label like "Ubuntu 24.04 LTS"
        $name = strtolower(str_replace('linode/', '', $id));
        $os_map = [
            'ubuntu'    => 'ubuntu',
            'debian'    => 'debian',
            'centos'    => 'centos',
            'fedora'    => 'fedora',
            'rocky'     => 'rocky',
            'alma'      => 'alma',
            'arch'      => 'arch',
            'opensuse'  => 'opensuse',
            'alpine'    => 'alpine',
            'kali'      => 'kali',
            'gentoo'    => 'gentoo',
        ];

        $os_name    = 'linux';
        $os_version = '';

        foreach ($os_map as $key => $mapped) {
            if (str_contains($name, $key)) {
                $os_name = $mapped;
                // Extract version from label e.g. "Ubuntu 24.04 LTS" → "24.04"
                if (preg_match('/(\d+\.?\d*)/', $label, $m)) {
                    $os_version = $m[1];
                }
                break;
            }
        }

        return [$os_name, $os_version];
    }

    private function regionCity(string $slug): string
    {
        $map = [
            'ap-south' => 'Mumbai', 'ap-west' => 'Mumbai', 'in-maa' => 'Chennai',
            'ap-southeast' => 'Singapore', 'id-cgk' => 'Jakarta',
            'ap-northeast' => 'Tokyo', 'jp-osa' => 'Osaka',
            'us-east' => 'Newark', 'us-west' => 'Fremont', 'us-central' => 'Dallas',
            'us-southeast' => 'Atlanta', 'us-ord' => 'Chicago', 'us-lax' => 'Los Angeles',
            'us-mia' => 'Miami', 'us-sea' => 'Seattle',
            'eu-west' => 'London', 'eu-central' => 'Frankfurt',
            'nl-ams' => 'Amsterdam', 'se-sto' => 'Stockholm',
            'es-mad' => 'Madrid', 'fr-par' => 'Paris',
            'ca-central' => 'Toronto', 'br-gru' => 'São Paulo',
            'au-mel' => 'Melbourne',
        ];
        return $map[$slug] ?? ucwords(str_replace('-', ' ', $slug));
    }

    private function regionCountry(string $slug): array
    {
        $map = [
            'ap-south'    => ['name'=>'India',          'code'=>'in'],
            'ap-west'     => ['name'=>'India',          'code'=>'in'],
            'in-maa'      => ['name'=>'India',          'code'=>'in'],
            'ap-southeast'=> ['name'=>'Singapore',      'code'=>'sg'],
            'id-cgk'      => ['name'=>'Indonesia',      'code'=>'id'],
            'ap-northeast'=> ['name'=>'Japan',          'code'=>'jp'],
            'jp-osa'      => ['name'=>'Japan',          'code'=>'jp'],
            'us-east'     => ['name'=>'United States',  'code'=>'us'],
            'us-west'     => ['name'=>'United States',  'code'=>'us'],
            'us-central'  => ['name'=>'United States',  'code'=>'us'],
            'us-southeast'=> ['name'=>'United States',  'code'=>'us'],
            'us-ord'      => ['name'=>'United States',  'code'=>'us'],
            'us-lax'      => ['name'=>'United States',  'code'=>'us'],
            'us-mia'      => ['name'=>'United States',  'code'=>'us'],
            'us-sea'      => ['name'=>'United States',  'code'=>'us'],
            'eu-west'     => ['name'=>'United Kingdom', 'code'=>'gb'],
            'eu-central'  => ['name'=>'Germany',        'code'=>'de'],
            'nl-ams'      => ['name'=>'Netherlands',    'code'=>'nl'],
            'se-sto'      => ['name'=>'Sweden',         'code'=>'se'],
            'es-mad'      => ['name'=>'Spain',          'code'=>'es'],
            'fr-par'      => ['name'=>'France',         'code'=>'fr'],
            'ca-central'  => ['name'=>'Canada',         'code'=>'ca'],
            'br-gru'      => ['name'=>'Brazil',         'code'=>'br'],
            'au-mel'      => ['name'=>'Australia',      'code'=>'au'],
        ];
        return $map[$slug] ?? ['name' => ucwords(str_replace('-', ' ', $slug)), 'code' => 'us'];
    }

    /**
     * Convert panel-neutral rules to Linode inbound/outbound format.
     * Panel rule: {direction, protocol, port, source_ips}
     * Linode rule: {action, addresses, ports, protocol, label}
     */
    private function mapRulesToLinode(array $rules): array
    {
        $inbound  = [];
        $outbound = [];

        foreach ($rules as $rule) {
            $linodeRule = [
                'action'    => 'ACCEPT',
                'protocol'  => strtoupper($rule['protocol'] ?? 'TCP'),
                'ports'     => $rule['port'] ?? '',
                'addresses' => [
                    'ipv4' => $rule['source_ips'] ?? ['0.0.0.0/0'],
                    'ipv6' => ['::/0'],
                ],
                'label'     => $rule['description'] ?? 'rule',
            ];

            if (($rule['direction'] ?? 'in') === 'in') {
                $inbound[]  = $linodeRule;
            } else {
                $outbound[] = $linodeRule;
            }
        }

        return [
            'inbound'         => $inbound,
            'inbound_policy'  => 'DROP',
            'outbound'        => $outbound,
            'outbound_policy' => 'ACCEPT',
        ];
    }
}
