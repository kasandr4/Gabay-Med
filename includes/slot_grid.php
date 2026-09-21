<?php
// includes/slot_grid.php
// Shared helper that builds the 15-day open-slot grid for one doctor:
// which dates they're on duty (via schedule_resolver.php, so any
// doctor_schedule_overrides row is already factored in), which are fully
// booked, and which exact datetimes are already taken. Originally lived
// only in patient/book-appointment-actions.php's 'get_slots' action;
// pulled out here so doctor/my-schedule-actions.php's reschedule flow
// can offer a patient a new slot using the exact same rules a patient
// booking themselves would see - no separate, potentially drifting copy
// of "what counts as an open slot."

require_once __DIR__ . '/system_settings.php';

/**
 * Minutes since midnight for a 'H:i' or 'H:i:s' string. Small helper so
 * "closest to preferred time" comparisons in find_next_available_slot
 * can account for minutes, not just the hour - needed once there are two
 * slots per hour instead of one.
 */
function slot_minutes_since_midnight(string $timeStr): int
{
    $parts = explode(':', $timeStr);
    return ((int) $parts[0]) * 60 + ((int) ($parts[1] ?? 0));
}

/**
 * A doctor's department_id, or null if doctorId isn't a real doctor.
 * Every function below takes only $doctorId (matching how they were
 * already being called everywhere before this change), so this is looked
 * up internally rather than adding a $departmentId parameter to all four
 * existing call sites across the codebase.
 */
function get_doctor_department_id(mysqli $conn, int $doctorId): ?int
{
    $stmt = $conn->prepare("SELECT department_id FROM users WHERE user_id = ? AND role = 'doctor'");
    $stmt->bind_param("i", $doctorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int) $row['department_id'] : null;
}

/**
 * A department's configured checkup duration in minutes, defaulting to
 * 30 if unset. See migration 009_add_default_duration_to_departments.sql
 * for the reasoning (settled 2026-08-06): some specialties genuinely run
 * shorter checkups than others per real OMCDH numbers, once gathered, so
 * this is a per-department value rather than one hospital-wide constant.
 */
