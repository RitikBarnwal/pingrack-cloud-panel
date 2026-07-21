<?php
/**
 * providers/digitalocean/catalog.php
 *
 * DigitalOcean catalog: regions, images, sizes (plans), SSH keys, firewalls.
 */

declare(strict_types=1);

class DOCatalog
{
    private DOClient $http;

    public function __construct(DOClient $http)
    {
        $this->http = $http;
    }

    public function http_get(string $path, array $params = []): array  { return $this->http->get($path, $params); }
    public function http_post(string $path, array $body = []): array   { return $this->http->post($path, $body); }
    public function http_put(string $path, array $body = []): array    { return $this->http->put($path, $body); }
    public function http_delete(string $path): array                   { return $this->http->delete($path); }

    // ── Regions ───────────────────────────────────────────────

    public function regions(): array
    {
        $raw  = $this->http->get('/regions', ['per_page' => 100]);
        $list = [];

        foreach ($raw['regions'] ?? [] as $r) {
            if (!($r['available'] ?? true)) continue;

            $slug = $r['slug'];
            $cc   = $this->regionFlag($slug);

            $list[] = [
                'slug'         => $slug,
                'label'        => $r['name'],
                'city'         => $this->regionCity($slug),
                'country'      => $this->regionCountryName($slug),
                'country_code' => $cc,
                'country_flag' => $cc,
            ];
        }
        return $list;
    }

    // ── OS Images ─────────────────────────────────────────────

    public function images(): array
    {
        $list = [];
        $page = 1;
        $seen = [];

        do {
            $raw = $this->http->get('/images', [
                'type'     => 'distribution',
                'public'   => 'true',
                'per_page' => 100,
                'page'     => $page,
            ]);
            $imgs = $raw['images'] ?? [];

            foreach ($imgs as $img) {
                if (!($img['public'] ?? false)) continue;
                $slug = $img['slug'] ?? '';
                if (!$slug || isset($seen[$slug])) continue;
                $seen[$slug] = true;

                [$os_name, $os_version] = $this->parseImage($img);

                $list[] = [
                    'slug'           => $slug,
                    'label'          => $img['name'] ?? $slug,
                    'os'             => $os_name,
                    'version'        => $os_version,
                    'image_type'     => 'system',
                    'app_description'=> null,
                ];
            }
            $page++;
        } while (count($imgs) === 100 && $page <= 5);

        return $list;
    }

    // ── One-click apps (DO Marketplace) ───────────────────────

    public function apps(): array
    {
        $raw  = $this->http->get('/images', ['type' => 'application', 'public' => 'true', 'per_page' => 100]);
        $list = [];

        foreach ($raw['images'] ?? [] as $img) {
            $slug = $img['slug'] ?? '';
            if (!$slug) continue;

            $app_name = $img['name'] ?? $slug;

            $list[] = [
                'slug'           => $slug,
                'label'          => $app_name,
                'os'             => strtolower(preg_replace('/[^a-z0-9]/i', '', explode(' ', $app_name)[0])),
                'version'        => '',
                'image_type'     => 'app',
                'app_description'=> $img['description'] ?? null,
            ];
        }
        return $list;
    }

    // ── Sizes / Plans ─────────────────────────────────────────

    public function plans(): array
    {
        $raw  = $this->http->get('/sizes', ['per_page' => 100]);
        $list = [];

        foreach ($raw['sizes'] ?? [] as $s) {
            if (!($s['available'] ?? true)) continue;

            $list[] = [
                'slug'              => $s['slug'],
                'label'             => $s['description'] ?? strtoupper($s['slug']),
                'vcpu'              => (int)$s['vcpus'],
                'ram_mb'            => (int)$s['memory'],
                'ram_gb'            => round($s['memory'] / 1024, 1),
                'disk_mb'           => $s['disk'] * 1024,
                'disk_gb'           => (int)$s['disk'],
                'transfer_tb'       => round($s['transfer'], 1),
                'price_hourly_usd'  => (float)$s['price_hourly'],
                'price_monthly_usd' => (float)$s['price_monthly'],
                'class'             => $this->sizeClass($s['slug']),
                'regions'           => $s['regions'] ?? [],
            ];
        }
        return $list;
    }

    // ── SSH Keys ──────────────────────────────────────────────

