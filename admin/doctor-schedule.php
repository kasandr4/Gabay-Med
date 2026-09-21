<?php
// admin/doctor-schedule.php
// Module — Doctor Schedule Management (UI ONLY).
//
// Per the brief: no backend logic, no SQL, no writes beyond the existing
// auth guard. The hospital schedules doctors on a MONTHLY basis, so an
// admin picks a doctor and a month, sees a full calendar of that doctor's
// planned shifts, and can open any day to edit its shift details. Every
// doctor, every day's status/shift/room/capacity, and the quick-copy
// actions below are static/mock data or client-side-only DOM operations
// so the page can be designed and reviewed before the data layer exists.
//
// TODO(backend): this is the write-side counterpart to the read-only view
// at doctor/my-schedule.php, which currently reads on-duty *dates* for the
// logged-in doctor from `duty_schedule` (doctor_id, duty_date). To support
// monthly scheduling with the richer per-day detail this page edits
// (status, start/end time, department, room, max patients, notes),
// `duty_schedule` will need extra columns (or a companion table keyed by
// doctor_id + duty_date) - see the shape of $monthData below for the
// fields to persist. The Save button in the day panel should become an
// UPSERT for that single date; the "Copy" quick actions should become a
// bulk UPSERT across the affected dates, all in one transaction.

require_once '../includes/auth_guard.php';
require_role('admin');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/schedule_resolver.php';

$today = date("F j, Y");
$todayDate = date('Y-m-d');

// ---- Real doctor roster (used by all three tabs) -----------------------
// All three tabs are now real and backend-connected, so all three share
// this one query against the actual `users` table rather than a mock
// list. Reshaped into the {name, department, status, initials} shape the
// Monthly/Weekly Overrides markup already expected, so those templates
// needed no structural changes - just a real data source.
$doctors = [];
$realDoctors = []; // kept as an alias some blocks below still reference
$result = $conn->query(
    "SELECT u.user_id, u.first_name, u.last_name, u.status, d.department_name
     FROM users u
     LEFT JOIN departments d ON d.department_id = u.department_id
     WHERE u.role = 'doctor'
     ORDER BY u.last_name, u.first_name"
);
while ($row = $result->fetch_assoc()) {
    $realDoctors[$row['user_id']] = $row;
    $doctors[$row['user_id']] = [
        'name'       => 'Dr. ' . $row['first_name'] . ' ' . $row['last_name'],
        'department' => $row['department_name'] ?? 'No department',
        // Real accounts only use active/blocked/confined/deceased - map
        // anything other than active to "deactivated" for the badge/read-only
        // banner logic the Monthly & Weekly Overrides tabs already had.
        'status'     => $row['status'] === 'active' ? 'active' : 'deactivated',
        'initials'   => strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1)),
    ];
}

// ---- Selected doctor ---------------------------------------------------
$selectedDoctorId = isset($_GET['doctor_id']) ? (int) $_GET['doctor_id'] : ($doctors ? array_key_first($doctors) : 0);
if (!isset($doctors[$selectedDoctorId])) {
    $selectedDoctorId = $doctors ? array_key_first($doctors) : 0;
}
$selectedDoctor = $doctors[$selectedDoctorId] ?? ['name' => 'No Doctors', 'department' => '—', 'status' => 'deactivated', 'initials' => '—'];
$isDeactivated = $selectedDoctor['status'] === 'deactivated';

// ---- Selected month ------------------------------------------------------
// month_offset=0 is the current month, -1 is last month, 1 is next month.
$monthOffset = isset($_GET['month_offset']) ? (int) $_GET['month_offset'] : 0;
$monthStartTs = strtotime(date('Y-m-01') . " {$monthOffset} months");
$monthLabel = date('F Y', $monthStartTs);
$daysInMonth = (int) date('t', $monthStartTs);
$firstOfMonthDow = (int) date('N', $monthStartTs); // 1 = Monday ... 7 = Sunday
$year = (int) date('Y', $monthStartTs);
$monthNum = (int) date('n', $monthStartTs);

$statusLabels = [
    'available' => 'Available',
    'off_duty'  => 'Off Duty',
    'leave'     => 'Leave',
    'half_day'  => 'Half Day',
];

// ---- Build the read-only Monthly calendar from real data ---------------
// This tab no longer edits anything - it's a resolved view combining the
// Weekly Recurring Schedule with any Weekly Overrides for each date, via
// resolve_effective_schedule() in includes/schedule_resolver.php. Editing
// happens on the other two (real) tabs; this one just visualizes the
// result across a full month.
$monthData = [];
$workingDays = 0;
$offDays = 0;
$leaveDays = 0;
$halfDays = 0;
$totalCapacity = 0;

