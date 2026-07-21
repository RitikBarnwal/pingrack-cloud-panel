<?php
/**
 * api/storage-upload.php
 * File upload → MinIO S3 (ab local filesystem nahi)
 *
 * FIXED: move_uploaded_file → minio->putObject()
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/storage.php';
require_login();

header('Content-Type: application/json');

$uid = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST only']); exit;
}
if (!verify_csrf($_POST['csrf'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Invalid token']); exit;
}

$bid        = (int)($_POST['bucket_id'] ?? 0);
$object_key = trim($_POST['key'] ?? '');
$bucket     = storage_get_bucket($bid, $uid);

if (!$bucket || $bucket['status'] !== 'active') {
    echo json_encode(['ok' => false, 'error' => 'Bucket not found or suspended']); exit;
}
if (!$object_key) {
    echo json_encode(['ok' => false, 'error' => 'Object key required']); exit;
}
if (empty($_FILES['file'])) {
    echo json_encode(['ok' => false, 'error' => 'No file uploaded']); exit;
}

$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'Upload error code: ' . $file['error']]); exit;
}

// Max 100 MB
if ($file['size'] > 100 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'error' => 'File too large. Max 100 MB.']); exit;
}

$tmp_path    = $file['tmp_name'];
$size        = $file['size'];
$mime        = mime_content_type($tmp_path) ?: 'application/octet-stream';
$etag        = md5_file($tmp_path);
$file_body   = file_get_contents($tmp_path);

if ($file_body === false) {
    echo json_encode(['ok' => false, 'error' => 'Could not read uploaded file.']); exit;
}

// ── MinIO pe upload ───────────────────────────────────────────
try {
    $minio = storage_minio_for($bucket['region']);
    $ok    = $minio->putObject($bucket['name'], $object_key, $file_body, $mime);

    if (!$ok) {
        echo json_encode(['ok' => false, 'error' => 'MinIO upload failed. Check credentials and bucket config.']); exit;
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'MinIO error: ' . $e->getMessage()]); exit;
}

// ── DB mein index karo ───────────────────────────────────────
try {
    db()->prepare(
        'INSERT INTO storage_objects (bucket_id, object_key, size_bytes, content_type, etag, last_modified)
         VALUES (?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE
           size_bytes=VALUES(size_bytes),
           content_type=VALUES(content_type),
           etag=VALUES(etag),
           last_modified=NOW()'
    )->execute([$bid, $object_key, $size, $mime, $etag]);

    // used_gb update
    $total = db()->prepare('SELECT COALESCE(SUM(size_bytes),0) FROM storage_objects WHERE bucket_id=?');
    $total->execute([$bid]);
    db()->prepare('UPDATE storage_buckets SET used_gb=? WHERE id=?')
       ->execute([round((float)$total->fetchColumn() / (1024 ** 3), 6), $bid]);

    echo json_encode([
        'ok'   => true,
        'key'  => $object_key,
        'size' => $size,
        'etag' => $etag,
        'mime' => $mime,
    ]);
} catch (Throwable $e) {
    // MinIO pe file gai lekin DB index nahi hua — log karo
    error_log('[storage-upload] DB index failed for ' . $object_key . ': ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'File uploaded but DB index failed: ' . $e->getMessage()]);
}