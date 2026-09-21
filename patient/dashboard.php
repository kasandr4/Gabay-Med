<?php
// patient/dashboard.php
// Implements spec 1.4 — Personal Dashboard.
// This is the patient's home base after login.

require_once '../includes/auth_guard.php';
require_role('patient');
require_once '../config/db.php';
require_once '../includes/reference_number.php';

$active_page = 'dashboard';

// --- Fetch this patient's data ---
$user_id = $_SESSION['user_id'];

// Check account status FIRST — per spec, blocked/confined patients see an
// entirely different screen, not this normal dashboard.
$stmt = $conn->prepare("SELECT status, no_show_count FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$patient_row = $stmt->get_result()->fetch_assoc();
$patient_status = $patient_row['status'];
$no_show_count = $patient_row['no_show_count'];
$stmt->close();

if ($patient_status === 'blocked') {
    header("Location: blocked.php");
    exit;
}
if ($patient_status === 'confined') {
    header("Location: confinement-dashboard.php?blocked=dashboard");
    exit;
}

// Pull ALL upcoming appointments, not just the soonest one.
//
// FIXED 2026-08-08: this used to be LIMIT 1 / fetch_assoc, from before
// "one active appointment per department" was decided (see
// patient/book-appointment.php's $active_appointments header comment,
// settled 2026-08-04). Once a patient could hold more than one active
// appointment at a time, this silently hid every appointment except
// whichever was soonest - the FIRST screen a patient sees after login
// was quietly incomplete for anyone with concurrent care across
// departments, with no indication a second appointment even existed
// unless they specifically visited book-appointment.php's own list.
$stmt = $conn->prepare("
    SELECT a.appointment_id, a.status, a.slot_start, a.created_at, d.department_name, u.first_name AS doctor_first, u.last_name AS doctor_last
    FROM appointments a
    JOIN departments d ON a.department_id = d.department_id
    JOIN users u ON a.doctor_id = u.user_id
    WHERE a.patient_id = ? AND a.status IN ('pending', 'confirmed')
    ORDER BY a.slot_start ASC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$upcoming_appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// For the "just booked" banner: the newest one by created_at, NOT the
// first in $upcoming_appointments (which is sorted by soonest slot_start
// for display, so the appointment the patient just booked might not be
// first in that list at all if they already had an earlier one pending
// in a different department).
$just_booked_appointment = null;
foreach ($upcoming_appointments as $appt) {
    if ($just_booked_appointment === null || $appt['created_at'] > $just_booked_appointment['created_at']) {
        $just_booked_appointment = $appt;
    }
}

// Upcoming follow-ups (2026-08-08): follow-up-process.php notifies the
// patient with a link to this page, but until now nothing here (or on
// appointment-history.php, which is past-visits-only by design - see its
// own header comment) ever actually showed a follow_ups row. The
// notification pointed at a page that couldn't show what it promised.
$stmt = $conn->prepare("
    SELECT f.follow_up_id, f.followup_date, f.followup_time, f.reason,
           u.first_name AS doctor_first, u.last_name AS doctor_last
    FROM follow_ups f
    JOIN users u ON u.user_id = f.doctor_id
    WHERE f.patient_id = ? AND f.status = 'scheduled' AND f.followup_date >= CURDATE()
    ORDER BY f.followup_date ASC, f.followup_time ASC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$upcoming_followups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$just_booked = isset($_GET['booked']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - GabayMed</title>
    <?php require_once '../includes/asset_helpers.php'; ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('../assets/css/dashboard.css')) ?>">
    <script src="../assets/js/qrcode.min.js"></script>
</head>

<body>

    <div class="app-layout">

        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">

            <div class="page-header">
                <h1 class="page-title">Welcome, <?= htmlspecialchars($_SESSION['first_name']) ?></h1>
                <p class="page-subtitle">Here's an overview of your care.</p>
            </div>

            <!-- Appointment policy reminder — always visible, near the top -->
            <div class="policy-card">
                <span class="policy-card-icon">⏰</span>
                <div class="policy-card-text">
                    <strong>Please arrive on time for appointments.</strong>
                    Slots more than 30 minutes late may be given to walk-ins, and <strong>3 missed appointments</strong> will temporarily block your account.
                </div>
            </div>

            <?php if ($just_booked && $just_booked_appointment): ?>
                <div class="success-banner">
                    Your appointment has been booked successfully.
                    Your reference number is <strong><?= htmlspecialchars(format_appointment_reference($just_booked_appointment['appointment_id'], $just_booked_appointment['created_at'])) ?></strong> — please keep this for your records.
                </div>
            <?php endif; ?>

            <!-- ============ SECTION: Your Care ============ -->
            <h2 class="section-header">Your Care</h2>

            <!-- Status banner -->
            <div class="status-banner status-banner-active">
                <span class="status-dot"></span>
                Active
            </div>

            <!-- No-show warning — only rendered if no_show_count > 0 -->
            <?php if ($no_show_count > 0): ?>
                <div class="warning-banner">
                    <?= $no_show_count ?> of 3 — one more missed visit may block your account.
                </div>
            <?php endif; ?>

            <!-- Book an Appointment card (2026-09-17) — two entry points into
                 patient/book-appointment.php: the normal flow (soonest
                 available, rolling ~15-day window) and "Advance Booking"
                 (?mode=advance, pre-selects a later-month picker on that
                 page — see its own $advance_mode comment). Shown
                 unconditionally, even when the patient already has an
                 active appointment; book-appointment.php itself is what
                 enforces "one active appointment at a time" and shows the
                 right messaging for that, so this card doesn't need to
                 duplicate that check just to decide whether to render. -->
            <div class="card">
                <div class="card-title">Book an Appointment</div>
                <p style="font-size:13.5px; color:var(--text-muted); margin:-4px 0 14px;">
                    Need to be seen soon, or want to plan ahead? Either way, we'll match you to the right department and doctor automatically.
                </p>
                <div style="display:flex; gap:12px; flex-wrap:wrap;">
                    <a href="book-appointment.php" class="btn btn-primary">Book Appointment</a>
                    <a href="book-appointment.php?mode=advance" class="btn btn-secondary">Advance Booking (next month+)</a>
                </div>
            </div>

            <!-- Upcoming Appointment(s) card -->
            <div class="card">
                <div class="card-title">
                    Upcoming Appointment<?= count($upcoming_appointments) > 1 ? 's' : '' ?>
                </div>

                <?php if (!empty($upcoming_appointments)): ?>
                    <?php foreach ($upcoming_appointments as $i => $appt): ?>
                        <?php $ref_number = format_appointment_reference($appt['appointment_id'], $appt['created_at']); ?>
                        <div class="appointment-card-wrapper" <?= $i > 0 ? 'style="margin-top:18px; padding-top:18px; border-top:1px solid var(--border-color);"' : '' ?>>
                            <a href="appointment-detail.php?appointment_id=<?= $appt['appointment_id'] ?>" class="appointment-card appointment-card-clickable">
                                <div class="appointment-info">
                                    <div class="appointment-department"><?= htmlspecialchars($appt['department_name']) ?></div>
                                    <div class="appointment-meta">
                                        Dr. <?= htmlspecialchars($appt['doctor_last']) ?>
                                        &middot; <?= date('M j, Y \a\t g:i A', strtotime($appt['slot_start'])) ?>
                                    </div>
                                    <div class="appointment-reference">
                                        Ref: <?= htmlspecialchars($ref_number) ?>
                                    </div>
                                </div>
                                <span class="status-chip status-chip-<?= htmlspecialchars($appt['status']) ?>">
                                    <?= ucfirst(htmlspecialchars($appt['status'])) ?>
                                </span>
                            </a>

                            <div class="qr-code-box">
                                <div id="qr-code-canvas-<?= (int) $appt['appointment_id'] ?>"></div>
                                <div class="qr-code-label">Show this at the front desk</div>
                            </div>
                        </div>

                        <script>
                            new QRCode(document.getElementById("qr-code-canvas-<?= (int) $appt['appointment_id'] ?>"), {
                                text: <?= json_encode($ref_number) ?>,
                                width: 96,
                                height: 96,
                                colorDark: "#1F2D2D",
                                colorLight: "#ffffff",
                                correctLevel: QRCode.CorrectLevel.M
                            });
                        </script>
                    <?php endforeach; ?>

                    <div class="what-to-bring">
                        <div class="what-to-bring-header">
                            <span class="what-to-bring-icon">🎒</span>
                            <span class="what-to-bring-title">What to bring</span>
                        </div>
                        <ul class="what-to-bring-list">
                            <li>Valid ID</li>
                            <li>PhilHealth ID (if available)</li>
                            <li>Previous medical records or referral, if any</li>
                        </ul>
                        <div class="what-to-bring-note">
                            <span>⏰</span> Please arrive at least 15 minutes early.
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <p class="empty-state-text">No upcoming appointments yet.</p>
                        <a href="book-appointment.php" class="btn btn-primary">Book your first appointment</a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Upcoming Follow-Up(s) card (2026-08-08) - see header comment
                 above $upcoming_followups for why this exists: the
                 notification sent when a doctor schedules one needs an
                 actual page to point to. -->
            <?php if (!empty($upcoming_followups)): ?>
                <div class="card">
                    <div class="card-title">
                        Upcoming Follow-Up<?= count($upcoming_followups) > 1 ? 's' : '' ?>
                    </div>
                    <?php foreach ($upcoming_followups as $i => $fu): ?>
                        <div class="appointment-card-wrapper" <?= $i > 0 ? 'style="margin-top:18px; padding-top:18px; border-top:1px solid var(--border-color);"' : '' ?>>
                            <div class="appointment-card">
                                <div class="appointment-info">
                                    <div class="appointment-department">
                                        Follow-up with Dr. <?= htmlspecialchars(trim($fu['doctor_first'] . ' ' . $fu['doctor_last'])) ?>
                                    </div>
                                    <div class="appointment-meta">
                                        <?= date('M j, Y', strtotime($fu['followup_date'])) ?>
                                        <?php if ($fu['followup_time']): ?>
                                            &middot; <?= date('g:i A', strtotime($fu['followup_time'])) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="appointment-reference">
                                        <?= htmlspecialchars($fu['reason']) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="what-to-bring-note" style="margin-top:14px;">
                        <span>ℹ️</span> This is a reminder from your doctor, not a booked time slot. Please arrive within normal clinic hours (8AM-4PM) on the date shown - no need to check in at a precise time for this.
                    </div>
                </div>
            <?php endif; ?>

            <!-- ============ SECTION: Hospital Info ============ -->
            <h2 class="section-header">Hospital Info</h2>

            <!-- Emergency / hospital contact card -->
            <div class="card contact-card">
                <div class="card-title">Hospital Contact Information</div>
                <div class="contact-row">
                    <span class="contact-icon">📍</span>
                    <span>Papandayan, Pinamalayan, Oriental Mindoro</span>
                </div>
                <div class="contact-row">
                    <span class="contact-icon">📞</span>
                    <a href="tel:+63433980350">(043) 398 0350</a>
                </div>
                <div class="contact-row">
                    <span class="contact-icon">✉️</span>
                    <a href="mailto:omcdh@ormindoro.gov.ph">omcdh@ormindoro.gov.ph</a>
                </div>
                <p class="contact-note">For medical emergencies, please go directly to the Emergency Room or call the number above — do not wait for an appointment confirmation.</p>
            </div>

        </main>

    </div>

</body>

</html>