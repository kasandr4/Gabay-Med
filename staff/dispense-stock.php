<?php
// staff/dispense-stock.php
// REWRITTEN (2026-09-05) per instructor requirement: dispensing must now
// be driven by an actual doctor's prescription, not a free-form manual
// cart. Staff look up the visit by the same signed reference number
// printed on doctor/print-consultation.php's handout (or typed in from
// the patient's paper), see exactly what the doctor ordered, and can
// only dispense the medicines that are actually part of that
// prescription — each one already linked to a real inventory_medicines
// row (see 022_link_prescriptions_to_dispense.sql and
// doctor/consultation-process.php, which resolves that link server-side
// at prescribing time).
//
// This replaces the previous "Dispense Stock" cart, which was
// deliberately NOT tied to prescriptions. That manual, pick-anything
// cart is gone — every dispense from this screen now traces back to a
// specific prescription_id, and prescriptions.status flips to
// 'dispensed' once it's filled. Nothing is deleted anywhere in this
// flow, consistent with the rest of the app's archive-only rule.
//
// VERIFICATION: the point of this screen is the same idea as check-in.php
// — the reference number is unforgeable (HMAC-signed, see
// includes/reference_number.php), so pulling up a match by it is itself
// a check that what's on screen corresponds to the real visit. Staff
// still has to visually confirm the medicines/quantities on screen match
// what's printed on the paper the patient is holding before checking the
// confirmation box below the list; the printed handout is what stands in
// for physical presence of the prescription.
//
// FEFO batch deduction, reorder-point notifications, and audit logging
// are unchanged from the old dispense-actions.php — only the source of
// what's being dispensed changed (a prescription's lines instead of a
// freely-chosen cart).

require_once '../includes/auth_guard.php';
require_role('staff');
require_staff_type('inventory');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/reference_number.php';

$current_page = 'dispense';
$today = date("F j, Y");

// --- Look up a reference number (GET, triggered by the search form) ------
// Same pattern as staff/check-in.php: decode first, re-derive and compare
// before trusting anything the candidate row returns.
//
// EXTENDED (2026-09-05): a reference can now be either an appointment's
// ("GM-...", an outpatient prescription) or a confinement's ("GMC-...",
// discharge medications) — see includes/reference_number.php's header
// comment for why the two prefixes can never collide. $matchType tracks
// which one, if either, matched, so the rest of the page knows which
// pending-medicines table to query and which dispense action to wire up.
$match = null;
$matchType = null; // 'appointment' | 'confinement' | null
$searchedRef = trim($_GET['reference_number'] ?? '');

if ($searchedRef !== '') {
    $appointmentLookupId = parse_appointment_reference($searchedRef);
    $confinementLookupId = parse_confinement_reference($searchedRef);

    if ($appointmentLookupId !== null) {
        $stmt = $conn->prepare(
            "SELECT a.appointment_id, a.created_at,
                    pat.user_id AS patient_id, pat.first_name, pat.last_name,
                    d.department_name,
                    doc.first_name AS doctor_first_name, doc.last_name AS doctor_last_name
             FROM appointments a
             JOIN users pat ON pat.user_id = a.patient_id
             JOIN departments d ON d.department_id = a.department_id
             JOIN users doc ON doc.user_id = a.doctor_id
             WHERE a.appointment_id = ?
             LIMIT 1"
        );
        $stmt->bind_param("i", $appointmentLookupId);
        $stmt->execute();
        $candidate = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($candidate && verify_appointment_reference($searchedRef, $candidate['appointment_id'], $candidate['created_at'])) {
            $match = $candidate;
            $matchType = 'appointment';
        }
    } elseif ($confinementLookupId !== null) {
        $stmt = $conn->prepare(
            "SELECT c.confinement_id, c.created_at, c.discharge_date, c.room_location,
                    pat.user_id AS patient_id, pat.first_name, pat.last_name,
                    doc.first_name AS doctor_first_name, doc.last_name AS doctor_last_name
             FROM confinements c
             JOIN users pat ON pat.user_id = c.patient_id
             JOIN users doc ON doc.user_id = c.attending_doctor_id
             WHERE c.confinement_id = ?
             LIMIT 1"
        );
        $stmt->bind_param("i", $confinementLookupId);
        $stmt->execute();
        $candidate = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($candidate && verify_confinement_reference($searchedRef, $candidate['confinement_id'], $candidate['created_at'])) {
            $match = $candidate;
            $matchType = 'confinement';
        }
    }
}

