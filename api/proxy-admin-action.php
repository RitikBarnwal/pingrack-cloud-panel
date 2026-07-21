<?php
// api/proxy-admin-action.php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/proxy_providers.php';
require_login(); require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'error'=>'Method not allowed']); exit; }
$in   = json_decode(file_get_contents('php://input'), true) ?: [];
$csrf = $in['csrf'] ?? '';
if (!verify_csrf($csrf)) { echo json_encode(['success'=>false,'error'=>'Invalid CSRF']); exit; }

$action = $in['action'] ?? '';

// ── SYNC ORDER (from Manage modal) ──────────────────────────────
if ($action === 'sync_order') {
    $order_id  = (int)($in['order_id'] ?? 0);
    $prov_id   = (int)($in['provider_id'] ?? 0);
    $poid      = trim($in['provider_order_id'] ?? '');
    if (!$order_id || !$prov_id || !$poid) { echo json_encode(['success'=>false,'error'=>'Missing fields']); exit; }

    // Save provider_order_id + provider_id first
    db()->prepare("UPDATE proxy_orders SET provider_id=?, provider_order_id=?, updated_at=NOW() WHERE id=?")
        ->execute([$prov_id, $poid, $order_id]);

    // Fetch order row
    $st = db()->prepare("SELECT po.*, pp.slug AS provider_slug, pp.api_key, pp.api_secret, pp.api_base_url, pp.name AS provider_name FROM proxy_orders po JOIN proxy_providers pp ON pp.id=po.provider_id WHERE po.id=?");
    $st->execute([$order_id]); $order = $st->fetch();
    if (!$order) { echo json_encode(['success'=>false,'error'=>'Order not found']); exit; }

    $result = sync_proxy_order($order);
    if ($result['ok']) {
        // Re-fetch normalised data to return to frontend
        $nd = db()->prepare("SELECT username,password,gateway_host,gateway_port,proxy_list,whitelist_ip,provider_status,expires_at FROM proxy_orders WHERE id=?");
        $nd->execute([$order_id]); $ndata = $nd->fetch();
        echo json_encode(['success'=>true,'msg'=>$result['msg'],'data'=>$ndata]);
    } else {
        echo json_encode(['success'=>false,'error'=>$result['msg']]);
    }
    exit;
}

// ── SYNC BY ID (inline sync button in table) ────────────────────
if ($action === 'sync_order_by_id') {
    $order_id = (int)($in['order_id'] ?? 0);
    $st = db()->prepare("SELECT po.*, pp.slug AS provider_slug, pp.api_key, pp.api_secret, pp.api_base_url, pp.name AS provider_name FROM proxy_orders po JOIN proxy_providers pp ON pp.id=po.provider_id WHERE po.id=?");
    $st->execute([$order_id]); $order = $st->fetch();
    if (!$order) { echo json_encode(['success'=>false,'error'=>'Order not found']); exit; }
    $result = sync_proxy_order($order);
    echo json_encode(['success'=>$result['ok'],'msg'=>$result['msg'],'error'=>$result['ok']?null:$result['msg']]);
    exit;
}

