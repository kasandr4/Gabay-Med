<?php
require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/audit_log.php';
require_once '../includes/inventory_helpers.php';

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
if (!in_array($action, ['record_batch', 'record_new_medicine'], true)) {
    respond(['success' => false, 'error' => 'Unknown action.'], 400);
}

$pharmacistId = (int) $_SESSION['user_id'];

if ($action === 'record_new_medicine') {
    // FIXED 2026-09-20 (audit finding): this action used to require and
    // validate a `category` field against a `medicine_categories` table,
    // and wrote it into `medicine_names.category` - both were dropped
    // entirely by the category-retirement migration (see medicine-
    // catalog-action.php's own header comment: "CATEGORY RETIRED... the
    // pharmacy only manages medicine stock"). That migration updated
    // every other file that referenced category except this one, so
    // "New Medicine" has been failing at the very first validation check
    // ever since (the form itself was already updated to not send a
    // category field at all, so $category read as permanently empty and
    // tripped the "required" check below). Removed entirely rather than
    // patched around, matching what every other medicine-creation path
    // in this app already does post-retirement.
    $genericName = trim($_POST['generic_name'] ?? '');
    $manufacturer = trim((string) ($_POST['manufacturer'] ?? ''));
    $strength = trim((string) ($_POST['strength'] ?? ''));
    $unit = trim($_POST['unit'] ?? '');
    $unitsPerBox = filter_var($_POST['units_per_box'] ?? null, FILTER_VALIDATE_INT);
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $batchNo = trim((string) ($_POST['batch_no'] ?? ''));
    $brand = trim((string) ($_POST['brand'] ?? ''));
    $expiryDate = trim((string) ($_POST['expiry_date'] ?? ''));
    $unitPriceInput = trim((string) ($_POST['unit_price'] ?? ''));
    $sellingPriceInput = trim((string) ($_POST['selling_price'] ?? ''));
    $source = strtolower(trim((string) ($_POST['source'] ?? '')));
    $donorNotes = trim((string) ($_POST['donor_notes'] ?? ''));

    if ($genericName === '' || $unit === '') {
        respond(['success' => false, 'error' => 'Generic name and unit are required.'], 422);
    }
    if (strlen($genericName) > 150 || strlen($manufacturer) > 150 || strlen($strength) > 50 || strlen($unit) > 30 || strlen($donorNotes) > 255 || strlen($brand) > 150) {
        respond(['success' => false, 'error' => 'One or more fields exceed the allowed length.'], 422);
    }
    if ($unitsPerBox === false || $unitsPerBox < 1) {
        respond(['success' => false, 'error' => 'Enter a valid units-per-box value.'], 422);
    }
    if ($source === 'purchase_order') {
        respond(['success' => false, 'error' => 'Purchase Order deliveries are recorded from Purchase Orders > Receive Delivery, not here.'], 422);
    }
    if (!in_array($source, ['maip', 'philhealth', 'pho', 'donated'], true)) {
        respond(['success' => false, 'error' => 'Select a valid stock source.'], 422);
    }
    if ($quantity <= 0) {
        respond(['success' => false, 'error' => 'Enter a valid quantity.'], 422);
    }
    if ($expiryDate !== '') {
        $expiryParts = explode('-', $expiryDate);
        $expiryObj = DateTime::createFromFormat('Y-m-d', $expiryDate);
        $validExpiry = $expiryObj
            && count($expiryParts) === 3
            && checkdate((int) $expiryParts[1], (int) $expiryParts[2], (int) $expiryParts[0]);
        if (!$validExpiry) {
            respond(['success' => false, 'error' => 'Enter a valid expiry date.'], 422);
        }
    }
    $unitPrice = null;
    if ($unitPriceInput !== '') {
        $unitPrice = filter_var($unitPriceInput, FILTER_VALIDATE_FLOAT);
        if ($unitPrice === false || $unitPrice < 0) {
            respond(['success' => false, 'error' => 'Enter a valid unit price.'], 422);
        }
    }
    $sellingPrice = null;
    if ($sellingPriceInput !== '') {
        $sellingPrice = filter_var($sellingPriceInput, FILTER_VALIDATE_FLOAT);
        if ($sellingPrice === false || $sellingPrice < 0) {
            respond(['success' => false, 'error' => 'Enter a valid selling price.'], 422);
        }
    }

    $unitCheck = $conn->prepare("SELECT unit_id FROM medicine_units WHERE LOWER(name) = LOWER(?) LIMIT 1");
    $unitCheck->bind_param('s', $unit);
    $unitCheck->execute();
    $validUnit = $unitCheck->get_result()->fetch_assoc();
    $unitCheck->close();
    if (!$validUnit) {
        respond(['success' => false, 'error' => 'Choose a unit from the controlled list.'], 422);
    }

    $medicineName = $genericName . ($strength !== '' ? ' ' . $strength : '');
    if (strlen($medicineName) > 150) {
        respond(['success' => false, 'error' => 'The medicine name plus strength is too long.'], 422);
    }

    $conn->begin_transaction();
    try {
        $result = find_or_create_medicine($conn, $genericName, $strength, $unit, $unitsPerBox, true);
        $genericId = $result['generic_id'];
        $medicineId = $result['medicine_id'];

        $manufacturerParam = $manufacturer !== '' ? $manufacturer : null;
        $updateGeneric = $conn->prepare("UPDATE medicine_names SET manufacturer = ? WHERE generic_id = ?");
        $updateGeneric->bind_param('si', $manufacturerParam, $genericId);
        $updateGeneric->execute();
        $updateGeneric->close();

        $batchNoParam = $batchNo !== '' ? $batchNo : null;
        $expiryDateParam = $expiryDate !== '' ? $expiryDate : null;
        $donorNotesParam = $donorNotes !== '' ? $donorNotes : null;
        $brandParam = $brand !== '' ? $brand : null;

        credit_medicine_batch($conn, $medicineId, $source, $brandParam, $batchNoParam, $quantity, $expiryDateParam, $unitPrice, $sellingPrice, $pharmacistId, $donorNotesParam);

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        $message = $e instanceof RuntimeException ? $e->getMessage() : 'Could not add this medicine. Please try again.';
        respond(['success' => false, 'error' => $message], $e instanceof RuntimeException ? 422 : 500);
    }

    write_audit_log($conn, $pharmacistId, 'pharmacist', 'medicine_added', 'medicine_catalog', "Added {$medicineName} (medicine {$medicineId}) with an initial batch of {$quantity} {$source} units.");
    respond(['success' => true, 'message' => 'Medicine added and batch recorded.', 'medicine_id' => $medicineId]);
}

