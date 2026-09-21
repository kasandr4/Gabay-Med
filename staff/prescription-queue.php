<?php
// staff/prescription-queue.php
//
// REWORKED 2026-09-17: this used to just link each row out to
// staff/dispense-stock.php?reference_number=... (a separate page where
// staff searched by the reference number printed on the patient's
// handout). Per instructor requirement, that whole page and its
// reference-number lookup step are gone now - dispense-stock.php has
// been deleted. Instead, clicking "Accept" on a row opens a panel right
// here with everything dispense-stock.php used to show (medicines,
// quantities, doctor, diagnosis), a Release button, and a Void button per
// unlinked-catalog line.
//
// The reference number itself hasn't been removed from the security
// model - only the part where staff had to know/type it. Every group
// below still carries its real, signed reference (format_appointment_
// reference()/format_confinement_reference(), same functions
// dispense-stock.php always used), submitted silently in the
// background when Release/Void is clicked. staff/dispense-actions.php
// (completely unchanged by this rework) still independently re-derives
// and verifies that signature server-side before writing anything -
// same "never trust the client" guarantee as before, just without
// asking a human to be the one who carries it from a printed slip to a
// search box.
//
// REMOVED (2026-09-17, per instructor): the "confirm this matches the
// printed prescription" checkbox dispense-stock.php required before
// Release. That checkbox stood in for physical proof the patient was
// present with their handout - removing it (so patients no longer need
// a printout at all) was a deliberate tradeoff, not an oversight. The
// reference-number re-verification above is what's left standing in
// for "this is really the right visit."
//
// "Accept" only opens this panel - it does not write anything to the
// database by itself (unlike staff/lab-queue.php's Accept, which does
// persist a status change). Prescriptions/confinement discharge
// medications only ever move pending -> dispensed or pending -> void;
// there's no in-between "someone's already looking at this" state here.

require_once '../includes/auth_guard.php';
require_role('staff');
require_staff_type('inventory');
require_once '../config/db.php';
require_once '../includes/reference_number.php';

$current_page = 'prescription-queue';

// --- Pending prescriptions (outpatient), full line detail + diagnosis --
$prescriptionGroups = [];
$stmt = $conn->prepare(
    "SELECT c.consultation_id, c.created_at AS consultation_date, c.findings,
            a.appointment_id,
            pat.first_name AS patient_first, pat.last_name AS patient_last,
            doc.first_name AS doctor_first, doc.last_name AS doctor_last,
            p.prescription_id, p.medicine_name, p.medicine_id, p.quantity, p.instructions,
            im.unit, im.current_stock
     FROM prescriptions p
     JOIN consultations c ON c.consultation_id = p.consultation_id
     JOIN appointments a ON a.appointment_id = c.appointment_id
     JOIN users pat ON pat.user_id = p.patient_id
     JOIN users doc ON doc.user_id = p.doctor_id
     LEFT JOIN inventory_medicines im ON im.medicine_id = p.medicine_id
     WHERE p.status = 'pending'
     ORDER BY c.created_at ASC, p.prescription_id ASC"
);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($rows as $row) {
    $key = 'rx-' . $row['consultation_id'];
    if (!isset($prescriptionGroups[$key])) {
        $prescriptionGroups[$key] = [
            'group_key' => $key,
            'type' => 'outpatient',
            'reference' => format_appointment_reference((int) $row['appointment_id'], $row['consultation_date']),
            'appointment_id' => (int) $row['appointment_id'],
            'consultation_id' => (int) $row['consultation_id'],
            'patient_name' => trim($row['patient_first'] . ' ' . $row['patient_last']),
            'doctor_name' => 'Dr. ' . trim($row['doctor_first'] . ' ' . $row['doctor_last']),
            // "findings" is the closest thing this schema has to a
            // diagnosis field - see doctor/consultation.php, where it's
            // captured as "clinical findings and observations" at the
            // point of care. Shown here labeled "Diagnosis" since that's
            // what it functionally is for this purpose.
            'diagnosis' => $row['findings'] !== '' ? $row['findings'] : '—',
            'date' => $row['consultation_date'],
            'lines' => [],
        ];
    }

    $parsedQty = null;
    if (preg_match('/^\d+$/', trim((string) $row['quantity']))) {
        $parsedQty = (int) $row['quantity'];
    }
    $linked = $row['medicine_id'] !== null;
    $stock = $linked ? (int) $row['current_stock'] : null;

    $prescriptionGroups[$key]['lines'][] = [
        'prescription_id' => (int) $row['prescription_id'],
        'medicine_id' => $linked ? (int) $row['medicine_id'] : null,
        'medicine_name' => $row['medicine_name'],
        'quantity_label' => $row['quantity'] !== null && $row['quantity'] !== '' ? $row['quantity'] : '—',
        'parsed_qty' => $parsedQty,
        'instructions' => $row['instructions'],
        'unit' => $row['unit'] ?? '',
        'current_stock' => $stock,
        // Void is only ever offered for the "not linked" case, never for
        // "linked but zero stock" - matches dispense-actions.php's own
        // void_prescription_line, which rejects voiding a line that
        // already has a medicine_id (see that action's own check).
        'blocked_reason' => !$linked
            ? 'Not in the pharmacy catalog — cannot dispense here.'
            : ($stock <= 0 ? 'Out of stock.' : null),
    ];
}