function get_department_duration_minutes(mysqli $conn, int $departmentId): int
{
    $stmt = $conn->prepare("SELECT default_duration_minutes FROM departments WHERE department_id = ?");
    $stmt->bind_param("i", $departmentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ($row && $row['default_duration_minutes']) ? (int) $row['default_duration_minutes'] : 30;
}

/**
 * Every valid clinic slot start time ('H:i:s') for a given duration,
 * bounded by a specific day's actual duty hours ($dayStart/$dayEnd -
 * from resolve_effective_schedule(), NOT a hospital-wide constant), minus
 * the fixed 11AM-1PM lunch break wherever it overlaps that window.
 *
 * FIXED 2026-09-08: this used to hardcode the window as 8AM-4PM
 * regardless of what any doctor's actual configured end time was - so a
 * doctor scheduled 8AM-5PM (or any hours other than exactly 8-4) never
 * had their last hour of slots offered or even accepted server-side,
 * because every caller (the booking grid, confirm_booking's
 * re-validation, walk-in's auto-assign, and bulk reschedule's
 * find_next_available_slot) all pulled their "what times are even valid"
 * list from this one hardcoded template. Reported via a screenshot
 * showing Dr. Birung's booking grid stopping at 3:30 PM despite his
 * admin-configured schedule being 8AM-5PM.
 *
 * Generated as two independent windows ($dayStart-lunchStart,
 * lunchEnd-$dayEnd) rather than one loop that skips the middle, so a
 * duration that doesn't evenly divide either window (e.g. 25 minutes)
 * never produces a slot straddling lunch or spilling past $dayEnd - it
 * just leaves a few unused minutes at the end of a window instead of
 * erroring. A window collapses to nothing (and is silently skipped) if
 * $dayStart/$dayEnd don't reach that side of the lunch block at all -
 * e.g. a 2PM-6PM day only ever produces the second window.
 */
function get_clinic_time_slots(int $durationMinutes, string $dayStart = '08:00', string $dayEnd = '17:00'): array
{
    $lunchStart = '11:00';
    $lunchEnd = '13:00';

    $slots = [];
    foreach ([[$dayStart, min($dayEnd, $lunchStart)], [max($dayStart, $lunchEnd), $dayEnd]] as [$rangeStart, $rangeEnd]) {
        if ($rangeStart >= $rangeEnd) {
            continue;
        }
        $cursor = strtotime($rangeStart);
        $end = strtotime($rangeEnd);
        while ($cursor < $end) {
            $slots[] = date('H:i:s', $cursor);
            $cursor += $durationMinutes * 60;
        }
    }
    return $slots;
}

/**
 * @param string|null $startDate  'Y-m-d' to start the window from, or
 *   null for today. Added 2026-09-17 for advance booking (see
 *   patient/book-appointment-actions.php's match_symptoms action) -
 *   letting a patient jump the whole window out to a future month
 *   rather than always starting at "today" for $numDays.
 * @return array{
 *   doctor_id:int, doctor_name:string, clinic_hours:int[], days:array,
 *   booked_slots:string[], server_now:string
 * }|null  Null if doctorId isn't a real, active doctor.
 */
function build_doctor_slot_grid(mysqli $conn, int $doctorId, ?int $numDays = null, ?string $startDate = null): ?array
{
    // FORMERLY a hardcoded default (15) — now admin-editable via
    // admin/system-settings.php ('booking_window_days', category
    // 'scheduling'). Callers can still pass an explicit $numDays to
    // override this, same as before.
    if ($numDays === null) {
        $numDays = get_setting_int($conn, 'booking_window_days', 15);
    }

    $stmt = $conn->prepare("SELECT user_id, first_name, last_name, department_id FROM users WHERE user_id = ? AND role = 'doctor' AND is_active = 1");
    $stmt->bind_param("i", $doctorId);
    $stmt->execute();
    $doctor = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$doctor) {
        return null;
    }

    $duration_minutes = get_department_duration_minutes($conn, (int) $doctor['department_id']);

    $windowStart = new DateTime($startDate !== null ? $startDate : 'today');
    // Never actually starts in the past even if a caller passes a stale
    // date (e.g. a month the patient picked days ago in another tab) -
    // clamped forward to today rather than rejected, since "today" is
    // always a valid, sensible fallback.
    $today = new DateTime('today');
    if ($windowStart < $today) {
        $windowStart = $today;
    }

    $dates = [];
    for ($i = 0; $i < $numDays; $i++) {
        $day = (clone $windowStart)->modify("+$i days");
        if ($day->format('N') == 7) continue; // skip Sunday
        $dates[] = $day->format('Y-m-d');
    }

    // Bounds computed in PHP from $windowStart/$numDays rather than
    // MySQL's CURDATE() - the window doesn't necessarily start today
    // anymore (see $startDate above), so CURDATE() alone can't express
    // it for advance bookings.
    $windowStartStr = $windowStart->format('Y-m-d');
    $windowEndStr = (clone $windowStart)->modify("+$numDays days")->format('Y-m-d');

    $booked_slots = [];
    $stmt = $conn->prepare("
        SELECT slot_start FROM appointments
        WHERE doctor_id = ? AND status IN ('pending', 'confirmed', 'completed')
          AND slot_start >= ? AND slot_start < ?
    ");
    $stmt->bind_param("iss", $doctorId, $windowStartStr, $windowEndStr);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $booked_slots[] = $row['slot_start'];
    }
    $stmt->close();

    // FIXED 2026-09-08: clinic_hours (the shared row template every date
    // column is rendered against) used to come from
    // get_clinic_time_slots($duration_minutes) alone - always the
    // function's 8AM-5PM default, never this doctor's ACTUAL configured
    // hours. A doctor scheduled past 5PM (or, before that default was
    // corrected, past the old hardcoded 4PM) never had their later hours
    // shown at all, on any day, because the row list itself stopped
    // short - no per-day check could fix that, since the rows to check
    // against never existed. Now widened to span the earliest start and
    // latest end actually in use across this doctor's on-duty days in
    // the window being displayed, so every day's real hours fit within
    // the rendered rows; assets/js/book-appointment.js already greys out
    // any row that falls outside a SPECIFIC day's own start/end via the
    // per-day 'start'/'end' fields below, so widening this doesn't cause
    // a day with shorter hours to wrongly show extra open slots.
    $widest_start = null;
    $widest_end = null;

    $days = [];
    foreach ($dates as $date) {
        $sched = resolve_effective_schedule($conn, $doctorId, $date);

        $entry = [
            'date' => $date,
            'label' => date('D, M j', strtotime($date)),
            'on_duty' => $sched['on_duty'],
            'start' => $sched['start'],
            'end' => $sched['end'],
            'full' => false,
        ];

        if ($sched['on_duty']) {
            if ($widest_start === null || $sched['start'] < $widest_start) {
                $widest_start = $sched['start'];
            }
            if ($widest_end === null || $sched['end'] > $widest_end) {
                $widest_end = $sched['end'];
            }
        }

        if ($sched['on_duty'] && $sched['max_patients']) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) AS booked_count FROM appointments
                WHERE doctor_id = ? AND status IN ('pending', 'confirmed', 'completed')
                  AND DATE(slot_start) = ?
            ");
            $stmt->bind_param("is", $doctorId, $date);
            $stmt->execute();
            $booked_that_day = (int) $stmt->get_result()->fetch_assoc()['booked_count'];
            $stmt->close();
            $entry['full'] = $booked_that_day >= (int) $sched['max_patients'];
        }

        $days[] = $entry;
    }

    // No on-duty days at all in this window (e.g. doctor on leave for
    // the entire 15-day range) - fall back to get_clinic_time_slots()'s
    // own default range just so the grid still renders a sensible set of
    // rows (every cell will show "Day Off" regardless).
    $clinic_hours = array_map(
        fn($t) => substr($t, 0, 5),
        $widest_start !== null
            ? get_clinic_time_slots($duration_minutes, $widest_start, $widest_end)
            : get_clinic_time_slots($duration_minutes)
    );

    return [
        'doctor_id' => $doctorId,
        'doctor_name' => trim($doctor['first_name'] . ' ' . $doctor['last_name']),
        'clinic_hours' => $clinic_hours,
        'days' => $days,
        'booked_slots' => $booked_slots,
        'server_now' => date('Y-m-d H:i:s'),
    ];
}

