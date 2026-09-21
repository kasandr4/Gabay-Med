<?php
// staff/dispense-actions.php
// JSON action endpoint backing "Complete Dispense" on dispense-stock.php.
// Mirrors delivery-actions.php's conventions: single action-routed JSON
// endpoint, CSRF-checked, every write through prepared statements, wrapped
// in a transaction so every item in a dispense succeeds together or none
// of them apply — a partial dispense would leave current_stock and
// medicine_exit_log disagreeing with what actually left the shelf.
//
// REWRITTEN (2026-09-05): the old dispense_stock action took an
// arbitrary (medicine_id, pieces) cart with no link to any patient or
// prescription — see the git history if that's ever needed again. Per
// instructor requirement, dispensing is now driven by
// dispense_prescription: it takes a consultation_id (one prescribing
// event) plus the reference number printed on that visit's handout, and
// only ever dispenses the prescription rows that already exist and are
// still 'pending' for that consultation. Nothing here is a free pick —
// every write traces back to a specific prescription_id.
//
// SERVER-SIDE RE-VERIFICATION: the client (dispense-stock.php) already
// looked up the visit by a signed reference number before showing this
// form, but this endpoint re-derives and checks that signature again
// itself (see includes/reference_number.php's verify_appointment_reference)
// rather than trusting the client's word for which appointment this is —
// same "never trust the hidden form fields alone" principle used by
// staff/check-in-process.php. It also re-fetches the consultation's
// pending prescriptions itself and requires the submitted items to be
// exactly that set (same prescription_ids, no more, no fewer) — so a
// tampered or stale request can't dispense something that isn't actually
// part of this prescription, and a prescription that's already been
// partially handled by someone else fails cleanly instead of silently
// double-dispensing.
//
// UNIT (2026-07-26): dispensing is piece/unit-level, not box-level.
// Delivery Receiving stays box-level (that's how suppliers ship), but
// dispensing to a patient is naturally per-piece. Both still hit the
// same unit-denominated inventory_medicines.current_stock column.
// medicine_exit_log's boxes_scanned column holds the piece count
// directly, so units_deducted === boxes_scanned for every row this
// endpoint writes. barcode stays NULL (no scanner involved).
//
// FEFO (2026-07-27): dispensing draws from medicine_batches, soonest-
// expiry batch first, spilling into the next batch if one runs out
// mid-dispense — current_stock and batch rows are updated together in
// the same transaction so they can't drift apart. A batch with no known
// expiry_date sorts LAST. If a medicine's batch units_remaining somehow
// can't cover what current_stock says is available, this fails loudly
// with 'batch_shortfall' rather than silently dispensing without a batch
// record — see the catch block below.
//
// CRITICAL STOCK NOTIFICATION (2026-07-27): after a successful dispense,
// checks whether any dispensed item just crossed its reorder point (see
// includes/reorder_point.php) and, if so, notifies every admin once via
// the shared notifications system, only on the dispense that newly
// pushes it under the line.

require_once '../includes/auth_guard.php';
require_role('staff');
require_staff_type('inventory');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/notifications.php';
require_once '../includes/reorder_point.php';
require_once '../includes/audit_log.php';
require_once '../includes/reference_number.php';

header('Content-Type: application/json');

function respond($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Invalid request method.'], 405);
}

$submittedToken = $_POST['csrf_token'] ?? '';
$expectedToken = $_SESSION['csrf_token'] ?? '';
if ($expectedToken === '' || !hash_equals($expectedToken, $submittedToken)) {
    respond(['success' => false, 'error' => 'Your session expired. Please refresh the page and try again.'], 403);
}

$action = $_POST['action'] ?? '';
$staffId = (int) $_SESSION['user_id'];
// all_admin_ids() used below (batch-shortfall alert, reorder-point
// crossing check) now lives in includes/notifications.php — shared with
// admin/inventory-actions.php's new-request notification, so both
// "notify every admin" triggers stay in sync.

