<?php
/**
 * providers/proxmox/catalog.php
 *
 * Proxmox catalog: nodes (regions), ISO/templates (images), VM configs (plans).
 */
declare(strict_types=1);

class ProxmoxCatalog
{
    private ProxmoxClient $http;

    public function __construct(ProxmoxClient $http)
    {
        $this->http = $http;
    }

    public function getClient(): ProxmoxClient { return $this->http; }

    public function http_get(string $path, array $params = []): array
    {
        return $this->http->get($path, $params);
    }

    public function http_post(string $path, array $body = []): array
    {
        return $this->http->post($path, $body);
    }

    // ── Nodes → our "regions" ─────────────────────────────────

    public function regions(): array
    {
        $raw  = $this->http->get('nodes');
        $list = [];

        foreach ($raw['data'] ?? [] as $node) {
            $name = $node['node'] ?? '';
            if (!$name) continue;

            $list[] = [
                'slug'         => $name,
                'label'        => ucfirst($name),
                'city'         => ucfirst($name),
                'country'      => 'India',
                'country_code' => 'in',
                'country_flag' => 'in',
            ];
        }

        return $list;
    }

    // ── ISO images + CT templates → our "images" ─────────────
    // Proxmox: GET /nodes/{node}/storage/{storage}/content?content=iso,vztmpl

    public function images(int $vmid = 0): array
    {
        $node  = $this->http->resolveNode();
        $list  = [];
        $seen  = [];

        // Get storage list first
        try {
            $storages_raw = $this->http->get("nodes/{$node}/storage");
            $storages = $storages_raw['data'] ?? [];
        } catch (Throwable $e) {
            $storages = [];
        }

        // Collect ISOs and templates from each storage
        foreach ($storages as $storage) {
            $stor_name   = $storage['storage'] ?? '';
            $stor_content= $storage['content'] ?? '';

            if (!$stor_name) continue;

            // Only storages that have iso or vztmpl content
            $fetch_types = [];
            if (str_contains($stor_content, 'iso'))    $fetch_types[] = 'iso';
            if (str_contains($stor_content, 'vztmpl')) $fetch_types[] = 'vztmpl';
            if (empty($fetch_types)) continue;

            foreach ($fetch_types as $ctype) {
                try {
                    $raw = $this->http->get("nodes/{$node}/storage/{$stor_name}/content", [
                        'content' => $ctype,
                    ]);

                    foreach ($raw['data'] ?? [] as $img) {
                        $volid = $img['volid'] ?? '';
                        if (!$volid || isset($seen[$volid])) continue;
                        $seen[$volid] = true;

                        $filename = basename($volid);
                        [$os_name, $os_version] = $this->parseImageName($filename);

                        // Dedup by os+major_version
                        $major = (string)(int)$os_version;
                        $dedup = $os_name . '|' . $major;
                        if (isset($seen['dedup:' . $dedup])) continue;
                        $seen['dedup:' . $dedup] = true;

                        $label = ucfirst($os_name) . ($os_version ? ' ' . $os_version : '');
                        if ($os_name === 'linux' || $os_name === 'other') {
                            $label = $this->cleanFilename($filename);
                        }

                        $list[] = [
                            'slug'            => $volid,   // e.g. "local:iso/ubuntu-22.04.iso"
                            'label'           => $label,
                            'os'              => $os_name,
                            'version'         => $os_version,
                            'image_type'      => 'system',
                            'app_description' => null,
                        ];
                    }
                } catch (Throwable $e) {
                    // Storage might not be reachable, skip
                }
            }
        }

        return $list;
    }

    // ── Plans — Proxmox has no plans API, return empty ────────
    // Plans are defined manually by admin in provider_plans table
    // (same as Hetzner approach)

    public function plans(): array
    {
        return [];
    }

    // ── SSH Keys ─────────────────────────────────────────────

    public function addSshKey(string $name, string $publicKey): array
    {
        return ['id' => $name, 'name' => $name, 'public_key' => $publicKey];
    }

    public function deleteSshKey(int $id): bool { return true; }
    public function listSshKeys(): array         { return []; }

    // ── Stubs ─────────────────────────────────────────────────

    public function createFirewall(string $name, array $rules = []): array { return ['id' => 0]; }
    public function deleteFirewall(int $id): bool                          { return true; }
    public function listFirewalls(): array                                 { return []; }

    // ── Private helpers ───────────────────────────────────────

    private function parseImageName(string $name): array
    {
        $lower = strtolower($name);
        $os_map = [
            'ubuntu', 'debian', 'centos', 'fedora', 'rocky',
            'alma', 'almalinux', 'opensuse', 'windows', 'arch', 'alpine', 'kali',
        ];
        foreach ($os_map as $os) {
            if (str_contains($lower, $os)) {
                preg_match('/(\d+\.?\d*)/', $name, $m);
                return [$os === 'almalinux' ? 'alma' : $os, $m[1] ?? ''];
            }
        }
        if (str_contains($lower, 'win')) return ['windows', ''];
        return ['linux', ''];
    }

    private function cleanFilename(string $name): string
    {
        // Remove extension, replace hyphens/underscores with spaces
        $name = preg_replace('/\.(iso|img|qcow2|vmdk|vhd|tar\.gz|tar\.xz)$/i', '', $name);
        $name = str_replace(['-', '_'], ' ', $name);
        return ucwords($name);
    }
}
