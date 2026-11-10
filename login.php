<?php
/**
 * StockPlus - User Login Page
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Redirect to dashboard if already authenticated
if (auth_is_logged_in()) {
    header('Location: index.php');
    exit;
}

$errorMessage = null;
$successMessage = null;
$unverifiedEmail = null;

// Handle flash messages
if (!empty($_SESSION['flash_success'])) {
    $successMessage = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $errorMessage = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// Handle Login Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string)($_POST['csrf_token'] ?? '');
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $rememberMe = !empty($_POST['remember_me']);

    if (!verify_csrf_token($csrfToken)) {
        $errorMessage = 'Security validation failed (CSRF). Please refresh the page and try again.';
    } else {
        $result = auth_login($email, $password, $rememberMe);
        if ($result['success']) {
            $redirectUrl = $_SESSION['redirect_after_login'] ?? 'index.php';
            unset($_SESSION['redirect_after_login']);
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            $errorMessage = $result['error'];
            if (!empty($result['unverified'])) {
                $unverifiedEmail = $result['email'] ?? $email;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In &bull; StockPlus Trading Terminal</title>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <!-- Auth CSS -->
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body class="auth-page">

    <div class="auth-wrapper">
        <!-- Brand Logo -->
        <a href="login.php" class="auth-brand">
            <div class="auth-brand-logo">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
                    <polyline points="17 6 23 6 23 12"></polyline>
                </svg>
            </div>
            <div class="auth-brand-text">
                Stock<strong>Plus</strong>
                <span class="auth-badge">Terminal</span>
            </div>
        </a>

        <!-- Main Login Card -->
        <div class="auth-card">
            <div class="auth-header">
                <h1 class="auth-title">Welcome Back</h1>
                <p class="auth-subtitle">Sign in to your paper-trading simulator account</p>
            </div>

            <?php if ($successMessage): ?>
                <div class="auth-alert auth-alert-success">
                    <span>✓ <?= htmlspecialchars($successMessage) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage): ?>
                <div class="auth-alert auth-alert-error">
                    <div>
                        <span>⚠️ <?= htmlspecialchars($errorMessage) ?></span>
                        <?php if ($unverifiedEmail): ?>
                            <div style="margin-top: 8px;">
                                <a href="verify_email.php?email=<?= urlencode($unverifiedEmail) ?>" class="auth-link" style="font-size: 0.85rem;">
                                    Click here to enter your 6-digit OTP code &rarr;
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" action="login.php" novalidate autocomplete="on">
                <?= csrf_field() ?>

                <!-- Email Input -->
                <div class="form-group">
                    <label for="loginEmail" class="form-label">Email Address</label>
                    <div class="input-container">
                        <input 
                            type="email" 
                            id="loginEmail" 
                            name="email" 
                            class="auth-input" 
                            placeholder="name@example.com" 
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" 
                            required 
                            autofocus 
                            autocomplete="username"
                        >
                    </div>
                </div>

                <!-- Password Input with Visibility Toggle -->
                <div class="form-group">
                    <label for="loginPassword" class="form-label">Password</label>
                    <div class="input-container">
                        <input 
                            type="password" 
                            id="loginPassword" 
                            name="password" 
                            class="auth-input input-with-toggle" 
                            placeholder="Enter your password" 
                            required 
                            autocomplete="current-password"
                        >
                        <button type="button" class="btn-toggle-password" data-target="loginPassword" aria-label="Show password">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Remember Me & Forgot Password -->
                <div class="auth-row-actions">
                    <label class="checkbox-label">
                        <input type="checkbox" name="remember_me" value="1" <?= !empty($_POST['remember_me']) ? 'checked' : '' ?>>
                        <span>Remember me</span>
                    </label>
                    <a href="forgot_password.php" class="auth-link">Forgot password?</a>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-auth-submit">
                    <span>Sign In to Terminal</span>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </button>
            </form>

            <!-- Link to Signup -->
            <div class="auth-footer">
                Don't have an account? 
                <a href="signup.php" class="auth-link">Create Account</a>
            </div>
        </div>

        <div class="auth-legal-footer">
            StockPlus &bull; Simulated Paper Trading System<br>
            Virtual Currency Only &bull; Zero Real Market Risk
        </div>
    </div>

    <!-- Scripts -->
    <script src="assets/js/auth.js"></script>
</body>
</html>

