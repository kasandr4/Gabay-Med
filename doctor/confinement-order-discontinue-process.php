<?php
// doctor/confinement-order-discontinue-process.php
// Marks one confinement_orders row as 'discontinued'. Separate file from
// confinement-order-process.php (which only adds), matching this
// directory's one-file-per-action convention (see confinement-note-
// process.php vs confinement-discharge-process.php).
//
// Only valid while the confinement is still ongoing - discontinuing an
// order on an already-discharged stay doesn't mean anything.

require_once '../includes/auth_guard.php';
require_role('doctor');
require_once '../config/db.php';
require_once '../includes/csrf.php';

function backToRecord($confinementId, $type, $message)
{
    $_SESSION['confinement_record_flash'] = ["type" => $type, "message" => $message];
    header("Location: confinement-record.php?confinement_id=" . (int) $confinementId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: confined-patients.php");
    exit;
}

require_csrf('confined-patients.php', 'confinement_record_flash');

$doctorId = (int) $_SESSION['user_id'];
$confinementId = isset($_POST['confinement_id']) ? (int) $_POST['confinement_id'] : 0;
$orderId = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;

if ($confinementId <= 0 || $orderId <= 0) {
    header("Location: confined-patients.php");
    exit;
}

// Ownership + ongoing check on the confinement, PLUS confirming the order
// actually belongs to that same confinement - a doctor can't discontinue
// an order_id that belongs to a different patient's stay just by guessing
// an ID, even one of their own patients'.
$stmt = $conn->prepare(
    "SELECT co.order_id
     FROM confinement_orders co
     JOIN confinements c ON c.confinement_id = co.confinement_id
     WHERE co.order_id = ? AND co.confinement_id = ?
       AND c.attending_doctor_id = ? AND c.discharge_date IS NULL
       AND co.status = 'active'
     LIMIT 1"
);
$stmt->bind_param("iii", $orderId, $confinementId, $doctorId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    backToRecord($confinementId, "error", "That order could not be discontinued (it may already be discontinued, or this confinement may already be discharged).");
}

$stmt = $conn->prepare(
    "UPDATE confinement_orders SET status = 'discontinued', discontinued_at = NOW() WHERE order_id = ?"
);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$stmt->close();

backToRecord($confinementId, "success", "Order discontinued.");
