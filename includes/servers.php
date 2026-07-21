<?php
/**
 * includes/servers.php
 *
 * Server DB helper functions.
 * All server data comes from OUR database — not directly from provider.
 * Provider ID is stored internally and never shown to user.
 */

declare(strict_types=1);

/* ── Currency helpers ──────────────────────────────────────── */

function eur_to_user(float $eur, string $currency): float
{
    return match ($currency) {
        'INR'  => round($eur * 92.5,  4),
        default => round($eur * 1.08, 6),
    };
}

function fmt_price(float $amount, string $currency, string $sym): string
{
    return $sym . number_format($amount, $currency === 'INR' ? 2 : 4);
}

/* ── Server CRUD (DB) ──────────────────────────────────────── */

/**
 * Get all servers for a user (non-deleted).
 */
function get_user_servers(int $userId): array
{
    $st = db()->prepare(
        'SELECT * FROM servers WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at DESC'
    );
    $st->execute([$userId]);
    return $st->fetchAll() ?: [];
}

/**
 * Get single server — verifies ownership.
 */
function get_server(int $serverId, int $userId): ?array
{
    $st = db()->prepare(
        'SELECT * FROM servers WHERE id = ? AND user_id = ? AND deleted_at IS NULL LIMIT 1'
    );
    $st->execute([$serverId, $userId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Create server record in DB.
 * Returns new server ID.
 */
function db_create_server(int $userId, array $data): int
{
    $st = db()->prepare(
        'INSERT INTO servers
         (user_id, provider_id, source_provider_id, name, status, plan_slug, image_slug, region_slug,
          vcpu, ram_gb, disk_gb, ipv4, ipv6, os_label, region_label, region_flag,
          price_hourly, price_monthly, currency, root_password,
          total_bandwidth_gb, used_bandwidth_gb)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $st->execute([
        $userId,
        $data['provider_id']        ?? null,
        $data['source_provider_id'] ?? null,
        $data['name'],
        $data['status']        ?? 'provisioning',
        $data['plan_slug'],
        $data['image_slug'],
        $data['region_slug'],
        $data['vcpu']          ?? 0,
        $data['ram_gb']        ?? 0,
        $data['disk_gb']       ?? 0,
        $data['ipv4']          ?? null,
        $data['ipv6']          ?? null,
        $data['os_label']      ?? '',
        $data['region_label']  ?? '',
        $data['region_flag']   ?? 'de',
        $data['price_hourly']  ?? 0,
        $data['price_monthly'] ?? 0,
        $data['currency'],
        $data['root_password'] ?? null,
        (int)($data['total_bandwidth_gb']  ?? $data['bandwidth_gb'] ?? 0),
        (float)($data['used_bandwidth_gb'] ?? 0),
    ]);
    return (int) db()->lastInsertId();
}

/**
 * Update server fields after provisioning (IP, status etc.)
 */
function db_update_server(int $serverId, array $fields): void
{
    $allowed = ['status','ipv4','ipv6','provider_id','root_password','os_label',
                'total_bandwidth_gb','used_bandwidth_gb'];
    $set = [];
    $vals = [];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $fields)) {
            $set[]  = "`$k` = ?";
            $vals[] = $fields[$k];
        }
    }
    if (!$set) return;
    $vals[] = $serverId;
    db()->prepare('UPDATE servers SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
}

/**
 * Soft delete server record.
 */
function db_delete_server(int $serverId, int $userId): bool
{
    $st = db()->prepare(
        'UPDATE servers SET status = "deleted", deleted_at = NOW() WHERE id = ? AND user_id = ?'
    );
    $st->execute([$serverId, $userId]);
    return $st->rowCount() > 0;
}

/* ── Wallet helpers ────────────────────────────────────────── */

/**
 * Deduct from wallet and log transaction.
 * Returns false if insufficient balance.
 */
function wallet_deduct(int $userId, float $amount, string $description, string $refType = 'server_billing', ?int $refId = null): bool
{
    $db = db();
    $db->beginTransaction();
    try {
        // Lock row
        $st = $db->prepare('SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE');
        $st->execute([$userId]);
        $before = (float) $st->fetchColumn();

        if ($before < $amount) {
            $db->rollBack();
            return false;
        }

        $after = $before - $amount;

        $db->prepare('UPDATE users SET wallet_balance = ? WHERE id = ?')
           ->execute([$after, $userId]);

        $st2 = $db->prepare(
            'SELECT currency FROM users WHERE id = ? LIMIT 1'
        );
        $st2->execute([$userId]);
        $currency = $st2->fetchColumn() ?: 'USD';

        //if (strpos($description, 'Hourly billing — ') !== 0) {
        $db->prepare(
            'INSERT INTO transactions
             (user_id, type, amount, currency, description, ref_type, ref_id, balance_before, balance_after)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([$userId, 'debit', $amount, $currency, $description, $refType, $refId, $before, $after]);
        //}

        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('[wallet_deduct] ' . $e->getMessage());
        return false;
    }
}

/**
 * Credit wallet and log transaction.
 */
function wallet_credit(int $userId, float $amount, string $description, string $refType = 'topup', ?int $refId = null): bool
{
    $db = db();
    $db->beginTransaction();

    try {

        $st = $db->prepare('SELECT wallet_balance, currency FROM users WHERE id = ? FOR UPDATE');
        $st->execute([$userId]);

        $row = $st->fetch();

        if (!$row) {
            throw new Exception('User not found');
        }

        $before   = (float)$row['wallet_balance'];
        $currency = $row['currency'] ?? 'USD';
        $after    = $before + $amount;

        $db->prepare(
            'UPDATE users SET wallet_balance = ? WHERE id = ?'
        )->execute([$after, $userId]);

        $tx = $db->prepare(
            'INSERT INTO transactions
            (user_id, type, amount, currency, description, ref_type, ref_id, balance_before, balance_after)
            VALUES (?,?,?,?,?,?,?,?,?)'
        );

        $ok = $tx->execute([
            $userId,
            'credit',
            $amount,
            $currency,
            $description,
            $refType,
            $refId,
            $before,
            $after
        ]);

        if (!$ok) {
            $err = $tx->errorInfo();
            throw new Exception(json_encode($err));
        }

        $db->commit();
        return true;

    } catch (Throwable $e) {

        $db->rollBack();

        error_log(
            '[wallet_credit] ' .
            $e->getMessage()
        );

        return false;
    }
}

/* ── Action log ────────────────────────────────────────────── */

function log_server_action(int $serverId, int $userId, string $action, string $status = 'pending', ?int $providerActionId = null): int
{
    $st = db()->prepare(
        'INSERT INTO server_actions (server_id, user_id, action, status, provider_action_id)
         VALUES (?,?,?,?,?)'
    );
    $st->execute([$serverId, $userId, $action, $status, $providerActionId]);
    return (int) db()->lastInsertId();
}

function finish_server_action(int $actionId, string $status): void
{
    db()->prepare(
        'UPDATE server_actions SET status = ?, finished_at = NOW() WHERE id = ?'
    )->execute([$status, $actionId]);
}

/* ── Plan pricing (cached) ─────────────────────────────────── */

function get_cached_plans(): array
{
    $st = db()->query('SELECT * FROM plan_pricing WHERE is_active = 1 ORDER BY vcpu, ram_gb');
    return $st->fetchAll() ?: [];
}

function upsert_plan_cache(array $plans): void
{
    $st = db()->prepare(
        'INSERT INTO plan_pricing (slug, label, vcpu, ram_gb, disk_gb, cpu_type, price_hourly_eur)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           label=VALUES(label), vcpu=VALUES(vcpu), ram_gb=VALUES(ram_gb),
           disk_gb=VALUES(disk_gb), cpu_type=VALUES(cpu_type),
           price_hourly_eur=VALUES(price_hourly_eur), is_active=1'
    );
    foreach ($plans as $p) {
        $st->execute([
            $p['slug'], $p['label'], $p['vcpu'], $p['ram_gb'],
            $p['disk_gb'], $p['cpu_type'], $p['price_hourly_eur'],
        ]);
    }
}

/* ── Status display ────────────────────────────────────────── */

function server_status_badge(string $status): string
{
    return match ($status) {
        'running'      => '<span class="badge badge-green"><span class="s-dot dot-green"></span>Running</span>',
        'stopped'      => '<span class="badge badge-gray"><span class="s-dot dot-gray"></span>Stopped</span>',
        'provisioning' => '<span class="badge badge-blue"><span class="s-dot dot-blue dot-pulse"></span>Provisioning</span>',
        'starting'     => '<span class="badge badge-blue"><span class="s-dot dot-blue dot-pulse"></span>Starting</span>',
        'stopping'     => '<span class="badge badge-yellow"><span class="s-dot dot-yellow dot-pulse"></span>Stopping</span>',
        'rebuilding'   => '<span class="badge badge-yellow"><span class="s-dot dot-yellow dot-pulse"></span>Rebuilding</span>',
        'error'        => '<span class="badge badge-red"><span class="s-dot dot-red"></span>Error</span>',
        default        => '<span class="badge badge-gray">Unknown</span>',
    };
}
