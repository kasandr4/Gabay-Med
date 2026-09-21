<?php
// includes/waitlist.php
// Core waitlist logic. See migration 008_create_appointment_waitlist.sql
// for the schema reasoning (notify-all instead of a claim-timer, since
// this app has no cron to expire a timed claim).

require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/mailer.php'; // send_email() — see notify_waitlist_of_opening() below

/**
 * Adds a patient to the waitlist for a department (required), optionally
 * a specific doctor within it, over a date range (date_from == date_to
 * for a single specific date).
 *
 * Does NOT check whether the range is actually full right now - a patient
 * can join even if slots currently exist elsewhere in the range, since
 * the whole point is "let me know if something better opens up" as much
 * as "nothing is available at all."
 *
 * @return array{success:bool, error?:string}
 */
function join_appointment_waitlist(
    mysqli $conn,
    int $patientId,
    int $departmentId,
    ?int $doctorId,
    string $dateFrom,
    string $dateTo
): array {
    if (strtotime($dateFrom) === false || strtotime($dateTo) === false) {
        return ['success' => false, 'error' => 'Please choose valid dates.'];
    }
    if ($dateTo < $dateFrom) {
        return ['success' => false, 'error' => 'The end date can\'t be before the start date.'];
    }
    if ($dateFrom < date('Y-m-d')) {
        return ['success' => false, 'error' => 'The start date can\'t be in the past.'];
    }

    $stmt = $conn->prepare(
        "INSERT INTO appointment_waitlist (patient_id, department_id, doctor_id, date_from, date_to, status)
         VALUES (?, ?, ?, ?, ?, 'waiting')"
    );
    $stmt->bind_param("iiiss", $patientId, $departmentId, $doctorId, $dateFrom, $dateTo);
    $stmt->execute();
    $stmt->close();

    return ['success' => true];
}

/**
 * Cancels one of the CALLING patient's own waitlist entries. Re-checks
 * ownership itself, same defensive pattern as cancel_patient_appointment().
 */
function cancel_waitlist_entry(mysqli $conn, int $waitlistId, int $patientId): bool
{
    $stmt = $conn->prepare(
        "UPDATE appointment_waitlist SET status = 'cancelled'
         WHERE waitlist_id = ? AND patient_id = ? AND status = 'waiting'"
    );
    $stmt->bind_param("ii", $waitlistId, $patientId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected > 0;
}

/**
 * How many patients are ahead of a given waitlist entry, based on join
 * order, among entries wanting the exact same department + doctor
 * selection - an "any doctor" entry is grouped with other "any doctor"
 * entries, not mixed in with everyone waiting on one specific doctor in
 * the same department. Returns a 1-based position (1 = first in line).
 *
 * This is a simplification, not a perfect competitive-priority model:
 * when a slot for Dr. X actually opens, both "Dr. X only" waiters AND
 * "any doctor" waiters get notified together for it (see the WHERE
 * clause in notify_waitlist_of_opening below), so the two groups do
 * compete for that one real slot. Modeling that properly would mean a
 * patient's position changes depending on which doctor's slot happens to
 * open next, which isn't something they could sensibly check ahead of
 * time. "Position" here answers the simpler, stable question instead:
 * "of everyone who asked for the exact same thing I did, who asked
 * first?"
 */
function get_waitlist_position(mysqli $conn, int $departmentId, ?int $doctorId, string $createdAt): int
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS ahead FROM appointment_waitlist
         WHERE status = 'waiting'
           AND department_id = ?
           AND doctor_id <=> ?
           AND created_at < ?"
    );
    $stmt->bind_param("iis", $departmentId, $doctorId, $createdAt);
    $stmt->execute();
    $ahead = (int) $stmt->get_result()->fetch_assoc()['ahead'];
    $stmt->close();
    return $ahead + 1;
}

/**
 * Call this right after an appointment is cancelled. Notifies EVERY
 * waiting entry that matches this now-open slot - not just the earliest
 * one - since there's no cron here to expire a single claimant's time
 * window. Whoever actually completes the booking first gets it; the
 * unique_active_slot constraint on appointments is what actually
 * enforces that, not this function. Each notification now includes the
 * recipient's own queue position (see get_waitlist_position() above) so
 * a patient can gauge their odds before racing to book.
 *
 * FIXED 2026-09-15: also emails each matching patient now (on top of the
 * existing in-app notification) via includes/mailer.php - a waitlist
 * opening is exactly the kind of time-sensitive event a patient is
 * unlikely to see in time if it only shows up next time they happen to
 * log in. Every call site (cancellation, doctor reschedule/override,
 * admin schedule republish) gets this for free since they all already
 * route through this one shared function.
 */