/**
 * True if a slot grid (as returned by build_doctor_slot_grid) has at
 * least one genuinely bookable cell left - i.e. some on-duty, not-full
 * day where at least one of that day's actual clinic hours isn't
 * already booked or already in the past. Added 2026-09-16 for the
 * patient booking rework: once the doctor is auto-assigned rather than
 * chosen by the patient (see patient/includes/symptom_catalog.php),
 * something has to decide WHICH doctor to assign - "has any doctor in
 * this department got real availability" is exactly this check, run
 * once per candidate doctor in booked-this-week order until one comes
 * back true (see patient/book-appointment-actions.php's match_symptoms
 * action).
 *
 * Deliberately coarse compared to validate_candidate_slot() above (no
 * per-slot duplicate-booking re-check, no max_patients re-check beyond
 * what build_doctor_slot_grid already baked into day.full) - this is
 * only ever used to decide "is it even worth offering this doctor",
 * never to actually commit a booking. confirm_booking in
 * patient/book-appointment.php still re-validates the real, final pick
 * from scratch regardless of what this said.
 */
function grid_has_open_slot(array $grid): bool
{
    $bookedSet = array_flip($grid['booked_slots']);
    $serverNow = strtotime($grid['server_now']);

    foreach ($grid['days'] as $day) {
        if (!$day['on_duty'] || $day['full']) {
            continue;
        }

        foreach ($grid['clinic_hours'] as $hourStr) {
            // Same per-day duty-hours narrowing the JS grid applies
            // (assets/js/book-appointment.js's withinDutyHours) - the
            // clinic_hours list is the WIDEST range across every on-duty
            // day in the window, so a specific day can still be
            // narrower than a given hour in that list.
            if ($day['start'] && $day['end']) {
                $timeOnly = $hourStr . ':00';
                if ($timeOnly < $day['start'] || $timeOnly >= $day['end']) {
                    continue;
                }
            }

            $datetime = $day['date'] . ' ' . $hourStr . ':00';
            if (isset($bookedSet[$datetime])) {
                continue;
            }
            if ($serverNow >= strtotime($datetime)) {
                continue; // already past
            }

            return true;
        }
    }

    return false;
}

