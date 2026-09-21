<?php
// patient/appointment-detail.php
// Detail view for the Upcoming Appointment card on the dashboard (spec 1.4).
// Shows department, doctor, exact time, and an optional Cancel action
// (marked as stretch/optional in spec, but included here since it's
// genuinely useful for patients).

require_once '../includes/auth_guard.php';
require_role('patient');
require_once '../config/db.php';
require_active_patient($conn);
require_once '../includes/notifications.php';
require_once '../includes/reference_number.php';
require_once '../includes/cancellation_policy.php';
require_once '../includes/csrf.php';

$patient_id = $_SESSION['user_id'];
$errors = [];
$cancelled = false;

$appointment_id = (int)($_GET['appointment_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('appointment-detail.php?appointment_id=' . $appointment_id);
}

// Direct cancellation (2026-09-17: reverted back from the admin-gated
// request/review flow — see includes/cancellation_policy.php's header
// comment). Same shared cancel_patient_appointment() book-appointment.php
// uses, so both entry points stay in sync.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel') {
    $reason = $_POST['reason'] ?? '';

    $stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $patient_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $patient_name = trim(($patient_row['first_name'] ?? '') . ' ' . ($patient_row['last_name'] ?? ''));

    $cancel_result = cancel_patient_appointment($conn, $appointment_id, $patient_id, $patient_name, $reason);

    if (!$cancel_result['success']) {
        $errors[] = $cancel_result['error'];
    } else {
        $cancelled = true;
    }
}

// Fetch the appointment (re-fetch even after a cancel, to show current state)
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
    <?php require_once '../includes/asset_helpers.php'; ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('../assets/css/dashboard.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('../assets/css/booking.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('../assets/css/history.css')) ?>">
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
                        <?php if (!empty($appointment['cancellation_reason'])): ?>
                            <div class="confirmation-row">
                                <span class="confirmation-key">Cancellation reason</span>
                                <span class="confirmation-value"><?= htmlspecialchars($appointment['cancellation_reason']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (in_array($appointment['status'], ['pending', 'confirmed'])): ?>
                        <?php $would_be_late = is_late_cancellation($conn, $appointment['slot_start']); ?>
                        <form method="POST" action="appointment-detail.php?appointment_id=<?= $appointment_id ?>" id="cancel-appointment-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="reason" id="cancel-reason-input" value="">
                            <button type="button" class="btn btn-cancel" id="open-cancel-modal-btn">Cancel Appointment</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </main>
    </div>

    <!-- Cancel confirmation modal — replaces the old native confirm() so it
         can actually show the late-cancellation warning below, not just a
         generic yes/no. Reuses the same .modal-* classes as
         appointment-history.php's detail modal (assets/css/history.css,
         already loaded on this page) rather than adding new CSS. -->
    <?php if ($appointment && in_array($appointment['status'], ['pending', 'confirmed'])): ?>
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
                <p>
                    You're about to cancel your appointment with
                    Dr. <?= htmlspecialchars($appointment['doctor_first'] . ' ' . $appointment['doctor_last']) ?>
                    on <?= date('l, F j, Y \a\t g:i A', strtotime($appointment['slot_start'])) ?>.
                    This can't be undone.
                </p>
                <?php if ($would_be_late): ?>
                    <p style="margin-top:12px; padding:12px; background:#FEF3E2; border-radius:8px; color:#8A5A00; font-size:14px;">
                        This is within 2 hours of your scheduled time — it will be logged as a late cancellation.
                    </p>
                <?php endif; ?>
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

                document.getElementById('open-cancel-modal-btn').addEventListener('click', openModal);
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
</body>

</html>