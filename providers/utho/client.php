<?php
/**
 * providers/utho/client.php
 *
 * Utho Cloud API v2 HTTP client.
 * Base URL : https://console.utho.com/api/v2
 * Auth     : Authorization: Bearer {token}
 *            (Personal API Token from Utho console → API Tokens)
 *
 * Utho API response format:
 *   Success: {"status":"success", "message":"...", "data":[...]} or {"status":"success", ...}
 *   Error:   {"status":"error", "message":"...", "errors":{...}}
 *   HTTP 200 for most operations, 201 for creates
 */

declare(strict_types=1);

class UthoClient
{
    private string $token;
    private string $base = 'https://console.utho.com/api/v2';

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
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];

        if (!empty($body)) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        } elseif ($method === 'POST' || $method === 'PUT') {
            $opts[CURLOPT_POSTFIELDS] = '{}';
        }

        $ch       = curl_init();
        curl_setopt_array($ch, $opts);
        $raw      = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('cURL error: ' . $curl_err);
        }

        if ($code === 204) return ['_http_status' => 204, 'status' => 'success'];

        $data = $raw ? json_decode($raw, true) : [];
        $data = is_array($data) ? $data : [];
        $data['_http_status'] = $code;

        return $data;
    }

    /**
     * Check if response indicates success.
     * Utho returns status:"success" or HTTP 2xx.
     */
    public static function isOk(array $r): bool
    {
        $code = $r['_http_status'] ?? 0;
        $status = strtolower($r['status'] ?? '');
        return ($status === 'success') || ($code >= 200 && $code < 300 && $status !== 'error');
    }

    /**
     * Extract error message from Utho response.
     */
    public static function errMsg(array $r, string $fallback = 'API error.'): string
    {
        if (!empty($r['message']) && strtolower($r['status'] ?? '') === 'error') {
            return $r['message'];
        }
        if (!empty($r['errors'])) {
            if (is_array($r['errors'])) {
                return implode(' | ', array_map(fn($e) => is_string($e) ? $e : json_encode($e), $r['errors']));
            }
            return (string)$r['errors'];
        }
        return $r['message'] ?? $fallback;
    }
}
