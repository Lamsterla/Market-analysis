/**
 * StockPlus - Authentication UI Scripts
 * Handles password visibility toggles, strength calculations, and validation
 */

document.addEventListener('DOMContentLoaded', () => {
    initPasswordToggles();
    initPasswordStrength();
    initConfirmPasswordCheck();
});

/**
 * Password Visibility Toggle
 */
function initPasswordToggles() {
    document.querySelectorAll('.btn-toggle-password').forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.getAttribute('data-target');
            const input = document.getElementById(targetId);
            if (!input) return;

            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';

            // Update SVG icon
            if (isPassword) {
                btn.innerHTML = `
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                        <line x1="1" y1="1" x2="23" y2="23"></line>
                    </svg>`;
                btn.setAttribute('aria-label', 'Hide password');
            } else {
                btn.innerHTML = `
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>`;
                btn.setAttribute('aria-label', 'Show password');
            }
        });
    });
}

/**
 * Dynamic Password Strength Meter
 */
function initPasswordStrength() {
    const passwordInput = document.getElementById('signupPassword');
    const strengthText = document.getElementById('strengthText');
    const segments = document.querySelectorAll('.strength-segment');

    if (!passwordInput || !strengthText || segments.length === 0) return;

    passwordInput.addEventListener('input', () => {
        const val = passwordInput.value;
        if (!val) {
            segments.forEach(s => s.style.backgroundColor = 'transparent');
            strengthText.textContent = '';
            return;
        }

        let score = 0;
        if (val.length >= 8) score++;
        if (/[A-Z]/.test(val)) score++;
        if (/[a-z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[\W_]/.test(val)) score++;

        const colors = ['#EF4444', '#F97316', '#FBBF24', '#34D399', '#10B981'];
        const labels = ['Very Weak', 'Weak', 'Fair', 'Good', 'Strong'];

        const activeCount = Math.min(score, 4);
        const color = colors[activeCount];

        segments.forEach((seg, idx) => {
            seg.style.backgroundColor = idx <= activeCount ? color : 'transparent';
        });

        strengthText.textContent = labels[activeCount];
        strengthText.style.color = color;
    });
}

/**
 * Real-time Password Confirmation Check
 */
function initConfirmPasswordCheck() {
    const passInput = document.getElementById('signupPassword');
    const confirmInput = document.getElementById('confirmPassword');
    const matchHint = document.getElementById('passwordMatchHint');

    if (!passInput || !confirmInput || !matchHint) return;

    function checkMatch() {
        if (!confirmInput.value) {
            matchHint.textContent = '';
            return;
        }

        if (passInput.value === confirmInput.value) {
            matchHint.textContent = '✓ Passwords match';
            matchHint.style.color = '#10B981';
        } else {
            matchHint.textContent = '✕ Passwords do not match';
            matchHint.style.color = '#EF4444';
        }
    }

    passInput.addEventListener('input', checkMatch);
    confirmInput.addEventListener('input', checkMatch);
}

