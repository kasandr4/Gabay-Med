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
require_once '../includes/xlsx_helper.php';

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

const PR_FUNDING_SOURCES = ['maip', 'philhealth', 'pho', 'purchase_order', 'donated'];
// Every purchase request line is funded by Purchase Order. The form has no
// funding-source field any more; create/update below always store this,
// whatever a client sends, and pr-stock-in-actions.php credits the stock
// batch with the same source.
const PR_FUNDING_SOURCE = 'purchase_order';
// Estimated Unit Price sits in the same position PO_TEMPLATE_HEADER
// already uses (purchase-order-actions.php) -- right after the quantity
// column -- so Reorder Insights' export (which follows this same header
// shape) round-trips its price into the PR grid the same way it already
// round-trips quantity.
const PR_TEMPLATE_HEADER = ['Generic Name', 'Brand', 'Strength', 'Unit', 'Units per Box', 'Quantity Requested', 'Estimated Unit Price', 'Funding Source', 'Notes'];

// GET: template download (not JSON, so it's handled before the JSON/CSRF checks below)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'download_template') {
    csv_download('purchase_request_template.csv', PR_TEMPLATE_HEADER, [
        ['Amoxicillin', '', '500mg', 'Capsule', '100', '20', '2.50', 'purchase_order', 'e.g. for Ward 2 restock'],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Invalid request method.'], 405);
}

// The CSV-parse action is multipart/form-data (file upload); everything
// else is JSON. Detect which one we got.
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
    // Accept either a plain CSV (from the downloadable template) or the
    // official OMCDH PO/PR .xlsx -- so the pharmacist can upload the
    // exact document the hospital already fills out, without a separate
    // re-entry step. Both parsers return the same row shape, so
    // everything below this point is identical either way.
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
        $lineNo = $index + 2; // +1 for header, +1 for 1-indexing
        $genericName = trim($row['generic_name'] ?? '');
        $qty = (int) ($row['quantity_requested'] ?? 0);
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
        if ($source !== '' && !in_array($source, PR_FUNDING_SOURCES, true)) {
            $warnings[] = "Row {$lineNo}: unrecognized funding source \"{$source}\", left blank";
            $source = '';
        }
        // Same validate-or-blank handling as purchase-order-actions.php's
        // own 'estimated_unit_price' column -- an unparseable or negative
        // value doesn't fail the row, it just leaves price for the
        // pharmacist to fill in on the grid.
        $priceInput = trim($row['estimated_unit_price'] ?? '');
        $price = '';
        if ($priceInput !== '') {
            $parsedPrice = filter_var($priceInput, FILTER_VALIDATE_FLOAT);
            if ($parsedPrice === false || $parsedPrice < 0) {
                $warnings[] = "Row {$lineNo}: invalid unit price, left blank";
            } else {
                $price = $parsedPrice;
            }
        }
        $items[] = [
            'generic_name' => $genericName,
            'brand' => trim($row['brand'] ?? ''),
            'strength' => trim($row['strength'] ?? ''),
            'unit' => trim($row['unit'] ?? ''),
            'units_per_box' => trim($row['units_per_box'] ?? ''),
            'qty_requested' => $qty,
            'unit_price_estimated' => $price,
            'funding_source' => $source,
            'notes' => trim($row['notes'] ?? ''),
            'medicine_id' => null,
        ];
    }

    if (empty($items)) {
        respond(['success' => false, 'error' => 'No valid rows found in that file. Check the template columns and try again.'], 422);
    }

    respond(['success' => true, 'items' => $items, 'warnings' => $warnings]);
}

if ($action === 'cancel') {
    $prId = (int) ($body['pr_id'] ?? 0);
    if ($prId <= 0) {
        respond(['success' => false, 'error' => 'Missing purchase request.'], 422);
    }
    $stmt = $conn->prepare("UPDATE purchase_requests SET status = 'cancelled' WHERE pr_id = ? AND status = 'open'");
    $stmt->bind_param('i', $prId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected === 0) {
        respond(['success' => false, 'error' => 'This request can no longer be cancelled.'], 409);
    }
    write_audit_log($conn, $pharmacistId, 'pharmacist', 'purchase_request_cancelled', 'procurement', "Cancelled purchase request {$prId}.");
    respond(['success' => true]);
}

