<?php
// staff/check-in-process.php
// Commits a check-in: sets appointments.checked_in_at = NOW() for the
// given appointment, after re-validating it's still eligible.

require_once '../includes/auth_guard.php';
require_role('staff');
require_once '../config/db.php';
require_once '../includes/reference_number.php';
require_once '../includes/csrf.php';

function backToCheckIn($ref, $type, $message)
{
    $_SESSION['checkin_flash'] = ["type" => $type, "message" => $message];
    $target = $ref !== '' ? "check-in.php?reference_number=" . urlencode($ref) : "check-in.php";
    header("Location: " . $target);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: check-in.php");
    exit;
}

require_csrf('check-in.php', 'checkin_flash');

$appointmentId = isset($_POST['appointment_id']) ? (int) $_POST['appointment_id'] : 0;
$referenceNumber = trim($_POST['reference_number'] ?? '');

if ($appointmentId <= 0 || $referenceNumber === '') {
    backToCheckIn('', "error", "Missing appointment information. Please look it up again.");
}

// Re-check everything server-side — never trust the hidden form fields
// alone. Pull the appointment fresh and re-derive its reference number
// to confirm the one submitted actually belongs to it.
$stmt = $conn->prepare(
    "SELECT appointment_id, created_at, status, checked_in_at, slot_start
     FROM appointments
     WHERE appointment_id = ?
     LIMIT 1"
);
$stmt->bind_param("i", $appointmentId);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appointment || !verify_appointment_reference($referenceNumber, $appointment['appointment_id'], $appointment['created_at'])) {
    backToCheckIn($referenceNumber, "error", "That appointment could not be found.");
}

if ($appointment['checked_in_at']) {
    backToCheckIn($referenceNumber, "error", "This patient was already checked in.");
}

if (!in_array($appointment['status'], ['pending', 'confirmed'], true)) {
    backToCheckIn($referenceNumber, "error", "This appointment is " . $appointment['status'] . " and can't be checked in.");
}

if (date("Y-m-d", strtotime($appointment['slot_start'])) !== date("Y-m-d")) {
    backToCheckIn($referenceNumber, "error", "This appointment is not scheduled for today.");
}

// Early-side check-in window: symmetric with the 30-minute late grace
// period already enforced by no_show_policy.php, so the full eligible
// window is [slot_start - 30min, slot_start + 30min].
if (time() < strtotime($appointment['slot_start']) - 1800) {
    $opensAt = date("g:i A", strtotime($appointment['slot_start']) - 1800);
    backToCheckIn($referenceNumber, "error", "This appointment can't be checked in yet. Check-in opens at $opensAt (30 minutes before the appointment time).");
}

// Atomic check-and-set: the WHERE clause re-checks checked_in_at IS NULL
// in the same statement as the write, so two near-simultaneous submissions
// (e.g. a double-click, or two staff members checking in the same patient)
// can't both succeed - only the first UPDATE's row will actually match.
$stmt = $conn->prepare("UPDATE appointments SET checked_in_at = NOW() WHERE appointment_id = ? AND checked_in_at IS NULL");
$stmt->bind_param("i", $appointmentId);
$stmt->execute();
$rowsAffected = $stmt->affected_rows;
$stmt->close();

if ($rowsAffected === 0) {
    // Passed the earlier SELECT-based check, but lost the race to another
    // request that checked this appointment in first.
    backToCheckIn($referenceNumber, "error", "This patient was already checked in.");
}

$_SESSION['checkin_flash'] = ["type" => "success", "message" => "Patient checked in successfully."];
header("Location: check-in.php");
exit;
