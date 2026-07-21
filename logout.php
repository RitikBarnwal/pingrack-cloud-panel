<?php
require_once __DIR__ . '/includes/bootstrap.php';
logout_user();
header('Location: ' . BASE_URL . '/login.php');
exit;
