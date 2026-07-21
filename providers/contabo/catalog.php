<?php
/**
 * providers/contabo/catalog.php
 *
 * Contabo catalog: regions, OS images, plans (products), SSH keys.
 *
 * API endpoints:
 *   GET /compute/datacenters          — list datacenters/regions
 *   GET /compute/images               — list available OS images
 *   GET /product/v1/vps               — list VPS plans/products
 *   GET /v1/secrets?type=ssh          — list SSH key secrets
 *   POST /v1/secrets                  — add SSH key
 *   DELETE /v1/secrets/{id}           — delete SSH key
 */

declare(strict_types=1);

class ContaboCatalog
{
    private ContaboClient $http;

    public function __construct(ContaboClient $http)
    {
        $this->http = $http;
    }

    // ── Raw HTTP pass-through ─────────────────────────────────

    public function http_get(string $path, array $params = []): array { return $this->http->get($path, $params); }
    public function http_post(string $path, array $body = []): array  { return $this->http->post($path, $body); }
    public function http_put(string $path, array $body = []): array   { return $this->http->put($path, $body); }
    public function http_patch(string $path, array $body = []): array { return $this->http->patch($path, $body); }
    public function http_delete(string $path): array                  { return $this->http->delete($path); }

    // ── Regions / Datacenters ─────────────────────────────────

    public function regions(): array
    {
        $raw  = $this->http->get('/compute/datacenters');
        $list = [];

        foreach ($raw['data'] ?? [] as $dc) {
            $slug  = strtolower($dc['slug'] ?? $dc['regionSlug'] ?? $dc['name'] ?? '');
            $city  = $dc['city']    ?? $dc['name'] ?? '';
            $ctry  = strtolower($dc['countryCode'] ?? $this->regionCountry($slug)['code']);

            $list[] = [
                'slug'         => $slug,
                'label'        => $dc['name'] ?? $city,
                'city'         => $city,
                'country'      => $this->regionCountry($slug)['name'],
                'country_code' => $ctry,
                'country_flag' => $ctry,
            ];
        }

        // If API returns empty, use known Contabo regions
        if (empty($list)) {
            $list = $this->hardcodedRegions();
        }

        return $list;
    }

    // ── OS Images ─────────────────────────────────────────────

    public function images(): array
    {
        // Contabo images — fetch all pages, deduplicate by (os_name + major_version)
        $list = [];
        $seen = [];

        for ($page = 1; $page <= 5; $page++) {
            $raw  = $this->http->get('/compute/images', ['page' => $page, 'size' => 100]);
            $data = $raw['data'] ?? [];
            if (empty($data)) break;

            foreach ($data as $img) {
                $slug  = $img['imageId'] ?? $img['id'] ?? '';
                $name  = $img['name']    ?? '';
                if (!$slug || !$name) continue;

                [$os_name, $os_version] = $this->parseImage($name);

                // Deduplicate by (os_name + major version only e.g. "8" not "8.8" vs "8.9")
                $major_ver = (string)(int)$os_version; // "8.8" -> "8", "22.04" -> "22"
                $dedup_key = $os_name . '|' . $major_ver;
                if (isset($seen[$dedup_key])) continue;
                $seen[$dedup_key] = true;

                $list[] = [
                    'slug'            => $slug,
                    'label'           => $os_name === 'windows'
                                         ? $name
                                         : ucfirst($os_name) . ($os_version ? ' ' . $os_version : ''),
                    'os'              => $os_name,
                    'version'         => $os_version,
                    'image_type'      => 'system',
                    'app_description' => null,
                ];
            }

            // No more pages
            $total = $raw['_pagination']['totalElements'] ?? $raw['totalCount'] ?? count($data);
            if ($page * 100 >= $total) break;
        }

        return $list;
    }

    // ── Plans / Products ──────────────────────────────────────

