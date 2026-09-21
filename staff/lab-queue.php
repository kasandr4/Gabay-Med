<?php
// staff/lab-queue.php
// NEW (2026-09-17): the Laboratory did not exist as a role/portal in this
// app before this - see patient/lab-orders.php's old header comment
// ("there is no lab portal/role in the system, so there's no status to
// track here"). This is that portal's one screen: every lab order a
// doctor has placed that isn't finished yet, oldest first, with a
// two-step Accept -> Complete flow per instructor requirement
// ("automatically sent to... laboratory so that they will just accept
// and release it for the patient"):
//   - Accept: the lab has picked this test up and is working on it.
//   - Complete: results have been released back to the patient. This
//     app has no results-entry/LIS functionality (out of scope - see
//     025_lab_order_queue_and_lab_staff_type.sql's header comment) - the
//     actual result stays on paper/whatever the lab's own equipment
//     produces, exactly as it always has. "Complete" here only means
//     "this order is done, take it off the queue."
//
// Grouped by visit (consultation or confinement) for readability, same
// as patient/lab-orders.php's own patient-facing grouping - but Accept/
// Complete act on individual lab_order_id rows, not the whole group. A
// confinement can have tests ordered on different days across a long
// stay with no natural "submitted together" boundary (see
// patient/lab-orders.php's own comment on this) - forcing one group-wide
// status would either block an already-resulted test behind a newer one
// still pending, or vice versa.

require_once '../includes/auth_guard.php';
require_role('staff');
require_staff_type('laboratory');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/status_labels.php';

$current_page = 'lab-queue';
$staff_id = (int) $_SESSION['user_id'];

// --- Consultation-sourced (outpatient) orders --------------------------
$labOrderGroups = [];
$stmt = $conn->prepare(
    "SELECT lo.lab_order_id, lo.test_name, lo.notes, lo.status, lo.created_at,
            lo.accepted_at, acc.first_name AS accepted_by_first, acc.last_name AS accepted_by_last,
            c.consultation_id, c.created_at AS consultation_date,
            pat.first_name AS patient_first, pat.last_name AS patient_last,
            doc.first_name AS doctor_first, doc.last_name AS doctor_last
     FROM lab_orders lo
     JOIN consultations c ON c.consultation_id = lo.consultation_id
     JOIN users pat ON pat.user_id = lo.patient_id
     JOIN users doc ON doc.user_id = lo.doctor_id
     LEFT JOIN users acc ON acc.user_id = lo.accepted_by
     WHERE lo.consultation_id IS NOT NULL AND lo.status IN ('pending', 'accepted')
     ORDER BY c.created_at ASC, lo.lab_order_id ASC"
);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($rows as $row) {
    $key = 'consultation-' . $row['consultation_id'];
    if (!isset($labOrderGroups[$key])) {
        $labOrderGroups[$key] = [
            'sort_at' => $row['consultation_date'],
            'type' => 'Outpatient visit',
            'patient_name' => trim($row['patient_first'] . ' ' . $row['patient_last']),
            'doctor_name' => 'Dr. ' . trim($row['doctor_first'] . ' ' . $row['doctor_last']),
            'date' => $row['consultation_date'],
            'lines' => [],
        ];
    }
    $labOrderGroups[$key]['lines'][] = [
        'lab_order_id' => (int) $row['lab_order_id'],
        'test_name' => $row['test_name'],
        'notes' => $row['notes'],
        'status' => $row['status'],
        'accepted_by_name' => $row['accepted_by_first'] ? trim($row['accepted_by_first'] . ' ' . $row['accepted_by_last']) : null,
        'accepted_at' => $row['accepted_at'],
    ];
}

// --- Confinement-sourced (inpatient) orders -----------------------------
$stmt = $conn->prepare(
    "SELECT lo.lab_order_id, lo.test_name, lo.notes, lo.status, lo.created_at,
            lo.accepted_at, acc.first_name AS accepted_by_first, acc.last_name AS accepted_by_last,
            cf.confinement_id, cf.date_confined,
            pat.first_name AS patient_first, pat.last_name AS patient_last,
            doc.first_name AS doctor_first, doc.last_name AS doctor_last
     FROM lab_orders lo
     JOIN confinements cf ON cf.confinement_id = lo.confinement_id
     JOIN users pat ON pat.user_id = lo.patient_id
     JOIN users doc ON doc.user_id = lo.doctor_id
     LEFT JOIN users acc ON acc.user_id = lo.accepted_by
     WHERE lo.confinement_id IS NOT NULL AND lo.status IN ('pending', 'accepted')
     ORDER BY lo.created_at ASC, lo.lab_order_id ASC"
);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($rows as $row) {
    $key = 'confinement-' . $row['confinement_id'];
    if (!isset($labOrderGroups[$key])) {
        $labOrderGroups[$key] = [
            'sort_at' => $row['date_confined'],
            'type' => 'Inpatient stay',
            'patient_name' => trim($row['patient_first'] . ' ' . $row['patient_last']),
            'doctor_name' => 'Dr. ' . trim($row['doctor_first'] . ' ' . $row['doctor_last']),
            'date' => $row['date_confined'],
            'lines' => [],
        ];
    }
    $labOrderGroups[$key]['lines'][] = [
        'lab_order_id' => (int) $row['lab_order_id'],
        'test_name' => $row['test_name'],
        'notes' => $row['notes'],
        'status' => $row['status'],
        'accepted_by_name' => $row['accepted_by_first'] ? trim($row['accepted_by_first'] . ' ' . $row['accepted_by_last']) : null,
        'accepted_at' => $row['accepted_at'],
    ];
}

