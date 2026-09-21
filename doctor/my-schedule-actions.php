<?php
// doctor/my-schedule-actions.php
// JSON action endpoint backing the "Update Availability" modal on
// doctor/my-schedule.php. Lets a doctor create/reset their OWN
// doctor_schedule_overrides row for a specific date (e.g. an emergency
// that takes them off duty), without needing an admin to do it for them.
//
// This intentionally does NOT touch doctor_weekly_schedules (the
// recurring base pattern) - that stays admin-only, since it defines the
// doctor's standing assignment. This endpoint only ever writes a
// same-table row as admin/doctor-schedule-actions.php's save_override /
// delete_override, so the moment a doctor saves an override here, the
// exact same schedule_resolver.php that patient/book-appointment.php and
// staff/walk-in.php use to build the bookable slot grid picks it up -
// meaning an emergency_leave override created from this page immediately
// stops new patients from booking that date, with no separate step.
//
// SECURITY: doctor_id is NEVER taken from the request. It is always the
// logged-in doctor's own $_SESSION['user_id'], so no amount of tampering
// with the POST body lets a doctor touch another doctor's schedule.

require_once '../includes/auth_guard.php';
require_role('doctor');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/schedule_resolver.php';
require_once '../includes/notifications.php';
require_once '../includes/schedule_conflicts.php';
require_once '../includes/slot_grid.php';

// Reschedule requires a reason, which is logged to
// appointment_reschedule_history (see reschedule action below) before the
// appointment itself is updated - this is what powers the Reschedule
// History tab on admin/doctor-schedule.php.

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

// Always the logged-in doctor - see security note above.
$doctorId = (int) $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

/**
 * Looks up a doctor's display name for the staff notification message.
 */
function findDoctorName(mysqli $conn, int $doctorId): string
{
    $stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $doctorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? trim($row['first_name'] . ' ' . $row['last_name']) : 'your doctor';
}

/**
 * Moves one of THIS doctor's own appointments to a new slot: re-fetches
 * and re-validates everything server-side, logs to
 * appointment_reschedule_history, updates the appointment, and returns
 * enough detail for the caller to notify the patient - all inside one
 * transaction. Shared by the single 'reschedule' action and
 * 'bulk_reschedule' below, so a batch move and a one-off move both leave
 * an identical audit trail.
 *
 * @return array{success:bool, error?:string, old_slot_start?:string, new_slot_start?:string, patient_id?:int}
 */
function perform_doctor_reschedule(mysqli $conn, int $appointmentId, int $doctorId, string $newSlotStartRaw, string $reason): array
{
    $stmt = $conn->prepare(
        "SELECT appointment_id, patient_id, slot_start, slot_end, status
         FROM appointments
         WHERE appointment_id = ? AND doctor_id = ? AND status IN ('pending', 'confirmed')"
    );
    $stmt->bind_param("ii", $appointmentId, $doctorId);
    $stmt->execute();
    $appt = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$appt) {
        return ['success' => false, 'error' => 'That appointment is no longer active, or is not yours.'];
    }

    $error = validate_candidate_slot($conn, $doctorId, $newSlotStartRaw);
    if ($error) {
        return ['success' => false, 'error' => $error];
    }

    $oldSlotStartClean = $appt['slot_start'];
    $oldSlotEndClean = $appt['slot_end'];
    $newSlotStartClean = date('Y-m-d H:i:s', strtotime($newSlotStartRaw));

    // PER-DEPARTMENT DURATION (settled 2026-08-06): this used to be a
    // hardcoded +1800 (30 min) - missed when that change first went in,
    // since validate_candidate_slot() above already resolves the correct
    // duration internally but doesn't hand it back to the caller. Resolved
    // the same way here so a reschedule's new slot_end actually matches
    // this department's configured duration instead of always being 30
    // minutes regardless.
    $departmentIdForDuration = get_doctor_department_id($conn, $doctorId);
    $durationMinutes = $departmentIdForDuration !== null ? get_department_duration_minutes($conn, $departmentIdForDuration) : 30;
    $newSlotEndClean = date('Y-m-d H:i:s', strtotime($newSlotStartRaw) + ($durationMinutes * 60));

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "INSERT INTO appointment_reschedule_history
                (appointment_id, doctor_id, old_slot_start, old_slot_end, new_slot_start, new_slot_end, emergency_reason)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            "iisssss",
            $appointmentId,
            $doctorId,
            $oldSlotStartClean,
            $oldSlotEndClean,
            $newSlotStartClean,
            $newSlotEndClean,
            $reason
        );
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare(
            "UPDATE appointments SET slot_start = ?, slot_end = ?
             WHERE appointment_id = ? AND doctor_id = ? AND status IN ('pending', 'confirmed')"
        );
        $stmt->bind_param("ssii", $newSlotStartClean, $newSlotEndClean, $appointmentId, $doctorId);
        $stmt->execute();
        $updated = $stmt->affected_rows > 0;
        $stmt->close();

        if (!$updated) {
            throw new Exception('slot_taken');
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        if ($e->getMessage() === 'slot_taken') {
            return ['success' => false, 'error' => 'That slot was just taken - please choose another.'];
        }
        return ['success' => false, 'error' => 'Something went wrong while rescheduling. Please try again.'];
    }

    return [
        'success'         => true,
        'old_slot_start'  => $oldSlotStartClean,
        'new_slot_start'  => $newSlotStartClean,
        'patient_id'      => (int) $appt['patient_id'],
    ];
}