    public function plans(): array
    {
        // Contabo VPS products — try multiple endpoints (API version differences)
        $raw = null;
        foreach (['/products', '/v1/products', '/product/v1/vps', '/v1/vps'] as $endpoint) {
            try {
                $r = $this->http->get($endpoint, ['page' => 1, 'size' => 100]);
                if (!empty($r['data'])) { $raw = $r; break; }
            } catch (Throwable $e) { continue; }
        }

        if (empty($raw['data'])) {
            // Fallback: return hardcoded Contabo plans with known pricing
            return $this->hardcodedPlans();
        }

        $list = [];
        foreach ($raw['data'] ?? [] as $p) {
            // Contabo API returns different field names across versions
            $id   = $p['productId'] ?? $p['id']    ?? $p['slug']   ?? '';
            $name = $p['name']      ?? $p['label']  ?? strtoupper($id);

            // vCPU — try multiple field names
            $vcpu = (int)($p['cpuCores']  ?? $p['cpu']    ?? $p['vcpus'] ?? $p['vCores'] ?? 0);

            // RAM — may be in MB or GB
            $ram_raw = (int)($p['memoryMb'] ?? $p['memory'] ?? $p['ram'] ?? 0);
            $ram_gb  = $ram_raw >= 1024 ? round($ram_raw / 1024, 1) : (float)$ram_raw;
            $ram_mb  = $ram_raw >= 1024 ? $ram_raw : $ram_raw * 1024;

            // Disk — may be in MB or GB
            $disk_raw = (int)($p['diskMb'] ?? ($p['storageGb'] ?? $p['disk'] ?? $p['storage'] ?? 0));
            $disk_gb  = $disk_raw > 1024 ? (int)round($disk_raw / 1024) : (int)$disk_raw;

            // Price — EUR/month, several possible structures
            $monthly_eur = 0.0;
            if (!empty($p['price']) && is_array($p['price'])) {
                if (isset($p['price'][0]['price'])) {
                    $monthly_eur = (float)$p['price'][0]['price'];
                } elseif (isset($p['price']['amount'])) {
                    $monthly_eur = (float)$p['price']['amount'];
                }
            }
            if (!$monthly_eur) {
                $monthly_eur = (float)($p['monthlyPrice'] ?? $p['monthly_price'] ?? $p['priceMonthly'] ?? 0);
            }

            $hourly_eur = $monthly_eur > 0 ? round($monthly_eur / 730, 8) : 0;
            if (!$id || !$vcpu) continue; // skip invalid entries

            $list[] = [
                'slug'              => $id,
                'label'             => $name,
                'vcpu'              => $vcpu,
                'ram_mb'            => $ram_mb,
                'ram_gb'            => $ram_gb,
                'disk_mb'           => $disk_gb * 1024,
                'disk_gb'           => $disk_gb,
                'price_monthly_eur' => $monthly_eur,
                'price_hourly_eur'  => $hourly_eur,
                'class'             => 'shared',
            ];
        }

        return $list ?: $this->hardcodedPlans();
    }

    /**
     * Hardcoded Contabo plans as fallback when API doesn't return data.
     * Updated pricing as of 2025.
     */
    private function hardcodedPlans(): array
    {
        return [
            ['slug'=>'V1',  'label'=>'Cloud VPS 1',  'vcpu'=>4,  'ram_mb'=>4096,  'ram_gb'=>4,  'disk_mb'=>102400, 'disk_gb'=>100, 'price_monthly_eur'=>4.50,  'price_hourly_eur'=>round(4.50/730,8),  'class'=>'shared'],
            ['slug'=>'V2',  'label'=>'Cloud VPS 2',  'vcpu'=>4,  'ram_mb'=>8192,  'ram_gb'=>8,  'disk_mb'=>204800, 'disk_gb'=>200, 'price_monthly_eur'=>6.99,  'price_hourly_eur'=>round(6.99/730,8),  'class'=>'shared'],
            ['slug'=>'V3',  'label'=>'Cloud VPS 3',  'vcpu'=>4,  'ram_mb'=>12288, 'ram_gb'=>12, 'disk_mb'=>307200, 'disk_gb'=>300, 'price_monthly_eur'=>9.99,  'price_hourly_eur'=>round(9.99/730,8),  'class'=>'shared'],
            ['slug'=>'V4',  'label'=>'Cloud VPS 4',  'vcpu'=>6,  'ram_mb'=>16384, 'ram_gb'=>16, 'disk_mb'=>409600, 'disk_gb'=>400, 'price_monthly_eur'=>13.99, 'price_hourly_eur'=>round(13.99/730,8), 'class'=>'shared'],
            ['slug'=>'V5',  'label'=>'Cloud VPS 5',  'vcpu'=>8,  'ram_mb'=>24576, 'ram_gb'=>24, 'disk_mb'=>614400, 'disk_gb'=>600, 'price_monthly_eur'=>19.99, 'price_hourly_eur'=>round(19.99/730,8), 'class'=>'shared'],
            ['slug'=>'V6',  'label'=>'Cloud VPS 6',  'vcpu'=>10, 'ram_mb'=>30720, 'ram_gb'=>30, 'disk_mb'=>819200, 'disk_gb'=>800, 'price_monthly_eur'=>26.99, 'price_hourly_eur'=>round(26.99/730,8), 'class'=>'shared'],
            ['slug'=>'V7',  'label'=>'Cloud VPS 7',  'vcpu'=>12, 'ram_mb'=>61440, 'ram_gb'=>60, 'disk_mb'=>1638400,'disk_gb'=>1600,'price_monthly_eur'=>52.99, 'price_hourly_eur'=>round(52.99/730,8), 'class'=>'shared'],
            ['slug'=>'V8',  'label'=>'Cloud VPS 8',  'vcpu'=>16, 'ram_mb'=>122880,'ram_gb'=>120,'disk_mb'=>3276800,'disk_gb'=>3200,'price_monthly_eur'=>99.99, 'price_hourly_eur'=>round(99.99/730,8), 'class'=>'shared'],
        ];
    }

