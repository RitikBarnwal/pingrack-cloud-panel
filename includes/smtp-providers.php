<?php
/**
 * includes/smtp-providers.php
 * Amazon SES — IAM user create karo, SES send policy attach karo,
 * SMTP credentials generate karo (HMAC-SHA256 derived from secret key)
 */
declare(strict_types=1);

/**
 * Main entry — SES IAM user provision karo
 * Returns array with ok=true + credentials, or ok=false + error
 */
function smtp_auto_provision(array $order, array $plan): array
{
    $access_key = trim(get_setting('smtp_ses_access_key', ''));
    $secret_key = trim(get_setting('smtp_ses_secret_key', ''));
    $region     = trim(get_setting('smtp_ses_region', 'ap-south-1'));

    if (!$access_key || !$secret_key) {
        return ['ok'=>false,'error'=>'AWS credentials not configured in Admin → SMTP → Settings'];
    }

    $iam_username = 'gh-smtp-' . strtolower($order['order_ref']);

    // ── Step 1: Create IAM User ───────────────────────────────
    $r = _aws_request('POST', 'https://iam.amazonaws.com/', $access_key, $secret_key, 'us-east-1', 'iam', [
        'Action'   => 'CreateUser',
        'UserName' => $iam_username,
        'Version'  => '2010-05-08',
    ]);
    if ($r['code'] !== 200) {
        return ['ok'=>false,'error'=>'IAM CreateUser failed: '._aws_error($r['body']).' (HTTP '.$r['code'].')'];
    }

    // Parse ARN
    preg_match('/<Arn>(.*?)<\/Arn>/', $r['body'], $m);
    $iam_user_arn = $m[1] ?? '';

    // ── Step 2: Attach SES send policy ───────────────────────
    $policy = json_encode([
        'Version'   => '2012-10-17',
        'Statement' => [[
            'Effect'   => 'Allow',
            'Action'   => ['ses:SendEmail','ses:SendRawEmail'],
            'Resource' => '*',
        ]],
    ]);

    $r2 = _aws_request('POST', 'https://iam.amazonaws.com/', $access_key, $secret_key, 'us-east-1', 'iam', [
        'Action'         => 'PutUserPolicy',
        'UserName'       => $iam_username,
        'PolicyName'     => 'ses-send-only',
        'PolicyDocument' => $policy,
        'Version'        => '2010-05-08',
    ]);
    if ($r2['code'] !== 200) {
        // Rollback user
        _aws_request('POST','https://iam.amazonaws.com/',$access_key,$secret_key,'us-east-1','iam',
            ['Action'=>'DeleteUser','UserName'=>$iam_username,'Version'=>'2010-05-08']);
        return ['ok'=>false,'error'=>'IAM PutUserPolicy failed: '._aws_error($r2['body'])];
    }

    // ── Step 3: Create IAM Access Keys ───────────────────────
    $r3 = _aws_request('POST', 'https://iam.amazonaws.com/', $access_key, $secret_key, 'us-east-1', 'iam', [
        'Action'   => 'CreateAccessKey',
        'UserName' => $iam_username,
        'Version'  => '2010-05-08',
    ]);
    if ($r3['code'] !== 200) {
        _aws_request('POST','https://iam.amazonaws.com/',$access_key,$secret_key,'us-east-1','iam',
            ['Action'=>'DeleteUser','UserName'=>$iam_username,'Version'=>'2010-05-08']);
        return ['ok'=>false,'error'=>'IAM CreateAccessKey failed: '._aws_error($r3['body'])];
    }

    preg_match('/<AccessKeyId>(.*?)<\/AccessKeyId>/',     $r3['body'], $m1);
    preg_match('/<SecretAccessKey>(.*?)<\/SecretAccessKey>/', $r3['body'], $m2);

    $iam_access_key = $m1[1] ?? '';
    $iam_secret_key = $m2[1] ?? '';

    if (!$iam_access_key || !$iam_secret_key) {
        return ['ok'=>false,'error'=>'IAM credentials parse failed'];
    }

    // ── Step 4: Derive SES SMTP password ─────────────────────
    // AWS docs formula: HMAC-SHA256 chain on secret key
    $smtp_password = _ses_smtp_password($iam_secret_key, $region);

    // SES SMTP host for region
    $smtp_host = "email-smtp.{$region}.amazonaws.com";

    return [
        'ok'             => true,
        'smtp_host'      => $smtp_host,
        'smtp_port'      => 587,
        'smtp_username'  => $iam_access_key,    // SES SMTP user = IAM Access Key ID
        'smtp_password'  => $smtp_password,      // Derived SMTP password
        'aws_access_key' => $iam_access_key,
        'aws_secret_key' => $iam_secret_key,
        'aws_region'     => $region,
        'iam_user_arn'   => $iam_user_arn,
        'iam_username'   => $iam_username,
    ];
}

