<?php
// doctor/my-schedule.php
// Read-only weekly view of the logged-in doctor's on-duty days, sourced
// from the same `duty_schedule` table that patient/book-appointment.php
// and staff/walk-in.php already use to determine availability. This page
// adds no new table and no new writes - it's purely a calendar-style
// display of data that already drives booking elsewhere in the system.

require_once '../includes/auth_guard.php';
require_role('doctor');
require_once '../config/db.php';
require_once '../includes/no_show_policy.php';

// See doctor/dashboard.php for why this runs here too - without it, a
// patient who never checked in could keep showing as Upcoming instead of
// No Show if no staff member happened to load check-in.php since then.
run_no_show_sweep($conn);

$doctorFirstName = $_SESSION['first_name'];
$doctorLastName  = $_SESSION['last_name'];
$doctorFullName  = "Dr. " . $doctorFirstName . " " . $doctorLastName;
$doctorInitial   = strtoupper(substr($doctorFirstName, 0, 1));
$doctorId        = (int) $_SESSION['user_id'];

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

// --- Pull on-duty days for this week from duty_schedule --------------

$onDutyDates = [];
$stmt = $conn->prepare(
    "SELECT duty_date FROM duty_schedule
     WHERE doctor_id = ? AND duty_date BETWEEN ? AND ?"
);
$stmt->bind_param("iss", $doctorId, $weekStart, $weekEnd);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $onDutyDates[$row['duty_date']] = true;
}
$stmt->close();

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
        "isToday"       => $date === $todayDate,
        "appointments"  => $dayAppointments,
        "activeCount"   => count(array_filter($dayAppointments, function ($a) {
            return !in_array($a['status'], ['cancelled', 'no_show'], true);
        })),
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
                </div>
            </header>

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
                        </div>

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

    <script src="../assets/js/doctor-dashboard.js"></script>
</body>

</html>