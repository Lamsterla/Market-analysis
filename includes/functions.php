<?php
/**
 * Core Trading Logic, Market Data Retrieval, and Portfolio Math
 * Stock Market Automated System (Paper Trading Simulator)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Format monetary amounts with symbol
 */
function format_money(float $amount, string $currency = 'INR'): string {
    $sym = ($currency === 'INR' || $currency === '₹') ? '₹' : '$';
    $isNegative = $amount < 0;
    $absAmount = abs($amount);

    return ($isNegative ? '-' : '') . $sym . number_format($absAmount, 2);
}

/**
 * Format percentage change
 */
function format_percent(float $percent): string {
    $prefix = $percent > 0 ? '+' : '';
    return $prefix . number_format($percent, 2) . '%';
}

/**
 * Get SQL locking clause ('FOR UPDATE' for PostgreSQL, empty for SQLite)
 */
function get_for_update_clause(): string {
    $driver = strtolower(get_db_config()['driver'] ?? 'pgsql');
    return ($driver === 'pgsql') ? ' FOR UPDATE' : '';
}

/**
 * Get Python Command or Executable Path
 */
function get_python_command(): string {
    $envPath = getenv('PYTHON_PATH');
    if ($envPath && file_exists($envPath)) {
        return '"' . $envPath . '"';
    }
    // Check known default paths on this Windows system
    $localPy312 = 'C:\\Users\\Arun Sah\\AppData\\Local\\Programs\\Python\\Python312\\python.exe';
    if (file_exists($localPy312)) {
        return '"' . $localPy312 . '"';
    }
    return 'python';
}

/**
 * Fetch Stock Quote using Python yfinance script
 *
 * @param string $symbol Stock symbol (e.g. AAPL, RELIANCE.NS)
 * @param bool $forceMock Force using mock data for deterministic testing
 * @return array Standardized quote array
 */
function get_stock_quote(string $symbol, bool $forceMock = false): array {
    $symbol = trim(strtoupper($symbol));
    if ($symbol === '') {
        return [
            'success' => false,
            'symbol'  => '',
            'error'   => 'Stock symbol cannot be empty.'
        ];
    }

    $isMockEnv = (strtolower((string)getenv('MOCK_DATA')) === 'true') || $forceMock;
    $pythonCmd = get_python_command();
    $scriptPath = dirname(__DIR__) . '/python/stock_data.py';

    if (!file_exists($scriptPath)) {
        return [
            'success' => false,
            'symbol'  => $symbol,
            'error'   => 'Stock data retrieval script missing.'
        ];
    }

    $escapedSymbol = escapeshellarg($symbol);
    $mockFlag = $isMockEnv ? ' --mock' : '';
    $cmd = "{$pythonCmd} " . escapeshellarg($scriptPath) . " {$escapedSymbol}{$mockFlag}";

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    $process = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        return [
            'success' => false,
            'symbol'  => $symbol,
            'error'   => 'Failed to launch Python price-fetching process.'
        ];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $data = json_decode((string)$stdout, true);
    if (is_array($data) && isset($data['success'])) {
        if ($data['success'] && isset($data['price']) && (float)$data['price'] > 0) {
            // Cache latest price in database if connection is alive
            try {
                cache_stock_price(
                    $symbol,
                    $data['name'] ?? $symbol,
                    (float)$data['price'],
                    $data['currency'] ?? 'INR'
                );
            } catch (Throwable $e) {
                // Silently ignore cache error so UI does not break
            }
        }
        return $data;
    }

    // If live call returned error or failed, try cached price if available
    try {
        $cached = get_cached_stock_price($symbol);
        if ($cached) {
            return [
                'success'         => true,
                'symbol'          => $symbol,
                'name'            => $cached['company_name'] ?: $symbol,
                'price'           => (float)$cached['price'],
                'currency'        => $cached['currency'] ?: 'INR',
                'currency_symbol' => ($cached['currency'] === 'USD') ? '$' : '₹',
                'change'          => 0.0,
                'change_percent'  => 0.0,
                'is_cached'       => true,
                'timestamp'       => $cached['last_updated'],
                'message'         => 'Retrieved from local cache (Live network unavailable)'
            ];
        }
    } catch (Throwable $e) {
        // Cache lookup failed
    }

    $errMsg = !empty($data['error']) ? $data['error'] : (!empty($stderr) ? trim($stderr) : "Unable to retrieve price for {$symbol}.");
    return [
        'success' => false,
        'symbol'  => $symbol,
        'error'   => $errMsg
    ];
}

