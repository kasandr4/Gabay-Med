<?php
// patient/prescriptions.php

require_once '../includes/auth_guard.php';
require_role('patient');
require_once '../config/db.php';
require_once '../includes/status_labels.php';
require_active_patient($conn);

$active_page = 'prescriptions';

// The logged-in patient's ID comes only from the session - never from the
// query string or a form field - so a patient can never view someone
// else's prescriptions by guessing an ID.
$patientId = (int) $_SESSION['user_id'];

/**
 * Format a MySQL datetime/timestamp string for display.
 */
function formatRxDate($datetime)
{
    $timestamp = strtotime($datetime);
    return $timestamp ? date("M j, Y", $timestamp) : $datetime;
}

/**
 * Turn the consultations.outcome value into a display label. Not used for
 * the table's Status column anymore (see fill_status below), but kept
 * around in case another page still needs the same outcome vocabulary.
 */
function formatRxStatus($outcome)
{
    $map = [
        "discharged" => "Discharged",
        "confined"   => "Confined",
    ];
    return $map[$outcome] ?? ucfirst(str_replace('_', ' ', $outcome));
}

/**
 * Roll up the per-medicine dispense statuses (pending/dispensed/void) for
 * a prescription group into a single status to show in the table. Applies
 * equally to outpatient consultation groups and confinement discharge
 * groups (2026-09-07) - both are just "a group of medicines" from the
 * patient's point of view. Priority: if ANY medicine is still pending,
 * the whole group reads as "Pending" (there's still something
 * actionable). Otherwise, if any medicine was actually dispensed, it
 * reads as "Dispensed". Only when every medicine was voided does it read
 * as "Void".
 */
function computeFillStatus($medicines)
{
    $statuses = array_column($medicines, 'dispense_status');

    if (in_array('pending', $statuses, true)) {
        return 'pending';
    }
    if (in_array('dispensed', $statuses, true)) {
        return 'dispensed';
    }
    return 'void';
}

function formatFillStatus($fillStatus)
{
    $map = [
        "pending"   => "Pending",
        "dispensed" => "Dispensed",
        "void"      => "Void",
    ];
    return $map[$fillStatus] ?? ucfirst($fillStatus);
}

// One row per prescribed medicine, joined back to the consultation it was
// issued under and the doctor who issued it. Scoped to this patient only
// via the WHERE clause (prepared statement, no string concatenation).
$sql = "SELECT
            c.consultation_id,
            c.created_at    AS consultation_date,
            c.findings      AS diagnosis,
            c.clinical_notes,
            c.outcome,
            u.first_name    AS doctor_first_name,
            u.last_name     AS doctor_last_name,
            p.prescription_id,
            p.medicine_name,
            p.quantity,
            p.instructions,
            p.status        AS dispense_status
        FROM prescriptions p
        INNER JOIN consultations c ON c.consultation_id = p.consultation_id
        INNER JOIN users u ON u.user_id = p.doctor_id
        WHERE p.patient_id = ?
        ORDER BY c.created_at DESC, p.prescription_id ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $patientId);
$stmt->execute();
$result = $stmt->get_result();

// Group the flat medicine rows into one entry per consultation, since a
// single consultation can carry several prescribed medicines. "source"/
// "id"/"sort_ts" (2026-09-07) identify this group across the merge with
// confinement discharge medications below - kept as generic names rather
// than reusing "consultation_id" for both, since a confinement group has
// no consultation_id at all.
$prescriptionGroups = [];
while ($row = $result->fetch_assoc()) {
    $cid = $row['consultation_id'];

    if (!isset($prescriptionGroups[$cid])) {
        $prescriptionGroups[$cid] = [
            "source"          => "consultation",
            "id"              => (int) $cid,
            "consultation_id" => $cid,
            "date"            => formatRxDate($row['consultation_date']),
            "sort_ts"         => strtotime($row['consultation_date']),
            "doctor_name"     => "Dr. " . trim($row['doctor_first_name'] . " " . $row['doctor_last_name']),
            "diagnosis"       => $row['diagnosis'] !== '' ? $row['diagnosis'] : null,
            "notes"           => $row['clinical_notes'],
            "medicines"       => [],
        ];
    }

    $prescriptionGroups[$cid]['medicines'][] = [
        "name"         => $row['medicine_name'],
        "quantity"     => $row['quantity'],
        "instructions" => $row['instructions'],
        // Per-medicine fill status (see 022_link_prescriptions_to_dispense.sql
        // and staff/dispense-stock.php, which is what flips this to
        // 'dispensed'). Legacy rows created before that migration default
        // to 'pending' at the DB level even though they can never actually
        // be dispensed through that screen (no medicine_id link) - shown
        // as "Pending" here too, since that's still an accurate statement
        // of what has/hasn't happened to the medicine itself.
        "dispense_status" => $row['dispense_status'],
    ];
}
$stmt->close();

