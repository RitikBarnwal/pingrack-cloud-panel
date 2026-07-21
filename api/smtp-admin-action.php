<?php
// api/smtp-admin-action.php — Admin SMTP (SES) AJAX actions
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/smtp-providers.php';
require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok'=>false,'error'=>'Method not allowed']); exit;
}
$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';
if (!verify_csrf($input['csrf'] ?? '')) {
    echo json_encode(['ok'=>false,'error'=>'Invalid CSRF']); exit;
}

// ── Save Plan ─────────────────────────────────────────────────
if ($action === 'save_plan') {
    $id    = (int)($input['id'] ?? 0);
    $name  = trim($input['name'] ?? '');
    $quota = max(1,(int)($input['emails_month'] ?? 10000));
    $p_inr = (float)($input['price_inr'] ?? 0);
    $p_usd = (float)($input['price_usd'] ?? 0);
    $days  = max(1,(int)($input['duration_days'] ?? 30));
    $feats = json_encode(array_values(array_filter(array_map('trim', explode("\n", $input['features'] ?? '')))));
    $feat  = (int)($input['is_featured'] ?? 0);
    $act   = (int)($input['is_active']   ?? 1);
    $sort  = (int)($input['sort_order']  ?? 0);
    if (!$name) { echo json_encode(['ok'=>false,'error'=>'Name required']); exit; }
    if ($id) {
        db()->prepare("UPDATE smtp_plans SET name=?,emails_month=?,price_inr=?,price_usd=?,duration_days=?,features=?,is_featured=?,is_active=?,sort_order=?,updated_at=NOW() WHERE id=?")
           ->execute([$name,$quota,$p_inr,$p_usd,$days,$feats,$feat,$act,$sort,$id]);
    } else {
        db()->prepare("INSERT INTO smtp_plans (name,emails_month,price_inr,price_usd,duration_days,features,is_featured,is_active,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())")
           ->execute([$name,$quota,$p_inr,$p_usd,$days,$feats,$feat,$act,$sort]);
        $id = (int)db()->lastInsertId();
    }
    echo json_encode(['ok'=>true,'id'=>$id]); exit;
}

if ($action === 'toggle_plan') {
    db()->prepare("UPDATE smtp_plans SET is_active=? WHERE id=?")->execute([(int)($input['is_active']??0),(int)($input['id']??0)]);
    echo json_encode(['ok'=>true]); exit;
}

if ($action === 'delete_plan') {
    $id = (int)($input['id']??0);
    $c  = db()->prepare("SELECT COUNT(*) FROM smtp_orders WHERE plan_id=? AND status IN ('pending','active')");
    $c->execute([$id]);
    if ((int)$c->fetchColumn() > 0) { echo json_encode(['ok'=>false,'error'=>'Active orders on this plan']); exit; }
    db()->prepare("DELETE FROM smtp_plans WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}

// ── Manually update order credentials ────────────────────────
if ($action === 'update_order') {
    $id     = (int)($input['id'] ?? 0);
    $status = $input['status'] ?? '';
    $allowed = ['pending','active','suspended','expired','cancelled'];
    if (!$id || !in_array($status, $allowed)) { echo json_encode(['ok'=>false,'error'=>'Invalid']); exit; }

    $activated_at = null;
    if ($status === 'active') {
        $cur = db()->prepare("SELECT activated_at FROM smtp_orders WHERE id=?");
        $cur->execute([$id]); $row=$cur->fetch();
        $activated_at = $row['activated_at'] ?: date('Y-m-d H:i:s');
    }

    db()->prepare(
        "UPDATE smtp_orders SET status=?,smtp_host=?,smtp_port=?,smtp_username=?,smtp_password=?,
         aws_access_key=?,aws_secret_key=?,aws_region=?,iam_username=?,notes=?,
         expires_at=COALESCE(?,expires_at),activated_at=COALESCE(?,activated_at),updated_at=NOW()
         WHERE id=?"
    )->execute([
        $status,
        $input['smtp_host']      ?? null, $input['smtp_port']    ?? 587,
        $input['smtp_username']  ?? null, $input['smtp_password'] ?? null,
        $input['aws_access_key'] ?? null, $input['aws_secret_key'] ?? null,
        $input['aws_region']     ?? 'ap-south-1',
        $input['iam_username']   ?? null,
        $input['notes']          ?? null,
        !empty($input['expires_at']) ? date('Y-m-d H:i:s', strtotime($input['expires_at'])) : null,
        $activated_at, $id,
    ]);
    echo json_encode(['ok'=>true]); exit;
}

// ── Force provision via AWS API ───────────────────────────────
if ($action === 'provision_order') {
    $id  = (int)($input['id'] ?? 0);
    $st  = db()->prepare("SELECT o.*, u.email, u.full_name, p.emails_month, p.duration_days FROM smtp_orders o JOIN users u ON u.id=o.user_id JOIN smtp_plans p ON p.id=o.plan_id WHERE o.id=?");
    $st->execute([$id]); $row=$st->fetch();
    if (!$row) { echo json_encode(['ok'=>false,'error'=>'Order not found']); exit; }

    $result = smtp_auto_provision($row, ['emails_month'=>$row['emails_month']]);
    if ($result['ok']) {
        db()->prepare(
            "UPDATE smtp_orders SET status='active',smtp_host=?,smtp_port=?,smtp_username=?,smtp_password=?,
             aws_access_key=?,aws_secret_key=?,aws_region=?,iam_user_arn=?,iam_username=?,
             auto_activated=1,activated_at=NOW(),updated_at=NOW() WHERE id=?"
        )->execute([
            $result['smtp_host'],$result['smtp_port'],
            $result['smtp_username'],$result['smtp_password'],
            $result['aws_access_key'],$result['aws_secret_key'],
            $result['aws_region'],$result['iam_user_arn'],
            $result['iam_username'],$id,
        ]);
        echo json_encode(['ok'=>true,'message'=>'Provisioned via AWS IAM']);
    } else {
        echo json_encode(['ok'=>false,'error'=>$result['error']??'Failed']);
    }
    exit;
}

// ── Cancel + deprovision (delete IAM user) ────────────────────
if ($action === 'cancel_order') {
    $id  = (int)($input['id'] ?? 0);
    $st  = db()->prepare("SELECT * FROM smtp_orders WHERE id=?");
    $st->execute([$id]); $row=$st->fetch();
    if (!$row) { echo json_encode(['ok'=>false,'error'=>'Not found']); exit; }
    try { smtp_deprovision($row); } catch(Throwable $e) { error_log('deprovision: '.$e->getMessage()); }
    db()->prepare("UPDATE smtp_orders SET status='cancelled',updated_at=NOW() WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}

// ── Save Settings ─────────────────────────────────────────────
if ($action === 'save_settings') {
    $keys = ['smtp_ses_access_key','smtp_ses_secret_key','smtp_ses_region','smtp_auto_activate','smtp_module_enabled'];
    foreach ($keys as $k) {
        if (isset($input[$k])) set_setting($k, trim($input[$k]));
    }
    echo json_encode(['ok'=>true]); exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
