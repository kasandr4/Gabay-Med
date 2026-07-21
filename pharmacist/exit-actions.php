<?php
// pharmacist/exit-actions.php
// JSON action endpoint backing the scan submit on storage-exit-scan.php
// ("Stock Release"). Mirrors delivery-actions.php's conventions: single
// action-routed JSON endpoint, CSRF-checked, prepared statements only.
//
// Requires inventory_medicines_barcode_migration.sql to have been run —
// see that file for why: inventory_medicines had no barcode column at
// all in the real schema, so barcode lookup had nothing to query against.
//
// "Scanning as" stays a shared-station picker, not $_SESSION['user_id']:
// this screen is used standing at a counter, and per its own TODO(backend)
// comment it's meant to be any active pharmacist/admin picked from a real
// roster, not necessarily whoever is logged into the browser session. So
// staff_id is validated against that same roster server-side (role IN
// pharmacist/admin, is_active = 1) rather than trusted as posted.
//
// destination/reason/remarks stay UI-only, same scope decision as before
// this endpoint existed: medicine_exit_log has no columns for them, and
// this isn't the pass to add ones that aren't strictly needed for the
// core deduction to work.

require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/csrf.php';

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

switch ($action) {

    // ------------------------------------------------------------
    // Scan Exit — one barcode read, boxes released (default 1, but the
    // Quantity field is editable for a multi-box exit in one scan).
    // Deducts inventory_medicines.current_stock in UNITS (boxes *
    // units_per_box, same convention as delivery-actions.php's credit
    // side) and logs the exit to medicine_exit_log.
    // ------------------------------------------------------------
    case 'scan_exit': {
            $barcode = trim($_POST['barcode'] ?? '');
            $staffId = (int) ($_POST['staff_id'] ?? 0);
            $boxes = (int) ($_POST['boxes'] ?? 1);

            if ($barcode === '' || $staffId <= 0 || $boxes <= 0) {
                respond(['success' => false, 'error' => 'Select your name and scan a valid barcode.'], 422);
            }

            $staffStmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND role IN ('pharmacist','admin') AND is_active = 1");
            $staffStmt->bind_param("i", $staffId);
            $staffStmt->execute();
            $staffFound = $staffStmt->get_result()->fetch_assoc();
            $staffStmt->close();

            if (!$staffFound) {
                respond(['success' => false, 'error' => 'That staff member is not recognized. Please reselect your name.'], 403);
            }

            $medStmt = $conn->prepare("SELECT medicine_id, name, units_per_box, current_stock FROM inventory_medicines WHERE barcode = ?");
            $medStmt->bind_param("s", $barcode);
            $medStmt->execute();
            $medicine = $medStmt->get_result()->fetch_assoc();
            $medStmt->close();

            if (!$medicine) {
                respond(['success' => false, 'error' => 'Barcode not recognized \u2014 please rescan.'], 404);
            }

            $unitsToDeduct = $boxes * (int) $medicine['units_per_box'];

            if ($unitsToDeduct > (int) $medicine['current_stock']) {
                respond([
                    'success' => false,
                    'error' => "Not enough stock \u2014 only {$medicine['current_stock']} units of {$medicine['name']} left.",
                ], 409);
            }

            $conn->begin_transaction();
            try {
                $ins = $conn->prepare(
                    "INSERT INTO medicine_exit_log (medicine_id, boxes_scanned, units_deducted, staff_id, barcode, scanned_at)
                     VALUES (?, ?, ?, ?, ?, NOW())"
                );
                $ins->bind_param("iiiis", $medicine['medicine_id'], $boxes, $unitsToDeduct, $staffId, $barcode);
                $ins->execute();
                $ins->close();

                $stock = $conn->prepare("UPDATE inventory_medicines SET current_stock = current_stock - ? WHERE medicine_id = ?");
                $stock->bind_param("ii", $unitsToDeduct, $medicine['medicine_id']);
                $stock->execute();
                $stock->close();

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                respond(['success' => false, 'error' => 'Could not log this scan. Please try again.'], 500);
            }

            respond([
                'success' => true,
                'medicine' => $medicine['name'],
                'boxes' => $boxes,
                'units_deducted' => $unitsToDeduct,
            ]);
            break;
        }

    default:
        respond(['success' => false, 'error' => 'Unknown action.'], 400);
}
