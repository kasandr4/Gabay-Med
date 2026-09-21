<?php
// pharmacist/pr-stock-in-actions.php
//
// Backend for the "From Approved PR" tab on record-stock-batch.php.
//
// PHASE 5: stock is received against the APPROVED PURCHASE REQUEST. The
// Purchase Order step is gone from the OMCDH workflow (the approved PR
// is printed/exported and forwarded manually, and the Provincial
// Capitol delivers against it), so there is no PO to receive against.
// This is the PR-based replacement for po-stock-in-actions.php, which is
// deliberately left untouched.
//
// What it does, in one sentence: the pharmacist enters what was actually
// delivered for each PR line (plus batch / expiry / prices, all
// optional), and CONFIRM is the only place stock changes -- one
// transaction that adds a FEFO batch per delivered line, raises
// inventory_medicines.current_stock, and records the variance against
// qty_requested.
//
// Rules enforced here (server-side, not just hidden UI):
//   * Only a PR with status 'approved' can be received.
//   * One receiving per PR. Confirming closes it (received_at is set);
//     a second confirm is refused. A shortfall is recorded as variance --
//     there is no back-order / second delivery.
//   * A PR that already has a non-cancelled Purchase Order is refused, so
//     the same delivery can't be stocked in twice (once here, once via
//     po-receiving.php). PRs that predate this phase and were turned
//     into a PO stay on the old PO path.
//   * Quantities, expiry dates and prices are re-validated on the
//     server; at least one line must have a delivered quantity above 0.
//
// Stock crediting reuses includes/inventory_helpers.php
// (find_or_create_medicine + credit_medicine_batch) -- the same two
// helpers Record Stock Batch and po-receiving-actions.php use -- so
// there is exactly one implementation of "how stock actually gets
// credited".
//
// The request's own `notes` column is NOT written to. It holds the note
// printed on the PR; receiving records live in the qty_received /
// variance / received_* columns added by 032_pr_receiving.sql.
ob_start();
require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/audit_log.php';
require_once '../includes/excel_helper.php';
require_once '../includes/inventory_helpers.php';

$pharmacistId = (int) $_SESSION['user_id'];

const PR_STOCK_IN_TEMPLATE_HEADER = ['PR Item ID', 'Generic Name', 'Brand', 'Strength', 'Unit', 'Qty Requested', 'Qty Delivered', 'Batch No', 'Expiry Date (YYYY-MM-DD)', 'Unit Price', 'Selling Price'];
const PR_STOCK_IN_MAX_QTY = 10000000;
const PR_STOCK_IN_MAX_PRICE = 99999999.99;

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

/**
 * "Short by 2 Box vs PR" / "Excess of 2 Box vs PR" / "Matches PR request".
 * The unit is optional at the PR stage, so a blank one is simply left out
 * rather than leaving a double space.
 */
function pr_variance_note(int $variance, string $unit): string
{
    $unit = trim($unit);
    $unitPart = $unit !== '' ? ' ' . $unit : '';
    if ($variance < 0) {
        return 'Short by ' . abs($variance) . $unitPart . ' vs PR';
    }
    if ($variance > 0) {
        return 'Excess of ' . $variance . $unitPart . ' vs PR';
    }
    return 'Matches PR request';
}

/** Strict Y-m-d check: rejects things like 2026-02-31 that DateTime silently rolls over. */
function pr_valid_ymd(string $value): bool
{
    $date = DateTime::createFromFormat('Y-m-d', $value);
    if (!$date) {
        return false;
    }
    $parts = explode('-', $value);
    return count($parts) === 3 && checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
}

/**
 * Loads a PR and works out whether it can be received right now.
 *
 * @return array{pr: ?array, po_no: ?string, error: ?string, code: int}
 *   error is null when the PR is receivable; otherwise it says why not,
 *   and code is the HTTP status to answer with.
 */
