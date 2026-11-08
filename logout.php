<?php
/**
 * StockPlus - User Logout Script
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

auth_logout();

// Start a fresh session to deliver logout notice
auth_start_session();
$_SESSION['flash_success'] = 'You have been logged out securely.';

header('Location: login.php');
exit;

