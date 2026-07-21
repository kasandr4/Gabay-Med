<?php
// doctor/confinement-note-process.php
// Handles the "Add Progress Note" form: an optional note and/or a
// clinical_status update. Only valid while the confinement is still
// ongoing - once discharged, this record is read-only.

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
$note = trim($_POST['note'] ?? '');
$clinicalStatus = $_POST['clinical_status'] ?? '';

$validStatuses = ['stable', 'improving', 'critical'];

if ($confinementId <= 0) {
    header("Location: confined-patients.php");
    exit;
}

// Ownership + ongoing check.
$stmt = $conn->prepare(
    "SELECT confinement_id, clinical_status FROM confinements
     WHERE confinement_id = ? AND attending_doctor_id = ? AND discharge_date IS NULL LIMIT 1"
);
$stmt->bind_param("ii", $confinementId, $doctorId);
$stmt->execute();
$confinement = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$confinement) {
    backToRecord($confinementId, "error", "This confinement record is not available to update (it may already be discharged).");
}

if (!in_array($clinicalStatus, $validStatuses, true)) {
    $clinicalStatus = $confinement['clinical_status'];
}

if ($note === '' && $clinicalStatus === $confinement['clinical_status']) {
    backToRecord($confinementId, "error", "Add a note or change the status before saving.");
}

$conn->begin_transaction();
try {
    if ($note !== '') {
        $stmt = $conn->prepare(
            "INSERT INTO confinement_notes (confinement_id, doctor_id, note) VALUES (?, ?, ?)"
        );
        $stmt->bind_param("iis", $confinementId, $doctorId, $note);
        $stmt->execute();
        $stmt->close();
    }

    if ($clinicalStatus !== $confinement['clinical_status']) {
        $stmt = $conn->prepare("UPDATE confinements SET clinical_status = ? WHERE confinement_id = ?");
        $stmt->bind_param("si", $clinicalStatus, $confinementId);
        $stmt->execute();
        $stmt->close();
    }

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    backToRecord($confinementId, "error", "Something went wrong while saving. Please try again.");
}

backToRecord($confinementId, "success", "Progress note saved.");