function pr_receiving_state(mysqli $conn, int $prId, bool $lock = false): array
{
    $sql = 'SELECT pr_id, pr_no, status, received_at FROM purchase_requests WHERE pr_id = ?' . ($lock ? ' FOR UPDATE' : '');
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $prId);
    $stmt->execute();
    $pr = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$pr) {
        return ['pr' => null, 'po_no' => null, 'error' => 'Purchase request not found.', 'code' => 404];
    }
    if ($pr['received_at'] !== null) {
        return ['pr' => $pr, 'po_no' => null, 'error' => 'Stock has already been recorded for this purchase request.', 'code' => 409];
    }
    if ($pr['status'] !== 'approved') {
        return ['pr' => $pr, 'po_no' => null, 'error' => 'Only an approved purchase request can be received.', 'code' => 409];
    }

    $poStmt = $conn->prepare("SELECT po_no FROM purchase_orders WHERE pr_id = ? AND status <> 'cancelled' ORDER BY po_id DESC LIMIT 1");
    $poStmt->bind_param('i', $prId);
    $poStmt->execute();
    $po = $poStmt->get_result()->fetch_assoc();
    $poStmt->close();
    if ($po) {
        return [
            'pr' => $pr,
            'po_no' => $po['po_no'],
            'error' => "This request already has {$po['po_no']}. Receive it from Purchase Orders instead, so the same delivery isn't stocked in twice.",
            'code' => 409,
        ];
    }

    return ['pr' => $pr, 'po_no' => null, 'error' => null, 'code' => 200];
}

// ---------------------------------------------------------------------
// GET: pre-filled template download
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'download_template') {
    $prId = (int) ($_GET['pr_id'] ?? 0);
    if ($prId <= 0) {
        respond(['success' => false, 'error' => 'Missing purchase request.'], 422);
    }
    $state = pr_receiving_state($conn, $prId);
    if ($state['error'] !== null) {
        respond(['success' => false, 'error' => $state['error']], $state['code']);
    }

    $stmt = $conn->prepare(
        'SELECT pri_id, generic_name, brand, strength, unit, qty_requested, unit_price_estimated
         FROM purchase_request_items WHERE pr_id = ? ORDER BY pri_id ASC'
    );
    $stmt->bind_param('i', $prId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            $row['pri_id'],
            $row['generic_name'],
            $row['brand'],
            $row['strength'],
            $row['unit'],
            $row['qty_requested'],
            $row['qty_requested'], // Qty Delivered pre-filled with Qty Requested; edit if it differs
            '',
            '',
            $row['unit_price_estimated'],
            '',
        ];
    }
    $stmt->close();
    csv_download('stock_in_' . $state['pr']['pr_no'] . '.csv', PR_STOCK_IN_TEMPLATE_HEADER, $rows);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Invalid request method.'], 405);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isMultipart = stripos($contentType, 'multipart/form-data') !== false;

$body = [];
if ($isMultipart) {
    $action = $_POST['action'] ?? '';
    $submittedToken = $_POST['csrf_token'] ?? '';
    $prId = (int) ($_POST['pr_id'] ?? 0);
} else {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?: [];
    $action = $body['action'] ?? '';
    $submittedToken = $body['csrf_token'] ?? '';
    $prId = (int) ($body['pr_id'] ?? 0);
}

$expectedToken = $_SESSION['csrf_token'] ?? '';
if ($expectedToken === '' || !hash_equals($expectedToken, (string) $submittedToken)) {
    respond(['success' => false, 'error' => 'Your session expired. Please refresh the page and try again.'], 403);
}

if ($prId <= 0) {
    respond(['success' => false, 'error' => 'Missing purchase request.'], 422);
}

