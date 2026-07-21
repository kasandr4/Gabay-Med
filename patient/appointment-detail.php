<?php
// patient/appointment-detail.php
// Detail view for the Upcoming Appointment card on the dashboard (spec 1.4).
// Shows department, doctor, exact time, and an optional Cancel action
// (marked as stretch/optional in spec, but included here since it's
// genuinely useful for patients).

require_once '../includes/auth_guard.php';
require_role('patient');
require_once '../config/db.php';
require_once '../includes/notifications.php';
require_once '../includes/reference_number.php';

$patient_id = $_SESSION['user_id'];
$errors = [];
$cancelled = false;

$appointment_id = (int)($_GET['appointment_id'] ?? 0);

// Handle cancel submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel') {
    // Re-validate ownership AND that it's still an active (pending/confirmed)
    // appointment before cancelling — protects against double-cancel races
    // or a patient trying to cancel someone else's appointment by URL-guessing.
    $stmt = $conn->prepare("
        SELECT appointment_id, doctor_id, slot_start FROM appointments
        WHERE appointment_id = ? AND patient_id = ? AND status IN ('pending', 'confirmed')
    ");
    $stmt->bind_param("ii", $appointment_id, $patient_id);
    $stmt->execute();
    $valid = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$valid) {
        $errors[] = "This appointment can no longer be cancelled.";
    } else {
        $stmt = $conn->prepare("UPDATE appointments SET status = 'cancelled' WHERE appointment_id = ? AND patient_id = ?");
        $stmt->bind_param("ii", $appointment_id, $patient_id);
        $stmt->execute();
        $stmt->close();

        create_notification($conn, $patient_id, "Your appointment has been cancelled.", 'dashboard.php');

        // Notify the doctor of the cancellation
        $doctor_notif_message = "Appointment cancelled: " . $_SESSION['first_name'] . " " . $_SESSION['last_name'] .
            " on " . date('M j, Y \a\t g:i A', strtotime($valid['slot_start'])) . ".";
        create_notification($conn, $valid['doctor_id'], $doctor_notif_message, "todays-queue.php");

        $cancelled = true;
    }
}

// Fetch the appointment (re-fetch even after cancel, to show the updated status)
$stmt = $conn->prepare("
    SELECT a.*, d.department_name, doc.first_name AS doctor_first, doc.last_name AS doctor_last
    FROM appointments a
    JOIN departments d ON a.department_id = d.department_id
    JOIN users doc ON a.doctor_id = doc.user_id
    WHERE a.appointment_id = ? AND a.patient_id = ?
");
$stmt->bind_param("ii", $appointment_id, $patient_id);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointment Details - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/booking.css">
    <link rel="stylesheet" href="../assets/css/history.css">
</head>

<body>
    <div class="app-layout">
        <?php $active_page = 'dashboard';
        include 'includes/sidebar.php'; ?>
        <main class="main-content">

            <div class="page-header">
                <a href="dashboard.php" class="link-back-top">&larr; Back to Dashboard</a>
                <h1 class="page-title">Appointment Details</h1>
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

            <?php if (!$appointment): ?>
                <div class="card">
                    <div class="empty-state">
                        <p class="empty-state-text">Appointment not found.</p>
                        <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="card booking-card">
                    <div class="confirmation-summary">
                        <div class="confirmation-row">
                            <span class="confirmation-key">Reference No.</span>
                            <span class="confirmation-value confirmation-value-mono"><?= htmlspecialchars(format_appointment_reference($appointment['appointment_id'], $appointment['created_at'])) ?></span>
                        </div>
                        <div class="confirmation-row">
                            <span class="confirmation-key">Department</span>
                            <span class="confirmation-value"><?= htmlspecialchars($appointment['department_name']) ?></span>
                        </div>
                        <div class="confirmation-row">
                            <span class="confirmation-key">Doctor</span>
                            <span class="confirmation-value">Dr. <?= htmlspecialchars($appointment['doctor_first'] . ' ' . $appointment['doctor_last']) ?></span>
                        </div>
                        <div class="confirmation-row">
                            <span class="confirmation-key">Date & Time</span>
                            <span class="confirmation-value"><?= date('l, F j, Y \a\t g:i A', strtotime($appointment['slot_start'])) ?></span>
                        </div>
                        <div class="confirmation-row">
                            <span class="confirmation-key">Status</span>
                            <span class="confirmation-value">
                                <span class="status-chip status-chip-<?= htmlspecialchars($appointment['status']) ?>">
                                    <?= ucwords(str_replace('_', ' ', $appointment['status'])) ?>
                                </span>
                            </span>
                        </div>
                    </div>

                    <?php if (in_array($appointment['status'], ['pending', 'confirmed'])): ?>
                        <form method="POST" action="appointment-detail.php?appointment_id=<?= $appointment_id ?>"
                            onsubmit="return confirm('Are you sure you want to cancel this appointment?');">
                            <input type="hidden" name="action" value="cancel">
                            <button type="submit" class="btn btn-cancel">Cancel Appointment</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </main>
    </div>
</body>

</html>