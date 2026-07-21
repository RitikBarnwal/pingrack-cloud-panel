<?php
// includes/proxy_providers.php
// Unified provider API class for HydraProxy, IPRoyal, ProxyCheap

class ProxyProviderAPI
{
    private array  $provider;
    private string $slug;
    private string $apiKey;
    private string $apiSecret;
    private string $baseUrl;

    public function __construct(array $provider)
    {
        $this->provider  = $provider;
        $this->slug      = $provider['slug'];
        $this->apiKey    = $provider['api_key']    ?? '';
        $this->apiSecret = $provider['api_secret'] ?? '';
        $this->baseUrl   = rtrim($provider['api_base_url'] ?? '', '/');
    }

    // ── PUBLIC METHODS ─────────────────────────────────────────────

    /** Fetch account balance/info */
    public function getAccountInfo(): array
    {
        return match($this->slug) {
            'hydraproxy' => $this->hydra_GET('/get-account-info/'),
            'iproyal'    => $this->iproyal_GET('/balance'),
            'proxycheap' => $this->proxycheap_GET('/balance'),
            default      => ['status' => 'ERROR', 'message' => 'Manual provider has no API'],
        };
    }

    /** Fetch order details by provider order ID */
    public function getOrderDetails(string $order_id): array
    {
        return match($this->slug) {
            'hydraproxy' => $this->hydra_POST('/proxy-details/', ['order_id' => $order_id]),
            'iproyal'    => $this->iproyal_GET("/orders/{$order_id}"),
            'proxycheap' => $this->proxycheap_GET("/proxies/{$order_id}"),
            default      => ['status' => 'ERROR', 'message' => 'Manual: no API sync available'],
        };
    }

    /** Fetch usage history (bandwidth) */
    public function getUsageHistory(string $order_id): array
    {
        return match($this->slug) {
            'hydraproxy' => $this->hydra_POST('/view_usage_history/', ['order_id' => $order_id]),
            'iproyal'    => $this->iproyal_GET("/orders/{$order_id}/usage"),
            'proxycheap' => ['status' => 'ERROR', 'message' => 'Not supported by ProxyCheap'],
            default      => ['status' => 'ERROR', 'message' => 'Manual provider'],
        };
    }

    /** Update whitelist IP (mobile/static proxies) */
    public function updateWhitelistIp(string $order_id, string $ip): array
    {
        return match($this->slug) {
            'hydraproxy' => $this->hydra_POST('/update-whitelist-ip/', [
                'order_id'     => $order_id,
                'whitelist_ip' => $ip,
            ]),
            'iproyal'    => ['status' => 'ERROR', 'message' => 'Use IPRoyal dashboard for whitelist'],
            'proxycheap' => ['status' => 'ERROR', 'message' => 'Use ProxyCheap dashboard for whitelist'],
            default      => ['status' => 'ERROR', 'message' => 'Manual provider'],
        };
    }

    /** Parse API response into a normalised array for DB storage */
    public function normalise(array $raw): array
    {
        return match($this->slug) {
            'hydraproxy' => $this->normaliseHydra($raw),
            'iproyal'    => $this->normaliseIProyal($raw),
            'proxycheap' => $this->normaliseProxyCheap($raw),
            default      => [],
        };
    }

    // ── NORMALISE PER PROVIDER ─────────────────────────────────────

    private function normaliseHydra(array $r): array
    {
        if (($r['status'] ?? '') !== 'OK') {
            return ['error' => $r['message'] ?? 'Unknown error'];
        }
        $info       = $r['order_info']  ?? [];
        $proxy_info = $r['proxy_info']  ?? [];
        $proxy      = $r['proxy']       ?? [];

        $ports = $proxy['port'] ?? null;
        if (is_array($ports)) $ports = implode(',', $ports);

        return [
            'provider_status'    => $r['order_status']             ?? null,
            'username'           => $proxy_info['username']         ?? null,
            'password'           => $proxy_info['password']         ?? null,
            'gateway_host'       => $proxy['hostname']              ?? null,
            'gateway_port'       => $ports                          ?? null,
            'whitelist_ip'       => $proxy_info['whitelist_ip']     ?? null,
            'whitelist_unlock_at'=> $proxy_info['whitelist_ip_unlock_time'] ?? null,
            'location'           => $proxy_info['location']         ?? 'ANY',
            'expires_at'         => (!empty($info['date_end']))
                                        ? date('Y-m-d H:i:s', strtotime($info['date_end'])) : null,
            'bandwidth_avail_gb' => isset($proxy_info['bandwidth_available_gb'])
                                        ? (float)$proxy_info['bandwidth_available_gb'] : null,
            'bandwidth_used_gb'  => isset($proxy_info['bandwidth_start_gb'], $proxy_info['bandwidth_available_gb'])
                                        ? max(0, (float)$proxy_info['bandwidth_start_gb'] - (float)$proxy_info['bandwidth_available_gb'])
                                        : 0,
        ];
    }

