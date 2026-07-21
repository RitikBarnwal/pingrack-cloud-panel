<?php
/**
 * api/storage-action.php
 * Storage object actions: delete, rename, mkdir, presign
 *
 * FIXED: local unlink() → minio->deleteObject()
 * ADDED: presign action (temporary download URL)
 * ADDED: mkdir (virtual folder via empty .keep object)
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/storage.php';
require_login();

header('Content-Type: application/json');

$uid  = (int)current_user()['id'];
$body = json_decode(file_get_contents('php://input'), true) ?? [];

if (!verify_csrf($body['csrf'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Invalid token']); exit;
}

$action = $body['action'] ?? '';
$bid    = (int)($body['bucket_id'] ?? 0);
$bucket = storage_get_bucket($bid, $uid);

if (!$bucket) {
    echo json_encode(['ok' => false, 'error' => 'Bucket not found']); exit;
}

// ── DELETE ────────────────────────────────────────────────────
if ($action === 'delete') {
    $oid = (int)($body['object_id'] ?? 0);
    if (!$oid) { echo json_encode(['ok' => false, 'error' => 'Object ID required']); exit; }

    $st = db()->prepare('SELECT * FROM storage_objects WHERE id=? AND bucket_id=? LIMIT 1');
    $st->execute([$oid, $bid]);
    $obj = $st->fetch();
    if (!$obj) { echo json_encode(['ok' => false, 'error' => 'Object not found']); exit; }

    // MinIO se delete karo
    try {
        $minio = storage_minio_for($bucket['region']);
        $minio->deleteObject($bucket['name'], $obj['object_key']);
    } catch (Throwable $e) {
        error_log('[storage-action:delete] MinIO error: ' . $e->getMessage());
        // MinIO error fatal nahi hai — DB se bhi hata do
    }

    // DB se delete karo
    db()->prepare('DELETE FROM storage_objects WHERE id=?')->execute([$oid]);

    // used_gb update
    $total = db()->prepare('SELECT COALESCE(SUM(size_bytes),0) FROM storage_objects WHERE bucket_id=?');
    $total->execute([$bid]);
    db()->prepare('UPDATE storage_buckets SET used_gb=? WHERE id=?')
       ->execute([round((float)$total->fetchColumn() / (1024 ** 3), 6), $bid]);

    echo json_encode(['ok' => true]); exit;
}

// ── DELETE FOLDER (sare objects with prefix) ──────────────────
if ($action === 'delete_folder') {
    $prefix = rtrim($body['prefix'] ?? '', '/');
    if (!$prefix) { echo json_encode(['ok' => false, 'error' => 'Prefix required']); exit; }

    // DB se sab objects lo is prefix ke saath
    $st = db()->prepare(
        'SELECT * FROM storage_objects WHERE bucket_id=? AND object_key LIKE ?'
    );
    $st->execute([$bid, $prefix . '/%']);
    $objs = $st->fetchAll() ?: [];

    try {
        $minio = storage_minio_for($bucket['region']);
        foreach ($objs as $obj) {
            $minio->deleteObject($bucket['name'], $obj['object_key']);
        }
        // .keep object bhi delete karo agar hai
        $minio->deleteObject($bucket['name'], $prefix . '/.keep');
    } catch (Throwable $e) {
        error_log('[storage-action:delete_folder] ' . $e->getMessage());
    }

    db()->prepare(
        'DELETE FROM storage_objects WHERE bucket_id=? AND object_key LIKE ?'
    )->execute([$bid, $prefix . '/%']);

    // used_gb update
    $total = db()->prepare('SELECT COALESCE(SUM(size_bytes),0) FROM storage_objects WHERE bucket_id=?');
    $total->execute([$bid]);
    db()->prepare('UPDATE storage_buckets SET used_gb=? WHERE id=?')
       ->execute([round((float)$total->fetchColumn() / (1024 ** 3), 6), $bid]);

    echo json_encode(['ok' => true, 'deleted' => count($objs)]); exit;
}

// ── CREATE FOLDER (virtual — empty .keep object) ──────────────
if ($action === 'mkdir') {
    $folder_name = trim($body['folder_name'] ?? '');
    $prefix      = rtrim($body['prefix'] ?? '', '/');
    if (!$folder_name || !preg_match('/^[a-zA-Z0-9_\-. ]+$/', $folder_name)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid folder name']); exit;
    }

    $keep_key = ($prefix ? $prefix . '/' : '') . $folder_name . '/.keep';

    try {
        $minio = storage_minio_for($bucket['region']);
        $minio->putObject($bucket['name'], $keep_key, '', 'application/x-directory');
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'MinIO error: ' . $e->getMessage()]); exit;
    }

    // DB mein .keep index karo (size 0)
    try {
        db()->prepare(
            'INSERT IGNORE INTO storage_objects (bucket_id, object_key, size_bytes, content_type, etag, last_modified)
             VALUES (?,?,0,"application/x-directory","",NOW())'
        )->execute([$bid, $keep_key]);
    } catch (Throwable $e) {}

    echo json_encode(['ok' => true, 'folder' => $folder_name]); exit;
}

// ── PRESIGNED URL (temporary download link) ───────────────────
if ($action === 'presign') {
    $oid     = (int)($body['object_id'] ?? 0);
    $expires = min((int)($body['expires'] ?? 3600), 7 * 24 * 3600); // max 7 days
    if (!$oid) { echo json_encode(['ok' => false, 'error' => 'Object ID required']); exit; }

    $st = db()->prepare('SELECT * FROM storage_objects WHERE id=? AND bucket_id=? LIMIT 1');
    $st->execute([$oid, $bid]);
    $obj = $st->fetch();
    if (!$obj) { echo json_encode(['ok' => false, 'error' => 'Object not found']); exit; }

    try {
        $minio = storage_minio_for($bucket['region']);
        $url   = $minio->presignedUrl($bucket['name'], $obj['object_key'], $expires);
        echo json_encode(['ok' => true, 'url' => $url, 'expires_in' => $expires]); exit;
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit;
    }
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);