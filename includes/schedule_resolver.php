<?php
// includes/schedule_resolver.php
// Single place that combines a doctor's recurring weekly pattern
// (`doctor_weekly_schedules`, managed on the "Weekly Recurring Schedule"
// admin tab) with a specific date's exception, if any
// (`doctor_schedule_overrides`, managed on the "Weekly Overrides" admin
// tab) into one effective answer: is the doctor on duty this date, what
// hours, and how many patients can they see.
//
// Used by:
//   - admin/doctor-schedule.php    (Monthly Schedule read-only calendar)
//   - doctor/my-schedule.php       (doctor's own read-only week view)
//   - patient/book-appointment.php (slot grid + booking validation)
//
// Precedence: an override with status != 'no_change' always wins for
// that date. Otherwise the recurring weekly pattern applies. If neither
// exists, the doctor is simply off that day.

// The ONE place that classifies which override statuses put the doctor
// ON DUTY (with explicit hours) vs fully OFF for the date. Both
// resolve_effective_schedule()'s switch below and
// includes/schedule_conflicts.php's override_is_full_day_off() key off
// this constant instead of each maintaining their own copy of the list -
// FIXED 2026-09-14, closing a precedence-duplication gap where those two
// could have silently drifted if a new override status were ever added
// to one without the other.
const OVERRIDE_STATUSES_ON_DUTY = ['available', 'half_day'];

// FIXED 2026-09-14: an 'available' or 'half_day' override on a date with
// NO underlying recurring schedule row (e.g. a doctor coming in on their
// normal off-day) used to resolve max_patients to null. Every downstream
// capacity check (includes/slot_grid.php, patient/book-appointment.php,
// staff/walk-in.php) treats a falsy max_patients as "no cap enforced" -
// so that day silently had UNLIMITED bookings instead of any real limit.
// This fallback only applies when there's no recurring row to inherit
// max_patients from; a day with a recurring schedule underneath is
// unaffected and keeps using that row's own max_patients as before.
// Matches DEFAULT_MAX_PATIENTS in admin/doctor-schedule-actions.php (the
// value new recurring schedules get) so an override-only day isn't
// capped any differently than a normal one, by default.
const OVERRIDE_FALLBACK_MAX_PATIENTS = 20;

/**
 * Returns the ACTIVE recurring schedule row covering this doctor +
 * date's weekday, or null if none applies.
 */
function get_recurring_schedule(mysqli $conn, int $doctorId, string $dateStr): ?array
{
    $dayOfWeek = (int) date('N', strtotime($dateStr));
    $stmt = $conn->prepare(
        "SELECT start_time, end_time, max_patients
         FROM doctor_weekly_schedules
         WHERE doctor_id = ? AND day_of_week = ? AND status = 'active'
           AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
         LIMIT 1"
    );
    $stmt->bind_param("iiss", $doctorId, $dayOfWeek, $dateStr, $dateStr);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Returns the override row for this doctor + exact date, or null.
 */
function get_schedule_override(mysqli $conn, int $doctorId, string $dateStr): ?array
{
    $stmt = $conn->prepare(
        "SELECT status, start_time, end_time, reason, notes
         FROM doctor_schedule_overrides
         WHERE doctor_id = ? AND override_date = ?
         LIMIT 1"
    );
    $stmt->bind_param("is", $doctorId, $dateStr);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Combines the two into one effective-schedule answer for a date.
 *
 * Returns:
 *   on_duty         bool     can this doctor see patients this date
 *   start, end      ?string  'HH:MM:SS' or null
 *   max_patients    ?int
 *   calendar_status string   one of: available | off_duty | leave | half_day
 *                            (matches the 4-bucket legend the Monthly
 *                            Schedule calendar already uses)
 *   override_status ?string  the raw override status if one exists
 *                            (no_change | available | half_day |
 *                            emergency_leave | training | meeting |
 *                            unavailable), else null
 *   reason, notes   ?string  from the override, if any
 *   source          string   override | recurring | none
 */
function resolve_effective_schedule(mysqli $conn, int $doctorId, string $dateStr): array
{
    $recurring = get_recurring_schedule($conn, $doctorId, $dateStr);
    $override = get_schedule_override($conn, $doctorId, $dateStr);

    $base = [
        'on_duty'         => false,
        'start'           => null,
        'end'             => null,
        'max_patients'    => null,
        'calendar_status' => 'off_duty',
        'override_status' => $override['status'] ?? null,
        'reason'          => $override['reason'] ?? null,
        'notes'           => $override['notes'] ?? null,
        'source'          => 'none',
    ];

    $overridden = $override && $override['status'] !== 'no_change';

    if ($overridden) {
        if (in_array($override['status'], OVERRIDE_STATUSES_ON_DUTY, true)) {
            $base['on_duty'] = true;
            $base['max_patients'] = $recurring['max_patients'] ?? OVERRIDE_FALLBACK_MAX_PATIENTS;
            if ($override['status'] === 'available') {
                $base['start'] = $override['start_time'] ?: ($recurring['start_time'] ?? null);
                $base['end'] = $override['end_time'] ?: ($recurring['end_time'] ?? null);
                $base['calendar_status'] = 'available';
            } else { // half_day
                $base['start'] = $override['start_time'];
                $base['end'] = $override['end_time'];
                $base['calendar_status'] = 'half_day';
            }
        } else { // emergency_leave, training, meeting, unavailable
            $base['on_duty'] = false;
            $base['calendar_status'] = 'leave';
        }
        $base['source'] = 'override';
        return $base;
    }

    if ($recurring) {
        $base['on_duty'] = true;
        $base['start'] = $recurring['start_time'];
        $base['end'] = $recurring['end_time'];
        $base['max_patients'] = (int) $recurring['max_patients'];
        $base['calendar_status'] = 'available';
        $base['source'] = 'recurring';
    }

    return $base;
}
