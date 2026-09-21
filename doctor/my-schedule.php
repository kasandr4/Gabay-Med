<?php
// doctor/my-schedule.php
// Weekly view of the logged-in doctor's on-duty days, sourced from
// duty_schedule / doctor_weekly_schedules + doctor_schedule_overrides
// (the same tables patient/book-appointment.php and staff/walk-in.php
// read from). Originally read-only; now also lets the doctor:
//   - set a same-day/future override on themselves (an emergency, a
//     meeting, etc.) via doctor/my-schedule-actions.php
//   - see + resolve any of their OWN appointments that override just
//     orphaned, by rescheduling to a new slot or cancelling outright
//     (the "Needs Follow-Up" panel below) - the doctor-side equivalent
//     of staff/schedule-conflicts.php, scoped to just their own patients.

require_once '../includes/auth_guard.php';
require_role('doctor');
require_once '../config/db.php';
require_once '../includes/no_show_policy.php';
require_once '../includes/csrf.php';
require_once '../includes/schedule_conflicts.php';

// See doctor/dashboard.php for why this runs here too - without it, a
// patient who never checked in could keep showing as Upcoming instead of
// No Show if no staff member happened to load check-in.php since then.
run_no_show_sweep($conn);

$doctorFirstName = $_SESSION['first_name'];
$doctorLastName  = $_SESSION['last_name'];
$doctorFullName  = "Dr. " . $doctorFirstName . " " . $doctorLastName;
$doctorInitial   = strtoupper(substr($doctorFirstName, 0, 1));
$doctorId        = (int) $_SESSION['user_id'];

$myConflicts = find_schedule_conflicts_for_doctor($conn, $doctorId);
$overrideStatusLabelsShort = [
    'emergency_leave' => 'Emergency Leave',
    'half_day'         => 'Half Day',
    'training'         => 'Training',
    'meeting'          => 'Meeting',
    'unavailable'      => 'Unavailable',
];

$today = date("F j, Y");
$todayDate = date('Y-m-d');

// --- Figure out which Monday-Sunday week we're showing --------------

// week_offset=0 is the current week, -1 is last week, 1 is next week, etc.
$weekOffset = isset($_GET['week_offset']) ? (int) $_GET['week_offset'] : 0;

// ISO weekday (1 = Monday ... 7 = Sunday), used to find this week's Monday
// regardless of what day it is today.
$isoDayOfWeek = (int) date('N');
$mondayThisWeek = date('Y-m-d', strtotime('-' . ($isoDayOfWeek - 1) . ' days'));
$weekStart = date('Y-m-d', strtotime($mondayThisWeek . ' ' . ($weekOffset >= 0 ? '+' : '') . ($weekOffset * 7) . ' days'));
$weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));

// --- Pull on-duty days for this week -----------------------------------
// Source of truth is now the Admin > Doctor Schedules module:
// doctor_weekly_schedules (recurring pattern) combined with any
// doctor_schedule_overrides (one-off exceptions) for a specific date,
// via includes/schedule_resolver.php - the same resolver the Monthly
// Schedule read-only calendar and patient booking both use, so this
// view, the admin calendar, and what patients can book always agree.
require_once '../includes/schedule_resolver.php';

