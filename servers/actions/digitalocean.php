<?php
/**
 * servers/actions/digitalocean.php
 * DigitalOcean panel action handler — called by api/server-action.php
 */
declare(strict_types=1);

class DigitaloceanActions
{
    private object $cloud;
    private array  $server;
    private int    $did; // Droplet ID

    public function __construct(object $cloud, array $server)
    {
        $this->cloud  = $cloud;
        $this->server = $server;
        $this->did    = (int)($server['provider_id'] ?? 0);
    }

    private function action(array $payload, string $msg): array
    {
        $r = $this->cloud->catalog->http_post('/droplets/' . $this->did . '/actions', $payload);
        if (DOClient::isOk($r) && !empty($r['action'])) return ['ok' => true, 'message' => $msg];
        return ['ok' => false, 'error' => DOClient::errMsg($r, 'Action failed.')];
    }

    public function start(): array    { return $this->action(['type'=>'power_on'],   'Server is starting.'); }
    public function stop(): array     { return $this->action(['type'=>'power_off'],  'Server powered off.'); }
    public function shutdown(): array { return $this->action(['type'=>'shutdown'],   'Shutdown signal sent.'); }
    public function reboot(): array   { return $this->action(['type'=>'reboot'],     'Server is rebooting.'); }
    public function reset(): array    { return $this->action(['type'=>'power_cycle'],'Server power cycled.'); }

    public function enable_rescue(array $payload = []): array
    {
        // DO: password reset email effectively is rescue
        $r = $this->action(['type' => 'password_reset'], 'Password reset email sent. Use that to regain access.');
        return $r;
    }

    public function enable_rescue_cycle(array $payload = []): array
    {
        return $this->enable_rescue($payload);
    }

    public function reset_root_password(): array
    {
        return $this->action(['type' => 'password_reset'],
            'Password reset initiated. New root password will be sent to your DigitalOcean account email.');
    }

    public function rebuild(array $payload): array
    {
        $image = trim($payload['image'] ?? '');
        if (!$image) return ['ok' => false, 'error' => 'Image slug required.'];
        $r = $this->cloud->catalog->http_post('/droplets/' . $this->did . '/actions', [
            'type'  => 'rebuild',
            'image' => $image,
        ]);
        if (DOClient::isOk($r) && !empty($r['action'])) return ['ok' => true, 'message' => 'Server rebuild started.'];
        return ['ok' => false, 'error' => DOClient::errMsg($r, 'Rebuild failed.')];
    }

    public function create_snapshot(array $payload = []): array
    {
        $name = trim($payload['description'] ?? ('snapshot-' . date('Ymd-Hi')));
        $r = $this->cloud->catalog->http_post('/droplets/' . $this->did . '/actions', [
            'type' => 'snapshot',
            'name' => $name,
        ]);
        if (DOClient::isOk($r)) return ['ok' => true, 'message' => "Snapshot '{$name}' started."];
        return ['ok' => false, 'error' => DOClient::errMsg($r, 'Snapshot failed.')];
    }

    public function list_snapshots(): array
    {
        $r = $this->cloud->catalog->http_get('/droplets/' . $this->did . '/snapshots', ['per_page' => 50]);
        $snaps = array_map(fn($s) => [
            'id'          => $s['id']           ?? null,
            'description' => $s['name']         ?? 'Snapshot',
            'created'     => $s['created_at']   ?? null,
            'image_size'  => $s['size_gigabytes']?? null,
            'status'      => 'available',
        ], $r['snapshots'] ?? []);
        return ['ok' => true, 'snapshots' => $snaps];
    }

    public function delete_snapshot(array $payload): array
    {
        $snap_id = (int)($payload['image_id'] ?? $payload['snapshot_id'] ?? 0);
        if (!$snap_id) return ['ok' => false, 'error' => 'Snapshot ID required.'];
        $r = $this->cloud->catalog->http_delete('/snapshots/' . $snap_id);
        return ($r['_http_status'] ?? 0) === 204
            ? ['ok' => true, 'message' => 'Snapshot deleted.']
            : ['ok' => false, 'error' => DOClient::errMsg($r, 'Delete failed.')];
    }

    public function list_volumes(): array
    {
        $r    = $this->cloud->catalog->http_get('/volumes', ['per_page' => 100]);
        $vols = array_filter($r['volumes'] ?? [], fn($v) => in_array($this->did, $v['droplet_ids'] ?? []));
        return ['ok' => true, 'volumes' => array_values(array_map(fn($v) => [
            'id'           => $v['id'],
            'name'         => $v['name'],
            'size'         => $v['size_gigabytes'],
            'status'       => 'active',
            'linux_device' => '/dev/disk/by-id/scsi-0DO_Volume_' . ($v['name'] ?? ''),
            'location'     => ['name' => $v['region']['slug'] ?? ''],
        ], $vols))];
    }

