<?php
// CloudVault – Verify OTP (AJAX)
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json');
session_start_safe();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }
if (!verify_csrf($_POST['csrf_token'] ?? '')) { http_response_code(403); echo json_encode(['error'=>'Invalid CSRF']); exit; }

$email = strtolower(trim($_POST['email'] ?? ''));
$otp   = trim($_POST['otp'] ?? '');
$mode  = $_POST['mode'] ?? 'reg';

if (!$email || strlen($otp) !== 6 || !ctype_digit($otp)) {
    echo json_encode(['error'=>'Invalid input']); exit;
}

$key  = 'otp_' . $mode . '_' . md5($email);
$data = $_SESSION[$key] ?? null;

if (!$data || $data['email'] !== $email) {
    echo json_encode(['error'=>'OTP not found. Please request a new one.']); exit;
}
if (time() > $data['expires']) {
    unset($_SESSION[$key]);
    echo json_encode(['error'=>'OTP expired. Please request a new one.']); exit;
}
if (!password_verify($otp, $data['hash'])) {
    echo json_encode(['success'=>false, 'error'=>'Incorrect OTP']); exit;
}

// Mark verified in session
$_SESSION['otp_verified_' . $mode . '_' . md5($email)] = true;
echo json_encode(['success'=>true]);
