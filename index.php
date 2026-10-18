<?php
/**
 * Main Application Dashboard
 * Stock Market Automated System (Paper Trading Simulator)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

// Handle Reset Simulation Action
$flashMessage = null;
$flashType = 'info';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_simulation') {
    if (reset_simulation()) {
        $flashMessage = 'Simulation reset successfully! Virtual cash balance restored to initial amount.';
        $flashType = 'success';
    } else {
        $flashMessage = 'Failed to reset simulation. Please check your database connection.';
        $flashType = 'error';
    }
}

// Fetch Account Metrics and Positions
$summary = [
    'initial_balance'      => 100000.00,
    'available_cash'       => 100000.00,
    'portfolio_value'      => 0.00,
    'total_invested'       => 0.00,
    'total_unrealised_pnl' => 0.00,
    'total_realised_pnl'   => 0.00,
    'overall_pnl'          => 0.00,
    'overall_pnl_percent'  => 0.00,
    'total_account_value'  => 100000.00,
    'currency'             => 'INR',
    'open_positions_count' => 0
];
$positions = [];
$recentTrades = [];

try {
    $summary = get_portfolio_summary();
    $positions = get_open_positions();
    $recentTrades = get_recent_trades(10);
} catch (Throwable $e) {
    if (!$flashMessage) {
        $flashMessage = 'Database communication error: ' . $e->getMessage();
        $flashType = 'error';
    }
}

$pageTitle = 'Dashboard - Stock Market Simulator';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Trading Dashboard</h1>
        <p class="page-subtitle">Real-time paper trading simulator using virtual money and Yahoo Finance data.</p>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="buy.php" class="btn btn-primary">+ Buy Stocks</a>
        <a href="portfolio.php" class="btn btn-secondary">View Portfolio (<?= count($positions) ?>)</a>
    </div>
</div>

<?php if ($flashMessage): ?>
    <div class="alert alert-<?= $flashType ?>">
        <span><?= htmlspecialchars($flashMessage) ?></span>
    </div>
<?php endif; ?>

<!-- 1. Top Financial Metrics Grid -->
<section class="metrics-grid">
    <!-- Available Virtual Cash -->
    <div class="metric-card">
        <div class="metric-header">
            <span class="metric-label">Available Virtual Cash</span>
            <span class="metric-icon">💵</span>
        </div>
        <div class="metric-value">
            <?= format_money($summary['available_cash'], $summary['currency']) ?>
        </div>
        <div class="metric-sub">
            Initial Capital: <?= format_money($summary['initial_balance'], $summary['currency']) ?>
        </div>
    </div>

    <!-- Current Portfolio Value -->
    <div class="metric-card">
        <div class="metric-header">
            <span class="metric-label">Portfolio Market Value</span>
            <span class="metric-icon">💼</span>
        </div>
        <div class="metric-value">
            <?= format_money($summary['portfolio_value'], $summary['currency']) ?>
        </div>
        <div class="metric-sub">
            Invested Cost: <?= format_money($summary['total_invested'], $summary['currency']) ?>
        </div>
    </div>

    <!-- Total Unrealised Profit / Loss -->
    <div class="metric-card">
        <div class="metric-header">
            <span class="metric-label">Unrealised P&amp;L</span>
            <span class="metric-icon">📊</span>
        </div>
        <div class="metric-value <?= $summary['total_unrealised_pnl'] >= 0 ? 'positive' : 'negative' ?>">
            <?= ($summary['total_unrealised_pnl'] >= 0 ? '+' : '') . format_money($summary['total_unrealised_pnl'], $summary['currency']) ?>
        </div>
        <div class="metric-sub">
            Based on <?= count($positions) ?> active open position(s)
        </div>
    </div>

    <!-- Total Realised Profit / Loss -->
    <div class="metric-card">
        <div class="metric-header">
            <span class="metric-label">Realised P&amp;L</span>
            <span class="metric-icon">🪙</span>
        </div>
        <div class="metric-value <?= $summary['total_realised_pnl'] >= 0 ? 'positive' : 'negative' ?>">
            <?= ($summary['total_realised_pnl'] >= 0 ? '+' : '') . format_money($summary['total_realised_pnl'], $summary['currency']) ?>
        </div>
        <div class="metric-sub">
            From closed trades &amp; stop-losses
        </div>
    </div>

    <!-- Overall Net Profit / Loss -->
    <div class="metric-card">
        <div class="metric-header">
            <span class="metric-label">Overall Net P&amp;L</span>
            <span class="metric-icon">🎯</span>
        </div>
        <div class="metric-value <?= $summary['overall_pnl'] >= 0 ? 'positive' : 'negative' ?>">
            <?= ($summary['overall_pnl'] >= 0 ? '+' : '') . format_money($summary['overall_pnl'], $summary['currency']) ?>
        </div>
        <div class="metric-sub">
            <?= format_percent($summary['overall_pnl_percent']) ?> overall return
        </div>
    </div>
</section>

<!-- 2. Stock Search & Market Quote Widget -->
<section class="search-widget">
    <h2 class="panel-title" style="margin-bottom: 12px;">🔍 Live Stock Market Search</h2>
    <form id="stockSearchForm" class="search-form-row">
        <div class="search-input-wrapper">
            <span class="search-icon-inside">🔎</span>
            <input 
                type="text" 
                id="stockSearchInput" 
                class="search-input" 
                placeholder="Enter stock symbol (e.g. RELIANCE.NS, TCS.NS, INFY.NS, AAPL, MSFT, TSLA)..." 
                autocomplete="off" 
                required
            >
        </div>
        <button type="submit" id="stockSearchBtn" class="btn btn-primary">
            Search Stock
        </button>
    </form>

    <div class="quick-symbols">
        <span class="quick-symbols-label">Quick select:</span>
        <button type="button" class="symbol-chip" data-symbol="RELIANCE.NS">RELIANCE.NS</button>
        <button type="button" class="symbol-chip" data-symbol="TCS.NS">TCS.NS</button>
        <button type="button" class="symbol-chip" data-symbol="INFY.NS">INFY.NS</button>
        <button type="button" class="symbol-chip" data-symbol="HDFCBANK.NS">HDFCBANK.NS</button>
        <button type="button" class="symbol-chip" data-symbol="AAPL">AAPL</button>
        <button type="button" class="symbol-chip" data-symbol="MSFT">MSFT</button>
        <button type="button" class="symbol-chip" data-symbol="GOOGL">GOOGL</button>
        <button type="button" class="symbol-chip" data-symbol="TSLA">TSLA</button>
    </div>

    <!-- Dynamic search output target -->
    <div id="searchResultContainer"></div>
</section>

<!-- 3. Open Positions Section -->
<section class="panel">
    <div class="panel-header">
        <h2 class="panel-title">
            <span>Open Virtual Positions</span>
            <span class="badge badge-buy"><?= count($positions) ?> Active</span>
        </h2>
        <a href="portfolio.php" class="btn btn-secondary btn-sm">Manage Stop-Losses</a>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Symbol</th>
                    <th>Company Name</th>
                    <th>Qty</th>
                    <th>Avg Purchase</th>
                    <th>Latest Price</th>
                    <th>Market Value</th>
                    <th>Unrealised P&amp;L</th>
                    <th>Stop-Loss</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($positions)): ?>
                    <tr>
                        <td colspan="9" class="table-empty">
                            No open positions yet. Use the search bar above or <a href="buy.php" style="color: var(--accent-blue);">buy a stock</a> to start trading!
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($positions as $pos): ?>
                        <tr>
                            <td>
                                <strong class="font-mono"><?= htmlspecialchars($pos['symbol']) ?></strong>
                            </td>
                            <td><?= htmlspecialchars($pos['company_name']) ?></td>
                            <td class="font-mono"><?= number_format($pos['quantity']) ?></td>
                            <td class="font-mono"><?= format_money($pos['average_buy_price'], $pos['currency']) ?></td>
                            <td class="font-mono">
                                <?= format_money($pos['current_price'], $pos['currency']) ?>
                            </td>
                            <td class="font-mono"><?= format_money($pos['market_value'], $pos['currency']) ?></td>
                            <td class="font-mono <?= $pos['unrealised_pnl'] >= 0 ? 'positive' : 'negative' ?>">
                                <?= ($pos['unrealised_pnl'] >= 0 ? '+' : '') . format_money($pos['unrealised_pnl'], $pos['currency']) ?>
                                <small>(<?= format_percent($pos['unrealised_pnl_percent']) ?>)</small>
                            </td>
                            <td class="font-mono">
                                <?php if ($pos['stop_loss_price']): ?>
                                    <span class="badge badge-stoploss">
                                        <?= format_money($pos['stop_loss_price'], $pos['currency']) ?>
                                        (<?= $pos['stop_loss_percent'] ?>%)
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted"><small>None set</small></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="sell.php?symbol=<?= urlencode($pos['symbol']) ?>" class="btn btn-danger btn-sm">
                                    Sell
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- 4. Recent Virtual Trades -->
<section class="panel">
    <div class="panel-header">
        <h2 class="panel-title">
            <span>Recent Virtual Trades</span>
        </h2>
        <a href="trade_history.php" class="btn btn-secondary btn-sm">View Full History</a>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date &amp; Time</th>
                    <th>Type</th>
                    <th>Symbol</th>
                    <th>Qty</th>
                    <th>Executed Price</th>
                    <th>Total Amount</th>
                    <th>Realised P&amp;L</th>
                    <th>Reason</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentTrades)): ?>
                    <tr>
                        <td colspan="8" class="table-empty">
                            No trade transactions recorded yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentTrades as $trade): ?>
                        <tr>
                            <td class="text-muted"><small><?= htmlspecialchars($trade['created_at']) ?></small></td>
                            <td>
                                <span class="badge <?= $trade['trade_type'] === 'BUY' ? 'badge-buy' : 'badge-sell' ?>">
                                    <?= htmlspecialchars($trade['trade_type']) ?>
                                </span>
                            </td>
                            <td class="font-mono"><strong><?= htmlspecialchars($trade['symbol']) ?></strong></td>
                            <td class="font-mono"><?= number_format((int)$trade['quantity']) ?></td>
                            <td class="font-mono"><?= format_money((float)$trade['price'], $trade['currency'] ?? 'INR') ?></td>
                            <td class="font-mono"><?= format_money((float)$trade['total_amount'], $trade['currency'] ?? 'INR') ?></td>
                            <td class="font-mono">
                                <?php if ($trade['trade_type'] === 'SELL'): ?>
                                    <span class="<?= (float)$trade['realised_pnl'] >= 0 ? 'positive' : 'negative' ?>">
                                        <?= ((float)$trade['realised_pnl'] >= 0 ? '+' : '') . format_money((float)$trade['realised_pnl'], $trade['currency'] ?? 'INR') ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($trade['reason'] === 'STOP_LOSS_TRIGGERED'): ?>
                                    <span class="badge badge-stoploss">STOP-LOSS</span>
                                <?php else: ?>
                                    <span class="badge badge-manual">MANUAL</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