// ---------------------------------------------------------------------
// parse_upload: read a completed sheet back. Changes nothing.
// ---------------------------------------------------------------------
if ($action === 'parse_upload') {
    $state = pr_receiving_state($conn, $prId);
    if ($state['error'] !== null) {
        respond(['success' => false, 'error' => $state['error']], $state['code']);
    }

    try {
        $rows = csv_parse_upload('csv_file');
    } catch (RuntimeException $e) {
        respond(['success' => false, 'error' => $e->getMessage()], 422);
    }

    // Only accept rows referencing items that actually belong to this PR.
    $validPriIds = [];
    $stmt = $conn->prepare('SELECT pri_id FROM purchase_request_items WHERE pr_id = ?');
    $stmt->bind_param('i', $prId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $validPriIds[(int) $row['pri_id']] = true;
    }
    $stmt->close();

    $items = [];
    $warnings = [];
    $seen = [];
    foreach ($rows as $index => $row) {
        $lineNo = $index + 2; // +1 for header, +1 for 1-indexing
        $priId = (int) ($row['pr_item_id'] ?? 0);
        if ($priId <= 0 || !isset($validPriIds[$priId])) {
            $warnings[] = "Row {$lineNo}: PR Item ID doesn't match this request, skipped";
            continue;
        }
        if (isset($seen[$priId])) {
            $warnings[] = "Row {$lineNo}: this PR Item ID appears more than once, skipped";
            continue;
        }
        $qtyRaw = trim((string) ($row['qty_delivered'] ?? ''));
        if ($qtyRaw === '' || !ctype_digit($qtyRaw) || (int) $qtyRaw > PR_STOCK_IN_MAX_QTY) {
            $warnings[] = "Row {$lineNo}: Qty Delivered must be a whole number (0 or more), skipped";
            continue;
        }
        $expiry = trim((string) ($row['expiry_date_yyyy_mm_dd'] ?? ''));
        if ($expiry !== '' && !pr_valid_ymd($expiry)) {
            $warnings[] = "Row {$lineNo}: invalid expiry date format, left blank";
            $expiry = '';
        }
        $batchNo = trim((string) ($row['batch_no'] ?? ''));
        if (strlen($batchNo) > 100) {
            $warnings[] = "Row {$lineNo}: batch number is too long, left blank";
            $batchNo = '';
        }
        $prices = [];
        foreach (['unit_price' => 'unit price', 'selling_price' => 'selling price'] as $key => $label) {
            $input = trim((string) ($row[$key] ?? ''));
            $prices[$key] = '';
            if ($input !== '') {
                $parsed = filter_var($input, FILTER_VALIDATE_FLOAT);
                if ($parsed === false || $parsed < 0 || $parsed > PR_STOCK_IN_MAX_PRICE) {
                    $warnings[] = "Row {$lineNo}: invalid {$label}, left blank";
                } else {
                    $prices[$key] = $parsed;
                }
            }
        }
        $seen[$priId] = true;
        $items[] = [
            'pri_id' => $priId,
            'qty_delivered' => (int) $qtyRaw,
            'batch_no' => $batchNo,
            'expiry_date' => $expiry,
            'unit_price' => $prices['unit_price'],
            'selling_price' => $prices['selling_price'],
        ];
    }

    if (empty($items)) {
        respond(['success' => false, 'error' => 'No valid rows found. Make sure you uploaded the sheet downloaded for this request.'], 422);
    }

    respond(['success' => true, 'items' => $items, 'warnings' => $warnings]);
}