function notify_waitlist_of_opening(mysqli $conn, int $departmentId, ?int $doctorId, string $slotStart): void
{
    $slotDate = date('Y-m-d', strtotime($slotStart));

    $stmt = $conn->prepare(
        "SELECT w.waitlist_id, w.patient_id, w.doctor_id, w.created_at,
                u.email AS patient_email, CONCAT(u.first_name, ' ', u.last_name) AS patient_name
         FROM appointment_waitlist w
         JOIN users u ON u.user_id = w.patient_id
         WHERE w.status = 'waiting'
           AND w.department_id = ?
           AND (w.doctor_id IS NULL OR w.doctor_id = ?)
           AND w.date_from <= ? AND w.date_to >= ?"
    );
    $stmt->bind_param("iiss", $departmentId, $doctorId, $slotDate, $slotDate);
    $stmt->execute();
    $result = $stmt->get_result();

    $niceDate = date('l, F j, Y \a\t g:i A', strtotime($slotStart));
    while ($row = $result->fetch_assoc()) {
        $rowDoctorId = $row['doctor_id'] !== null ? (int) $row['doctor_id'] : null;
        $position = get_waitlist_position($conn, $departmentId, $rowDoctorId, $row['created_at']);

        create_notification(
            $conn,
            (int) $row['patient_id'],
            "A slot just opened up on {$niceDate} that matches your waitlist request (you're #{$position} in line for it). Book now before it's taken.",
            'book-appointment.php'
        );

        if (!empty($row['patient_email'])) {
            send_email(
                $row['patient_email'],
                $row['patient_name'],
                'A slot just opened up on your GabayMed waitlist',
                "<p>Hi {$row['patient_name']},</p>"
                    . "<p>A slot just opened up on <strong>{$niceDate}</strong> that matches your waitlist request "
                    . "(you're #{$position} in line for it).</p>"
                    . "<p>Log in to GabayMed and book it before someone else does.</p>"
                    . "<p>- GabayMed</p>"
            );
        }
    }
    $stmt->close();
}

/**
 * Call this right after a patient successfully books a new appointment.
 * Clears (marks 'fulfilled') ALL of their 'waiting' entries.
 *
 * REVERTED 2026-09-15: this was scoped to just the booked department
 * (2026-08-04 fix) back when a patient could hold concurrent active
 * appointments across different departments, so a waitlist entry for an
 * unrelated department was still legitimately actionable. That policy
 * is now back to "one active appointment total" (see
 * patient/book-appointment.php's $active_appointments header comment) -
 * so once a patient has booked ANY appointment, they can't act on ANY
 * other waitlist entry either way (booking a second one, in any
 * department, would violate the one-appointment rule). Original
 * reasoning restored: clearing everything is correct again.
 *
 * $departmentId is intentionally still a parameter, even though it's
 * unused below now - both call sites (patient/book-appointment.php,
 * staff/walk-in.php) already pass it, and keeping the signature
 * unchanged avoids touching either of them just for this.
 */
function clear_waitlist_on_booking(mysqli $conn, int $patientId, int $appointmentId, int $departmentId): void
{
    $stmt = $conn->prepare(
        "UPDATE appointment_waitlist
         SET status = 'fulfilled', fulfilled_appointment_id = ?
         WHERE patient_id = ? AND status = 'waiting'"
    );
    $stmt->bind_param("ii", $appointmentId, $patientId);
    $stmt->execute();
    $stmt->close();
}

/**
 * The calling patient's own active (status='waiting') waitlist entries,
 * newest first, decorated with department/doctor names and queue
 * position (see get_waitlist_position() above) for display.
 */
function get_patient_waitlist(mysqli $conn, int $patientId): array
{
    $stmt = $conn->prepare(
        "SELECT w.waitlist_id, w.department_id, w.doctor_id, w.date_from, w.date_to, w.created_at,
                d.department_name, u.first_name AS doctor_first, u.last_name AS doctor_last
         FROM appointment_waitlist w
         JOIN departments d ON d.department_id = w.department_id
         LEFT JOIN users u ON u.user_id = w.doctor_id
         WHERE w.patient_id = ? AND w.status = 'waiting'
         ORDER BY w.created_at DESC"
    );
    $stmt->bind_param("i", $patientId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$row) {
        $rowDoctorId = $row['doctor_id'] !== null ? (int) $row['doctor_id'] : null;
        $row['position'] = get_waitlist_position($conn, (int) $row['department_id'], $rowDoctorId, $row['created_at']);
    }
    unset($row);

    return $rows;
}
