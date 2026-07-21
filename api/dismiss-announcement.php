<?php
/**
 * api/dismiss-announcement.php
 * Records that a user dismissed an announcement (so it never shows again).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false]); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?? [];
if (!verify_csrf($body['csrf'] ?? '')) { echo json_encode(['ok'=>false,'error'=>'Invalid token']); exit; }

$ann_id = (int)($body['id'] ?? 0);
$uid    = (int)current_user()['id'];

if (!$ann_id) { echo json_encode(['ok'=>false]); exit; }

// Verify announcement exists and has dismiss_once=1
try {
    $st = db()->prepare('SELECT id, dismiss_once FROM announcements WHERE id=? AND is_active=1 LIMIT 1');
    $st->execute([$ann_id]);
    $ann = $st->fetch();

    if (!$ann || !$ann['dismiss_once']) {
        echo json_encode(['ok'=>false]); exit;
    }

    db()->prepare(
        'INSERT IGNORE INTO announcement_dismissals (announcement_id, user_id) VALUES (?,?)'
    )->execute([$ann_id, $uid]);

    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
