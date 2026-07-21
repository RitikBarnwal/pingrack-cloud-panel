<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin.php';
require_admin();

$file = basename($_GET['file'] ?? '');
if (!$file) { http_response_code(400); exit; }

$type = $_GET['type'] ?? 'resume';
$base_path = $type === 'kyc'
    ? '/www/uploads/kyc/'
    : '/www/uploads/resumes/';

$path = $base_path . $file;
if (!file_exists($path)) { http_response_code(404); exit; }

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $file . '"');
readfile($path);