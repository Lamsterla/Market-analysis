<?php
/**
 * StockPlus - Forgot Password Page
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (auth_is_logged_in()) {
    header('Location: index.php');
    exit;
}

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $csrfToken = (string)($_POST['csrf_token'] ?? '');

    if (!verify_csrf_token($csrfToken)) {
        $error = 'Security validation failed (CSRF).';
    } else {
        $res = auth_request_password_reset($email);
        if ($res['success']) {
            $message = $res['message'];
        } else {
            $error = $res['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password &bull; StockPlus Trading Terminal</title>
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
                <span class="auth-badge">Recovery</span>
            </div>
        </a>

        <div class="auth-card">
            <div class="auth-header">
                <h1 class="auth-title">Password Recovery</h1>
                <p class="auth-subtitle">Enter your email and we'll send you a link to reset your password</p>
            </div>

            <?php if ($message): ?>
                <div class="auth-alert auth-alert-success">
                    <span>✓ <?= htmlspecialchars($message) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="auth-alert auth-alert-error">
                    <span>⚠️ <?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="forgot_password.php" novalidate>
                <?= csrf_field() ?>

                <div class="form-group">
                    <label for="recoveryEmail" class="form-label">Email Address</label>
                    <input 
                        type="email" 
                        id="recoveryEmail" 
                        name="email" 
                        class="auth-input" 
                        placeholder="name@example.com" 
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" 
                        required 
                        autofocus
                    >
                </div>

                <button type="submit" class="btn-auth-submit">
                    <span>Send Reset Instructions</span>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="22" y1="2" x2="11" y2="13"></line>
                        <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                    </svg>
                </button>
            </form>

            <div class="auth-footer">
                Remembered your password? 
                <a href="login.php" class="auth-link">Back to Sign In</a>
            </div>
        </div>

        <div class="auth-legal-footer">
            StockPlus &bull; Simulated Paper Trading System
        </div>
    </div>

</body>
</html>

