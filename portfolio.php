<?php
/**
 * Portfolio Management Page
 * Displays open holdings, profit/loss, and stop-loss configuration
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

$flashMessage = null;
$flashType = 'info';

// Handle Stop-Loss Configuration Updates
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_stop_loss') {
    $posId = (int)($_POST['position_id'] ?? 0);
    $slPriceRaw = trim($_POST['stop_loss_price'] ?? '');
    $slPctRaw = trim($_POST['stop_loss_percent'] ?? '');

    $slPrice = ($slPriceRaw !== '') ? (float)$slPriceRaw : null;
    $slPct = ($slPctRaw !== '') ? (float)$slPctRaw : null;

    $res = update_position_stop_loss($posId, $slPrice, $slPct);
    if ($res['success']) {
        $flashMessage = $res['message'];
        $flashType = 'success';
    } else {
        $flashMessage = $res['error'];
        $flashType = 'error';
    }
}

// Fetch positions & summary
try {
    $positions = get_open_positions();
    $summary = get_portfolio_summary();
} catch (Throwable $e) {
    $positions = [];
    $summary = [
        'available_cash'       => 0,
        'portfolio_value'      => 0,
        'total_invested'       => 0,
        'total_unrealised_pnl' => 0,
        'currency'             => 'INR'
    ];
    $flashMessage = 'Could not load portfolio data: ' . $e->getMessage();
    $flashType = 'error';
}

$pageTitle = 'My Portfolio - Stock Market Simulator';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Virtual Stock Portfolio</h1>
        <p class="page-subtitle">Track your holdings, unrealised gains, and configure simulated automatic stop-losses.</p>
    </div>
    <div>
        <a href="buy.php" class="btn btn-primary">+ Buy More Stocks</a>
    </div>
</div>

<?php if ($flashMessage): ?>
    <div class="alert alert-<?= $flashType ?>">
        <span><?= htmlspecialchars($flashMessage) ?></span>
    </div>
<?php endif; ?>

<!-- Portfolio Quick Overview Bar -->
<div class="metrics-grid">
    <div class="metric-card">
        <div class="metric-label">Holdings Count</div>
        <div class="metric-value"><?= count($positions) ?></div>
        <div class="metric-sub">Open Positions</div>
    </div>
    <div class="metric-card">
        <div class="metric-label">Total Cost Basis</div>
        <div class="metric-value"><?= format_money($summary['total_invested'], $summary['currency']) ?></div>
        <div class="metric-sub">Total Investment</div>
    </div>
    <div class="metric-card">
        <div class="metric-label">Market Value</div>
        <div class="metric-value"><?= format_money($summary['portfolio_value'], $summary['currency']) ?></div>
        <div class="metric-sub">Current Worth</div>
    </div>
    <div class="metric-card">
        <div class="metric-label">Total Unrealised P&amp;L</div>
        <div class="metric-value <?= $summary['total_unrealised_pnl'] >= 0 ? 'positive' : 'negative' ?>">
            <?= ($summary['total_unrealised_pnl'] >= 0 ? '+' : '') . format_money($summary['total_unrealised_pnl'], $summary['currency']) ?>
        </div>
        <div class="metric-sub">Floating Profit / Loss</div>
    </div>
</div>

<!-- Portfolio Holdings Table -->
<div class="panel">
    <div class="panel-header">
        <h2 class="panel-title">
            <span>Current Holdings</span>
        </h2>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Symbol</th>
                    <th>Company Name</th>
                    <th>Quantity</th>
                    <th>Avg Purchase</th>
                    <th>Current Price</th>
                    <th>Investment Cost</th>
                    <th>Market Value</th>
                    <th>Unrealised P&amp;L</th>
                    <th>Stop-Loss Setting</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($positions)): ?>
                    <tr>
                        <td colspan="10" class="table-empty">
                            You do not hold any open positions. Browse stocks and make your first virtual purchase!
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
                            <td class="font-mono"><?= format_money($pos['invested_cost'], $pos['currency']) ?></td>
                            <td class="font-mono"><?= format_money($pos['market_value'], $pos['currency']) ?></td>
                            <td class="font-mono <?= $pos['unrealised_pnl'] >= 0 ? 'positive' : 'negative' ?>">
                                <?= ($pos['unrealised_pnl'] >= 0 ? '+' : '') . format_money($pos['unrealised_pnl'], $pos['currency']) ?><br>
                                <small>(<?= format_percent($pos['unrealised_pnl_percent']) ?>)</small>
                            </td>
                            <td>
                                <?php if ($pos['stop_loss_price']): ?>
                                    <div style="display: flex; flex-direction: column; gap: 2px;">
                                        <span class="badge badge-stoploss">
                                            <?= format_money($pos['stop_loss_price'], $pos['currency']) ?>
                                        </span>
                                        <small class="text-muted"><?= $pos['stop_loss_percent'] ?>% below cost</small>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted"><small>None</small></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: 6px;">
                                    <button 
                                        type="button" 
                                        class="btn btn-secondary btn-sm"
                                        onclick="openStopLossModal(<?= $pos['id'] ?>, '<?= htmlspecialchars($pos['symbol']) ?>', <?= $pos['average_buy_price'] ?>, '<?= $pos['stop_loss_price'] ?? '' ?>', '<?= $pos['stop_loss_percent'] ?? '' ?>')"
                                    >
                                        ⚙️ Stop-Loss
                                    </button>
                                    <a href="sell.php?symbol=<?= urlencode($pos['symbol']) ?>" class="btn btn-danger btn-sm">
                                        Sell
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Stop-Loss Config Modal -->
<div id="stopLossModal" class="modal-backdrop" style="display: none;">
    <div class="modal-dialog">
        <form method="POST" action="portfolio.php">
            <input type="hidden" name="action" value="update_stop_loss">
            <input type="hidden" name="position_id" id="modalPositionId" value="">

            <div class="modal-header">
                <h3>Configure Stop-Loss: <span id="modalSymbolTitle" class="font-mono"></span></h3>
                <button type="button" class="btn-close" onclick="closeStopLossModal()">&times;</button>
            </div>

            <div class="modal-body">
                <p class="text-muted" style="margin-bottom: 14px;">
                    Average Purchase Price: <strong id="modalAvgPriceText" class="font-mono"></strong>
                </p>

                <div class="form-group">
                    <label class="form-label" for="modalStopLossPercent">Stop-Loss Percentage (% below purchase price)</label>
                    <input 
                        type="number" 
                        step="0.01" 
                        min="0.1" 
                        max="99.9" 
                        id="modalStopLossPercent" 
                        name="stop_loss_percent" 
                        class="form-control" 
                        placeholder="e.g. 5.0"
                    >
                    <div class="form-hint">Example: 5% triggers auto-sell when price falls 5% below cost.</div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="modalStopLossPrice">Or Fixed Stop-Loss Price</label>
                    <input 
                        type="number" 
                        step="0.01" 
                        min="0.01" 
                        id="modalStopLossPrice" 
                        name="stop_loss_price" 
                        class="form-control" 
                        placeholder="e.g. 950.00"
                    >
                    <div class="form-hint">Entering one automatically calculates the other. Leave blank to disable stop-loss.</div>
                </div>

                <div class="notice-note">
                    <strong>Note:</strong> Stop-loss orders are executed virtually when the background monitor retrieves a price at or below your stop-loss threshold.
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeStopLossModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Stop-Loss</button>
            </div>
        </form>
    </div>
</div>

<script>
let currentAvgPrice = 0;

function openStopLossModal(posId, symbol, avgPrice, currentSlPrice, currentSlPct) {
    document.getElementById('modalPositionId').value = posId;
    document.getElementById('modalSymbolTitle').textContent = symbol;
    document.getElementById('modalAvgPriceText').textContent = '₹' + avgPrice.toFixed(2);
    currentAvgPrice = avgPrice;

    const slPriceInput = document.getElementById('modalStopLossPrice');
    const slPctInput = document.getElementById('modalStopLossPercent');

    slPriceInput.value = currentSlPrice || '';
    slPctInput.value = currentSlPct || '';

    // Synchronize inputs
    slPctInput.oninput = function() {
        const pct = parseFloat(slPctInput.value);
        if (!isNaN(pct) && pct > 0 && pct < 100 && currentAvgPrice > 0) {
            slPriceInput.value = (currentAvgPrice * (1 - (pct / 100))).toFixed(2);
        } else if (slPctInput.value === '') {
            slPriceInput.value = '';
        }
    };

    slPriceInput.oninput = function() {
        const slPrice = parseFloat(slPriceInput.value);
        if (!isNaN(slPrice) && slPrice > 0 && currentAvgPrice > 0 && slPrice < currentAvgPrice) {
            slPctInput.value = (((currentAvgPrice - slPrice) / currentAvgPrice) * 100).toFixed(2);
        } else if (slPriceInput.value === '') {
            slPctInput.value = '';
        }
    };

    document.getElementById('stopLossModal').style.display = 'flex';
}

function closeStopLossModal() {
    document.getElementById('stopLossModal').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
