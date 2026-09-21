<?php
// includes/schedule_conflicts.php
// Shared helper for finding appointments that are stuck "orphaned" by a
// doctor_schedule_overrides row: patients who already have an active
// (pending/confirmed) appointment at a date/time the doctor is no longer
// actually available for, because an override was set AFTER they booked.
//
// Also covers the same problem caused by a change to a doctor's WEEKLY
// RECURRING schedule (doctor_weekly_schedules) instead of a one-off daily
// override - see find_conflicting_appointments_for_weekly_change() below.
//
// This never touches the appointment itself - neither overrides nor
// weekly-schedule edits ever cancel/move a booking themselves (see
// includes/schedule_resolver.php). Surfacing these here is what lets a
// human (front-desk staff, or the doctor) do the part software shouldn't
// do silently: decide whether to call the patient, reschedule them, or
// cancel outright.
//
// Used by:
//   - doctor/my-schedule-actions.php     (to notify staff the moment a
//     doctor's override creates conflicts)
//   - admin/doctor-schedule-actions.php  (same, for a weekly-schedule edit -
//     FIXED 2026-09-14: this admin endpoint used to check schedule-vs-
//     schedule overlaps only, never schedule-vs-appointments, so narrowing
//     or removing a doctor's recurring hours silently orphaned any
//     appointments already booked outside the new hours)
//   - staff/schedule-conflicts.php       (the review/action queue itself)

require_once __DIR__ . '/schedule_resolver.php'; // OVERRIDE_STATUSES_ON_DUTY, resolve_effective_schedule() — not every caller of this file guarantees that require happens first on its own

/**
 * Whether an override status takes the doctor fully off duty for the
 * WHOLE day (no hours at all). Keys off OVERRIDE_STATUSES_ON_DUTY in
 * includes/schedule_resolver.php - the one place this classification is
 * defined - rather than maintaining a second, independent copy of the
 * status list here (FIXED 2026-09-14: those two lists used to be
 * maintained separately and could have silently drifted).
 */
function override_is_full_day_off(string $status): bool
{
    return $status !== 'no_change' && !in_array($status, OVERRIDE_STATUSES_ON_DUTY, true);
}

/**
 * Finds active appointments for ONE doctor + date that no longer fall
 * inside that doctor's effective on-duty hours, given a specific
 * override row already known (doctorId/date/status/start/end - as just
 * saved by save_override). Returns appointment rows with patient contact
 * info attached. Empty array if the override doesn't create any conflict
 * (e.g. status is 'available', or 'no_change', or no appointments exist).
 */