// Discharge ("Medications to Continue") lines, added 2026-09-07 - these
// live in their own table (confinement_discharge_medications, see
// 023_confinement_discharge_medications.sql) because they're written by
// doctor/confinement-discharge-process.php at discharge time, not by the
// same consultation/prescriptions flow above. Before this, a patient's
// take-home discharge medicines only ever showed up on the "My
// Confinement" page's past-confinement history, never here - even though
// staff/dispense-stock.php already treats them as an equal, dispensable
// counterpart to a regular prescription. Folding them into this same page
// (and the same View Details / Print pattern, same fill-status rollup) is
// that fix.
$dischargeSql = "SELECT
            cf.confinement_id,
            cf.discharge_date,
            cf.final_diagnosis,
            cf.discharge_medications AS notes,
            doc.first_name    AS doctor_first_name,
            doc.last_name     AS doctor_last_name,
            cdm.discharge_medication_id,
            cdm.medicine_name,
            cdm.quantity,
            cdm.instructions,
            cdm.status        AS dispense_status
        FROM confinement_discharge_medications cdm
        INNER JOIN confinements cf ON cf.confinement_id = cdm.confinement_id
        INNER JOIN users doc ON doc.user_id = cf.attending_doctor_id
        WHERE cf.patient_id = ?
        ORDER BY cf.discharge_date DESC, cdm.discharge_medication_id ASC";

$stmt = $conn->prepare($dischargeSql);
$stmt->bind_param("i", $patientId);
$stmt->execute();
$result = $stmt->get_result();

$dischargeGroups = [];
while ($row = $result->fetch_assoc()) {
    $fid = $row['confinement_id'];

    if (!isset($dischargeGroups[$fid])) {
        $dischargeGroups[$fid] = [
            "source"          => "confinement",
            "id"              => (int) $fid,
            "confinement_id"  => $fid,
            "date"            => formatRxDate($row['discharge_date']),
            "sort_ts"         => strtotime($row['discharge_date']),
            "doctor_name"     => "Dr. " . trim($row['doctor_first_name'] . " " . $row['doctor_last_name']),
            "diagnosis"       => $row['final_diagnosis'] !== null && $row['final_diagnosis'] !== '' ? $row['final_diagnosis'] : null,
            "notes"           => $row['notes'],
            "medicines"       => [],
        ];
    }

    $dischargeGroups[$fid]['medicines'][] = [
        "name"             => $row['medicine_name'],
        "quantity"         => $row['quantity'],
        "instructions"     => $row['instructions'],
        "dispense_status"  => $row['dispense_status'],
    ];
}
$stmt->close();

// Merge both sources into one list, newest first - a patient looking for
// "my prescriptions" shouldn't have to know or care which table a
// particular medicine came from.
$prescriptions = array_merge(array_values($prescriptionGroups), array_values($dischargeGroups));
usort($prescriptions, function ($a, $b) {
    return $b['sort_ts'] <=> $a['sort_ts'];
});

// Now that each group (from either source) has its full medicine list,
// roll up a single fill status (pending/dispensed/void) for the table's
// STATUS column. Applied uniformly across both sources so a discharge
// group with, say, one dispensed and one still-pending medicine reads
// exactly the same way an outpatient prescription would.
foreach ($prescriptions as &$rx) {
    $rx['fill_status']       = computeFillStatus($rx['medicines']);
    $rx['fill_status_label'] = formatFillStatus($rx['fill_status']);
}
unset($rx);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescriptions - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/prescriptions.css">
</head>