    // ── SSH Keys (stored as Secrets in Contabo) ───────────────

    public function addSshKey(string $name, string $publicKey): array
    {
        $r = $this->http->post('/v1/secrets', [
            'name'  => $name,
            'type'  => 'ssh',
            'value' => $publicKey,
        ]);

        if (ContaboClient::isOk($r)) {
            $k = $r['data'][0] ?? [];
            return [
                'id'          => $k['secretId'] ?? $k['id'] ?? null,
                'name'        => $k['name']      ?? $name,
                'fingerprint' => '',
                'public_key'  => $publicKey,
            ];
        }
        throw new RuntimeException(ContaboClient::errMsg($r, 'SSH key error.'));
    }

    public function deleteSshKey(int $id): bool
    {
        $r = $this->http->delete('/v1/secrets/' . $id);
        return ContaboClient::isOk($r);
    }

    public function listSshKeys(): array
    {
        $r = $this->http->get('/v1/secrets', ['type' => 'ssh', 'size' => 100]);
        return array_map(fn($k) => [
            'id'          => $k['secretId'] ?? $k['id'],
            'name'        => $k['name']     ?? '',
            'fingerprint' => '',
        ], $r['data'] ?? []);
    }

    // ── Firewall ─────────────────────────────────────────────

    public function createFirewall(string $name, array $rules = []): array
    {
        // Contabo doesn't have API-managed firewalls in v1
        return ['id' => 0, 'name' => $name, 'rules' => $rules];
    }

    public function deleteFirewall(int $id): bool { return true; }
    public function listFirewalls(): array { return []; }

    // ── Private helpers ───────────────────────────────────────

    private function hardcodedRegions(): array
    {
        return [
            ['slug'=>'eu',         'label'=>'Nuremberg, Germany',       'city'=>'Nuremberg',    'country'=>'Germany',        'country_code'=>'de','country_flag'=>'de'],
            ['slug'=>'us-central', 'label'=>'St. Louis, United States', 'city'=>'St. Louis',    'country'=>'United States',  'country_code'=>'us','country_flag'=>'us'],
            ['slug'=>'us-east',    'label'=>'New York, United States',  'city'=>'New York',     'country'=>'United States',  'country_code'=>'us','country_flag'=>'us'],
            ['slug'=>'us-west',    'label'=>'Los Angeles, United States','city'=>'Los Angeles',  'country'=>'United States',  'country_code'=>'us','country_flag'=>'us'],
            ['slug'=>'sin',        'label'=>'Singapore',                'city'=>'Singapore',    'country'=>'Singapore',      'country_code'=>'sg','country_flag'=>'sg'],
            ['slug'=>'aus',        'label'=>'Sydney, Australia',        'city'=>'Sydney',       'country'=>'Australia',      'country_code'=>'au','country_flag'=>'au'],
            ['slug'=>'jpn',        'label'=>'Tokyo, Japan',             'city'=>'Tokyo',        'country'=>'Japan',          'country_code'=>'jp','country_flag'=>'jp'],
            ['slug'=>'gbr',        'label'=>'London, United Kingdom',   'city'=>'London',       'country'=>'United Kingdom', 'country_code'=>'gb','country_flag'=>'gb'],
        ];
    }

    private function regionCountry(string $slug): array
    {
        $map = [
            'eu'         => ['name'=>'Germany',        'code'=>'de'],
            'us-central' => ['name'=>'United States',  'code'=>'us'],
            'us-east'    => ['name'=>'United States',  'code'=>'us'],
            'us-west'    => ['name'=>'United States',  'code'=>'us'],
            'sin'        => ['name'=>'Singapore',      'code'=>'sg'],
            'aus'        => ['name'=>'Australia',      'code'=>'au'],
            'jpn'        => ['name'=>'Japan',          'code'=>'jp'],
            'gbr'        => ['name'=>'United Kingdom', 'code'=>'gb'],
        ];
        return $map[$slug] ?? ['name' => ucwords(str_replace('-',' ',$slug)), 'code' => 'de'];
    }

    private function parseImage(string $name): array
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

    private function parseOsName(string $name): string
    {
        return $this->parseImage($name)[0];
    }
}