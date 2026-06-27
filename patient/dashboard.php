<?php
// patient/dashboard.php
// Implements spec 1.4 — Personal Dashboard.
// This is the patient's home base after login.

require_once '../includes/auth_guard.php';
require_role('patient');
require_once '../config/db.php';

$active_page = 'dashboard';

// --- Fetch this patient's data ---
$user_id = $_SESSION['user_id'];

// NOTE: appointments table doesn't exist yet (booking flow 1.5 isn't built),
// so for now we treat every patient as having NO upcoming appointment.
// Once 1.5 is built, this query will pull the real pending/confirmed row:
//
//   SELECT a.*, d.department_name, doc.first_name AS doctor_first, doc.last_name AS doctor_last
//   FROM appointments a
//   JOIN departments d ON a.department_id = d.department_id
//   JOIN users doc ON a.doctor_id = doc.user_id
//   WHERE a.patient_id = ? AND a.status IN ('pending', 'confirmed')
//   ORDER BY a.slot_start ASC LIMIT 1
$upcoming_appointment = null;

// NOTE: no_show_count doesn't exist as a column yet — defaulting to 0.
// When the booking/attendance system is built, pull this from the users table.
$no_show_count = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
</head>
<body>

<div class="app-layout">

    <?php include 'includes/sidebar.php'; ?>

    <main class="main-content">

        <div class="page-header">
            <h1 class="page-title">Welcome, <?= htmlspecialchars($_SESSION['first_name']) ?></h1>
            <p class="page-subtitle">Here's an overview of your care.</p>
        </div>

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

            <?php if ($upcoming_appointment): ?>
                <!-- This branch will render once the booking flow (1.5) exists -->
                <div class="appointment-card">
                    <div class="appointment-info">
                        <div class="appointment-department"><?= htmlspecialchars($upcoming_appointment['department_name']) ?></div>
                        <div class="appointment-meta">
                            Dr. <?= htmlspecialchars($upcoming_appointment['doctor_last']) ?>
                            &middot; <?= htmlspecialchars($upcoming_appointment['slot_start']) ?>
                        </div>
                    </div>
                    <span class="status-chip status-chip-<?= htmlspecialchars($upcoming_appointment['status']) ?>">
                        <?= ucfirst(htmlspecialchars($upcoming_appointment['status'])) ?>
                    </span>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <p class="empty-state-text">No upcoming appointments yet.</p>
                    <a href="book-appointment.php" class="btn btn-primary">Book your first appointment</a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Quick action links -->
        <div class="quick-actions">
            <a href="book-appointment.php" class="quick-action-card">
                <div class="quick-action-label">📅 Book an Appointment</div>
            </a>
            <a href="appointment-history.php" class="quick-action-card">
                <div class="quick-action-label">🗂️ View History</div>
            </a>
            <a href="prescriptions.php" class="quick-action-card">
                <div class="quick-action-label">💊 View Prescriptions</div>
            </a>
        </div>

    </main>

</div>

</body>
</html>
