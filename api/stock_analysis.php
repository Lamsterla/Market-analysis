<?php
/**
 * Stock Analysis API Endpoint
 * Handles asynchronous historical data retrieval, chart series, date-range summaries, and CSV export.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

$action = trim($_GET['action'] ?? 'history');

// 1. CSV Export Action (Streams file download)
if ($action === 'export_csv') {
    $range = trim($_GET['range'] ?? 'ALL');
    $startDate = trim($_GET['start'] ?? '');
    $endDate = trim($_GET['end'] ?? '');

    $dateFilter = [];
    if ($range === 'TODAY') {
        $dateFilter['start'] = date('Y-m-d 00:00:00');
        $dateFilter['end'] = date('Y-m-d 23:59:59');
        $dateFilter['label'] = 'Today (' . date('Y-m-d') . ')';
    } elseif ($range === '7D') {
        $dateFilter['start'] = date('Y-m-d 00:00:00', strtotime('-7 days'));
        $dateFilter['label'] = 'Last 7 Days';
    } elseif ($range === '30D') {
        $dateFilter['start'] = date('Y-m-d 00:00:00', strtotime('-30 days'));
        $dateFilter['label'] = 'Last 30 Days';
    } elseif ($range === 'CUSTOM' && !empty($startDate)) {
        $dateFilter['start'] = $startDate . ' 00:00:00';
        if (!empty($endDate)) {
            $dateFilter['end'] = $endDate . ' 23:59:59';
            $dateFilter['label'] = "Custom Range ({$startDate} to {$endDate})";
        } else {
            $dateFilter['label'] = "From {$startDate}";
        }
    } else {
        $dateFilter['label'] = 'All Time';
    }

    export_stock_analysis_csv($dateFilter);
    exit;
}

// JSON API responses
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

try {
    // 2. Historical Prices & Technical Indicators Action
    if ($action === 'history') {
        $symbol = trim(strtoupper($_GET['symbol'] ?? ''));
        $period = trim(strtolower($_GET['period'] ?? '30d'));
        $forceMock = isset($_GET['mock']) && $_GET['mock'] === '1';

        if ($symbol === '') {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => 'Please provide a valid stock symbol.'
            ]);
            exit;
        }

        $result = get_stock_history_and_indicators($symbol, $period, $forceMock);

        if (!$result['success']) {
            http_response_code(404);
            echo json_encode($result);
            exit;
        }

        echo json_encode($result, JSON_PRETTY_PRINT);
        exit;
    }

    // 3. Date-Range Summary Metrics Action
    if ($action === 'summary') {
        $range = trim($_GET['range'] ?? 'ALL');
        $startDate = trim($_GET['start'] ?? '');
        $endDate = trim($_GET['end'] ?? '');

        $dateFilter = [];
        if ($range === 'TODAY') {
            $dateFilter['start'] = date('Y-m-d 00:00:00');
            $dateFilter['end'] = date('Y-m-d 23:59:59');
            $dateFilter['label'] = 'Today';
        } elseif ($range === '7D') {
            $dateFilter['start'] = date('Y-m-d 00:00:00', strtotime('-7 days'));
            $dateFilter['label'] = 'Last 7 Days';
        } elseif ($range === '30D') {
            $dateFilter['start'] = date('Y-m-d 00:00:00', strtotime('-30 days'));
            $dateFilter['label'] = 'Last 30 Days';
        } elseif ($range === 'CUSTOM' && !empty($startDate)) {
            $dateFilter['start'] = $startDate . ' 00:00:00';
            if (!empty($endDate)) {
                $dateFilter['end'] = $endDate . ' 23:59:59';
            }
            $dateFilter['label'] = 'Custom';
        }

        $summary = get_portfolio_analysis_summary($dateFilter);
        $snapshots = get_portfolio_snapshots(30);

        echo json_encode([
            'success'   => true,
            'summary'   => $summary,
            'snapshots' => $snapshots,
            'range'     => $range
        ], JSON_PRETTY_PRINT);
        exit;
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Unknown action requested.'
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Analysis service error: ' . $e->getMessage()
    ]);
}
