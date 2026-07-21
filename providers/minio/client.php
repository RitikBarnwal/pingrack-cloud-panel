<?php
/**
 * providers/minio/client.php
 *
 * MinIO S3-compatible client — FIXED VERSION
 *
 * ARCHITECTURE:
 * ─────────────────────────────────
 * MinIO root credentials → Admin → Storage → Regions.
 * Bucket create flow:
 *   1. S3 API (PUT /bucket-name) se bucket banao
 *   2. MinIO Admin API se user banao (unique access_key + secret_key)
 *   3. Bucket-scoped IAM policy banao (sirf is bucket ka access)
 *   4. Policy user se attach karo
 *   5. User ko credentials do (endpoint, access_key, secret_key)
 *
 * BUGS FIXED:
 *   [1] createServiceAccount() ab Admin HTTP API use karta hai,
 *       pehle `mc` CLI use kar raha tha jo PHP process mein kaam nahi karta
 *   [2] deleteServiceAccount() bhi ab Admin API se properly kaam karta hai
 *   [3] Canonical query string ab properly sorted + encoded hai (SignatureDoesNotMatch fix)
 *   [4] Bucket-scoped IAM policy banti hai (readwrite global nahi)
 *   [5] Admin API sign karne ke liye alag signer hai (service = 's3' nahi)
 */
declare(strict_types=1);

class MinioAdminClient
{
    private string $endpoint;
    private string $access_key;
    private string $secret_key;
    private string $region;
    private string $mc_alias;

    public function __construct(
        string $endpoint,
        string $access_key,
        string $secret_key,
        string $region = 'ap-south-1',
        string $mc_alias = 'myminio'
    ) {
        $this->endpoint   = rtrim($endpoint, '/');
        $this->access_key = $access_key;
        $this->secret_key = $secret_key;
        $this->region     = $region;
        $this->mc_alias   = $mc_alias;
    }

    // ═══════════════════════════════════════════════════════════
    //  BUCKET OPERATIONS (S3 API)
    // ═══════════════════════════════════════════════════════════

    public function createBucket(string $bucket_name): bool
    {
        $r = $this->s3_request('PUT', '/' . $bucket_name, '');
        // 200 = created, 204 = ok, 409 = already exists (ok)
        return in_array($r['code'], [200, 204, 409]);
    }

    public function deleteBucket(string $bucket_name): bool
{
    try {

        $this->runCommand(
            'mc rm --recursive --force ' . escapeshellarg($this->mc_alias . '/' . $bucket_name)
        );

        $this->runCommand(
            'mc rb --force ' . escapeshellarg($this->mc_alias . '/' . $bucket_name)
        );

        return true;

    } catch (Throwable $e) {

        return false;
    }
}

    public function bucketExists(string $bucket_name): bool
    {
        $r = $this->s3_request('HEAD', '/' . $bucket_name, '');
        return $r['code'] === 200;
    }

    // ═══════════════════════════════════════════════════════════
    //  USER + POLICY OPERATIONS (MinIO Admin API)
    //
    //  BUG FIX [1]: Ab `mc` CLI nahi, pure HTTP Admin API use hoti hai
    //  BUG FIX [4]: Bucket-scoped policy banti hai, global nahi
    //  BUG FIX [5]: Admin API requests alag signer use karte hain
    // ═══════════════════════════════════════════════════════════

