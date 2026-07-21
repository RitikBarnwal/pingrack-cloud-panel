<?php
/**
 * providers/virtualizor/client.php
 *
 * Virtualizor Master API client.
 *
 * Admin API ports:
 *   HTTPS : panel_url:4085/index.php
 *   HTTP  : panel_url:4084/index.php
 *
 * Credentials JSON in providers.api_key:
 *   {"panel_url":"https://IP_OR_DOMAIN","api_key":"KEY","api_pass":"PASS"}
 *   (no trailing slash, no port in panel_url)
 */
declare(strict_types=1);

class VirtualizorClient
{
    private string $panelUrl;
    private string $apiKey;
    private string $apiPass;
    public  string $base;
    public  string $enduser_base;

    public function __construct(string $credJson)
    {
        $creds = json_decode($credJson, true);
        if (!is_array($creds)) {
            throw new RuntimeException('Virtualizor api_key must be JSON: {"panel_url":"https://IP","api_key":"KEY","api_pass":"PASS"}');
        }
        $this->panelUrl = rtrim($creds['panel_url'] ?? '', '/');
        $this->apiKey   = $creds['api_key']  ?? '';
        $this->apiPass  = $creds['api_pass'] ?? '';

        if (!$this->panelUrl || !$this->apiKey || !$this->apiPass) {
            throw new RuntimeException('Virtualizor: panel_url, api_key and api_pass required.');
        }

        // Virtualizor has TWO separate API endpoints:
        //   Admin API  — port 4085 (HTTPS) / 4084 (HTTP) — for admin-level ops (listservers, addvs, etc.)
        //   Enduser API — port 4083 (HTTPS) / 4082 (HTTP) — for per-VPS enduser ops (ostemplate/rebuild, etc.)
        // We build both base URLs and pick the right one per call.
        $is_https = str_starts_with($this->panelUrl, 'https');
        $this->base          = $this->panelUrl . ':' . ($is_https ? 4083 : 4082) . '/index.php'; // Admin API
        $this->enduser_base  = $this->panelUrl . ':' . ($is_https ? 4083 : 4082) . '/index.php'; // Enduser API
    }

    // Acts that must go through the Enduser API (port 4083/4082)
    private const ENDUSER_ACTS = [
        'ostemplate',   // OS reinstall / rebuild
        'vpsmanage',    // VPS manage/details (enduser)
        'start', 'stop', 'restart', 'poweroff',  // power actions
        'changepassword',
        'snapshot',
        'rescue',
        'vnc',
        'volume',
        'firewall',
    ];

    private function baseFor(string $action): string
    {
        return in_array($action, self::ENDUSER_ACTS, true)
            ? $this->enduser_base
            : $this->base;
    }

    private function authQs(): string
    {
        return http_build_query(['api' => 'json', 'apikey' => $this->apiKey, 'apipass' => $this->apiPass]);
    }

    public function get(string $action, array $params = []): array
    {
        $params['act'] = $action;
        return $this->req('GET', $this->baseFor($action) . '?' . $this->authQs() . '&' . http_build_query($params));
    }

    // qs_extra = extra query string params (besides auth+act)
    // body     = POST body fields
    public function post(string $action, array $qs_extra = [], array $body = []): array
    {
        $qs_extra['act'] = $action;
        $url = $this->baseFor($action) . '?' . $this->authQs() . '&' . http_build_query($qs_extra);
        return $this->req('POST', $url, $body);
    }

    private function req(string $method, string $url, array $body = []): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0',
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        }
        $raw     = curl_exec($ch);
        $err     = curl_error($ch);
        $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $err) throw new RuntimeException("cURL: $err");
        if (str_starts_with(ltrim((string)$raw), '<')) {
            throw new RuntimeException("Got HTML instead of JSON — wrong port or bad credentials. Report to admin");
        }
        $d = json_decode((string)$raw, true);
        if (!is_array($d)) throw new RuntimeException("Non-JSON response: " . substr((string)$raw, 0, 300));
        $d['_http_status'] = $code;
        return $d;
    }

    public static function isOk(array $r): bool      { return ($r['done'] ?? 0) == 1; }
    public static function errMsg(array $r, string $fb = 'API error.'): string
    {
        $e = $r['error'] ?? null;
        if ($e) return is_array($e) ? implode(' | ', $e) : (string)$e;
        return $fb;
    }
    public function getPanelUrl(): string { return $this->panelUrl; }

    /** Return raw credentials for direct API calls (e.g. rebuild via enduser port) */
    public function getCredentials(): array
    {
        return ['apikey' => $this->apiKey, 'apipass' => $this->apiPass];
    }
}