// --- Pending confinement discharge medications, with the confinement's
// real final_diagnosis column (a genuine diagnosis field, unlike the
// outpatient side above which has to borrow "findings" for this).
$stmt = $conn->prepare(
    "SELECT c.confinement_id, c.created_at AS confinement_created_at, c.final_diagnosis,
            pat.first_name AS patient_first, pat.last_name AS patient_last,
            doc.first_name AS doctor_first, doc.last_name AS doctor_last,
            cdm.discharge_medication_id, cdm.medicine_name, cdm.medicine_id, cdm.quantity, cdm.instructions,
            im.unit, im.current_stock
     FROM confinement_discharge_medications cdm
     JOIN confinements c ON c.confinement_id = cdm.confinement_id
     JOIN users pat ON pat.user_id = c.patient_id
     JOIN users doc ON doc.user_id = c.attending_doctor_id
     LEFT JOIN inventory_medicines im ON im.medicine_id = cdm.medicine_id
     WHERE cdm.status = 'pending'
     ORDER BY c.created_at ASC, cdm.discharge_medication_id ASC"
);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($rows as $row) {
    $key = 'cf-' . $row['confinement_id'];
    if (!isset($prescriptionGroups[$key])) {
        $prescriptionGroups[$key] = [
            'group_key' => $key,
            'type' => 'confinement',
            'reference' => format_confinement_reference((int) $row['confinement_id'], $row['confinement_created_at']),
            'confinement_id' => (int) $row['confinement_id'],
            'patient_name' => trim($row['patient_first'] . ' ' . $row['patient_last']),
            'doctor_name' => 'Dr. ' . trim($row['doctor_first'] . ' ' . $row['doctor_last']),
            'diagnosis' => $row['final_diagnosis'] !== null && $row['final_diagnosis'] !== '' ? $row['final_diagnosis'] : '—',
            'date' => $row['confinement_created_at'],
            'lines' => [],
        ];
    }

    $parsedQty = null;
    if (preg_match('/^\d+$/', trim((string) $row['quantity']))) {
        $parsedQty = (int) $row['quantity'];
    }
    $linked = $row['medicine_id'] !== null;
    $stock = $linked ? (int) $row['current_stock'] : null;

    $prescriptionGroups[$key]['lines'][] = [
        'discharge_medication_id' => (int) $row['discharge_medication_id'],
        'medicine_id' => $linked ? (int) $row['medicine_id'] : null,
        'medicine_name' => $row['medicine_name'],
        'quantity_label' => $row['quantity'] !== null && $row['quantity'] !== '' ? $row['quantity'] : '—',
        'parsed_qty' => $parsedQty,
        'instructions' => $row['instructions'],
        'unit' => $row['unit'] ?? '',
        'current_stock' => $stock,
        'blocked_reason' => !$linked
            ? 'Not in the pharmacy catalog — cannot dispense here.'
            : ($stock <= 0 ? 'Out of stock.' : null),
    ];
}