for ($d = 1; $d <= $daysInMonth; $d++) {
    // Note: resolve_effective_schedule() runs 2 small prepared queries per
    // call, so this is ~60 queries for a 30-day month. Acceptable for a
    // low-traffic admin page; if this page gets slow, prefetch the whole
    // month's doctor_weekly_schedules + doctor_schedule_overrides rows in
    // 2 queries up front and resolve in-memory instead.
    $dateStr = sprintf('%04d-%02d-%02d', $year, $monthNum, $d);
    $resolved = $selectedDoctorId ? resolve_effective_schedule($conn, $selectedDoctorId, $dateStr) : [
        'on_duty' => false,
        'start' => null,
        'end' => null,
        'max_patients' => null,
        'calendar_status' => 'off_duty',
        'notes' => null,
    ];

    $day = [
        'date'        => $dateStr,
        'status'      => $resolved['calendar_status'],
        'start'       => $resolved['start'],
        'end'         => $resolved['end'],
        'maxPatients' => $resolved['max_patients'] ?? 0,
        'notes'       => $resolved['notes'] ?? '',
    ];
    $monthData[$dateStr] = $day;

    switch ($day['status']) {
        case 'available':
            $workingDays++;
            break;
        case 'off_duty':
            $offDays++;
            break;
        case 'leave':
            $leaveDays++;
            break;
        case 'half_day':
            $halfDays++;
            break;
    }
    $totalCapacity += $day['maxPatients'];
}

// ---- Build the calendar grid (leading/trailing blanks for a Mon-start grid)
$leadingBlanks = $firstOfMonthDow - 1; // 0 if month starts on a Monday
$totalCells = $leadingBlanks + $daysInMonth;
$trailingBlanks = (7 - ($totalCells % 7)) % 7;

// =========================================================================
// Reschedule History tab (REAL, read-only)
// -------------------------------------------------------------------------
// Replaces the old Weekly Overrides tab (2026-07-27). That tab let admin
// create/edit/reset a doctor's doctor_schedule_overrides row directly -
// fully duplicating what a doctor can already do themselves from
// doctor/my-schedule.php's Update Availability modal. Rather than keep
// two write paths to the same table, admin's view here is now read-only:
// every doctor-initiated reschedule, logged by
// doctor/my-schedule-actions.php's `reschedule` action into
// appointment_reschedule_history (see
// appointment_reschedule_history_migration.sql). No writes happen from
// this tab at all - nothing in doctor-schedule-actions.php backs it.

$activeTab = 'monthly';
if (isset($_GET['tab']) && $_GET['tab'] === 'history') {
    $activeTab = 'history';
} elseif (isset($_GET['tab']) && $_GET['tab'] === 'recurring') {
    $activeTab = 'recurring';
}

// Optional doctor filter (0 = all doctors) - independent of the Monthly
// Schedule tab's selected doctor, same pattern the old Weekly Overrides
// tab used for its own doctor selector.
$historyDoctorId = isset($_GET['history_doctor_id']) ? (int) $_GET['history_doctor_id'] : 0;
if ($historyDoctorId !== 0 && !isset($doctors[$historyDoctorId])) {
    $historyDoctorId = 0;
}

