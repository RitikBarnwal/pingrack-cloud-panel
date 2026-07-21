<?php
/**
 * api/admin-impersonate.php
 * Admin can enter or exit any user's account.
 *
 * POST { action: 'enter', user_id: 5, csrf: '...' }
 * POST { action: 'exit',              csrf: '...' }
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST only']); exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
if (!verify_csrf($body['csrf'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF']); exit;
}

$action = $body['action'] ?? '';

// Enter impersonation
if ($action === 'enter') {
    require_admin();  // must be admin

    $uid = (int)($body['user_id'] ?? 0);
    if (!$uid) { echo json_encode(['ok' => false, 'error' => 'User ID required']); exit; }

    if (impersonate_user($uid)) {
        echo json_encode(['ok' => true, 'redirect' => BASE_URL . '/dashboard.php']);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Cannot impersonate this user (admin accounts protected)']);
    }
    exit;
}

// Exit impersonation
if ($action === 'exit') {
    if (!impersonating()) {
        echo json_encode(['ok' => false, 'error' => 'Not in impersonation mode']); exit;
    }
    impersonate_exit();
    echo json_encode(['ok' => true, 'redirect' => BASE_URL . '/admin/?tab=users']);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