// --- Pending prescriptions for the matched visit, grouped by consultation
// (one consultation = one prescribing event = one thing to dispense as a
// unit — see dispense-actions.php's dispense_prescription action, which
// applies every line in a consultation's prescription together or not
// at all). Each line carries its linked catalog stock (if any) so the
// page can flag anything that can't actually be dispensed yet.
$prescriptionGroups = []; // consultation_id => { doctor_name, created_at, lines: [...] }
if ($matchType === 'appointment') {
    $stmt = $conn->prepare(
        "SELECT c.consultation_id, c.created_at AS consultation_date,
                doc.first_name AS doctor_first, doc.last_name AS doctor_last,
                p.prescription_id, p.medicine_name, p.medicine_id, p.quantity, p.instructions,
                im.unit, im.current_stock
         FROM consultations c
         JOIN prescriptions p ON p.consultation_id = c.consultation_id
         JOIN users doc ON doc.user_id = c.doctor_id
         LEFT JOIN inventory_medicines im ON im.medicine_id = p.medicine_id
         WHERE c.appointment_id = ? AND p.status = 'pending'
         ORDER BY c.created_at DESC, p.prescription_id ASC"
    );
    $stmt->bind_param("i", $match['appointment_id']);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as $row) {
        $cid = (int) $row['consultation_id'];
        if (!isset($prescriptionGroups[$cid])) {
            $prescriptionGroups[$cid] = [
                "consultation_id" => $cid,
                "doctor_name" => "Dr. " . trim($row['doctor_first'] . ' ' . $row['doctor_last']),
                "date" => $row['consultation_date'],
                "lines" => [],
            ];
        }

        // Parse a plain integer piece count out of quantity where possible
        // (e.g. "20" -> 20) so the input can be prefilled; a non-numeric
        // quantity (e.g. free text) is left blank for staff to fill in.
        $parsedQty = null;
        if (preg_match('/^\d+$/', trim((string) $row['quantity']))) {
            $parsedQty = (int) $row['quantity'];
        }

        $linked = $row['medicine_id'] !== null;
        $stock = $linked ? (int) $row['current_stock'] : null;

        $prescriptionGroups[$cid]['lines'][] = [
            "prescription_id" => (int) $row['prescription_id'],
            "medicine_id" => $linked ? (int) $row['medicine_id'] : null,
            "medicine_name" => $row['medicine_name'],
            "quantity_label" => $row['quantity'] !== null && $row['quantity'] !== '' ? $row['quantity'] : '—',
            "parsed_qty" => $parsedQty,
            "instructions" => $row['instructions'],
            "unit" => $row['unit'] ?? '',
            "current_stock" => $stock,
            // A line can't be dispensed here if it was never linked to a
            // catalog item (legacy free-text prescriptions from before
            // this feature, or a medicine since removed from the
            // catalog) or if the catalog item is out of stock.
            "blocked_reason" => !$linked
                ? "Not in the pharmacy catalog — cannot verify or dispense here."
                : ($stock <= 0 ? "Out of stock." : null),
        ];
    }
}

