<?php
/**
 * servers/actions/hetzner.php
 *
 * ALL Hetzner-specific server action handlers.
 * Called by api/server-action.php when provider_type = 'hetzner'
 *
 * Each action receives: $cloud (CloudProvider), $server (DB row), $payload (POST data)
 * Returns: ['ok'=>bool, 'message'=>string, 'data'=>array]
 */
declare(strict_types=1);

class HetznerActions
{
    private object $cloud;
    private array  $server;
    private string $hid; // Hetzner server ID

    public function __construct(object $cloud, array $server)
    {
        $this->cloud  = $cloud;
        $this->server = $server;
        $this->hid    = (string)($server['provider_id'] ?? 0);
    }

    /* ── Power ────────────────────────────────────────────────── */

    public function start(): array
    {
        $r = $this->cloud->catalog->http_post('/servers/' . $this->hid . '/actions/poweron');
        return $this->action_result($r, 'Server is starting up.');
    }

    public function stop(): array
    {
        $r = $this->cloud->catalog->http_post('/servers/' . $this->hid . '/actions/poweroff');
        return $this->action_result($r, 'Server is powering off.');
    }

    public function shutdown(): array
    {
        $r = $this->cloud->catalog->http_post('/servers/' . $this->hid . '/actions/shutdown');
        return $this->action_result($r, 'Shutdown signal sent to server.');
    }

    public function reboot(): array
    {
        $r = $this->cloud->catalog->http_post('/servers/' . $this->hid . '/actions/reboot');
        return $this->action_result($r, 'Server is rebooting.');
    }

    public function reset(): array
    {
        $r = $this->cloud->catalog->http_post('/servers/' . $this->hid . '/actions/reset');
        return $this->action_result($r, 'Hard reset sent to server.');
    }

    /* ── Rescue ───────────────────────────────────────────────── */

    public function enable_rescue(array $payload = []): array
    {
        $type     = $payload['rescue_type'] ?? 'linux64';
        $ssh_keys = $payload['ssh_keys']    ?? [];

        $body = ['type' => $type];
        if (!empty($ssh_keys)) $body['ssh_keys'] = array_map('intval', $ssh_keys);

        $r = $this->cloud->catalog->http_post('/servers/' . $this->hid . '/actions/enable_rescue', $body);

        if (!empty($r['action']) && ($r['action']['status'] ?? '') !== 'error') {
            $pass = $r['root_password'] ?? null;
            return ['ok'=>true, 'message'=>'Rescue mode enabled. Reboot within 60 minutes.', 'root_password'=>$pass];
        }
        return ['ok'=>false, 'error'=>$r['error']['message'] ?? 'Could not enable rescue.'];
    }

    public function enable_rescue_cycle(array $payload = []): array
    {
        $res = $this->enable_rescue($payload);
        if (!$res['ok']) return $res;
        $this->reboot();
        return ['ok'=>true, 'message'=>'Rescue enabled and server rebooted.', 'root_password'=>$res['root_password']??null];
    }

    public function reset_root_password(): array
    {
        $r = $this->cloud->catalog->http_post('/servers/' . $this->hid . '/actions/reset_password');
        if (!empty($r['action'])) {
            $pass = $r['root_password'] ?? null;
            return ['ok'=>true, 'message'=>'Root password reset.', 'root_password'=>$pass];
        }
        return ['ok'=>false, 'error'=>$r['error']['message'] ?? 'Could not reset password.'];
    }

    /* ── Rebuild ──────────────────────────────────────────────── */

