<?php
// staff/schedule-conflicts.php
// Read-only "Doctor Availability" screen for front-desk staff - shows
// every active doctor's on/off-duty status for a chosen date, so staff
// can tell a walk-in "Dr. X isn't in today" or plan ahead for tomorrow,
// without needing to open the admin Doctor Schedules module.
//
// This used to be an actionable "Schedule Conflicts" queue (reschedule /
// cancel patients orphaned by a doctor's leave). That responsibility now
// lives entirely on the doctor's own side - see doctor/my-schedule.php's
// "Needs Follow-Up" panel and doctor/my-schedule-actions.php's
// reschedule / bulk_reschedule / cancel_appointment actions. Doctors
// know their own patients and their own reason for being out, so they're
// better placed to pick a new slot than staff relaying secondhand; staff
// still get notified when a doctor's override affects existing bookings
// (see notify_staff_of_schedule_conflict in includes/schedule_conflicts.php),
// it's just informational now rather than a to-do list.
//
// Kept the same filename/nav key ('schedule-conflicts') so the existing
// sidebar entry and staff notification links didn't need touching - only
// the page's actual content and purpose changed.
//
// Nothing on this page writes anything; it's purely
// resolve_effective_schedule() run once per active doctor for the
// selected date, same source of truth as the admin Monthly Schedule
// calendar and the doctor's own week view.

require_once '../includes/auth_guard.php';
require_role('staff');
require_staff_type('front_desk');
require_once '../config/db.php';
require_once '../includes/no_show_policy.php';
require_once '../includes/schedule_resolver.php';

run_no_show_sweep($conn);

$staffFirstName = $_SESSION['first_name'] ?? '';
$staffLastName  = $_SESSION['last_name'] ?? '';
$current_page = 'schedule-conflicts';

$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}
$selectedDateLabel = date('l, F j, Y', strtotime($selectedDate));
$isToday = $selectedDate === date('Y-m-d');
$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));

// Doctor name search + department filter, so staff can jump straight to
// "is Dr. Cruz in today" or "who's on duty in Pediatrics" instead of
// scanning the whole active-doctor list every time.
$searchName = trim($_GET['search'] ?? '');
$departmentId = (int) ($_GET['department_id'] ?? 0);

// Carries the current date/search/department onto the Previous Day /
// Today / Next Day links so switching days never silently drops a
// filter staff already set.
$rsQueryBase = array_filter([
    'search'        => $searchName !== '' ? $searchName : null,
    'department_id' => $departmentId > 0 ? $departmentId : null,
], fn($v) => $v !== null);
function da_day_link(string $date, array $base): string
{
    return '?' . http_build_query(array_merge($base, ['date' => $date]));
}

$stmt = $conn->prepare("SELECT department_id, department_name FROM departments ORDER BY department_name ASC");
$stmt->execute();
$departments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$doctorQuery = "SELECT u.user_id, u.first_name, u.last_name, d.department_name
                FROM users u
                LEFT JOIN departments d ON d.department_id = u.department_id
                WHERE u.role = 'doctor' AND u.is_active = 1";
$doctorParams = [];
$doctorTypes = '';

if ($searchName !== '') {
    $doctorQuery .= " AND CONCAT(u.first_name, ' ', u.last_name) LIKE ?";
    $doctorParams[] = '%' . $searchName . '%';
    $doctorTypes .= 's';
}
if ($departmentId > 0) {
    $doctorQuery .= " AND u.department_id = ?";
    $doctorParams[] = $departmentId;
    $doctorTypes .= 'i';
}
$doctorQuery .= " ORDER BY u.last_name ASC, u.first_name ASC";

$stmt = $conn->prepare($doctorQuery);
if ($doctorTypes !== '') {
    $stmt->bind_param($doctorTypes, ...$doctorParams);
}
$stmt->execute();
$doctors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($doctors as &$doc) {
    $doc['schedule'] = resolve_effective_schedule($conn, (int) $doc['user_id'], $selectedDate);
}
unset($doc);

