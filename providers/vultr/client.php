<?php
/**
 * providers/vultr/client.php
 *
 * Vultr v2 REST API client.
 *
 * Auth: Bearer token in Authorization header.
 * Base: https://api.vultr.com/v2
 *
 * Docs: https://www.vultr.com/api/
 */
declare(strict_types=1);

class VultrClient
{
    private string $apiKey;
    private string $base = 'https://api.vultr.com/v2';

    public function __construct(string $apiKey)
    {
        if (!$apiKey) throw new RuntimeException('Vultr: API key is required.');
        $this->apiKey = $apiKey;
    }

    // ── GET ───────────────────────────────────────────────────

    public function get(string $path, array $params = []): array
    {
        $url = $this->base . $path;
        if ($params) $url .= '?' . http_build_query($params);
        return $this->request('GET', $url);
    }

    // ── POST ──────────────────────────────────────────────────

    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $this->base . $path, $body);
    }

    // ── PATCH ─────────────────────────────────────────────────

    public function patch(string $path, array $body = []): array
    {
        return $this->request('PATCH', $this->base . $path, $body);
    }

    // ── DELETE ────────────────────────────────────────────────

    public function delete(string $path): array
    {
        return $this->request('DELETE', $this->base . $path);
    }

    // ── Private HTTP ──────────────────────────────────────────

    private function request(string $method, string $url, array $body = []): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'GreatHostVPS/1.0',
        ]);

        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
        }

        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $err) {
            throw new RuntimeException("Vultr cURL error: $err");
        }

        // 204 No Content (DELETE success) → return empty ok
        if ($code === 204) return ['ok' => true];

        $d = json_decode((string)$raw, true);
        if (!is_array($d)) {
            throw new RuntimeException("Vultr non-JSON response (HTTP $code): " . substr((string)$raw, 0, 300));
        }

        if ($code >= 400) {
            $msg = $d['error'] ?? ($d['message'] ?? "HTTP $code");
            throw new RuntimeException("Vultr API error ($code): $msg");
        }

        $d['_http_status'] = $code;
        return $d;
    }
}