// --- Pending discharge medications for a matched confinement. One
// confinement's discharge is a single event, so this is just a flat line
// list (no per-consultation grouping needed like the prescription side).
$confinementMedLines = [];
if ($matchType === 'confinement') {
    $stmt = $conn->prepare(
        "SELECT cdm.discharge_medication_id, cdm.medicine_name, cdm.medicine_id, cdm.quantity, cdm.instructions,
                im.unit, im.current_stock
         FROM confinement_discharge_medications cdm
         LEFT JOIN inventory_medicines im ON im.medicine_id = cdm.medicine_id
         WHERE cdm.confinement_id = ? AND cdm.status = 'pending'
         ORDER BY cdm.discharge_medication_id ASC"
    );
    $stmt->bind_param("i", $match['confinement_id']);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as $row) {
        $parsedQty = null;
        if (preg_match('/^\d+$/', trim((string) $row['quantity']))) {
            $parsedQty = (int) $row['quantity'];
        }

        $linked = $row['medicine_id'] !== null;
        $stock = $linked ? (int) $row['current_stock'] : null;

        $confinementMedLines[] = [
            "discharge_medication_id" => (int) $row['discharge_medication_id'],
            "medicine_id" => $linked ? (int) $row['medicine_id'] : null,
            "medicine_name" => $row['medicine_name'],
            "quantity_label" => $row['quantity'] !== null && $row['quantity'] !== '' ? $row['quantity'] : '—',
            "parsed_qty" => $parsedQty,
            "instructions" => $row['instructions'],
            "unit" => $row['unit'] ?? '',
            "current_stock" => $stock,
            "blocked_reason" => !$linked
                ? "Not in the pharmacy catalog — cannot verify or dispense here."
                : ($stock <= 0 ? "Out of stock." : null),
        ];
    }
}

