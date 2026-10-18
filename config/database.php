<?php
/**
 * Database Configuration and PDO Connection Manager
 * PostgreSQL database access with environment variable support
 */

declare(strict_types=1);

// Prevent direct script execution if accessed over web without routing
if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'database.php') {
    http_response_code(403);
    exit('Direct access not permitted');
}

/**
 * Load .env file into PHP environment
 */
function load_env(string $filePath = null): void {
    static $loaded = false;
    if ($loaded) {
        return;
    }

    if ($filePath === null) {
        $filePath = dirname(__DIR__) . '/.env';
    }

    if (!file_exists($filePath)) {
        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $val = trim($parts[1]);

            // Strip surrounding quotes
            if (
                (str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))
            ) {
                $val = substr($val, 1, -1);
            }

            // Populate $_ENV, $_SERVER, and putenv for maximum compatibility
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
            putenv("{$key}={$val}");
        }
    }

    $loaded = true;
}

// Load .env automatically
load_env();

/**
 * Robust environment variable accessor checking $_ENV, $_SERVER, and getenv()
 */
function get_env_var(string $key, $default = null) {
    $val = getenv($key);
    if ($val !== false && $val !== '') {
        return $val;
    }
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return $_SERVER[$key];
    }
    return $default;
}

/**
 * Get Database Configuration from environment or defaults
 */
function get_db_config(): array {
    return [
        'driver'   => (string)get_env_var('DB_DRIVER', 'pgsql'),
        'host'     => (string)get_env_var('DB_HOST', '127.0.0.1'),
        'port'     => (int)get_env_var('DB_PORT', 5432),
        'dbname'   => (string)get_env_var('DB_NAME', 'stock'),
        'user'     => (string)get_env_var('DB_USER', 'postgres'),
        'password' => (string)get_env_var('DB_PASSWORD', 'root'),
    ];
}

/**
 * Ensure SQLite database schema exists if fallback is engaged
 */