/**
 * Finds the next open slot for one doctor, preferring the SAME time of
 * day as $preferredTime and the EARLIEST date on/after $searchFromDate -
 * used by doctor/my-schedule-actions.php's bulk_reschedule action to
 * auto-place several of a doctor's own orphaned patients at once instead
 * picking a slot for each one by hand. Tries every clinic hour on a
 * given day (closest to $preferredTime first) before moving to the next
 * day, so a patient keeps roughly their original time if at all
 * possible, and only drifts to a different day once that day is
 * genuinely full.
 *
 * $alreadyTaken carries slots already handed out to OTHER patients
 * earlier in the same bulk batch, since those writes haven't hit the
 * database yet when this runs for the next patient in the loop -
 * without it, two orphaned patients could both be assigned the same new
 * slot before either INSERT happens.
 *
 * @param string[] $alreadyTaken  'Y-m-d H:i:s' strings already claimed this batch.
 * @return string|null  'Y-m-d H:i:s' of the slot found, or null if nothing opened up within the lookahead window.
 */
function find_next_available_slot(mysqli $conn, int $doctorId, string $preferredTime, array $alreadyTaken, string $searchFromDate, ?int $lookaheadDays = null): ?string
{
    // FORMERLY a hardcoded default (15) — now admin-editable via
    // admin/system-settings.php ('reschedule_lookahead_days', category
    // 'scheduling'). Callers can still pass an explicit $lookaheadDays
    // to override this, same as before.
    if ($lookaheadDays === null) {
        $lookaheadDays = get_setting_int($conn, 'reschedule_lookahead_days', 15);
    }

    $departmentId = get_doctor_department_id($conn, $doctorId);
    $duration_minutes = $departmentId !== null ? get_department_duration_minutes($conn, $departmentId) : 30;
    $preferredMinutes = slot_minutes_since_midnight($preferredTime);

    $cursor = new DateTime($searchFromDate);
    for ($i = 0; $i < $lookaheadDays; $i++) {
        $day = (clone $cursor)->modify("+$i days");
        if ($day->format('N') == 7) {
            continue; // Sunday closed, matches build_doctor_slot_grid
        }
        $dateStr = $day->format('Y-m-d');

        $sched = resolve_effective_schedule($conn, $doctorId, $dateStr);
        if (!$sched['on_duty']) {
            continue; // this naturally skips the doctor's leave day(s) too
        }

        // FIXED 2026-09-08: this used to build ONE clinicTimes list
        // outside the day loop, from get_clinic_time_slots($duration_minutes)'s
        // hardcoded default range, then filter it down to $sched['start']/
        // ['end'] below - which only works if that default range already
        // happens to cover this doctor's real hours. Now generated fresh
        // per day from THIS day's actual resolved start/end, so a doctor
        // whose hours run past (or start before) the old default is
        // still offered every real slot they have, not just whichever
        // ones happened to fall inside that unrelated hardcoded window.
        $clinicTimes = get_clinic_time_slots($duration_minutes, $sched['start'], $sched['end']);

        // Try every slot on this day, closest to the original TIME (hour
        // AND minute) first - a plain hour comparison would treat 8:00
        // and 8:30 as equally close to a 9:00 preference, which isn't
        // right once there are two slots per hour.
        usort($clinicTimes, function ($a, $b) use ($preferredMinutes) {
            return abs(slot_minutes_since_midnight($a) - $preferredMinutes) <=> abs(slot_minutes_since_midnight($b) - $preferredMinutes);
        });

        foreach ($clinicTimes as $timeStr) {
            $candidate = "$dateStr $timeStr";
            if (in_array($candidate, $alreadyTaken, true)) {
                continue;
            }

            if (validate_candidate_slot($conn, $doctorId, $candidate) === null) {
                return $candidate;
            }
        }
    }

    return null;
}