/**
 * Fetch batch stock quotes in a single invocation
 */
function get_batch_stock_quotes(array $symbols, bool $forceMock = false): array {
    if (empty($symbols)) {
        return [];
    }

    $cleanSymbols = array_unique(array_filter(array_map('trim', array_map('strtoupper', $symbols))));
    if (empty($cleanSymbols)) {
        return [];
    }

    $isMockEnv = (strtolower((string)getenv('MOCK_DATA')) === 'true') || $forceMock;
    $pythonCmd = get_python_command();
    $scriptPath = dirname(__DIR__) . '/python/stock_data.py';
    $symbolsArg = escapeshellarg(implode(',', $cleanSymbols));
    $mockFlag = $isMockEnv ? ' --mock' : '';

    $cmd = "{$pythonCmd} " . escapeshellarg($scriptPath) . " --batch {$symbolsArg}{$mockFlag}";

    $stdout = shell_exec($cmd);
    $data = json_decode((string)$stdout, true);

    if (is_array($data) && !empty($data['batch'])) {
        return $data['batch'];
    }

    // Fallback: individually query each
    $results = [];
    foreach ($cleanSymbols as $sym) {
        $results[$sym] = get_stock_quote($sym, $forceMock);
    }
    return $results;
}

/**
 * Cache stock price in price_cache table
 */
function cache_stock_price(string $symbol, string $name, float $price, string $currency): void {
    $pdo = get_db_connection();
    $sql = "INSERT INTO price_cache (symbol, company_name, price, currency, last_updated)
            VALUES (:symbol, :name, :price, :currency, CURRENT_TIMESTAMP)
            ON CONFLICT (symbol) DO UPDATE
            SET price = EXCLUDED.price,
                company_name = EXCLUDED.company_name,
                currency = EXCLUDED.currency,
                last_updated = CURRENT_TIMESTAMP";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'symbol'   => $symbol,
        'name'     => $name,
        'price'    => $price,
        'currency' => $currency
    ]);
}

/**
 * Retrieve cached stock price
 */
