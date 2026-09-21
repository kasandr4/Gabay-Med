<?php
// reset-password.php
// Step 2 of "Forgot password?": enter the 6-digit code that forgot-password.php
// emailed, then choose a new password.
//
// The code must belong to the account the person typed, be unused, unexpired,
// and have fewer than RESET_MAX_WRONG_GUESSES wrong guesses against it (after
// that it is voided and they must request a new one) - that limit is what
// keeps a 6-digit code safe from guessing. Once used, the code and every other
// unused code for that account are voided. A successful reset also clears any
// failed-login lockout. It does NOT log the person in - they go back to the
// sign-in page and log in with the new password.
//
// Password rules match register.php exactly (includes/password_reset.php).

session_start();
require_once 'config/db.php';
require_once 'includes/csrf.php';
require_once 'includes/mailer.php';
require_once 'includes/audit_log.php';
require_once 'includes/password_reset.php';

$errors = [];

$notice = $_SESSION['reset_notice'] ?? '';
unset($_SESSION['reset_notice']);

if (!empty($_SESSION['csrf_flash'])) {
    $errors[] = $_SESSION['csrf_flash']['message'];
    unset($_SESSION['csrf_flash']);
}

// What the person typed in step 1 (mobile or email) - kept in the session so
// step 2 can pre-fill it without ever revealing what's stored on an account.
$identifier = $_SESSION['reset_identifier'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_csrf('reset-password.php');

    $identifier = trim($_POST['identifier'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $_SESSION['reset_identifier'] = $identifier;

    // Blunt brute-force throttle per browser session; the real protection is
    // the per-code wrong-guess limit below.
    $_SESSION['reset_tries'] = array_filter($_SESSION['reset_tries'] ?? [], function ($t) {
        return $t > time() - 600;
    });
    if (count($_SESSION['reset_tries']) >= 10) {
        $errors[] = "Too many attempts. Please wait a few minutes and try again.";
    }

    if (empty($errors)) {
        $_SESSION['reset_tries'][] = time();

        if ($identifier === '') {
            $errors[] = "Please enter your mobile number or email.";
        }
        if (!preg_match('/^[0-9]{6}$/', $code)) {
            $errors[] = "Please enter the 6-digit code sent to your email.";
        }
        if ($password === '') {
            $errors[] = "Password is required.";
        } else {
            $issues = password_rule_issues($password);
            if (!empty($issues)) {
                $errors[] = "Password must contain " . implode(", ", $issues) . ".";
            }
        }
        if ($password !== $confirm) {
            $errors[] = "Passwords do not match.";
        }
    }

    if (empty($errors)) {
        $user = find_resettable_user($conn, $identifier);
        $row = null;

        if ($user) {
            $userId = (int) $user['user_id'];
            $maxGuesses = RESET_MAX_WRONG_GUESSES;
            $stmt = $conn->prepare(
                "SELECT reset_id, token_hash FROM password_resets
                 WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW() AND attempts < $maxGuesses
                 ORDER BY reset_id DESC LIMIT 1"
            );
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        if (!$row || !password_verify($code, $row['token_hash'])) {
            if ($row) {
                // Wrong guess against a live code: count it, and void the code on the last one.
                $rid = (int) $row['reset_id'];
                $maxGuesses = RESET_MAX_WRONG_GUESSES;
                $stmt = $conn->prepare("UPDATE password_resets SET attempts = attempts + 1 WHERE reset_id = ?");
                $stmt->bind_param("i", $rid);
                $stmt->execute();
                $stmt->close();
                $stmt = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE reset_id = ? AND used_at IS NULL AND attempts >= $maxGuesses");
                $stmt->bind_param("i", $rid);
                $stmt->execute();
                $stmt->close();
            }
            $errors[] = "The code is invalid or has expired. Please request a new one.";
        } else {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $resetId = (int) $row['reset_id'];
            $maxGuesses = RESET_MAX_WRONG_GUESSES;

            $conn->begin_transaction();
            // Claim the code first: only one request can flip used_at from NULL,
            // so a double-click or second tab can't reuse it.
            $stmt = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE reset_id = ? AND used_at IS NULL AND attempts < $maxGuesses");
            $stmt->bind_param("i", $resetId);
            $stmt->execute();
            $claimed = $stmt->affected_rows === 1;
            $stmt->close();

            if (!$claimed) {
                $conn->rollback();
                $errors[] = "The code is invalid or has expired. Please request a new one.";
            } else {
                $stmt = $conn->prepare("UPDATE users SET password = ?, failed_attempts = 0, lockout_until = NULL WHERE user_id = ?");
                $stmt->bind_param("si", $hashed, $userId);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $stmt->close();
                $conn->commit();

                write_audit_log($conn, $userId, $user['role'], 'password_reset_completed', 'auth', 'Password changed using an emailed 6-digit code.');

                if (filter_var($user['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                    $name = htmlspecialchars($user['first_name']);
                    send_email(
                        $user['email'],
                        $user['first_name'],
                        'Your GabayMed password was changed',
                        "<p>Hi {$name},</p><p>Your GabayMed password was just changed. If this wasn't you, please contact the hospital administrator right away.</p><p>- GabayMed</p>"
                    );
                }

                unset($_SESSION['reset_identifier'], $_SESSION['reset_tries']);
                $_SESSION['login_flash'] = ['message' => 'Your password has been reset. You can now sign in with your new password.'];
                header('Location: login.php');
                exit;
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
    <title>Reset Password - GabayMed</title>
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

        <div class="auth-brand-panel">
            <div class="auth-logo">
                <img src="logo-icon.png" alt="GabayMed logo">
                GabayMed
            </div>

            <h1 class="auth-headline">Choose a new <span>password.</span></h1>
            <p class="auth-subtext">
                Enter the 6-digit code we emailed you, then pick a password you
                haven't used before.
            </p>
        </div>

        <div class="auth-form-panel">
            <a href="forgot-password.php" class="auth-back-link">&larr; Back</a>

            <div class="auth-form-container">

                <h2 class="auth-title">Reset password</h2>
                <p class="auth-description">Enter the 6-digit code we sent, then choose a new password.</p>

                <?php if ($identifier !== ''): ?>
                    <p style="margin: 0 0 16px;">
                        <span style="display:inline-block;padding:6px 14px;border-radius:999px;background:rgba(11,143,172,0.10);color:var(--primary);font-size:13px;font-weight:600;"><?= htmlspecialchars($identifier) ?></span>
                    </p>
                <?php endif; ?>

                <?php if ($notice !== ''): ?>
                    <div class="success-banner"><?= htmlspecialchars($notice) ?></div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="error-banner">
                        <?php foreach ($errors as $error): ?>
                            <div><?= htmlspecialchars($error) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="reset-password.php" autocomplete="off">
                    <?= csrf_field() ?>

                    <div class="form-group">
                        <label for="identifier">Mobile Number or Email</label>
                        <input type="text" id="identifier" name="identifier" placeholder="09171234567 or you@gmail.com"
                            value="<?= htmlspecialchars($identifier) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="code">6-digit verification code</label>
                        <input type="text" id="code" name="code" placeholder="e.g., 123456" inputmode="numeric"
                            pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required <?= $identifier !== '' ? 'autofocus' : '' ?>>
                    </div>

                    <div class="form-group">
                        <label for="password">New password</label>
                        <div class="password-field-wrapper">
                            <input type="password" id="password" name="password" placeholder="Create a strong password"
                                oninput="checkStrength(this.value)" required>
                            <button type="button" class="toggle-password" onclick="togglePassword('password', this)" aria-label="Show password">
                                <svg class="eye-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                    <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2" />
                                </svg>
                            </button>
                        </div>
                        <div style="height:5px;border-radius:3px;background:#E5E7EB;margin-top:8px;overflow:hidden;">
                            <div id="strengthBar" style="height:100%;width:0;transition:width .2s,background .2s;"></div>
                        </div>
                        <p id="strengthLabel" style="font-size:12px;margin:4px 0 0;color:var(--text-muted);">
                            At least 8 characters with an uppercase letter, a number and a special character.
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm new password</label>
                        <div class="password-field-wrapper">
                            <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter your new password" required>
                            <button type="button" class="toggle-password" onclick="togglePassword('confirm_password', this)" aria-label="Show password">
                                <svg class="eye-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                    <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary">Reset password</button>
                </form>

                <?php if ($identifier !== ''): ?>
                    <form method="POST" action="forgot-password.php" id="resend-form" style="text-align:center;margin-top:16px;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="identifier" value="<?= htmlspecialchars($identifier) ?>">
                        <span style="font-size:14px;color:var(--text-muted);">Didn't get the code?</span>
                        <button type="submit" id="resend-btn" style="background:none;border:none;padding:0;margin-left:4px;font:inherit;font-size:14px;font-weight:600;color:var(--primary);cursor:pointer;">Resend code</button>
                    </form>
                <?php endif; ?>

                <p class="auth-footer-text">Remembered it? <a href="login.php">Sign in</a></p>

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

        // Same four rules the server enforces (and register.php uses).
        function checkStrength(val) {
            let score = 0;
            if (val.length >= 8) score++;
            if (/[A-Z]/.test(val)) score++;
            if (/[0-9]/.test(val)) score++;
            if (/[^A-Za-z0-9]/.test(val)) score++;
            const levels = [{
                    pct: '0%',
                    color: 'transparent',
                    text: 'At least 8 characters with an uppercase letter, a number and a special character.'
                },
                {
                    pct: '25%',
                    color: '#EF4444',
                    text: 'Weak'
                },
                {
                    pct: '50%',
                    color: '#F97316',
                    text: 'Fair'
                },
                {
                    pct: '75%',
                    color: '#EAB308',
                    text: 'Good'
                },
                {
                    pct: '100%',
                    color: '#22C55E',
                    text: 'Strong'
                }
            ];
            const lvl = val.length === 0 ? levels[0] : levels[score];
            document.getElementById('strengthBar').style.width = lvl.pct;
            document.getElementById('strengthBar').style.background = lvl.color;
            document.getElementById('strengthLabel').textContent = lvl.text;
        }
    </script>
</body>

</html>