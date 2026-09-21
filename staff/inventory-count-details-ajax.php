<?php
// staff/inventory-count-details-ajax.php
// Returns one assigned batch's medicine list for the count-entry drawer.
// DELIBERATELY BLIND: the SELECT below never names system_count, so
// there is nothing to see in the JSON response, page source, or network
// tab even if staff opens dev tools — the only way to know the "correct"
// number is to look at the shelf. Compare admin/inventory-details-ajax.php's
// sibling on the Pharmacist side, whose query does select system_count.

require_once '../includes/auth_guard.php';
require_role('staff');
require_staff_type('inventory');
require_once '../config/db.php';

header('Content-Type: application/json');

function respond($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

$staffId = (int) $_SESSION['user_id'];
$batchId = isset($_GET['batch_id']) ? (int) $_GET['batch_id'] : 0;
if ($batchId <= 0) {
    respond(['error' => 'Missing or invalid batch_id.'], 400);
}

$stmt = $conn->prepare(
    "SELECT icb.batch_id, icb.batch_number, icb.status, icb.notes, icb.due_date,
            CONCAT(pu.first_name, ' ', pu.last_name) AS assigned_by_name
     FROM inventory_count_batches icb
     JOIN users pu ON pu.user_id = icb.assigned_by
     WHERE icb.batch_id = ? AND icb.assigned_to = ?"
);
$stmt->bind_param("ii", $batchId, $staffId);
$stmt->execute();
$batch = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$batch) {
    respond(['error' => 'Batch not found.'], 404);
}

// Keep this payload blind: only descriptive medicine metadata is selected.
// In particular, do not select inventory_medicines stock fields,
// inventory_count_items.system_count, or any medicine_batches quantity.
$itemsStmt = $conn->prepare(
    "SELECT ici.item_id, ici.staff_count, im.medicine_id, im.name AS medicine_name,
            mn.manufacturer AS brand, im.unit, open_batch.expiry_date
     FROM inventory_count_items ici
     JOIN inventory_medicines im ON im.medicine_id = ici.medicine_id
     JOIN medicine_names mn ON mn.generic_id = im.generic_id
     LEFT JOIN (
         SELECT medicine_id, MIN(expiry_date) AS expiry_date
         FROM medicine_batches
         WHERE units_remaining > 0 AND expiry_date IS NOT NULL
         GROUP BY medicine_id
     ) open_batch ON open_batch.medicine_id = im.medicine_id
     WHERE ici.batch_id = ?
     ORDER BY im.name ASC"
);
$itemsStmt->bind_param("i", $batchId);
$itemsStmt->execute();
$items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$itemsStmt->close();

respond([
    'batch' => $batch,
    'items' => $items,
]);