// --- Recent dispenses (either source) for the log table under the form.
// LEFT JOINs both prescriptions and confinement_discharge_medications so
// each row can be attributed to a patient regardless of which flow
// produced it; a pre-migration manual dispense (both IDs NULL) still
// renders, just without a patient name.
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
    <title>Dispense Medicines - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/pharmacist-dashboard.css">
    <style>
        /* "Mark as Void" (2026-09-07) — scoped to this page rather than
           added to the shared stylesheets, matching the existing
           inline-style convention already used throughout this file. Red
           outline echoes the #b91c1c used for blocked-line text above. */
        .rx-void-btn {
            background: #ffffff;
            color: #b91c1c;
            border: 1px solid #f3c6c2;
            white-space: nowrap;
        }

        .rx-void-btn:hover {
            background: #fcebea;
        }
    </style>
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <!-- Main content -->
        <main class="main-content" style="margin: 0 auto; max-width: 900px;">

            <header class="page-header">
                <div>
                    <h1>Dispense Medicines</h1>
                    <p class="page-subtitle">Look up a visit or confinement by reference number, verify against the printed handout, then dispense.</p>
                </div>
                <div class="header-actions">
                    <span class="header-date"><?php echo htmlspecialchars($today); ?></span>
                </div>
            </header>

            <!-- Search -->
            <section class="card">
                <div class="card-header">
                    <h2>Look Up Medicines to Dispense</h2>
                </div>
                <form method="GET" action="dispense-stock.php" class="consultation-form">
                    <div class="form-group">
                        <label for="reference_number" class="form-label">Reference Number</label>
                        <input
                            type="text"
                            id="reference_number"
                            name="reference_number"
                            class="form-input"
                            placeholder="e.g. GM-2026-000047-A3F9 or GMC-2026-000012-B7E1"
                            value="<?php echo htmlspecialchars($searchedRef); ?>"
                            autofocus
                            required>
                        <span class="form-hint">An outpatient prescription's reference starts with "GM-"; a confinement discharge's starts with "GMC-". Both are printed on the patient's handout.</span>
                    </div>
                    <button type="submit" class="btn btn-primary">Look Up</button>
                </form>
            </section>

            <?php if ($searchedRef !== ''): ?>
                <section class="card" style="margin-top: 20px;">
                    <div class="card-header">
                        <h2>Result</h2>
                    </div>

                    <?php if (!$match): ?>
                        <div class="empty-state">
                            <p>No visit or confinement found for reference number <strong><?php echo htmlspecialchars($searchedRef); ?></strong>. Double-check the number printed on the handout.</p>
                        </div>
                    <?php elseif ($matchType === 'appointment'): ?>
                        <div class="patient-info-grid">
                            <div class="info-item">
                                <span class="info-label">Patient</span>
                                <span class="info-value"><?php echo htmlspecialchars(trim($match['first_name'] . ' ' . $match['last_name'])); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Department</span>
                                <span class="info-value"><?php echo htmlspecialchars($match['department_name']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Doctor</span>
                                <span class="info-value">Dr. <?php echo htmlspecialchars(trim($match['doctor_first_name'] . ' ' . $match['doctor_last_name'])); ?></span>
                            </div>
                        </div>

                        <?php if (empty($prescriptionGroups)): ?>
                            <div class="empty-state" style="margin-top: 16px;">
                                <p>No pending prescription for this visit — either nothing was prescribed, or it's already been dispensed.</p>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="patient-info-grid">
                            <div class="info-item">
                                <span class="info-label">Patient</span>
                                <span class="info-value"><?php echo htmlspecialchars(trim($match['first_name'] . ' ' . $match['last_name'])); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Room</span>
                                <span class="info-value"><?php echo htmlspecialchars($match['room_location'] ?? '—'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Attending Doctor</span>
                                <span class="info-value">Dr. <?php echo htmlspecialchars(trim($match['doctor_first_name'] . ' ' . $match['doctor_last_name'])); ?></span>
                            </div>
                        </div>

                        <?php if ($match['discharge_date'] === null): ?>
                            <div class="empty-state" style="margin-top: 16px;">
                                <p>This patient hasn't been discharged yet — discharge medications aren't finalized until the doctor completes discharge.</p>
                            </div>
                        <?php elseif (empty($confinementMedLines)): ?>
                            <div class="empty-state" style="margin-top: 16px;">
                                <p>No pending discharge medications for this confinement — either none were prescribed, or they've already been dispensed.</p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php foreach ($prescriptionGroups as $group): ?>
                <?php
                // Nothing to actually complete-dispense if every line on
                // this prescription is blocked (not in the catalog, or
                // out of stock) — the verify checkbox + Complete Dispense
                // button below only make sense when at least one line
                // has a real piece-count input to fill in.
                $groupHasDispensable = false;
                foreach ($group['lines'] as $groupLine) {
                    if (!$groupLine['blocked_reason']) {
                        $groupHasDispensable = true;
                        break;
                    }
                }
                ?>
                <section class="card prescription-dispense-card" style="margin-top: 20px;" data-consultation-id="<?php echo $group['consultation_id']; ?>">
                    <div class="card-header">
                        <h2>Prescription — <?php echo htmlspecialchars($group['doctor_name']); ?></h2>
                        <span class="card-subtitle">Issued <?php echo htmlspecialchars(date("M j, Y g:i A", strtotime($group['date']))); ?></span>
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
                            <tbody>
                                <?php foreach ($group['lines'] as $line): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($line['medicine_name']); ?></td>
                                        <td><?php echo htmlspecialchars($line['quantity_label']); ?></td>
                                        <td><?php echo htmlspecialchars($line['instructions'] ?? '—'); ?></td>
                                        <td>
                                            <?php if ($line['blocked_reason']): ?>
                                                <span style="color:#b91c1c;"><?php echo htmlspecialchars($line['blocked_reason']); ?></span>
                                            <?php else: ?>
                                                <?php echo (int) $line['current_stock']; ?> <?php echo htmlspecialchars($line['unit']); ?>(s)
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($line['medicine_id'] === null): ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm rx-void-btn"
                                                    data-void-type="prescription"
                                                    data-line-id="<?php echo (int) $line['prescription_id']; ?>"
                                                    data-medicine-name="<?php echo htmlspecialchars($line['medicine_name']); ?>">
                                                    Mark as Void
                                                </button>
                                            <?php elseif ($line['blocked_reason']): ?>
                                                <input type="number" min="1" step="1" disabled style="width:80px;">
                                            <?php else: ?>
                                                <input
                                                    type="number"
                                                    min="1"
                                                    step="1"
                                                    style="width:80px;"
                                                    class="rx-pieces-input"
                                                    data-prescription-id="<?php echo (int) $line['prescription_id']; ?>"
                                                    data-medicine-id="<?php echo (int) $line['medicine_id']; ?>"
                                                    data-max-stock="<?php echo (int) $line['current_stock']; ?>"
                                                    value="<?php echo $line['parsed_qty'] !== null ? (int) $line['parsed_qty'] : ''; ?>">
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <p class="scan-inline-error rx-dispense-error" hidden></p>

                    <?php if ($groupHasDispensable): ?>
                        <div class="form-group" style="margin-top: 16px;">
                            <label style="display:flex; align-items:flex-start; gap:8px; font-weight:normal;">
                                <input type="checkbox" class="rx-verify-checkbox" required>
                                I have compared this against the printed prescription the patient presented, and it matches.
                            </label>
                        </div>

                        <button type="button" class="btn btn-primary rx-complete-btn">Complete Dispense</button>
                    <?php else: ?>
                        <p class="form-hint" style="margin-top: 16px;">Nothing left to dispense on this prescription — void the line(s) above, or add the medicine to the catalog, before it can be completed.</p>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

            <?php if ($matchType === 'confinement' && !empty($confinementMedLines)): ?>
                <?php
                $confinementHasDispensable = false;
                foreach ($confinementMedLines as $cmLine) {
                    if (!$cmLine['blocked_reason']) {
                        $confinementHasDispensable = true;
                        break;
                    }
                }
                ?>
                <section class="card confinement-dispense-card" style="margin-top: 20px;" data-confinement-id="<?php echo (int) $match['confinement_id']; ?>">
                    <div class="card-header">
                        <h2>Discharge Medications — Dr. <?php echo htmlspecialchars(trim($match['doctor_first_name'] . ' ' . $match['doctor_last_name'])); ?></h2>
                        <span class="card-subtitle">Medications to continue after discharge</span>
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
                            <tbody>
                                <?php foreach ($confinementMedLines as $line): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($line['medicine_name']); ?></td>
                                        <td><?php echo htmlspecialchars($line['quantity_label']); ?></td>
                                        <td><?php echo htmlspecialchars($line['instructions'] ?? '—'); ?></td>
                                        <td>
                                            <?php if ($line['blocked_reason']): ?>
                                                <span style="color:#b91c1c;"><?php echo htmlspecialchars($line['blocked_reason']); ?></span>
                                            <?php else: ?>
                                                <?php echo (int) $line['current_stock']; ?> <?php echo htmlspecialchars($line['unit']); ?>(s)
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($line['medicine_id'] === null): ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm rx-void-btn"
                                                    data-void-type="confinement"
                                                    data-line-id="<?php echo (int) $line['discharge_medication_id']; ?>"
                                                    data-medicine-name="<?php echo htmlspecialchars($line['medicine_name']); ?>">
                                                    Mark as Void
                                                </button>
                                            <?php elseif ($line['blocked_reason']): ?>
                                                <input type="number" min="1" step="1" disabled style="width:80px;">
                                            <?php else: ?>
                                                <input
                                                    type="number"
                                                    min="1"
                                                    step="1"
                                                    style="width:80px;"
                                                    class="rx-pieces-input"
                                                    data-discharge-medication-id="<?php echo (int) $line['discharge_medication_id']; ?>"
                                                    data-medicine-id="<?php echo (int) $line['medicine_id']; ?>"
                                                    data-max-stock="<?php echo (int) $line['current_stock']; ?>"
                                                    value="<?php echo $line['parsed_qty'] !== null ? (int) $line['parsed_qty'] : ''; ?>">
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <p class="scan-inline-error rx-dispense-error" hidden></p>

                    <?php if ($confinementHasDispensable): ?>
                        <div class="form-group" style="margin-top: 16px;">
                            <label style="display:flex; align-items:flex-start; gap:8px; font-weight:normal;">
                                <input type="checkbox" class="rx-verify-checkbox" required>
                                I have compared this against the printed discharge summary the patient presented, and it matches.
                            </label>
                        </div>

                        <button type="button" class="btn btn-primary rx-complete-btn">Complete Dispense</button>
                    <?php else: ?>
                        <p class="form-hint" style="margin-top: 16px;">Nothing left to dispense on this discharge — void the line(s) above, or add the medicine to the catalog, before it can be completed.</p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="card" style="margin-top: 20px;">
                <div class="card-header">
                    <h2>Recent Dispenses</h2>
                    <span class="card-subtitle">Last 15 items released, most recent first</span>
                </div>

                <?php if (empty($recentDispenses)): ?>
                    <div class="empty-state">
                        <p>No stock has been dispensed yet.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="queue-table" id="dispenseLogTable">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Pieces</th>
                                    <th>Patient</th>
                                    <th>Dispensed By</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentDispenses as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['medicine']); ?></td>
                                        <td><?php echo (int) $row['pieces']; ?> <?php echo htmlspecialchars($row['unit']); ?>(s)</td>
                                        <td><?php echo $row['patient_first'] ? htmlspecialchars(trim($row['patient_first'] . ' ' . $row['patient_last'])) : '—'; ?></td>
                                        <td><?php echo htmlspecialchars($row['staff_name']); ?></td>
                                        <td><?php echo htmlspecialchars(date('M j, g:i A', strtotime($row['scanned_at']))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <div class="scan-toast-host" id="dispenseToastHost"></div>

    <!-- Mark as Void confirmation modal (2026-09-07) — one shared modal for
         every "Mark as Void" button on the page; JS below fills in the
         medicine name and wires the target line before showing it. -->
    <div class="confirm-modal-backdrop" id="voidConfirmModal">
        <div class="confirm-modal-box" role="dialog" aria-modal="true" aria-labelledby="voidConfirmTitle">
            <div class="confirm-modal-icon">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"></path>
                    <line x1="12" y1="9" x2="12" y2="13"></line>
                    <line x1="12" y1="17" x2="12.01" y2="17"></line>
                </svg>
            </div>
            <h3 id="voidConfirmTitle">Void <span id="voidConfirmMedicineName"></span>?</h3>
            <p>This line isn't in the pharmacy catalog and can't be dispensed here. Voiding it removes just this line from the prescription — the patient will see it as "Void" instead of "Pending," and any other medicines on the same prescription can still be dispensed normally.</p>
            <p class="scan-inline-error" id="voidConfirmError" hidden></p>
            <div class="confirm-modal-actions">
                <button type="button" class="btn btn-secondary" id="voidConfirmCancel">Cancel</button>
                <button type="button" class="btn btn-primary" id="voidConfirmProceed" style="background:#b91c1c;">Void This Line</button>
            </div>
        </div>
    </div>

    <script>
        const csrfToken = "<?php echo csrf_token(); ?>";
        const appointmentId = <?php echo $matchType === 'appointment' ? (int) $match['appointment_id'] : 'null'; ?>;
        const confinementId = <?php echo $matchType === 'confinement' ? (int) $match['confinement_id'] : 'null'; ?>;
        const referenceNumber = <?php
                                if ($matchType === 'appointment') {
                                    echo json_encode(format_appointment_reference($match['appointment_id'], $match['created_at']));
                                } elseif ($matchType === 'confinement') {
                                    echo json_encode(format_confinement_reference($match['confinement_id'], $match['created_at']));
                                } else {
                                    echo 'null';
                                }
                                ?>;

        function showToast(message) {
            const host = document.getElementById('dispenseToastHost');
            const toast = document.createElement('div');
            toast.className = 'scan-toast';
            toast.textContent = message;
            host.appendChild(toast);
            requestAnimationFrame(() => toast.classList.add('scan-toast-show'));
            setTimeout(() => {
                toast.classList.remove('scan-toast-show');
                setTimeout(() => toast.remove(), 250);
            }, 2400);
        }

        // Voiding a line changes which prescriptions.rows are 'pending' —
        // the same set dispense-actions.php treats as each consultation's
        // all-or-nothing group — so after a void the safest way to show an
        // accurate page (blocked line gone, remaining lines now
        // dispensable on their own, or the whole card gone if that was the
        // only line) is a fresh page load rather than hand-patching the
        // DOM. sessionStorage carries the toast message across that reload.
        const pendingToast = sessionStorage.getItem('gabaymed_dispense_toast');
        if (pendingToast) {
            sessionStorage.removeItem('gabaymed_dispense_toast');
            showToast(pendingToast);
        }

        // ---------------- Mark as Void ----------------
        const voidModal = document.getElementById('voidConfirmModal');
        const voidConfirmError = document.getElementById('voidConfirmError');
        const voidConfirmMedicineName = document.getElementById('voidConfirmMedicineName');
        const voidConfirmProceed = document.getElementById('voidConfirmProceed');
        const voidConfirmCancel = document.getElementById('voidConfirmCancel');
        let voidTarget = null; // { type: 'prescription'|'confinement', lineId, medicineName }

        function openVoidModal(type, lineId, medicineName) {
            voidTarget = {
                type,
                lineId,
                medicineName
            };
            voidConfirmMedicineName.textContent = medicineName;
            voidConfirmError.hidden = true;
            voidConfirmProceed.disabled = false;
            voidConfirmProceed.textContent = 'Void This Line';
            voidModal.classList.add('active');
            document.body.classList.add('modal-open');
            voidConfirmProceed.focus();
        }

        function closeVoidModal() {
            voidModal.classList.remove('active');
            document.body.classList.remove('modal-open');
            voidTarget = null;
        }

        document.querySelectorAll('.rx-void-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                openVoidModal(btn.dataset.voidType, parseInt(btn.dataset.lineId, 10), btn.dataset.medicineName);
            });
        });

        voidConfirmCancel.addEventListener('click', closeVoidModal);
        voidModal.addEventListener('click', function(e) {
            if (e.target === voidModal) {
                closeVoidModal();
            }
        });

        voidConfirmProceed.addEventListener('click', function() {
            if (!voidTarget) {
                return;
            }
            voidConfirmError.hidden = true;
            voidConfirmProceed.disabled = true;
            voidConfirmProceed.textContent = 'Voiding…';

            // No reason field -- this modal only ever fires for a line
            // that isn't in the medicine catalog (rx-void-btn only
            // renders for those lines to begin with), and the modal
            // copy above already states that. A typed reason would just
            // restate what the system already knows.
            const bodyFields = voidTarget.type === 'confinement' ? {
                action: 'void_confinement_medication_line',
                csrf_token: csrfToken,
                confinement_id: confinementId,
                reference_number: referenceNumber,
                discharge_medication_id: voidTarget.lineId,
            } : {
                action: 'void_prescription_line',
                csrf_token: csrfToken,
                appointment_id: appointmentId,
                reference_number: referenceNumber,
                prescription_id: voidTarget.lineId,
            };
            const body = new URLSearchParams(bodyFields);

            fetch('dispense-actions.php', {
                    method: 'POST',
                    body: body,
                })
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        voidConfirmProceed.disabled = false;
                        voidConfirmProceed.textContent = 'Void This Line';
                        voidConfirmError.textContent = data.error || 'Something went wrong. Please try again.';
                        voidConfirmError.hidden = false;
                        return;
                    }
                    sessionStorage.setItem('gabaymed_dispense_toast', `Voided ${data.medicine_name}`);
                    window.location.reload();
                })
                .catch(() => {
                    voidConfirmProceed.disabled = false;
                    voidConfirmProceed.textContent = 'Void This Line';
                    voidConfirmError.textContent = 'Could not reach the server. Please check your connection and try again.';
                    voidConfirmError.hidden = false;
                });
        });

        // Shared wiring for both card types: a prescription card
        // (.prescription-dispense-card, keyed by prescription_id lines)
        // and a confinement discharge-medications card
        // (.confinement-dispense-card, keyed by discharge_medication_id
        // lines). Only the POST body's shape differs between the two.
        document.querySelectorAll('.prescription-dispense-card, .confinement-dispense-card').forEach(function(card) {
            const isConfinement = card.classList.contains('confinement-dispense-card');
            const completeBtn = card.querySelector('.rx-complete-btn');
            const verifyCheckbox = card.querySelector('.rx-verify-checkbox');
            const errorEl = card.querySelector('.rx-dispense-error');

            // Card has nothing dispensable (every line blocked) — the
            // Complete Dispense button/checkbox aren't rendered at all in
            // that case, so there's nothing to wire up.
            if (!completeBtn || !verifyCheckbox) {
                return;
            }

            completeBtn.addEventListener('click', function() {
                errorEl.hidden = true;

                if (!verifyCheckbox.checked) {
                    errorEl.textContent = isConfinement ?
                        'Confirm this matches the printed discharge summary before dispensing.' :
                        'Confirm this matches the printed prescription before dispensing.';
                    errorEl.hidden = false;
                    return;
                }

                const items = [];
                let invalid = false;
                card.querySelectorAll('.rx-pieces-input').forEach(function(input) {
                    const pieces = parseInt(input.value, 10);
                    if (isNaN(pieces) || pieces <= 0) {
                        invalid = true;
                        return;
                    }
                    if (isConfinement) {
                        items.push({
                            discharge_medication_id: parseInt(input.dataset.dischargeMedicationId, 10),
                            medicine_id: parseInt(input.dataset.medicineId, 10),
                            pieces: pieces,
                        });
                    } else {
                        items.push({
                            prescription_id: parseInt(input.dataset.prescriptionId, 10),
                            medicine_id: parseInt(input.dataset.medicineId, 10),
                            pieces: pieces,
                        });
                    }
                });

                if (invalid || items.length === 0) {
                    errorEl.textContent = 'Enter a valid piece count for every medicine before dispensing.';
                    errorEl.hidden = false;
                    return;
                }

                completeBtn.disabled = true;
                completeBtn.textContent = 'Dispensing…';

                const bodyFields = isConfinement ? {
                    action: 'dispense_confinement_medications',
                    csrf_token: csrfToken,
                    confinement_id: confinementId,
                    reference_number: referenceNumber,
                    items: JSON.stringify(items),
                } : {
                    action: 'dispense_prescription',
                    csrf_token: csrfToken,
                    appointment_id: appointmentId,
                    reference_number: referenceNumber,
                    consultation_id: parseInt(card.dataset.consultationId, 10),
                    items: JSON.stringify(items),
                };
                const body = new URLSearchParams(bodyFields);

                fetch('dispense-actions.php', {
                        method: 'POST',
                        body: body,
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (!data.success) {
                            completeBtn.disabled = false;
                            completeBtn.textContent = 'Complete Dispense';
                            errorEl.textContent = data.error || 'Something went wrong. Please try again.';
                            errorEl.hidden = false;
                            return;
                        }

                        showToast(`✓ Dispensed ${data.items.length} item${data.items.length === 1 ? '' : 's'}`);
                        card.remove();
                    })
                    .catch(() => {
                        completeBtn.disabled = false;
                        completeBtn.textContent = 'Complete Dispense';
                        errorEl.textContent = 'Could not reach the server. Please check your connection and try again.';
                        errorEl.hidden = false;
                    });
            });
        });
    </script>
</body>

</html>