usort($labOrderGroups, fn($a, $b) => strtotime($a['sort_at']) <=> strtotime($b['sort_at']));
$labOrderGroups = array_values($labOrderGroups);

// FIXED 2026-09-20: this used to hardcode its own label for 'accepted'
// ("Accepted") that didn't match the patient-facing label for the exact
// same status ("In Progress" - see patient/lab-orders.php via
// includes/status_labels.php). Same status, two different words shown
// to two different people about the same test - consolidated onto one
// canonical label everywhere rather than leaving that drift in place.
$statusLabelsShared = get_lab_status_labels();
$statusColors = get_lab_status_colors();
$statusBadge = [];
foreach ($statusLabelsShared as $key => $label) {
    $statusBadge[$key] = ['label' => $label, 'bg' => $statusColors[$key]['bg'], 'fg' => $statusColors[$key]['fg']];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lab Queue - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/pharmacist-dashboard.css">
</head>

<body>
    <div class="app-shell">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">

            <div class="page-header">
                <h1>Lab Queue</h1>
                <p>Tests ordered by doctors, oldest first. Accept a test to start working on it, then Complete once results are released to the patient.</p>
            </div>

            <?php if (empty($labOrderGroups)): ?>
                <div class="card">
                    <div class="empty-state">
                        <p>Nothing waiting right now.</p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($labOrderGroups as $group): ?>
                    <div class="card" style="margin-bottom:16px;">
                        <div style="display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:8px; margin-bottom:12px;">
                            <div>
                                <strong><?php echo htmlspecialchars($group['patient_name']); ?></strong>
                                <span style="color:var(--text-secondary, #666); font-size:13px;"> &middot; <?php echo htmlspecialchars($group['doctor_name']); ?> &middot; <?php echo htmlspecialchars($group['type']); ?></span>
                            </div>
                            <span style="font-size:12.5px; color:var(--text-secondary, #666);"><?php echo date('M j, Y g:i A', strtotime($group['date'])); ?></span>
                        </div>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Test</th>
                                        <th>Notes</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($group['lines'] as $line): ?>
                                        <tr data-lab-order-row="<?php echo (int) $line['lab_order_id']; ?>">
                                            <td><?php echo htmlspecialchars($line['test_name']); ?></td>
                                            <td><?php echo htmlspecialchars($line['notes'] ?? '—'); ?></td>
                                            <td>
                                                <span class="lab-status-badge" style="display:inline-block; padding:3px 10px; border-radius:99px; font-size:12px; font-weight:500; background:<?php echo $statusBadge[$line['status']]['bg']; ?>; color:<?php echo $statusBadge[$line['status']]['fg']; ?>;">
                                                    <?php echo $statusBadge[$line['status']]['label']; ?>
                                                </span>
                                                <?php if ($line['status'] === 'accepted' && $line['accepted_by_name']): ?>
                                                    <div style="font-size:11.5px; color:var(--text-secondary, #888); margin-top:3px;">
                                                        by <?php echo htmlspecialchars($line['accepted_by_name']); ?>, <?php echo date('g:i A', strtotime($line['accepted_at'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($line['status'] === 'pending'): ?>
                                                    <button type="button" class="btn btn-sm btn-secondary lab-accept-btn" data-lab-order-id="<?php echo (int) $line['lab_order_id']; ?>">Accept</button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-sm btn-primary lab-complete-btn" data-lab-order-id="<?php echo (int) $line['lab_order_id']; ?>">Complete</button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        </main>
    </div>

    <script>
        const csrfToken = <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>;

        function labQueueAction(action, labOrderId, button) {
            button.disabled = true;
            const originalText = button.textContent;
            button.textContent = action === 'accept_lab_order' ? 'Accepting…' : 'Completing…';

            fetch('lab-queue-actions.php', {
                    method: 'POST',
                    body: new URLSearchParams({
                        action: action,
                        lab_order_id: labOrderId,
                        csrf_token: csrfToken
                    }),
                })
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        button.disabled = false;
                        button.textContent = originalText;
                        alert(data.error || 'Something went wrong. Please try again.');
                        return;
                    }
                    window.location.reload();
                })
                .catch(() => {
                    button.disabled = false;
                    button.textContent = originalText;
                    alert('Could not reach the server. Please check your connection and try again.');
                });
        }

        document.querySelectorAll('.lab-accept-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                labQueueAction('accept_lab_order', btn.dataset.labOrderId, btn);
            });
        });
        document.querySelectorAll('.lab-complete-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                labQueueAction('complete_lab_order', btn.dataset.labOrderId, btn);
            });
        });
    </script>
</body>

</html>