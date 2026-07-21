<?php
/**
 * cron/storage-billing.php
 * Hourly billing + usage sync for MinIO-backed object storage.
 *
 * Crontab: 0 * * * * /usr/local/bin/php /home/cloudgreat/public_html/cron/storage-billing.php
 */


require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/mailer_invoice.php';

if (!function_exists('cron_log')) {
    function cron_log(string $msg): void {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
        flush();
    }
}

cron_log('====== Storage billing cron started ======');

if (!storage_is_configured()) {
    cron_log('SKIP: MinIO not configured in settings.');
    exit;
}

$active = db()->query(
    "SELECT b.*, u.wallet_balance, u.currency, u.email, u.full_name, u.username
     FROM storage_buckets b JOIN users u ON u.id=b.user_id
     WHERE b.status='active' AND b.deleted_at IS NULL"
)->fetchAll();

cron_log('Active buckets: '.count($active));
$billed=$suspended=$errors=0;

foreach ($active as $b) {
    $uid=$b['user_id']; $bid=$b['id']; $amount=(float)$b['price_hourly'];
    $currency=$b['currency']; $balance=(float)$b['wallet_balance'];
    $sym=$currency==='INR'?'₹':'$';

    // Skip if billed this hour
    if ($b['last_billed_at'] && (time()-strtotime($b['last_billed_at'])) < 3500) {
        cron_log("  SKIP bucket #{$bid} (already billed)"); continue;
    }

    // Sync usage from MinIO every billing cycle
    try { storage_sync_usage($bid, $b['name'], $b['currency'] ? $b['region'] : ''); } catch(Throwable $e) {}

    if ($balance < $amount) {
        cron_log("  SUSPEND bucket #{$bid} ({$b['name']}) — low balance");
        db()->prepare("UPDATE storage_buckets SET status='suspended', suspended_at=NOW() WHERE id=?")->execute([$bid]);
        // Make bucket private so public URLs stop working
        try {
            $minio_sus = storage_minio_for($b['region']);
            $minio_sus->setBucketPublic($b['name'], false);
            cron_log("  PRIVATE set for bucket {$b['name']}");
        } catch(Throwable $e) {
            error_log('[storage-billing suspend] setBucketPublic(false) failed: '.$e->getMessage());
        }
        db()->prepare("INSERT INTO storage_billing (bucket_id,user_id,amount,currency,status,note) VALUES (?,?,?,?,'failed','Insufficient balance')")->execute([$bid,$uid,$amount,$currency]);
        try {
            require_once __DIR__.'/../includes/mailer.php';
            send_mail(to:$b['email'],to_name:$b['full_name']?:$b['username'],
                subject:APP_NAME.' — Bucket Suspended: '.$b['name'],
                html_body:'<p>Your bucket <strong>'.htmlspecialchars($b['name']).'</strong> has been suspended due to insufficient balance.</p><p>Balance: <strong>'.$sym.number_format($balance,2).'</strong></p><p><a href="'.BASE_URL.'/billing.php">Add Funds →</a></p>');
        } catch(Throwable $e) {}
        $suspended++; continue;
    }
    
function wallet_deduct(int $userId, float $amount, string $description, string $refType = 'server_billing', ?int $refId = null): bool {
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

//     $ok = db()->prepare("
//     UPDATE users
//     SET wallet_balance = wallet_balance - ?
//     WHERE id = ? AND wallet_balance >= ?
// ")->execute([$amount, $uid, $amount]);
    $ok = wallet_deduct($uid, $amount, 'Hourly billing — Bucket: '.$b['name'], 'bucket_billing', $bid);
    if ($ok) {
        db()->prepare("UPDATE storage_buckets SET last_billed_at=NOW() WHERE id=?")->execute([$bid]);
        db()->prepare("INSERT INTO storage_billing (bucket_id,user_id,amount,currency,status) VALUES (?,?,?,?,'success')")->execute([$bid,$uid,$amount,$currency]);
        cron_log("  BILLED bucket #{$bid} — {$sym}{$amount}");
        $billed++;
    } else { $errors++; }
}

// Auto-resume if topped up
$suspended_list = db()->query(
    "SELECT b.*, u.wallet_balance FROM storage_buckets b JOIN users u ON u.id=b.user_id WHERE b.status='suspended' AND b.deleted_at IS NULL"
)->fetchAll();

foreach ($suspended_list as $b) {
    if ((float)$b['wallet_balance'] >= (float)$b['price_hourly']*5) {
        db()->prepare("UPDATE storage_buckets SET status='active',suspended_at=NULL WHERE id=?")->execute([$b['id']]);
        // Restore public access
        try {
            $minio_res = storage_minio_for($b['region']);
            $minio_res->setBucketPublic($b['name'], true);
            cron_log("  PUBLIC restored for bucket {$b['name']}");
        } catch(Throwable $e) {
            error_log('[storage-billing resume] setBucketPublic(true) failed: '.$e->getMessage());
        }
        cron_log("  RESUMED bucket #{$b['id']} ({$b['name']})");
    }
}

cron_log("====== Done. Billed:{$billed} | Suspended:{$suspended} | Errors:{$errors} ======");