    public function rebuild(array $payload): array
    {
        $image = trim($payload['image'] ?? '');
        if (!$image) return ['ok'=>false, 'error'=>'Image is required.'];

        $r = $this->cloud->catalog->http_post('/servers/' . $this->hid . '/actions/rebuild', ['image' => $image]);

        // Hetzner rebuild returns: {"action":{...}, "root_password":"..."} with HTTP 200/201
        $http_ok    = in_array($r['_http_status'] ?? 0, [200, 201, 202], true);
        $has_action = isset($r['action']) || isset($r['rebuild_action']);

        if ($http_ok || $has_action) {
            $pass = $r['root_password'] ?? null;

            // image slug → derive os_label for DB update
            // image can be "ubuntu-22.04" or "debian-12" or numeric ID
            $os_label   = null;
            $image_slug = null;
            if (!is_numeric($image)) {
                $image_slug = strtolower(explode('-', $image)[0]); // "ubuntu-22.04" → "ubuntu"
                // Friendly label map
                $label_map = [
                    'ubuntu'  => 'Ubuntu',
                    'debian'  => 'Debian',
                    'centos'  => 'CentOS',
                    'rocky'   => 'Rocky Linux',
                    'alma'    => 'AlmaLinux',
                    'fedora'  => 'Fedora',
                    'arch'    => 'Arch Linux',
                    'windows' => 'Windows',
                ];
                $base  = $label_map[$image_slug] ?? ucfirst($image_slug);
                // Version part e.g. "22.04" from "ubuntu-22.04"
                $parts = explode('-', $image, 2);
                $ver   = $parts[1] ?? null;
                $os_label = $ver ? $base . ' ' . $ver : $base;
            } else {
                // Numeric image ID — fetch from Hetzner to get name
                try {
                    $img_r = $this->cloud->catalog->http_get('/images/' . (int)$image);
                    $img   = $img_r['image'] ?? [];
                    $os_label   = $img['description'] ?? $img['name'] ?? null;
                    $image_slug = $img['os_flavor'] ?? strtolower(explode('-', $img['name'] ?? '', 2)[0]) ?: null;
                } catch (Throwable $e) {}
            }

            return [
                'ok'          => true,
                'message'     => 'Server rebuild started.',
                'root_password' => $pass,
                'image_slug'  => $image_slug,
                'os_label'    => $os_label,
            ];
        }

        // Error from Hetzner (e.g. invalid_image_type for unsupported OS slugs)
        $err_msg = $r['error']['message'] ?? ($r['error']['code'] ?? 'Rebuild failed.');
        return ['ok'=>false, 'error'=>$err_msg];
    }

    /* ── Snapshots ────────────────────────────────────────────── */

    public function create_snapshot(array $payload = []): array
    {
        $desc = trim($payload['description'] ?? date('Y-m-d H:i'));
        $r    = $this->cloud->catalog->http_post('/servers/' . $this->hid . '/actions/create_image', [
            'description' => $desc,
            'type'        => 'snapshot',
        ]);
        if (!empty($r['image'])) {
            return ['ok'=>true, 'message'=>'Snapshot created: ' . ($r['image']['description'] ?? $desc), 'image'=>$r['image']];
        }
        return ['ok'=>false, 'error'=>$r['error']['message'] ?? 'Snapshot failed.'];
    }

    public function list_snapshots(): array
    {
        $r = $this->cloud->catalog->http_get('/images', ['type'=>'snapshot', 'bound_to'=>$this->hid]);
        return ['ok'=>true, 'snapshots'=>$r['images'] ?? []];
    }

    public function delete_snapshot(array $payload): array
    {
        $image_id = (int)($payload['image_id'] ?? 0);
        if (!$image_id) return ['ok'=>false, 'error'=>'Image ID required.'];
        $r = $this->cloud->catalog->http_delete('/images/' . $image_id);
        return ['ok'=>true, 'message'=>'Snapshot deleted.'];
    }

    /* ── Volumes ──────────────────────────────────────────────── */

    public function list_volumes(): array
    {
        $r = $this->cloud->catalog->http_get('/volumes');
        $vols = array_filter($r['volumes'] ?? [], function($v) {
            return isset($v['server']) && (int)$v['server'] === (int)$this->hid;
        });
        return ['ok'=>true, 'volumes'=>array_values($vols)];
    }

