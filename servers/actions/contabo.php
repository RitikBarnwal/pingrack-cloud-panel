<?php
/**
 * servers/actions/contabo.php
 * Contabo panel action handler — called by api/server-action.php
 */
declare(strict_types=1);

class ContaboActions
{
    private object $cloud;
    private array  $server;
    private int    $cid; // Contabo instanceId

    public function __construct(object $cloud, array $server)
    {
        $this->cloud  = $cloud;
        $this->server = $server;
        $this->cid    = (int)($server['provider_id'] ?? 0);
    }

    public function start(): array
    {
        $r = $this->cloud->catalog->http_post('/compute/instances/' . $this->cid . '/actions/start');
        return $this->ok($r, 'Server is starting.');
    }

    public function stop(): array
    {
        $r = $this->cloud->catalog->http_post('/compute/instances/' . $this->cid . '/actions/stop');
        return $this->ok($r, 'Shutdown signal sent.');
    }

    public function shutdown(): array { return $this->stop(); }

    public function reboot(): array
    {
        $r = $this->cloud->catalog->http_post('/compute/instances/' . $this->cid . '/actions/restart');
        return $this->ok($r, 'Server is rebooting.');
    }

    public function reset(): array { return $this->reboot(); }

    public function enable_rescue(array $payload = []): array
    {
        $r = $this->cloud->catalog->http_post('/compute/instances/' . $this->cid . '/actions/rescue');
        if (ContaboClient::isOk($r)) {
            $pass = $r['data'][0]['rootPassword'] ?? null;
            return ['ok' => true, 'message' => 'Rescue mode enabled.', 'root_password' => $pass];
        }
        return ['ok' => false, 'error' => ContaboClient::errMsg($r, 'Rescue failed.')];
    }

    public function enable_rescue_cycle(array $payload = []): array
    {
        $res = $this->enable_rescue($payload);
        if (!$res['ok']) return $res;
        $this->reboot();
        return array_merge($res, ['message' => 'Rescue enabled and rebooted.']);
    }

    public function reset_root_password(): array
    {
        // Contabo sends new password via email
        $r = $this->cloud->catalog->http_post('/compute/instances/' . $this->cid . '/actions/resetPasswordRequest');
        if (ContaboClient::isOk($r)) {
            return ['ok' => true, 'message' => 'Password reset requested. New password sent to your Contabo account email.'];
        }
        return ['ok' => false, 'error' => ContaboClient::errMsg($r, 'Password reset failed.')];
    }

    public function rebuild(array $payload): array
    {
        $image = trim($payload['image'] ?? '');
        if (!$image) return ['ok' => false, 'error' => 'Image ID required.'];

        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
        $pass  = '';
        for ($i = 0; $i < 20; $i++) $pass .= $chars[random_int(0, strlen($chars)-1)];

        $r = $this->cloud->catalog->http_put('/compute/instances/' . $this->cid, [
            'imageId'      => $image,
            'rootPassword' => $pass,
            'defaultUser'  => 'root',
        ]);
        if (ContaboClient::isOk($r)) {
            return ['ok' => true, 'message' => 'Server reinstall started.', 'root_password' => $pass];
        }
        return ['ok' => false, 'error' => ContaboClient::errMsg($r, 'Reinstall failed.')];
    }

    public function create_snapshot(array $payload = []): array
    {
        $label = trim($payload['description'] ?? ('snapshot-' . date('Ymd-Hi')));
        $r = $this->cloud->catalog->http_post('/compute/snapshots', [
            'instanceId' => $this->cid,
            'name'       => $label,
        ]);
        return $this->ok($r, "Snapshot '{$label}' created.");
    }

    public function list_snapshots(): array
    {
        $r = $this->cloud->catalog->http_get('/compute/snapshots', ['instanceId' => $this->cid]);
        $snaps = array_map(fn($s) => [
            'id'          => $s['snapshotId'] ?? $s['id']  ?? null,
            'description' => $s['name']       ?? 'Snapshot',
            'created'     => $s['createdDate']?? null,
            'image_size'  => null,
            'status'      => 'available',
        ], $r['data'] ?? []);
        return ['ok' => true, 'snapshots' => $snaps];
    }

