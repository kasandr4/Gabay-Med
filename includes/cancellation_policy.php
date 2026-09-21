<?php
// includes/cancellation_policy.php
// Centralizes patient-initiated appointment cancellation. Previously this
// logic (re-validate ownership + status, UPDATE to 'cancelled', notify
// both patient and doctor) was duplicated identically across
// patient/book-appointment.php and patient/appointment-detail.php — this
// is the single source of truth both now call instead.
//
// LATE-CANCELLATION POLICY (settled 2026-08-02): cancelling within
// the admin-configurable late_cancellation_window_minutes setting of the
// appointment's slot_start is tracked via users.late_cancel_count,
// mirroring how includes/no_show_policy.php tracks no_show_count.
//
// DELIBERATELY DIFFERENT FROM THE NO-SHOW POLICY: a late cancellation is
// NEVER auto-blocked, and never feeds into no_show_count. A patient who
// calls ahead to cancel — even 10 minutes before their slot — is being
// more responsible than one who simply doesn't show up at all, and
// shouldn't face the same account-block consequence. This column exists
// purely so Staff/Admin can see a repeated pattern if one develops; there
// is currently no automatic penalty attached to it, by design. If OMCDH
// later decides repeated late cancellations should carry a consequence,
// that's a deliberate policy decision to layer on top of this — not
// something this file assumes on its own.

require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/mailer.php'; // send_email() — see cancel_patient_appointment() below
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/system_settings.php';

// FORMERLY a hardcoded constant — now admin-editable via
// admin/system-settings.php (system_settings table, key
// 'late_cancellation_window_minutes', category 'no_show'). See
// is_late_cancellation() below for where it's actually read.

// ============================================================
// REVERTED 2026-09-17 (per instructor requirement): the 2026-09-07
// admin-gated request/review flow (request_appointment_cancellation(),
// withdraw_cancellation_request(), approve_cancellation_request(),
// reject_cancellation_request(), and the appointment_cancellation_requests
// table behind them) is removed entirely. Cancellation is direct again -
// the patient states a reason and cancel_patient_appointment() below
// cancels immediately, same as it always did under the hood (the
// admin-gated flow's "approve" action was just a call to this same
// function). See 024_direct_cancellation_with_reason.sql for the schema
// change this went with: appointments.cancellation_reason replaces the
// old request table's reason column.
// ============================================================

/**
 * True if cancelling right now, for an appointment with this slot_start,
 * falls inside the late-cancellation window. Also true if slot_start has
 * already passed — cancelling after the appointment time was due is at
 * least as late as cancelling 5 minutes before it.
 */
function is_late_cancellation(mysqli $conn, string $slot_start): bool
{
    $windowMinutes = get_setting_int($conn, 'late_cancellation_window_minutes', 120);
    $minutes_until_slot = (strtotime($slot_start) - time()) / 60;
    return $minutes_until_slot <= $windowMinutes;
}

/**
 * Cancels one appointment on behalf of the patient who owns it.
 *
 * Re-validates ownership and status itself (appointment_id + patient_id
 * match, status still pending/confirmed) rather than trusting the caller
 * — protects against double-cancel races or a patient trying to cancel
 * someone else's appointment by URL/ID guessing, exactly like the two
 * call sites already did individually before this was centralized.
 *
 * FIXED 2026-09-15: also emails the patient now (on top of the existing
 * in-app notification) via includes/mailer.php — a cancellation is worth
 * knowing about even if the patient doesn't happen to be logged in when
 * it happens.
 *
 * REWORKED 2026-09-17: takes $reason now (required — enforced here, not
 * left to each caller to check separately) and stores it on
 * appointments.cancellation_reason. This is the only place that reason
 * ever gets written, so there's one validation rule (non-empty, under
 * 500 chars) regardless of which page the cancellation came from.
 *
 * @return array{success:bool, error?:string, was_late?:bool}
 */
function cancel_patient_appointment(mysqli $conn, int $appointmentId, int $patientId, string $patientName, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') {
        return ['success' => false, 'error' => "Please tell us why you'd like to cancel."];
    }
    if (mb_strlen($reason) > 500) {
        return ['success' => false, 'error' => "Reason is too long — please keep it under 500 characters."];
    }

    $stmt = $conn->prepare("
        SELECT a.appointment_id, a.doctor_id, a.department_id, a.slot_start,
               p.email AS patient_email,
               CONCAT(d.first_name, ' ', d.last_name) AS doctor_name,
               dep.department_name
        FROM appointments a
        JOIN users p ON p.user_id = a.patient_id
        JOIN users d ON d.user_id = a.doctor_id
        JOIN departments dep ON dep.department_id = a.department_id
        WHERE a.appointment_id = ? AND a.patient_id = ? AND a.status IN ('pending', 'confirmed')
    ");
    $stmt->bind_param("ii", $appointmentId, $patientId);
    $stmt->execute();
    $valid = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$valid) {
        return ['success' => false, 'error' => "This appointment can no longer be cancelled."];
    }

    $wasLate = is_late_cancellation($conn, $valid['slot_start']);

    $stmt = $conn->prepare("UPDATE appointments SET status = 'cancelled', cancellation_reason = ? WHERE appointment_id = ? AND patient_id = ?");
    $stmt->bind_param("sii", $reason, $appointmentId, $patientId);
    $stmt->execute();
    $stmt->close();

    if ($wasLate) {
        $stmt = $conn->prepare("UPDATE users SET late_cancel_count = late_cancel_count + 1 WHERE user_id = ?");
        $stmt->bind_param("i", $patientId);
        $stmt->execute();
        $stmt->close();
    }

    $patientMessage = $wasLate
        ? "Your appointment has been cancelled. Since this was within 2 hours of your scheduled time, it's been logged as a late cancellation."
        : "Your appointment has been cancelled.";
    create_notification($conn, $patientId, $patientMessage, 'dashboard.php');

    if (!empty($valid['patient_email'])) {
        $whenLabel = date('l, F j, Y \a\t g:i A', strtotime($valid['slot_start']));
        $lateNote = $wasLate
            ? "<p>Since this was within the late-cancellation window, it's been logged as a late cancellation.</p>"
            : '';
        send_email(
            $valid['patient_email'],
            $patientName,
            'Your GabayMed appointment was cancelled',
            "<p>Hi {$patientName},</p>"
                . "<p>Your appointment with Dr. {$valid['doctor_name']} ({$valid['department_name']}) on "
                . "<strong>{$whenLabel}</strong> has been cancelled.</p>"
                . $lateNote
                . "<p>If this wasn't intentional, or you'd like to book a new appointment, please log in to GabayMed.</p>"
                . "<p>- GabayMed</p>"
        );
    }

    $doctorNotifMessage = "Appointment cancelled: " . $patientName .
        " on " . date('M j, Y \a\t g:i A', strtotime($valid['slot_start'])) . ".";
    create_notification($conn, $valid['doctor_id'], $doctorNotifMessage, "todays-queue.php");

    return ['success' => true, 'was_late' => $wasLate];
}
