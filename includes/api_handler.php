<?php
require_once __DIR__ . '/bootstrap.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

$csrfHeader  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$sessionCsrf = $_SESSION['csrf_token'] ?? '';

$isAjax = (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
);

if (
    !$isAjax ||
    empty($csrfHeader) ||
    empty($sessionCsrf) ||
    !hash_equals($sessionCsrf, $csrfHeader)
) {

    http_response_code(403);

    echo json_encode([
        'error' => 'forbidden',
        'message' => 'Invalid request source'
    ]);

    exit;
}
header('Content-Type: application/json');
header('Cache-Control: no-cache');

$type = $_GET['type'] ?? '';

// ─── cURL helper ──────────────────────────────────────────────
function do_curl(string $url, int $timeout = 8): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; GreatHost/1.0)',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $res = curl_exec($ch);
        $err = curl_errno($ch);
        curl_close($ch);
        if (!$err && $res !== false) return $res;
    }
    // fallback to file_get_contents
    $ctx = stream_context_create(['http' => [
        'timeout'        => $timeout,
        'ignore_errors'  => true,
        'user_agent'     => 'Mozilla/5.0 (compatible; GreatHost/1.0)',
    ], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $res = @file_get_contents($url, false, $ctx);
    return $res !== false ? $res : null;
}

// ─── Real IP detection ─────────────────────────────────────────
function get_real_ip(): string {
    // Priority order: Cloudflare → Load Balancer → Proxy → Direct
    $checks = [
        'HTTP_CF_CONNECTING_IP',      // Cloudflare
        'HTTP_X_REAL_IP',             // Nginx proxy
        'HTTP_X_FORWARDED_FOR',       // Standard proxy header
        'HTTP_X_FORWARDED',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR',
    ];
    foreach ($checks as $key) {
        if (!empty($_SERVER[$key])) {
            // X-Forwarded-For can be a comma-separated list — take first
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    // If all are private/reserved (local dev), fall back to REMOTE_ADDR as-is
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

// ─── IP Info ───────────────────────────────────────────────────
if ($type === 'ip') {
    $ip = get_real_ip();

    // Try ip-api.com first (free, reliable)
    $res = do_curl("http://ip-api.com/json/{$ip}?fields=status,country,countryCode,regionName,city,zip,lat,lon,isp,query");
    if ($res) {
        $data = json_decode($res, true);
        if (!empty($data) && ($data['status'] ?? '') === 'success') {
            echo $res;
            exit;
        }
    }

    // Fallback: ipapi.co
    $res2 = do_curl("https://ipapi.co/{$ip}/json/");
    if ($res2) {
        $d = json_decode($res2, true);
        if (!empty($d) && empty($d['error'])) {
            // Normalize to ip-api.com format
            echo json_encode([
                'status'      => 'success',
                'country'     => $d['country_name']  ?? '',
                'countryCode' => $d['country_code']  ?? '',
                'regionName'  => $d['region']        ?? '',
                'city'        => $d['city']           ?? '',
                'zip'         => $d['postal']         ?? '',
                'query'       => $ip,
            ]);
            exit;
        }
    }

    // Fallback: geoiplookup-style with ip-api batch
    echo json_encode(['status' => 'fail', 'query' => $ip]);
    exit;
}

// ─── Pincode lookup ────────────────────────────────────────────
if ($type === 'pincode') {
    $pin     = preg_replace('/[^0-9A-Za-z\- ]/', '', $_GET['pin'] ?? '');
    $country = preg_replace('/[^A-Z]/', '', strtoupper($_GET['country'] ?? 'IN'));

    if (empty($pin)) {
        echo json_encode(['error' => 'Invalid pincode']);
        exit;
    }

    // ── Method 1: GeoNames (use setting-based username if available) ──
    $gnUser = get_setting('geonames_username', 'demo');
    if (empty($gnUser) || $gnUser === 'demo') {
        // Try multiple free GeoNames usernames as fallback pool
        $gnUsers = ['demo', 'geonames', 'sagar7252'];
    } else {
        $gnUsers = [$gnUser];
    }

    foreach ($gnUsers as $gn) {
        $url = "https://secure.geonames.org/postalCodeSearchJSON?postalcode=" . urlencode($pin) . "&country=" . urlencode($country) . "&maxRows=10&username=" . urlencode($gn);
        $res = do_curl($url, 7);
        if ($res) {
            $data = json_decode($res, true);
            if (!empty($data['postalCodes'])) {
                echo $res;
                exit;
            }
        }
    }

    // ── Method 2: India Post API (for Indian pincodes) ──
    if ($country === 'IN' && preg_match('/^\d{6}$/', $pin)) {
        $res = do_curl("https://api.postalpincode.in/pincode/{$pin}", 7);
        if ($res) {
            $data = json_decode($res, true);
            if (!empty($data[0]) && ($data[0]['Status'] ?? '') === 'Success' && !empty($data[0]['PostOffice'])) {
                $offices = $data[0]['PostOffice'];
                $first   = $offices[0];
                // Build GeoNames-compatible response
                $postal  = [];
                foreach ($offices as $po) {
                    $postal[] = [
                        'postalCode'  => $pin,
                        'placeName'   => $po['Name'] ?? '',
                        'adminName1'  => $po['State'] ?? '',
                        'adminName2'  => $po['District'] ?? '',
                        'adminName3'  => $po['Division'] ?? '',
                        'countryCode' => 'IN',
                    ];
                }
                echo json_encode(['postalCodes' => $postal]);
                exit;
            }
        }
    }

    // ── Method 3: Open Postcode (UK) ──
    if ($country === 'GB') {
        $res = do_curl("https://api.postcodes.io/postcodes/" . urlencode($pin), 7);
        if ($res) {
            $data = json_decode($res, true);
            if (($data['status'] ?? 0) === 200 && !empty($data['result'])) {
                $r = $data['result'];
                echo json_encode(['postalCodes' => [[
                    'postalCode'  => $pin,
                    'placeName'   => $r['parish'] ?? $r['admin_ward'] ?? '',
                    'adminName1'  => $r['nuts'] ?? '',
                    'adminName2'  => $r['admin_district'] ?? '',
                    'countryCode' => 'GB',
                ]]]);
                exit;
            }
        }
    }

    // ── Nothing worked — return empty so JS triggers manual fallback ──
    echo json_encode(['postalCodes' => []]);
    exit;
}

echo json_encode(['error' => 'Invalid request']);