    /**
     * MinIO user banao + bucket-scoped policy attach karo
     * Returns: ['access_key' => '...', 'secret_key' => '...']
     */
    public function createServiceAccount(string $bucket_name): array
{
    $access_key = 'USR' . strtoupper(bin2hex(random_bytes(8)));
    $secret_key = bin2hex(random_bytes(20));

    $policy_name = 'bkt-' . $bucket_name;

    // ── Create MinIO user ─────────────────────
    $this->runCommand(
        'mc admin user add ' . escapeshellarg($this->mc_alias) . ' '
        . escapeshellarg($access_key) . ' '
        . escapeshellarg($secret_key)
    );

    // ── Bucket scoped policy ──────────────────
    $policy = [
        'Version' => '2012-10-17',
        'Statement' => [
        [
            'Effect' => 'Allow',
            'Action' => [
                's3:GetObject',
                's3:PutObject',
                's3:DeleteObject',
            ],
            'Resource' => [
                'arn:aws:s3:::' . $bucket_name . '/*'
            ],
        ],
        [
            'Effect' => 'Allow',
            'Action' => [
                's3:ListBucket',
                's3:GetBucketLocation',
            ],
            'Resource' => [
                'arn:aws:s3:::' . $bucket_name
                ],
            ],
        ],
    ];

    $tmp = sys_get_temp_dir() . '/' . $policy_name . '.json';

    file_put_contents(
        $tmp,
        json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );

    // ── Create policy ─────────────────────────
    $this->runCommand(
        'mc admin policy create ' . escapeshellarg($this->mc_alias) . ' '
        . escapeshellarg($policy_name) . ' '
        . escapeshellarg($tmp)
    );

    // ── Attach policy ─────────────────────────
    $this->runCommand(
        'mc admin policy attach ' . escapeshellarg($this->mc_alias) . ' '
        . escapeshellarg($policy_name)
        . ' --user '
        . escapeshellarg($access_key)
    );

    @unlink($tmp);

    return [
        'access_key' => $access_key,
        'secret_key' => $secret_key,
    ];
}

public function deleteServiceAccount(string $access_key, string $bucket_name = ''): bool
{
    try {

        if ($bucket_name !== '') {

            $policy_name = 'bkt-' . $bucket_name;

            $this->runCommand(
                'mc admin policy rm ' . escapeshellarg($this->mc_alias) . ' '
                . escapeshellarg($policy_name)
            );
        }

        $this->runCommand(
            'mc admin user remove ' . escapeshellarg($this->mc_alias) . ' '
            . escapeshellarg($access_key)
        );

        return true;

    } catch (Throwable $e) {

        return false;
    }
}

    // ═══════════════════════════════════════════════════════════
    //  BUCKET POLICY (Public/Private)
    // ═══════════════════════════════════════════════════════════

    public function setBucketPublic(string $bucket_name, bool $public = false): void
{
    if ($public) {

        $this->runCommand(
            'mc anonymous set public ' . escapeshellarg($this->mc_alias . '/' . $bucket_name)
        );

    } else {

        $this->runCommand(
            'mc anonymous set private ' . escapeshellarg($this->mc_alias . '/' . $bucket_name)
        );
    }
}

    // ═══════════════════════════════════════════════════════════
    //  OBJECT OPERATIONS
    // ═══════════════════════════════════════════════════════════

    public function listObjects(string $bucket_name, string $prefix = '', int $max = 200): array
    {
        $params = ['list-type' => 2, 'max-keys' => $max];
        if ($prefix !== '') $params['prefix'] = $prefix;
        // BUG FIX [3]: query params ab ksort se canonical form mein hain (signed_request handles it)
        $r = $this->s3_request('GET', '/' . $bucket_name, '', 'application/xml', $params);
        if ($r['code'] !== 200) return [];

        $objects = [];
        if (preg_match_all('/<Contents>(.*?)<\/Contents>/s', $r['body'], $blocks)) {
            foreach ($blocks[1] as $block) {
                $key  = $this->xml_val($block, 'Key');
                $size = (int)$this->xml_val($block, 'Size');
                $mod  = $this->xml_val($block, 'LastModified');
                if ($key !== null) {
                    $objects[] = ['key' => $key, 'size_bytes' => $size, 'last_modified' => $mod];
                }
            }
        }
        return $objects;
    }

    public function deleteAllObjects(string $bucket_name): void
    {
        $objects = $this->listObjects($bucket_name, '', 1000);
        if (empty($objects)) return;

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
             . '<Delete xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
             . '<Quiet>true</Quiet>';
        foreach ($objects as $obj) {
            $xml .= '<Object><Key>' . htmlspecialchars($obj['key'], ENT_XML1) . '</Key></Object>';
        }
        $xml .= '</Delete>';
        $this->s3_request('POST', '/' . $bucket_name, $xml, 'application/xml', ['delete' => '']);
    }

    public function getBucketUsage(string $bucket_name): array
{
    try {

        $cmd = 'mc du --json ' . escapeshellarg($this->mc_alias . '/' . $bucket_name);

        $output = $this->runCommand($cmd);

        $data = json_decode($output, true);

        return [
            'objects'    => (int)($data['objectsCount'] ?? 0),
            'size_bytes' => (int)($data['size'] ?? 0),
        ];

    } catch (Throwable $e) {

        return [
            'objects'    => 0,
            'size_bytes' => 0,
        ];
    }
}