// ── UPDATE ORDER (manual save) ──────────────────────────────────
if ($action === 'update_order') {
    $id   = (int)($in['order_id'] ?? 0);
    if (!$id) { echo json_encode(['success'=>false,'error'=>'Missing order ID']); exit; }

    $status     = $in['status'] ?? '';
    $expires_at = !empty($in['expires_at']) ? date('Y-m-d H:i:s', strtotime($in['expires_at'])) : null;
    $proxy_list = trim($in['proxy_list'] ?? '') ?: null;
    $gw_host    = trim($in['gateway_host'] ?? '') ?: null;
    $gw_port    = !empty($in['gateway_port']) ? (int)$in['gateway_port'] : null;
    $username   = trim($in['username'] ?? '') ?: null;
    $password   = trim($in['password'] ?? '') ?: null;
    $notes      = trim($in['notes'] ?? '') ?: null;
    $prov_id    = (int)($in['provider_id'] ?? 0) ?: null;
    $poid       = trim($in['provider_order_id'] ?? '') ?: null;

    $allowed = ['pending','active','expired','cancelled','suspended'];
    if ($status && !in_array($status, $allowed)) { echo json_encode(['success'=>false,'error'=>'Invalid status']); exit; }

    $cur = db()->prepare("SELECT status,activated_at,duration_days FROM proxy_orders WHERE id=?");
    $cur->execute([$id]); $cur = $cur->fetch();
    $activated_at = $cur['activated_at'];
    if ($status === 'active' && $cur['status'] !== 'active') {
        $activated_at = date('Y-m-d H:i:s');
        if (!$expires_at) $expires_at = date('Y-m-d H:i:s', strtotime("+{$cur['duration_days']} days"));
    }

    db()->prepare(
        "UPDATE proxy_orders SET
           status=COALESCE(NULLIF(?,''),status), expires_at=COALESCE(?,expires_at),
           username=COALESCE(?,username), password=COALESCE(?,password),
           gateway_host=COALESCE(?,gateway_host), gateway_port=COALESCE(?,gateway_port),
           proxy_list=COALESCE(?,proxy_list), notes=COALESCE(?,notes),
           provider_id=COALESCE(?,provider_id), provider_order_id=COALESCE(?,provider_order_id),
           activated_at=COALESCE(?,activated_at), updated_at=NOW()
         WHERE id=?"
    )->execute([$status,$expires_at,$username,$password,$gw_host,$gw_port,$proxy_list,$notes,$prov_id,$poid,$activated_at,$id]);

    if ($username && $password) {
        db()->prepare("INSERT INTO proxy_credentials (order_id,username,password_plain) VALUES (?,?,?) ON DUPLICATE KEY UPDATE username=VALUES(username),password_plain=VALUES(password_plain),updated_at=NOW()")
            ->execute([$id,$username,$password]);
    }
    echo json_encode(['success'=>true]); exit;
}

// ── UPDATE WHITELIST IP ─────────────────────────────────────────
if ($action === 'update_whitelist') {
    $order_id = (int)($in['order_id'] ?? 0);
    $prov_id  = (int)($in['provider_id'] ?? 0);
    $poid     = trim($in['provider_order_id'] ?? '');
    $ip       = trim($in['whitelist_ip'] ?? '');

    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        echo json_encode(['success'=>false,'error'=>'Invalid IPv4 address']); exit;
    }

    $st = db()->prepare("SELECT * FROM proxy_providers WHERE id=? AND is_active=1");
    $st->execute([$prov_id]); $prov = $st->fetch();
    if (!$prov) { echo json_encode(['success'=>false,'error'=>'Provider not found']); exit; }

    $api    = new ProxyProviderAPI($prov);
    $result = $api->updateWhitelistIp($poid, $ip);

    if (($result['status'] ?? '') === 'OK') {
        db()->prepare("UPDATE proxy_orders SET whitelist_ip=?, updated_at=NOW() WHERE id=?")
            ->execute([$ip, $order_id]);
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'error'=>$result['message']??'Provider returned error']);
    }
    exit;
}

