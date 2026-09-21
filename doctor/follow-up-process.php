<?php
// doctor/follow-up-process.php
// Handles the POST from the "Schedule New Follow-Up" form on follow-up.php.

require_once '../includes/auth_guard.php';
require_role('doctor');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/notifications.php';

function backToFollowUps($type, $message)
{
    $_SESSION['followup_flash'] = ["type" => $type, "message" => $message];
    header("Location: follow-up.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: follow-up.php");
    exit;
}

require_csrf('follow-up.php', 'followup_flash');

$doctorId = (int) $_SESSION['user_id'];
$appointmentId = isset($_POST['appointment_id']) ? (int) $_POST['appointment_id'] : 0;
$patientName = trim($_POST['patient_name'] ?? '');
$originalVisit = trim($_POST['original_visit'] ?? '');
$followupDate = trim($_POST['followup_date'] ?? '');
$followupTime = trim($_POST['followup_time'] ?? '');
$reason = trim($_POST['reason'] ?? '');

// --- Validation -----------------------------------------------------

if ($patientName === '' || $followupDate === '' || $reason === '') {
    backToFollowUps("error", "Patient name, follow-up date, and reason are required.");
}

// The form only collects a free-text patient name (no patient picker yet),
// so we look up the matching patient record here rather than trusting an
// arbitrary patient_id from the client. If the name doesn't match exactly
// one patient on file, we can't safely link this follow-up to a real
// patient_id, so we reject rather than guessing.
$stmt = $conn->prepare(
    "SELECT user_id FROM users
     WHERE role = 'patient' AND CONCAT(first_name, ' ', last_name) = ?"
);
$stmt->bind_param("s", $patientName);
$stmt->execute();
$matches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (count($matches) === 0) {
    backToFollowUps("error", "No patient named \"$patientName\" was found. Check the spelling and try again.");
}

if (count($matches) > 1) {
    backToFollowUps("error", "More than one patient is named \"$patientName\". This form can't tell them apart yet - please schedule the follow-up from that patient's record instead.");
}

$patientId = (int) $matches[0]['user_id'];
$originalVisitValue = $originalVisit === '' ? null : $originalVisit;
$followupTimeValue = $followupTime === '' ? null : $followupTime;

// appointment_id is optional (only present when this form was reached via
// Consultation's "Follow-up Recommended" outcome). If present, confirm it's
// actually this doctor's appointment for this patient before trusting it -
// it arrived via a hidden field, so treat it the same as any other input.
$originalAppointmentIdValue = null;
if ($appointmentId > 0) {
    $stmt = $conn->prepare(
        "SELECT appointment_id FROM appointments WHERE appointment_id = ? AND doctor_id = ? AND patient_id = ? LIMIT 1"
    );
    $stmt->bind_param("iii", $appointmentId, $doctorId, $patientId);
    $stmt->execute();
    $ownedAppointment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($ownedAppointment) {
        $originalAppointmentIdValue = $appointmentId;
    }
}

// --- Save -------------------------------------------------------------

$stmt = $conn->prepare(
    "INSERT INTO follow_ups (patient_id, doctor_id, original_appointment_id, original_visit_date, followup_date, followup_time, reason, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, 'scheduled')"
);
$stmt->bind_param("iiissss", $patientId, $doctorId, $originalAppointmentIdValue, $originalVisitValue, $followupDate, $followupTimeValue, $reason);
$stmt->execute();
$stmt->close();

$followupDateDisplay = date('M j, Y', strtotime($followupDate));
create_notification(
    $conn,
    $patientId,
    "A follow-up visit has been scheduled for {$followupDateDisplay}.",
    // FIXED 2026-08-08: used to link to appointment-history.php, which is
    // past-visits-only by design (see that file's own query) and never
    // had any idea follow_ups existed. dashboard.php now actually
    // displays upcoming follow-ups (see its own header comment), so this
    // link finally goes somewhere that shows what it promises.
    "dashboard.php"
);

backToFollowUps("success", "Follow-up scheduled for $patientName.");
