<?php
// doctor/confinement-referral-process.php
// Handles the "Log Referral" form on confinement-record.php - a simple
// record of "referred to [facility/specialist], reason: [text]", e.g.
// referring up to a provincial or regional hospital when a case exceeds
// OMCDH's capacity. See 023_confinement_referrals.sql for why this is
// deliberately a log, not a tracked workflow (no status/acceptance).
//
// Only valid while the confinement is still ongoing, same rule as every
// other confinement action.

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
$referredTo = trim($_POST['referred_to'] ?? '');
$reason = trim($_POST['reason'] ?? '');

if ($confinementId <= 0) {
    header("Location: confined-patients.php");
    exit;
}

if ($referredTo === '' || $reason === '') {
    backToRecord($confinementId, "error", "Please enter both where the patient was referred and the reason.");
}
if (mb_strlen($referredTo) > 255) {
    $referredTo = mb_substr($referredTo, 0, 255);
}

// Ownership + ongoing check, same pattern as every other confinement
// action file.
$stmt = $conn->prepare(
    "SELECT confinement_id FROM confinements
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
    "INSERT INTO confinement_referrals (confinement_id, doctor_id, referred_to, reason)
     VALUES (?, ?, ?, ?)"
);
$stmt->bind_param("iiss", $confinementId, $doctorId, $referredTo, $reason);
$stmt->execute();
$stmt->close();

backToRecord($confinementId, "success", "Referral logged.");
