<?php
// CloudVault – Authentication Helpers v2

function session_start_safe(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // Mark cookie Secure whenever the request is HTTPS (site runs behind
        // Cloudflare/HSTS). Falls back to false only for plain-HTTP/local dev.
        $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443);
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => $is_https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function is_logged_in(): bool {
    session_start_safe();
    return !empty($_SESSION['user_id']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }

    // ── Token validation: agar session DB mein revoke ho gayi toh force logout ──
    if (!empty($_SESSION['session_token'])) {
        try {
            $st = db()->prepare("SELECT is_active FROM login_sessions WHERE token=? AND user_id=? LIMIT 1");
            $st->execute([$_SESSION['session_token'], $_SESSION['user_id']]);
            $row = $st->fetch();

            if (!$row || !$row['is_active']) {
                // Session revoke ho gayi (logout all / logout specific session)
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $p = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000,
                        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
                }
                session_destroy();
                header('Location: ' . BASE_URL . '/login.php?reason=session_revoked');
                exit;
            }

            // Session valid hai — last_active update karo
            db()->prepare("UPDATE login_sessions SET last_active=NOW() WHERE token=?")
               ->execute([$_SESSION['session_token']]);
        } catch (Throwable $e) {
            error_log('[auth] session validation error: ' . $e->getMessage());
        }
    }
}

function require_admin(): void {
    require_login();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        header('Location: ' . BASE_URL . '/dashboard.php');
        exit;
    }
}

function current_user(): array {
    if (!is_logged_in()) return [];
    $st = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $st->execute([$_SESSION['user_id']]);
    return $st->fetch() ?: [];
}

function login_user(array $user): void {
    session_start_safe();
    session_regenerate_id(true);
    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role']     = $user['role'];

    // ── Track login session ───────────────────────────────
    try {
        $token      = bin2hex(random_bytes(32));
        $session_id = session_id();
        $ip         = _get_client_ip();
        $ua         = $_SERVER['HTTP_USER_AGENT'] ?? '';
        [$device_type, $device_name, $os_name, $browser_name] = _parse_user_agent($ua);

        // Mark all previous sessions as not-current
        db()->prepare("UPDATE login_sessions SET is_current=0 WHERE user_id=?")
           ->execute([$user['id']]);

        // ── GeoIP lookup (ip-api.com free, no key needed) ───────
        $geo_city    = null;
        $geo_country = null;
        if ($ip && $ip !== '127.0.0.1' && $ip !== '0.0.0.0' && !str_starts_with($ip, '192.168.') && !str_starts_with($ip, '10.')) {
            try {
                $geo_url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,city,regionName,country,countryCode';
                $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
                $geo_raw = @file_get_contents($geo_url, false, $ctx);
                if ($geo_raw) {
                    $geo = json_decode($geo_raw, true);
                    if (($geo['status'] ?? '') === 'success') {
                        $geo_city    = trim(($geo['city'] ?? '') . (isset($geo['regionName']) ? ', ' . $geo['regionName'] : ''));
                        $geo_country = $geo['countryCode'] ?? null;
                    }
                }
            } catch (Throwable $e) {
                error_log('[auth] GeoIP lookup failed: ' . $e->getMessage());
            }
        }

        db()->prepare(
            'INSERT INTO login_sessions
             (user_id, session_id, token, ip_address, user_agent, device_type, device_name, os_name, browser_name, country, city, is_current, is_active)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,1,1)'
        )->execute([$user['id'], $session_id, $token, $ip, $ua, $device_type, $device_name, $os_name, $browser_name, $geo_country, $geo_city]);

        $_SESSION['session_token'] = $token;
    } catch (Throwable $e) {
        error_log('[auth] session tracking error: ' . $e->getMessage());
    }
}

