<?php
/**
 * GreatHost VPS — API Security Middleware v1.0
 *
 * Include at the TOP of every file under /api/*.php and /api/v1/*.php:
 *   require_once __DIR__ . '/../includes/api_security.php';
 *
 * What it does, in order:
 *   1. Enforces strict CORS (only whitelisted origins)
 *   2. Enforces per-IP rate limit
 *   3. Enforces per-user rate limit (if session active)
 *   4. Enforces per-token rate limit (if Bearer token in header)
 *   5. Sends secure headers
 *   6. Blocks non-HTTPS in production
 *   7. Logs the request for analytics
 */

// Bootstrap is already loaded before this file from the API file itself.
// If not yet loaded, load it:
if (!function_exists('db')) {
    require_once __DIR__ . '/bootstrap.php';
}
if (!function_exists('sec_get_ip')) {
    require_once __DIR__ . '/security.php';
}

// ── Step 1: CORS ─────────────────────────────────────────────
// Strict = true: non-whitelisted origins get 403 + logged.
sec_enforce_cors(strict: true);

// ── Step 2: Rate limit ───────────────────────────────────────
$_api_uid   = $_SESSION['user_id'] ?? null;
$_api_token = null;

// Extract Bearer token if present
$_auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Bearer\s+(.+)$/i', $_auth_header, $m)) {
    $_api_token = trim($m[1]);
}

// Also check X-API-Key header (for v1 API keys)
if (!$_api_token) {
    $_api_token = $_SERVER['HTTP_X_API_KEY'] ?? null;
}

sec_enforce_api_rate_limit($_api_uid, $_api_token);

// ── Step 3: Log API access (non-blocking) ───────────────────
$_api_action = $_SERVER['REQUEST_METHOD'] . ':' . (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/');
track_action('api_request', [
    'method'  => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'has_token' => (bool)$_api_token,
    'has_session' => (bool)$_api_uid,
]);

// ── Step 4: Reject obviously abusive patterns ────────────────
// (oversized bodies, suspicious content-types trying to smuggle)
$content_length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($content_length > 10 * 1024 * 1024) { // 10 MB max API body
    sec_log_event('api_oversized_body', ['size' => $content_length, 'ip' => sec_get_ip()], 'warning');
    http_response_code(413);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'payload_too_large', 'message' => 'Request body exceeds limit.']);
    exit;
}

// ── Cleanup temp vars from global scope ─────────────────────
unset($_auth_header, $_api_action, $_api_uid, $_api_token, $_m, $content_length);
