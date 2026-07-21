<?php
/**
 * providers/vultr/catalog.php
 *
 * Vultr catalog: regions, OS images, plans, SSH keys.
 *
 * Docs:
 *   GET /regions         — regions
 *   GET /os              — OS list
 *   GET /plans           — plan list
 *   GET /account/keys    — SSH keys
 */
declare(strict_types=1);

class VultrCatalog
{
    private VultrClient $http;

    public function __construct(VultrClient $http)
    {
        $this->http = $http;
    }

    public function getClient(): VultrClient { return $this->http; }

    // ── Raw HTTP helpers (used by action handlers) ────────────

    public function http_get(string $path, array $params = []): array
    {
        return $this->http->get($path, $params);
    }

    public function http_post(string $path, array $body = []): array
    {
        return $this->http->post($path, $body);
    }

    public function http_patch(string $path, array $body = []): array
    {
        return $this->http->patch($path, $body);
    }

    public function http_delete(string $path): array
    {
        return $this->http->delete($path);
    }

    // ── Regions ───────────────────────────────────────────────

    public function regions(): array
    {
        $list = [];
        $cursor = '';

        do {
            $params = ['per_page' => 100];
            if ($cursor) $params['cursor'] = $cursor;

            $raw    = $this->http->get('/regions', $params);
            $cursor = $raw['meta']['links']['next'] ?? '';

            foreach ($raw['regions'] ?? [] as $r) {
                $list[] = [
                    'slug'         => $r['id']      ?? '',
                    'label'        => $r['city']    . ', ' . $r['country'],
                    'city'         => $r['city']    ?? '',
                    'country'      => $r['country'] ?? '',
                    'country_code' => strtolower($r['country'] ?? 'us'),
                    'country_flag' => strtolower($r['country'] ?? 'us'),
                ];
            }
        } while ($cursor);

        return $list;
    }

    // ── OS Images ─────────────────────────────────────────────

    public function images(int $vmid = 0): array
    {
        $list = [];
        $seen = [];

        $cursor = '';
        do {
            $params = ['per_page' => 500];
            if ($cursor) $params['cursor'] = $cursor;

            $raw    = $this->http->get('/os', $params);
            $cursor = $raw['meta']['links']['next'] ?? '';

            foreach ($raw['os'] ?? [] as $os) {
                $os_id   = (int)($os['id']   ?? 0);
                $os_name = strtolower($os['name'] ?? '');
                if (!$os_id) continue;

                [$distro, $version] = $this->parseOsName($os_name);

                // Dedup by distro + major version
                $major  = (string)(int)$version;
                $dedup  = $distro . '|' . $major;
                if (isset($seen[$dedup])) continue;
                $seen[$dedup] = true;

                $list[] = [
                    'slug'            => (string)$os_id,
                    'label'           => $os['name'] ?? 'OS ' . $os_id,
                    'os'              => $distro,
                    'version'         => $version,
                    'image_type'      => 'system',
                    'app_description' => null,
                ];
            }
        } while ($cursor);

        return $list;
    }

    // ── Applications ─────────────────────────────────────────

    public function apps(): array
    {
        $list   = [];
        $cursor = '';

        do {
            $params = ['per_page' => 200];
            if ($cursor) $params['cursor'] = $cursor;

            $raw    = $this->http->get('/applications', $params);
            $cursor = $raw['meta']['links']['next'] ?? '';

            foreach ($raw['applications'] ?? [] as $app) {
                $app_id = (int)($app['id'] ?? 0);
                if (!$app_id) continue;

                $list[] = [
                    'slug'            => 'app:' . $app_id,
                    'label'           => $app['name']   ?? 'App ' . $app_id,
                    'os'              => 'app',
                    'version'         => $app['short_name'] ?? '',
                    'image_type'      => 'app',
                    'app_description' => $app['vendor']  ?? null,
                ];
            }
        } while ($cursor);

        return $list;
    }

    // ── Plans ─────────────────────────────────────────────────

    public function plans(string $type = 'vc2'): array
    {
        $list   = [];
        $cursor = '';

        do {
            $params = ['per_page' => 500, 'type' => $type];
            if ($cursor) $params['cursor'] = $cursor;

            $raw    = $this->http->get('/plans', $params);
            $cursor = $raw['meta']['links']['next'] ?? '';

            foreach ($raw['plans'] ?? [] as $p) {
                $plan_id   = $p['id']         ?? '';
                $vcpu      = (int)($p['vcpu_count'] ?? 1);
                $ram_mb    = (int)($p['ram']        ?? 0);
                $disk_gb   = (int)($p['disk']       ?? 0);
                $bw_gb     = (int)($p['bandwidth']  ?? 0);
                $price_mo  = (float)($p['monthly_cost'] ?? 0);
                $price_hr  = $price_mo > 0 ? round($price_mo / 730, 8) : 0.0;
                $ram_gb    = $ram_mb ? round($ram_mb / 1024, 1) : 0;

                if (!$plan_id) continue;

                $label = "{$vcpu}vCPU / {$ram_gb}GB RAM / {$disk_gb}GB SSD";

                $list[] = [
                    'id'            => $plan_id,
                    'slug'          => $plan_id,
                    'label'         => $label,
                    'vcpu'          => $vcpu,
                    'ram_gb'        => $ram_gb,
                    'disk_gb'       => $disk_gb,
                    'bandwidth_gb'  => $bw_gb,
                    'price_monthly' => $price_mo,
                    'price_hourly'  => $price_hr,
                    'cpu_type'      => str_contains($plan_id, 'vhf') ? 'dedicated' : 'shared',
                    'locations'     => $p['locations'] ?? [],
                ];
            }
        } while ($cursor);

        return $list;
    }

    // ── SSH Keys ─────────────────────────────────────────────

    public function listSshKeys(): array
    {
        $raw  = $this->http->get('/ssh-keys', ['per_page' => 100]);
        $keys = [];
        foreach ($raw['ssh_keys'] ?? [] as $k) {
            $keys[] = [
                'id'         => $k['id']   ?? '',
                'name'       => $k['name'] ?? '',
                'public_key' => $k['ssh_key'] ?? '',
            ];
        }
        return $keys;
    }

    public function addSshKey(string $name, string $publicKey): array
    {
        $raw = $this->http->post('/ssh-keys', ['name' => $name, 'ssh_key' => $publicKey]);
        $k   = $raw['ssh_key'] ?? [];
        return [
            'id'         => $k['id']   ?? '',
            'name'       => $k['name'] ?? $name,
            'public_key' => $k['ssh_key'] ?? $publicKey,
        ];
    }

    public function deleteSshKey(string $id): bool
    {
        try {
            $this->http->delete('/ssh-keys/' . $id);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ── Private helpers ───────────────────────────────────────

    private function parseOsName(string $name): array
    {
        $lower   = strtolower($name);
        $os_list = [
            'ubuntu','debian','centos','fedora','rocky','alma','almalinux',
            'windows','freebsd','arch','alpine','opensuse','kali',
        ];
        foreach ($os_list as $os) {
            if (str_contains($lower, $os)) {
                preg_match('/(\d+\.?\d*)/', $name, $m);
                return [$os === 'almalinux' ? 'alma' : $os, $m[1] ?? ''];
            }
        }
        return ['linux', ''];
    }
}
