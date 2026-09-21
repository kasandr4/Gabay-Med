<?php
// patient/appointment-history.php
// Implements spec 1.6 — Appointment History.
// Detail view now opens as a modal dialog with blurred backdrop
// instead of navigating to a separate page.
//
// EXPANDED 2026-09-18: the modal used to only show the appointment's own
// fields (reference/date/department/doctor/status). Now also shows what
// happened AT the visit - diagnosis/notes, prescriptions, and lab orders
// - all genuinely linked via consultations.consultation_id (one
// consultation per appointment, prescriptions/lab_orders both carry
// consultation_id). A LEFT JOIN to consultations is required, not INNER
// - a cancelled or no_show appointment never got a consultation row at
// all (the doctor never saw the patient), so this has to render
// gracefully with no diagnosis/notes/prescriptions/labs section for
// those, not silently drop them from the list.
//
// DELIBERATELY NOT INCLUDED: a specific confinement's details (diagnosis
// on discharge, medications, room, etc.) for a visit that led to
// admission. confinements has NO foreign key back to appointments OR
// consultations anywhere in the schema - only patient_id and
// attending_doctor_id. There is no reliable way to say "THIS confinement
// came from THIS appointment" without guessing (e.g. matching by nearest
// date), and guessing wrong on a patient's own medical history is worse
// than not showing it. What IS reliably known - consultations.outcome
// being 'admitted' - is shown as part of the outcome text below. If
// admission details per-visit are wanted, that needs a real column
// (e.g. confinements.originating_appointment_id) added first.

require_once '../includes/auth_guard.php';
require_role('patient');
require_once '../config/db.php';
require_active_patient($conn);
require_once '../includes/reference_number.php';

$active_page = 'appointment-history';
$patient_id = $_SESSION['user_id'];

// List view: all PAST appointments, most recent first, plus whatever
// consultation resulted from each (LEFT JOIN - see header comment on why
// this can't be an INNER JOIN).
//
// FIXED 2026-09-19: consultations.appointment_id has no UNIQUE constraint
// (only a plain index) - found while testing this against a real data
// dump, one appointment genuinely had 3 consultation rows against it. A
// straight LEFT JOIN fans out into 3 duplicate rows for that one
// appointment (same row 3x in the list, and worse, $appointments_json
// silently keeps only the LAST one under that appointment_id key,
// dropping the other two consultations' prescriptions/lab orders
// entirely with no indication anything was lost). The subquery below
// picks only the most-recently-created consultation per appointment_id,
// same "latest write is authoritative" convention already used
// elsewhere in this app, guaranteeing exactly one row per appointment
// regardless of how many consultation rows exist for it.
$stmt = $conn->prepare("
    SELECT a.appointment_id, a.slot_start, a.status, a.created_at, d.department_name,
           doc.first_name AS doctor_first, doc.last_name AS doctor_last,
           c.consultation_id, c.findings, c.clinical_notes, c.outcome
    FROM appointments a
    JOIN departments d ON a.department_id = d.department_id
    JOIN users doc ON a.doctor_id = doc.user_id
    LEFT JOIN (
        SELECT c1.*
        FROM consultations c1
        INNER JOIN (
            SELECT appointment_id, MAX(consultation_id) AS max_id
            FROM consultations
            GROUP BY appointment_id
        ) latest ON latest.appointment_id = c1.appointment_id AND latest.max_id = c1.consultation_id
    ) c ON c.appointment_id = a.appointment_id
    WHERE a.patient_id = ? AND a.status IN ('completed', 'no_show', 'cancelled')
    ORDER BY a.slot_start DESC
");
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Batch-fetch prescriptions and lab orders for every consultation found
// above in two queries total, rather than one query per appointment.
$consultationIds = array_values(array_unique(array_filter(array_column($history, 'consultation_id'))));

$prescriptionsByConsultation = [];
$labOrdersByConsultation = [];

if (!empty($consultationIds)) {
    $placeholders = implode(',', array_fill(0, count($consultationIds), '?'));
    $types = str_repeat('i', count($consultationIds));

    $stmt = $conn->prepare("
        SELECT consultation_id, medicine_name, quantity, instructions, status
        FROM prescriptions
        WHERE consultation_id IN ($placeholders)
        ORDER BY prescription_id ASC
    ");
    $stmt->bind_param($types, ...$consultationIds);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $prescriptionsByConsultation[(int) $row['consultation_id']][] = $row;
    }
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT consultation_id, test_name, notes, status
        FROM lab_orders
        WHERE consultation_id IN ($placeholders)
        ORDER BY lab_order_id ASC
    ");
    $stmt->bind_param($types, ...$consultationIds);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $labOrdersByConsultation[(int) $row['consultation_id']][] = $row;
    }
    $stmt->close();
}

function get_outcome_summary($status, $consultationOutcome = null)
{
    switch ($status) {
        case 'completed':
            if ($consultationOutcome === 'admitted') {
                return "Visit completed. The patient was admitted for further care.";
            }
            if ($consultationOutcome === 'follow_up') {
                return "Visit completed. A follow-up was recommended.";
            }
            return "Visit completed.";
        case 'no_show':
            return "This appointment was marked as a no-show.";
        case 'cancelled':
            return "This appointment was cancelled.";
        default:
            return "";
    }
}