switch ($action) {

    // ------------------------------------------------------------
    // Complete Dispense — dispenses every pending prescription line for
    // one consultation (one prescribing event) together, in a single
    // transaction. Re-verifies the reference number and re-fetches the
    // authoritative pending-prescription set server-side rather than
    // trusting the client's item list — see header comment.
    // ------------------------------------------------------------
    case 'dispense_prescription': {
            $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
            $referenceNumber = trim($_POST['reference_number'] ?? '');
            $consultationId = (int) ($_POST['consultation_id'] ?? 0);
            $itemsRaw = $_POST['items'] ?? '';
            $items = json_decode($itemsRaw, true);

            if ($appointmentId <= 0 || $referenceNumber === '' || $consultationId <= 0) {
                respond(['success' => false, 'error' => 'Missing visit information. Please look it up again.'], 422);
            }
            if (!is_array($items) || count($items) === 0) {
                respond(['success' => false, 'error' => 'Enter a piece count for at least one medicine before dispensing.'], 422);
            }
            if (count($items) > 50) {
                respond(['success' => false, 'error' => 'Too many items in one dispense.'], 422);
            }

            // Re-derive the reference number from the appointment's own
            // data and compare — never trust the hidden fields alone
            // (same pattern as staff/check-in-process.php).
            $stmt = $conn->prepare("SELECT appointment_id, created_at FROM appointments WHERE appointment_id = ? LIMIT 1");
            $stmt->bind_param("i", $appointmentId);
            $stmt->execute();
            $appointment = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$appointment || !verify_appointment_reference($referenceNumber, $appointment['appointment_id'], $appointment['created_at'])) {
                respond(['success' => false, 'error' => 'That visit could not be verified. Please look it up again.'], 403);
            }

            // Consultation must actually belong to this appointment.
            $stmt = $conn->prepare("SELECT consultation_id FROM consultations WHERE consultation_id = ? AND appointment_id = ? LIMIT 1");
            $stmt->bind_param("ii", $consultationId, $appointmentId);
            $stmt->execute();
            $consultationMatch = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$consultationMatch) {
                respond(['success' => false, 'error' => 'That prescription could not be found for this visit.'], 404);
            }

            // The authoritative set of what can be dispensed right now —
            // pulled fresh from the database, not from the client. Any
            // prescription not linked to a real catalog item can't be
            // dispensed through this screen at all (see
            // doctor/consultation-process.php, which is the only place
            // medicine_id gets set).
            $stmt = $conn->prepare(
                "SELECT prescription_id, medicine_id, medicine_name, patient_id
                 FROM prescriptions
                 WHERE consultation_id = ? AND status = 'pending'"
            );
            $stmt->bind_param("i", $consultationId);
            $stmt->execute();
            $pendingRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (empty($pendingRows)) {
                respond(['success' => false, 'error' => 'This prescription has already been dispensed or no longer exists.'], 409);
            }

            $pendingByRxId = [];
            foreach ($pendingRows as $row) {
                $pendingByRxId[(int) $row['prescription_id']] = $row;
            }

            foreach ($pendingByRxId as $rxId => $row) {
                if ($row['medicine_id'] === null) {
                    respond(['success' => false, 'error' => "{$row['medicine_name']} isn't linked to the pharmacy catalog and can't be dispensed here. Ask the pharmacist to reconcile it."], 422);
                }
            }

            // Every submitted item must match a pending prescription_id
            // for this exact consultation, one piece count each, and the
            // set must be complete — dispensing is all-or-nothing per
            // prescription, so a partial submission (e.g. the page went
            // stale after someone else already handled one line) is
            // rejected rather than silently dispensing only some of it.
            $piecesByRxId = [];
            foreach ($items as $item) {
                $rxId = (int) ($item['prescription_id'] ?? 0);
                $pieces = (int) ($item['pieces'] ?? 0);
                if ($rxId <= 0 || $pieces <= 0) {
                    respond(['success' => false, 'error' => 'Every medicine needs a valid piece count.'], 422);
                }
                if (!isset($pendingByRxId[$rxId])) {
                    respond(['success' => false, 'error' => 'This prescription has changed since it was loaded. Please refresh and try again.'], 409);
                }
                $piecesByRxId[$rxId] = $pieces;
            }
            if (count($piecesByRxId) !== count($pendingByRxId)) {
                respond(['success' => false, 'error' => 'This prescription has changed since it was loaded. Please refresh and try again.'], 409);
            }

            // Look up current catalog info for every medicine involved,
            // for the stock check and for the response/notification text.
            $medicineIds = array_unique(array_map(fn($row) => (int) $row['medicine_id'], $pendingByRxId));
            $placeholders = implode(',', array_fill(0, count($medicineIds), '?'));
            $types = str_repeat('i', count($medicineIds));

            $stmt = $conn->prepare(
                "SELECT medicine_id, name, unit, current_stock FROM inventory_medicines WHERE medicine_id IN ($placeholders)"
            );
            $stmt->bind_param($types, ...$medicineIds);
            $stmt->execute();
            $medicines = [];
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $medicines[(int) $row['medicine_id']] = $row;
            }
            $stmt->close();

            foreach ($pendingByRxId as $rxId => $rx) {
                $medicineId = (int) $rx['medicine_id'];
                $pieces = $piecesByRxId[$rxId];
                if (!isset($medicines[$medicineId])) {
                    respond(['success' => false, 'error' => 'One of the prescribed medicines no longer exists in the catalog.'], 404);
                }
                $stock = (int) $medicines[$medicineId]['current_stock'];
                if ($pieces > $stock) {
                    $name = $medicines[$medicineId]['name'];
                    $unit = $medicines[$medicineId]['unit'];
                    respond(['success' => false, 'error' => "Only {$stock} {$unit}(s) of {$name} in stock \u2014 this exceeds what's available."], 409);
                }
            }

            $conn->begin_transaction();
            try {
                $dispensed = [];
                foreach ($pendingByRxId as $rxId => $rx) {
                    $medicineId = (int) $rx['medicine_id'];
                    $pieces = $piecesByRxId[$rxId];

                    // Guarded on current_stock >= pieces so a double-click
                    // (or a second staff member dispensing the same
                    // medicine at the same time) can't drive stock
                    // negative. This is the aggregate check; the batch-
                    // level FEFO consumption below is the detailed one.
                    $upd = $conn->prepare(
                        "UPDATE inventory_medicines
                         SET current_stock = current_stock - ?
                         WHERE medicine_id = ? AND current_stock >= ?"
                    );
                    $upd->bind_param("iii", $pieces, $medicineId, $pieces);
                    $upd->execute();
                    $applied = $upd->affected_rows > 0;
                    $upd->close();

                    if (!$applied) {
                        throw new Exception('stock_changed');
                    }

                    // FEFO: soonest-expiry batch first, NULL-expiry
                    // batches last (see header comment). FOR UPDATE locks
                    // these rows for the rest of this transaction, so a
                    // second concurrent dispense of the same medicine
                    // can't read the same "available" units twice.
                    $batchStmt = $conn->prepare(
                        "SELECT batch_id, batch_no, units_remaining, expiry_date
                         FROM medicine_batches
                         WHERE medicine_id = ? AND units_remaining > 0
                         ORDER BY (expiry_date IS NULL) ASC, expiry_date ASC, batch_id ASC
                         FOR UPDATE"
                    );
                    $batchStmt->bind_param("i", $medicineId);
                    $batchStmt->execute();
                    $batches = $batchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $batchStmt->close();

                    $remaining = $pieces;
                    $batchesUsed = [];
                    foreach ($batches as $batch) {
                        if ($remaining <= 0) {
                            break;
                        }
                        $take = min($remaining, (int) $batch['units_remaining']);
                        $newRemaining = (int) $batch['units_remaining'] - $take;

                        $bUpd = $conn->prepare("UPDATE medicine_batches SET units_remaining = ? WHERE batch_id = ?");
                        $bUpd->bind_param("ii", $newRemaining, $batch['batch_id']);
                        $bUpd->execute();
                        $bUpd->close();

                        $batchesUsed[] = [
                            'batch_id'    => (int) $batch['batch_id'],
                            'batch_no'    => $batch['batch_no'],
                            'expiry_date' => $batch['expiry_date'],
                            'pieces'      => $take,
                        ];
                        $remaining -= $take;
                    }

                    if ($remaining > 0) {
                        // current_stock said enough was available, but
                        // batch records don't cover it — a real data
                        // inconsistency (most likely: 005_backfill_legacy_
                        // batches.sql hasn't been run yet for this
                        // medicine). Fail loudly instead of dispensing
                        // pieces with no batch backing them, which would
                        // silently break FEFO/expiry tracking going
                        // forward.
                        throw new Exception('batch_shortfall:' . $medicineId);
                    }

                    // Record one exit-log row per FEFO batch allocation,
                    // tagged with the prescription it came from, so the
                    // dispense log and any report can trace every
                    // deduction back to the doctor's order.
                    foreach ($batchesUsed as $batchUsed) {
                        $batchId = (int) $batchUsed['batch_id'];
                        $batchPieces = (int) $batchUsed['pieces'];
                        $ins = $conn->prepare(
                            "INSERT INTO medicine_exit_log (medicine_id, batch_id, prescription_id, boxes_scanned, units_deducted, staff_id, barcode, scanned_at)
                             VALUES (?, ?, ?, ?, ?, ?, NULL, NOW())"
                        );
                        $ins->bind_param("iiiiii", $medicineId, $batchId, $rxId, $batchPieces, $batchPieces, $staffId);
                        $ins->execute();
                        $ins->close();
                    }

                    // The prescription line is now filled — flips its
                    // status so it won't show up as pending again, and
                    // patient/prescriptions.php can reflect it too.
                    $markStmt = $conn->prepare("UPDATE prescriptions SET status = 'dispensed' WHERE prescription_id = ?");
                    $markStmt->bind_param("i", $rxId);
                    $markStmt->execute();
                    $markStmt->close();

                    $dispensed[] = [
                        'prescription_id' => $rxId,
                        'medicine_id'   => $medicineId,
                        'medicine_name' => $medicines[$medicineId]['name'],
                        'unit'          => $medicines[$medicineId]['unit'],
                        'pieces'        => $pieces,
                        'new_stock'     => (int) $medicines[$medicineId]['current_stock'] - $pieces,
                        'batches'       => $batchesUsed,
                    ];
                }

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                $msg = $e->getMessage();
                if ($msg === 'stock_changed') {
                    respond(['success' => false, 'error' => 'Stock changed before this could be applied \u2014 please re-check the current counts and try again.'], 409);
                }
                if (str_starts_with($msg, 'batch_shortfall:')) {
                    $shortfallId = (int) substr($msg, strlen('batch_shortfall:'));
                    $shortfallName = $medicines[$shortfallId]['name'] ?? 'this medicine';
                    foreach (all_admin_ids($conn) as $adminId) {
                        create_notification(
                            $conn,
                            $adminId,
                            "Dispense blocked: {$shortfallName}'s batch records don't cover its current stock. Run 005_backfill_legacy_batches.sql or check Reconciliation.",
                            'inventory-procurement.php',
                            'critical'
                        );
                    }
                    respond(['success' => false, 'error' => "{$shortfallName}'s batch records don't cover its current stock \u2014 run 005_backfill_legacy_batches.sql or check Reconciliation before dispensing."], 409);
                }
                respond(['success' => false, 'error' => 'Could not record this dispense. Please try again.'], 500);
            }

            // Notify admins on the reorder-point CROSSING only (not every
            // dispense of an already-low item) — see header comment above.
            $adminIds = all_admin_ids($conn);

            foreach ($dispensed as $item) {
                $beforeStock = (int) $medicines[$item['medicine_id']]['current_stock'];
                $afterStock = $item['new_stock'];
                $reorder = compute_reorder_point_for_medicine($conn, $item['medicine_id']);
                $reorderPoint = $reorder['reorder_point'];

                if ($beforeStock > $reorderPoint && $afterStock <= $reorderPoint) {
                    $message = "Critical stock: {$item['medicine_name']} is down to {$afterStock} {$item['unit']}(s) (reorder point: {$reorderPoint}).";
                    if (strlen($message) > 255) {
                        $message = substr($message, 0, 252) . '...';
                    }
                    foreach ($adminIds as $adminId) {
                        create_notification($conn, $adminId, $message, 'inventory-procurement.php', 'warning');
                    }
                }
            }

            $dispenseDetails = array_map(static function (array $item): string {
                return $item['medicine_name'] . ': ' . $item['pieces'] . ' ' . $item['unit'];
            }, $dispensed);
            write_audit_log($conn, $staffId, 'staff', 'prescription_dispensed', 'dispensing', "Consultation #{$consultationId}: " . implode('; ', $dispenseDetails), (int) $pendingRows[0]['patient_id']);

            $staffStmt = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) AS name FROM users WHERE user_id = ?");
            $staffStmt->bind_param("i", $staffId);
            $staffStmt->execute();
            $staffName = $staffStmt->get_result()->fetch_assoc()['name'] ?? 'Unknown';
            $staffStmt->close();

            respond([
                'success'    => true,
                'message'    => 'Prescription dispensed.',
                'items'      => $dispensed,
                'staff_name' => $staffName,
                'time'       => date('M j, g:i A'),
            ]);
            break;
        }

        // ------------------------------------------------------------
        // Mark as Void (prescription line) — 2026-09-07. For a prescription
        // line that can't be dispensed here because it was never linked to
        // the pharmacy catalog, or was linked but the catalog row has
        // since been deleted (fk_prescriptions_medicine is ON DELETE SET
        // NULL, so both cases look identical: medicine_id IS NULL). Voiding
        // takes that one line out of the 'pending' set for its
        // consultation, which is also the all-or-nothing set
        // dispense_prescription re-fetches above — so a blocked line no
        // longer holds up its consultation's other, dispensable lines.
        // Deliberately does NOT accept a medicine_id/stock-based block
        // (e.g. "out of stock") — that's a catalog item that just needs
        // restocking, not a gap to write off, so it stays voidable only by
        // an actual pharmacist workflow, not this screen.
        // ------------------------------------------------------------
    case 'void_prescription_line': {
            $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
            $referenceNumber = trim($_POST['reference_number'] ?? '');
            $prescriptionId = (int) ($_POST['prescription_id'] ?? 0);

            if ($appointmentId <= 0 || $referenceNumber === '' || $prescriptionId <= 0) {
                respond(['success' => false, 'error' => 'Missing visit information. Please look it up again.'], 422);
            }

            // Re-derive the reference number, same as dispense_prescription —
            // never trust the hidden fields alone.
            $stmt = $conn->prepare("SELECT appointment_id, created_at FROM appointments WHERE appointment_id = ? LIMIT 1");
            $stmt->bind_param("i", $appointmentId);
            $stmt->execute();
            $appointment = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$appointment || !verify_appointment_reference($referenceNumber, $appointment['appointment_id'], $appointment['created_at'])) {
                respond(['success' => false, 'error' => 'That visit could not be verified. Please look it up again.'], 403);
            }

            // Must be a pending line that actually belongs to this
            // appointment's own consultations — not just any prescription_id.
            $stmt = $conn->prepare(
                "SELECT p.prescription_id, p.medicine_id, p.medicine_name, p.patient_id
                 FROM prescriptions p
                 JOIN consultations c ON c.consultation_id = p.consultation_id
                 WHERE p.prescription_id = ? AND c.appointment_id = ? AND p.status = 'pending'
                 LIMIT 1"
            );
            $stmt->bind_param("ii", $prescriptionId, $appointmentId);
            $stmt->execute();
            $line = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$line) {
                respond(['success' => false, 'error' => 'This line could not be found, or has already been dispensed or voided. Please refresh and try again.'], 404);
            }
            if ($line['medicine_id'] !== null) {
                respond(['success' => false, 'error' => 'Only medicines missing from the pharmacy catalog can be voided from this screen.'], 422);
            }

            $upd = $conn->prepare("UPDATE prescriptions SET status = 'void' WHERE prescription_id = ? AND status = 'pending'");
            $upd->bind_param("i", $prescriptionId);
            $upd->execute();
            $applied = $upd->affected_rows > 0;
            $upd->close();

            if (!$applied) {
                respond(['success' => false, 'error' => 'This line was already handled by someone else. Please refresh and try again.'], 409);
            }

            $medicineName = $line['medicine_name'];
            write_audit_log(
                $conn,
                $staffId,
                'staff',
                'prescription_line_voided',
                'dispensing',
                "Prescription #{$prescriptionId} ({$medicineName}) voided \u2014 not in pharmacy catalog.",
                (int) $line['patient_id']
            );

            // Let the pharmacist team know — a genuinely missing catalog
            // entry (vs. legacy free-text data) is something they may want
            // to add going forward.
            $notifyMessage = "Prescription line voided: {$medicineName} isn't in the pharmacy catalog.";
            if (strlen($notifyMessage) > 255) {
                $notifyMessage = substr($notifyMessage, 0, 252) . '...';
            }
            foreach (all_pharmacist_ids($conn) as $pharmacistId) {
                create_notification($conn, $pharmacistId, $notifyMessage, 'medicine-catalog.php', 'warning');
            }

            respond([
                'success'       => true,
                'medicine_name' => $medicineName,
            ]);
            break;
        }

        // ------------------------------------------------------------
        // Complete Dispense (confinement discharge) — same shape and same
        // FEFO/verification logic as dispense_prescription above, just
        // pointed at confinement_discharge_medications instead of
        // prescriptions, and verified against a confinement reference number
        // instead of an appointment one. Kept as its own case rather than
        // sharing a helper with dispense_prescription, so that already-tested
        // logic above isn't touched by this change — see that case's comments
        // for the reasoning behind each step, which all applies here too.
        // ------------------------------------------------------------
    case 'dispense_confinement_medications': {
            $confinementId = (int) ($_POST['confinement_id'] ?? 0);
            $referenceNumber = trim($_POST['reference_number'] ?? '');
            $itemsRaw = $_POST['items'] ?? '';
            $items = json_decode($itemsRaw, true);

            if ($confinementId <= 0 || $referenceNumber === '') {
                respond(['success' => false, 'error' => 'Missing confinement information. Please look it up again.'], 422);
            }
            if (!is_array($items) || count($items) === 0) {
                respond(['success' => false, 'error' => 'Enter a piece count for at least one medicine before dispensing.'], 422);
            }
            if (count($items) > 50) {
                respond(['success' => false, 'error' => 'Too many items in one dispense.'], 422);
            }

            $stmt = $conn->prepare("SELECT confinement_id, created_at, patient_id FROM confinements WHERE confinement_id = ? LIMIT 1");
            $stmt->bind_param("i", $confinementId);
            $stmt->execute();
            $confinement = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$confinement || !verify_confinement_reference($referenceNumber, $confinement['confinement_id'], $confinement['created_at'])) {
                respond(['success' => false, 'error' => 'That confinement record could not be verified. Please look it up again.'], 403);
            }

            $stmt = $conn->prepare(
                "SELECT discharge_medication_id, medicine_id, medicine_name
                 FROM confinement_discharge_medications
                 WHERE confinement_id = ? AND status = 'pending'"
            );
            $stmt->bind_param("i", $confinementId);
            $stmt->execute();
            $pendingRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (empty($pendingRows)) {
                respond(['success' => false, 'error' => 'These discharge medications have already been dispensed or no longer exist.'], 409);
            }

            $pendingByMedId = [];
            foreach ($pendingRows as $row) {
                $pendingByMedId[(int) $row['discharge_medication_id']] = $row;
            }

            foreach ($pendingByMedId as $dmId => $row) {
                if ($row['medicine_id'] === null) {
                    respond(['success' => false, 'error' => "{$row['medicine_name']} isn't linked to the pharmacy catalog and can't be dispensed here. Ask the pharmacist to reconcile it."], 422);
                }
            }

            $piecesByMedId = [];
            foreach ($items as $item) {
                $dmId = (int) ($item['discharge_medication_id'] ?? 0);
                $pieces = (int) ($item['pieces'] ?? 0);
                if ($dmId <= 0 || $pieces <= 0) {
                    respond(['success' => false, 'error' => 'Every medicine needs a valid piece count.'], 422);
                }
                if (!isset($pendingByMedId[$dmId])) {
                    respond(['success' => false, 'error' => 'This medication list has changed since it was loaded. Please refresh and try again.'], 409);
                }
                $piecesByMedId[$dmId] = $pieces;
            }
            if (count($piecesByMedId) !== count($pendingByMedId)) {
                respond(['success' => false, 'error' => 'This medication list has changed since it was loaded. Please refresh and try again.'], 409);
            }

            $medicineIds = array_unique(array_map(fn($row) => (int) $row['medicine_id'], $pendingByMedId));
            $placeholders = implode(',', array_fill(0, count($medicineIds), '?'));
            $types = str_repeat('i', count($medicineIds));

            $stmt = $conn->prepare(
                "SELECT medicine_id, name, unit, current_stock FROM inventory_medicines WHERE medicine_id IN ($placeholders)"
            );
            $stmt->bind_param($types, ...$medicineIds);
            $stmt->execute();
            $medicines = [];
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $medicines[(int) $row['medicine_id']] = $row;
            }
            $stmt->close();

            foreach ($pendingByMedId as $dmId => $rx) {
                $medicineId = (int) $rx['medicine_id'];
                $pieces = $piecesByMedId[$dmId];
                if (!isset($medicines[$medicineId])) {
                    respond(['success' => false, 'error' => 'One of the listed medicines no longer exists in the catalog.'], 404);
                }
                $stock = (int) $medicines[$medicineId]['current_stock'];
                if ($pieces > $stock) {
                    $name = $medicines[$medicineId]['name'];
                    $unit = $medicines[$medicineId]['unit'];
                    respond(['success' => false, 'error' => "Only {$stock} {$unit}(s) of {$name} in stock \u2014 this exceeds what's available."], 409);
                }
            }

            $conn->begin_transaction();
            try {
                $dispensed = [];
                foreach ($pendingByMedId as $dmId => $rx) {
                    $medicineId = (int) $rx['medicine_id'];
                    $pieces = $piecesByMedId[$dmId];

                    $upd = $conn->prepare(
                        "UPDATE inventory_medicines
                         SET current_stock = current_stock - ?
                         WHERE medicine_id = ? AND current_stock >= ?"
                    );
                    $upd->bind_param("iii", $pieces, $medicineId, $pieces);
                    $upd->execute();
                    $applied = $upd->affected_rows > 0;
                    $upd->close();

                    if (!$applied) {
                        throw new Exception('stock_changed');
                    }

                    $batchStmt = $conn->prepare(
                        "SELECT batch_id, batch_no, units_remaining, expiry_date
                         FROM medicine_batches
                         WHERE medicine_id = ? AND units_remaining > 0
                         ORDER BY (expiry_date IS NULL) ASC, expiry_date ASC, batch_id ASC
                         FOR UPDATE"
                    );
                    $batchStmt->bind_param("i", $medicineId);
                    $batchStmt->execute();
                    $batches = $batchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $batchStmt->close();

                    $remaining = $pieces;
                    $batchesUsed = [];
                    foreach ($batches as $batch) {
                        if ($remaining <= 0) {
                            break;
                        }
                        $take = min($remaining, (int) $batch['units_remaining']);
                        $newRemaining = (int) $batch['units_remaining'] - $take;

                        $bUpd = $conn->prepare("UPDATE medicine_batches SET units_remaining = ? WHERE batch_id = ?");
                        $bUpd->bind_param("ii", $newRemaining, $batch['batch_id']);
                        $bUpd->execute();
                        $bUpd->close();

                        $batchesUsed[] = [
                            'batch_id'    => (int) $batch['batch_id'],
                            'batch_no'    => $batch['batch_no'],
                            'expiry_date' => $batch['expiry_date'],
                            'pieces'      => $take,
                        ];
                        $remaining -= $take;
                    }

                    if ($remaining > 0) {
                        throw new Exception('batch_shortfall:' . $medicineId);
                    }

                    foreach ($batchesUsed as $batchUsed) {
                        $batchId = (int) $batchUsed['batch_id'];
                        $batchPieces = (int) $batchUsed['pieces'];
                        $ins = $conn->prepare(
                            "INSERT INTO medicine_exit_log (medicine_id, batch_id, confinement_discharge_medication_id, boxes_scanned, units_deducted, staff_id, barcode, scanned_at)
                             VALUES (?, ?, ?, ?, ?, ?, NULL, NOW())"
                        );
                        $ins->bind_param("iiiiii", $medicineId, $batchId, $dmId, $batchPieces, $batchPieces, $staffId);
                        $ins->execute();
                        $ins->close();
                    }

                    $markStmt = $conn->prepare("UPDATE confinement_discharge_medications SET status = 'dispensed' WHERE discharge_medication_id = ?");
                    $markStmt->bind_param("i", $dmId);
                    $markStmt->execute();
                    $markStmt->close();

                    $dispensed[] = [
                        'discharge_medication_id' => $dmId,
                        'medicine_id'   => $medicineId,
                        'medicine_name' => $medicines[$medicineId]['name'],
                        'unit'          => $medicines[$medicineId]['unit'],
                        'pieces'        => $pieces,
                        'new_stock'     => (int) $medicines[$medicineId]['current_stock'] - $pieces,
                        'batches'       => $batchesUsed,
                    ];
                }

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                $msg = $e->getMessage();
                if ($msg === 'stock_changed') {
                    respond(['success' => false, 'error' => 'Stock changed before this could be applied \u2014 please re-check the current counts and try again.'], 409);
                }
                if (str_starts_with($msg, 'batch_shortfall:')) {
                    $shortfallId = (int) substr($msg, strlen('batch_shortfall:'));
                    $shortfallName = $medicines[$shortfallId]['name'] ?? 'this medicine';
                    foreach (all_admin_ids($conn) as $adminId) {
                        create_notification(
                            $conn,
                            $adminId,
                            "Dispense blocked: {$shortfallName}'s batch records don't cover its current stock. Run 005_backfill_legacy_batches.sql or check Reconciliation.",
                            'inventory-procurement.php',
                            'critical'
                        );
                    }
                    respond(['success' => false, 'error' => "{$shortfallName}'s batch records don't cover its current stock \u2014 run 005_backfill_legacy_batches.sql or check Reconciliation before dispensing."], 409);
                }
                respond(['success' => false, 'error' => 'Could not record this dispense. Please try again.'], 500);
            }

            $adminIds = all_admin_ids($conn);
            foreach ($dispensed as $item) {
                $beforeStock = (int) $medicines[$item['medicine_id']]['current_stock'];
                $afterStock = $item['new_stock'];
                $reorder = compute_reorder_point_for_medicine($conn, $item['medicine_id']);
                $reorderPoint = $reorder['reorder_point'];

                if ($beforeStock > $reorderPoint && $afterStock <= $reorderPoint) {
                    $message = "Critical stock: {$item['medicine_name']} is down to {$afterStock} {$item['unit']}(s) (reorder point: {$reorderPoint}).";
                    if (strlen($message) > 255) {
                        $message = substr($message, 0, 252) . '...';
                    }
                    foreach ($adminIds as $adminId) {
                        create_notification($conn, $adminId, $message, 'inventory-procurement.php', 'warning');
                    }
                }
            }

            $dispenseDetails = array_map(static function (array $item): string {
                return $item['medicine_name'] . ': ' . $item['pieces'] . ' ' . $item['unit'];
            }, $dispensed);
            write_audit_log($conn, $staffId, 'staff', 'confinement_medication_dispensed', 'dispensing', "Confinement #{$confinementId}: " . implode('; ', $dispenseDetails), (int) $confinement['patient_id']);

            $staffStmt = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) AS name FROM users WHERE user_id = ?");
            $staffStmt->bind_param("i", $staffId);
            $staffStmt->execute();
            $staffName = $staffStmt->get_result()->fetch_assoc()['name'] ?? 'Unknown';
            $staffStmt->close();

            respond([
                'success'    => true,
                'message'    => 'Discharge medications dispensed.',
                'items'      => $dispensed,
                'staff_name' => $staffName,
                'time'       => date('M j, g:i A'),
            ]);
            break;
        }

        // ------------------------------------------------------------
        // Mark as Void (confinement discharge medication line) — mirrors
        // void_prescription_line above, just pointed at
        // confinement_discharge_medications and verified against a
        // confinement reference number instead of an appointment one. See
        // that case's comments for the reasoning.
        // ------------------------------------------------------------
    case 'void_confinement_medication_line': {
            $confinementId = (int) ($_POST['confinement_id'] ?? 0);
            $referenceNumber = trim($_POST['reference_number'] ?? '');
            $dischargeMedicationId = (int) ($_POST['discharge_medication_id'] ?? 0);

            if ($confinementId <= 0 || $referenceNumber === '' || $dischargeMedicationId <= 0) {
                respond(['success' => false, 'error' => 'Missing confinement information. Please look it up again.'], 422);
            }

            $stmt = $conn->prepare("SELECT confinement_id, created_at, patient_id FROM confinements WHERE confinement_id = ? LIMIT 1");
            $stmt->bind_param("i", $confinementId);
            $stmt->execute();
            $confinement = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$confinement || !verify_confinement_reference($referenceNumber, $confinement['confinement_id'], $confinement['created_at'])) {
                respond(['success' => false, 'error' => 'That confinement could not be verified. Please look it up again.'], 403);
            }

            $stmt = $conn->prepare(
                "SELECT discharge_medication_id, medicine_id, medicine_name
                 FROM confinement_discharge_medications
                 WHERE discharge_medication_id = ? AND confinement_id = ? AND status = 'pending'
                 LIMIT 1"
            );
            $stmt->bind_param("ii", $dischargeMedicationId, $confinementId);
            $stmt->execute();
            $line = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$line) {
                respond(['success' => false, 'error' => 'This line could not be found, or has already been dispensed or voided. Please refresh and try again.'], 404);
            }
            if ($line['medicine_id'] !== null) {
                respond(['success' => false, 'error' => 'Only medicines missing from the pharmacy catalog can be voided from this screen.'], 422);
            }

            $upd = $conn->prepare("UPDATE confinement_discharge_medications SET status = 'void' WHERE discharge_medication_id = ? AND status = 'pending'");
            $upd->bind_param("i", $dischargeMedicationId);
            $upd->execute();
            $applied = $upd->affected_rows > 0;
            $upd->close();

            if (!$applied) {
                respond(['success' => false, 'error' => 'This line was already handled by someone else. Please refresh and try again.'], 409);
            }

            $medicineName = $line['medicine_name'];
            write_audit_log(
                $conn,
                $staffId,
                'staff',
                'prescription_line_voided',
                'dispensing',
                "Confinement #{$confinementId} discharge medication ({$medicineName}) voided \u2014 not in pharmacy catalog.",
                (int) $confinement['patient_id']
            );

            $notifyMessage = "Discharge medication line voided: {$medicineName} isn't in the pharmacy catalog.";
            if (strlen($notifyMessage) > 255) {
                $notifyMessage = substr($notifyMessage, 0, 252) . '...';
            }
            foreach (all_pharmacist_ids($conn) as $pharmacistId) {
                create_notification($conn, $pharmacistId, $notifyMessage, 'medicine-catalog.php', 'warning');
            }

            respond([
                'success'       => true,
                'medicine_name' => $medicineName,
            ]);
            break;
        }

    default:
        respond(['success' => false, 'error' => 'Unknown action.'], 400);
}
