<?php
// doctor/confine-patient-process.php
// Handles the POST from confined-patients.php (admit mode): creates the confinements row,
// an optional first progress note, and sets users.status = 'confined'.
// This is the ONLY place a confinement is created. Nothing here discharges
// anyone - discharge is confinement-record.php's job alone.

require_once '../includes/auth_guard.php';
require_role('doctor');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/notifications.php';

function backToAdmission($patientId, $appointmentId, $type, $message)
{
    $_SESSION['confine_flash'] = ["type" => $type, "message" => $message];
    header("Location: confined-patients.php?patient_id=" . (int) $patientId . "&appointment_id=" . (int) $appointmentId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: confined-patients.php");
    exit;
}

require_csrf('confined-patients.php', 'confine_flash');

$doctorId = (int) $_SESSION['user_id'];
$patientId = isset($_POST['patient_id']) ? (int) $_POST['patient_id'] : 0;
$appointmentId = isset($_POST['appointment_id']) ? (int) $_POST['appointment_id'] : 0;
$roomLocation = trim($_POST['room_location'] ?? '');
$clinicalStatus = $_POST['clinical_status'] ?? 'stable';
$admissionNote = trim($_POST['admission_note'] ?? '');

$validStatuses = ['stable', 'improving', 'critical'];

if ($patientId <= 0) {
    header("Location: confined-patients.php");
    exit;
}

if ($roomLocation === '') {
    backToAdmission($patientId, $appointmentId, "error", "Room / Location is required.");
}

if (!in_array($clinicalStatus, $validStatuses, true)) {
    $clinicalStatus = 'stable';
}

// Confirm this is actually a patient, and that they're currently active -
// a blocked or deceased patient (or one already confined, checked below)
// should never be admitted.
$stmt = $conn->prepare("SELECT user_id, status FROM users WHERE user_id = ? AND role = 'patient' LIMIT 1");
$stmt->bind_param("i", $patientId);
$stmt->execute();
$patientRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$patientRow) {
    backToAdmission($patientId, $appointmentId, "error", "That patient could not be found.");
}

if ($patientRow['status'] !== 'active') {
    backToAdmission(
        $patientId,
        $appointmentId,
        "error",
        "This patient's account status is currently \"" . $patientRow['status'] . "\" and cannot be admitted to confinement."
    );
}

// Re-check for an ongoing confinement (race condition guard - e.g. two tabs
// submitting the admission form for the same patient).
$stmt = $conn->prepare(
    "SELECT confinement_id FROM confinements WHERE patient_id = ? AND discharge_date IS NULL LIMIT 1"
);
$stmt->bind_param("i", $patientId);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    $_SESSION['confine_flash'] = [
        "type" => "error",
        "message" => "This patient already has an ongoing confinement.",
    ];
    header("Location: confinement-record.php?confinement_id=" . (int) $existing['confinement_id']);
    exit;
}

// Authoritative emergency-contact check. confined-patients.php already
// hides the admission form until this is filled in, but that's a UI
// convenience only - the same rule this app applies everywhere else
// (e.g. consultation-process.php re-checking checked_in_at) means this
// must also be enforced here, in case this endpoint is POSTed to directly.
$stmt = $conn->prepare(
    "SELECT emergency_contact_name, emergency_contact_number
     FROM patient_profiles WHERE patient_id = ? LIMIT 1"
);
$stmt->bind_param("i", $patientId);
$stmt->execute();
$profileRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

$hasEmergencyContact = $profileRow
    && trim($profileRow['emergency_contact_name'] ?? '') !== ''
    && trim($profileRow['emergency_contact_number'] ?? '') !== '';

if (!$hasEmergencyContact) {
    backToAdmission(
        $patientId,
        $appointmentId,
        "error",
        "This patient needs an emergency contact on file before they can be admitted."
    );
}

$conn->begin_transaction();

try {
    $today = date('Y-m-d');
    $stmt = $conn->prepare(
        "INSERT INTO confinements (patient_id, attending_doctor_id, date_confined, room_location, clinical_status)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("iisss", $patientId, $doctorId, $today, $roomLocation, $clinicalStatus);
    $stmt->execute();
    $confinementId = $stmt->insert_id;
    $stmt->close();

    if ($admissionNote !== '') {
        $stmt = $conn->prepare(
            "INSERT INTO confinement_notes (confinement_id, doctor_id, note) VALUES (?, ?, ?)"
        );
        $stmt->bind_param("iis", $confinementId, $doctorId, $admissionNote);
        $stmt->execute();
        $stmt->close();
    }

    // This is the one place a patient's status flips to 'confined'.
    $stmt = $conn->prepare("UPDATE users SET status = 'confined' WHERE user_id = ?");
    $stmt->bind_param("i", $patientId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    backToAdmission($patientId, $appointmentId, "error", "Something went wrong while admitting the patient. Please try again.");
}

create_notification(
    $conn,
    $patientId,
    "You have been admitted to confinement (Room: {$roomLocation}). Your care team will keep you updated.",
    "confinement-dashboard.php"
);

$_SESSION['confine_flash'] = [
    "type" => "success",
    "message" => "Patient admitted to confinement.",
];
header("Location: confinement-record.php?confinement_id=" . $confinementId);
exit;
