<?php
// admin/inventory-details-ajax.php
// Returns one purchase request's full detail — request info, its medicine's
// stock levels, any supplier bids, and its purchase order if one has been
// generated — as JSON, for the View Details drawer. Read-only, admin-only.
// Mirrors doctor/patient-record-ajax.php's shape (auth -> query -> JSON).

require_once '../includes/auth_guard.php';
require_role('admin');
require_once '../config/db.php';

header('Content-Type: application/json');

function respond($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

$requestId = isset($_GET['request_id']) ? (int) $_GET['request_id'] : 0;
if ($requestId <= 0) {
    respond(['error' => 'Missing or invalid request_id.'], 400);
}

$stmt = $conn->prepare(
    "SELECT pr.request_id, pr.requested_quantity, pr.reason, pr.notes, pr.priority, pr.status,
            pr.revision_note, pr.rejection_reason, pr.created_at, pr.updated_at,
            m.medicine_id, m.name AS medicine_name, m.category, m.unit, m.description,
            m.current_stock, m.minimum_stock,
            u.first_name AS requester_first, u.last_name AS requester_last, u.role AS requester_role
     FROM purchase_requests pr
     JOIN inventory_medicines m ON m.medicine_id = pr.medicine_id
     JOIN users u ON u.user_id = pr.requested_by
     WHERE pr.request_id = ?"
);
$stmt->bind_param("i", $requestId);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) {
    respond(['error' => 'Purchase request not found.'], 404);
}

$bidsStmt = $conn->prepare(
    "SELECT bid_id, supplier_name, bid_price, estimated_delivery_date, warranty, supplier_rating, is_winner, created_at
     FROM supplier_bids WHERE request_id = ? ORDER BY bid_price ASC"
);
$bidsStmt->bind_param("i", $requestId);
$bidsStmt->execute();
$bids = $bidsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$bidsStmt->close();

$poStmt = $conn->prepare(
    "SELECT po_id, po_number, supplier_name, approved_quantity, total_cost, purchase_date, expected_delivery, status
     FROM purchase_orders WHERE request_id = ? LIMIT 1"
);
$poStmt->bind_param("i", $requestId);
$poStmt->execute();
$po = $poStmt->get_result()->fetch_assoc();
$poStmt->close();

respond([
    'request' => $request,
    'bids' => $bids,
    'purchase_order' => $po ?: null,
]);
