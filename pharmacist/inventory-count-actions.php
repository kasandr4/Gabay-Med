<?php
// pharmacist/inventory-count-actions.php
// JSON action endpoint backing inventory-count.php's "Assign Count" and
// "Confirm & Finalize" actions. Mirrors the conventions already
// established across this app's other action endpoints (delivery-actions.php,
// dispense-actions.php, admin/inventory-actions.php): single
// action-routed JSON endpoint, CSRF-checked, prepared statements only.
//
// assign_batch snapshots inventory_medicines.current_stock into
// system_count AT THE MOMENT OF ASSIGNMENT for every medicine in the
// batch — that's the "hidden from staff" number staff/inventory-count.php
// never selects/echoes to the counting screen.
//
// confirm_batch is the merged Review & Recount + Final Confirmation step
// (see the scope note atop inventory-count.php for why these two OMCDH
// mockup screens are one action here). It writes each line's final_count
// (defaulting to staff_count if the pharmacist didn't type a correction)
// and remarks, marks the batch confirmed, and rolls
// the count forward from the moment it was TAKEN (not the moment it's
// being confirmed) using medicine_batches deliveries and
// medicine_exit_log dispenses recorded since then, so a delivery or
// dispense that happened while the batch sat in "Awaiting Review" isn't
// silently wiped out by an overwrite.
//
// BATCH RECONCILIATION: the same confirmation also applies its result to
// medicine_batches, not just inventory_medicines.current_stock, so
// current_stock and SUM(units_remaining) stay equal (the rule dispense-
// actions.php and expiry-batch-actions.php already rely on). A shortage
// is taken out of batches soonest-expiry-first (the dispensing order);
// stock found beyond what the system expected is added as a new batch with
// source 'count_adjustment'. Every batch touched is saved on the report
// line (batch_changes) so the correction is traceable per batch.

require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/notifications.php';
require_once '../includes/audit_log.php';

header('Content-Type: application/json');

