<?php
// patient/appointment-history.php
// Implements spec 1.6 — Appointment History.
// Detail view now opens as a modal dialog with blurred backdrop
// instead of navigating to a separate page.

require_once '../includes/auth_guard.php';
require_role('patient');
require_once '../config/db.php';
require_once '../includes/reference_number.php';

$active_page = 'appointment-history';
$patient_id = $_SESSION['user_id'];

// List view: all PAST appointments, most recent first
$stmt = $conn->prepare("
    SELECT a.appointment_id, a.slot_start, a.status, a.created_at, d.department_name,
           doc.first_name AS doctor_first, doc.last_name AS doctor_last
    FROM appointments a
    JOIN departments d ON a.department_id = d.department_id
    JOIN users doc ON a.doctor_id = doc.user_id
    WHERE a.patient_id = ? AND a.status IN ('completed', 'no_show', 'cancelled')
    ORDER BY a.slot_start DESC
");
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function get_outcome_summary($status)
{
    switch ($status) {
        case 'completed':
            return "Visit completed.";
        case 'no_show':
            return "This appointment was marked as a no-show.";
        case 'cancelled':
            return "This appointment was cancelled.";
        default:
            return "";
    }
}

// Pre-build the data for all appointments so JS can open the modal
// without any extra network request — just read from a JS object.
$appointments_json = [];
foreach ($history as $row) {
    $appointments_json[$row['appointment_id']] = [
        'reference'  => format_appointment_reference($row['appointment_id'], $row['created_at']),
        'date'       => date('l, F j, Y \a\t g:i A', strtotime($row['slot_start'])),
        'department' => $row['department_name'],
        'doctor'     => 'Dr. ' . $row['doctor_first'] . ' ' . $row['doctor_last'],
        'status'     => $row['status'],
        'status_label' => ucwords(str_replace('_', ' ', $row['status'])),
        'outcome'    => get_outcome_summary($row['status']),
    ];
}

// If ?appointment_id= is in the URL, auto-open that appointment's modal on load
$auto_open_id = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : null;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointment History - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/booking.css">
    <link rel="stylesheet" href="../assets/css/history.css">
</head>

<body>
    <div class="app-layout">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">

            <div class="page-header">
                <h1 class="page-title">Appointment History</h1>
                <p class="page-subtitle">A record of your past visits.</p>
            </div>

            <?php if (empty($history)): ?>
                <div class="card">
                    <div class="empty-state">
                        <p class="empty-state-text">You haven't had any appointments yet.</p>
                        <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="card no-padding">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Date</th>
                                <th>Department</th>
                                <th>Doctor</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $row): ?>
                                <tr onclick="openDetailModal(<?= $row['appointment_id'] ?>)" class="history-row-clickable">
                                    <td class="ref-cell"><?= htmlspecialchars(format_appointment_reference($row['appointment_id'], $row['created_at'])) ?></td>
                                    <td><?= date('M j, Y', strtotime($row['slot_start'])) ?></td>
                                    <td><?= htmlspecialchars($row['department_name']) ?></td>
                                    <td>Dr. <?= htmlspecialchars($row['doctor_first'] . ' ' . $row['doctor_last']) ?></td>
                                    <td>
                                        <span class="status-chip status-chip-<?= htmlspecialchars($row['status']) ?>">
                                            <?= ucwords(str_replace('_', ' ', $row['status'])) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        </main>
    </div>

    <!-- ===== Appointment Detail Modal ===== -->
    <div class="modal-backdrop" id="modal-backdrop" onclick="closeDetailModal()"></div>

    <div class="modal-card" id="detail-modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <div class="modal-header">
            <h2 class="modal-title" id="modal-title">Appointment Details</h2>
            <button class="modal-close-btn" onclick="closeDetailModal()" aria-label="Close">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
            </button>
        </div>

        <div class="modal-body">
            <div class="confirmation-summary">
                <div class="confirmation-row">
                    <span class="confirmation-key">Reference No.</span>
                    <span class="confirmation-value confirmation-value-mono" id="modal-reference"></span>
                </div>
                <div class="confirmation-row">
                    <span class="confirmation-key">Date &amp; Time</span>
                    <span class="confirmation-value" id="modal-date"></span>
                </div>
                <div class="confirmation-row">
                    <span class="confirmation-key">Department</span>
                    <span class="confirmation-value" id="modal-department"></span>
                </div>
                <div class="confirmation-row">
                    <span class="confirmation-key">Doctor</span>
                    <span class="confirmation-value" id="modal-doctor"></span>
                </div>
                <div class="confirmation-row">
                    <span class="confirmation-key">Status</span>
                    <span class="confirmation-value">
                        <span class="status-chip" id="modal-status"></span>
                    </span>
                </div>
            </div>

            <div class="outcome-box">
                <div class="card-title">Outcome</div>
                <p id="modal-outcome"></p>
            </div>
        </div>
    </div>

    <script>
        // All appointment data pre-loaded from PHP — no extra fetch needed
        const appointments = <?= json_encode($appointments_json) ?>;

        function openDetailModal(id) {
            const data = appointments[id];
            if (!data) return;

            document.getElementById('modal-reference').textContent = data.reference;
            document.getElementById('modal-date').textContent = data.date;
            document.getElementById('modal-department').textContent = data.department;
            document.getElementById('modal-doctor').textContent = data.doctor;
            document.getElementById('modal-outcome').textContent = data.outcome;

            const chip = document.getElementById('modal-status');
            chip.textContent = data.status_label;
            chip.className = 'status-chip status-chip-' + data.status;

            document.getElementById('modal-backdrop').classList.add('modal-visible');
            document.getElementById('detail-modal').classList.add('modal-visible');
            document.body.classList.add('modal-open');
        }

        function closeDetailModal() {
            document.getElementById('modal-backdrop').classList.remove('modal-visible');
            document.getElementById('detail-modal').classList.remove('modal-visible');
            document.body.classList.remove('modal-open');
            // Clean up the URL if ?appointment_id= was set
            if (window.location.search.includes('appointment_id')) {
                history.replaceState(null, '', 'appointment-history.php');
            }
        }

        // Close on Escape key
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeDetailModal();
        });

        // Auto-open if ?appointment_id= was in the URL
        <?php if ($auto_open_id && isset($appointments_json[$auto_open_id])): ?>
            document.addEventListener('DOMContentLoaded', () => openDetailModal(<?= $auto_open_id ?>));
        <?php endif; ?>
    </script>

</body>

</html>