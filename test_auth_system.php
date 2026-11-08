<?php
/**
 * StockPlus - Authentication and Multi-User Automated Test Suite
 * Tests all requirements from Section 11 of the specification.
 */

declare(strict_types=1);

echo "========================================================================\n";
echo " STOCKPLUS AUTHENTICATION & MULTI-USER VERIFICATION SUITE\n";
echo "========================================================================\n\n";

putenv('MOCK_DATA=true');
putenv('DB_DRIVER=sqlite');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = get_db_connection();
$passedTests = 0;
$totalTests = 0;

function run_test(string $name, callable $fn): void {
    global $passedTests, $totalTests;
    $totalTests++;
    printf("[%02d] Testing: %-55s ", $totalTests, $name);
    try {
        $result = $fn();
        if ($result === true || (is_array($result) && !empty($result['pass']))) {
            $passedTests++;
            echo "[PASS]\n";
        } else {
            $msg = is_array($result) ? ($result['error'] ?? 'Assertion failed') : 'Test failed';
            echo "[FAIL] -> {$msg}\n";
        }
    } catch (Throwable $e) {
        echo "[ERROR] -> " . $e->getMessage() . "\n";
    }
}

// Ensure clean test state
ensure_pending_registrations_table($pdo);
$pdo->exec("
    DELETE FROM trades WHERE user_id IN (SELECT user_id FROM users WHERE email LIKE '%@testsuite.local');
    DELETE FROM positions WHERE user_id IN (SELECT user_id FROM users WHERE email LIKE '%@testsuite.local');
    DELETE FROM accounts WHERE user_id IN (SELECT user_id FROM users WHERE email LIKE '%@testsuite.local');
    DELETE FROM users WHERE email LIKE '%@testsuite.local';
    DELETE FROM pending_registrations WHERE email LIKE '%@testsuite.local';
    DELETE FROM login_attempts;
");

// Test 1: New user registration initiation (User is NOT in users table yet!)
$warrenOtp = null;
run_test("Scenario 1: Successful registration initiation (Not in users table yet)", function() use ($pdo, &$warrenOtp) {
    $res = auth_register(
        "Warren Buffett",
        "warren@testsuite.local",
        "StrongP@ssw0rd123!",
        "StrongP@ssw0rd123!",
        true
    );

    if (!$res['success']) {
        return ['pass' => false, 'error' => $res['error']];
    }

    $warrenOtp = $res['otp'] ?? null;
    if (empty($warrenOtp) || strlen($warrenOtp) !== 6) {
        return ['pass' => false, 'error' => '6-digit OTP was not generated'];
    }

    // Crucial requirement: User MUST NOT be in `users` table yet!
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = 'warren@testsuite.local'");
    $stmt->execute();
    $u = $stmt->fetch();
    if ($u) {
        return ['pass' => false, 'error' => 'User was inserted into users table BEFORE OTP verification!'];
    }

    // User MUST be in pending_registrations table
    $pStmt = $pdo->prepare("SELECT * FROM pending_registrations WHERE email = 'warren@testsuite.local'");
    $pStmt->execute();
    $p = $pStmt->fetch();
    if (!$p) {
        return ['pass' => false, 'error' => 'User was not recorded in pending_registrations'];
    }

    return true;
});

// Test 2: Password strength rejection
run_test("Scenario 2: Weak password rejection on signup", function() {
    $res = auth_register("Weak User", "weak@testsuite.local", "12345", "12345", true);
    if ($res['success']) return ['pass' => false, 'error' => 'Weak password should be rejected'];
    return true;
});

// Test 3: Duplicate email rejection (once registered)
run_test("Scenario 3: Duplicate email registration rejection", function() use ($warrenOtp) {
    // Verify Warren first so he is in `users`
    $v = auth_verify_otp("warren@testsuite.local", $warrenOtp);
    if (!$v['success']) return ['pass' => false, 'error' => 'Failed to verify Warren: ' . $v['error']];

    $res = auth_register(
        "Another Warren",
        "warren@testsuite.local",
        "AnotherP@ssw0rd123!",
        "AnotherP@ssw0rd123!",
        true
    );
    if ($res['success']) {
        return ['pass' => false, 'error' => 'Duplicate registration was allowed'];
    }
    return str_contains($res['error'], 'already exists');
});

// Test 4: Login rejected before email verification
run_test("Scenario 4: Login rejected for unverified/unregistered user", function() {
    $res = auth_login("ghost@testsuite.local", "StrongP@ssw0rd123!");
    return !$res['success'];
});

// Test 5: Email verification activation via 6-digit OTP adds user to database
$warrenUserId = null;
run_test("Scenario 5: OTP verification inserts user into database & provisions ₹100,000", function() use ($pdo, &$warrenUserId) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = 'warren@testsuite.local'");
    $stmt->execute();
    $u = $stmt->fetch();
    if (!$u) return ['pass' => false, 'error' => 'Warren not found in users table after OTP verification'];

    $warrenUserId = (int)$u['user_id'];
    if (empty($u['is_verified'])) return ['pass' => false, 'error' => 'Warren is_verified is not TRUE'];

    // Check account table auto-provisioned
    $accStmt = $pdo->prepare("SELECT * FROM accounts WHERE user_id = :uid");
    $accStmt->execute(['uid' => $warrenUserId]);
    $acc = $accStmt->fetch();

    if (!$acc) return ['pass' => false, 'error' => 'Virtual trading account was not auto-provisioned'];
    if ((float)$acc['balance'] !== 100000.00) return ['pass' => false, 'error' => 'Initial balance is not 100,000.00'];

    return true;
});

