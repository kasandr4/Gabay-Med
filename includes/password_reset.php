<?php
// includes/password_reset.php
// Small helpers shared by forgot-password.php (step 1: send the code) and
// reset-password.php (step 2: enter the code + new password).

const RESET_CODE_MINUTES = 10;
const RESET_MAX_REQUESTS_PER_HOUR = 5;
const RESET_MAX_WRONG_GUESSES = 5;

/**
 * Finds the active, password-holding account the person typed: an email if it
 * contains "@", otherwise a mobile number (the login ID). Returns the row or null.
 */
function find_resettable_user(mysqli $conn, string $identifier): ?array
{
    if ($identifier === '') {
        return null;
    }
    $column = strpos($identifier, '@') !== false ? 'email' : 'phone_number';
    $stmt = $conn->prepare(
        "SELECT user_id, role, first_name, email FROM users
         WHERE {$column} = ? AND is_active = 1 AND account_status = 'active' AND password IS NOT NULL
         LIMIT 1"
    );
    $stmt->bind_param("s", $identifier);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $user ?: null;
}

/**
 * Same password rules as register.php. Returns the list of unmet requirements
 * (empty array = password is fine).
 */
function password_rule_issues(string $password): array
{
    $issues = [];
    if (strlen($password) < 8) {
        $issues[] = "at least 8 characters";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $issues[] = "one uppercase letter";
    }
    if (!preg_match('/[0-9]/', $password)) {
        $issues[] = "one number";
    }
    if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        $issues[] = "one special character (e.g. @, #, !)";
    }
    return $issues;
}
