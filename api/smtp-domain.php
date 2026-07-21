<?php
// api/smtp-domain.php — Domain add karo, DNS records fetch karo, verify karo
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/smtp-providers.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok'=>false,'error'=>'Method not allowed']); exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';
$uid    = (int)current_user()['id'];

if (!verify_csrf($input['csrf'] ?? '')) {
    echo json_encode(['ok'=>false,'error'=>'Invalid CSRF']); exit;
}

// ── Validate order belongs to user ────────────────────────────
function get_user_order(int $order_id, int $uid): array|false {
    $st = db()->prepare("SELECT * FROM smtp_orders WHERE id=? AND user_id=? LIMIT 1");
    $st->execute([$order_id, $uid]);
    return $st->fetch() ?: false;
}

// ══════════════════════════════════════════════════════════════
// ACTION: add_domain — User domain submit karta hai
// Calls SES VerifyDomainIdentity + VerifyDomainDkim
// Returns DNS records user ko dikhane ke liye
// ══════════════════════════════════════════════════════════════
if ($action === 'add_domain') {
    $order_id = (int)($input['order_id'] ?? 0);
    $domain   = strtolower(trim($input['domain'] ?? ''));

    // Basic domain validation
    if (!$domain || !preg_match('/^([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
        echo json_encode(['ok'=>false,'error'=>'Invalid domain format. Example: mail.yourdomain.com']); exit;
    }

    $order = get_user_order($order_id, $uid);
    if (!$order) { echo json_encode(['ok'=>false,'error'=>'Order not found']); exit; }
    if (!in_array($order['status'], ['active','pending'])) {
        echo json_encode(['ok'=>false,'error'=>'Order is not active']); exit;
    }

    $region = $order['aws_region'] ?? get_setting('smtp_ses_region','ap-south-1');
    $ak     = trim(get_setting('smtp_ses_access_key',''));
    $sk     = trim(get_setting('smtp_ses_secret_key',''));
    if (!$ak || !$sk) { echo json_encode(['ok'=>false,'error'=>'AWS not configured. Contact support.']); exit; }

    // Step 1: SES VerifyDomainIdentity (TXT record for ownership)
    $r1 = _ses_request('VerifyDomainIdentity', ['Domain'=>$domain], $ak, $sk, $region);
    if (!isset($r1['VerifyDomainIdentityResult']['VerificationToken'])) {
        $err = _ses_error($r1['raw'] ?? '');
        echo json_encode(['ok'=>false,'error'=>'SES domain add failed: '.$err]); exit;
    }
    $txt_token = $r1['VerifyDomainIdentityResult']['VerificationToken'];

    // Step 2: SES VerifyDomainDkim (3 CNAME records for DKIM)
    $r2 = _ses_request('VerifyDomainDkim', ['Domain'=>$domain], $ak, $sk, $region);
    $dkim_tokens = [];
    if (!empty($r2['VerifyDomainDkimResult']['DkimTokens']['member'])) {
        $raw = $r2['VerifyDomainDkimResult']['DkimTokens']['member'];
        $dkim_tokens = is_array($raw) ? $raw : [$raw];
    }

    // Save to DB
    db()->prepare(
        "UPDATE smtp_orders SET
         sender_domain=?, domain_verified=0, dkim_tokens=?,
         domain_added_at=NOW(), domain_verified_at=NULL,
         updated_at=NOW()
         WHERE id=?"
    )->execute([$domain, json_encode(['txt_token'=>$txt_token,'dkim'=>$dkim_tokens]), $order_id]);

    // Build DNS records response
    $records = _build_dns_records($domain, $txt_token, $dkim_tokens);

    echo json_encode([
        'ok'      => true,
        'domain'  => $domain,
        'records' => $records,
        'message' => 'Domain added! Add the DNS records below, then click Verify.',
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════
// ACTION: get_dns_records — Already added domain ke records fetch
// ══════════════════════════════════════════════════════════════
if ($action === 'get_dns_records') {
    $order_id = (int)($input['order_id'] ?? 0);
    $order    = get_user_order($order_id, $uid);
    if (!$order || !$order['sender_domain']) {
        echo json_encode(['ok'=>false,'error'=>'No domain added yet']); exit;
    }

    $tokens_data = json_decode($order['dkim_tokens'] ?? '{}', true) ?: [];
    $records = _build_dns_records(
        $order['sender_domain'],
        $tokens_data['txt_token'] ?? '',
        $tokens_data['dkim'] ?? []
    );

    echo json_encode([
        'ok'       => true,
        'domain'   => $order['sender_domain'],
        'verified' => (bool)$order['domain_verified'],
        'records'  => $records,
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════
// ACTION: verify_domain — DNS records add kiye? Check karo
// ══════════════════════════════════════════════════════════════
if ($action === 'verify_domain') {
    $order_id = (int)($input['order_id'] ?? 0);
    $order    = get_user_order($order_id, $uid);
    if (!$order || !$order['sender_domain']) {
        echo json_encode(['ok'=>false,'error'=>'No domain added yet']); exit;
    }
    if ($order['domain_verified']) {
        echo json_encode(['ok'=>true,'verified'=>true,'message'=>'Domain already verified!']); exit;
    }

    $region = $order['aws_region'] ?? get_setting('smtp_ses_region','ap-south-1');
    $ak     = trim(get_setting('smtp_ses_access_key',''));
    $sk     = trim(get_setting('smtp_ses_secret_key',''));
    $domain = $order['sender_domain'];

    // Check SES verification status
    $r = _ses_request('GetIdentityVerificationAttributes',
        ['Identities.member.1' => $domain],
        $ak, $sk, $region
    );

    $status = '';
    $entries = $r['GetIdentityVerificationAttributesResult']['VerificationAttributes']['entry'] ?? [];
    // Single entry vs array
    if (isset($entries['key'])) $entries = [$entries];
    foreach ($entries as $entry) {
        if (strtolower($entry['key'] ?? '') === strtolower($domain)) {
            $status = $entry['value']['VerificationStatus'] ?? '';
        }
    }

    if ($status === 'Success') {
        // Mark verified + provision IAM credentials if not done yet
        db()->prepare("UPDATE smtp_orders SET domain_verified=1, domain_verified_at=NOW(), updated_at=NOW() WHERE id=?")
           ->execute([$order_id]);

        // If IAM not yet provisioned, provision now
        if (!$order['smtp_username']) {
            $plan_st = db()->prepare("SELECT * FROM smtp_plans WHERE id=?");
            $plan_st->execute([$order['plan_id']]);
            $plan = $plan_st->fetch();

            $result = smtp_auto_provision($order, $plan ?: []);
            if ($result['ok']) {
                db()->prepare(
                    "UPDATE smtp_orders SET
                     status='active', smtp_host=?, smtp_port=?, smtp_username=?,
                     smtp_password=?, aws_access_key=?, aws_secret_key=?, aws_region=?,
                     iam_user_arn=?, iam_username=?, sender_domain=?,
                     auto_activated=1, activated_at=NOW(), updated_at=NOW()
                     WHERE id=?"
                )->execute([
                    $result['smtp_host'], $result['smtp_port'],
                    $result['smtp_username'], $result['smtp_password'],
                    $result['aws_access_key'], $result['aws_secret_key'],
                    $result['aws_region'], $result['iam_user_arn'],
                    $result['iam_username'], $domain, $order_id,
                ]);
            }
        } else {
            // Already provisioned, just update domain
            db()->prepare("UPDATE smtp_orders SET status='active', sender_domain=?, updated_at=NOW() WHERE id=?")
               ->execute([$domain, $order_id]);
        }

        echo json_encode(['ok'=>true,'verified'=>true,'message'=>'✅ Domain verified! SMTP credentials are ready.']);
    } elseif ($status === 'Pending') {
        echo json_encode(['ok'=>true,'verified'=>false,'message'=>'DNS records not yet detected. DNS propagation can take up to 72 hours. Try again in a few minutes.']);
    } else {
        echo json_encode(['ok'=>true,'verified'=>false,'message'=>'Verification pending. Make sure all DNS records are added correctly.']);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════
// ACTION: change_domain — User domain badalna chahta hai
// ══════════════════════════════════════════════════════════════
if ($action === 'change_domain') {
    $order_id = (int)($input['order_id'] ?? 0);
    $order    = get_user_order($order_id, $uid);
    if (!$order) { echo json_encode(['ok'=>false,'error'=>'Not found']); exit; }

    // Reset domain fields
    db()->prepare(
        "UPDATE smtp_orders SET sender_domain=NULL, domain_verified=0, dkim_tokens=NULL,
         domain_added_at=NULL, domain_verified_at=NULL, updated_at=NOW() WHERE id=?"
    )->execute([$order_id]);

    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);

// ── Helpers ───────────────────────────────────────────────────

function _build_dns_records(string $domain, string $txt_token, array $dkim_tokens): array
{
    $records = [];

    // TXT — domain ownership
    if ($txt_token) {
        $records[] = [
            'type'    => 'TXT',
            'name'    => '_amazonses.'.$domain,
            'value'   => $txt_token,
            'purpose' => 'Domain Ownership Verification',
            'ttl'     => '300',
        ];
    }

    // CNAME — DKIM (3 records)
    foreach ($dkim_tokens as $i => $token) {
        $records[] = [
            'type'    => 'CNAME',
            'name'    => $token.'._domainkey.'.$domain,
            'value'   => $token.'.dkim.amazonses.com',
            'purpose' => 'DKIM Signing ('.($i+1).'/3)',
            'ttl'     => '300',
        ];
    }

    return $records;
}

function _ses_request(string $action, array $params, string $ak, string $sk, string $region): array
{
    $endpoint = "https://email.{$region}.amazonaws.com/";
    $params   = array_merge($params, ['Action'=>$action,'Version'=>'2010-12-01']);

    $resp = _aws_request('POST', $endpoint, $ak, $sk, $region, 'ses', $params);
    $xml  = @simplexml_load_string($resp['body'] ?? '');
    if (!$xml) return ['raw' => $resp['body'] ?? ''];
    return array_merge(json_decode(json_encode($xml), true) ?: [], ['raw'=>$resp['body']]);
}

function _ses_error(string $xml): string
{
    preg_match('/<Message>(.*?)<\/Message>/s', $xml, $m);
    return trim($m[1] ?? 'Unknown SES error');
}
