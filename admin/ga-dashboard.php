<?php
/**
 * admin/ga-dashboard.php — Enterprise Google Analytics Dashboard
 * Shows: live users, sessions, geo, traffic sources, devices, page views,
 *        bounce/session data, all with period filters.
 *
 * Data sources:
 *  - GA4 Measurement Protocol + Data API (via service account)
 *  - Falls back to embedded JS for client-side data when API key absent
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/security.php';
require_admin();

$user     = current_user();
$app_name = APP_NAME;
$csrf     = csrf_token();
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));

$ga_enabled    = get_setting('ga_enabled') === '1';
$ga_id         = get_setting('ga_measurement_id', '');
$ga_prop_id    = get_setting('ga_property_id', '');
$ga_email      = get_setting('ga_client_email', '');
$ga_key        = get_setting('ga_private_key', '');
$ga_realtime   = get_setting('ga_realtime_enabled') === '1';

// ── Server-side GA4 Data API proxy ──────────────────────────
if (!empty($_GET['ajax']) && $_GET['ajax'] === 'ga_proxy') {
    header('Content-Type: application/json');

    if (!$ga_enabled || !$ga_prop_id || !$ga_email || !$ga_key) {
        echo json_encode(['ok' => false, 'error' => 'GA not configured. Set up credentials in GA Settings.']);
        exit;
    }

    $report_type = $_GET['report'] ?? 'overview';
    $period      = $_GET['period'] ?? '7daysAgo';

    /**
     * Build JWT for Google OAuth2 and get access token.
     * Pure PHP, no Composer dependency.
     */
    function ga_get_access_token(string $client_email, string $private_key): string|false {
        $now = time();
        $header  = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims  = base64_encode(json_encode([
            'iss'   => $client_email,
            'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));
        $signing_input = "$header.$claims";
        // Clean base64url
        $header  = str_replace(['+','/','='],  ['-','_',''], $header);
        $claims  = str_replace(['+','/','='],  ['-','_',''], $claims);
        $signing_input = "$header.$claims";

        $pk = openssl_pkey_get_private($private_key);
        if (!$pk) return false;
        openssl_sign($signing_input, $sig, $pk, 'SHA256');
        $sig_b64 = str_replace(['+','/','='], ['-','_',''], base64_encode($sig));
        $jwt = "$signing_input.$sig_b64";

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if (!$resp) return false;
        $data = json_decode($resp, true);
        return $data['access_token'] ?? false;
    }

    function ga_run_report(string $property_id, string $token, array $body): array|false {
        $url = "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runReport";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", "Content-Type: application/json"],
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        return $resp ? (json_decode($resp, true) ?: false) : false;
    }

    function ga_run_realtime(string $property_id, string $token, array $body): array|false {
        $url = "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runRealtimeReport";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", "Content-Type: application/json"],
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        return $resp ? (json_decode($resp, true) ?: false) : false;
    }

    // Get token (cached in session for 55 min)
    if (empty($_SESSION['ga_token']) || ($_SESSION['ga_token_exp'] ?? 0) < time()) {
        $token = ga_get_access_token($ga_email, $ga_key);
        if (!$token) {
            echo json_encode(['ok' => false, 'error' => 'Failed to authenticate with Google. Check service account credentials.']);
            exit;
        }
        $_SESSION['ga_token']     = $token;
        $_SESSION['ga_token_exp'] = time() + 3300;
    }
    $token = $_SESSION['ga_token'];

    // Build date range
    $date_ranges = [['startDate' => $period, 'endDate' => 'today']];

    $result = [];

    if ($report_type === 'overview') {
        $r = ga_run_report($ga_prop_id, $token, [
            'dateRanges' => $date_ranges,
            'metrics'    => [
                ['name' => 'sessions'],
                ['name' => 'totalUsers'],
                ['name' => 'newUsers'],
                ['name' => 'screenPageViews'],
                ['name' => 'bounceRate'],
                ['name' => 'averageSessionDuration'],
                ['name' => 'sessionsPerUser'],
            ],
        ]);
        if ($r && !empty($r['rows'])) {
            $v = $r['rows'][0]['metricValues'];
            $result = [
                'sessions'          => $v[0]['value'] ?? 0,
                'total_users'       => $v[1]['value'] ?? 0,
                'new_users'         => $v[2]['value'] ?? 0,
                'page_views'        => $v[3]['value'] ?? 0,
                'bounce_rate'       => round(($v[4]['value'] ?? 0) * 100, 1),
                'avg_session_sec'   => round($v[5]['value'] ?? 0),
                'sessions_per_user' => round($v[6]['value'] ?? 0, 2),
            ];
        }
    } elseif ($report_type === 'realtime') {
        $r = ga_run_realtime($ga_prop_id, $token, [
            'metrics'    => [['name' => 'activeUsers']],
            'dimensions' => [['name' => 'country']],
        ]);
        $online = 0;
        $countries = [];
        if ($r && !empty($r['rows'])) {
            foreach ($r['rows'] as $row) {
                $cnt = (int)($row['metricValues'][0]['value'] ?? 0);
                $online += $cnt;
                $countries[] = ['country' => $row['dimensionValues'][0]['value'] ?? '', 'users' => $cnt];
            }
        }
        $result = ['active_users' => $online, 'countries' => $countries];

    } elseif ($report_type === 'geo') {
        $r = ga_run_report($ga_prop_id, $token, [
            'dateRanges' => $date_ranges,
            'dimensions' => [['name' => 'country'], ['name' => 'city']],
            'metrics'    => [['name' => 'sessions'], ['name' => 'totalUsers']],
            'orderBys'   => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
            'limit'      => 50,
        ]);
        $rows = [];
        if ($r && !empty($r['rows'])) {
            foreach ($r['rows'] as $row) {
                $rows[] = [
                    'country'  => $row['dimensionValues'][0]['value'] ?? '',
                    'city'     => $row['dimensionValues'][1]['value'] ?? '',
                    'sessions' => $row['metricValues'][0]['value'] ?? 0,
                    'users'    => $row['metricValues'][1]['value'] ?? 0,
                ];
            }
        }
        $result = ['rows' => $rows];

    } elseif ($report_type === 'traffic_sources') {
        $r = ga_run_report($ga_prop_id, $token, [
            'dateRanges' => $date_ranges,
            'dimensions' => [['name' => 'sessionDefaultChannelGroup']],
            'metrics'    => [['name' => 'sessions'], ['name' => 'totalUsers'], ['name' => 'bounceRate']],
            'orderBys'   => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
        ]);
        $rows = [];
        if ($r && !empty($r['rows'])) {
            foreach ($r['rows'] as $row) {
                $rows[] = [
                    'channel'     => $row['dimensionValues'][0]['value'] ?? '',
                    'sessions'    => $row['metricValues'][0]['value'] ?? 0,
                    'users'       => $row['metricValues'][1]['value'] ?? 0,
                    'bounce_rate' => round(($row['metricValues'][2]['value'] ?? 0) * 100, 1),
                ];
            }
        }
        $result = ['rows' => $rows];

    } elseif ($report_type === 'devices') {
        $r = ga_run_report($ga_prop_id, $token, [
            'dateRanges' => $date_ranges,
            'dimensions' => [['name' => 'deviceCategory']],
            'metrics'    => [['name' => 'sessions'], ['name' => 'totalUsers']],
        ]);
        $rows = [];
        if ($r && !empty($r['rows'])) {
            foreach ($r['rows'] as $row) {
                $rows[] = [
                    'device'   => $row['dimensionValues'][0]['value'] ?? '',
                    'sessions' => $row['metricValues'][0]['value'] ?? 0,
                    'users'    => $row['metricValues'][1]['value'] ?? 0,
                ];
            }
        }
        $result = ['rows' => $rows];

    } elseif ($report_type === 'browsers') {
        $r = ga_run_report($ga_prop_id, $token, [
            'dateRanges' => $date_ranges,
            'dimensions' => [['name' => 'browser']],
            'metrics'    => [['name' => 'sessions']],
            'orderBys'   => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
            'limit'      => 10,
        ]);
        $rows = [];
        if ($r && !empty($r['rows'])) {
            foreach ($r['rows'] as $row) {
                $rows[] = ['browser' => $row['dimensionValues'][0]['value'] ?? '', 'sessions' => $row['metricValues'][0]['value'] ?? 0];
            }
        }
        $result = ['rows' => $rows];

    } elseif ($report_type === 'pages') {
        $r = ga_run_report($ga_prop_id, $token, [
            'dateRanges' => $date_ranges,
            'dimensions' => [['name' => 'pagePath'], ['name' => 'pageTitle']],
            'metrics'    => [['name' => 'screenPageViews'], ['name' => 'averageSessionDuration'], ['name' => 'bounceRate']],
            'orderBys'   => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
            'limit'      => 25,
        ]);
        $rows = [];
        if ($r && !empty($r['rows'])) {
            foreach ($r['rows'] as $row) {
                $rows[] = [
                    'path'        => $row['dimensionValues'][0]['value'] ?? '',
                    'title'       => $row['dimensionValues'][1]['value'] ?? '',
                    'views'       => $row['metricValues'][0]['value'] ?? 0,
                    'avg_time'    => round($row['metricValues'][1]['value'] ?? 0),
                    'bounce_rate' => round(($row['metricValues'][2]['value'] ?? 0) * 100, 1),
                ];
            }
        }
        $result = ['rows' => $rows];

    } elseif ($report_type === 'timeline') {
        $r = ga_run_report($ga_prop_id, $token, [
            'dateRanges' => $date_ranges,
            'dimensions' => [['name' => 'date']],
            'metrics'    => [['name' => 'sessions'], ['name' => 'totalUsers'], ['name' => 'screenPageViews']],
            'orderBys'   => [['dimension' => ['dimensionName' => 'date'], 'desc' => false]],
        ]);
        $rows = [];
        if ($r && !empty($r['rows'])) {
            foreach ($r['rows'] as $row) {
                $d = $row['dimensionValues'][0]['value'] ?? '';
                $rows[] = [
                    'date'      => substr($d,0,4).'-'.substr($d,4,2).'-'.substr($d,6,2),
                    'sessions'  => $row['metricValues'][0]['value'] ?? 0,
                    'users'     => $row['metricValues'][1]['value'] ?? 0,
                    'pageviews' => $row['metricValues'][2]['value'] ?? 0,
                ];
            }
        }
        $result = ['rows' => $rows];
    }

    echo json_encode(['ok' => true, 'data' => $result]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>GA Dashboard — <?= htmlspecialchars($app_name) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/admin/admin.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<!-- Leaflet for world map -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<?php inject_global_head(); ?>
<?php if ($ga_enabled && $ga_id): ?>
<!-- GA4 Tracking -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= htmlspecialchars($ga_id) ?>"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', '<?= htmlspecialchars($ga_id) ?>');
</script>
<?php endif; ?>
<style>
.an-shell { display:flex; min-height:100vh; background:#f1f5f9; }
.an-main  { flex:1; margin-left:232px; padding:0; }
@media(max-width:768px){ .an-main { margin-left:0; } }
.an-topbar { background:#fff; border-bottom:1px solid #e2e8f0; padding:16px 28px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
.an-topbar h1 { font-size:20px; font-weight:700; color:#0f172a; margin:0; }
.an-content { padding:24px 28px 48px; }

/* Period buttons */
.period-bar { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:24px; }
.period-btn { padding:6px 16px; border-radius:20px; font-size:12px; font-weight:500; border:1px solid #e2e8f0; background:#fff; color:#64748b; cursor:pointer; transition:all .15s; }
.period-btn.active { background:#6366f1; border-color:#6366f1; color:#fff; }

/* KPI Cards */
.kpi-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:16px; margin-bottom:24px; }
.kpi-card { background:#fff; border-radius:12px; padding:20px 18px; border:1px solid #e2e8f0; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.kpi-card .lbl { font-size:11px; font-weight:600; color:#94a3b8; text-transform:uppercase; letter-spacing:.06em; }
.kpi-card .val { font-size:26px; font-weight:800; color:#0f172a; margin:6px 0 2px; }
.kpi-card .sub { font-size:12px; color:#64748b; }

/* Live users pill */
.live-pill {
  background:linear-gradient(135deg,#10b981,#059669);
  color:#fff; border-radius:30px; padding:8px 20px;
  display:flex; align-items:center; gap:8px; font-size:15px; font-weight:700;
}
.live-dot { width:9px; height:9px; border-radius:50%; background:#fff; animation:pulse 1.5s infinite; }
@keyframes pulse { 0%,100%{opacity:1}50%{opacity:.3} }

/* Grid layouts */
.two-col { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:24px; }
.three-col { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; margin-bottom:24px; }
@media(max-width:1100px){ .three-col { grid-template-columns:1fr 1fr; } }
@media(max-width:768px)  { .two-col,.three-col { grid-template-columns:1fr; } }
.full-col { margin-bottom:24px; }

/* Chart boxes */
.chart-box { background:#fff; border-radius:12px; padding:20px; border:1px solid #e2e8f0; }
.chart-box h3 { font-size:14px; font-weight:600; color:#64748b; margin:0 0 16px; }

/* Map */
#world-map { height:360px; border-radius:12px; background:#e2e8f0; }

/* Table */
.data-table { width:100%; border-collapse:collapse; font-size:13px; }
.data-table thead th { background:#f8fafc; padding:9px 14px; text-align:left; font-weight:600; color:#64748b; border-bottom:1px solid #e2e8f0; white-space:nowrap; }
.data-table tbody td { padding:9px 14px; border-bottom:1px solid #f1f5f9; color:#1e293b; }
.data-table tbody tr:hover td { background:#f8fafc; }

/* No-config banner */
.banner-warn { background:#fef3c7; border:1px solid #fcd34d; border-radius:12px; padding:20px 24px; margin-bottom:24px; font-size:14px; color:#92400e; }
.banner-warn a { color:#6366f1; font-weight:600; }

.tag-channel { display:inline-block; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600; }
.tag-organic  { background:#d1fae5; color:#065f46; }
.tag-direct   { background:#dbeafe; color:#1e40af; }
.tag-referral { background:#ede9fe; color:#6d28d9; }
.tag-social   { background:#fce7f3; color:#9d174d; }
.tag-paid     { background:#fef3c7; color:#92400e; }
.tag-other    { background:#f1f5f9; color:#64748b; }
</style>
</head>
<!-- ── Mobile top bar ────────────────────────────────────── -->
<div class="adm-mobile-bar">
  <button class="adm-ham" onclick="admToggleSidebar()" aria-label="Menu">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
      <line x1="3" y1="6"  x2="21" y2="6"/>
      <line x1="3" y1="12" x2="21" y2="12"/>
      <line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
  </button>
  <?php if (!empty(get_setting('site_logo', ''))) : ?>
    <img src="<?= htmlspecialchars(get_setting('site_logo', '')) ?>" alt="Logo" style="width: 130px;">
    <span style="font-size:9px;background:#dc2626;color:#fff;padding:2px 6px;border-radius:99px;font-weight:700;text-transform:uppercase;vertical-align:middle;margin-left:4px">Admin</span>
<?php else: ?>
    <span class="adm-mobile-title">
    <?= APP_NAME ?>
    <span style="font-size:9px;background:#dc2626;color:#fff;padding:2px 6px;border-radius:99px;font-weight:700;text-transform:uppercase;vertical-align:middle;margin-left:4px">Admin</span>
  </span>
<?php endif; ?>
</div>
<body>
<div class="an-shell">
<?php require_once __DIR__ . '/sidebar.php'; ?>
<div class="an-main">

<div class="an-topbar">
  <h1>📈 Google Analytics Dashboard</h1>
  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
    <?php if ($ga_enabled && $ga_realtime): ?>
    <div class="live-pill">
      <span class="live-dot"></span>
      <span id="live-count">…</span> online now
    </div>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>/admin/ga-settings.php" style="font-size:13px;color:#6366f1;font-weight:500;">⚙️ Settings</a>
  </div>
</div>

<div class="an-content">

<?php if (!$ga_enabled || !$ga_id): ?>
<div class="banner-warn">
  ⚠️ Google Analytics is not configured.
  <a href="<?= BASE_URL ?>/admin/ga-settings.php">Open GA Settings →</a> to add your Measurement ID and enable tracking.
</div>
<?php elseif (!$ga_email || !$ga_key): ?>
<div class="banner-warn">
  ℹ️ Tracking code is active (<?= htmlspecialchars($ga_id) ?>) but <strong>API credentials are missing</strong> — real-time and report data won't load.
  <a href="<?= BASE_URL ?>/admin/ga-settings.php">Add service account →</a>
</div>
<?php endif; ?>

<!-- Period selector -->
<div class="period-bar">
  <span style="font-size:13px;color:#64748b;align-self:center;">Period:</span>
  <button class="period-btn" onclick="setPeriod('1daysAgo',this)">24h</button>
  <button class="period-btn" onclick="setPeriod('yesterday',this)">Yesterday</button>
  <button class="period-btn active" onclick="setPeriod('7daysAgo',this)">7 Days</button>
  <button class="period-btn" onclick="setPeriod('30daysAgo',this)">30 Days</button>
  <button class="period-btn" onclick="setPeriod('90daysAgo',this)">3 Months</button>
  <button class="period-btn" onclick="setPeriod('180daysAgo',this)">6 Months</button>
  <button class="period-btn" onclick="setPeriod('270daysAgo',this)">9 Months</button>
  <button class="period-btn" onclick="setPeriod('365daysAgo',this)">1 Year</button>
</div>

<!-- KPI Overview Cards -->
<div class="kpi-grid" id="kpi-grid">
  <div class="kpi-card"><div class="lbl">Sessions</div><div class="val" id="k-sessions">…</div></div>
  <div class="kpi-card"><div class="lbl">Total Users</div><div class="val" id="k-users">…</div></div>
  <div class="kpi-card"><div class="lbl">New Users</div><div class="val" id="k-new-users">…</div></div>
  <div class="kpi-card"><div class="lbl">Page Views</div><div class="val" id="k-pageviews">…</div></div>
  <div class="kpi-card"><div class="lbl">Bounce Rate</div><div class="val" id="k-bounce">…</div><div class="sub">% of sessions</div></div>
  <div class="kpi-card"><div class="lbl">Avg Session</div><div class="val" id="k-avg-session">…</div><div class="sub">seconds</div></div>
  <div class="kpi-card"><div class="lbl">Sessions/User</div><div class="val" id="k-spu">…</div></div>
</div>

<!-- Timeline chart (full width) -->
<div class="full-col">
  <div class="chart-box">
    <h3>📅 Sessions &amp; Users Over Time</h3>
    <canvas id="chart-timeline" height="90"></canvas>
  </div>
</div>

<!-- World map -->
<div class="full-col">
  <div class="chart-box">
    <h3>🌍 Live User Map</h3>
    <div id="world-map">
      <div style="display:flex;align-items:center;justify-content:center;height:100%;color:#94a3b8;font-size:14px;">
        Loading map data…
      </div>
    </div>
  </div>
</div>

<!-- Traffic sources + Devices -->
<div class="two-col">
  <div class="chart-box">
    <h3>🚦 Traffic Sources</h3>
    <canvas id="chart-traffic" height="200"></canvas>
    <div id="traffic-table" style="margin-top:16px;"></div>
  </div>
  <div class="chart-box">
    <h3>📱 Device Categories</h3>
    <canvas id="chart-devices" height="200"></canvas>
    <div id="devices-table" style="margin-top:16px;"></div>
  </div>
</div>

<!-- Browsers + Geo table -->
<div class="two-col">
  <div class="chart-box">
    <h3>🌐 Browsers</h3>
    <canvas id="chart-browsers" height="200"></canvas>
  </div>
  <div class="chart-box">
    <h3>🌏 Top Countries &amp; Cities</h3>
    <div style="overflow-x:auto;max-height:280px;overflow-y:auto;" id="geo-table">
      <table class="data-table"><thead><tr><th>Country</th><th>City</th><th>Sessions</th><th>Users</th></tr></thead>
      <tbody id="geo-tbody"><tr><td colspan="4" style="text-align:center;padding:20px;color:#94a3b8;">Loading…</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<!-- Top pages -->
<div class="full-col">
  <div class="chart-box">
    <h3>📄 Top Pages</h3>
    <div style="overflow-x:auto;">
    <table class="data-table">
      <thead><tr><th>Path</th><th>Title</th><th>Views</th><th>Avg Time (s)</th><th>Bounce %</th></tr></thead>
      <tbody id="pages-tbody"><tr><td colspan="5" style="text-align:center;padding:20px;color:#94a3b8;">Loading…</td></tr></tbody>
    </table>
    </div>
  </div>
</div>

</div><!-- .an-content -->
</div><!-- .an-main -->
</div><!-- .an-shell -->

<script>
const BASE = '<?= BASE_URL ?>/admin/ga-dashboard.php';
let currentPeriod = '7daysAgo';
let charts = {};
let mapInstance = null;
let mapMarkers  = [];

// ── Period ────────────────────────────────────────────────────
function setPeriod(p, btn) {
  currentPeriod = p;
  document.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  loadAll();
}

// ── Load all sections ─────────────────────────────────────────
async function loadAll() {
  await Promise.all([
    loadOverview(),
    loadTimeline(),
    loadTraffic(),
    loadDevices(),
    loadBrowsers(),
    loadGeo(),
    loadPages(),
  ]);
}

// ── Overview KPIs ─────────────────────────────────────────────
async function loadOverview() {
  const d = await gaFetch('overview');
  if (!d?.ok) return;
  const r = d.data;
  setText('k-sessions',    fmt(r.sessions));
  setText('k-users',       fmt(r.total_users));
  setText('k-new-users',   fmt(r.new_users));
  setText('k-pageviews',   fmt(r.page_views));
  setText('k-bounce',      r.bounce_rate + '%');
  setText('k-avg-session', r.avg_session_sec + 's');
  setText('k-spu',         r.sessions_per_user);
}

// ── Timeline ──────────────────────────────────────────────────
async function loadTimeline() {
  const d = await gaFetch('timeline');
  if (!d?.ok) return;
  const rows = d.data.rows || [];
  const labels   = rows.map(r => r.date);
  const sessions = rows.map(r => +r.sessions);
  const users    = rows.map(r => +r.users);
  const pvs      = rows.map(r => +r.pageviews);

  const ctx = document.getElementById('chart-timeline');
  if (!ctx) return;
  if (charts['timeline']) charts['timeline'].destroy();
  charts['timeline'] = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [
        { label:'Sessions', data:sessions, borderColor:'#6366f1', backgroundColor:'rgba(99,102,241,.08)', fill:true, tension:.35, pointRadius:2 },
        { label:'Users',    data:users,    borderColor:'#06b6d4', backgroundColor:'rgba(6,182,212,.05)',  fill:true, tension:.35, pointRadius:2 },
        { label:'PVs',      data:pvs,      borderColor:'#10b981', backgroundColor:'rgba(16,185,129,.05)',fill:true, tension:.35, pointRadius:2 },
      ]
    },
    options: { responsive:true, plugins:{ legend:{ position:'top' } }, scales:{ x:{ ticks:{ maxTicksLimit:12, font:{size:11} } }, y:{ beginAtZero:true, ticks:{font:{size:11}} } } }
  });
}

// ── Traffic sources ───────────────────────────────────────────
async function loadTraffic() {
  const d = await gaFetch('traffic_sources');
  if (!d?.ok) return;
  const rows = d.data.rows || [];
  const colors = { 'Organic Search':'#10b981','Direct':'#6366f1','Referral':'#f59e0b','Paid Search':'#ef4444','Organic Social':'#ec4899','Display':'#06b6d4' };
  const labels = rows.map(r => r.channel);
  const data   = rows.map(r => +r.sessions);
  const bgs    = labels.map(l => colors[l] || '#94a3b8');

  buildDoughnut('chart-traffic', labels, data, bgs);

  // Table
  document.getElementById('traffic-table').innerHTML = `
    <table class="data-table">
      <thead><tr><th>Channel</th><th>Sessions</th><th>Users</th><th>Bounce</th></tr></thead>
      <tbody>${rows.map(r => `<tr>
        <td><span class="tag-channel tag-${r.channel.toLowerCase().split(' ')[0]}">${esc(r.channel)}</span></td>
        <td>${fmt(+r.sessions)}</td><td>${fmt(+r.users)}</td><td>${r.bounce_rate}%</td>
      </tr>`).join('')}</tbody>
    </table>`;
}

// ── Devices ───────────────────────────────────────────────────
async function loadDevices() {
  const d = await gaFetch('devices');
  if (!d?.ok) return;
  const rows = d.data.rows || [];
  const icons = { desktop:'🖥️', mobile:'📱', tablet:'📲' };
  buildDoughnut('chart-devices',
    rows.map(r => `${icons[r.device.toLowerCase()]||'💻'} ${r.device}`),
    rows.map(r => +r.sessions),
    ['#6366f1','#06b6d4','#10b981','#f59e0b']
  );
  document.getElementById('devices-table').innerHTML = `
    <table class="data-table">
      <thead><tr><th>Device</th><th>Sessions</th><th>Users</th></tr></thead>
      <tbody>${rows.map(r => `<tr>
        <td>${icons[r.device.toLowerCase()]||'💻'} ${esc(r.device)}</td>
        <td>${fmt(+r.sessions)}</td><td>${fmt(+r.users)}</td>
      </tr>`).join('')}</tbody>
    </table>`;
}

// ── Browsers ──────────────────────────────────────────────────
async function loadBrowsers() {
  const d = await gaFetch('browsers');
  if (!d?.ok) return;
  const rows  = d.data.rows || [];
  const ctx   = document.getElementById('chart-browsers');
  if (!ctx) return;
  if (charts['browsers']) charts['browsers'].destroy();
  charts['browsers'] = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: rows.map(r => r.browser),
      datasets:[{ label:'Sessions', data:rows.map(r=>+r.sessions), backgroundColor:'#6366f1cc', borderRadius:4 }]
    },
    options:{ responsive:true, indexAxis:'y', plugins:{legend:{display:false}}, scales:{ x:{beginAtZero:true,ticks:{font:{size:11}}}, y:{ticks:{font:{size:11}}} } }
  });
}

// ── Geo ───────────────────────────────────────────────────────
async function loadGeo() {
  const d = await gaFetch('geo');
  if (!d?.ok) return;
  const rows = d.data.rows || [];

  // Table
  document.getElementById('geo-tbody').innerHTML = rows.length ?
    rows.map(r => `<tr>
      <td>${esc(r.country)}</td><td>${esc(r.city)}</td>
      <td>${fmt(+r.sessions)}</td><td>${fmt(+r.users)}</td>
    </tr>`).join('') :
    '<tr><td colspan="4" style="text-align:center;padding:20px;color:#94a3b8;">No data</td></tr>';

  // World map
  initMap(rows);
}

// ── Pages ─────────────────────────────────────────────────────
async function loadPages() {
  const d = await gaFetch('pages');
  if (!d?.ok) return;
  const rows = d.data.rows || [];
  document.getElementById('pages-tbody').innerHTML = rows.length ?
    rows.map(r => `<tr>
      <td style="font-family:monospace;font-size:12px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(r.path)}">${esc(r.path)}</td>
      <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(r.title)}</td>
      <td>${fmt(+r.views)}</td>
      <td>${r.avg_time}s</td>
      <td>${r.bounce_rate}%</td>
    </tr>`).join('') :
    '<tr><td colspan="5" style="text-align:center;padding:20px;color:#94a3b8;">No data</td></tr>';
}

// ── World map ─────────────────────────────────────────────────
// Country → approx lat/lon lookup (enough for geo dots)
const GEO_COORDS = {
  'India':[20.59,78.96],'United States':[37.09,-95.71],'United Kingdom':[55.37,-3.43],
  'Germany':[51.16,10.45],'France':[46.23,2.21],'Brazil':[-14.23,-51.93],
  'Canada':[56.13,-106.34],'Australia':[-25.27,133.77],'Japan':[36.20,138.25],
  'China':[35.86,104.19],'Russia':[61.52,105.31],'South Africa':[-30.56,22.93],
  'Mexico':[23.63,-102.55],'Indonesia':[-0.78,113.92],'Nigeria':[9.08,8.67],
  'Pakistan':[30.37,69.34],'Bangladesh':[23.68,90.35],'Singapore':[1.35,103.81],
  'Netherlands':[52.13,5.29],'Spain':[40.46,-3.74],'Italy':[41.87,12.56],
  'Turkey':[38.96,35.24],'Saudi Arabia':[23.88,45.07],'UAE':[23.42,53.84],
};

function initMap(rows) {
  const el = document.getElementById('world-map');
  if (!el) return;

  if (!mapInstance) {
    mapInstance = L.map('world-map', { center:[20,0], zoom:2, zoomControl:true });
    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
      attribution:'&copy; CartoDB',
      subdomains:'abcd', maxZoom:19
    }).addTo(mapInstance);
  }

  // Clear old markers
  mapMarkers.forEach(m => m.remove());
  mapMarkers = [];

  const maxSessions = Math.max(...rows.map(r => +r.sessions), 1);

  rows.forEach(r => {
    const coords = GEO_COORDS[r.country];
    if (!coords) return;
    const radius = 4 + (r.sessions / maxSessions) * 20;
    const m = L.circleMarker(coords, {
      radius,
      fillColor:'#10b981', fillOpacity:.7,
      color:'#fff', weight:1,
    }).bindPopup(`<b>${r.country}</b><br>${r.city}<br>${fmt(+r.sessions)} sessions`).addTo(mapInstance);
    mapMarkers.push(m);
  });
}

// ── Real-time live users ──────────────────────────────────────
<?php if ($ga_enabled && $ga_realtime && $ga_email && $ga_key): ?>
async function loadRealtime() {
  const d = await gaFetch('realtime');
  if (!d?.ok) return;
  setText('live-count', fmt(d.data.active_users));
}
setInterval(loadRealtime, 30000);
loadRealtime();
<?php endif; ?>

// ── Helpers ───────────────────────────────────────────────────
async function gaFetch(report) {
  try {
    const r = await fetch(`${BASE}?ajax=ga_proxy&report=${report}&period=${encodeURIComponent(currentPeriod)}`);
    return await r.json();
  } catch(e) { return null; }
}

function buildDoughnut(id, labels, data, colors) {
  const ctx = document.getElementById(id);
  if (!ctx) return;
  if (charts[id]) charts[id].destroy();
  charts[id] = new Chart(ctx, {
    type:'doughnut',
    data:{ labels, datasets:[{ data, backgroundColor:colors, borderWidth:2, hoverOffset:6 }] },
    options:{ responsive:true, plugins:{ legend:{ position:'right', labels:{font:{size:12}} } } }
  });
}

function setText(id, val) { const el=document.getElementById(id); if(el) el.textContent = val ?? '—'; }
function fmt(n) { return (n||0).toLocaleString(); }
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// ── Boot ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', loadAll);
</script>
</body>
</html>
