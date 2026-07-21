<?php
/**
 * providers/digitalocean/client.php
 *
 * DigitalOcean API v2 HTTP client.
 * Base URL : https://api.digitalocean.com/v2
 * Auth     : Bearer token (Personal Access Token)
 *
 * DO response format:
 *   Success: {"droplet":{...}} / {"droplets":[...]} / {"action":{...}}
 *   Error:   {"id":"...", "message":"..."}
 *   HTTP: 200 (GET), 201 (POST create), 204 (DELETE), 202 (action accepted)
 *
 * Pagination: {"meta":{"total":N}, "links":{"pages":{"next":"..."}}}
 */

declare(strict_types=1);

class DOClient
{
    private string $token;
    private string $base = 'https://api.digitalocean.com/v2';

    public function __construct(string $token)
    {
        $this->token = trim($token);
    }

    public function get(string $path, array $params = []): array
    {
        $url = $this->base . $path;
        if ($params) $url .= '?' . http_build_query($params);
        return $this->request('GET', $url);
    }

    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $this->base . $path, $body);
    }

    public function put(string $path, array $body = []): array
    {
        return $this->request('PUT', $this->base . $path, $body);
    }

    public function delete(string $path): array
    {
        return $this->request('DELETE', $this->base . $path);
    }

    private function request(string $method, string $url, array $body = []): array
    {
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
            ],
            CURLOPT_CUSTOMREQUEST  => $method,
        ];

        if (!empty($body)) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        } elseif ($method === 'POST') {
            $opts[CURLOPT_POSTFIELDS] = '{}';
        }

        $ch       = curl_init();
        curl_setopt_array($ch, $opts);
        $raw      = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) throw new RuntimeException('cURL error: ' . $curl_err);
        if ($code === 204)  return ['_http_status' => 204];

        $data = $raw ? json_decode($raw, true) : [];
        $data = is_array($data) ? $data : [];
        $data['_http_status'] = $code;
        return $data;
    }

    public static function isOk(array $r): bool
    {
        $code = $r['_http_status'] ?? 0;
        return $code >= 200 && $code < 300;
    }

    public static function errMsg(array $r, string $fallback = 'API error.'): string
    {
        return $r['message'] ?? $fallback;
    }
}
