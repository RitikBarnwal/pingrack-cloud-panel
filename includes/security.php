<?php
/**
 * GreatHost VPS — Enterprise Security Layer v1.0
 * Covers: XSS sanitization, CSRF, brute-force / rate limiting,
 *         secure session, CORS domain whitelist, secure headers,
 *         API abuse protection, attack logging.
 *
 * Include ONCE at the top of bootstrap.php (after db.php):
 *   require_once __DIR__ . '/security.php';
 */

// ═══════════════════════════════════════════════════════════════
// 1. SECURE HTTP HEADERS
// ═══════════════════════════════════════════════════════════════

function security_send_headers(): void {
    if (headers_sent()) return;

    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: geolocation=(self), camera=(self), microphone=(self)");
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
    header("X-Powered-By: GreatHost");
}

// ═══════════════════════════════════════════════════════════════
// 2. INPUT SANITIZATION  (XSS prevention)
// ═══════════════════════════════════════════════════════════════

/**
 * Sanitize a scalar value — strips tags, encodes HTML entities.
 * Use for any user-supplied text displayed in HTML.
 */
function sec_clean(mixed $val, int $flags = ENT_QUOTES | ENT_HTML5): string {
    if (is_array($val) || is_object($val)) return '';
    return htmlspecialchars(strip_tags((string)$val), $flags, 'UTF-8');
}

/**
 * Recursively sanitize an entire array (GET/POST/COOKIE).
 */
function sec_clean_array(array $arr): array {
    $out = [];
    foreach ($arr as $k => $v) {
        $k = sec_clean($k);
        $out[$k] = is_array($v) ? sec_clean_array($v) : sec_clean($v);
    }
    return $out;
}

/**
 * Safe integer — returns 0 if non-numeric.
 */
function sec_int(mixed $val): int {
    return filter_var($val, FILTER_VALIDATE_INT) !== false ? (int)$val : 0;
}

/**
 * Safe email — returns '' if invalid.
 */
function sec_email(mixed $val): string {
    $v = filter_var(trim((string)$val), FILTER_VALIDATE_EMAIL);
    return $v !== false ? $v : '';
}

/**
 * Safe URL — returns '' if invalid.
 */
function sec_url(mixed $val): string {
    $v = filter_var(trim((string)$val), FILTER_VALIDATE_URL);
    return $v !== false ? $v : '';
}

/**
 * Strip all HTML (for plain-text storage like names, usernames).
 */
function sec_text(mixed $val): string {
    return strip_tags(trim((string)$val));
}

// ═══════════════════════════════════════════════════════════════
// 3. BRUTE-FORCE & RATE LIMITING
//    Tables needed — see security_install_tables() at bottom.
// ═══════════════════════════════════════════════════════════════

define('SEC_LOGIN_MAX_ATTEMPTS',  10);   // per IP per window
define('SEC_LOGIN_WINDOW',        900);  // 15 min window
define('SEC_LOGIN_BAN_DURATION',  1800); // 30 min ban
define('SEC_API_MAX_RPM',         120);  // requests per minute per IP
define('SEC_API_MAX_RPM_TOKEN',   300);  // per API token per minute
define('SEC_API_BAN_DURATION',    300);  // 5 min API ban

/**
 * Cloudflare edge IP ranges. Proxy/client headers (CF-Connecting-IP,
 * X-Forwarded-For, …) are ONLY trusted when the TCP peer (REMOTE_ADDR)
 * is one of these — otherwise any client could spoof its IP and bypass
 * brute-force bans, rate limits, and poison logs.
 * Source: https://www.cloudflare.com/ips/  (update on CF range changes)
 */
const SEC_CF_RANGES = [
    // IPv4
    '173.245.48.0/20','103.21.244.0/22','103.22.200.0/22','103.31.4.0/22',
    '141.101.64.0/18','108.162.192.0/18','190.93.240.0/20','188.114.96.0/20',
    '197.234.240.0/22','198.41.128.0/17','162.158.0.0/15','104.16.0.0/13',
    '104.24.0.0/14','172.64.0.0/13','131.0.72.0/22',
    // IPv6
    '2400:cb00::/32','2606:4700::/32','2803:f800::/32','2405:b500::/32',
    '2405:8100::/32','2a06:98c0::/29','2c0f:f248::/32',
];

/**
 * Is $ip within CIDR $cidr? (IPv4 + IPv6)
 */
