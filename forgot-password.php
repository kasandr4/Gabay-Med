<?php
// forgot-password.php
// Step 1 of "Forgot password?": the person enters the mobile number (their
// login ID) or the email on their account. If that account has an email on
// file, a 6-digit code is sent through Gmail (includes/mailer.php), then they
// are taken to reset-password.php to enter the code and a new password.
//
// The person always lands on step 2 with the same message whether or not an
// account matched, so this page can't be used to find out which numbers or
// emails are registered. Email is optional at registration, so accounts with
// no email on file can't use this - they need the hospital administrator.
//
// The code: 6 digits, only a bcrypt hash is stored (see 030_password_reset_codes.sql),
// valid RESET_CODE_MINUTES, single use. Asking for a new code voids the earlier
// one, and each account is limited to RESET_MAX_REQUESTS_PER_HOUR codes per hour (and each browser to 3 requests per 10 minutes, with a visible message)
// so this can't be used to spam someone's inbox.
//
// DEV MODE: if Gmail isn't working yet, add APP_DEBUG_SHOW_CODE=true to .env and
// the code is shown on screen whenever sending fails, so the rest of the flow
// can still be tested. NEVER leave that on in production.

session_start();
require_once 'config/db.php';
require_once 'includes/csrf.php';
require_once 'includes/mailer.php';
require_once 'includes/audit_log.php';
require_once 'includes/password_reset.php';

$errors = [];

if (!empty($_SESSION['csrf_flash'])) {
    $errors[] = $_SESSION['csrf_flash']['message'];
    unset($_SESSION['csrf_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_csrf('forgot-password.php');

    $identifier = trim($_POST['identifier'] ?? '');

    // Visible per-browser throttle: at most 3 code requests every 10 minutes.
    // This is the limit people will actually run into while testing, so it says
    // so out loud - and because it counts requests, not accounts, it reveals
    // nothing about which numbers/emails are registered.
    $_SESSION['reset_sends'] = array_values(array_filter($_SESSION['reset_sends'] ?? [], function ($t) {
        return $t > time() - 600;
    }));
    $throttled = count($_SESSION['reset_sends']) >= 3;

    if ($identifier === '') {
        $errors[] = "Please enter your mobile number or email.";
    } elseif (strlen($identifier) > 100) {
        $errors[] = "That doesn't look right. Please check and try again.";
    } elseif ($throttled) {
        $wait = max(1, (int) ceil(($_SESSION['reset_sends'][0] + 600 - time()) / 60));
        $errors[] = "You've requested a code several times just now. Please wait about {$wait} minute(s) and try again - or use the last code we sent you.";
    } else {
        $_SESSION['reset_sends'][] = time();
        $notice = 'If that account exists and has an email address on file, a 6-digit code has been sent. It expires in '
            . RESET_CODE_MINUTES . ' minutes. Check your spam folder if you don\'t see it.';
        $devNote = '';

        $user = find_resettable_user($conn, $identifier);

        if (!$user) {
            $devNote = 'no active account matches that mobile number/email.';
        } elseif (!filter_var($user['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $devNote = 'that account has no valid email address on file.';
        }

        if ($user && filter_var($user['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $userId = (int) $user['user_id'];

            $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM password_resets WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $recent = (int) $stmt->get_result()->fetch_assoc()['n'];
            $stmt->close();

            if ($recent >= RESET_MAX_REQUESTS_PER_HOUR) {
                $devNote = 'this account already had ' . RESET_MAX_REQUESTS_PER_HOUR . ' codes in the last hour, so nothing was sent.';
            }

            if ($recent < RESET_MAX_REQUESTS_PER_HOUR) {
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $codeHash = password_hash($code, PASSWORD_BCRYPT);
                $ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
                $minutes = RESET_CODE_MINUTES;

                $conn->begin_transaction();
                // A new code voids any earlier one that hasn't been used.
                $stmt = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at, requested_ip) VALUES (?, ?, NOW() + INTERVAL $minutes MINUTE, ?)");
                $stmt->bind_param("iss", $userId, $codeHash, $ip);
                $stmt->execute();
                $stmt->close();
                $conn->commit();

                $name = htmlspecialchars($user['first_name']);
                $body = "<p>Hi {$name},</p>"
                    . "<p>Your GabayMed password reset code is:</p>"
                    . "<p style=\"font-size:30px;font-weight:bold;letter-spacing:6px;font-family:monospace;\">{$code}</p>"
                    . "<p>This code expires in {$minutes} minutes and can only be used once.</p>"
                    . "<p>If you didn't ask for this, you can ignore this email - your password won't change.</p>"
                    . "<p>- GabayMed</p>";

                $ok = send_email($user['email'], $user['first_name'], 'Your GabayMed verification code', $body);
                if (!$ok) {
                    error_log("forgot-password.php: reset code email to user {$userId} could not be sent (see the mailer.php line above).");
                    if (getenv('APP_DEBUG_SHOW_CODE') === 'true') {
                        // mailer_last_error() comes from the updated includes/mailer.php; the
                        // guard keeps this page working even with the older mailer.
                        $why = function_exists('mailer_last_error') ? mailer_last_error() : '';
                        $notice = "Email sending failed (DEV MODE)" . ($why !== '' ? " - reason: {$why}" : '')
                            . " - your code is: {$code}";
                    }
                }

                write_audit_log($conn, $userId, $user['role'], 'password_reset_requested', 'auth', $ok ? 'Reset code emailed.' : 'Reset code could not be emailed.');
            }
        }

        // DEV MODE only: say out loud why nothing was sent (this reveals whether an
        // account exists, so it must never be on in production).
        if ($devNote !== '' && getenv('APP_DEBUG_SHOW_CODE') === 'true') {
            $notice = "Nothing was emailed (DEV MODE): {$devNote}";
        }

        // Same destination and message whether or not anything was sent.
        $_SESSION['reset_identifier'] = $identifier;
        $_SESSION['reset_notice'] = $notice;
        header('Location: reset-password.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - GabayMed</title>
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

            <h1 class="auth-headline">Locked out? <span>We'll get you back in.</span></h1>
            <p class="auth-subtext">
                Enter your mobile number or email and we'll send a 6-digit code
                to the email address on your account.
            </p>
        </div>

        <div class="auth-form-panel">
            <a href="login.php" class="auth-back-link">&larr; Back to Sign In</a>

            <div class="auth-form-container">

                <h2 class="auth-title">Forgot your password?</h2>
                <p class="auth-description">We'll email you a 6-digit code to choose a new one.</p>

                <?php if (!empty($errors)): ?>
                    <div class="error-banner">
                        <?php foreach ($errors as $error): ?>
                            <div><?= htmlspecialchars($error) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="forgot-password.php">
                    <?= csrf_field() ?>

                    <div class="form-group">
                        <label for="identifier">Mobile Number or Email</label>
                        <input type="text" id="identifier" name="identifier" placeholder="09171234567 or you@gmail.com"
                            value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>" required autofocus>
                    </div>

                    <button type="submit" class="btn-primary">Send Verification Code</button>
                </form>

                <p class="auth-footer-text">Already have a code? <a href="reset-password.php">Enter it here</a></p>
                <p class="auth-footer-text" style="margin-top: 8px;">Remembered it? <a href="login.php">Sign in</a></p>

            </div>
        </div>

    </div>

    <script src="assets/js/page-transitions.js"></script>
</body>

</html>