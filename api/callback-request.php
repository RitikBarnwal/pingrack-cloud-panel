<?php
/**
 * api/callback-request.php
 * Handles callback request form submission from client area.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']); exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

if (!verify_csrf($data['csrf'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']); exit;
}

// Check feature enabled
if (get_setting('callback_enabled', '1') !== '1') {
    echo json_encode(['ok' => false, 'error' => 'Callback feature is currently disabled']); exit;
}

$user = current_user();

$name    = trim($data['name']    ?? '');
$phone   = trim($data['phone']   ?? '');
$dept    = trim($data['dept']    ?? '');
$time    = trim($data['time']    ?? '');
$message = trim($data['message'] ?? '');

// Validation
if (empty($phone))   { echo json_encode(['ok'=>false,'error'=>'Phone number is required']); exit; }
if (empty($dept))    { echo json_encode(['ok'=>false,'error'=>'Department is required']); exit; }
if (empty($message)) { echo json_encode(['ok'=>false,'error'=>'Message is required']); exit; }
if (strlen($message) > 2000) { echo json_encode(['ok'=>false,'error'=>'Message too long']); exit; }

// Validate department exists and is active
$dept_check = db()->prepare("SELECT id FROM callback_departments WHERE name=? AND is_active=1 LIMIT 1");
$dept_check->execute([$dept]);
if (!$dept_check->fetch()) {
    echo json_encode(['ok'=>false,'error'=>'Invalid department selected']); exit;
}

// Rate limit: max 3 pending per user
$pending = db()->prepare("SELECT COUNT(*) FROM callback_requests WHERE user_id=? AND status='pending'");
$pending->execute([$user['id']]);
if ((int)$pending->fetchColumn() >= 3) {
    echo json_encode(['ok'=>false,'error'=>'You already have pending callback requests. Please wait for them to be resolved.']); exit;
}

// Use full_name if name empty
if (empty($name)) {
    $name = $user['full_name'] ?: $user['username'];
}

$stmt = db()->prepare("
    INSERT INTO callback_requests (user_id, name, phone, department, preferred_time, message)
    VALUES (?, ?, ?, ?, ?, ?)
");
$stmt->execute([
    $user['id'],
    substr($name, 0, 120),
    substr($phone, 0, 30),
    substr($dept, 0, 100),
    substr($time, 0, 60),
    $message,
]);

echo json_encode(['ok' => true, 'message' => 'Your callback request has been submitted! Our team will contact you soon.']);
