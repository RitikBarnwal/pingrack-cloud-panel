<?php
/**
 * virt-test.php — TEMPORARY Virtualizor connection diagnostic. DELETE after use.
 *
 * Admin-only. Visit:  https://<your-domain>/virt-test.php
 * Shows exactly what the panel returns for act=plans / act=servers so we can
 * see WHY plans aren't fetching (wrong port, bad creds, firewall, HTML, …).
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/admin.php';
require_admin();

header('Content-Type: text/html; charset=utf-8');

// Pick the Virtualizor provider (first active one, or ?id=N)
$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $st = db()->prepare("SELECT * FROM providers WHERE id=? LIMIT 1");
    $st->execute([$id]); $prov = $st->fetch();
} else {
    $prov = db()->query("SELECT * FROM providers WHERE provider_type='virtualizor' AND is_active=1 ORDER BY id LIMIT 1")->fetch();
}

function h($v){ return htmlspecialchars((string)$v); }

// Resolve credentials the same way the client does
function resolve_creds(array $prov): array {
    $panel = $prov['panel_url'] ?? '';
    $key   = $prov['api_key']   ?? '';
    $pass  = $prov['api_pass']  ?? '';
    if ((!$panel || !$pass) && is_string($prov['api_key'] ?? null) && str_starts_with(ltrim($prov['api_key']), '{')) {
        $j = json_decode($prov['api_key'], true);
        if (is_array($j)) {
            $panel = $panel ?: ($j['panel_url'] ?? '');
            $key   = $j['api_key']  ?? $key;
            $pass  = $pass ?: ($j['api_pass'] ?? '');
        }
    }
    $panel = trim((string)$panel);
    if ($panel !== '' && !preg_match('#^https?://#i', $panel)) $panel = 'https://' . $panel;
    $panel = preg_replace('#:\d+$#', '', rtrim($panel, '/'));
    return [$panel, trim((string)$key), trim((string)$pass)];
}

$results = [];
if ($prov) {
    [$panel, $key, $pass] = resolve_creds($prov);
    // Strip scheme to try both http and https cleanly
    $hostOnly = preg_replace('#^https?://#i', '', $panel);

    // Probe every plausible Virtualizor endpoint for act=plans, so we can see
    // which scheme+port actually returns JSON on THIS panel.
    $probes = [
        ['https', 4085, 'Admin API (SSL)'],
        ['http',  4084, 'Admin API (non-SSL)'],
        ['https', 4083, 'Enduser API (SSL)'],
        ['http',  4082, 'Enduser API (non-SSL)'],
    ];

    // Virtualizor hashed auth (same as the client / official SDK)
    $rand    = substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'), 0, 8);
    $apikey  = $rand . $key;
    $apipass = md5($rand . $pass);

    foreach ($probes as [$scheme, $port, $label]) {
        $base = $scheme . '://' . $hostOnly . ':' . $port . '/index.php';
        $url  = $base . '?api=json&apikey=' . urlencode($apikey) . '&apipass=' . urlencode($apipass) . '&act=plans';
        $shown = $scheme . '://' . $hostOnly . ':' . $port . '/index.php?api=json&apikey=' . substr($apikey,0,8) . '…&apipass=<md5>&act=plans';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => false,   // don't follow to a login page
            CURLOPT_USERAGENT => 'Mozilla/5.0',
        ]);
        $t0   = microtime(true);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ms   = round((microtime(true) - $t0) * 1000);
        curl_close($ch);

        $json = json_decode((string)$raw, true);
        $results["$label — :$port"] = [
            'url'      => $shown,
            'http'     => $code,
            'ms'       => $ms,
            'curl_err' => $err,
            'is_html'  => str_starts_with(ltrim((string)$raw), '<'),
            'json_ok'  => is_array($json),
            'top_keys' => is_array($json) ? array_keys($json) : [],
            'raw'      => substr((string)$raw, 0, 1500),
        ];
    }
}
?><!doctype html><html><head><meta charset="utf-8">
<title>Virtualizor Connection Test</title>
<style>
body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:32px 18px}
.box{max-width:900px;margin:0 auto}
h1{font-size:20px}h2{font-size:15px;margin-top:26px;color:#93c5fd}
.kv{display:grid;grid-template-columns:150px 1fr;gap:4px 12px;font-size:13px;margin:10px 0}
.kv div:nth-child(odd){color:#94a3b8}
pre{background:#020617;border:1px solid #1e293b;border-radius:8px;padding:12px;font-size:12px;overflow:auto;max-height:340px;white-space:pre-wrap;word-break:break-all}
.ok{color:#4ade80}.bad{color:#f87171}.warn{color:#fbbf24}
code{background:#1e293b;padding:1px 5px;border-radius:4px}
</style></head><body><div class="box">
<h1>Virtualizor Connection Test</h1>
<?php if (!$prov): ?>
  <p class="bad">No active Virtualizor provider found. Add one in Admin → Providers.</p>
<?php else:
  [$panel, $key, $pass] = resolve_creds($prov);
?>
  <div class="kv">
    <div>Provider</div><div><?= h($prov['display_name']) ?> (id <?= (int)$prov['id'] ?>)</div>
    <div>Resolved panel</div><div><code><?= h($panel ?: '(empty!)') ?></code></div>
    <div>Admin API port</div><div><code><?= str_starts_with($panel,'https') ? 4085 : 4084 ?></code></div>
    <div>API key</div><div><?= $key !== '' ? '✓ set ('.strlen($key).' chars)' : '<span class="bad">EMPTY</span>' ?></div>
    <div>API pass</div><div><?= $pass !== '' ? '✓ set ('.strlen($pass).' chars)' : '<span class="bad">EMPTY</span>' ?></div>
  </div>

  <?php foreach ($results as $act => $r): ?>
  <h2>act=<?= h($act) ?></h2>
  <div class="kv">
    <div>Request URL</div><div><code><?= h($r['url']) ?></code></div>
    <div>HTTP status</div><div class="<?= $r['http']==200?'ok':'bad' ?>"><?= (int)$r['http'] ?> · <?= (int)$r['ms'] ?>ms</div>
    <?php if ($r['curl_err']): ?><div>cURL error</div><div class="bad"><?= h($r['curl_err']) ?></div><?php endif; ?>
    <div>Response type</div><div>
      <?php if ($r['is_html']): ?><span class="bad">HTML (login page / wrong port)</span>
      <?php elseif ($r['json_ok']): ?><span class="ok">JSON</span> — keys: <?= h(implode(', ', $r['top_keys'])) ?>
      <?php else: ?><span class="warn">not JSON</span><?php endif; ?>
    </div>
  </div>
  <pre><?= h($r['raw'] ?: '(empty response)') ?></pre>
  <?php endforeach; ?>

  <p style="margin-top:24px;color:#94a3b8;font-size:13px">Delete <code>virt-test.php</code> when done.</p>
<?php endif; ?>
</div></body></html>
