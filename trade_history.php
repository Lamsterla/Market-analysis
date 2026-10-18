<?php
/**
 * Trade History Ledger
 * Stock Market Automated System
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

$trades = [];
$stats = [
    'total_trades' => 0,
    'total_bought' => 0,
    'total_sold'   => 0,
    'lifetime_pnl' => 0
];
$flashMessage = null;
$flashType = 'info';

// Filter parameters
$filterType = $_GET['type'] ?? 'ALL';
$filterType = in_array($filterType, ['BUY', 'SELL', 'STOP_LOSS']) ? $filterType : 'ALL';

try {
    $pdo = get_db_connection();

    $sql = "SELECT * FROM trades WHERE 1=1";
    $params = [];

    if ($filterType === 'BUY') {
        $sql .= " AND trade_type = 'BUY'";
    } elseif ($filterType === 'SELL') {
        $sql .= " AND trade_type = 'SELL'";
    } elseif ($filterType === 'STOP_LOSS') {
        $sql .= " AND reason = 'STOP_LOSS_TRIGGERED'";
    }

    $sql .= " ORDER BY created_at DESC LIMIT 100";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $trades = $stmt->fetchAll();

    // Calculate totals
    $totStmt = $pdo->query("
        SELECT 
            COUNT(*) as total_trades,
            COALESCE(SUM(CASE WHEN trade_type = 'BUY' THEN total_amount ELSE 0 END), 0) as total_bought,
            COALESCE(SUM(CASE WHEN trade_type = 'SELL' THEN total_amount ELSE 0 END), 0) as total_sold,
            COALESCE(SUM(CASE WHEN trade_type = 'SELL' THEN realised_pnl ELSE 0 END), 0) as lifetime_pnl
        FROM trades
    ");
    $stats = $totStmt->fetch() ?: $stats;
} catch (Throwable $e) {
    $flashMessage = 'Database connection notice: ' . $e->getMessage();
    $flashType = 'error';
}

$pageTitle = 'Trade History - Stock Market Simulator';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Simulated Trade History</h1>
        <p class="page-subtitle">Complete chronological audit ledger of all virtual buy and sell executions.</p>
    </div>
    <div style="display: flex; gap: 8px;">
        <a href="trade_history.php?type=ALL" class="btn btn-sm <?= $filterType === 'ALL' ? 'btn-primary' : 'btn-secondary' ?>">All Trades</a>
        <a href="trade_history.php?type=BUY" class="btn btn-sm <?= $filterType === 'BUY' ? 'btn-primary' : 'btn-secondary' ?>">Buys Only</a>
        <a href="trade_history.php?type=SELL" class="btn btn-sm <?= $filterType === 'SELL' ? 'btn-primary' : 'btn-secondary' ?>">Sells Only</a>
        <a href="trade_history.php?type=STOP_LOSS" class="btn btn-sm <?= $filterType === 'STOP_LOSS' ? 'btn-primary' : 'btn-secondary' ?>">Stop-Loss Triggers</a>
    </div>
</div>

<!-- Ledger Stats Grid -->
<div class="metrics-grid">
    <div class="metric-card">
        <div class="metric-label">Total Transactions</div>
        <div class="metric-value"><?= number_format((int)$stats['total_trades']) ?></div>
        <div class="metric-sub">Recorded Orders</div>
    </div>
    <div class="metric-card">
        <div class="metric-label">Total Volume Bought</div>
        <div class="metric-value"><?= format_money((float)$stats['total_bought']) ?></div>
        <div class="metric-sub">Capital Deployed</div>
    </div>
    <div class="metric-card">
        <div class="metric-label">Total Proceeds Realised</div>
        <div class="metric-value"><?= format_money((float)$stats['total_sold']) ?></div>
        <div class="metric-sub">Sales Value</div>
    </div>
    <div class="metric-card">
        <div class="metric-label">Net Lifetime Realised P&amp;L</div>
        <div class="metric-value <?= (float)$stats['lifetime_pnl'] >= 0 ? 'positive' : 'negative' ?>">
            <?= ((float)$stats['lifetime_pnl'] >= 0 ? '+' : '') . format_money((float)$stats['lifetime_pnl']) ?>
        </div>
        <div class="metric-sub">Closed Trades Profit/Loss</div>
    </div>
</div>

<!-- Trade History Table -->
<div class="panel">
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th># ID</th>
                    <th>Date &amp; Timestamp</th>
                    <th>Type</th>
                    <th>Stock Symbol</th>
                    <th>Company Name</th>
                    <th>Shares</th>
                    <th>Simulated Price</th>
                    <th>Total Value</th>
                    <th>Realised P&amp;L</th>
                    <th>Execution Reason</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($trades)): ?>
                    <tr>
                        <td colspan="10" class="table-empty">
                            No trades matching filter "<?= htmlspecialchars($filterType) ?>" found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($trades as $t): ?>
                        <tr>
                            <td class="font-mono text-muted">#<?= $t['id'] ?></td>
                            <td class="text-muted"><small><?= htmlspecialchars($t['created_at']) ?></small></td>
                            <td>
                                <span class="badge <?= $t['trade_type'] === 'BUY' ? 'badge-buy' : 'badge-sell' ?>">
                                    <?= htmlspecialchars($t['trade_type']) ?>
                                </span>
                            </td>
                            <td class="font-mono"><strong><?= htmlspecialchars($t['symbol']) ?></strong></td>
                            <td><?= htmlspecialchars($t['company_name'] ?: $t['symbol']) ?></td>
                            <td class="font-mono"><?= number_format((int)$t['quantity']) ?></td>
                            <td class="font-mono"><?= format_money((float)$t['price'], $t['currency'] ?? 'INR') ?></td>
                            <td class="font-mono"><?= format_money((float)$t['total_amount'], $t['currency'] ?? 'INR') ?></td>
                            <td class="font-mono">
                                <?php if ($t['trade_type'] === 'SELL'): ?>
                                    <span class="<?= (float)$t['realised_pnl'] >= 0 ? 'positive' : 'negative' ?>">
                                        <?= ((float)$t['realised_pnl'] >= 0 ? '+' : '') . format_money((float)$t['realised_pnl'], $t['currency'] ?? 'INR') ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($t['reason'] === 'STOP_LOSS_TRIGGERED'): ?>
                                    <span class="badge badge-stoploss" title="<?= htmlspecialchars($t['notes'] ?? '') ?>">
                                        ⚡ STOP-LOSS
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-manual">
                                        <?= htmlspecialchars($t['reason']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
