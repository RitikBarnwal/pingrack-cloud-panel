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

    /**
     * Accepts EITHER:
     *   - a providers table row (array) using columns panel_url / api_key / api_pass
     *     (preferred — credentials stored in real MySQL columns), OR
     *   - a legacy JSON string {"panel_url":...,"api_key":...,"api_pass":...}
     *     kept in the api_key column (backward compatible).
     */
    public function __construct(string|array $creds)
    {
        // Row array from the providers table
        if (is_array($creds)) {
            $panel = $creds['panel_url'] ?? '';
            $key   = $creds['api_key']   ?? '';
            $pass  = $creds['api_pass']  ?? '';
            // If columns are empty but api_key still holds legacy JSON, parse it.
            if ((!$panel || !$pass) && is_string($creds['api_key'] ?? null)
                && str_starts_with(ltrim($creds['api_key']), '{')) {
                $j = json_decode($creds['api_key'], true);
                if (is_array($j)) {
                    $panel = $panel ?: ($j['panel_url'] ?? '');
                    $key   = $j['api_key']  ?? $key;
                    $pass  = $pass ?: ($j['api_pass'] ?? '');
                }
            }
        } else {
            // Legacy: a JSON string
            $j = json_decode($creds, true);
            if (!is_array($j)) {
                throw new RuntimeException('Virtualizor credentials missing. Enter Panel URL, API Key and API Pass.');
            }
            $panel = $j['panel_url'] ?? '';
            $key   = $j['api_key']  ?? '';
            $pass  = $j['api_pass'] ?? '';
        }

        // Normalise panel URL: add scheme if missing, strip trailing slash + any port.
        $panel = trim((string)$panel);
        if ($panel !== '' && !preg_match('#^https?://#i', $panel)) {
            $panel = 'https://' . $panel;
        }
        $panel = preg_replace('#:\d+$#', '', rtrim($panel, '/'));

        $this->panelUrl = $panel;
        $this->apiKey   = trim((string)$key);
        $this->apiPass  = trim((string)$pass);

        if (!$this->panelUrl || !$this->apiKey || !$this->apiPass) {
            throw new RuntimeException('Virtualizor: panel_url, api_key and api_pass are all required.');
        }

        // Virtualizor has TWO separate API endpoints:
        //   Admin API  — port 4085 (HTTPS) / 4084 (HTTP) — for admin-level ops (listservers, addvs, etc.)
        //   Enduser API — port 4083 (HTTPS) / 4082 (HTTP) — for per-VPS enduser ops (ostemplate/rebuild, etc.)
        // We build both base URLs and pick the right one per call.
        $is_https = str_starts_with($this->panelUrl, 'https');
        // Admin API   → 4085 (HTTPS) / 4084 (HTTP)   — plans, servers, vs, addvs …
        // Enduser API → 4083 (HTTPS) / 4082 (HTTP)   — ostemplate, power, rescue …
        $this->base          = $this->panelUrl . ':' . ($is_https ? 4085 : 4084) . '/index.php';
        $this->enduser_base  = $this->panelUrl . ':' . ($is_https ? 4083 : 4082) . '/index.php';
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
        // Virtualizor API auth (matches the official SDK): the raw key/pass are
        // NOT sent directly. Prepend an 8-char random string to the key, and
        // send apipass = md5(rand . pass). Sending them raw returns the HTML
        // login page.
        $rand    = self::randStr(8);
        $apikey  = $rand . $this->apiKey;
        $apipass = md5($rand . $this->apiPass);
        return http_build_query(['api' => 'json', 'apikey' => $apikey, 'apipass' => $apipass]);
    }

    private static function randStr(int $len): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $out = '';
        for ($i = 0; $i < $len; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
        return $out;
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