function ensure_sqlite_initialized(string $dbPath, array $options): void {
    if (!file_exists($dbPath) || filesize($dbPath) === 0) {
        $dbDir = dirname($dbPath);
        if (!is_dir($dbDir)) {
            @mkdir($dbDir, 0777, true);
        }
        $pdo = new PDO("sqlite:{$dbPath}", null, null, $options);
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS accounts (
                id INTEGER PRIMARY KEY,
                balance NUMERIC(15, 2) NOT NULL DEFAULT 100000.00,
                initial_balance NUMERIC(15, 2) NOT NULL DEFAULT 100000.00,
                currency VARCHAR(10) NOT NULL DEFAULT 'INR',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS positions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                symbol VARCHAR(30) NOT NULL UNIQUE,
                company_name VARCHAR(255),
                quantity INTEGER NOT NULL,
                average_buy_price NUMERIC(15, 2) NOT NULL,
                stop_loss_price NUMERIC(15, 2) DEFAULT NULL,
                stop_loss_percent NUMERIC(5, 2) DEFAULT NULL,
                currency VARCHAR(10) NOT NULL DEFAULT 'INR',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS trades (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                symbol VARCHAR(30) NOT NULL,
                company_name VARCHAR(255),
                trade_type VARCHAR(10) NOT NULL,
                quantity INTEGER NOT NULL,
                price NUMERIC(15, 2) NOT NULL,
                total_amount NUMERIC(15, 2) NOT NULL,
                realised_pnl NUMERIC(15, 2) DEFAULT 0.00,
                reason VARCHAR(100) NOT NULL DEFAULT 'MANUAL',
                notes TEXT DEFAULT NULL,
                currency VARCHAR(10) NOT NULL DEFAULT 'INR',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS price_cache (
                symbol VARCHAR(30) PRIMARY KEY,
                company_name VARCHAR(255),
                price NUMERIC(15, 2) NOT NULL,
                currency VARCHAR(10) NOT NULL DEFAULT 'INR',
                last_updated DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS monitor_status (
                id INTEGER PRIMARY KEY DEFAULT 1,
                is_running INTEGER DEFAULT 0,
                last_heartbeat DATETIME DEFAULT NULL,
                last_check_symbols TEXT DEFAULT NULL,
                message TEXT DEFAULT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            INSERT OR IGNORE INTO accounts (id, balance, initial_balance, currency) 
            VALUES (1, 100000.00, 100000.00, 'INR');

            INSERT OR IGNORE INTO monitor_status (id, is_running, message) 
            VALUES (1, 0, 'Stop-loss monitor is inactive');
        ");
    }
}

/**
 * Get Singleton PDO Connection (PostgreSQL by default, with automatic SQLite fallback if offline)
 *
 * @param bool $forceNew Force creating a new PDO instance instead of reusing singleton
 * @param bool $allowFallback If false, throws exception on PostgreSQL failure instead of falling back to SQLite
 * @throws PDOException
 */
function get_db_connection(bool $forceNew = false, bool $allowFallback = true): PDO {
    static $pdo = null;

    if ($pdo !== null && !$forceNew) {
        return $pdo;
    }

    $cfg = get_db_config();
    $driver = strtolower($cfg['driver']);

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 3,
    ];

    if ($driver === 'sqlite') {
        $dbPath = dirname(__DIR__) . '/database/simulator.sqlite';
        ensure_sqlite_initialized($dbPath, $options);
        $pdo = new PDO("sqlite:{$dbPath}", null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $GLOBALS['DB_ACTIVE_DRIVER'] = 'sqlite';
        $GLOBALS['DB_FALLBACK_ACTIVE'] = false;
        return $pdo;
    }

    // Check if pdo_pgsql driver is available
    if (!extension_loaded('pdo_pgsql') || !in_array('pgsql', PDO::getAvailableDrivers(), true)) {
        $driverErr = "PDO PostgreSQL driver ('pdo_pgsql') is not installed or enabled in php.ini.";
        $fallbackPermitted = $allowFallback && (strtolower((string)getenv('DB_FALLBACK')) !== 'false');
        if ($fallbackPermitted) {
            error_log('[DB Driver Warning] ' . $driverErr . ' Activating SQLite fallback.');
            $GLOBALS['DB_ACTIVE_DRIVER'] = 'sqlite';
            $GLOBALS['DB_FALLBACK_ACTIVE'] = true;
            $GLOBALS['DB_PGSQL_ERROR'] = $driverErr;

            $dbPath = dirname(__DIR__) . '/database/simulator.sqlite';
            ensure_sqlite_initialized($dbPath, $options);
            $pdo = new PDO("sqlite:{$dbPath}", null, null, $options);
            $pdo->exec('PRAGMA foreign_keys = ON;');
            return $pdo;
        }
        throw new PDOException($driverErr);
    }

    // Primary: Attempt PostgreSQL connection
    try {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;options=\'--client_encoding=UTF8\'',
            $cfg['host'],
            $cfg['port'],
            $cfg['dbname']
        );

        $pdo = new PDO($dsn, $cfg['user'], $cfg['password'], $options);
        $GLOBALS['DB_ACTIVE_DRIVER'] = 'pgsql';
        $GLOBALS['DB_FALLBACK_ACTIVE'] = false;
        $GLOBALS['DB_PGSQL_ERROR'] = null;
        return $pdo;
    } catch (PDOException $e) {
        error_log('[DB Connection Warning] PostgreSQL offline (' . $e->getMessage() . '). Activating SQLite fallback.');
        
        $fallbackPermitted = $allowFallback && (strtolower((string)getenv('DB_FALLBACK')) !== 'false');
        if ($fallbackPermitted) {
            $GLOBALS['DB_ACTIVE_DRIVER'] = 'sqlite';
            $GLOBALS['DB_FALLBACK_ACTIVE'] = true;
            $GLOBALS['DB_PGSQL_ERROR'] = $e->getMessage();

            $dbPath = dirname(__DIR__) . '/database/simulator.sqlite';
            ensure_sqlite_initialized($dbPath, $options);
            $pdo = new PDO("sqlite:{$dbPath}", null, null, $options);
            $pdo->exec('PRAGMA foreign_keys = ON;');
            return $pdo;
        }

        $GLOBALS['DB_ACTIVE_DRIVER'] = 'pgsql';
        $GLOBALS['DB_FALLBACK_ACTIVE'] = false;
        $GLOBALS['DB_PGSQL_ERROR'] = $e->getMessage();
        throw $e;
    }
}

/**
 * Check whether database is reachable
 */
function check_db_status(): array {
    $cfg = get_db_config();

    if (!extension_loaded('pdo_pgsql') || !in_array('pgsql', PDO::getAvailableDrivers(), true)) {
        $fallbackActive = !empty($GLOBALS['DB_FALLBACK_ACTIVE']);
        return [
            'connected' => $fallbackActive,
            'driver'    => $fallbackActive ? 'sqlite' : 'none',
            'fallback'  => $fallbackActive,
            'error'     => "PDO PostgreSQL driver ('pdo_pgsql') is not enabled in php.ini",
        ];
    }

    // Check direct PostgreSQL availability
    try {
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $cfg['host'], $cfg['port'], $cfg['dbname']);
        $testPdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
            PDO::ATTR_TIMEOUT   => 2,
            PDO::ATTR_ERRMODE   => PDO::ERRMODE_EXCEPTION
        ]);
        $testPdo->query('SELECT 1');
        return [
            'connected' => true,
            'driver'    => 'pgsql',
            'fallback'  => false,
            'error'     => null,
        ];
    } catch (Throwable $e) {
        $fallbackActive = !empty($GLOBALS['DB_FALLBACK_ACTIVE']);
        return [
            'connected' => $fallbackActive,
            'driver'    => $fallbackActive ? 'sqlite' : 'pgsql',
            'fallback'  => $fallbackActive,
            'error'     => $e->getMessage(),
        ];
    }
}