$prescriptionGroups = array_values($prescriptionGroups);
usort($prescriptionGroups, fn($a, $b) => strtotime($a['date']) <=> strtotime($b['date']));
foreach ($prescriptionGroups as &$g) {
    $g['medicine_count'] = count($g['lines']);
}
unset($g);

// --- Recent dispenses (moved here from the now-deleted dispense-stock.php
// - same query, unchanged, just relocated so this visibility isn't lost
// along with that page.
$recentDispenses = [];
$logResult = $conn->query(
    "SELECT mel.log_id, im.name AS medicine, im.unit, mel.boxes_scanned AS pieces,
            CONCAT(u.first_name, ' ', u.last_name) AS staff_name, mel.scanned_at,
            COALESCE(pat.first_name, cpat.first_name) AS patient_first,
            COALESCE(pat.last_name, cpat.last_name) AS patient_last
     FROM medicine_exit_log mel
     JOIN inventory_medicines im ON im.medicine_id = mel.medicine_id
     JOIN users u ON u.user_id = mel.staff_id
     LEFT JOIN prescriptions p ON p.prescription_id = mel.prescription_id
     LEFT JOIN users pat ON pat.user_id = p.patient_id
     LEFT JOIN confinement_discharge_medications cdm ON cdm.discharge_medication_id = mel.confinement_discharge_medication_id
     LEFT JOIN confinements cf ON cf.confinement_id = cdm.confinement_id
     LEFT JOIN users cpat ON cpat.user_id = cf.patient_id
     ORDER BY mel.scanned_at DESC, mel.log_id DESC
     LIMIT 15"
);
if ($logResult) {
    while ($row = $logResult->fetch_assoc()) {
        $recentDispenses[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescription Queue - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/pharmacist-dashboard.css">
    <style>
        .rx-void-btn {
            background: #ffffff;
            color: #b91c1c;
            border: 1px solid #f3c6c2;
            white-space: nowrap;
        }

        .rx-void-btn:hover {
            background: #fcebea;
        }

        #pqPanelOverlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.45);
            z-index: 100;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        #pqPanelOverlay.panel-visible {
            display: flex;
        }

        #pqPanel {
            background: #fff;
            border-radius: 12px;
            width: 100%;
            max-width: 960px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 28px 32px;
        }

        /* Fixed-ish column widths so a 5-column row (medicine, prescribed
           qty, instructions, in-stock/blocked reason, action) has room to
           breathe instead of forcing horizontal scroll - was cramped
           enough at 720px that "In Stock"/blocked-reason text and the
           action button both got cut off. */
        #pqLinesBody td:nth-child(1) {
            min-width: 140px;
        }

        #pqLinesBody td:nth-child(3) {
            min-width: 160px;
        }

        #pqLinesBody td:nth-child(4) {
            min-width: 200px;
        }

        #pqLinesBody td:nth-child(5) {
            min-width: 130px;
        }

        #pqVoidOverlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.55);
            z-index: 110;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        #pqVoidOverlay.panel-visible {
            display: flex;
        }

        #pqVoidModal {
            background: #fff;
            border-radius: 12px;
            width: 100%;
            max-width: 440px;
            padding: 20px;
        }
    </style>
</head>

