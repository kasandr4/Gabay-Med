<?php
// doctor/confinement-discharge-process.php
// Handles the discharge form on confinement-record.php. This is the ONLY
// place in the whole application that may set confinements.discharge_date,
// confinements.discharge_status, or move a patient's users.status off of
// 'confined'. Consultation, follow-ups, etc. must never do this.

require_once '../includes/auth_guard.php';
require_role('doctor');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/notifications.php';

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
$dischargeStatus = $_POST['discharge_status'] ?? '';

// Recovered/Transferred/DAMA all return the patient to normal 'active'
// standing; only Deceased maps differently. This mapping lives here and
// nowhere else.
$dischargeToUserStatus = [
    'recovered'   => 'active',
    'transferred' => 'active',
    'dama'        => 'active',
    'deceased'    => 'deceased',
];

if ($confinementId <= 0) {
    header("Location: confined-patients.php");
    exit;
}

if (!isset($dischargeToUserStatus[$dischargeStatus])) {
    backToRecord($confinementId, "error", "Please select a discharge outcome.");
}

// Ownership + ongoing check - can't discharge someone twice, and can't
// discharge another doctor's patient.
$stmt = $conn->prepare(
    "SELECT patient_id FROM confinements
     WHERE confinement_id = ? AND attending_doctor_id = ? AND discharge_date IS NULL LIMIT 1"
);
$stmt->bind_param("ii", $confinementId, $doctorId);
$stmt->execute();
$confinement = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$confinement) {
    backToRecord($confinementId, "error", "This confinement record could not be discharged (it may already be discharged).");
}

$patientId = (int) $confinement['patient_id'];
$newUserStatus = $dischargeToUserStatus[$dischargeStatus];
$today = date('Y-m-d');

$conn->begin_transaction();
try {
    $stmt = $conn->prepare(
        "UPDATE confinements SET discharge_date = ?, discharge_status = ? WHERE confinement_id = ?"
    );
    $stmt->bind_param("ssi", $today, $dischargeStatus, $confinementId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("UPDATE users SET status = ? WHERE user_id = ?");
    $stmt->bind_param("si", $newUserStatus, $patientId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    backToRecord($confinementId, "error", "Something went wrong while discharging the patient. Please try again.");
}

// Skip notifying for 'deceased' - a "you've been discharged" message
// doesn't make sense for that outcome.
if ($dischargeStatus !== 'deceased') {
    create_notification(
        $conn,
        $patientId,
        "You have been discharged from confinement.",
        "dashboard.php"
    );
}

backToRecord($confinementId, "success", "Patient discharged.");