/**
 * Delete IAM user (on order cancel/expire)
 */
function smtp_deprovision(array $order): void
{
    $access_key = trim(get_setting('smtp_ses_access_key',''));
    $secret_key = trim(get_setting('smtp_ses_secret_key',''));
    $iam_user   = $order['iam_username'] ?? '';
    if (!$access_key || !$secret_key || !$iam_user) return;

    // Delete access keys first
    $r = _aws_request('POST','https://iam.amazonaws.com/',$access_key,$secret_key,'us-east-1','iam',[
        'Action'        => 'ListAccessKeys',
        'UserName'      => $iam_user,
        'Version'       => '2010-05-08',
    ]);
    if (preg_match_all('/<AccessKeyId>(.*?)<\/AccessKeyId>/', $r['body'], $keys)) {
        foreach ($keys[1] as $kid) {
            _aws_request('POST','https://iam.amazonaws.com/',$access_key,$secret_key,'us-east-1','iam',[
                'Action'      => 'DeleteAccessKey',
                'UserName'    => $iam_user,
                'AccessKeyId' => $kid,
                'Version'     => '2010-05-08',
            ]);
        }
    }
    // Delete policy
    _aws_request('POST','https://iam.amazonaws.com/',$access_key,$secret_key,'us-east-1','iam',[
        'Action'     => 'DeleteUserPolicy',
        'UserName'   => $iam_user,
        'PolicyName' => 'ses-send-only',
        'Version'    => '2010-05-08',
    ]);
    // Delete user
    _aws_request('POST','https://iam.amazonaws.com/',$access_key,$secret_key,'us-east-1','iam',[
        'Action'   => 'DeleteUser',
        'UserName' => $iam_user,
        'Version'  => '2010-05-08',
    ]);
}

// ── SES SMTP Password derivation (AWS documented formula) ────
function _ses_smtp_password(string $secret_key, string $region): string
{
    $date = "11111111";
    $service = "ses";
    $terminal = "aws4_request";
    $message = "SendRawEmail";
    $version = 0x04;

    $kDate = hash_hmac('sha256', $date, "AWS4" . $secret_key, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', $terminal, $kService, true);
    $signature = hash_hmac('sha256', $message, $kSigning, true);

    return base64_encode(chr($version) . $signature);
}

// ── AWS SigV4 Request (IAM uses query-string style) ──────────
function _aws_request(string $method, string $url, string $ak, string $sk, string $region, string $service, array $params): array
{
    $now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $date_str  = $now->format('Ymd');
    $datetime  = $now->format('Ymd\THis\Z');
    $scope     = "{$date_str}/{$region}/{$service}/aws4_request";

    $params['AWSAccessKeyId'] = $ak;    // needed for SigV4 query
    ksort($params);

    // Build canonical query string
    $qs_parts = [];
    foreach ($params as $k => $v) {
        $qs_parts[] = rawurlencode($k) . '=' . rawurlencode($v);
    }
    $qs = implode('&', $qs_parts);

    // For IAM POST, send params as body
    $body         = $qs;
    $payload_hash = hash('sha256', $body);
    $parsed       = parse_url($url);
    $host         = $parsed['host'];
    $path         = $parsed['path'] ?? '/';

    $canon_headers = "content-type:application/x-www-form-urlencoded\nhost:{$host}\nx-amz-content-sha256:{$payload_hash}\nx-amz-date:{$datetime}\n";
    $signed_headers = 'content-type;host;x-amz-content-sha256;x-amz-date';

    $canon_req = implode("\n", [
        $method, $path, '',   // empty canonical query for POST body
        $canon_headers,
        $signed_headers,
        $payload_hash,
    ]);

    $sts = "AWS4-HMAC-SHA256\n{$datetime}\n{$scope}\n" . hash('sha256', $canon_req);

    $k_date    = hash_hmac('sha256', $date_str, "AWS4{$sk}",    true);
    $k_region  = hash_hmac('sha256', $region,   $k_date,        true);
    $k_service = hash_hmac('sha256', $service,  $k_region,      true);
    $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
    $sig = hash_hmac('sha256', $sts, $k_signing);

    $auth = "AWS4-HMAC-SHA256 Credential={$ak}/{$scope}, SignedHeaders={$signed_headers}, Signature={$sig}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Host: '           . $host,
            'X-Amz-Content-Sha256: ' . $payload_hash,
            'X-Amz-Date: '    . $datetime,
            'Authorization: ' . $auth,
        ],
    ]);
    $body_resp = curl_exec($ch);
    $code      = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err       = curl_error($ch);
    curl_close($ch);
    if ($err) return ['code'=>0,'body'=>'cURL: '.$err];
    return ['code'=>$code,'body'=>(string)$body_resp];
}

function _aws_error(string $xml): string
{
    preg_match('/<Message>(.*?)<\/Message>/s', $xml, $m);
    return $m[1] ?? 'Unknown AWS error';
}
