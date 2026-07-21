<?php
/**
 * includes/storage.php
 * Object storage helpers — MinIO backed, multi-region.
 *
 * Each region in `storage_regions` table has its own MinIO server.
 * Admin adds regions via Admin → Storage → Regions.
 */
declare(strict_types=1);

require_once __DIR__ . '/../providers/minio/client.php';

// ── Get all active regions ────────────────────────────────────
function storage_get_regions(): array
{
    try {
        return db()->query(
            "SELECT * FROM storage_regions WHERE is_active=1 ORDER BY sort_order, id"
        )->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}

// ── Get single region by slug ─────────────────────────────────
function storage_get_region(string $slug): ?array
{
    try {
        $st = db()->prepare('SELECT * FROM storage_regions WHERE slug=? AND is_active=1 LIMIT 1');
        $st->execute([$slug]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

// ── Get MinIO client for a specific region ────────────────────
function storage_minio_for(string $region_slug): MinioAdminClient
{
    $region = storage_get_region($region_slug);
    if (!$region) {
        throw new RuntimeException("Storage region '{$region_slug}' not found or inactive.");
    }
    return new MinioAdminClient(
        $region['minio_endpoint'],
        $region['minio_admin_key'],
        $region['minio_admin_secret'],
        $region['slug']
    );
}

// ── Get MinIO client (legacy — uses first active region) ──────
function storage_minio(): MinioAdminClient
{
    $regions = storage_get_regions();
    if (empty($regions)) {
        throw new RuntimeException('No storage regions configured. Go to Admin → Storage → Regions.');
    }
    $r = $regions[0];
    return new MinioAdminClient(
        $r['minio_endpoint'],
        $r['minio_admin_key'],
        $r['minio_admin_secret'],
        $r['slug']
    );
}

// ── Public S3 endpoint for a bucket ──────────────────────────
function storage_endpoint(string $bucket_name, string $region_slug = ''): string
{
    $regions = storage_get_regions();
    $base = '';
    
    if ($region_slug) {
        $region = storage_get_region($region_slug);
        if ($region) $base = rtrim($region['s3_public_endpoint'], '/');
    }
    if (!$base && !empty($regions)) {
        $base = rtrim($regions[0]['s3_public_endpoint'], '/');
    }
    
    // Virtual-hosted style: bucket.s3.greathost.in
    // base = https://s3.greathost.in
    // result = https://my-bucket.s3.greathost.in
    $parsed = parse_url($base);
    return $parsed['scheme'] . '://' . $bucket_name . '.' . $parsed['host'];
    //return $parsed['scheme'] . '://' . $parsed['host'];
}

// ── Check if at least one region is configured ────────────────
function storage_is_configured(): bool
{
    try {
        $count = db()->query(
            "SELECT COUNT(*) FROM storage_regions WHERE is_active=1"
        )->fetchColumn();
        return (int)$count > 0;
    } catch (Throwable $e) { return false; }
}

// ── Create bucket on MinIO + DB record ───────────────────────
function storage_create_bucket(int $user_id, int $plan_id, string $bucket_name, string $region_slug, string $currency): array
{
    $region = storage_get_region($region_slug);
    if (!$region) throw new RuntimeException("Region '{$region_slug}' not found.");

    $minio = storage_minio_for($region_slug);

    // 1. Create actual bucket on MinIO
    if (!$minio->createBucket($bucket_name)) {
        throw new RuntimeException("MinIO: Failed to create bucket '{$bucket_name}'.");
    }
    
    $minio->setBucketPublic($bucket_name, true);

    // 2. Create MinIO user + bucket-scoped policy
    //    This creates a REAL user in MinIO so the credentials actually work
    try {
    $keys = $minio->createServiceAccount($bucket_name);
} catch (Throwable $e) {

    // rollback bucket
    try {
        $minio->deleteBucket($bucket_name);
    } catch (Throwable $e2) {}

    throw $e;
}

    // 3. Get plan pricing
    $plan = db()->prepare('SELECT * FROM storage_plans WHERE id=? LIMIT 1');
    $plan->execute([$plan_id]);
    $plan = $plan->fetch();
    if (!$plan) throw new RuntimeException('Storage plan not found.');

    $price_hr = $currency === 'INR'
        ? round((float)$plan['price_inr'] / 730, 8)
        : round((float)$plan['price_usd'] / 730, 8);

    $endpoint = storage_endpoint($bucket_name, $region_slug);

    // 4. Save to DB
    db()->prepare(
        'INSERT INTO storage_buckets
         (user_id, plan_id, name, display_name, region, status,
          access_key, secret_key, endpoint_url, currency,
          price_hourly, price_monthly, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
    )->execute([
        $user_id, $plan_id, $bucket_name, $bucket_name,
        $region_slug, 'active',
        $keys['access_key'], $keys['secret_key'],
        $endpoint, $currency,
        $price_hr,
        round($price_hr * 730, $currency === 'INR' ? 2 : 4),
    ]);

    $new_id = (int)db()->lastInsertId();

    // 5. Default API key record
    db()->prepare(
        'INSERT INTO storage_api_keys (bucket_id, label, access_key, secret_key, permissions)
         VALUES (?,?,?,?,?)'
    )->execute([$new_id, 'Default', $keys['access_key'], $keys['secret_key'], 'read,write,delete']);

    return ['id' => $new_id, 'access_key' => $keys['access_key'], 'secret_key' => $keys['secret_key']];
}

// ── Delete bucket from MinIO + mark deleted in DB ────────────
function storage_delete_bucket(int $bucket_id, int $user_id): void
{
    $bucket = storage_get_bucket($bucket_id, $user_id);
    if (!$bucket) throw new RuntimeException('Bucket not found.');

    try {
        $minio = storage_minio_for($bucket['region']);
        $minio->deleteServiceAccount($bucket['access_key'], $bucket['name']);
        $minio->deleteBucket($bucket['name']);
    } catch (Throwable $e) {
        error_log('[storage-delete] ' . $e->getMessage());
    }

    db()->prepare("UPDATE storage_buckets SET status='deleted', deleted_at=NOW() WHERE id=?")
       ->execute([$bucket_id]);
}

// ── Sync usage from MinIO ─────────────────────────────────────
function storage_sync_usage(int $bucket_id, string $bucket_name, string $region_slug): float
{
    try {
        $minio = storage_minio_for($region_slug);
        $usage = $minio->getBucketUsage($bucket_name);
        $gb    = round($usage['size_bytes'] / (1024 ** 3), 6);
        db()->prepare('UPDATE storage_buckets SET used_gb=? WHERE id=?')->execute([$gb, $bucket_id]);
        return $gb;
    } catch (Throwable $e) {
        error_log('[storage-usage] ' . $e->getMessage());
        return 0.0;
    }
}

// ── Get bucket with permission check ─────────────────────────
function storage_get_bucket(int $bucket_id, int $user_id, bool $admin = false): ?array
{
    try {
        $st = db()->prepare(
            'SELECT b.*, p.name as plan_name, p.storage_gb as plan_gb, p.bandwidth_gb as plan_bw
             FROM storage_buckets b
             JOIN storage_plans p ON p.id = b.plan_id
             WHERE b.id=? AND b.deleted_at IS NULL' . ($admin ? '' : ' AND b.user_id=?')
        );
        $params = $admin ? [$bucket_id] : [$bucket_id, $user_id];
        $st->execute($params);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

// ── Helpers ───────────────────────────────────────────────────
function storage_valid_bucket_name(string $name): bool
{
    return (bool)preg_match('/^[a-z0-9][a-z0-9\-]{1,61}[a-z0-9]$/', $name);
}

function storage_pct(float $used_gb, int $total_gb): float
{
    if ($total_gb <= 0) return 0;
    return min(100, round(($used_gb / $total_gb) * 100, 1));
}

function fmt_bytes(float $bytes, int $precision = 2): string
{
    if ($bytes === 0.0) return '0 B';
    $units = ['B','KB','MB','GB','TB'];
    $pow   = min((int)floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
}