function sec_ip_in_cidr(string $ip, string $cidr): bool {
    if (strpos($cidr, '/') === false) return false;
    [$subnet, $bits] = explode('/', $cidr, 2);
    $bits = (int)$bits;
    $ip_bin     = @inet_pton($ip);
    $subnet_bin = @inet_pton($subnet);
    if ($ip_bin === false || $subnet_bin === false) return false;
    if (strlen($ip_bin) !== strlen($subnet_bin)) return false; // v4 vs v6 mismatch

    $bytes = intdiv($bits, 8);
    $rem   = $bits % 8;
    if ($bytes > 0 && strncmp($ip_bin, $subnet_bin, $bytes) !== 0) return false;
    if ($rem === 0) return true;
    $mask = ~((1 << (8 - $rem)) - 1) & 0xFF;
    return (ord($ip_bin[$bytes]) & $mask) === (ord($subnet_bin[$bytes]) & $mask);
}

/**
 * True when the direct TCP peer is a trusted Cloudflare edge.
 */
function sec_remote_is_cloudflare(): bool {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!filter_var($remote, FILTER_VALIDATE_IP)) return false;
    foreach (SEC_CF_RANGES as $cidr) {
        if (sec_ip_in_cidr($remote, $cidr)) return true;
    }
    return false;
}

/**
 * Get real client IP (Cloudflare-aware, spoof-resistant).
 * Proxy headers are honoured only when the request actually arrived
 * through Cloudflare; otherwise the TCP peer (REMOTE_ADDR) is authoritative.
 */
function sec_get_ip(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    if (sec_remote_is_cloudflare()) {
        // Trust CF-Connecting-IP first (single, unspoofable-behind-CF value).
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
    }

    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '127.0.0.1';
}

/**
 * Device fingerprint (IP + UA hash).
 */
function sec_device_id(): string {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return hash('sha256', sec_get_ip() . '|' . $ua);
}

// ─── Login brute-force ────────────────────────────────────────

/**
 * Check if IP/device is currently banned.
 * Returns ['banned'=>true,'remaining'=>int] or ['banned'=>false].
 */
