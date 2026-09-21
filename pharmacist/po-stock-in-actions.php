<?php
// pharmacist/po-stock-in-actions.php
//
// Backend for the "From Purchase Order" tab on record-stock-batch.php.
//
// Replaces the old po-receiving.php / po-receiving-actions.php pair.
// That flow assumed the pharmacist was the one physically checking a
// delivery box against the PO (qty_ordered vs qty_received). The
// pharmacy doesn't do that anymore -- the Provincial Capitol delivers,
// and by the time stock reaches the pharmacist it's already in hand.
// So this compares what's actually being added to stock against what
// was originally requested on the linked PR instead -- that's the real
// "did Capitol send too little/too much" question -- and writes the
// batch straight into inventory the same way record-stock-batch-actions.php
// does for every other source.
ob_start();
require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/audit_log.php';
require_once '../includes/excel_helper.php';

$pharmacistId = (int) $_SESSION['user_id'];

function respond($data, $status = 200)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

const PO_STOCK_IN_TEMPLATE_HEADER = ['PO Item ID', 'Generic Name', 'Brand', 'Strength', 'Unit', 'Qty Ordered', 'Qty Requested (PR)', 'Qty to Add', 'Batch No', 'Expiry Date (YYYY-MM-DD)', 'Unit Price', 'Selling Price', 'Notes'];

/**
 * Finds an existing catalog medicine matching (generic name + strength),
 * or creates the generic name and medicine record if this is genuinely
 * new. Mirrors the same helper in record-stock-batch-actions.php / the
 * removed po-receiving-actions.php, so a medicine ordered via PR/PO that
 * isn't in the catalog yet still gets created consistently.
 */
function resolve_medicine_id(mysqli $conn, string $genericName, ?string $strength, string $unit, ?int $unitsPerBox): int
{
    $genericStmt = $conn->prepare("SELECT generic_id FROM medicine_names WHERE LOWER(name) = LOWER(?) LIMIT 1 FOR UPDATE");
    $genericStmt->bind_param('s', $genericName);
    $genericStmt->execute();
    $generic = $genericStmt->get_result()->fetch_assoc();
    $genericStmt->close();

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
        return (int) $existing['medicine_id'];
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

    return $medicineId;
}

/**
 * PR requested quantities for the PO's linked PR, keyed by medicine_id
 * when known, else by lower(generic name)+strength as a fallback -- same
 * matching approach used client-side elsewhere (matchCatalog()).
 */
function load_pr_requested_map(mysqli $conn, ?int $prId): array
{
    $byMedicineId = [];
    $byGenericStrength = [];
    if (!$prId) {
        return [$byMedicineId, $byGenericStrength];
    }
    $stmt = $conn->prepare('SELECT medicine_id, generic_name, strength, qty_requested FROM purchase_request_items WHERE pr_id = ?');
    $stmt->bind_param('i', $prId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        if ($row['medicine_id']) {
            $byMedicineId[(int) $row['medicine_id']] = (int) $row['qty_requested'];
        }
        $key = strtolower(trim($row['generic_name'])) . '|' . strtolower(trim((string) $row['strength']));
        $byGenericStrength[$key] = (int) $row['qty_requested'];
    }
    $stmt->close();
    return [$byMedicineId, $byGenericStrength];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'download_template') {
    $poId = (int) ($_GET['po_id'] ?? 0);
    if ($poId <= 0) {
        respond(['success' => false, 'error' => 'Missing purchase order.'], 422);
    }
    $poRow = $conn->prepare('SELECT pr_id FROM purchase_orders WHERE po_id = ?');
    $poRow->bind_param('i', $poId);
    $poRow->execute();
    $poData = $poRow->get_result()->fetch_assoc();
    $poRow->close();
    if (!$poData) {
        respond(['success' => false, 'error' => 'Purchase order not found.'], 404);
    }
    [$byMedicineId, $byGenericStrength] = load_pr_requested_map($conn, $poData['pr_id'] ? (int) $poData['pr_id'] : null);

    $stmt = $conn->prepare(
        'SELECT poi_id, medicine_id, generic_name, brand, strength, unit, qty_ordered, unit_price_estimated
         FROM purchase_order_items WHERE po_id = ? ORDER BY poi_id ASC'
    );
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $qtyRequested = null;
        if ($row['medicine_id'] && isset($byMedicineId[(int) $row['medicine_id']])) {
            $qtyRequested = $byMedicineId[(int) $row['medicine_id']];
        } else {
            $key = strtolower(trim($row['generic_name'])) . '|' . strtolower(trim((string) $row['strength']));
            $qtyRequested = $byGenericStrength[$key] ?? null;
        }
        $rows[] = [
            $row['poi_id'],
            $row['generic_name'],
            $row['brand'],
            $row['strength'],
            $row['unit'],
            $row['qty_ordered'],
            $qtyRequested ?? '',
            $row['qty_ordered'], // Qty to Add pre-filled with Qty Ordered; edit if it differs
            '',
            '',
            $row['unit_price_estimated'],
            '',
            '',
        ];
    }
    $stmt->close();
    csv_download("stock_in_po_{$poId}.csv", PO_STOCK_IN_TEMPLATE_HEADER, $rows);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Invalid request method.'], 405);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isMultipart = stripos($contentType, 'multipart/form-data') !== false;

