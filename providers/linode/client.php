<?php
/**
 * providers/linode/client.php
 *
 * Linode API v4 HTTP client.
 * Base URL : https://api.linode.com/v4
 * Auth     : Bearer token (Personal Access Token or OAuth)
 *
 * Linode responses differ from Hetzner:
 *  - Errors come as: {"errors":[{"field":"...","reason":"..."}]}
 *  - Pagination: {"data":[...], "page":1, "pages":3, "results":25}
 *  - Success status codes: 200 (GET), 200 (POST), 200 (PUT), 204 (DELETE)
 */

declare(strict_types=1);

class LinodeClient
{
    private string $token;
    private string $base = 'https://api.linode.com/v4';

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

    // ── Generic raw request ───────────────────────────────────

    private function request(string $method, string $url, array $body = []): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];

        if (!empty($body)) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        } elseif ($method === 'POST' || $method === 'PUT') {
            $opts[CURLOPT_POSTFIELDS] = '{}';
        }

        $ch  = curl_init();
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('cURL error: ' . $curl_err);
        }

        // DELETE 204 = success, no body
        if ($code === 204) {
            return ['_http_status' => 204];
        }

        $data = $raw ? json_decode($raw, true) : [];
        $data = is_array($data) ? $data : [];
        $data['_http_status'] = $code;

        return $data;
    }

    // ── Normalize Linode errors to single message string ──────

    public static function errorMessage(array $response, string $fallback = 'API error.'): string
    {
        if (!empty($response['errors']) && is_array($response['errors'])) {
            $msgs = array_map(fn($e) => trim(($e['field'] ? $e['field'] . ': ' : '') . ($e['reason'] ?? '')), $response['errors']);
            return implode(' | ', $msgs);
        }
        return $response['error'] ?? $fallback;
    }
}
