<?php
// admin/inventory-actions.php
// Single JSON action endpoint backing every state-changing button on
// inventory-procurement.php (create request, approve, send for revision,
// reject, resubmit, add bid, select winner, advance PO status). Mirrors
// the project's existing "-process.php" convention, just returning JSON
// instead of redirecting, since the page it serves is drawer/modal-driven
// rather than full-page-reload driven.
//
// Segregation of duties: create_request and resubmit_request are
// Pharmacist-only (ownership-checked against requested_by); every other
// action — approve, reject, send for revision, add bid, select winner,
// advance PO status — is Admin-only. See the $pharmacistActions /
// $adminActions gate below for the enforcement, and inventory-procurement.php
// for the matching UI (no Admin create/resubmit affordance exists there).
// CSRF-checked throughout. Every write is done through prepared statements.

require_once '../includes/auth_guard.php';
require_role(['admin', 'pharmacist']);
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/notifications.php';

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

// Segregation of duties, enforced server-side (not just hidden in the UI):
// Pharmacist may only ever reach create_request / resubmit_request, and
// only on their own requests (ownership is re-checked inside each case
// below via requested_by). Every other action (approve, reject, revise,
// bid, select winner, PO progression) stays Admin-only — an Admin can no
// longer create or resubmit a request, so they can't submit and approve
// the same request themselves.
$pharmacistActions = ['create_request', 'resubmit_request'];
$adminActions = ['approve_request', 'send_revision', 'reject_request', 'add_bid', 'select_winner', 'advance_po_status'];

if ($_SESSION['role'] === 'pharmacist' && !in_array($action, $pharmacistActions, true)) {
    respond(['success' => false, 'error' => 'Not authorized for this action.'], 403);
}
if ($_SESSION['role'] === 'admin' && !in_array($action, $adminActions, true)) {
    respond(['success' => false, 'error' => 'Not authorized for this action.'], 403);
}

// NOTE: kept as $adminId for minimal diff against the rest of this file —
// for create_request/resubmit_request specifically, this holds the
// pharmacist's user_id, since purchase_requests.requested_by is a
// generic FK to users, not admin-scoped.
$adminId = (int) $_SESSION['user_id'];

/**
 * Recomputes the 4 dashboard stat-card totals so the frontend can patch
 * the cards after any action without a full page reload.
 */
function getStats($conn)
{
    $stats = [
        'pending' => 0,
        'approved' => 0,
        'active_bids' => 0,
        'low_stock' => 0,
    ];

    $r = $conn->query("SELECT COUNT(*) c FROM purchase_requests WHERE status = 'pending'");
    $stats['pending'] = (int) $r->fetch_assoc()['c'];

    $r = $conn->query("SELECT COUNT(*) c FROM purchase_requests WHERE status = 'approved'");
    $stats['approved'] = (int) $r->fetch_assoc()['c'];

    $r = $conn->query(
        "SELECT COUNT(*) c FROM supplier_bids sb
         JOIN purchase_requests pr ON pr.request_id = sb.request_id
         WHERE pr.status = 'approved' AND sb.is_winner = 0"
    );
    $stats['active_bids'] = (int) $r->fetch_assoc()['c'];

    $r = $conn->query("SELECT COUNT(*) c FROM inventory_medicines WHERE current_stock <= minimum_stock");
    $stats['low_stock'] = (int) $r->fetch_assoc()['c'];

    return $stats;
}

