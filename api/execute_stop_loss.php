<?php
/**
 * Stop-Loss Evaluation & Execution Endpoint
 * Can be run via CLI by Python monitor or called via HTTP POST / GET
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

// Detect whether running in CLI or HTTP
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
}

// Parse arguments if CLI or payload if HTTP
$quotesPayload = null;
$forceMock = false;
$action = 'evaluate';

if ($isCli) {
    global $argv;
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--prices=')) {
            $jsonStr = substr($arg, 9);
            $quotesPayload = json_decode($jsonStr, true);
        } elseif ($arg === '--get-symbols') {
            $action = 'get-symbols';
        } elseif ($arg === '--mock') {
            $forceMock = true;
        }
    }
} else {
    $rawInput = file_get_contents('php://input');
    if ($rawInput) {
        $json = json_decode($rawInput, true);
        if (is_array($json)) {
            if (isset($json['prices'])) {
                $quotesPayload = $json['prices'];
            }
            if (!empty($json['mock'])) {
                $forceMock = true;
            }
            if (isset($json['action'])) {
                $action = $json['action'];
            }
        }
    }
    if (isset($_GET['action'])) {
        $action = $_GET['action'];
    }
    if (isset($_GET['mock'])) {
        $forceMock = true;
    }
}

try {
    if ($action === 'get-symbols') {
        $pdo = get_db_connection();
        $stmt = $pdo->query("SELECT DISTINCT symbol, stop_loss_price FROM positions WHERE stop_loss_price IS NOT NULL AND stop_loss_price > 0");
        $symbols = $stmt->fetchAll();
        $response = [
            'success' => true,
            'symbols' => $symbols
        ];
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit(0);
    }

    // Default: Evaluate stop-losses
    $result = evaluate_stop_losses($quotesPayload ?: [], $forceMock);

    // Update heartbeat
    update_monitor_heartbeat(true, $result['message']);

    $response = [
        'success'   => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'result'    => $result
    ];

    echo json_encode($response, JSON_PRETTY_PRINT);
    exit(0);

} catch (Throwable $e) {
    $response = [
        'success' => false,
        'error'   => $e->getMessage()
    ];
    if (!$isCli) {
        http_response_code(500);
    }
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit(1);
}