    public function create_volume(array $payload): array
    {
        $name    = trim($payload['volume_name'] ?? '');
        $size_gb = (int)($payload['size_gb'] ?? 20);
        $region  = $this->server['region_slug'] ?? 'nyc1';
        if (!$name) return ['ok' => false, 'error' => 'Volume name required.'];

        $r = $this->cloud->catalog->http_post('/volumes', [
            'size_gigabytes'  => $size_gb,
            'name'            => $name,
            'region'          => $region,
            'filesystem_type' => 'ext4',
        ]);
        if (!empty($r['volume']['id'])) {
            $volId = $r['volume']['id'];
            $this->cloud->catalog->http_post('/volumes/' . $volId . '/actions', ['type' => 'attach', 'droplet_id' => $this->did, 'region' => $region]);
            return ['ok' => true, 'message' => "Volume '{$name}' created and attached.", 'volume' => ['id' => $volId, 'name' => $name, 'size' => $size_gb]];
        }
        return ['ok' => false, 'error' => DOClient::errMsg($r, 'Volume creation failed.')];
    }

    public function attach_volume(array $payload): array
    {
        $vol_id = $payload['volume_id'] ?? '';
        $region = $this->server['region_slug'] ?? 'nyc1';
        if (!$vol_id) return ['ok' => false, 'error' => 'Volume ID required.'];
        $r = $this->cloud->catalog->http_post('/volumes/' . $vol_id . '/actions', ['type' => 'attach', 'droplet_id' => $this->did, 'region' => $region]);
        return DOClient::isOk($r) ? ['ok' => true, 'message' => 'Volume attached.'] : ['ok' => false, 'error' => DOClient::errMsg($r, 'Attach failed.')];
    }

    public function detach_volume(array $payload): array
    {
        $vol_id = $payload['volume_id'] ?? '';
        $region = $this->server['region_slug'] ?? 'nyc1';
        if (!$vol_id) return ['ok' => false, 'error' => 'Volume ID required.'];
        $r = $this->cloud->catalog->http_post('/volumes/' . $vol_id . '/actions', ['type' => 'detach', 'droplet_id' => $this->did, 'region' => $region]);
        return DOClient::isOk($r) ? ['ok' => true, 'message' => 'Volume detached.'] : ['ok' => false, 'error' => DOClient::errMsg($r, 'Detach failed.')];
    }

    public function resize_volume(array $payload): array
    {
        $vol_id  = $payload['volume_id'] ?? '';
        $size_gb = (int)($payload['size_gb'] ?? 0);
        $region  = $this->server['region_slug'] ?? 'nyc1';
        if (!$vol_id || $size_gb < 1) return ['ok' => false, 'error' => 'Invalid params.'];
        $r = $this->cloud->catalog->http_post('/volumes/' . $vol_id . '/actions', ['type' => 'resize', 'size_gigabytes' => $size_gb, 'region' => $region]);
        return DOClient::isOk($r) ? ['ok' => true, 'message' => 'Volume resize started.'] : ['ok' => false, 'error' => DOClient::errMsg($r, 'Resize failed.')];
    }

    public function delete_volume(array $payload): array
    {
        $vol_id = $payload['volume_id'] ?? '';
        $region = $this->server['region_slug'] ?? 'nyc1';
        if (!$vol_id) return ['ok' => false, 'error' => 'Volume ID required.'];
        $this->cloud->catalog->http_post('/volumes/' . $vol_id . '/actions', ['type' => 'detach', 'droplet_id' => $this->did, 'region' => $region]);
        $r = $this->cloud->catalog->http_delete('/volumes/' . $vol_id);
        return ($r['_http_status'] ?? 0) === 204 ? ['ok' => true, 'message' => 'Volume deleted.'] : ['ok' => false, 'error' => DOClient::errMsg($r, 'Delete failed.')];
    }

    public function list_server_firewalls(): array
    {
        $r  = $this->cloud->catalog->http_get('/firewalls', ['per_page' => 100]);
        $fw = array_filter($r['firewalls'] ?? [], fn($f) => in_array($this->did, $f['droplet_ids'] ?? []));
        return ['ok' => true, 'firewalls' => array_values(array_map(fn($f) => ['id' => $f['id'], 'name' => $f['name'], 'rules' => [], 'status' => $f['status'] ?? 'active'], $fw))];
    }

    public function apply_firewall(array $payload): array
    {
        $fw_id = $payload['firewall_id'] ?? '';
        if (!$fw_id) return ['ok' => false, 'error' => 'Firewall ID required.'];
        $r = $this->cloud->catalog->http_post('/firewalls/' . $fw_id . '/droplets', ['droplet_ids' => [$this->did]]);
        return ($r['_http_status'] ?? 0) === 204 ? ['ok' => true, 'message' => 'Firewall applied.'] : ['ok' => false, 'error' => DOClient::errMsg($r, 'Apply failed.')];
    }

    public function remove_firewall(array $payload): array
    {
        return ['ok' => true, 'message' => 'Use DigitalOcean panel to remove firewall from this Droplet.'];
    }