    public function create_volume(array $payload): array
    {
        $name     = trim($payload['volume_name'] ?? '');
        $size_gb  = (int)($payload['size_gb'] ?? 10);
        $location = $this->server['region_slug'] ?? 'nbg1';
        $format   = $payload['format'] ?? 'ext4';
        $automount= (bool)($payload['automount'] ?? true);

        if (!$name) return ['ok'=>false, 'error'=>'Volume name required.'];
        if ($size_gb < 10 || $size_gb > 10240) return ['ok'=>false, 'error'=>'Size must be 10–10240 GB.'];

        $r = $this->cloud->catalog->http_post('/volumes', [
            'name'      => $name,
            'size'      => $size_gb,
            'server'    => (int)$this->hid,
            'location'  => $location,
            'format'    => $format,
            'automount' => $automount,
        ]);

        if (!empty($r['volume'])) {
            return ['ok'=>true, 'message'=>"Volume '{$name}' created and attached.", 'volume'=>$r['volume']];
        }
        return ['ok'=>false, 'error'=>$r['error']['message'] ?? 'Volume creation failed.'];
    }

    public function attach_volume(array $payload): array
    {
        $vol_id   = (int)($payload['volume_id'] ?? 0);
        $automount= (bool)($payload['automount'] ?? true);
        if (!$vol_id) return ['ok'=>false, 'error'=>'Volume ID required.'];

        $r = $this->cloud->catalog->http_post('/volumes/' . $vol_id . '/actions/attach', [
            'server'    => (int)$this->hid,
            'automount' => $automount,
        ]);
        return $this->action_result($r, 'Volume attached.');
    }

    public function detach_volume(array $payload): array
    {
        $vol_id = (int)($payload['volume_id'] ?? 0);
        if (!$vol_id) return ['ok'=>false, 'error'=>'Volume ID required.'];
        $r = $this->cloud->catalog->http_post('/volumes/' . $vol_id . '/actions/detach');
        return $this->action_result($r, 'Volume detached.');
    }

    public function resize_volume(array $payload): array
    {
        $vol_id  = (int)($payload['volume_id'] ?? 0);
        $size_gb = (int)($payload['size_gb']   ?? 0);
        if (!$vol_id || $size_gb < 10) return ['ok'=>false, 'error'=>'Invalid volume ID or size.'];
        $r = $this->cloud->catalog->http_post('/volumes/' . $vol_id . '/actions/resize', ['size' => $size_gb]);
        return $this->action_result($r, 'Volume resize started.');
    }

    public function delete_volume(array $payload): array
    {
        $vol_id = (int)($payload['volume_id'] ?? 0);
        if (!$vol_id) return ['ok'=>false, 'error'=>'Volume ID required.'];
        // Must detach first
        $this->cloud->catalog->http_post('/volumes/' . $vol_id . '/actions/detach');
        $this->cloud->catalog->http_delete('/volumes/' . $vol_id);
        return ['ok'=>true, 'message'=>'Volume deleted.'];
    }

    /* ── Firewalls ────────────────────────────────────────────── */

    public function list_server_firewalls(): array
    {
        // Get from Hetzner API - server details has firewall_status
        $r       = $this->cloud->catalog->http_get('/servers/' . $this->hid);
        $fwrefs  = $r['server']['firewall_status'] ?? [];

        // For each, get full firewall details
        $firewalls = [];
        foreach ($fwrefs as $ref) {
            $fwid = $ref['firewall']['id'] ?? null;
            if (!$fwid) continue;
            $fwdata = $this->cloud->catalog->http_get('/firewalls/' . $fwid);
            if (!empty($fwdata['firewall'])) {
                $firewalls[] = array_merge($fwdata['firewall'], ['status'=>$ref['status']??'applied']);
            }
        }
        return ['ok'=>true, 'firewalls'=>$firewalls];
    }

