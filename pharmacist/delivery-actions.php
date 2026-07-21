<?php
// pharmacist/delivery-actions.php
// JSON action endpoint backing "Confirm Delivery" on delivery-receiving.php.
// Mirrors admin/inventory-actions.php's conventions: single action-routed
// JSON endpoint, CSRF-checked, every write through prepared statements.
//
// SCOPE DECISION (2026-07-20): confirm_delivery moves a PO straight from
// its current status ('po_issued' or 'awaiting_delivery') to 'completed'
// in one step, rather than pausing at the intermediate 'delivered' status
// that appears in admin/inventory-actions.php's advance_po_status stage
// list. This is deliberate, not an oversight: admin/inventory-procurement.php
// still has its own generic "Advance" button wired to advance_po_status,
// which also credits stock once a PO reaches 'completed'. Landing on
// 'completed' directly here is what makes the two paths mutually
// exclusive instead of double-crediting the same PO — advance_po_status
// refuses to advance anything already at 'completed' (see its
// `$currentIndex >= count($order) - 1` guard), so once a pharmacist
// confirms a delivery here, Admin's Advance button can no longer touch
// that PO's stock again. The Track Order stepper (purchase_order_tracking)
// stays exactly as mocked on both portals for now — its earlier stages
// (order_placed, admin_approved, ...) aren't written anywhere real yet
// either, so partially wiring just the "delivered" stage here would leave
// the stepper in a half-real, half-mock state that's more confusing than
// today's fully-mocked one.
//
// STOCK UNIT FIX (2026-07-20): current_stock is tracked in UNITS, not
// boxes — confirmed against the real inventory_medicines schema, which
// does have units_per_box (the earlier comment here claiming it didn't
// was wrong), and against storage-exit-scan.php's own TODO(backend),
// which already assumes unit-denominated stock (units_deducted =
// units_per_box per box scanned). So boxes_received here must be
// multiplied by units_per_box before crediting current_stock — crediting
// raw box counts would silently corrupt stock the moment a medicine has
// units_per_box > 1 (today's seed data has every medicine at 1, which is
// why this went unnoticed). admin/inventory-actions.php's
// advance_po_status had the same gap; both are fixed together so neither
// path can leave current_stock in a different unit scale than the other.
//
// Requires purchase_order_deliveries_migration.sql to have been run.

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

    // ------------------------------------------------------------
    // Confirm Delivery — the pharmacist's physical box count for a PO
    // that's arrived. Credits inventory_medicines.current_stock with the
    // ACTUAL boxes received (which may differ from what was ordered —
    // the UI already warns on mismatch), not the originally approved
    // quantity.
    // ------------------------------------------------------------
    case 'confirm_delivery': {
            $poId = (int) ($_POST['po_id'] ?? 0);
            $boxesReceived = (int) ($_POST['boxes_received'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');

            if ($poId <= 0 || $boxesReceived <= 0) {
                respond(['success' => false, 'error' => 'Enter a valid number of boxes received.'], 422);
            }

            $stmt = $conn->prepare(
                "SELECT po.po_id, po.status, po.request_id, pr.medicine_id, im.units_per_box
                 FROM purchase_orders po
                 JOIN purchase_requests pr ON pr.request_id = po.request_id
                 JOIN inventory_medicines im ON im.medicine_id = pr.medicine_id
                 WHERE po.po_id = ?"
            );
            $stmt->bind_param("i", $poId);
            $stmt->execute();
            $po = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$po) {
                respond(['success' => false, 'error' => 'Purchase order not found.'], 404);
            }
            if (!in_array($po['status'], ['po_issued', 'awaiting_delivery'], true)) {
                respond(['success' => false, 'error' => 'This purchase order has already been marked delivered.'], 409);
            }

            $conn->begin_transaction();
            try {
                // Guarded on the old status too, so a double-click (or a
                // race with someone else confirming the same PO) can't
                // record the delivery twice.
                $upd = $conn->prepare("UPDATE purchase_orders SET status = 'completed' WHERE po_id = ? AND status IN ('po_issued','awaiting_delivery')");
                $upd->bind_param("i", $poId);
                $upd->execute();
                $advanced = $upd->affected_rows > 0;
                $upd->close();

                if (!$advanced) {
                    $conn->rollback();
                    respond(['success' => false, 'error' => 'This purchase order has already been marked delivered.'], 409);
                }

                $ins = $conn->prepare(
                    "INSERT INTO purchase_order_deliveries (po_id, boxes_received, received_by, notes, received_at)
                     VALUES (?, ?, ?, ?, NOW())"
                );
                $ins->bind_param("iiis", $poId, $boxesReceived, $pharmacistId, $notes);
                $ins->execute();
                $ins->close();

                // current_stock is unit-denominated (see the header
                // comment) — boxes_received alone would under-credit
                // stock for any medicine packaged more than 1-per-box.
                $unitsReceived = $boxesReceived * (int) $po['units_per_box'];
                $stock = $conn->prepare("UPDATE inventory_medicines SET current_stock = current_stock + ? WHERE medicine_id = ?");
                $stock->bind_param("ii", $unitsReceived, $po['medicine_id']);
                $stock->execute();
                $stock->close();

                // Mirrors what admin's own advance_po_status does on
                // reaching 'completed' — keeps purchase_requests in sync
                // regardless of which of the two paths closed the PO out.
                $req = $conn->prepare("UPDATE purchase_requests SET status = 'completed' WHERE request_id = ?");
                $req->bind_param("i", $po['request_id']);
                $req->execute();
                $req->close();

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                respond(['success' => false, 'error' => 'Could not record this delivery. Please try again.'], 500);
            }

            respond([
                'success' => true,
                'message' => 'Delivery confirmed — inventory updated.',
                'boxes_received' => $boxesReceived,
                'units_received' => $unitsReceived,
            ]);
            break;
        }

    default:
        respond(['success' => false, 'error' => 'Unknown action.'], 400);
}