// Test 6: Successful login with verified credentials
run_test("Scenario 6: Successful login with correct credentials", function() {
    $res = auth_login("warren@testsuite.local", "StrongP@ssw0rd123!");
    if (!$res['success']) {
        return ['pass' => false, 'error' => $res['error']];
    }
    return !empty($_SESSION['user_id']) && $_SESSION['user_email'] === 'warren@testsuite.local';
});

// Test 7: Login rejected with incorrect password
run_test("Scenario 7: Login rejected with incorrect password", function() {
    $res = auth_login("warren@testsuite.local", "WrongPassword123!");
    if ($res['success']) {
        return ['pass' => false, 'error' => 'Login succeeded with wrong password'];
    }
    return true;
});

// Test 8: Rate limiting after multiple failed attempts
run_test("Scenario 8: Rate limiting triggered after failed attempts", function() {
    $ip = '192.168.1.99';
    for ($i = 0; $i < 5; $i++) {
        record_login_attempt($ip, 'brute@testsuite.local');
    }
    $check = check_login_rate_limit($ip, 'brute@testsuite.local');
    if ($check['allowed']) {
        return ['pass' => false, 'error' => 'Rate limiter should have blocked after 5 attempts'];
    }
    clear_login_attempts($ip, 'brute@testsuite.local');
    return true;
});

// Test 9: Password recovery & reset token generation
$resetRawToken = null;
run_test("Scenario 9: Password reset request generation", function() use ($pdo, &$resetRawToken) {
    $req = auth_request_password_reset("warren@testsuite.local");
    if (!$req['success']) {
        return ['pass' => false, 'error' => $req['error']];
    }

    $stmt = $pdo->prepare("SELECT reset_token_hash, reset_expiry FROM users WHERE email = 'warren@testsuite.local'");
    $stmt->execute();
    $u = $stmt->fetch();

    if (empty($u['reset_token_hash'])) {
        return ['pass' => false, 'error' => 'Reset token hash was not generated'];
    }

    // Set a known test token
    $resetRawToken = "test_reset_token_" . time();
    $upd = $pdo->prepare("UPDATE users SET reset_token_hash = :h WHERE email = 'warren@testsuite.local'");
    $upd->execute(['h' => hash('sha256', $resetRawToken)]);

    return true;
});

// Test 10: Complete password reset workflow
run_test("Scenario 10: Complete password reset execution", function() use ($resetRawToken) {
    $res = auth_reset_password($resetRawToken, "BrandNewP@ssw0rd999!", "BrandNewP@ssw0rd999!");
    if (!$res['success']) {
        return ['pass' => false, 'error' => $res['error']];
    }

    // Old password should fail
    $oldLogin = auth_login("warren@testsuite.local", "StrongP@ssw0rd123!");
    if ($oldLogin['success']) {
        return ['pass' => false, 'error' => 'Old password still works after reset'];
    }

    // New password should succeed
    $newLogin = auth_login("warren@testsuite.local", "BrandNewP@ssw0rd999!");
    if (!$newLogin['success']) {
        return ['pass' => false, 'error' => 'New password failed to log in'];
    }

    return true;
});

