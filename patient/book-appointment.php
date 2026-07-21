<?php
// patient/book-appointment.php
// Implements spec 1.5 — Booking Flow (Step 0 pending-check through Step 5 confirmation).
//
// Uses $_SESSION['booking'] to carry wizard state between steps, since this
// is a multi-step flow. Each step RE-VALIDATES against the database rather
// than trusting whatever is in the session, per the spec's edge-case note
// about browser back/forward navigation.

require_once '../includes/auth_guard.php';
require_role('patient');
require_once '../config/db.php';
require_once 'includes/department_matcher.php';
require_once '../includes/notifications.php';
require_once '../includes/csrf.php';
require_once '../includes/reference_number.php';

$active_page = 'book-appointment';
$patient_id = $_SESSION['user_id'];

// Confined patients can't start a new booking — the sidebar already
// intercepts this link with a warning panel, but that's client-side only,
// so this is the actual enforcement for anyone hitting this URL directly
// (bookmark, browser back/forward, typed URL, etc.).
$stmt = $conn->prepare("SELECT status FROM users WHERE user_id = ?");
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$patient_status = $stmt->get_result()->fetch_assoc()['status'];
$stmt->close();

if ($patient_status === 'confined') {
    header("Location: confinement-dashboard.php?blocked=book-appointment");
    exit;
}

// Reset wizard state if starting fresh (no step param = Step 0 entry point)
if (!isset($_GET['step']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    unset($_SESSION['booking']);
}

if (!isset($_SESSION['booking'])) {
    $_SESSION['booking'] = [];
}

$booking = &$_SESSION['booking'];
$errors = [];
$cancelled = false;

// Every step of this wizard posts back to this same file, so one check
// here covers all 5 action branches below - no need to repeat it per step.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('book-appointment.php');
}


// ============================================================
// STEP -1 — Blocked Account Check
// Runs before the pending-booking check, per the no-show policy (spec
// 1.7): a patient blocked after 3 no-shows can't start (or continue) a
// booking until staff reactivates their account. Reuses the same
// users.status the no-show policy itself sets in
// includes/no_show_policy.php - no new column, no separate flag.
// ============================================================
$stmt = $conn->prepare("SELECT status FROM users WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$account = $stmt->get_result()->fetch_assoc();
$stmt->close();

$account_blocked = ($account['status'] ?? '') === 'blocked';

// ============================================================
// STEP 0a — Cancel Existing Appointment
// Lets a patient cancel straight from this page instead of being sent to
// the dashboard first. Mirrors appointment-detail.php's cancel logic
// exactly (same re-validation, same notifications) so behavior stays
// consistent between the two entry points. Runs before the pending-check
// below so a successful cancel is reflected immediately on this same load.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel') {
    $cancel_appointment_id = (int)($_POST['appointment_id'] ?? 0);

    $stmt = $conn->prepare("
        SELECT appointment_id, doctor_id, slot_start FROM appointments
        WHERE appointment_id = ? AND patient_id = ? AND status IN ('pending', 'confirmed')
    ");
    $stmt->bind_param("ii", $cancel_appointment_id, $patient_id);
    $stmt->execute();
    $valid = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$valid) {
        $errors[] = "This appointment can no longer be cancelled.";
    } else {
        $stmt = $conn->prepare("UPDATE appointments SET status = 'cancelled' WHERE appointment_id = ? AND patient_id = ?");
        $stmt->bind_param("ii", $cancel_appointment_id, $patient_id);
        $stmt->execute();
        $stmt->close();

        create_notification($conn, $patient_id, "Your appointment has been cancelled.", 'dashboard.php');

        $doctor_notif_message = "Appointment cancelled: " . $_SESSION['first_name'] . " " . $_SESSION['last_name'] .
            " on " . date('M j, Y \a\t g:i A', strtotime($valid['slot_start'])) . ".";
        create_notification($conn, $valid['doctor_id'], $doctor_notif_message, "todays-queue.php");

        $cancelled = true;
    }
}

