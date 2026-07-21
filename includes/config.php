<?php

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/db.php';

// ── Settings (DB cache) ─────────────────────────────────
if (!function_exists('get_setting')) {
function get_setting(string $key, string $default = ''): string {
    static $cache = null;

    if ($cache === null) {
        try {
            $rows = db()->query("SELECT `key`,`value` FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
            $cache = $rows ?: [];
        } catch (Throwable $e) {
            $cache = [];
        }
    }

    return $cache[$key] ?? $default;
}
}

if (!function_exists('set_setting')) {
    function set_setting(string $key, string $value): void {
        db()->prepare("INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=?")
           ->execute([$key, $value, $value]);
    }
}

// ── Basic ───────────────────────────────────────────────
define('APP_VERSION', '3.0.0');

// ── Database ────────────────────────────────────────────
// Prefer environment variables (set outside the webroot / in the server
// config) so credentials are not hardcoded in a file that could leak.
// The literals remain only as a local/dev fallback — set the env vars in prod.
define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost');
define('DB_NAME',    getenv('DB_NAME')    ?: 'pingrack_vps');
define('DB_USER',    getenv('DB_USER')    ?: 'pingrack_vps');
define('DB_PASS',    getenv('DB_PASS')    ?: 'lCJV=MvOSqZ+zZi!');
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

// ── App Settings (DB + fallback) ────────────────────────
define('APP_NAME', get_setting('site_name', 'CloudVault'));

// ── Base URL ────────────────────────────────────────────
// Must match the domain the user is actually on, otherwise post-login
// redirects cross domains and the session cookie is dropped (login loop).
// Derived from the request Host, but ONLY from a whitelist so the Host
// header can't be spoofed to poison reset-email / redirect links.
// Add every domain the panel is served on here (or via env BASE_URL_HOSTS,
// comma-separated). CLI/cron have no Host → fall back to the default.
(function () {
    $default = getenv('BASE_URL') ?: 'https://my.pingrack.com';

    $allowed = ['vps.greathost.in', 'my.pingrack.com'];
    if ($extra = getenv('BASE_URL_HOSTS')) {
        foreach (explode(',', $extra) as $h) {
            $h = trim($h);
            if ($h !== '') $allowed[] = $h;
        }
    }

    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    // Strip any port for comparison.
    $host_nport = preg_replace('/:\d+$/', '', $host);

    if ($host_nport && in_array($host_nport, $allowed, true)) {
        $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443);
        define('BASE_URL', ($https ? 'https://' : 'http://') . $host);
    } else {
        define('BASE_URL', $default);
    }
})();

// ── SMTP ────────────────────────────────────────────────
define('SMTP_HOST', get_setting('SMTP_HOST', 'mail.pingrack.com'));
define('SMTP_USERNAME', get_setting('SMTP_USERNAME', 'support@pingrack.com'));
define('SMTP_PASS', get_setting('SMTP_PASS', 'gm*Gexr.gXSh*l(%'));
define('SMTP_PORT', (int)get_setting('SMTP_PORT', 587));
define('SMTP_ENCRYPTION', get_setting('SMTP_ENCRYPTION', 'tls'));

// ── Site Color ────────────────────────────────────────────────
define('PRIMARY_COLOR', get_setting('primary_color'));
define('PRIMARY_COLOR_HOVER', get_setting('primary_color_hover'));

// ── Security ────────────────────────────────────────────
define('SESSION_LIFETIME', 3600 * 8);
define('PRESIGN_EXPIRY',   300);

// ── Error Reporting ─────────────────────────────────────
// Never render errors to visitors in production (leaks paths / SQL / traces).
// Set APP_ENV=dev in the environment to see errors locally.
define('APP_ENV', getenv('APP_ENV') ?: 'production');
if (APP_ENV === 'dev') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}