    public function addSshKey(string $name, string $publicKey): array
    {
        $r = $this->http->post('/account/keys', [
            'name'       => $name,
            'public_key' => $publicKey,
        ]);
        if (!empty($r['ssh_key']['id'])) {
            $k = $r['ssh_key'];
            return ['id' => $k['id'], 'name' => $k['name'], 'fingerprint' => $k['fingerprint'] ?? '', 'public_key' => $k['public_key'] ?? $publicKey];
        }
        throw new RuntimeException(DOClient::errMsg($r, 'SSH key error.'));
    }

    public function deleteSshKey(int $id): bool
    {
        $r = $this->http->delete('/account/keys/' . $id);
        return ($r['_http_status'] ?? 0) === 204;
    }

    public function listSshKeys(): array
    {
        $r = $this->http->get('/account/keys', ['per_page' => 100]);
        return array_map(fn($k) => ['id' => $k['id'], 'name' => $k['name'], 'fingerprint' => $k['fingerprint'] ?? ''], $r['ssh_keys'] ?? []);
    }

    // ── Firewalls ─────────────────────────────────────────────

    public function createFirewall(string $name, array $rules = []): array
    {
        $r = $this->http->post('/firewalls', ['name' => $name]);
        if (!empty($r['firewall']['id'])) {
            return ['id' => $r['firewall']['id'], 'name' => $r['firewall']['name'], 'rules' => $rules];
        }
        throw new RuntimeException(DOClient::errMsg($r, 'Firewall error.'));
    }

    public function deleteFirewall(string $id): bool
    {
        $r = $this->http->delete('/firewalls/' . $id);
        return ($r['_http_status'] ?? 0) === 204;
    }

    public function listFirewalls(): array
    {
        $r = $this->http->get('/firewalls', ['per_page' => 100]);
        return array_map(fn($f) => ['id' => $f['id'], 'name' => $f['name'], 'status' => $f['status'] ?? 'active'], $r['firewalls'] ?? []);
    }

    // ── Helpers ───────────────────────────────────────────────

    private function parseImage(array $img): array
    {
        $distro  = $img['distribution'] ?? '';
        $name    = $img['name']         ?? '';
        $os_map  = ['Ubuntu'=>'ubuntu','Debian'=>'debian','CentOS'=>'centos','CentOS Stream'=>'centos',
                    'Fedora'=>'fedora','Rocky Linux'=>'rocky','AlmaLinux'=>'alma','Windows'=>'windows',
                    'Arch Linux'=>'arch','openSUSE'=>'opensuse','FreeBSD'=>'freebsd'];
        $os_name = $os_map[$distro] ?? strtolower(explode(' ', $distro)[0]) ?: 'linux';
        preg_match('/(\d+\.?\d*)/', $name, $m);
        return [$os_name, $m[1] ?? ''];
    }

    private function sizeClass(string $slug): string
    {
        if (str_starts_with($slug, 'c-'))    return 'dedicated'; // CPU-Optimized
        if (str_starts_with($slug, 'g-'))    return 'dedicated'; // General Purpose
        if (str_starts_with($slug, 'm-'))    return 'dedicated'; // Memory-Optimized
        if (str_starts_with($slug, 'so-'))   return 'dedicated'; // Storage-Optimized
        return 'shared'; // s- Basic droplets
    }

    private function regionCity(string $slug): string
    {
        $map = [
            'nyc1'=>'New York','nyc2'=>'New York','nyc3'=>'New York',
            'sfo1'=>'San Francisco','sfo2'=>'San Francisco','sfo3'=>'San Francisco',
            'ams2'=>'Amsterdam','ams3'=>'Amsterdam',
            'sgp1'=>'Singapore',
            'lon1'=>'London',
            'fra1'=>'Frankfurt',
            'tor1'=>'Toronto',
            'blr1'=>'Bangalore',
            'syd1'=>'Sydney',
        ];
        return $map[$slug] ?? ucwords(str_replace(['-','_'], ' ', $slug));
    }

    private function regionCountryName(string $slug): string
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
        if (str_starts_with($slug, 'nyc') || str_starts_with($slug, 'sfo')) return 'us';
        if (str_starts_with($slug, 'ams')) return 'nl';
        if ($slug === 'sgp1') return 'sg';
        if ($slug === 'lon1') return 'gb';
        if ($slug === 'fra1') return 'de';
        if ($slug === 'tor1') return 'ca';
        if ($slug === 'blr1') return 'in';
        if ($slug === 'syd1') return 'au';
        return 'us';
    }
}