switch ($action) {

    // ------------------------------------------------------------
    // Create a new Purchase Request. Pharmacist-only (enforced above) —
    // this is now reached exclusively from pharmacist/inventory.php's
    // "Request Restock" modal. Admin no longer has a Create Request
    // entry point on either the frontend or this endpoint.
    // ------------------------------------------------------------
    case 'create_request': {
            $medicineId = (int) ($_POST['medicine_id'] ?? 0);
            $quantity   = (int) ($_POST['requested_quantity'] ?? 0);
            $priority   = $_POST['priority'] ?? 'medium';
            $reason     = trim($_POST['reason'] ?? '');
            $notes      = trim($_POST['notes'] ?? '');

            $allowedPriority = ['low', 'medium', 'high', 'critical'];
            if (!in_array($priority, $allowedPriority, true)) $priority = 'medium';

            if ($medicineId <= 0 || $quantity <= 0 || $reason === '') {
                respond(['success' => false, 'error' => 'Medicine, quantity, and reason are required.'], 422);
            }

            $stmt = $conn->prepare(
                "INSERT INTO purchase_requests (medicine_id, requested_quantity, reason, notes, priority, status, requested_by)
             VALUES (?, ?, ?, ?, ?, 'pending', ?)"
            );
            $stmt->bind_param("iisssi", $medicineId, $quantity, $reason, $notes, $priority, $adminId);
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();

            respond(['success' => true, 'message' => 'Purchase request submitted.', 'request_id' => $newId, 'stats' => getStats($conn)]);
            break;
        }

        // ------------------------------------------------------------
        // Approve / Send for Revision / Reject
        // ------------------------------------------------------------
    case 'approve_request': {
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $stmt = $conn->prepare("UPDATE purchase_requests SET status = 'approved' WHERE request_id = ? AND status IN ('pending','revision_requested')");
            $stmt->bind_param("i", $requestId);
            $stmt->execute();
            $ok = $stmt->affected_rows > 0;
            $stmt->close();

            if (!$ok) respond(['success' => false, 'error' => 'This request can no longer be approved.'], 409);

            respond(['success' => true, 'message' => 'Request approved. Suppliers can now submit bids.', 'stats' => getStats($conn)]);
            break;
        }

    case 'send_revision': {
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $note = trim($_POST['revision_note'] ?? '');
            if ($note === '') respond(['success' => false, 'error' => 'A revision note is required.'], 422);

            $stmt = $conn->prepare("UPDATE purchase_requests SET status = 'revision_requested', revision_note = ? WHERE request_id = ? AND status = 'pending'");
            $stmt->bind_param("si", $note, $requestId);
            $stmt->execute();
            $ok = $stmt->affected_rows > 0;
            $stmt->close();

            if (!$ok) respond(['success' => false, 'error' => 'This request can no longer be sent back for revision.'], 409);

            respond(['success' => true, 'message' => 'Sent back for revision.', 'stats' => getStats($conn)]);
            break;
        }

    case 'reject_request': {
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $reason = trim($_POST['rejection_reason'] ?? '');
            if ($reason === '') respond(['success' => false, 'error' => 'A rejection reason is required.'], 422);

            $stmt = $conn->prepare("UPDATE purchase_requests SET status = 'rejected', rejection_reason = ? WHERE request_id = ? AND status = 'pending'");
            $stmt->bind_param("si", $reason, $requestId);
            $stmt->execute();
            $ok = $stmt->affected_rows > 0;
            $stmt->close();

            if (!$ok) respond(['success' => false, 'error' => 'This request can no longer be rejected.'], 409);

            respond(['success' => true, 'message' => 'Request rejected.', 'stats' => getStats($conn)]);
            break;
        }

        // Puts a revision_requested request back into the pending queue after
        // the PHARMACIST who owns it has edited it. Pharmacist-only
        // (enforced above), reached from pharmacist/inventory.php's "Edit &
        // Resubmit" action on their own "My Restock Requests" table. The
        // requested_by = ? check below is the actual ownership boundary —
        // a pharmacist cannot resubmit a colleague's request even if they
        // guess the request_id.
    case 'resubmit_request': {
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $quantity  = (int) ($_POST['requested_quantity'] ?? 0);
            $reason    = trim($_POST['reason'] ?? '');
            $notes     = trim($_POST['notes'] ?? '');
            $priority  = $_POST['priority'] ?? 'medium';
            $allowedPriority = ['low', 'medium', 'high', 'critical'];
            if (!in_array($priority, $allowedPriority, true)) $priority = 'medium';

            if ($requestId <= 0 || $quantity <= 0 || $reason === '') {
                respond(['success' => false, 'error' => 'Quantity and reason are required.'], 422);
            }

            $stmt = $conn->prepare(
                "UPDATE purchase_requests
             SET requested_quantity = ?, reason = ?, notes = ?, priority = ?, status = 'pending', revision_note = NULL
             WHERE request_id = ? AND status = 'revision_requested' AND requested_by = ?"
            );
            $stmt->bind_param("isssii", $quantity, $reason, $notes, $priority, $requestId, $adminId);
            $stmt->execute();
            $ok = $stmt->affected_rows > 0;
            $stmt->close();

            if (!$ok) respond(['success' => false, 'error' => 'This request is not awaiting your revision.'], 409);

            respond(['success' => true, 'message' => 'Request resubmitted for approval.', 'stats' => getStats($conn)]);
            break;
        }

        // ------------------------------------------------------------
        // Supplier bidding
        // ------------------------------------------------------------
    case 'add_bid': {
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $supplierName = trim($_POST['supplier_name'] ?? '');
            $bidPrice = (float) ($_POST['bid_price'] ?? 0);
            $deliveryDate = $_POST['estimated_delivery_date'] ?? '';
            $warranty = trim($_POST['warranty'] ?? '');
            $rating = (float) ($_POST['supplier_rating'] ?? 0);

            if ($supplierName === '' || $bidPrice <= 0 || $deliveryDate === '') {
                respond(['success' => false, 'error' => 'Supplier name, bid price, and delivery date are required.'], 422);
            }
            if ($rating < 0) $rating = 0;
            if ($rating > 5) $rating = 5;

            // Only requests currently Approved can receive bids.
            $check = $conn->prepare("SELECT status FROM purchase_requests WHERE request_id = ?");
            $check->bind_param("i", $requestId);
            $check->execute();
            $row = $check->get_result()->fetch_assoc();
            $check->close();
            if (!$row || $row['status'] !== 'approved') {
                respond(['success' => false, 'error' => 'Bids can only be added to an approved request.'], 409);
            }

            $stmt = $conn->prepare(
                "INSERT INTO supplier_bids (request_id, supplier_name, bid_price, estimated_delivery_date, warranty, supplier_rating)
             VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("isdssd", $requestId, $supplierName, $bidPrice, $deliveryDate, $warranty, $rating);
            $stmt->execute();
            $stmt->close();

            respond(['success' => true, 'message' => 'Bid recorded.', 'stats' => getStats($conn)]);
            break;
        }

    case 'select_winner': {
            $bidId = (int) ($_POST['bid_id'] ?? 0);

            $stmt = $conn->prepare(
                "SELECT sb.bid_id, sb.request_id, sb.supplier_name, sb.bid_price, sb.estimated_delivery_date,
                    pr.requested_quantity, pr.status
             FROM supplier_bids sb
             JOIN purchase_requests pr ON pr.request_id = sb.request_id
             WHERE sb.bid_id = ?"
            );
            $stmt->bind_param("i", $bidId);
            $stmt->execute();
            $bid = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$bid) respond(['success' => false, 'error' => 'Bid not found.'], 404);
            if ($bid['status'] !== 'approved') respond(['success' => false, 'error' => 'This request is no longer open for a winning bid.'], 409);

            // Already has a PO? Don't double-generate.
            $existing = $conn->prepare("SELECT po_id FROM purchase_orders WHERE request_id = ?");
            $existing->bind_param("i", $bid['request_id']);
            $existing->execute();
            $hasPo = $existing->get_result()->fetch_assoc();
            $existing->close();
            if ($hasPo) respond(['success' => false, 'error' => 'A purchase order already exists for this request.'], 409);

            $conn->begin_transaction();
            try {
                $mark = $conn->prepare("UPDATE supplier_bids SET is_winner = 0 WHERE request_id = ?");
                $mark->bind_param("i", $bid['request_id']);
                $mark->execute();
                $mark->close();

                $win = $conn->prepare("UPDATE supplier_bids SET is_winner = 1 WHERE bid_id = ?");
                $win->bind_param("i", $bidId);
                $win->execute();
                $win->close();

                $totalCost = $bid['bid_price'];
                $purchaseDate = date('Y-m-d');
                $expectedDelivery = $bid['estimated_delivery_date'];

                // PO-YYYY-NNNNNN, sequential per year, based on the next
                // purchase_orders auto-increment id — internal document number,
                // not a public lookup code, so no HMAC signature is needed
                // (unlike appointment reference numbers).
                $year = date('Y');
                $seedStmt = $conn->prepare("INSERT INTO purchase_orders (po_number, request_id, bid_id, supplier_name, approved_quantity, total_cost, purchase_date, expected_delivery, status) VALUES ('', ?, ?, ?, ?, ?, ?, ?, 'po_issued')");
                $seedStmt->bind_param(
                    "iisidss",
                    $bid['request_id'],
                    $bidId,
                    $bid['supplier_name'],
                    $bid['requested_quantity'],
                    $totalCost,
                    $purchaseDate,
                    $expectedDelivery
                );
                $seedStmt->execute();
                $poId = $seedStmt->insert_id;
                $seedStmt->close();

                $poNumber = sprintf('PO-%s-%06d', $year, $poId);
                $upd = $conn->prepare("UPDATE purchase_orders SET po_number = ? WHERE po_id = ?");
                $upd->bind_param("si", $poNumber, $poId);
                $upd->execute();
                $upd->close();

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                respond(['success' => false, 'error' => 'Could not generate the purchase order. Please try again.'], 500);
            }

            respond(['success' => true, 'message' => 'Winning supplier confirmed. Purchase order generated.', 'po_id' => $poId, 'stats' => getStats($conn)]);
            break;
        }

        // ------------------------------------------------------------
        // Purchase Order status progression. On reaching "completed", the
        // ordered quantity (approved_quantity — boxes, same field
        // delivery-receiving.php labels "boxes_ordered") is converted to
        // units via units_per_box and added back into stock, and the
        // originating request is marked completed too. Fixed 2026-07-20
        // to stop crediting raw box counts into a unit-denominated column
        // — see pharmacist/delivery-actions.php's header comment for the
        // full reasoning, since confirm_delivery had the same gap.
        // ------------------------------------------------------------
    case 'advance_po_status': {
            $poId = (int) ($_POST['po_id'] ?? 0);
            $order = ['po_issued', 'awaiting_delivery', 'delivered', 'completed'];

            $stmt = $conn->prepare("SELECT po.status, po.request_id, po.approved_quantity, pr.medicine_id, im.units_per_box FROM purchase_orders po JOIN purchase_requests pr ON pr.request_id = po.request_id JOIN inventory_medicines im ON im.medicine_id = pr.medicine_id WHERE po.po_id = ?");
            $stmt->bind_param("i", $poId);
            $stmt->execute();
            $po = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$po) respond(['success' => false, 'error' => 'Purchase order not found.'], 404);

            $currentIndex = array_search($po['status'], $order, true);
            if ($currentIndex === false || $currentIndex >= count($order) - 1) {
                respond(['success' => false, 'error' => 'This purchase order is already completed.'], 409);
            }
            $nextStatus = $order[$currentIndex + 1];

            $conn->begin_transaction();
            try {
                $upd = $conn->prepare("UPDATE purchase_orders SET status = ? WHERE po_id = ?");
                $upd->bind_param("si", $nextStatus, $poId);
                $upd->execute();
                $upd->close();

                if ($nextStatus === 'completed') {
                    // approved_quantity is boxes (same field delivery-receiving.php
                    // shows as "boxes_ordered") — current_stock is unit-denominated,
                    // same fix as pharmacist/delivery-actions.php's confirm_delivery.
                    $unitsToCredit = (int) $po['approved_quantity'] * (int) $po['units_per_box'];
                    $stock = $conn->prepare("UPDATE inventory_medicines SET current_stock = current_stock + ? WHERE medicine_id = ?");
                    $stock->bind_param("ii", $unitsToCredit, $po['medicine_id']);
                    $stock->execute();
                    $stock->close();

                    $req = $conn->prepare("UPDATE purchase_requests SET status = 'completed' WHERE request_id = ?");
                    $req->bind_param("i", $po['request_id']);
                    $req->execute();
                    $req->close();
                }

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                respond(['success' => false, 'error' => 'Could not update the purchase order. Please try again.'], 500);
            }

            respond(['success' => true, 'message' => 'Purchase order updated to "' . str_replace('_', ' ', $nextStatus) . '".', 'status' => $nextStatus, 'stats' => getStats($conn)]);
            break;
        }

    default:
        respond(['success' => false, 'error' => 'Unknown action.'], 400);
}
