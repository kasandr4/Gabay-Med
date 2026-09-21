<?php
// staff/restock-actions.php
// JSON action endpoint backing restock-management.php's "Submit Purchase
// Request" button. Inventory-type Staff only.
//
// Submitting a PR NEVER touches inventory: no stock column is read for
// writing or updated anywhere in this file. Stock only changes at
// Receiving (see the Restock / PR workflow spec).
//
// Everything the browser sends is re-validated here - medicine must
// exist, unit comes from the inventory record (not from the client) and
// must be a valid unit, quantity must be a whole number > 0, and the
// same medicine can't appear twice - the same "don't trust the client"
// principle used by the other action endpoints in this app.

ob_start();
require_once '../includes/auth_guard.php';
require_role('staff');
require_staff_type('inventory');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/audit_log.php';
require_once '../includes/notifications.php';

$staffId = (int) $_SESSION['user_id'];

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Invalid request method.'], 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$submittedToken = (string) ($body['csrf_token'] ?? '');
$expectedToken = $_SESSION['csrf_token'] ?? '';
if ($expectedToken === '' || !hash_equals($expectedToken, $submittedToken)) {
    respond(['success' => false, 'error' => 'Your session expired. Please refresh the page and try again.'], 403);
}

$action = $body['action'] ?? '';

if ($action !== 'submit') {
    respond(['success' => false, 'error' => 'Unknown action.'], 400);
}

$items = is_array($body['items'] ?? null) ? $body['items'] : [];
if (count($items) === 0) {
    respond(['success' => false, 'error' => 'Select at least one medicine.'], 422);
}
if (count($items) > 500) {
    respond(['success' => false, 'error' => 'Too many medicines in one request (500 max). Split it up.'], 422);
}

// Pass 1: shape checks, quantity > 0, no duplicates.
$requested = []; // medicine_id => qty
foreach ($items as $item) {
    $medicineId = filter_var($item['medicine_id'] ?? null, FILTER_VALIDATE_INT);
    $qty = filter_var($item['qty'] ?? null, FILTER_VALIDATE_INT);
    if ($medicineId === false || $medicineId <= 0) {
        respond(['success' => false, 'error' => 'One of the selected medicines is invalid.'], 422);
    }
    if ($qty === false || $qty <= 0) {
        respond(['success' => false, 'error' => 'Every requested quantity must be a whole number greater than zero.'], 422);
    }
    if ($qty > 1000000) {
        respond(['success' => false, 'error' => 'A requested quantity is unreasonably large.'], 422);
    }
    if (isset($requested[$medicineId])) {
        respond(['success' => false, 'error' => 'The same medicine appears more than once in this request.'], 422);
    }
    $requested[$medicineId] = $qty;
}

// Pass 2: every medicine exists; unit comes from the inventory record.
$validUnits = [];
$unitRes = $conn->query("SELECT name FROM medicine_units");
if ($unitRes) {
    while ($row = $unitRes->fetch_assoc()) {
        $validUnits[strtolower($row['name'])] = true;
    }
}

$idList = implode(',', array_map('intval', array_keys($requested)));
$medicines = [];
$medRes = $conn->query(
    "SELECT medicine_id, name, strength, unit, units_per_box
     FROM inventory_medicines
     WHERE medicine_id IN ($idList)"
);
if ($medRes) {
    while ($row = $medRes->fetch_assoc()) {
        $medicines[(int) $row['medicine_id']] = $row;
    }
}

$validated = [];
foreach ($requested as $medicineId => $qty) {
    if (!isset($medicines[$medicineId])) {
        respond(['success' => false, 'error' => 'One of the selected medicines no longer exists. Refresh the page and try again.'], 422);
    }
    $med = $medicines[$medicineId];
    $unit = trim((string) $med['unit']);
    if ($unit === '' || !isset($validUnits[strtolower($unit)])) {
        respond(['success' => false, 'error' => $med['name'] . ' has no valid unit set in inventory. Ask the pharmacist to fix it first.'], 422);
    }
    $validated[] = [
        'medicine_id' => $medicineId,
        'name' => $med['name'],
        'strength' => ($med['strength'] !== null && $med['strength'] !== '') ? $med['strength'] : null,
        'unit' => $unit,
        'units_per_box' => $med['units_per_box'] !== null ? (int) $med['units_per_box'] : null,
        'qty' => $qty,
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

    // status 'open' is what the pharmacist side treats as "Submitted".
    $insertPr = $conn->prepare(
        "INSERT INTO purchase_requests (pr_no, requested_by, status, created_at, submitted_at)
         VALUES (?, ?, 'open', NOW(), NOW())"
    );
    $insertPr->bind_param('si', $prNo, $staffId);
    $insertPr->execute();
    $prId = $insertPr->insert_id;
    $insertPr->close();

    $insertItem = $conn->prepare(
        "INSERT INTO purchase_request_items
            (pr_id, medicine_id, generic_name, strength, unit, units_per_box, qty_requested)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($validated as $v) {
        $insertItem->bind_param(
            'iisssii',
            $prId,
            $v['medicine_id'],
            $v['name'],
            $v['strength'],
            $v['unit'],
            $v['units_per_box'],
            $v['qty']
        );
        $insertItem->execute();
    }
    $insertItem->close();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    respond(['success' => false, 'error' => 'Could not save this purchase request. Please try again.'], 500);
}

write_audit_log($conn, $staffId, 'staff', 'purchase_request_submitted', 'procurement', "Submitted {$prNo} with " . count($validated) . ' item(s).');

$staffName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$message = "New purchase request {$prNo}" . ($staffName !== '' ? " from {$staffName}" : '') . ' is waiting for your review.';
foreach (all_pharmacist_ids($conn) as $pharmacistId) {
    create_notification($conn, (int) $pharmacistId, $message, '../pharmacist/purchase-requests.php', 'info');
}

respond(['success' => true, 'pr_id' => $prId, 'pr_no' => $prNo]);
