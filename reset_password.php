<?php
/**
 * StockPlus - Reset Password Page
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (auth_is_logged_in()) {
    header('Location: index.php');
    exit;
}

$token = trim((string)($_GET['token'] ?? ($_POST['token'] ?? '')));
$error = null;
$success = null;

// Validate token presence
if ($token === '') {
    $error = 'Password reset token is missing. Please check your reset link.';
} else {
    $user = auth_verify_reset_token($token);
    if (!$user) {
        $error = 'This password reset link is invalid or has expired. Please request a new one.';
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $csrfToken = (string)($_POST['csrf_token'] ?? '');
    $newPassword = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if (!verify_csrf_token($csrfToken)) {
        $error = 'Security validation failed (CSRF).';
    } else {
        $result = auth_reset_password($token, $newPassword, $confirmPassword);
        if ($result['success']) {
            $_SESSION['flash_success'] = $result['message'];
            header('Location: login.php');
            exit;
        } else {
            $error = $result['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password &bull; StockPlus Trading Terminal</title>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <!-- Auth CSS -->
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body class="auth-page">

    <div class="auth-wrapper">
        <a href="login.php" class="auth-brand">
            <div class="auth-brand-logo">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
                    <polyline points="17 6 23 6 23 12"></polyline>
                </svg>
            </div>
            <div class="auth-brand-text">
                Stock<strong>Plus</strong>
                <span class="auth-badge">Security</span>
            </div>
        </a>

        <div class="auth-card">
            <div class="auth-header">
                <h1 class="auth-title">Set New Password</h1>
                <p class="auth-subtitle">Create a strong, secure password for your account</p>
            </div>

            <?php if ($error): ?>
                <div class="auth-alert auth-alert-error">
                    <span>⚠️ <?= htmlspecialchars($error) ?></span>
                </div>
                <div style="text-align: center; margin-top: 16px;">
                    <a href="forgot_password.php" class="auth-link">Request a New Reset Link &rarr;</a>
                </div>
            <?php else: ?>
                <form method="POST" action="reset_password.php" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                    <!-- New Password with Visibility Toggle -->
                    <div class="form-group">
                        <label for="signupPassword" class="form-label">New Password</label>
                        <div class="input-container">
                            <input 
                                type="password" 
                                id="signupPassword" 
                                name="password" 
                                class="auth-input input-with-toggle" 
                                placeholder="Min. 8 chars (A-Z, a-z, 0-9, symbol)" 
                                required 
                                autofocus 
                                autocomplete="new-password"
                            >
                            <button type="button" class="btn-toggle-password" data-target="signupPassword" aria-label="Show password">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>

                        <!-- Live Strength Indicator -->
                        <div class="password-strength-container">
                            <div class="strength-bar-bg">
                                <div class="strength-segment"></div>
                                <div class="strength-segment"></div>
                                <div class="strength-segment"></div>
                                <div class="strength-segment"></div>
                                <div class="strength-segment"></div>
                            </div>
                            <span id="strengthText" class="strength-text"></span>
                        </div>
                    </div>

                    <!-- Confirm Password -->
                    <div class="form-group">
                        <label for="confirmPassword" class="form-label">Confirm New Password</label>
                        <div class="input-container">
                            <input 
                                type="password" 
                                id="confirmPassword" 
                                name="confirm_password" 
                                class="auth-input input-with-toggle" 
                                placeholder="Re-enter your new password" 
                                required 
                                autocomplete="new-password"
                            >
                            <button type="button" class="btn-toggle-password" data-target="confirmPassword" aria-label="Show password">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                        <span id="passwordMatchHint" style="font-size: 0.78rem; display: block; margin-top: 4px;"></span>
                    </div>

                    <button type="submit" class="btn-auth-submit">
                        <span>Save New Password &amp; Sign In</span>
                    </button>
                </form>
            <?php endif; ?>

            <div class="auth-footer">
                <a href="login.php" class="auth-link">&larr; Return to Sign In</a>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="assets/js/auth.js"></script>
</body>
</html>

