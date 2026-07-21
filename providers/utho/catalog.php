<?php
/**
 * providers/utho/catalog.php
 *
 * Utho Cloud catalog: datacenters, images (OS), plans, SSH keys, firewalls.
 *
 * API endpoints:
 *   GET /dclocation               — list datacenters
 *   GET /images                   — list OS images
 *   GET /plans                    — list plans (with pricing)
 *   GET /sshkeys                  — list SSH keys
 *   POST /sshkeys                 — add SSH key
 *   DELETE /sshkeys/{id}          — delete SSH key
 *   GET /firewall                 — list firewalls
 *   POST /firewall                — create firewall
 *   DELETE /firewall/{id}         — delete firewall
 */

declare(strict_types=1);

class UthoCatalog
{
    private UthoClient $http;

    public function __construct(UthoClient $http)
    {
        $this->http = $http;
    }

    // ── Raw HTTP pass-through ─────────────────────────────────

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

    // ── Regions / Datacenters ─────────────────────────────────

    public function regions(): array
    {
        $raw  = $this->http->get('/dclocation');
        $list = [];

        foreach ($raw['datacenters'] ?? $raw['dclocation'] ?? $raw['data'] ?? [] as $dc) {
            $slug = $dc['slug'] ?? $dc['dcslug'] ?? strtolower(str_replace(' ', '', $dc['location'] ?? $dc['name'] ?? ''));
            $city = $dc['city'] ?? $dc['location'] ?? $dc['name'] ?? '';
            $cc   = strtolower($dc['country'] ?? $dc['country_code'] ?? $this->guessCountry($slug));

            $list[] = [
                'slug'         => $slug,
                'label'        => $dc['name'] ?? $city,
                'city'         => $city,
                'country'      => $this->countryName($cc),
                'country_code' => $cc,
                'country_flag' => $cc,
            ];
        }
        return $list;
    }

    // ── OS Images ─────────────────────────────────────────────

    public function images(): array
    {
        $raw  = $this->http->get('/images');
        $list = [];

        foreach ($raw['images'] ?? $raw['data'] ?? [] as $img) {
            $slug  = $img['image'] ?? $img['slug'] ?? $img['name'] ?? '';
            $label = $img['label'] ?? $img['name'] ?? $img['distro'] ?? $slug;

            [$os_name, $os_version] = $this->parseImage($slug, $label);

            $list[] = [
                'slug'           => $slug,
                'label'          => $label,
                'os'             => $os_name,
                'version'        => $os_version,
                'image_type'     => 'system',
                'app_description'=> null,
            ];
        }
        return $list;
    }

    // ── Plans ─────────────────────────────────────────────────

    public function plans(): array
    {
        // Utho API — try multiple possible endpoints
        $raw = [];
        foreach (['/plans', '/cloudplans', '/pricing', '/cloud/plans', '/v1/plans'] as $ep) {
            try {
                $r = $this->http->get($ep);
                if (!empty($r['plans']) || !empty($r['data']) || !empty($r['cloudplans'])) {
                    $raw = $r;
                    break;
                }
            } catch (Throwable $e) { continue; }
        }

        $items = $raw['plans'] ?? $raw['cloudplans'] ?? $raw['data'] ?? [];

        // If API returns nothing, use hardcoded Utho plans
        if (empty($items)) {
            return $this->hardcodedPlans();
        }

        $list = [];
        foreach ($items as $p) {
            $planid = (string)($p['id'] ?? $p['planid'] ?? '');
            if (!$planid) continue;

            $ram_mb  = (int)($p['ram']    ?? 0);
            $disk_gb = (int)($p['disk']   ?? $p['storage'] ?? 0);
            $ram_gb  = $ram_mb >= 1024 ? round($ram_mb / 1024, 1) : (float)$ram_mb;

            $price_monthly_inr = (float)($p['price'] ?? $p['monthly_price'] ?? $p['cost'] ?? 0);
            $price_hourly_inr  = $price_monthly_inr > 0 ? round($price_monthly_inr / 730, 6) : 0;

            $list[] = [
                'slug'              => $planid,
                'label'             => $p['name'] ?? $p['planname'] ?? strtoupper($planid),
                'vcpu'              => (int)($p['cpu'] ?? $p['vcpu'] ?? 0),
                'ram_mb'            => $ram_mb,
                'ram_gb'            => (float)$ram_gb,
                'disk_gb'           => $disk_gb,
                'price_monthly_inr' => $price_monthly_inr,
                'price_hourly_inr'  => $price_hourly_inr,
                'class'             => $p['type'] ?? 'shared',
            ];
        }
        return $list ?: $this->hardcodedPlans();
    }