<body>
    <div class="app-layout">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">
            <div class="page-header">
                <h1 class="page-title">Prescriptions</h1>
                <p class="page-subtitle">View your prescription history and medication details.</p>
            </div>

            <div class="card">
                <?php if (empty($prescriptions)): ?>
                    <div class="empty-state">
                        <p class="empty-state-text">No prescriptions found.</p>
                        <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="rx-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Doctor</th>
                                    <th>Diagnosis</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($prescriptions as $rx): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($rx['date']); ?></td>
                                        <td><?php echo htmlspecialchars($rx['doctor_name']); ?></td>
                                        <td><?php echo $rx['diagnosis'] !== null ? htmlspecialchars($rx['diagnosis']) : '<span class="rx-muted">Not available</span>'; ?></td>
                                        <td>
                                            <span class="status-chip status-chip-<?php echo htmlspecialchars($rx['fill_status']); ?>">
                                                <?php echo htmlspecialchars($rx['fill_status_label']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button
                                                type="button"
                                                class="btn-view-rx"
                                                data-source="<?php echo htmlspecialchars($rx['source']); ?>"
                                                data-id="<?php echo (int) $rx['id']; ?>">
                                                View Details
                                            </button>
                                            <?php if ($rx['source'] === 'confinement'): ?>
                                                <?php if (!empty($rx['medicines'])): ?>
                                                    <a
                                                        href="print-pharmacy-slip.php?confinement_id=<?php echo (int) $rx['id']; ?>"
                                                        target="_blank"
                                                        class="btn-view-rx"
                                                        style="margin-left: 8px; display: inline-block; text-decoration: none;">
                                                        Print Pharmacy Slip
                                                    </a>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php if (!empty($rx['medicines'])): ?>
                                                    <a
                                                        href="print-pharmacy-slip.php?consultation_id=<?php echo (int) $rx['id']; ?>"
                                                        target="_blank"
                                                        class="btn-view-rx"
                                                        style="margin-left: 8px; display: inline-block; text-decoration: none;">
                                                        Print Pharmacy Slip
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>
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

    <!-- Prescription Details Modal (single instance, populated via JS) -->
    <div class="rx-modal-overlay" id="rxModalOverlay">
        <div class="rx-modal" role="dialog" aria-modal="true" aria-labelledby="rxModalTitle">
            <div class="rx-modal-header">
                <div class="rx-modal-header-title">
                    <span class="rx-modal-header-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="16" y1="13" x2="8" y2="13"></line>
                            <line x1="16" y1="17" x2="8" y2="17"></line>
                        </svg>
                    </span>
                    <h2 id="rxModalTitle">Prescription Details</h2>
                </div>
                <button type="button" class="rx-modal-close" id="rxModalClose" aria-label="Close">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>

            <div class="rx-modal-body">

                <!-- Doctor / Date metadata -->
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
                            <span class="rx-detail-label">Date</span>
                            <span class="rx-detail-value" id="rxDetailDate">—</span>
                        </div>
                    </div>
                </div>

                <!-- Diagnosis (accent card) -->
                <div class="rx-diagnosis-card">
                    <span class="rx-detail-label">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 11l3 3L22 4"></path>
                            <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                        </svg>
                        Diagnosis
                    </span>
                    <p class="rx-diagnosis-text" id="rxDetailDiagnosis">—</p>
                </div>

                <!-- Notes / Instructions (bordered container) -->
                <div class="rx-notes-card">
                    <span class="rx-detail-label">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                        </svg>
                        Notes
                    </span>
                    <p class="rx-notes-text" id="rxDetailNotes">—</p>
                </div>

                <!-- Medications -->
                <div class="rx-medicines-section">
                    <h3 class="rx-section-title">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.5 20.5 3.5 13.5a4.95 4.95 0 1 1 7-7l7 7a4.95 4.95 0 1 1-7 7Z"></path>
                            <path d="m8.5 8.5 7 7"></path>
                        </svg>
                        Medications
                    </h3>
                    <div id="rxMedicinesList" class="rx-medicines-list">
                        <!-- Populated via JS -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Prescription data for this patient only, already scoped server-side -->
    <script>
        var prescriptionsData = <?php echo json_encode($prescriptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        // Single source of truth for these labels is includes/status_labels.php
        // (get_rx_status_labels()) - embedded here rather than hardcoded in
        // prescriptions.js, so the PHP side can't drift from the JS side. See
        // that file's own header comment for the other half of this.
        var rxStatusLabels = <?php echo json_encode(get_rx_status_labels(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <script src="../assets/js/prescriptions.js"></script>
</body>

</html>