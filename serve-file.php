<?php
/**
 * serve-file.php — Secure private file server
 * Place at: /www/wwwroot/vps.greathost.in/serve-file.php
 *
 * Usage:
 *   Profile pic:  /serve-file.php?type=pr_pic&file=u9_abc123.jpg
 *   KYC doc:      /serve-file.php?type=kyc&file=kyc_u1_file_front_abc.jpg
 *
 * - Files served from /www/uploads/ (outside webroot, not directly accessible)
 * - KYC files: only admin or the owner can access
 * - Profile pics: public (anyone with the URL can view)
 */

require_once __DIR__ . '/includes/bootstrap.php';

// ── Allowed types → base dirs ──────────────────────────────
const SERVE_DIRS = [
    'pr_pic' => '/www/uploads/pr_pic/',
    'kyc'    => '/www/uploads/kyc/',
];

// ── Input validation ───────────────────────────────────────
$type = $_GET['type'] ?? '';
$file = $_GET['file'] ?? '';

if (!isset(SERVE_DIRS[$type])) {
    http_response_code(400); exit('Invalid type.');
}

// Strip any directory traversal — filename only, no slashes
$file = basename($file);
if ($file === '' || $file === '.' || $file === '..') {
    http_response_code(400); exit('Invalid file.');
}

$base_dir  = SERVE_DIRS[$type];
$full_path = $base_dir . $file;

// ── File must exist ────────────────────────────────────────
if (!file_exists($full_path) || !is_file($full_path)) {
    http_response_code(404); exit('File not found.');
}

// ── Access control ─────────────────────────────────────────
if ($type === 'kyc') {
    // KYC: must be logged in + must be owner OR admin
    require_login(); // redirects if not logged in
    $current = current_user();

    if ($current['role'] !== 'admin') {
        // Check this file belongs to the current user
        $st = db()->prepare(
            'SELECT id FROM kyc_requests
             WHERE user_id = ?
               AND (file_front = ? OR file_back = ? OR file_video = ?)
             LIMIT 1'
        );
        $rel = 'kyc/' . $file;
        $st->execute([$current['id'], $rel, $rel, $rel]);
        if (!$st->fetch()) {
            http_response_code(403); exit('Access denied.');
        }
    }
}
// pr_pic: no auth check — profile pics are public

// ── Detect MIME ────────────────────────────────────────────
$ext      = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime_map = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'pdf'  => 'application/pdf',
    'webm' => 'video/webm',
    'mp4'  => 'video/mp4',
    'ogg'  => 'video/ogg',
    'mov'  => 'video/quicktime',
];
$mime = $mime_map[$ext] ?? mime_content_type($full_path) ?: 'application/octet-stream';

// ── Security headers ───────────────────────────────────────
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($full_path));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
// Prevent HTML/JS execution if someone sneaks a .html file
header('Content-Security-Policy: default-src \'none\'');

// For PDFs/videos — inline display; for others too
if (in_array($mime, ['application/pdf', 'video/webm', 'video/mp4', 'video/ogg'])) {
    header('Content-Disposition: inline; filename="' . addslashes($file) . '"');
} else {
    header('Content-Disposition: inline');
}

// ── Stream file ────────────────────────────────────────────
readfile($full_path);
exit;