function find_conflicting_appointments_for_override(mysqli $conn, int $doctorId, string $dateStr, string $overrideStatus, ?string $overrideStart, ?string $overrideEnd): array
{
    if ($overrideStatus === 'no_change' || $overrideStatus === 'available') {
        return [];
    }

    if (override_is_full_day_off($overrideStatus)) {
        // Whole day is off - every active appointment that date is orphaned.
        $stmt = $conn->prepare(
            "SELECT a.appointment_id, a.slot_start, a.slot_end, a.status,
                    p.user_id AS patient_id, p.first_name, p.last_name, p.phone_number
             FROM appointments a
             JOIN users p ON p.user_id = a.patient_id
             WHERE a.doctor_id = ? AND DATE(a.slot_start) = ?
               AND a.status IN ('pending', 'confirmed')
             ORDER BY a.slot_start ASC"
        );
        $stmt->bind_param("is", $doctorId, $dateStr);
    } else {
        // half_day - only appointments OUTSIDE the override's start/end
        // window are orphaned; the rest are still covered.
        $stmt = $conn->prepare(
            "SELECT a.appointment_id, a.slot_start, a.slot_end, a.status,
                    p.user_id AS patient_id, p.first_name, p.last_name, p.phone_number
             FROM appointments a
             JOIN users p ON p.user_id = a.patient_id
             WHERE a.doctor_id = ? AND DATE(a.slot_start) = ?
               AND a.status IN ('pending', 'confirmed')
               AND (TIME(a.slot_start) < ? OR TIME(a.slot_start) >= ?)
             ORDER BY a.slot_start ASC"
        );
        $stmt->bind_param("isss", $doctorId, $dateStr, $overrideStart, $overrideEnd);
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Scans every active (today onward), non-'no_change', non-'available'
 * override on file and returns one row per orphaned appointment across
 * ALL doctors, joined with doctor + patient details - this is what
 * staff/schedule-conflicts.php lists. Single query rather than looping
 * find_conflicting_appointments_for_override() per doctor/date, since
 * staff need to see the whole hospital's conflicts at once.
 */
function find_all_schedule_conflicts(mysqli $conn): array
{
    $stmt = $conn->prepare(
        "SELECT
            a.appointment_id, a.slot_start, a.slot_end, a.status AS appointment_status,
            p.user_id AS patient_id, p.first_name AS patient_first, p.last_name AS patient_last, p.phone_number,
            doc.user_id AS doctor_id, doc.first_name AS doctor_first, doc.last_name AS doctor_last,
            o.status AS override_status, o.reason AS override_reason, o.start_time AS override_start, o.end_time AS override_end
         FROM doctor_schedule_overrides o
         JOIN appointments a
            ON a.doctor_id = o.doctor_id
           AND DATE(a.slot_start) = o.override_date
           AND a.status IN ('pending', 'confirmed')
         JOIN users doc ON doc.user_id = o.doctor_id
         JOIN users p ON p.user_id = a.patient_id
         WHERE o.override_date >= CURDATE()
           AND o.status NOT IN ('no_change', 'available')
           AND (
                o.status IN ('emergency_leave', 'training', 'meeting', 'unavailable')
                OR (o.status = 'half_day' AND (TIME(a.slot_start) < o.start_time OR TIME(a.slot_start) >= o.end_time))
           )
         ORDER BY a.slot_start ASC"
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Total count of orphaned appointments, for the staff sidebar badge.
 * Cheaper than find_all_schedule_conflicts() when only the count is
 * needed (e.g. rendering the nav badge on every staff page load).
 */
function count_schedule_conflicts(mysqli $conn): int
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c
         FROM doctor_schedule_overrides o
         JOIN appointments a
            ON a.doctor_id = o.doctor_id
           AND DATE(a.slot_start) = o.override_date
           AND a.status IN ('pending', 'confirmed')
         WHERE o.override_date >= CURDATE()
           AND o.status NOT IN ('no_change', 'available')
           AND (
                o.status IN ('emergency_leave', 'training', 'meeting', 'unavailable')
                OR (o.status = 'half_day' AND (TIME(a.slot_start) < o.start_time OR TIME(a.slot_start) >= o.end_time))
           )"
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['c'] ?? 0);
}

/**
 * Same as find_all_schedule_conflicts() but scoped to ONE doctor's own
 * orphaned appointments - powers the "Needs Follow-Up" panel on
 * doctor/my-schedule.php, the doctor-side equivalent of
 * staff/schedule-conflicts.php's hospital-wide list.
 */
function find_schedule_conflicts_for_doctor(mysqli $conn, int $doctorId): array
{
    $stmt = $conn->prepare(
        "SELECT
            a.appointment_id, a.slot_start, a.slot_end, a.status AS appointment_status,
            p.user_id AS patient_id, p.first_name AS patient_first, p.last_name AS patient_last, p.phone_number,
            o.status AS override_status, o.reason AS override_reason, o.start_time AS override_start, o.end_time AS override_end
         FROM doctor_schedule_overrides o
         JOIN appointments a
            ON a.doctor_id = o.doctor_id
           AND DATE(a.slot_start) = o.override_date
           AND a.status IN ('pending', 'confirmed')
         JOIN users p ON p.user_id = a.patient_id
         WHERE o.doctor_id = ?
           AND o.override_date >= CURDATE()
           AND o.status NOT IN ('no_change', 'available')
           AND (
                o.status IN ('emergency_leave', 'training', 'meeting', 'unavailable')
                OR (o.status = 'half_day' AND (TIME(a.slot_start) < o.start_time OR TIME(a.slot_start) >= o.end_time))
           )
         ORDER BY a.slot_start ASC"
    );
    $stmt->bind_param("i", $doctorId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Notifies each patient whose appointment was just orphaned by a
 * doctor's override - so they hear about it right away instead of only
 * finding out once staff happen to work through the Schedule Conflicts
 * queue (see staff/schedule-conflicts.php, which still owns the actual
 * reschedule/cancel decision - this is purely a heads-up, it doesn't
 * touch the appointment itself). Called right alongside
 * notify_staff_of_schedule_conflict() in doctor/my-schedule-actions.php's
 * save_override action, reusing the same $conflicts rows already
 * fetched by find_conflicting_appointments_for_override().
 */
function notify_patients_of_schedule_conflict(mysqli $conn, string $doctorName, array $conflicts): void
{
    foreach ($conflicts as $conflict) {
        $whenLabel = date('M j, Y \a\t g:i A', strtotime($conflict['slot_start']));
        $message = "Dr. {$doctorName} is no longer available for your appointment on {$whenLabel}. "
            . "Our staff will contact you to reschedule - or you can cancel it yourself and book a new time "
            . "if you'd rather not wait.";
        create_notification($conn, (int) $conflict['patient_id'], $message, 'dashboard.php');
    }
}

/**
 * Notifies every active staff account that a doctor's override just
 * orphaned some appointments. Called right after save_override() in
 * doctor/my-schedule-actions.php when find_conflicting_appointments_for_override()
 * comes back non-empty. Staff (not admin) because they're the ones who
 * own patient-facing rescheduling/check-in in this system - see
 * staff/walk-in.php and staff/check-in.php.
 */
/**
 * Notifies every active staff account when a doctor's override orphans
 * existing appointments - purely informational now (e.g. so front-desk
 * can expect calls from confused patients, or heads-up walk-ins about
 * that doctor being out). The doctor themselves owns actually fixing
 * these - see doctor/my-schedule.php's "Needs Follow-Up" panel - staff
 * have no action to take here, just visibility via the read-only
 * Doctor Availability page this links to.
 */
function notify_staff_of_schedule_conflict(mysqli $conn, string $doctorName, string $dateStr, int $conflictCount): void
{
    // FIXED (staff_type split, 013_staff_subtype.sql): 'staff' is now two
    // unrelated jobs. Only front-desk staff own rescheduling/check-in -
    // inventory-counting staff have nothing to do with a doctor's
    // schedule and shouldn't get pinged about it.
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE role = 'staff' AND staff_type = 'front_desk' AND is_active = 1 AND archived_at IS NULL");
    $stmt->execute();
    $staffIds = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'user_id');
    $stmt->close();

    if (empty($staffIds)) {
        return;
    }

    $dateLabel = date('M j, Y', strtotime($dateStr));
    $message = "Dr. {$doctorName} marked {$dateLabel} as unavailable — "
        . $conflictCount . ($conflictCount === 1 ? " patient's appointment is" : " patients' appointments are")
        . " affected. The doctor is handling rescheduling; you may get walk-ins or calls asking about that day.";

    foreach ($staffIds as $staffId) {
        create_notification($conn, (int) $staffId, $message, 'schedule-conflicts.php');
    }
}

/**
 * Same purpose as find_conflicting_appointments_for_override() above, but
 * for a change to a doctor's WEEKLY RECURRING schedule
 * (doctor_weekly_schedules - create_schedule/update_schedule/
 * archive_schedule/toggle_status/publish_opd_schedule in
 * admin/doctor-schedule-actions.php) rather than a one-off daily override.
 * Narrowing, shifting, or removing a doctor's recurring hours can orphan
 * already-booked active appointments exactly the same way an override
 * can - this just never got checked for (FIXED 2026-09-14).
 *
 * Call this AFTER the schedule write has already been committed -
 * re-resolves each candidate appointment's date via
 * resolve_effective_schedule() (the same function every booking path
 * already trusts) rather than reasoning about the weekly-schedule row
 * directly, so this naturally accounts for whatever the doctor's
 * schedule now actually is post-write, override included.
 *
 * Bounded to booking_window_days (includes/system_settings.php) - the
 * same horizon every booking path is already bounded to - since no
 * appointment can exist further out than that regardless of what
 * effective_from/effective_to range was passed in.
 *
 * @param string|null $rangeFrom  'Y-m-d'; defaults to today if omitted or in the past.
 * @param string|null $rangeTo    'Y-m-d'; defaults to (today + booking_window_days) if omitted or beyond it.
 * @return array  Appointment rows (with patient contact info attached), one per orphaned appointment found.
 */
function find_conflicting_appointments_for_weekly_change(mysqli $conn, int $doctorId, ?string $rangeFrom = null, ?string $rangeTo = null): array
{
    require_once __DIR__ . '/system_settings.php';

    $today = date('Y-m-d');
    $horizonEnd = date('Y-m-d', strtotime('+' . get_setting_int($conn, 'booking_window_days', 15) . ' days'));

    $scanFrom = ($rangeFrom !== null && $rangeFrom > $today) ? $rangeFrom : $today;
    $scanTo = ($rangeTo !== null && $rangeTo < $horizonEnd) ? $rangeTo : $horizonEnd;
    if ($scanFrom > $scanTo) {
        return [];
    }

    $stmt = $conn->prepare(
        "SELECT a.appointment_id, a.slot_start, a.slot_end, a.status,
                p.user_id AS patient_id, p.first_name, p.last_name, p.phone_number
         FROM appointments a
         JOIN users p ON p.user_id = a.patient_id
         WHERE a.doctor_id = ? AND a.status IN ('pending', 'confirmed')
           AND DATE(a.slot_start) BETWEEN ? AND ?
         ORDER BY a.slot_start ASC"
    );
    $stmt->bind_param("iss", $doctorId, $scanFrom, $scanTo);
    $stmt->execute();
    $candidates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($candidates)) {
        return [];
    }

    // Cached per date - a doctor's day easily has several appointments,
    // and resolve_effective_schedule() is a query itself, so this avoids
    // re-resolving the same date once per appointment on it.
    $schedCache = [];
    $orphaned = [];
    foreach ($candidates as $appt) {
        $date = date('Y-m-d', strtotime($appt['slot_start']));
        $time = date('H:i:s', strtotime($appt['slot_start']));

        if (!isset($schedCache[$date])) {
            $schedCache[$date] = resolve_effective_schedule($conn, $doctorId, $date);
        }
        $sched = $schedCache[$date];

        $stillCovered = $sched['on_duty'] && $time >= $sched['start'] && $time < $sched['end'];
        if (!$stillCovered) {
            $orphaned[] = $appt;
        }
    }

    return $orphaned;
}

/**
 * Staff-notification counterpart to notify_staff_of_schedule_conflict(),
 * for a weekly-schedule change instead of a daily override. A weekly
 * change can orphan appointments spread across several different future
 * dates in one edit (unlike an override, which only ever affects one
 * date), so this reports a single summary notification rather than one
 * per date. Patients themselves still get the existing
 * notify_patients_of_schedule_conflict() per-appointment message - that
 * function is already generic enough to reuse as-is here.
 */
function notify_staff_of_weekly_schedule_conflict(mysqli $conn, string $doctorName, int $conflictCount): void
{
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE role = 'staff' AND staff_type = 'front_desk' AND is_active = 1 AND archived_at IS NULL");
    $stmt->execute();
    $staffIds = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'user_id');
    $stmt->close();

    if (empty($staffIds)) {
        return;
    }

    $message = "Dr. {$doctorName}'s recurring schedule was just updated — "
        . $conflictCount . ($conflictCount === 1 ? " patient's appointment no longer falls" : " patients' appointments no longer fall")
        . " within the new hours. The doctor is handling rescheduling; you may get walk-ins or calls asking about it.";

    foreach ($staffIds as $staffId) {
        create_notification($conn, (int) $staffId, $message, 'doctor-schedule.php');
    }
}
