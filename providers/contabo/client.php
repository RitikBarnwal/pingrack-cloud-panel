<?php
/**
 * providers/contabo/client.php
 *
 * Contabo API v1 HTTP client.
 * Base URL : https://api.contabo.com/v1
 * Auth     : OAuth2 Client Credentials
 *            Token URL: https://auth.contabo.com/auth/realms/contabo/protocol/openid-connect/token
 *
 * Contabo requires 4 credentials (stored in api_key as JSON):
 *   client_id     — from Contabo API management page
 *   client_secret — from Contabo API management page
 *   api_user      — your Contabo account email
 *   api_password  — your Contabo account password
 *
 * Token is valid for 3600 seconds — cached per request.
 *
 * Contabo API response format:
 *   Success: {"data":[...], "_pagination":{...}}  (list)
 *             {"data":{...}}                       (single)
 *             {"traceId":"...", "data":{...}}      (action)
 *   Error:   {"traceId":"...", "type":"...", "message":"...", "errors":[...]}
 *   HTTP: 200 (GET), 201 (POST/create), 204 (DELETE), 400/401/404/409 (errors)
 */

declare(strict_types=1);

class ContaboClient
{
    private array  $creds;   // {client_id, client_secret, api_user, api_password}
    private string $base    = 'https://api.contabo.com/v1';
    private string $authUrl = 'https://auth.contabo.com/auth/realms/contabo/protocol/openid-connect/token';
    private ?string $token  = null;
    private int    $tokenExpiry = 0;

    public function __construct(string $apiKeyJson)
    {
        $decoded = json_decode($apiKeyJson, true);
        if (!is_array($decoded)) {
            // Legacy: treat as plain token (future-proof)
            $decoded = ['access_token' => $apiKeyJson];
        }
        $this->creds = $decoded;
    }

    // ── HTTP methods ──────────────────────────────────────────

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

    public function patch(string $path, array $body = []): array
    {
        return $this->request('PATCH', $this->base . $path, $body);
    }

    public function delete(string $path): array
    {
        return $this->request('DELETE', $this->base . $path);
    }

    // ── OAuth2 token ──────────────────────────────────────────

    private function getToken(): string
    {
        // Already cached and valid
        if ($this->token && time() < $this->tokenExpiry - 60) {
            return $this->token;
        }

        // If we have a direct access_token (testing mode)
        if (!empty($this->creds['access_token']) && empty($this->creds['client_id'])) {
            $this->token      = $this->creds['access_token'];
            $this->tokenExpiry = time() + 3600;
            return $this->token;
        }

        $required = ['client_id', 'client_secret', 'api_user', 'api_password'];
        foreach ($required as $k) {
            if (empty($this->creds[$k])) {
                throw new RuntimeException("Contabo credential '{$k}' missing. Store JSON with client_id, client_secret, api_user, api_password.");
            }
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->authUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => http_build_query([
                'client_id'     => $this->creds['client_id'],
                'client_secret' => $this->creds['client_secret'],
                'username'      => $this->creds['api_user'],
                'password'      => $this->creds['api_password'],
                'grant_type'    => 'password',
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) throw new RuntimeException('cURL auth error: ' . $err);

        $data = json_decode($raw, true);
        if (empty($data['access_token'])) {
            $msg = $data['error_description'] ?? $data['error'] ?? 'OAuth2 token request failed.';
            throw new RuntimeException('Contabo auth failed: ' . $msg);
        }

        $this->token       = $data['access_token'];
        $this->tokenExpiry = time() + (int)($data['expires_in'] ?? 3600);
        return $this->token;
    }

    // ── Core request ──────────────────────────────────────────

    private function request(string $method, string $url, array $body = []): array
    {
        $token = $this->getToken();

        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
            'x-request-id: ' . $this->requestId(),
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
        } elseif (in_array($method, ['POST', 'PUT', 'PATCH'])) {
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

    // ── Helpers ───────────────────────────────────────────────

    private function requestId(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    public static function isOk(array $r): bool
    {
        $code = $r['_http_status'] ?? 0;
        return $code >= 200 && $code < 300;
    }

    public static function errMsg(array $r, string $fallback = 'API error.'): string
    {
        if (!empty($r['message'])) return $r['message'];
        if (!empty($r['errors']) && is_array($r['errors'])) {
            return implode(' | ', array_map(fn($e) => $e['message'] ?? json_encode($e), $r['errors']));
        }
        return $fallback;
    }

    /**
     * Build the api_key JSON string for storing in DB.
     * Helper for admin when adding Contabo provider.
     */
    public static function buildCredentials(
        string $client_id,
        string $client_secret,
        string $api_user,
        string $api_password
    ): string {
        return json_encode(compact('client_id', 'client_secret', 'api_user', 'api_password'));
    }
}