function get_cached_stock_price(string $symbol): ?array {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT * FROM price_cache WHERE symbol = :symbol");
    $stmt->execute(['symbol' => $symbol]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Get Virtual Account Balance Details
 */
function get_account_balance(): array {
    $pdo = get_db_connection();
    $stmt = $pdo->query("SELECT * FROM accounts WHERE id = 1");
    $acc = $stmt->fetch();

    if (!$acc) {
        $initBalance = (float)(getenv('INITIAL_BALANCE') ?: 100000.00);
        $curr = getenv('CURRENCY_CODE') ?: 'INR';
        $ins = $pdo->prepare("INSERT INTO accounts (id, balance, initial_balance, currency) VALUES (1, :b, :ib, :c)");
        $ins->execute(['b' => $initBalance, 'ib' => $initBalance, 'c' => $curr]);
        return [
            'id'              => 1,
            'balance'         => $initBalance,
            'initial_balance' => $initBalance,
            'currency'        => $curr
        ];
    }

    return [
        'id'              => (int)$acc['id'],
        'balance'         => (float)$acc['balance'],
        'initial_balance' => (float)$acc['initial_balance'],
        'currency'        => (string)$acc['currency']
    ];
}

/**
 * Get Open Stock Positions with live prices and unrealised P&L
 */
function get_open_positions(): array {
    $pdo = get_db_connection();
    $stmt = $pdo->query("SELECT * FROM positions ORDER BY symbol ASC");
    $positions = $stmt->fetchAll();

    if (empty($positions)) {
        return [];
    }

    // Collect symbols for batch price retrieval
    $symbols = array_column($positions, 'symbol');
    $quotes = get_batch_stock_quotes($symbols);

    $results = [];
    foreach ($positions as $pos) {
        $sym = $pos['symbol'];
        $qty = (int)$pos['quantity'];
        $avgBuy = (float)$pos['average_buy_price'];
        $investedCost = round($qty * $avgBuy, 2);

        $currentPrice = $avgBuy; // fallback
        $quoteSuccess = false;
        $companyName = $pos['company_name'] ?: $sym;

        if (isset($quotes[$sym]) && !empty($quotes[$sym]['success']) && (float)$quotes[$sym]['price'] > 0) {
            $currentPrice = (float)$quotes[$sym]['price'];
            $quoteSuccess = true;
            if (!empty($quotes[$sym]['name'])) {
                $companyName = $quotes[$sym]['name'];
            }
        }

        $marketValue = round($qty * $currentPrice, 2);
        $unrealisedPnl = round($marketValue - $investedCost, 2);
        $unrealisedPnlPercent = $investedCost > 0 ? round(($unrealisedPnl / $investedCost) * 100, 2) : 0.0;

        $results[] = [
            'id'                     => (int)$pos['id'],
            'symbol'                 => $sym,
            'company_name'           => $companyName,
            'quantity'               => $qty,
            'average_buy_price'      => $avgBuy,
            'current_price'          => $currentPrice,
            'invested_cost'          => $investedCost,
            'market_value'           => $marketValue,
            'unrealised_pnl'         => $unrealisedPnl,
            'unrealised_pnl_percent' => $unrealisedPnlPercent,
            'stop_loss_price'        => $pos['stop_loss_price'] !== null ? (float)$pos['stop_loss_price'] : null,
            'stop_loss_percent'      => $pos['stop_loss_percent'] !== null ? (float)$pos['stop_loss_percent'] : null,
            'currency'               => $pos['currency'] ?: 'INR',
            'quote_success'          => $quoteSuccess,
            'updated_at'             => $pos['updated_at']
        ];
    }

    return $results;
}

/**
 * Get Comprehensive Portfolio Summary Metrics
 */
function get_portfolio_summary(): array {
    $account = get_account_balance();
    $positions = get_open_positions();

    $availableCash = $account['balance'];
    $initialBalance = $account['initial_balance'];
    $currency = $account['currency'];

    $portfolioValue = 0.0;
    $totalInvested = 0.0;
    $totalUnrealisedPnl = 0.0;

    foreach ($positions as $pos) {
        $portfolioValue += $pos['market_value'];
        $totalInvested += $pos['invested_cost'];
        $totalUnrealisedPnl += $pos['unrealised_pnl'];
    }

    // Get total realised P&L from trades
    $pdo = get_db_connection();
    $stmt = $pdo->query("SELECT COALESCE(SUM(realised_pnl), 0) AS total_realised FROM trades WHERE trade_type = 'SELL'");
    $realisedRow = $stmt->fetch();
    $totalRealisedPnl = (float)($realisedRow['total_realised'] ?? 0.0);

    $totalAccountValue = round($availableCash + $portfolioValue, 2);
    $overallPnl = round($totalRealisedPnl + $totalUnrealisedPnl, 2);
    $overallPnlPercent = $initialBalance > 0 ? round(($overallPnl / $initialBalance) * 100, 2) : 0.0;

    return [
        'initial_balance'      => $initialBalance,
        'available_cash'       => $availableCash,
        'portfolio_value'      => round($portfolioValue, 2),
        'total_invested'       => round($totalInvested, 2),
        'total_unrealised_pnl' => round($totalUnrealisedPnl, 2),
        'total_realised_pnl'   => round($totalRealisedPnl, 2),
        'overall_pnl'          => $overallPnl,
        'overall_pnl_percent'  => $overallPnlPercent,
        'total_account_value'  => $totalAccountValue,
        'currency'             => $currency,
        'open_positions_count' => count($positions)
    ];
}

/**
 * Execute Virtual Stock Purchase
 * Atomic transaction: balance deduction + position update/insert + trade record
 */
function execute_buy_stock(
    string $symbol,
    int $quantity,
    ?float $customStopLossPrice = null,
    ?float $customStopLossPercent = null,
    bool $forceMock = false
): array {
    $symbol = trim(strtoupper($symbol));
    if ($symbol === '') {
        return ['success' => false, 'error' => 'Please enter a valid stock symbol.'];
    }

    if ($quantity <= 0) {
        return ['success' => false, 'error' => 'Number of shares must be greater than zero.'];
    }

    // 1. Fetch current price
    $quote = get_stock_quote($symbol, $forceMock);
    if (!$quote['success'] || empty($quote['price']) || (float)$quote['price'] <= 0) {
        return [
            'success' => false,
            'error'   => !empty($quote['error']) ? $quote['error'] : 'Market price for ' . $symbol . ' is currently unavailable.'
        ];
    }

    $executionPrice = round((float)$quote['price'], 2);
    $companyName = !empty($quote['name']) ? $quote['name'] : $symbol;
    $currency = !empty($quote['currency']) ? $quote['currency'] : 'INR';
    $totalCost = round($quantity * $executionPrice, 2);

    // Calculate Stop-Loss Price if configured
    $stopLossPrice = null;
    $stopLossPercent = null;

    if ($customStopLossPrice !== null && $customStopLossPrice > 0) {
        $stopLossPrice = round($customStopLossPrice, 2);
        if ($stopLossPrice >= $executionPrice) {
            return [
                'success' => false,
                'error'   => 'Stop-loss price must be lower than the current purchase price (' . format_money($executionPrice, $currency) . ').'
            ];
        }
        $stopLossPercent = round((($executionPrice - $stopLossPrice) / $executionPrice) * 100, 2);
    } elseif ($customStopLossPercent !== null && $customStopLossPercent > 0) {
        if ($customStopLossPercent >= 100) {
            return ['success' => false, 'error' => 'Stop-loss percentage must be less than 100%.'];
        }
        $stopLossPercent = round($customStopLossPercent, 2);
        $stopLossPrice = round($executionPrice * (1 - ($stopLossPercent / 100)), 2);
    }

    // 2. Perform Atomic Database Transaction
    $pdo = get_db_connection();
    $pdo->beginTransaction();

    try {
        $lockClause = get_for_update_clause();

        // Lock account record for update
        $stmt = $pdo->prepare("SELECT balance FROM accounts WHERE id = 1{$lockClause}");
        $stmt->execute();
        $account = $stmt->fetch();

        if (!$account) {
            throw new RuntimeException("Virtual account not found.");
        }

        $currentBalance = (float)$account['balance'];
        if ($currentBalance < $totalCost) {
            $pdo->rollBack();
            return [
                'success' => false,
                'error'   => sprintf(
                    'Purchase rejected: Insufficient virtual cash. Required: %s, Available: %s',
                    format_money($totalCost, $currency),
                    format_money($currentBalance, $currency)
                )
            ];
        }

        // Deduct cost
        $newBalance = round($currentBalance - $totalCost, 2);
        $updAcc = $pdo->prepare("UPDATE accounts SET balance = :balance, updated_at = CURRENT_TIMESTAMP WHERE id = 1");
        $updAcc->execute(['balance' => $newBalance]);

        // Check existing position
        $posStmt = $pdo->prepare("SELECT * FROM positions WHERE symbol = :symbol{$lockClause}");
        $posStmt->execute(['symbol' => $symbol]);
        $existingPos = $posStmt->fetch();

        if ($existingPos) {
            $oldQty = (int)$existingPos['quantity'];
            $oldAvg = (float)$existingPos['average_buy_price'];
            $newQty = $oldQty + $quantity;
            $newAvgPrice = round((($oldQty * $oldAvg) + ($quantity * $executionPrice)) / $newQty, 2);

            // Keep stop loss if already set, or update if specified in new order
            $finalSlPrice = $stopLossPrice !== null ? $stopLossPrice : $existingPos['stop_loss_price'];
            $finalSlPct = $stopLossPercent !== null ? $stopLossPercent : $existingPos['stop_loss_percent'];

            $updPos = $pdo->prepare("UPDATE positions
                SET quantity = :qty,
                    average_buy_price = :avg_price,
                    stop_loss_price = :sl_price,
                    stop_loss_percent = :sl_pct,
                    company_name = :cname,
                    updated_at = CURRENT_TIMESTAMP
                WHERE symbol = :symbol");
            $updPos->execute([
                'qty'       => $newQty,
                'avg_price' => $newAvgPrice,
                'sl_price'  => $finalSlPrice,
                'sl_pct'    => $finalSlPct,
                'cname'     => $companyName,
                'symbol'    => $symbol
            ]);
        } else {
            $insPos = $pdo->prepare("INSERT INTO positions
                (symbol, company_name, quantity, average_buy_price, stop_loss_price, stop_loss_percent, currency, created_at, updated_at)
                VALUES (:symbol, :cname, :qty, :avg_price, :sl_price, :sl_pct, :currency, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $insPos->execute([
                'symbol'    => $symbol,
                'cname'     => $companyName,
                'qty'       => $quantity,
                'avg_price' => $executionPrice,
                'sl_price'  => $stopLossPrice,
                'sl_pct'    => $stopLossPercent,
                'currency'  => $currency
            ]);
        }

        // Record in trades table
        $insTrade = $pdo->prepare("INSERT INTO trades
            (symbol, company_name, trade_type, quantity, price, total_amount, realised_pnl, reason, notes, currency, created_at)
            VALUES (:symbol, :cname, 'BUY', :qty, :price, :total, 0.00, 'MANUAL_BUY', :notes, :currency, CURRENT_TIMESTAMP)");
        $insTrade->execute([
            'symbol'   => $symbol,
            'cname'    => $companyName,
            'qty'      => $quantity,
            'price'    => $executionPrice,
            'total'    => $totalCost,
            'notes'    => 'Simulated paper trade buy order',
            'currency' => $currency
        ]);

        $pdo->commit();

        return [
            'success'         => true,
            'symbol'          => $symbol,
            'company_name'    => $companyName,
            'quantity'        => $quantity,
            'price'           => $executionPrice,
            'total_cost'      => $totalCost,
            'remaining_cash'  => $newBalance,
            'stop_loss_price' => $stopLossPrice,
            'currency'        => $currency,
            'message'         => "Successfully purchased {$quantity} shares of {$symbol} at " . format_money($executionPrice, $currency)
        ];

    } catch (Throwable $e) {
        $pdo->rollBack();
        return [
            'success' => false,
            'error'   => 'Transaction failed: ' . $e->getMessage()
        ];
    }
}

/**
 * Execute Virtual Stock Sale
 * Atomic transaction: balance addition + position deduction/removal + trade record with realised P&L
 */
function execute_sell_stock(
    string $symbol,
    int $quantity,
    string $reason = 'MANUAL_SELL',
    ?float $overridePrice = null,
    bool $forceMock = false
): array {
    $symbol = trim(strtoupper($symbol));
    if ($symbol === '') {
        return ['success' => false, 'error' => 'Please enter a valid stock symbol.'];
    }

    if ($quantity <= 0) {
        return ['success' => false, 'error' => 'Number of shares to sell must be greater than zero.'];
    }

    // 1. Fetch current price (unless override provided e.g. from automated monitor tick)
    $executionPrice = null;
    $currency = 'INR';
    $companyName = $symbol;

    if ($overridePrice !== null && $overridePrice > 0) {
        $executionPrice = round($overridePrice, 2);
    } else {
        $quote = get_stock_quote($symbol, $forceMock);
        if (!$quote['success'] || empty($quote['price']) || (float)$quote['price'] <= 0) {
            return [
                'success' => false,
                'error'   => !empty($quote['error']) ? $quote['error'] : 'Simulated sale rejected: Latest market price is unavailable or stale.'
            ];
        }
        $executionPrice = round((float)$quote['price'], 2);
        $currency = $quote['currency'] ?? 'INR';
        $companyName = $quote['name'] ?? $symbol;
    }

    // 2. Perform Atomic Database Transaction
    $pdo = get_db_connection();
    $pdo->beginTransaction();

    try {
        $lockClause = get_for_update_clause();

        // Lock position record for update
        $stmt = $pdo->prepare("SELECT * FROM positions WHERE symbol = :symbol{$lockClause}");
        $stmt->execute(['symbol' => $symbol]);
        $position = $stmt->fetch();

        if (!$position) {
            $pdo->rollBack();
            return [
                'success' => false,
                'error'   => "Sale rejected: You do not own any shares of {$symbol}."
            ];
        }

        $heldQty = (int)$position['quantity'];
        if ($quantity > $heldQty) {
            $pdo->rollBack();
            return [
                'success' => false,
                'error'   => "Sale rejected: Cannot sell {$quantity} shares. You only own {$heldQty} shares."
            ];
        }

        $avgBuyPrice = (float)$position['average_buy_price'];
        $currency = $position['currency'] ?: $currency;
        $companyName = $position['company_name'] ?: $companyName;

        // Calculate Proceeds and Realised P&L
        $saleProceeds = round($quantity * $executionPrice, 2);
        $costBasis = round($quantity * $avgBuyPrice, 2);
        $realisedPnl = round($saleProceeds - $costBasis, 2);

        // Update virtual cash balance
        $accStmt = $pdo->prepare("SELECT balance FROM accounts WHERE id = 1{$lockClause}");
        $accStmt->execute();
        $account = $accStmt->fetch();
        $newBalance = round((float)$account['balance'] + $saleProceeds, 2);

        $updAcc = $pdo->prepare("UPDATE accounts SET balance = :balance, updated_at = CURRENT_TIMESTAMP WHERE id = 1");
        $updAcc->execute(['balance' => $newBalance]);

        // Update or delete position
        if ($quantity === $heldQty) {
            $delPos = $pdo->prepare("DELETE FROM positions WHERE id = :id");
            $delPos->execute(['id' => $position['id']]);
        } else {
            $remainingQty = $heldQty - $quantity;
            $updPos = $pdo->prepare("UPDATE positions SET quantity = :qty, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $updPos->execute(['qty' => $remainingQty, 'id' => $position['id']]);
        }

        // Record in trades table
        $insTrade = $pdo->prepare("INSERT INTO trades
            (symbol, company_name, trade_type, quantity, price, total_amount, realised_pnl, reason, notes, currency, created_at)
            VALUES (:symbol, :cname, 'SELL', :qty, :price, :total, :pnl, :reason, :notes, :currency, CURRENT_TIMESTAMP)");
        $insTrade->execute([
            'symbol'   => $symbol,
            'cname'    => $companyName,
            'qty'      => $quantity,
            'price'    => $executionPrice,
            'total'    => $saleProceeds,
            'pnl'      => $realisedPnl,
            'reason'   => $reason,
            'notes'    => $reason === 'STOP_LOSS_TRIGGERED'
                ? "Simulated stop-loss automatically executed at {$executionPrice}"
                : "Simulated paper trade manual sell order",
            'currency' => $currency
        ]);

        $pdo->commit();

        return [
            'success'       => true,
            'symbol'        => $symbol,
            'quantity'      => $quantity,
            'price'         => $executionPrice,
            'proceeds'      => $saleProceeds,
            'realised_pnl'  => $realisedPnl,
            'new_balance'   => $newBalance,
            'reason'        => $reason,
            'currency'      => $currency,
            'message'       => sprintf(
                'Successfully sold %d shares of %s at %s. Realised P&L: %s',
                $quantity,
                $symbol,
                format_money($executionPrice, $currency),
                format_money($realisedPnl, $currency)
            )
        ];

    } catch (Throwable $e) {
        $pdo->rollBack();
        return [
            'success' => false,
            'error'   => 'Sale transaction failed: ' . $e->getMessage()
        ];
    }
}

/**
 * Update Stop-Loss Configuration for an Open Position
 */
function update_position_stop_loss(
    int $positionId,
    ?float $stopLossPrice = null,
    ?float $stopLossPercent = null
): array {
    $pdo = get_db_connection();

    $stmt = $pdo->prepare("SELECT * FROM positions WHERE id = :id");
    $stmt->execute(['id' => $positionId]);
    $pos = $stmt->fetch();

    if (!$pos) {
        return ['success' => false, 'error' => 'Position not found.'];
    }

    $avgPrice = (float)$pos['average_buy_price'];

    if ($stopLossPercent !== null && $stopLossPercent > 0) {
        if ($stopLossPercent >= 100) {
            return ['success' => false, 'error' => 'Stop-loss percentage must be less than 100%.'];
        }
        $stopLossPrice = round($avgPrice * (1 - ($stopLossPercent / 100)), 2);
    } elseif ($stopLossPrice !== null && $stopLossPrice > 0) {
        $stopLossPercent = round((($avgPrice - $stopLossPrice) / $avgPrice) * 100, 2);
    } else {
        // Clear stop loss
        $stopLossPrice = null;
        $stopLossPercent = null;
    }

    $upd = $pdo->prepare("UPDATE positions
        SET stop_loss_price = :sl_price,
            stop_loss_percent = :sl_pct,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id");
    $upd->execute([
        'sl_price' => $stopLossPrice,
        'sl_pct'   => $stopLossPercent,
        'id'       => $positionId
    ]);

    return [
        'success'           => true,
        'stop_loss_price'   => $stopLossPrice,
        'stop_loss_percent' => $stopLossPercent,
        'message'           => $stopLossPrice !== null
            ? "Stop-loss updated to " . format_money($stopLossPrice, $pos['currency']) . " ({$stopLossPercent}% below average purchase price)"
            : "Stop-loss removed for {$pos['symbol']}."
    ];
}

/**
 * Evaluate Stop-Loss conditions across all active positions
 * Prevents duplicate execution by checking active row existence under transaction lock
 */
function evaluate_stop_losses(array $customQuotes = [], bool $forceMock = false): array {
    $pdo = get_db_connection();

    // Find all positions with an active stop-loss set
    $stmt = $pdo->query("SELECT * FROM positions WHERE stop_loss_price IS NOT NULL AND stop_loss_price > 0");
    $positions = $stmt->fetchAll();

    if (empty($positions)) {
        return [
            'checked_count'   => 0,
            'triggered_count' => 0,
            'triggered'       => [],
            'message'         => 'No positions with configured stop-losses.'
        ];
    }

    // Collect symbols
    $symbols = array_column($positions, 'symbol');
    $quotes = !empty($customQuotes) ? $customQuotes : get_batch_stock_quotes($symbols, $forceMock);

    $triggered = [];

    foreach ($positions as $pos) {
        $sym = $pos['symbol'];
        $slPrice = (float)$pos['stop_loss_price'];
        $heldQty = (int)$pos['quantity'];

        if (!isset($quotes[$sym]) || empty($quotes[$sym]['success']) || (float)$quotes[$sym]['price'] <= 0) {
            // "Do not execute a simulated sale using an invalid, missing, or stale price."
            continue;
        }

        $currentPrice = (float)$quotes[$sym]['price'];

        // Trigger condition: Market price reached or fallen below stop-loss price
        if ($currentPrice <= $slPrice) {
            // Execute automated sale
            $sellResult = execute_sell_stock(
                $sym,
                $heldQty,
                'STOP_LOSS_TRIGGERED',
                $currentPrice,
                $forceMock
            );

            if ($sellResult['success']) {
                $triggered[] = [
                    'symbol'          => $sym,
                    'quantity'        => $heldQty,
                    'stop_loss_price' => $slPrice,
                    'executed_price'  => $currentPrice,
                    'realised_pnl'    => $sellResult['realised_pnl'],
                    'currency'        => $pos['currency'],
                    'message'         => "Stop-loss triggered! {$heldQty} shares sold at {$currentPrice} (Stop-loss threshold: {$slPrice})"
                ];
            }
        }
    }

    return [
        'checked_count'   => count($positions),
        'triggered_count' => count($triggered),
        'triggered'       => $triggered,
        'message'         => count($triggered) > 0
            ? count($triggered) . ' stop-loss position(s) triggered and executed.'
            : 'All positions evaluated; no stop-loss thresholds crossed.'
    ];
}

/**
 * Get Recent Trades History
 */
function get_recent_trades(int $limit = 50): array {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT * FROM trades ORDER BY created_at DESC LIMIT :limit");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Update monitor heartbeat state in database and local file
 */
function update_monitor_heartbeat(bool $isRunning, string $message = '', ?array $checkedSymbols = null): void {
    $now = date('Y-m-d H:i:s');
    $data = [
        'is_running'     => $isRunning,
        'last_heartbeat' => $now,
        'symbols'        => $checkedSymbols ?: [],
        'message'        => $message
    ];

    // Write local json heartbeat for zero-dependency file-based checking
    $heartbeatFile = dirname(__DIR__) . '/monitor_heartbeat.json';
    @file_put_contents($heartbeatFile, json_encode($data, JSON_PRETTY_PRINT));

    // Also persist in database
    try {
        $pdo = get_db_connection();
        $symbolsStr = $checkedSymbols ? implode(',', $checkedSymbols) : null;
        $stmt = $pdo->prepare("UPDATE monitor_status
            SET is_running = :running,
                last_heartbeat = CURRENT_TIMESTAMP,
                last_check_symbols = :symbols,
                message = :msg,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = 1");
        $stmt->execute([
            'running' => $isRunning ? 1 : 0,
            'symbols' => $symbolsStr,
            'msg'     => $message
        ]);
    } catch (Throwable $e) {
        // Silently tolerate if DB is temporarily unreachable
    }
}

/**
 * Check if the background stop-loss monitor is actively running
 * Heartbeat considered active if updated within the last 60 seconds
 */
function get_monitor_status(): array {
    $heartbeatFile = dirname(__DIR__) . '/monitor_heartbeat.json';
    $data = null;

    if (file_exists($heartbeatFile)) {
        $raw = @file_get_contents($heartbeatFile);
        $data = json_decode((string)$raw, true);
    }

    if (!$data) {
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->query("SELECT * FROM monitor_status WHERE id = 1");
            $row = $stmt->fetch();
            if ($row) {
                $data = [
                    'is_running'     => (bool)$row['is_running'],
                    'last_heartbeat' => $row['last_heartbeat'],
                    'symbols'        => $row['last_check_symbols'] ? explode(',', $row['last_check_symbols']) : [],
                    'message'        => $row['message']
                ];
            }
        } catch (Throwable $e) {
            // DB unreachable
        }
    }

    if (!$data || empty($data['last_heartbeat'])) {
        return [
            'is_active'      => false,
            'last_heartbeat' => null,
            'seconds_ago'    => null,
            'message'        => 'Stop-loss monitor has never run or is stopped.'
        ];
    }

    $lastTime = strtotime($data['last_heartbeat']);
    $diff = time() - $lastTime;
    $isActive = ($diff <= 65) && !empty($data['is_running']);

    return [
        'is_active'      => $isActive,
        'last_heartbeat' => $data['last_heartbeat'],
        'seconds_ago'    => max(0, $diff),
        'message'        => $isActive
            ? "Stop-loss monitor is ACTIVE (Last heartbeat: {$diff}s ago)"
            : "Stop-loss monitor is INACTIVE (Last heartbeat: {$diff}s ago)"
    ];
}

/**
 * Reset Simulation to Clean Initial State
 */
function reset_simulation(): bool {
    $pdo = get_db_connection();
    $pdo->beginTransaction();

    try {
        $initialBalance = (float)(getenv('INITIAL_BALANCE') ?: 100000.00);
        $currency = getenv('CURRENCY_CODE') ?: 'INR';

        $pdo->exec("DELETE FROM trades");
        $pdo->exec("DELETE FROM positions");
        $pdo->exec("DELETE FROM price_cache");

        $upd = $pdo->prepare("UPDATE accounts SET balance = :b, initial_balance = :ib, currency = :c, updated_at = CURRENT_TIMESTAMP WHERE id = 1");
        $upd->execute(['b' => $initialBalance, 'ib' => $initialBalance, 'c' => $currency]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        return false;
    }
}