$onDutyDates = [];
$scheduleByDate = [];
$overrideByDate = [];
$overrideStatusOptions = [
    'available'       => 'Available',
    'half_day'        => 'Half Day',
    'emergency_leave' => 'Emergency Leave',
    'training'        => 'Training',
    'meeting'         => 'Meeting',
    'unavailable'     => 'Unavailable',
];
for ($i = 0; $i < 7; $i++) {
    $date = date('Y-m-d', strtotime($weekStart . " +{$i} days"));
    $resolved = resolve_effective_schedule($conn, $doctorId, $date);
    if ($resolved['on_duty']) {
        $onDutyDates[$date] = true;
        $scheduleByDate[$date] = [
            'start'        => $resolved['start'],
            'end'          => $resolved['end'],
            'max_patients' => $resolved['max_patients'],
        ];
    }
    // Pulled separately from resolve_effective_schedule() (which only
    // returns the final resolved outcome) because the "Update
    // Availability" modal needs to know whether an override already
    // exists for this date (to show "Edit"/"Reset" vs "Set Availability")
    // and, if so, prefill its status/hours/reason - same reasoning as
    // buildOverrideWeek() on the admin side.
    $override = get_schedule_override($conn, $doctorId, $date);
    $overrideByDate[$date] = [
        'status'  => $override['status'] ?? 'no_change',
        'start'   => $override['start_time'] ?? null,
        'end'     => $override['end_time'] ?? null,
        'reason'  => $override['reason'] ?? '',
        'notes'   => $override['notes'] ?? '',
    ];
}

// --- Pull this week's appointments (booked or walk-in) ---------------

// One query for the whole week, bucketed by date in PHP below - avoids
// running 7 separate queries. Includes cancelled/no-show rows too (not
// just active ones) since the point of this view is "what happened /
// is happening this week", not just what's still pending.
$appointmentsByDate = [];
$stmt = $conn->prepare(
    "SELECT a.appointment_id, a.slot_start, a.slot_end, a.status,
            a.is_follow_up, a.booking_source,
            u.first_name, u.last_name, u.account_status
     FROM appointments a
     JOIN users u ON u.user_id = a.patient_id
     WHERE a.doctor_id = ? AND DATE(a.slot_start) BETWEEN ? AND ?
     ORDER BY a.slot_start ASC"
);
$stmt->bind_param("iss", $doctorId, $weekStart, $weekEnd);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $date = date('Y-m-d', strtotime($row['slot_start']));
    $appointmentsByDate[$date][] = $row;
}
$stmt->close();

/**
 * Maps a raw appointments.status value to a display label + the
 * existing status-pill CSS modifier class, reusing the same classes
 * already defined for the Follow-up module rather than inventing new
 * colors for the same meanings.
 */
function appointmentStatusDisplay($status)
{
    $map = [
        "pending"   => ["label" => "Upcoming",  "class" => "status-scheduled"],
        "confirmed" => ["label" => "Upcoming",  "class" => "status-scheduled"],
        "completed" => ["label" => "Completed", "class" => "status-completed"],
        "cancelled" => ["label" => "Cancelled", "class" => "status-cancelled"],
        "no_show"   => ["label" => "No Show",   "class" => "status-no-show"],
    ];
    return $map[$status] ?? ["label" => ucfirst($status), "class" => "status-scheduled"];
}

// --- Build the 7 day cards (Monday through Sunday) -------------------

$dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$weekDays = [];
for ($i = 0; $i < 7; $i++) {
    $date = date('Y-m-d', strtotime($weekStart . " +{$i} days"));
    $dayAppointments = $appointmentsByDate[$date] ?? [];
    $weekDays[] = [
        "date"          => $date,
        "dayName"       => $dayNames[$i],
        "dayNumber"     => date('j', strtotime($date)),
        "onDuty"        => isset($onDutyDates[$date]),
        "shiftStart"    => $scheduleByDate[$date]['start'] ?? null,
        "shiftEnd"      => $scheduleByDate[$date]['end'] ?? null,
        "maxPatients"   => $scheduleByDate[$date]['max_patients'] ?? null,
        "isToday"       => $date === $todayDate,
        "isPast"        => $date < $todayDate,
        "appointments"  => $dayAppointments,
        "activeCount"   => count(array_filter($dayAppointments, function ($a) {
            return !in_array($a['status'], ['cancelled', 'no_show'], true);
        })),
        "override"      => $overrideByDate[$date],
    ];
}