<body>
    <div class="app-shell">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">

            <div class="page-header">
                <h1>Prescription Queue</h1>
                <p>Prescriptions and discharge medications waiting to be dispensed, oldest first.</p>
            </div>

            <div class="card">
                <?php if (empty($prescriptionGroups)): ?>
                    <div class="empty-state">
                        <p>Nothing waiting right now.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Doctor</th>
                                    <th>Type</th>
                                    <th>Prescribed</th>
                                    <th>Medicines</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($prescriptionGroups as $group): ?>
                                    <tr data-pq-row="<?php echo htmlspecialchars($group['group_key']); ?>">
                                        <td><?php echo htmlspecialchars($group['patient_name']); ?></td>
                                        <td><?php echo htmlspecialchars($group['doctor_name']); ?></td>
                                        <td><?php echo $group['type'] === 'confinement' ? 'Discharge meds' : 'Outpatient'; ?></td>
                                        <td><?php echo date('M j, Y g:i A', strtotime($group['date'])); ?></td>
                                        <td><?php echo $group['medicine_count']; ?> item<?php echo $group['medicine_count'] === 1 ? '' : 's'; ?></td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-primary pq-accept-btn" data-group-key="<?php echo htmlspecialchars($group['group_key']); ?>">
                                                Accept
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card" style="margin-top:20px;">
                <div class="card-header">
                    <h2>Recent Dispenses</h2>
                </div>
                <?php if (empty($recentDispenses)): ?>
                    <div class="empty-state">
                        <p>Nothing dispensed yet.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Medicine</th>
                                    <th>Pieces</th>
                                    <th>Dispensed By</th>
                                    <th>When</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentDispenses as $d): ?>
                                    <tr>
                                        <td><?php echo $d['patient_first'] ? htmlspecialchars(trim($d['patient_first'] . ' ' . $d['patient_last'])) : '—'; ?></td>
                                        <td><?php echo htmlspecialchars($d['medicine']); ?></td>
                                        <td><?php echo (int) $d['pieces']; ?> <?php echo htmlspecialchars($d['unit']); ?>(s)</td>
                                        <td><?php echo htmlspecialchars($d['staff_name']); ?></td>
                                        <td><?php echo date('M j, Y g:i A', strtotime($d['scanned_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>

    <!-- Accept panel - populated entirely from prescriptionQueueData by
         assets/js/prescription-queue.js, one shared panel reused for
         whichever row's Accept button was clicked. -->
    <div id="pqPanelOverlay">
        <div id="pqPanel" role="dialog" aria-modal="true" aria-labelledby="pqPanelTitle">
            <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:16px;">
                <h2 id="pqPanelTitle" style="margin:0;">Dispense</h2>
                <button type="button" class="btn btn-sm btn-secondary" id="pqCloseBtn">Close</button>
            </div>

            <div class="patient-info-grid" style="margin-bottom:16px;">
                <div class="info-item">
                    <span class="info-label">Patient</span>
                    <span class="info-value" id="pqPatient">—</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Doctor</span>
                    <span class="info-value" id="pqDoctor">—</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Diagnosis</span>
                    <span class="info-value" id="pqDiagnosis">—</span>
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>Prescribed Qty</th>
                            <th>Instructions</th>
                            <th>In Stock</th>
                            <th>Pieces to Dispense</th>
                        </tr>
                    </thead>
                    <tbody id="pqLinesBody"></tbody>
                </table>
            </div>

            <p id="pqError" hidden style="color:#b91c1c; margin-top:10px;"></p>
            <p id="pqBlockedNotice" hidden style="background:#FFF7E6; border:1px solid #F0C36D; border-radius:8px; padding:10px 14px; font-size:13px; margin-top:10px;"></p>

            <div style="margin-top:16px;">
                <button type="button" class="btn btn-primary" id="pqReleaseBtn">Release</button>
            </div>
        </div>
    </div>

    <!-- Void confirmation - a second, smaller overlay on top of the panel -->
    <div id="pqVoidOverlay">
        <div id="pqVoidModal">
            <h3 style="margin-top:0;">Void <span id="pqVoidMedicineName"></span>?</h3>
            <p style="margin-bottom:6px; color:var(--text-secondary,#555); font-size:13.5px;">This line isn't in the pharmacy catalog and can't be dispensed here. Voiding it removes just this line — the patient will see it as "Void" instead of "Pending," and any other medicines on the same visit can still be dispensed normally.</p>
            <p id="pqVoidError" hidden style="color:#b91c1c;"></p>
            <div style="display:flex; gap:10px; margin-top:14px;">
                <button type="button" class="btn btn-secondary" id="pqVoidCancelBtn">Cancel</button>
                <button type="button" class="btn btn-primary rx-void-btn" id="pqVoidConfirmBtn">Void This Line</button>
            </div>
        </div>
    </div>

    <script>
        const csrfToken = <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>;
        var prescriptionQueueData = <?php echo json_encode($prescriptionGroups, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <script src="../assets/js/prescription-queue.js"></script>
</body>

</html>