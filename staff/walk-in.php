<?php
// staff/walk-in.php
// Front-desk flow for booking a same-day walk-in appointment — e.g. when
// a patient no-shows and someone else walks in to take the freed slot.
//
// Mirrors patient/book-appointment.php's wizard (symptoms -> department ->
// doctor -> slot -> confirm) so the exact same department-matching and
// slot-validation logic is used for staff-created appointments as for
// self-booked ones. The only real addition is Step 0, which doesn't exist
// in the patient flow: identifying WHO this appointment is for, since
// staff aren't logged in as the patient.
//
// Uses $_SESSION['walkin'] to carry wizard state between steps (parallel
// to $_SESSION['booking'] in book-appointment.php). Every step re-validates
// against the database rather than trusting session state.

require_once '../includes/auth_guard.php';
require_role('staff');
require_staff_type('front_desk');
require_once '../config/db.php';
require_once '../patient/includes/department_matcher.php';
require_once '../includes/schedule_resolver.php';
require_once '../includes/slot_grid.php';
require_once '../includes/notifications.php';
require_once '../includes/csrf.php';
require_once '../includes/reference_number.php';
require_once '../includes/no_show_policy.php';

// Auto-enforce the no-show policy on every load of this page too, not just
// check-in.php. Without this, a slot that's actually 30+ minutes late would
// still show as taken (status still 'confirmed') if staff open Walk-in
// directly, blocking the very "give the slot to a walk-in" behavior this
// page exists for. Idempotent by design (see includes/no_show_policy.php),
// so running it here as well is safe.
run_no_show_sweep($conn);

$current_page = 'walk-in';

$priorityLabels = [
    'senior'  => 'Senior Citizen',
    'pwd'     => 'PWD',
    'ip'      => 'IP',
    'regular' => 'Regular',
];

// Reset wizard state if starting fresh (no step param, no POST = Step 0 entry)
if (!isset($_GET['step']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    unset($_SESSION['walkin']);
}

if (!isset($_SESSION['walkin'])) {
    $_SESSION['walkin'] = [];
}

$walkin = &$_SESSION['walkin'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('walk-in.php');
}

$step = isset($_GET['step']) ? (int) $_GET['step'] : 0;

// ============================================================
// STEP 0a: Search for an existing patient
// ============================================================
$search_results = [];
$searched = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'search_patient') {
    $searched = true;
    $q = trim($_POST['search_query'] ?? '');
    if ($q !== '') {
        $like = '%' . $q . '%';
        $stmt = $conn->prepare("
            SELECT user_id, first_name, last_name, phone_number, account_status, priority_type
            FROM users
            WHERE role = 'patient'
              AND (first_name LIKE ? OR last_name LIKE ? OR phone_number LIKE ?)
            ORDER BY last_name, first_name
            LIMIT 20
        ");
        $stmt->bind_param("sss", $like, $like, $like);
        $stmt->execute();
        $search_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    $step = 0;
}

// ============================================================
// STEP 0b: Select an existing patient from search results
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'select_existing_patient') {
    $selected_id = (int) ($_POST['patient_id'] ?? 0);

    $stmt = $conn->prepare("SELECT user_id, first_name, last_name FROM users WHERE user_id = ? AND role = 'patient' LIMIT 1");
    $stmt->bind_param("i", $selected_id);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$found) {
        $errors[] = "That patient could not be found. Please search again.";
        $step = 0;
    } else {
        $walkin['patient_id'] = $found['user_id'];
        $walkin['patient_name'] = trim($found['first_name'] . ' ' . $found['last_name']);
        $walkin['patient_is_new'] = false;
        $step = 1;
    }
}

