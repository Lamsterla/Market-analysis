<?php
/**
 * StockPlus - Authentication and Security Module
 * 
 * Features:
 * - Secure Session & Remember-Me handling
 * - CSRF Protection
 * - Password Hashing & Strength Validation
 * - Brute-Force Rate Limiting (5 attempts / 15 mins)
 * - PHPMailer Gmail SMTP Integration (Verification & Password Reset)
 * - Multi-User Account Provisioning
 */

declare(strict_types=1);

// Set default timezone to Indian Standard Time (matching user's environment)
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Initialize secure PHP session
 */
function auth_start_session(): void {
    if (php_sapi_name() === 'cli') {
        return;
    }
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        $cookieParams = [
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => true,
            'samesite' => 'Lax'
        ];
        session_set_cookie_params($cookieParams);
        @session_start();
    }

    // Auto-login via remember-me cookie if session is unauthenticated
    if (isset($_SESSION) && !isset($_SESSION['user_id'])) {
        auth_check_remember_token();
    }
}

// Start session automatically when auth module is loaded
auth_start_session();

/**
 * Generate or retrieve CSRF token
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Render CSRF hidden input field
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Verify CSRF token
 */
function verify_csrf_token(?string $token): bool {
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Retrieve client IP address
 */
function get_client_ip(): string {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * Check rate limit for authentication attempts (Max 5 attempts per 15 minutes)
 */
function check_login_rate_limit(string $ip, ?string $email = null): array {
    $pdo = get_db_connection();
    $cutoff = gmdate('Y-m-d H:i:s', time() - 900); // 15 minutes ago in UTC

    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM login_attempts 
        WHERE (ip_address = :ip OR (:email != '' AND email = :email))
          AND attempt_time > :cutoff
    ");
    $stmt->execute([
        'ip'     => $ip,
        'email'  => $email ?: '',
        'cutoff' => $cutoff
    ]);
    $count = (int)$stmt->fetchColumn();

    if ($count >= 5) {
        return [
            'allowed'     => false,
            'retry_after' => 15,
            'message'     => 'Too many failed login attempts. Please wait 15 minutes before trying again.'
        ];
    }

    return ['allowed' => true, 'remaining' => 5 - $count];
}

/**
 * Record a failed authentication attempt
 */
function record_login_attempt(string $ip, ?string $email = null): void {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, email, attempt_time) VALUES (:ip, :email, :t)");
    $stmt->execute([
        'ip'    => $ip,
        'email' => $email ?: null,
        't'     => gmdate('Y-m-d H:i:s')
    ]);
}

/**
 * Clear login attempts on successful login
 */
function clear_login_attempts(string $ip, ?string $email = null): void {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = :ip OR (:email IS NOT NULL AND email = :email)");
    $stmt->execute([
        'ip'    => $ip,
        'email' => $email ?: ''
    ]);
}

/**
 * Validate password strength
 */
function validate_password_strength(string $password): array {
    $errors = [];
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter (A-Z).';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter (a-z).';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number (0-9).';
    }
    if (!preg_match('/[\W_]/', $password)) {
        $errors[] = 'Password must contain at least one special character (!@#$%^&* etc).';
    }

    return [
        'valid'  => empty($errors),
        'errors' => $errors
    ];
}

/**
 * Register a new user
 */
