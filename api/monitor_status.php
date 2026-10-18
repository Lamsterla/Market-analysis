<?php
/**
 * Monitor Heartbeat & Status Endpoint
 * Returns JSON status indicating if the Python stop-loss background monitor is alive
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/../includes/functions.php';

try {
    $status = get_monitor_status();
    echo json_encode([
        'success'   => true,
        'status'    => $status,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