// ============================================================
// STEP 0c: Register a brand-new walk-in patient
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register_walkin_patient') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');

    // Staff-declared at intake — this is the walk-in equivalent of the
    // dropdown on register.php. Unlike self-registration, staff are
    // physically face-to-face with the patient right now, so this is
    // actually the more reliable of the two capture points for
    // verifying a senior/PWD/IP claim (e.g. against an ID card) -
    // that verification step itself isn't enforced here, just the
    // capture. Falls back to 'regular' for anything unexpected since
    // this is an optional field, same as register.php.
    $allowed_priority_types = ['regular', 'senior', 'pwd', 'ip'];
    $priority_type = trim($_POST['priority_type'] ?? 'regular');
    if (!in_array($priority_type, $allowed_priority_types, true)) {
        $priority_type = 'regular';
    }

    if ($first_name === '' || $last_name === '' || $phone_number === '') {
        $errors[] = "First name, last name, and phone number are required.";
        $step = 0;
    } elseif (!preg_match('/^(09\d{9}|\+639\d{9})$/', $phone_number)) {
        // Same PH mobile format register.php requires — a walk-in record
        // saved in a different format would never match up later when the
        // patient self-registers with this same number.
        $errors[] = "Enter a valid PH mobile number (e.g. 09171234567).";
        $step = 0;
    } else {
        // phone_number is UNIQUE across all users, so a plain INSERT would
        // fail outright if this number is already on file. Look it up
        // first: if it belongs to an existing PATIENT (guest or active),
        // reuse that record instead of creating a duplicate. If it belongs
        // to a doctor/staff/admin account, that's a genuine conflict.
        $stmt = $conn->prepare("SELECT user_id, first_name, last_name, role FROM users WHERE phone_number = ?");
        $stmt->bind_param("s", $phone_number);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing && $existing['role'] !== 'patient') {
            $errors[] = "This phone number is already associated with a non-patient account. Please verify the number and try again.";
            $step = 0;
        } elseif ($existing) {
            // Already a patient record — link to it rather than duplicating.
            // No account changes happen here either way, so this is safe
            // to do regardless of whether that record is guest or active.
            $walkin['patient_id'] = $existing['user_id'];
            $walkin['patient_name'] = trim($existing['first_name'] . ' ' . $existing['last_name']);
            $walkin['patient_is_new'] = false;
            $walkin['patient_linked_existing'] = true;
            $step = 1;
        } else {
            // Genuinely new — this only creates a hospital record. No
            // activation token, no link, no email/SMS. The patient stays
            // account_status = 'guest' indefinitely unless they choose to
            // sign up themselves later using this same phone number (see
            // register.php), which links this exact record instead of
            // creating a duplicate.
            $stmt = $conn->prepare("
                INSERT INTO users (first_name, last_name, phone_number, role, account_status, status, priority_type)
                VALUES (?, ?, ?, 'patient', 'guest', 'active', ?)
            ");
            $stmt->bind_param("ssss", $first_name, $last_name, $phone_number, $priority_type);
            $stmt->execute();
            $new_patient_id = $stmt->insert_id;
            $stmt->close();

            $walkin['patient_id'] = $new_patient_id;
            $walkin['patient_name'] = trim($first_name . ' ' . $last_name);
            $walkin['patient_is_new'] = true;
            $step = 1;
        }
    }
}

$patient_identified = isset($walkin['patient_id']);

// ============================================================
// STEP 1 -> 2: Symptom description
// ============================================================
if ($patient_identified && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_symptoms') {
    $symptom_text = trim($_POST['symptom_description'] ?? '');

    if (mb_strlen($symptom_text) < 10) {
        $errors[] = "Please enter at least 10 characters describing the walk-in's symptoms.";
        $step = 1;
    } elseif (mb_strlen($symptom_text) > 500) {
        $errors[] = "Please keep the description under 500 characters.";
        $step = 1;
    } else {
        $walkin['symptom_description'] = $symptom_text;
        $recommendation = recommend_department($symptom_text);
        $walkin['recommended_department'] = $recommendation['department'];
        $walkin['recommendation_rationale'] = $recommendation['rationale'];
        $walkin['department_override'] = false;
        // Only pre-confirm when something actually matched. If nothing
        // matched, $recommendation['department'] is null — leave
        // confirmed_department unset so step 2 forces staff to pick one
        // explicitly instead of silently carrying forward no department.
        if ($recommendation['department'] !== null) {
            $walkin['confirmed_department'] = $recommendation['department'];
        } else {
            unset($walkin['confirmed_department']);
        }
        $step = 2;
    }
}

