<?php
// patient/lab-orders.php
// Read-only view of lab tests ordered for this patient. Mirrors
// prescriptions.php's pattern: one row per "order group", with a "View
// Details" modal - expanded to cover BOTH sources a lab order can now
// come from (see 022_confinement_orders_and_discharge.sql):
//   - a consultation (outpatient visit) - one group per consultation
//   - a confinement (inpatient stay)    - one group per confinement,
//     covering every test ordered across that whole admission, since a
//     stay can span many separate order submissions over several days
//     with no natural per-submission boundary the way a consultation has.
//
// UPDATED 2026-09-17: this used to be genuinely read-only with no status
// to show - there was no lab portal/role in the system at all (see
// staff/lab-queue.php, the new Laboratory staff screen that changed
// that). Each test now carries its actual status (pending/accepted/
// completed - lab_orders.status), shown as a status chip per test in the
// details modal, same visual pattern prescriptions.php already uses for
// dispense status. Still nothing to ACT on here - a patient can't accept
// or complete their own test, only see where it's at - so the page
// itself stays otherwise unchanged: the doctor still hands over a
// printed slip at the point of care (doctor/print-lab-order.php), this
// just lets the patient check back on progress the same way
// prescriptions.php lets them check on a prescription.

require_once '../includes/auth_guard.php';
require_role('patient');
require_once '../config/db.php';
require_once '../includes/slot_grid.php';
require_once '../includes/status_labels.php';
require_active_patient($conn);

$active_page = 'lab-orders';

// The logged-in patient's ID comes only from the session - never from the
// query string or a form field - same guarantee as prescriptions.php.
$patientId = (int) $_SESSION['user_id'];

/**
 * Format a MySQL datetime/timestamp string for display.
 */
function formatLabDate($datetime)
{
    $timestamp = strtotime($datetime);
    return $timestamp ? date("M j, Y", $timestamp) : $datetime;
}

$labOrderGroups = [];

// --- Consultation-based orders (unchanged query/grouping) --------------
$sql = "SELECT
            c.consultation_id,
            c.created_at     AS consultation_date,
            u.first_name     AS doctor_first_name,
            u.last_name      AS doctor_last_name,
            d.department_name,
            lo.lab_order_id,
            lo.test_name,
            lo.notes,
            lo.status
        FROM lab_orders lo
        INNER JOIN consultations c ON c.consultation_id = lo.consultation_id
        INNER JOIN appointments a ON a.appointment_id = c.appointment_id
        INNER JOIN departments d ON d.department_id = a.department_id
        INNER JOIN users u ON u.user_id = lo.doctor_id
        WHERE lo.patient_id = ? AND lo.consultation_id IS NOT NULL
        ORDER BY c.created_at DESC, lo.lab_order_id ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $patientId);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $key = "consultation-" . $row['consultation_id'];
    if (!isset($labOrderGroups[$key])) {
        $labOrderGroups[$key] = [
            "group_key"   => $key,
            "sort_at"     => $row['consultation_date'],
            "date"        => formatLabDate($row['consultation_date']),
            "date_label"  => "Ordered",
            "doctor_name" => "Dr. " . trim($row['doctor_first_name'] . " " . $row['doctor_last_name']),
            "department"  => $row['department_name'],
            "tests"       => [],
        ];
    }
    $labOrderGroups[$key]['tests'][] = ["name" => $row['test_name'], "notes" => $row['notes'], "status" => $row['status']];
}
$stmt->close();

// --- Confinement-based orders -------------------------------------------
// One group per confinement (whole stay), not per submission. Department
// is resolved through the attending doctor via get_doctor_department_id()
// since confinements have no department_id of their own.
$sql = "SELECT
            c.confinement_id,
            c.date_confined,
            c.attending_doctor_id,
            u.first_name     AS doctor_first_name,
            u.last_name      AS doctor_last_name,
            lo.lab_order_id,
            lo.test_name,
            lo.notes,
            lo.status,
            lo.created_at    AS order_created_at
        FROM lab_orders lo
        INNER JOIN confinements c ON c.confinement_id = lo.confinement_id
        INNER JOIN users u ON u.user_id = c.attending_doctor_id
        WHERE lo.patient_id = ? AND lo.confinement_id IS NOT NULL
        ORDER BY c.date_confined DESC, lo.lab_order_id ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $patientId);
$stmt->execute();
$result = $stmt->get_result();

$departmentNameCache = [];
while ($row = $result->fetch_assoc()) {
    $key = "confinement-" . $row['confinement_id'];
    if (!isset($labOrderGroups[$key])) {
        $doctorId = (int) $row['attending_doctor_id'];
        if (!array_key_exists($doctorId, $departmentNameCache)) {
            $deptId = get_doctor_department_id($conn, $doctorId);
            $departmentNameCache[$doctorId] = null;
            if ($deptId !== null) {
                $deptStmt = $conn->prepare("SELECT department_name FROM departments WHERE department_id = ? LIMIT 1");
                $deptStmt->bind_param("i", $deptId);
                $deptStmt->execute();
                $deptRow = $deptStmt->get_result()->fetch_assoc();
                $deptStmt->close();
                $departmentNameCache[$doctorId] = $deptRow['department_name'] ?? null;
            }
        }

        $labOrderGroups[$key] = [
            "group_key"   => $key,
            "sort_at"     => $row['date_confined'],
            "date"        => formatLabDate($row['date_confined']),
            "date_label"  => "Admitted",
            "doctor_name" => "Dr. " . trim($row['doctor_first_name'] . " " . $row['doctor_last_name']),
            "department"  => $departmentNameCache[$doctorId] ?? 'Inpatient',
            "tests"       => [],
        ];
    }
    $labOrderGroups[$key]['tests'][] = ["name" => $row['test_name'], "notes" => $row['notes'], "status" => $row['status']];
}
$stmt->close();