    public function list_floating_ips(): array
    {
        $r   = $this->cloud->catalog->http_get('/reserved_ips', ['per_page' => 50]);
        $all = $r['reserved_ips'] ?? $r['floating_ips'] ?? [];
        $mine = array_filter($all, fn($f) => (int)($f['droplet']['id'] ?? 0) === $this->did);
        $free = array_filter($all, fn($f) => empty($f['droplet']));
        $fmt  = fn($f) => ['id' => $f['ip'], 'ip' => $f['ip'], 'type' => 'ipv4', 'home_location' => ['name' => $f['region']['slug'] ?? '']];
        return ['ok' => true, 'assigned' => array_values(array_map($fmt, $mine)), 'available' => array_values(array_map($fmt, $free))];
    }

    public function create_floating_ip(array $payload): array
    {
        $r = $this->cloud->catalog->http_post('/reserved_ips', ['droplet_id' => $this->did]);
        if (!empty($r['reserved_ip']['ip'])) return ['ok' => true, 'message' => 'Reserved IP created: ' . $r['reserved_ip']['ip'], 'ip' => $r['reserved_ip']];
        return ['ok' => false, 'error' => DOClient::errMsg($r, 'Could not create reserved IP.')];
    }

    public function assign_floating_ip(array $payload): array
    {
        $ip = $payload['fip_id'] ?? '';
        if (!$ip) return ['ok' => false, 'error' => 'IP required.'];
        $r = $this->cloud->catalog->http_post('/reserved_ips/' . $ip . '/actions', ['type' => 'assign', 'droplet_id' => $this->did]);
        return DOClient::isOk($r) ? ['ok' => true, 'message' => 'Reserved IP assigned.'] : ['ok' => false, 'error' => DOClient::errMsg($r, 'Assign failed.')];
    }

    public function unassign_floating_ip(array $payload): array
    {
        $ip = $payload['fip_id'] ?? '';
        if (!$ip) return ['ok' => false, 'error' => 'IP required.'];
        $r = $this->cloud->catalog->http_post('/reserved_ips/' . $ip . '/actions', ['type' => 'unassign']);
        return DOClient::isOk($r) ? ['ok' => true, 'message' => 'Reserved IP unassigned.'] : ['ok' => false, 'error' => DOClient::errMsg($r, 'Unassign failed.')];
    }

    public function delete_floating_ip(array $payload): array
    {
        $ip = $payload['fip_id'] ?? '';
        if (!$ip) return ['ok' => false, 'error' => 'IP required.'];
        $r = $this->cloud->catalog->http_delete('/reserved_ips/' . $ip);
        return ($r['_http_status'] ?? 0) === 204 ? ['ok' => true, 'message' => 'Reserved IP deleted.'] : ['ok' => false, 'error' => DOClient::errMsg($r, 'Delete failed.')];
    }

    public function list_networks(): array
    {
        $r       = $this->cloud->catalog->http_get('/droplets/' . $this->did);
        $droplet = $r['droplet'] ?? [];
        $nets    = [];
        foreach ($droplet['networks']['v4'] ?? [] as $net) {
            if ($net['type'] === 'private') {
                $nets[] = ['ip' => $net['ip_address'], 'network_id' => null, 'mac_address' => null, 'network' => ['name' => 'VPC Network']];
            }
        }
        return ['ok' => true, 'networks' => $nets];
    }

    public function list_all_networks(): array
    {
        $r = $this->cloud->catalog->http_get('/vpcs', ['per_page' => 50]);
        return ['ok' => true, 'networks' => array_map(fn($v) => ['id' => $v['id'], 'name' => $v['name'], 'ip_range' => $v['ip_range'] ?? ''], $r['vpcs'] ?? [])];
    }

    public function create_network(array $payload): array
    {
        $name  = trim($payload['network_name'] ?? '');
        $range = trim($payload['ip_range']     ?? '10.10.0.0/16');
        $region= $this->server['region_slug']  ?? 'nyc1';
        if (!$name) return ['ok' => false, 'error' => 'Network name required.'];
        $r = $this->cloud->catalog->http_post('/vpcs', ['name' => $name, 'region' => $region, 'ip_range' => $range]);
        if (!empty($r['vpc']['id'])) return ['ok' => true, 'message' => "VPC '{$name}' created.", 'network' => $r['vpc']];
        return ['ok' => false, 'error' => DOClient::errMsg($r, 'VPC creation failed.')];
    }

    public function attach_network(array $payload): array
    {
        return ['ok' => false, 'error' => 'To attach a Droplet to a different VPC, recreate it via DigitalOcean panel.'];
    }

    public function detach_network(array $payload): array
    {
        return ['ok' => false, 'error' => 'Network detach not supported via API. Use DigitalOcean panel.'];
    }

    public function get_console(): array
    {
        return [
            'ok'       => true,
            'url'      => 'https://cloud.digitalocean.com/droplets/' . $this->did . '/console',
            'password' => null,
        ];
    }

    public function delete_server(): array
    {
        $r    = $this->cloud->catalog->http_delete('/droplets/' . $this->did);
        $code = $r['_http_status'] ?? 0;
        if ($code === 204 || $code === 404) return ['ok' => true, 'message' => 'Droplet deleted.'];
        return ['ok' => false, 'error' => DOClient::errMsg($r, 'Delete failed.')];
    }
}
