<?php
// admin/user-management-actions.php
// JSON action endpoint backing the User Management module. Same pattern
// as admin/doctor-schedule-actions.php: single action-routed endpoint,
// CSRF-checked, prepared statements only.

require_once '../includes/auth_guard.php';
require_role('admin');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once 'includes/user-management-data.php';

header('Content-Type: application/json');

// Belt-and-suspenders: PHP 8.1+ makes mysqli throw exceptions on query
// errors by default, and this file has no try/catch around any of its
// queries. Left alone, an uncaught exception (or any other fatal error)
// here ends the request with a 500 and an EMPTY body - res.json() on the
// JS side throws on that, and postAction()'s catch() reports it as a
// generic "Network error", hiding whatever actually went wrong. These two
// handlers guarantee this endpoint always answers with real JSON, even on
// a failure nobody anticipated.
set_exception_handler(function (Throwable $e) {
    error_log('user-management-actions.php: ' . $e->getMessage());
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'error' => 'Something went wrong on our end. Please try again.']);
    exit;
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('user-management-actions.php fatal: ' . $error['message']);
        http_response_code(500);
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['success' => false, 'error' => 'Something went wrong on our end. Please try again.']);
    }
});

function respond($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Invalid request method.'], 405);
}

$submittedToken = $_POST['csrf_token'] ?? '';
$expectedToken = $_SESSION['csrf_token'] ?? '';
if ($expectedToken === '' || !hash_equals($expectedToken, $submittedToken)) {
    respond(['success' => false, 'error' => 'Your session expired. Please refresh the page and try again.'], 403);
}

$action = $_POST['action'] ?? '';
$adminId = (int) $_SESSION['user_id'];
$validRoles = ['patient', 'doctor', 'pharmacist', 'admin', 'staff', 'capitol'];
$validStatuses = ['active', 'blocked', 'confined', 'deceased'];

/**
 * Shared validation for create/update: required names, role, phone
 * format, email format if provided. Returns an array of error strings
 * (empty = valid).
 */
function validateUserFields(string $firstName, string $lastName, string $phone, string $role, ?string $email): array
{
    global $validRoles;
    $errors = [];

    if ($firstName === '' || $lastName === '') {
        $errors[] = "Please provide a first and last name.";
    }
    if (!in_array($role, $validRoles, true)) {
        $errors[] = "Please choose a valid role.";
    }
    if (!preg_match('/^[0-9+\-\s]{7,20}$/', $phone)) {
        $errors[] = "Please provide a valid phone number.";
    }
    if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please provide a valid email address, or leave it blank.";
    }

    return $errors;
}

/** Errors if phone/email is already used by a DIFFERENT user (or any user, on create). */
function findDuplicateUser(mysqli $conn, string $phone, ?string $email, ?int $excludeUserId): array
{
    $errors = [];

    $sql = "SELECT user_id FROM users WHERE phone_number = ?" . ($excludeUserId ? " AND user_id != ?" : "");
    $stmt = $conn->prepare($sql);
    if ($excludeUserId) {
        $stmt->bind_param("si", $phone, $excludeUserId);
    } else {
        $stmt->bind_param("s", $phone);
    }
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $errors[] = "A user with that phone number already exists.";
    }
    $stmt->close();

    if ($email !== null) {
        $sql = "SELECT user_id FROM users WHERE email = ?" . ($excludeUserId ? " AND user_id != ?" : "");
        $stmt = $conn->prepare($sql);
        if ($excludeUserId) {
            $stmt->bind_param("si", $email, $excludeUserId);
        } else {
            $stmt->bind_param("s", $email);
        }
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            $errors[] = "A user with that email already exists.";
        }
        $stmt->close();
    }

    return $errors;
}

