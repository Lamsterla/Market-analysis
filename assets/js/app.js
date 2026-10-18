/**
 * Stock Market Automated System - Vanilla JavaScript Client
 * Handles stock searches, real-time calculations, modal dialogs, and monitor polling
 */

document.addEventListener('DOMContentLoaded', () => {
    initStockSearch();
    initTradeCalculations();
    initMonitorStatusPolling();
});

/* --------------------------------------------------------------------------
   Stock Search
   -------------------------------------------------------------------------- */
function initStockSearch() {
    const searchForm = document.getElementById('stockSearchForm');
    const searchInput = document.getElementById('stockSearchInput');
    const resultsContainer = document.getElementById('searchResultContainer');

    if (!searchForm || !searchInput || !resultsContainer) {
        return;
    }

    searchForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const symbol = searchInput.value.trim().toUpperCase();
        if (symbol) {
            lookupStock(symbol);
        }
    });

    // Quick symbol chips
    document.querySelectorAll('.symbol-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            const sym = chip.getAttribute('data-symbol');
            if (sym) {
                searchInput.value = sym;
                lookupStock(sym);
            }
        });
    });
}

async function lookupStock(symbol) {
    const searchBtn = document.getElementById('stockSearchBtn');
    const resultsContainer = document.getElementById('searchResultContainer');

    if (!resultsContainer) return;

    if (searchBtn) {
        searchBtn.disabled = true;
        searchBtn.innerHTML = 'Fetching...';
    }

    resultsContainer.innerHTML = `
        <div class="quote-card" style="justify-content: center; opacity: 0.8;">
            <span>⏳ Fetching market data for <strong>${escapeHtml(symbol)}</strong> via Yahoo Finance...</span>
        </div>
    `;

    try {
        const response = await fetch(`api/stock_price.php?symbol=${encodeURIComponent(symbol)}`);
        const data = await response.json();

        if (response.ok && data.success) {
            renderQuoteCard(data);
        } else {
            renderSearchError(data.error || `Unable to retrieve market data for "${symbol}".`);
        }
    } catch (err) {
        renderSearchError(`Network error or market service unavailable: ${err.message}`);
    } finally {
        if (searchBtn) {
            searchBtn.disabled = false;
            searchBtn.innerHTML = 'Search Stock';
        }
    }
}

