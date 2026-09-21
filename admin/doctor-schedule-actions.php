<?php
// admin/doctor-schedule-actions.php
// JSON action endpoint backing the "Weekly Recurring Schedule" tab on
// admin/doctor-schedule.php. Mirrors the pattern already established
// across this app's other action endpoints: single action-routed JSON
// endpoint, CSRF-checked, prepared statements only.
//
// Requires doctor_weekly_schedule_migration.sql to have been run - see
// that file for the `doctor_weekly_schedules` table this writes to and
// why it's a new table rather than reusing `duty_schedule`.
//
// This is the ONLY place that writes doctor_weekly_schedules. Everything
// that reads a doctor's effective schedule (doctor/my-schedule.php,
// patient/book-appointment.php) reads back what's written here, so the
// Admin schedule is the single source of truth end to end.

require_once '../includes/auth_guard.php';
require_role('admin');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/schedule_resolver.php';
require_once '../includes/slot_grid.php';
require_once '../includes/schedule_conflicts.php'; // find_conflicting_appointments_for_weekly_change() / notify_staff_of_weekly_schedule_conflict() — see that file's header comment

// FIXED (2026-08-26): Max Patients used to be admin-customizable per day
// (per-row input in both the period builder and the single-row edit
// modal). Per the call to simplify that UI, it's now a fixed value the
// server always writes, regardless of what a request claims — the client
// no longer has any way to set it, and even a tampered request can't
// override this, since every write path below uses this constant instead
// of trusting $_POST/JSON input. The real booking-capacity enforcement
// this feeds (includes/slot_grid.php, includes/schedule_resolver.php,
// patient/book-appointment.php) is completely unaffected — those all
// just read whatever's in the max_patients column, and don't care
// whether an admin chose that number or it's a fixed default.
const DEFAULT_MAX_PATIENTS = 20;

header('Content-Type: application/json');

function respond($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Invalid request method.'], 405);
}

$submittedToken = $_POST['csrf_token'] ?? '';
$expectedToken = $_SESSION['csrf_token'] ?? '';
if ($expectedToken === '' || !hash_equals($expectedToken, $submittedToken)) {
    respond(['success' => false, 'error' => 'Your session expired. Please refresh the page and try again.'], 403);
}

$action = $_POST['action'] ?? '';
$adminId = (int) $_SESSION['user_id'];

/**
 * Confirms doctor_id actually belongs to an active doctor account,
 * so a tampered request can't attach a schedule to a patient/admin/etc.
 */
function findDoctor(mysqli $conn, int $doctorId): ?array
{
    $stmt = $conn->prepare("SELECT user_id, first_name, last_name FROM users WHERE user_id = ? AND role = 'doctor'");
    $stmt->bind_param("i", $doctorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Shared field validation for create/update: day_of_week range, time
 * range (end after start), effective date range. max_patients is no
 * longer validated here since it's never client-provided anymore — see
 * DEFAULT_MAX_PATIENTS above.
 * Returns an array of error strings (empty = valid).
 */
function validateScheduleFields($dayOfWeek, $startTime, $endTime, $effectiveFrom, $effectiveTo): array
{
    $errors = [];

    if ($dayOfWeek < 1 || $dayOfWeek > 7) {
        $errors[] = "Please choose a valid day of the week.";
    }

    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endTime)) {
        $errors[] = "Please provide valid start and end times.";
    } elseif ($endTime <= $startTime) {
        $errors[] = "End time must be later than start time.";
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom)) {
        $errors[] = "Please provide a valid effective start date.";
    }

    if ($effectiveTo !== null) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveTo)) {
            $errors[] = "Please provide a valid effective end date.";
        } elseif ($effectiveTo < $effectiveFrom) {
            $errors[] = "Effective end date can't be before the start date.";
        }
    }

    return $errors;
}