    public function delete_snapshot(array $payload): array
    {
        $snap_id = $payload['image_id'] ?? $payload['snapshot_id'] ?? '';
        if (!$snap_id) return ['ok' => false, 'error' => 'Snapshot ID required.'];
        $r = $this->cloud->catalog->http_delete('/compute/snapshots/' . $snap_id);
        return ContaboClient::isOk($r)
            ? ['ok' => true, 'message' => 'Snapshot deleted.']
            : ['ok' => false, 'error' => ContaboClient::errMsg($r, 'Delete failed.')];
    }

    public function list_volumes(): array
    {
        return ['ok' => true, 'volumes' => []]; // Contabo no block volumes API
    }

    public function create_volume(array $p): array { return ['ok'=>false,'error'=>'Use Contabo console to order storage add-ons.']; }
    public function attach_volume(array $p): array { return ['ok'=>false,'error'=>'Volume management via Contabo console.']; }
    public function detach_volume(array $p): array { return ['ok'=>false,'error'=>'Volume management via Contabo console.']; }
    public function resize_volume(array $p): array { return ['ok'=>false,'error'=>'Volume management via Contabo console.']; }
    public function delete_volume(array $p): array { return ['ok'=>false,'error'=>'Volume management via Contabo console.']; }

    public function list_server_firewalls(): array { return ['ok'=>true,'firewalls'=>[]]; }
    public function apply_firewall(array $p): array  { return ['ok'=>false,'error'=>'Contabo firewalls managed via console. Use iptables on server.']; }
    public function remove_firewall(array $p): array { return ['ok'=>false,'error'=>'Contabo firewalls managed via console.']; }

    public function list_floating_ips(): array
    {
        $r    = $this->cloud->catalog->http_get('/compute/instances/' . $this->cid);
        $inst = $r['data'][0] ?? [];
        $ipv4 = $inst['ipConfig']['v4']['ip'] ?? null;
        $ips  = $ipv4 ? [['id'=>$ipv4,'ip'=>$ipv4,'type'=>'ipv4','home_location'=>['name'=>strtolower($inst['region']??'')]]] : [];
        return ['ok' => true, 'assigned' => $ips, 'available' => []];
    }

    public function create_floating_ip(array $p): array  { return ['ok'=>false,'error'=>'Additional IPs via Contabo console.']; }
    public function assign_floating_ip(array $p): array  { return ['ok'=>false,'error'=>'IP management via Contabo console.']; }
    public function unassign_floating_ip(array $p): array{ return ['ok'=>false,'error'=>'IP management via Contabo console.']; }
    public function delete_floating_ip(array $p): array  { return ['ok'=>false,'error'=>'IP management via Contabo console.']; }

    public function list_networks(): array         { return ['ok'=>true,'networks'=>[]]; }
    public function list_all_networks(): array     { return ['ok'=>true,'networks'=>[]]; }
    public function create_network(array $p): array{ return ['ok'=>false,'error'=>'Network management via Contabo console.']; }
    public function attach_network(array $p): array{ return ['ok'=>false,'error'=>'Network management via Contabo console.']; }
    public function detach_network(array $p): array{ return ['ok'=>false,'error'=>'Network management via Contabo console.']; }

    public function get_console(): array
    {
        $r   = $this->cloud->catalog->http_get('/compute/instances/' . $this->cid . '/console');
        $url = $r['data'][0]['url'] ?? $r['url'] ?? null;
        if ($url) return ['ok'=>true,'url'=>$url,'password'=>$r['data'][0]['password']??null];
        return ['ok'=>false,'error'=>ContaboClient::errMsg($r,'Console not available.')];
    }

    public function delete_server(): array
    {
        $r    = $this->cloud->catalog->http_delete('/compute/instances/' . $this->cid);
        $code = $r['_http_status'] ?? 0;
        if (ContaboClient::isOk($r) || $code === 404) return ['ok'=>true,'message'=>'Instance cancellation scheduled.'];
        return ['ok'=>false,'error'=>ContaboClient::errMsg($r,'Cancel failed.')];
    }

    private function ok(array $r, string $msg): array
    {
        return ContaboClient::isOk($r)
            ? ['ok'=>true,'message'=>$msg]
            : ['ok'=>false,'error'=>ContaboClient::errMsg($r,'Action failed.')];
    }
}
