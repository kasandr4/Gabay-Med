<?php
// capitol/purchase-order-actions.php
// JSON action endpoint for Capitol's Purchase Orders page (2026-09-20,
// new role - see 027_capitol_role_and_po_handoff.sql). 'create' and
// 'parse_upload' were moved here FROM pharmacist/purchase-order-
// actions.php wholesale - the pharmacist can no longer create a PO at
// all (see that file's own header comment for why). 'cancel' is
// duplicated rather than moved - either side may reasonably need to
// cancel an order that fell through, and it's a safe, idempotent,
// already-guarded action to have in both places. 'send_to_pharmacist'
// is new: the one action that actually makes a PO visible on the
// pharmacist's side (see pharmacist/purchase-orders.php's WHERE clause).
//
// Buffer all output from this point on, same reasoning as every other
// *-actions.php endpoint in this app: a stray warning/notice must never
// leak into the JSON response and break the client's JSON.parse().
ob_start();
require_once '../includes/auth_guard.php';
require_role('capitol');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/audit_log.php';
require_once '../includes/excel_helper.php';
require_once '../includes/xlsx_helper.php';

$capitolId = (int) $_SESSION['user_id'];

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

const PO_FUNDING_SOURCES = ['maip', 'philhealth', 'pho', 'purchase_order', 'donated'];
const PO_TEMPLATE_HEADER = ['Generic Name', 'Brand', 'Strength', 'Unit', 'Units per Box', 'Quantity Ordered', 'Estimated Unit Price', 'Funding Source'];

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'download_template') {
    csv_download('purchase_order_template.csv', PO_TEMPLATE_HEADER, [
        ['Amoxicillin', 'Amoxil', '500mg', 'Capsule', '100', '20', '5.50', 'purchase_order'],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Invalid request method.'], 405);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isMultipart = stripos($contentType, 'multipart/form-data') !== false;

if ($isMultipart) {
    $action = $_POST['action'] ?? '';
    $submittedToken = $_POST['csrf_token'] ?? '';
} else {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?: [];
    $action = $body['action'] ?? '';
    $submittedToken = $body['csrf_token'] ?? '';
}

$expectedToken = $_SESSION['csrf_token'] ?? '';
if ($expectedToken === '' || !hash_equals($expectedToken, (string) $submittedToken)) {
    respond(['success' => false, 'error' => 'Your session expired. Please refresh the page and try again.'], 403);
}

if ($action === 'parse_upload') {
    $uploadedName = $_FILES['csv_file']['name'] ?? '';
    $uploadedExt = strtolower((string) pathinfo($uploadedName, PATHINFO_EXTENSION));
    try {
        if ($uploadedExt === 'xlsx') {
            $rows = xlsx_parse_upload('csv_file');
        } else {
            $rows = csv_parse_upload('csv_file');
        }
    } catch (RuntimeException $e) {
        respond(['success' => false, 'error' => $e->getMessage()], 422);
    }

    $validUnits = [];
    $unitRes = $conn->query("SELECT name FROM medicine_units");
    if ($unitRes) {
        while ($unitRow = $unitRes->fetch_assoc()) {
            $validUnits[strtolower($unitRow['name'])] = true;
        }
    }

    $items = [];
    $warnings = [];
    foreach ($rows as $index => $row) {
        $lineNo = $index + 2;
        $genericName = trim($row['generic_name'] ?? '');
        $qty = (int) ($row['quantity_ordered'] ?? 0);
        if ($genericName === '') {
            $warnings[] = "Row {$lineNo}: missing medicine name, skipped";
            continue;
        }
        if ($qty <= 0) {
            $warnings[] = "Row {$lineNo}: invalid quantity, skipped";
            continue;
        }
        $unit = trim($row['unit'] ?? '');
        if ($unit !== '' && !isset($validUnits[strtolower($unit)])) {
            $warnings[] = "Row {$lineNo}: \"{$unit}\" isn't a recognized unit, pick one in the grid before saving";
        }
        $source = strtolower(trim($row['funding_source'] ?? ''));
        if ($source !== '' && !in_array($source, PO_FUNDING_SOURCES, true)) {
            $warnings[] = "Row {$lineNo}: unrecognized funding source \"{$source}\", left blank";
            $source = '';
        }
        $priceInput = trim($row['estimated_unit_price'] ?? '');
        $price = '';
        if ($priceInput !== '') {
            $parsed = filter_var($priceInput, FILTER_VALIDATE_FLOAT);
            if ($parsed === false || $parsed < 0) {
                $warnings[] = "Row {$lineNo}: invalid unit price, left blank";
            } else {
                $price = $parsed;
            }
        }
        $items[] = [
            'generic_name' => $genericName,
            'brand' => trim($row['brand'] ?? ''),
            'strength' => trim($row['strength'] ?? ''),
            'unit' => trim($row['unit'] ?? ''),
            'units_per_box' => trim($row['units_per_box'] ?? ''),
            'qty_ordered' => $qty,
            'unit_price' => $price,
            'funding_source' => $source,
            'medicine_id' => null,
        ];
    }

    if (empty($items)) {
        respond(['success' => false, 'error' => 'No valid rows found in that file. Check the template columns and try again.'], 422);
    }

    respond(['success' => true, 'items' => $items, 'warnings' => $warnings]);
}

if ($action === 'cancel') {
    $poId = (int) ($body['po_id'] ?? 0);
    if ($poId <= 0) {
        respond(['success' => false, 'error' => 'Missing purchase order.'], 422);
    }
    $stmt = $conn->prepare("UPDATE purchase_orders SET status = 'cancelled' WHERE po_id = ? AND status = 'open'");
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected === 0) {
        respond(['success' => false, 'error' => 'This order can no longer be cancelled.'], 409);
    }
    write_audit_log($conn, $capitolId, 'capitol', 'purchase_order_cancelled', 'procurement', "Cancelled purchase order {$poId}.");
    respond(['success' => true]);
}

// New (2026-09-20): the one action that makes a PO visible to the
// pharmacist at all - see pharmacist/purchase-orders.php's list query,
// which only shows POs where sent_to_pharmacist_at IS NOT NULL.
// Idempotent by the WHERE clause below: a PO that's already been sent
// can't be "sent" again (affected_rows = 0 the second time), so a
// double-click or a retried request can't produce two notifications or
// overwrite who/when it was actually first sent.
if ($action === 'send_to_pharmacist') {
    $poId = (int) ($body['po_id'] ?? 0);
    if ($poId <= 0) {
        respond(['success' => false, 'error' => 'Missing purchase order.'], 422);
    }
    $stmt = $conn->prepare(
        "UPDATE purchase_orders SET sent_to_pharmacist_at = NOW(), sent_to_pharmacist_by = ?
         WHERE po_id = ? AND status = 'open' AND sent_to_pharmacist_at IS NULL"
    );
    $stmt->bind_param('ii', $capitolId, $poId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected === 0) {
        respond(['success' => false, 'error' => 'This order has already been sent, or no longer exists.'], 409);
    }

    // Best-effort notify every active pharmacist - mirrors the pattern
    // already used for admin-wide notifications elsewhere in this app
    // (e.g. the old cancellation-request flow) rather than requiring
    // Capitol to pick a specific pharmacist by name, since procurement
    // in this hospital isn't scoped to one individual.
    $poNoStmt = $conn->prepare("SELECT po_no FROM purchase_orders WHERE po_id = ?");
    $poNoStmt->bind_param('i', $poId);
    $poNoStmt->execute();
    $poNo = $poNoStmt->get_result()->fetch_assoc()['po_no'] ?? "PO #{$poId}";
    $poNoStmt->close();

    $pharmacistsRes = $conn->query("SELECT user_id FROM users WHERE role = 'pharmacist' AND is_active = 1");
    if ($pharmacistsRes) {
        require_once '../includes/notifications.php';
        while ($pharmacistRow = $pharmacistsRes->fetch_assoc()) {
            create_notification(
                $conn,
                (int) $pharmacistRow['user_id'],
                "Capitol sent {$poNo} - ready to receive once the delivery arrives.",
                '../pharmacist/purchase-orders.php'
            );
        }
    }

    write_audit_log($conn, $capitolId, 'capitol', 'purchase_order_sent_to_pharmacist', 'procurement', "Sent {$poNo} to the pharmacist.");
    respond(['success' => true]);
}

if ($action === 'create') {
    $prId = !empty($body['pr_id']) ? (int) $body['pr_id'] : null;
    $supplierName = trim((string) ($body['supplier_name'] ?? ''));
    $orderDate = trim((string) ($body['order_date'] ?? ''));
    $items = is_array($body['items'] ?? null) ? $body['items'] : [];

    if (strlen($supplierName) > 150) {
        respond(['success' => false, 'error' => 'Supplier name is too long.'], 422);
    }
    if (empty($items)) {
        respond(['success' => false, 'error' => 'Add at least one item.'], 422);
    }
    if (count($items) > 500) {
        respond(['success' => false, 'error' => 'Too many items in one order (500 max). Split it up.'], 422);
    }
    if ($orderDate !== '') {
        $dateObj = DateTime::createFromFormat('Y-m-d', $orderDate);
        if (!$dateObj) {
            respond(['success' => false, 'error' => 'Enter a valid order date.'], 422);
        }
    }
    if ($prId !== null) {
        // Fast-path check only - gives a quick, friendly error before
        // doing all the item validation work below. This is NOT what
        // actually prevents two POs from the same PR under concurrent
        // requests - see the FOR UPDATE re-check inside the transaction
        // further down for the real protection (Remaining Issue #2 from
        // the change report).
        $prCheck = $conn->prepare("SELECT pr_id FROM purchase_requests WHERE pr_id = ? AND status = 'sent_to_capitol'");
        $prCheck->bind_param('i', $prId);
        $prCheck->execute();
        $prRow = $prCheck->get_result()->fetch_assoc();
        $prCheck->close();
        if (!$prRow) {
            respond(['success' => false, 'error' => "That purchase request isn't awaiting action, or has already been converted."], 422);
        }
    }

    $validUnits = [];
    $unitCheckRes = $conn->query("SELECT name FROM medicine_units");
    if ($unitCheckRes) {
        while ($row = $unitCheckRes->fetch_assoc()) {
            $validUnits[strtolower($row['name'])] = true;
        }
    }

    $validated = [];
    foreach ($items as $i => $item) {
        $rowNo = $i + 1;
        $genericName = trim((string) ($item['generic_name'] ?? ''));
        $unit = trim((string) ($item['unit'] ?? ''));
        $qty = (int) ($item['qty_ordered'] ?? 0);
        $brand = trim((string) ($item['brand'] ?? ''));
        $strength = trim((string) ($item['strength'] ?? ''));
        $unitsPerBox = $item['units_per_box'] !== '' && $item['units_per_box'] !== null ? (int) $item['units_per_box'] : null;
        $source = strtolower(trim((string) ($item['funding_source'] ?? '')));
        $medicineId = !empty($item['medicine_id']) ? (int) $item['medicine_id'] : null;
        $priceInput = trim((string) ($item['unit_price'] ?? ''));
        $price = null;

        if ($genericName === '' || $unit === '' || $qty <= 0) {
            respond(['success' => false, 'error' => "Row {$rowNo}: medicine name, unit, and a valid quantity are required."], 422);
        }
        if (strlen($genericName) > 150 || strlen($unit) > 30 || strlen($brand) > 150 || strlen($strength) > 50) {
            respond(['success' => false, 'error' => "Row {$rowNo}: one or more fields exceed the allowed length."], 422);
        }
        if (!isset($validUnits[strtolower($unit)])) {
            respond(['success' => false, 'error' => "Row {$rowNo}: \"{$unit}\" isn't a recognized unit. Choose one from the Medicine Catalog's unit list."], 422);
        }
        if ($source !== '' && !in_array($source, PO_FUNDING_SOURCES, true)) {
            respond(['success' => false, 'error' => "Row {$rowNo}: invalid funding source."], 422);
        }
        if ($priceInput !== '') {
            $price = filter_var($priceInput, FILTER_VALIDATE_FLOAT);
            if ($price === false || $price < 0) {
                respond(['success' => false, 'error' => "Row {$rowNo}: enter a valid unit price."], 422);
            }
        }

        $validated[] = [
            'medicine_id' => $medicineId,
            'generic_name' => $genericName,
            'brand' => $brand !== '' ? $brand : null,
            'strength' => $strength !== '' ? $strength : null,
            'unit' => $unit,
            'units_per_box' => $unitsPerBox,
            'qty_ordered' => $qty,
            'unit_price' => $price,
            'funding_source' => $source !== '' ? $source : null,
        ];
    }

    $conn->begin_transaction();
    try {
        // FIXED 2026-09-20 (Remaining Issue #2 from the change report):
        // the pre-check above (before this transaction even opened) was
        // the ONLY thing stopping two POs from being created off the
        // same PR - two near-simultaneous requests could both pass that
        // early check before either of them reached the UPDATE at the
        // bottom of this block. Re-checking here, inside the
        // transaction, with FOR UPDATE, closes that window: FOR UPDATE
        // takes a row lock that a second concurrent request's own
        // SELECT ... FOR UPDATE on the same pr_id has to wait on, so it
        // can only proceed once this transaction has fully committed or
        // rolled back - at which point it sees the TRUE current status
        // (already 'converted' if this request won the race), not a
        // stale read from before either request wrote anything.
        if ($prId !== null) {
            $prLockStmt = $conn->prepare("SELECT pr_id FROM purchase_requests WHERE pr_id = ? AND status = 'sent_to_capitol' FOR UPDATE");
            $prLockStmt->bind_param('i', $prId);
            $prLockStmt->execute();
            $stillSendable = (bool) $prLockStmt->get_result()->fetch_assoc();
            $prLockStmt->close();
            if (!$stillSendable) {
                throw new RuntimeException("That purchase request isn't awaiting action, or has already been converted.");
            }
        }

        $year = date('Y');
        $countStmt = $conn->prepare("SELECT COUNT(*) AS c FROM purchase_orders WHERE po_no LIKE ?");
        $likePattern = "PO-{$year}-%";
        $countStmt->bind_param('s', $likePattern);
        $countStmt->execute();
        $count = (int) $countStmt->get_result()->fetch_assoc()['c'];
        $countStmt->close();
        $poNo = sprintf('PO-%s-%04d', $year, $count + 1);

        $supplierParam = $supplierName !== '' ? $supplierName : null;
        $orderDateParam = $orderDate !== '' ? $orderDate : null;

        $insertPo = $conn->prepare(
            "INSERT INTO purchase_orders (po_no, pr_id, supplier_name, order_date, status, created_by, created_at)
             VALUES (?, ?, ?, ?, 'open', ?, NOW())"
        );
        $insertPo->bind_param('sissi', $poNo, $prId, $supplierParam, $orderDateParam, $capitolId);
        $insertPo->execute();
        $poId = $insertPo->insert_id;
        $insertPo->close();

        $insertItem = $conn->prepare(
            'INSERT INTO purchase_order_items
                (po_id, medicine_id, generic_name, brand, strength, unit, units_per_box, qty_ordered, unit_price_estimated, funding_source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($validated as $item) {
            $insertItem->bind_param(
                'iissssiids',
                $poId,
                $item['medicine_id'],
                $item['generic_name'],
                $item['brand'],
                $item['strength'],
                $item['unit'],
                $item['units_per_box'],
                $item['qty_ordered'],
                $item['unit_price'],
                $item['funding_source']
            );
            $insertItem->execute();
        }
        $insertItem->close();

        if ($prId !== null) {
            // AND status = 'sent_to_capitol' here is defense-in-depth,
            // not the actual protection (the FOR UPDATE lock above
            // already guarantees this can't race) - same "trust the
            // WHERE clause, not just an earlier read" pattern this app
            // uses on every other status-gated write.
            $updatePr = $conn->prepare("UPDATE purchase_requests SET status = 'converted' WHERE pr_id = ? AND status = 'sent_to_capitol'");
            $updatePr->bind_param('i', $prId);
            $updatePr->execute();
            $updatePr->close();
        }

        $conn->commit();
    } catch (RuntimeException $e) {
        $conn->rollback();
        respond(['success' => false, 'error' => $e->getMessage()], 409);
    } catch (Throwable $e) {
        $conn->rollback();
        respond(['success' => false, 'error' => 'Could not save this purchase order. Please try again.'], 500);
    }

    write_audit_log($conn, $capitolId, 'capitol', 'purchase_order_created', 'procurement', "Created {$poNo} with " . count($validated) . ' item(s).');
    respond(['success' => true, 'po_id' => $poId, 'po_no' => $poNo]);
}

respond(['success' => false, 'error' => 'Unknown action.'], 400);
