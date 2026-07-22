<?php
/**
 * install-db.php — one-shot DB migrator (run in browser)
 *
 * Creates any MISSING tables for the VPS Packages feature (WHMCS-style).
 * Uses the DB credentials from includes/config.php via db().
 * Safe to re-run: every statement is CREATE TABLE IF NOT EXISTS / additive.
 *
 * Access: admin only. Visit  https://<your-domain>/install-db.php
 * Delete this file (or leave it — it's admin-gated) once tables exist.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/admin.php';
require_admin(); // must be logged in as admin

header('Content-Type: text/html; charset=utf-8');

// ── Table definitions ─────────────────────────────────────────
$tables = [

// WHMCS-style sellable VPS package linked to a Virtualizor plan/node/OS
'vps_packages' => "
CREATE TABLE IF NOT EXISTS vps_packages (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_id   INT UNSIGNED NOT NULL,            -- providers.id (a virtualizor provider)
    name          VARCHAR(120)  NOT NULL,
    slug          VARCHAR(140)  NOT NULL,
    description   TEXT          NULL,
    virt_plid     VARCHAR(64)   NOT NULL,           -- Virtualizor plan id (plid)
    virt_serid    VARCHAR(64)   NOT NULL,           -- Virtualizor node/server id (serid)
    virt_osid     VARCHAR(64)   NOT NULL,           -- default OS template id (osid)
    os_label      VARCHAR(120)  NOT NULL DEFAULT '',
    vcpu          INT UNSIGNED  NOT NULL DEFAULT 1,
    ram_gb        DECIMAL(6,1)  NOT NULL DEFAULT 1,
    disk_gb       INT UNSIGNED  NOT NULL DEFAULT 25,
    bandwidth_gb  INT UNSIGNED  NOT NULL DEFAULT 0,
    price_inr     DECIMAL(10,2) NOT NULL DEFAULT 0, -- monthly, INR
    price_usd     DECIMAL(10,2) NOT NULL DEFAULT 0, -- monthly, USD
    billing_cycle VARCHAR(20)   NOT NULL DEFAULT 'monthly',
    is_active     TINYINT(1)    NOT NULL DEFAULT 1,
    sort_order    INT           NOT NULL DEFAULT 0,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_slug (slug),
    KEY idx_provider (provider_id),
    KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// Order/provision ledger for package purchases
'vps_package_orders' => "
CREATE TABLE IF NOT EXISTS vps_package_orders (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED  NOT NULL,
    package_id   INT UNSIGNED  NOT NULL,
    server_id    INT UNSIGNED  NULL,               -- servers.id once provisioned
    vpsid        VARCHAR(64)   NULL,               -- Virtualizor VPS id
    status       ENUM('pending','active','failed','refunded') NOT NULL DEFAULT 'pending',
    amount       DECIMAL(10,2) NOT NULL DEFAULT 0,
    currency     VARCHAR(8)    NOT NULL DEFAULT 'INR',
    error        VARCHAR(500)  NULL,
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user (user_id),
    KEY idx_pkg (package_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// Per-package billing cycles (1,3,6,12,24,36 months) with enable + price
'package_cycles' => "
CREATE TABLE IF NOT EXISTS package_cycles (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    package_id  INT UNSIGNED  NOT NULL,
    months      SMALLINT UNSIGNED NOT NULL,        -- 1,3,6,12,24,36
    price_inr   DECIMAL(10,2) NOT NULL DEFAULT 0,  -- total for the whole cycle
    price_usd   DECIMAL(10,2) NOT NULL DEFAULT 0,
    is_enabled  TINYINT(1)    NOT NULL DEFAULT 0,
    UNIQUE KEY uq_pkg_months (package_id, months),
    KEY idx_pkg (package_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

];

// ── Idempotent column additions (MySQL-safe: checks information_schema) ──
$alters = [
    // package type: vps (auto-provision) or dedicated (manual, no panel)
    ['vps_packages',       'ptype',        "ALTER TABLE vps_packages ADD COLUMN ptype ENUM('vps','dedicated') NOT NULL DEFAULT 'vps' AFTER name"],
    ['vps_packages',       'cpu_label',    "ALTER TABLE vps_packages ADD COLUMN cpu_label VARCHAR(160) NOT NULL DEFAULT '' AFTER os_label"],
    // location grouping — deploy page shows locations first, then plans within
    ['vps_packages',       'location',     "ALTER TABLE vps_packages ADD COLUMN location VARCHAR(120) NOT NULL DEFAULT '' AFTER slug"],
    ['vps_packages',       'location_flag',"ALTER TABLE vps_packages ADD COLUMN location_flag VARCHAR(8) NOT NULL DEFAULT '' AFTER location"],
    // servers: prepaid support so hourly cron can skip + expiry can suspend
    ['servers',            'billing_type', "ALTER TABLE servers ADD COLUMN billing_type ENUM('hourly','prepaid') NOT NULL DEFAULT 'hourly'"],
    ['servers',            'expires_at',   "ALTER TABLE servers ADD COLUMN expires_at DATETIME NULL"],
    // VPS vs Dedicated so 'My Servers' and 'Dedicated' show the right ones
    ['servers',            'server_type',  "ALTER TABLE servers ADD COLUMN server_type ENUM('vps','dedicated') NOT NULL DEFAULT 'vps'"],
    ['servers',            'billing_months',"ALTER TABLE servers ADD COLUMN billing_months SMALLINT UNSIGNED NOT NULL DEFAULT 1"],
    // orders: which cycle + when it expires
    ['vps_package_orders', 'cycle_months', "ALTER TABLE vps_package_orders ADD COLUMN cycle_months SMALLINT UNSIGNED NOT NULL DEFAULT 1"],
    ['vps_package_orders', 'expires_at',   "ALTER TABLE vps_package_orders ADD COLUMN expires_at DATETIME NULL"],
    // provider API creds in real columns (instead of a JSON blob in api_key)
    ['providers',          'panel_url',    "ALTER TABLE providers ADD COLUMN panel_url VARCHAR(255) NOT NULL DEFAULT '' AFTER api_key"],
    ['providers',          'api_pass',     "ALTER TABLE providers ADD COLUMN api_pass VARCHAR(255) NOT NULL DEFAULT '' AFTER panel_url"],
    // server location defined once at the provider level (packages inherit it)
    ['providers',          'location',     "ALTER TABLE providers ADD COLUMN location VARCHAR(120) NOT NULL DEFAULT '' AFTER api_pass"],
    ['providers',          'location_flag',"ALTER TABLE providers ADD COLUMN location_flag VARCHAR(8) NOT NULL DEFAULT '' AFTER location"],
    // Virtualizor node (serid) chosen once at the provider level
    ['providers',          'default_serid',"ALTER TABLE providers ADD COLUMN default_serid VARCHAR(64) NOT NULL DEFAULT '' AFTER location_flag"],
];

// ── Run + report ──────────────────────────────────────────────
$pdo = db();
$results = [];

// Which of our tables already exist?
$existing = [];
try {
    foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) $existing[$t] = true;
} catch (Throwable $e) {}

foreach ($tables as $name => $sql) {
    $was = isset($existing[$name]);
    try {
        $pdo->exec($sql);
        $results[$name] = $was ? ['ok', 'already existed — left unchanged'] : ['new', 'created'];
    } catch (Throwable $e) {
        $results[$name] = ['err', $e->getMessage()];
    }
}

// Column migrations (add only if missing)
$dbname = DB_NAME;
$colExists = function(string $table, string $col) use ($pdo, $dbname): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?"
    );
    $st->execute([$dbname, $table, $col]);
    return (int)$st->fetchColumn() > 0;
};
foreach ($alters as [$table, $col, $sql]) {
    $label = "$table.$col";
    try {
        if (!isset($existing[$table]) && $table !== 'servers') {
            $results[$label] = ['ok', 'parent table absent — skipped']; continue;
        }
        if ($colExists($table, $col)) {
            $results[$label] = ['ok', 'column already present'];
        } else {
            $pdo->exec($sql);
            $results[$label] = ['new', 'column added'];
        }
    } catch (Throwable $e) {
        $results[$label] = ['err', $e->getMessage()];
    }
}

// ── Migrate legacy JSON creds → panel_url / api_key / api_pass columns ──
try {
    if ($colExists('providers', 'panel_url') && $colExists('providers', 'api_pass')) {
        $rows = $pdo->query("SELECT id, api_key, panel_url, api_pass FROM providers")->fetchAll();
        $migrated = 0;
        foreach ($rows as $r) {
            // Only migrate rows that still have JSON in api_key and empty columns
            $ak = (string)$r['api_key'];
            if (($r['panel_url'] === '' || $r['api_pass'] === '') && str_starts_with(ltrim($ak), '{')) {
                $j = json_decode($ak, true);
                if (is_array($j) && !empty($j['api_key'])) {
                    $pdo->prepare("UPDATE providers SET panel_url=?, api_key=?, api_pass=? WHERE id=?")
                        ->execute([
                            $j['panel_url'] ?? '',
                            $j['api_key']   ?? '',
                            $j['api_pass']  ?? '',
                            $r['id'],
                        ]);
                    $migrated++;
                }
            }
        }
        $results['providers.creds_migration'] = ['ok', $migrated ? "$migrated JSON credential row(s) split into columns" : 'nothing to migrate'];
    }
} catch (Throwable $e) {
    $results['providers.creds_migration'] = ['err', $e->getMessage()];
}
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DB Installer — <?= htmlspecialchars(APP_NAME) ?></title>
<style>
  body{font-family:system-ui,-apple-system,'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:40px 16px}
  .box{max-width:680px;margin:0 auto;background:#1e293b;border:1px solid #334155;border-radius:16px;overflow:hidden}
  .hd{padding:22px 26px;border-bottom:1px solid #334155}
  .hd h1{margin:0;font-size:19px}
  .hd p{margin:6px 0 0;color:#94a3b8;font-size:13px}
  .bd{padding:14px 26px 26px}
  .row{display:flex;align-items:center;gap:12px;padding:13px 0;border-bottom:1px solid #24324a}
  .row:last-child{border-bottom:none}
  .tag{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;padding:4px 9px;border-radius:99px;flex-shrink:0}
  .new{background:#052e1a;color:#4ade80}
  .ok{background:#0c2340;color:#60a5fa}
  .err{background:#3b0d0d;color:#f87171}
  .nm{font-weight:700;font-family:ui-monospace,monospace}
  .ms{color:#94a3b8;font-size:13px;margin-left:auto;text-align:right}
  .done{margin-top:20px;padding:14px 16px;background:#052e1a;border:1px solid #16643a;border-radius:10px;color:#86efac;font-size:14px}
  a{color:#60a5fa}
</style></head><body>
<div class="box">
  <div class="hd">
    <h1>Database Installer</h1>
    <p>Creating missing tables for VPS Packages. Safe to re-run.</p>
  </div>
  <div class="bd">
    <?php foreach ($results as $name => [$state, $msg]): ?>
    <div class="row">
      <span class="tag <?= $state ?>"><?= $state === 'new' ? 'Created' : ($state === 'ok' ? 'Exists' : 'Error') ?></span>
      <span class="nm"><?= htmlspecialchars($name) ?></span>
      <span class="ms"><?= htmlspecialchars($msg) ?></span>
    </div>
    <?php endforeach; ?>
    <div class="done">
      ✓ Done. Now open <a href="<?= BASE_URL ?>/admin/vps-packages.php">Admin → VPS Packages</a> to create packages.
      You can delete <code>install-db.php</code> when finished.
    </div>
  </div>
</div>
</body></html>