if ($isMultipart) {
    $action = $_POST['action'] ?? '';
    $submittedToken = $_POST['csrf_token'] ?? '';
    $poId = (int) ($_POST['po_id'] ?? 0);
} else {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?: [];
    $action = $body['action'] ?? '';
    $submittedToken = $body['csrf_token'] ?? '';
    $poId = (int) ($body['po_id'] ?? 0);
}

$expectedToken = $_SESSION['csrf_token'] ?? '';
if ($expectedToken === '' || !hash_equals($expectedToken, (string) $submittedToken)) {
    respond(['success' => false, 'error' => 'Your session expired. Please refresh the page and try again.'], 403);
}

if ($poId <= 0) {
    respond(['success' => false, 'error' => 'Missing purchase order.'], 422);
}

if ($action === 'parse_upload') {
    try {
        $rows = csv_parse_upload('csv_file');
    } catch (RuntimeException $e) {
        respond(['success' => false, 'error' => $e->getMessage()], 422);
    }

    // Only accept rows referencing items that actually belong to this PO.
    $validPoiIds = [];
    $stmt = $conn->prepare('SELECT poi_id FROM purchase_order_items WHERE po_id = ?');
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $validPoiIds[(int) $row['poi_id']] = true;
    }
    $stmt->close();

    $items = [];
    $warnings = [];
    foreach ($rows as $index => $row) {
        $lineNo = $index + 2;
        $poiId = (int) ($row['po_item_id'] ?? 0);
        if ($poiId <= 0 || !isset($validPoiIds[$poiId])) {
            $warnings[] = "Row {$lineNo}: PO Item ID doesn't match this order, skipped";
            continue;
        }
        $qtyToAdd = $row['qty_to_add'] ?? '';
        if ($qtyToAdd === '' || !is_numeric($qtyToAdd) || (int) $qtyToAdd < 0) {
            $warnings[] = "Row {$lineNo}: invalid Qty to Add, skipped";
            continue;
        }
        $expiry = trim($row['expiry_date_yyyy_mm_dd'] ?? '');
        if ($expiry !== '' && !DateTime::createFromFormat('Y-m-d', $expiry)) {
            $warnings[] = "Row {$lineNo}: invalid expiry date format, left blank";
            $expiry = '';
        }
        $items[] = [
            'poi_id' => $poiId,
            'qty_to_add' => (int) $qtyToAdd,
            'batch_no' => trim($row['batch_no'] ?? ''),
            'expiry_date' => $expiry,
            'unit_price' => trim($row['unit_price'] ?? ''),
            'selling_price' => trim($row['selling_price'] ?? ''),
            'notes' => trim($row['notes'] ?? ''),
        ];
    }

    if (empty($items)) {
        respond(['success' => false, 'error' => 'No valid rows found. Make sure you uploaded the sheet downloaded for this order.'], 422);
    }

    respond(['success' => true, 'items' => $items, 'warnings' => $warnings]);
}