function renderQuoteCard(quote) {
    const resultsContainer = document.getElementById('searchResultContainer');
    if (!resultsContainer) return;

    const currSym = quote.currency_symbol || (quote.currency === 'USD' ? '$' : '₹');
    const priceFormatted = `${currSym}${parseFloat(quote.price).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    
    const change = parseFloat(quote.change || 0);
    const changePct = parseFloat(quote.change_percent || 0);
    const isPositive = change >= 0;
    const changeClass = isPositive ? 'positive' : 'negative';
    const changeSign = isPositive ? '+' : '';
    const changeFormatted = `${changeSign}${change.toFixed(2)} (${changeSign}${changePct.toFixed(2)}%)`;

    const sourceBadge = quote.is_mock 
        ? '<span class="badge badge-manual" style="margin-left: 8px;">MOCK DATA</span>'
        : (quote.is_cached ? '<span class="badge badge-manual" style="margin-left: 8px;">CACHED</span>' : '<span class="badge badge-buy" style="margin-left: 8px;">YAHOO FINANCE</span>');

    resultsContainer.innerHTML = `
        <div class="quote-card">
            <div class="quote-left">
                <div class="quote-symbol">
                    ${escapeHtml(quote.symbol)}
                    ${sourceBadge}
                </div>
                <div class="quote-name">${escapeHtml(quote.name || quote.symbol)}</div>
                <div class="quote-time">Last updated: ${escapeHtml(quote.timestamp || new Date().toLocaleTimeString())}</div>
            </div>
            <div class="quote-right">
                <div class="quote-price-group">
                    <div class="quote-price">${priceFormatted}</div>
                    <div class="quote-change ${changeClass}">${changeFormatted}</div>
                </div>
                <div>
                    <a href="buy.php?symbol=${encodeURIComponent(quote.symbol)}" class="btn btn-primary">
                        Buy ${escapeHtml(quote.symbol)}
                    </a>
                </div>
            </div>
        </div>
    `;
}

function renderSearchError(message) {
    const resultsContainer = document.getElementById('searchResultContainer');
    if (!resultsContainer) return;

    resultsContainer.innerHTML = `
        <div class="alert alert-error" style="margin-top: 18px;">
            <div>
                <strong>Symbol Lookup Error:</strong> ${escapeHtml(message)}
            </div>
        </div>
    `;
}

/* --------------------------------------------------------------------------
   Trade Form Live Calculations (Buy & Sell)
   -------------------------------------------------------------------------- */
function initTradeCalculations() {
    const buyForm = document.getElementById('buyStockForm');
    const sellForm = document.getElementById('sellStockForm');

    if (buyForm) {
        const qtyInput = document.getElementById('buyQuantity');
        const priceInput = document.getElementById('buyPrice');
        const slPctInput = document.getElementById('buyStopLossPercent');
        const slPriceInput = document.getElementById('buyStopLossPrice');
        const totalCostEl = document.getElementById('calcTotalCost');
        const remainingCashEl = document.getElementById('calcRemainingCash');
        const availableCash = parseFloat(buyForm.getAttribute('data-available-cash') || 0);

        function updateBuyCalculations() {
            const qty = parseInt(qtyInput ? qtyInput.value : 0, 10) || 0;
            const price = parseFloat(priceInput ? priceInput.value : 0) || 0;
            const total = qty * price;
            const remaining = availableCash - total;

            if (totalCostEl) {
                totalCostEl.textContent = formatCurrency(total);
            }
            if (remainingCashEl) {
                remainingCashEl.textContent = formatCurrency(remaining);
                if (remaining < 0) {
                    remainingCashEl.classList.add('negative');
                } else {
                    remainingCashEl.classList.remove('negative');
                }
            }
        }

        if (qtyInput) qtyInput.addEventListener('input', updateBuyCalculations);
        if (priceInput) priceInput.addEventListener('input', updateBuyCalculations);

        // Link Stop-Loss Percentage and Price
        if (slPctInput && slPriceInput && priceInput) {
            slPctInput.addEventListener('input', () => {
                const pct = parseFloat(slPctInput.value);
                const price = parseFloat(priceInput.value);
                if (!isNaN(pct) && pct > 0 && pct < 100 && !isNaN(price) && price > 0) {
                    slPriceInput.value = (price * (1 - (pct / 100))).toFixed(2);
                } else if (slPctInput.value === '') {
                    slPriceInput.value = '';
                }
            });

            slPriceInput.addEventListener('input', () => {
                const slPrice = parseFloat(slPriceInput.value);
                const price = parseFloat(priceInput.value);
                if (!isNaN(slPrice) && slPrice > 0 && !isNaN(price) && price > 0 && slPrice < price) {
                    slPctInput.value = (((price - slPrice) / price) * 100).toFixed(2);
                } else if (slPriceInput.value === '') {
                    slPctInput.value = '';
                }
            });
        }

        updateBuyCalculations();
    }

    if (sellForm) {
        const qtyInput = document.getElementById('sellQuantity');
        const priceInput = document.getElementById('sellPrice');
        const avgPrice = parseFloat(sellForm.getAttribute('data-avg-price') || 0);
        const totalProceedsEl = document.getElementById('calcProceeds');
        const estimatedPnlEl = document.getElementById('calcEstimatedPnl');

        function updateSellCalculations() {
            const qty = parseInt(qtyInput ? qtyInput.value : 0, 10) || 0;
            const price = parseFloat(priceInput ? priceInput.value : 0) || 0;
            const proceeds = qty * price;
            const costBasis = qty * avgPrice;
            const pnl = proceeds - costBasis;

            if (totalProceedsEl) {
                totalProceedsEl.textContent = formatCurrency(proceeds);
            }
            if (estimatedPnlEl) {
                estimatedPnlEl.textContent = (pnl >= 0 ? '+' : '') + formatCurrency(pnl);
                if (pnl >= 0) {
                    estimatedPnlEl.className = 'positive';
                } else {
                    estimatedPnlEl.className = 'negative';
                }
            }
        }

        if (qtyInput) qtyInput.addEventListener('input', updateSellCalculations);
        if (priceInput) priceInput.addEventListener('input', updateSellCalculations);

        updateSellCalculations();
    }
}

/* --------------------------------------------------------------------------
   Monitor Heartbeat & Modal Handling
   -------------------------------------------------------------------------- */
function toggleMonitorModal() {
    const modal = document.getElementById('monitorModal');
    if (modal) {
        modal.style.display = modal.style.display === 'none' ? 'flex' : 'none';
    }
}

function initMonitorStatusPolling() {
    const poll = async () => {
        try {
            const res = await fetch('api/monitor_status.php');
            const data = await res.json();
            if (data.success && data.status) {
                updateMonitorUI(data.status);
            }
        } catch (e) {
            // Silently continue
        }
    };

    // Poll every 12 seconds
    setInterval(poll, 12000);
}

function updateMonitorUI(status) {
    const pill = document.getElementById('monitorStatusPill');
    const text = document.getElementById('monitorStatusText');
    const modalTitle = document.getElementById('modalStatusTitle');
    const modalDesc = document.getElementById('modalStatusDesc');

    if (!pill || !text) return;

    if (status.is_active) {
        pill.className = 'status-pill status-active';
        text.textContent = 'Monitor Active';
        if (modalTitle) modalTitle.textContent = '🟢 Background Monitor is ACTIVE';
    } else {
        pill.className = 'status-pill status-inactive';
        text.textContent = 'Monitor Inactive';
        if (modalTitle) modalTitle.textContent = '🔴 Background Monitor is INACTIVE';
    }

    if (modalDesc && status.message) {
        modalDesc.textContent = status.message;
    }
}

async function runManualStopLossCheck() {
    const btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Checking...';

    try {
        const res = await fetch('api/execute_stop_loss.php', { method: 'POST' });
        const data = await res.json();
        if (data.success) {
            const triggeredCount = data.result?.triggered_count || 0;
            alert(data.result?.message || 'Stop-loss check completed.');
            window.location.reload();
        } else {
            alert('Error running stop-loss check: ' + (data.error || 'Unknown error'));
        }
    } catch (e) {
        alert('Failed to connect to stop-loss execution endpoint: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Check Stop-Loss Now';
    }
}

/* --------------------------------------------------------------------------
   Simulation Reset
   -------------------------------------------------------------------------- */
function confirmResetSimulation() {
    if (confirm('Are you sure you want to reset your virtual trading balance? This will clear all virtual positions and trade history and reset your cash balance to the initial amount.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'index.php';
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'action';
        input.value = 'reset_simulation';
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    }
}

/* --------------------------------------------------------------------------
   Helpers
   -------------------------------------------------------------------------- */
function formatCurrency(amount) {
    return '₹' + Math.abs(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
