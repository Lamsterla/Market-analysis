<?php
/**
 * StockPlus - 6-Digit OTP Email Verification Interface
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

auth_start_session();

$email = trim((string)($_GET['email'] ?? ($_SESSION['verify_email'] ?? '')));
$rawToken = trim((string)($_GET['token'] ?? ''));

$status = null;
$message = null;
$error = null;
unset($_SESSION['dev_otp']);

// Backward Compatibility: If someone clicks a legacy token link
if ($rawToken !== '') {
    $tokenResult = auth_verify_email($rawToken);
    if ($tokenResult['success']) {
        $status = 'verified';
        $message = $tokenResult['message'];
    } else {
        $error = $tokenResult['error'];
    }
}

// Handle Form Actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'verify');
    $csrfToken = (string)($_POST['csrf_token'] ?? '');
    $email = strtolower(trim((string)($_POST['email'] ?? $email)));

    if (!verify_csrf_token($csrfToken)) {
        $error = 'Security validation failed (CSRF). Please refresh and try again.';
    } elseif ($action === 'verify') {
        // Collect 6-digit OTP from combined field or separate digit boxes
        $otp = trim((string)($_POST['otp'] ?? ''));
        if ($otp === '' && isset($_POST['otp_digit'])) {
            $digits = (array)$_POST['otp_digit'];
            $otp = implode('', array_slice($digits, 0, 6));
        }

        $verifyResult = auth_verify_otp($email, $otp);
        if ($verifyResult['success']) {
            $status = 'verified';
            $message = $verifyResult['message'];
            unset($_SESSION['dev_otp'], $_SESSION['verify_email']);
        } else {
            $error = $verifyResult['error'];
        }
    } elseif ($action === 'resend') {
        $resendResult = auth_resend_otp($email);
        if ($resendResult['success']) {
            $message = $resendResult['message'];
        } else {
            $error = $resendResult['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification &bull; StockPlus Trading Terminal</title>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600;700;800&display=swap" rel="stylesheet">
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
                <span class="auth-badge">Verification</span>
            </div>
        </a>

        <!-- Main Card Container -->
        <div class="auth-card">

            <?php if ($status === 'verified'): ?>
                <!-- Verified Successfully State -->
                <div class="auth-header">
                    <div style="font-size: 3rem; margin-bottom: 10px;">🎉</div>
                    <h1 class="auth-title">Account Verified!</h1>
                    <p class="auth-subtitle"><?= htmlspecialchars($message ?? 'Your email has been verified successfully.') ?></p>
                </div>

                <div class="auth-alert auth-alert-success">
                    <span>✓ Virtual trading account active: <strong>₹100,000</strong> has been credited to your portfolio.</span>
                </div>

                <div style="margin-top: 24px;">
                    <a href="index.php" class="btn-auth-submit" style="text-decoration: none;">
                        <span>Enter Trading Dashboard</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                            <polyline points="12 5 19 12 12 19"></polyline>
                        </svg>
                    </a>
                </div>

            <?php else: ?>
                <!-- OTP Verification State -->
                <div class="auth-header">
                    <div style="font-size: 2.6rem; margin-bottom: 8px;">🔐</div>
                    <h1 class="auth-title">Enter Verification Code</h1>
                    <p class="auth-subtitle">
                        <?php if (!empty($email)): ?>
                            We have sent a 6-digit OTP code to<br>
                            <span class="auth-highlight-email">
                                <?= htmlspecialchars($email) ?>
                            </span>
                        <?php else: ?>
                            Enter your registered email and the 6-digit OTP sent to your inbox.
                        <?php endif; ?>
                    </p>
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

                <form method="POST" action="verify_email.php" id="otpForm" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="verify">

                    <?php if (empty($email)): ?>
                        <div class="form-group" style="margin-bottom: 16px;">
                            <label for="emailInput" class="form-label">Email Address</label>
                            <input 
                                type="email" 
                                id="emailInput" 
                                name="email" 
                                class="auth-input" 
                                placeholder="name@example.com" 
                                required
                            >
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                    <?php endif; ?>

                    <label class="form-label" style="text-align: center; display: block;">6-Digit OTP Code</label>

                    <!-- 6 Digit Input Boxes (Always blank for user input) -->
                    <div class="otp-container">
                        <input type="text" class="otp-digit-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autofocus data-index="0" value="">
                        <input type="text" class="otp-digit-input" maxlength="1" pattern="[0-9]" inputmode="numeric" data-index="1" value="">
                        <input type="text" class="otp-digit-input" maxlength="1" pattern="[0-9]" inputmode="numeric" data-index="2" value="">
                        <input type="text" class="otp-digit-input" maxlength="1" pattern="[0-9]" inputmode="numeric" data-index="3" value="">
                        <input type="text" class="otp-digit-input" maxlength="1" pattern="[0-9]" inputmode="numeric" data-index="4" value="">
                        <input type="text" class="otp-digit-input" maxlength="1" pattern="[0-9]" inputmode="numeric" data-index="5" value="">
                    </div>

                    <!-- Hidden combined OTP field submitted with form -->
                    <input type="hidden" name="otp" id="combinedOtp" value="">

                    <button type="submit" class="btn-auth-submit" id="verifyBtn">
                        <span>Verify & Activate Account</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                    </button>
                </form>

                <!-- Resend OTP Code Form -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--auth-border);">
                    <span style="font-size: 0.88rem; color: var(--auth-text-secondary);">
                        Didn't receive the code?
                    </span>
                    <form method="POST" action="verify_email.php" style="display: inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="resend">
                        <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                        <button type="submit" class="auth-link" style="background: none; border: none; padding: 0; cursor: pointer; font-size: 0.88rem; font-weight: 700;">
                            Resend Code
                        </button>
                    </form>
                </div>

                <div class="auth-footer" style="margin-top: 16px;">
                    <a href="login.php" class="auth-link" style="color: var(--auth-text-muted); font-size: 0.85rem;">
                        &larr; Return to Sign In
                    </a>
                </div>

            <?php endif; ?>

        </div>
    </div>

    <!-- OTP Input Behavior JavaScript -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const inputs = document.querySelectorAll('.otp-digit-input');
            const hiddenOtp = document.getElementById('combinedOtp');
            const form = document.getElementById('otpForm');

            // Always clear all digit boxes on load so user enters their fresh OTP
            inputs.forEach(input => {
                input.value = '';
                input.classList.remove('filled');
            });
            if (hiddenOtp) hiddenOtp.value = '';
            if (inputs[0]) inputs[0].focus();

            function updateCombinedOtp() {
                let code = '';
                inputs.forEach(input => code += input.value);
                if (hiddenOtp) hiddenOtp.value = code;
                return code;
            }

            inputs.forEach((input, index) => {
                input.addEventListener('input', function (e) {
                    const val = this.value.replace(/[^0-9]/g, '');
                    this.value = val ? val.slice(-1) : '';

                    if (this.value) {
                        this.classList.add('filled');
                        if (index < inputs.length - 1) {
                            inputs[index + 1].focus();
                        }
                    } else {
                        this.classList.remove('filled');
                    }

                    const fullCode = updateCombinedOtp();
                    if (fullCode.length === 6) {
                        // All 6 digits filled
                    }
                });

                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Backspace' && !this.value && index > 0) {
                        inputs[index - 1].focus();
                        inputs[index - 1].value = '';
                        inputs[index - 1].classList.remove('filled');
                        updateCombinedOtp();
                    }
                });

                input.addEventListener('paste', function (e) {
                    e.preventDefault();
                    const pastedData = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
                    if (!pastedData) return;

                    for (let i = 0; i < inputs.length; i++) {
                        if (i < pastedData.length) {
                            inputs[i].value = pastedData[i];
                            inputs[i].classList.add('filled');
                        }
                    }
                    const fullCode = updateCombinedOtp();
                    const focusIndex = Math.min(pastedData.length, inputs.length - 1);
                    inputs[focusIndex].focus();
                });
            });

            if (form) {
                form.addEventListener('submit', function (e) {
                    const code = updateCombinedOtp();
                    if (code.length !== 6) {
                        e.preventDefault();
                        alert('Please enter all 6 digits of your verification code.');
                        const firstEmpty = Array.from(inputs).find(inp => !inp.value);
                        if (firstEmpty) firstEmpty.focus();
                    }
                });
            }
        });
    </script>
</body>
</html>
