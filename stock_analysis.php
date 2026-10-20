<?php
/**
 * Stock Analysis Page
 * Comprehensive Portfolio Performance, Individual Stock Analytics,
 * Technical History Charts, Rule-Based Outlook, and CSV Reporting.
 *
 * Stock Market Automated System (Paper Trading Simulator)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

// Record daily portfolio snapshot
record_daily_portfolio_snapshot();

// Determine date range filter
$filterRange = trim($_GET['range'] ?? 'ALL');
$filterStart = trim($_GET['start'] ?? '');
$filterEnd = trim($_GET['end'] ?? '');

$dateFilter = [];
if ($filterRange === 'TODAY') {
    $dateFilter['start'] = date('Y-m-d 00:00:00');
    $dateFilter['end'] = date('Y-m-d 23:59:59');
    $dateFilter['label'] = 'Today';
} elseif ($filterRange === '7D') {
    $dateFilter['start'] = date('Y-m-d 00:00:00', strtotime('-7 days'));
    $dateFilter['label'] = 'Last 7 Days';
} elseif ($filterRange === '30D') {
    $dateFilter['start'] = date('Y-m-d 00:00:00', strtotime('-30 days'));
    $dateFilter['label'] = 'Last 30 Days';
} elseif ($filterRange === 'CUSTOM' && !empty($filterStart)) {
    $dateFilter['start'] = $filterStart . ' 00:00:00';
    if (!empty($filterEnd)) {
        $dateFilter['end'] = $filterEnd . ' 23:59:59';
        $dateFilter['label'] = "Custom ({$filterStart} to {$filterEnd})";
    } else {
        $dateFilter['label'] = "From {$filterStart}";
    }
} else {
    $filterRange = 'ALL';
    $dateFilter['label'] = 'All Time';
}

$summary = [];
$stocks = [];
$snapshots = [];
$errorMessage = null;

try {
    $summary = get_portfolio_analysis_summary($dateFilter);
    $stocks = get_all_traded_and_held_stocks_analysis($dateFilter);
    $snapshots = get_portfolio_snapshots(30);
} catch (Throwable $e) {
    $errorMessage = 'Unable to load analysis data: ' . $e->getMessage();
}

// Initial stock to display in detailed technical chart & outlook
$defaultSymbol = 'RELIANCE.NS';
if (!empty($stocks)) {
    $defaultSymbol = $stocks[0]['symbol'];
    // Prefer open position if available
    foreach ($stocks as $s) {
        if ($s['is_open']) {
            $defaultSymbol = $s['symbol'];
            break;
        }
    }
}

$curr = $summary['currency'] ?? 'INR';
$pageTitle = 'Stock Analysis - Stock Market Simulator';

require_once __DIR__ . '/includes/header.php';
?>

<!-- Include Local Offline Chart.js -->
<script src="assets/js/chart.umd.min.js"></script>

<div class="page-header" style="flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 class="page-title">📈 Stock Analysis &amp; Performance</h1>
        <p class="page-subtitle">Multi-dimensional analysis of portfolio equity, open holdings, trade history, technical trends, and rule-based market outlook.</p>
    </div>
    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <!-- CSV Export Button -->
        <a href="api/stock_analysis.php?action=export_csv&amp;range=<?= urlencode($filterRange) ?>&amp;start=<?= urlencode($filterStart) ?>&amp;end=<?= urlencode($filterEnd) ?>" class="btn btn-secondary" title="Export analysis and performance summary to CSV">
            📥 Export CSV Report
        </a>
        <a href="buy.php" class="btn btn-primary">+ Buy Stocks</a>
    </div>
</div>

<?php if ($errorMessage): ?>
    <div class="alert alert-error">
        <span><?= htmlspecialchars($errorMessage) ?></span>
    </div>
<?php endif; ?>

<!-- ==========================================================================
     SECTION 1: DATE-RANGE FILTER CONTROLS
     ========================================================================== -->
<div class="panel" style="margin-bottom: 24px; padding: 16px 20px;">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <span style="font-size: 0.88rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Filter Analysis Period:</span>
            <a href="stock_analysis.php?range=ALL" class="btn btn-sm <?= $filterRange === 'ALL' ? 'btn-primary' : 'btn-secondary' ?>">All Time</a>
            <a href="stock_analysis.php?range=TODAY" class="btn btn-sm <?= $filterRange === 'TODAY' ? 'btn-primary' : 'btn-secondary' ?>">Today</a>
            <a href="stock_analysis.php?range=7D" class="btn btn-sm <?= $filterRange === '7D' ? 'btn-primary' : 'btn-secondary' ?>">Last 7 Days</a>
            <a href="stock_analysis.php?range=30D" class="btn btn-sm <?= $filterRange === '30D' ? 'btn-primary' : 'btn-secondary' ?>">Last 30 Days</a>
        </div>

        <form method="GET" action="stock_analysis.php" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <input type="hidden" name="range" value="CUSTOM">
            <span style="font-size: 0.82rem; color: var(--text-muted);">Custom:</span>
            <input type="date" name="start" value="<?= htmlspecialchars($filterStart) ?>" class="form-input" style="padding: 6px 10px; font-size: 0.85rem;" required>
            <span style="color: var(--text-muted);">&rarr;</span>
            <input type="date" name="end" value="<?= htmlspecialchars($filterEnd) ?>" class="form-input" style="padding: 6px 10px; font-size: 0.85rem;">
            <button type="submit" class="btn btn-sm btn-secondary">Apply</button>
            <?php if ($filterRange !== 'ALL'): ?>
                <a href="stock_analysis.php" class="btn btn-sm btn-outline-danger" title="Clear Date Filter">&times; Reset</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- ==========================================================================
     SECTION 2: OVERALL PORTFOLIO SUMMARY METRICS (Required Section 3)
     ========================================================================== -->
<div class="section-title-wrap" style="margin-bottom: 14px;">
    <h2 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 8px;">
        <span>💼 Overall Portfolio Summary</span>
        <span style="font-size: 0.75rem; font-weight: 500; color: var(--text-muted);">(Period: <?= htmlspecialchars($dateFilter['label']) ?>)</span>
    </h2>
</div>

<div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-bottom: 24px;">
    <!-- 1. Initial Virtual Balance -->
    <div class="metric-card">
        <div class="metric-header">
            <span class="metric-label">Initial Virtual Balance</span>
            <span class="metric-icon">🏦</span>
        </div>
        <div class="metric-value font-mono">
            <?= format_money((float)($summary['initial_balance'] ?? 100000), $curr) ?>
        </div>
        <div class="metric-sub">Account Starting Base</div>
    </div>

    <!-- 2. Available Virtual Cash -->
    <div class="metric-card">
        <div class="metric-header">
            <span class="metric-label">Available Virtual Cash</span>
            <span class="metric-icon">💵</span>
        </div>
        <div class="metric-value font-mono">
            <?= format_money((float)($summary['available_cash'] ?? 0), $curr) ?>
        </div>
        <div class="metric-sub">Unallocated Capital</div>
    </div>

    <!-- 3. Total Invested (Cost Basis of Open Positions) -->
    <div class="metric-card">
        <div class="metric-header">
            <span class="metric-label">Invested in Open Holdings</span>
            <span class="metric-icon">📊</span>
        </div>
        <div class="metric-value font-mono">
            <?= format_money((float)($summary['total_invested'] ?? 0), $curr) ?>
        </div>
        <div class="metric-sub"><?= (int)($summary['open_positions_count'] ?? 0) ?> Active Position(s)</div>
    </div>

    <!-- 4. Current Market Value of Open Positions -->
    <div class="metric-card">
        <div class="metric-header">
            <span class="metric-label">Open Positions Market Value</span>
            <span class="metric-icon">🏷️</span>
        </div>
        <div class="metric-value font-mono">
            <?= format_money((float)($summary['portfolio_market_value'] ?? 0), $curr) ?>
        </div>
        <div class="metric-sub">Live Valuation</div>
    </div>

    <!-- 5. Current Total Portfolio Equity -->
    <div class="metric-card" style="border: 1px solid rgba(59, 130, 246, 0.4); background: linear-gradient(180deg, rgba(30, 58, 138, 0.2) 0%, var(--bg-card) 100%);">
        <div class="metric-header">
            <span class="metric-label" style="color: #93C5FD; font-weight: 700;">Current Total Portfolio Equity</span>
            <span class="metric-icon">💎</span>
        </div>
        <div class="metric-value font-mono" style="color: #60A5FA;">
            <?= format_money((float)($summary['current_equity'] ?? 0), $curr) ?>
        </div>
        <div class="metric-sub">Available Cash + Market Value</div>
    </div>

    <!-- 6. Realised Profit / Loss -->
    <div class="metric-card">
        <div class="metric-header">
            <span class="metric-label">Realised Profit / Loss</span>
            <span class="metric-icon">🪙</span>
        </div>
        <div class="metric-value font-mono <?= (float)($summary['total_realised_pnl'] ?? 0) >= 0 ? 'positive' : 'negative' ?>">
            <?= ((float)($summary['total_realised_pnl'] ?? 0) >= 0 ? '+' : '') . format_money((float)($summary['total_realised_pnl'] ?? 0), $curr) ?>
        </div>
        <div class="metric-sub">From Completed Sales</div>
    </div>

    <!-- 7. Unrealised Profit / Loss -->
    <div class="metric-card">
        <div class="metric-header">
            <span class="metric-label">Unrealised Profit / Loss</span>
            <span class="metric-icon">📈</span>
        </div>
        <div class="metric-value font-mono <?= (float)($summary['total_unrealised_pnl'] ?? 0) >= 0 ? 'positive' : 'negative' ?>">
            <?= ((float)($summary['total_unrealised_pnl'] ?? 0) >= 0 ? '+' : '') . format_money((float)($summary['total_unrealised_pnl'] ?? 0), $curr) ?>
        </div>
        <div class="metric-sub">Floating Open P&amp;L</div>
    </div>

    <!-- 8. Total Profit / Loss -->
    <div class="metric-card">
        <div class="metric-header">
            <span class="metric-label">Total Profit / Loss</span>
            <span class="metric-icon">🎯</span>
        </div>
        <div class="metric-value font-mono <?= (float)($summary['total_pnl'] ?? 0) >= 0 ? 'positive' : 'negative' ?>">
            <?= ((float)($summary['total_pnl'] ?? 0) >= 0 ? '+' : '') . format_money((float)($summary['total_pnl'] ?? 0), $curr) ?>
        </div>
        <div class="metric-sub">Realised + Unrealised</div>
    </div>

    <!-- 9. Overall Return Percentage -->
    <div class="metric-card">
        <div class="metric-header">
            <span class="metric-label">Overall Return Percentage</span>
            <span class="metric-icon">⚡</span>
        </div>
        <div class="metric-value font-mono <?= (float)($summary['overall_return_percent'] ?? 0) >= 0 ? 'positive' : 'negative' ?>">
            <?= format_percent((float)($summary['overall_return_percent'] ?? 0)) ?>
        </div>
        <div class="metric-sub">Return on Initial Capital</div>
    </div>

    <!-- 10. Completed Trades Breakdown -->
    <div class="metric-card">
        <div class="metric-header">
            <span class="metric-label">Completed Trades Win/Loss</span>
            <span class="metric-icon">⚖️</span>
        </div>
        <div class="metric-value font-mono" style="font-size: 1.25rem;">
            <span class="positive"><?= (int)($summary['profitable_trades_count'] ?? 0) ?> Win</span> / 
            <span class="negative"><?= (int)($summary['losing_trades_count'] ?? 0) ?> Loss</span>
        </div>
        <div class="metric-sub">
            Win Rate: 
            <strong>
                <?= $summary['win_rate_percent'] !== null ? number_format((float)$summary['win_rate_percent'], 1) . '%' : 'Not available' ?>
            </strong>
            (<?= (int)($summary['completed_trades_count'] ?? 0) ?> completed)
        </div>
    </div>

    <!-- 11. Best Performing Stock -->
    <div class="metric-card">
        <div class="metric-header">
            <span class="metric-label">Best-Performing Stock</span>
            <span class="metric-icon">🏆</span>
        </div>
        <?php if (!empty($summary['best_stock']) && $summary['best_stock']['total_pnl'] != 0): ?>
            <div class="metric-value font-mono positive" style="font-size: 1.25rem;">
                <?= htmlspecialchars($summary['best_stock']['symbol']) ?>
            </div>
            <div class="metric-sub positive">
                +<?= format_money((float)$summary['best_stock']['total_pnl'], $curr) ?>
                <?php if ($summary['best_stock']['return_pct'] !== null): ?>
                    (<?= format_percent((float)$summary['best_stock']['return_pct']) ?>)
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="metric-value font-mono" style="font-size: 1.1rem; color: var(--text-muted);">
                Not available
            </div>
            <div class="metric-sub">Requires active or completed trades</div>
        <?php endif; ?>
    </div>

    <!-- 12. Worst Performing Stock -->
    <div class="metric-card">
        <div class="metric-header">
            <span class="metric-label">Worst-Performing Stock</span>
            <span class="metric-icon">🔻</span>
        </div>
        <?php if (!empty($summary['worst_stock']) && $summary['worst_stock']['total_pnl'] != 0): ?>
            <div class="metric-value font-mono negative" style="font-size: 1.25rem;">
                <?= htmlspecialchars($summary['worst_stock']['symbol']) ?>
            </div>
            <div class="metric-sub negative">
                <?= format_money((float)$summary['worst_stock']['total_pnl'], $curr) ?>
                <?php if ($summary['worst_stock']['return_pct'] !== null): ?>
                    (<?= format_percent((float)$summary['worst_stock']['return_pct']) ?>)
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="metric-value font-mono" style="font-size: 1.1rem; color: var(--text-muted);">
                Not available
            </div>
            <div class="metric-sub">Requires active or completed trades</div>
        <?php endif; ?>
    </div>
</div>

<!-- ==========================================================================
     SECTION 3: INDIVIDUAL STOCK ANALYSIS TABLE (Required Section 4)
     ========================================================================== -->
<div class="panel" style="margin-bottom: 28px;">
    <div class="panel-header" style="flex-wrap: wrap; gap: 14px;">
        <div>
            <h2 class="panel-title">📊 Individual Stock Analysis</h2>
            <p style="font-size: 0.82rem; color: var(--text-muted); margin-top: 2px;">
                Comprehensive stock-level view covering open holdings and historical trades. Click any stock to view interactive price history and rule-based outlook.
            </p>
        </div>

        <!-- Search and Filter Controls -->
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <!-- Status Filter Buttons -->
            <div style="display: flex; gap: 6px;">
                <button type="button" class="btn btn-sm btn-primary table-filter-btn" data-filter="ALL" onclick="filterStockTable('ALL', this)">All (<?= count($stocks) ?>)</button>
                <button type="button" class="btn btn-sm btn-secondary table-filter-btn" data-filter="OPEN" onclick="filterStockTable('OPEN', this)">Open Holdings</button>
                <button type="button" class="btn btn-sm btn-secondary table-filter-btn" data-filter="CLOSED" onclick="filterStockTable('CLOSED', this)">Closed Trades</button>
            </div>
            <!-- Search Input -->
            <div style="position: relative;">
                <input type="text" id="stockTableSearch" placeholder="Search symbol or name..." class="form-input" style="padding: 7px 12px; font-size: 0.88rem; min-width: 200px;" oninput="searchStockTable()">
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="data-table" id="stockAnalysisTable">
            <thead>
                <tr>
                    <th>Stock Symbol</th>
                    <th>Company Name</th>
                    <th>Status</th>
                    <th>Quantity</th>
                    <th>Avg Purchase</th>
                    <th>Latest Price</th>
                    <th>Cost Basis</th>
                    <th>Market Value</th>
                    <th>Unrealised P&amp;L</th>
                    <th>Realised P&amp;L</th>
                    <th>Combined P&amp;L</th>
                    <th>30D Trend</th>
                    <th>Last Market Update</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($stocks)): ?>
                    <tr>
                        <td colspan="14" class="table-empty" style="padding: 32px; text-align: center;">
                            No stocks currently held or previously traded. Visit the <a href="buy.php" style="color: var(--accent-blue);">Buy Stocks</a> page to initiate your first virtual simulation trade!
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($stocks as $s): ?>
                        <tr class="stock-analysis-row" 
                            data-symbol="<?= htmlspecialchars($s['symbol']) ?>" 
                            data-name="<?= htmlspecialchars(strtolower($s['company_name'])) ?>" 
                            data-status="<?= $s['status'] ?>"
                            id="stock-row-<?= htmlspecialchars($s['symbol']) ?>"
                            onclick="selectStockForAnalysis('<?= htmlspecialchars($s['symbol']) ?>')">
                            
                            <!-- Symbol -->
                            <td>
                                <strong class="font-mono text-primary" style="font-size: 0.95rem; cursor: pointer;">
                                    <?= htmlspecialchars($s['symbol']) ?>
                                </strong>
                            </td>

                            <!-- Company Name -->
                            <td style="max-width: 180px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($s['company_name']) ?>">
                                <?= htmlspecialchars($s['company_name']) ?>
                            </td>

                            <!-- Status Badge -->
                            <td>
                                <?php if ($s['is_open']): ?>
                                    <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #34D399; border: 1px solid rgba(16, 185, 129, 0.3);">
                                        HOLDING
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background: rgba(100, 116, 139, 0.2); color: #94A3B8; border: 1px solid rgba(100, 116, 139, 0.3);">
                                        CLOSED
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Quantity Held -->
                            <td class="font-mono">
                                <?= number_format($s['quantity']) ?>
                            </td>

                            <!-- Average Purchase Price -->
                            <td class="font-mono">
                                <?= $s['average_buy_price'] !== null ? format_money($s['average_buy_price'], $s['currency']) : '<span class="text-muted">Not available</span>' ?>
                            </td>

                            <!-- Latest Available Market Price -->
                            <td class="font-mono">
                                <?php if ($s['latest_market_price'] !== null): ?>
                                    <strong><?= format_money($s['latest_market_price'], $s['currency']) ?></strong>
                                <?php else: ?>
                                    <span class="text-muted">Not available</span>
                                <?php endif; ?>
                            </td>

                            <!-- Total Cost Basis of Open Holdings -->
                            <td class="font-mono">
                                <?= $s['is_open'] ? format_money($s['cost_basis'], $s['currency']) : '<span class="text-muted">₹0.00</span>' ?>
                            </td>

                            <!-- Current Market Value -->
                            <td class="font-mono">
                                <?= $s['is_open'] ? format_money($s['market_value'], $s['currency']) : '<span class="text-muted">₹0.00</span>' ?>
                            </td>

                            <!-- Unrealised Profit/Loss -->
                            <td class="font-mono">
                                <?php if ($s['is_open']): ?>
                                    <span class="<?= $s['unrealised_pnl'] >= 0 ? 'positive' : 'negative' ?>">
                                        <?= ($s['unrealised_pnl'] >= 0 ? '+' : '') . format_money($s['unrealised_pnl'], $s['currency']) ?>
                                    </span><br>
                                    <small class="<?= $s['unrealised_pnl'] >= 0 ? 'positive' : 'negative' ?>">
                                        (<?= format_percent((float)$s['unrealised_return_pct']) ?>)
                                    </small>
                                <?php else: ?>
                                    <span class="text-muted">Not available</span>
                                <?php endif; ?>
                            </td>

                            <!-- Realised Profit/Loss from Completed Trades -->
                            <td class="font-mono <?= $s['realised_pnl'] > 0 ? 'positive' : ($s['realised_pnl'] < 0 ? 'negative' : 'text-muted') ?>">
                                <?= ($s['realised_pnl'] > 0 ? '+' : '') . format_money($s['realised_pnl'], $s['currency']) ?>
                            </td>

                            <!-- Total Combined P&L -->
                            <td class="font-mono <?= $s['combined_pnl'] >= 0 ? 'positive' : 'negative' ?>" style="font-weight: 600;">
                                <?= ($s['combined_pnl'] >= 0 ? '+' : '') . format_money($s['combined_pnl'], $s['currency']) ?>
                            </td>

                            <!-- Recent Price Trend -->
                            <td>
                                <?php if ($s['price_change_recent'] !== null): ?>
                                    <span class="font-mono <?= $s['price_change_recent'] >= 0 ? 'positive' : 'negative' ?>">
                                        <?= $s['price_change_recent'] >= 0 ? '▲' : '▼' ?> <?= format_percent($s['price_change_recent']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">Not available</span>
                                <?php endif; ?>
                            </td>

                            <!-- Last Successful Market-Data Update -->
                            <td style="font-size: 0.8rem; color: var(--text-muted);">
                                <?= $s['last_updated'] ? date('M d, H:i', strtotime($s['last_updated'])) : 'Not available' ?>
                            </td>

                            <!-- Actions -->
                            <td>
                                <button type="button" class="btn btn-sm btn-primary" onclick="event.stopPropagation(); selectStockForAnalysis('<?= htmlspecialchars($s['symbol']) ?>');" style="white-space: nowrap;">
                                    Analyze 📊
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ==========================================================================
     SECTION 4: INTERACTIVE STOCK PRICE HISTORY & CHARTS (Required Section 5)
     ========================================================================== -->
<div class="panel" id="stockAnalysisDetailSection" style="margin-bottom: 28px;">
    <div class="panel-header" style="flex-wrap: wrap; gap: 14px;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <h2 class="panel-title" id="chartStockTitle">Stock Price History &amp; Indicators</h2>
                <span class="badge" id="chartStockSymbolBadge" style="background: var(--accent-blue); color: #fff; font-size: 0.85rem; font-weight: 700;">
                    <?= htmlspecialchars($defaultSymbol) ?>
                </span>
            </div>
            <p style="font-size: 0.82rem; color: var(--text-muted); margin-top: 3px;" id="chartStockSubtitle">
                Historical closing prices plotted alongside 20-day and 50-day moving averages retrieved from real market data.
            </p>
        </div>

        <!-- Period Selection Buttons -->
        <div style="display: flex; align-items: center; gap: 8px;">
            <span style="font-size: 0.82rem; color: var(--text-secondary); font-weight: 600;">Timeframe:</span>
            <button type="button" class="btn btn-sm btn-secondary chart-period-btn" data-period="7d" onclick="setChartPeriod('7d')">7D</button>
            <button type="button" class="btn btn-sm btn-primary chart-period-btn" data-period="30d" onclick="setChartPeriod('30d')">30D</button>
            <button type="button" class="btn btn-sm btn-secondary chart-period-btn" data-period="90d" onclick="setChartPeriod('90d')">90D</button>
            <button type="button" class="btn btn-sm btn-secondary chart-period-btn" data-period="1y" onclick="setChartPeriod('1y')">1Y</button>
        </div>
    </div>

    <!-- Quick Stats Bar for Selected Stock -->
    <div id="chartHeaderStats" style="display: flex; gap: 20px; padding: 12px 20px; background: rgba(15, 22, 38, 0.6); border-bottom: 1px solid var(--border-color); flex-wrap: wrap;">
        <div>
            <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">Latest Price:</span>
            <div id="chartStatLatestPrice" class="font-mono" style="font-size: 1.1rem; font-weight: 700;">--</div>
        </div>
        <div>
            <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">Period Change:</span>
            <div id="chartStatPeriodChange" class="font-mono" style="font-size: 1.1rem; font-weight: 700;">--</div>
        </div>
        <div>
            <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">20-Day MA:</span>
            <div id="chartStatSma20" class="font-mono" style="font-size: 1.1rem; font-weight: 600; color: #F59E0B;">--</div>
        </div>
        <div>
            <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">50-Day MA:</span>
            <div id="chartStatSma50" class="font-mono" style="font-size: 1.1rem; font-weight: 600; color: #8B5CF6;">--</div>
        </div>
        <div>
            <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">14-Day RSI:</span>
            <div id="chartStatRsi" class="font-mono" style="font-size: 1.1rem; font-weight: 600;">--</div>
        </div>
        <div style="margin-left: auto;">
            <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">Last Update:</span>
            <div id="chartStatLastUpdated" style="font-size: 0.85rem; color: var(--text-secondary);">--</div>
        </div>
    </div>

    <!-- Chart Canvas Container -->
    <div style="position: relative; height: 380px; padding: 16px 20px;">
        <!-- Loading Spinner -->
        <div id="chartLoadingOverlay" style="position: absolute; inset: 0; background: rgba(11, 15, 25, 0.8); display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 10;">
            <div class="pulse-dot" style="width: 14px; height: 14px; background: var(--accent-blue); margin-bottom: 8px;"></div>
            <span style="color: var(--text-secondary); font-size: 0.9rem;">Fetching market history and indicators...</span>
        </div>

        <!-- Error State Overlay -->
        <div id="chartErrorOverlay" style="position: absolute; inset: 0; background: rgba(11, 15, 25, 0.95); display: none; flex-direction: column; align-items: center; justify-content: center; z-index: 10; padding: 20px; text-align: center;">
            <span style="font-size: 2rem; margin-bottom: 8px;">⚠️</span>
            <h4 style="color: #EF4444; margin-bottom: 6px;">Market Data Provider Unavailable</h4>
            <p id="chartErrorMessage" style="color: var(--text-secondary); max-width: 500px; font-size: 0.88rem; margin-bottom: 12px;"></p>
            <button type="button" class="btn btn-sm btn-primary" onclick="retryLoadSelectedStock()">Retry Retrieval</button>
        </div>

        <canvas id="stockHistoryChart"></canvas>
    </div>
</div>

<!-- ==========================================================================
     SECTION 5: RULE-BASED STOCK OUTLOOK (Required Section 6)
     ========================================================================== -->
<div class="panel" id="stockOutlookSection" style="margin-bottom: 28px;">
    <div class="panel-header">
        <div>
            <h2 class="panel-title">🧭 Stock Outlook: Technical Signal Assessment</h2>
            <p style="font-size: 0.82rem; color: var(--text-muted); margin-top: 2px;">
                Transparent, rule-based technical evaluation using moving average relationships, Relative Strength Index (RSI), and historical volatility.
            </p>
        </div>
        <div id="outlookSignalBadgeContainer">
            <span class="badge" id="outlookMainBadge" style="font-size: 0.92rem; padding: 6px 14px; font-weight: 700;">
                Analyzing...
            </span>
        </div>
    </div>

    <div style="padding: 20px;">
        <!-- Supporting Indicators Grid -->
        <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-bottom: 20px;">
            <div class="metric-card" style="padding: 12px 16px;">
                <div class="metric-label">20-Day Simple MA</div>
                <div class="metric-value font-mono" id="outlookSma20Val" style="font-size: 1.15rem; color: #F59E0B;">--</div>
                <div class="metric-sub" id="outlookSma20Sub">Short-term trend</div>
            </div>

            <div class="metric-card" style="padding: 12px 16px;">
                <div class="metric-label">50-Day Simple MA</div>
                <div class="metric-value font-mono" id="outlookSma50Val" style="font-size: 1.15rem; color: #8B5CF6;">--</div>
                <div class="metric-sub" id="outlookSma50Sub">Medium-term trend</div>
            </div>

            <div class="metric-card" style="padding: 12px 16px;">
                <div class="metric-label">14-Period RSI</div>
                <div class="metric-value font-mono" id="outlookRsiVal" style="font-size: 1.15rem;">--</div>
                <div class="metric-sub" id="outlookRsiZone">Momentum zone</div>
            </div>

            <div class="metric-card" style="padding: 12px 16px;">
                <div class="metric-label">30-Day Price Change</div>
                <div class="metric-value font-mono" id="outlookChange30dVal" style="font-size: 1.15rem;">--</div>
                <div class="metric-sub">Past month momentum</div>
            </div>

            <div class="metric-card" style="padding: 12px 16px;">
                <div class="metric-label">Historical Volatility</div>
                <div class="metric-value font-mono" id="outlookVolatilityVal" style="font-size: 1.15rem;">--</div>
                <div class="metric-sub">Annualized return dispersion</div>
            </div>
        </div>

        <!-- Detailed Assessment Box -->
        <div style="background: var(--bg-main); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 18px 20px; margin-bottom: 16px;">
            <h4 style="font-size: 0.95rem; font-weight: 700; color: var(--text-primary); margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                <span>📋 Assessment Rationale:</span>
                <span id="outlookRationaleTitle" style="font-weight: 600; color: var(--accent-blue);">--</span>
            </h4>
            <p id="outlookExplanationText" style="font-size: 0.92rem; color: var(--text-secondary); line-height: 1.6;">
                Select a stock from the table above to view its automated rule-based evaluation.
            </p>
        </div>

        <!-- Risks & Disclaimer Box (Section 6 Requirement) -->
        <div style="background: rgba(245, 158, 11, 0.08); border-left: 4px solid var(--color-warning); padding: 14px 18px; border-radius: 0 var(--radius-sm) var(--radius-sm) 0;">
            <strong style="color: #FBBF24; font-size: 0.88rem; display: block; margin-bottom: 4px;">⚠️ Technical Limitations &amp; Educational Disclaimer:</strong>
            <p id="outlookRisksText" style="font-size: 0.82rem; color: #FDE68A; line-height: 1.5; margin-bottom: 4px;">
                Technical analysis indicators rely solely on past market prices and do not account for corporate earnings, news developments, or macroeconomic events. 
            </p>
            <p style="font-size: 0.78rem; color: #FCD34D; margin: 0;">
                <em>Notice: Strictly for educational paper-trading analysis. Not personalized financial advice. This analysis does not interact with or trigger simulated automatic stop-loss orders.</em>
            </p>
        </div>
    </div>
</div>

<!-- ==========================================================================
     SECTION 6: PORTFOLIO PERFORMANCE ANALYSIS (Required Section 7)
     ========================================================================== -->
<div class="panel" style="margin-bottom: 28px;">
    <div class="panel-header">
        <div>
            <h2 class="panel-title">📈 Portfolio Performance &amp; Equity Growth</h2>
            <p style="font-size: 0.82rem; color: var(--text-muted); margin-top: 2px;">
                Historical equity trajectory and unrealised return rankings across portfolio assets.
            </p>
        </div>
    </div>

    <div style="padding: 20px;">
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; align-items: start;">
            <!-- Left: Equity Over Time Chart -->
            <div>
                <h4 style="font-size: 0.92rem; color: var(--text-secondary); font-weight: 600; margin-bottom: 12px;">
                    Portfolio Equity Over Time (Simulated Snapshots)
                </h4>
                
                <?php if (!empty($snapshots)): ?>
                    <div style="position: relative; height: 260px;">
                        <canvas id="portfolioEquityChart"></canvas>
                    </div>
                <?php else: ?>
                    <div style="background: var(--bg-main); border: 1px dashed var(--border-color); border-radius: var(--radius-md); padding: 40px; text-align: center;">
                        <span style="font-size: 1.8rem; display: block; margin-bottom: 8px;">⏳</span>
                        <h4 style="color: var(--text-primary); margin-bottom: 4px;">Historical Tracking Initiated</h4>
                        <p style="color: var(--text-muted); font-size: 0.85rem; max-width: 400px; margin: 0 auto;">
                            Portfolio equity tracking records daily snapshots automatically. As you continue paper trading over upcoming days, your historical equity curve will display here.
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right: Holdings Ranked by Current Unrealised Return -->
            <div>
                <h4 style="font-size: 0.92rem; color: var(--text-secondary); font-weight: 600; margin-bottom: 12px;">
                    Holdings Ranked by Unrealised Return
                </h4>

                <?php if (empty($summary['ranked_holdings'])): ?>
                    <div style="background: var(--bg-main); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 30px 20px; text-align: center; color: var(--text-muted); font-size: 0.85rem;">
                        No open holdings to rank.
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <?php foreach ($summary['ranked_holdings'] as $idx => $rh): ?>
                            <div style="background: var(--bg-main); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 10px 14px; display: flex; align-items: center; justify-content: space-between;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <span style="font-weight: 700; color: var(--text-muted); font-size: 0.8rem; width: 18px;">#<?= $idx + 1 ?></span>
                                    <div>
                                        <strong class="font-mono text-primary" style="font-size: 0.92rem;"><?= htmlspecialchars($rh['symbol']) ?></strong>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?= (int)$rh['quantity'] ?> shares &bull; <?= format_money((float)$rh['market_value'], $rh['currency']) ?></div>
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <div class="font-mono <?= (float)$rh['unrealised_pnl'] >= 0 ? 'positive' : 'negative' ?>" style="font-weight: 700; font-size: 0.92rem;">
                                        <?= format_percent((float)$rh['unrealised_pnl_percent']) ?>
                                    </div>
                                    <div style="font-size: 0.75rem;" class="<?= (float)$rh['unrealised_pnl'] >= 0 ? 'positive' : 'negative' ?>">
                                        <?= ((float)$rh['unrealised_pnl'] >= 0 ? '+' : '') . format_money((float)$rh['unrealised_pnl'], $rh['currency']) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================================================
     JAVASCRIPT LOGIC FOR STOCK ANALYSIS PAGE
     ========================================================================== -->
<script>
/**
 * Global State for Stock Analysis
 */