// Approve / Reject are done by the logged-in Head Pharmacist. Capitol
// hand-off ('mark_sent') was removed: the approved PR is printed or
// exported as CSV and forwarded manually, outside GabayMed.

if ($action === 'approve') {
    // Approval is now done by the logged-in Head Pharmacist themselves
    // (see 031_pr_staff_restock.sql). The old typed "Chief of Hospital"
    // name and back-dated approval date are gone: the approver is the
    // session user and the timestamp is the moment of approval.
    $prId = (int) ($body['pr_id'] ?? 0);

    if ($prId <= 0) {
        respond(['success' => false, 'error' => 'Missing purchase request.'], 422);
    }

    $nameStmt = $conn->prepare("SELECT TRIM(CONCAT(first_name, ' ', last_name)) AS full_name FROM users WHERE user_id = ?");
    $nameStmt->bind_param('i', $pharmacistId);
    $nameStmt->execute();
    $nameRow = $nameStmt->get_result()->fetch_assoc();
    $nameStmt->close();
    $approvedByName = trim((string) ($nameRow['full_name'] ?? ''));
    if ($approvedByName === '') {
        respond(['success' => false, 'error' => 'Could not determine the approving pharmacist.'], 500);
    }

    $stmt = $conn->prepare(
        "UPDATE purchase_requests
         SET status = 'approved', approved_by = ?, approved_by_name = ?, approved_at = NOW()
         WHERE pr_id = ? AND status = 'open'"
    );
    $stmt->bind_param('isi', $pharmacistId, $approvedByName, $prId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected === 0) {
        respond(['success' => false, 'error' => 'This request is no longer open for approval.'], 409);
    }
    write_audit_log($conn, $pharmacistId, 'pharmacist', 'purchase_request_approved', 'procurement', "Approved PR {$prId} (approved by {$approvedByName}).");
    respond(['success' => true]);
}

if ($action === 'reject') {
    $prId = (int) ($body['pr_id'] ?? 0);
    $reason = trim((string) ($body['rejection_reason'] ?? ''));

    if ($prId <= 0) {
        respond(['success' => false, 'error' => 'Missing purchase request.'], 422);
    }
    if ($reason === '') {
        respond(['success' => false, 'error' => 'Enter a reason for rejection.'], 422);
    }
    if (strlen($reason) > 255) {
        respond(['success' => false, 'error' => 'Reason is too long.'], 422);
    }

    $stmt = $conn->prepare(
        "UPDATE purchase_requests SET status = 'rejected', rejected_at = NOW(), rejection_reason = ? WHERE pr_id = ? AND status = 'open'"
    );
    $stmt->bind_param('si', $reason, $prId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected === 0) {
        respond(['success' => false, 'error' => 'This request is no longer open for review.'], 409);
    }
    write_audit_log($conn, $pharmacistId, 'pharmacist', 'purchase_request_rejected', 'procurement', "Logged Hospital rejection for PR {$prId}: {$reason}");
    respond(['success' => true]);
}