function respond($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

/**
 * Makes SUM(medicine_batches.units_remaining) for one medicine equal
 * $targetStock. Call inside the caller's open transaction.
 *
 * The difference is measured against the batches themselves, not against
 * current_stock, so the result is exact even if the two had already drifted.
 * Returns the batches touched: [batch_id, batch_no, expiry_date, units]
 * where units is negative for units removed and positive for units added.
 *
 * A count is taken per medicine, not per batch, so WHICH batch lost units is
 * an allocation rule (soonest expiry first, same as dispensing), not an
 * observed fact.
 */
function reconcileBatchesToStock($conn, $medicineId, $targetStock, $countNumber)
{
    $sel = $conn->prepare(
        "SELECT batch_id, batch_no, expiry_date, units_remaining
         FROM medicine_batches
         WHERE medicine_id = ? AND units_remaining > 0
         ORDER BY (expiry_date IS NULL) ASC, expiry_date ASC, batch_id ASC
         FOR UPDATE"
    );
    $sel->bind_param('i', $medicineId);
    $sel->execute();
    $batches = $sel->get_result()->fetch_all(MYSQLI_ASSOC);
    $sel->close();

    $batchTotal = 0;
    foreach ($batches as $b) {
        $batchTotal += (int) $b['units_remaining'];
    }

    $delta = $targetStock - $batchTotal;
    $changes = [];

    if ($delta < 0) {
        $toRemove = -$delta;
        foreach ($batches as $b) {
            if ($toRemove <= 0) {
                break;
            }
            $take = min($toRemove, (int) $b['units_remaining']);
            $upd = $conn->prepare("UPDATE medicine_batches SET units_remaining = units_remaining - ? WHERE batch_id = ?");
            $upd->bind_param('ii', $take, $b['batch_id']);
            $upd->execute();
            $upd->close();
            $changes[] = [
                'batch_id' => (int) $b['batch_id'],
                'batch_no' => $b['batch_no'],
                'expiry_date' => $b['expiry_date'],
                'units' => -$take,
            ];
            $toRemove -= $take;
        }
    } elseif ($delta > 0) {
        // created_by stays NULL on purpose: across this app that column marks
        // manually recorded stock (dashboards, Stock Received report, batch
        // history all filter on it), and a count correction is not a delivery.
        // Who confirmed it is on the saved report and in the audit log.
        $batchNo = 'COUNT-' . $countNumber;
        $note = 'Found stock recorded at Final Confirmation of ' . $countNumber;
        $ins = $conn->prepare(
            "INSERT INTO medicine_batches (medicine_id, source, donor_notes, batch_no, units_received, units_remaining, expiry_date, received_at, created_by)
             VALUES (?, 'count_adjustment', ?, ?, ?, ?, NULL, NOW(), NULL)"
        );
        $ins->bind_param('issii', $medicineId, $note, $batchNo, $delta, $delta);
        try {
            $ins->execute();
        } catch (mysqli_sql_exception $e) {
            // 1265 = "Data truncated": strict-mode MySQL rejecting an ENUM value
            // the column doesn't have yet (migration 019 not run).
            if ((int) $e->getCode() === 1265) {
                throw new Exception('count_adjustment_source_missing');
            }
            throw $e;
        }
        $newBatchId = (int) $ins->insert_id;
        $ins->close();

        // If the database hasn't had 019_add_count_adjustment_batch_source.sql
        // run yet, a non-strict MySQL setting stores an unknown ENUM value as
        // '' instead of erroring. Catch that here so the whole confirmation
        // rolls back rather than saving a batch with a blank source.
        $srcChk = $conn->prepare("SELECT source FROM medicine_batches WHERE batch_id = ?");
        $srcChk->bind_param('i', $newBatchId);
        $srcChk->execute();
        $storedSource = $srcChk->get_result()->fetch_assoc()['source'] ?? '';
        $srcChk->close();
        if ($storedSource !== 'count_adjustment') {
            throw new Exception('count_adjustment_source_missing');
        }
        $changes[] = [
            'batch_id' => $newBatchId,
            'batch_no' => $batchNo,
            'expiry_date' => null,
            'units' => $delta,
        ];
    }

    // Safety net: if the batches still don't add up to the target, abort so
    // the caller's transaction rolls back rather than saving a half-fix.
    $chk = $conn->prepare("SELECT COALESCE(SUM(units_remaining), 0) AS total FROM medicine_batches WHERE medicine_id = ?");
    $chk->bind_param('i', $medicineId);
    $chk->execute();
    $after = (int) $chk->get_result()->fetch_assoc()['total'];
    $chk->close();
    if ($after !== (int) $targetStock) {
        throw new Exception('Batch quantities could not be reconciled for medicine ' . $medicineId . '.');
    }

    return $changes;
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
$pharmacistId = (int) $_SESSION['user_id'];

switch ($action) {

    // ------------------------------------------------------------
    // Assign New Count
    // ------------------------------------------------------------
    case 'assign_batch': {
            $assignedTo = (int) ($_POST['assigned_to'] ?? 0);
            $assignmentMode = $_POST['assignment_mode'] ?? 'selection';
            $fromItem = filter_var($_POST['from_item'] ?? null, FILTER_VALIDATE_INT);
            $toItem = filter_var($_POST['to_item'] ?? null, FILTER_VALIDATE_INT);
            $assignedDate = trim($_POST['assigned_date'] ?? date('Y-m-d'));
            $dueDate = trim($_POST['due_date'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            $medicineIds = array_map('intval', $_POST['medicine_ids'] ?? []);
            $medicineIds = array_values(array_unique(array_filter($medicineIds, fn($id) => $id > 0)));

            $parsedAssignedDate = DateTime::createFromFormat('!Y-m-d', $assignedDate);
            if (!$parsedAssignedDate || $parsedAssignedDate->format('Y-m-d') !== $assignedDate) {
                respond(['success' => false, 'error' => 'Enter a valid assignment date.'], 422);
            }

            if ($assignmentMode === 'range') {
                if ($fromItem === false || $toItem === false || $fromItem < 1 || $toItem < $fromItem) {
                    respond(['success' => false, 'error' => 'Enter a valid item number range.'], 422);
                }

                $rangeStmt = $conn->prepare(
                    "SELECT im.medicine_id
                     FROM inventory_medicines im
                     WHERE im.medicine_id BETWEEN ? AND ?
                     ORDER BY im.medicine_id ASC"
                );
                $rangeStmt->bind_param("ii", $fromItem, $toItem);
                $rangeStmt->execute();
                $rangeRows = $rangeStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $rangeStmt->close();
                foreach ($rangeRows as $row) {
                    $medicineIds[] = (int) $row['medicine_id'];
                }
                $medicineIds = array_values(array_unique($medicineIds));
            } elseif ($assignmentMode !== 'selection') {
                respond(['success' => false, 'error' => 'Invalid assignment mode.'], 422);
            }

            if ($assignedTo <= 0 || empty($medicineIds)) {
                respond(['success' => false, 'error' => $assignmentMode === 'range' ? 'No medicines were found in that item number range.' : 'Select a staff member and at least one medicine.'], 422);
            }

            // Confirm assignedTo really is an active INVENTORY staff
            // account — a tampered/mistaken user_id landing on a
            // front-desk staff, patient, or doctor row would otherwise
            // pass a plain role='staff' check but make no sense (see
            // 013_staff_subtype.sql).
            $check = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'staff' AND staff_type = 'inventory' AND is_active = 1");
            $check->bind_param("i", $assignedTo);
            $check->execute();
            $validStaff = $check->get_result()->fetch_assoc();
            $check->close();
            if (!$validStaff) {
                respond(['success' => false, 'error' => 'Selected staff member is not valid.'], 422);
            }

            $conn->begin_transaction();
            try {
                $year = date('Y');
                $seed = $conn->prepare(
                    "INSERT INTO inventory_count_batches (batch_number, assigned_to, assigned_by, assigned_at, due_date, notes, status)
                     VALUES ('', ?, ?, ?, ?, ?, 'pending')"
                );
                $assignedAt = $assignedDate . ' 00:00:00';
                $dueDateParam = $dueDate !== '' ? $dueDate : null;
                $notesParam = $notes !== '' ? $notes : null;
                $seed->bind_param("iisss", $assignedTo, $pharmacistId, $assignedAt, $dueDateParam, $notesParam);
                $seed->execute();
                $batchId = $seed->insert_id;
                $seed->close();

                // IC-YYYY-NNNNNN, same sequential-per-year convention as
                // PO numbers in admin/inventory-actions.php.
                $batchNumber = sprintf('IC-%s-%06d', $year, $batchId);
                $upd = $conn->prepare("UPDATE inventory_count_batches SET batch_number = ? WHERE batch_id = ?");
                $upd->bind_param("si", $batchNumber, $batchId);
                $upd->execute();
                $upd->close();

                // Snapshot current_stock for every selected medicine NOW —
                // this is the hidden "correct" number staff never sees.
                $placeholders = implode(',', array_fill(0, count($medicineIds), '?'));
                $types = str_repeat('i', count($medicineIds));
                $stockStmt = $conn->prepare(
                    "SELECT im.medicine_id, im.current_stock
                     FROM inventory_medicines im
                     WHERE im.medicine_id IN ($placeholders)"
                );
                $stockStmt->bind_param($types, ...$medicineIds);
                $stockStmt->execute();
                $stockResult = $stockStmt->get_result();
                $stockByMedicine = [];
                while ($row = $stockResult->fetch_assoc()) {
                    $stockByMedicine[(int) $row['medicine_id']] = (int) $row['current_stock'];
                }
                $stockStmt->close();

                if (count($stockByMedicine) !== count($medicineIds)) {
                    throw new Exception('One or more selected medicines no longer exist.');
                }

                $itemStmt = $conn->prepare(
                    "INSERT INTO inventory_count_items (batch_id, medicine_id, system_count) VALUES (?, ?, ?)"
                );
                foreach ($medicineIds as $medId) {
                    $systemCount = $stockByMedicine[$medId];
                    $itemStmt->bind_param("iii", $batchId, $medId, $systemCount);
                    $itemStmt->execute();
                }
                $itemStmt->close();

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                // Surface our own validation messages ("medicine no longer
                // exists") as-is — only mask genuine unexpected DB failures
                // behind the generic message, same distinction
                // admin/inventory-actions.php's create_purchase_order makes
                // between its own thrown messages and a real 500.
                $knownMessages = [
                    'One or more selected medicines no longer exist.',
                ];
                $message = in_array($e->getMessage(), $knownMessages, true)
                    ? $e->getMessage()
                    : 'Could not create the count batch. Please try again.';
                respond(['success' => false, 'error' => $message], $message === $e->getMessage() ? 422 : 500);
            }

            create_notification(
                $conn,
                $assignedTo,
                "New inventory count assigned to you — {$batchNumber} (" . count($medicineIds) . " item" . (count($medicineIds) === 1 ? '' : 's') . ").",
                'inventory-count.php',
                'info'
            );
            write_audit_log($conn, $pharmacistId, 'pharmacist', 'batch_assigned', 'inventory', "Assigned {$batchNumber} to staff user {$assignedTo} with " . count($medicineIds) . ' medicine items.');

            respond(['success' => true, 'message' => 'Count batch assigned.', 'batch_id' => $batchId]);
            break;
        }

        // ------------------------------------------------------------
        // Confirm & Finalize (merged Review & Recount + Final Confirmation)
        // ------------------------------------------------------------
    case 'confirm_batch': {
            $batchId = (int) ($_POST['batch_id'] ?? 0);
            $finalCounts = $_POST['final_count'] ?? [];   // item_id => value
            $remarks = $_POST['remarks'] ?? [];           // item_id => text

            $batchStmt = $conn->prepare(
                "SELECT batch_id, batch_number, assigned_to, status FROM inventory_count_batches WHERE batch_id = ? AND assigned_by = ?"
            );
            $batchStmt->bind_param("ii", $batchId, $pharmacistId);
            $batchStmt->execute();
            $batch = $batchStmt->get_result()->fetch_assoc();
            $batchStmt->close();

            if (!$batch) respond(['success' => false, 'error' => 'Batch not found.'], 404);
            if ($batch['status'] !== 'submitted') {
                respond(['success' => false, 'error' => 'This batch is not awaiting review.'], 409);
            }

            $itemsStmt = $conn->prepare(
                "SELECT ici.item_id, ici.medicine_id, ici.system_count, ici.staff_count, im.name AS medicine_name,
                        im.unit
                 FROM inventory_count_items ici
                 JOIN inventory_medicines im ON im.medicine_id = ici.medicine_id
                 WHERE ici.batch_id = ?"
            );
            $itemsStmt->bind_param("i", $batchId);
            $itemsStmt->execute();
            $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $itemsStmt->close();

            $reportLines = [];
            $totalFinalCount = 0;
            $unitsRemovedFromBatches = 0;
            $unitsAddedToBatches = 0;
            $conn->begin_transaction();
            try {
                // Roll-forward cutoff: the count was TAKEN when Staff
                // submitted, not right now — see header comment. Fetched
                // once, used for every item in this batch.
                $sinceStmt = $conn->prepare("SELECT submitted_at FROM inventory_count_batches WHERE batch_id = ?");
                $sinceStmt->bind_param("i", $batchId);
                $sinceStmt->execute();
                $submittedAt = $sinceStmt->get_result()->fetch_assoc()['submitted_at'];
                $sinceStmt->close();

                foreach ($items as $item) {
                    $itemId = (int) $item['item_id'];
                    $medicineId = (int) $item['medicine_id'];

                    // Default to what staff counted; pharmacist can type a
                    // correction in the Final Count field per line.
                    $submittedFinal = $finalCounts[$itemId] ?? null;
                    $final = ($submittedFinal !== null && $submittedFinal !== '')
                        ? (int) $submittedFinal
                        : (int) $item['staff_count'];
                    if ($final < 0) $final = 0;

                    $remark = trim($remarks[$itemId] ?? '');
                    $remarkParam = $remark !== '' ? $remark : null;
                    $totalFinalCount += $final;
                    $reportLines[] = [
                        'item_number' => $itemId,
                        'medicine_id' => $medicineId,
                        'medicine' => $item['medicine_name'],
                        'unit' => $item['unit'],
                        'system_count' => (int) $item['system_count'],
                        'staff_count' => (int) $item['staff_count'],
                        'final_count' => $final,
                        'difference' => $final - (int) $item['system_count'],
                        'remarks' => $remark,
                    ];

                    $upd = $conn->prepare("UPDATE inventory_count_items SET final_count = ?, remarks = ? WHERE item_id = ?");
                    $upd->bind_param("isi", $final, $remarkParam, $itemId);
                    $upd->execute();
                    $upd->close();

                    // Roll-forward: add back any delivery and
                    // subtract any dispense recorded since the count was
                    // submitted, before writing current_stock — otherwise
                    // a delivery or dispense that lands while the batch
                    // sits in "Awaiting Review" would be silently erased
                    // by a flat overwrite.
                    $deliveredSince = $conn->prepare(
                        "SELECT COALESCE(SUM(units_received), 0) AS total FROM medicine_batches WHERE medicine_id = ? AND received_at > ? AND source <> 'count_adjustment'"
                    );
                    $deliveredSince->bind_param("is", $medicineId, $submittedAt);
                    $deliveredSince->execute();
                    $unitsDeliveredSince = (int) $deliveredSince->get_result()->fetch_assoc()['total'];
                    $deliveredSince->close();

                    $dispensedSince = $conn->prepare(
                        "SELECT COALESCE(SUM(units_deducted), 0) AS total FROM medicine_exit_log WHERE medicine_id = ? AND scanned_at > ?"
                    );
                    $dispensedSince->bind_param("is", $medicineId, $submittedAt);
                    $dispensedSince->execute();
                    $unitsDispensedSince = (int) $dispensedSince->get_result()->fetch_assoc()['total'];
                    $dispensedSince->close();

                    $newStock = max(0, $final + $unitsDeliveredSince - $unitsDispensedSince);

                    // Apply the same correction to the batches (see the
                    // BATCH RECONCILIATION note in the header) and keep what
                    // changed on this line of the saved report.
                    $batchChanges = reconcileBatchesToStock($conn, $medicineId, $newStock, $batch['batch_number']);
                    $reportLines[count($reportLines) - 1]['batch_changes'] = $batchChanges;
                    foreach ($batchChanges as $change) {
                        if ($change['units'] < 0) {
                            $unitsRemovedFromBatches += -$change['units'];
                        } else {
                            $unitsAddedToBatches += $change['units'];
                        }
                    }

                    $stockUpd = $conn->prepare("UPDATE inventory_medicines SET current_stock = ? WHERE medicine_id = ?");
                    $stockUpd->bind_param("ii", $newStock, $medicineId);
                    $stockUpd->execute();
                    $stockUpd->close();
                }

                $closeBatch = $conn->prepare(
                    "UPDATE inventory_count_batches SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW() WHERE batch_id = ?"
                );
                $closeBatch->bind_param("ii", $pharmacistId, $batchId);
                $closeBatch->execute();
                $closeBatch->close();

                $reportName = 'Final Confirmation Report - ' . date('Y-m-d');
                $snapshotData = json_encode($reportLines, JSON_UNESCAPED_SLASHES);
                if ($snapshotData === false) {
                    throw new Exception('Could not encode the report snapshot.');
                }
                $reportInsert = $conn->prepare(
                    "INSERT INTO inventory_count_reports
                        (batch_id, report_name, saved_by, total_items, total_count, snapshot_data)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                $totalItems = count($reportLines);
                $reportInsert->bind_param('isiiis', $batchId, $reportName, $pharmacistId, $totalItems, $totalFinalCount, $snapshotData);
                $reportInsert->execute();
                $reportInsert->close();

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                if ($e->getMessage() === 'count_adjustment_source_missing') {
                    respond(['success' => false, 'error' => 'This database has not been updated for count adjustments yet. Run 019_add_count_adjustment_batch_source.sql, then confirm this batch again.'], 500);
                }
                respond(['success' => false, 'error' => 'Could not finalize this batch. Please try again.'], 500);
            }

            create_notification(
                $conn,
                $batch['assigned_to'],
                "Your inventory count {$batch['batch_number']} was reviewed and confirmed.",
                'inventory-count.php',
                'success'
            );
            write_audit_log($conn, $pharmacistId, 'pharmacist', 'batch_confirmed', 'inventory', "Confirmed {$batch['batch_number']} with " . count($reportLines) . " items and total final count {$totalFinalCount}. Batch adjustments: {$unitsRemovedFromBatches} unit(s) removed, {$unitsAddedToBatches} unit(s) added.");

            respond(['success' => true, 'message' => 'Batch confirmed — inventory updated.']);
            break;
        }

        // ------------------------------------------------------------
        // Send Back for Recount — partial, item-level. Only the specific
        // flagged item(s) get cleared and re-opened to staff; everything
        // else in the batch keeps its already-submitted staff_count.
        // Reverting the WHOLE batch to 'pending' (rather than adding a
        // per-item status) is what re-enables staff's editable form —
        // staff/inventory-count-actions.php already gates all editing on
        // status === 'pending', so no staff-side changes were needed for
        // this feature at all.
        // ------------------------------------------------------------
    case 'request_recount': {
            $batchId = (int) ($_POST['batch_id'] ?? 0);
            $recountItemIds = array_map('intval', $_POST['recount_item_ids'] ?? []);
            $recountItemIds = array_values(array_unique(array_filter($recountItemIds, fn($id) => $id > 0)));

            if (empty($recountItemIds)) {
                respond(['success' => false, 'error' => 'Select at least one item to send back for recount.'], 422);
            }

            $batchStmt = $conn->prepare(
                "SELECT batch_id, batch_number, assigned_to, notes, status FROM inventory_count_batches WHERE batch_id = ? AND assigned_by = ?"
            );
            $batchStmt->bind_param("ii", $batchId, $pharmacistId);
            $batchStmt->execute();
            $batch = $batchStmt->get_result()->fetch_assoc();
            $batchStmt->close();

            if (!$batch) respond(['success' => false, 'error' => 'Batch not found.'], 404);
            if ($batch['status'] !== 'submitted') {
                respond(['success' => false, 'error' => 'This batch is not awaiting review.'], 409);
            }

            // Only touch items that actually belong to this batch — same
            // tamper guard staff-side save_progress/submit_batch use.
            $validStmt = $conn->prepare("SELECT item_id FROM inventory_count_items WHERE batch_id = ?");
            $validStmt->bind_param("i", $batchId);
            $validStmt->execute();
            $validItemIds = array_column($validStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'item_id');
            $validStmt->close();
            $recountItemIds = array_values(array_intersect($recountItemIds, $validItemIds));
            if (empty($recountItemIds)) {
                respond(['success' => false, 'error' => 'Select at least one item to send back for recount.'], 422);
            }

            $conn->begin_transaction();
            try {
                $placeholders = implode(',', array_fill(0, count($recountItemIds), '?'));
                $types = str_repeat('i', count($recountItemIds));
                $clear = $conn->prepare(
                    "UPDATE inventory_count_items SET staff_count = NULL WHERE batch_id = ? AND item_id IN ($placeholders)"
                );
                $clear->bind_param('i' . $types, $batchId, ...$recountItemIds);
                $clear->execute();
                $clear->close();

                // Appended to the SAME notes field staff already sees as
                // "Instructions" — deliberately generic (item count only,
                // no medicine names, no system counts, no pharmacist
                // reasoning) so the blind-count guarantee holds: staff
                // learns a recount is needed, never why or by how much.
                $recountNote = count($recountItemIds) . ' item' . (count($recountItemIds) === 1 ? '' : 's') . ' need' . (count($recountItemIds) === 1 ? 's' : '') . ' to be recounted.';
                $existingNotes = trim((string) ($batch['notes'] ?? ''));
                $newNotes = $existingNotes !== '' ? $existingNotes . ' | Recount requested: ' . $recountNote : 'Recount requested: ' . $recountNote;

                $revert = $conn->prepare(
                    "UPDATE inventory_count_batches SET status = 'pending', submitted_at = NULL, notes = ? WHERE batch_id = ?"
                );
                $revert->bind_param("si", $newNotes, $batchId);
                $revert->execute();
                $revert->close();

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                respond(['success' => false, 'error' => 'Could not send this batch back for recount. Please try again.'], 500);
            }

            create_notification(
                $conn,
                $batch['assigned_to'],
                "{$batch['batch_number']} needs a recount on " . count($recountItemIds) . " item" . (count($recountItemIds) === 1 ? '' : 's') . " before it can be reviewed.",
                'inventory-count.php',
                'warning'
            );
            write_audit_log($conn, $pharmacistId, 'pharmacist', 'recount_requested', 'inventory', "Sent {$batch['batch_number']} back for recount on " . count($recountItemIds) . ' item(s).');

            respond(['success' => true, 'message' => 'Sent back for recount.']);
            break;
        }

    default:
        respond(['success' => false, 'error' => 'Unknown action.'], 400);
}
