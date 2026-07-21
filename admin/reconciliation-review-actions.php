<?php
// admin/reconciliation-review-actions.php
// JSON action endpoint backing admin/reconciliation.php's "Approve
// Adjustment" and "Return for Revision" buttons. Mirrors the pattern
// already established for pharmacist-side action endpoints
// (delivery-actions.php, exit-actions.php, reconciliation-actions.php):
// single action-routed JSON endpoint, CSRF-checked, prepared statements
// only.
//
// Requires inventory_reconciliation_review_migration.sql to have been
// run — see that file for why: no column existed to track Admin's review
// state at all.
//
// "Approve Adjustment" sets inventory_medicines.current_stock to the
// pharmacist's physical count (actual_count) for that reconciliation's
// medicine — reconciling the system to match what was physically
// counted, the standard meaning of approving a count. This is a
// deliberate simplification worth knowing about for your defense: if
// deliveries or exit scans happened between when the pharmacist
// submitted the count and Admin approves it, current_stock gets set to
// the OLD physical count, not adjusted for what's happened since. There's
// no timestamped stock ledger in this schema to reconstruct "what should
// current_stock be right now, accounting for that gap" — same limitation
// already flagged for the Daily Sales report's "Remaining Stock" column
// in pharmacist/reports.php.
//
// "Return for Revision" only changes review_status — no stock touched —
// sending it back to the pharmacist to recount and resubmit a fresh
// reconciliation entry (there's no edit-in-place; a fresh submission is
// consistent with how nothing else in this schema mutates a submitted
// record either, e.g. purchase_requests' revision_requested flow).

require_once '../includes/auth_guard.php';
require_role('admin');
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
$adminId = (int) $_SESSION['user_id'];

/**
 * Shared lookup + guard for both actions below: fetches the reconciliation
 * row (with its medicine_id) and confirms it's still review_status =
 * 'pending', so a double-click (or two admins reviewing the same record)
 * can't apply two actions to it.
 */
function findPendingReconciliation(mysqli $conn, int $reconciliationId): ?array
{
    $stmt = $conn->prepare(
        "SELECT reconciliation_id, medicine_id, actual_count, review_status
         FROM inventory_reconciliation
         WHERE reconciliation_id = ?"
    );
    $stmt->bind_param("i", $reconciliationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

switch ($action) {

    // ------------------------------------------------------------
    // Approve Adjustment
    // ------------------------------------------------------------
    case 'approve_adjustment': {
            $reconciliationId = (int) ($_POST['reconciliation_id'] ?? 0);
            if ($reconciliationId <= 0) {
                respond(['success' => false, 'error' => 'Invalid reconciliation record.'], 422);
            }

            $recon = findPendingReconciliation($conn, $reconciliationId);
            if (!$recon) {
                respond(['success' => false, 'error' => 'Reconciliation record not found.'], 404);
            }
            if ($recon['review_status'] !== 'pending') {
                respond(['success' => false, 'error' => 'This record has already been reviewed.'], 409);
            }

            $conn->begin_transaction();
            try {
                $upd = $conn->prepare(
                    "UPDATE inventory_reconciliation
                     SET review_status = 'approved', reviewed_by = ?, reviewed_at = NOW()
                     WHERE reconciliation_id = ? AND review_status = 'pending'"
                );
                $upd->bind_param("ii", $adminId, $reconciliationId);
                $upd->execute();
                $applied = $upd->affected_rows > 0;
                $upd->close();

                if (!$applied) {
                    $conn->rollback();
                    respond(['success' => false, 'error' => 'This record has already been reviewed.'], 409);
                }

                $stock = $conn->prepare("UPDATE inventory_medicines SET current_stock = ? WHERE medicine_id = ?");
                $stock->bind_param("ii", $recon['actual_count'], $recon['medicine_id']);
                $stock->execute();
                $stock->close();

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                respond(['success' => false, 'error' => 'Could not approve this record. Please try again.'], 500);
            }

            respond(['success' => true, 'message' => 'Adjustment approved \u2014 inventory updated.']);
            break;
        }

    // ------------------------------------------------------------
    // Return for Revision
    // ------------------------------------------------------------
    case 'return_for_revision': {
            $reconciliationId = (int) ($_POST['reconciliation_id'] ?? 0);
            if ($reconciliationId <= 0) {
                respond(['success' => false, 'error' => 'Invalid reconciliation record.'], 422);
            }

            $recon = findPendingReconciliation($conn, $reconciliationId);
            if (!$recon) {
                respond(['success' => false, 'error' => 'Reconciliation record not found.'], 404);
            }
            if ($recon['review_status'] !== 'pending') {
                respond(['success' => false, 'error' => 'This record has already been reviewed.'], 409);
            }

            $upd = $conn->prepare(
                "UPDATE inventory_reconciliation
                 SET review_status = 'revision_requested', reviewed_by = ?, reviewed_at = NOW()
                 WHERE reconciliation_id = ? AND review_status = 'pending'"
            );
            $upd->bind_param("ii", $adminId, $reconciliationId);
            $upd->execute();
            $applied = $upd->affected_rows > 0;
            $upd->close();

            if (!$applied) {
                respond(['success' => false, 'error' => 'This record has already been reviewed.'], 409);
            }

            respond(['success' => true, 'message' => 'Sent back to Pharmacy for a recount.']);
            break;
        }

    default:
        respond(['success' => false, 'error' => 'Unknown action.'], 400);
}