    public function apply_firewall(array $payload): array
    {
        $fw_id = (int)($payload['firewall_id'] ?? 0);
        if (!$fw_id) return ['ok'=>false, 'error'=>'Firewall ID required.'];
        $r = $this->cloud->catalog->http_post('/firewalls/' . $fw_id . '/actions/apply_to_resources', [
            'apply_to' => [['type'=>'server','server'=>['id'=>(int)$this->hid]]]
        ]);
        return $this->action_result($r, 'Firewall applied to server.');
    }

    public function remove_firewall(array $payload): array
    {
        $fw_id = (int)($payload['firewall_id'] ?? 0);
        if (!$fw_id) return ['ok'=>false, 'error'=>'Firewall ID required.'];
        $r = $this->cloud->catalog->http_post('/firewalls/' . $fw_id . '/actions/remove_from_resources', [
            'remove_from' => [['type'=>'server','server'=>['id'=>(int)$this->hid]]]
        ]);
        return $this->action_result($r, 'Firewall removed from server.');
    }

    /* ── Console (VNC) ────────────────────────────────────────── */

    public function get_console(): array
    {
        $r = $this->cloud->catalog->http_post('/servers/' . $this->hid . '/actions/request_console');
        if (!empty($r['action']) && !empty($r['wss_url'])) {
            return ['ok'=>true, 'url'=>$r['wss_url'], 'password'=>$r['password']??null];
        }
        // Hetzner returns console details differently
        if (!empty($r['wss_url'])) {
            return ['ok'=>true, 'url'=>$r['wss_url'], 'password'=>$r['password']??null];
        }
        return ['ok'=>false, 'error'=>$r['error']['message'] ?? 'Console not available.'];
    }

    /* ── Floating IPs ─────────────────────────────────────────── */

    public function list_floating_ips(): array
    {
        // List all floating IPs in the project, filter by assigned server
        $r    = $this->cloud->catalog->http_get('/floating_ips', ['per_page'=>50]);
        $all  = $r['floating_ips'] ?? [];
        $mine = array_filter($all, fn($f) => (int)($f['server'] ?? 0) === (int)$this->hid);
        // Also include unassigned ones so user can assign them
        $free = array_filter($all, fn($f) => empty($f['server']));
        return ['ok'=>true, 'assigned'=>array_values($mine), 'available'=>array_values($free)];
    }

    public function create_floating_ip(array $payload): array
    {
        $type     = $payload['type']     ?? 'ipv4';
        $location = $payload['location'] ?? ($this->server['region_slug'] ?? 'nbg1');
        $desc     = $payload['desc']     ?? 'Floating IP for ' . ($this->server['name'] ?? 'server');

        $r = $this->cloud->catalog->http_post('/floating_ips', [
            'type'             => $type,
            'home_location'    => $location,
            'description'      => $desc,
            'server'           => (int)$this->hid,
        ]);
        if (!empty($r['floating_ip'])) {
            return ['ok'=>true, 'message'=>'Floating IP created and assigned.', 'ip'=>$r['floating_ip']];
        }
        return ['ok'=>false, 'error'=>$r['error']['message'] ?? 'Could not create floating IP.'];
    }

    public function assign_floating_ip(array $payload): array
    {
        $fip_id = (int)($payload['fip_id'] ?? 0);
        if (!$fip_id) return ['ok'=>false, 'error'=>'Floating IP ID required.'];
        $r = $this->cloud->catalog->http_post('/floating_ips/' . $fip_id . '/actions/assign', [
            'server' => (int)$this->hid,
        ]);
        return $this->action_result($r, 'Floating IP assigned.');
    }

    public function unassign_floating_ip(array $payload): array
    {
        $fip_id = (int)($payload['fip_id'] ?? 0);
        if (!$fip_id) return ['ok'=>false, 'error'=>'Floating IP ID required.'];
        $r = $this->cloud->catalog->http_post('/floating_ips/' . $fip_id . '/actions/unassign');
        return $this->action_result($r, 'Floating IP unassigned.');
    }