// Test 11: Multi-User Isolation & Independent Trading
run_test("Scenario 11: Strict multi-user balance and position isolation", function() use ($pdo, $warrenUserId) {
    // 1. Register User B
    $resB = auth_register("Charlie Munger", "charlie@testsuite.local", "CharlieMung3rP@ss!", "CharlieMung3rP@ss!", true);
    if (!$resB['success']) return ['pass' => false, 'error' => 'Failed to register User B: ' . $resB['error']];

    $vB = auth_verify_otp("charlie@testsuite.local", $resB['otp']);
    if (!$vB['success']) return ['pass' => false, 'error' => 'Failed to verify Charlie: ' . $vB['error']];

    $charlieUserId = (int)$vB['user_id'];

    // 2. Warren (User A) buys 10 shares of RELIANCE.NS
    $_SESSION['user_id'] = $warrenUserId;
    $_SESSION['user_email'] = 'warren@testsuite.local';
    
    $buyA = execute_buy_stock('RELIANCE.NS', 10, null, null, true, $warrenUserId);
    if (!$buyA['success']) return ['pass' => false, 'error' => 'Warren buy failed: ' . $buyA['error']];

    // Warren's cash should be less than 100,000
    $accA = get_account_balance($warrenUserId);
    if ($accA['balance'] >= 100000.00) return ['pass' => false, 'error' => 'Warren cash was not deducted'];

    $posA = get_open_positions($warrenUserId);
    $symsA = array_column($posA, 'symbol');
    if (!in_array('RELIANCE.NS', $symsA, true)) return ['pass' => false, 'error' => 'Warren missing RELIANCE.NS position'];

    // 3. Charlie (User B) must have FULL 100,000 cash and ZERO positions!
    $_SESSION['user_id'] = $charlieUserId;
    $_SESSION['user_email'] = 'charlie@testsuite.local';

    $accB = get_account_balance($charlieUserId);
    if ($accB['balance'] !== 100000.00) {
        return ['pass' => false, 'error' => "Charlie's cash was contaminated by Warren! Expected 100,000, got " . $accB['balance']];
    }

    $posB = get_open_positions($charlieUserId);
    if (!empty($posB)) {
        return ['pass' => false, 'error' => "Charlie has positions belonging to Warren! Found " . count($posB) . " positions."];
    }

    // 4. Charlie buys 5 shares of TCS.NS
    $buyB = execute_buy_stock('TCS.NS', 5, null, null, true, $charlieUserId);
    if (!$buyB['success']) return ['pass' => false, 'error' => 'Charlie buy failed: ' . $buyB['error']];

    // Check Warren again - Warren must NOT have TCS.NS!
    $posACheck = get_open_positions($warrenUserId);
    $symsACheck = array_column($posACheck, 'symbol');
    if (in_array('TCS.NS', $symsACheck, true)) {
        return ['pass' => false, 'error' => "Warren received TCS.NS shares bought by Charlie!"];
    }

    // Clean up
    $pdo->prepare("DELETE FROM trades WHERE user_id IN (:u1, :u2)")->execute(['u1' => $warrenUserId, 'u2' => $charlieUserId]);
    $pdo->prepare("DELETE FROM positions WHERE user_id IN (:u1, :u2)")->execute(['u1' => $warrenUserId, 'u2' => $charlieUserId]);
    $pdo->prepare("DELETE FROM accounts WHERE user_id IN (:u1, :u2)")->execute(['u1' => $warrenUserId, 'u2' => $charlieUserId]);
    $pdo->prepare("DELETE FROM users WHERE user_id IN (:u1, :u2)")->execute(['u1' => $warrenUserId, 'u2' => $charlieUserId]);

    return true;
});

// Test 12: Logout and session destruction
run_test("Scenario 12: Logout invalidates active user session", function() {
    auth_logout();
    return empty($_SESSION['user_id']);
});

echo "\n========================================================================\n";
printf(" TEST RUN COMPLETE: %d Passed, %d Failed (Total %d Tests)\n", $passedTests, $totalTests - $passedTests, $totalTests);
echo "========================================================================\n";

exit($passedTests === $totalTests ? 0 : 1);
