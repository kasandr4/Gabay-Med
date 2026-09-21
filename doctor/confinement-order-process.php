<?php
// doctor/confinement-order-process.php
// Handles the "Add Order" form on confinement-record.php's Doctor's
// Orders section - diet, activity level, IV fluids, or medication
// orders. Distinct from confinement-note-process.php's free-text
// progress note, and distinct from pharmacy dispensing (this records
// what was ORDERED, not what was actually given).
//
// Only valid while the confinement is still ongoing.

require_once '../includes/auth_guard.php';
require_role('doctor');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/audit_log.php';

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
$orderType = $_POST['order_type'] ?? '';
$description = trim($_POST['description'] ?? '');
$medicineIdRaw = (int) ($_POST['medicine_id'] ?? 0);

$validTypes = ['diet', 'activity', 'iv_fluids', 'medication'];

if ($confinementId <= 0) {
    header("Location: confined-patients.php");
    exit;
}

if (!in_array($orderType, $validTypes, true)) {
    backToRecord($confinementId, "error", "Please select a valid order type.");
}

if ($description === '') {
    backToRecord($confinementId, "error", "Please describe the order before saving.");
}
if (mb_strlen($description) > 255) {
    $description = mb_substr($description, 0, 255);
}

// Catalog reference for standardization/lookup only - see
// 024_confinement_orders_medicine_link.sql's header comment for why this
// is deliberately NOT a dispensing link. Only applies to 'medication'
// orders, and only when the doctor actually picked a catalog item; a
// medication order for something outside the catalog is still allowed,
// just with medicine_id left NULL. Same server-side name-resolution
// posture as the other prescribing forms: medicine_id is trusted, but
// only after confirming it's a real, current catalog row.
$medicineId = null;
if ($orderType === 'medication' && $medicineIdRaw > 0) {
    $stmt = $conn->prepare("SELECT medicine_id FROM inventory_medicines WHERE medicine_id = ? LIMIT 1");
    $stmt->bind_param("i", $medicineIdRaw);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$found) {
        backToRecord($confinementId, "error", "The selected medicine is no longer available in the catalog. Please review the order.");
    }
    $medicineId = $medicineIdRaw;
}

// Ownership + ongoing check, same pattern as confinement-note-process.php.
$stmt = $conn->prepare(
    "SELECT confinement_id, patient_id FROM confinements
     WHERE confinement_id = ? AND attending_doctor_id = ? AND discharge_date IS NULL LIMIT 1"
);
$stmt->bind_param("ii", $confinementId, $doctorId);
$stmt->execute();
$confinement = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$confinement) {
    backToRecord($confinementId, "error", "This confinement record is not available to update (it may already be discharged).");
}

$stmt = $conn->prepare(
    "INSERT INTO confinement_orders (confinement_id, doctor_id, order_type, description, medicine_id)
     VALUES (?, ?, ?, ?, ?)"
);
$stmt->bind_param("iissi", $confinementId, $doctorId, $orderType, $description, $medicineId);
$stmt->execute();
$stmt->close();

// Audit trail entry, same 'prescribing' module as the outpatient and
// discharge-medication flows (see doctor/consultation-process.php's own
// copy of this comment) - but only for actual medication orders, not
// diet/activity/iv_fluids, since those aren't prescribing. Best-effort
// and non-blocking: a logging failure must never undo an already-saved
// order.
if ($orderType === 'medication') {
    write_audit_log($conn, $doctorId, 'doctor', 'inpatient_medication_ordered', 'prescribing', "Confinement #{$confinementId}: {$description}", (int) $confinement['patient_id']);
}

backToRecord($confinementId, "success", "Order added.");