// ============================================================
// STEP 0 — Pending Booking Check
// Runs on every page load before anything else, per spec.
// ============================================================
$stmt = $conn->prepare("
    SELECT a.appointment_id, a.status, a.slot_start, a.created_at, d.department_name, u.first_name, u.last_name
    FROM appointments a
    JOIN departments d ON a.department_id = d.department_id
    JOIN users u ON a.doctor_id = u.user_id
    WHERE a.patient_id = ? AND a.status IN ('pending', 'confirmed')
    LIMIT 1
");
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$existing_appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();

// If the account is blocked, OR a pending/confirmed appointment already
// exists, block the whole flow regardless of what step the user thinks
// they're on. Blocked takes priority for the message shown below.
$booking_disabled = $account_blocked || $existing_appointment !== null;

// Determine current step (default to 1 if not specified)
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
if ($booking_disabled) {
    $step = 0; // special "disabled" view
}

// ============================================================
// STEP 1 -> 2: Handle symptom description submission
// ============================================================
if (!$booking_disabled && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_symptoms') {
    $symptom_text = trim($_POST['symptom_description'] ?? '');

    if (mb_strlen($symptom_text) < 10) {
        $errors[] = "Please describe your symptoms in at least 10 characters.";
        $step = 1;
    } elseif (mb_strlen($symptom_text) > 500) {
        $errors[] = "Please keep your description under 500 characters.";
        $step = 1;
    } else {
        $recommendation = recommend_department($symptom_text);

        if ($recommendation['matched_count'] === 0) {
            $errors[] = "We couldn't identify a specific medical concern from your description. Please describe your symptoms in more detail (e.g. \"I've had a fever and cough for 3 days\").";
            $booking['symptom_description'] = $symptom_text;
            $step = 1;
        } else {
            $booking['symptom_description'] = $symptom_text;
            $booking['recommended_department'] = $recommendation['department'];
            $booking['recommendation_rationale'] = $recommendation['rationale'];
            $booking['department_override'] = false;
            $booking['confirmed_department'] = $recommendation['department'];
            $step = 2;
        }
    }
}

// ============================================================
// STEP 2 -> 3: Handle department confirmation (or override)
// ============================================================
if (!$booking_disabled && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_department') {
    if (!isset($booking['symptom_description'])) {
        // Session state lost (e.g. expired) — restart safely rather than crash
        header("Location: book-appointment.php?step=1");
        exit;
    }

    if (isset($_POST['override_department']) && $_POST['override_department'] !== '') {
        // Patient chose a different department than recommended
        $override_dept = trim($_POST['override_department']);

        // Re-validate the override choice actually exists in the DB
        $stmt = $conn->prepare("SELECT department_name FROM departments WHERE department_name = ?");
        $stmt->bind_param("s", $override_dept);
        $stmt->execute();
        $valid_dept = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$valid_dept) {
            $errors[] = "Please select a valid department.";
            $step = 2;
        } else {
            $booking['confirmed_department'] = $override_dept;
            $booking['department_override'] = true;
            $step = 3;
        }
    } else {
        // Patient accepted the recommended department as-is
        $booking['confirmed_department'] = $booking['recommended_department'];
        $booking['department_override'] = false;
        $step = 3;
    }
}

// ============================================================
// STEP 3 -> 4: Handle doctor selection
// ============================================================
if (!$booking_disabled && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'select_doctor') {
    if (!isset($booking['confirmed_department'])) {
        header("Location: book-appointment.php?step=1");
        exit;
    }

    $doctor_id = (int)($_POST['doctor_id'] ?? 0);

    // Re-validate: does this doctor actually exist and belong to the confirmed department?
    $stmt = $conn->prepare("
        SELECT u.user_id, u.first_name, u.last_name
        FROM users u
        JOIN departments d ON u.department_id = d.department_id
        WHERE u.user_id = ? AND u.role = 'doctor' AND d.department_name = ? AND u.is_active = 1
    ");
    $stmt->bind_param("is", $doctor_id, $booking['confirmed_department']);
    $stmt->execute();
    $doctor = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$doctor) {
        $errors[] = "Please select a valid doctor.";
        $step = 3;
    } else {
        $booking['doctor_id'] = $doctor['user_id'];
        $booking['doctor_name'] = $doctor['first_name'] . ' ' . $doctor['last_name'];
        $step = 4;
    }
}

