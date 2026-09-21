<?php
// register.php
// Public registration page — PATIENTS ONLY.
// Doctors and Admins/Pharmacists are created later by an Admin from inside the system.

// Needed for CSRF (added 2026-08-08, see below) - this page never called
// it before since it's otherwise a no-login page with nothing else to
// track in a session. Must run before any HTML output, same requirement
// as includes/auth_guard.php's own session_start(). Without this, the
// token embedded in the form on page load and the token read back on
// submission belong to two disconnected, non-persisted sessions, so
// EVERY submission - not just forged ones - would fail csrf_token()'s
// check, since $_SESSION['csrf_token'] would be empty again on each
// request.
session_start();

require_once 'config/db.php';
require_once 'includes/csrf.php';

$errors = [];
$success = false;
$linked_existing_record = false;

if (!empty($_SESSION['csrf_flash'])) {
    $errors[] = $_SESSION['csrf_flash']['message'];
    unset($_SESSION['csrf_flash']);
}

// This block only runs when the form is submitted (POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF (settled 2026-08-08): registration was missing this entirely,
    // despite includes/csrf.php's own doc comment saying it's "checked on
    // every state-changing POST" - creating a new users row is
    // unambiguously that. Not a classic session-riding CSRF (registration
    // needs no prior session to exploit), but a real, narrower risk
    // remains: a hidden auto-submitting form on another site could
    // silently register an account under a VICTIM's real phone number
    // with a password the attacker chose, before the real owner ever
    // gets to register with their own number. Redirects back to this
    // same page rather than a generic error page, matching how the rest
    // of this form already re-renders with $errors on any failure.
    require_csrf('register.php');

    // Step 1: Collect and clean up the submitted values
    // trim() removes accidental leading/trailing spaces
    $first_name   = trim($_POST['first_name'] ?? '');
    $last_name    = trim($_POST['last_name'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $birthdate    = trim($_POST['birthdate'] ?? '');
    $philhealth_id = trim($_POST['philhealth_id'] ?? '');
    $sex          = trim($_POST['sex'] ?? '');
    $address      = trim($_POST['address'] ?? '');
    $password     = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Self-declared at signup — not verified against an ID here. A real
    // priority lane still needs a verification step at check-in; this
    // just captures the declaration so it exists somewhere. Falls back
    // to 'regular' for anything unexpected instead of erroring, since
    // this is an optional field.
    $allowed_priority_types = ['regular', 'senior', 'pwd', 'ip'];
    $priority_type = trim($_POST['priority_type'] ?? 'regular');
    if (!in_array($priority_type, $allowed_priority_types, true)) {
        $priority_type = 'regular';
    }

    // Step 2: Validate required fields
    if ($first_name === '') {
        $errors[] = "First name is required.";
    }
    if ($last_name === '') {
        $errors[] = "Last name is required.";
    }

    // Philippine mobile numbers are typically 10 digits after the +63,
    // or 11 digits if written starting with 0 (e.g. 09171234567).
    if ($phone_number === '') {
        $errors[] = "Mobile number is required.";
    } elseif (!preg_match('/^(09\d{9}|\+639\d{9})$/', $phone_number)) {
        $errors[] = "Enter a valid PH mobile number (e.g. 09171234567).";
    }

    // Email is OPTIONAL, but if they typed something, it must look like an email
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "That email address doesn't look valid.";
    }

    if ($password === '') {
        $errors[] = "Password is required.";
    } else {
        // Build up a list of what's missing, so the user knows exactly what to fix
        $password_issues = [];

        if (strlen($password) < 8) {
            $password_issues[] = "at least 8 characters";
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $password_issues[] = "one uppercase letter";
        }
        if (!preg_match('/[0-9]/', $password)) {
            $password_issues[] = "one number";
        }
        // Special character = anything that isn't a letter or number
        if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
            $password_issues[] = "one special character (e.g. @, #, !)";
        }

        if (!empty($password_issues)) {
            $errors[] = "Password must contain " . implode(", ", $password_issues) . ".";
        }
    }

    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }

    // Step 3: If no errors so far, check the database for an existing
    // record under this phone number. Two different outcomes here:
    //   - account_status = 'active'  -> a real account already exists, block it as before.
    //   - account_status = 'guest'   -> this is a hospital record staff created
    //     during a walk-in/registration visit, with no online account yet.
    //     Instead of rejecting it as a duplicate, we link this signup to
    //     that same record below (Step 4) rather than creating a new patient.
    $existing_guest_id = null;
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT user_id, account_status FROM users WHERE phone_number = ?");
        $stmt->bind_param("s", $phone_number);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            if ($existing['account_status'] === 'guest') {
                $existing_guest_id = (int) $existing['user_id'];
            } else {
                $errors[] = "An account with that mobile number already exists.";
            }
        }
    }

    // Also check email for duplicates, but only if one was provided, and
    // only against OTHER users — not the guest record we're about to link.
    if (empty($errors) && $email !== '') {
        $exclude_id = $existing_guest_id ?? 0;
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $stmt->bind_param("si", $email, $exclude_id);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $errors[] = "An account with that email already exists.";
        }
        $stmt->close();
    }

    // Step 4: If everything checks out, either link the existing guest
    // record or insert a brand-new patient.
    if (empty($errors)) {
        // NEVER store plain-text passwords. password_hash() scrambles it
        // using a one-way algorithm (bcrypt) that can't be reversed.
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);

        // Convert empty optional fields to NULL so they save cleanly
        $email_value      = $email !== '' ? $email : null;
        $birthdate_value  = $birthdate !== '' ? $birthdate : null;
        $philhealth_value = $philhealth_id !== '' ? $philhealth_id : null;
        $sex_value        = $sex !== '' ? $sex : null;
        $address_value    = $address !== '' ? $address : null;

        // Both branches below are wrapped in try/catch for the same reason
        // as patient/profile.php's update handler: this environment's
        // mysqli throws mysqli_sql_exception on a duplicate-key violation
        // (PHP 8.1+ default, no mysqli_report() override in config/db.php)
        // rather than having execute() return false - the plain if/else
        // below was written as if the latter were still true. The
        // pre-checks above (phone_number, email) don't fully close this:
        // two submissions racing each other - including something as
        // ordinary as double-clicking "Create Account", not just a
        // contrived attack - can both pass the pre-check before either
        // INSERT/UPDATE actually lands, especially here where
        // password_hash()'s deliberate ~100-300ms BCRYPT cost widens that
        // window more than a typical fast query would.
        if ($existing_guest_id !== null) {
            // Link to the existing hospital record instead of creating a
            // duplicate patient. First/last name are left untouched since
            // that record may already be tied to real appointments/consultations
            // under the name staff entered — only fields that are still
            // empty on the existing record get filled in from this form.
            // The re-check on account_status = 'guest' guards against a
            // race where the same guest activates twice at once.
            try {
                $stmt = $conn->prepare("
                    UPDATE users
                    SET password = ?,
                        email = COALESCE(email, ?),
                        birthdate = COALESCE(birthdate, ?),
                        philhealth_id = COALESCE(philhealth_id, ?),
                        sex = COALESCE(sex, ?),
                        address = COALESCE(address, ?),
                        priority_type = ?,
                        account_status = 'active'
                    WHERE user_id = ? AND account_status = 'guest'
                ");
                $stmt->bind_param(
                    "sssssssi",
                    $hashed_password,
                    $email_value,
                    $birthdate_value,
                    $philhealth_value,
                    $sex_value,
                    $address_value,
                    $priority_type,
                    $existing_guest_id
                );
                $stmt->execute();
                $linked = $stmt->affected_rows === 1;
                $stmt->close();

                if ($linked) {
                    $success = true;
                    $linked_existing_record = true;
                } else {
                    $errors[] = "That hospital record was just updated elsewhere. Please try again.";
                }
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() === 1062) {
                    $errors[] = "An account with that email already exists.";
                } else {
                    $errors[] = "Something went wrong while creating your account. Please try again.";
                }
            }
        } else {
            try {
                $stmt = $conn->prepare("
                    INSERT INTO users
                        (role, phone_number, password, email, first_name, last_name, birthdate, philhealth_id, sex, address, priority_type)
                    VALUES
                        ('patient', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    "ssssssssss",
                    $phone_number,
                    $hashed_password,
                    $email_value,
                    $first_name,
                    $last_name,
                    $birthdate_value,
                    $philhealth_value,
                    $sex_value,
                    $address_value,
                    $priority_type
                );

                if ($stmt->execute()) {
                    $success = true;
                } else {
                    $errors[] = "Something went wrong while creating your account. Please try again.";
                }
                $stmt->close();
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() === 1062) {
                    $dupField = (strpos($e->getMessage(), 'email') !== false) ? 'email address' : 'mobile number';
                    $errors[] = "An account with that {$dupField} already exists.";
                } else {
                    $errors[] = "Something went wrong while creating your account. Please try again.";
                }
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
    <title>Create an Account - GabayMed</title>
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
                Register as a patient to book appointments, view your medical records,
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

                <?php if ($success): ?>

                    <h2 class="auth-title">Account created!</h2>
                    <div class="success-banner">
                        <?php if ($linked_existing_record): ?>
                            We found an existing hospital record under this phone number and connected it to your new online account.
                        <?php else: ?>
                            Your patient account has been created successfully.
                        <?php endif; ?>
                    </div>
                    <a href="login.php" class="btn-primary" style="display:block; text-align:center; text-decoration:none; line-height:1.4;">
                        Continue to Sign In
                    </a>

                <?php else: ?>

                    <h2 class="auth-title">Create an Account</h2>
                    <p class="auth-description">Enter your details to register as a new patient.</p>

                    <?php if (!empty($errors)): ?>
                        <div class="error-banner">
                            <?php foreach ($errors as $error): ?>
                                <div><?= htmlspecialchars($error) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="register.php">
                        <?= csrf_field() ?>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="first_name">First Name</label>
                                <input type="text" id="first_name" name="first_name" placeholder="Juan"
                                    value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="last_name">Last Name</label>
                                <input type="text" id="last_name" name="last_name" placeholder="Dela Cruz"
                                    value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="phone_number">Mobile Number</label>
                            <input type="tel" id="phone_number" name="phone_number" placeholder="09171234567"
                                value="<?= htmlspecialchars($_POST['phone_number'] ?? '') ?>" required>
                            <div class="input-hint">This will be your login ID. Format: 09XXXXXXXXX</div>
                        </div>

                        <div class="form-group">
                            <label for="email">Email <span style="color:#A8B5B5; font-weight:400;">(optional)</span></label>
                            <input type="email" id="email" name="email" placeholder="juan@example.com"
                                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="birthdate">Date of Birth</label>
                                <input type="date" id="birthdate" name="birthdate"
                                    value="<?= htmlspecialchars($_POST['birthdate'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label for="sex">Sex</label>
                                <select id="sex" name="sex">
                                    <option value="">Select</option>
                                    <option value="male" <?= (($_POST['sex'] ?? '') === 'male') ? 'selected' : '' ?>>Male</option>
                                    <option value="female" <?= (($_POST['sex'] ?? '') === 'female') ? 'selected' : '' ?>>Female</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="philhealth_id">PhiHealth ID <span style="color:#A8B5B5; font-weight:400;">(optional)</span></label>
                            <input type="text" id="philhealth_id" name="philhealth_id" placeholder="ID Number"
                                value="<?= htmlspecialchars($_POST['philhealth_id'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label for="address">Address <span style="color:#A8B5B5; font-weight:400;">(optional)</span></label>
                            <input type="text" id="address" name="address" placeholder="Street, Barangay, City"
                                value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label for="priority_type">Priority Lane <span style="color:#A8B5B5; font-weight:400;">(optional)</span></label>
                            <select id="priority_type" name="priority_type">
                                <option value="regular" <?= (($_POST['priority_type'] ?? 'regular') === 'regular') ? 'selected' : '' ?>>Regular</option>
                                <option value="senior" <?= (($_POST['priority_type'] ?? '') === 'senior') ? 'selected' : '' ?>>Senior Citizen</option>
                                <option value="pwd" <?= (($_POST['priority_type'] ?? '') === 'pwd') ? 'selected' : '' ?>>PWD</option>
                                <option value="ip" <?= (($_POST['priority_type'] ?? '') === 'ip') ? 'selected' : '' ?>>Indigenous Peoples (IP)</option>
                            </select>
                            <span style="color:#A8B5B5; font-weight:400; font-size:12.5px;">Verified against ID at your first in-person check-in — this just tells the front desk what to check for.</span>
                        </div>

                        <div class="form-group">
                            <label for="password">Password</label>
                            <div class="password-field-wrapper">
                                <input type="password" id="password" name="password" placeholder="At least 8 characters" required
                                    oninput="checkPasswordStrength()">
                                <button type="button" class="toggle-password" onclick="togglePassword('password', this)" aria-label="Show password">
                                    <svg class="eye-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                        <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2" />
                                    </svg>
                                </button>
                            </div>

                            <ul class="password-checklist" id="password-checklist">
                                <li id="check-length">At least 8 characters</li>
                                <li id="check-upper">One uppercase letter</li>
                                <li id="check-number">One number</li>
                                <li id="check-special">One special character (e.g. @, #, !)</li>
                            </ul>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm Password</label>
                            <div class="password-field-wrapper">
                                <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter your password" required>
                                <button type="button" class="toggle-password" onclick="togglePassword('confirm_password', this)" aria-label="Show password">
                                    <svg class="eye-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                        <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="btn-primary">Complete Registration</button>
                    </form>

                    <p class="auth-footer-text">Already have an account? <a href="login.php">Sign In</a></p>

                <?php endif; ?>

            </div>
        </div>

    </div>

    <script src="assets/js/page-transitions.js"></script>
    <script>
        // Toggles a password field between hidden (••••) and visible (plain text)
        function togglePassword(fieldId, button) {
            const field = document.getElementById(fieldId);
            const isHidden = field.type === 'password';
            field.type = isHidden ? 'text' : 'password';
            button.classList.toggle('is-visible', isHidden);
            button.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        }

        // Live-checks the password as the user types and ticks off each requirement
        function checkPasswordStrength() {
            const password = document.getElementById('password').value;

            const rules = {
                'check-length': password.length >= 8,
                'check-upper': /[A-Z]/.test(password),
                'check-number': /[0-9]/.test(password),
                'check-special': /[^a-zA-Z0-9]/.test(password)
            };

            for (const [id, passed] of Object.entries(rules)) {
                document.getElementById(id).classList.toggle('valid', passed);
            }
        }
    </script>

</body>

</html>