let currentSelectedSymbol = <?= json_encode($defaultSymbol) ?>;
let currentSelectedPeriod = '30d';
let stockChartInstance = null;
let equityChartInstance = null;

// Snapshots passed from PHP
const portfolioSnapshots = <?= json_encode($snapshots) ?>;

document.addEventListener('DOMContentLoaded', function () {
    // 1. Initialise Technical History Chart for default stock
    if (currentSelectedSymbol) {
        loadStockHistory(currentSelectedSymbol, currentSelectedPeriod);
    }

    // 2. Initialise Portfolio Equity Over Time Chart
    initPortfolioEquityChart();
});

/**
 * Filter Stock Table by Status (ALL, OPEN, CLOSED)
 */
function filterStockTable(status, btnElement) {
    document.querySelectorAll('.table-filter-btn').forEach(b => {
        b.classList.remove('btn-primary');
        b.classList.add('btn-secondary');
    });
    btnElement.classList.remove('btn-secondary');
    btnElement.classList.add('btn-primary');

    const rows = document.querySelectorAll('.stock-analysis-row');
    rows.forEach(row => {
        const rowStatus = row.getAttribute('data-status');
        if (status === 'ALL' || rowStatus === status) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

/**
 * Real-time Search in Stock Analysis Table
 */
function searchStockTable() {
    const query = document.getElementById('stockTableSearch').value.trim().toLowerCase();
    const rows = document.querySelectorAll('.stock-analysis-row');

    rows.forEach(row => {
        const sym = row.getAttribute('data-symbol').toLowerCase();
        const name = row.getAttribute('data-name').toLowerCase();

        if (query === '' || sym.includes(query) || name.includes(query)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

/**
 * Select a stock from the table to inspect details
 */
function selectStockForAnalysis(symbol) {
    if (!symbol) return;
    currentSelectedSymbol = symbol;

    // Highlight row
    document.querySelectorAll('.stock-analysis-row').forEach(r => r.style.backgroundColor = '');
    const targetRow = document.getElementById('stock-row-' + symbol);
    if (targetRow) {
        targetRow.style.backgroundColor = 'rgba(59, 130, 246, 0.12)';
    }

    // Scroll smoothly to detail section
    const detailSection = document.getElementById('stockAnalysisDetailSection');
    if (detailSection) {
        detailSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // Update Header Badges
    document.getElementById('chartStockSymbolBadge').textContent = symbol;

    // Fetch and render data
    loadStockHistory(symbol, currentSelectedPeriod);
}

/**
 * Set timeframe period for chart (7d, 30d, 90d, 1y)
 */
function setChartPeriod(period) {
    currentSelectedPeriod = period;

    document.querySelectorAll('.chart-period-btn').forEach(b => {
        if (b.getAttribute('data-period') === period) {
            b.classList.remove('btn-secondary');
            b.classList.add('btn-primary');
        } else {
            b.classList.remove('btn-primary');
            b.classList.add('btn-secondary');
        }
    });

    if (currentSelectedSymbol) {
        loadStockHistory(currentSelectedSymbol, period);
    }
}

function retryLoadSelectedStock() {
    if (currentSelectedSymbol) {
        loadStockHistory(currentSelectedSymbol, currentSelectedPeriod);
    }
}

/**
 * Fetch historical stock data and render Chart.js + Outlook
 */
async function loadStockHistory(symbol, period) {
    const loadingOverlay = document.getElementById('chartLoadingOverlay');
    const errorOverlay = document.getElementById('chartErrorOverlay');

    loadingOverlay.style.display = 'flex';
    errorOverlay.style.display = 'none';

    try {
        const response = await fetch(`api/stock_analysis.php?action=history&symbol=${encodeURIComponent(symbol)}&period=${encodeURIComponent(period)}`);
        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Failed to retrieve stock history.');
        }

        renderStockHistoryChart(data);
        renderStockOutlook(data);

        loadingOverlay.style.display = 'none';
    } catch (err) {
        loadingOverlay.style.display = 'none';
        errorOverlay.style.display = 'flex';
        document.getElementById('chartErrorMessage').textContent = err.message || 'Market data provider connection failed.';
        console.error('History fetch error:', err);
    }
}

/**
 * Render Historical Price Chart with 20-Day and 50-Day Moving Averages
 */
function renderStockHistoryChart(data) {
    const sym = data.symbol;
    const name = data.name || sym;
    const currSym = data.currency_symbol || '₹';
    const history = data.history || [];
    const metrics = data.metrics || {};

    // Update Header Text & Stats
    document.getElementById('chartStockTitle').textContent = `${name} (${sym})`;
    document.getElementById('chartStockSubtitle').textContent = 
        `Historical daily closing prices and trend averages over the selected ${data.period.toUpperCase()} period.`;

    // Latest price & changes
    const latestClose = metrics.latest_close !== null ? `${currSym}${metrics.latest_close.toFixed(2)}` : '--';
    document.getElementById('chartStatLatestPrice').textContent = latestClose;

    const periodChangePct = metrics.period_change_pct !== undefined ? metrics.period_change_pct : null;
    const changeElem = document.getElementById('chartStatPeriodChange');
    if (periodChangePct !== null) {
        const sign = periodChangePct >= 0 ? '+' : '';
        changeElem.textContent = `${sign}${periodChangePct.toFixed(2)}%`;
        changeElem.className = 'font-mono ' + (periodChangePct >= 0 ? 'positive' : 'negative');
    } else {
        changeElem.textContent = '--';
        changeElem.className = 'font-mono text-muted';
    }

    document.getElementById('chartStatSma20').textContent = metrics.sma20 ? `${currSym}${metrics.sma20.toFixed(2)}` : 'N/A';
    document.getElementById('chartStatSma50').textContent = metrics.sma50 ? `${currSym}${metrics.sma50.toFixed(2)}` : 'N/A';

    const rsiElem = document.getElementById('chartStatRsi');
    if (metrics.rsi14 !== null && metrics.rsi14 !== undefined) {
        rsiElem.textContent = metrics.rsi14.toFixed(1);
        if (metrics.rsi14 >= 70) {
            rsiElem.style.color = '#EF4444';
        } else if (metrics.rsi14 <= 30) {
            rsiElem.style.color = '#10B981';
        } else {
            rsiElem.style.color = '#38BDF8';
        }
    } else {
        rsiElem.textContent = 'N/A';
        rsiElem.style.color = 'var(--text-muted)';
    }

    const lastUpdated = data.last_updated ? new Date(data.last_updated).toLocaleString() : 'Not available';
    document.getElementById('chartStatLastUpdated').textContent = lastUpdated;

    // Prepare chart datasets
    const labels = history.map(item => item.date);
    const closePrices = history.map(item => item.close);
    const sma20Prices = history.map(item => item.sma20);
    const sma50Prices = history.map(item => item.sma50);

    const ctx = document.getElementById('stockHistoryChart').getContext('2d');

    if (stockChartInstance) {
        stockChartInstance.destroy();
    }

    stockChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Closing Price',
                    data: closePrices,
                    borderColor: '#3B82F6',
                    backgroundColor: 'rgba(59, 130, 246, 0.08)',
                    borderWidth: 2.2,
                    fill: true,
                    tension: 0.15,
                    pointRadius: history.length > 50 ? 0 : 3,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#3B82F6'
                },
                {
                    label: '20-Day SMA',
                    data: sma20Prices,
                    borderColor: '#F59E0B',
                    borderWidth: 1.8,
                    borderDash: [5, 4],
                    fill: false,
                    tension: 0.2,
                    pointRadius: 0,
                    pointHoverRadius: 4
                },
                {
                    label: '50-Day SMA',
                    data: sma50Prices,
                    borderColor: '#8B5CF6',
                    borderWidth: 1.8,
                    borderDash: [4, 4],
                    fill: false,
                    tension: 0.2,
                    pointRadius: 0,
                    pointHoverRadius: 4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        color: '#94A3B8',
                        font: { family: 'Plus Jakarta Sans', size: 12 },
                        usePointStyle: true,
                        boxWidth: 8
                    }
                },
                tooltip: {
                    backgroundColor: '#1E293B',
                    titleColor: '#F8FAFC',
                    bodyColor: '#CBD5E1',
                    borderColor: '#334155',
                    borderWidth: 1,
                    padding: 10,
                    callbacks: {
                        label: function (context) {
                            const val = context.raw;
                            return val !== null && val !== undefined
                                ? ` ${context.dataset.label}: ${currSym}${Number(val).toFixed(2)}`
                                : ` ${context.dataset.label}: Not available`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(30, 41, 59, 0.4)' },
                    ticks: {
                        color: '#64748B',
                        font: { family: 'JetBrains Mono', size: 10 },
                        maxTicksLimit: 8
                    }
                },
                y: {
                    grid: { color: 'rgba(30, 41, 59, 0.5)' },
                    ticks: {
                        color: '#64748B',
                        font: { family: 'JetBrains Mono', size: 11 },
                        callback: function (val) {
                            return currSym + Number(val).toFixed(0);
                        }
                    }
                }
            }
        }
    });
}

/**
 * Render Rule-Based Stock Outlook & Technical Indicators
 */
function renderStockOutlook(data) {
    const outlook = data.outlook || {};
    const metrics = data.metrics || {};
    const currSym = data.currency_symbol || '₹';

    // 1. Outlook Main Badge
    const badge = document.getElementById('outlookMainBadge');
    const signal = outlook.signal || 'INSUFFICIENT_DATA';

    if (signal === 'POSITIVE') {
        badge.textContent = '🟢 Positive Technical Trend (Bullish)';
        badge.style.background = 'rgba(16, 185, 129, 0.2)';
        badge.style.color = '#34D399';
        badge.style.border = '1px solid rgba(16, 185, 129, 0.5)';
    } else if (signal === 'NEGATIVE') {
        badge.textContent = '🔴 Negative Technical Trend (Bearish)';
        badge.style.background = 'rgba(239, 68, 68, 0.2)';
        badge.style.color = '#F87171';
        badge.style.border = '1px solid rgba(239, 68, 68, 0.5)';
    } else if (signal === 'MIXED') {
        badge.textContent = '🟡 Mixed Technical Signals (Neutral)';
        badge.style.background = 'rgba(245, 158, 11, 0.2)';
        badge.style.color = '#FBBF24';
        badge.style.border = '1px solid rgba(245, 158, 11, 0.5)';
    } else {
        badge.textContent = '⚪ Insufficient Historical Data';
        badge.style.background = 'rgba(100, 116, 139, 0.2)';
        badge.style.color = '#94A3B8';
        badge.style.border = '1px solid rgba(100, 116, 139, 0.5)';
    }

    // 2. Metrics Cards
    document.getElementById('outlookSma20Val').textContent = metrics.sma20 ? `${currSym}${metrics.sma20.toFixed(2)}` : 'Not available';
    document.getElementById('outlookSma50Val').textContent = metrics.sma50 ? `${currSym}${metrics.sma50.toFixed(2)}` : 'Not available';
    
    document.getElementById('outlookRsiVal').textContent = metrics.rsi14 ? metrics.rsi14.toFixed(1) : 'Not available';
    document.getElementById('outlookRsiZone').textContent = outlook.rsi_zone || 'Momentum gauge';

    const change30d = metrics.change_30d_pct !== undefined ? metrics.change_30d_pct : null;
    const c30Elem = document.getElementById('outlookChange30dVal');
    if (change30d !== null) {
        c30Elem.textContent = (change30d >= 0 ? '+' : '') + change30d.toFixed(2) + '%';
        c30Elem.className = 'metric-value font-mono ' + (change30d >= 0 ? 'positive' : 'negative');
    } else {
        c30Elem.textContent = 'Not available';
        c30Elem.className = 'metric-value font-mono text-muted';
    }

    const vol = metrics.volatility_annualized !== undefined ? metrics.volatility_annualized : null;
    document.getElementById('outlookVolatilityVal').textContent = vol !== null ? `${vol.toFixed(1)}%` : 'Not available';

    // 3. Explanation & Limitations
    document.getElementById('outlookRationaleTitle').textContent = `${data.name || data.symbol} (${data.symbol})`;
    document.getElementById('outlookExplanationText').textContent = outlook.explanation || 'No outlook assessment available.';
    document.getElementById('outlookRisksText').textContent = outlook.risks || 'Technical indicators reflect past trading performance only.';
}

/**
 * Render Portfolio Equity Over Time Chart
 */
function initPortfolioEquityChart() {
    const canvas = document.getElementById('portfolioEquityChart');
    if (!canvas || !portfolioSnapshots || portfolioSnapshots.length === 0) {
        return;
    }

    const labels = portfolioSnapshots.map(s => s.snapshot_date);
    const equities = portfolioSnapshots.map(s => parseFloat(s.total_equity));

    const ctx = canvas.getContext('2d');
    equityChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Portfolio Equity',
                    data: equities,
                    borderColor: '#10B981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 2.2,
                    fill: true,
                    tension: 0.2,
                    pointRadius: equities.length > 20 ? 0 : 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#10B981'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1E293B',
                    titleColor: '#F8FAFC',
                    bodyColor: '#CBD5E1',
                    callbacks: {
                        label: function (ctx) {
                            return ` Total Equity: <?= $curr ?>${Number(ctx.raw).toFixed(2)}`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(30, 41, 59, 0.4)' },
                    ticks: {
                        color: '#64748B',
                        font: { family: 'JetBrains Mono', size: 10 },
                        maxTicksLimit: 7
                    }
                },
                y: {
                    grid: { color: 'rgba(30, 41, 59, 0.5)' },
                    ticks: {
                        color: '#64748B',
                        font: { family: 'JetBrains Mono', size: 10 },
                        callback: function (val) {
                            return '<?= $curr ?>' + Number(val).toFixed(0);
                        }
                    }
                }
            }
        }
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