    private function normaliseIProyal(array $r): array
    {
        // IPRoyal returns data directly or under 'data' key
        $d = $r['data'] ?? $r;
        if (empty($d['id'])) {
            return ['error' => $r['message'] ?? 'Unknown IPRoyal error'];
        }

        $proxy_data = $d['proxy_data'] ?? [];
        $proxies    = $proxy_data['proxies'] ?? [];
        $ports      = $proxy_data['ports']   ?? [];
        $port       = $ports['http|https']   ?? ($ports['socks5'] ?? null);

        // Build proxy list from proxies array
        $proxy_list = '';
        if (!empty($proxies)) {
            $proxy_list = implode("\n", array_map(function($p) {
                return is_array($p) ? ($p['hostname'].':'.$p['port']) : $p;
            }, $proxies));
        }

        return [
            'provider_status' => $d['status']      ?? null,
            'username'        => $d['username']     ?? null,
            'password'        => $d['password']     ?? null,
            'gateway_host'    => 'proxy.iproyal.com',
            'gateway_port'    => $port,
            'proxy_list'      => $proxy_list ?: null,
            'location'        => $d['location']     ?? 'ANY',
            'expires_at'      => !empty($d['expire_date'])
                                    ? date('Y-m-d H:i:s', strtotime($d['expire_date'])) : null,
            'bandwidth_avail_gb' => null,
            'bandwidth_used_gb'  => 0,
        ];
    }

    private function normaliseProxyCheap(array $r): array
    {
        if (!empty($r['error'])) {
            return ['error' => $r['error']];
        }
        $d = $r['data'] ?? $r;
        return [
            'provider_status' => $d['status']      ?? null,
            'username'        => $d['username']     ?? null,
            'password'        => $d['password']     ?? null,
            'gateway_host'    => $d['host']         ?? null,
            'gateway_port'    => $d['port']         ?? null,
            'proxy_list'      => $d['proxy_list']   ?? null,
            'location'        => $d['location']     ?? 'ANY',
            'expires_at'      => !empty($d['expires_at'])
                                    ? date('Y-m-d H:i:s', strtotime($d['expires_at'])) : null,
            'bandwidth_avail_gb' => isset($d['bandwidth_gb_left'])  ? (float)$d['bandwidth_gb_left']  : null,
            'bandwidth_used_gb'  => isset($d['bandwidth_gb_used'])  ? (float)$d['bandwidth_gb_used']  : 0,
        ];
    }

    // ── HTTP HELPERS ───────────────────────────────────────────────

    private function hydra_GET(string $path): array
    {
        return $this->request('GET', $this->baseUrl . $path, [], [
            'Accept: application/json',
            'Authorization: Token ' . $this->apiKey,
        ]);
    }

    private function hydra_POST(string $path, array $body): array
    {
        return $this->request('POST', $this->baseUrl . $path, $body, [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Token ' . $this->apiKey,
        ]);
    }

    private function iproyal_GET(string $path): array
    {
        return $this->request('GET', $this->baseUrl . $path, [], [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Access-Token: ' . $this->apiKey,
        ]);
    }

    private function proxycheap_GET(string $path): array
    {
        return $this->request('GET', $this->baseUrl . $path, [], [
            'Accept: application/json',
            'X-Api-Key: ' . $this->apiKey,
            'X-Api-Secret: ' . $this->apiSecret,
        ]);
    }

    private function request(string $method, string $url, array $body, array $headers): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'GreatHostVPS/1.0',
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return ['status' => 'ERROR', 'message' => 'cURL error: ' . $curlErr];
        }
        if ($httpCode === 401 || $httpCode === 403) {
            return ['status' => 'ERROR', 'message' => 'Unauthorised — check API key or whitelisted IPs'];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return ['status' => 'ERROR', 'message' => 'Invalid JSON response (HTTP '.$httpCode.')'];
        }
        return $decoded;
    }
}

/** Helper: load provider from DB */
function get_proxy_provider(int $id): ?ProxyProviderAPI
{
    $st = db()->prepare("SELECT * FROM proxy_providers WHERE id=? AND is_active=1");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ? new ProxyProviderAPI($row) : null;
}

