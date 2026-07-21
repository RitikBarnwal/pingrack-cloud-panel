<?php
/**
 * includes/dns.php
 * DNS management helper — Cloudflare API backend
 *
 * Admin → Settings → DNS mein Cloudflare credentials set karo:
 *   dns_cf_api_token  = Cloudflare API Token (Zone:Edit permission)
 *   dns_cf_account_id = Cloudflare Account ID
 */
declare(strict_types=1);

// ── Cloudflare API client ─────────────────────────────────────
function dns_cf_request(string $method, string $path, array $data = []): array
{
    $token = get_setting('dns_cf_api_token', '');
    if (!$token) throw new RuntimeException('Cloudflare API token not configured. Go to Admin → Settings → DNS.');

    $url = 'https://api.cloudflare.com/client/v4' . $path;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
    ]);
    if (!empty($data)) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) throw new RuntimeException('Cloudflare API cURL error: ' . $err);
    $result = json_decode($resp, true);
    if (!($result['success'] ?? false)) {
        $msg = $result['errors'][0]['message'] ?? 'Cloudflare API error';
        throw new RuntimeException($msg);
    }
    return $result;
}

// ── Add domain to Cloudflare ──────────────────────────────────
function dns_add_zone(string $domain): array
{
    $account_id = get_setting('dns_cf_account_id', '');
    $result = dns_cf_request('POST', '/zones', [
        'name'    => $domain,
        'account' => ['id' => $account_id],
        'jump_start' => false,
    ]);
    return [
        'cf_zone_id'  => $result['result']['id'],
        'nameservers' => json_encode($result['result']['name_servers'] ?? []),
        'status'      => 'pending',
    ];
}

// ── Check zone activation status ─────────────────────────────
function dns_check_zone(string $cf_zone_id): string
{
    $result = dns_cf_request('GET', '/zones/' . $cf_zone_id);
    return $result['result']['status'] ?? 'pending'; // active | pending | initializing
}

// ── Delete zone from Cloudflare ───────────────────────────────
function dns_delete_zone(string $cf_zone_id): void
{
    try { dns_cf_request('DELETE', '/zones/' . $cf_zone_id); }
    catch (Throwable $e) { /* ignore if already deleted */ }
}

// ── Add DNS record ────────────────────────────────────────────
function dns_add_record(string $cf_zone_id, array $record): string
{
    $payload = [
        'type'    => $record['type'],
        'name'    => $record['name'],
        'content' => $record['content'],
        'ttl'     => (int)($record['ttl'] ?? 1),
        'proxied' => (bool)($record['proxied'] ?? false),
    ];
    if (isset($record['priority'])) $payload['priority'] = (int)$record['priority'];

    $result = dns_cf_request('POST', '/zones/' . $cf_zone_id . '/dns_records', $payload);
    return $result['result']['id'];
}

// ── Update DNS record ─────────────────────────────────────────
function dns_update_record(string $cf_zone_id, string $cf_record_id, array $record): void
{
    $payload = [
        'type'    => $record['type'],
        'name'    => $record['name'],
        'content' => $record['content'],
        'ttl'     => (int)($record['ttl'] ?? 1),
        'proxied' => (bool)($record['proxied'] ?? false),
    ];
    if (isset($record['priority'])) $payload['priority'] = (int)$record['priority'];
    dns_cf_request('PUT', '/zones/' . $cf_zone_id . '/dns_records/' . $cf_record_id, $payload);
}

// ── Delete DNS record ─────────────────────────────────────────
function dns_delete_record(string $cf_zone_id, string $cf_record_id): void
{
    try { dns_cf_request('DELETE', '/zones/' . $cf_zone_id . '/dns_records/' . $cf_record_id); }
    catch (Throwable $e) {}
}

// ── List records from Cloudflare (sync) ──────────────────────
function dns_list_records(string $cf_zone_id): array
{
    $result = dns_cf_request('GET', '/zones/' . $cf_zone_id . '/dns_records?per_page=100');
    return $result['result'] ?? [];
}

// ── DB helpers ────────────────────────────────────────────────
function dns_get_zone(int $zone_id, int $user_id, bool $admin = false): ?array
{
    try {
        $sql = 'SELECT * FROM dns_zones WHERE id=? AND deleted_at IS NULL' . ($admin ? '' : ' AND user_id=?');
        $st  = db()->prepare($sql);
        $st->execute($admin ? [$zone_id] : [$zone_id, $user_id]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

function dns_get_records(int $zone_id): array
{
    try {
        return db()->prepare('SELECT * FROM dns_records WHERE zone_id=? ORDER BY type,name')
                   ->execute([$zone_id]) ? db()->query("SELECT * FROM dns_records WHERE zone_id=$zone_id ORDER BY type,name")->fetchAll() : [];
    } catch (Throwable $e) { return []; }
}

function dns_is_configured(): bool
{
    return !empty(get_setting('dns_cf_api_token')) && !empty(get_setting('dns_cf_account_id'));
}

// ── TTL options ───────────────────────────────────────────────
function dns_ttl_options(): array {
    return [1=>'Auto',60=>'1 min',300=>'5 min',600=>'10 min',900=>'15 min',
            1800=>'30 min',3600=>'1 hour',7200=>'2 hours',18000=>'5 hours',
            43200=>'12 hours',86400=>'1 day'];
}

// ── Record type presets (for UI hints) ───────────────────────
function dns_record_placeholder(string $type): string {
    return match($type) {
        'A'     => '1.2.3.4',
        'AAAA'  => '2001:db8::1',
        'CNAME' => 'target.example.com',
        'MX'    => 'mail.example.com',
        'TXT'   => 'v=spf1 include:_spf.google.com ~all',
        'NS'    => 'ns1.example.com',
        default => 'value',
    };
}
