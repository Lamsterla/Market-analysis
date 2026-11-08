<?php
/**
 * Shared Header Template - Left Sidebar Navigation Layout
 * Stock Market Automated System (Paper Trading Simulator)
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// Protect dashboard pages from unauthenticated access
if (function_exists('auth_require_login')) {
    auth_require_login();
}

$currentUser = function_exists('auth_current_user') ? auth_current_user() : null;
if (!$currentUser) {
    $currentUser = ['full_name' => 'Paper Trader', 'email' => 'trader@stockplus.local'];
}

$userInitials = 'TR';
if (!empty($currentUser['full_name'])) {
    $parts = explode(' ', trim($currentUser['full_name']));
    $userInitials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$monitorStatus = get_monitor_status();

// Check database status
$dbCheck = check_db_status();

// Retrieve account summary for the left sidebar widget
$sidebarSummary = [
    'available_cash' => 100000.00,
    'portfolio_value' => 0.00,
    'total_account_value' => 100000.00,
    'currency' => 'INR'
];
try {
    $sidebarSummary = get_portfolio_summary();
} catch (Throwable $e) {
    // Keep defaults if db offline
}
$sidebarCurr = ($sidebarSummary['currency'] ?? 'INR') === 'USD' ? '$' : '₹';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Stock Market Automated System (Paper Trading)') ?></title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Custom CSS with Cache Busting Timestamp -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body class="app-body">

    <!-- Top Paper Trading Caution Banner -->
    <div class="simulation-banner">
        <div class="banner-content">
            <span class="banner-badge">SIMULATION ONLY</span>
            <span>This is strictly a paper-trading system using virtual money and Yahoo Finance market data. No real orders are ever placed.</span>
        </div>
    </div>

    <!-- App Main Layout Container: Left Sidebar + Right Main Viewport -->
    <div class="app-layout">
        
        <!-- Left Navigation Sidebar -->
        <aside class="app-sidebar" id="appSidebar">
            <div class="sidebar-header">
                <a href="index.php" class="sidebar-brand">
                    <span class="brand-symbol">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
                            <polyline points="17 6 23 6 23 12"></polyline>
                        </svg>
                    </span>
                    <div class="brand-text-group">
                        <span class="brand-title">Stock<strong>Pulse</strong></span>
                        <span class="brand-badge">Paper Trade</span>
                    </div>
                </a>
                <button type="button" class="sidebar-close-btn" onclick="toggleMobileSidebar()" aria-label="Close menu">&times;</button>
            </div>

            <!-- Left Navigation Menu -->
            <nav class="sidebar-menu">
                <div class="menu-heading">Main Navigation</div>

                <a href="index.php" class="menu-link <?= ($currentPage === 'index.php' || $currentPage === 'dashboard.php') ? 'active' : '' ?>">
                    <span class="menu-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"></rect><rect x="14" y="3" width="7" height="5"></rect><rect x="14" y="12" width="7" height="9"></rect><rect x="3" y="16" width="7" height="5"></rect></svg>
                    </span>
                    <span class="menu-text">Dashboard</span>
                </a>

                <a href="portfolio.php" class="menu-link <?= $currentPage === 'portfolio.php' ? 'active' : '' ?>">
                    <span class="menu-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                    </span>
                    <span class="menu-text">Portfolio</span>
                </a>

                <a href="buy.php" class="menu-link <?= $currentPage === 'buy.php' ? 'active' : '' ?>">
                    <span class="menu-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
                    </span>
                    <span class="menu-text">Buy Stocks</span>
                    <span class="menu-badge-pill">Trade</span>
                </a>

                <a href="trade_history.php" class="menu-link <?= $currentPage === 'trade_history.php' ? 'active' : '' ?>">
                    <span class="menu-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    </span>
                    <span class="menu-text">Trade History</span>
                </a>

                <a href="stock_analysis.php" class="menu-link <?= $currentPage === 'stock_analysis.php' ? 'active' : '' ?>">
                    <span class="menu-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                    </span>
                    <span class="menu-text">Stock Analysis</span>
                    <span class="menu-badge-pro">Pro</span>
                </a>
            </nav>

            <!-- Sidebar Virtual Wallet Card -->
            <div class="sidebar-wallet-card">
                <div class="wallet-header">
                    <span class="wallet-label">Virtual Cash</span>
                    <span class="wallet-tag">Simulated</span>
                </div>
                <div class="wallet-amount font-mono">
                    <?= $sidebarCurr . number_format($sidebarSummary['available_cash'], 2) ?>
                </div>
                <div class="wallet-footer">
                    <span class="wallet-subtext">Total Value: <?= $sidebarCurr . number_format($sidebarSummary['total_account_value'], 2) ?></span>
                </div>
                <div class="wallet-actions">
                    <a href="buy.php" class="btn btn-sm btn-primary" style="width: 100%;">+ Trade Now</a>
                </div>
            </div>

            <!-- Sidebar Footer Info -->
            <div class="sidebar-footer">
                <div class="user-profile-badge" style="justify-content: space-between; width: 100%;">
                    <div style="display: flex; align-items: center; gap: 10px; overflow: hidden;">
                        <div class="user-avatar"><?= htmlspecialchars($userInitials) ?></div>
                        <div class="user-info" style="overflow: hidden;">
                            <span class="user-name" style="white-space: nowrap; text-overflow: ellipsis; overflow: hidden;" title="<?= htmlspecialchars($currentUser['full_name']) ?>"><?= htmlspecialchars($currentUser['full_name']) ?></span>
                            <span class="user-status">Virtual Mode</span>
                        </div>
                    </div>
                    <a href="logout.php" class="btn btn-sm btn-outline-danger" title="Sign out of StockPlus" style="padding: 4px 8px; font-size: 0.75rem; text-decoration: none;">
                        Logout
                    </a>
                </div>
            </div>
        </aside>

        <!-- Mobile Sidebar Backdrop Overlay -->
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleMobileSidebar()"></div>

        <!-- Right Main Viewport Area -->
        <div class="app-main-viewport">

            <!-- Top Header Action Bar -->
            <header class="app-topbar">
                <div class="topbar-left">
                    <button type="button" class="mobile-toggle-btn" onclick="toggleMobileSidebar()" aria-label="Toggle navigation menu">
                        <span class="hamburger-line"></span>
                        <span class="hamburger-line"></span>
                        <span class="hamburger-line"></span>
                    </button>
                    <div class="topbar-crumb-info">
                        <span class="topbar-site-badge">STOCKFUL SYSTEM</span>
                        <span class="topbar-context-page"><?= htmlspecialchars(str_replace(['.php', '_'], ['', ' '], ucwords($currentPage ?: 'Dashboard', ' _'))) ?></span>
                    </div>
                </div>

                <div class="topbar-right">
                    <!-- Monitor Status Pill (Preserves IDs and Click Handler for app.js) -->
                    <div id="monitorStatusPill" class="status-pill <?= $monitorStatus['is_active'] ? 'status-active' : 'status-inactive' ?>" onclick="toggleMonitorModal()" title="View automatic stop-loss monitor details">
                        <span class="pulse-dot"></span>
                        <span id="monitorStatusText">
                            <?= $monitorStatus['is_active'] ? 'Monitor Active' : 'Monitor Inactive' ?>
                        </span>
                        <span class="info-icon">ℹ️</span>
                    </div>

                    <!-- Reset Balance Action Button -->
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="confirmResetSimulation()" title="Reset virtual account balance and trades">
                        Reset Balance
                    </button>
                </div>
            </header>

            <?php if (!empty($dbCheck['fallback'])): ?>
            <!-- Database Fallback Banner -->
            <div class="alert-banner alert-warning">
                <div class="alert-content">
                    <strong>⚡ Local Offline Storage Active:</strong>
                    <span>PostgreSQL is offline (<code><?= htmlspecialchars($dbCheck['error']) ?></code>). Running with local storage fallback so you can trade and test all features immediately!</span>
                    <p class="alert-tip">To use PostgreSQL, start your PostgreSQL service on port 5432 and run <code>php database/init_db.php</code>.</p>
                </div>
            </div>
            <?php elseif (!$dbCheck['connected']): ?>
            <!-- Database Connection Warning Banner -->
            <div class="alert-banner alert-danger">
                <div class="alert-content">
                    <strong>⚠️ PostgreSQL Database Offline:</strong>
                    <span>Could not connect to PostgreSQL (<?= htmlspecialchars($dbCheck['error']) ?>).</span>
                    <p class="alert-tip">Tip: Start PostgreSQL service or verify credentials in <code>.env</code>, then run <code>php database/init_db.php</code>.</p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Main Content Area Starts -->
            <main class="main-content">
