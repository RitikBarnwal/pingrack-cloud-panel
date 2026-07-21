<?php
/**
 * providers/hetzner/client.php
 * Hetzner API HTTP client.
 */
declare(strict_types=1);

class CloudProviderClient
{
    private string $api_key;
    private string $base = 'https://api.hetzner.cloud/v1';

    public function __construct(string $api_key)
    {
        $this->api_key = $api_key;
    }

    public function get(string $path, array $params = []): array
    {
        $url = $this->base . $path;
        if ($params) $url .= '?' . http_build_query($params);
        return $this->request('GET', $url);
    }

    public function post(string $path, array $body = []): array
    {
        $url = $this->base . $path;
        return $this->request('POST', $url, $body);
    }

    public function delete(string $path): array
    {
        $url = $this->base . $path;
        return $this->request('DELETE', $url);
    }

    private function request(string $method, string $url, array $body = []): array
    {
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->api_key,
                'Content-Type: application/json',
            ],
            CURLOPT_CUSTOMREQUEST  => $method,
        ];

        if (!empty($body)) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        } elseif ($method === 'POST') {
            $opts[CURLOPT_POSTFIELDS] = '{}';
        }

        $ch  = curl_init();
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) throw new RuntimeException('cURL error: ' . curl_error($ch));

        $data = $raw ? json_decode($raw, true) : [];
        $data = is_array($data) ? $data : [];
        $data['_http_status'] = $code;

        if ($code >= 400 && isset($data['error'])) {
            // Don't throw — let caller handle
        }

        return $data;
    }
}