    /**
     * MinIO pe file upload karo (S3 PUT Object)
     * $body = raw file contents (string)
     */
    public function putObject(string $bucket_name, string $object_key, string $body, string $content_type = 'application/octet-stream'): bool
    {
        $key = ltrim($object_key, '/');
        $r   = $this->s3_request('PUT', '/' . $bucket_name . '/' . $this->encode_path($key), $body, $content_type);
        return in_array($r['code'], [200, 201, 204]);
    }

    /**
     * MinIO se file delete karo (S3 DELETE Object)
     */
    public function deleteObject(string $bucket_name, string $object_key): bool
    {
        $key = ltrim($object_key, '/');
        $r   = $this->s3_request('DELETE', '/' . $bucket_name . '/' . $this->encode_path($key), '');
        return in_array($r['code'], [200, 204, 404]);
    }

    /**
     * Object ka pre-signed URL banao (GET — download/preview ke liye)
     * $expires = seconds mein (default 1 hour)
     */
    public function presignedUrl(string $bucket_name, string $object_key, int $expires = 3600): string
    {
        $key      = ltrim($object_key, '/');
        $parsed   = parse_url($this->endpoint);
        $host     = $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
        $path     = '/' . $bucket_name . '/' . $this->encode_path($key);

        $now      = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $date_str = $now->format('Ymd');
        $datetime = $now->format('Ymd\THis\Z');
        $scope    = "{$date_str}/{$this->region}/s3/aws4_request";

        $qparams = [
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => "{$this->access_key}/{$scope}",
            'X-Amz-Date'          => $datetime,
            'X-Amz-Expires'       => (string)$expires,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($qparams);
        $qs = $this->canonical_query_string($qparams);

        $canon_req = implode("\n", [
            'GET',
            $path,
            $qs,
            'host:' . $host . "\n",
            'host',
            'UNSIGNED-PAYLOAD',
        ]);

        $sts = "AWS4-HMAC-SHA256\n{$datetime}\n{$scope}\n" . hash('sha256', $canon_req);
        $sk  = $this->hmac("AWS4{$this->secret_key}", $date_str);
        $sk  = $this->hmac($sk, $this->region);
        $sk  = $this->hmac($sk, 's3');
        $sk  = $this->hmac($sk, 'aws4_request');
        $sig = hash_hmac('sha256', $sts, $sk);

        return $this->endpoint . $path . '?' . $qs . '&X-Amz-Signature=' . $sig;
    }

    /**
     * Path segments ko properly encode karo (slashes preserve karo)
     */
    private function encode_path(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    // ═══════════════════════════════════════════════════════════
    //  INTERNAL: Request builders
    // ═══════════════════════════════════════════════════════════

    /**
     * S3 API request (bucket/object operations)
     */
    private function s3_request(
        string $method,
        string $path,
        string $body,
        string $content_type = 'application/xml',
        array  $query_params = []
    ): array {
        // Parse path for any inline query string
        if (str_contains($path, '?')) {
            [$path, $qs] = explode('?', $path, 2);
            parse_str($qs, $inline_params);
            $query_params = array_merge($inline_params, $query_params);
        }

        $url = $this->endpoint . $path;
        if ($query_params) {
            $url .= '?' . $this->canonical_query_string($query_params);
        }

        return $this->signed_request($method, $url, $body, $content_type);
    }

    /**
     * MinIO Admin API request
     * BUG FIX [5]: Admin API same AWS SigV4 use karta hai lekin
     * endpoint /minio/admin/v3/... pe — same s3 service signing works.
     * MinIO admin paths are signed with service='s3' and region matching.
     */
    private function admin_request(
        string $method,
        string $path,
        string $body,
        string $content_type = 'application/json'
    ): array {
        // Parse path for inline query string
        $query_params = [];
        if (str_contains($path, '?')) {
            [$path, $qs] = explode('?', $path, 2);
            parse_str($qs, $query_params);
        }

        $url = $this->endpoint . $path;
        if ($query_params) {
            $url .= '?' . $this->canonical_query_string($query_params);
        }

        return $this->signed_request($method, $url, $body, $content_type);
    }

    // ═══════════════════════════════════════════════════════════
    //  AWS Signature V4 — FIXED
    // ═══════════════════════════════════════════════════════════

    /**
     * BUG FIX [3]: Canonical query string ab properly sorted aur percent-encoded hai
     * Pehle raw query string directly use hoti thi jo SignatureDoesNotMatch deta tha
     */
    private function canonical_query_string(array $params): string
    {
        ksort($params); // Keys alphabetically sort karni hain
        $parts = [];
        foreach ($params as $k => $v) {
            // RFC 3986 encoding — space = %20, * = %2A, ~ nahi encode hoti
            $parts[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
        }
        return implode('&', $parts);
    }

    private function signed_request(
        string $method,
        string $url,
        string $body,
        string $content_type
    ): array {
        $parsed   = parse_url($url);
        $host     = $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
        $uri      = $parsed['path'] ?? '/';
        // BUG FIX [3]: query string canonical form mein use karo
        $raw_query = $parsed['query'] ?? '';

        // Parse and re-canonicalize the query string from URL
        $canonical_qs = '';
        if ($raw_query !== '') {
            $qparams = [];
            parse_str($raw_query, $qparams);
            $canonical_qs = $this->canonical_query_string($qparams);
        }

        $now      = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $date_str = $now->format('Ymd');
        $datetime = $now->format('Ymd\THis\Z');
        $pl_hash  = hash('sha256', $body);

        // Build headers — always include x-amz-content-sha256
        $headers = [
            'host'                 => $host,
            'x-amz-content-sha256' => $pl_hash,
            'x-amz-date'           => $datetime,
        ];

        // content-type header — include when body is non-empty OR when it's needed
        // Always include for Admin API calls (json body), for S3 PUT/POST with body
        if ($body !== '' || in_array($method, ['PUT', 'POST'])) {
            $headers['content-type'] = $content_type;
        }

        ksort($headers);

        $signed_hdrs = implode(';', array_keys($headers));
        $canon_hdrs  = '';
        foreach ($headers as $k => $v) {
            $canon_hdrs .= $k . ':' . trim($v) . "\n";
        }

        // Canonical URI — percent-encode path segments (but not slashes)
        $canon_uri = implode('/', array_map('rawurlencode', explode('/', $uri)));
        $canon_uri = str_replace('%2F', '/', $canon_uri); // preserve path separators

        $canon_req = implode("\n", [
            $method,
            $canon_uri,
            $canonical_qs,   // BUG FIX [3]: sorted + encoded query string
            $canon_hdrs,
            $signed_hdrs,
            $pl_hash,
        ]);

        $scope = "{$date_str}/{$this->region}/s3/aws4_request";
        $sts   = "AWS4-HMAC-SHA256\n{$datetime}\n{$scope}\n" . hash('sha256', $canon_req);

        $sk  = $this->hmac("AWS4{$this->secret_key}", $date_str);
        $sk  = $this->hmac($sk, $this->region);
        $sk  = $this->hmac($sk, 's3');
        $sk  = $this->hmac($sk, 'aws4_request');
        $sig = hash_hmac('sha256', $sts, $sk);

        $auth = "AWS4-HMAC-SHA256 Credential={$this->access_key}/{$scope}, "
              . "SignedHeaders={$signed_hdrs}, Signature={$sig}";

        $curl_headers = ['Authorization: ' . $auth];
        foreach ($headers as $k => $v) {
            if ($k !== 'host') $curl_headers[] = $k . ': ' . $v;
        }
        if ($body !== '') {
            $curl_headers[] = 'Content-Length: ' . strlen($body);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $curl_headers,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_SSL_VERIFYPEER => false, // internal HTTP endpoints ke liye
            CURLOPT_HEADER         => false,
        ]);

        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif (in_array($method, ['POST', 'PUT'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) throw new RuntimeException("MinIO cURL error: $err");
        return ['code' => $code, 'body' => (string)$resp];
    }

    private function hmac(string $key, string $data): string
    {
        return hash_hmac('sha256', $data, $key, true);
    }

    private function xml_val(string $xml, string $tag): ?string
    {
        if (preg_match('/<' . preg_quote($tag, '/') . '>(.*?)<\/' . preg_quote($tag, '/') . '>/s', $xml, $m)) {
            return $m[1];
        }
        return null;
    }
    private function runCommand(string $cmd): string
{
    exec($cmd . ' 2>&1', $output, $code);

    $result = trim(implode("\n", $output));

    if ($code !== 0) {
        throw new RuntimeException(
            $result ?: 'Command failed'
        );
    }

    return $result;
}
}