<?php
/**
 * Database Initialization Script
 * Connects directly to PostgreSQL, creates the database if missing, executes schema.sql,
 * and verifies tables. Never falls back to SQLite during initialization.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

echo "========================================\n";
echo " Stock Market Simulator - DB Setup\n";
echo "========================================\n\n";

// Phase 3 & 4: Verify PDO PostgreSQL driver is loaded
if (!extension_loaded('pdo_pgsql') || !in_array('pgsql', PDO::getAvailableDrivers(), true)) {
    echo "[ERROR] The PHP extension 'pdo_pgsql' is not installed or enabled.\n";
    echo "Please enable 'extension=pdo_pgsql' in your php.ini file and restart Apache/PHP.\n";
    exit(1);
}

// Phase 2: Correct configuration mapping
$config = get_db_config();
$host     = (string)($config['host'] ?? '127.0.0.1');
$port     = (int)($config['port'] ?? 5432);
$dbName   = (string)($config['dbname'] ?? 'stock');
$user     = (string)($config['user'] ?? 'postgres');
$password = (string)($config['password'] ?? 'root');

// Validate database identifier name to prevent injection
if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
    echo "[ERROR] Invalid database name '{$dbName}'. Only alphanumeric characters and underscores are allowed.\n";
    exit(1);
}

echo "Database Configuration:\n";
echo "  Host:     {$host}\n";
echo "  Port:     {$port}\n";
echo "  Database: {$dbName}\n";
echo "  User:     {$user}\n\n";

$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT            => 5,
];

// Step 1: Connect to the PostgreSQL maintenance database ('postgres')
$maintenanceDb = 'postgres';
echo "Step 1: Connecting to PostgreSQL maintenance database '{$maintenanceDb}' on {$host}:{$port}...\n";

try {
    $maintenanceDsn = "pgsql:host={$host};port={$port};dbname={$maintenanceDb};options='--client_encoding=UTF8'";
    $adminPdo = new PDO($maintenanceDsn, $user, $password, $pdoOptions);
    echo "[OK] Connected to PostgreSQL maintenance database '{$maintenanceDb}'.\n";

    // Step 2: Check whether target database exists
    echo "\nStep 2: Checking if database '{$dbName}' exists...\n";
    $stmt = $adminPdo->prepare("SELECT 1 FROM pg_database WHERE datname = :dbname");
    $stmt->execute(['dbname' => $dbName]);
    $dbExists = (bool)$stmt->fetchColumn();

    if (!$dbExists) {
        echo "Database '{$dbName}' does not exist. Creating it now...\n";
        // Safe identifier quoting with double quotes
        $adminPdo->exec("CREATE DATABASE \"{$dbName}\" ENCODING 'UTF8'");
        echo "[OK] Database '{$dbName}' created successfully.\n";
    } else {
        echo "[OK] Database '{$dbName}' already exists.\n";
    }

    // Close maintenance connection
    unset($adminPdo);

} catch (PDOException $e) {
    echo "\n[ERROR] PostgreSQL maintenance connection failed:\n";
    echo "  " . $e->getMessage() . "\n\n";

    // Provide specific actionable diagnostics for common errors
    if (str_contains($e->getMessage(), 'password authentication failed')) {
        echo "TROUBLESHOOTING PASSWORD AUTHENTICATION:\n";
        echo "1. The password supplied for user '{$user}' was rejected by PostgreSQL.\n";
        echo "2. Note: 'mini' in pgAdmin is the server registration display name, not the database name.\n";
        echo "3. Open .env in your project root and update DB_PASSWORD with your actual PostgreSQL password:\n";
        echo "     DB_PASSWORD=your_actual_postgres_password\n";
        echo "4. In pgAdmin, you can verify your password by right-clicking server 'mini' -> Properties -> Connection.\n";
    } elseif (str_contains($e->getMessage(), 'Connection refused') || str_contains($e->getMessage(), '779')) {
        echo "TROUBLESHOOTING CONNECTION FAILURE:\n";
        echo "1. Verify that the PostgreSQL service (postgresql-x64-18) is running.\n";
        echo "2. Ensure PostgreSQL is listening on port {$port}.\n";
    }

    exit(1);
}

// Step 3: Connect directly to the application database using PostgreSQL (NO fallback)
echo "\nStep 3: Connecting directly to application database '{$dbName}' on PostgreSQL...\n";

try {
    $appDsn = "pgsql:host={$host};port={$port};dbname={$dbName};options='--client_encoding=UTF8'";
    $pdo = new PDO($appDsn, $user, $password, $pdoOptions);

    $actualDriver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($actualDriver !== 'pgsql') {
        throw new RuntimeException("Unexpected driver '{$actualDriver}'; strictly expected 'pgsql'.");
    }
    echo "[OK] Connected successfully to '{$dbName}' via PostgreSQL PDO.\n";

    // Step 4: Execute schema.sql
    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new RuntimeException("Schema file not found at: {$schemaFile}");
    }

    echo "\nStep 4: Applying PostgreSQL schema definitions from schema.sql...\n";
    $sql = file_get_contents($schemaFile);
    if ($sql === false) {
        throw new RuntimeException("Failed to read schema file: {$schemaFile}");
    }

    $pdo->exec($sql);
    echo "[OK] Schema executed successfully.\n";

    // Step 5: Verify that required tables exist
    echo "\nStep 5: Verifying required tables in database '{$dbName}'...\n";
    $requiredTables = ['accounts', 'positions', 'trades', 'price_cache', 'monitor_status'];
    $placeholders = implode(',', array_fill(0, count($requiredTables), '?'));

    $verifyStmt = $pdo->prepare("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'public' 
          AND table_name IN ({$placeholders})
    ");
    $verifyStmt->execute($requiredTables);
    $foundTables = $verifyStmt->fetchAll(PDO::FETCH_COLUMN);

    $missing = array_diff($requiredTables, $foundTables);
    if (!empty($missing)) {
        throw new RuntimeException("Missing expected tables after schema execution: " . implode(', ', $missing));
    }

    foreach ($requiredTables as $table) {
        echo "  - Table '{$table}': OK\n";
    }

    // Step 6: Display current virtual account balance
    echo "\nStep 6: Account Status:\n";
    $accStmt = $pdo->query("SELECT id, balance, initial_balance, currency FROM accounts WHERE id = 1");
    $acc = $accStmt->fetch();
    if ($acc) {
        echo "  Virtual Account ID:   #{$acc['id']}\n";
        echo "  Current Balance:      {$acc['currency']} " . number_format((float)$acc['balance'], 2) . "\n";
        echo "  Initial Balance:      {$acc['currency']} " . number_format((float)$acc['initial_balance'], 2) . "\n";
    } else {
        echo "  [WARNING] Initial account record not found in 'accounts'.\n";
    }

    echo "\n=======================================================\n";
    echo " [SUCCESS] PostgreSQL database initialization complete!\n";
    echo " Database: '{$dbName}' is fully prepared and verified.\n";
    echo "=======================================================\n";
    exit(0);

} catch (Exception $e) {
    echo "\n[ERROR] Failed to initialize database '{$dbName}': " . $e->getMessage() . "\n";
    exit(1);
}

