<?php
/**
 * Shared Header Template
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$monitorStatus = get_monitor_status();

// Check database status
$dbCheck = check_db_status();
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
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

    <!-- Paper Trading Top Warning Banner -->
    <div class="simulation-banner">
        <div class="banner-content">
            <span class="banner-badge">SIMULATION ONLY</span>
            <span>This is strictly a paper-trading system using virtual money and Yahoo Finance market data. No real orders are ever placed.</span>
        </div>
    </div>

    <!-- Main Navigation Bar -->
    <header class="main-header">
        <div class="header-container">
            <a href="index.php" class="brand-logo">
                <span class="logo-icon">📈</span>
                <span class="logo-text">Stock<strong>Pulse</strong> <small>Paper Trade</small></span>
            </a>

            <nav class="nav-links">
                <a href="index.php" class="nav-item <?= ($currentPage === 'index.php' || $currentPage === 'dashboard.php') ? 'active' : '' ?>">
                    Dashboard
                </a>
                <a href="portfolio.php" class="nav-item <?= $currentPage === 'portfolio.php' ? 'active' : '' ?>">
                    Portfolio
                </a>
                <a href="buy.php" class="nav-item <?= $currentPage === 'buy.php' ? 'active' : '' ?>">
                    Buy Stocks
                </a>
                <a href="trade_history.php" class="nav-item <?= $currentPage === 'trade_history.php' ? 'active' : '' ?>">
                    Trade History
                </a>
                <a href="stock_analysis.php" class="nav-item <?= $currentPage === 'stock_analysis.php' ? 'active' : '' ?>">
                    Stock Analysis
                </a>
            </nav>

            <div class="header-right">
                <!-- Monitor Status Badge -->
                <div id="monitorStatusPill" class="status-pill <?= $monitorStatus['is_active'] ? 'status-active' : 'status-inactive' ?>" onclick="toggleMonitorModal()">
                    <span class="pulse-dot"></span>
                    <span id="monitorStatusText">
                        <?= $monitorStatus['is_active'] ? 'Monitor Active' : 'Monitor Inactive' ?>
                    </span>
                    <span class="info-icon" title="View monitor details">ℹ️</span>
                </div>

                <!-- Simulation Reset Button -->
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="confirmResetSimulation()" title="Reset virtual account balance and trades">
                    Reset Balance
                </button>
            </div>
        </div>
    </header>

    <?php if (!empty($dbCheck['fallback'])): ?>
    <!-- Database Fallback Banner -->
    <div class="alert-banner alert-warning" style="background-color: rgba(245, 158, 11, 0.15); border-bottom: 1px solid rgba(245, 158, 11, 0.4); color: #FDE68A;">
        <div class="alert-content">
            <strong style="color: #FBBF24;">⚡ Local Offline Storage Active:</strong>
            <span>PostgreSQL is offline (<code><?= htmlspecialchars($dbCheck['error']) ?></code>). Running with local storage fallback so you can trade and test all features immediately!</span>
            <p class="alert-tip" style="color: #FCD34D;">To use PostgreSQL, start your PostgreSQL service on port 5432 and run <code>php database/init_db.php</code>.</p>
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

    <main class="main-content">
