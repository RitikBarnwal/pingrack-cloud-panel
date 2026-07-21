<?php
require_once __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/json');

$u = trim($_GET['u'] ?? '');
if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $u)) {
    echo json_encode(['available' => false]);
    exit;
}
$stmt = db()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$stmt->execute([$u]);
echo json_encode(['available' => !$stmt->fetch()]);
