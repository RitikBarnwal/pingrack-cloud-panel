<?php
/**
 * includes/currency.php
 *
 * Currency conversion using the live exchange rate API.
 *
 * Flow:
 *   Provider price (EUR or USD)
 *     → apply margin %
 *     → convert to USD   (if base is EUR)
 *     → convert to INR
 *     → save both in plan_pricing table
 *
 * Rates are fetched from the live API and cached in DB (settings table).
 * Cron refreshes every 3 hours.
 * Sync button also refreshes rates before applying.
 */

declare(strict_types=1);

/* ── API endpoint ──────────────────────────────────────────── */

define('CV_FX_API_BASE', 'https://api.frankfurter.app/latest');

/* ── Fetch live rate from API ──────────────────────────────── */

/**
 * Returns the exchange rate for converting $from → $to.
 * e.g. get_live_rate('EUR','USD') returns ~1.08
 *
 * On failure returns null — callers must handle null.
 */
function get_live_rate(string $from, string $to): ?float
{
    if (strtoupper($from) === strtoupper($to)) return 1.0;

    $from = strtoupper($from);
    $to   = strtoupper($to);

    $url = "https://api.frankfurter.app/latest?from={$from}&to={$to}";

    $ctx = stream_context_create([
        'http' => ['timeout' => 8, 'ignore_errors' => true],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if (!$raw) {
        error_log("[currency] API unreachable: $url");
        return null;
    }

    $data = json_decode($raw, true);
    // frankfurter returns: {"amount":1,"base":"EUR","date":"...","rates":{"USD":1.08}}
    if (empty($data['rates'][$to])) {
        error_log("[currency] API bad response: $raw");
        return null;
    }

    return (float)$data['rates'][$to];
}

/* ── Get rate with DB cache ────────────────────────────────── */

/**
 * Returns rate from DB cache.
 * If cache is older than $max_age_seconds (default 3hr), refreshes from API.
 * Falls back to DB cached value if API is down.
 */
function get_rate(string $from, string $to, int $max_age_seconds = 10800): float
{
    $from = strtoupper($from);
    $to   = strtoupper($to);

    if ($from === $to) return 1.0;

    $key       = "fx_rate_{$from}_{$to}";
    $ts_key    = "fx_rate_{$from}_{$to}_ts";
    $cached    = get_setting($key, '');
    $cached_ts = (int)get_setting($ts_key, '0');

    $is_fresh = ($cached !== '' && (time() - $cached_ts) < $max_age_seconds);

    if ($is_fresh) {
        return (float)$cached;
    }

    // Fetch fresh rate
    $live = get_live_rate($from, $to);

    if ($live !== null) {
        // Save to DB
        db()->prepare("INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=?")->execute([$key, $live, $live]);
        db()->prepare("INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=?")->execute([$ts_key, time(), time()]);
        return $live;
    }

    // API down — use stale cache if available
    if ($cached !== '') {
        error_log("[currency] Using stale cache for {$from}→{$to}");
        return (float)$cached;
    }

    // Last resort hardcoded fallback
    $fallbacks = [
        'EUR_USD' => 1.08,
        'USD_EUR' => 0.926,
        'EUR_INR' => 101.80,
        'USD_INR' => 94.25,
        'INR_USD' => 0.01061,
        'INR_EUR' => 0.00982,
    ];
    $fb = $fallbacks["{$from}_{$to}"] ?? 1.0;
    error_log("[currency] Using hardcoded fallback for {$from}→{$to} = $fb");
    return $fb;
}

/* ── Refresh all needed rates & store in DB ────────────────── */

/**
 * Called by cron every 3 hours AND by sync button.
 * Fetches EUR→USD, EUR→INR, USD→INR, USD→EUR and caches them.
 *
 * Returns array of results for logging.
 */
function refresh_all_rates(): array
{
    $pairs = [
        ['EUR','USD'],
        ['EUR','INR'],
        ['USD','INR'],
        ['USD','EUR'],
        ['INR','USD'],
    ];

    $results = [];

    foreach ($pairs as [$from, $to]) {
        $rate = get_live_rate($from, $to);
        if ($rate !== null) {
            $key    = "fx_rate_{$from}_{$to}";
            $ts_key = "fx_rate_{$from}_{$to}_ts";
            db()->prepare("INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=?")->execute([$key, $rate, $rate]);
            db()->prepare("INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=?")->execute([$ts_key, time(), time()]);
            $results[] = ['pair' => "{$from}→{$to}", 'rate' => $rate, 'ok' => true];
        } else {
            $results[] = ['pair' => "{$from}→{$to}", 'rate' => null, 'ok' => false];
        }
    }

    return $results;
}

/* ── Core price pipeline ───────────────────────────────────── */

/**
 * Main function used at sync time.
 *
 * Input:  base price in provider's currency (e.g. EUR 0.00595)
 *         provider currency (EUR or USD)
 *         margin % (e.g. 20 for 20%)
 *
 * Output: [
 *   'base_original'   => 0.00595,        // original from provider
 *   'base_currency'   => 'EUR',
 *   'with_margin'     => 0.00714,        // after margin
 *   'price_usd'       => 0.007711,       // in USD (hourly)
 *   'price_inr'       => 0.7272,         // in INR (hourly)
 *   'price_usd_mo'    => 5.63,           // monthly approx (730hr)
 *   'price_inr_mo'    => 530.85,
 *   'margin_pct'      => 20.0,
 *   'eur_usd_rate'    => 1.08,
 *   'usd_inr_rate'    => 94.25,
 * ]
 */
function compute_price(float $base_price, string $base_currency, float $margin_pct): array
{
    $base_currency = strtoupper($base_currency);

    // Step 1: apply margin
    $with_margin = $base_price * (1 + $margin_pct / 100);

    // Step 2: convert to USD
    if ($base_currency === 'USD') {
        $eur_usd_rate = null;
        $price_usd    = $with_margin;
    } else {
        // EUR → USD
        $eur_usd_rate = get_rate('EUR', 'USD');
        $price_usd    = $with_margin * $eur_usd_rate;
    }

    // Step 3: USD → INR
    $usd_inr_rate = get_rate('USD', 'INR');
    $price_inr    = $price_usd * $usd_inr_rate;

    return [
        'base_original' => $base_price,
        'base_currency' => $base_currency,
        'with_margin'   => round($with_margin, 8),
        'price_usd'     => round($price_usd,   8),
        'price_inr'     => round($price_inr,   6),
        'price_usd_mo'  => round($price_usd * 730, 4),
        'price_inr_mo'  => round($price_inr * 730, 2),
        'margin_pct'    => $margin_pct,
        'eur_usd_rate'  => $eur_usd_rate ?? null,
        'usd_inr_rate'  => $usd_inr_rate,
    ];
}

/* ── User-facing price in their currency ───────────────────── */

/**
 * Given a server's stored price_usd (hourly), return price in user currency.
 * Used on dashboard / servers list / create page.
 *
 * Uses DB-cached rates (no live API call on every page load).
 */
function price_for_user(float $price, string $user_currency = 'INR'): float
{
    // INR-only: prices are already stored in INR, no conversion.
    return round($price, 4);
}

/**
 * Format price with the ₹ symbol for display (INR-only).
 */
function fmt_user_price(float $price, string $user_currency = 'INR', bool $monthly = false): string
{
    $amount = round($price * ($monthly ? 730 : 1), 2);
    return '₹' . number_format($amount, 2);
}

function currency_symbol(string $currency): string
{
    return '₹'; // INR-only platform
}

/* ── Get cached rates summary (for admin display) ─────────────*/

function get_cached_rates_summary(): array
{
    $pairs = ['EUR_USD','EUR_INR','USD_INR','USD_EUR'];
    $out   = [];
    foreach ($pairs as $pair) {
        [$from, $to] = explode('_', $pair);
        $rate = get_setting("fx_rate_{$from}_{$to}", '');
        $ts   = (int)get_setting("fx_rate_{$from}_{$to}_ts", '0');
        $out[] = [
            'from'       => $from,
            'to'         => $to,
            'rate'       => $rate !== '' ? (float)$rate : null,
            'cached_at'  => $ts ? date('d M Y, H:i', $ts) : null,
            'age_min'    => $ts ? round((time() - $ts) / 60) : null,
            'fresh'      => $ts && (time() - $ts) < 10800,
        ];
    }
    return $out;
}
