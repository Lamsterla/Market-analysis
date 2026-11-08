<?php
/**
 * StockPlus - User Registration (Signup) Page
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Redirect if already logged in
if (auth_is_logged_in()) {
    header('Location: index.php');
    exit;
}

$errorMessage = null;
$successInfo = null;

// Handle Signup POST Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string)($_POST['csrf_token'] ?? '');
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    $termsAgreed = !empty($_POST['terms_agreed']);

    if (!verify_csrf_token($csrfToken)) {
        $errorMessage = 'Security validation failed (CSRF). Please refresh and submit again.';
    } else {
        $result = auth_register($fullName, $email, $password, $confirmPassword, $termsAgreed);
        if ($result['success']) {
            auth_start_session();
            $_SESSION['verify_email'] = $email;
            unset($_SESSION['dev_otp']);
            header('Location: verify_email.php?email=' . urlencode($email) . '&sent=1');
            exit;
        } else {
            $errorMessage = $result['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account &bull; StockPlus Trading Terminal</title>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <!-- Auth CSS -->
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body class="auth-page">

    <div class="auth-wrapper">
        <!-- Brand Header -->
        <a href="login.php" class="auth-brand">
            <div class="auth-brand-logo">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
                    <polyline points="17 6 23 6 23 12"></polyline>
                </svg>
            </div>
            <div class="auth-brand-text">
                Stock<strong>Plus</strong>
                <span class="auth-badge">Register</span>
            </div>
        </a>

        <!-- Main Card -->
        <div class="auth-card">
            <?php if ($successInfo): ?>
                <!-- Registration Success State -->
                <div class="auth-header" style="margin-bottom: 16px;">
                    <div style="font-size: 2.8rem; margin-bottom: 8px;">📬</div>
                    <h1 class="auth-title">Verify Your Email</h1>
                    <p class="auth-subtitle">We have sent a confirmation email to:</p>
                    <p style="color: #60A5FA; font-weight: 700; font-family: var(--font-mono); margin-top: 6px;">
                        <?= htmlspecialchars($successInfo['email']) ?>
                    </p>
                </div>

                <div class="auth-alert auth-alert-success" style="font-size: 0.88rem;">
                    <div>
                        <strong>What's Next?</strong><br>
                        Click the verification link in your email to activate your account and claim your <strong>₹100,000</strong> virtual trading account.
                        <?php if (!empty($successInfo['mailMsg']) && str_contains($successInfo['mailMsg'], 'not configured')): ?>
                            <div style="margin-top: 10px; padding-top: 8px; border-top: 1px solid rgba(16, 185, 129, 0.3); font-size: 0.8rem; color: #FDE68A;">
                                <em>Development Note:</em> Gmail SMTP credentials not yet provided in <code>.env</code>. You can manually verify via database or configure SMTP keys.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="margin-top: 24px; text-align: center;">
                    <a href="login.php" class="btn-auth-submit" style="text-decoration: none;">
                        Return to Sign In
                    </a>
                </div>
            <?php else: ?>
                <!-- Signup Form State -->
                <div class="auth-header">
                    <h1 class="auth-title">Create an Account</h1>
                    <p class="auth-subtitle">Join StockPlus with ₹100,000 in virtual funds</p>
                </div>

                <?php if ($errorMessage): ?>
                    <div class="auth-alert auth-alert-error">
                        <span>⚠️ <?= htmlspecialchars($errorMessage) ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="signup.php" novalidate autocomplete="on">
                    <?= csrf_field() ?>

                    <!-- Full Name -->
                    <div class="form-group">
                        <label for="fullName" class="form-label">Full Name</label>
                        <div class="input-container">
                            <input 
                                type="text" 
                                id="fullName" 
                                name="full_name" 
                                class="auth-input" 
                                placeholder="e.g. Rahul Sharma" 
                                value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" 
                                required 
                                autofocus 
                                autocomplete="name"
                            >
                        </div>
                    </div>

                    <!-- Email Address -->
                    <div class="form-group">
                        <label for="signupEmail" class="form-label">Email Address</label>
                        <div class="input-container">
                            <input 
                                type="email" 
                                id="signupEmail" 
                                name="email" 
                                class="auth-input" 
                                placeholder="name@example.com" 
                                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" 
                                required 
                                autocomplete="email"
                            >
                        </div>
                    </div>

                    <!-- Password with Toggle -->
                    <div class="form-group">
                        <label for="signupPassword" class="form-label">Password</label>
                        <div class="input-container">
                            <input 
                                type="password" 
                                id="signupPassword" 
                                name="password" 
                                class="auth-input input-with-toggle" 
                                placeholder="Min. 8 chars (A-Z, a-z, 0-9, symbol)" 
                                required 
                                autocomplete="new-password"
                            >
                            <button type="button" class="btn-toggle-password" data-target="signupPassword" aria-label="Show password">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>

                        <!-- Live Password Strength Meter -->
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

                    <!-- Confirm Password with Toggle -->
                    <div class="form-group">
                        <label for="confirmPassword" class="form-label">Confirm Password</label>
                        <div class="input-container">
                            <input 
                                type="password" 
                                id="confirmPassword" 
                                name="confirm_password" 
                                class="auth-input input-with-toggle" 
                                placeholder="Re-enter your password" 
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

                    <!-- Terms & Conditions Checkbox -->
                    <div class="form-group" style="margin-bottom: 24px;">
                        <label class="checkbox-label" style="align-items: flex-start;">
                            <input type="checkbox" name="terms_agreed" value="1" <?= !empty($_POST['terms_agreed']) ? 'checked' : '' ?> required style="margin-top: 3px;">
                            <span style="font-size: 0.85rem; line-height: 1.45;">
                                I agree to the <a href="#termsModal" onclick="alert('StockPlus is strictly a paper-trading simulation platform with zero real financial risk.'); return false;" class="auth-link">Terms &amp; Conditions</a> and understand this system uses simulated virtual funds with Yahoo Finance market data.
                            </span>
                        </label>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" class="btn-auth-submit">
                        <span>Create Trading Account</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                            <polyline points="12 5 19 12 12 19"></polyline>
                        </svg>
                    </button>
                </form>

                <!-- Link to Login -->
                <div class="auth-footer">
                    Already have an account? 
                    <a href="login.php" class="auth-link">Sign In</a>
                </div>
            <?php endif; ?>
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