if ($action === 'create') {
    $purpose = trim((string) ($body['purpose'] ?? ''));
    $notes = trim((string) ($body['notes'] ?? ''));
    $items = is_array($body['items'] ?? null) ? $body['items'] : [];

    if (strlen($purpose) > 255) {
        respond(['success' => false, 'error' => 'Purpose is too long.'], 422);
    }
    if (empty($items)) {
        respond(['success' => false, 'error' => 'Add at least one item.'], 422);
    }
    if (count($items) > 500) {
        respond(['success' => false, 'error' => 'Too many items in one request (500 max). Split it up.'], 422);
    }

    // Controlled vocabulary -- loaded once, checked per row below. The
    // frontend already renders Unit as a <select> dropdown built from
    // this table, but a request can reach this endpoint without going
    // through that UI (direct API call, or a stale/edited page), so the
    // server re-checks independently. This mirrors the same check in
    // record-stock-batch-actions.php.
    $validUnits = [];
    $unitRes = $conn->query("SELECT name FROM medicine_units");
    if ($unitRes) {
        while ($row = $unitRes->fetch_assoc()) {
            $validUnits[strtolower($row['name'])] = true;
        }
    }

    $validated = [];
    foreach ($items as $i => $item) {
        $rowNo = $i + 1;
        $genericName = trim((string) ($item['generic_name'] ?? ''));
        $unit = trim((string) ($item['unit'] ?? ''));
        $qty = (int) ($item['qty_requested'] ?? 0);
        $brand = trim((string) ($item['brand'] ?? ''));
        $strength = trim((string) ($item['strength'] ?? ''));
        $unitsPerBox = $item['units_per_box'] !== '' && $item['units_per_box'] !== null ? (int) $item['units_per_box'] : null;
        $unitPriceEstimated = isset($item['unit_price_estimated']) && $item['unit_price_estimated'] !== '' && $item['unit_price_estimated'] !== null
            ? (float) $item['unit_price_estimated']
            : null;
        $itemNotes = trim((string) ($item['notes'] ?? ''));
        $medicineId = !empty($item['medicine_id']) ? (int) $item['medicine_id'] : null;

        // Unit is optional at the PR stage -- a PR is just "we need
        // this," not a commitment, so it shouldn't force the full
        // catalog-ready spec before the pharmacist even knows if this
        // will be ordered. It's still validated against the controlled
        // list when given (so a bad value can't sneak through), and
        // becomes mandatory once this is converted to a Purchase Order
        // -- see the same check in purchase-order-actions.php, which is
        // the point this actually needs to be catalog-ready.
        if ($genericName === '' || $qty <= 0) {
            respond(['success' => false, 'error' => "Row {$rowNo}: medicine name and a valid quantity are required."], 422);
        }
        if (strlen($genericName) > 150 || strlen($unit) > 30 || strlen($brand) > 150 || strlen($strength) > 50 || strlen($itemNotes) > 255) {
            respond(['success' => false, 'error' => "Row {$rowNo}: one or more fields exceed the allowed length."], 422);
        }
        if ($unit !== '' && !isset($validUnits[strtolower($unit)])) {
            respond(['success' => false, 'error' => "Row {$rowNo}: \"{$unit}\" isn't a recognized unit. Choose one from the Medicine Catalog's unit list."], 422);
        }
        if ($unitPriceEstimated !== null && $unitPriceEstimated < 0) {
            respond(['success' => false, 'error' => "Row {$rowNo}: estimated unit price can't be negative."], 422);
        }

        $validated[] = [
            'medicine_id' => $medicineId,
            'generic_name' => $genericName,
            'brand' => $brand !== '' ? $brand : null,
            'strength' => $strength !== '' ? $strength : null,
            'unit' => $unit,
            'units_per_box' => $unitsPerBox,
            'qty_requested' => $qty,
            'unit_price_estimated' => $unitPriceEstimated,
            'funding_source' => PR_FUNDING_SOURCE,
            'notes' => $itemNotes !== '' ? $itemNotes : null,
        ];
    }

    $conn->begin_transaction();
    try {
        $year = date('Y');
        $countStmt = $conn->prepare("SELECT COUNT(*) AS c FROM purchase_requests WHERE pr_no LIKE ?");
        $likePattern = "PR-{$year}-%";
        $countStmt->bind_param('s', $likePattern);
        $countStmt->execute();
        $count = (int) $countStmt->get_result()->fetch_assoc()['c'];
        $countStmt->close();
        $prNo = sprintf('PR-%s-%04d', $year, $count + 1);

        $purposeParam = $purpose !== '' ? $purpose : null;
        $notesParam = $notes !== '' ? $notes : null;
        $insertPr = $conn->prepare(
            'INSERT INTO purchase_requests (pr_no, requested_by, purpose, notes, status, created_at)
             VALUES (?, ?, ?, ?, \'open\', NOW())'
        );
        $insertPr->bind_param('siss', $prNo, $pharmacistId, $purposeParam, $notesParam);
        $insertPr->execute();
        $prId = $insertPr->insert_id;
        $insertPr->close();

        $insertItem = $conn->prepare(
            'INSERT INTO purchase_request_items
                (pr_id, medicine_id, generic_name, brand, strength, unit, units_per_box, qty_requested, unit_price_estimated, funding_source, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($validated as $item) {
            $insertItem->bind_param(
                'iissssiidss',
                $prId,
                $item['medicine_id'],
                $item['generic_name'],
                $item['brand'],
                $item['strength'],
                $item['unit'],
                $item['units_per_box'],
                $item['qty_requested'],
                $item['unit_price_estimated'],
                $item['funding_source'],
                $item['notes']
            );
            $insertItem->execute();
        }
        $insertItem->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        respond(['success' => false, 'error' => 'Could not save this purchase request. Please try again.'], 500);
    }

    write_audit_log($conn, $pharmacistId, 'pharmacist', 'purchase_request_created', 'procurement', "Created {$prNo} with " . count($validated) . ' item(s).');
    respond(['success' => true, 'pr_id' => $prId, 'pr_no' => $prNo]);
}

// NEW 2026-09-20 (spec item 2 - "Edit a draft PR" was listed as a
// pharmacist capability but no action ever existed for it; a PR could
// only ever be created once and then moved forward through status
// transitions). Same validation as 'create' above, but replaces this
// PR's existing items wholesale (delete + reinsert) rather than
// inserting a new PR - and only while status is still 'open'. Once
// approved/sent/converted, this correctly falls through to "Unknown
// action" territory in the sense that it's rejected with a clear error,
// not a silent no-op - locking the fields "from casual editing after
// submission" per the spec, without needing a second code path to
// enforce it.
if ($action === 'update') {
    $prId = (int) ($body['pr_id'] ?? 0);
    $purpose = trim((string) ($body['purpose'] ?? ''));
    $notes = trim((string) ($body['notes'] ?? ''));
    $items = is_array($body['items'] ?? null) ? $body['items'] : [];

    if ($prId <= 0) {
        respond(['success' => false, 'error' => 'Missing purchase request.'], 422);
    }
    if (strlen($purpose) > 255) {
        respond(['success' => false, 'error' => 'Purpose is too long.'], 422);
    }
    if (empty($items)) {
        respond(['success' => false, 'error' => 'Add at least one item.'], 422);
    }
    if (count($items) > 500) {
        respond(['success' => false, 'error' => 'Too many items in one request (500 max). Split it up.'], 422);
    }

    $prCheck = $conn->prepare("SELECT pr_id FROM purchase_requests WHERE pr_id = ? AND status = 'open'");
    $prCheck->bind_param('i', $prId);
    $prCheck->execute();
    $prRow = $prCheck->get_result()->fetch_assoc();
    $prCheck->close();
    if (!$prRow) {
        respond(['success' => false, 'error' => "This request can no longer be edited — it's already moved past the draft stage."], 409);
    }

    $validUnits = [];
    $unitRes = $conn->query("SELECT name FROM medicine_units");
    if ($unitRes) {
        while ($row = $unitRes->fetch_assoc()) {
            $validUnits[strtolower($row['name'])] = true;
        }
    }

    $validated = [];
    foreach ($items as $i => $item) {
        $rowNo = $i + 1;
        $genericName = trim((string) ($item['generic_name'] ?? ''));
        $unit = trim((string) ($item['unit'] ?? ''));
        $qty = (int) ($item['qty_requested'] ?? 0);
        $brand = trim((string) ($item['brand'] ?? ''));
        $strength = trim((string) ($item['strength'] ?? ''));
        $unitsPerBox = $item['units_per_box'] !== '' && $item['units_per_box'] !== null ? (int) $item['units_per_box'] : null;
        $unitPriceEstimated = isset($item['unit_price_estimated']) && $item['unit_price_estimated'] !== '' && $item['unit_price_estimated'] !== null
            ? (float) $item['unit_price_estimated']
            : null;
        $itemNotes = trim((string) ($item['notes'] ?? ''));
        $medicineId = !empty($item['medicine_id']) ? (int) $item['medicine_id'] : null;

        if ($genericName === '' || $qty <= 0) {
            respond(['success' => false, 'error' => "Row {$rowNo}: medicine name and a valid quantity are required."], 422);
        }
        if (strlen($genericName) > 150 || strlen($unit) > 30 || strlen($brand) > 150 || strlen($strength) > 50 || strlen($itemNotes) > 255) {
            respond(['success' => false, 'error' => "Row {$rowNo}: one or more fields exceed the allowed length."], 422);
        }
        if ($unit !== '' && !isset($validUnits[strtolower($unit)])) {
            respond(['success' => false, 'error' => "Row {$rowNo}: \"{$unit}\" isn't a recognized unit. Choose one from the Medicine Catalog's unit list."], 422);
        }
        if ($unitPriceEstimated !== null && $unitPriceEstimated < 0) {
            respond(['success' => false, 'error' => "Row {$rowNo}: estimated unit price can't be negative."], 422);
        }

        $validated[] = [
            'medicine_id' => $medicineId,
            'generic_name' => $genericName,
            'brand' => $brand !== '' ? $brand : null,
            'strength' => $strength !== '' ? $strength : null,
            'unit' => $unit,
            'units_per_box' => $unitsPerBox,
            'qty_requested' => $qty,
            'unit_price_estimated' => $unitPriceEstimated,
            'funding_source' => PR_FUNDING_SOURCE,
            'notes' => $itemNotes !== '' ? $itemNotes : null,
        ];
    }

    $conn->begin_transaction();
    try {
        $purposeParam = $purpose !== '' ? $purpose : null;
        $notesParam = $notes !== '' ? $notes : null;

        // Re-check status inside the transaction, immediately before
        // writing - narrows (doesn't eliminate) the race between the
        // pre-check above and this write. Same level of protection the
        // rest of this file's status-gated writes rely on (cancel/
        // approve/reject/mark_sent all gate the UPDATE's WHERE clause
        // itself rather than a separate lock) - not using UPDATE's own
        // affected_rows for this check because a no-op edit (items
        // changed, purpose/notes text identical) would misreport 0 rows
        // affected even though the row genuinely matched.
        $recheckStmt = $conn->prepare("SELECT pr_id FROM purchase_requests WHERE pr_id = ? AND status = 'open'");
        $recheckStmt->bind_param('i', $prId);
        $recheckStmt->execute();
        $stillOpen = (bool) $recheckStmt->get_result()->fetch_assoc();
        $recheckStmt->close();
        if (!$stillOpen) {
            throw new RuntimeException("This request can no longer be edited — it's already moved past the draft stage.");
        }

        $updatePr = $conn->prepare("UPDATE purchase_requests SET purpose = ?, notes = ?, modified_by = ?, modified_at = NOW() WHERE pr_id = ?");
        $updatePr->bind_param('ssii', $purposeParam, $notesParam, $pharmacistId, $prId);
        $updatePr->execute();
        $updatePr->close();

        $deleteItems = $conn->prepare("DELETE FROM purchase_request_items WHERE pr_id = ?");
        $deleteItems->bind_param('i', $prId);
        $deleteItems->execute();
        $deleteItems->close();

        $insertItem = $conn->prepare(
            'INSERT INTO purchase_request_items
                (pr_id, medicine_id, generic_name, brand, strength, unit, units_per_box, qty_requested, unit_price_estimated, funding_source, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($validated as $item) {
            $insertItem->bind_param(
                'iissssiidss',
                $prId,
                $item['medicine_id'],
                $item['generic_name'],
                $item['brand'],
                $item['strength'],
                $item['unit'],
                $item['units_per_box'],
                $item['qty_requested'],
                $item['unit_price_estimated'],
                $item['funding_source'],
                $item['notes']
            );
            $insertItem->execute();
        }
        $insertItem->close();

        $conn->commit();
    } catch (RuntimeException $e) {
        $conn->rollback();
        respond(['success' => false, 'error' => $e->getMessage()], 409);
    } catch (Throwable $e) {
        $conn->rollback();
        respond(['success' => false, 'error' => 'Could not save these changes. Please try again.'], 500);
    }

    write_audit_log($conn, $pharmacistId, 'pharmacist', 'purchase_request_updated', 'procurement', "Modified PR {$prId} (" . count($validated) . ' item(s)).');
    respond(['success' => true, 'pr_id' => $prId]);
}

respond(['success' => false, 'error' => 'Unknown action.'], 400);