function logout_user(): void {
    session_start_safe();

    // Mark session as logged out
    try {
        if (!empty($_SESSION['session_token'])) {
            db()->prepare("UPDATE login_sessions SET is_active=0, is_current=0, logged_out_at=NOW() WHERE token=?")
               ->execute([$_SESSION['session_token']]);
        }
    } catch (Throwable $e) {}

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ── Impersonation ─────────────────────────────────────────────
// Admin can enter any user's session. Original admin session is
// saved in $_SESSION['impersonate_admin'] so they can exit back.

function impersonate_user(int $target_user_id): bool
{
    session_start_safe();
    // Must be admin
    if (($_SESSION['role'] ?? '') !== 'admin') return false;

    $st = db()->prepare('SELECT id, username, role FROM users WHERE id=? AND role!=? LIMIT 1');
    $st->execute([$target_user_id, 'admin']);
    $target = $st->fetch();
    if (!$target) return false;

    // Save original admin session
    $_SESSION['impersonate_admin'] = [
        'user_id'       => $_SESSION['user_id'],
        'username'      => $_SESSION['username'],
        'role'          => $_SESSION['role'],
        'session_token' => $_SESSION['session_token'] ?? '',
    ];

    // Audit log: who impersonated whom (before switching identity).
    if (function_exists('sec_log_event')) {
        sec_log_event('impersonate_enter', [
            'admin_id'   => $_SESSION['user_id'],
            'admin_user' => $_SESSION['username'],
            'target_id'  => $target['id'],
            'target_user'=> $target['username'],
        ], 'warning');
    }

    // Switch session to target user
    $_SESSION['user_id']  = $target['id'];
    $_SESSION['username'] = $target['username'];
    $_SESSION['role']     = $target['role'];
    unset($_SESSION['session_token']); // skip session_token validation while impersonating

    return true;
}

function impersonating(): bool
{
    session_start_safe();
    return !empty($_SESSION['impersonate_admin']);
}

function impersonate_exit(): void
{
    session_start_safe();
    if (empty($_SESSION['impersonate_admin'])) return;

    $admin = $_SESSION['impersonate_admin'];

    if (function_exists('sec_log_event')) {
        sec_log_event('impersonate_exit', [
            'admin_id'   => $admin['user_id'],
            'admin_user' => $admin['username'],
            'target_id'  => $_SESSION['user_id'] ?? null,
            'target_user'=> $_SESSION['username'] ?? null,
        ], 'warning');
    }

    $_SESSION['user_id']       = $admin['user_id'];
    $_SESSION['username']      = $admin['username'];
    $_SESSION['role']          = $admin['role'];
    $_SESSION['session_token'] = $admin['session_token'];
    unset($_SESSION['impersonate_admin']);
}

function impersonate_admin_name(): string
{
    session_start_safe();
    return htmlspecialchars($_SESSION['impersonate_admin']['username'] ?? '');
}

function hash_password(string $pw): string {
    return password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verify_password(string $pw, string $hash): bool {
    return password_verify($pw, $hash);
}

function format_bytes(int $bytes, int $precision = 1): string {
    if ($bytes <= 0) return '0 B';
    $units = ['B','KB','MB','GB','TB'];
    $pow   = min(floor(log($bytes, 1024)), count($units) - 1);
    $val   = $bytes / (1024 ** $pow);
    return round($val, $pow === 0 ? 0 : $precision) . ' ' . $units[$pow];
}

function csrf_token(): string {
    session_start_safe();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(string $token): bool {
    session_start_safe();
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ── Device/IP helpers ─────────────────────────────────────

function _get_client_ip(): string {
    // Use the hardened, Cloudflare-validated resolver so proxy headers
    // can't be spoofed to forge the logged/geo IP.
    if (function_exists('sec_get_ip')) {
        return sec_get_ip();
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function _parse_user_agent(string $ua): array {
    if (!$ua) return ['unknown', 'Unknown Device', 'Unknown OS', 'Unknown Browser'];

    // Device type
    $device_type = 'desktop';
    if (preg_match('/bot|crawl|slurp|spider|mediapartners/i', $ua)) {
        $device_type = 'bot';
    } elseif (preg_match('/tablet|ipad|kindle|playbook|silk/i', $ua)) {
        $device_type = 'tablet';
    } elseif (preg_match('/mobile|android|iphone|ipod|blackberry|windows phone|opera mini/i', $ua)) {
        $device_type = 'mobile';
    }

    // OS
    $os = 'Unknown OS';
    if (preg_match('/windows nt 10/i', $ua))      $os = 'Windows 10/11';
    elseif (preg_match('/windows nt 6\.1/i', $ua)) $os = 'Windows 7';
    elseif (preg_match('/windows/i', $ua))          $os = 'Windows';
    elseif (preg_match('/iphone os ([0-9_]+)/i', $ua, $m)) $os = 'iOS ' . str_replace('_', '.', $m[1]);
    elseif (preg_match('/ipad.*os ([0-9_]+)/i', $ua, $m))  $os = 'iPadOS ' . str_replace('_', '.', $m[1]);
    elseif (preg_match('/android ([0-9.]+)/i', $ua, $m))    $os = 'Android ' . $m[1];
    elseif (preg_match('/mac os x ([0-9_]+)/i', $ua, $m))   $os = 'macOS ' . str_replace('_', '.', $m[1]);
    elseif (preg_match('/ubuntu/i', $ua))           $os = 'Ubuntu Linux';
    elseif (preg_match('/linux/i', $ua))             $os = 'Linux';

    // Browser
    $browser = 'Unknown Browser';
    if (preg_match('/edg\/([0-9.]+)/i', $ua, $m))           $browser = 'Edge ' . explode('.', $m[1])[0];
    elseif (preg_match('/opr\/([0-9.]+)/i', $ua, $m))        $browser = 'Opera ' . explode('.', $m[1])[0];
    elseif (preg_match('/chrome\/([0-9.]+)/i', $ua, $m))     $browser = 'Chrome ' . explode('.', $m[1])[0];
    elseif (preg_match('/firefox\/([0-9.]+)/i', $ua, $m))    $browser = 'Firefox ' . explode('.', $m[1])[0];
    elseif (preg_match('/safari\/([0-9.]+)/i', $ua, $m) && !preg_match('/chrome/i', $ua)) {
        $browser = preg_match('/version\/([0-9.]+)/i', $ua, $mv) ? 'Safari ' . explode('.', $mv[1])[0] : 'Safari';
    }

    // Device name label
    $device_name = $browser . ' on ' . $os;

    return [$device_type, $device_name, $os, $browser];
}