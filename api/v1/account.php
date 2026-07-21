<?php
/**
 * api/v1/account.php
 *
 * GET /api/v1/account  — current user info + wallet balance
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') api_error('Method not allowed.', 405);

$auth = api_auth();

api_ok([
    'account' => [
        'id'             => (int)$auth['user_id'],
        'username'       => $auth['username'],
        'email'          => $auth['email'],
        'full_name'      => $auth['full_name'] ?? '',
        'currency'       => strtoupper($auth['currency'] ?? 'INR'),
        'wallet_balance' => (float)$auth['wallet_balance'],
        'country'        => $auth['country'] ?? 'IN',
        'role'           => $auth['role'],
    ],
]);
