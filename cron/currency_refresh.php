<?php
/**
 * cron/currency_refresh.php
 * Runs every 3 hours. Refreshes rates + recalculates plan prices.
 * Only touches plans that admin has manually added in provider_plans.
 *
 * *   0 * / 3 * * * /usr/local/bin/php /home/cloudgreat/public_html/cron/currency_refresh.php >> /var/log/cv_currency_refresh.log 2>&1
 *
 * 
 *
 */


require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/currency.php';
require_once __DIR__ . '/../includes/admin.php';

if (!function_exists('clog')) {
    function clog(string $m): void {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n";
        flush();
    }
}

clog('Currency refresh started.');

// Step 1: Fetch live rates
$results = refresh_all_rates();
foreach ($results as $r) {
    clog(($r['ok']?'✓':'✗')." {$r['pair']}" . ($r['rate']?' = '.$r['rate']:''));
}

// Step 2: Recalculate prices for each active provider
$providers = get_all_providers();
$total = 0;
foreach ($providers as $prov) {
    if (!$prov['is_active']) continue;
    $n = recalculate_provider_prices((int)$prov['id'], (float)$prov['margin_pct']);
    clog("✓ {$prov['display_name']}: {$n} plans recalculated");
    $total += $n;
}

clog("Done. Total plans recalculated: {$total}");
