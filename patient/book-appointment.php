<?php
// patient/book-appointment.php
//
// Single-page booking form. Previously this was a 5-step wizard driven by
// $_SESSION['booking'] (symptoms -> department -> doctor -> slot ->
// confirm), one page load per step. Per updated requirements, all of that
// now lives on ONE scrolling page.
//
// REWORKED 2026-09-16: department and doctor are no longer patient
// choices at all. What used to be 5 sections (free-text symptoms ->
// confirm department -> choose doctor -> pick slot -> review) is now 3:
// (1) check symptoms from a fixed list, (2) pick a date & time with the
// doctor the system assigned, (3) review & confirm. The department is
// derived purely from which symptoms were checked
// (includes/symptom_catalog.php), and the doctor is auto-assigned
// server-side (least booked this week, among doctors who actually have
// an open slot - see book-appointment-actions.php's match_symptoms
// action). If nobody in the matched department has any real
// availability, the flow terminates right there with no date/time step
// offered at all - see the "no_doctors" handling in
// assets/js/book-appointment.js.
//
// IMPORTANT: book-appointment-actions.php (the AJAX endpoint) only powers
// the progressive UI - match symptoms to a department, auto-assign a
// doctor, list that doctor's open slots. It never writes to the
// database. The ONLY code path that inserts an appointment is the
// "confirm_booking" POST handled at the bottom of THIS file, and it
// re-validates every field from scratch against the database rather
// than trusting anything the client or the AJAX endpoint already said -
// including re-deriving the department from the submitted symptom keys
// all over again, exactly like the AJAX call did. That preserves the
// same "never trust the client" defense the old session-based wizard
// had, just without needing session step state to enforce it.

require_once '../includes/auth_guard.php';
require_role('patient');
require_once '../config/db.php';
require_once 'includes/symptom_catalog.php';
require_once '../includes/notifications.php';
require_once '../includes/csrf.php';
require_once '../includes/reference_number.php';
require_once '../includes/schedule_resolver.php';
require_once '../includes/slot_grid.php';
require_once '../includes/cancellation_policy.php';

$active_page = 'book-appointment';
$patient_id = $_SESSION['user_id'];

// Confined patients can't start a new booking - the sidebar already
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

$errors = [];
$cancelled = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('book-appointment.php');
}

// ============================================================
// Blocked Account Check
// Per the no-show policy (spec 1.7): a patient blocked after 3 no-shows
// can't book until staff reactivates their account. Reuses the same
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
// Cancel Existing Appointment (2026-09-17: reverted back to direct
// cancellation — see includes/cancellation_policy.php's header comment.
// appointment-detail.php uses the same shared function so both entry
// points stay in sync.)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel') {
    $cancel_appointment_id = (int)($_POST['appointment_id'] ?? 0);
    $cancel_reason = $_POST['reason'] ?? '';

    $stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $patient_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $patient_name = trim(($patient_row['first_name'] ?? '') . ' ' . ($patient_row['last_name'] ?? ''));

    $cancel_result = cancel_patient_appointment($conn, $cancel_appointment_id, $patient_id, $patient_name, $cancel_reason);

    if (!$cancel_result['success']) {
        $errors[] = $cancel_result['error'];
    } else {
        $cancelled = true;
    }
}