// ============================================================
// STEP 4 -> 5: Handle slot selection
// ============================================================
if (!$booking_disabled && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'select_slot') {
    if (!isset($booking['doctor_id'])) {
        header("Location: book-appointment.php?step=1");
        exit;
    }

    $slot_start = $_POST['slot_start'] ?? '';
    $slot_start_clean = date('Y-m-d H:i:s', strtotime($slot_start));
    $slot_date_only = date('Y-m-d', strtotime($slot_start));
    $slot_hour_only = (int)date('G', strtotime($slot_start));

    // Re-validate: block the lunch break (11AM-1PM) even if someone bypasses
    // the UI and POSTs one of those hours directly.
    if ($slot_hour_only === 11 || $slot_hour_only === 12) {
        $errors[] = "That time falls within the lunch break (11:00 AM–1:00 PM) — please choose another.";
        $step = 4;
    } else {
        // Re-validate: is the doctor actually on duty this date at all,
        // and if there's a custom start_time/end_time override for this
        // date, is this hour actually within it? (Protects against
        // someone bypassing the UI and POSTing a day-off date, or an
        // hour outside a custom shift, directly.)
        $stmt = $conn->prepare("SELECT duty_id, start_time, end_time FROM duty_schedule WHERE doctor_id = ? AND duty_date = ?");
        $stmt->bind_param("is", $booking['doctor_id'], $slot_date_only);
        $stmt->execute();
        $on_duty = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $within_duty_hours = true;
        if ($on_duty && !empty($on_duty['start_time']) && !empty($on_duty['end_time'])) {
            $slot_time_only = sprintf('%02d:00:00', $slot_hour_only);
            $within_duty_hours = ($slot_time_only >= $on_duty['start_time'] && $slot_time_only < $on_duty['end_time']);
        }

        // Re-validate the slot is actually still available
        $stmt = $conn->prepare("
            SELECT appointment_id FROM appointments
            WHERE doctor_id = ? AND slot_start = ? AND status IN ('pending', 'confirmed', 'completed')
        ");
        $stmt->bind_param("is", $booking['doctor_id'], $slot_start_clean);
        $stmt->execute();
        $taken = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$on_duty) {
            $errors[] = "The doctor is not on duty that day — please choose another date.";
            $step = 4;
        } elseif (!$within_duty_hours) {
            $errors[] = "That time is outside the doctor's duty hours that day — please choose another.";
            $step = 4;
        } elseif ($taken) {
            $errors[] = "This slot was just taken — please choose another.";
            $step = 4;
        } elseif (time() >= strtotime($slot_start_clean) + 1800) {
            $errors[] = "This time slot is no longer available to book — please choose another.";
            $step = 4;
        } else {
            $booking['slot_start'] = $slot_start_clean;
            $booking['slot_end'] = date('Y-m-d H:i:s', strtotime($slot_start_clean) + 3600); // 1-hour blocks
            $step = 5;
        }
    }
}

