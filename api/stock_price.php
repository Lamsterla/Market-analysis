<?php
/**
 * Stock Price Retrieval API Endpoint
 * Invoked by vanilla JavaScript for live stock search and preview
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/../includes/functions.php';

$symbol = $_GET['symbol'] ?? '';

if (!$symbol) {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $json = json_decode($raw, true);
        if (is_array($json) && !empty($json['symbol'])) {
            $symbol = (string)$json['symbol'];
        }
    }
}

$symbol = trim(strtoupper((string)$symbol));

if ($symbol === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Please provide a valid stock symbol (e.g. AAPL, RELIANCE.NS, TCS.NS).'
    ]);
    exit;
}

$forceMock = isset($_GET['mock']) && $_GET['mock'] === '1';

try {
    $quote = get_stock_quote($symbol, $forceMock);

    if (!$quote['success']) {
        http_response_code(404);
        echo json_encode($quote);
        exit;
    }

    echo json_encode($quote, JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'symbol'  => $symbol,
        'error'   => 'Internal server error while retrieving market data: ' . $e->getMessage()
    ]);
}
