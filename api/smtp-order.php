<?php
// api/smtp-order.php — Place SMTP order (domain required before credentials unlock)
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/smtp-providers.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok'=>false,'error'=>'Method not allowed']); exit;
}

$input   = json_decode(file_get_contents('php://input'), true) ?: [];
if (!verify_csrf($input['csrf'] ?? '')) {
    echo json_encode(['ok'=>false,'error'=>'Invalid CSRF']); exit;
}

$user     = current_user();
$currency = strtoupper($user['currency'] ?? 'INR');
$plan_id  = (int)($input['plan_id']  ?? 0);
$domain   = strtolower(trim($input['domain'] ?? ''));

// Validate plan
$st = db()->prepare("SELECT * FROM smtp_plans WHERE id=? AND is_active=1 LIMIT 1");
$st->execute([$plan_id]);
$plan = $st->fetch();
if (!$plan) { echo json_encode(['ok'=>false,'error'=>'Plan not found']); exit; }

// Validate domain
if (!$domain || !preg_match('/^([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
    echo json_encode(['ok'=>false,'error'=>'Enter a valid domain. Example: mail.yourdomain.com']); exit;
}

$price   = $currency === 'INR' ? (float)$plan['price_inr'] : (float)$plan['price_usd'];
$balance = (float)$user['wallet_balance'];
if ($balance < $price) {
    echo json_encode(['ok'=>false,'error'=>"Insufficient balance. Need {$currency} {$price}, have {$currency} {$balance}."]); exit;
}

// Unique ref
do {
    $ref = 'SES-'.strtoupper(substr(md5(uniqid(rand(),true)),0,8));
    $chk = db()->prepare("SELECT id FROM smtp_orders WHERE order_ref=?");
    $chk->execute([$ref]);
} while ($chk->fetch());

$region  = get_setting('smtp_ses_region','ap-south-1');
$expires = date('Y-m-d H:i:s', strtotime("+{$plan['duration_days']} days"));

// Deduct + insert order (status = domain_pending until verified)
$db = db();
$db->beginTransaction();
try {
    $db->prepare("UPDATE users SET wallet_balance=wallet_balance-? WHERE id=?")->execute([$price,$user['id']]);
    $db->prepare("INSERT INTO transactions (user_id,type,amount,description,created_at) VALUES (?,'debit',?,?,NOW())")
       ->execute([$user['id'],$price,"SMTP Email: {$plan['name']} ({$ref})"]);

    $db->prepare(
        "INSERT INTO smtp_orders
         (order_ref,user_id,plan_id,status,amount_paid,currency,emails_quota,
          aws_region,sender_domain,expires_at,created_at,updated_at)
         VALUES (?,?,?,'pending',?,?,?,?,?,?,NOW(),NOW())"
    )->execute([$ref,$user['id'],$plan['id'],$price,$currency,$plan['emails_month'],$region,$domain,$expires]);

    $order_id = (int)$db->lastInsertId();
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('smtp-order: '.$e->getMessage());
    echo json_encode(['ok'=>false,'error'=>'Order failed. Try again.']); exit;
}

// ── Auto-add domain to SES and get DNS records ───────────────
$ak = trim(get_setting('smtp_ses_access_key',''));
$sk = trim(get_setting('smtp_ses_secret_key',''));
$dns_records = [];

if ($ak && $sk) {
    // SES VerifyDomainIdentity
    $r1 = _ses_api_request('VerifyDomainIdentity', ['Domain'=>$domain], $ak, $sk, $region);
    $txt_token = _ses_parse($r1['body']??'','VerificationToken');

    // SES VerifyDomainDkim
    $r2 = _ses_api_request('VerifyDomainDkim', ['Domain'=>$domain], $ak, $sk, $region);
    preg_match_all('/<member>(.*?)<\/member>/', $r2['body']??'', $dm);
    $dkim_tokens = $dm[1] ?? [];

    if ($txt_token || !empty($dkim_tokens)) {
        db()->prepare("UPDATE smtp_orders SET dkim_tokens=?, domain_added_at=NOW() WHERE id=?")
           ->execute([json_encode(['txt_token'=>$txt_token,'dkim'=>$dkim_tokens]), $order_id]);

        // Build records for response
        if ($txt_token) {
            $dns_records[] = ['type'=>'TXT','name'=>'_amazonses.'.$domain,'value'=>$txt_token,'purpose'=>'Domain Ownership'];
        }
        foreach ($dkim_tokens as $i => $tok) {
            $dns_records[] = ['type'=>'CNAME','name'=>$tok.'._domainkey.'.$domain,'value'=>$tok.'.dkim.amazonses.com','purpose'=>'DKIM '.($i+1).'/3'];
        }
    }
}

echo json_encode([
    'ok'          => true,
    'order_id'    => $order_id,
    'order_ref'   => $ref,
    'domain'      => $domain,
    'dns_records' => $dns_records,
    'status'      => 'pending',
]);

// ── Minimal SES API helper (for order flow) ──────────────────
function _ses_api_request(string $action, array $params, string $ak, string $sk, string $region): array
{
    $params = array_merge($params, ['Action'=>$action,'Version'=>'2010-12-01']);
    return _aws_request('POST', "https://email.{$region}.amazonaws.com/", $ak, $sk, $region, 'ses', $params);
}
function _ses_parse(string $xml, string $tag): string
{
    preg_match("/<{$tag}>(.*?)<\/{$tag}>/s", $xml, $m);
    return trim($m[1] ?? '');
}
