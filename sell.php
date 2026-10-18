<?php
/**
 * Virtual Stock Sell Page
 * Stock Market Automated System
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

$flashMessage = null;
$flashType = 'info';

$selectedSymbol = trim(strtoupper($_GET['symbol'] ?? ''));
$positions = [];
$targetPosition = null;

try {
    $positions = get_open_positions();
} catch (Throwable $e) {
    $flashMessage = 'Database connection notice: ' . $e->getMessage();
    $flashType = 'error';
}

foreach ($positions as $pos) {
    if ($pos['symbol'] === $selectedSymbol) {
        $targetPosition = $pos;
        break;
    }
}

// If no specific symbol passed but positions exist, pick the first
if (!$targetPosition && !empty($positions)) {
    $targetPosition = $positions[0];
    $selectedSymbol = $targetPosition['symbol'];
}

// Handle Sell Form Submission
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $symbol = trim(strtoupper($_POST['symbol'] ?? ''));
    $quantity = (int)($_POST['quantity'] ?? 0);

    $result = execute_sell_stock($symbol, $quantity, 'MANUAL_SELL');

    if ($result['success']) {
        $flashMessage = $result['message'];
        $flashType = 'success';
        // Refresh positions
        $positions = get_open_positions();
        $targetPosition = null;
        foreach ($positions as $pos) {
            if ($pos['symbol'] === $symbol) {
                $targetPosition = $pos;
                break;
            }
        }
        if (!$targetPosition && !empty($positions)) {
            $targetPosition = $positions[0];
            $selectedSymbol = $targetPosition['symbol'];
        }
    } else {
        $flashMessage = $result['error'];
        $flashType = 'error';
    }
}

$pageTitle = 'Sell Stocks - Virtual Paper Trading';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Virtual Sell Order</h1>
        <p class="page-subtitle">Liquidate all or part of an open position and lock in simulated profit or loss.</p>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="portfolio.php" class="btn btn-secondary">Back to Portfolio</a>
    </div>
</div>

<?php if ($flashMessage): ?>
    <div class="alert alert-<?= $flashType ?>">
        <span><?= htmlspecialchars($flashMessage) ?></span>
    </div>
<?php endif; ?>

<?php if (empty($positions)): ?>
    <div class="panel" style="text-align: center; padding: 48px 24px;">
        <h3 style="margin-bottom: 8px;">No Open Positions</h3>
        <p class="text-muted" style="margin-bottom: 20px;">You do not have any virtual shares to sell. Make a purchase first to start trading.</p>
        <a href="buy.php" class="btn btn-primary">Go to Buy Stocks</a>
    </div>
<?php else: ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 24px;">
        
        <!-- Left: Sell Form -->
        <div class="panel">
            <h2 class="panel-title" style="margin-bottom: 18px;">Confirm Sale</h2>

            <form 
                method="POST" 
                action="sell.php" 
                id="sellStockForm"
                data-avg-price="<?= $targetPosition ? $targetPosition['average_buy_price'] : 0 ?>"
            >
                <!-- Stock Position Selector -->
                <div class="form-group">
                    <label class="form-label" for="sellSymbolSelect">Select Position to Sell</label>
                    <select id="sellSymbolSelect" name="symbol" class="form-control font-mono" onchange="window.location.href='sell.php?symbol=' + encodeURIComponent(this.value)">
                        <?php foreach ($positions as $p): ?>
                            <option value="<?= htmlspecialchars($p['symbol']) ?>" <?= $p['symbol'] === $selectedSymbol ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['symbol']) ?> (<?= $p['quantity'] ?> shares held @ <?= format_money($p['average_buy_price'], $p['currency']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($targetPosition): ?>
                    <!-- Current Position Stats -->
                    <div style="background-color: var(--bg-input); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 14px; margin-bottom: 18px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                            <span class="text-muted">Shares Owned:</span>
                            <strong class="font-mono"><?= number_format($targetPosition['quantity']) ?></strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                            <span class="text-muted">Average Buy Price:</span>
                            <span class="font-mono"><?= format_money($targetPosition['average_buy_price'], $targetPosition['currency']) ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span class="text-muted">Latest Market Price:</span>
                            <span class="font-mono"><strong><?= format_money($targetPosition['current_price'], $targetPosition['currency']) ?></strong></span>
                        </div>
                    </div>

                    <!-- Hidden Price input for script calculation -->
                    <input type="hidden" id="sellPrice" value="<?= $targetPosition['current_price'] ?>">

                    <!-- Number of Shares to Sell -->
                    <div class="form-group">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <label class="form-label" for="sellQuantity" style="margin-bottom: 0;">Shares to Sell</label>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="setSellAll(<?= $targetPosition['quantity'] ?>)">
                                Max (<?= $targetPosition['quantity'] ?>)
                            </button>
                        </div>
                        <input 
                            type="number" 
                            name="quantity" 
                            id="sellQuantity" 
                            class="form-control font-mono" 
                            min="1" 
                            max="<?= $targetPosition['quantity'] ?>" 
                            value="<?= $targetPosition['quantity'] ?>" 
                            required
                        >
                        <div class="form-hint">You cannot sell more shares than you hold (<?= $targetPosition['quantity'] ?>).</div>
                    </div>

                    <!-- Live Realised P&L Calculation Box -->
                    <div class="trade-calculation-box">
                        <div class="calc-row">
                            <span>Estimated Sale Proceeds:</span>
                            <span id="calcProceeds" class="font-mono">₹0.00</span>
                        </div>
                        <div class="calc-row total-row">
                            <span>Estimated Realised Profit / Loss:</span>
                            <span id="calcEstimatedPnl" class="font-mono">₹0.00</span>
                        </div>
                    </div>

                    <div style="margin-top: 20px;">
                        <button type="submit" class="btn btn-danger" style="width: 100%; padding: 14px; font-size: 1rem;">
                            Confirm Virtual Sale
                        </button>
                    </div>

                    <p class="text-muted" style="text-align: center; font-size: 0.78rem; margin-top: 12px;">
                        Simulated transaction. Sale proceeds will be credited immediately to your virtual balance.
                    </p>
                <?php endif; ?>
            </form>
        </div>

        <!-- Right: Information & Positions List -->
        <div class="panel">
            <h3 style="font-size: 1.1rem; margin-bottom: 14px;">📊 All Holdings Available for Sale</h3>
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <?php foreach ($positions as $p): ?>
                    <a href="sell.php?symbol=<?= urlencode($p['symbol']) ?>" style="text-decoration: none;">
                        <div class="metric-card" style="padding: 12px 16px; border-color: <?= $p['symbol'] === $selectedSymbol ? 'var(--accent-blue)' : 'var(--border-color)' ?>;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <strong class="font-mono" style="color: var(--text-primary);"><?= htmlspecialchars($p['symbol']) ?></strong>
                                    <div class="text-muted"><small><?= $p['quantity'] ?> shares &bull; Avg: <?= format_money($p['average_buy_price'], $p['currency']) ?></small></div>
                                </div>
                                <div style="text-align: right;">
                                    <div class="font-mono <?= $p['unrealised_pnl'] >= 0 ? 'positive' : 'negative' ?>">
                                        <?= ($p['unrealised_pnl'] >= 0 ? '+' : '') . format_money($p['unrealised_pnl'], $p['currency']) ?>
                                    </div>
                                    <small class="text-muted">Unrealised</small>
                                </div>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
function setSellAll(maxQty) {
    const input = document.getElementById('sellQuantity');
    if (input) {
        input.value = maxQty;
        input.dispatchEvent(new Event('input'));
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