// ============================================================
// STEP 2 -> 3: Department confirmation (or override)
// ============================================================
if ($patient_identified && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_department') {
    if (!isset($walkin['symptom_description'])) {
        header("Location: walk-in.php?step=1");
        exit;
    }

    if (isset($_POST['override_department']) && $_POST['override_department'] !== '') {
        $override_dept = trim($_POST['override_department']);

        $stmt = $conn->prepare("SELECT department_name FROM departments WHERE department_name = ? AND is_active = 1");
        $stmt->bind_param("s", $override_dept);
        $stmt->execute();
        $valid_dept = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$valid_dept) {
            $errors[] = "Please select a valid department.";
            $step = 2;
        } else {
            $walkin['confirmed_department'] = $override_dept;
            $walkin['department_override'] = true;
            $step = 3;
        }
    } elseif ($walkin['recommended_department'] === null) {
        // Nothing was recommended and staff didn't pick an override —
        // don't fall through to a guessed department, force a choice.
        $errors[] = "No department was recommended from that description — please select one below.";
        $step = 2;
    } else {
        $walkin['confirmed_department'] = $walkin['recommended_department'];
        $walkin['department_override'] = false;
        $step = 3;
    }

    // REVERTED 2026-09-15: this was scoped to the confirmed department
    // (2026-08-08 fix) back when the policy was "one active appointment
    // per DEPARTMENT". That policy is now back to "one active appointment
    // total, system-wide" (see patient/book-appointment.php's
    // $active_appointments header comment) - same check, department_id
    // filter dropped, applies regardless of which department this
    // walk-in is headed for.
    if ($step === 3) {
        $stmt = $conn->prepare("
            SELECT a.slot_start, a.department_id, dep.department_name, u.first_name AS doc_first, u.last_name AS doc_last
            FROM appointments a
            JOIN departments dep ON dep.department_id = a.department_id
            JOIN users u ON u.user_id = a.doctor_id
            WHERE a.patient_id = ?
              AND a.status IN ('pending', 'confirmed')
        ");
        $stmt->bind_param("i", $walkin['patient_id']);
        $stmt->execute();
        $already_active = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($already_active) {
            $errors[] = $walkin['patient_name'] . " already has an active " . $already_active['department_name'] .
                " appointment on " . date('M j, Y \a\t g:i A', strtotime($already_active['slot_start'])) .
                " with Dr. " . $already_active['doc_first'] . ' ' . $already_active['doc_last'] .
                " - check them in for that instead, or cancel it first if this is unrelated.";
            $step = 2;
        }
    }
}

