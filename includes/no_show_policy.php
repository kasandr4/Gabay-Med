<?php
// includes/no_show_policy.php
// Centralizes the no-show / 3-strikes policy so there's ONE source of
// truth, callable from staff/check-in.php's automatic sweep (see
// run_no_show_sweep() below), the doctor module, admin override tools,
// or anywhere else that needs it.
//
// POLICY:
//   - A patient becomes eligible to be marked "no-show" once 30+ minutes
//     have passed since their slot_start with no check-in recorded.
//   - Marking a no-show increments the patient's no_show_count.
//   - On the 3rd no-show, the patient's account is automatically blocked
//     (status = 'blocked'), per spec 1.7.
//
// INTEGRATION: run_no_show_sweep() is the automatic entry point - it finds
// every appointment that has crossed the grace period with no check-in and
// runs it through record_no_show(). It's called from staff/check-in.php on
// every page load (see that file), so no cron/scheduled task is needed.
// is_eligible_for_no_show() and record_no_show() remain available on their
// own for any manual "Mark No-Show" action a doctor/staff member might
// trigger directly.

define('NO_SHOW_GRACE_MINUTES', 30);
define('NO_SHOW_STRIKE_LIMIT', 3);

/**
 * Returns true if an appointment is old enough (30+ min past slot_start)
 * and still unresolved (pending/confirmed) to be ELIGIBLE for a doctor/staff
 * member to mark it as a no-show. This does not mark anything itself —
 * it's a guard so the "Mark No-Show" action can't be used prematurely.
 */
function is_eligible_for_no_show($appointment_status, $slot_start)
{
    if (!in_array($appointment_status, ['pending', 'confirmed'])) {
        return false; // already resolved one way or another
    }
    $minutes_elapsed = (time() - strtotime($slot_start)) / 60;
    return $minutes_elapsed >= NO_SHOW_GRACE_MINUTES;
}

/**
 * Records a no-show: marks the appointment, increments the patient's
 * strike count, and blocks the account if the limit is reached.
 *
 * Can be called from a manual doctor/staff-facing action, or from
 * run_no_show_sweep() below. The UPDATE itself carries the same guard as
 * is_eligible_for_no_show() (checked_in_at IS NULL AND status still
 * pending/confirmed), so calling this more than once for the same
 * appointment — from any caller, at any time — is a no-op after the
 * first time. Returns true if the account was just blocked as a result
 * of this call (so the caller can notify/inform accordingly), and false
 * both when nothing was blocked AND when there was nothing to mark.
 */
function record_no_show($conn, $appointment_id, $patient_id)
{
    // Mark the appointment itself. The extra WHERE conditions (beyond the
    // id/patient match) are what make this idempotent and safe to call
    // redundantly: an appointment that's already checked in, completed,
    // cancelled, or already no_show will simply not be touched again.
    $stmt = $conn->prepare(
        "UPDATE appointments
         SET status = 'no_show'
         WHERE appointment_id = ? AND patient_id = ?
           AND checked_in_at IS NULL
           AND status IN ('pending', 'confirmed')"
    );
    $stmt->bind_param("ii", $appointment_id, $patient_id);
    $stmt->execute();
    $wasMarked = $stmt->affected_rows > 0;
    $stmt->close();

    if (!$wasMarked) {
        // Nothing eligible was found for this appointment_id/patient_id
        // combination - already resolved some other way. Don't touch the
        // strike count or send a notification for a no-show that didn't
        // actually just happen.
        return false;
    }

    // Increment the strike count
    $stmt = $conn->prepare("UPDATE users SET no_show_count = no_show_count + 1 WHERE user_id = ?");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $stmt->close();

    // Check if this strike crosses the block threshold
    $stmt = $conn->prepare("SELECT no_show_count FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $new_count = $stmt->get_result()->fetch_assoc()['no_show_count'];
    $stmt->close();

    $just_blocked = false;
    if ($new_count >= NO_SHOW_STRIKE_LIMIT) {
        $stmt = $conn->prepare("UPDATE users SET status = 'blocked' WHERE user_id = ?");
        $stmt->bind_param("i", $patient_id);
        $stmt->execute();
        $stmt->close();
        $just_blocked = true;
    }

    // Fire the appropriate notification
    require_once __DIR__ . '/notifications.php';
    if ($just_blocked) {
        create_notification(
            $conn,
            $patient_id,
            "Your account has been blocked after 3 missed appointments. Please visit the front desk to reactivate it.",
            'blocked.php'
        );
    } else {
        create_notification(
            $conn,
            $patient_id,
            "You missed your appointment and it has been marked as a no-show ($new_count of " . NO_SHOW_STRIKE_LIMIT . ").",
            'dashboard.php'
        );
    }

    return $just_blocked;
}

/**
 * Automatic entry point for the no-show policy. Scans for every
 * appointment that is still pending/confirmed, has never been checked in,
 * and is 30+ minutes past its slot_start, and runs each one through
 * record_no_show().
 *
 * Safe to call on every page load from anywhere (currently: the top of
 * staff/check-in.php): the SQL WHERE clause plus record_no_show()'s own
 * guard mean an appointment is only ever marked once, no matter how many
 * times or how many places this sweep runs from.
 *
 * @return array List of appointment_ids that were just marked no-show
 *               during this call (empty if none were eligible).
 */
function run_no_show_sweep($conn)
{
    $graceMinutes = NO_SHOW_GRACE_MINUTES;
    $stmt = $conn->prepare(
        "SELECT appointment_id, patient_id, status, slot_start
         FROM appointments
         WHERE checked_in_at IS NULL
           AND status IN ('pending', 'confirmed')
           AND slot_start <= (NOW() - INTERVAL ? MINUTE)"
    );
    $stmt->bind_param("i", $graceMinutes);
    $stmt->execute();
    $candidates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $marked = [];
    foreach ($candidates as $appointment) {
        // Defensive re-check with the same rule the SQL above already
        // applied, so this stays the single source of truth for what
        // "eligible" means rather than duplicating the 30-minute logic.
        if (!is_eligible_for_no_show($appointment['status'], $appointment['slot_start'])) {
            continue;
        }

        record_no_show($conn, (int) $appointment['appointment_id'], (int) $appointment['patient_id']);
        $marked[] = (int) $appointment['appointment_id'];
    }

    return $marked;
}