/** Helper: sync one order, save to DB, return ['ok'=>bool, 'msg'=>str] */
function sync_proxy_order(array $order): array
{
    $provider = get_proxy_provider((int)$order['provider_id']);
    if (!$provider) {
        return ['ok' => false, 'msg' => 'Provider not found or inactive'];
    }
    if (empty($order['provider_order_id'])) {
        return ['ok' => false, 'msg' => 'No provider order ID set — admin must enter it first'];
    }

    $raw        = $provider->getOrderDetails((string)$order['provider_order_id']);
    $normalised = $provider->normalise($raw);

    if (isset($normalised['error'])) {
        // Log error
        db()->prepare(
            "INSERT INTO proxy_sync_log (order_id,provider,status,message) VALUES (?,?,?,?)"
        )->execute([$order['id'], $order['slug']??'', 'error', $normalised['error']]);

        db()->prepare(
            "UPDATE proxy_orders SET sync_error=?, last_synced_at=NOW() WHERE id=?"
        )->execute([substr($normalised['error'],0,480), $order['id']]);

        return ['ok' => false, 'msg' => $normalised['error']];
    }

    // Map provider status to our enum
    $provStatus = strtolower($normalised['provider_status'] ?? '');
    $ourStatus  = match(true) {
        str_contains($provStatus, 'active')  => 'active',
        str_contains($provStatus, 'expired') => 'expired',
        str_contains($provStatus, 'suspend') => 'suspended',
        default                              => $order['status'],
    };

    $activated_at = $order['activated_at'];
    if ($ourStatus === 'active' && !$activated_at) {
        $activated_at = date('Y-m-d H:i:s');
    }

    // Update order
    db()->prepare(
        "UPDATE proxy_orders SET
           provider_status    = ?,
           provider_raw       = ?,
           username           = COALESCE(?,username),
           password           = COALESCE(?,password),
           gateway_host       = COALESCE(?,gateway_host),
           gateway_port       = COALESCE(?,gateway_port),
           proxy_list         = COALESCE(?,proxy_list),
           whitelist_ip       = COALESCE(?,whitelist_ip),
           whitelist_unlock_at= COALESCE(?,whitelist_unlock_at),
           location           = COALESCE(?,location),
           bandwidth_used_gb  = COALESCE(?,bandwidth_used_gb),
           bandwidth_avail_gb = COALESCE(?,bandwidth_avail_gb),
           expires_at         = COALESCE(?,expires_at),
           status             = ?,
           activated_at       = COALESCE(?,activated_at),
           sync_error         = NULL,
           last_synced_at     = NOW()
         WHERE id = ?"
    )->execute([
        $normalised['provider_status']    ?? null,
        json_encode($raw),
        $normalised['username']           ?? null,
        $normalised['password']           ?? null,
        $normalised['gateway_host']       ?? null,
        $normalised['gateway_port']       ?? null,
        $normalised['proxy_list']         ?? null,
        $normalised['whitelist_ip']       ?? null,
        $normalised['whitelist_unlock_at'] ?? null,
        $normalised['location']           ?? null,
        $normalised['bandwidth_used_gb']  ?? null,
        $normalised['bandwidth_avail_gb'] ?? null,
        $normalised['expires_at']         ?? null,
        $ourStatus,
        $activated_at,
        $order['id'],
    ]);

    // Upsert credentials
    if (!empty($normalised['username']) && !empty($normalised['password'])) {
        db()->prepare(
            "INSERT INTO proxy_credentials (order_id,username,password_plain)
             VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE username=VALUES(username), password_plain=VALUES(password_plain), updated_at=NOW()"
        )->execute([$order['id'], $normalised['username'], $normalised['password']]);
    }

    // Sync usage history (HydraProxy residential)
    $usage_raw = $provider->getUsageHistory((string)$order['provider_order_id']);
    if (!empty($usage_raw['usage']) && is_array($usage_raw['usage'])) {
        $stmt = db()->prepare(
            "INSERT INTO proxy_usage_logs (order_id,log_date,upload_mb,download_mb,total_mb)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE upload_mb=VALUES(upload_mb),download_mb=VALUES(download_mb),total_mb=VALUES(total_mb)"
        );
        foreach ($usage_raw['usage'] as $u) {
            if (!empty($u['date'])) {
                $stmt->execute([
                    $order['id'],
                    $u['date'],
                    $u['bandwidth_upload_mb']   ?? 0,
                    $u['bandwidth_download_mb'] ?? 0,
                    $u['bandwidth_total_mb']    ?? 0,
                ]);
            }
        }
    }

    // Log success
    db()->prepare(
        "INSERT INTO proxy_sync_log (order_id,provider,status,message) VALUES (?,?,?,?)"
    )->execute([$order['id'], $order['provider_slug']??'', 'ok', 'Synced — '.$ourStatus]);

    return ['ok' => true, 'msg' => 'Synced — ' . $ourStatus, 'status' => $ourStatus, 'data' => $normalised];
}