/*
 * before actually moving an appointment, since the grid above is only
 * ever advisory (built for display, not to be trusted blindly). Mirrors
 * the re-validation patient/book-appointment.php's confirm_booking step
 * already does for a brand-new booking.
 *
 * @return string|null  An error message, or null if the slot is valid.
 */
function validate_candidate_slot(mysqli $conn, int $doctorId, string $slotStartRaw): ?string
{
    $slot_start_clean = date('Y-m-d H:i:s', strtotime($slotStartRaw));
    $slot_date_only = date('Y-m-d', strtotime($slotStartRaw));
    $slot_time_only = date('H:i:s', strtotime($slotStartRaw));

    if (strtotime($slot_start_clean) <= time()) {
        return "Please choose a time in the future.";
    }

    $on_duty = resolve_effective_schedule($conn, $doctorId, $slot_date_only);
    if (!$on_duty['on_duty']) {
        return "The doctor isn't on duty that day.";
    }
    if ($slot_time_only < $on_duty['start'] || $slot_time_only >= $on_duty['end']) {
        return "That time is outside the doctor's duty hours that day.";
    }

    // FIXED 2026-09-08: this used to check the slot against
    // get_clinic_time_slots($duration_minutes) BEFORE resolving the
    // doctor's actual schedule - using that function's hardcoded
    // default range instead of this specific doctor's actual duty hours
    // for this specific date. A doctor's hours running past (or
    // starting before) that default meant a perfectly valid time was
    // rejected here as "not a valid clinic time slot" even though the
    // duty-hours check two lines up would have passed it. Now resolves
    // the schedule first and generates the valid-times list from THIS
    // day's actual start/end, so the two checks can't disagree.
    $departmentId = get_doctor_department_id($conn, $doctorId);
    $duration_minutes = $departmentId !== null ? get_department_duration_minutes($conn, $departmentId) : 30;
    if (!in_array($slot_time_only, get_clinic_time_slots($duration_minutes, $on_duty['start'], $on_duty['end']), true)) {
        return "That's not a valid clinic time slot.";
    }

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM appointments
         WHERE doctor_id = ? AND slot_start = ? AND status IN ('pending', 'confirmed', 'completed')"
    );
    $stmt->bind_param("is", $doctorId, $slot_start_clean);
    $stmt->execute();
    if ((int) $stmt->get_result()->fetch_assoc()['c'] > 0) {
        $stmt->close();
        return "That slot was just taken - please choose another.";
    }
    $stmt->close();

    if ($on_duty['max_patients']) {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS c FROM appointments
             WHERE doctor_id = ? AND status IN ('pending', 'confirmed', 'completed')
               AND DATE(slot_start) = ?"
        );
        $stmt->bind_param("is", $doctorId, $slot_date_only);
        $stmt->execute();
        $bookedThatDay = (int) $stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();
        if ($bookedThatDay >= (int) $on_duty['max_patients']) {
            return "The doctor is fully booked that day.";
        }
    }

    return null;
}