// ── TEST PROVIDER ───────────────────────────────────────────────
if ($action === 'test_provider') {
    $id = (int)($in['id'] ?? 0);

    $st = db()->prepare("SELECT * FROM proxy_providers WHERE id=?");
    $st->execute([$id]);
    $prov = $st->fetch();

    if (!$prov) {
        echo json_encode(['success'=>false,'error'=>'Provider not found']);
        exit;
    }

    if (empty($prov['api_key'])) {
        echo json_encode(['success'=>false,'error'=>'No API key configured']);
        exit;
    }

    $api    = new ProxyProviderAPI($prov);
    $result = $api->getAccountInfo();

    if (($result['status'] ?? '') === 'OK') {

        $balance = $result['balance_usd'] ?? $result['balance'] ?? null;

        db()->prepare("
            UPDATE proxy_providers
            SET account_balance=?,
                last_synced_at=NOW()
            WHERE id=?
        ")->execute([$balance, $id]);

        echo json_encode([
            'success'=>true,
            'balance'=>$balance
        ]);

    } else {

        echo json_encode([
            'success'=>false,
            'error'=>$result['message'] ?? 'Connection failed'
        ]);
    }

    exit;
}

// ── SAVE PROVIDER ───────────────────────────────────────────────
if ($action === 'save_provider') {
    $id      = (int)($in['id'] ?? 0);
    $api_key = trim($in['api_key']      ?? '');
    $secret  = trim($in['api_secret']   ?? '');
    $ips     = trim($in['whitelisted_ips'] ?? '');
    $notes   = trim($in['notes']        ?? '');

    if (!$id) { echo json_encode(['success'=>false,'error'=>'Missing provider ID']); exit; }

    db()->prepare(
        "UPDATE proxy_providers SET api_key=?, api_secret=?, whitelisted_ips=?, notes=?, updated_at=NOW() WHERE id=?"
    )->execute([$api_key?:null, $secret?:null, $ips?:null, $notes?:null, $id]);

    echo json_encode(['success'=>true]); exit;
}

// ── SAVE PLAN ───────────────────────────────────────────────────
if ($action === 'save_plan') {
    $id        = (int)($in['id'] ?? 0);
    $name      = trim($in['name'] ?? '');
    $slug      = trim($in['slug'] ?? '');
    $type      = $in['proxy_type']   ?? 'datacenter';
    $protocol  = $in['protocol']     ?? 'http';
    $prov_id   = (int)($in['provider_id'] ?? 1);
    $price_inr = (float)($in['price_inr'] ?? 0);
    $price_usd = (float)($in['price_usd'] ?? 0);
    $bw        = (float)($in['bandwidth_gb'] ?? 0);
    $days      = (int)($in['duration_days'] ?? 30);
    $max_ips   = (int)($in['max_ips'] ?? 1);
    $rotation  = $in['rotation'] ?? 'rotating';
    $threads   = (int)($in['threads'] ?? 100);
    $sort      = (int)($in['sort_order'] ?? 0);
    $featured  = (int)($in['is_featured'] ?? 0);
    $features  = json_encode(array_values($in['features'] ?? []));
    $locations = json_encode(array_values($in['locations'] ?? []));

    if (!$name || !$slug) { echo json_encode(['success'=>false,'error'=>'Name and slug required']); exit; }

    try {
        if ($id) {
            db()->prepare("UPDATE proxy_plans SET name=?,slug=?,proxy_type=?,protocol=?,provider_id=?,bandwidth_gb=?,duration_days=?,max_ips=?,rotation=?,threads=?,price_inr=?,price_usd=?,sort_order=?,is_featured=?,features=?,locations=?,updated_at=NOW() WHERE id=?")
                ->execute([$name,$slug,$type,$protocol,$prov_id,$bw,$days,$max_ips,$rotation,$threads,$price_inr,$price_usd,$sort,$featured,$features,$locations,$id]);
        } else {
            db()->prepare("INSERT INTO proxy_plans (name,slug,proxy_type,protocol,provider_id,bandwidth_gb,duration_days,max_ips,rotation,threads,price_inr,price_usd,sort_order,is_featured,features,locations,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)")
                ->execute([$name,$slug,$type,$protocol,$prov_id,$bw,$days,$max_ips,$rotation,$threads,$price_inr,$price_usd,$sort,$featured,$features,$locations]);
        }
        echo json_encode(['success'=>true]);
    } catch(Throwable $e) {
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

if ($action === 'toggle_plan') {
    db()->prepare("UPDATE proxy_plans SET is_active=? WHERE id=?")->execute([(int)($in['is_active']??0),(int)($in['id']??0)]);
    echo json_encode(['success'=>true]); exit;
}

if ($action === 'delete_plan') {
    $id = (int)($in['id'] ?? 0);
    $cnt = db()->prepare("SELECT COUNT(*) FROM proxy_orders WHERE plan_id=? AND status IN ('pending','active')");
    $cnt->execute([$id]);
    if ($cnt->fetchColumn() > 0) { echo json_encode(['success'=>false,'error'=>'Active orders exist for this plan']); exit; }
    db()->prepare("DELETE FROM proxy_plans WHERE id=?")->execute([$id]);
    echo json_encode(['success'=>true]); exit;
}

echo json_encode(['success'=>false,'error'=>'Unknown action: '.$action]);