$rescheduleHistoryRows = [];
if ($historyDoctorId !== 0) {
    $stmt = $conn->prepare(
        "SELECT h.history_id, h.old_slot_start, h.old_slot_end, h.new_slot_start, h.new_slot_end,
                h.emergency_reason, h.changed_at,
                doc.first_name AS doc_first, doc.last_name AS doc_last,
                pat.first_name AS pat_first, pat.last_name AS pat_last
         FROM appointment_reschedule_history h
         JOIN users doc ON doc.user_id = h.doctor_id
         JOIN appointments a ON a.appointment_id = h.appointment_id
         JOIN users pat ON pat.user_id = a.patient_id
         WHERE h.doctor_id = ?
         ORDER BY h.changed_at DESC"
    );
    $stmt->bind_param("i", $historyDoctorId);
    $stmt->execute();
    $rescheduleHistoryRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $result = $conn->query(
        "SELECT h.history_id, h.old_slot_start, h.old_slot_end, h.new_slot_start, h.new_slot_end,
                h.emergency_reason, h.changed_at,
                doc.first_name AS doc_first, doc.last_name AS doc_last,
                pat.first_name AS pat_first, pat.last_name AS pat_last
         FROM appointment_reschedule_history h
         JOIN users doc ON doc.user_id = h.doctor_id
         JOIN appointments a ON a.appointment_id = h.appointment_id
         JOIN users pat ON pat.user_id = a.patient_id
         ORDER BY h.changed_at DESC"
    );
    $rescheduleHistoryRows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

// =========================================================================
// Weekly Recurring Schedule tab (REAL, backend-connected)
// -------------------------------------------------------------------------
// This is the source of truth read by doctor/my-schedule.php and
// patient/book-appointment.php - see doctor_weekly_schedule_migration.sql
// and admin/doctor-schedule-actions.php for the table and the endpoint
// this tab's JS posts to.

$rsDoctorId = isset($_GET['rs_doctor_id']) ? (int) $_GET['rs_doctor_id'] : 0;
if (!isset($realDoctors[$rsDoctorId])) {
    $rsDoctorId = $realDoctors ? array_key_first($realDoctors) : 0;
}

$rsDayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

$rsSchedules = [];
if ($rsDoctorId) {
    $stmt = $conn->prepare(
        "SELECT schedule_id, doctor_id, day_of_week, start_time, end_time, max_patients,
                effective_from, effective_to, status
         FROM doctor_weekly_schedules
         WHERE doctor_id = ? AND archived_at IS NULL
         ORDER BY day_of_week ASC, effective_from ASC"
    );
    $stmt->bind_param("i", $rsDoctorId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rsSchedules[] = $row;
    }
    $stmt->close();
}

// Group the flat row list into one panel per distinct effective period
// (same effective_from + effective_to), instead of one long table where
// several unrelated periods for different weekdays end up interleaved -
// e.g. a doctor with a Jul-Sep period AND an Oct period was showing as
// 10+ rows in date order by weekday, not by period, which read as
// confusing/duplicated even though every row was correct.
$rsPeriods = [];
foreach ($rsSchedules as $sch) {
    $periodKey = $sch['effective_from'] . '|' . ($sch['effective_to'] ?? 'ongoing');
    if (!isset($rsPeriods[$periodKey])) {
        $rsPeriods[$periodKey] = [
            'effective_from' => $sch['effective_from'],
            'effective_to'   => $sch['effective_to'],
            'schedules'      => [],
        ];
    }
    $rsPeriods[$periodKey]['schedules'][] = $sch;
}
// Chronological, earliest period first. An open-ended (Ongoing) period
// sorts after a bounded one with the same start date - there's no real
// ambiguity in practice since two periods can't share a start date for
// the same weekday, but this keeps the order stable either way.
uasort($rsPeriods, function ($a, $b) {
    $cmp = strcmp($a['effective_from'], $b['effective_from']);
    if ($cmp !== 0) {
        return $cmp;
    }
    if ($a['effective_to'] === $b['effective_to']) {
        return 0;
    }
    if ($a['effective_to'] === null) {
        return 1;
    }
    if ($b['effective_to'] === null) {
        return -1;
    }
    return strcmp($a['effective_to'], $b['effective_to']);
});
// Within each panel, always show Monday through Sunday in order.
foreach ($rsPeriods as &$rsPeriod) {
    usort($rsPeriod['schedules'], function ($a, $b) {
        return $a['day_of_week'] <=> $b['day_of_week'];
    });
}
unset($rsPeriod);

$rsToday = date('Y-m-d');

$current_page = 'doctor-schedule';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Schedules - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-dashboard.css">
    <link rel="stylesheet" href="../assets/css/doctor-schedule.css">
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">

            <header class="page-header">
                <div>
                    <h1>Doctor Schedules</h1>
                    <p class="page-subtitle">Monthly duty schedule per doctor. Updated once a month, not week to week.</p>
                </div>
                <div class="header-actions">
                    <span class="header-date"><?php echo htmlspecialchars($today); ?></span>
                </div>
            </header>

            <!-- Schedule view tabs -->
            <div class="ds-tabs" role="tablist" aria-label="Schedule view">
                <a href="?tab=monthly&doctor_id=<?php echo $selectedDoctorId; ?>&month_offset=<?php echo $monthOffset; ?>"
                    class="ds-tab-btn <?php echo $activeTab === 'monthly' ? 'active' : ''; ?>"
                    role="tab" aria-selected="<?php echo $activeTab === 'monthly' ? 'true' : 'false'; ?>">
                    Monthly Schedule
                </a>
                <a href="?tab=history&history_doctor_id=<?php echo $historyDoctorId; ?>"
                    class="ds-tab-btn <?php echo $activeTab === 'history' ? 'active' : ''; ?>"
                    role="tab" aria-selected="<?php echo $activeTab === 'history' ? 'true' : 'false'; ?>">
                    Reschedule History
                </a>
                <a href="?tab=recurring&rs_doctor_id=<?php echo $rsDoctorId; ?>"
                    class="ds-tab-btn <?php echo $activeTab === 'recurring' ? 'active' : ''; ?>"
                    role="tab" aria-selected="<?php echo $activeTab === 'recurring' ? 'true' : 'false'; ?>">
                    Weekly Recurring Schedule
                </a>
            </div>

            <!-- ===================== Monthly Schedule tab ===================== -->
            <div class="ds-tab-panel <?php echo $activeTab === 'monthly' ? 'active' : ''; ?>" id="ds-tab-panel-monthly" role="tabpanel">

                <!-- Doctor roster selector -->
                <section class="card">
                    <div class="card-header">
                        <div>
                            <h2>Select a Doctor</h2>
                            <span class="card-subtitle">Choose whose monthly schedule to view or edit</span>
                        </div>
                    </div>
                    <div class="ds-doctor-grid">
                        <?php foreach ($doctors as $docId => $doc): ?>
                            <?php $isActiveCard = $docId === $selectedDoctorId; ?>
                            <a href="?doctor_id=<?php echo $docId; ?>&month_offset=<?php echo $monthOffset; ?>"
                                class="ds-doctor-card <?php echo $isActiveCard ? 'ds-doctor-card-active' : ''; ?>">
                                <div class="ds-doctor-avatar"><?php echo htmlspecialchars($doc['initials']); ?></div>
                                <div class="ds-doctor-info">
                                    <span class="ds-doctor-name"><?php echo htmlspecialchars($doc['name']); ?></span>
                                    <span class="ds-doctor-dept"><?php echo htmlspecialchars($doc['department']); ?></span>
                                </div>
                                <span class="status-pill ds-status-<?php echo htmlspecialchars($doc['status']); ?>">
                                    <?php echo htmlspecialchars(ucfirst($doc['status'])); ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>

                <?php if ($isDeactivated): ?>
                    <div class="ds-warning-banner">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                            <line x1="12" y1="9" x2="12" y2="13"></line>
                            <line x1="12" y1="17" x2="12.01" y2="17"></line>
                        </svg>
                        <span><?php echo htmlspecialchars($selectedDoctor['name']); ?>'s account is deactivated. They won't appear as bookable for patients until the account is reactivated in User Management.</span>
                    </div>
                <?php endif; ?>

                <!-- Monthly summary -->
                <section class="stats-grid ds-summary-grid">
                    <div class="stat-card stat-teal fade-in-card">
                        <div class="stat-icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                                <polyline points="9 15 11 17 15 13"></polyline>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <span class="stat-value"><?php echo $workingDays + $halfDays; ?></span>
                            <span class="stat-label">Working Days</span>
                        </div>
                    </div>
                    <div class="stat-card stat-amber fade-in-card">
                        <div class="stat-icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                                <line x1="9" y1="15" x2="15" y2="19"></line>
                                <line x1="15" y1="15" x2="9" y2="19"></line>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <span class="stat-value"><?php echo $offDays; ?></span>
                            <span class="stat-label">Off Days</span>
                        </div>
                    </div>
                    <div class="stat-card stat-red fade-in-card">
                        <div class="stat-icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                                <path d="M12 14v3"></path>
                                <circle cx="12" cy="19.5" r="0.6" fill="currentColor"></circle>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <span class="stat-value"><?php echo $leaveDays; ?></span>
                            <span class="stat-label">Leave Days</span>
                        </div>
                    </div>
                    <div class="stat-card stat-blue fade-in-card">
                        <div class="stat-icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <span class="stat-value"><?php echo $totalCapacity; ?></span>
                            <span class="stat-label">Total Appointment Capacity</span>
                        </div>
                    </div>
                </section>

                <!-- Month calendar -->
                <section class="card" id="dsScheduleCard"
                    data-doctor-id="<?php echo $selectedDoctorId; ?>"
                    data-doctor-name="<?php echo htmlspecialchars($selectedDoctor['name']); ?>"
                    data-doctor-department="<?php echo htmlspecialchars($selectedDoctor['department']); ?>"
                    data-today="<?php echo $todayDate; ?>"
                    data-deactivated="<?php echo $isDeactivated ? '1' : '0'; ?>">

                    <div class="card-header schedule-week-header">
                        <div>
                            <h2><?php echo htmlspecialchars($monthLabel); ?></h2>
                            <span class="card-subtitle"><?php echo htmlspecialchars($selectedDoctor['name']); ?>'s schedule for the month</span>
                        </div>
                        <div class="schedule-week-nav">
                            <select class="filter-select ds-month-select" id="dsMonthSelect" aria-label="Jump to month">
                                <?php for ($m = -3; $m <= 3; $m++): ?>
                                    <?php
                                    $optTs = strtotime(date('Y-m-01') . " {$m} months");
                                    $optLabel = date('F Y', $optTs);
                                    ?>
                                    <option value="<?php echo $m; ?>" <?php echo $m === $monthOffset ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($optLabel); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                            <a class="btn btn-secondary" href="?doctor_id=<?php echo $selectedDoctorId; ?>&month_offset=<?php echo $monthOffset - 1; ?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="15 18 9 12 15 6"></polyline>
                                </svg>
                                Previous Month
                            </a>
                            <?php if ($monthOffset !== 0): ?>
                                <a class="btn btn-secondary" href="?doctor_id=<?php echo $selectedDoctorId; ?>&month_offset=0">This Month</a>
                            <?php endif; ?>
                            <a class="btn btn-secondary" href="?doctor_id=<?php echo $selectedDoctorId; ?>&month_offset=<?php echo $monthOffset + 1; ?>">
                                Next Month
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="9 18 15 12 9 6"></polyline>
                                </svg>
                            </a>
                        </div>
                    </div>

                    <!-- Legend -->
                    <div class="ds-legend">
                        <span class="ds-legend-item"><span class="ds-legend-swatch ds-swatch-available"></span>Available</span>
                        <span class="ds-legend-item"><span class="ds-legend-swatch ds-swatch-off_duty"></span>Off Duty</span>
                        <span class="ds-legend-item"><span class="ds-legend-swatch ds-swatch-leave"></span>Leave</span>
                        <span class="ds-legend-item"><span class="ds-legend-swatch ds-swatch-half_day"></span>Half Day</span>
                    </div>

                    <!-- This calendar is read-only: it visualizes the resolved
                         schedule (Weekly Recurring Schedule + any Weekly
                         Overrides). Edit either of those two tabs to change
                         what shows up here. -->

                    <!-- Calendar grid -->
                    <div class="ds-calendar-weekdays">
                        <span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span>
                    </div>
                    <div class="ds-calendar-grid">
                        <?php for ($i = 0; $i < $leadingBlanks; $i++): ?>
                            <div class="ds-calendar-cell ds-calendar-cell-blank"></div>
                        <?php endfor; ?>

                        <?php foreach ($monthData as $dateStr => $day): ?>
                            <?php
                            $dayNum = (int) date('j', strtotime($dateStr));
                            $isToday = $dateStr === $todayDate;
                            $isWeekend = in_array((int) date('N', strtotime($dateStr)), [6, 7], true);
                            $tooltip = $statusLabels[$day['status']];
                            if ($day['start'] && $day['end']) {
                                $tooltip .= ' — ' . date('g:i A', strtotime($day['start'])) . ' to ' . date('g:i A', strtotime($day['end']));
                            }
                            if (!empty($day['notes'])) {
                                $tooltip .= ' (' . $day['notes'] . ')';
                            }
                            ?>
                            <div
                                class="ds-calendar-cell ds-cell-<?php echo $day['status']; ?> <?php echo $isToday ? 'ds-cell-today' : ''; ?> <?php echo $isWeekend ? 'ds-cell-weekend' : ''; ?>"
                                title="<?php echo htmlspecialchars($tooltip); ?>">
                                <?php if ($isToday): ?><span class="day-card-today-badge ds-cell-today-badge">Today</span><?php endif; ?>
                                <span class="ds-cell-daynum"><?php echo $dayNum; ?></span>
                                <span class="status-pill ds-status-pill-<?php echo $day['status']; ?>"><?php echo $statusLabels[$day['status']]; ?></span>
                                <?php if ($day['start'] && $day['end']): ?>
                                    <span class="ds-cell-shift"><?php echo date('g:i A', strtotime($day['start'])) . '–' . date('g:i A', strtotime($day['end'])); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <?php for ($i = 0; $i < $trailingBlanks; $i++): ?>
                            <div class="ds-calendar-cell ds-calendar-cell-blank"></div>
                        <?php endfor; ?>
                    </div>

                    <p class="ds-editor-hint">This calendar is <strong>read-only</strong>: each date shows the resolved outcome of the Weekly Recurring Schedule, adjusted by any Weekly Override for that date. Hover a date for its exact hours. To change a date, edit the Weekly Recurring Schedule (ongoing pattern) or add a Weekly Override (one-off exception).</p>
                </section>

            </div>
            <!-- =================== /Monthly Schedule tab ==================== -->

            <!-- ===================== Reschedule History tab ===================== -->
            <div class="ds-tab-panel <?php echo $activeTab === 'history' ? 'active' : ''; ?>" id="ds-tab-panel-history" role="tabpanel">

                <div class="wo-intro-banner">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    <span>Read-only. Every doctor-initiated appointment reschedule is logged here automatically when a doctor moves one of their own patients from <strong>My Schedule</strong> - admin no longer sets overrides directly; that's the doctor's own call.</span>
                </div>

                <!-- Doctor filter -->
                <section class="card">
                    <div class="card-header schedule-week-header">
                        <div>
                            <h2>Filter by Doctor</h2>
                            <span class="card-subtitle">Optional - defaults to every doctor</span>
                        </div>
                        <div class="schedule-week-nav">
                            <select class="filter-select" id="historyDoctorSelect" aria-label="Filter by doctor">
                                <option value="0" <?php echo $historyDoctorId === 0 ? 'selected' : ''; ?>>All Doctors</option>
                                <?php foreach ($doctors as $docId => $doc): ?>
                                    <option value="<?php echo $docId; ?>" <?php echo $docId === $historyDoctorId ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($doc['name']); ?> — <?php echo htmlspecialchars($doc['department']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </section>

                <section class="card">
                    <div class="card-header">
                        <div>
                            <h2><?php echo count($rescheduleHistoryRows); ?> Reschedule<?php echo count($rescheduleHistoryRows) === 1 ? '' : 's'; ?> Logged</h2>
                            <span class="card-subtitle">Read directly from appointment_reschedule_history, most recent first</span>
                        </div>
                    </div>

                    <?php if (empty($rescheduleHistoryRows)): ?>
                        <p class="schedule-day-empty" style="padding: 0 20px 20px;">No doctor-initiated reschedules have been logged yet<?php echo $historyDoctorId !== 0 ? ' for this doctor' : ''; ?>.</p>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="records-table">
                                <thead>
                                    <tr>
                                        <th>Doctor</th>
                                        <th>Patient</th>
                                        <th>Old Schedule</th>
                                        <th>New Schedule</th>
                                        <th>Emergency Reason</th>
                                        <th>Date Changed</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rescheduleHistoryRows as $r): ?>
                                        <tr>
                                            <td>Dr. <?php echo htmlspecialchars($r['doc_first'] . ' ' . $r['doc_last']); ?></td>
                                            <td><?php echo htmlspecialchars($r['pat_first'] . ' ' . $r['pat_last']); ?></td>
                                            <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($r['old_slot_start']))); ?></td>
                                            <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($r['new_slot_start']))); ?></td>
                                            <td><?php echo htmlspecialchars($r['emergency_reason']); ?></td>
                                            <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($r['changed_at']))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

            </div>
            <!-- =================== /Reschedule History tab ==================== -->

            <!-- ============= Weekly Recurring Schedule tab (REAL) ============ -->
            <div class="ds-tab-panel <?php echo $activeTab === 'recurring' ? 'active' : ''; ?>" id="ds-tab-panel-recurring" role="tabpanel">

                <div class="wo-intro-banner">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="16" x2="12" y2="12"></line>
                        <line x1="12" y1="8" x2="12.01" y2="8"></line>
                    </svg>
                    <span>This is the <strong>live schedule</strong> the Doctor Portal and patient appointment booking read from. Changes here take effect immediately. Each schedule applies to one weekday for a specific effective period, so a new month's hours can be added ahead of time without deleting the old one. Adding a day, changing several at once, or rolling the whole week over for a new cycle? Use <strong>Set Up New Period</strong> - it loads what's already active so you only touch what's actually changing. Use <strong>Edit</strong> on a row for a quick correction to just that row.</span>
                </div>

                <section class="card">
                    <div class="card-header">
                        <div>
                            <h2>Select a Doctor</h2>
                            <span class="card-subtitle">Choose whose recurring weekly schedule to manage</span>
                        </div>
                    </div>
                    <div class="ds-doctor-grid">
                        <?php foreach ($realDoctors as $docId => $doc): ?>
                            <?php $isActiveCard = $docId === $rsDoctorId; ?>
                            <a href="?tab=recurring&rs_doctor_id=<?php echo $docId; ?>"
                                class="ds-doctor-card <?php echo $isActiveCard ? 'ds-doctor-card-active' : ''; ?>">
                                <div class="ds-doctor-avatar"><?php echo htmlspecialchars(strtoupper(substr($doc['first_name'], 0, 1) . substr($doc['last_name'], 0, 1))); ?></div>
                                <div class="ds-doctor-info">
                                    <span class="ds-doctor-name">Dr. <?php echo htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']); ?></span>
                                    <span class="ds-doctor-dept"><?php echo htmlspecialchars($doc['department_name'] ?? 'No department'); ?></span>
                                </div>
                                <span class="status-pill ds-status-<?php echo htmlspecialchars($doc['status']); ?>">
                                    <?php echo htmlspecialchars(ucfirst($doc['status'])); ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                        <?php if (empty($realDoctors)): ?>
                            <p class="rs-empty-text">No doctor accounts found.</p>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="card">
                    <div class="card-header schedule-week-header rs-header-no-wrap">
                        <div class="rs-header-text">
                            <h2><?php echo $rsDoctorId ? 'Dr. ' . htmlspecialchars($realDoctors[$rsDoctorId]['first_name'] . ' ' . $realDoctors[$rsDoctorId]['last_name']) : 'Doctor'; ?>'s Recurring Schedule</h2>
                            <span class="card-subtitle">One row per weekday + effective period. Only one <em>active</em> schedule can cover a given weekday and date at a time. Use <strong>Edit</strong> below to adjust a row that already exists, or <strong>Set Up New Period</strong> to add a day, change several at once, or roll the whole week over for a new cycle.</span>
                        </div>
                        <button type="button" class="btn btn-primary" id="pbOpenBtn" <?php echo $rsDoctorId ? '' : 'disabled'; ?>>
                            Set Up New Period
                        </button>
                    </div>

                    <div id="rsScheduleTableBody">
                        <?php if (empty($rsPeriods)): ?>
                            <p class="schedule-day-empty">No recurring schedules set up yet for this doctor.</p>
                        <?php else: ?>
                            <?php foreach ($rsPeriods as $rsPeriod):
                                $rsIsCurrent = $rsPeriod['effective_from'] <= $rsToday
                                    && ($rsPeriod['effective_to'] === null || $rsPeriod['effective_to'] >= $rsToday);
                            ?>
                                <div class="rs-period-panel <?php echo $rsIsCurrent ? 'rs-period-current' : ''; ?>">
                                    <div class="rs-period-heading">
                                        <span class="rs-period-range">
                                            <?php echo htmlspecialchars(date('M j, Y', strtotime($rsPeriod['effective_from']))); ?>
                                            &ndash;
                                            <?php echo $rsPeriod['effective_to'] ? htmlspecialchars(date('M j, Y', strtotime($rsPeriod['effective_to']))) : 'Ongoing'; ?>
                                        </span>
                                        <?php if ($rsIsCurrent): ?>
                                            <span class="rs-period-badge">Current period</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="table-wrap">
                                        <table class="records-table">
                                            <thead>
                                                <tr>
                                                    <th>Day</th>
                                                    <th>Time</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($rsPeriod['schedules'] as $sch): ?>
                                                    <tr data-schedule-id="<?php echo $sch['schedule_id']; ?>">
                                                        <td><?php echo htmlspecialchars($rsDayNames[$sch['day_of_week']]); ?></td>
                                                        <td><?php echo htmlspecialchars(date('g:i A', strtotime($sch['start_time'])) . ' – ' . date('g:i A', strtotime($sch['end_time']))); ?></td>
                                                        <td>
                                                            <span class="status-pill <?php echo $sch['status'] === 'active' ? 'status-scheduled' : 'status-cancelled'; ?>">
                                                                <?php echo ucfirst($sch['status']); ?>
                                                            </span>
                                                        </td>
                                                        <td class="rs-actions-cell">
                                                            <button type="button" class="btn btn-secondary rs-btn-sm rs-edit-btn"
                                                                data-schedule='<?php echo htmlspecialchars(json_encode($sch), ENT_QUOTES); ?>'>Edit</button>
                                                            <button type="button" class="btn btn-secondary rs-btn-sm rs-toggle-btn" data-schedule-id="<?php echo $sch['schedule_id']; ?>">
                                                                <?php echo $sch['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                                                            </button>
                                                            <button type="button" class="btn rs-btn-sm rs-btn-danger rs-archive-btn" data-schedule-id="<?php echo $sch['schedule_id']; ?>">Archive</button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

            </div>
            <!-- =========== /Weekly Recurring Schedule tab (REAL) ============= -->

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <!-- Create / Edit Weekly Recurring Schedule modal -->
    <div class="modal-backdrop" id="rsScheduleModalBackdrop">
        <div class="modal-box ds-modal-box-centered" role="dialog" aria-modal="true" aria-labelledby="rsScheduleModalTitle">
            <div class="ds-modal-header">
                <h3 id="rsScheduleModalTitle">Add Schedule</h3>
                <button type="button" class="um-modal-close" id="rsScheduleModalClose" aria-label="Close">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="ds-modal-body">
                <p class="rs-modal-error" id="rsModalError"></p>

                <div class="um-field">
                    <label for="rsFieldDayOfWeek">Day of Week</label>
                    <select id="rsFieldDayOfWeek">
                        <?php foreach ($rsDayNames as $num => $name): ?>
                            <option value="<?php echo $num; ?>"><?php echo htmlspecialchars($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="um-form-row">
                    <div class="um-field">
                        <label for="rsFieldStart">Start Time</label>
                        <input type="time" id="rsFieldStart">
                    </div>
                    <div class="um-field">
                        <label for="rsFieldEnd">End Time</label>
                        <input type="time" id="rsFieldEnd">
                    </div>
                </div>

                <div class="um-form-row">
                    <div class="um-field">
                        <label for="rsFieldStatus">Status</label>
                        <select id="rsFieldStatus">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="um-form-row">
                    <div class="um-field">
                        <label for="rsFieldEffectiveFrom">Effective From</label>
                        <input type="date" id="rsFieldEffectiveFrom">
                    </div>
                    <div class="um-field">
                        <label for="rsFieldEffectiveTo">Effective To (optional)</label>
                        <input type="date" id="rsFieldEffectiveTo">
                    </div>
                </div>

                <p class="ds-editor-hint">Leave "Effective To" blank for a schedule that stays in effect until you change or end it. Only one <em>active</em> schedule can cover the same weekday and date range for a doctor.</p>

                <div class="um-modal-actions">
                    <button type="button" class="btn btn-secondary" id="rsScheduleModalCancel">Cancel</button>
                    <button type="button" class="btn btn-primary" id="rsScheduleModalSave">Save</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Set Up OPD Schedule for a New Period modal - bulk version of the
         modal above. Loads whatever OPD pattern is currently active as
         of the period's start date, lets admin adjust any/all of the 7
         weekdays, and publishes all of them in one transaction on
         Save - see publish_opd_schedule in doctor-schedule-actions.php. -->
    <div class="modal-backdrop" id="pbModalBackdrop">
        <div class="modal-box ds-modal-box-centered ds-modal-box-wide" role="dialog" aria-modal="true" aria-labelledby="pbModalTitle">
            <div class="ds-modal-header">
                <h3 id="pbModalTitle">Set Up OPD Schedule for a New Period</h3>
                <button type="button" class="um-modal-close" id="pbModalClose" aria-label="Close">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="ds-modal-body">
                <p class="rs-modal-error" id="pbModalError"></p>

                <p class="ds-editor-hint">Covers only OPD (patient-booking) hours. "Period Start" defaults to the day right after this doctor's current schedule ends, and "Period End" defaults to the last day of that month - so publishing creates one clean, separate calendar-month period by default. Clear "Period End" for an open-ended schedule instead, or change either date for a different cycle (e.g. semi-monthly). The grid below loads whatever's already active as of the start date. Doctor-owned leave/half-day overrides and the reschedule history log are never affected by this.</p>

                <div class="um-form-row">
                    <div class="um-field">
                        <label for="pbFieldFrom">Period Start</label>
                        <input type="date" id="pbFieldFrom">
                    </div>
                    <div class="um-field">
                        <label for="pbFieldTo">Period End (optional)</label>
                        <input type="date" id="pbFieldTo">
                    </div>
                </div>
                <p class="ds-editor-hint">Leave "Period End" blank for a pattern that continues until the next period is published. Set it for a hospital cycle with a hard stop (e.g. a semi-monthly 1st–15th roster).</p>

                <div class="table-wrap">
                    <table class="records-table pb-days-table">
                        <thead>
                            <tr>
                                <th>Day</th>
                                <th>On Duty</th>
                                <th>Start</th>
                                <th>End</th>
                            </tr>
                        </thead>
                        <tbody id="pbDaysTableBody">
                            <?php foreach ($rsDayNames as $num => $name): ?>
                                <tr class="pb-day-row" data-day-of-week="<?php echo $num; ?>">
                                    <td><?php echo htmlspecialchars($name); ?></td>
                                    <td><input type="checkbox" class="pb-day-enabled" aria-label="<?php echo htmlspecialchars($name); ?> on duty"></td>
                                    <td><input type="time" class="pb-day-start" value="08:00"></td>
                                    <td><input type="time" class="pb-day-end" value="17:00"></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="um-modal-actions">
                    <button type="button" class="btn btn-secondary" id="pbModalCancel">Cancel</button>
                    <button type="button" class="btn btn-primary" id="pbScheduleModalSave">Publish Schedule</button>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/js/doctor-dashboard.js"></script>
    <script>
        // Reschedule History tab's doctor filter - plain GET navigation,
        // no fetch()/modal needed since this tab is read-only.
        (function() {
            const select = document.getElementById('historyDoctorSelect');
            if (!select) return;
            select.addEventListener('change', function() {
                window.location.href = '?tab=history&history_doctor_id=' + encodeURIComponent(select.value);
            });
        })();
    </script>
    <script>
        // Real data for the Weekly Recurring Schedule tab - unlike the
        // two blocks above, this drives actual fetch() calls to
        // admin/doctor-schedule-actions.php rather than being edited
        // in-memory only. See assets/js/doctor-schedule-weekly.js.
        window.rsDoctorId = <?php echo json_encode($rsDoctorId); ?>;
        window.rsCsrfToken = <?php echo json_encode(csrf_token()); ?>;
    </script>
    <script src="../assets/js/doctor-schedule-weekly.js"></script>
    <script src="../assets/js/doctor-schedule-period-builder.js"></script>
</body>

</html>