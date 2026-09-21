<?php
// staff/lab-queue-actions.php
// JSON action endpoint backing staff/lab-queue.php's Accept/Complete
// buttons. Mirrors the *-actions.php pattern used everywhere else in
// this app (see admin/doctor-schedule-actions.php): action-routed,
// CSRF-checked, prepared statements only, JSON on auth failure (handled
// automatically by require_role()/require_staff_type() since this
// filename ends in "-actions.php" — see is_json_action_endpoint() in
// includes/auth_guard.php).
//
// Each action re-validates the lab_order row's CURRENT status itself
// (not trusting the page the click came from) before writing — the same
// "never trust the client" guarantee every other action endpoint in this
// app already follows. That also means two lab staff clicking Accept on
// the same order within the same second can't both "win" — only the one
// whose UPDATE actually matched a still-pending row succeeds.

require_once '../includes/auth_guard.php';
require_role('staff');
require_staff_type('laboratory');
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

$staff_id = (int) $_SESSION['user_id'];
$labOrderId = (int) ($_POST['lab_order_id'] ?? 0);
$action = $_POST['action'] ?? '';

if ($labOrderId <= 0) {
    respond(['success' => false, 'error' => 'Please select a valid lab order.'], 422);
}

switch ($action) {

    case 'accept_lab_order': {
            $stmt = $conn->prepare("UPDATE lab_orders SET status = 'accepted', accepted_by = ?, accepted_at = NOW() WHERE lab_order_id = ? AND status = 'pending'");
            $stmt->bind_param("ii", $staff_id, $labOrderId);
            $stmt->execute();
            $applied = $stmt->affected_rows > 0;
            $stmt->close();

            if (!$applied) {
                respond(['success' => false, 'error' => 'This test was already accepted by someone else. Please refresh the page.'], 409);
            }

            respond(['success' => true]);
        }

    case 'complete_lab_order': {
            $stmt = $conn->prepare("
                SELECT lo.patient_id, lo.doctor_id, lo.confinement_id, lo.test_name,
                       CONCAT(pat.first_name, ' ', pat.last_name) AS patient_name
                FROM lab_orders lo
                JOIN users pat ON pat.user_id = lo.patient_id
                WHERE lo.lab_order_id = ? AND lo.status = 'accepted'
                LIMIT 1
            ");
            $stmt->bind_param("i", $labOrderId);
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$order) {
                respond(['success' => false, 'error' => 'This test must be accepted before it can be marked complete. Please refresh the page.'], 409);
            }

            $stmt = $conn->prepare("UPDATE lab_orders SET status = 'completed', completed_by = ?, completed_at = NOW() WHERE lab_order_id = ? AND status = 'accepted'");
            $stmt->bind_param("ii", $staff_id, $labOrderId);
            $stmt->execute();
            $applied = $stmt->affected_rows > 0;
            $stmt->close();

            if (!$applied) {
                respond(['success' => false, 'error' => 'This test could not be completed — it may have changed since the page loaded. Please refresh.'], 409);
            }

            create_notification(
                $conn,
                (int) $order['patient_id'],
                "Your results for {$order['test_name']} are ready. Please visit the Laboratory to receive them.",
                'lab-orders.php'
            );

            // The ordering doctor hears about it too - previously only the
            // patient was notified, so the doctor never learned a result
            // was ready. Inpatient orders link to that confinement record;
            // outpatient ones to the doctor's visit history.
            $doctorLink = $order['confinement_id'] !== null
                ? 'confinement-record.php?confinement_id=' . (int) $order['confinement_id']
                : 'appointment-history.php';
            create_notification(
                $conn,
                (int) $order['doctor_id'],
                "Lab results ready: {$order['test_name']} for " . trim($order['patient_name']) . ".",
                $doctorLink
            );

            respond(['success' => true]);
        }

    default:
        respond(['success' => false, 'error' => 'Unknown action.'], 400);
}