$weekRangeLabel = (date('n', strtotime($weekStart)) === date('n', strtotime($weekEnd)))
    ? date('F j', strtotime($weekStart)) . ' – ' . date('j, Y', strtotime($weekEnd))
    : date('F j', strtotime($weekStart)) . ' – ' . date('F j, Y', strtotime($weekEnd));

$onDutyCount = count($onDutyDates);

$current_page = 'my-schedule';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Schedule - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/doctor-schedule.css">
    <link rel="stylesheet" href="../assets/css/booking.css">
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <!-- Main content -->
        <main class="main-content">

            <!-- Page header -->
            <header class="page-header">
                <div>
                    <h1>My Schedule</h1>
                    <p class="page-subtitle">Your on-duty days and booked patients for the week.</p>
                </div>
                <div class="header-actions">
                    <span class="header-date"><?php echo htmlspecialchars($today); ?></span>
                    <button type="button" class="btn btn-primary" id="msOpenModalBtn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                            <line x1="12" y1="9" x2="12" y2="13"></line>
                            <line x1="12" y1="17" x2="12.01" y2="17"></line>
                        </svg>
                        Update Availability
                    </button>
                </div>
            </header>

            <div class="wo-intro-banner">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="16" x2="12" y2="12"></line>
                    <line x1="12" y1="8" x2="12.01" y2="8"></line>
                </svg>
                <span>Need to step away unexpectedly? Click <strong>Update Availability</strong> above (or on any day below) to mark yourself on emergency leave, half-day, or unavailable — for today or any future date. Patients immediately stop being able to book that date. It won't cancel appointments already booked; those patients still need to be contacted directly. Past days are read-only, which is why you won't see the button on days that have already happened.</span>
            </div>

            <?php if (!empty($myConflicts)): ?>
                <section class="card" id="needs-follow-up">
                    <div class="card-header">
                        <div>
                            <h2>Needs Follow-Up</h2>
                            <span class="card-subtitle">Your own patients already booked at a time your availability change no longer covers</span>
                        </div>
                    </div>

                    <div class="sc-bulk-bar" id="msBulkBar" hidden>
                        <span id="msBulkCount">0 selected</span>
                        <button type="button" class="btn btn-primary rs-btn-sm" id="msBulkRescheduleBtn">Reschedule Selected</button>
                    </div>

                    <div class="table-wrap">
                        <table class="records-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="msSelectAll" aria-label="Select all"></th>
                                    <th>Patient</th>
                                    <th>Phone</th>
                                    <th>Original Time</th>
                                    <th>Your Status</th>
                                    <th>Reason</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($myConflicts as $c): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="ms-row-check"
                                                data-appointment-id="<?php echo (int) $c['appointment_id']; ?>"
                                                aria-label="Select <?php echo htmlspecialchars($c['patient_first'] . ' ' . $c['patient_last']); ?>">
                                        </td>
                                        <td><?php echo htmlspecialchars($c['patient_first'] . ' ' . $c['patient_last']); ?></td>
                                        <td><?php echo $c['phone_number'] ? htmlspecialchars($c['phone_number']) : '—'; ?></td>
                                        <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($c['slot_start']))); ?></td>
                                        <td><span class="status-pill wo-status-pill-<?php echo $c['override_status']; ?>"><?php echo htmlspecialchars($overrideStatusLabelsShort[$c['override_status']] ?? ucfirst($c['override_status'])); ?></span></td>
                                        <td><?php echo $c['override_reason'] ? htmlspecialchars($c['override_reason']) : '—'; ?></td>
                                        <td>
                                            <div class="rs-actions-cell">
                                                <button type="button" class="btn btn-secondary rs-btn-sm ms-reschedule-btn"
                                                    data-appointment-id="<?php echo (int) $c['appointment_id']; ?>"
                                                    data-patient-name="<?php echo htmlspecialchars($c['patient_first'] . ' ' . $c['patient_last']); ?>"
                                                    data-old-time="<?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($c['slot_start']))); ?>"
                                                    data-override-status="<?php echo htmlspecialchars($c['override_status']); ?>"
                                                    data-override-reason="<?php echo htmlspecialchars($c['override_reason'] ?? ''); ?>">
                                                    Reschedule
                                                </button>
                                                <button type="button" class="btn rs-btn-sm rs-btn-danger ms-cancel-appt-btn"
                                                    data-appointment-id="<?php echo (int) $c['appointment_id']; ?>">
                                                    Cancel
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>

            <section class="card">
                <div class="card-header schedule-week-header">
                    <div>
                        <h2><?php echo htmlspecialchars($weekRangeLabel); ?></h2>
                        <span class="card-subtitle">
                            <?php echo $onDutyCount; ?> on-duty day<?php echo $onDutyCount === 1 ? '' : 's'; ?> this week
                        </span>
                    </div>
                    <div class="schedule-week-nav">
                        <a class="btn btn-secondary" href="?week_offset=<?php echo $weekOffset - 1; ?>">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="15 18 9 12 15 6"></polyline>
                            </svg>
                            Previous
                        </a>
                        <?php if ($weekOffset !== 0): ?>
                            <a class="btn btn-secondary" href="?week_offset=0">This Week</a>
                        <?php endif; ?>
                        <a class="btn btn-secondary" href="?week_offset=<?php echo $weekOffset + 1; ?>">
                            Next
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="9 18 15 12 9 6"></polyline>
                            </svg>
                        </a>
                    </div>
                </div>

                <div class="week-schedule-grid">
                    <?php foreach ($weekDays as $day): ?>
                        <a href="#day-<?php echo $day['date']; ?>" class="day-card <?php echo $day['onDuty'] ? 'day-card-on-duty' : 'day-card-off-duty'; ?> <?php echo $day['isToday'] ? 'day-card-today' : ''; ?>">
                            <?php if ($day['isToday']): ?>
                                <span class="day-card-today-badge">Today</span>
                            <?php endif; ?>
                            <span class="day-card-name"><?php echo htmlspecialchars($day['dayName']); ?></span>
                            <span class="day-card-number"><?php echo htmlspecialchars($day['dayNumber']); ?></span>
                            <span class="day-card-status">
                                <?php echo $day['onDuty'] ? 'On Duty' : 'Off Duty'; ?>
                            </span>
                            <?php if ($day['onDuty'] && $day['shiftStart'] && $day['shiftEnd']): ?>
                                <span class="day-card-count"><?php echo htmlspecialchars(date('g:i A', strtotime($day['shiftStart'])) . '–' . date('g:i A', strtotime($day['shiftEnd']))); ?></span>
                            <?php endif; ?>
                            <?php if ($day['activeCount'] > 0): ?>
                                <span class="day-card-count"><?php echo $day['activeCount']; ?> patient<?php echo $day['activeCount'] === 1 ? '' : 's'; ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Per-day appointment details -->
            <section class="card schedule-details-card">
                <div class="card-header">
                    <div>
                        <h2>Appointment Details</h2>
                        <span class="card-subtitle">Who's booked each day, and their status</span>
                    </div>
                </div>

                <?php foreach ($weekDays as $day): ?>
                    <?php $hasOverride = $day['override']['status'] !== 'no_change'; ?>
                    <div class="schedule-day-block" id="day-<?php echo $day['date']; ?>">
                        <div class="schedule-day-block-header">
                            <h3>
                                <?php echo htmlspecialchars($day['dayName']); ?>,
                                <?php echo htmlspecialchars(date('F j', strtotime($day['date']))); ?>
                                <?php if ($day['isToday']): ?><span class="day-card-today-badge schedule-day-today-badge">Today</span><?php endif; ?>
                            </h3>
                            <span class="status-pill <?php echo $day['onDuty'] ? 'status-scheduled' : 'status-cancelled'; ?>">
                                <?php echo $day['onDuty'] ? 'On Duty' : 'Off Duty'; ?>
                            </span>
                            <?php if ($hasOverride): ?>
                                <span class="status-pill wo-status-pill-<?php echo $day['override']['status']; ?>">
                                    <?php echo htmlspecialchars($overrideStatusOptions[$day['override']['status']] ?? ucfirst($day['override']['status'])); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($day['onDuty'] && $day['shiftStart'] && $day['shiftEnd']): ?>
                                <span class="card-subtitle">
                                    <?php echo htmlspecialchars(date('g:i A', strtotime($day['shiftStart'])) . ' – ' . date('g:i A', strtotime($day['shiftEnd']))); ?>
                                    <?php if ($day['maxPatients']): ?>
                                        &middot; up to <?php echo (int) $day['maxPatients']; ?> patients
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!$day['isPast']): ?>
                                <div class="my-schedule-day-actions">
                                    <button type="button" class="btn btn-secondary my-schedule-avail-btn" data-date="<?php echo $day['date']; ?>">
                                        <?php echo $hasOverride ? 'Edit Availability' : 'Update Availability'; ?>
                                    </button>
                                    <?php if ($hasOverride): ?>
                                        <button type="button" class="btn btn-secondary my-schedule-reset-btn" data-date="<?php echo $day['date']; ?>">Reset</button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($hasOverride && $day['override']['reason']): ?>
                            <p class="schedule-day-empty">Reason: <?php echo htmlspecialchars($day['override']['reason']); ?></p>
                        <?php endif; ?>

                        <?php if (empty($day['appointments'])): ?>
                            <p class="schedule-day-empty">No appointments <?php echo $day['onDuty'] ? 'booked yet.' : '- not on duty this day.'; ?></p>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="records-table">
                                    <thead>
                                        <tr>
                                            <th>Time</th>
                                            <th>Patient</th>
                                            <th>Type</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($day['appointments'] as $appt): ?>
                                            <?php $statusInfo = appointmentStatusDisplay($appt['status']); ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars(date('g:i A', strtotime($appt['slot_start']))); ?></td>
                                                <td><?php echo htmlspecialchars($appt['first_name'] . ' ' . $appt['last_name']); ?></td>
                                                <td>
                                                    <?php if ($appt['account_status'] === 'guest'): ?>
                                                        <span class="status-pill status-guest">Guest</span>
                                                    <?php endif; ?>
                                                    <?php if ($appt['booking_source'] === 'walk_in'): ?>
                                                        <span class="status-pill status-walkin">Walk-in</span>
                                                    <?php endif; ?>
                                                    <?php if ($appt['is_follow_up']): ?>
                                                        <span class="tag-followup">Follow-up</span>
                                                    <?php endif; ?>
                                                    <?php if ($appt['account_status'] !== 'guest' && $appt['booking_source'] !== 'walk_in' && !$appt['is_follow_up']): ?>
                                                        <span class="schedule-type-plain">Regular</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><span class="status-pill <?php echo $statusInfo['class']; ?>"><?php echo $statusInfo['label']; ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <!-- Update Availability modal -->
    <div class="modal-backdrop" id="msOverrideModalBackdrop">
        <div class="modal-box ds-modal-box-centered" role="dialog" aria-modal="true" aria-labelledby="msOverrideModalTitle">
            <div class="ds-modal-header">
                <h3 id="msOverrideModalTitle">Update Availability</h3>
                <button type="button" class="um-modal-close" id="msOverrideModalClose" aria-label="Close">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="ds-modal-body">
                <p class="rs-modal-error" id="msModalError"></p>
                <p class="rs-modal-error rs-modal-error-visible" id="msModalAppointmentsWarning" style="display:none;"></p>

                <div class="um-form-row">
                    <div class="um-field">
                        <label for="msFieldDate">Date</label>
                        <input type="date" id="msFieldDate">
                    </div>
                    <div class="um-field">
                        <label for="msFieldDateTo">Repeat Through (optional)</label>
                        <input type="date" id="msFieldDateTo">
                    </div>
                </div>
                <p class="ds-editor-hint" id="msRangeHint">Leave "Repeat Through" blank for just the one date above. Set it for a multi-day event (e.g. a 3-day training) - the same status and hours apply to every day in between, and each day still gets its own affected-patients check before you save.</p>

                <div class="um-field">
                    <label for="msFieldStatus">Status</label>
                    <select id="msFieldStatus">
                        <?php foreach ($overrideStatusOptions as $val => $label): ?>
                            <option value="<?php echo $val; ?>"><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="um-form-row">
                    <div class="um-field">
                        <label for="msFieldStart">Start Time</label>
                        <input type="time" id="msFieldStart">
                    </div>
                    <div class="um-field">
                        <label for="msFieldEnd">End Time</label>
                        <input type="time" id="msFieldEnd">
                    </div>
                </div>

                <div class="um-field">
                    <label for="msFieldReason">Reason</label>
                    <input type="text" id="msFieldReason" placeholder="e.g., Family emergency, feeling unwell...">
                </div>

                <div class="um-field">
                    <label for="msFieldNotes">Notes</label>
                    <textarea id="msFieldNotes" rows="3" placeholder="Optional additional detail for admin/staff..."></textarea>
                </div>

                <p class="ds-editor-hint">This stops new patients from booking the affected date(s) and doesn't touch your recurring weekly schedule. It won't cancel appointments already booked - use the Needs Follow-Up panel below to reschedule or cancel those yourself.</p>

                <div class="um-modal-actions">
                    <button type="button" class="btn btn-secondary" id="msOverrideModalCancel">Cancel</button>
                    <button type="button" class="btn btn-primary" id="msOverrideModalSave">Save</button>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/js/doctor-dashboard.js"></script>
    <script>
        // csrf token + today's date, shared by both
        // doctor-my-schedule-overrides.js (Update Availability modal) and
        // doctor-schedule-reschedule.js (Needs Follow-Up panel's
        // Reschedule modal) - both POST to doctor/my-schedule-actions.php.
        // Override prefill itself now comes from the get_override action
        // at open-time rather than a blob baked into the page, so this
        // works correctly for any date, not just the 7 currently on
        // screen (see doctor-my-schedule-overrides.js's loadOverrideFor()).
        window.msCsrfToken = <?php echo json_encode(csrf_token()); ?>;
        window.msTodayDate = <?php echo json_encode($todayDate); ?>;
    </script>
    <script src="../assets/js/doctor-my-schedule-overrides.js"></script>

    <!-- Reschedule modal (Needs Follow-Up panel) -->
    <div class="modal-backdrop" id="msRescheduleModalBackdrop">
        <div class="modal-box ds-modal-box-centered ds-modal-box-wide" role="dialog" aria-modal="true" aria-labelledby="msRescheduleModalTitle">
            <div class="ds-modal-header">
                <h3 id="msRescheduleModalTitle">Reschedule Appointment</h3>
                <button type="button" class="um-modal-close" id="msRescheduleModalClose" aria-label="Close">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="ds-modal-body">
                <p class="rs-modal-error" id="msRescheduleModalError"></p>
                <p id="msRescheduleModalContext" class="schedule-day-empty" style="margin-bottom: 14px;"></p>

                <div class="um-field">
                    <label for="msRescheduleReason">Reason for Reschedule</label>
                    <select id="msRescheduleReason">
                        <option value="">Select a reason…</option>
                        <option value="Emergency Leave">Emergency Leave</option>
                        <option value="Medical Emergency">Medical Emergency</option>
                        <option value="Hospital Meeting">Hospital Meeting</option>
                        <option value="Personal Leave">Personal Leave</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="um-field" id="msRescheduleReasonOtherWrap" style="display:none;">
                    <label for="msRescheduleReasonOther">Please specify</label>
                    <input type="text" id="msRescheduleReasonOther" maxlength="255" placeholder="Enter reason...">
                </div>

                <div id="ms-slot-content">
                    <p class="empty-hint">Loading open slots...</p>
                </div>
                <div class="um-modal-actions">
                    <button type="button" class="btn btn-secondary" id="msRescheduleModalCancel">Cancel</button>
                    <button type="button" class="btn btn-primary" id="msRescheduleModalConfirm" disabled>Move Appointment</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk reschedule modal (Needs Follow-Up panel) - for moving
         several of the doctor's own orphaned appointments at once
         instead of one Reschedule click per patient. See
         bulk_reschedule in my-schedule-actions.php: doctor picks a
         starting date + one shared reason, everything else is
         auto-assigned. -->
    <div class="modal-backdrop" id="msBulkModalBackdrop">
        <div class="modal-box ds-modal-box-centered" role="dialog" aria-modal="true" aria-labelledby="msBulkModalTitle">
            <div class="ds-modal-header">
                <h3 id="msBulkModalTitle">Reschedule Selected Appointments</h3>
                <button type="button" class="um-modal-close" id="msBulkModalClose" aria-label="Close">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="ds-modal-body">
                <p class="rs-modal-error" id="msBulkModalError"></p>
                <p id="msBulkModalContext" class="schedule-day-empty" style="margin-bottom: 14px;"></p>

                <div id="msBulkModalForm">
                    <div class="um-field">
                        <label for="msBulkReason">Reason for Reschedule</label>
                        <select id="msBulkReason">
                            <option value="">Select a reason…</option>
                            <option value="Emergency Leave">Emergency Leave</option>
                            <option value="Medical Emergency">Medical Emergency</option>
                            <option value="Hospital Meeting">Hospital Meeting</option>
                            <option value="Personal Leave">Personal Leave</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="um-field" id="msBulkReasonOtherWrap" style="display:none;">
                        <label for="msBulkReasonOther">Please specify</label>
                        <input type="text" id="msBulkReasonOther" maxlength="255" placeholder="Enter reason...">
                    </div>

                    <div class="um-field">
                        <label for="msBulkSearchFrom">Reschedule starting from</label>
                        <input type="date" id="msBulkSearchFrom">
                    </div>
                    <p class="ds-editor-hint">Each patient keeps their original time of day where possible. If a day's fully booked, the next patient automatically moves to the next open day - nothing is ever double-booked. Anything that can't be placed within 15 days is left for you to handle individually instead.</p>
                </div>

                <div id="msBulkModalResults" hidden></div>

                <div class="um-modal-actions">
                    <button type="button" class="btn btn-secondary" id="msBulkModalCancel">Cancel</button>
                    <button type="button" class="btn btn-primary" id="msBulkModalConfirm">Reschedule All</button>
                </div>
            </div>
        </div>
    </div>

    <?php
    // Same cache-busting reasoning as patient/book-appointment.php's
    // script tag - forces a re-fetch whenever the file on disk actually
    // changes, instead of the browser silently keeping a stale cached
    // copy after a plain refresh.
    $rescheduleJsPath = __DIR__ . '/../assets/js/doctor-schedule-reschedule.js';
    $rescheduleJsVersion = file_exists($rescheduleJsPath) ? filemtime($rescheduleJsPath) : time();
    $bulkRescheduleJsPath = __DIR__ . '/../assets/js/doctor-bulk-reschedule.js';
    $bulkRescheduleJsVersion = file_exists($bulkRescheduleJsPath) ? filemtime($bulkRescheduleJsPath) : time();
    ?>
    <script src="../assets/js/doctor-schedule-reschedule.js?v=<?= $rescheduleJsVersion ?>"></script>
    <script src="../assets/js/doctor-bulk-reschedule.js?v=<?= $bulkRescheduleJsVersion ?>"></script>
</body>

</html>