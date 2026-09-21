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
$dischargeStatus = $_POST['discharge_status'] ?? '';
$finalDiagnosis = trim($_POST['final_diagnosis'] ?? '');
$dischargeMedications = trim($_POST['discharge_medications'] ?? '');
$followUpDateRaw = trim($_POST['follow_up_date'] ?? '');
$followUpInstructions = trim($_POST['follow_up_instructions'] ?? '');

// All four are optional - a DAMA or deceased discharge in particular may
// genuinely have none of these to record, so this form must not force
// them the way the outcome radio itself is required.
if (mb_strlen($finalDiagnosis) > 255) {
    $finalDiagnosis = mb_substr($finalDiagnosis, 0, 255);
}
if (mb_strlen($followUpInstructions) > 255) {
    $followUpInstructions = mb_substr($followUpInstructions, 0, 255);
}
$followUpDate = ($followUpDateRaw !== '' && strtotime($followUpDateRaw) !== false) ? $followUpDateRaw : null;

// Itemized "Medications to Continue" (see 023_confinement_discharge_
// medications.sql and confinement-record.php's medicine picker) are read
// here but resolved against the catalog further down, after the
// ownership/ongoing check confirms this is actually a valid, dischargeable
// confinement belonging to this doctor.
$medicineIdsRaw = $_POST['medicine_id'] ?? [];
$dischargeMedQuantities = $_POST['quantity'] ?? [];
$dischargeMedInstructions = $_POST['instructions'] ?? [];

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

// Same server-side name-resolution pattern as doctor/consultation-
// process.php: the medicine name stored is always resolved from the
// submitted medicine_id, never trusted as free text from the client.
// Optional as a whole - a DAMA/deceased discharge, or simply a patient
// who needs no take-home medicine, can have zero rows here.
$dischargeMedRows = [];
foreach ($medicineIdsRaw as $i => $rawId) {
    $medicineId = (int) $rawId;
    if ($medicineId <= 0) {
        continue;
    }
    $dischargeMedRows[] = [
        "medicine_id" => $medicineId,
        "quantity" => trim($dischargeMedQuantities[$i] ?? ''),
        "instructions" => trim($dischargeMedInstructions[$i] ?? ''),
    ];
}

$resolvedMedNames = [];
if (!empty($dischargeMedRows)) {
    $idsToResolve = array_unique(array_column($dischargeMedRows, 'medicine_id'));
    $placeholders = implode(',', array_fill(0, count($idsToResolve), '?'));
    $types = str_repeat('i', count($idsToResolve));
    $stmt = $conn->prepare("SELECT medicine_id, name FROM inventory_medicines WHERE medicine_id IN ($placeholders)");
    $stmt->bind_param($types, ...$idsToResolve);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($medRow = $res->fetch_assoc()) {
        $resolvedMedNames[(int) $medRow['medicine_id']] = $medRow['name'];
    }
    $stmt->close();

    foreach ($dischargeMedRows as $medRowToCheck) {
        if (!isset($resolvedMedNames[$medRowToCheck['medicine_id']])) {
            backToRecord($confinementId, "error", "One of the selected medicines is no longer available in the catalog. Please review the discharge medications.");
        }
    }
}

$conn->begin_transaction();
try {
    $stmt = $conn->prepare(
        "UPDATE confinements
         SET discharge_date = ?, discharge_status = ?, final_diagnosis = ?,
             discharge_medications = ?, follow_up_date = ?, follow_up_instructions = ?
         WHERE confinement_id = ?"
    );
    $finalDiagnosisValue = $finalDiagnosis === '' ? null : $finalDiagnosis;
    $dischargeMedicationsValue = $dischargeMedications === '' ? null : $dischargeMedications;
    $followUpInstructionsValue = $followUpInstructions === '' ? null : $followUpInstructions;
    $stmt->bind_param(
        "ssssssi",
        $today,
        $dischargeStatus,
        $finalDiagnosisValue,
        $dischargeMedicationsValue,
        $followUpDate,
        $followUpInstructionsValue,
        $confinementId
    );
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("UPDATE users SET status = ? WHERE user_id = ?");
    $stmt->bind_param("si", $newUserStatus, $patientId);
    $stmt->execute();
    $stmt->close();

    if (!empty($dischargeMedRows)) {
        $stmt = $conn->prepare(
            "INSERT INTO confinement_discharge_medications (confinement_id, medicine_name, medicine_id, quantity, instructions)
             VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($dischargeMedRows as $medRowToInsert) {
            $medName = $resolvedMedNames[$medRowToInsert['medicine_id']];
            $qtyValue = $medRowToInsert['quantity'] === '' ? null : $medRowToInsert['quantity'];
            $instrValue = $medRowToInsert['instructions'] === '' ? null : $medRowToInsert['instructions'];
            $stmt->bind_param("isiss", $confinementId, $medName, $medRowToInsert['medicine_id'], $qtyValue, $instrValue);
            $stmt->execute();
        }
        $stmt->close();
    }

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    backToRecord($confinementId, "error", "Something went wrong while discharging the patient. Please try again.");
}

// Audit trail entry for prescribing activity - same reasoning as
// doctor/consultation-process.php's own copy of this comment. Best-effort
// and non-blocking: a logging failure must never undo an already-
// committed discharge.
if (!empty($dischargeMedRows)) {
    $rxDetails = array_map(
        static fn(array $row): string => $resolvedMedNames[$row['medicine_id']] . ($row['quantity'] !== '' ? " ({$row['quantity']})" : ''),
        $dischargeMedRows
    );
    write_audit_log($conn, $doctorId, 'doctor', 'discharge_medications_prescribed', 'prescribing', "Confinement #{$confinementId}: " . implode('; ', $rxDetails), $patientId);
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
