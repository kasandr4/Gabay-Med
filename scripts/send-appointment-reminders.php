<?php
// scripts/send-appointment-reminders.php
//
// Finds every active (pending/confirmed) appointment starting within the
// next REMINDER_WINDOW_HOURS hours that hasn't been reminded yet, and
// sends the patient an email (via includes/mailer.php's Gmail SMTP
// client) plus an in-app notification. Marks reminder_sent_at once sent,
// so re-running this is always safe - it only ever emails a given
// appointment once (see 015_add_reminder_sent_at_to_appointments.sql).
//
// *** This project has no cron/scheduled-task mechanism of its own (see
// staff/check-in.php's own header comment) - this
// script is meant to be invoked BY one, external to the app. It does
// nothing on its own until something calls it on a schedule:
//
//   Linux/shared hosting (cron), every 15 minutes:
//     */15 * * * * /usr/bin/php /full/path/to/GabayMed/scripts/send-appointment-reminders.php >> /full/path/to/GabayMed/reminder-log.txt 2>&1
//
//   Windows/XAMPP local dev (Task Scheduler), every 15 minutes:
//     Program:   C:\xampp\php\php.exe
//     Arguments: C:\xampp\htdocs\GabayMed\scripts\send-appointment-reminders.php
//     Trigger:   Repeat task every 15 minutes
//
// CLI-only by design (see the php_sapi_name() guard below) - this sends
// real emails, so it must never be reachable as a public URL.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/notifications.php';

// How far ahead to remind. 24 hours means: the first time this script
// runs (on its cron schedule) after an appointment enters the next-24h
// window, that appointment gets reminded - it won't fire exactly 24h
// out to the minute, since this only runs on a schedule, not a timer per
// appointment.
const REMINDER_WINDOW_HOURS = 24;

$stmt = $conn->prepare(
    "SELECT a.appointment_id, a.slot_start,
            p.user_id AS patient_id, p.first_name AS patient_first, p.last_name AS patient_last, p.email AS patient_email,
            d.first_name AS doctor_first, d.last_name AS doctor_last,
            dep.department_name
     FROM appointments a
     JOIN users p ON p.user_id = a.patient_id
     JOIN users d ON d.user_id = a.doctor_id
     JOIN departments dep ON dep.department_id = a.department_id
     WHERE a.status IN ('pending', 'confirmed')
       AND a.reminder_sent_at IS NULL
       AND a.slot_start BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL " . REMINDER_WINDOW_HOURS . " HOUR)
     ORDER BY a.slot_start ASC"
);
$stmt->execute();
$dueAppointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$sentCount = 0;
$skippedNoEmail = 0;

foreach ($dueAppointments as $appt) {
    $patientName = trim($appt['patient_first'] . ' ' . $appt['patient_last']);
    $doctorName = trim($appt['doctor_first'] . ' ' . $appt['doctor_last']);
    $whenLabel = date('l, F j, Y \a\t g:i A', strtotime($appt['slot_start']));

    // Guest/walk-in patients can have no email on file - nothing to send
    // to, but still mark it so this row stops showing up as "due" every
    // run. The in-app notification still lands for them regardless, in
    // case they ever log into the account this profile is tied to.
    if (!empty($appt['patient_email'])) {
        $subject = "Reminder: your appointment on " . date('M j', strtotime($appt['slot_start']));
        $body = "<p>Hi {$patientName},</p>"
            . "<p>This is a reminder of your upcoming appointment:</p>"
            . "<p><strong>{$whenLabel}</strong><br>"
            . "with Dr. {$doctorName} ({$appt['department_name']})</p>"
            . "<p>Please arrive a few minutes early. If you need to reschedule or cancel, please do so from your GabayMed patient portal.</p>"
            . "<p>- GabayMed</p>";

        $emailSent = send_email($appt['patient_email'], $patientName, $subject, $body);
        if ($emailSent) {
            $sentCount++;
        }
    } else {
        $skippedNoEmail++;
    }

    create_notification(
        $conn,
        (int) $appt['patient_id'],
        "Reminder: you have an appointment with Dr. {$doctorName} on {$whenLabel}.",
        'dashboard.php'
    );

    $updateStmt = $conn->prepare("UPDATE appointments SET reminder_sent_at = NOW() WHERE appointment_id = ?");
    $updateStmt->bind_param("i", $appt['appointment_id']);
    $updateStmt->execute();
    $updateStmt->close();
}

echo "Checked " . count($dueAppointments) . " due appointment(s). "
    . "Emails sent: {$sentCount}. Skipped (no email on file): {$skippedNoEmail}." . PHP_EOL;