function sec_check_login_ban(): array {
    $ip  = sec_get_ip();
    $dev = sec_device_id();
    $now = time();
    try {
        $st = db()->prepare("
            SELECT ban_until FROM sec_login_attempts
            WHERE (ip=? OR device_id=?) AND ban_until IS NOT NULL AND ban_until > ?
            ORDER BY ban_until DESC LIMIT 1
        ");
        $st->execute([$ip, $dev, $now]);
        $row = $st->fetch();
        if ($row) {
            return ['banned' => true, 'remaining' => (int)$row['ban_until'] - $now];
        }
    } catch (Throwable $e) {
        error_log('[security] check_login_ban: ' . $e->getMessage());
    }
    return ['banned' => false];
}

/**
 * Record a failed login attempt; impose ban if threshold crossed.
 * Returns ban info if newly banned.
 */
function sec_record_login_fail(string $username = ''): array {
    $ip  = sec_get_ip();
    $dev = sec_device_id();
    $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $now = time();
    $window_start = $now - SEC_LOGIN_WINDOW;

    try {
        // Insert attempt
        db()->prepare("
            INSERT INTO sec_login_attempts (ip, device_id, username, user_agent, attempted_at)
            VALUES (?,?,?,?,NOW())
        ")->execute([$ip, $dev, $username, $ua]);

        // Count recent attempts
        $st = db()->prepare("
            SELECT COUNT(*) FROM sec_login_attempts
            WHERE (ip=? OR device_id=?) AND attempted_at > FROM_UNIXTIME(?) AND ban_until IS NULL
        ");
        $st->execute([$ip, $dev, $window_start]);
        $count = (int)$st->fetchColumn();

        if ($count >= SEC_LOGIN_MAX_ATTEMPTS) {
            $ban_until = $now + SEC_LOGIN_BAN_DURATION;
            db()->prepare("
                UPDATE sec_login_attempts SET ban_until=FROM_UNIXTIME(?)
                WHERE (ip=? OR device_id=?) AND ban_until IS NULL
            ")->execute([$ban_until, $ip, $dev]);

            // Log attack
            sec_log_event('brute_force_login', [
                'ip' => $ip, 'device_id' => $dev,
                'username' => $username, 'attempts' => $count,
                'ban_until' => date('Y-m-d H:i:s', $ban_until),
            ], 'critical');

            return ['banned' => true, 'remaining' => SEC_LOGIN_BAN_DURATION, 'attempts' => $count];
        }
        return ['banned' => false, 'attempts' => $count];
    } catch (Throwable $e) {
        error_log('[security] record_login_fail: ' . $e->getMessage());
        return ['banned' => false, 'attempts' => 0];
    }
}

/**
 * Clear login attempts on successful login.
 */
function sec_clear_login_attempts(string $username = ''): void {
    $ip  = sec_get_ip();
    $dev = sec_device_id();
    try {
        db()->prepare("
            DELETE FROM sec_login_attempts WHERE ip=? OR device_id=? OR username=?
        ")->execute([$ip, $dev, $username]);
    } catch (Throwable $e) {
        error_log('[security] clear_login_attempts: ' . $e->getMessage());
    }
}

// ─── API rate limiting ────────────────────────────────────────

/**
 * Check and record API rate limit.
 * $key: 'ip', 'user:ID', or 'token:TOKEN_HASH'
 * Returns ['allowed'=>bool, 'remaining'=>int, 'reset_in'=>int]
 */
function sec_api_rate_limit(string $key, int $max_per_min = SEC_API_MAX_RPM): array {
    $now    = time();
    $window = 60; // 1 minute sliding window

    try {
        // Cleanup old entries
        db()->prepare("DELETE FROM sec_rate_limit WHERE window_start < FROM_UNIXTIME(?)")
            ->execute([$now - $window * 2]);

        $st = db()->prepare("
            SELECT id, hits, window_start FROM sec_rate_limit
            WHERE rate_key=? LIMIT 1
        ");
        $st->execute([$key]);
        $row = $st->fetch();

        if (!$row) {
            db()->prepare("INSERT INTO sec_rate_limit (rate_key, hits, window_start) VALUES (?,1,NOW())")
                ->execute([$key]);
            return ['allowed' => true, 'remaining' => $max_per_min - 1, 'reset_in' => $window];
        }

        $window_age = $now - strtotime($row['window_start']);
        if ($window_age > $window) {
            // New window
            db()->prepare("UPDATE sec_rate_limit SET hits=1, window_start=NOW() WHERE id=?")
                ->execute([$row['id']]);
            return ['allowed' => true, 'remaining' => $max_per_min - 1, 'reset_in' => $window];
        }

        if ($row['hits'] >= $max_per_min) {
            $reset_in = $window - $window_age;
            // Log if just crossed threshold
            if ($row['hits'] == $max_per_min) {
                sec_log_event('api_rate_limit_exceeded', [
                    'key' => $key,
                    'hits' => $row['hits'],
                    'ip' => sec_get_ip(),
                    'url' => $_SERVER['REQUEST_URI'] ?? '',
                ], 'warning');
            }
            return ['allowed' => false, 'remaining' => 0, 'reset_in' => $reset_in];
        }

        db()->prepare("UPDATE sec_rate_limit SET hits=hits+1 WHERE id=?")
            ->execute([$row['id']]);
        return ['allowed' => true, 'remaining' => $max_per_min - $row['hits'] - 1, 'reset_in' => $window - $window_age];
    } catch (Throwable $e) {
        error_log('[security] api_rate_limit: ' . $e->getMessage());
        return ['allowed' => true, 'remaining' => $max_per_min, 'reset_in' => $window];
    }
}

/**
 * Apply API rate limit and send 429 headers if exceeded.
 * Call at top of every API endpoint.
 */
function sec_enforce_api_rate_limit(?int $user_id = null, ?string $token = null): void {
    $ip_key   = 'ip:' . sec_get_ip();
    $max      = SEC_API_MAX_RPM;

    // Per-token limit
    if ($token) {
        $tok_key = 'token:' . hash('sha256', $token);
        $result  = sec_api_rate_limit($tok_key, SEC_API_MAX_RPM_TOKEN);
        if (!$result['allowed']) {
            sec_api_rate_limit_response($result);
        }
    }

    // Per-user limit
    if ($user_id) {
        $u_result = sec_api_rate_limit('user:' . $user_id, SEC_API_MAX_RPM_TOKEN);
        if (!$u_result['allowed']) {
            sec_api_rate_limit_response($u_result);
        }
    }

    // Per-IP limit (always)
    $ip_result = sec_api_rate_limit($ip_key, $max);
    if (!$ip_result['allowed']) {
        sec_api_rate_limit_response($ip_result);
    }
}

function sec_api_rate_limit_response(array $result): void {
    http_response_code(429);
    header('Retry-After: ' . $result['reset_in']);
    header('X-RateLimit-Remaining: 0');
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'rate_limit_exceeded',
        'message' => 'Too many requests. Please retry after ' . $result['reset_in'] . ' seconds.',
        'retry_after' => $result['reset_in'],
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════
// 4. CORS & DOMAIN WHITELIST
// ═══════════════════════════════════════════════════════════════

/**
 * Allowed origins for API endpoints.
 * Reads extra domains from settings table (sec_allowed_origins).
 */
function sec_get_allowed_origins(): array {
    static $origins = null;
    if ($origins !== null) return $origins;

    $base = BASE_URL ?? 'https://vps.greathost.in';
    $origins = [$base];

    // Admin-configurable extra origins
    try {
        $extra = get_setting('sec_allowed_origins', '');
        if ($extra) {
            foreach (explode("\n", $extra) as $line) {
                $line = rtrim(trim($line), '/');
                if ($line && filter_var($line, FILTER_VALIDATE_URL)) {
                    $origins[] = $line;
                }
            }
        }
    } catch (Throwable $e) {}

    return $origins;
}

/**
 * Validate request origin against whitelist.
 * For API endpoints: call this + enforce on fail.
 */
function sec_check_cors(): bool {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!$origin) {
        // Same-origin request (no Origin header) — allow
        return true;
    }
    $allowed = sec_get_allowed_origins();
    foreach ($allowed as $o) {
        if (rtrim($origin, '/') === rtrim($o, '/')) {
            return true;
        }
    }
    return false;
}

/**
 * Send CORS headers. Call before any output on API endpoints.
 * Blocks non-whitelisted origins with 403.
 */
function sec_enforce_cors(bool $strict = true): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if (!$origin) {
        // No origin header — direct/same-origin request, OK
        return;
    }

    if (sec_check_cors()) {
        header("Access-Control-Allow-Origin: $origin");
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token");
        header("Access-Control-Max-Age: 3600");
        header("Vary: Origin");

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        return;
    }

    if ($strict) {
        sec_log_event('cors_blocked', [
            'origin' => $origin,
            'ip' => sec_get_ip(),
            'url' => $_SERVER['REQUEST_URI'] ?? '',
        ], 'warning');
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'forbidden', 'message' => 'Origin not allowed.']);
        exit;
    }
}

// ═══════════════════════════════════════════════════════════════
// 5. ATTACK / SECURITY EVENT LOGGING
// ═══════════════════════════════════════════════════════════════

/**
 * Log a security event.
 * $type: string identifier (brute_force_login, xss_attempt, cors_blocked, etc.)
 * $data: associative array of context
 * $severity: info | warning | critical
 */
function sec_log_event(string $type, array $data = [], string $severity = 'info'): void {
    try {
        $ip     = sec_get_ip();
        $uid    = $_SESSION['user_id'] ?? null;
        $url    = $_SERVER['REQUEST_URI'] ?? '';
        $ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ref    = $_SERVER['HTTP_REFERER'] ?? '';

        db()->prepare("
            INSERT INTO sec_event_log
            (event_type, severity, ip, user_id, url, user_agent, referer, payload, created_at)
            VALUES (?,?,?,?,?,?,?,?,NOW())
        ")->execute([
            $type,
            $severity,
            $ip,
            $uid,
            substr($url, 0, 1000),
            substr($ua, 0, 512),
            substr($ref, 0, 512),
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]);
    } catch (Throwable $e) {
        error_log('[security] log_event failed: ' . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════════
// 6. ACTIVITY / ERROR LOGGING (for admin analytics panel)
// ═══════════════════════════════════════════════════════════════

/**
 * Log a user action / click / page view for analytics.
 * Call from any page/API you want to track.
 */
function track_action(string $action, array $meta = []): void {
    try {
        $ip  = sec_get_ip();
        $uid = $_SESSION['user_id'] ?? null;
        $url = $_SERVER['REQUEST_URI'] ?? '';
        $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';

        db()->prepare("
            INSERT INTO activity_log
            (user_id, ip, action, url, user_agent, meta, created_at)
            VALUES (?,?,?,?,?,?,NOW())
        ")->execute([
            $uid, $ip, $action,
            substr($url, 0, 1000),
            substr($ua, 0, 512),
            json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]);
    } catch (Throwable $e) {
        // Non-fatal — don't break page
        error_log('[security] track_action: ' . $e->getMessage());
    }
}

/**
 * Log an application error / exception for the admin panel.
 */
function log_app_error(string $error_type, string $message, array $context = []): void {
    try {
        $ip  = sec_get_ip();
        $uid = $_SESSION['user_id'] ?? null;
        $url = $_SERVER['REQUEST_URI'] ?? '';
        $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $payload = [
            'message' => $message,
            'context' => $context,
        ];

        db()->prepare("
            INSERT INTO error_log
            (error_type, user_id, ip, url, user_agent, payload, created_at)
            VALUES (?,?,?,?,?,?,NOW())
        ")->execute([
            $error_type,
            $uid,
            $ip,
            substr($url, 0, 1000),
            substr($ua, 0, 512),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]);
    } catch (Throwable $e) {
        error_log('[security] log_app_error: ' . $e->getMessage());
    }
}

// ─── PHP error handler integration ───────────────────────────
function sec_register_error_handler(): void {
    set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
        // Only log E_ERROR, E_WARNING, E_USER_ERROR, etc. (not notices in prod)
        if ($errno & (E_ERROR | E_WARNING | E_USER_ERROR | E_USER_WARNING | E_RECOVERABLE_ERROR)) {
            log_app_error('php_error', $errstr, [
                'errno' => $errno,
                'file'  => $errfile,
                'line'  => $errline,
            ]);
        }
        return false; // Let PHP handle normally too
    });

    set_exception_handler(function (Throwable $e): void {
        log_app_error('php_exception', $e->getMessage(), [
            'class' => get_class($e),
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => substr($e->getTraceAsString(), 0, 2000),
        ]);
        // Rethrow or display generic error
        http_response_code(500);
        echo json_encode(['error' => 'internal_error', 'message' => 'An unexpected error occurred.']);
        exit;
    });
}

// ═══════════════════════════════════════════════════════════════
// 7. DATABASE TABLE INSTALLER
//    Run once: sec_install_tables();
//    Or call from admin setup page / migration script.
// ═══════════════════════════════════════════════════════════════

function sec_install_tables(): void {
    $pdo = db();

    $tables = [
        // Login brute-force tracking
        "CREATE TABLE IF NOT EXISTS sec_login_attempts (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip          VARCHAR(45)  NOT NULL DEFAULT '',
            device_id   VARCHAR(64)  NOT NULL DEFAULT '',
            username    VARCHAR(255) NOT NULL DEFAULT '',
            user_agent  VARCHAR(512) NOT NULL DEFAULT '',
            attempted_at DATETIME    NOT NULL,
            ban_until   DATETIME     NULL,
            INDEX idx_ip (ip),
            INDEX idx_device (device_id),
            INDEX idx_attempted (attempted_at),
            INDEX idx_ban (ban_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // API rate limiting
        "CREATE TABLE IF NOT EXISTS sec_rate_limit (
            id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            rate_key     VARCHAR(128) NOT NULL UNIQUE,
            hits         INT UNSIGNED NOT NULL DEFAULT 1,
            window_start DATETIME     NOT NULL,
            INDEX idx_key (rate_key),
            INDEX idx_window (window_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Security event log
        "CREATE TABLE IF NOT EXISTS sec_event_log (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_type  VARCHAR(64)   NOT NULL,
            severity    ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
            ip          VARCHAR(45)   NOT NULL DEFAULT '',
            user_id     INT UNSIGNED  NULL,
            url         VARCHAR(1000) NOT NULL DEFAULT '',
            user_agent  VARCHAR(512)  NOT NULL DEFAULT '',
            referer     VARCHAR(512)  NOT NULL DEFAULT '',
            payload     JSON          NULL,
            created_at  DATETIME      NOT NULL,
            INDEX idx_type (event_type),
            INDEX idx_severity (severity),
            INDEX idx_ip (ip),
            INDEX idx_uid (user_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // General activity log
        "CREATE TABLE IF NOT EXISTS activity_log (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id     INT UNSIGNED  NULL,
            ip          VARCHAR(45)   NOT NULL DEFAULT '',
            action      VARCHAR(128)  NOT NULL,
            url         VARCHAR(1000) NOT NULL DEFAULT '',
            user_agent  VARCHAR(512)  NOT NULL DEFAULT '',
            meta        JSON          NULL,
            created_at  DATETIME      NOT NULL,
            INDEX idx_uid (user_id),
            INDEX idx_action (action),
            INDEX idx_ip (ip),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Application error log
        "CREATE TABLE IF NOT EXISTS error_log (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            error_type  VARCHAR(64)   NOT NULL,
            user_id     INT UNSIGNED  NULL,
            ip          VARCHAR(45)   NOT NULL DEFAULT '',
            url         VARCHAR(1000) NOT NULL DEFAULT '',
            user_agent  VARCHAR(512)  NOT NULL DEFAULT '',
            payload     JSON          NULL,
            created_at  DATETIME      NOT NULL,
            INDEX idx_type (error_type),
            INDEX idx_uid (user_id),
            INDEX idx_ip (ip),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($tables as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            error_log('[security] install_tables: ' . $e->getMessage());
        }
    }
}

// ═══════════════════════════════════════════════════════════════
// 8. AUTO-BOOT  (called automatically when this file is included)
// ═══════════════════════════════════════════════════════════════

// Only send headers for real HTTP requests (not CLI/cron)
if (php_sapi_name() !== 'cli') {
    security_send_headers();
}