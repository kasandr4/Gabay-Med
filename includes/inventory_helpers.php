<?php
// includes/inventory_helpers.php
//
// FIXED 2026-09-20 (audit finding, Redundancy Findings R3): the exact
// same two-statement sequence - INSERT medicine_batches, then UPDATE
// inventory_medicines.current_stock - was independently hand-written
// three times: twice in pharmacist/record-stock-batch-actions.php
// (record_new_medicine and the default record_batch action) and once
// in pharmacist/po-receiving-actions.php's confirm action. Three
// separately-maintained copies of "how stock actually gets credited" is
// exactly the kind of drift risk a real inventory system can't afford -
// a bug fixed in one copy (e.g. a missing bounds check) stays broken in
// the other two until someone happens to notice. One function now, used
// by all three.
//
// Deliberately narrow: this ONLY does the batch insert + stock credit.
// It does not touch purchase_order_items (po-receiving-actions.php's
// own variance/reconciliation columns), does not validate inputs (every
// caller already validates before calling this - duplicating validation
// here too would just be a second copy of a different kind), and does
// not manage the transaction (every caller wraps its own call in
// $conn->begin_transaction()/commit() alongside its OTHER writes in the
// same transaction, so this function must not open/close one itself).

/**
 * Finds or creates the medicine_names generic entry (case-insensitive
 * match by name) and the matching inventory_medicines row for (generic,
 * strength).
 *
 * FIXED 2026-09-20 (audit finding, Redundancy Findings R3, part 2 of 2 -
 * see credit_medicine_batch() above for part 1): this exact lookup was
 * independently duplicated between pharmacist/record-stock-batch-
 * actions.php's record_new_medicine action and pharmacist/po-receiving-
 * actions.php's own resolve_medicine_id() - confirmed by that function's
 * own comment admitting it: "Mirrors the 'New Medicine' logic in
 * record-stock-batch-actions.php... created consistently the same way."
 *
 * DELIBERATELY DOES NOT TOUCH `manufacturer` - that's the one place the
 * two original callers genuinely differed (Record Stock Batch sets/
 * clears it on the generic every time; PO Receiving never touched it at
 * all), and folding that into this shared function either way would
 * change one caller's real behavior. Manufacturer stays each caller's
 * own responsibility, applied (or not) after calling this - see
 * pharmacist/record-stock-batch-actions.php's own manufacturer UPDATE,
 * which still runs exactly as before, right after this returns.
 *
 * @param bool $errorOnExistingInventoryRow If true, throws a
 *   RuntimeException when an inventory_medicines row already exists for
 *   this (generic, strength) pair, instead of returning its id. Record
 *   Stock Batch's "New Medicine" path uses true - the pharmacist
 *   explicitly said this is new, so a real match is worth a clear error
 *   pointing them at "Existing Medicine" instead of silently reusing a
 *   possibly-wrong entry. PO Receiving uses false - it can't ask the
 *   pharmacist "is this new or existing" mid-delivery-reconciliation,
 *   so it must always resolve to something.
 * @return array{generic_id:int, medicine_id:int, generic_existed:bool}
 * @throws RuntimeException
 */
function find_or_create_medicine(
    mysqli $conn,
    string $genericName,
    ?string $strength,
    string $unit,
    ?int $unitsPerBox,
    bool $errorOnExistingInventoryRow = false
): array {
    $genericStmt = $conn->prepare("SELECT generic_id FROM medicine_names WHERE LOWER(name) = LOWER(?) LIMIT 1 FOR UPDATE");
    $genericStmt->bind_param('s', $genericName);
    $genericStmt->execute();
    $generic = $genericStmt->get_result()->fetch_assoc();
    $genericStmt->close();

    $genericExisted = (bool) $generic;

    if ($generic) {
        $genericId = (int) $generic['generic_id'];
    } else {
        $insertGeneric = $conn->prepare('INSERT INTO medicine_names (name) VALUES (?)');
        $insertGeneric->bind_param('s', $genericName);
        $insertGeneric->execute();
        $genericId = $insertGeneric->insert_id;
        $insertGeneric->close();
    }

    $strengthParam = $strength !== null && $strength !== '' ? $strength : '';
    $dupStmt = $conn->prepare("SELECT medicine_id FROM inventory_medicines WHERE generic_id = ? AND LOWER(COALESCE(strength, '')) = LOWER(?) LIMIT 1");
    $dupStmt->bind_param('is', $genericId, $strengthParam);
    $dupStmt->execute();
    $existing = $dupStmt->get_result()->fetch_assoc();
    $dupStmt->close();

    if ($existing) {
        if ($errorOnExistingInventoryRow) {
            throw new RuntimeException('A medicine with this generic name and strength already exists. Use "Existing Medicine" to add stock to it instead.');
        }
        return ['generic_id' => $genericId, 'medicine_id' => (int) $existing['medicine_id'], 'generic_existed' => $genericExisted];
    }

    $medicineName = $genericName . ($strengthParam !== '' ? ' ' . $strengthParam : '');
    $strengthColumn = $strengthParam !== '' ? $strengthParam : null;
    $unitsPerBoxColumn = $unitsPerBox ?: 1;
    $medStmt = $conn->prepare(
        'INSERT INTO inventory_medicines (generic_id, name, strength, unit, units_per_box, current_stock)
         VALUES (?, ?, ?, ?, ?, 0)'
    );
    $medStmt->bind_param('isssi', $genericId, $medicineName, $strengthColumn, $unit, $unitsPerBoxColumn);
    $medStmt->execute();
    $medicineId = $medStmt->insert_id;
    $medStmt->close();

    return ['generic_id' => $genericId, 'medicine_id' => $medicineId, 'generic_existed' => $genericExisted];
}
function credit_medicine_batch(
    mysqli $conn,
    int $medicineId,
    string $source,
    ?string $brand,
    ?string $batchNo,
    int $quantity,
    ?string $expiryDate,
    ?float $unitPrice,
    ?float $sellingPrice,
    int $createdBy,
    ?string $donorNotes = null
): int {
    $batchInsert = $conn->prepare(
        'INSERT INTO medicine_batches (medicine_id, source, brand, donor_notes, batch_no, units_received, units_remaining, expiry_date, unit_price, selling_price, received_at, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
    );
    $batchInsert->bind_param(
        'issssiisddi',
        $medicineId,
        $source,
        $brand,
        $donorNotes,
        $batchNo,
        $quantity,
        $quantity,
        $expiryDate,
        $unitPrice,
        $sellingPrice,
        $createdBy
    );
    $batchInsert->execute();
    $batchId = $batchInsert->insert_id;
    $batchInsert->close();

    $stockUpdate = $conn->prepare('UPDATE inventory_medicines SET current_stock = current_stock + ? WHERE medicine_id = ?');
    $stockUpdate->bind_param('ii', $quantity, $medicineId);
    $stockUpdate->execute();
    $stockUpdate->close();

    return $batchId;
}