// ============================================================
// STEP 3 -> 4: Doctor selection -> auto-assign next available
// slot TODAY for that doctor. A walk-in is, by definition, for
// right now: the patient is already at the desk, so there's no
// calendar to browse - just the soonest opening this doctor has
// left today, first-come-first-served.
// ============================================================
if ($patient_identified && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'select_doctor') {
    if (!isset($walkin['confirmed_department'])) {
        header("Location: walk-in.php?step=1");
        exit;
    }

    $doctor_id = (int) ($_POST['doctor_id'] ?? 0);

    $stmt = $conn->prepare("
        SELECT u.user_id, u.first_name, u.last_name
        FROM users u
        JOIN departments d ON u.department_id = d.department_id
        WHERE u.user_id = ? AND u.role = 'doctor' AND d.department_name = ? AND u.is_active = 1
    ");
    $stmt->bind_param("is", $doctor_id, $walkin['confirmed_department']);
    $stmt->execute();
    $doctor = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$doctor) {
        $errors[] = "Please select a valid doctor.";
        $step = 3;
    } else {
        $doctor_full_name = trim($doctor['first_name'] . ' ' . $doctor['last_name']);
        $today_date = date('Y-m-d');

        // Same source of truth as patient/book-appointment.php and the
        // Doctor Portal: recurring weekly schedule + any override for
        // today, via includes/schedule_resolver.php. Replaces the old
        // duty_schedule existence check + hardcoded 8am-5pm hours below,
        // so a walk-in can only be booked within the doctor's actual
        // scheduled hours for today (and respects Weekly Overrides, e.g.
        // an emergency leave or half day set in the admin portal).
        $today_schedule = resolve_effective_schedule($conn, $doctor['user_id'], $today_date);

        if (!$today_schedule['on_duty']) {
            $errors[] = "Dr. " . $doctor_full_name . " is not on duty today. Please select another doctor.";
            $step = 3;
        } else {
            $today_start = $today_date . ' 00:00:00';
            $stmt = $conn->prepare("
                SELECT slot_start FROM appointments
                WHERE doctor_id = ? AND status IN ('pending', 'confirmed', 'completed')
                  AND slot_start >= ? AND slot_start < DATE_ADD(?, INTERVAL 1 DAY)
            ");
            $stmt->bind_param("iss", $doctor['user_id'], $today_start, $today_date);
            $stmt->execute();
            $result = $stmt->get_result();
            $booked_today = [];
            while ($row = $result->fetch_assoc()) {
                $booked_today[] = $row['slot_start'];
            }
            $stmt->close();

            if ($today_schedule['max_patients'] && count($booked_today) >= (int) $today_schedule['max_patients']) {
                $errors[] = "Dr. " . $doctor_full_name . " is fully booked for today — please select another doctor.";
                $step = 3;
            } else {
                // Walk within the doctor's actual scheduled hours today
                // (still skipping the 11am-1pm lunch block, same as the
                // rest of the system), rather than a fixed 8am-5pm window.
                // PER-DEPARTMENT DURATION (settled 2026-08-06): this used
                // to build its own hardcoded 30-minute time list, a THIRD
                // copy of logic that now also lives in
                // includes/slot_grid.php's get_clinic_time_slots() and
                // patient/book-appointment.php's confirm_booking. Reusing
                // the shared helper means a walk-in's slot spacing always
                // matches that department's configured duration
                // automatically, with nothing to keep in sync by hand.
                // Doctors belong to exactly one department (users.department_id),
                // so this reuses that instead of a second name-based lookup.
                //
                // FIXED 2026-09-08: now passes today's actual resolved
                // start/end straight into get_clinic_time_slots() instead
                // of generating the function's hardcoded default range
                // and filtering it down afterward - the old filter could
                // only ever narrow that default range, never recover
                // hours outside it, so a doctor scheduled past the
                // default (or, before that default was corrected, past
                // the previous hardcoded 4PM) never had a walk-in slot
                // offered in their actual last working hour(s).
                $department_id_for_duration = get_doctor_department_id($conn, $doctor['user_id']);
                $duration_minutes = get_department_duration_minutes($conn, $department_id_for_duration);
                $clinic_times_today = get_clinic_time_slots($duration_minutes, $today_schedule['start'], $today_schedule['end']);
                $next_slot = null;
                foreach ($clinic_times_today as $timeStr) {
                    $candidate = $today_date . ' ' . $timeStr;
                    if (strtotime($candidate) < time()) continue;
                    if (in_array($candidate, $booked_today, true)) continue;
                    $next_slot = $candidate;
                    break;
                }

                if ($next_slot === null) {
                    $errors[] = "No available slots remaining today for Dr. " . $doctor_full_name . " — please select another doctor.";
                    $step = 3;
                } else {
                    $walkin['doctor_id'] = $doctor['user_id'];
                    $walkin['doctor_name'] = $doctor_full_name;
                    $walkin['slot_start'] = $next_slot;
                    $walkin['slot_end'] = date('Y-m-d H:i:s', strtotime($next_slot) + ($duration_minutes * 60));
                    $step = 4;
                }
            }
        }
    }
}

// ============================================================
// STEP 4: Final confirmation — insert the appointment
// ============================================================
if ($patient_identified && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_booking') {
    if (!isset($walkin['slot_start'])) {
        header("Location: walk-in.php?step=1");
        exit;
    }

    $stmt = $conn->prepare("
        SELECT appointment_id FROM appointments
        WHERE doctor_id = ? AND slot_start = ? AND status IN ('pending', 'confirmed', 'completed')
    ");
    $stmt->bind_param("is", $walkin['doctor_id'], $walkin['slot_start']);
    $stmt->execute();
    $taken = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($taken) {
        $errors[] = "This slot was just taken — please select the doctor again to get the next available time.";
        $step = 3;
    } else {
        $stmt = $conn->prepare("SELECT department_id FROM departments WHERE department_name = ? AND is_active = 1");
        $stmt->bind_param("s", $walkin['confirmed_department']);
        $stmt->execute();
        $dept_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $department_id = $dept_row['department_id'];

        $recommended_department_id = null;
        if (isset($walkin['recommended_department'])) {
            $stmt = $conn->prepare("SELECT department_id FROM departments WHERE department_name = ? AND is_active = 1");
            $stmt->bind_param("s", $walkin['recommended_department']);
            $stmt->execute();
            $rec_row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $recommended_department_id = $rec_row['department_id'] ?? null;
        }

        // FIXED (2026-09-14): same bug as book-appointment.php had -
        // "@$insert_stmt->execute()" never actually caught the UNIQUE KEY
        // race-condition guard (unique_active_slot on doctor_id +
        // slot_start). PHP 8.1+'s mysqli throws mysqli_sql_exception on a
        // duplicate-key violation instead of having execute() return
        // false, and @ only suppresses warnings/notices, not thrown
        // exceptions - so a genuine race (this walk-in landing on a slot a
        // patient or another walk-in just took) threw an uncaught fatal
        // instead of the intended "just taken" message. try/catch is the
        // real guard now, same fix as book-appointment.php. Also still:
        //   - booking_source = 'walk_in' so this is distinguishable everywhere
        //     downstream (doctor's queue, patient records, reports)
        //   - checked_in_at = NOW() set immediately — the patient is already
        //     standing at the desk, there's no separate check-in step to send
        //     them through
        $insert_stmt = $conn->prepare("
            INSERT INTO appointments
                (patient_id, doctor_id, department_id, symptom_description,
                 recommended_department_id, department_override, slot_start, slot_end,
                 status, booking_source, checked_in_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', 'walk_in', NOW())
        ");
        $override_int = $walkin['department_override'] ? 1 : 0;
        $insert_stmt->bind_param(
            "iiisiiss",
            $walkin['patient_id'],
            $walkin['doctor_id'],
            $department_id,
            $walkin['symptom_description'],
            $recommended_department_id,
            $override_int,
            $walkin['slot_start'],
            $walkin['slot_end']
        );

        try {
            $insert_stmt->execute();
            $new_appointment_id = $insert_stmt->insert_id;
            $insert_stmt->close();

            // Guest patients have no dashboard to be notified through, but
            // the notification system is harmless to call regardless — it
            // simply won't be seen until/if this patient ever activates an
            // account. Existing patients see it immediately as usual.
            $notif_message = "Your walk-in appointment with Dr. " . $walkin['doctor_name'] .
                " (" . $walkin['confirmed_department'] . ") on " .
                date('M j, Y \a\t g:i A', strtotime($walkin['slot_start'])) . " is confirmed.";
            create_notification($conn, $walkin['patient_id'], $notif_message, 'dashboard.php');

            // Notify the doctor of the new walk-in appointment
            $doctor_notif_message = "New walk-in appointment: " . $walkin['patient_name'] .
                " on " . date('M j, Y \a\t g:i A', strtotime($walkin['slot_start'])) . ".";
            create_notification($conn, $walkin['doctor_id'], $doctor_notif_message, "todays-queue.php");

            // Reference number for this new appointment — hand this to the
            // patient (printed or read aloud) so they can look up their
            // visit later even without an account.
            $stmt = $conn->prepare("SELECT created_at FROM appointments WHERE appointment_id = ?");
            $stmt->bind_param("i", $new_appointment_id);
            $stmt->execute();
            $created_row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $_SESSION['walkin_flash'] = [
                "type" => "success",
                "message" => "Walk-in appointment booked for " . $walkin['patient_name'] . ". Reference number: "
                    . format_appointment_reference($new_appointment_id, $created_row['created_at']),
            ];

            unset($_SESSION['walkin']);
            header("Location: walk-in.php");
            exit;
        } catch (mysqli_sql_exception $e) {
            $insert_stmt->close();
            if ($e->getCode() === 1062 && str_contains($e->getMessage(), 'unique_active_appointment_per_patient')) {
                // unique_active_appointment_per_patient - the app-layer
                // $already_active check above already covers this in the
                // normal case; this only fires on a genuine race (two
                // front-desk windows, or the patient's own app booking
                // landing at the same moment).
                $errors[] = $walkin['patient_name'] . " already has an active appointment - check them in for that instead, or cancel it first if this is unrelated.";
                $step = 2;
            } elseif ($e->getCode() === 1062) {
                $errors[] = "This slot was just taken — please select the doctor again to get the next available time.";
                $step = 3;
            } else {
                $errors[] = "Something went wrong while booking. Please try again.";
                $step = 3;
            }
        }
    }
}

// ============================================================
// Data needed to render the current step
// ============================================================

$all_departments = $conn->query("SELECT department_id, department_name FROM departments WHERE is_active = 1 ORDER BY department_name")->fetch_all(MYSQLI_ASSOC);

$doctors_in_department = [];
$department_has_any_doctors = false;
if ($step === 3 && isset($walkin['confirmed_department'])) {
    // All active doctors in this department at all — used only to tell
    // "this department has nobody" apart from "this department has
    // people, just not today" further down.
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS cnt FROM users
        WHERE role = 'doctor' AND is_active = 1
          AND department_id = (SELECT department_id FROM departments WHERE department_name = ?)
    ");
    $stmt->bind_param("s", $walkin['confirmed_department']);
    $stmt->execute();
    $department_has_any_doctors = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0) > 0;
    $stmt->close();

    // Only doctors actually on duty TODAY are selectable — no point
    // offering a choice that would just bounce back with an error.
    // Same source of truth as the check above and the rest of the
    // system: recurring weekly schedule + any override for today.
    $today_date_for_list = date('Y-m-d');
    $stmt = $conn->prepare("
        SELECT u.user_id, u.first_name, u.last_name
        FROM users u
        WHERE u.role = 'doctor' AND u.is_active = 1
          AND u.department_id = (SELECT department_id FROM departments WHERE department_name = ?)
    ");
    $stmt->bind_param("s", $walkin['confirmed_department']);
    $stmt->execute();
    $candidate_doctors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $doctors_in_department = array_values(array_filter($candidate_doctors, function ($doc) use ($conn, $today_date_for_list) {
        return resolve_effective_schedule($conn, $doc['user_id'], $today_date_for_list)['on_duty'];
    }));
}

$flash = $_SESSION['walkin_flash'] ?? null;
unset($_SESSION['walkin_flash']);
$today_label = date("F j, Y");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Walk-In Booking - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
</head>

<body>
    <div class="app-shell">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content" style="margin: 0 auto; max-width: 820px;">
            <header class="page-header">
                <div>
                    <h1>Walk-In Booking</h1>
                    <p class="page-subtitle">Book a same-day appointment for a walk-in patient.</p>
                </div>
                <div class="header-actions">
                    <span class="header-date"><?php echo htmlspecialchars($today_label); ?></span>
                </div>
            </header>

            <?php if ($flash): ?>
                <section class="card" style="border-left: 4px solid <?php echo $flash['type'] === 'error' ? 'var(--red)' : 'var(--teal)'; ?>; margin-bottom: 20px;">
                    <p style="margin: 0; font-size: 14px;"><?php echo htmlspecialchars($flash['message']); ?></p>
                </section>
            <?php endif; ?>

            <?php foreach ($errors as $error): ?>
                <section class="card" style="border-left: 4px solid var(--red); margin-bottom: 20px;">
                    <p style="margin: 0; font-size: 14px; color: var(--red);"><?php echo htmlspecialchars($error); ?></p>
                </section>
            <?php endforeach; ?>

            <?php if ($patient_identified && !empty($walkin['patient_is_new'])): ?>
                <section class="card" style="border-left: 4px solid var(--teal); margin-bottom: 20px;">
                    <p style="margin: 0 0 8px; font-size: 14px; font-weight: 600;">
                        Patient Registered Successfully
                    </p>
                    <p style="margin: 0 0 8px; font-size: 13px; color: var(--text-secondary);">
                        <?php echo htmlspecialchars($walkin['patient_name']); ?> has been registered in the hospital records.
                    </p>
                    <p style="margin: 0; font-size: 13px; color: var(--text-secondary);">
                        An online account is optional and can be created later using the same phone number to:
                    </p>
                    <ul style="margin: 8px 0 0; padding-left: 20px; font-size: 13px; color: var(--text-secondary);">
                        <li>View appointments</li>
                        <li>Receive notifications</li>
                        <li>Access medical records</li>
                        <li>Book future appointments online</li>
                    </ul>
                </section>
            <?php elseif ($patient_identified && !empty($walkin['patient_linked_existing'])): ?>
                <section class="card" style="border-left: 4px solid var(--teal); margin-bottom: 20px;">
                    <p style="margin: 0 0 8px; font-size: 14px; font-weight: 600;">
                        Existing Patient Found
                    </p>
                    <p style="margin: 0; font-size: 13px; color: var(--text-secondary);">
                        A patient record already exists for this phone number.
                        <?php echo htmlspecialchars($walkin['patient_name']); ?> has been linked to that record instead of creating a duplicate.
                    </p>
                </section>
            <?php endif; ?>

            <?php if ($step === 0 && !$patient_identified): ?>
                <section class="card">
                    <div class="card-header">
                        <h2>Step 1: Identify the Patient</h2>
                    </div>

                    <form method="POST" action="walk-in.php" class="consultation-form" style="margin-bottom: 24px;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="search_patient">
                        <div class="form-group">
                            <label class="form-label">Search existing patient (name or phone number)</label>
                            <input type="text" name="search_query" class="form-input" value="<?php echo htmlspecialchars($_POST['search_query'] ?? ''); ?>">
                        </div>
                        <button type="submit" class="btn btn-secondary">Search</button>
                    </form>

                    <?php if ($searched): ?>
                        <?php if (count($search_results) > 0): ?>
                            <div class="table-wrap">
                                <table class="queue-table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Phone</th>
                                            <th>Account</th>
                                            <th>Priority</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($search_results as $r): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars(trim($r['first_name'] . ' ' . $r['last_name'])); ?></td>
                                                <td><?php echo htmlspecialchars($r['phone_number']); ?></td>
                                                <td><?php echo $r['account_status'] === 'guest' ? '<span class="status-pill status-guest">Guest</span>' : '<span class="status-pill status-active">Active</span>'; ?></td>
                                                <td><span class="priority-badge priority-<?php echo htmlspecialchars($r['priority_type']); ?>"><?php echo htmlspecialchars($priorityLabels[$r['priority_type']] ?? 'Regular'); ?></span></td>
                                                <td style="white-space: nowrap;">
                                                    <form method="POST" action="walk-in.php" style="display:inline-block;">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="select_existing_patient">
                                                        <input type="hidden" name="patient_id" value="<?php echo (int) $r['user_id']; ?>">
                                                        <button type="submit" class="btn btn-primary">Select</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <p>No matching patient found. Register them as new below.</p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <hr style="margin: 24px 0; border: none; border-top: 1px solid var(--border-light, #e5e7eb);">

                    <h3 style="font-size: 15px; margin-bottom: 12px;">Or register a new patient</h3>
                    <form method="POST" action="walk-in.php" class="consultation-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="register_walkin_patient">
                        <div class="form-group">
                            <label class="form-label">First Name</label>
                            <input type="text" name="first_name" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Last Name</label>
                            <input type="text" name="last_name" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone Number</label>
                            <input type="text" name="phone_number" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Priority Lane <span style="color:#A8B5B5; font-weight:400;">(optional)</span></label>
                            <select name="priority_type" class="form-input">
                                <option value="regular">Regular</option>
                                <option value="senior">Senior Citizen</option>
                                <option value="pwd">PWD</option>
                                <option value="ip">Indigenous Peoples (IP)</option>
                            </select>
                            <span class="form-hint">Confirm against a valid ID (Senior Citizen / PWD card, or applicable IP certification) before selecting — this is not verified automatically.</span>
                        </div>
                        <span class="form-hint">This creates a hospital record only. An online account is optional — the patient can create one later at register.php using this same phone number.</span>
                        <button type="submit" class="btn btn-primary" style="margin-top: 12px;">Register &amp; Continue</button>
                    </form>
                </section>

            <?php elseif ($step === 1): ?>
                <section class="card">
                    <div class="card-header">
                        <h2>Step 2: Symptoms — <?php echo htmlspecialchars($walkin['patient_name']); ?></h2>
                    </div>
                    <form method="POST" action="walk-in.php" class="consultation-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="submit_symptoms">
                        <div class="form-group">
                            <label class="form-label">What is the patient experiencing?</label>
                            <textarea name="symptom_description" class="form-textarea" rows="4" required><?php echo htmlspecialchars($walkin['symptom_description'] ?? ''); ?></textarea>
                            <span class="form-hint">10–500 characters. Used to recommend the right department, same as the patient's own booking flow.</span>
                        </div>
                        <button type="submit" class="btn btn-primary">Continue</button>
                    </form>
                </section>

            <?php elseif ($step === 2): ?>
                <section class="card">
                    <div class="card-header">
                        <h2>Step 3: Confirm Department</h2>
                    </div>
                    <?php if ($walkin['recommended_department'] !== null): ?>
                        <p>Recommended: <strong><?php echo htmlspecialchars($walkin['recommended_department']); ?></strong></p>
                        <?php if ($walkin['recommendation_rationale']): ?>
                            <p class="form-hint"><?php echo htmlspecialchars($walkin['recommendation_rationale']); ?></p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="form-hint">No specific department could be matched from that description — please select one below.</p>
                    <?php endif; ?>
                    <form method="POST" action="walk-in.php" class="consultation-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="confirm_department">
                        <div class="form-group">
                            <label class="form-label">
                                <?php echo $walkin['recommended_department'] !== null ? 'Override department (optional)' : 'Select department'; ?>
                            </label>
                            <select name="override_department" class="form-input">
                                <option value=""><?php echo $walkin['recommended_department'] !== null ? 'Use recommended department' : '-- Select a department --'; ?></option>
                                <?php foreach ($all_departments as $d): ?>
                                    <option value="<?php echo htmlspecialchars($d['department_name']); ?>"><?php echo htmlspecialchars($d['department_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">Continue</button>
                    </form>
                </section>

            <?php elseif ($step === 3): ?>
                <section class="card">
                    <div class="card-header">
                        <h2>Step 4: Select Doctor — <?php echo htmlspecialchars($walkin['confirmed_department']); ?></h2>
                    </div>
                    <?php if (count($doctors_in_department) === 0): ?>
                        <div class="empty-state">
                            <?php if (!$department_has_any_doctors): ?>
                                <p>No active doctors in this department.</p>
                            <?php else: ?>
                                <p>No doctors in <?php echo htmlspecialchars($walkin['confirmed_department']); ?> are on duty today. You can choose a different department, or come back tomorrow.</p>
                                <p style="margin-top: 12px;"><a href="walk-in.php?step=2" class="btn btn-secondary">&larr; Choose a different department</a></p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <form method="POST" action="walk-in.php" class="consultation-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="select_doctor">
                            <div class="form-group">
                                <label class="form-label">Doctor</label>
                                <select name="doctor_id" class="form-input" required>
                                    <?php foreach ($doctors_in_department as $doc): ?>
                                        <option value="<?php echo (int) $doc['user_id']; ?>">Dr. <?php echo htmlspecialchars(trim($doc['first_name'] . ' ' . $doc['last_name'])); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">Continue</button>
                        </form>
                    <?php endif; ?>
                </section>

            <?php elseif ($step === 4): ?>
                <section class="card">
                    <div class="card-header">
                        <h2>Step 5: Confirm Walk-In Booking</h2>
                    </div>
                    <div class="patient-info-grid">
                        <div class="info-item"><span class="info-label">Patient</span><span class="info-value"><?php echo htmlspecialchars($walkin['patient_name']); ?></span></div>
                        <div class="info-item"><span class="info-label">Department</span><span class="info-value"><?php echo htmlspecialchars($walkin['confirmed_department']); ?></span></div>
                        <div class="info-item"><span class="info-label">Doctor</span><span class="info-value">Dr. <?php echo htmlspecialchars($walkin['doctor_name']); ?></span></div>
                        <div class="info-item"><span class="info-label">Time</span><span class="info-value"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($walkin['slot_start']))); ?></span></div>
                    </div>
                    <form method="POST" action="walk-in.php" style="margin-top: 20px;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="confirm_booking">
                        <button type="submit" class="btn btn-primary">Confirm &amp; Check In Now</button>
                    </form>
                </section>
            <?php endif; ?>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>
        </main>
    </div>
    <script src="../assets/js/doctor-dashboard.js"></script>
</body>

</html>