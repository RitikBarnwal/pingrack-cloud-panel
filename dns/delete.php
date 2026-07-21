<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/dns.php';
require_login();

$user = current_user();
$uid  = (int)$user['id'];
$zid  = (int)($_GET['id'] ?? 0);
$zone = dns_get_zone($zid, $uid);

if ($zone) {
    try {
        if ($zone['cf_zone_id']) dns_delete_zone($zone['cf_zone_id']);
    } catch (Throwable $e) {}
    db()->prepare('DELETE FROM dns_records WHERE zone_id=?')->execute([$zid]);
    db()->prepare('UPDATE dns_zones SET deleted_at=NOW() WHERE id=?')->execute([$zid]);
}
header('Location: ' . BASE_URL . '/dns.php');
exit;
