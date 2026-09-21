<?php
require_once '../includes/auth_guard.php';
require_role('patient');
require_once '../config/db.php';
require_active_patient($conn);
require_once '../includes/csrf.php';

$active_page = 'profile';

// ─── AJAX: Handle profile update (POST via fetch) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    // Discard any accidental output (warnings, whitespace, stray echoes from includes)
    // so the response body is guaranteed to be pure JSON.
    if (ob_get_level()) {
        ob_clean();
    }
    ob_start();
    header('Content-Type: application/json');

    // This is a JSON endpoint, not a normal form redirect, so we verify the
    // token inline (rather than require_csrf(), which redirects) and fail
    // with JSON - matching what profile.js's fetch() call expects back.
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $submittedToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Your session expired. Please refresh the page and try again.']);
        exit;
    }

    $user_id       = $_SESSION['user_id'];
    $first_name    = trim($_POST['first_name'] ?? '');
    $last_name     = trim($_POST['last_name'] ?? '');
    $phone_number  = trim($_POST['phone_number'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $birthdate     = trim($_POST['birthdate'] ?? '');
    $sex           = trim($_POST['sex'] ?? '');
    $philhealth_id = trim($_POST['philhealth_id'] ?? '');
    $address       = trim($_POST['address'] ?? '');

    // ── Medical & Emergency Information ──
    $blood_type               = trim($_POST['blood_type'] ?? '');
    $civil_status              = trim($_POST['civil_status'] ?? '');
    $emergency_contact_name   = trim($_POST['emergency_contact_name'] ?? '');
    $emergency_contact_number = trim($_POST['emergency_contact_number'] ?? '');

    // Basic validation
    $errors = [];
    if ($first_name === '') $errors[] = 'First name is required.';
    if ($last_name === '')  $errors[] = 'Last name is required.';
    if ($phone_number === '') $errors[] = 'Phone number is required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($sex !== '' && !in_array($sex, ['male', 'female'], true)) {
        $errors[] = 'Please select a valid option for sex.';
    }
    if ($blood_type !== '' && !in_array($blood_type, ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'Unknown'], true)) {
        $errors[] = 'Please select a valid blood type.';
    }
    if ($civil_status !== '' && !in_array($civil_status, ['Single', 'Married', 'Widowed', 'Divorced', 'Separated'], true)) {
        $errors[] = 'Please select a valid civil status.';
    }
    if ($emergency_contact_number !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $emergency_contact_number)) {
        $errors[] = 'Please enter a valid emergency contact number.';
    }

    if (!empty($errors)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        exit;
    }

    // ── users: personal info only (medical/emergency fields live in patient_profiles) ──
    // phone_number and email both have real UNIQUE constraints (login is
    // by phone_number system-wide). Wrapped in try/catch because this
    // environment's mysqli throws mysqli_sql_exception on a constraint
    // violation (PHP 8.1+ default, confirmed live in this project by an
    // earlier "Table doesn't exist" crash reaching the browser as a raw
    // fatal error) rather than having execute() just return false - the
    // $usersOk/$profileOk checks further down were written as if the
    // latter were still true, so they'd never even be reached for this
    // specific failure; the exception would crash the request first.
    $usersOk = false;
    try {
        $stmt = $conn->prepare("
            UPDATE users
            SET
                first_name = ?,
                last_name = ?,
                phone_number = ?,
                email = ?,
                birthdate = ?,
                sex = ?,
                philhealth_id = ?,
                address = ?
            WHERE user_id = ?
        ");
        $stmt->bind_param(
            "ssssssssi",
            $first_name,
            $last_name,
            $phone_number,
            $email,
            $birthdate,
            $sex,
            $philhealth_id,
            $address,
            $user_id
        );
        $usersOk = $stmt->execute();
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() === 1062) {
            ob_end_clean();
            $dupField = (strpos($e->getMessage(), 'email') !== false) ? 'email address' : 'phone number';
            echo json_encode(['success' => false, 'message' => "That {$dupField} is already registered to another account."]);
            exit;
        }
        // Some other constraint/DB error - not something the patient can
        // fix by changing their input, so fall through to the generic
        // "something went wrong" message below rather than exposing
        // database internals.
    }

    // ── patient_profiles: upsert (creates the row automatically the first
    // time this patient saves, updates it on every save after that) ──
    $stmt = $conn->prepare("
        INSERT INTO patient_profiles
            (patient_id, blood_type, civil_status, emergency_contact_name, emergency_contact_number)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            blood_type = VALUES(blood_type),
            civil_status = VALUES(civil_status),
            emergency_contact_name = VALUES(emergency_contact_name),
            emergency_contact_number = VALUES(emergency_contact_number)
    ");
    $stmt->bind_param(
        "issss",
        $user_id,
        $blood_type,
        $civil_status,
        $emergency_contact_name,
        $emergency_contact_number
    );
    $profileOk = $stmt->execute();
    $stmt->close();

    if ($usersOk && $profileOk) {
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'data' => [
                'first_name'    => htmlspecialchars($first_name),
                'last_name'     => htmlspecialchars($last_name),
                'phone_number'  => htmlspecialchars($phone_number),
                'email'         => htmlspecialchars($email),
                'birthdate'     => htmlspecialchars($birthdate),
                'sex'           => htmlspecialchars($sex),
                'philhealth_id' => htmlspecialchars($philhealth_id),
                'address'       => htmlspecialchars($address),
                'blood_type'               => htmlspecialchars($blood_type),
                'civil_status'             => htmlspecialchars($civil_status),
                'emergency_contact_name'   => htmlspecialchars($emergency_contact_name),
                'emergency_contact_number' => htmlspecialchars($emergency_contact_number),
                'initials'      => strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1)),
                'full_name'     => htmlspecialchars(trim($first_name . ' ' . $last_name)),
            ]
        ]);
    } else {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Something went wrong while saving. Please try again.']);
    }

    exit;
}

