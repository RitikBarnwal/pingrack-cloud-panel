<?php
/**
 * providers/hetzner/catalog.php
 * Public http_get/post/delete so action handlers can call any endpoint.
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';

class CloudCatalog
{
    private CloudProviderClient $http;

    public function __construct(CloudProviderClient $http)
    {
        $this->http = $http;
    }

    public function http_get(string $path, array $params = []): array
    {
        return $this->http->get($path, $params);
    }

    public function http_post(string $path, array $body = []): array
    {
        return $this->http->post($path, $body);
    }

    public function http_delete(string $path): array
    {
        return $this->http->delete($path);
    }

    /* ── Regions ───────────────────────────────────────────── */

    public function regions(): array
    {
        $raw  = $this->http->get('/locations', ['per_page' => 50]);
        $list = [];
        foreach ($raw['locations'] ?? [] as $l) {
            $code  = strtoupper($l['country'] ?? '');
            $names = ['DE'=>'Germany','FI'=>'Finland','US'=>'United States','SG'=>'Singapore','IN'=>'India','GB'=>'United Kingdom','FR'=>'France','NL'=>'Netherlands','PL'=>'Poland'];
            $list[] = [
                'slug'         => $l['name']        ?? '',
                'label'        => $l['description'] ?? '',
                'city'         => $l['city']         ?? '',
                'country'      => $names[$code]      ?? $code,
                'country_code' => strtolower($l['country'] ?? 'de'),
                'country_flag' => strtolower($l['country'] ?? 'de'),
            ];
        }
        return $list;
    }

    /* ── OS Images ─────────────────────────────────────────── */

    public function images(string $type = 'system'): array
    {
        $raw  = $this->http->get('/images', ['type' => $type, 'per_page' => 100]);
        $list = [];
        foreach ($raw['images'] ?? [] as $img) {
            if ($img['deprecated'] ?? false) continue;
            $list[] = [
                'slug'           => $img['name']       ?? (string)($img['id'] ?? ''),
                'label'          => ucfirst($img['os_flavor'] ?? '') . ' ' . ($img['os_version'] ?? ''),
                'os'             => $img['os_flavor']  ?? '',
                'version'        => $img['os_version'] ?? '',
                'image_type'     => 'system',
                'app_description'=> null,
            ];
        }
        return $list;
    }

    /**
     * Fetch Hetzner marketplace apps (/images?type=app).
     * Returns same format as images() for use with upsert_image_catalog.
     */
    public function apps(): array
    {
        $raw  = $this->http->get('/images', ['type' => 'app', 'per_page' => 100]);
        $list = [];
        foreach ($raw['images'] ?? [] as $img) {
            if ($img['deprecated'] ?? false) continue;

            $slug  = $img['name'] ?? (string)($img['id'] ?? '');
            $label = $img['description'] ?? $img['name'] ?? '';
            $app_name = $this->parseAppName($slug, $label);

            $list[] = [
                'slug'           => $slug,
                'label'          => $label ?: $app_name,
                'os'             => strtolower($app_name),
                'version'        => '',
                'image_type'     => 'app',
                'app_description'=> $img['description'] ?? null,
            ];
        }
        return $list;
    }

    private function parseAppName(string $slug, string $label): string
    {
        // Hetzner slug: "app-wordpress-1-click" → "WordPress"
        if (preg_match('/^app-([a-z0-9]+)/i', $slug, $m)) {
            return ucfirst($m[1]);
        }
        return $label ?: $slug;
    }

    /* ── SSH Keys ──────────────────────────────────────────── */

    public function addSshKey(string $name, string $publicKey): array
    {
        $raw = $this->http->post('/ssh_keys', ['name' => $name, 'public_key' => $publicKey]);
        if (empty($raw['ssh_key'])) throw new RuntimeException($raw['error']['message'] ?? 'SSH key error');
        $k = $raw['ssh_key'];
        return ['id'=>$k['id'],'name'=>$k['name'],'fingerprint'=>$k['fingerprint']??'','public_key'=>$k['public_key']??''];
    }

    public function deleteSshKey(int $id): bool
    {
        $raw = $this->http->delete('/ssh_keys/' . $id);
        return ($raw['_http_status'] ?? 0) === 204;
    }

    /* ── Firewalls ─────────────────────────────────────────── */

    public function createFirewall(string $name, array $rules): array
    {
        $raw = $this->http->post('/firewalls', ['name' => $name, 'rules' => $rules]);
        if (empty($raw['firewall'])) throw new RuntimeException($raw['error']['message'] ?? 'Firewall error');
        $f = $raw['firewall'];
        return ['id'=>$f['id'],'name'=>$f['name'],'rules'=>$f['rules']??[]];
    }

    public function deleteFirewall(int $id): bool
    {
        $raw = $this->http->delete('/firewalls/' . $id);
        return ($raw['_http_status'] ?? 0) === 204;
    }
}