    public function delete_floating_ip(array $payload): array
    {
        $fip_id = (int)($payload['fip_id'] ?? 0);
        if (!$fip_id) return ['ok'=>false, 'error'=>'Floating IP ID required.'];
        $this->cloud->catalog->http_delete('/floating_ips/' . $fip_id);
        return ['ok'=>true, 'message'=>'Floating IP deleted.'];
    }

    /* ── Private Networks ─────────────────────────────────────── */

    public function list_networks(): array
    {
        // Get networks from server details
        $r    = $this->cloud->catalog->http_get('/servers/' . $this->hid);
        $nets = $r['server']['private_net'] ?? [];
        return ['ok'=>true, 'networks'=>$nets];
    }

    public function list_all_networks(): array
    {
        $r = $this->cloud->catalog->http_get('/networks', ['per_page'=>50]);
        return ['ok'=>true, 'networks'=>$r['networks'] ?? []];
    }

    public function create_network(array $payload): array
    {
        $name    = trim($payload['network_name'] ?? '');
        $ip_range= trim($payload['ip_range'] ?? '10.0.0.0/16');
        if (!$name) return ['ok'=>false, 'error'=>'Network name required.'];

        $r = $this->cloud->catalog->http_post('/networks', [
            'name'     => $name,
            'ip_range' => $ip_range,
            'subnets'  => [['type'=>'cloud','ip_range'=>$ip_range,'network_zone'=>'eu-central']],
        ]);
        if (!empty($r['network'])) {
            // Attach to server
            $net_id = $r['network']['id'];
            $this->cloud->catalog->http_post('/servers/' . $this->hid . '/actions/attach_to_network', [
                'network' => $net_id,
            ]);
            return ['ok'=>true, 'message'=>'Network created and attached.', 'network'=>$r['network']];
        }
        return ['ok'=>false, 'error'=>$r['error']['message'] ?? 'Could not create network.'];
    }

    public function attach_network(array $payload): array
    {
        $net_id = (int)($payload['network_id'] ?? 0);
        if (!$net_id) return ['ok'=>false, 'error'=>'Network ID required.'];
        $r = $this->cloud->catalog->http_post('/servers/' . $this->hid . '/actions/attach_to_network', [
            'network' => $net_id,
        ]);
        return $this->action_result($r, 'Network attached.');
    }

    public function detach_network(array $payload): array
    {
        $net_id = (int)($payload['network_id'] ?? 0);
        if (!$net_id) return ['ok'=>false, 'error'=>'Network ID required.'];
        $r = $this->cloud->catalog->http_post('/servers/' . $this->hid . '/actions/detach_from_network', [
            'network' => $net_id,
        ]);
        return $this->action_result($r, 'Network detached.');
    }

    /* ── list_server_firewalls — return full firewall details ─── */

    /* ── Delete server ────────────────────────────────────────── */

    public function delete_server(): array
    {
        $r = $this->cloud->catalog->http_delete('/servers/' . $this->hid);
        if (($r['_http_status'] ?? 0) === 200 || ($r['_http_status'] ?? 0) === 204 || isset($r['action'])) {
            return ['ok'=>true, 'message'=>'Server deleted from provider.'];
        }
        // Already deleted or not found = treat as success
        if (($r['_http_status'] ?? 0) === 404) {
            return ['ok'=>true, 'message'=>'Server deleted.'];
        }
        return ['ok'=>false, 'error'=>$r['error']['message'] ?? 'Delete failed.'];
    }

    /* ── Helper ───────────────────────────────────────────────── */

    private function action_result(array $r, string $success_msg): array
    {
        if (!empty($r['action']) && ($r['action']['status'] ?? '') !== 'error') {
            return ['ok'=>true, 'message'=>$success_msg];
        }
        if (!empty($r['actions'])) {
            return ['ok'=>true, 'message'=>$success_msg];
        }
        $err = $r['error']['message'] ?? null;
        return $err
            ? ['ok'=>false, 'error'=>$err]
            : ['ok'=>true,  'message'=>$success_msg]; // 204 No Content = success
    }
}