// ============================================================
// Active Appointments Check.
//
// POLICY REVERSAL (2026-09-15): "one active appointment per
// DEPARTMENT" (settled 2026-08-04) is reverted back to "one active
// appointment total, system-wide" - a patient can no longer hold
// concurrent active appointments across different departments. This
// query itself is unchanged (it was already written to return every
// active appointment, not just one) - in practice it will now only
// ever return 0 or 1 rows, since $booking_disabled below makes it
// impossible to reach a second one.
// ============================================================
$stmt = $conn->prepare("
    SELECT a.appointment_id, a.status, a.slot_start, a.created_at, a.department_id, d.department_name, u.first_name, u.last_name
    FROM appointments a
    JOIN departments d ON a.department_id = d.department_id
    JOIN users u ON a.doctor_id = u.user_id
    WHERE a.patient_id = ? AND a.status IN ('pending', 'confirmed')
    ORDER BY a.slot_start ASC
");
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$active_appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// FIXED 2026-09-15: used to be `$account_blocked` only, from when a
// patient could still book in an unrelated department while holding an
// active appointment elsewhere. Now that the policy is back to one
// active appointment total, having ANY active appointment hides the
// booking wizard entirely too - see the `<?php if (!$booking_disabled)`
// wrapper further down.
$booking_disabled = $account_blocked || !empty($active_appointments);

// Whatever the patient checked, preserved across a failed submit so they
// don't have to re-check every box (doctor/slot choices are NOT
// preserved on a failed final submit - see the note above the form below
// for why, and what the tradeoff is).
$submitted_symptom_keys = [];
if (isset($_POST['symptoms']) && is_array($_POST['symptoms'])) {
    $submitted_symptom_keys = array_values(array_filter($_POST['symptoms'], 'is_string'));
}

// Symptom checklist for the form below. Grouped under patient-friendly
// category headers (not the exact department name - see
// includes/symptom_catalog.php's header comment) - the group => [keys]
// order here is also the render order on the page.
$symptom_catalog = get_symptom_catalog();
$symptom_groups = [
    "Pregnancy & Women's Health" => ['ob_pregnant', 'ob_family_planning', 'ob_menstrual', 'ob_postpartum', 'ob_other'],
    'Child & Baby Health' => ['ped_fever_cough', 'ped_vaccination', 'ped_growth_checkup', 'ped_newborn'],
    'General / Adult Health' => ['im_fever_cough', 'im_headache_bodypain', 'im_stomach', 'im_chronic_condition', 'im_general_checkup'],
    'Breathing & Lungs' => ['pu_breathing_difficulty', 'pu_chronic_cough', 'pu_chest_tightness'],
];

// Advance booking (2026-09-17): patient/dashboard.php links here with
// ?mode=advance for its "Advance Booking" button (see that file's own
// comment) - pre-selects the "choose a later month" option below instead
// of the normal "soonest available" one. Purely a UI default; nothing
// server-side trusts this query param for anything - the actual
// start_date/num_days the booking ends up using come from whatever the
// patient has selected in the form by the time they click Continue (see
// assets/js/book-appointment.js's matchSymptoms()), same "never trust a
// bare query param" reasoning as everything else in this flow.
//
// FIXED 2026-09-17: on a failed confirm_booking submit (page reloads via
// POST, not the original ?mode=advance GET), this used to fall straight
// back to the ?mode=advance check above - which is never true on a POST
// - silently resetting the picker to "Soonest available" and discarding
// whatever month the patient had actually chosen, even though nothing
// about their symptoms/month choice was wrong (the failure is almost
// always the SLOT, not this). Now prefers whatever was actually
// submitted (when_choice/start_date) when this is a POST re-render, and
// only falls back to the ?mode=advance query param on the original GET
// load.
$submitted_when_choice = $_POST['when_choice'] ?? null;
$submitted_start_date = $_POST['start_date'] ?? '';
$advance_mode = $submitted_when_choice !== null
    ? ($submitted_when_choice === 'advance')
    : (($_GET['mode'] ?? '') === 'advance');

// Next 3 full calendar months after the current one, for the "choose a
// later month" picker - the normal "soonest available" flow already
// covers the rolling booking_window_days window (15 days by default),
// so this starts from next month rather than overlapping it.
$advance_month_options = [];
for ($m = 0; $m < 3; $m++) {
    $monthStart = (new DateTime('first day of next month'))->modify("+{$m} month");
    $advance_month_options[$monthStart->format('Y-m-d')] = [
        'label' => $monthStart->format('F Y'),
        'num_days' => (int) $monthStart->format('t'),
    ];
}

// ============================================================
// FINAL SUBMIT - the only code path that writes an appointment row.
// Combines what used to be the wizard's separate Step 2/3/4/5
// re-validations into one pass, since there's no session-tracked step
// left to trust - every field the client sent gets checked against the
// database here, from scratch, regardless of what the AJAX endpoint
// said earlier in the page's life.
// ============================================================
if (!$booking_disabled && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_booking') {
    $doctor_id = (int) ($_POST['doctor_id'] ?? 0);
    $slot_start_raw = $_POST['slot_start'] ?? '';

    // Department is derived from the submitted symptom keys, from
    // scratch, same as the AJAX match_symptoms call did - there's no
    // hidden "confirmed_department" input to trust anymore, since the
    // patient never chooses a department directly (see
    // includes/symptom_catalog.php's header comment).
    $match = match_department_from_symptoms($submitted_symptom_keys);
    if (!$match['success']) {
        $errors[] = $match['error'];
    }

    $department_id = null;
    $confirmed_department = null;
    if (!$errors) {
        $confirmed_department = $match['department'];
        $stmt = $conn->prepare("SELECT department_id FROM departments WHERE department_name = ? AND is_active = 1");
        $stmt->bind_param("s", $confirmed_department);
        $stmt->execute();
        $dept_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$dept_row) {
            $errors[] = "This type of care is not currently available for booking. Please contact the front desk.";
        } else {
            $department_id = (int) $dept_row['department_id'];
        }
    }

    // One active appointment total, system-wide (REVERTED 2026-09-15 from
    // the "per department" rule settled 2026-08-04 - see
    // $active_appointments' header comment above). This is the
    // authoritative from-scratch check; $booking_disabled above already
    // keeps most patients from ever reaching this form at all once they
    // have an active appointment, but this re-checks it fresh right
    // before the write, same "trust nothing the client sent" philosophy
    // as every other check in this handler.
    if (!$errors && $department_id !== null) {
        $stmt = $conn->prepare("
            SELECT appointment_id FROM appointments
            WHERE patient_id = ? AND status IN ('pending', 'confirmed')
        ");
        $stmt->bind_param("i", $patient_id);
        $stmt->execute();
        $already_active = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($already_active) {
            $errors[] = "You already have an active appointment. Cancel it first if you'd like to book a different one.";
        }
    }

    // Doctor must exist, be active, and actually belong to that department.
    $doctor_name = null;
    if (!$errors) {
        $stmt = $conn->prepare("
            SELECT u.user_id, u.first_name, u.last_name
            FROM users u
            WHERE u.user_id = ? AND u.role = 'doctor' AND u.is_active = 1 AND u.department_id = ?
        ");
        $stmt->bind_param("ii", $doctor_id, $department_id);
        $stmt->execute();
        $doctor = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$doctor) {
            $errors[] = "Please select a valid doctor for that department.";
        } else {
            $doctor_name = $doctor['first_name'] . ' ' . $doctor['last_name'];
        }
    }

    // Slot: identical re-validation to what the old wizard's Step 4/5 did
    // (on-duty, within duty hours, day capacity, not already taken, not
    // past the booking cutoff), now duration-aware per department - see
    // includes/slot_grid.php's get_department_duration_minutes() and
    // get_clinic_time_slots(), settled 2026-08-06, for why this is no
    // longer a flat 30-minute/11-12-lunch check.
    //
    // FIXED 2026-09-08: resolve_effective_schedule() now runs FIRST, and
    // its start/end feed get_clinic_time_slots() directly - this used to
    // check get_clinic_time_slots($duration_minutes) alone (that
    // function's hardcoded default range) before even knowing the
    // doctor's real hours, so a doctor scheduled outside that default
    // window had valid bookings rejected here as "not a valid clinic
    // time slot" before the duty-hours check further down ever got a
    // chance to correctly pass them.
    $slot_start_clean = null;
    $duration_minutes = null;
    if (!$errors) {
        $slot_start_clean = date('Y-m-d H:i:s', strtotime($slot_start_raw));
        $slot_date_only = date('Y-m-d', strtotime($slot_start_raw));
        $slot_time_only = date('H:i:s', strtotime($slot_start_raw));
        $duration_minutes = get_department_duration_minutes($conn, $department_id);
        $on_duty = resolve_effective_schedule($conn, $doctor_id, $slot_date_only);

        $valid_times_today = $on_duty['on_duty']
            ? get_clinic_time_slots($duration_minutes, $on_duty['start'], $on_duty['end'])
            : get_clinic_time_slots($duration_minutes);

        if (!in_array($slot_time_only, $valid_times_today, true)) {
            $errors[] = "That's not a valid clinic time slot - please choose another.";
        } else {
            $within_duty_hours = true;
            if ($on_duty['on_duty']) {
                $within_duty_hours = ($slot_time_only >= $on_duty['start'] && $slot_time_only < $on_duty['end']);
            }

            $stmt = $conn->prepare("
                SELECT appointment_id FROM appointments
                WHERE doctor_id = ? AND slot_start = ? AND status IN ('pending', 'confirmed', 'completed')
            ");
            $stmt->bind_param("is", $doctor_id, $slot_start_clean);
            $stmt->execute();
            $taken = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $is_full = false;
            if ($on_duty['on_duty'] && $on_duty['max_patients']) {
                $stmt = $conn->prepare("
                    SELECT COUNT(*) AS booked_count FROM appointments
                    WHERE doctor_id = ? AND status IN ('pending', 'confirmed', 'completed')
                      AND DATE(slot_start) = ?
                ");
                $stmt->bind_param("is", $doctor_id, $slot_date_only);
                $stmt->execute();
                $booked_that_day = (int) $stmt->get_result()->fetch_assoc()['booked_count'];
                $stmt->close();
                $is_full = $booked_that_day >= (int) $on_duty['max_patients'];
            }

            if (!$on_duty['on_duty']) {
                $errors[] = "The doctor is not on duty that day - please choose another date.";
            } elseif (!$within_duty_hours) {
                $errors[] = "That time is outside the doctor's duty hours that day - please choose another.";
            } elseif ($is_full) {
                $errors[] = "This doctor is fully booked for that date - please choose another.";
            } elseif ($taken) {
                $errors[] = "This slot was just taken - please choose another.";
            } elseif (time() >= strtotime($slot_start_clean) + ($duration_minutes * 60)) {
                $errors[] = "This time slot is no longer available to book - please choose another.";
            }
        }
    }

    if (!$errors) {
        $slot_end_clean = date('Y-m-d H:i:s', strtotime($slot_start_clean) + ($duration_minutes * 60));

        // symptom_description is now built from the checked symptom
        // labels rather than typed free text - same column, same NOT
        // NULL constraint, just a different source. There's no separate
        // "recommended vs overridden" department anymore (the patient
        // never picks a different one - see this file's top-of-file
        // comment), so recommended_department_id and department_id are
        // always the same value now, and department_override is always
        // 0. Both columns are kept rather than dropped, so existing
        // reports/history reading them don't need a schema change on top
        // of this rework.
        $symptom_description = implode('; ', $match['matched_labels']);
        $recommended_department_id = $department_id;
        $department_override = 0;

        // FIXED (2026-09-14): "@$insert_stmt->execute()" never actually
        // caught the UNIQUE KEY race-condition guard (unique_active_slot on
        // doctor_id + slot_start) - PHP 8.1+'s mysqli throws
        // mysqli_sql_exception on a duplicate-key violation instead of
        // having execute() return false, and @ only suppresses
        // warnings/notices, not thrown exceptions. A genuine race (two
        // submits landing on the same doctor+slot within milliseconds)
        // threw an uncaught fatal instead of showing the intended "just
        // taken" message. Same fix already applied in register.php /
        // patient/profile.php for their own duplicate-key races.
        $insert_stmt = $conn->prepare("
            INSERT INTO appointments
                (patient_id, doctor_id, department_id, symptom_description,
                 recommended_department_id, department_override, slot_start, slot_end, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'confirmed')
        ");
        $insert_stmt->bind_param(
            "iiisiiss",
            $patient_id,
            $doctor_id,
            $department_id,
            $symptom_description,
            $recommended_department_id,
            $department_override,
            $slot_start_clean,
            $slot_end_clean
        );

        try {
            $insert_stmt->execute();
            $new_appointment_id = $conn->insert_id;
            $insert_stmt->close();

            $notif_message = "Your appointment with Dr. " . $doctor_name .
                " (" . $confirmed_department . ") on " .
                date('M j, Y \a\t g:i A', strtotime($slot_start_clean)) . " is confirmed.";
            create_notification($conn, $patient_id, $notif_message, 'dashboard.php');

            $doctor_notif_message = "New appointment: " . $_SESSION['first_name'] . " " . $_SESSION['last_name'] .
                " on " . date('M j, Y \a\t g:i A', strtotime($slot_start_clean)) . ".";
            create_notification($conn, $doctor_id, $doctor_notif_message, "todays-queue.php");

            header("Location: dashboard.php?booked=1");
            exit;
        } catch (mysqli_sql_exception $e) {
            $insert_stmt->close();
            if ($e->getCode() === 1062 && str_contains($e->getMessage(), 'unique_active_appointment_per_patient')) {
                // unique_active_appointment_per_patient (patient_id,
                // active_slot_lock) - a second tab/click raced this same
                // confirm_booking submit and got there first. Re-uses the
                // same wording as the pre-existing app-layer
                // $already_active check above, since this is just that
                // same rule caught one level deeper.
                $errors[] = "You already have an active appointment. Cancel it first if you'd like to book a different one.";
            } elseif ($e->getCode() === 1062) {
                // unique_active_slot (doctor_id, slot_start, active_slot_lock)
                // catching a true race condition.
                $errors[] = "This slot was just taken - please choose another.";
            } else {
                $errors[] = "Something went wrong while booking. Please try again.";
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
    <title>Book an Appointment - GabayMed</title>
    <?php require_once '../includes/asset_helpers.php'; ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('../assets/css/dashboard.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('../assets/css/booking.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('../assets/css/history.css')) ?>">
</head>

<body data-patient-name="<?= htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']) ?>">
    <div class="app-layout">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1 class="page-title">Book an Appointment</h1>
                <?php if (!$booking_disabled): ?>
                    <p class="page-subtitle">Fill in each section below - later sections unlock as you go.</p>
                <?php endif; ?>
            </div>

            <!-- Emergency disclaimer — always visible regardless of booking
                 state, since a patient in an emergency could land here in
                 any state (blocked, already has a pending appointment,
                 mid-flow). Reuses the exact hospital number already shown
                 on dashboard.php's contact card, and the same .policy-card
                 visual language as dashboard.php's own no-show reminder,
                 so it reads as a standing notice rather than a form error. -->
            <div class="policy-card">
                <span class="policy-card-icon">🚨</span>
                <div class="policy-card-text">
                    <strong>This form is not for medical emergencies.</strong>
                    If you or someone with you needs urgent care — chest pain, difficulty breathing, severe bleeding, or anything that can't wait — go directly to the Emergency Room or call <a href="tel:+63433980350">(043) 398 0350</a> now. Do not wait for an appointment.
                </div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="error-banner" id="page-error-banner">
                    <?php foreach ($errors as $error): ?>
                        <div><?= htmlspecialchars($error) ?></div>
                    <?php endforeach; ?>
                    <?php if (!$booking_disabled): ?>
                        <div>Please pick your date &amp; time again below - your symptom selections were kept.</div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($cancelled): ?>
                <div class="success-banner">Your appointment has been cancelled.</div>
            <?php endif; ?>

            <?php if ($account_blocked): ?>
                <!-- ===== Account Blocked ===== -->
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
                <?php if (!empty($active_appointments)): ?>
                    <!-- ===== Your Active Appointments =====
                         REVERTED 2026-09-15 to one active appointment
                         total (was: 0 to one per department). In
                         practice this loop now only ever has 0 or 1
                         items, but it's left as a loop rather than
                         collapsed back to a single block - harmless
                         either way, and avoids two near-identical
                         code paths to keep in sync if the policy ever
                         changes again. -->
                    <div class="card booking-card">
                        <p class="booking-label">Your active appointment:</p>
                        <?php foreach ($active_appointments as $appt): ?>
                            <div class="confirmation-summary" style="border: 1px solid var(--border-color); border-radius: 10px; padding: 4px 16px; margin-bottom: 14px;">
                                <div class="confirmation-row">
                                    <span class="confirmation-key">Reference No.</span>
                                    <span class="confirmation-value confirmation-value-mono"><?= htmlspecialchars(format_appointment_reference($appt['appointment_id'], $appt['created_at'])) ?></span>
                                </div>
                                <div class="confirmation-row">
                                    <span class="confirmation-key">Department</span>
                                    <span class="confirmation-value"><?= htmlspecialchars($appt['department_name']) ?></span>
                                </div>
                                <div class="confirmation-row">
                                    <span class="confirmation-key">Doctor</span>
                                    <span class="confirmation-value">Dr. <?= htmlspecialchars($appt['first_name'] . ' ' . $appt['last_name']) ?></span>
                                </div>
                                <div class="confirmation-row">
                                    <span class="confirmation-key">Date &amp; Time</span>
                                    <span class="confirmation-value"><?= date('l, F j, Y \a\t g:i A', strtotime($appt['slot_start'])) ?></span>
                                </div>
                                <div class="confirmation-row">
                                    <span class="confirmation-key">Status</span>
                                    <span class="confirmation-value">
                                        <span class="status-chip status-chip-<?= htmlspecialchars($appt['status']) ?>">
                                            <?= ucwords(str_replace('_', ' ', $appt['status'])) ?>
                                        </span>
                                    </span>
                                </div>
                                <div style="padding: 12px 0;">
                                    <button type="button" class="btn btn-cancel open-cancel-modal-btn"
                                        data-appointment-id="<?= (int) $appt['appointment_id'] ?>"
                                        data-doctor-name="<?= htmlspecialchars($appt['first_name'] . ' ' . $appt['last_name']) ?>"
                                        data-slot-label="<?= htmlspecialchars(date('l, F j, Y \a\t g:i A', strtotime($appt['slot_start']))) ?>"
                                        data-would-be-late="<?= is_late_cancellation($conn, $appt['slot_start']) ? '1' : '0' ?>">
                                        Cancel Appointment
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <p style="font-size:13px; color:var(--text-muted); margin-top:-4px;">
                            You have an active appointment, so booking a new one is disabled. Cancel the appointment above first if you'd like to book something else.
                        </p>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

            <?php if (!$booking_disabled): ?>
                <!-- ===== Single-page booking form =====
                     Sections 2-3 render locked/greyed-out. JS
                     (assets/js/book-appointment.js) unlocks each one as the
                     section above it is completed, using
                     book-appointment-actions.php's match_symptoms action to
                     turn checked symptoms straight into an auto-assigned
                     doctor + that doctor's slot grid in one call. The final
                     "Confirm Booking" button is the only real form submit -
                     everything before it is just AJAX populating the page.
                     The right-hand summary panel is purely cosmetic - a
                     live readout of the same hidden inputs the form already
                     submits, kept in sync by the same JS functions that
                     unlock each section. -->
                <form method="POST" action="book-appointment.php" id="booking-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="confirm_booking">
                    <input type="hidden" name="doctor_id" id="doctor_id_input" value="">
                    <input type="hidden" name="slot_start" id="slot_start_input" value="">

                    <div class="booking-layout">
                        <div class="booking-main">

                            <!-- Section 1: Symptoms -->
                            <section class="card booking-card booking-section" id="section-1">
                                <div class="booking-section-header">
                                    <span class="section-number">1</span>
                                    <h2 class="section-title">What's the reason for your visit?</h2>
                                </div>
                                <p style="font-size:13.5px; color:var(--text-muted); margin:-6px 0 16px;">
                                    Check everything that applies. We'll match you to the right department and doctor automatically.
                                </p>

                                <?php foreach ($symptom_groups as $groupLabel => $keys): ?>
                                    <fieldset class="symptom-group">
                                        <legend class="symptom-group-title"><?= htmlspecialchars($groupLabel) ?></legend>
                                        <div class="symptom-group-items">
                                            <?php foreach ($keys as $key): ?>
                                                <label class="symptom-checkbox">
                                                    <input type="checkbox" name="symptoms[]" value="<?= htmlspecialchars($key) ?>"
                                                        onchange="GabayBooking.onSymptomsChanged()"
                                                        <?= in_array($key, $submitted_symptom_keys, true) ? 'checked' : '' ?>>
                                                    <span><?= htmlspecialchars($symptom_catalog[$key]['label']) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </fieldset>
                                <?php endforeach; ?>

                                <!-- When would you like to be seen? (2026-09-17, advance
                                     booking; re-skinned as icon cards 2026-09-17 per
                                     feedback that the radio-styled rows read as more
                                     checkboxes right below the actual symptom checkboxes.
                                     See $advance_mode's PHP comment above for why nothing
                                     server-side trusts the ?mode=advance query param
                                     itself. start_date/num_days hidden inputs are
                                     populated by GabayBooking.onWhenChanged() and read by
                                     matchSymptoms(); left empty for "soonest available" so
                                     the server just uses its normal default window.
                                     "Soonest available" is the pre-selected default card -
                                     no click needed to use it, same as the old default-
                                     checked radio behaved; only picking "A specific month"
                                     needs a click, since that's the one that needs to
                                     reveal the month dropdown. -->
                                <div class="booking-when-picker">
                                    <p class="booking-label" style="margin-bottom:10px;">When would you like to be seen?</p>
                                    <div class="when-card-grid">
                                        <label class="when-card" id="when-card-soonest">
                                            <input type="radio" name="when_choice" value="soonest" id="when-soonest"
                                                onchange="GabayBooking.onWhenChanged()" <?= !$advance_mode ? 'checked' : '' ?>>
                                            <span class="when-card-icon" aria-hidden="true">🕐</span>
                                            <span class="when-card-label">Soonest available</span>
                                        </label>
                                        <label class="when-card" id="when-card-advance">
                                            <input type="radio" name="when_choice" value="advance" id="when-advance"
                                                onchange="GabayBooking.onWhenChanged()" <?= $advance_mode ? 'checked' : '' ?>>
                                            <span class="when-card-icon" aria-hidden="true">📅</span>
                                            <span class="when-card-label">A specific month</span>
                                        </label>
                                    </div>
                                    <div id="advance-month-wrapper" style="<?= $advance_mode ? '' : 'display:none;' ?> margin-top:10px;">
                                        <select id="advance-month-select" class="form-input" style="width:auto;"
                                            onchange="GabayBooking.onWhenChanged()">
                                            <?php foreach ($advance_month_options as $startDate => $opt): ?>
                                                <option value="<?= htmlspecialchars($startDate) ?>" data-num-days="<?= (int) $opt['num_days'] ?>"
                                                    <?= ($startDate === $submitted_start_date) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($opt['label']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <input type="hidden" name="start_date" id="start_date_input" value="">
                                <input type="hidden" name="num_days" id="num_days_input" value="">

                                <div class="error-inline" id="symptom-error"></div>
                                <button type="button" class="btn btn-primary" id="continue-btn" disabled
                                    onclick="GabayBooking.matchSymptoms()">Continue</button>
                            </section>

                            <!-- Section 2: Date & time (doctor is auto-assigned - see
                                 the "Assigned doctor" line match_symptoms fills in
                                 above this section's content once matched) -->
                            <section class="card booking-card booking-section booking-section-locked" id="section-2">
                                <div class="booking-section-header">
                                    <span class="section-number">2</span>
                                    <h2 class="section-title">Pick a date &amp; time</h2>
                                </div>
                                <div id="slot-content">
                                    <p class="empty-hint">Check your symptoms above to continue.</p>
                                </div>
                            </section>

                            <!-- Section 3: Review & confirm -->
                            <section class="card booking-card booking-section booking-section-locked" id="section-3">
                                <div class="booking-section-header">
                                    <span class="section-number">3</span>
                                    <h2 class="section-title">Review &amp; confirm</h2>
                                </div>
                                <div id="review-content">
                                    <p class="empty-hint">Pick a date and time above to review your booking.</p>
                                </div>
                            </section>

                        </div>

                        <aside class="booking-summary">
                            <div class="card summary-card">
                                <p class="summary-card-title">Your progress</p>

                                <div class="summary-step" id="summary-step-1">
                                    <span class="summary-step-icon">1</span>
                                    <div class="summary-step-text">
                                        <div class="summary-step-label">Symptoms</div>
                                        <div class="summary-step-value" id="summary-value-1">Not selected yet</div>
                                    </div>
                                </div>

                                <div class="summary-step" id="summary-step-2">
                                    <span class="summary-step-icon">2</span>
                                    <div class="summary-step-text">
                                        <div class="summary-step-label">Assigned doctor</div>
                                        <div class="summary-step-value" id="summary-value-2">Not matched yet</div>
                                    </div>
                                </div>

                                <div class="summary-step" id="summary-step-3">
                                    <span class="summary-step-icon">3</span>
                                    <div class="summary-step-text">
                                        <div class="summary-step-label">Date &amp; time</div>
                                        <div class="summary-step-value" id="summary-value-3">Not selected yet</div>
                                    </div>
                                </div>
                            </div>
                        </aside>
                    </div>
                </form>
            <?php endif; ?>

        </main>

    </div>

    <!-- Cancel confirmation modal — ONE shared modal + form for however
         many active appointments are listed above (0 to one per
         department), populated dynamically via JS from the clicked
         card's data-* attributes, rather than one modal per appointment.
         Reuses history.css's .modal-* classes (already loaded on this
         page) instead of the old native confirm(). -->
    <?php if (!empty($active_appointments)): ?>
        <form method="POST" action="book-appointment.php" id="cancel-appointment-form" style="display:none;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="appointment_id" id="cancel-appointment-id-input" value="">
            <input type="hidden" name="reason" id="cancel-reason-input" value="">
        </form>

        <div class="modal-backdrop" id="cancel-modal-backdrop"></div>
        <div class="modal-card" id="cancel-modal" role="dialog" aria-modal="true" aria-labelledby="cancel-modal-title">
            <div class="modal-header">
                <h2 class="modal-title" id="cancel-modal-title">Cancel appointment?</h2>
                <button class="modal-close-btn" type="button" id="cancel-modal-close-btn" aria-label="Close">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <p id="cancel-modal-text">
                    You're about to cancel this appointment. This can't be undone.
                </p>
                <p id="cancel-modal-late-warning" style="display:none; margin-top:12px; padding:12px; background:#FEF3E2; border-radius:8px; color:#8A5A00; font-size:14px;">
                    This is within 2 hours of your scheduled time — it will be logged as a late cancellation.
                </p>
                <div class="form-group" style="text-align:left; margin-top:16px;">
                    <label for="cancel-modal-reason" class="form-label">Reason for cancellation (required)</label>
                    <textarea id="cancel-modal-reason" class="form-textarea" rows="3" maxlength="500"
                        placeholder="e.g. Schedule conflict came up, can't make it that day"></textarea>
                    <p class="scan-inline-error" id="cancel-modal-reason-error" hidden>Please tell us why you'd like to cancel.</p>
                </div>
                <div style="display:flex; gap:12px; margin-top:20px;">
                    <button type="button" class="btn btn-secondary" id="cancel-modal-keep-btn" style="flex:1;">No, Keep Appointment</button>
                    <button type="button" class="btn btn-cancel" id="cancel-modal-confirm-btn" style="flex:1;">Cancel Appointment</button>
                </div>
            </div>
        </div>

        <script>
            (function() {
                const backdrop = document.getElementById('cancel-modal-backdrop');
                const modal = document.getElementById('cancel-modal');
                const form = document.getElementById('cancel-appointment-form');
                const idInput = document.getElementById('cancel-appointment-id-input');
                const textEl = document.getElementById('cancel-modal-text');
                const lateWarningEl = document.getElementById('cancel-modal-late-warning');
                const reasonInput = document.getElementById('cancel-modal-reason');
                const reasonError = document.getElementById('cancel-modal-reason-error');
                const reasonHidden = document.getElementById('cancel-reason-input');

                function openModal() {
                    backdrop.classList.add('modal-visible');
                    modal.classList.add('modal-visible');
                    document.body.classList.add('modal-open');
                }

                function closeModal() {
                    backdrop.classList.remove('modal-visible');
                    modal.classList.remove('modal-visible');
                    document.body.classList.remove('modal-open');
                }

                // Event delegation on one listener, not one per card - this
                // list can have as many cards as there are departments.
                document.querySelectorAll('.open-cancel-modal-btn').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        idInput.value = btn.dataset.appointmentId;
                        textEl.textContent = "You're about to cancel your appointment with Dr. " +
                            btn.dataset.doctorName + " on " + btn.dataset.slotLabel + ". This can't be undone.";
                        lateWarningEl.style.display = btn.dataset.wouldBeLate === '1' ? 'block' : 'none';
                        reasonInput.value = '';
                        reasonError.hidden = true;
                        openModal();
                    });
                });

                document.getElementById('cancel-modal-keep-btn').addEventListener('click', closeModal);
                document.getElementById('cancel-modal-close-btn').addEventListener('click', closeModal);
                backdrop.addEventListener('click', closeModal);
                document.getElementById('cancel-modal-confirm-btn').addEventListener('click', function() {
                    const reason = reasonInput.value.trim();
                    if (reason === '') {
                        reasonError.hidden = false;
                        reasonInput.focus();
                        return;
                    }
                    reasonError.hidden = true;
                    reasonHidden.value = reason;
                    form.submit();
                });
            })();
        </script>
    <?php endif; ?>

    <?php
    // Cache-busting via filemtime, not a hardcoded version string - the
    // query string changes automatically whenever this file is actually
    // edited on disk, so the browser is forced to re-fetch instead of
    // serving a stale cached copy. Was the actual cause of a real
    // "the JS fix isn't showing up even though I replaced the file"
    // report - the browser kept the pre-fix book-appointment.js cached
    // since the <script> URL never changed.
    $bookAppointmentJsPath = __DIR__ . '/../assets/js/book-appointment.js';
    $bookAppointmentJsVersion = file_exists($bookAppointmentJsPath) ? filemtime($bookAppointmentJsPath) : time();
    ?>
    <script src="../assets/js/book-appointment.js?v=<?= $bookAppointmentJsVersion ?>"></script>
</body>

</html>