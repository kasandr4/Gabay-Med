<?php
// register.php
// Public registration page — PATIENTS ONLY.
// Doctors and Admins/Pharmacists are created later by an Admin from inside the system.

require_once 'config/db.php';

$errors = [];
$success = false;

// This block only runs when the form is submitted (POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

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

    // Step 3: If no errors so far, check the database for duplicates
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE phone_number = ?");
        $stmt->bind_param("s", $phone_number);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $errors[] = "An account with that mobile number already exists.";
        }
        $stmt->close();
    }

    // Also check email for duplicates, but only if one was provided
    if (empty($errors) && $email !== '') {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $errors[] = "An account with that email already exists.";
        }
        $stmt->close();
    }

    // Step 4: If everything checks out, insert the new patient
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

        $stmt = $conn->prepare("
            INSERT INTO users
                (role, phone_number, password, email, first_name, last_name, birthdate, philhealth_id, sex, address)
            VALUES
                ('patient', ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "sssssssss",
            $phone_number,
            $hashed_password,
            $email_value,
            $first_name,
            $last_name,
            $birthdate_value,
            $philhealth_value,
            $sex_value,
            $address_value
        );

        if ($stmt->execute()) {
            $success = true;
        } else {
            $errors[] = "Something went wrong while creating your account. Please try again.";
        }
        $stmt->close();
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
            <a href="index.html" class="auth-back-link">&larr; Back to Home</a>

            <div class="auth-form-container">

                <?php if ($success): ?>

                    <h2 class="auth-title">Account created!</h2>
                    <div class="success-banner">
                        Your patient account has been created successfully.
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