if ($action === 'confirm') {
    $poStmt = $conn->prepare("SELECT po_id, pr_id, status FROM purchase_orders WHERE po_id = ?");
    $poStmt->bind_param('i', $poId);
    $poStmt->execute();
    $po = $poStmt->get_result()->fetch_assoc();
    $poStmt->close();
    if (!$po) {
        respond(['success' => false, 'error' => 'Purchase order not found.'], 404);
    }
    if ($po['status'] !== 'open') {
        respond(['success' => false, 'error' => 'Stock has already been recorded for this order, or it was cancelled.'], 409);
    }

    $itemsStmt = $conn->prepare(
        'SELECT poi_id, medicine_id, generic_name, brand, strength, unit, units_per_box, qty_ordered, funding_source
         FROM purchase_order_items WHERE po_id = ?'
    );
    $itemsStmt->bind_param('i', $poId);
    $itemsStmt->execute();
    $res = $itemsStmt->get_result();
    $poItemsById = [];
    while ($row = $res->fetch_assoc()) {
        $poItemsById[(int) $row['poi_id']] = $row;
    }
    $itemsStmt->close();

    [$prByMedicineId, $prByGenericStrength] = load_pr_requested_map($conn, $po['pr_id'] ? (int) $po['pr_id'] : null);

    $submitted = is_array($body['items'] ?? null) ? $body['items'] : [];
    if (empty($submitted)) {
        respond(['success' => false, 'error' => 'No items to confirm.'], 422);
    }

    $validated = [];
    foreach ($submitted as $i => $line) {
        $rowNo = $i + 1;
        $poiId = (int) ($line['poi_id'] ?? 0);
        if (!isset($poItemsById[$poiId])) {
            respond(['success' => false, 'error' => "Row {$rowNo}: item does not belong to this order."], 422);
        }
        $qtyToAdd = (int) ($line['qty_to_add'] ?? -1);
        if ($qtyToAdd < 0) {
            respond(['success' => false, 'error' => "Row {$rowNo}: enter a valid quantity to add (0 or more)."], 422);
        }
        $batchNo = trim((string) ($line['batch_no'] ?? ''));
        $expiryDate = trim((string) ($line['expiry_date'] ?? ''));
        $notes = trim((string) ($line['notes'] ?? ''));
        if (strlen($batchNo) > 50 || strlen($notes) > 255) {
            respond(['success' => false, 'error' => "Row {$rowNo}: one or more fields exceed the allowed length."], 422);
        }
        if ($expiryDate !== '' && !DateTime::createFromFormat('Y-m-d', $expiryDate)) {
            respond(['success' => false, 'error' => "Row {$rowNo}: enter a valid expiry date."], 422);
        }
        $unitPrice = null;
        $unitPriceInput = trim((string) ($line['unit_price'] ?? ''));
        if ($unitPriceInput !== '') {
            $unitPrice = filter_var($unitPriceInput, FILTER_VALIDATE_FLOAT);
            if ($unitPrice === false || $unitPrice < 0) {
                respond(['success' => false, 'error' => "Row {$rowNo}: enter a valid unit price."], 422);
            }
        }
        $sellingPrice = null;
        $sellingPriceInput = trim((string) ($line['selling_price'] ?? ''));
        if ($sellingPriceInput !== '') {
            $sellingPrice = filter_var($sellingPriceInput, FILTER_VALIDATE_FLOAT);
            if ($sellingPrice === false || $sellingPrice < 0) {
                respond(['success' => false, 'error' => "Row {$rowNo}: enter a valid selling price."], 422);
            }
        }

        $validated[$poiId] = [
            'qty_to_add' => $qtyToAdd,
            'batch_no' => $batchNo !== '' ? $batchNo : null,
            'expiry_date' => $expiryDate !== '' ? $expiryDate : null,
            'unit_price' => $unitPrice,
            'selling_price' => $sellingPrice,
            'notes' => $notes !== '' ? $notes : null,
        ];
    }

    $mismatches = [];
    $conn->begin_transaction();
    try {
        foreach ($poItemsById as $poiId => $poItem) {
            $line = $validated[$poiId] ?? ['qty_to_add' => 0, 'batch_no' => null, 'expiry_date' => null, 'unit_price' => null, 'selling_price' => null, 'notes' => null];
            $qtyToAdd = (int) $line['qty_to_add'];

            // Compare against what the PR actually requested -- not
            // qty_ordered -- since the question here is whether Capitol
            // sent what the hospital asked for, not whether this order
            // matches itself.
            $qtyRequested = null;
            if ($poItem['medicine_id'] && isset($prByMedicineId[(int) $poItem['medicine_id']])) {
                $qtyRequested = $prByMedicineId[(int) $poItem['medicine_id']];
            } else {
                $key = strtolower(trim($poItem['generic_name'])) . '|' . strtolower(trim((string) $poItem['strength']));
                $qtyRequested = $prByGenericStrength[$key] ?? null;
            }

            if ($qtyRequested === null) {
                $variance = 0;
                $varianceNote = 'No linked PR to compare against';
            } else {
                $variance = $qtyToAdd - $qtyRequested;
                if ($variance < 0) {
                    $varianceNote = 'Short by ' . abs($variance) . ' ' . $poItem['unit'] . ' vs PR';
                } elseif ($variance > 0) {
                    $varianceNote = 'Excess of ' . $variance . ' ' . $poItem['unit'] . ' vs PR';
                } else {
                    $varianceNote = 'Matches PR request';
                }
                if ($variance !== 0) {
                    $mismatches[] = $poItem['generic_name'] . ': ' . $varianceNote;
                }
            }

            $batchId = null;
            if ($qtyToAdd > 0) {
                $medicineId = $poItem['medicine_id'] ? (int) $poItem['medicine_id'] : null;
                if (!$medicineId) {
                    $medicineId = resolve_medicine_id(
                        $conn,
                        $poItem['generic_name'],
                        $poItem['strength'],
                        $poItem['unit'],
                        $poItem['units_per_box'] !== null ? (int) $poItem['units_per_box'] : null
                    );
                }

                $source = $poItem['funding_source'] ?: 'purchase_order';
                $brandParam = $poItem['brand'];
                $batchInsert = $conn->prepare(
                    'INSERT INTO medicine_batches (medicine_id, source, brand, batch_no, units_received, units_remaining, expiry_date, unit_price, selling_price, received_at, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
                );
                $batchInsert->bind_param(
                    'isssiisddi',
                    $medicineId,
                    $source,
                    $brandParam,
                    $line['batch_no'],
                    $qtyToAdd,
                    $qtyToAdd,
                    $line['expiry_date'],
                    $line['unit_price'],
                    $line['selling_price'],
                    $pharmacistId
                );
                $batchInsert->execute();
                $batchId = $batchInsert->insert_id;
                $batchInsert->close();

                $stockUpdate = $conn->prepare('UPDATE inventory_medicines SET current_stock = current_stock + ? WHERE medicine_id = ?');
                $stockUpdate->bind_param('ii', $qtyToAdd, $medicineId);
                $stockUpdate->execute();
                $stockUpdate->close();

                if (!$poItem['medicine_id']) {
                    $linkMedicine = $conn->prepare('UPDATE purchase_order_items SET medicine_id = ? WHERE poi_id = ?');
                    $linkMedicine->bind_param('ii', $medicineId, $poiId);
                    $linkMedicine->execute();
                    $linkMedicine->close();
                }
            }

            // Reuses the existing receiving-era columns (qty_received,
            // variance, variance_note, etc.) as the record of what was
            // actually stocked in and how it compared -- renaming those
            // columns isn't needed for what they now hold.
            $updateItem = $conn->prepare(
                'UPDATE purchase_order_items
                 SET qty_received = ?, variance = ?, variance_note = ?, received_batch_no = ?, received_expiry_date = ?,
                     received_unit_price = ?, received_selling_price = ?, batch_id = ?, notes = ?
                 WHERE poi_id = ?'
            );
            $updateItem->bind_param(
                'iisssddiis',
                $qtyToAdd,
                $variance,
                $varianceNote,
                $line['batch_no'],
                $line['expiry_date'],
                $line['unit_price'],
                $line['selling_price'],
                $batchId,
                $line['notes'],
                $poiId
            );
            $updateItem->execute();
            $updateItem->close();
        }

        $updatePo = $conn->prepare("UPDATE purchase_orders SET status = 'received', received_at = NOW(), received_by = ? WHERE po_id = ?");
        $updatePo->bind_param('ii', $pharmacistId, $poId);
        $updatePo->execute();
        $updatePo->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        respond(['success' => false, 'error' => 'Could not confirm this stock-in. Please try again.'], 500);
    }

    $summary = "Stocked in PO {$poId}." . (!empty($mismatches) ? ' Flagged vs PR: ' . implode('; ', $mismatches) : ' All quantities matched the PR.');
    write_audit_log($conn, $pharmacistId, 'pharmacist', 'purchase_order_stocked', 'inventory', $summary);

    respond(['success' => true, 'mismatches' => $mismatches]);
}

respond(['success' => false, 'error' => 'Unknown action.'], 400);