    /**
     * Hardcoded Utho plans — used when API doesn't expose /plans endpoint.
     * Prices in INR/month as per Utho pricing page (2025).
     */
    private function hardcodedPlans(): array
    {
        return [
            ['slug'=>'10030','label'=>'Basic 1GB',   'vcpu'=>1, 'ram_mb'=>1024,  'ram_gb'=>1,  'disk_gb'=>25,  'price_monthly_inr'=>225,  'price_hourly_inr'=>round(225/730,6),  'class'=>'shared'],
            ['slug'=>'10060','label'=>'Basic 2GB',   'vcpu'=>1, 'ram_mb'=>2048,  'ram_gb'=>2,  'disk_gb'=>50,  'price_monthly_inr'=>450,  'price_hourly_inr'=>round(450/730,6),  'class'=>'shared'],
            ['slug'=>'10090','label'=>'Basic 4GB',   'vcpu'=>2, 'ram_mb'=>4096,  'ram_gb'=>4,  'disk_gb'=>80,  'price_monthly_inr'=>900,  'price_hourly_inr'=>round(900/730,6),  'class'=>'shared'],
            ['slug'=>'10120','label'=>'Basic 8GB',   'vcpu'=>4, 'ram_mb'=>8192,  'ram_gb'=>8,  'disk_gb'=>160, 'price_monthly_inr'=>1800, 'price_hourly_inr'=>round(1800/730,6), 'class'=>'shared'],
            ['slug'=>'10150','label'=>'Basic 16GB',  'vcpu'=>6, 'ram_mb'=>16384, 'ram_gb'=>16, 'disk_gb'=>320, 'price_monthly_inr'=>3600, 'price_hourly_inr'=>round(3600/730,6), 'class'=>'shared'],
            ['slug'=>'10180','label'=>'Basic 32GB',  'vcpu'=>8, 'ram_mb'=>32768, 'ram_gb'=>32, 'disk_gb'=>640, 'price_monthly_inr'=>7200, 'price_hourly_inr'=>round(7200/730,6), 'class'=>'shared'],
        ];
    }

    // ── SSH Keys ──────────────────────────────────────────────

    public function addSshKey(string $name, string $publicKey): array
    {
        $r = $this->http->post('/sshkeys', [
            'name'        => $name,
            'ssh_key'     => $publicKey,
        ]);

        if (UthoClient::isOk($r)) {
            $k = $r['sshkey'] ?? $r['data'] ?? $r;
            return [
                'id'          => $k['id']          ?? null,
                'name'        => $k['name']         ?? $name,
                'fingerprint' => $k['fingerprint']  ?? '',
                'public_key'  => $k['ssh_key']      ?? $publicKey,
            ];
        }
        throw new RuntimeException(UthoClient::errMsg($r, 'SSH key error.'));
    }

    public function deleteSshKey(int $id): bool
    {
        $r = $this->http->delete('/sshkeys/' . $id);
        return UthoClient::isOk($r);
    }

    public function listSshKeys(): array
    {
        $r = $this->http->get('/sshkeys');
        return array_map(fn($k) => [
            'id'          => $k['id'],
            'name'        => $k['name']        ?? '',
            'fingerprint' => $k['fingerprint'] ?? '',
        ], $r['sshkeys'] ?? $r['data'] ?? []);
    }

    // ── Firewalls ─────────────────────────────────────────────

    public function createFirewall(string $name, array $rules = []): array
    {
        $r = $this->http->post('/firewall', [
            'name'  => $name,
        ]);

        if (UthoClient::isOk($r)) {
            $f = $r['firewall'] ?? $r['data'] ?? $r;
            return ['id' => $f['id'], 'name' => $f['name'] ?? $name, 'rules' => $rules];
        }
        throw new RuntimeException(UthoClient::errMsg($r, 'Firewall error.'));
    }

    public function deleteFirewall(int $id): bool
    {
        $r = $this->http->delete('/firewall/' . $id);
        return UthoClient::isOk($r);
    }

    public function listFirewalls(): array
    {
        $r = $this->http->get('/firewall');
        return array_map(fn($f) => [
            'id'     => $f['id'],
            'name'   => $f['name'],
            'status' => 'active',
        ], $r['firewalls'] ?? $r['data'] ?? []);
    }

    // ── Private helpers ───────────────────────────────────────

    private function parseImage(string $slug, string $label): array
    {
        $text = strtolower($slug . ' ' . $label);
        $os_map = ['ubuntu','debian','centos','fedora','rocky','alma','almalinux',
                   'opensuse','arch','windows','kali','alpine'];
        foreach ($os_map as $os) {
            if (str_contains($text, $os)) {
                if (preg_match('/(\d+\.?\d*)/', $label, $m)) {
                    return [$os === 'almalinux' ? 'alma' : $os, $m[1]];
                }
                return [$os === 'almalinux' ? 'alma' : $os, ''];
            }
        }
        return ['linux', ''];
    }

    private function guessCountry(string $slug): string
    {
        if (str_starts_with($slug, 'in') || str_contains($slug, 'india') ||
            str_contains($slug, 'noida') || str_contains($slug, 'mumbai') ||
            str_contains($slug, 'bangalore') || str_contains($slug, 'hyderabad')) return 'in';
        if (str_starts_with($slug, 'us')) return 'us';
        if (str_starts_with($slug, 'sg') || str_contains($slug, 'singapore')) return 'sg';
        if (str_starts_with($slug, 'uk') || str_contains($slug, 'london')) return 'gb';
        if (str_starts_with($slug, 'de') || str_contains($slug, 'frankfurt')) return 'de';
        return 'in';
    }

    private function countryName(string $cc): string
    {
        $map = ['in'=>'India','us'=>'United States','sg'=>'Singapore',
                'gb'=>'United Kingdom','de'=>'Germany','au'=>'Australia','jp'=>'Japan'];
        return $map[$cc] ?? strtoupper($cc);
    }
}