/**
 * Checks whether a new/edited ACTIVE schedule's effective date range
 * would overlap another ACTIVE schedule already on file for the same
 * doctor + day of week. Only active-vs-active overlaps matter, since an
 * inactive row is never used to resolve "what's the doctor's schedule
 * on this date" (see resolveDoctorScheduleForDate() callers elsewhere).
 * $excludeScheduleId lets an update check against every OTHER row.
 * Open-ended ranges (effective_to IS NULL) are treated as extending to
 * a far-future sentinel date for the comparison.
 */
function findOverlappingSchedule(mysqli $conn, int $doctorId, int $dayOfWeek, string $effectiveFrom, ?string $effectiveTo, ?int $excludeScheduleId): ?array
{
    $sql = "SELECT schedule_id, start_time, end_time, effective_from, effective_to
            FROM doctor_weekly_schedules
            WHERE doctor_id = ? AND day_of_week = ? AND status = 'active'
              AND effective_from <= COALESCE(?, '9999-12-31')
              AND COALESCE(effective_to, '9999-12-31') >= ?";
    $params = [$doctorId, $dayOfWeek, $effectiveTo, $effectiveFrom];
    $types = "iiss";

    if ($excludeScheduleId !== null) {
        $sql .= " AND schedule_id != ?";
        $params[] = $excludeScheduleId;
        $types .= "i";
    }
    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function findSchedule(mysqli $conn, int $scheduleId): ?array
{
    $stmt = $conn->prepare("SELECT * FROM doctor_weekly_schedules WHERE schedule_id = ?");
    $stmt->bind_param("i", $scheduleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Runs the weekly-schedule-change conflict check (see
 * includes/schedule_conflicts.php's find_conflicting_appointments_for_weekly_change()
 * header comment for why this was missing) and notifies the affected
 * patients + front-desk staff if anything was orphaned. Shared by
 * update_schedule, archive_schedule, toggle_status (deactivate direction),
 * and publish_opd_schedule below - call this AFTER the write has
 * committed, since the check re-resolves each appointment's schedule live
 * from the database.
 */
function check_and_notify_weekly_schedule_conflicts(mysqli $conn, int $doctorId, string $doctorName): void
{
    $conflicts = find_conflicting_appointments_for_weekly_change($conn, $doctorId);
    if (!empty($conflicts)) {
        notify_staff_of_weekly_schedule_conflict($conn, $doctorName, count($conflicts));
        notify_patients_of_schedule_conflict($conn, $doctorName, $conflicts);
    }
}

$dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

switch ($action) {

    // ------------------------------------------------------------
    // Create a new weekly schedule row for a doctor
    // ------------------------------------------------------------
    case 'create_schedule': {
            $doctorId = (int) ($_POST['doctor_id'] ?? 0);
            $dayOfWeek = (int) ($_POST['day_of_week'] ?? 0);
            $startTime = trim($_POST['start_time'] ?? '');
            $endTime = trim($_POST['end_time'] ?? '');
            $maxPatients = DEFAULT_MAX_PATIENTS;
            $effectiveFrom = trim($_POST['effective_from'] ?? '');
            $effectiveToRaw = trim($_POST['effective_to'] ?? '');
            $effectiveTo = $effectiveToRaw === '' ? null : $effectiveToRaw;
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

            $doctor = findDoctor($conn, $doctorId);
            if (!$doctor) {
                respond(['success' => false, 'error' => 'Please select a valid doctor.'], 422);
            }

            $errors = validateScheduleFields($dayOfWeek, $startTime, $endTime, $effectiveFrom, $effectiveTo);
            if (!empty($errors)) {
                respond(['success' => false, 'error' => implode(' ', $errors)], 422);
            }

            if ($status === 'active') {
                $conflict = findOverlappingSchedule($conn, $doctorId, $dayOfWeek, $effectiveFrom, $effectiveTo, null);
                if ($conflict) {
                    respond([
                        'success' => false,
                        'error' => "This overlaps an existing active {$dayNames[$dayOfWeek]} schedule ("
                            . date('g:i A', strtotime($conflict['start_time'])) . '–' . date('g:i A', strtotime($conflict['end_time']))
                            . ", effective " . date('M j, Y', strtotime($conflict['effective_from']))
                            . ($conflict['effective_to'] ? ' to ' . date('M j, Y', strtotime($conflict['effective_to'])) : ' onward')
                            . "). Deactivate or edit that schedule first."
                    ], 409);
                }
            }

            $stmt = $conn->prepare(
                "INSERT INTO doctor_weekly_schedules
                    (doctor_id, day_of_week, start_time, end_time, max_patients, effective_from, effective_to, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                "iississsi",
                $doctorId,
                $dayOfWeek,
                $startTime,
                $endTime,
                $maxPatients,
                $effectiveFrom,
                $effectiveTo,
                $status,
                $adminId
            );
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();

            respond(['success' => true, 'message' => 'Schedule created.', 'schedule_id' => $newId]);
        }

        // ------------------------------------------------------------
        // Edit an existing weekly schedule row
        // ------------------------------------------------------------
    case 'update_schedule': {
            $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
            $existing = findSchedule($conn, $scheduleId);
            if (!$existing) {
                respond(['success' => false, 'error' => 'Schedule not found.'], 404);
            }

            $doctorId = (int) $existing['doctor_id']; // doctor is not reassignable from the edit form
            $dayOfWeek = (int) ($_POST['day_of_week'] ?? 0);
            $startTime = trim($_POST['start_time'] ?? '');
            $endTime = trim($_POST['end_time'] ?? '');
            $maxPatients = DEFAULT_MAX_PATIENTS;
            $effectiveFrom = trim($_POST['effective_from'] ?? '');
            $effectiveToRaw = trim($_POST['effective_to'] ?? '');
            $effectiveTo = $effectiveToRaw === '' ? null : $effectiveToRaw;
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

            $errors = validateScheduleFields($dayOfWeek, $startTime, $endTime, $effectiveFrom, $effectiveTo);
            if (!empty($errors)) {
                respond(['success' => false, 'error' => implode(' ', $errors)], 422);
            }

            if ($status === 'active') {
                $conflict = findOverlappingSchedule($conn, $doctorId, $dayOfWeek, $effectiveFrom, $effectiveTo, $scheduleId);
                if ($conflict) {
                    respond([
                        'success' => false,
                        'error' => "This overlaps an existing active {$dayNames[$dayOfWeek]} schedule ("
                            . date('g:i A', strtotime($conflict['start_time'])) . '–' . date('g:i A', strtotime($conflict['end_time']))
                            . ", effective " . date('M j, Y', strtotime($conflict['effective_from']))
                            . ($conflict['effective_to'] ? ' to ' . date('M j, Y', strtotime($conflict['effective_to'])) : ' onward')
                            . "). Deactivate or edit that schedule first."
                    ], 409);
                }
            }

            $stmt = $conn->prepare(
                "UPDATE doctor_weekly_schedules
                 SET day_of_week = ?, start_time = ?, end_time = ?, max_patients = ?,
                     effective_from = ?, effective_to = ?, status = ?
                 WHERE schedule_id = ?"
            );
            $stmt->bind_param(
                "ississsi",
                $dayOfWeek,
                $startTime,
                $endTime,
                $maxPatients,
                $effectiveFrom,
                $effectiveTo,
                $status,
                $scheduleId
            );
            $stmt->execute();
            $stmt->close();

            // FIXED 2026-09-14: this write could narrow or shift hours out
            // from under already-booked appointments with zero check - see
            // includes/schedule_conflicts.php's header comment.
            $doctorForConflictCheck = findDoctor($conn, $doctorId);
            if ($doctorForConflictCheck) {
                check_and_notify_weekly_schedule_conflicts(
                    $conn,
                    $doctorId,
                    trim($doctorForConflictCheck['first_name'] . ' ' . $doctorForConflictCheck['last_name'])
                );
            }

            respond(['success' => true, 'message' => 'Schedule updated.']);
        }

        // ------------------------------------------------------------
        // Archive a weekly schedule row - NOT a real DELETE. Sets
        // archived_at instead (see doctor_weekly_schedule_archive_migration.sql),
        // same reasoning as admin/user-management-actions.php's
        // archive_user: hospital records shouldn't disappear, and a
        // schedule that turns out to still be referenced somewhere
        // (past appointments booked against it, audit history) shouldn't
        // vanish just because it's no longer in use going forward.
        // ------------------------------------------------------------
    case 'archive_schedule': {
            $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
            $existing = findSchedule($conn, $scheduleId);
            if (!$existing) {
                respond(['success' => false, 'error' => 'Schedule not found.'], 404);
            }

            // FIXED (2026-07-29): this used to only set archived_at,
            // leaving status untouched. A row archived this way stayed
            // 'active' underneath - hidden from this list, but still
            // fully counted as active by schedule_resolver.php (which
            // checks status, not archived_at) for real patient booking.
            // Archiving a row now also deactivates it, matching what
            // publish_opd_schedule already does when it supersedes a row.
            $stmt = $conn->prepare("UPDATE doctor_weekly_schedules SET status = 'inactive', archived_at = NOW() WHERE schedule_id = ?");
            $stmt->bind_param("i", $scheduleId);
            $stmt->execute();
            $stmt->close();

            // FIXED 2026-09-14: archiving always removes coverage, so it
            // can always orphan appointments - see
            // includes/schedule_conflicts.php's header comment.
            $doctorForConflictCheck = findDoctor($conn, (int) $existing['doctor_id']);
            if ($doctorForConflictCheck) {
                check_and_notify_weekly_schedule_conflicts(
                    $conn,
                    (int) $existing['doctor_id'],
                    trim($doctorForConflictCheck['first_name'] . ' ' . $doctorForConflictCheck['last_name'])
                );
            }

            respond(['success' => true, 'message' => 'Schedule archived.']);
        }

        // ------------------------------------------------------------
        // Toggle Active / Inactive
        // ------------------------------------------------------------
    case 'toggle_status': {
            $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
            $existing = findSchedule($conn, $scheduleId);
            if (!$existing) {
                respond(['success' => false, 'error' => 'Schedule not found.'], 404);
            }

            $newStatus = $existing['status'] === 'active' ? 'inactive' : 'active';

            if ($newStatus === 'active') {
                $conflict = findOverlappingSchedule(
                    $conn,
                    (int) $existing['doctor_id'],
                    (int) $existing['day_of_week'],
                    $existing['effective_from'],
                    $existing['effective_to'],
                    $scheduleId
                );
                if ($conflict) {
                    respond([
                        'success' => false,
                        'error' => "Can't reactivate — it overlaps another active {$dayNames[$existing['day_of_week']]} schedule for this doctor. Deactivate that one first."
                    ], 409);
                }
            }

            $stmt = $conn->prepare("UPDATE doctor_weekly_schedules SET status = ? WHERE schedule_id = ?");
            $stmt->bind_param("si", $newStatus, $scheduleId);
            $stmt->execute();
            $stmt->close();

            // FIXED 2026-09-14: deactivating removes coverage, same as
            // archive_schedule above - reactivating only adds it back, so
            // no check is needed on that direction.
            if ($newStatus === 'inactive') {
                $doctorForConflictCheck = findDoctor($conn, (int) $existing['doctor_id']);
                if ($doctorForConflictCheck) {
                    check_and_notify_weekly_schedule_conflicts(
                        $conn,
                        (int) $existing['doctor_id'],
                        trim($doctorForConflictCheck['first_name'] . ' ' . $doctorForConflictCheck['last_name'])
                    );
                }
            }

            respond(['success' => true, 'message' => 'Status updated.', 'status' => $newStatus]);
        }

        // ------------------------------------------------------------
        // Suggests where the NEXT period should start for this doctor,
        // so "Set Up New Period" doesn't default to today - which, if
        // today falls in the middle of an already-running period, is
        // exactly what caused an earlier bug (splitting an active row
        // into a meaningless one-day sliver instead of creating a clean
        // new one). If every one of this doctor's active weekdays is
        // bounded to the same kind of end date, suggest the 1st of the
        // month AFTER the LATEST of those end dates - this page bills
        // itself as "updated once a month," so the suggestion always
        // rounds forward to a clean month boundary (e.g. an end date of
        // Aug 29 still suggests Sep 1, not Aug 30) rather than assuming
        // whoever set the end date typed the literal last day of the
        // month. Publishing at that date creates a genuinely separate
        // new row with nothing to merge or trim. If anything is
        // open-ended (no end date set), there's no clean boundary to
        // suggest, so this falls back to null and the client defaults
        // to today as before.
        // ------------------------------------------------------------
    case 'get_next_period_start': {
            $doctorId = (int) ($_POST['doctor_id'] ?? 0);

            $doctor = findDoctor($conn, $doctorId);
            if (!$doctor) {
                respond(['success' => false, 'error' => 'Please select a valid doctor.'], 422);
            }

            $stmt = $conn->prepare(
                "SELECT MAX(effective_to) AS latest_end,
                        SUM(CASE WHEN effective_to IS NULL THEN 1 ELSE 0 END) AS open_ended_count,
                        COUNT(*) AS total_count
                 FROM doctor_weekly_schedules
                 WHERE doctor_id = ? AND status = 'active' AND archived_at IS NULL"
            );
            $stmt->bind_param("i", $doctorId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $suggestedStart = null;
            if ($row && (int) $row['total_count'] > 0 && (int) $row['open_ended_count'] === 0 && $row['latest_end']) {
                $suggestedStart = date('Y-m-d', strtotime($row['latest_end'] . ' first day of next month'));
            }

            respond(['success' => true, 'suggested_start' => $suggestedStart]);
        }


        // Returns, for each of the 7 weekdays, whatever OPD pattern is
        // currently ACTIVE as of $asOfDate - i.e. exactly what a new
        // period starting on that date would inherit if left untouched.
        // Days with no active row come back disabled with sane defaults
        // so the builder still renders a full week.
        // ------------------------------------------------------------
    case 'get_opd_pattern': {
            $doctorId = (int) ($_POST['doctor_id'] ?? 0);
            $asOfDate = trim($_POST['as_of_date'] ?? '');

            $doctor = findDoctor($conn, $doctorId);
            if (!$doctor) {
                respond(['success' => false, 'error' => 'Please select a valid doctor.'], 422);
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate)) {
                respond(['success' => false, 'error' => 'Please provide a valid date.'], 422);
            }

            $days = [];
            for ($d = 1; $d <= 7; $d++) {
                $stmt = $conn->prepare(
                    "SELECT start_time, end_time, max_patients
                     FROM doctor_weekly_schedules
                     WHERE doctor_id = ? AND day_of_week = ? AND status = 'active'
                       AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
                     LIMIT 1"
                );
                $stmt->bind_param("iiss", $doctorId, $d, $asOfDate, $asOfDate);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $days[] = [
                    'day_of_week'  => $d,
                    'enabled'      => (bool) $row,
                    'start_time'   => $row['start_time'] ?? '08:00:00',
                    'end_time'     => $row['end_time'] ?? '17:00:00',
                    'max_patients' => $row ? (int) $row['max_patients'] : 20,
                ];
            }

            respond(['success' => true, 'days' => $days]);
        }

        // ------------------------------------------------------------
        // Publishes a doctor's OPD (patient-booking) weekly pattern for
        // a new period in one shot - backs the "Set Up New Period"
        // button, the single entry point for every OPD schedule change
        // that isn't a quick fix to a row already on file (that's what
        // the per-row Edit button - update_schedule above - is still
        // for). Adding one new day, changing a handful of days, or
        // rolling the whole week over for a new cycle are all the same
        // action here: submit all 7 weekdays, only the ones that
        // actually change get written.
        //
        // For each of the 7 submitted weekdays (enabled + hours, or
        // disabled = day off for this period):
        //   0. If exactly one active row already covers the new period
        //      with identical hours, skip it entirely - no write. This
        //      is what makes "add one day" cheap: the other 6 days you
        //      didn't touch don't get closed and reopened just because
        //      they were included in the submission.
        //   0.5. If exactly one active row has identical hours but
        //      DOESN'T fully cover the new period (e.g. an end date is
        //      being removed or pushed out, and "Period Start" - which
        //      defaults to today - happens to land inside that row),
        //      widen that same row in place instead of trimming it and
        //      inserting a near-duplicate next to it.
        //   1. Otherwise, finds every currently-ACTIVE row for that
        //      weekday which overlaps the new [effective_from,
        //      effective_to] period.
        //   2. Clears the overlap WITHOUT deleting anything:
        //      - starts before the new period -> shorten its tail
        //        (effective_to = day before the new period starts)
        //      - starts on/after the new period but runs past its end
        //        -> shorten its head instead (effective_from = day
        //        after the new period ends)
        //      - otherwise entirely inside the new period -> fully
        //        superseded, so mark inactive + archived_at (stays on
        //        file, just drops out of the active list)
        //   3. If this weekday is on duty for the new period, inserts
        //      ONE new row covering exactly that period.
        //
        // Does NOT touch doctor_schedule_overrides (doctor-owned, see
        // doctor/my-schedule-actions.php) or
        // appointment_reschedule_history (the Reschedule History tab's
        // audit trail) - this only ever reads/writes
        // doctor_weekly_schedules, the same table create_schedule /
        // update_schedule already write to.
        //
        // INTENTIONAL: if a row being trimmed had a later effective_to
        // (or was open-ended) than the new period's own end date, that
        // remainder is NOT auto-restored after the new period ends - it
        // becomes unscheduled until the next period is published. This
        // matches how the hospital actually runs (see the semi-monthly
        // roster photo): each cycle is planned fresh, nothing carries
        // over by default. If a truly temporary override that reverts
        // on its own is ever needed, that's a separate feature - this
        // builder always defines "the new normal going forward."
        // ------------------------------------------------------------
    case 'publish_opd_schedule': {
            $doctorId = (int) ($_POST['doctor_id'] ?? 0);
            $newFrom = trim($_POST['effective_from'] ?? '');
            $newToRaw = trim($_POST['effective_to'] ?? '');
            $newTo = $newToRaw === '' ? null : $newToRaw;
            $daysJson = $_POST['days_json'] ?? '';

            $doctor = findDoctor($conn, $doctorId);
            if (!$doctor) {
                respond(['success' => false, 'error' => 'Please select a valid doctor.'], 422);
            }

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newFrom)) {
                respond(['success' => false, 'error' => 'Please provide a valid start date for this period.'], 422);
            }
            if ($newTo !== null) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newTo)) {
                    respond(['success' => false, 'error' => 'Please provide a valid end date for this period.'], 422);
                }
                if ($newTo < $newFrom) {
                    respond(['success' => false, 'error' => "This period's end date can't be before its start date."], 422);
                }
            }

            $days = json_decode($daysJson, true);
            if (!is_array($days) || count($days) !== 7) {
                respond(['success' => false, 'error' => 'Please provide all 7 days of the week.'], 422);
            }

            // Validate everything up front, before touching the database.
            $seenDays = [];
            foreach ($days as $day) {
                $dow = (int) ($day['day_of_week'] ?? 0);
                if ($dow < 1 || $dow > 7 || isset($seenDays[$dow])) {
                    respond(['success' => false, 'error' => 'Invalid schedule data submitted.'], 422);
                }
                $seenDays[$dow] = true;

                if (!empty($day['enabled'])) {
                    $errors = validateScheduleFields(
                        $dow,
                        trim($day['start_time'] ?? ''),
                        trim($day['end_time'] ?? ''),
                        $newFrom,
                        $newTo
                    );
                    if (!empty($errors)) {
                        respond(['success' => false, 'error' => "{$dayNames[$dow]}: " . implode(' ', $errors)], 422);
                    }
                }
            }

            $conn->begin_transaction();
            try {
                foreach ($days as $day) {
                    $dow = (int) $day['day_of_week'];
                    $enabled = !empty($day['enabled']);

                    // ---- Steps 1+2: clear the overlap for this weekday,
                    // without deleting any existing row. ----
                    $stmt = $conn->prepare(
                        "SELECT schedule_id, start_time, end_time, max_patients, effective_from, effective_to
                         FROM doctor_weekly_schedules
                         WHERE doctor_id = ? AND day_of_week = ? AND status = 'active'
                           AND effective_from <= COALESCE(?, '9999-12-31')
                           AND COALESCE(effective_to, '9999-12-31') >= ?"
                    );
                    $stmt->bind_param("iiss", $doctorId, $dow, $newTo, $newFrom);
                    $stmt->execute();
                    $overlapping = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();

                    // Skip this weekday entirely if a single existing row
                    // already covers the new period with identical hours -
                    // e.g. publishing a period to add just one new day
                    // shouldn't close/reopen every other unchanged day's
                    // row too. Only every weekday actually being changed
                    // gets a write.
                    $sameHours = false;
                    if ($enabled && count($overlapping) === 1) {
                        $existing = $overlapping[0];
                        $sameHours = substr($existing['start_time'], 0, 5) === substr($day['start_time'], 0, 5)
                            && substr($existing['end_time'], 0, 5) === substr($day['end_time'], 0, 5)
                            && (int) $existing['max_patients'] === DEFAULT_MAX_PATIENTS;
                        // max_patients is no longer client-provided (see
                        // DEFAULT_MAX_PATIENTS above) — this compares the
                        // existing row against the fixed default rather
                        // than against $day['max_patients'], which no
                        // longer exists in the request. An old row still
                        // carrying a pre-fix custom value correctly counts
                        // as "different" here, so republishing naturally
                        // migrates it down to the fixed default instead of
                        // silently preserving a stale custom number.
                        // FIXED (2026-07-29): this used to treat an
                        // open-ended existing row (effective_to NULL) as
                        // "covering" ANY new request, including one
                        // asking to add a real end date - so narrowing an
                        // "Ongoing" schedule down to e.g. "ends August
                        // 31" was silently skipped as "no change needed."
                        // Only genuinely identical tails now count as
                        // already covered: both open-ended, or the
                        // existing bound already reaches at least as far
                        // as what's being requested.
                        $fullyCovers = $existing['effective_from'] <= $newFrom
                            && (
                                ($newTo === null && $existing['effective_to'] === null)
                                || ($newTo !== null && $existing['effective_to'] !== null && $existing['effective_to'] >= $newTo)
                            );

                        if ($sameHours && $fullyCovers) {
                            continue; // already exactly this - nothing to change
                        }
                    }

                    // The hours aren't actually changing - only the date
                    // range is (e.g. removing an end date, or pushing it
                    // further out). Widen the SAME row instead of trimming
                    // it and inserting a near-identical new one: there's no
                    // reason two rows should exist side by side with
                    // identical hours just because "Period Start" happened
                    // to fall in the middle of an already-active range.
                    if ($enabled && $sameHours) {
                        $existing = $overlapping[0];
                        $mergedFrom = ($existing['effective_from'] <= $newFrom) ? $existing['effective_from'] : $newFrom;

                        $upd = $conn->prepare("UPDATE doctor_weekly_schedules SET effective_from = ?, effective_to = ? WHERE schedule_id = ?");
                        $upd->bind_param("ssi", $mergedFrom, $newTo, $existing['schedule_id']);
                        $upd->execute();
                        $upd->close();
                        continue; // this weekday is fully handled, no insert needed
                    }

                    foreach ($overlapping as $row) {
                        $rowFrom = $row['effective_from'];
                        $rowTo = $row['effective_to'];

                        if ($rowFrom < $newFrom) {
                            // Starts before the new period - shorten its tail.
                            $trimmedTo = date('Y-m-d', strtotime($newFrom . ' -1 day'));
                            $upd = $conn->prepare("UPDATE doctor_weekly_schedules SET effective_to = ? WHERE schedule_id = ?");
                            $upd->bind_param("si", $trimmedTo, $row['schedule_id']);
                            $upd->execute();
                            $upd->close();
                        } elseif ($newTo !== null && $rowTo !== null && $rowTo > $newTo) {
                            // Starts on/after the new period but runs past
                            // its end - shorten its head so the portion
                            // beyond the new period survives untouched.
                            $trimmedFrom = date('Y-m-d', strtotime($newTo . ' +1 day'));
                            $upd = $conn->prepare("UPDATE doctor_weekly_schedules SET effective_from = ? WHERE schedule_id = ?");
                            $upd->bind_param("si", $trimmedFrom, $row['schedule_id']);
                            $upd->execute();
                            $upd->close();
                        } else {
                            // Entirely inside the new period - fully
                            // superseded. Never deleted: deactivated and
                            // archived so it drops out of the active list
                            // but the row (and its history) stays on file.
                            $upd = $conn->prepare("UPDATE doctor_weekly_schedules SET status = 'inactive', archived_at = NOW() WHERE schedule_id = ?");
                            $upd->bind_param("i", $row['schedule_id']);
                            $upd->execute();
                            $upd->close();
                        }
                    }

                    // ---- Step 3: insert the new row, if this weekday is
                    // on duty for the new period. ----
                    if ($enabled) {
                        $startTime = trim($day['start_time']);
                        $endTime = trim($day['end_time']);
                        $maxPatients = DEFAULT_MAX_PATIENTS;

                        // Safety net - should never fire since the loop
                        // above just cleared this exact overlap, but
                        // guards against a data race or a logic gap.
                        $conflict = findOverlappingSchedule($conn, $doctorId, $dow, $newFrom, $newTo, null);
                        if ($conflict) {
                            throw new Exception("overlap:{$dayNames[$dow]}");
                        }

                        $stmt = $conn->prepare(
                            "INSERT INTO doctor_weekly_schedules
                                (doctor_id, day_of_week, start_time, end_time, max_patients, effective_from, effective_to, status, created_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)"
                        );
                        $stmt->bind_param(
                            "iississi",
                            $doctorId,
                            $dow,
                            $startTime,
                            $endTime,
                            $maxPatients,
                            $newFrom,
                            $newTo,
                            $adminId
                        );
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                if (strpos($e->getMessage(), 'overlap:') === 0) {
                    $dayLabel = substr($e->getMessage(), 8);
                    respond(['success' => false, 'error' => "Couldn't publish - an unexpected overlap was found on {$dayLabel}. Please refresh and try again."], 409);
                }
                respond(['success' => false, 'error' => 'Something went wrong while publishing the schedule. Please try again.'], 500);
            }

            // FIXED 2026-09-14: publishing a period can narrow, shift, or
            // entirely drop a weekday's hours - the bulk equivalent of
            // update_schedule/archive_schedule/toggle_status, and the
            // highest-risk write path here since it can touch all 7
            // weekdays in one submit. See
            // includes/schedule_conflicts.php's header comment for why
            // this check was missing across this whole file until now.
            check_and_notify_weekly_schedule_conflicts(
                $conn,
                $doctorId,
                trim($doctor['first_name'] . ' ' . $doctor['last_name'])
            );

            respond(['success' => true, 'message' => 'OPD schedule published for the new period.']);
        }

        // ------------------------------------------------------------
        // save_override / delete_override REMOVED (2026-07-27): admin
        // used to be able to create/edit/reset a doctor's
        // doctor_schedule_overrides row here (the old Weekly Overrides
        // tab). That's now doctor-only, via doctor/my-schedule.php's
        // Update Availability modal -> doctor/my-schedule-actions.php's
        // save_override/delete_override actions (session-scoped to the
        // logged-in doctor, so no doctor_id is ever taken from the
        // request there). Admin's read-only view of what doctors have
        // rescheduled now lives in the Reschedule History tab on
        // doctor-schedule.php instead, backed by
        // appointment_reschedule_history - see that tab's markup for the
        // read-only query.
        // ------------------------------------------------------------

    default:
        respond(['success' => false, 'error' => 'Unknown action.'], 400);
}