// ─── Normal page load: pull patient's current info ─────────────────────────
$stmt = $conn->prepare("
    SELECT u.first_name, u.last_name, u.phone_number, u.email, u.birthdate, u.sex, u.address, u.philhealth_id,
           pp.blood_type, pp.civil_status, pp.emergency_contact_name, pp.emergency_contact_number
    FROM users u
    LEFT JOIN patient_profiles pp ON pp.patient_id = u.user_id
    WHERE u.user_id = ?
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$patient = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Helper: return value for input fields
function val(string $value = null): string
{
    return htmlspecialchars(trim($value ?? ''));
}

// Helper: return value or styled "Not provided" for display spans
function displayVal(string $value = null): string
{
    $v = trim($value ?? '');
    return $v === '' ? '<span class="empty">Not provided</span>' : htmlspecialchars($v);
}

// Generate initials for avatar (up to 2 characters)
$initials = strtoupper(
    substr($patient['first_name'], 0, 1) .
        substr($patient['last_name'],  0, 1)
);

// Full name
$full_name = htmlspecialchars(trim($patient['first_name'] . ' ' . $patient['last_name']));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/profile.css">
</head>

<body>
    <div class="app-layout">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">

            <div class="page-header">
                <h1 class="page-title">My Profile</h1>
                <p class="page-subtitle">Your personal information on record.</p>
            </div>

            <!-- Toast notification for save feedback -->
            <div id="profileToast" class="profile-toast" role="status" aria-live="polite"></div>

            <div class="profile-wrapper">

                <!-- ── Header Card ────────────────────────────────────────── -->
                <div class="profile-card profile-header-card">
                    <div class="profile-avatar" id="profileAvatar" aria-hidden="true">
                        <?= htmlspecialchars($initials) ?>
                    </div>
                    <div class="profile-header-info">
                        <p class="profile-header-name" id="profileFullName"><?= $full_name ?></p>
                        <p class="profile-header-role">Patient</p>
                        <p class="profile-header-location" id="profileHeaderAddress">
                            <?= displayVal($patient['address']) ?>
                        </p>
                    </div>
                </div>

                <!-- ── Personal Information Card (editable form) ─────────── -->
                <form id="profileForm" class="profile-card profile-section-card" autocomplete="off">
                    <?= csrf_field() ?>

                    <div class="profile-section-header">
                        <h2 class="profile-section-title">Personal Information</h2>
                        <div class="profile-actions">
                            <button type="button" id="editBtn" class="btn btn-edit">
                                Edit Profile
                            </button>
                            <button type="submit" id="saveBtn" class="btn btn-save" hidden>
                                Save Changes
                            </button>
                            <button type="button" id="cancelBtn" class="btn btn-cancel" hidden>
                                Cancel
                            </button>
                        </div>
                    </div>

                    <div class="profile-info-grid">

                        <div class="profile-info-item">
                            <label class="profile-info-label" for="first_name">First Name</label>
                            <input
                                type="text"
                                id="first_name"
                                name="first_name"
                                class="profile-input"
                                value="<?= val($patient['first_name']) ?>"
                                disabled>
                        </div>

                        <div class="profile-info-item">
                            <label class="profile-info-label" for="last_name">Last Name</label>
                            <input
                                type="text"
                                id="last_name"
                                name="last_name"
                                class="profile-input"
                                value="<?= val($patient['last_name']) ?>"
                                disabled>
                        </div>

                        <div class="profile-info-item">
                            <label class="profile-info-label" for="birthdate">Date of Birth</label>
                            <input
                                type="date"
                                id="birthdate"
                                name="birthdate"
                                class="profile-input"
                                value="<?= val($patient['birthdate']) ?>"
                                disabled>
                        </div>

                        <div class="profile-info-item">
                            <label class="profile-info-label" for="email">Email Address</label>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                class="profile-input"
                                value="<?= val($patient['email']) ?>"
                                disabled>
                        </div>

                        <div class="profile-info-item">
                            <label class="profile-info-label" for="phone_number">Phone Number</label>
                            <input
                                type="text"
                                id="phone_number"
                                name="phone_number"
                                class="profile-input"
                                value="<?= val($patient['phone_number']) ?>"
                                disabled>
                        </div>

                        <div class="profile-info-item">
                            <label class="profile-info-label" for="sex">Sex</label>
                            <select id="sex" name="sex" class="profile-input" disabled>
                                <option value="" <?= val($patient['sex']) === '' ? 'selected' : '' ?>>Select</option>
                                <option value="male" <?= val($patient['sex']) === 'male' ? 'selected' : '' ?>>Male</option>
                                <option value="female" <?= val($patient['sex']) === 'female' ? 'selected' : '' ?>>Female</option>
                            </select>
                        </div>

                        <div class="profile-info-item">
                            <label class="profile-info-label" for="philhealth_id">PhilHealth ID</label>
                            <input
                                type="text"
                                id="philhealth_id"
                                name="philhealth_id"
                                class="profile-input"
                                value="<?= val($patient['philhealth_id']) ?>"
                                disabled>
                        </div>

                    </div>
                </form>

                <!-- ── Address Card (editable, tied to same form via JS) ──── -->
                <div class="profile-card profile-section-card">
                    <h2 class="profile-section-title">Address</h2>
                    <div class="profile-info-item">
                        <label class="profile-info-label" for="address">Full Address</label>
                        <textarea
                            id="address"
                            name="address"
                            form="profileForm"
                            class="profile-input profile-textarea"
                            rows="2"
                            disabled><?= val($patient['address']) ?></textarea>
                    </div>
                </div>

                <!-- ── Medical & Emergency Information Card (editable, tied to same form) ── -->
                <div class="profile-card profile-section-card">
                    <h2 class="profile-section-title">Medical &amp; Emergency Information</h2>
                    <div class="profile-info-grid">

                        <div class="profile-info-item">
                            <label class="profile-info-label" for="blood_type">Blood Type</label>
                            <select id="blood_type" name="blood_type" form="profileForm" class="profile-input" disabled>
                                <option value="" <?= val($patient['blood_type']) === '' ? 'selected' : '' ?>>Select</option>
                                <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'Unknown'] as $bt): ?>
                                    <option value="<?= $bt ?>" <?= val($patient['blood_type']) === $bt ? 'selected' : '' ?>><?= $bt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="profile-info-item">
                            <label class="profile-info-label" for="civil_status">Civil Status</label>
                            <select id="civil_status" name="civil_status" form="profileForm" class="profile-input" disabled>
                                <option value="" <?= val($patient['civil_status']) === '' ? 'selected' : '' ?>>Select</option>
                                <?php foreach (['Single', 'Married', 'Widowed', 'Divorced', 'Separated'] as $cs): ?>
                                    <option value="<?= $cs ?>" <?= val($patient['civil_status']) === $cs ? 'selected' : '' ?>><?= $cs ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="profile-info-item">
                            <label class="profile-info-label" for="emergency_contact_name">Emergency Contact Name</label>
                            <input
                                type="text"
                                id="emergency_contact_name"
                                name="emergency_contact_name"
                                form="profileForm"
                                class="profile-input"
                                value="<?= val($patient['emergency_contact_name']) ?>"
                                disabled>
                        </div>

                        <div class="profile-info-item">
                            <label class="profile-info-label" for="emergency_contact_number">Emergency Contact Number</label>
                            <input
                                type="text"
                                id="emergency_contact_number"
                                name="emergency_contact_number"
                                form="profileForm"
                                class="profile-input"
                                value="<?= val($patient['emergency_contact_number']) ?>"
                                disabled>
                        </div>

                    </div>
                </div>

            </div><!-- /.profile-wrapper -->
        </main>
    </div>

    <script src="../assets/js/profile.js"></script>
</body>

</html>