// ============================================================
// STEP 5: Final confirmation submit
// ============================================================
if (!$booking_disabled && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_booking') {
    if (!isset($booking['slot_start'])) {
        header("Location: book-appointment.php?step=1");
        exit;
    }

    // FINAL re-check right before insert (race-condition safeguard layer #1)
    $stmt = $conn->prepare("
        SELECT appointment_id FROM appointments
        WHERE doctor_id = ? AND slot_start = ? AND status IN ('pending', 'confirmed', 'completed')
    ");
    $stmt->bind_param("is", $booking['doctor_id'], $booking['slot_start']);
    $stmt->execute();
    $taken = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($taken) {
        $errors[] = "This slot was just taken — please choose another.";
        $step = 4;
    } else {
        $stmt = $conn->prepare("SELECT department_id FROM departments WHERE department_name = ?");
        $stmt->bind_param("s", $booking['confirmed_department']);
        $stmt->execute();
        $dept_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $department_id = $dept_row['department_id'];

        $recommended_department_id = null;
        if (isset($booking['recommended_department'])) {
            $stmt = $conn->prepare("SELECT department_id FROM departments WHERE department_name = ?");
            $stmt->bind_param("s", $booking['recommended_department']);
            $stmt->execute();
            $rec_row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $recommended_department_id = $rec_row['department_id'] ?? null;
        }

        // Use mysqli error suppression at the call level so we can catch the
        // UNIQUE KEY violation manually (race-condition safeguard layer #2)
        // without needing mysqli exception mode enabled globally.
        $insert_stmt = $conn->prepare("
            INSERT INTO appointments
                (patient_id, doctor_id, department_id, symptom_description,
                 recommended_department_id, department_override, slot_start, slot_end, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'confirmed')
        ");
        $override_int = $booking['department_override'] ? 1 : 0;
        $insert_stmt->bind_param(
            "iiisiiss",
            $patient_id,
            $booking['doctor_id'],
            $department_id,
            $booking['symptom_description'],
            $recommended_department_id,
            $override_int,
            $booking['slot_start'],
            $booking['slot_end']
        );

        $insert_succeeded = @$insert_stmt->execute();

        if ($insert_succeeded) {
            $insert_stmt->close();

            // Notify the patient their booking was confirmed
            $notif_message = "Your appointment with Dr. " . $booking['doctor_name'] .
                " (" . $booking['confirmed_department'] . ") on " .
                date('M j, Y \a\t g:i A', strtotime($booking['slot_start'])) . " is confirmed.";
            create_notification($conn, $patient_id, $notif_message, 'dashboard.php');

            // Notify the doctor of the new appointment
            $doctor_notif_message = "New appointment: " . $_SESSION['first_name'] . " " . $_SESSION['last_name'] .
                " on " . date('M j, Y \a\t g:i A', strtotime($booking['slot_start'])) . ".";
            create_notification($conn, $booking['doctor_id'], $doctor_notif_message, "todays-queue.php");

            unset($_SESSION['booking']);
            header("Location: dashboard.php?booked=1");
            exit;
        } else {
            // Errno 1062 = duplicate entry, which is exactly our UNIQUE KEY
            // (doctor_id, slot_start) constraint catching a true race condition.
            $errors[] = "This slot was just taken — please choose another.";
            $step = 4;
            $insert_stmt->close();
        }
    }
}

// ============================================================
// Data needed to RENDER the current step
// ============================================================

$all_departments = $conn->query("SELECT department_id, department_name FROM departments ORDER BY department_name")->fetch_all(MYSQLI_ASSOC);

$doctors_in_department = [];
if ($step === 3 && isset($booking['confirmed_department'])) {
    $stmt = $conn->prepare("
        SELECT user_id, first_name, last_name
        FROM users
        WHERE role = 'doctor' AND is_active = 1
          AND department_id = (SELECT department_id FROM departments WHERE department_name = ?)
    ");
    $stmt->bind_param("s", $booking['confirmed_department']);
    $stmt->execute();
    $doctors_in_department = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($doctors_in_department as &$doc) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS booked_count FROM appointments
            WHERE doctor_id = ? AND status IN ('pending','confirmed','completed')
              AND slot_start >= CURDATE() AND slot_start < DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ");
        $stmt->bind_param("i", $doc['user_id']);
        $stmt->execute();
        $doc['booked_this_week'] = $stmt->get_result()->fetch_assoc()['booked_count'];
        $stmt->close();
    }
    unset($doc);
}

$week_slots = [];
$booked_slots = [];
$on_duty_dates = [];
// Clinic hours: 8AM-4PM start times, but 11AM and 12PM are excluded
// since that's the lunch break (no appointments start during 11-1).
$clinic_hours = array_values(array_diff(range(8, 16), [11, 12]));

if ($step === 4 && isset($booking['doctor_id'])) {
    $today = new DateTime('today');
    for ($i = 0; $i < 7; $i++) {
        $day = (clone $today)->modify("+$i days");
        if ($day->format('N') == 7) continue; // skip Sunday
        $week_slots[$day->format('Y-m-d')] = [
            'label' => $day->format('D, M j'),
        ];
    }

    $stmt = $conn->prepare("
        SELECT slot_start FROM appointments
        WHERE doctor_id = ? AND status IN ('pending', 'confirmed', 'completed')
          AND slot_start >= CURDATE() AND slot_start < DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ");
    $stmt->bind_param("i", $booking['doctor_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $booked_slots[] = $row['slot_start'];
    }
    $stmt->close();

    // Check duty_schedule to find which of these 7 days the doctor is
    // actually on duty. Days NOT in this list get disabled entirely in
    // the grid, since the doctor isn't working that day at all.
    // start_time/end_time are optional per-day overrides (set by staff/
    // admin later) — NULL means "use the standard clinic hours" below.
    $duty_hours = [];
    $stmt = $conn->prepare("
        SELECT duty_date, start_time, end_time FROM duty_schedule
        WHERE doctor_id = ? AND duty_date >= CURDATE() AND duty_date < DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ");
    $stmt->bind_param("i", $booking['doctor_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $on_duty_dates[] = $row['duty_date'];
        $duty_hours[$row['duty_date']] = [
            'start' => $row['start_time'], // e.g. '13:00:00', or null = full clinic day
            'end'   => $row['end_time'],
        ];
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book an Appointment - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/booking.css">
    <link rel="stylesheet" href="../assets/css/history.css">
</head>

<body>
    <div class="app-layout">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1 class="page-title">Book an Appointment</h1>
                <?php if (!$booking_disabled): ?>
                    <p class="page-subtitle">Step <?= min($step, 5) ?> of 5</p>
                <?php endif; ?>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="error-banner">
                    <?php foreach ($errors as $error): ?>
                        <div><?= htmlspecialchars($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($cancelled): ?>
                <div class="success-banner">Your appointment has been cancelled.</div>
            <?php endif; ?>

            <?php if ($booking_disabled): ?>
                <!-- ===== Step 0: Booking Disabled ===== -->
                <?php if ($account_blocked): ?>
                    <div class="card">
                        <div class="empty-state">
                            <p class="empty-state-text">
                                Your account has been blocked after 3 missed appointments.
                                Please visit the front desk to reactivate it before booking again.
                            </p>
                            <a href="dashboard.php" class="btn btn-primary">Back to Dashboard</a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card booking-card">
                        <p class="booking-label">You already have an appointment scheduled — please attend or cancel it before booking another:</p>
                        <div class="confirmation-summary">
                            <div class="confirmation-row">
                                <span class="confirmation-key">Reference No.</span>
                                <span class="confirmation-value confirmation-value-mono"><?= htmlspecialchars(format_appointment_reference($existing_appointment['appointment_id'], $existing_appointment['created_at'])) ?></span>
                            </div>
                            <div class="confirmation-row">
                                <span class="confirmation-key">Department</span>
                                <span class="confirmation-value"><?= htmlspecialchars($existing_appointment['department_name']) ?></span>
                            </div>
                            <div class="confirmation-row">
                                <span class="confirmation-key">Doctor</span>
                                <span class="confirmation-value">Dr. <?= htmlspecialchars($existing_appointment['first_name'] . ' ' . $existing_appointment['last_name']) ?></span>
                            </div>
                            <div class="confirmation-row">
                                <span class="confirmation-key">Date & Time</span>
                                <span class="confirmation-value"><?= date('l, F j, Y \a\t g:i A', strtotime($existing_appointment['slot_start'])) ?></span>
                            </div>
                            <div class="confirmation-row">
                                <span class="confirmation-key">Status</span>
                                <span class="confirmation-value">
                                    <span class="status-chip status-chip-<?= htmlspecialchars($existing_appointment['status']) ?>">
                                        <?= ucwords(str_replace('_', ' ', $existing_appointment['status'])) ?>
                                    </span>
                                </span>
                            </div>
                        </div>
                        <form method="POST" action="book-appointment.php" style="margin-top:1rem;"
                            onsubmit="return confirm('Are you sure you want to cancel this appointment?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="appointment_id" value="<?= $existing_appointment['appointment_id'] ?>">
                            <button type="submit" class="btn btn-cancel">Cancel Appointment</button>
                        </form>
                    </div>
                <?php endif; ?>

            <?php elseif ($step === 1): ?>
                <!-- ===== Step 1: Describe Symptoms ===== -->
                <div class="card booking-card">
                    <label for="symptom_description" class="booking-label">What's the reason for your visit?</label>
                    <form method="POST" action="book-appointment.php" id="symptom-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="submit_symptoms">
                        <textarea name="symptom_description" id="symptom_description" rows="6"
                            placeholder="Describe what you're experiencing — for example: 'I've had a fever and cough for 3 days...'"
                            oninput="updateCharCount()" required><?= htmlspecialchars($booking['symptom_description'] ?? '') ?></textarea>
                        <div class="char-count" id="char-count">0 / 500 characters (minimum 10)</div>
                        <button type="submit" class="btn btn-primary" id="continue-btn" disabled>Continue</button>
                    </form>
                </div>
                <script>
                    function updateCharCount() {
                        const text = document.getElementById('symptom_description').value;
                        const len = text.length;
                        document.getElementById('char-count').textContent = len + ' / 500 characters (minimum 10)';
                        document.getElementById('continue-btn').disabled = (len < 10 || len > 500);
                    }
                    updateCharCount();
                </script>

            <?php elseif ($step === 2): ?>
                <!-- ===== Step 2: Department Recommendation ===== -->
                <div class="card booking-card">
                    <p class="booking-label">Based on what you described, we recommend:</p>
                    <div class="recommended-department"><?= htmlspecialchars($booking['recommended_department']) ?></div>
                    <?php if (!empty($booking['recommendation_rationale'])): ?>
                        <p class="recommendation-rationale"><?= htmlspecialchars($booking['recommendation_rationale']) ?></p>
                    <?php endif; ?>

                    <form method="POST" action="book-appointment.php" class="booking-actions">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="confirm_department">
                        <button type="submit" class="btn btn-primary">Confirm — <?= htmlspecialchars($booking['recommended_department']) ?></button>
                        <button type="button" class="btn btn-secondary" onclick="showOverride()">Choose a different department</button>
                    </form>

                    <form method="POST" action="book-appointment.php" id="override-form" class="booking-override" style="display:none;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="confirm_department">
                        <label for="override_department" class="booking-label">Select a department:</label>
                        <select name="override_department" id="override_department" required>
                            <option value="">-- Select Department --</option>
                            <?php foreach ($all_departments as $dept): ?>
                                <option value="<?= htmlspecialchars($dept['department_name']) ?>">
                                    <?= htmlspecialchars($dept['department_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-primary">Confirm Override</button>
                    </form>

                    <script>
                        function showOverride() {
                            document.getElementById('override-form').style.display = 'block';
                        }
                    </script>
                    <a href="book-appointment.php?step=1" class="link-back">&larr; Edit symptoms</a>
                </div>

            <?php elseif ($step === 3): ?>
                <!-- ===== Step 3: Doctor Selection ===== -->
                <div class="card booking-card">
                    <p class="booking-label">Choose a doctor in <?= htmlspecialchars($booking['confirmed_department']) ?>:</p>

                    <?php if (empty($doctors_in_department)): ?>
                        <div class="empty-state">
                            <p class="empty-state-text">No doctors currently available in this department — please contact the front desk.</p>
                            <a href="book-appointment.php?step=2" class="btn btn-secondary">Choose a different department</a>
                        </div>
                    <?php else: ?>
                        <form method="POST" action="book-appointment.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="select_doctor">
                            <div class="doctor-grid">
                                <?php foreach ($doctors_in_department as $doc): ?>
                                    <label class="doctor-card">
                                        <input type="radio" name="doctor_id" value="<?= $doc['user_id'] ?>" required>
                                        <div class="doctor-name">Dr. <?= htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']) ?></div>
                                        <div class="doctor-meta"><?= $doc['booked_this_week'] ?> appointment<?= $doc['booked_this_week'] == 1 ? '' : 's' ?> this week</div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <button type="submit" class="btn btn-primary">Continue</button>
                        </form>
                        <a href="book-appointment.php?step=2" class="link-back">&larr; Choose a different department</a>
                    <?php endif; ?>
                </div>

            <?php elseif ($step === 4): ?>
                <!-- ===== Step 4: Slot Selection ===== -->
                <div class="card booking-card">
                    <p class="booking-label">Choose a date and time with Dr. <?= htmlspecialchars($booking['doctor_name']) ?>:</p>

                    <?php if (empty($week_slots)): ?>
                        <div class="empty-state">
                            <p class="empty-state-text">No available slots could be loaded — please try again.</p>
                        </div>
                    <?php else: ?>
                        <form method="POST" action="book-appointment.php" id="slot-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="select_slot">
                            <input type="hidden" name="slot_start" id="slot_start_input">

                            <div class="slot-grid-wrapper">
                                <table class="slot-grid">
                                    <thead>
                                        <tr>
                                            <th>Time</th>
                                            <?php foreach ($week_slots as $date => $day):
                                                $doctor_on_duty = in_array($date, $on_duty_dates);
                                            ?>
                                                <th class="<?= $doctor_on_duty ? '' : 'day-off-header' ?>">
                                                    <?= htmlspecialchars($day['label']) ?>
                                                    <?php if (!$doctor_on_duty): ?>
                                                        <div class="day-off-label">Day Off</div>
                                                    <?php endif; ?>
                                                </th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($clinic_hours as $hour): ?>
                                            <tr>
                                                <td class="slot-hour-label"><?= date('g:i A', strtotime("$hour:00")) ?></td>
                                                <?php foreach ($week_slots as $date => $day):
                                                    $slot_datetime = sprintf('%s %02d:00:00', $date, $hour);
                                                    $is_booked = in_array($slot_datetime, $booked_slots);
                                                    // Each slot represents a 1-hour block (e.g. "2:00 PM" = 2:00-3:00 PM).
                                                    // It stays bookable through the walk-in window and only closes
                                                    // once fewer than 30 minutes remain in that block, i.e. 30
                                                    // minutes after the slot's own start time.
                                                    $is_past = time() >= strtotime($slot_datetime) + 1800;
                                                    $doctor_on_duty = in_array($date, $on_duty_dates);

                                                    // If this date has a custom start/end override, only hours
                                                    // inside that window count as available. No override (both
                                                    // null) means the full standard clinic day applies.
                                                    $within_duty_hours = true;
                                                    if ($doctor_on_duty && !empty($duty_hours[$date]['start']) && !empty($duty_hours[$date]['end'])) {
                                                        $slot_time_only = sprintf('%02d:00:00', $hour);
                                                        $within_duty_hours = ($slot_time_only >= $duty_hours[$date]['start'] && $slot_time_only < $duty_hours[$date]['end']);
                                                    }
                                                ?>
                                                    <td class="<?= $doctor_on_duty ? '' : 'day-off-cell' ?>">
                                                        <?php if (!$doctor_on_duty): ?>
                                                            <button type="button" class="slot-btn slot-disabled" disabled title="Doctor not on duty this day"></button>
                                                        <?php elseif (!$within_duty_hours): ?>
                                                            <button type="button" class="slot-btn slot-disabled" disabled title="Outside doctor's duty hours this day"></button>
                                                        <?php elseif ($is_booked || $is_past): ?>
                                                            <button type="button" class="slot-btn slot-disabled" disabled></button>
                                                        <?php else: ?>
                                                            <button type="button" class="slot-btn" onclick="selectSlot('<?= $slot_datetime ?>', this)"></button>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                            <?php if ($hour == 10): ?>
                                                <tr class="lunch-break-row">
                                                    <td class="slot-hour-label">11:00 AM&ndash;1:00 PM</td>
                                                    <td colspan="<?= count($week_slots) ?>" class="lunch-break-cell">Lunch Break</td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <p class="slot-grid-legend">
                                <span class="legend-item"><span class="legend-dot legend-available"></span> Available</span>
                                <span class="legend-item"><span class="legend-dot legend-booked"></span> Booked</span>
                                <span class="legend-item"><span class="legend-dot legend-dayoff"></span> Day Off</span>
                            </p>

                            <p class="slot-selected-label" id="slot-selected-label">No time selected yet.</p>
                            <button type="submit" class="btn btn-primary" id="slot-continue-btn" disabled>Continue</button>
                        </form>
                    <?php endif; ?>
                    <a href="book-appointment.php?step=3" class="link-back">&larr; Choose a different doctor</a>
                </div>

                <script>
                    function selectSlot(datetime, btn) {
                        document.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('slot-selected'));
                        btn.classList.add('slot-selected');
                        document.getElementById('slot_start_input').value = datetime;

                        const d = new Date(datetime.replace(' ', 'T'));
                        const label = d.toLocaleDateString('en-US', {
                                weekday: 'short',
                                month: 'short',
                                day: 'numeric'
                            }) +
                            ' at ' + d.toLocaleTimeString('en-US', {
                                hour: 'numeric',
                                minute: '2-digit'
                            });
                        document.getElementById('slot-selected-label').textContent = 'Selected: ' + label;
                        document.getElementById('slot-continue-btn').disabled = false;
                    }
                </script>

            <?php elseif ($step === 5): ?>
                <!-- ===== Step 5: Confirmation ===== -->
                <div class="card booking-card">
                    <p class="booking-label">Please review your appointment details:</p>

                    <div class="confirmation-summary">
                        <div class="confirmation-row">
                            <span class="confirmation-key">Patient</span>
                            <span class="confirmation-value"><?= htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']) ?></span>
                        </div>
                        <div class="confirmation-row">
                            <span class="confirmation-key">Department</span>
                            <span class="confirmation-value"><?= htmlspecialchars($booking['confirmed_department']) ?></span>
                        </div>
                        <div class="confirmation-row">
                            <span class="confirmation-key">Doctor</span>
                            <span class="confirmation-value">Dr. <?= htmlspecialchars($booking['doctor_name']) ?></span>
                        </div>
                        <div class="confirmation-row">
                            <span class="confirmation-key">Date & Time</span>
                            <span class="confirmation-value"><?= date('l, F j, Y \a\t g:i A', strtotime($booking['slot_start'])) ?></span>
                        </div>
                    </div>

                    <div class="policy-notice">
                        Please arrive on time. If you're more than <strong>30 minutes late</strong>, your slot may be given to a walk-in patient and marked as a no-show.
                        After <strong>3 no-shows</strong>, your account will be temporarily blocked.
                    </div>

                    <form method="POST" action="book-appointment.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="confirm_booking">
                        <button type="submit" class="btn btn-primary">Confirm Booking</button>
                    </form>
                    <a href="book-appointment.php?step=4" class="link-back">&larr; Go Back</a>
                </div>
            <?php endif; ?>

        </main>
    </div>
</body>

</html>