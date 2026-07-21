<?php
/**
 * providers/proxmox/client.php
 *
 * Proxmox VE REST API client.
 *
 * Credentials JSON in providers.api_key:
 *   {
 *     "host"       : "https://proxmox.example.com:8006",
 *     "token_id"   : "root@pam!mytoken",
 *     "token_secret": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
 *     "node"       : "pve"          // default node name (optional, auto-detected if blank)
 *     "verify_ssl" : false          // optional, default false
 *   }
 *
 * Proxmox API uses token-based auth:
 *   Header: Authorization: PVEAPIToken=root@pam!mytoken=secret
 *
 * Base URL: https://host:8006/api2/json
 */
declare(strict_types=1);

class ProxmoxClient
{
    private string $host;
    private string $tokenId;
    private string $tokenSecret;
    private bool   $verifySsl;
    public  string $defaultNode;
    public  string $base;

    public function __construct(string|array $credJson)
    {
        // Accept a providers table row (array) or a legacy JSON string.
        // Proxmox keeps its 4-field credentials as JSON in the api_key column.
        if (is_array($credJson)) {
            $credJson = (string)($credJson['api_key'] ?? '');
        }
        $creds = json_decode($credJson, true);
        if (!is_array($creds)) {
            throw new RuntimeException('Proxmox api_key must be JSON: {"host":"https://IP:8006","token_id":"user@realm!name","token_secret":"secret","node":"pve"}');
        }

        $this->host        = rtrim($creds['host'] ?? '', '/');
        $this->tokenId     = $creds['token_id']     ?? '';
        $this->tokenSecret = $creds['token_secret'] ?? '';
        $this->defaultNode = $creds['node']          ?? '';
        $this->verifySsl   = (bool)($creds['verify_ssl'] ?? false);

        if (!$this->host || !$this->tokenId || !$this->tokenSecret) {
            throw new RuntimeException('Proxmox: host, token_id and token_secret are required.');
        }

        $this->base = $this->host . '/api2/json';
    }

    // ── GET /api2/json/{path} ────────────────────────────────

    public function get(string $path, array $params = []): array
    {
        $url = $this->base . '/' . ltrim($path, '/');
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        return $this->req('GET', $url);
    }

    // ── POST ─────────────────────────────────────────────────

    public function post(string $path, array $body = []): array
    {
        $url = $this->base . '/' . ltrim($path, '/');
        return $this->req('POST', $url, $body);
    }

    // ── PUT ──────────────────────────────────────────────────

    public function put(string $path, array $body = []): array
    {
        $url = $this->base . '/' . ltrim($path, '/');
        return $this->req('PUT', $url, $body);
    }

    // ── DELETE ───────────────────────────────────────────────

    public function delete(string $path): array
    {
        $url = $this->base . '/' . ltrim($path, '/');
        return $this->req('DELETE', $url);
    }

    // ── Detect default node if not set ───────────────────────

    public function resolveNode(): string
    {
        if ($this->defaultNode) return $this->defaultNode;

        try {
            $r = $this->get('nodes');
            $nodes = $r['data'] ?? [];
            if (!empty($nodes)) {
                $this->defaultNode = $nodes[0]['node'] ?? 'pve';
            }
        } catch (Throwable $e) {
            $this->defaultNode = 'pve';
        }

        return $this->defaultNode;
    }

    // ── Raw credentials ──────────────────────────────────────

    public function getHost(): string        { return $this->host; }
    public function getTokenId(): string     { return $this->tokenId; }
    public function getTokenSecret(): string { return $this->tokenSecret; }
    public function getDefaultNode(): string { return $this->defaultNode; }

    // ── Private HTTP ─────────────────────────────────────────

    private function req(string $method, string $url, array $body = []): array
    {
        $ch = curl_init();
        $headers = [
            'Authorization: PVEAPIToken=' . $this->tokenId . '=' . $this->tokenSecret,
            'Accept: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_USERAGENT      => 'GreatHostVPS/1.0',
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);

        if (in_array($method, ['POST', 'PUT']) && !empty($body)) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $err) {
            throw new RuntimeException("Proxmox cURL error: $err");
        }

        $d = json_decode((string)$raw, true);
        if (!is_array($d)) {
            throw new RuntimeException("Proxmox non-JSON response (HTTP $code): " . substr((string)$raw, 0, 300));
        }

        if ($code >= 400) {
            $msg = $d['errors'] ?? $d['message'] ?? "HTTP $code";
            if (is_array($msg)) $msg = implode(', ', $msg);
            throw new RuntimeException("Proxmox API error ($code): $msg");
        }

        $d['_http_status'] = $code;
        return $d;
    }
}