/**
 * Creates/updates ONE date's override row for a doctor, finds whatever
 * appointments it just orphaned, and notifies staff + those patients.
 * Shared by the single-date 'save_override' action and the new
 * 'save_override_range' below (multi-day leave/training in one action
 * instead of setting the same status on each day by hand). Status/hours
 * are assumed already validated by the caller once, since they're the
 * same for every date in a range - only the date itself varies here.
 *
 * FIXED (2026-09-04): this used to be defined mid-switch, between the
 * preview_override_impact and save_override case blocks. PHP only
 * defines a function once execution actually flows past that line, and
 * a switch jumps straight to the matching case label - so any request
 * with action=save_override or action=save_override_range skipped
 * right over the definition and fatal-errored with "Call to undefined
 * function" every single time. Moved above the switch so it's
 * unconditionally defined no matter which case runs.
 *
 * @return int  how many appointments this specific date's save orphaned
 */
function perform_save_override_for_date(mysqli $conn, int $doctorId, string $overrideDate, string $status, ?string $startTime, ?string $endTime, string $reason, string $notes): int
{
    $stmt = $conn->prepare(
        "INSERT INTO doctor_schedule_overrides
    (doctor_id, override_date, status, start_time, end_time, reason, notes, created_by)
 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
 ON DUPLICATE KEY UPDATE
    status = VALUES(status), start_time = VALUES(start_time), end_time = VALUES(end_time),
    reason = VALUES(reason), notes = VALUES(notes), created_by = VALUES(created_by)"
    );
    $stmt->bind_param(
        "issssssi",
        $doctorId,
        $overrideDate,
        $status,
        $startTime,
        $endTime,
        $reason,
        $notes,
        $doctorId
    );
    $stmt->execute();
    $stmt->close();

    // Find any appointments this override just orphaned (patients
    // already booked at a time the doctor is no longer covering).
    // Front-desk staff get a follow-up alert AND each affected patient
    // gets an immediate heads-up of their own - this never cancels or
    // reschedules anything on its own; the doctor does that from the
    // Needs Follow-Up panel, or staff can check the read-only Doctor
    // Availability page.
    $conflicts = find_conflicting_appointments_for_override($conn, $doctorId, $overrideDate, $status, $startTime, $endTime);
    if (!empty($conflicts)) {
        $doctorName = findDoctorName($conn, $doctorId);
        notify_staff_of_schedule_conflict($conn, $doctorName, $overrideDate, count($conflicts));
        notify_patients_of_schedule_conflict($conn, $doctorName, $conflicts);
    }

    return count($conflicts);
}

