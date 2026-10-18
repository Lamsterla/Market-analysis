<?php
/**
 * Virtual Stock Purchase Page
 * Stock Market Automated System
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

$flashMessage = null;
$flashType = 'info';

$account = [
    'balance' => (float)(getenv('INITIAL_BALANCE') ?: 100000.00),
    'currency' => getenv('CURRENCY_CODE') ?: 'INR'
];

try {
    $account = get_account_balance();
} catch (Throwable $e) {
    $flashMessage = 'Database connection notice: ' . $e->getMessage();
    $flashType = 'error';
}

$availableCash = $account['balance'];
$currency = $account['currency'];

$prefilledSymbol = trim(strtoupper($_GET['symbol'] ?? ''));
$initialQuote = null;

if ($prefilledSymbol !== '') {
    $initialQuote = get_stock_quote($prefilledSymbol);
}

// Handle Purchase Submission
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $symbol = trim(strtoupper($_POST['symbol'] ?? ''));
    $quantity = (int)($_POST['quantity'] ?? 0);
    $slPriceRaw = trim($_POST['stop_loss_price'] ?? '');
    $slPctRaw = trim($_POST['stop_loss_percent'] ?? '');

    $slPrice = ($slPriceRaw !== '') ? (float)$slPriceRaw : null;
    $slPct = ($slPctRaw !== '') ? (float)$slPctRaw : null;

    $result = execute_buy_stock($symbol, $quantity, $slPrice, $slPct);

    if ($result['success']) {
        $flashMessage = $result['message'];
        $flashType = 'success';
        // Refresh account balance
        $account = get_account_balance();
        $availableCash = $account['balance'];
        $prefilledSymbol = '';
        $initialQuote = null;
    } else {
        $flashMessage = $result['error'];
        $flashType = 'error';
    }
}

$pageTitle = 'Buy Stocks - Virtual Paper Trading';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Virtual Buy Order</h1>
        <p class="page-subtitle">Simulate purchasing shares with virtual money using live Yahoo Finance market quotes.</p>
    </div>
    <div class="metric-card" style="padding: 10px 18px; margin-bottom: 0;">
        <span class="metric-label" style="font-size: 0.72rem;">Available Virtual Cash</span>
        <div class="metric-value" style="font-size: 1.25rem;">
            <?= format_money($availableCash, $currency) ?>
        </div>
    </div>
</div>

<?php if ($flashMessage): ?>
    <div class="alert alert-<?= $flashType ?>">
        <span><?= htmlspecialchars($flashMessage) ?></span>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 24px;">
    
    <!-- Left: Buy Form -->
    <div class="panel">
        <h2 class="panel-title" style="margin-bottom: 18px;">Order Configuration</h2>

        <form method="POST" action="buy.php" id="buyStockForm" data-available-cash="<?= $availableCash ?>">
            
            <!-- Stock Symbol -->
            <div class="form-group">
                <label class="form-label" for="buySymbolInput">Stock Symbol</label>
                <div style="display: flex; gap: 8px;">
                    <input 
                        type="text" 
                        name="symbol" 
                        id="buySymbolInput" 
                        class="form-control font-mono" 
                        placeholder="e.g. RELIANCE.NS, TCS.NS, AAPL, MSFT" 
                        value="<?= htmlspecialchars($prefilledSymbol) ?>" 
                        required 
                        autocomplete="off"
                    >
                    <button type="button" class="btn btn-secondary" onclick="fetchQuoteForBuy()">Check Price</button>
                </div>
                <div class="form-hint">Supports Indian NSE/BSE stocks (e.g. <code>RELIANCE.NS</code>) and US equities (e.g. <code>AAPL</code>).</div>
            </div>

            <!-- Execution Price Display -->
            <div class="form-group">
                <label class="form-label" for="buyPrice">Latest Market Price</label>
                <input 
                    type="number" 
                    step="0.01" 
                    id="buyPrice" 
                    class="form-control font-mono" 
                    value="<?= $initialQuote && $initialQuote['success'] ? (float)$initialQuote['price'] : '' ?>" 
                    readonly 
                    placeholder="Click 'Check Price' above..."
                >
                <div id="buyPriceMeta" class="form-hint">
                    <?php if ($initialQuote && $initialQuote['success']): ?>
                        <?= htmlspecialchars($initialQuote['name'] ?? '') ?> &bull; Last updated: <?= htmlspecialchars($initialQuote['timestamp'] ?? '') ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Number of Shares -->
            <div class="form-group">
                <label class="form-label" for="buyQuantity">Number of Shares</label>
                <input 
                    type="number" 
                    name="quantity" 
                    id="buyQuantity" 
                    class="form-control font-mono" 
                    min="1" 
                    step="1" 
                    value="1" 
                    required
                >
            </div>

            <!-- Optional Initial Stop-Loss -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="buyStopLossPercent">Stop-Loss % (Optional)</label>
                    <input 
                        type="number" 
                        step="0.01" 
                        min="0.1" 
                        max="99.9" 
                        name="stop_loss_percent" 
                        id="buyStopLossPercent" 
                        class="form-control font-mono" 
                        placeholder="e.g. 5.0"
                    >
                    <div class="form-hint">% drop to trigger auto-sale</div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="buyStopLossPrice">Stop-Loss Price (Optional)</label>
                    <input 
                        type="number" 
                        step="0.01" 
                        min="0.01" 
                        name="stop_loss_price" 
                        id="buyStopLossPrice" 
                        class="form-control font-mono" 
                        placeholder="e.g. 950.00"
                    >
                    <div class="form-hint">Calculated automatically</div>
                </div>
            </div>

            <!-- Live Calculation Summary Box -->
            <div class="trade-calculation-box">
                <div class="calc-row">
                    <span>Available Cash:</span>
                    <span class="font-mono"><?= format_money($availableCash, $currency) ?></span>
                </div>
                <div class="calc-row">
                    <span>Total Purchase Cost:</span>
                    <span id="calcTotalCost" class="font-mono">₹0.00</span>
                </div>
                <div class="calc-row total-row">
                    <span>Remaining Balance After Trade:</span>
                    <span id="calcRemainingCash" class="font-mono"><?= format_money($availableCash, $currency) ?></span>
                </div>
            </div>

            <div style="margin-top: 20px;">
                <button type="submit" id="submitBuyBtn" class="btn btn-primary" style="width: 100%; padding: 14px; font-size: 1rem;">
                    Confirm Virtual Purchase
                </button>
            </div>
            
            <p class="text-muted" style="text-align: center; font-size: 0.78rem; margin-top: 12px;">
                Virtual paper trading simulation only. Deducts from virtual cash. Never connects to a broker.
            </p>
        </form>
    </div>

    <!-- Right: Information & Quick Guide -->
    <div>
        <div class="panel" style="margin-bottom: 24px;">
            <h3 style="font-size: 1.1rem; margin-bottom: 12px;">ℹ️ Order Execution Rules</h3>
            <ul style="margin-left: 20px; font-size: 0.88rem; line-height: 1.8; color: var(--text-secondary);">
                <li>Orders are executed at the latest available market price fetched via Yahoo Finance.</li>
                <li>Your virtual cash balance must be sufficient to cover <code>Price &times; Shares</code>.</li>
                <li>If you already hold this stock, your average purchase price is re-computed using the weighted average cost formula.</li>
                <li>Configuring a stop-loss will automatically sell the position when market price touches or falls below your threshold.</li>
            </ul>
        </div>

        <div class="panel">
            <h3 style="font-size: 1.1rem; margin-bottom: 12px;">📈 Popular Symbols to Try</h3>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <button type="button" class="btn btn-secondary btn-sm" style="justify-content: flex-start;" onclick="selectSymbol('RELIANCE.NS')">
                    <strong class="font-mono" style="width: 120px;">RELIANCE.NS</strong> Reliance Industries (NSE India)
                </button>
                <button type="button" class="btn btn-secondary btn-sm" style="justify-content: flex-start;" onclick="selectSymbol('TCS.NS')">
                    <strong class="font-mono" style="width: 120px;">TCS.NS</strong> Tata Consultancy Services (NSE India)
                </button>
                <button type="button" class="btn btn-secondary btn-sm" style="justify-content: flex-start;" onclick="selectSymbol('INFY.NS')">
                    <strong class="font-mono" style="width: 120px;">INFY.NS</strong> Infosys Ltd (NSE India)
                </button>
                <button type="button" class="btn btn-secondary btn-sm" style="justify-content: flex-start;" onclick="selectSymbol('AAPL')">
                    <strong class="font-mono" style="width: 120px;">AAPL</strong> Apple Inc. (NASDAQ US)
                </button>
                <button type="button" class="btn btn-secondary btn-sm" style="justify-content: flex-start;" onclick="selectSymbol('MSFT')">
                    <strong class="font-mono" style="width: 120px;">MSFT</strong> Microsoft Corporation (NASDAQ US)
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function selectSymbol(sym) {
    document.getElementById('buySymbolInput').value = sym;
    fetchQuoteForBuy();
}

async function fetchQuoteForBuy() {
    const symInput = document.getElementById('buySymbolInput');
    const priceInput = document.getElementById('buyPrice');
    const metaEl = document.getElementById('buyPriceMeta');
    const symbol = symInput.value.trim().toUpperCase();

    if (!symbol) {
        alert('Please enter a stock symbol.');
        return;
    }

    metaEl.textContent = 'Fetching market price...';
    try {
        const res = await fetch(`api/stock_price.php?symbol=${encodeURIComponent(symbol)}`);
        const data = await res.json();
        if (data.success && data.price) {
            priceInput.value = parseFloat(data.price).toFixed(2);
            metaEl.textContent = `${data.name || symbol} (${data.currency}) • Updated: ${data.timestamp || 'Just now'}`;
            // Trigger recalculation
            priceInput.dispatchEvent(new Event('input'));
        } else {
            priceInput.value = '';
            metaEl.textContent = data.error || 'Failed to retrieve price.';
            alert(data.error || 'Symbol lookup failed.');
        }
    } catch (e) {
        metaEl.textContent = 'Network error fetching price.';
    }
}

// Auto-fetch if symbol was prefilled in URL
document.addEventListener('DOMContentLoaded', () => {
    const priceInput = document.getElementById('buyPrice');
    if (priceInput && priceInput.value) {
        priceInput.dispatchEvent(new Event('input'));
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