$medicineId = (int) ($_POST['medicine_id'] ?? 0);
$quantity = (int) ($_POST['quantity'] ?? 0);
$batchNo = trim((string) ($_POST['batch_no'] ?? ''));
$expiryDate = trim((string) ($_POST['expiry_date'] ?? ''));
$source = strtolower(trim((string) ($_POST['source'] ?? '')));
$donorNotes = trim((string) ($_POST['donor_notes'] ?? ''));
$brand = trim((string) ($_POST['brand'] ?? ''));
$unitPriceInput = trim((string) ($_POST['unit_price'] ?? ''));
$sellingPriceInput = trim((string) ($_POST['selling_price'] ?? ''));

if ($medicineId <= 0) {
    respond(['success' => false, 'error' => 'Select a medicine.'], 422);
}
if ($source === 'purchase_order') {
    respond(['success' => false, 'error' => 'Purchase Order deliveries are recorded from Purchase Orders > Receive Delivery, not here.'], 422);
}
if (!in_array($source, ['maip', 'philhealth', 'pho', 'donated'], true)) {
    respond(['success' => false, 'error' => 'Select a valid stock source.'], 422);
}
if ($quantity <= 0) {
    respond(['success' => false, 'error' => 'Enter a valid quantity.'], 422);
}
if (strlen($brand) > 150) {
    respond(['success' => false, 'error' => 'Brand is too long.'], 422);
}
if ($expiryDate !== '') {
    $expiryParts = explode('-', $expiryDate);
    $expiryObj = DateTime::createFromFormat('Y-m-d', $expiryDate);
    $validExpiry = $expiryObj
        && count($expiryParts) === 3
        && checkdate((int) $expiryParts[1], (int) $expiryParts[2], (int) $expiryParts[0]);
    if (!$validExpiry) {
        respond(['success' => false, 'error' => 'Enter a valid expiry date.'], 422);
    }
}
$unitPrice = null;
if ($unitPriceInput !== '') {
    $unitPrice = filter_var($unitPriceInput, FILTER_VALIDATE_FLOAT);
    if ($unitPrice === false || $unitPrice < 0) {
        respond(['success' => false, 'error' => 'Enter a valid unit price.'], 422);
    }
}
$sellingPrice = null;
if ($sellingPriceInput !== '') {
    $sellingPrice = filter_var($sellingPriceInput, FILTER_VALIDATE_FLOAT);
    if ($sellingPrice === false || $sellingPrice < 0) {
        respond(['success' => false, 'error' => 'Enter a valid selling price.'], 422);
    }
}

$stmt = $conn->prepare('SELECT medicine_id FROM inventory_medicines WHERE medicine_id = ?');
$stmt->bind_param('i', $medicineId);
$stmt->execute();
$medicine = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$medicine) {
    respond(['success' => false, 'error' => 'Medicine not found.'], 404);
}

$conn->begin_transaction();
try {
    $batchNoParam = $batchNo !== '' ? $batchNo : null;
    $expiryDateParam = $expiryDate !== '' ? $expiryDate : null;
    $donorNotesParam = $donorNotes !== '' ? $donorNotes : null;
    $brandParam = $brand !== '' ? $brand : null;

    credit_medicine_batch($conn, $medicineId, $source, $brandParam, $batchNoParam, $quantity, $expiryDateParam, $unitPrice, $sellingPrice, $pharmacistId, $donorNotesParam);

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    respond(['success' => false, 'error' => 'Could not record this batch. Please try again.'], 500);
}

write_audit_log($conn, $pharmacistId, 'pharmacist', 'stock_batch_recorded', 'inventory', "Recorded {$quantity} {$source} units for medicine {$medicineId} (batch " . ($batchNo !== '' ? $batchNo : 'unlabeled') . ').');
respond(['success' => true, 'message' => 'Batch recorded successfully.', 'quantity' => $quantity]);