switch ($action) {

    // ------------------------------------------------------------
    // Look up this doctor's own override + active-appointment count
    // for an arbitrary date. Used so the header-level "Update
    // Availability" button (not tied to any specific day row) still
    // shows correct prefilled data / warnings even for a date outside
    // whichever week is currently on screen.
    // ------------------------------------------------------------
    case 'get_override': {
            $lookupDate = trim($_POST['override_date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $lookupDate)) {
                respond(['success' => false, 'error' => 'Please provide a valid date.'], 422);
            }

            $override = get_schedule_override($conn, $doctorId, $lookupDate);

            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS c FROM appointments
                 WHERE doctor_id = ? AND DATE(slot_start) = ?
                   AND status IN ('pending', 'confirmed')"
            );
            $stmt->bind_param("is", $doctorId, $lookupDate);
            $stmt->execute();
            $activeCount = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();

            respond([
                'success' => true,
                'status'  => $override['status'] ?? 'no_change',
                'start'   => $override['start_time'] ?? null,
                'end'     => $override['end_time'] ?? null,
                'reason'  => $override['reason'] ?? '',
                'notes'   => $override['notes'] ?? '',
                'active_appointments' => $activeCount,
            ]);
        }

        // ------------------------------------------------------------
        // Live preview of who a PROPOSED (not-yet-saved) override would
        // orphan - lets the doctor see the real impact before
        // confirming, instead of finding out via the Needs Follow-Up
        // panel afterward. Reuses find_conflicting_appointments_for_override(),
        // the exact same function save_override(_range) calls once it's
        // actually saved, so this preview can never disagree with what
        // actually happens - no separate "estimate" logic to drift out
        // of sync.
        //
        // Deliberately covers every status, not just the fully-off ones:
        // half_day only orphans appointments OUTSIDE the hours being
        // kept, which get_override's old flat "any booking that day"
        // count couldn't distinguish - this calls the real per-status
        // logic instead, so half_day/training/meeting are just as
        // accurate as emergency_leave/unavailable.
        //
        // override_date_to is optional - when present (a multi-day
        // range), this checks every date in the range and returns one
        // combined list with each entry labeled by its own date, instead
        // of the doctor only ever seeing day one's impact before saving
        // three days at once.
        // ------------------------------------------------------------
    case 'preview_override_impact': {
            $overrideDate = trim($_POST['override_date'] ?? '');
            $overrideDateTo = trim($_POST['override_date_to'] ?? '');
            $status = trim($_POST['status'] ?? 'no_change');
            $startTimeRaw = trim($_POST['start_time'] ?? '');
            $endTimeRaw = trim($_POST['end_time'] ?? '');

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $overrideDate)) {
                respond(['success' => false, 'error' => 'Please provide a valid date.'], 422);
            }
            if ($overrideDateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $overrideDateTo)) {
                $overrideDateTo = '';
            }
            if ($overrideDateTo !== '' && $overrideDateTo < $overrideDate) {
                $overrideDateTo = ''; // ignore an invalid/backwards range rather than error mid-typing
            }

            $validStatuses = ['no_change', 'available', 'half_day', 'emergency_leave', 'training', 'meeting', 'unavailable'];
            if (!in_array($status, $validStatuses, true)) {
                respond(['success' => false, 'error' => 'Please choose a valid status.'], 422);
            }

            $startTime = $startTimeRaw === '' ? null : $startTimeRaw;
            $endTime = $endTimeRaw === '' ? null : $endTimeRaw;

            // half_day's conflict check needs both hours to mean anything
            // ("outside this window" is undefined without a window) -
            // just report nothing yet rather than erroring, since the
            // doctor is still mid-typing the hours in this case.
            if ($status === 'half_day' && (!$startTime || !$endTime)) {
                respond(['success' => true, 'count' => 0, 'appointments' => []]);
            }

            $dateTo = $overrideDateTo !== '' ? $overrideDateTo : $overrideDate;
            // Same 31-day cap as save_override_range - a preview beyond
            // that would just be checking a range that can't be saved
            // anyway.
            $spanDays = (strtotime($dateTo) - strtotime($overrideDate)) / 86400 + 1;
            if ($spanDays > 31) {
                respond(['success' => false, 'error' => 'This range is more than 31 days - please check it in smaller chunks.'], 422);
            }

            $isRange = $overrideDateTo !== '' && $overrideDateTo !== $overrideDate;
            $preview = [];
            $cursor = new DateTime($overrideDate);
            $end = new DateTime($dateTo);
            while ($cursor <= $end) {
                $dateStr = $cursor->format('Y-m-d');
                $conflicts = find_conflicting_appointments_for_override($conn, $doctorId, $dateStr, $status, $startTime, $endTime);
                foreach ($conflicts as $c) {
                    $preview[] = [
                        'patient_name' => trim($c['first_name'] . ' ' . $c['last_name']),
                        'time'         => $isRange
                            ? date('M j, g:i A', strtotime($c['slot_start']))
                            : date('g:i A', strtotime($c['slot_start'])),
                    ];
                }
                $cursor->modify('+1 day');
            }

            respond(['success' => true, 'count' => count($preview), 'appointments' => $preview]);
        }

        // ------------------------------------------------------------
        // Create/update the doctor's own override for a date.
        // ------------------------------------------------------------
    case 'save_override': {
            $overrideDate = trim($_POST['override_date'] ?? '');
            $status = trim($_POST['status'] ?? 'no_change');
            $startTimeRaw = trim($_POST['start_time'] ?? '');
            $endTimeRaw = trim($_POST['end_time'] ?? '');
            $reason = trim($_POST['reason'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            $validStatuses = ['no_change', 'available', 'half_day', 'emergency_leave', 'training', 'meeting', 'unavailable'];
            if (!in_array($status, $validStatuses, true)) {
                respond(['success' => false, 'error' => 'Please choose a valid status.'], 422);
            }

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $overrideDate)) {
                respond(['success' => false, 'error' => 'Please provide a valid date.'], 422);
            }

            // A doctor can flag today onward (including today, for an
            // emergency that starts right now) - not edit history.
            if ($overrideDate < date('Y-m-d')) {
                respond(['success' => false, 'error' => "You can't set availability for a past date."], 422);
            }

            $statusesWithHours = ['available', 'half_day', 'training', 'meeting'];
            $startTime = $startTimeRaw === '' ? null : $startTimeRaw;
            $endTime = $endTimeRaw === '' ? null : $endTimeRaw;

            if (in_array($status, $statusesWithHours, true)) {
                if (!$startTime || !$endTime) {
                    respond(['success' => false, 'error' => 'Please provide a start and end time for this status.'], 422);
                }
                if ($endTime <= $startTime) {
                    respond(['success' => false, 'error' => 'End time must be later than start time.'], 422);
                }
            } else {
                // emergency_leave / unavailable / no_change: hours don't apply
                $startTime = null;
                $endTime = null;
            }

            $affected = perform_save_override_for_date($conn, $doctorId, $overrideDate, $status, $startTime, $endTime, $reason, $notes);

            respond([
                'success' => true,
                'message' => 'Your availability has been updated.',
                'affected_appointments' => $affected,
            ]);
        }

        // ------------------------------------------------------------
        // Same as save_override, but applies the SAME status/hours/
        // reason to every calendar date in an inclusive range - built
        // for multi-day leave/training (e.g. a 3-day conference) so the
        // doctor sets it once instead of repeating the single-date flow
        // three separate times. Every date gets its OWN
        // doctor_schedule_overrides row (the schema is still one row per
        // date - this just automates creating several of them), so
        // editing or resetting any individual day afterward still works
        // exactly like it always has via the single-date flow.
        // ------------------------------------------------------------
    case 'save_override_range': {
            $dateFrom = trim($_POST['override_date_from'] ?? '');
            $dateTo = trim($_POST['override_date_to'] ?? '');
            $status = trim($_POST['status'] ?? 'no_change');
            $startTimeRaw = trim($_POST['start_time'] ?? '');
            $endTimeRaw = trim($_POST['end_time'] ?? '');
            $reason = trim($_POST['reason'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            $validStatuses = ['no_change', 'available', 'half_day', 'emergency_leave', 'training', 'meeting', 'unavailable'];
            if (!in_array($status, $validStatuses, true)) {
                respond(['success' => false, 'error' => 'Please choose a valid status.'], 422);
            }

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
                respond(['success' => false, 'error' => 'Please provide a valid start and end date.'], 422);
            }
            if ($dateFrom < date('Y-m-d')) {
                respond(['success' => false, 'error' => "You can't set availability for a past date."], 422);
            }
            if ($dateTo < $dateFrom) {
                respond(['success' => false, 'error' => "The end date can't be before the start date."], 422);
            }

            $spanDays = (strtotime($dateTo) - strtotime($dateFrom)) / 86400 + 1;
            if ($spanDays > 31) {
                respond(['success' => false, 'error' => 'This range is more than 31 days - please set it up in smaller chunks.'], 422);
            }

            $statusesWithHours = ['available', 'half_day', 'training', 'meeting'];
            $startTime = $startTimeRaw === '' ? null : $startTimeRaw;
            $endTime = $endTimeRaw === '' ? null : $endTimeRaw;

            if (in_array($status, $statusesWithHours, true)) {
                if (!$startTime || !$endTime) {
                    respond(['success' => false, 'error' => 'Please provide a start and end time for this status.'], 422);
                }
                if ($endTime <= $startTime) {
                    respond(['success' => false, 'error' => 'End time must be later than start time.'], 422);
                }
            } else {
                $startTime = null;
                $endTime = null;
            }

            $totalAffected = 0;
            $daysUpdated = 0;

            $conn->begin_transaction();
            try {
                $cursor = new DateTime($dateFrom);
                $end = new DateTime($dateTo);
                while ($cursor <= $end) {
                    $dateStr = $cursor->format('Y-m-d');
                    $totalAffected += perform_save_override_for_date($conn, $doctorId, $dateStr, $status, $startTime, $endTime, $reason, $notes);
                    $daysUpdated++;
                    $cursor->modify('+1 day');
                }
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                respond(['success' => false, 'error' => 'Something went wrong while saving this range. Please try again.'], 500);
            }

            respond([
                'success'               => true,
                'message'               => "Availability updated for {$daysUpdated} day" . ($daysUpdated === 1 ? '' : 's') . '.',
                'affected_appointments' => $totalAffected,
                'days_updated'          => $daysUpdated,
            ]);
        }

        // ------------------------------------------------------------
        // Remove the doctor's own override for a date ("Reset").
        // ------------------------------------------------------------
    case 'delete_override': {
            $overrideDate = trim($_POST['override_date'] ?? '');

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $overrideDate)) {
                respond(['success' => false, 'error' => 'Please provide a valid date.'], 422);
            }

            $stmt = $conn->prepare("DELETE FROM doctor_schedule_overrides WHERE doctor_id = ? AND override_date = ?");
            $stmt->bind_param("is", $doctorId, $overrideDate);
            $stmt->execute();
            $stmt->close();

            respond(['success' => true, 'message' => 'Availability override removed.']);
        }

        // ------------------------------------------------------------
        // Open-slot grid for THIS doctor's own upcoming schedule -
        // powers the "Reschedule" modal in the Needs Follow-Up panel.
        // Same shared helper patient booking and staff rescheduling use,
        // so "what's open" is identical everywhere in the app.
        // ------------------------------------------------------------
    case 'get_slots': {
            $grid = build_doctor_slot_grid($conn, $doctorId);
            // Can't actually fail here (doctorId is always a real, active
            // logged-in doctor per require_role), but guard anyway.
            if (!$grid) {
                respond(['success' => false, 'error' => 'Could not load your schedule.'], 500);
            }
            respond(array_merge(['success' => true], $grid));
        }

        // ------------------------------------------------------------
        // Move one of THIS doctor's own orphaned appointments to a new
        // slot. Staff no longer have an equivalent action of their own -
        // they get a read-only Doctor Availability view instead (see
        // staff/schedule-conflicts.php) - this and bulk_reschedule below
        // are now the only ways an orphaned appointment gets fixed.
        // ------------------------------------------------------------
    case 'reschedule': {
            $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
            $newSlotStart = trim($_POST['slot_start'] ?? '');
            $reason = trim($_POST['reason'] ?? '');

            if ($appointmentId <= 0 || $newSlotStart === '') {
                respond(['success' => false, 'error' => 'Missing appointment or time.'], 422);
            }

            // A reason is required for every doctor-initiated reschedule -
            // it's shown to the patient in their notification and recorded
            // in appointment_reschedule_history for the admin audit log.
            if ($reason === '') {
                respond(['success' => false, 'error' => 'Please select or enter a reason for rescheduling.'], 422);
            }
            if (mb_strlen($reason) > 255) {
                $reason = mb_substr($reason, 0, 255);
            }

            $result = perform_doctor_reschedule($conn, $appointmentId, $doctorId, $newSlotStart, $reason);
            if (!$result['success']) {
                respond(['success' => false, 'error' => $result['error']], 422);
            }

            $doctorName = findDoctorName($conn, $doctorId);
            $oldWhenLabel = date('M j, Y \a\t g:i A', strtotime($result['old_slot_start']));
            $newWhenLabel = date('M j, Y \a\t g:i A', strtotime($result['new_slot_start']));

            // Kept short and reason-inclusive to fit the notifications
            // table's message column (varchar(255)) and the existing
            // bell UI. Truncated defensively in case of a very long
            // custom "Other" reason plus a long doctor name.
            $notifMessage = "Your appointment with Dr. {$doctorName} was moved from {$oldWhenLabel} to {$newWhenLabel}. Reason: {$reason}.";
            if (mb_strlen($notifMessage) > 255) {
                $notifMessage = mb_substr($notifMessage, 0, 252) . '...';
            }
            create_notification($conn, $result['patient_id'], $notifMessage, 'dashboard.php');

            respond(['success' => true, 'message' => "Appointment moved to {$newWhenLabel}. The patient has been notified."]);
        }

        // ------------------------------------------------------------
        // Move SEVERAL of THIS doctor's own orphaned appointments at
        // once - built for "I'm out 1-2 days and 10 patients need
        // moving," so the doctor isn't clicking Reschedule ten separate
        // times. One shared $reason applies to the whole batch (same
        // required-reason rule as the single action above).
        //
        // Processes appointments earliest-original-time first. For each
        // one, find_next_available_slot() (includes/slot_grid.php) tries
        // to keep the same time of day on the earliest day on/after
        // $searchFromDate the doctor is actually on duty, spilling to a
        // later day automatically if that day's full. Anything that
        // still can't be placed within the lookahead window is reported
        // back in "unresolved" rather than silently dropped, so the
        // doctor can finish those few with the single 'reschedule'
        // action instead.
        // ------------------------------------------------------------
    case 'bulk_reschedule': {
            $idsRaw = trim($_POST['appointment_ids'] ?? '');
            $searchFromDate = trim($_POST['search_from_date'] ?? '');
            $reason = trim($_POST['reason'] ?? '');

            $appointmentIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $idsRaw)))));
            if (empty($appointmentIds)) {
                respond(['success' => false, 'error' => 'No appointments selected.'], 422);
            }
            if ($reason === '') {
                respond(['success' => false, 'error' => 'Please select or enter a reason for rescheduling.'], 422);
            }
            if (mb_strlen($reason) > 255) {
                $reason = mb_substr($reason, 0, 255);
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $searchFromDate)) {
                $searchFromDate = date('Y-m-d', strtotime('+1 day'));
            }

            $placeholders = implode(',', array_fill(0, count($appointmentIds), '?'));
            $types = str_repeat('i', count($appointmentIds));
            $params = array_merge([$doctorId], $appointmentIds);
            $stmt = $conn->prepare(
                "SELECT a.appointment_id, a.patient_id, a.slot_start, a.status,
                        pat.first_name AS patient_first, pat.last_name AS patient_last
                 FROM appointments a
                 JOIN users pat ON pat.user_id = a.patient_id
                 WHERE a.doctor_id = ? AND a.appointment_id IN ($placeholders) AND a.status IN ('pending', 'confirmed')
                 ORDER BY a.slot_start ASC"
            );
            $stmt->bind_param("i" . $types, ...$params);
            $stmt->execute();
            $appts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (empty($appts)) {
                respond(['success' => false, 'error' => 'None of the selected appointments are still active, or they are not yours.'], 404);
            }

            $doctorName = findDoctorName($conn, $doctorId);
            $rescheduledCount = 0;
            $unresolved = [];
            $takenThisBatch = [];

            foreach ($appts as $appt) {
                $appointmentId = (int) $appt['appointment_id'];
                $patientName = trim($appt['patient_first'] . ' ' . $appt['patient_last']);
                $oldWhenLabel = date('M j, Y g:i A', strtotime($appt['slot_start']));
                $preferredTime = date('H:i:s', strtotime($appt['slot_start']));

                $newSlot = find_next_available_slot($conn, $doctorId, $preferredTime, $takenThisBatch, $searchFromDate);

                if ($newSlot === null) {
                    $unresolved[] = [
                        'appointment_id' => $appointmentId,
                        'patient_name'   => $patientName,
                        'old_time'       => $oldWhenLabel,
                        'reason'         => 'No open slot found in the next 15 days.',
                    ];
                    continue;
                }

                $takenThisBatch[] = $newSlot;

                $result = perform_doctor_reschedule($conn, $appointmentId, $doctorId, $newSlot, $reason);

                if ($result['success']) {
                    $rescheduledCount++;
                    $newWhenLabel = date('M j, Y \a\t g:i A', strtotime($result['new_slot_start']));
                    $oldWhenLabelFull = date('M j, Y \a\t g:i A', strtotime($result['old_slot_start']));
                    $notifMessage = "Your appointment with Dr. {$doctorName} was moved from {$oldWhenLabelFull} to {$newWhenLabel}. Reason: {$reason}.";
                    if (mb_strlen($notifMessage) > 255) {
                        $notifMessage = mb_substr($notifMessage, 0, 252) . '...';
                    }
                    create_notification($conn, $result['patient_id'], $notifMessage, 'dashboard.php');
                } else {
                    $unresolved[] = [
                        'appointment_id' => $appointmentId,
                        'patient_name'   => $patientName,
                        'old_time'       => $oldWhenLabel,
                        'reason'         => $result['error'],
                    ];
                }
            }

            $message = "{$rescheduledCount} appointment" . ($rescheduledCount === 1 ? '' : 's') . ' rescheduled.';
            if (!empty($unresolved)) {
                $message .= ' ' . count($unresolved) . ' need manual attention.';
            }

            respond([
                'success'           => true,
                'rescheduled_count' => $rescheduledCount,
                'unresolved'        => $unresolved,
                'message'           => $message,
            ]);
        }

        // ------------------------------------------------------------
        // Cancel one of THIS doctor's own orphaned appointments outright
        // (when no future slot works for the patient). Same cancel+
        // notify pattern as patient/appointment-detail.php's own cancel
        // action — EXCEPT that one goes through
        // cancellation_policy.php's cancel_patient_appointment(), which
        // this doesn't (different notification text, no late-cancel
        // tracking — that policy is specifically about patient behavior,
        // doesn't apply to a doctor-initiated cancellation).
        // ------------------------------------------------------------
    case 'cancel_appointment': {
            $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
            if ($appointmentId <= 0) {
                respond(['success' => false, 'error' => 'Missing appointment.'], 422);
            }

            $stmt = $conn->prepare(
                "SELECT appointment_id, patient_id, department_id, slot_start, status
                 FROM appointments
                 WHERE appointment_id = ? AND doctor_id = ? AND status IN ('pending', 'confirmed')"
            );
            $stmt->bind_param("ii", $appointmentId, $doctorId);
            $stmt->execute();
            $appt = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$appt) {
                respond(['success' => false, 'error' => 'That appointment is no longer active, or is not yours.'], 404);
            }

            $stmt = $conn->prepare("UPDATE appointments SET status = 'cancelled' WHERE appointment_id = ? AND doctor_id = ?");
            $stmt->bind_param("ii", $appointmentId, $doctorId);
            $stmt->execute();
            $stmt->close();

            $doctorName = findDoctorName($conn, $doctorId);
            $whenLabel = date('M j, Y \a\t g:i A', strtotime($appt['slot_start']));
            create_notification(
                $conn,
                (int) $appt['patient_id'],
                "Your appointment with Dr. {$doctorName} on {$whenLabel} was cancelled because the doctor became unavailable. Please book a new appointment.",
                'dashboard.php'
            );

            respond(['success' => true, 'message' => 'Appointment cancelled and the patient has been notified.']);
        }

    default:
        respond(['success' => false, 'error' => 'Unknown action.'], 400);
}
