<?php
// login.php
// ONE login form for everyone — Patient, Doctor, Admin/Pharmacist.
// After checking the password, we look at the user's `role` column
// and redirect them to the right dashboard.

session_start(); // starts/resumes the session so we can store "who is logged in"
require_once 'config/db.php';
require_once 'includes/csrf.php';
require_once 'includes/audit_log.php';
require_once 'includes/system_settings.php';

// FORMERLY hardcoded literals (3 attempts / 60 seconds) — now
// admin-editable via admin/system-settings.php (system_settings table,
// category 'security').
$max_failed_attempts = get_setting_int($conn, 'login_max_failed_attempts', 3);
$lockout_duration_seconds = get_setting_int($conn, 'login_lockout_seconds', 60);

$errors = [];

$login_success = null;
if (!empty($_SESSION['login_flash'])) {
    $login_success = $_SESSION['login_flash']['message'];
    unset($_SESSION['login_flash']);
}

$lockout_seconds_remaining = 0; // used to drive the JS countdown, 0 = not locked out

if (!empty($_SESSION['csrf_flash'])) {
    $errors[] = $_SESSION['csrf_flash']['message'];
    unset($_SESSION['csrf_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_csrf('login.php');

    $phone_number = trim($_POST['phone_number'] ?? '');
    $password     = $_POST['password'] ?? '';

    if ($phone_number === '') {
        $errors[] = "Mobile number is required.";
    }
    if ($password === '') {
        $errors[] = "Password is required.";
    }

    if (empty($errors)) {
        // Look up the account by phone number
        $stmt = $conn->prepare("SELECT user_id, role, staff_type, password, first_name, last_name, is_active, account_status, failed_attempts, lockout_until FROM users WHERE phone_number = ?");
        $stmt->bind_param("s", $phone_number);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!$user) {
            // Mobile number genuinely not found — safe to say so specifically,
            // since this is no more revealing than "user not found" on most systems.
            $errors[] = "This mobile number is not registered.";
        } elseif ($user['account_status'] === 'guest') {
            // Guest accounts (created by staff for a walk-in patient) have no
            // real password yet - `password` is NULL until the patient signs
            // up themselves via register.php using this same phone number,
            // which links to this record instead of creating a duplicate.
            // Checking this BEFORE password_verify() avoids passing a null
            // hash to it and gives the person a message that actually
            // explains their situation.
            $errors[] = "This account hasn't been set up for online access yet. You can create one at any time using this same mobile number.";
        } elseif ($user['role'] === 'capitol') {
            // Provincial Capitol accounts are retired: purchasing after an
            // approved Purchase Request happens outside GabayMed, so there
            // is no Capitol portal to sign in to.
            $errors[] = "Provincial Capitol accounts are no longer used in GabayMed.";
        } elseif (!$user['is_active']) {
            $errors[] = "This account has been deactivated. Please contact the hospital administrator.";
        } elseif ($user['lockout_until'] !== null && strtotime($user['lockout_until']) > time()) {
            // Account is currently locked out — calculate how many seconds remain
            $seconds_left = strtotime($user['lockout_until']) - time();
            $lockout_seconds_remaining = $seconds_left;
            $errors[] = "Too many failed attempts. Please try again in " . $seconds_left . " seconds.";
        } elseif (!password_verify($password, $user['password'])) {
            // Wrong password on an account that DOES exist.
            // We only ever say "Incorrect password" here — never reveal
            // anything more specific than that.
            $new_attempts = $user['failed_attempts'] + 1;

            if ($new_attempts >= $max_failed_attempts) {
                // Lock the account and reset the counter
                $lockout_time = date('Y-m-d H:i:s', time() + $lockout_duration_seconds);
                $stmt = $conn->prepare("UPDATE users SET failed_attempts = 0, lockout_until = ? WHERE user_id = ?");
                $stmt->bind_param("si", $lockout_time, $user['user_id']);
                $stmt->execute();
                $stmt->close();

                $lockout_seconds_remaining = $lockout_duration_seconds;
                $errors[] = "Too many failed attempts. Please try again in " . $lockout_duration_seconds . " seconds.";
            } else {
                $stmt = $conn->prepare("UPDATE users SET failed_attempts = ? WHERE user_id = ?");
                $stmt->bind_param("ii", $new_attempts, $user['user_id']);
                $stmt->execute();
                $stmt->close();

                $remaining = $max_failed_attempts - $new_attempts;
                $errors[] = "Incorrect password. " . $remaining . " attempt" . ($remaining === 1 ? "" : "s") . " remaining before lockout.";
            }
        } else {
            // SUCCESS — reset failed attempts and store session info
            $stmt = $conn->prepare("UPDATE users SET failed_attempts = 0, lockout_until = NULL WHERE user_id = ?");
            $stmt->bind_param("i", $user['user_id']);
            $stmt->execute();
            $stmt->close();

            // Regenerate the session ID now that the user has proven who they
            // are. This is a privilege change (anonymous -> authenticated),
            // so the session ID issued before login should never remain valid
            // afterward - otherwise a session ID fixed/known before login
            // (e.g. shared via a link, or set before the user ever logged in)
            // would silently become a valid authenticated session once they
            // log in. true = also destroy the old session data on the server.
            session_regenerate_id(true);

            $_SESSION['user_id']    = $user['user_id'];
            $_SESSION['role']       = $user['role'];
            $_SESSION['staff_type'] = $user['staff_type']; // null for every non-staff role, harmless
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name']  = $user['last_name'];

            // Audit trail entry for the admin portal's activity feeds
            // (see includes/audit_log.php). Best-effort and non-blocking,
            // same as every other write_audit_log call in the app.
            write_audit_log($conn, $user['user_id'], $user['role'], 'login', 'auth', 'Signed in.');

            // Redirect based on role to the correct dashboard
            switch ($user['role']) {
                case 'patient':
                    header("Location: patient/dashboard.php");
                    break;
                case 'doctor':
                    header("Location: doctor/dashboard.php");
                    break;
                case 'admin':
                    header("Location: admin/dashboard.php");
                    break;
                case 'pharmacist':
                    header("Location: pharmacist/dashboard.php");
                    break;
                case 'capitol':
                    // New role (2026-09-20) - see
                    // 027_capitol_role_and_po_handoff.sql. No dashboard
                    // page for this portal (it's only ever two things:
                    // review PRs, manage POs) - land straight on the
                    // queue itself rather than an extra landing page
                    // with nothing on it.
                    header("Location: capitol/purchase-requests.php");
                    break;
                case 'staff':
                    // Three unrelated jobs share the 'staff' role now (see
                    // 013_staff_subtype.sql and 025_lab_order_queue.sql for
                    // the newest one) — land each on their own portal's
                    // actual landing page instead of always sending
                    // inventory-counting/lab staff to the front-desk
                    // check-in screen they have no reason to be on.
                    if ($user['staff_type'] === 'inventory') {
                        header("Location: staff/dashboard.php");
                    } elseif ($user['staff_type'] === 'laboratory') {
                        header("Location: staff/lab-queue.php");
                    } else {
                        header("Location: staff/check-in.php");
                    }
                    break;
                default:
                    header("Location: index.php");
            }
            exit; // always exit immediately after a header() redirect
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - GabayMed</title>
    <link rel="stylesheet" href="assets/css/auth.css">
    <noscript>
        <style>
            body {
                opacity: 1 !important;
            }
        </style>
    </noscript>
</head>

<body>

    <div class="auth-wrapper">

        <!-- LEFT: Branding panel -->
        <div class="auth-brand-panel">
            <div class="auth-logo">
                <img src="logo-icon.png" alt="GabayMed logo">
                GabayMed
            </div>

            <h1 class="auth-headline">Your health, <span>guided and secured.</span></h1>
            <p class="auth-subtext">
                Sign in to manage your appointments, view your medical records,
                and stay connected with your care team.
            </p>

            <div class="auth-testimonial">
                <p>"Registering took less than five minutes. I could finally see my appointment history without asking the front desk every time."</p>
                <div class="auth-testimonial-author">
                    <div class="auth-testimonial-avatar"></div>
                    <div>
                        <div class="auth-testimonial-name">Maria Santos</div>
                        <div class="auth-testimonial-role">Patient</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT: Form panel -->
        <div class="auth-form-panel">
            <a href="index.php" class="auth-back-link">&larr; Back to Home</a>

            <div class="auth-form-container">

                <h2 class="auth-title">Welcome back</h2>
                <p class="auth-description">Sign in to access your GabayMed account.</p>

                <?php if (!empty($login_success)): ?>
                    <div class="error-banner" style="border-color: var(--teal, #0B8FAC); background: rgba(11,143,172,0.08); color: var(--teal, #0B8FAC);">
                        <div><?= htmlspecialchars($login_success) ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="error-banner">
                        <?php foreach ($errors as $error): ?>
                            <div>
                                <?php if ($lockout_seconds_remaining > 0 && strpos($error, 'seconds') !== false): ?>
                                    Too many failed attempts. Please try again in <span id="countdown"><?= $lockout_seconds_remaining ?></span> seconds.
                                <?php else: ?>
                                    <?= htmlspecialchars($error) ?>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="login.php" id="login-form">
                    <?= csrf_field() ?>

                    <div class="form-group">
                        <label for="phone_number">Mobile Number</label>
                        <input type="tel" id="phone_number" name="phone_number" placeholder="09171234567"
                            value="<?= htmlspecialchars($_POST['phone_number'] ?? '') ?>" required autofocus
                            <?= $lockout_seconds_remaining > 0 ? 'disabled' : '' ?>>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-field-wrapper">
                            <input type="password" id="password" name="password" placeholder="Enter your password" required
                                <?= $lockout_seconds_remaining > 0 ? 'disabled' : '' ?>>
                            <button type="button" class="toggle-password" onclick="togglePassword('password', this)" aria-label="Show password">
                                <svg class="eye-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                    <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <p style="text-align: right; margin: -6px 0 18px; font-size: 13px;">
                        <a href="forgot-password.php" style="color: var(--primary); font-weight: 600; text-decoration: none;">Forgot password?</a>
                    </p>

                    <button type="submit" class="btn-primary" id="submit-btn" <?= $lockout_seconds_remaining > 0 ? 'disabled' : '' ?>>
                        <?= $lockout_seconds_remaining > 0 ? 'Please wait...' : 'Sign In' ?>
                    </button>
                </form>

                <p class="auth-footer-text">Don't have an account? <a href="register.php">Register here</a></p>

            </div>
        </div>

    </div>

    <script src="assets/js/page-transitions.js"></script>
    <script>
        function togglePassword(fieldId, button) {
            const field = document.getElementById(fieldId);
            const isHidden = field.type === 'password';
            field.type = isHidden ? 'text' : 'password';
            button.classList.toggle('is-visible', isHidden);
            button.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        }

        // Live countdown for the lockout message.
        // PHP tells us how many seconds remain when the page loads;
        // this just ticks it down visually and re-enables the form at 0.
        let secondsLeft = <?= $lockout_seconds_remaining ?>;

        if (secondsLeft > 0) {
            const countdownEl = document.getElementById('countdown');
            const submitBtn = document.getElementById('submit-btn');
            const phoneField = document.getElementById('phone_number');
            const passwordField = document.getElementById('password');

            const timer = setInterval(() => {
                secondsLeft--;

                if (countdownEl) {
                    countdownEl.textContent = secondsLeft;
                }

                if (secondsLeft <= 0) {
                    clearInterval(timer);

                    // Re-enable the form so the user can try again immediately,
                    // without needing to manually refresh the page.
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Sign In';
                    phoneField.disabled = false;
                    passwordField.disabled = false;

                    // Replace the lockout message with a friendlier prompt
                    const banner = document.querySelector('.error-banner');
                    if (banner) {
                        banner.textContent = 'You can now try signing in again.';
                        banner.classList.remove('error-banner');
                        banner.classList.add('success-banner');
                    }
                }
            }, 1000);
        }
    </script>

</body>

</html>