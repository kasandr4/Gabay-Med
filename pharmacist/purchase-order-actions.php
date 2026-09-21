<?php
// Buffer all output from this point on. If anything (a stray PHP
// warning/notice from this file, an include, or a different php.ini's
// display_errors=On setting) prints text before we're ready to send
// our JSON response, respond() below discards it -- so the client
// always gets valid JSON back, never JSON prefixed with HTML error
// output that breaks JSON.parse() in the browser.
ob_start();
require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/audit_log.php';

$pharmacistId = (int) $_SESSION['user_id'];

function respond($data, $status = 200)
{
    // Discard any buffered output (stray warnings/notices) so only
    // clean JSON ever reaches the client.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// REWORKED 2026-09-20: 'create' and 'parse_upload' moved to capitol/
// purchase-order-actions.php entirely - the pharmacist no longer
// creates a PO at all (see pharmacist/purchase-orders.php's own header
// comment, and 027_capitol_role_and_po_handoff.sql). This file now only
// handles 'cancel', which the pharmacist may still reasonably need if
// an order Capitol sent them falls through before delivery.

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

if ($action === 'cancel') {
    $poId = (int) ($body['po_id'] ?? 0);
    if ($poId <= 0) {
        respond(['success' => false, 'error' => 'Missing purchase order.'], 422);
    }
    $stmt = $conn->prepare("UPDATE purchase_orders SET status = 'cancelled' WHERE po_id = ? AND status = 'open'");
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected === 0) {
        respond(['success' => false, 'error' => 'This order can no longer be cancelled.'], 409);
    }
    write_audit_log($conn, $pharmacistId, 'pharmacist', 'purchase_order_cancelled', 'procurement', "Cancelled purchase order {$poId}.");
    respond(['success' => true]);
}

respond(['success' => false, 'error' => 'Unknown action.'], 400);
