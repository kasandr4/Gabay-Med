<?php
// capitol/purchase-request-actions.php
// NEW (2026-09-20) - the one action Capitol's PR queue was missing:
// pushing a request back to the pharmacist instead of either creating a
// PO or leaving it sitting in the queue forever. Reuses the SAME
// 'rejected' status and rejected_at/rejection_reason columns the
// pharmacist-side Chief-of-Hospital rejection already uses (see
// purchase-request-actions.php's own 'reject' action) rather than
// inventing a parallel status - "use the existing status architecture
// where possible" per the original spec. The two are told apart by
// WHICH status this is allowed to fire from (sent_to_capitol only, vs
// the Chief-of-Hospital path which only fires on open/draft) and by
// whether sent_to_capitol_at is set on the resulting row - see
// pharmacist/purchase-requests.php's own note-rendering logic for where
// that distinction actually shows up to the pharmacist.

ob_start();
require_once '../includes/auth_guard.php';
require_role('capitol');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/audit_log.php';
require_once '../includes/notifications.php';

$capitolId = (int) $_SESSION['user_id'];

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

$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];
$action = $body['action'] ?? '';
$submittedToken = $body['csrf_token'] ?? '';

$expectedToken = $_SESSION['csrf_token'] ?? '';
if ($expectedToken === '' || !hash_equals($expectedToken, (string) $submittedToken)) {
    respond(['success' => false, 'error' => 'Your session expired. Please refresh the page and try again.'], 403);
}

if ($action === 'return_to_pharmacist') {
    $prId = (int) ($body['pr_id'] ?? 0);
    $reason = trim((string) ($body['reason'] ?? ''));

    if ($prId <= 0) {
        respond(['success' => false, 'error' => 'Missing purchase request.'], 422);
    }
    if ($reason === '') {
        respond(['success' => false, 'error' => 'Enter a reason so the pharmacist knows what to fix.'], 422);
    }
    if (strlen($reason) > 255) {
        respond(['success' => false, 'error' => 'Reason is too long (255 characters max).'], 422);
    }

    $stmt = $conn->prepare(
        "UPDATE purchase_requests SET status = 'rejected', rejected_at = NOW(), rejection_reason = ?
         WHERE pr_id = ? AND status = 'sent_to_capitol'"
    );
    $stmt->bind_param('si', $reason, $prId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        respond(['success' => false, 'error' => "This request is no longer awaiting action - it may have already been converted or returned."], 409);
    }

    $prNoStmt = $conn->prepare("SELECT pr_no FROM purchase_requests WHERE pr_id = ?");
    $prNoStmt->bind_param('i', $prId);
    $prNoStmt->execute();
    $prNo = $prNoStmt->get_result()->fetch_assoc()['pr_no'] ?? "PR #{$prId}";
    $prNoStmt->close();

    // Same "notify every active pharmacist" pattern as send_to_pharmacist
    // in capitol/purchase-order-actions.php - procurement in this
    // hospital isn't scoped to one individual pharmacist account.
    $pharmacistsRes = $conn->query("SELECT user_id FROM users WHERE role = 'pharmacist' AND is_active = 1");
    if ($pharmacistsRes) {
        while ($pharmacistRow = $pharmacistsRes->fetch_assoc()) {
            create_notification(
                $conn,
                (int) $pharmacistRow['user_id'],
                "Capitol returned {$prNo}: {$reason}",
                '../pharmacist/purchase-requests.php'
            );
        }
    }

    write_audit_log($conn, $capitolId, 'capitol', 'purchase_request_returned', 'procurement', "Returned {$prNo} to the pharmacist: {$reason}");
    respond(['success' => true]);
}

respond(['success' => false, 'error' => 'Unknown action.'], 400);