$rxStatusLabels = ['pending' => 'Pending', 'dispensed' => 'Dispensed', 'void' => 'Void'];
$labStatusLabels = ['pending' => 'Pending', 'accepted' => 'In Progress', 'completed' => 'Results Ready'];

// Pre-build the data for all appointments so JS can open the modal
// without any extra network request — just read from a JS object.
$appointments_json = [];
foreach ($history as $row) {
    $consultationId = $row['consultation_id'] !== null ? (int) $row['consultation_id'] : null;

    $prescriptions = [];
    foreach ($prescriptionsByConsultation[$consultationId] ?? [] as $rx) {
        $prescriptions[] = [
            'name' => $rx['medicine_name'],
            'quantity' => $rx['quantity'],
            'instructions' => $rx['instructions'],
            'status' => $rx['status'],
            'status_label' => $rxStatusLabels[$rx['status']] ?? ucfirst($rx['status']),
        ];
    }

    $labOrders = [];
    foreach ($labOrdersByConsultation[$consultationId] ?? [] as $lo) {
        $labOrders[] = [
            'name' => $lo['test_name'],
            'notes' => $lo['notes'],
            'status' => $lo['status'],
            'status_label' => $labStatusLabels[$lo['status']] ?? ucfirst($lo['status']),
        ];
    }

    $appointments_json[$row['appointment_id']] = [
        'reference'  => format_appointment_reference($row['appointment_id'], $row['created_at']),
        'date'       => date('l, F j, Y \a\t g:i A', strtotime($row['slot_start'])),
        'department' => $row['department_name'],
        'doctor'     => 'Dr. ' . $row['doctor_first'] . ' ' . $row['doctor_last'],
        'status'     => $row['status'],
        'status_label' => ucwords(str_replace('_', ' ', $row['status'])),
        'outcome'    => get_outcome_summary($row['status'], $row['outcome']),
        'has_consultation' => $consultationId !== null,
        'diagnosis'  => $row['findings'],
        'notes'      => $row['clinical_notes'],
        'prescriptions' => $prescriptions,
        'lab_orders' => $labOrders,
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
                    <div class="table-wrap">
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

            <div id="modal-no-consultation" class="outcome-box" hidden>
                <p style="margin:0; color:var(--text-muted, #666);">No consultation was recorded for this visit.</p>
            </div>

            <div id="modal-consultation-sections" hidden>
                <div class="outcome-box">
                    <div class="card-title">Diagnosis</div>
                    <p id="modal-diagnosis"></p>
                </div>

                <div class="outcome-box" id="modal-notes-box" hidden>
                    <div class="card-title">Doctor's Notes</div>
                    <p id="modal-notes"></p>
                </div>

                <div class="outcome-box" id="modal-prescriptions-box" hidden>
                    <div class="card-title">Prescriptions</div>
                    <ul class="history-detail-list" id="modal-prescriptions-list"></ul>
                </div>

                <div class="outcome-box" id="modal-lab-orders-box" hidden>
                    <div class="card-title">Laboratory</div>
                    <ul class="history-detail-list" id="modal-lab-orders-list"></ul>
                </div>
            </div>
        </div>
    </div>

    <script>
        // All appointment data pre-loaded from PHP — no extra fetch needed
        const appointments = <?= json_encode($appointments_json) ?>;

        function renderList(listEl, boxEl, items, formatter) {
            if (!items || items.length === 0) {
                boxEl.hidden = true;
                return;
            }
            boxEl.hidden = false;
            listEl.innerHTML = '';
            items.forEach(item => {
                const li = document.createElement('li');
                li.textContent = formatter(item);
                listEl.appendChild(li);
            });
        }

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

            // Diagnosis/notes/prescriptions/lab orders only exist when a
            // consultation actually happened (see this file's PHP header
            // comment on why cancelled/no_show visits never have one).
            document.getElementById('modal-no-consultation').hidden = data.has_consultation;
            document.getElementById('modal-consultation-sections').hidden = !data.has_consultation;

            if (data.has_consultation) {
                document.getElementById('modal-diagnosis').textContent = data.diagnosis || '—';

                const notesBox = document.getElementById('modal-notes-box');
                if (data.notes) {
                    notesBox.hidden = false;
                    document.getElementById('modal-notes').textContent = data.notes;
                } else {
                    notesBox.hidden = true;
                }

                renderList(
                    document.getElementById('modal-prescriptions-list'),
                    document.getElementById('modal-prescriptions-box'),
                    data.prescriptions,
                    rx => rx.name + (rx.quantity ? ' — ' + rx.quantity : '') + (rx.instructions ? ' (' + rx.instructions + ')' : '') + ' [' + rx.status_label + ']'
                );

                renderList(
                    document.getElementById('modal-lab-orders-list'),
                    document.getElementById('modal-lab-orders-box'),
                    data.lab_orders,
                    lo => lo.name + (lo.notes ? ' — ' + lo.notes : '') + ' [' + lo.status_label + ']'
                );
            }

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