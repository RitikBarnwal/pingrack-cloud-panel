<?php
/**
 * includes/page.php
 *
 * Shared page wrapper — include at top of every dashboard page.
 * Sets up common vars needed by sidebar.php.
 *
 * Usage:
 *   require_once __DIR__ . '/../includes/page.php';
 *   // then echo page_open('Page Title');
 *   // ... your content ...
 *   // echo page_close();
 */
declare(strict_types=1);

if (!function_exists('page_vars')) {
    function page_vars(): array {
        $user    = current_user();
        $currency= strtoupper($user['currency'] ?? 'USD');
        return [
            'user'     => $user,
            'app_name' => APP_NAME,
            'currency' => $currency,
            'curr_sym' => currency_symbol($currency),
            'avatar'   => strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1)),
            'fname'    => htmlspecialchars($user['full_name'] ?: $user['username']),
            'uname'    => htmlspecialchars($user['username']),
            'balance'  => (float)$user['wallet_balance'],
            'csrf'     => csrf_token(),
        ];
    }
}
