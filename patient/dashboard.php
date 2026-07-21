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

// Pull the real upcoming appointment now that the booking flow (1.5) exists
$stmt = $conn->prepare("
    SELECT a.appointment_id, a.status, a.slot_start, a.created_at, d.department_name, u.first_name AS doctor_first, u.last_name AS doctor_last
    FROM appointments a
    JOIN departments d ON a.department_id = d.department_id
    JOIN users u ON a.doctor_id = u.user_id
    WHERE a.patient_id = ? AND a.status IN ('pending', 'confirmed')
    ORDER BY a.slot_start ASC LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$upcoming_appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();

$just_booked = isset($_GET['booked']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
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

            <?php if ($just_booked && $upcoming_appointment): ?>
                <div class="success-banner">
                    Your appointment has been booked successfully.
                    Your reference number is <strong><?= htmlspecialchars(format_appointment_reference($upcoming_appointment['appointment_id'], $upcoming_appointment['created_at'])) ?></strong> — please keep this for your records.
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

            <!-- Upcoming Appointment card -->
            <div class="card">
                <div class="card-title">Upcoming Appointment</div>

                <?php if ($upcoming_appointment):
                    $ref_number = format_appointment_reference($upcoming_appointment['appointment_id'], $upcoming_appointment['created_at']);
                ?>
                    <div class="appointment-card-wrapper">
                        <a href="appointment-detail.php?appointment_id=<?= $upcoming_appointment['appointment_id'] ?>" class="appointment-card appointment-card-clickable">
                            <div class="appointment-info">
                                <div class="appointment-department"><?= htmlspecialchars($upcoming_appointment['department_name']) ?></div>
                                <div class="appointment-meta">
                                    Dr. <?= htmlspecialchars($upcoming_appointment['doctor_last']) ?>
                                    &middot; <?= date('M j, Y \a\t g:i A', strtotime($upcoming_appointment['slot_start'])) ?>
                                </div>
                                <div class="appointment-reference">
                                    Ref: <?= htmlspecialchars($ref_number) ?>
                                </div>
                            </div>
                            <span class="status-chip status-chip-<?= htmlspecialchars($upcoming_appointment['status']) ?>">
                                <?= ucfirst(htmlspecialchars($upcoming_appointment['status'])) ?>
                            </span>
                        </a>

                        <div class="qr-code-box">
                            <div id="qr-code-canvas"></div>
                            <div class="qr-code-label">Show this at the front desk</div>
                        </div>
                    </div>

                    <script>
                        new QRCode(document.getElementById("qr-code-canvas"), {
                            text: <?= json_encode($ref_number) ?>,
                            width: 96,
                            height: 96,
                            colorDark: "#1F2D2D",
                            colorLight: "#ffffff",
                            correctLevel: QRCode.CorrectLevel.M
                        });
                    </script>

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