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

/**
 * ==========================================================================
 * STOCK ANALYSIS & ADVANCED PERFORMANCE HELPER FUNCTIONS
 * ==========================================================================
 */

/**
 * Ensure portfolio_snapshots table exists (idempotent, supports PostgreSQL & SQLite)
 */
function ensure_portfolio_snapshots_table(?PDO $pdo = null): void {
    if ($pdo === null) {
        $pdo = get_db_connection();
    }

    $driver = strtolower(get_db_config()['driver'] ?? 'pgsql');
    $isFallback = !empty($GLOBALS['DB_FALLBACK_ACTIVE']);
    $activeDriver = $isFallback ? 'sqlite' : $driver;

    try {
        if ($activeDriver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS portfolio_snapshots (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    snapshot_date DATE NOT NULL UNIQUE,
                    available_cash NUMERIC(15, 2) NOT NULL,
                    portfolio_value NUMERIC(15, 2) NOT NULL,
                    total_equity NUMERIC(15, 2) NOT NULL,
                    total_invested NUMERIC(15, 2) NOT NULL,
                    unrealised_pnl NUMERIC(15, 2) NOT NULL,
                    realised_pnl NUMERIC(15, 2) NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS idx_snapshots_date ON portfolio_snapshots (snapshot_date ASC);
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS portfolio_snapshots (
                    id SERIAL PRIMARY KEY,
                    snapshot_date DATE NOT NULL UNIQUE,
                    available_cash NUMERIC(15, 2) NOT NULL,
                    portfolio_value NUMERIC(15, 2) NOT NULL,
                    total_equity NUMERIC(15, 2) NOT NULL,
                    total_invested NUMERIC(15, 2) NOT NULL,
                    unrealised_pnl NUMERIC(15, 2) NOT NULL,
                    realised_pnl NUMERIC(15, 2) NOT NULL,
                    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS idx_snapshots_date ON portfolio_snapshots (snapshot_date ASC);
            ");
        }
    } catch (Throwable $e) {
        error_log('[Portfolio Snapshot Table Error] ' . $e->getMessage());
    }
}

/**
 * Record or update today's portfolio snapshot
 */
function record_daily_portfolio_snapshot(): void {
    try {
        $pdo = get_db_connection();
        ensure_portfolio_snapshots_table($pdo);

        $summary = get_portfolio_summary();
        $today = date('Y-m-d');

        $driver = strtolower(get_db_config()['driver'] ?? 'pgsql');
        $isFallback = !empty($GLOBALS['DB_FALLBACK_ACTIVE']);
        $activeDriver = $isFallback ? 'sqlite' : $driver;

        if ($activeDriver === 'sqlite') {
            $sql = "INSERT INTO portfolio_snapshots
                    (snapshot_date, available_cash, portfolio_value, total_equity, total_invested, unrealised_pnl, realised_pnl, created_at)
                    VALUES (:sdate, :cash, :pval, :equity, :invested, :unrealised, :realised, CURRENT_TIMESTAMP)
                    ON CONFLICT(snapshot_date) DO UPDATE SET
                        available_cash = EXCLUDED.available_cash,
                        portfolio_value = EXCLUDED.portfolio_value,
                        total_equity = EXCLUDED.total_equity,
                        total_invested = EXCLUDED.total_invested,
                        unrealised_pnl = EXCLUDED.unrealised_pnl,
                        realised_pnl = EXCLUDED.realised_pnl";
        } else {
            $sql = "INSERT INTO portfolio_snapshots
                    (snapshot_date, available_cash, portfolio_value, total_equity, total_invested, unrealised_pnl, realised_pnl, created_at)
                    VALUES (:sdate, :cash, :pval, :equity, :invested, :unrealised, :realised, CURRENT_TIMESTAMP)
                    ON CONFLICT(snapshot_date) DO UPDATE SET
                        available_cash = EXCLUDED.available_cash,
                        portfolio_value = EXCLUDED.portfolio_value,
                        total_equity = EXCLUDED.total_equity,
                        total_invested = EXCLUDED.total_invested,
                        unrealised_pnl = EXCLUDED.unrealised_pnl,
                        realised_pnl = EXCLUDED.realised_pnl";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'sdate'      => $today,
            'cash'       => $summary['available_cash'],
            'pval'       => $summary['portfolio_value'],
            'equity'     => $summary['total_account_value'],
            'invested'   => $summary['total_invested'],
            'unrealised' => $summary['total_unrealised_pnl'],
            'realised'   => $summary['total_realised_pnl']
        ]);
    } catch (Throwable $e) {
        error_log('[Record Snapshot Error] ' . $e->getMessage());
    }
}

/**
 * Get historical portfolio snapshots for equity-over-time chart
 */
function get_portfolio_snapshots(int $limit = 30): array {
    try {
        $pdo = get_db_connection();
        ensure_portfolio_snapshots_table($pdo);

        $stmt = $pdo->prepare("SELECT * FROM portfolio_snapshots ORDER BY snapshot_date ASC LIMIT :lim");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        if (!empty($rows)) {
            return $rows;
        }

        // If no recorded snapshots exist yet, record today's snapshot and return it
        record_daily_portfolio_snapshot();
        $stmt = $pdo->prepare("SELECT * FROM portfolio_snapshots ORDER BY snapshot_date ASC");
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Fetch historical stock price data, moving averages, RSI, and rule-based outlook
 */
function get_stock_history_and_indicators(string $symbol, string $period = '30d', bool $forceMock = false): array {
    $symbol = trim(strtoupper($symbol));
    if ($symbol === '') {
        return ['success' => false, 'error' => 'Stock symbol cannot be empty.'];
    }

    $validPeriods = ['7d', '30d', '90d', '1y'];
    $period = in_array(strtolower($period), $validPeriods, true) ? strtolower($period) : '30d';

    $isMockEnv = (strtolower((string)getenv('MOCK_DATA')) === 'true') || $forceMock;
    $pythonCmd = get_python_command();
    $scriptPath = dirname(__DIR__) . '/python/stock_data.py';

    if (!file_exists($scriptPath)) {
        return ['success' => false, 'error' => 'Stock data retrieval script missing.'];
    }

    $escapedSymbol = escapeshellarg($symbol);
    $mockFlag = $isMockEnv ? ' --mock' : '';
    $cmd = "{$pythonCmd} " . escapeshellarg($scriptPath) . " {$escapedSymbol} --history --period {$period}{$mockFlag}";

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    $process = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        return ['success' => false, 'error' => 'Unable to launch Python process.'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($process);

    $data = json_decode((string)$stdout, true);
    if (is_array($data) && !empty($data['success'])) {
        return $data;
    }

    $errMsg = !empty($data['error']) ? $data['error'] : (!empty($stderr) ? trim($stderr) : "Unable to retrieve history for {$symbol}.");
    return [
        'success' => false,
        'symbol'  => $symbol,
        'error'   => $errMsg
    ];
}

/**
 * Get Comprehensive Stock Analysis Data for all held and previously traded stocks
 */
function get_all_traded_and_held_stocks_analysis(array $dateFilter = []): array {
    $pdo = get_db_connection();

    // 1. Fetch currently open positions
    $positionsStmt = $pdo->query("SELECT * FROM positions ORDER BY symbol ASC");
    $positionsList = $positionsStmt->fetchAll();
    $openPositionsBySymbol = [];
    foreach ($positionsList as $p) {
        $openPositionsBySymbol[$p['symbol']] = $p;
    }

    // 2. Fetch trade history statistics per symbol with optional date filter
    $tradeSql = "SELECT 
                    symbol,
                    MAX(company_name) as company_name,
                    MAX(currency) as currency,
                    COUNT(*) as total_trades,
                    SUM(CASE WHEN trade_type = 'BUY' THEN quantity ELSE 0 END) as total_shares_bought,
                    SUM(CASE WHEN trade_type = 'BUY' THEN total_amount ELSE 0 END) as total_amount_bought,
                    SUM(CASE WHEN trade_type = 'SELL' THEN quantity ELSE 0 END) as total_shares_sold,
                    SUM(CASE WHEN trade_type = 'SELL' THEN total_amount ELSE 0 END) as total_amount_sold,
                    SUM(CASE WHEN trade_type = 'SELL' THEN realised_pnl ELSE 0 END) as total_realised_pnl,
                    SUM(CASE WHEN trade_type = 'SELL' AND realised_pnl > 0 THEN 1 ELSE 0 END) as win_trades,
                    SUM(CASE WHEN trade_type = 'SELL' AND realised_pnl < 0 THEN 1 ELSE 0 END) as loss_trades,
                    MAX(created_at) as last_trade_time
                FROM trades WHERE 1=1";

    $params = [];
    if (!empty($dateFilter['start'])) {
        $tradeSql .= " AND created_at >= :start_date";
        $params['start_date'] = $dateFilter['start'];
    }
    if (!empty($dateFilter['end'])) {
        $tradeSql .= " AND created_at <= :end_date";
        $params['end_date'] = $dateFilter['end'];
    }

    $tradeSql .= " GROUP BY symbol ORDER BY symbol ASC";
    $tradeStmt = $pdo->prepare($tradeSql);
    $tradeStmt->execute($params);
    $tradeStatsList = $tradeStmt->fetchAll();
    $tradeStatsBySymbol = [];
    foreach ($tradeStatsList as $t) {
        $tradeStatsBySymbol[$t['symbol']] = $t;
    }

    // 3. Union of all distinct symbols (Open Positions + Traded Stocks)
    $allSymbols = array_unique(array_merge(
        array_keys($openPositionsBySymbol),
        array_keys($tradeStatsBySymbol)
    ));
    sort($allSymbols);

    if (empty($allSymbols)) {
        return [];
    }

    // 4. Batch fetch latest quotes for live pricing
    $quotes = get_batch_stock_quotes($allSymbols);

    $analysisRows = [];

    foreach ($allSymbols as $sym) {
        $isOpen = isset($openPositionsBySymbol[$sym]);
        $pos = $isOpen ? $openPositionsBySymbol[$sym] : null;
        $tradeStats = $tradeStatsBySymbol[$sym] ?? null;

        $qtyHeld = $pos ? (int)$pos['quantity'] : 0;
        $avgBuyPrice = $pos ? (float)$pos['average_buy_price'] : 0.0;
        $currency = $pos['currency'] ?? ($tradeStats['currency'] ?? 'INR');

        // Company Name resolution
        $companyName = $sym;
        if ($pos && !empty($pos['company_name'])) {
            $companyName = $pos['company_name'];
        } elseif ($tradeStats && !empty($tradeStats['company_name'])) {
            $companyName = $tradeStats['company_name'];
        }

        // Live quote resolution
        $quote = $quotes[$sym] ?? null;
        $marketPrice = null;
        $priceChange30d = null;
        $quoteSuccess = false;
        $lastUpdated = null;

        if ($quote && !empty($quote['success']) && (float)($quote['price'] ?? 0) > 0) {
            $marketPrice = (float)$quote['price'];
            $quoteSuccess = true;
            $lastUpdated = $quote['timestamp'] ?? date('Y-m-d H:i:s');
            if (!empty($quote['name'])) {
                $companyName = $quote['name'];
            }
            if (isset($quote['change_percent'])) {
                $priceChange30d = (float)$quote['change_percent'];
            }
        } else {
            // Check cache
            $cached = get_cached_stock_price($sym);
            if ($cached) {
                $marketPrice = (float)$cached['price'];
                $lastUpdated = $cached['last_updated'];
                $quoteSuccess = true;
            } elseif ($avgBuyPrice > 0) {
                $marketPrice = $avgBuyPrice;
            }
        }

        // Financial calculations for open position
        $costBasis = $qtyHeld > 0 ? round($qtyHeld * $avgBuyPrice, 2) : 0.0;
        $marketVal = ($qtyHeld > 0 && $marketPrice !== null) ? round($qtyHeld * $marketPrice, 2) : 0.0;
        $unrealisedPnl = $qtyHeld > 0 ? round($marketVal - $costBasis, 2) : 0.0;
        $unrealisedReturnPct = ($qtyHeld > 0 && $costBasis > 0) ? round(($unrealisedPnl / $costBasis) * 100, 2) : 0.0;

        // Realised P&L from completed trades
        $realisedPnl = $tradeStats ? round((float)$tradeStats['total_realised_pnl'], 2) : 0.0;
        $winningTrades = $tradeStats ? (int)$tradeStats['win_trades'] : 0;
        $losingTrades = $tradeStats ? (int)$tradeStats['loss_trades'] : 0;
        $completedTrades = $winningTrades + $losingTrades;
        $winRate = $completedTrades > 0 ? round(($winningTrades / $completedTrades) * 100, 1) : null;

        // Total combined P&L for this stock
        $combinedPnl = round($realisedPnl + $unrealisedPnl, 2);

        $analysisRows[] = [
            'symbol'                 => $sym,
            'company_name'           => $companyName,
            'is_open'                => $isOpen,
            'status'                 => $isOpen ? 'OPEN' : 'CLOSED',
            'quantity'               => $qtyHeld,
            'average_buy_price'      => $avgBuyPrice > 0 ? $avgBuyPrice : null,
            'latest_market_price'    => $marketPrice,
            'cost_basis'             => $costBasis,
            'market_value'           => $marketVal,
            'unrealised_pnl'         => $isOpen ? $unrealisedPnl : 0.0,
            'unrealised_return_pct'  => $isOpen ? $unrealisedReturnPct : null,
            'realised_pnl'           => $realisedPnl,
            'combined_pnl'           => $combinedPnl,
            'price_change_recent'    => $priceChange30d,
            'winning_trades'         => $winningTrades,
            'losing_trades'          => $losingTrades,
            'win_rate'               => $winRate,
            'total_trades'           => $tradeStats ? (int)$tradeStats['total_trades'] : 0,
            'last_trade_time'        => $tradeStats ? $tradeStats['last_trade_time'] : ($pos['created_at'] ?? null),
            'quote_success'          => $quoteSuccess,
            'last_updated'           => $lastUpdated,
            'currency'               => $currency,
            // Placeholders for on-demand technical metrics (populated asynchronously or upon stock selection)
            'sma20'                  => null,
            'sma50'                  => null,
            'rsi14'                  => null,
            'volatility'             => null,
            'outlook_signal'         => null
        ];
    }

    return $analysisRows;
}

/**
 * Get Comprehensive Stock Analysis Portfolio Summary with date filtering
 */
function get_portfolio_analysis_summary(array $dateFilter = []): array {
    $account = get_account_balance();
    $positions = get_open_positions();
    $currency = $account['currency'];

    $availableCash = (float)$account['balance'];
    $initialBalance = (float)$account['initial_balance'];

    $totalInvested = 0.0;
    $totalMarketValue = 0.0;
    $totalUnrealisedPnl = 0.0;

    foreach ($positions as $p) {
        $totalInvested += (float)$p['invested_cost'];
        $totalMarketValue += (float)$p['market_value'];
        $totalUnrealisedPnl += (float)$p['unrealised_pnl'];
    }

    // Current Portfolio Equity = Available Virtual Cash + Current Market Value of Open Positions
    $currentEquity = round($availableCash + $totalMarketValue, 2);

    // Realised P&L and Completed Trades Statistics from Trades table
    $pdo = get_db_connection();
    $tradeSql = "SELECT 
                    COUNT(*) as total_trades,
                    SUM(CASE WHEN trade_type = 'SELL' THEN 1 ELSE 0 END) as completed_trades,
                    SUM(CASE WHEN trade_type = 'SELL' AND realised_pnl > 0 THEN 1 ELSE 0 END) as profitable_trades,
                    SUM(CASE WHEN trade_type = 'SELL' AND realised_pnl < 0 THEN 1 ELSE 0 END) as losing_trades,
                    COALESCE(SUM(CASE WHEN trade_type = 'SELL' THEN realised_pnl ELSE 0 END), 0) as total_realised_pnl
                FROM trades WHERE 1=1";

    $params = [];
    if (!empty($dateFilter['start'])) {
        $tradeSql .= " AND created_at >= :start_date";
        $params['start_date'] = $dateFilter['start'];
    }
    if (!empty($dateFilter['end'])) {
        $tradeSql .= " AND created_at <= :end_date";
        $params['end_date'] = $dateFilter['end'];
    }

    $stmt = $pdo->prepare($tradeSql);
    $stmt->execute($params);
    $tradeData = $stmt->fetch() ?: [];

    $totalRealisedPnl = round((float)($tradeData['total_realised_pnl'] ?? 0.0), 2);
    $completedTradesCount = (int)($tradeData['completed_trades'] ?? 0);
    $profitableTradesCount = (int)($tradeData['profitable_trades'] ?? 0);
    $losingTradesCount = (int)($tradeData['losing_trades'] ?? 0);

    // Total Profit/Loss = Realised Profit/Loss + Unrealised Profit/Loss
    $totalProfitLoss = round($totalRealisedPnl + $totalUnrealisedPnl, 2);

    // Overall Return Percentage = ((Current Portfolio Equity - Initial Virtual Balance) / Initial Virtual Balance) * 100
    $overallReturnPct = $initialBalance > 0 ? round((($currentEquity - $initialBalance) / $initialBalance) * 100, 2) : 0.0;

    // Win Rate = (profitable trades / completed trades) * 100
    $winRatePct = $completedTradesCount > 0 ? round(($profitableTradesCount / $completedTradesCount) * 100, 1) : null;

    // Best and Worst performing stock calculation
    $stocksAnalysis = get_all_traded_and_held_stocks_analysis($dateFilter);
    $bestStock = null;
    $worstStock = null;

    if (!empty($stocksAnalysis)) {
        // Sort by combined profit/loss (realised + unrealised)
        $sortedByPnl = $stocksAnalysis;
        usort($sortedByPnl, function($a, $b) {
            return $b['combined_pnl'] <=> $a['combined_pnl'];
        });

        $top = $sortedByPnl[0];
        $bottom = end($sortedByPnl);

        $bestStock = [
            'symbol'       => $top['symbol'],
            'company_name' => $top['company_name'],
            'total_pnl'    => $top['combined_pnl'],
            'return_pct'   => $top['unrealised_return_pct'],
            'is_open'      => $top['is_open']
        ];

        $worstStock = [
            'symbol'       => $bottom['symbol'],
            'company_name' => $bottom['company_name'],
            'total_pnl'    => $bottom['combined_pnl'],
            'return_pct'   => $bottom['unrealised_return_pct'],
            'is_open'      => $bottom['is_open']
        ];
    }

    // Holdings ranked by current unrealised return percentage
    $rankedHoldings = array_filter($positions, function($p) {
        return (int)$p['quantity'] > 0;
    });
    usort($rankedHoldings, function($a, $b) {
        return $b['unrealised_pnl_percent'] <=> $a['unrealised_pnl_percent'];
    });

    return [
        'initial_balance'           => $initialBalance,
        'available_cash'            => $availableCash,
        'total_invested'            => round($totalInvested, 2),
        'portfolio_market_value'    => round($totalMarketValue, 2),
        'current_equity'            => $currentEquity,
        'total_realised_pnl'        => $totalRealisedPnl,
        'total_unrealised_pnl'      => round($totalUnrealisedPnl, 2),
        'total_pnl'                 => $totalProfitLoss,
        'overall_return_percent'    => $overallReturnPct,
        'completed_trades_count'    => $completedTradesCount,
        'profitable_trades_count'   => $profitableTradesCount,
        'losing_trades_count'       => $losingTradesCount,
        'win_rate_percent'          => $winRatePct,
        'best_stock'                => $bestStock,
        'worst_stock'               => $worstStock,
        'ranked_holdings'           => array_values($rankedHoldings),
        'currency'                  => $currency,
        'open_positions_count'      => count($positions)
    ];
}

/**
 * Generate CSV export of Stock Analysis and Portfolio Summary
 */
function export_stock_analysis_csv(array $dateFilter = []): void {
    $summary = get_portfolio_analysis_summary($dateFilter);
    $stocks = get_all_traded_and_held_stocks_analysis($dateFilter);
    $curr = $summary['currency'];

    $filename = 'stock_analysis_report_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $fp = fopen('php://output', 'w');

    // Section 1: Portfolio Performance Overview
    fputcsv($fp, ['=== STOCK PULSE PAPER-TRADING PORTFOLIO ANALYSIS REPORT ===']);
    fputcsv($fp, ['Generated At', date('Y-m-d H:i:s T')]);
    fputcsv($fp, ['Date Filter', !empty($dateFilter['label']) ? $dateFilter['label'] : 'All Time']);
    fputcsv($fp, []);

    fputcsv($fp, ['--- PORTFOLIO SUMMARY ---']);
    fputcsv($fp, ['Metric', 'Value', 'Currency / Unit']);
    fputcsv($fp, ['Initial Virtual Balance', number_format($summary['initial_balance'], 2), $curr]);
    fputcsv($fp, ['Available Virtual Cash', number_format($summary['available_cash'], 2), $curr]);
    fputcsv($fp, ['Total Invested (Open Positions)', number_format($summary['total_invested'], 2), $curr]);
    fputcsv($fp, ['Current Market Value (Open Positions)', number_format($summary['portfolio_market_value'], 2), $curr]);
    fputcsv($fp, ['Current Total Portfolio Equity', number_format($summary['current_equity'], 2), $curr]);
    fputcsv($fp, ['Total Realised Profit / Loss', number_format($summary['total_realised_pnl'], 2), $curr]);
    fputcsv($fp, ['Total Unrealised Profit / Loss', number_format($summary['total_unrealised_pnl'], 2), $curr]);
    fputcsv($fp, ['Total Combined Profit / Loss', number_format($summary['total_pnl'], 2), $curr]);
    fputcsv($fp, ['Overall Return Percentage', number_format($summary['overall_return_percent'], 2) . '%', '%']);
    fputcsv($fp, ['Completed Trades Count', (string)$summary['completed_trades_count'], 'Trades']);
    fputcsv($fp, ['Profitable Completed Trades', (string)$summary['profitable_trades_count'], 'Trades']);
    fputcsv($fp, ['Loss-Making Completed Trades', (string)$summary['losing_trades_count'], 'Trades']);
    fputcsv($fp, ['Win Rate', $summary['win_rate_percent'] !== null ? number_format($summary['win_rate_percent'], 1) . '%' : 'Not available', '%']);
    fputcsv($fp, ['Best-Performing Stock', $summary['best_stock'] ? "{$summary['best_stock']['symbol']} (" . format_money($summary['best_stock']['total_pnl'], $curr) . ")" : 'Not available', '']);
    fputcsv($fp, ['Worst-Performing Stock', $summary['worst_stock'] ? "{$summary['worst_stock']['symbol']} (" . format_money($summary['worst_stock']['total_pnl'], $curr) . ")" : 'Not available', '']);
    fputcsv($fp, []);

    // Section 2: Individual Stock Analysis Table
    fputcsv($fp, ['--- INDIVIDUAL STOCK ANALYSIS ---']);
    fputcsv($fp, [
        'Stock Symbol',
        'Company Name',
        'Position Status',
        'Quantity Held',
        'Average Purchase Price (' . $curr . ')',
        'Latest Market Price (' . $curr . ')',
        'Total Cost Basis (' . $curr . ')',
        'Current Market Value (' . $curr . ')',
        'Unrealised P&L (' . $curr . ')',
        'Unrealised Return (%)',
        'Realised P&L (' . $curr . ')',
        'Total Combined P&L (' . $curr . ')',
        'Completed Trades',
        'Win Rate (%)',
        'Last Market Update'
    ]);

    foreach ($stocks as $s) {
        fputcsv($fp, [
            $s['symbol'],
            $s['company_name'],
            $s['status'],
            (string)$s['quantity'],
            $s['average_buy_price'] !== null ? number_format($s['average_buy_price'], 2) : 'Not available',
            $s['latest_market_price'] !== null ? number_format($s['latest_market_price'], 2) : 'Not available',
            number_format($s['cost_basis'], 2),
            number_format($s['market_value'], 2),
            number_format($s['unrealised_pnl'], 2),
            $s['unrealised_return_pct'] !== null ? number_format($s['unrealised_return_pct'], 2) . '%' : 'Not available',
            number_format($s['realised_pnl'], 2),
            number_format($s['combined_pnl'], 2),
            (string)($s['winning_trades'] + $s['losing_trades']),
            $s['win_rate'] !== null ? number_format($s['win_rate'], 1) . '%' : 'Not available',
            $s['last_updated'] ?? 'Not available'
        ]);
    }

    fclose($fp);
    exit;
}