switch ($action) {

    // ------------------------------------------------------------
    // Create a new user account
    // ------------------------------------------------------------
    case 'create_user': {
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $emailRaw = trim($_POST['email'] ?? '');
            $email = $emailRaw === '' ? null : $emailRaw;
            $role = $_POST['role'] ?? '';
            $departmentId = ((int) ($_POST['department_id'] ?? 0)) ?: null;
            // Only meaningful for role='staff' - see 013_staff_subtype.sql
            // and 025_lab_order_queue_and_lab_staff_type.sql (the third,
            // 'laboratory' value).
            // Defaults to front_desk for any non-staff role/missing value so
            // the column never silently ends up NULL for a staff account.
            $staffTypeRaw = $_POST['staff_type'] ?? '';
            $staffType = ($role === 'staff' && in_array($staffTypeRaw, ['front_desk', 'inventory', 'laboratory'], true)) ? $staffTypeRaw : ($role === 'staff' ? 'front_desk' : null);
            $sexRaw = $_POST['sex'] ?? '';
            $sex = in_array($sexRaw, ['male', 'female'], true) ? $sexRaw : null;
            $birthdateRaw = trim($_POST['birthdate'] ?? '');
            $birthdate = $birthdateRaw === '' ? null : $birthdateRaw;
            $addressRaw = trim($_POST['address'] ?? '');
            $address = $addressRaw === '' ? null : $addressRaw;
            $password = (string) ($_POST['password'] ?? '');

            $errors = validateUserFields($firstName, $lastName, $phone, $role, $email);
            if (strlen($password) < 6) {
                $errors[] = "Password must be at least 6 characters.";
            }
            if (!empty($errors)) {
                respond(['success' => false, 'error' => implode(' ', $errors)], 422);
            }

            $dupErrors = findDuplicateUser($conn, $phone, $email, null);
            if (!empty($dupErrors)) {
                respond(['success' => false, 'error' => implode(' ', $dupErrors)], 409);
            }

            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            $stmt = $conn->prepare(
                "INSERT INTO users
                    (role, staff_type, department_id, phone_number, password, email, first_name, last_name, sex, birthdate, address, status, is_active, account_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 1, 'active')"
            );
            // Params: role(s), staff_type(s), department_id(i), phone(s), password(s),
            // email(s), first_name(s), last_name(s), sex(s), birthdate(s), address(s) = 11
            $stmt->bind_param(
                "ssissssssss",
                $role,
                $staffType,
                $departmentId,
                $phone,
                $hashedPassword,
                $email,
                $firstName,
                $lastName,
                $sex,
                $birthdate,
                $address
            );
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();

            respond(['success' => true, 'message' => 'User created.', 'user_id' => $newId]);
        }

        // ------------------------------------------------------------
        // Edit an existing user's profile fields (not password/status -
        // those are separate actions below)
        // ------------------------------------------------------------
    case 'update_user': {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $existing = um_fetch_user($conn, $userId);
            if (!$existing) {
                respond(['success' => false, 'error' => 'User not found.'], 404);
            }

            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $emailRaw = trim($_POST['email'] ?? '');
            $email = $emailRaw === '' ? null : $emailRaw;
            $role = $_POST['role'] ?? '';
            $departmentId = ((int) ($_POST['department_id'] ?? 0)) ?: null;
            // Only meaningful for role='staff' - see 013_staff_subtype.sql
            // and 025_lab_order_queue_and_lab_staff_type.sql.
            $staffTypeRaw = $_POST['staff_type'] ?? '';
            $staffType = ($role === 'staff' && in_array($staffTypeRaw, ['front_desk', 'inventory', 'laboratory'], true)) ? $staffTypeRaw : ($role === 'staff' ? 'front_desk' : null);
            $sexRaw = $_POST['sex'] ?? '';
            $sex = in_array($sexRaw, ['male', 'female'], true) ? $sexRaw : null;
            $birthdateRaw = trim($_POST['birthdate'] ?? '');
            $birthdate = $birthdateRaw === '' ? null : $birthdateRaw;
            $addressRaw = trim($_POST['address'] ?? '');
            $address = $addressRaw === '' ? null : $addressRaw;

            $errors = validateUserFields($firstName, $lastName, $phone, $role, $email);
            if (!empty($errors)) {
                respond(['success' => false, 'error' => implode(' ', $errors)], 422);
            }

            $dupErrors = findDuplicateUser($conn, $phone, $email, $userId);
            if (!empty($dupErrors)) {
                respond(['success' => false, 'error' => implode(' ', $dupErrors)], 409);
            }

            $stmt = $conn->prepare(
                "UPDATE users
                 SET first_name = ?, last_name = ?, phone_number = ?, email = ?, role = ?, staff_type = ?,
                     department_id = ?, sex = ?, birthdate = ?, address = ?
                 WHERE user_id = ?"
            );
            // Params: first_name(s), last_name(s), phone(s), email(s), role(s), staff_type(s),
            // department_id(i), sex(s), birthdate(s), address(s), user_id(i) = 11
            $stmt->bind_param(
                "ssssssisssi",
                $firstName,
                $lastName,
                $phone,
                $email,
                $role,
                $staffType,
                $departmentId,
                $sex,
                $birthdate,
                $address,
                $userId
            );
            $stmt->execute();
            $stmt->close();

            respond(['success' => true, 'message' => 'User updated.']);
        }

        // ------------------------------------------------------------
        // Deactivate / Reactivate (flips is_active - the real login-gate
        // flag; see is_active check in login.php)
        // ------------------------------------------------------------
    case 'toggle_active': {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $existing = um_fetch_user($conn, $userId);
            if (!$existing) {
                respond(['success' => false, 'error' => 'User not found.'], 404);
            }
            if ($userId === $adminId) {
                respond(['success' => false, 'error' => "You can't deactivate your own account."], 422);
            }

            $newIsActive = $existing['is_active'] ? 0 : 1;
            $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE user_id = ?");
            $stmt->bind_param("ii", $newIsActive, $userId);
            $stmt->execute();
            $stmt->close();

            respond(['success' => true, 'message' => $newIsActive ? 'User reactivated.' : 'User deactivated.', 'is_active' => $newIsActive]);
        }

        // ------------------------------------------------------------
        // Block / Unblock (sets the `status` column - separate from
        // is_active; a blocked user is still "active" in the account
        // sense, just flagged, e.g. for repeated missed appointments)
        // ------------------------------------------------------------
    case 'set_status': {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            $existing = um_fetch_user($conn, $userId);
            if (!$existing) {
                respond(['success' => false, 'error' => 'User not found.'], 404);
            }
            if (!in_array($status, $validStatuses, true)) {
                respond(['success' => false, 'error' => 'Please choose a valid status.'], 422);
            }
            if ($userId === $adminId && $status !== 'active') {
                respond(['success' => false, 'error' => "You can't change your own account's status."], 422);
            }

            $stmt = $conn->prepare("UPDATE users SET status = ? WHERE user_id = ?");
            $stmt->bind_param("si", $status, $userId);
            $stmt->execute();
            $stmt->close();

            respond(['success' => true, 'message' => 'Status updated.']);
        }

        // ------------------------------------------------------------
        // Reset password: generates a new random temporary password and
        // returns it once in this response so the admin can relay it to
        // the user directly (real accounts log in by phone number, and
        // email is optional/often unset, so an emailed reset link isn't a
        // reliable path here - a front-desk-relayed temp password is).
        // ------------------------------------------------------------
    case 'reset_password': {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $existing = um_fetch_user($conn, $userId);
            if (!$existing) {
                respond(['success' => false, 'error' => 'User not found.'], 404);
            }

            $tempPassword = substr(bin2hex(random_bytes(6)), 0, 10);
            $hashedPassword = password_hash($tempPassword, PASSWORD_BCRYPT);

            $stmt = $conn->prepare("UPDATE users SET password = ?, failed_attempts = 0, lockout_until = NULL WHERE user_id = ?");
            $stmt->bind_param("si", $hashedPassword, $userId);
            $stmt->execute();
            $stmt->close();

            respond(['success' => true, 'message' => 'Password reset.', 'temp_password' => $tempPassword]);
        }

        // ------------------------------------------------------------
        // Archive a user - NOT a real DELETE. Sets archived_at instead,
        // which excludes them from the normal list (um_fetch_users()) while
        // keeping the row and everything that references it (appointments,
        // prescriptions, etc.) fully intact. Deleting user records outright
        // isn't something this system does.
        // ------------------------------------------------------------
    case 'archive_user': {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $existing = um_fetch_user($conn, $userId);
            if (!$existing) {
                respond(['success' => false, 'error' => 'User not found.'], 404);
            }
            if ($userId === $adminId) {
                respond(['success' => false, 'error' => "You can't archive your own account."], 422);
            }
            if (!empty($existing['archived_at'])) {
                respond(['success' => false, 'error' => 'This user is already archived.'], 422);
            }

            $stmt = $conn->prepare("UPDATE users SET archived_at = NOW() WHERE user_id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $stmt->close();

            respond(['success' => true, 'message' => 'User archived.']);
        }

        // ------------------------------------------------------------
        // Restore a previously archived user back into the normal list.
        // ------------------------------------------------------------
    case 'restore_user': {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $existing = um_fetch_user($conn, $userId);
            if (!$existing) {
                respond(['success' => false, 'error' => 'User not found.'], 404);
            }
            if (empty($existing['archived_at'])) {
                respond(['success' => false, 'error' => 'This user is not archived.'], 422);
            }

            $stmt = $conn->prepare("UPDATE users SET archived_at = NULL WHERE user_id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $stmt->close();

            respond(['success' => true, 'message' => 'User restored.']);
        }

    default:
        respond(['success' => false, 'error' => 'Unknown action.'], 400);
}
