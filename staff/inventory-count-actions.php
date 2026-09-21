<?php
// staff/inventory-count-actions.php
// JSON action endpoint backing inventory-count.php's Save Progress and
// Submit buttons. Mirrors the conventions already established across
// this app's other action endpoints: single action-routed JSON endpoint,
// CSRF-checked, prepared statements only, ownership-checked against
// assigned_to so one staff member can't post counts onto another's batch.
//
// save_progress can be partial by design — a staff member can count a
// few lines, save, and come back later; the batch stays 'pending' until
// Submit. submit_batch requires every line to have a non-null
// staff_count first (server-side, not just a disabled button — the same
// "don't trust the client" principle used throughout this app).

require_once '../includes/auth_guard.php';
require_role('staff');
require_staff_type('inventory');
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
$staffId = (int) $_SESSION['user_id'];

/**
 * Shared ownership + status guard: fetches the batch and confirms it's
 * both assigned to this staff member and still 'pending' (can't touch a
 * batch that's already been submitted/confirmed).
 */
function findOwnedPendingBatch(mysqli $conn, int $batchId, int $staffId): ?array
{
    $stmt = $conn->prepare(
        "SELECT batch_id, batch_number, assigned_by, status FROM inventory_count_batches WHERE batch_id = ? AND assigned_to = ?"
    );
    $stmt->bind_param("ii", $batchId, $staffId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

switch ($action) {

    // ------------------------------------------------------------
    // Save Progress — partial counts allowed, batch stays 'pending'.
    // ------------------------------------------------------------
    case 'save_progress': {
            $batchId = (int) ($_POST['batch_id'] ?? 0);
            $counts = $_POST['staff_count'] ?? []; // item_id => value

            $batch = findOwnedPendingBatch($conn, $batchId, $staffId);
            if (!$batch) respond(['success' => false, 'error' => 'Batch not found.'], 404);
            if ($batch['status'] !== 'pending') {
                respond(['success' => false, 'error' => 'This batch has already been submitted.'], 409);
            }

            // Only touch items that actually belong to this batch — the
            // IN (...) clause is the real guard against a tampered
            // item_id key smuggled into the counts array.
            $itemsStmt = $conn->prepare("SELECT item_id FROM inventory_count_items WHERE batch_id = ?");
            $itemsStmt->bind_param("i", $batchId);
            $itemsStmt->execute();
            $validItemIds = array_column($itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'item_id');
            $itemsStmt->close();

            $upd = $conn->prepare("UPDATE inventory_count_items SET staff_count = ? WHERE item_id = ? AND batch_id = ?");
            foreach ($counts as $itemId => $value) {
                $itemId = (int) $itemId;
                if (!in_array($itemId, $validItemIds, true)) continue;
                if ($value === '' || $value === null) continue;
                $count = max(0, (int) $value);
                $upd->bind_param("iii", $count, $itemId, $batchId);
                $upd->execute();
            }
            $upd->close();

            respond(['success' => true, 'message' => 'Progress saved.']);
            break;
        }

        // ------------------------------------------------------------
        // Submit — requires every line counted first.
        // ------------------------------------------------------------
    case 'submit_batch': {
            $batchId = (int) ($_POST['batch_id'] ?? 0);
            $counts = $_POST['staff_count'] ?? [];

            $batch = findOwnedPendingBatch($conn, $batchId, $staffId);
            if (!$batch) respond(['success' => false, 'error' => 'Batch not found.'], 404);
            if ($batch['status'] !== 'pending') {
                respond(['success' => false, 'error' => 'This batch has already been submitted.'], 409);
            }

            $itemsStmt = $conn->prepare("SELECT item_id, staff_count FROM inventory_count_items WHERE batch_id = ?");
            $itemsStmt->bind_param("i", $batchId);
            $itemsStmt->execute();
            $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $itemsStmt->close();
            $validItemIds = array_column($items, 'item_id');

            $conn->begin_transaction();
            try {
                // Save whatever was on-screen at submit time first (same
                // as Save Progress), THEN verify completeness against the
                // database — not against the posted form — so a stale
                // client can't submit a batch with real gaps in it.
                $upd = $conn->prepare("UPDATE inventory_count_items SET staff_count = ? WHERE item_id = ? AND batch_id = ?");
                foreach ($counts as $itemId => $value) {
                    $itemId = (int) $itemId;
                    if (!in_array($itemId, $validItemIds, true)) continue;
                    if ($value === '' || $value === null) continue;
                    $count = max(0, (int) $value);
                    $upd->bind_param("iii", $count, $itemId, $batchId);
                    $upd->execute();
                }
                $upd->close();

                $recheck = $conn->prepare("SELECT COUNT(*) AS uncounted FROM inventory_count_items WHERE batch_id = ? AND staff_count IS NULL");
                $recheck->bind_param("i", $batchId);
                $recheck->execute();
                $uncounted = (int) $recheck->get_result()->fetch_assoc()['uncounted'];
                $recheck->close();

                if ($uncounted > 0) {
                    $conn->rollback();
                    respond(['success' => false, 'error' => "Please count all items before submitting ({$uncounted} remaining)."], 422);
                }

                $submit = $conn->prepare("UPDATE inventory_count_batches SET status = 'submitted', submitted_at = NOW() WHERE batch_id = ?");
                $submit->bind_param("i", $batchId);
                $submit->execute();
                $submit->close();

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                respond(['success' => false, 'error' => 'Could not submit this batch. Please try again.'], 500);
            }

            create_notification(
                $conn,
                $batch['assigned_by'],
                "{$batch['batch_number']} has been submitted and is ready for your review.",
                'inventory-count.php',
                'info'
            );

            respond(['success' => true, 'message' => 'Count submitted for review.']);
            break;
        }

    default:
        respond(['success' => false, 'error' => 'Unknown action.'], 400);
}