// ---------------------------------------------------------------------
// confirm: the ONLY place stock changes
// ---------------------------------------------------------------------
if ($action === 'confirm') {
    // Cheap early exit before doing any validation work. This is repeated
    // below under a row lock, which is the check that actually counts.
    $early = pr_receiving_state($conn, $prId);
    if ($early['error'] !== null) {
        respond(['success' => false, 'error' => $early['error']], $early['code']);
    }

    $submitted = is_array($body['items'] ?? null) ? $body['items'] : [];
    if (empty($submitted)) {
        respond(['success' => false, 'error' => 'No items to confirm.'], 422);
    }
    if (count($submitted) > 500) {
        respond(['success' => false, 'error' => 'Too many items in one request.'], 422);
    }

    // Controlled unit list, used only for the rare line that has neither a
    // catalog medicine nor a unit on the PR (unit is optional at the PR
    // stage) and so needs one chosen here before it can enter the catalog.
    $validUnits = [];
    $unitRes = $conn->query('SELECT name FROM medicine_units');
    if ($unitRes) {
        while ($unitRow = $unitRes->fetch_assoc()) {
            $validUnits[strtolower($unitRow['name'])] = $unitRow['name'];
        }
    }

    $validated = [];
    foreach ($submitted as $i => $line) {
        $rowNo = $i + 1;
        $priId = (int) ($line['pri_id'] ?? 0);
        if ($priId <= 0) {
            respond(['success' => false, 'error' => "Row {$rowNo}: missing item."], 422);
        }
        if (isset($validated[$priId])) {
            respond(['success' => false, 'error' => "Row {$rowNo}: the same item was submitted twice."], 422);
        }

        $qty = filter_var(trim((string) ($line['qty_delivered'] ?? '')), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => PR_STOCK_IN_MAX_QTY]]);
        if ($qty === false) {
            respond(['success' => false, 'error' => "Row {$rowNo}: enter a whole-number delivered quantity (0 or more)."], 422);
        }

        $batchNo = trim((string) ($line['batch_no'] ?? ''));
        if (strlen($batchNo) > 100) {
            respond(['success' => false, 'error' => "Row {$rowNo}: batch number is too long."], 422);
        }

        $expiryDate = trim((string) ($line['expiry_date'] ?? ''));
        if ($expiryDate !== '' && !pr_valid_ymd($expiryDate)) {
            respond(['success' => false, 'error' => "Row {$rowNo}: enter a valid expiry date."], 422);
        }

        $prices = [];
        foreach (['unit_price' => 'unit price', 'selling_price' => 'selling price'] as $key => $label) {
            $input = trim((string) ($line[$key] ?? ''));
            $prices[$key] = null;
            if ($input !== '') {
                $parsed = filter_var($input, FILTER_VALIDATE_FLOAT);
                if ($parsed === false || $parsed < 0 || $parsed > PR_STOCK_IN_MAX_PRICE) {
                    respond(['success' => false, 'error' => "Row {$rowNo}: enter a valid {$label}."], 422);
                }
                $prices[$key] = round($parsed, 2);
            }
        }

        $chosenUnit = trim((string) ($line['unit'] ?? ''));
        if ($chosenUnit !== '' && !isset($validUnits[strtolower($chosenUnit)])) {
            respond(['success' => false, 'error' => "Row {$rowNo}: choose a unit from the controlled list."], 422);
        }

        $validated[$priId] = [
            'qty' => $qty,
            'batch_no' => $batchNo !== '' ? $batchNo : null,
            'expiry_date' => $expiryDate !== '' ? $expiryDate : null,
            'unit_price' => $prices['unit_price'],
            'selling_price' => $prices['selling_price'],
            'unit' => $chosenUnit !== '' ? $validUnits[strtolower($chosenUnit)] : '',
        ];
    }

    $mismatches = [];
    $totalAdded = 0;
    $conn->begin_transaction();
    try {
        // Re-check under a row lock: two people confirming the same PR at
        // once serialize here, and the second one sees received_at set.
        $state = pr_receiving_state($conn, $prId, true);
        if ($state['error'] !== null) {
            throw new RuntimeException($state['error'], $state['code']);
        }
        $prNo = $state['pr']['pr_no'];

        $itemsStmt = $conn->prepare(
            'SELECT pri_id, medicine_id, generic_name, brand, strength, unit, units_per_box, qty_requested
             FROM purchase_request_items WHERE pr_id = ? ORDER BY pri_id ASC'
        );
        $itemsStmt->bind_param('i', $prId);
        $itemsStmt->execute();
        $res = $itemsStmt->get_result();
        $prItems = [];
        while ($row = $res->fetch_assoc()) {
            $prItems[(int) $row['pri_id']] = $row;
        }
        $itemsStmt->close();

        foreach ($validated as $priId => $line) {
            if (!isset($prItems[$priId])) {
                throw new RuntimeException('One of the submitted items does not belong to this purchase request.', 422);
            }
        }

        // One receiving closes the PR, so an all-zero confirm would lock
        // it with nothing stocked and no way back. Refuse it.
        $anyDelivered = false;
        foreach ($validated as $line) {
            if ($line['qty'] > 0) {
                $anyDelivered = true;
                break;
            }
        }
        if (!$anyDelivered) {
            throw new RuntimeException('Enter a delivered quantity for at least one item.', 422);
        }

        $updateItem = $conn->prepare(
            'UPDATE purchase_request_items
             SET qty_received = ?, variance = ?, variance_note = ?, received_batch_no = ?, received_expiry_date = ?,
                 received_unit_price = ?, received_selling_price = ?, batch_id = ?, medicine_id = COALESCE(medicine_id, ?)
             WHERE pri_id = ? AND pr_id = ?'
        );

        // Every PR line gets a receiving record, including any that were
        // not submitted (recorded as 0 delivered = short by the full
        // requested quantity) -- that is the whole point of the variance.
        foreach ($prItems as $priId => $item) {
            $line = $validated[$priId] ?? ['qty' => 0, 'batch_no' => null, 'expiry_date' => null, 'unit_price' => null, 'selling_price' => null, 'unit' => ''];
            $qty = (int) $line['qty'];
            $qtyRequested = (int) $item['qty_requested'];
            $variance = $qty - $qtyRequested;
            $varianceNote = pr_variance_note($variance, (string) $item['unit']);
            $label = trim($item['generic_name'] . ' ' . (string) $item['strength']);
            if ($variance !== 0) {
                $mismatches[] = $label . ': ' . $varianceNote;
            }

            $batchId = null;
            $medicineId = $item['medicine_id'] !== null ? (int) $item['medicine_id'] : null;
            if ($qty > 0) {
                if ($medicineId !== null) {
                    $existsStmt = $conn->prepare('SELECT medicine_id FROM inventory_medicines WHERE medicine_id = ?');
                    $existsStmt->bind_param('i', $medicineId);
                    $existsStmt->execute();
                    $exists = $existsStmt->get_result()->fetch_assoc();
                    $existsStmt->close();
                    if (!$exists) {
                        throw new RuntimeException("{$label} is no longer in the medicine catalog, so it can't be stocked in from this request.", 422);
                    }
                } else {
                    $unit = trim((string) $item['unit']) !== '' ? trim((string) $item['unit']) : $line['unit'];
                    if ($unit === '') {
                        throw new RuntimeException("{$label} isn't in the catalog yet and has no unit on the request. Choose a unit for it before confirming.", 422);
                    }
                    // The PR form fills Strength from the medicine name, so the
                    // name often already contains it. find_or_create_medicine
                    // names the new catalog row "<name> <strength>", which would
                    // repeat it - leave strength off when the name has it.
                    $strengthForCatalog = $item['strength'];
                    if ($strengthForCatalog !== null && $strengthForCatalog !== '' && stripos($item['generic_name'], $strengthForCatalog) !== false) {
                        $strengthForCatalog = null;
                    }
                    $found = find_or_create_medicine(
                        $conn,
                        $item['generic_name'],
                        $strengthForCatalog,
                        $unit,
                        $item['units_per_box'] !== null ? (int) $item['units_per_box'] : null,
                        false
                    );
                    $medicineId = $found['medicine_id'];
                }

                // Stock received against a purchase request is always
                // Purchase Order stock, whatever funding source an older
                // request may have saved.
                $source = 'purchase_order';
                $batchId = credit_medicine_batch(
                    $conn,
                    $medicineId,
                    $source,
                    $item['brand'],
                    $line['batch_no'],
                    $qty,
                    $line['expiry_date'],
                    $line['unit_price'],
                    $line['selling_price'],
                    $pharmacistId
                );
                $totalAdded += $qty;
            }

            $updateItem->bind_param(
                'iisssddiiii',
                $qty,
                $variance,
                $varianceNote,
                $line['batch_no'],
                $line['expiry_date'],
                $line['unit_price'],
                $line['selling_price'],
                $batchId,
                $medicineId,
                $priId,
                $prId
            );
            $updateItem->execute();
        }
        $updateItem->close();

        $closePr = $conn->prepare('UPDATE purchase_requests SET received_at = NOW(), received_by = ? WHERE pr_id = ? AND received_at IS NULL');
        $closePr->bind_param('ii', $pharmacistId, $prId);
        $closePr->execute();
        $closed = $closePr->affected_rows;
        $closePr->close();
        if ($closed !== 1) {
            throw new RuntimeException('Stock has already been recorded for this purchase request.', 409);
        }

        $conn->commit();
    } catch (mysqli_sql_exception $e) {
        // Must come before RuntimeException: mysqli_sql_exception extends
        // it, and its message is raw SQL error text that must never be
        // shown to the user.
        $conn->rollback();
        error_log('pr-stock-in confirm failed for PR ' . $prId . ': ' . $e->getMessage());
        respond(['success' => false, 'error' => 'Could not confirm this stock-in. Please try again.'], 500);
    } catch (RuntimeException $e) {
        // Our own validation / state errors, thrown above with an HTTP
        // status as the exception code.
        $conn->rollback();
        $code = in_array($e->getCode(), [404, 409, 422], true) ? $e->getCode() : 422;
        respond(['success' => false, 'error' => $e->getMessage()], $code);
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('pr-stock-in confirm failed for PR ' . $prId . ': ' . $e->getMessage());
        respond(['success' => false, 'error' => 'Could not confirm this stock-in. Please try again.'], 500);
    }

    $summary = "Stocked in {$prNo} ({$totalAdded} units across " . count($prItems) . ' line(s)).'
        . (!empty($mismatches) ? ' Flagged vs PR: ' . implode('; ', $mismatches) : ' All quantities matched the PR.');
    write_audit_log($conn, $pharmacistId, 'pharmacist', 'purchase_request_stocked', 'inventory', $summary);

    respond(['success' => true, 'mismatches' => $mismatches]);
}

respond(['success' => false, 'error' => 'Unknown action.'], 400);
