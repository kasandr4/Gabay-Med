<?php
// pharmacist/inventory-count-details-ajax.php
// Returns one count batch's full detail — batch info plus every line item
// (system_count now visible, since this is the Pharmacist's own review
// screen, not the blind staff-facing one) — as JSON, for the drawer on
// inventory-count.php. Read-only, pharmacist-only, and scoped to batches
// THIS pharmacist assigned (same ownership check as the rest of this
// module). Mirrors admin/inventory-details-ajax.php's shape.

require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';

header('Content-Type: application/json');

function respond($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

$pharmacistId = (int) $_SESSION['user_id'];
$batchId = isset($_GET['batch_id']) ? (int) $_GET['batch_id'] : 0;
if ($batchId <= 0) {
    respond(['error' => 'Missing or invalid batch_id.'], 400);
}

$stmt = $conn->prepare(
    "SELECT icb.batch_id, icb.batch_number, icb.status, icb.assigned_at, icb.due_date,
            icb.submitted_at, icb.confirmed_at, icb.notes,
            CONCAT(su.first_name, ' ', su.last_name) AS staff_name,
            CONCAT(cu.first_name, ' ', cu.last_name) AS confirmed_by_name
     FROM inventory_count_batches icb
     JOIN users su ON su.user_id = icb.assigned_to
     LEFT JOIN users cu ON cu.user_id = icb.confirmed_by
     WHERE icb.batch_id = ? AND icb.assigned_by = ?"
);
$stmt->bind_param("ii", $batchId, $pharmacistId);
$stmt->execute();
$batch = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$batch) {
    respond(['error' => 'Batch not found.'], 404);
}

$itemsStmt = $conn->prepare(
    "SELECT ici.item_id, ici.system_count, ici.staff_count, ici.final_count, ici.remarks,
            im.medicine_id, im.name AS medicine_name, im.unit,
            (SELECT mb.expiry_date FROM medicine_batches mb
              WHERE mb.medicine_id = im.medicine_id
                AND mb.units_remaining > 0 AND mb.expiry_date IS NOT NULL
              ORDER BY mb.expiry_date ASC LIMIT 1) AS earliest_expiry
     FROM inventory_count_items ici
     JOIN inventory_medicines im ON im.medicine_id = ici.medicine_id
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
