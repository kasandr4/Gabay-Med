<?php
// pharmacist/expiry-batch-actions.php
// JSON action endpoint backing "Add Batch" on expiry-tracking.php.
// Mirrors delivery-actions.php's conventions: single action-routed JSON
// endpoint, CSRF-checked, prepared statements, wrapped in a transaction.
//
// This is a SECOND way stock and a batch can enter the system, alongside
// pharmacist/delivery-actions.php's confirm_delivery — for stock that
// needs a batch logged outside the normal PO/Delivery Receiving flow
// (e.g. backfilling a batch that predates medicine_batches, or a
// donation/transfer that never went through procurement). It credits
// inventory_medicines.current_stock exactly the same way Delivery
// Receiving does, so current_stock and the sum of batch units_remaining
// can't drift apart no matter which of the two paths added the stock.
// units_received/units_remaining here are pieces (unit-denominated),
// same as everywhere else — NOT boxes, so no units_per_box conversion.

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
$pharmacistId = (int) $_SESSION['user_id'];

switch ($action) {

    case 'add_batch': {
            $medicineId = (int) ($_POST['medicine_id'] ?? 0);
            $batchNo = trim($_POST['batch_no'] ?? '');
            $quantity = (int) ($_POST['quantity'] ?? 0);
            $expiryDate = trim($_POST['expiry_date'] ?? '');
            $source = strtolower(trim((string) ($_POST['source'] ?? '')));
            $donorNotes = trim((string) ($_POST['donor_notes'] ?? ''));

            // Same rule as record-stock-batch-actions.php: this is a non-PO
            // entry point, so 'purchase_order' is off-limits here even
            // though it's a valid enum value — that source is only ever
            // written by the "From Purchase Order" tab on Record Stock Batch.
            if ($source === 'purchase_order') {
                respond(['success' => false, 'error' => 'Purchase Order deliveries are recorded from the "From Purchase Order" tab on Record Stock Batch, not here.'], 422);
            }
            if (!in_array($source, ['maip', 'philhealth', 'pho', 'donated'], true)) {
                respond(['success' => false, 'error' => 'Select a valid stock source.'], 422);
            }
            if (strlen($donorNotes) > 255) {
                respond(['success' => false, 'error' => 'Donor / notes is too long (255 characters max).'], 422);
            }
            if ($medicineId <= 0) {
                respond(['success' => false, 'error' => 'Select a medicine.'], 422);
            }
            if ($batchNo === '') {
                respond(['success' => false, 'error' => 'Enter a batch number.'], 422);
            }
            if ($quantity <= 0) {
                respond(['success' => false, 'error' => 'Enter a valid quantity.'], 422);
            }
            // DateTime::createFromFormat alone isn't enough here — it
            // silently normalizes out-of-range dates (e.g. 2026-02-30
            // becomes 2026-03-02) instead of rejecting them, so a
            // malformed direct POST (bypassing the <input type="date">
            // constraint in the browser) could otherwise sneak in a
            // rolled-over date. checkdate() catches that.
            $expiryParts = explode('-', $expiryDate);
            $expiryDt = DateTime::createFromFormat('Y-m-d', $expiryDate);
            $validExpiry = $expiryDt
                && count($expiryParts) === 3
                && checkdate((int) $expiryParts[1], (int) $expiryParts[2], (int) $expiryParts[0]);
            if ($expiryDate === '' || !$validExpiry) {
                respond(['success' => false, 'error' => 'Enter a valid expiry date.'], 422);
            }

            $stmt = $conn->prepare("SELECT medicine_id FROM inventory_medicines WHERE medicine_id = ?");
            $stmt->bind_param("i", $medicineId);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$exists) {
                respond(['success' => false, 'error' => 'Medicine not found.'], 404);
            }

            $conn->begin_transaction();
            try {
                $ins = $conn->prepare(
                    "INSERT INTO medicine_batches (medicine_id, source, donor_notes, batch_no, units_received, units_remaining, expiry_date, received_at, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)"
                );
                $donorNotesParam = $donorNotes !== '' ? $donorNotes : null;
                // 8 bound variables: medicineId(i), source(s), donorNotesParam(s),
                // batchNo(s), quantity(i) x2 (units_received AND units_remaining
                // both get the same value), expiryDate(s), pharmacistId(i).
                // Type string must be 8 chars long to match — it was 7 ("isssisi"),
                // one short, which made bind_param() throw ArgumentCountError
                // before any query ran.
                $ins->bind_param("isssiisi", $medicineId, $source, $donorNotesParam, $batchNo, $quantity, $quantity, $expiryDate, $pharmacistId);
                $ins->execute();
                $ins->close();

                $stock = $conn->prepare("UPDATE inventory_medicines SET current_stock = current_stock + ? WHERE medicine_id = ?");
                $stock->bind_param("ii", $quantity, $medicineId);
                $stock->execute();
                $stock->close();

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                respond(['success' => false, 'error' => 'Could not add this batch. Please try again.'], 500);
            }

            respond([
                'success'  => true,
                'message'  => 'Batch added — inventory updated.',
                'quantity' => $quantity,
            ]);
            break;
        }

    default:
        respond(['success' => false, 'error' => 'Unknown action.'], 400);
}
