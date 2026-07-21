<?php
/**
 * providers/hetzner/fetch-regions.php
 *
 * Fetches regions (locations) + OS images from Hetzner API.
 * Called via AJAX from admin panel "Fetch Regions" button.
 * Belongs here because it's Hetzner-specific.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/currency.php';
require_once __DIR__ . '/../../includes/admin.php';
require_admin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok'=>false,'error'=>'POST required.']); exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$pid  = (int)($body['provider_id'] ?? 0);
$csrf = $body['csrf_token'] ?? $body['csrf'] ?? '';

if (!verify_csrf($csrf)) {
    echo json_encode(['ok'=>false,'error'=>'Invalid token.']); exit;
}

$prov = get_provider($pid);
if (!$prov) {
    echo json_encode(['ok'=>false,'error'=>'Provider not found.']); exit;
}

$api_key = trim($prov['api_key'] ?? '');
if (!$api_key) {
    echo json_encode(['ok'=>false,'error'=>'No API key set. Edit provider first.']); exit;
}

try {
    require_once __DIR__ . '/bootstrap.php';
    CloudProvider::reset();
    $cloud = new CloudProvider($api_key);

    // Fetch & save regions
    $regions = $cloud->catalog->regions();
    upsert_region_catalog($pid, $regions);

    // Fetch & save OS images
    $images = $cloud->catalog->images();
    upsert_image_catalog($pid, $images);

    echo json_encode([
        'ok'      => true,
        'regions' => array_map(fn($r) => [
            'slug'         => $r['slug'],
            'city'         => $r['city'],
            'country'      => $r['country'],
            'country_code' => $r['country_code'],
        ], $regions),
        'images'  => count($images),
        'message' => count($regions).' regions and '.count($images).' OS images saved.',
    ]);

} catch (Throwable $e) {
    error_log('[hetzner/fetch-regions] '.$e->getMessage());
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
