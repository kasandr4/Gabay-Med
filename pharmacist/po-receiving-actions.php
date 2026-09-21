<?php
// Buffer all output from this point on. If anything (a stray PHP
// warning/notice from this file, an include, or a different php.ini's
// display_errors=On setting) prints text before we're ready to send
// our JSON response, respond() below discards it -- so the client
// always gets valid JSON back, never JSON prefixed with HTML error
// output that breaks JSON.parse() in the browser.
ob_start();
require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/audit_log.php';
require_once '../includes/excel_helper.php';
require_once '../includes/inventory_helpers.php';

$pharmacistId = (int) $_SESSION['user_id'];

function respond($data, $status = 200)
{
    // Discard any buffered output (stray warnings/notices) so only
    // clean JSON ever reaches the client.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

const RECEIVING_TEMPLATE_HEADER = ['PO Item ID', 'Generic Name', 'Brand', 'Strength', 'Unit', 'Qty Ordered', 'Qty Received', 'Batch No', 'Expiry Date (YYYY-MM-DD)', 'Unit Price', 'Selling Price', 'Notes'];

// resolve_medicine_id() used to be defined here - moved to
// includes/inventory_helpers.php as find_or_create_medicine() and
// merged with record-stock-batch-actions.php's near-identical logic
// (see that function's own header comment for the full reasoning).
// Behavior at this call site is unchanged: never errors on an existing
// match, never touches manufacturer.

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'download_template') {
    $poId = (int) ($_GET['po_id'] ?? 0);
    if ($poId <= 0) {
        respond(['success' => false, 'error' => 'Missing purchase order.'], 422);
    }
    $stmt = $conn->prepare(
        'SELECT poi_id, generic_name, brand, strength, unit, qty_ordered, unit_price_estimated
         FROM purchase_order_items WHERE po_id = ? ORDER BY poi_id ASC'
    );
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            $row['poi_id'],
            $row['generic_name'],
            $row['brand'],
            $row['strength'],
            $row['unit'],
            $row['qty_ordered'],
            $row['qty_ordered'], // Qty Received pre-filled with Qty Ordered; edit if it differs
            '',
            '',
            $row['unit_price_estimated'],
            '',
            '',
        ];
    }
    $stmt->close();
    csv_download("receiving_po_{$poId}.csv", RECEIVING_TEMPLATE_HEADER, $rows);
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
        $qtyReceived = $row['qty_received'] ?? '';
        if ($qtyReceived === '' || !is_numeric($qtyReceived) || (int) $qtyReceived < 0) {
            $warnings[] = "Row {$lineNo}: invalid Qty Received, skipped";
            continue;
        }
        $expiry = trim($row['expiry_date_yyyy_mm_dd'] ?? '');
        if ($expiry !== '' && !DateTime::createFromFormat('Y-m-d', $expiry)) {
            $warnings[] = "Row {$lineNo}: invalid expiry date format, left blank";
            $expiry = '';
        }
        $items[] = [
            'poi_id' => $poiId,
            'qty_received' => (int) $qtyReceived,
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
    $poStmt = $conn->prepare("SELECT po_id, status FROM purchase_orders WHERE po_id = ?");
    $poStmt->bind_param('i', $poId);
    $poStmt->execute();
    $po = $poStmt->get_result()->fetch_assoc();
    $poStmt->close();
    if (!$po) {
        respond(['success' => false, 'error' => 'Purchase order not found.'], 404);
    }
    if ($po['status'] !== 'open') {
        respond(['success' => false, 'error' => 'This order has already been received or cancelled.'], 409);
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
        $qtyReceived = (int) ($line['qty_received'] ?? -1);
        if ($qtyReceived < 0) {
            respond(['success' => false, 'error' => "Row {$rowNo}: enter a valid received quantity (0 or more)."], 422);
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
            'qty_received' => $qtyReceived,
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
            $line = $validated[$poiId] ?? ['qty_received' => 0, 'batch_no' => null, 'expiry_date' => null, 'unit_price' => null, 'selling_price' => null, 'notes' => null];
            $qtyOrdered = (int) $poItem['qty_ordered'];
            $qtyReceived = (int) $line['qty_received'];
            $variance = $qtyReceived - $qtyOrdered;

            if ($variance < 0) {
                $varianceNote = 'Short by ' . abs($variance) . ' ' . $poItem['unit'];
            } elseif ($variance > 0) {
                $varianceNote = 'Excess of ' . $variance . ' ' . $poItem['unit'];
            } else {
                $varianceNote = 'Received as ordered';
            }
            if ($variance !== 0) {
                $mismatches[] = $poItem['generic_name'] . ': ' . $varianceNote;
            }

            $batchId = null;
            if ($qtyReceived > 0) {
                $medicineId = $poItem['medicine_id'] ? (int) $poItem['medicine_id'] : null;
                if (!$medicineId) {
                    $lookup = find_or_create_medicine(
                        $conn,
                        $poItem['generic_name'],
                        $poItem['strength'],
                        $poItem['unit'],
                        $poItem['units_per_box'] !== null ? (int) $poItem['units_per_box'] : null,
                        false
                    );
                    $medicineId = $lookup['medicine_id'];
                }

                $source = $poItem['funding_source'] ?: 'purchase_order';
                $brandParam = $poItem['brand'];
                $batchId = credit_medicine_batch(
                    $conn,
                    $medicineId,
                    $source,
                    $brandParam,
                    $line['batch_no'],
                    $qtyReceived,
                    $line['expiry_date'],
                    $line['unit_price'],
                    $line['selling_price'],
                    $pharmacistId
                );

                if (!$poItem['medicine_id']) {
                    $linkMedicine = $conn->prepare('UPDATE purchase_order_items SET medicine_id = ? WHERE poi_id = ?');
                    $linkMedicine->bind_param('ii', $medicineId, $poiId);
                    $linkMedicine->execute();
                    $linkMedicine->close();
                }
            }

            $updateItem = $conn->prepare(
                'UPDATE purchase_order_items
                 SET qty_received = ?, variance = ?, variance_note = ?, received_batch_no = ?, received_expiry_date = ?,
                     received_unit_price = ?, received_selling_price = ?, batch_id = ?, notes = ?
                 WHERE poi_id = ?'
            );
            $updateItem->bind_param(
                'iisssddiis',
                $qtyReceived,
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
        respond(['success' => false, 'error' => 'Could not confirm this delivery. Please try again.'], 500);
    }

    $summary = "Received delivery for PO {$poId}." . (!empty($mismatches) ? ' Mismatches: ' . implode('; ', $mismatches) : ' All quantities matched.');
    write_audit_log($conn, $pharmacistId, 'pharmacist', 'purchase_order_received', 'procurement', $summary);

    respond(['success' => true, 'mismatches' => $mismatches]);
}

respond(['success' => false, 'error' => 'Unknown action.'], 400);
