<?php
// staff/check-in-process.php
// Commits a check-in: sets appointments.checked_in_at = NOW() for the
// given appointment, after re-validating it's still eligible.
//
// REAL (2026-07-27): also persists priority ID verification now, if this
// patient's priority isn't already verified — previously the checkbox on
// check-in.php was required to submit but never actually read here. See
// 007_add_priority_verification.sql and staff/edit-priority.php's header
// comment for the full picture (verification can happen here, at
// edit-priority.php, or wherever it happens first — same fields either
// way, so they can't drift out of sync).

require_once '../includes/auth_guard.php';
require_role('staff');
require_staff_type('front_desk');
require_once '../config/db.php';
require_once '../includes/reference_number.php';
require_once '../includes/csrf.php';
require_once '../includes/notifications.php';

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
    "SELECT a.appointment_id, a.created_at, a.status, a.checked_in_at, a.slot_start, a.patient_id, a.doctor_id,
            u.priority_type, u.priority_status, u.first_name AS patient_first, u.last_name AS patient_last,
            doc.first_name AS doctor_first, doc.last_name AS doctor_last
     FROM appointments a
     JOIN users u ON u.user_id = a.patient_id
     JOIN users doc ON doc.user_id = a.doctor_id
     WHERE a.appointment_id = ?
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

// REAL (2026-07-27): priority verification now actually persists — see
// 007_add_priority_verification.sql and staff/edit-priority.php's header
// comment. If this patient's priority isn't already verified (from a
// previous visit, or via edit-priority.php), the checkbox + ID number
// are required — re-checked server-side here too, not just via the
// form's `required` attributes, same "never trust the client" principle
// as everything else on this page.
$needsVerification = $appointment['priority_type'] !== 'regular' && $appointment['priority_status'] !== 'verified';
$priorityIdNumber = trim($_POST['priority_id_number'] ?? '');
$priorityVerifiedBox = ($_POST['priority_verified'] ?? '') === '1';

if ($needsVerification && (!$priorityVerifiedBox || $priorityIdNumber === '')) {
    backToCheckIn($referenceNumber, "error", "Please confirm the ID and enter its number before checking in a priority patient.");
}

// DUPLICATE ID CHECK: same fraud signal staff/edit-priority.php checks
// for — the same real ID number verified under two different patient
// accounts. Doesn't block the check-in, just gets folded into the
// success message so staff sees it.
$duplicateWarning = '';
if ($needsVerification) {
    $dupStmt = $conn->prepare(
        "SELECT user_id, first_name, last_name FROM users
         WHERE priority_id_number = ? AND priority_status = 'verified'
           AND user_id != ? AND role = 'patient'
         LIMIT 1"
    );
    $dupStmt->bind_param("si", $priorityIdNumber, $appointment['patient_id']);
    $dupStmt->execute();
    $duplicateMatch = $dupStmt->get_result()->fetch_assoc();
    $dupStmt->close();

    if ($duplicateMatch) {
        $duplicateWarning = " \u26a0 Note: this ID number is already verified under " . trim($duplicateMatch['first_name'] . ' ' . $duplicateMatch['last_name']) . "'s account \u2014 please double check.";
    }
}

$staffId = (int) $_SESSION['user_id'];

$conn->begin_transaction();

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
    $conn->rollback();
    backToCheckIn($referenceNumber, "error", "This patient was already checked in.");
}

if ($needsVerification) {
    $verifyStmt = $conn->prepare(
        "UPDATE users
         SET priority_status = 'verified', priority_id_number = ?,
             priority_verified_by = ?, priority_verified_at = NOW()
         WHERE user_id = ?"
    );
    $verifyStmt->bind_param("sii", $priorityIdNumber, $staffId, $appointment['patient_id']);
    $verifyStmt->execute();
    $verifyStmt->close();
}

$conn->commit();

// Check-in is the moment the visit becomes real for everyone else: the
// patient gets confirmation, and the doctor is told someone joined their
// queue (todays-queue.php only refreshes on page load, so without this the
// doctor had no signal at all). Best-effort, after the commit.
create_notification(
    $conn,
    (int) $appointment['patient_id'],
    "You've been checked in for your " . date('g:i A', strtotime($appointment['slot_start'])) . " appointment with Dr. " . trim($appointment['doctor_first'] . ' ' . $appointment['doctor_last']) . ". Please wait to be called.",
    'dashboard.php'
);
create_notification(
    $conn,
    (int) $appointment['doctor_id'],
    trim($appointment['patient_first'] . ' ' . $appointment['patient_last']) . " has checked in for the " . date('g:i A', strtotime($appointment['slot_start'])) . " slot.",
    'todays-queue.php'
);

$_SESSION['checkin_flash'] = ["type" => $duplicateWarning ? "error" : "success", "message" => "Patient checked in successfully." . $duplicateWarning];
header("Location: check-in.php");
exit;