function auth_register(
    string $fullName,
    string $email,
    string $password,
    string $confirmPassword,
    bool $termsAgreed
): array {
    $fullName = trim($fullName);
    $email = strtolower(trim($email));

    // 1. Validation
    if ($fullName === '') {
        return ['success' => false, 'error' => 'Please enter your full name.'];
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Please enter a valid email address.'];
    }
    if ($password !== $confirmPassword) {
        return ['success' => false, 'error' => 'Passwords do not match.'];
    }
    if (!$termsAgreed) {
        return ['success' => false, 'error' => 'You must agree to the Terms & Conditions to create an account.'];
    }

    $strength = validate_password_strength($password);
    if (!$strength['valid']) {
        return ['success' => false, 'error' => implode(' ', $strength['errors'])];
    }

    $pdo = get_db_connection();

    // 2. Check for duplicate email in users table
    $checkStmt = $pdo->prepare("SELECT user_id FROM users WHERE email = :email");
    $checkStmt->execute(['email' => $email]);
    if ($checkStmt->fetch()) {
        return ['success' => false, 'error' => 'An account with this email address already exists. Please log in or reset your password.'];
    }

    // 3. Security tokens, 6-digit OTP and password hashing
    $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $otp = sprintf('%06d', random_int(100000, 999999));
    $tokenHash = hash('sha256', $otp);
    $expiry = date('c', time() + 1800); // 30 minutes with full ISO 8601 timezone

    // 4. Save to temporary pending_registrations (DO NOT insert into `users` table until OTP is verified!)
    try {
        ensure_pending_registrations_table($pdo);

        // Remove any prior pending attempt for this email
        $del = $pdo->prepare("DELETE FROM pending_registrations WHERE email = :email");
        $del->execute(['email' => $email]);

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $expExpr = ($driver === 'pgsql') ? "CURRENT_TIMESTAMP + INTERVAL '30 minutes'" : "datetime('now', '+30 minutes')";

        $ins = $pdo->prepare("
            INSERT INTO pending_registrations (full_name, email, password_hash, otp_hash, expires_at, created_at)
            VALUES (:name, :email, :pass, :thash, {$expExpr}, CURRENT_TIMESTAMP)
        ");
        $ins->execute([
            'name'  => $fullName,
            'email' => $email,
            'pass'  => $passwordHash,
            'thash' => $tokenHash
        ]);

        // Also store in server-side session for fast verification
        auth_start_session();
        $_SESSION['pending_registration'] = [
            'full_name'     => $fullName,
            'email'         => $email,
            'password_hash' => $passwordHash,
            'otp_hash'      => $tokenHash,
            'expiry'        => time() + 1800
        ];
        $_SESSION['verify_email'] = $email;
        unset($_SESSION['dev_otp']);

        // 5. Send 6-digit OTP verification email via Gmail SMTP (PHPMailer)
        $mailResult = auth_send_verification_otp_email($email, $fullName, $otp);

        return [
            'success'   => true,
            'email'     => $email,
            'otp'       => $otp,
            'mail_sent' => $mailResult['success'],
            'mail_msg'  => $mailResult['message'] ?? '',
            'message'   => 'A 6-digit verification code has been sent to your email. Enter it below to complete your registration.'
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Registration initiation failed: ' . $e->getMessage()];
    }
}

/**
 * Ensure `pending_registrations` table exists in PostgreSQL and SQLite
 */
function ensure_pending_registrations_table(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) return;

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS pending_registrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                full_name VARCHAR(255) NOT NULL,
                email VARCHAR(191) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                otp_hash VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } else {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS pending_registrations (
                id SERIAL PRIMARY KEY,
                full_name VARCHAR(255) NOT NULL,
                email VARCHAR(191) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                otp_hash VARCHAR(64) NOT NULL,
                expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
                created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }
    $ensured = true;
}

/**
 * Verify account using 6-digit OTP
 * User is registered and inserted into `users` table ONLY AFTER OTP matches!
 */
function auth_verify_otp(string $email, string $otp): array {
    $email = strtolower(trim($email));
    $otp = trim($otp);

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Please provide a valid email address.'];
    }

    if (!preg_match('/^[0-9]{6}$/', $otp)) {
        return ['success' => false, 'error' => 'Please enter a valid 6-digit verification code.'];
    }

    $pdo = get_db_connection();

    // 1. Check if user is already registered in `users`
    $checkUser = $pdo->prepare("SELECT user_id, is_verified FROM users WHERE email = :email");
    $checkUser->execute(['email' => $email]);
    $alreadyRegistered = $checkUser->fetch();
    if ($alreadyRegistered && !empty($alreadyRegistered['is_verified'])) {
        return [
            'success'          => true, 
            'already_verified' => true, 
            'message'          => 'Your account is already registered and verified! You can log in directly.'
        ];
    }

    // 2. Retrieve pending registration from DB or session
    ensure_pending_registrations_table($pdo);
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $expCheckSql = ($driver === 'pgsql') ? "(expires_at < CURRENT_TIMESTAMP)" : "(expires_at < datetime('now'))";

    $stmt = $pdo->prepare("SELECT *, {$expCheckSql} AS is_expired_db FROM pending_registrations WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $pending = $stmt->fetch();

    auth_start_session();
    $sessionPending = $_SESSION['pending_registration'] ?? null;

    $fullName = null;
    $passwordHash = null;
    $otpHash = null;
    $isExpired = false;

    if ($pending) {
        $fullName = $pending['full_name'];
        $passwordHash = $pending['password_hash'];
        $otpHash = $pending['otp_hash'];
        if (!empty($pending['is_expired_db'])) {
            $isExpired = true;
        }
    }

    if ($sessionPending && strtolower($sessionPending['email'] ?? '') === $email) {
        $fullName = $fullName ?: $sessionPending['full_name'];
        $passwordHash = $passwordHash ?: $sessionPending['password_hash'];
        $otpHash = $otpHash ?: $sessionPending['otp_hash'];
        // If session expiry is still within the active window, it is NOT expired
        if (($sessionPending['expiry'] ?? 0) >= time()) {
            $isExpired = false;
        }
    }

    if (!$fullName || !$passwordHash || !$otpHash) {
        return [
            'success' => false,
            'error'   => 'No pending registration found for this email. Please sign up first.'
        ];
    }

    if ($isExpired) {
        return [
            'success' => false,
            'expired' => true,
            'email'   => $email,
            'error'   => 'This verification code has expired. Please click Resend Code to receive a fresh OTP.'
        ];
    }

    // 3. Verify OTP hash
    $inputHash = hash('sha256', $otp);
    if (!hash_equals($otpHash, $inputHash)) {
        return [
            'success' => false,
            'error'   => 'Invalid verification code. Please check your 6-digit code and try again.'
        ];
    }

    // 4. OTP IS VALID -> NOW AND ONLY NOW, REGISTER USER AND ADD TO DATABASE
    try {
        $insert = $pdo->prepare("
            INSERT INTO users (full_name, email, password_hash, is_verified, created_at, updated_at)
            VALUES (:name, :email, :pass, TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $insert->execute([
            'name'  => $fullName,
            'email' => $email,
            'pass'  => $passwordHash
        ]);
        $userId = (int)$pdo->lastInsertId();

        // Provision the ₹100,000 virtual trading account
        auth_ensure_user_account($userId);

        // Delete from pending_registrations
        $del = $pdo->prepare("DELETE FROM pending_registrations WHERE email = :email");
        $del->execute(['email' => $email]);
        unset($_SESSION['pending_registration'], $_SESSION['dev_otp']);

        // Log the user in
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_name'] = $fullName;
        $_SESSION['user_email'] = $email;
        $_SESSION['logged_in_time'] = time();

        return [
            'success' => true,
            'user_id' => $userId,
            'message' => 'Account successfully registered and verified! Welcome to StockPlus.'
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Database registration error: ' . $e->getMessage()];
    }
}

/**
 * Resend 6-digit OTP verification code
 */
function auth_resend_otp(string $email): array {
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Please enter a valid email address.'];
    }

    $pdo = get_db_connection();

    // Check if already registered
    $checkUser = $pdo->prepare("SELECT user_id, is_verified FROM users WHERE email = :email");
    $checkUser->execute(['email' => $email]);
    $u = $checkUser->fetch();
    if ($u && !empty($u['is_verified'])) {
        return ['success' => true, 'already_verified' => true, 'message' => 'This account is already registered and verified. You can log in directly.'];
    }

    ensure_pending_registrations_table($pdo);
    $stmt = $pdo->prepare("SELECT * FROM pending_registrations WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $pending = $stmt->fetch();

    auth_start_session();
    $sessionPending = $_SESSION['pending_registration'] ?? null;

    $fullName = null;
    if ($pending) {
        $fullName = $pending['full_name'];
    } elseif ($sessionPending && strtolower($sessionPending['email'] ?? '') === $email) {
        $fullName = $sessionPending['full_name'];
    } else {
        return ['success' => false, 'error' => 'No pending registration found for this email. Please submit the sign up form first.'];
    }

    $otp = sprintf('%06d', random_int(100000, 999999));
    $tokenHash = hash('sha256', $otp);
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $expExpr = ($driver === 'pgsql') ? "CURRENT_TIMESTAMP + INTERVAL '30 minutes'" : "datetime('now', '+30 minutes')";

    $upd = $pdo->prepare("
        UPDATE pending_registrations 
        SET otp_hash = :thash, expires_at = {$expExpr} 
        WHERE email = :email
    ");
    $upd->execute(['thash' => $tokenHash, 'email' => $email]);

    if ($sessionPending && strtolower($sessionPending['email'] ?? '') === $email) {
        $_SESSION['pending_registration']['otp_hash'] = $tokenHash;
        $_SESSION['pending_registration']['expiry'] = time() + 1800;
    }
    unset($_SESSION['dev_otp']);

    $mailResult = auth_send_verification_otp_email($email, $fullName, $otp);

    return [
        'success'   => true,
        'otp'       => $otp,
        'mail_sent' => $mailResult['success'],
        'mail_msg'  => $mailResult['message'] ?? '',
        'message'   => 'A fresh 6-digit verification code has been sent to your email.'
    ];
}

/**
 * Verify email address with token
 */
function auth_verify_email(string $rawToken): array {
    $rawToken = trim($rawToken);
    if ($rawToken === '') {
        return ['success' => false, 'error' => 'Invalid or missing verification token.'];
    }

    $tokenHash = hash('sha256', $rawToken);
    $pdo = get_db_connection();

    $stmt = $pdo->prepare("SELECT * FROM users WHERE verification_token_hash = :thash");
    $stmt->execute(['thash' => $tokenHash]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'error' => 'Invalid verification token. Your account may already be verified.'];
    }

    if (!empty($user['is_verified'])) {
        return ['success' => true, 'already_verified' => true, 'message' => 'Your email is already verified! You can now log in.'];
    }

    if (!empty($user['verification_expiry']) && strtotime($user['verification_expiry']) < time()) {
        return [
            'success'   => false,
            'expired'   => true,
            'user_id'   => (int)$user['user_id'],
            'email'     => $user['email'],
            'error'     => 'This verification link has expired. Please request a new verification email.'
        ];
    }

    // Mark user verified and clear token
    $update = $pdo->prepare("
        UPDATE users 
        SET is_verified = TRUE, verification_token_hash = NULL, verification_expiry = NULL, updated_at = CURRENT_TIMESTAMP 
        WHERE user_id = :uid
    ");
    $update->execute(['uid' => $user['user_id']]);

    // Provision an independent virtual trading account for this verified user
    auth_ensure_user_account((int)$user['user_id']);

    return [
        'success' => true,
        'message' => 'Your email has been verified successfully! Your ₹100,000 virtual trading account is ready. Please log in to continue.'
    ];
}

/**
 * Resend verification email
 */
function auth_resend_verification(string $email): array {
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Please enter a valid email address.'];
    }

    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'error' => 'No account found with this email address.'];
    }

    if (!empty($user['is_verified'])) {
        return ['success' => true, 'message' => 'This account is already verified. You can log in directly.'];
    }

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiry = date('Y-m-d H:i:s', time() + 86400);

    $upd = $pdo->prepare("UPDATE users SET verification_token_hash = :thash, verification_expiry = :expiry, updated_at = CURRENT_TIMESTAMP WHERE user_id = :uid");
    $upd->execute(['thash' => $tokenHash, 'expiry' => $expiry, 'uid' => $user['user_id']]);

    $mailResult = auth_send_verification_email($email, $user['full_name'], $rawToken);

    return [
        'success' => true,
        'message' => 'A new verification link has been sent to your email.'
    ];
}

/**
 * Ensure user has an independent trading account row in `accounts`
 */
function auth_ensure_user_account(int $userId): void {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT id FROM accounts WHERE user_id = :uid");
    $stmt->execute(['uid' => $userId]);
    $existing = $stmt->fetch();

    if (!$existing) {
        $initBalance = (float)(getenv('INITIAL_BALANCE') ?: 100000.00);
        $currency = getenv('CURRENCY_CODE') ?: 'INR';
        $ins = $pdo->prepare("
            INSERT INTO accounts (user_id, balance, initial_balance, currency, created_at, updated_at) 
            VALUES (:uid, :b, :ib, :c, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $ins->execute([
            'uid' => $userId,
            'b'   => $initBalance,
            'ib'  => $initBalance,
            'c'   => $currency
        ]);
    }
}

/**
 * Authenticate User Login
 */
function auth_login(string $email, string $password, bool $rememberMe = false): array {
    $ip = get_client_ip();
    $email = strtolower(trim($email));

    // 1. Rate limiting check
    $rateCheck = check_login_rate_limit($ip, $email);
    if (!$rateCheck['allowed']) {
        return ['success' => false, 'error' => $rateCheck['message']];
    }

    if ($email === '' || $password === '') {
        return ['success' => false, 'error' => 'Please enter both your email and password.'];
    }

    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        record_login_attempt($ip, $email);
        return ['success' => false, 'error' => 'Invalid email or password.'];
    }

    // 2. Email verification requirement
    if (empty($user['is_verified'])) {
        return [
            'success'     => false,
            'unverified'  => true,
            'email'       => $email,
            'error'       => 'Your email address has not been verified yet. Please check your email inbox or click below to resend the verification link.'
        ];
    }

    // 3. Clear rate limit attempts on success
    clear_login_attempts($ip, $email);

    // 4. Session security: regenerate session ID to prevent fixation
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $_SESSION['user_id'] = (int)$user['user_id'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['logged_in_time'] = time();

    // Ensure trading account is initialized
    auth_ensure_user_account((int)$user['user_id']);

    // 5. Handle "Remember Me"
    if ($rememberMe) {
        $rawRememberToken = bin2hex(random_bytes(32));
        $rememberHash = hash('sha256', $rawRememberToken);
        $rememberExpiry = date('Y-m-d H:i:s', time() + (86400 * 30)); // 30 days

        $remStmt = $pdo->prepare("INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (:uid, :thash, :exp)");
        $remStmt->execute([
            'uid'   => $user['user_id'],
            'thash' => $rememberHash,
            'exp'   => $rememberExpiry
        ]);

        $cookieValue = $user['user_id'] . ':' . $rawRememberToken;
        setcookie('stockplus_remember', $cookieValue, [
            'expires'  => time() + (86400 * 30),
            'path'     => '/',
            'httponly' => true,
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'samesite' => 'Lax'
        ]);
    }

    return [
        'success' => true,
        'user'    => [
            'user_id'   => (int)$user['user_id'],
            'full_name' => $user['full_name'],
            'email'     => $user['email']
        ]
    ];
}

/**
 * Check remember-me persistent token
 */
function auth_check_remember_token(): void {
    if (empty($_COOKIE['stockplus_remember']) || isset($_SESSION['user_id'])) {
        return;
    }

    $parts = explode(':', $_COOKIE['stockplus_remember'], 2);
    if (count($parts) !== 2) {
        return;
    }

    $userId = (int)$parts[0];
    $rawToken = $parts[1];
    $tokenHash = hash('sha256', $rawToken);

    try {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("
            SELECT rt.*, u.full_name, u.email, u.is_verified 
            FROM remember_tokens rt
            JOIN users u ON rt.user_id = u.user_id
            WHERE rt.user_id = :uid 
              AND rt.token_hash = :thash 
              AND rt.expires_at > CURRENT_TIMESTAMP
        ");
        $stmt->execute(['uid' => $userId, 'thash' => $tokenHash]);
        $tokenRow = $stmt->fetch();

        if ($tokenRow && !empty($tokenRow['is_verified'])) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            $_SESSION['user_id'] = (int)$tokenRow['user_id'];
            $_SESSION['user_name'] = $tokenRow['full_name'];
            $_SESSION['user_email'] = $tokenRow['email'];
            $_SESSION['logged_in_time'] = time();
            auth_ensure_user_account((int)$tokenRow['user_id']);
        }
    } catch (Throwable $e) {
        // Silently continue if database is unready
    }
}

/**
 * Logout authenticated user
 */
function auth_logout(): void {
    auth_start_session();

    if (!empty($_SESSION['user_id'])) {
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = :uid");
            $stmt->execute(['uid' => $_SESSION['user_id']]);
        } catch (Throwable $e) {}
    }

    // Clear remember-me cookie
    if (isset($_COOKIE['stockplus_remember']) && !headers_sent()) {
        setcookie('stockplus_remember', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'samesite' => 'Lax'
        ]);
    }

    // Clear session data
    $_SESSION = [];
    if (ini_get("session.use_cookies") && !headers_sent()) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/**
 * Request password reset
 */
function auth_request_password_reset(string $email): array {
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Please enter a valid email address.'];
    }

    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    // Security practice: Never reveal whether email exists in database
    $genericMessage = 'If an account exists with that email address, we have sent instructions to reset your password.';

    if (!$user) {
        return ['success' => true, 'message' => $genericMessage];
    }

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiry = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiry

    $upd = $pdo->prepare("UPDATE users SET reset_token_hash = :rhash, reset_expiry = :rexp, updated_at = CURRENT_TIMESTAMP WHERE user_id = :uid");
    $upd->execute([
        'rhash' => $tokenHash,
        'rexp'  => $expiry,
        'uid'   => $user['user_id']
    ]);

    auth_send_password_reset_email($email, $user['full_name'], $rawToken);

    return ['success' => true, 'message' => $genericMessage];
}

/**
 * Verify password reset token
 */
function auth_verify_reset_token(string $rawToken): ?array {
    $rawToken = trim($rawToken);
    if ($rawToken === '') {
        return null;
    }

    $tokenHash = hash('sha256', $rawToken);
    $pdo = get_db_connection();

    $stmt = $pdo->prepare("SELECT * FROM users WHERE reset_token_hash = :rhash");
    $stmt->execute(['rhash' => $tokenHash]);
    $user = $stmt->fetch();

    if (!$user) {
        return null;
    }

    if (!empty($user['reset_expiry']) && strtotime($user['reset_expiry']) < time()) {
        return null;
    }

    return $user;
}

/**
 * Reset password with verified token
 */
function auth_reset_password(string $rawToken, string $newPassword, string $confirmPassword): array {
    $user = auth_verify_reset_token($rawToken);
    if (!$user) {
        return ['success' => false, 'error' => 'Invalid or expired password reset link. Please request a new one.'];
    }

    if ($newPassword !== $confirmPassword) {
        return ['success' => false, 'error' => 'Passwords do not match.'];
    }

    $strength = validate_password_strength($newPassword);
    if (!$strength['valid']) {
        return ['success' => false, 'error' => implode(' ', $strength['errors'])];
    }

    $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo = get_db_connection();

    $upd = $pdo->prepare("
        UPDATE users 
        SET password_hash = :pass, reset_token_hash = NULL, reset_expiry = NULL, updated_at = CURRENT_TIMESTAMP 
        WHERE user_id = :uid
    ");
    $upd->execute([
        'pass' => $newHash,
        'uid'  => $user['user_id']
    ]);

    return [
        'success' => true,
        'message' => 'Password reset successfully! You can now log in with your new password.'
    ];
}

/**
 * Check if current session is logged in
 */
function auth_is_logged_in(): bool {
    auth_start_session();
    return !empty($_SESSION['user_id']);
}

/**
 * Retrieve current authenticated user ID (defaults to 1 if unauthenticated CLI testing)
 */
function get_current_user_id(): int {
    auth_start_session();
    return (int)($_SESSION['user_id'] ?? 1);
}

/**
 * Retrieve current authenticated user details
 */
function auth_current_user(): ?array {
    auth_start_session();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return [
        'user_id'   => (int)$_SESSION['user_id'],
        'full_name' => (string)($_SESSION['user_name'] ?? 'Trader'),
        'email'     => (string)($_SESSION['user_email'] ?? '')
    ];
}

/**
 * Require login on protected dashboard pages
 */
function auth_require_login(): void {
    if (php_sapi_name() === 'cli') {
        return;
    }
    if (!auth_is_logged_in()) {
        $currentUrl = $_SERVER['REQUEST_URI'] ?? 'index.php';
        $_SESSION['redirect_after_login'] = $currentUrl;
        header('Location: login.php');
        exit;
    }
}

/**
 * Build configured PHPMailer instance using Gmail SMTP
 */
function create_phpmailer_instance(): PHPMailer {
    $mail = new PHPMailer(true);

    $host = get_env_var('SMTP_HOST', 'smtp.gmail.com');
    $port = (int)get_env_var('SMTP_PORT', 587);
    $user = trim((string)get_env_var('SMTP_USER', ''));
    $pass = str_replace(' ', '', (string)get_env_var('SMTP_PASS', ''));
    $secure = (string)get_env_var('SMTP_SECURE', 'tls');
    $fromEmail = (string)get_env_var('SMTP_FROM_EMAIL', '') ?: ($user ?: 'noreply@stockplus.local');
    $fromName = (string)get_env_var('SMTP_FROM_NAME', 'StockPlus');

    // Enable SMTP
    $mail->isSMTP();
    $mail->Host       = $host;
    $mail->SMTPAuth   = !empty($user) && !empty($pass);
    $mail->Username   = $user;
    $mail->Password   = $pass;
    $mail->SMTPSecure = strtolower($secure) === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $port;
    $mail->CharSet    = 'UTF-8';
    $mail->Timeout    = 10;

    $mail->setFrom($fromEmail, $fromName);
    $mail->isHTML(true);

    return $mail;
}

/**
 * Send 6-digit OTP email verification via PHPMailer
 */
function auth_send_verification_otp_email(string $recipientEmail, string $recipientName, string $otp): array {
    $subject = "Your StockPlus Verification Code: {$otp}";

    $htmlBody = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background-color: #0B0F19; color: #F8FAFC; margin: 0; padding: 20px; }
            .card { max-width: 520px; margin: 0 auto; background-color: #131B2E; border: 1px solid #1E293B; border-radius: 14px; padding: 32px; box-shadow: 0 8px 24px rgba(0,0,0,0.5); }
            .logo { font-size: 24px; font-weight: 800; color: #3B82F6; margin-bottom: 20px; display: inline-block; }
            h2 { color: #F8FAFC; font-size: 20px; margin-top: 0; font-weight: 700; }
            p { color: #94A3B8; font-size: 15px; line-height: 1.6; }
            .otp-box { background: #0B0F19; border: 2px dashed #3B82F6; border-radius: 10px; padding: 20px; text-align: center; margin: 24px 0; }
            .otp-code { font-family: "JetBrains Mono", Consolas, monospace; font-size: 38px; letter-spacing: 12px; font-weight: 800; color: #9FA0FF; display: inline-block; margin-left: 12px; }
            .otp-expiry { color: #F59E0B; font-size: 13px; font-weight: 600; margin-top: 8px; }
            .footer { font-size: 12px; color: #64748B; margin-top: 24px; border-top: 1px solid #1E293B; padding-top: 16px; }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="logo">📈 StockPlus</div>
            <h2>Verify Your Email Address</h2>
            <p>Hello <strong>' . htmlspecialchars($recipientName) . '</strong>,</p>
            <p>Thank you for registering with <strong>StockPlus</strong> Paper Trading Simulator. Use the 6-digit verification code below to verify your email and activate your ₹100,000 virtual trading account:</p>
            
            <div class="otp-box">
                <div class="otp-code">' . htmlspecialchars($otp) . '</div>
                <div class="otp-expiry">⏱ This code expires in 15 minutes</div>
            </div>

            <p>Enter this 6-digit code on the verification screen to activate your account. Never share this code with anyone.</p>
            <p class="footer">If you did not request this verification code, please ignore this email.</p>
        </div>
    </body>
    </html>';

    $textBody = "Hello {$recipientName},\n\n"
              . "Your StockPlus 6-digit verification code is: {$otp}\n\n"
              . "This code expires in 15 minutes.\n"
              . "Enter this code on the verification screen to activate your account.\n";

    return send_email_safe($recipientEmail, $recipientName, $subject, $htmlBody, $textBody);
}

/**
 * Send email verification link via PHPMailer
 */
function auth_send_verification_email(string $recipientEmail, string $recipientName, string $rawToken): array {
    $appUrl = rtrim((string)get_env_var('APP_URL', 'http://localhost/h'), '/');
    $verifyUrl = $appUrl . '/verify_email.php?token=' . urlencode($rawToken);

    $subject = 'Verify your StockPlus account';

    $htmlBody = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background-color: #0B0F19; color: #F8FAFC; margin: 0; padding: 20px; }
            .card { max-width: 540px; margin: 0 auto; background-color: #131B2E; border: 1px solid #1E293B; border-radius: 12px; padding: 32px; }
            .logo { font-size: 24px; font-weight: 800; color: #3B82F6; margin-bottom: 20px; display: inline-block; }
            h2 { color: #F8FAFC; font-size: 20px; margin-top: 0; }
            p { color: #94A3B8; font-size: 15px; line-height: 1.6; }
            .btn { display: inline-block; background-color: #3B82F6; color: #FFFFFF !important; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-weight: 700; margin: 24px 0; font-size: 15px; }
            .footer { font-size: 12px; color: #64748B; margin-top: 24px; border-top: 1px solid #1E293B; padding-top: 16px; }
            .link-text { color: #38BDF8; word-break: break-all; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="logo">📈 StockPlus</div>
            <h2>Welcome to StockPlus, ' . htmlspecialchars($recipientName) . '!</h2>
            <p>Thank you for signing up for StockPlus Paper Trading Simulator. To activate your account and start trading with ₹100,000 in virtual funds, please verify your email address below:</p>
            <div style="text-align: center;">
                <a href="' . htmlspecialchars($verifyUrl) . '" class="btn">Verify Email Address</a>
            </div>
            <p>This verification link will expire in <strong>24 hours</strong>.</p>
            <p class="footer">If you did not sign up for StockPlus, please disregard this email.<br><br>Direct link: <a href="' . htmlspecialchars($verifyUrl) . '" class="link-text">' . htmlspecialchars($verifyUrl) . '</a></p>
        </div>
    </body>
    </html>';

    $textBody = "Welcome to StockPlus, {$recipientName}!\n\n"
              . "Please verify your email address by opening the following link in your browser:\n"
              . "{$verifyUrl}\n\n"
              . "This link expires in 24 hours.\n";

    return send_email_safe($recipientEmail, $recipientName, $subject, $htmlBody, $textBody);
}

/**
 * Send password reset email via PHPMailer
 */
function auth_send_password_reset_email(string $recipientEmail, string $recipientName, string $rawToken): array {
    $appUrl = rtrim((string)get_env_var('APP_URL', 'http://localhost/h'), '/');
    $resetUrl = $appUrl . '/reset_password.php?token=' . urlencode($rawToken);

    $subject = 'Reset your StockPlus password';

    $htmlBody = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background-color: #0B0F19; color: #F8FAFC; margin: 0; padding: 20px; }
            .card { max-width: 540px; margin: 0 auto; background-color: #131B2E; border: 1px solid #1E293B; border-radius: 12px; padding: 32px; }
            .logo { font-size: 24px; font-weight: 800; color: #3B82F6; margin-bottom: 20px; display: inline-block; }
            h2 { color: #F8FAFC; font-size: 20px; margin-top: 0; }
            p { color: #94A3B8; font-size: 15px; line-height: 1.6; }
            .btn { display: inline-block; background-color: #3B82F6; color: #FFFFFF !important; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-weight: 700; margin: 24px 0; font-size: 15px; }
            .footer { font-size: 12px; color: #64748B; margin-top: 24px; border-top: 1px solid #1E293B; padding-top: 16px; }
            .link-text { color: #38BDF8; word-break: break-all; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="logo">📈 StockPlus</div>
            <h2>Password Reset Request</h2>
            <p>Hello ' . htmlspecialchars($recipientName) . ',</p>
            <p>We received a request to reset your password for your StockPlus account. Click the button below to choose a new password:</p>
            <div style="text-align: center;">
                <a href="' . htmlspecialchars($resetUrl) . '" class="btn">Reset Password</a>
            </div>
            <p>This password reset link will expire in <strong>1 hour</strong>.</p>
            <p class="footer">If you did not request a password reset, you can safely ignore this email. Your password will remain unchanged.<br><br>Direct link: <a href="' . htmlspecialchars($resetUrl) . '" class="link-text">' . htmlspecialchars($resetUrl) . '</a></p>
        </div>
    </body>
    </html>';

    $textBody = "Hello {$recipientName},\n\n"
              . "We received a request to reset your StockPlus password. Click or visit the link below:\n"
              . "{$resetUrl}\n\n"
              . "This link expires in 1 hour.\n";

    return send_email_safe($recipientEmail, $recipientName, $subject, $htmlBody, $textBody);
}

/**
 * Internal helper to send email safely through PHPMailer with graceful fallback
 */
function send_email_safe(string $recipientEmail, string $recipientName, string $subject, string $htmlBody, string $textBody): array {
    $smtpUser = trim((string)get_env_var('SMTP_USER', ''));
    $smtpPass = str_replace(' ', '', (string)get_env_var('SMTP_PASS', ''));

    // If SMTP is not yet configured, log and return informative status
    if (empty($smtpUser) || empty($smtpPass)) {
        error_log("[SMTP NOTICE] Email to {$recipientEmail} ('{$subject}') was prepared, but SMTP_USER / SMTP_PASS is unconfigured in .env.");
        return [
            'success' => false,
            'message' => 'SMTP credentials not configured in .env. Verification token generated.'
        ];
    }

    try {
        $mail = create_phpmailer_instance();
        $mail->addAddress($recipientEmail, $recipientName);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $textBody;

        $mail->send();
        return ['success' => true, 'message' => 'Email sent successfully via Gmail SMTP.'];
    } catch (Throwable $e) {
        error_log("[SMTP ERROR] Failed to send email to {$recipientEmail}: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Unable to send email via SMTP: ' . $e->getMessage()
        ];
    }
}