$statusLabels = [
    'available' => 'Available',
    'off_duty'  => 'Off Duty',
    'leave'     => 'On Leave',
    'half_day'  => 'Half Day',
];

$availableCount = count(array_filter($doctors, fn($d) => $d['schedule']['calendar_status'] === 'available'));
$onLeaveCount = count(array_filter($doctors, fn($d) => $d['schedule']['calendar_status'] === 'leave'));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Availability - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/doctor-schedule.css">
    <link rel="stylesheet" href="../assets/css/booking.css">
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">

            <header class="page-header">
                <div>
                    <h1>Doctor Availability</h1>
                    <p class="page-subtitle">Check who's on duty before booking a walk-in or answering a patient call. Read-only - to change a doctor's schedule, that's done from Admin &rsaquo; Doctor Schedules.</p>
                </div>
            </header>

            <section class="card">
                <div class="card-header">
                    <div>
                        <h2><?php echo htmlspecialchars($selectedDateLabel); ?><?php echo $isToday ? ' (Today)' : ''; ?></h2>
                        <span class="card-subtitle"><?php echo $availableCount; ?> available, <?php echo $onLeaveCount; ?> on leave, <?php echo count($doctors) - $availableCount - $onLeaveCount; ?> off duty</span>
                    </div>
                </div>

                <form method="GET" class="da-filter-bar">
                    <div class="um-field">
                        <label for="daSearch">Doctor</label>
                        <input type="text" id="daSearch" name="search" placeholder="Search by name..." value="<?php echo htmlspecialchars($searchName); ?>">
                    </div>
                    <div class="um-field">
                        <label for="daDepartment">Department</label>
                        <select id="daDepartment" name="department_id">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo (int) $dept['department_id']; ?>" <?php echo $departmentId === (int) $dept['department_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['department_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="um-field">
                        <label for="daDate">Date</label>
                        <input type="date" id="daDate" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>">
                    </div>
                    <button type="submit" class="btn btn-primary rs-btn-sm">Search</button>
                    <?php if ($searchName !== '' || $departmentId > 0): ?>
                        <a href="?date=<?php echo $selectedDate; ?>" class="btn btn-secondary rs-btn-sm">Clear Filters</a>
                    <?php endif; ?>
                </form>

                <div class="schedule-week-nav da-day-nav">
                    <a href="<?php echo da_day_link($prevDate, $rsQueryBase); ?>" class="btn btn-secondary rs-btn-sm">&lsaquo; Previous Day</a>
                    <?php if (!$isToday): ?>
                        <a href="<?php echo da_day_link(date('Y-m-d'), $rsQueryBase); ?>" class="btn btn-secondary rs-btn-sm">Today</a>
                    <?php endif; ?>
                    <a href="<?php echo da_day_link($nextDate, $rsQueryBase); ?>" class="btn btn-secondary rs-btn-sm">Next Day &rsaquo;</a>
                </div>

                <div class="table-wrap">
                    <table class="records-table">
                        <thead>
                            <tr>
                                <th>Doctor</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th>Hours</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($doctors)): ?>
                                <tr>
                                    <td colspan="5" class="schedule-day-empty">No doctors match this search.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($doctors as $doc): ?>
                                    <?php $sched = $doc['schedule']; ?>
                                    <tr>
                                        <td>Dr. <?php echo htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']); ?></td>
                                        <td><?php echo $doc['department_name'] ? htmlspecialchars($doc['department_name']) : '—'; ?></td>
                                        <td>
                                            <span class="status-pill da-status-pill-<?php echo $sched['calendar_status']; ?>">
                                                <?php echo $statusLabels[$sched['calendar_status']] ?? ucfirst($sched['calendar_status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($sched['on_duty'] && $sched['start'] && $sched['end']): ?>
                                                <?php echo date('g:i A', strtotime($sched['start'])) . ' – ' . date('g:i A', strtotime($sched['end'])); ?>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $sched['reason'] ? htmlspecialchars($sched['reason']) : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <script src="../assets/js/doctor-dashboard.js"></script>
</body>

</html>