// Both sources fetched independently (different sort columns), so the
// combined list needs its own sort - newest first, same as before.
usort($labOrderGroups, function ($a, $b) {
    return strtotime($b['sort_at']) <=> strtotime($a['sort_at']);
});
$labOrders = array_values($labOrderGroups);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lab Orders - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/prescriptions.css">
</head>

<body>
    <div class="app-layout">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">
            <div class="page-header">
                <h1 class="page-title">Lab Orders</h1>
                <p class="page-subtitle">View the laboratory tests your doctor has ordered.</p>
            </div>

            <div class="card">
                <?php if (empty($labOrders)): ?>
                    <div class="empty-state">
                        <p class="empty-state-text">No lab orders found.</p>
                        <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="rx-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Doctor</th>
                                    <th>Department</th>
                                    <th>Tests</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($labOrders as $lo): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($lo['date']); ?></td>
                                        <td><?php echo str_starts_with($lo['group_key'], 'confinement-') ? 'Inpatient Stay' : 'Outpatient Visit'; ?></td>
                                        <td><?php echo htmlspecialchars($lo['doctor_name']); ?></td>
                                        <td><?php echo htmlspecialchars($lo['department']); ?></td>
                                        <td><?php echo count($lo['tests']); ?> test<?php echo count($lo['tests']) === 1 ? '' : 's'; ?></td>
                                        <td>
                                            <button
                                                type="button"
                                                class="btn-view-rx"
                                                data-group-key="<?php echo htmlspecialchars($lo['group_key']); ?>">
                                                View Details
                                            </button>
                                            <?php
                                            $isConfinementGroup = str_starts_with($lo['group_key'], 'confinement-');
                                            $rawId = (int) substr($lo['group_key'], strrpos($lo['group_key'], '-') + 1);
                                            $printUrl = $isConfinementGroup
                                                ? "print-lab-order.php?confinement_id={$rawId}"
                                                : "print-lab-order.php?consultation_id={$rawId}";
                                            ?>
                                            <a
                                                href="<?php echo htmlspecialchars($printUrl); ?>"
                                                target="_blank"
                                                class="btn-view-rx"
                                                style="margin-left: 8px; display: inline-block; text-decoration: none;">
                                                Print
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Lab Order Details Modal (single instance, populated via JS) -->
    <div class="rx-modal-overlay" id="rxModalOverlay">
        <div class="rx-modal" role="dialog" aria-modal="true" aria-labelledby="rxModalTitle">
            <div class="rx-modal-header">
                <div class="rx-modal-header-title">
                    <span class="rx-modal-header-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 2v6.5L4 20a1 1 0 0 0 1 2h14a1 1 0 0 0 1-2l-5-11.5V2"></path>
                            <path d="M8.5 2h7"></path>
                            <path d="M6 15h12"></path>
                        </svg>
                    </span>
                    <h2 id="rxModalTitle">Lab Order Details</h2>
                </div>
                <button type="button" class="rx-modal-close" id="rxModalClose" aria-label="Close">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>

            <div class="rx-modal-body">

                <!-- Doctor / Date / Department metadata -->
                <div class="rx-meta-grid">
                    <div class="rx-meta-item">
                        <span class="rx-meta-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </span>
                        <div class="rx-meta-text">
                            <span class="rx-detail-label">Doctor</span>
                            <span class="rx-detail-value" id="rxDetailDoctor">—</span>
                        </div>
                    </div>
                    <div class="rx-meta-item">
                        <span class="rx-meta-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                        </span>
                        <div class="rx-meta-text">
                            <span class="rx-detail-label" id="rxDetailDateLabel">Date</span>
                            <span class="rx-detail-value" id="rxDetailDate">—</span>
                        </div>
                    </div>
                    <div class="rx-meta-item">
                        <span class="rx-meta-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 21h18"></path>
                                <path d="M5 21V7l8-4v18"></path>
                                <path d="M19 21V11l-6-4"></path>
                            </svg>
                        </span>
                        <div class="rx-meta-text">
                            <span class="rx-detail-label">Department</span>
                            <span class="rx-detail-value" id="rxDetailDepartment">—</span>
                        </div>
                    </div>
                </div>

                <!-- Tests Ordered -->
                <div class="rx-medicines-section">
                    <h3 class="rx-section-title">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 2v6.5L4 20a1 1 0 0 0 1 2h14a1 1 0 0 0 1-2l-5-11.5V2"></path>
                            <path d="M8.5 2h7"></path>
                            <path d="M6 15h12"></path>
                        </svg>
                        Tests Ordered
                    </h3>
                    <div id="rxMedicinesList" class="rx-medicines-list">
                        <!-- Populated via JS -->
                    </div>
                </div>

                <p style="margin: 16px 0 0; font-size: 12.5px; color: var(--text-muted, #888);">
                    A printed copy of this order was given to you at your visit to bring to the Laboratory.
                </p>
            </div>
        </div>
    </div>

    <!-- Lab order data for this patient only, already scoped server-side -->
    <script>
        var labOrdersData = <?php echo json_encode($labOrders, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        // Single source of truth for these labels is includes/status_labels.php
        // (get_lab_status_labels()) - see lab-orders.js's own header comment.
        var labStatusLabels = <?php echo json_encode(get_lab_status_labels(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <script src="../assets/js/lab-orders.js"></script>
</body>

</html>