<?php
/**
 * api/v1/_auth.php
 * Bearer token authentication for public API.
 * Include this at the top of every v1 endpoint.
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function api_error(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function api_ok(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}

function api_auth(): array {
    $header = $_SERVER['HTTP_AUTHORIZATION']
           ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
           ?? getallheaders()['Authorization']
           ?? '';

    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        api_error('Missing or invalid Authorization header. Use: Bearer YOUR_API_TOKEN', 401);
    }

    $raw_token = trim($m[1]);

    $st = db()->prepare(
        'SELECT t.*, u.* FROM api_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.token = ? AND u.status = ?
         LIMIT 1'
    );
    $st->execute([$raw_token, 'active']);
    $row = $st->fetch();

    if (!$row) api_error('Invalid or expired API token.', 401);

    // Check expiry
    if ($row['expires_at'] && strtotime($row['expires_at']) < time()) {
        api_error('API token has expired.', 401);
    }

    // Update last_used
    db()->prepare('UPDATE api_tokens SET last_used=NOW() WHERE token=?')->execute([$raw_token]);

    return $row; // merged user + token row
}
