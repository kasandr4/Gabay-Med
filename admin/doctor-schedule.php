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

$today = date("F j, Y");
$todayDate = date('Y-m-d');

// ---- Doctor roster (placeholder) -------------------------------------
// Same four sample doctor accounts used in admin/includes/user-management-data.php
// (ids 2001-2004), so names/departments/status line up with what an admin
// already sees on the User Management page.
$doctors = [
    2001 => ['name' => 'Dr. Ramon Santos',     'department' => 'Internal Medicine', 'status' => 'active',      'initials' => 'RS', 'pattern' => [1, 2, 3, 5], 'room' => 'Room 204'],
    2002 => ['name' => 'Dr. Liza Fernandez',   'department' => 'Surgery',           'status' => 'active',      'initials' => 'LF', 'pattern' => [1, 3, 4, 6], 'room' => 'Room 118'],
    2003 => ['name' => 'Dr. Carlo Villanueva', 'department' => 'Pediatrics',        'status' => 'pending',     'initials' => 'CV', 'pattern' => [2, 3, 4, 5], 'room' => 'Room 305'],
    2004 => ['name' => 'Dr. Grace Ibarra',     'department' => 'Emergency',         'status' => 'deactivated', 'initials' => 'GI', 'pattern' => [1, 2],       'room' => 'Room 101'],
];

$departmentOptions = ['Internal Medicine', 'Surgery', 'Pediatrics', 'Emergency', 'OB-GYN', 'Cardiology'];
$roomOptions = ['Room 101', 'Room 118', 'Room 204', 'Room 210', 'Room 305', 'Room 312'];

// ---- Selected doctor ---------------------------------------------------
$selectedDoctorId = isset($_GET['doctor_id']) ? (int) $_GET['doctor_id'] : array_key_first($doctors);
if (!isset($doctors[$selectedDoctorId])) {
    $selectedDoctorId = array_key_first($doctors);
}
$selectedDoctor = $doctors[$selectedDoctorId];
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

/**
 * Deterministic mock-data generator for one calendar day. Uses the
 * doctor's weekday duty pattern as a base, then sprinkles in a handful of
 * Leave / Half Day overrides using simple date arithmetic (not real
 * randomness), so the same doctor + month always renders the same mock
 * schedule instead of shuffling on every page load.
 */
function generateMockDay($doctorId, $doctor, $dateStr, $dayOfMonth, $isoDow, $departmentOptions, $roomOptions)
{
    $baseAvailable = in_array($isoDow, $doctor['pattern'], true);
    $status = $baseAvailable ? 'available' : 'off_duty';

    // Sprinkle a handful of overrides so the month doesn't look identical
    // every single week - purely cosmetic mock variety.
    $seed = ($doctorId + $dayOfMonth) % 17;
    if ($baseAvailable && $seed === 0) {
        $status = 'leave';
    } elseif ($baseAvailable && $seed === 5) {
        $status = 'half_day';
    }

    $start = null;
    $end = null;
    $maxPatients = 0;
    if ($status === 'available') {
        $start = '08:00';
        $end = '17:00';
        $maxPatients = 20;
    } elseif ($status === 'half_day') {
        $start = '08:00';
        $end = '12:00';
        $maxPatients = 10;
    }

    $notes = '';
    if ($status === 'leave') {
        $notes = 'On approved leave.';
    }

    return [
        'date'        => $dateStr,
        'status'      => $status,
        'start'       => $start,
        'end'         => $end,
        'department'  => $doctor['department'],
        'room'        => $baseAvailable || $status === 'half_day' ? $doctor['room'] : '',
        'maxPatients' => $maxPatients,
        'notes'       => $notes,
    ];
}

$statusLabels = [
    'available' => 'Available',
    'off_duty'  => 'Off Duty',
    'leave'     => 'Leave',
    'half_day'  => 'Half Day',
];

$monthData = [];
$workingDays = 0;
$offDays = 0;
$leaveDays = 0;
$halfDays = 0;
$totalCapacity = 0;

for ($d = 1; $d <= $daysInMonth; $d++) {
    $dateStr = sprintf('%04d-%02d-%02d', $year, $monthNum, $d);
    $isoDow = (int) date('N', strtotime($dateStr));
    $day = generateMockDay($selectedDoctorId, $selectedDoctor, $dateStr, $d, $isoDow, $departmentOptions, $roomOptions);
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
// Weekly Overrides tab (UI ONLY, mock data)
// -------------------------------------------------------------------------
// Temporary, day-specific adjustments (emergency leave, half day, training,
// meetings, unavailability, or a one-off schedule change) layered on top of
// the Monthly Schedule above without touching it. Everything below is
// static/mock and manipulated client-side only in doctor-schedule-overrides.js
// — same no-backend pattern as the monthly calendar.
//
// TODO(backend): this would live in its own `schedule_overrides` table
// (doctor_id, override_date, status, start, end, reason, notes, created_by,
// created_at), read by both the admin write-view here and by whatever reads
// the doctor's effective schedule for a given date (monthly row unless an
// override exists for that date). The "Save" action in the modal below
// should become an UPSERT keyed on (doctor_id, override_date); "Reset"
// should DELETE that row.

$activeTab = (isset($_GET['tab']) && $_GET['tab'] === 'weekly') ? 'weekly' : 'monthly';

$overrideStatusOptions = [
    'no_change'       => 'No Change',
    'available'       => 'Available',
    'half_day'        => 'Half Day',
    'emergency_leave' => 'Emergency Leave',
    'training'        => 'Training',
    'meeting'         => 'Meeting',
    'unavailable'     => 'Unavailable',
];

// ---- Selected doctor + week for the Weekly Overrides tab (independent
// selectors from the Monthly Schedule tab above) -------------------------
$woDoctorId = isset($_GET['wo_doctor_id']) ? (int) $_GET['wo_doctor_id'] : $selectedDoctorId;
if (!isset($doctors[$woDoctorId])) {
    $woDoctorId = array_key_first($doctors);
}
$woDoctor = $doctors[$woDoctorId];
$woIsDeactivated = $woDoctor['status'] === 'deactivated';

$woWeekOffset = isset($_GET['wo_week_offset']) ? (int) $_GET['wo_week_offset'] : 0;
$todayIsoDow = (int) date('N'); // 1 = Mon ... 7 = Sun
$currentWeekMondayTs = strtotime(date('Y-m-d') . ' -' . ($todayIsoDow - 1) . ' days');
$woWeekStartTs = strtotime("{$woWeekOffset} weeks", $currentWeekMondayTs);

/**
 * Deterministic mock-data generator for one week of overrides for a doctor.
 * Original Schedule is derived the same way the monthly calendar derives
 * on-duty status (the doctor's weekday pattern); the override itself
 * rotates through the status list so the demo table shows every status at
 * least once, shifted per doctor/week so it isn't identical every time.
 */
function generateMockOverrideWeek($doctorId, $doctor, $weekStartTs, $weekOffset)
{
    $dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $rotation = ['no_change', 'half_day', 'no_change', 'emergency_leave', 'training', 'meeting', 'unavailable'];
    $shift = ($doctorId + $weekOffset) % count($rotation);

    $overrideDetails = [
        'half_day'        => ['start' => '08:00', 'end' => '12:00', 'reason' => 'Personal errand'],
        'emergency_leave'  => ['start' => null, 'end' => null, 'reason' => 'Family emergency'],
        'training'        => ['start' => '09:00', 'end' => '15:00', 'reason' => 'Mandatory CME training'],
        'meeting'         => ['start' => '13:00', 'end' => '14:30', 'reason' => 'Department head meeting'],
        'unavailable'     => ['start' => null, 'end' => null, 'reason' => 'Conference travel'],
        'available'       => ['start' => '08:00', 'end' => '17:00', 'reason' => 'Covering an extra shift'],
        'no_change'       => ['start' => null, 'end' => null, 'reason' => ''],
    ];

    $week = [];
    for ($i = 0; $i < 7; $i++) {
        $dateStr = date('Y-m-d', strtotime("+{$i} day", $weekStartTs));
        $isoDow = $i + 1;
        $onDutyThisDay = in_array($isoDow, $doctor['pattern'], true);
        $originalLabel = $onDutyThisDay ? '8:00 AM – 5:00 PM' : 'Off Duty (Monthly)';

        $status = $rotation[($shift + $i) % count($rotation)];
        $details = $overrideDetails[$status];

        $week[$dateStr] = [
            'date'           => $dateStr,
            'dayName'        => $dayNames[$i],
            'originalLabel'  => $originalLabel,
            'onDutyOriginal' => $onDutyThisDay,
            'status'         => $status,
            'start'          => $details['start'],
            'end'            => $details['end'],
            'reason'         => $details['reason'],
            'notes'          => '',
        ];
    }
    return $week;
}

$woWeekData = generateMockOverrideWeek($woDoctorId, $woDoctor, $woWeekStartTs, $woWeekOffset);

$woWeekLabel = date('M j', $woWeekStartTs) . ' – ' . date('M j, Y', strtotime('+6 days', $woWeekStartTs));
$woIsCurrentWeek = $woWeekOffset === 0;

$woOverrideDays = 0;
$woLeaveDays = 0;
$woHalfDays = 0;
$woTrainingDays = 0;
foreach ($woWeekData as $woDay) {
    if ($woDay['status'] !== 'no_change') {
        $woOverrideDays++;
    }
    if ($woDay['status'] === 'emergency_leave') {
        $woLeaveDays++;
    }
    if ($woDay['status'] === 'half_day') {
        $woHalfDays++;
    }
    if ($woDay['status'] === 'training') {
        $woTrainingDays++;
    }
}

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
                <a href="?tab=weekly&wo_doctor_id=<?php echo $woDoctorId; ?>&wo_week_offset=<?php echo $woWeekOffset; ?>"
                    class="ds-tab-btn <?php echo $activeTab === 'weekly' ? 'active' : ''; ?>"
                    role="tab" aria-selected="<?php echo $activeTab === 'weekly' ? 'true' : 'false'; ?>">
                    Weekly Overrides
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
                        <span><?php echo htmlspecialchars($selectedDoctor['name']); ?>'s account is deactivated. This calendar is read-only until the account is reactivated in User Management.</span>
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
                    data-doctor-room="<?php echo htmlspecialchars($selectedDoctor['room']); ?>"
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

                    <!-- Quick actions -->
                    <div class="ds-quick-actions">
                        <button type="button" class="btn btn-secondary ds-quick-btn" id="dsCopyTodayBtn" <?php echo $isDeactivated ? 'disabled' : ''; ?>>
                            Copy Today's Schedule
                        </button>
                        <button type="button" class="btn btn-secondary ds-quick-btn" id="dsCopyWeekBtn" <?php echo $isDeactivated ? 'disabled' : ''; ?>>
                            Copy This Week
                        </button>
                        <button type="button" class="btn btn-secondary ds-quick-btn" id="dsCopyPrevMonthBtn" <?php echo $isDeactivated ? 'disabled' : ''; ?>>
                            Copy Previous Month
                        </button>
                    </div>

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
                            ?>
                            <button type="button"
                                class="ds-calendar-cell ds-cell-<?php echo $day['status']; ?> <?php echo $isToday ? 'ds-cell-today' : ''; ?> <?php echo $isWeekend ? 'ds-cell-weekend' : ''; ?>"
                                data-date="<?php echo $dateStr; ?>"
                                <?php echo $isDeactivated ? 'disabled' : ''; ?>>
                                <?php if ($isToday): ?><span class="day-card-today-badge ds-cell-today-badge">Today</span><?php endif; ?>
                                <span class="ds-cell-daynum"><?php echo $dayNum; ?></span>
                                <span class="status-pill ds-status-pill-<?php echo $day['status']; ?>"><?php echo $statusLabels[$day['status']]; ?></span>
                                <?php if ($day['start'] && $day['end']): ?>
                                    <span class="ds-cell-shift"><?php echo date('g:i A', strtotime($day['start'])) . '–' . date('g:i A', strtotime($day['end'])); ?></span>
                                <?php endif; ?>
                            </button>
                        <?php endforeach; ?>

                        <?php for ($i = 0; $i < $trailingBlanks; $i++): ?>
                            <div class="ds-calendar-cell ds-calendar-cell-blank"></div>
                        <?php endfor; ?>
                    </div>

                    <p class="ds-editor-hint">Click a day to view or edit that day's shift. This calendar reflects a monthly schedule — set once, then adjusted for individual leaves or half days as needed.</p>
                </section>

            </div>
            <!-- =================== /Monthly Schedule tab ==================== -->

            <!-- ===================== Weekly Overrides tab ===================== -->
            <div class="ds-tab-panel <?php echo $activeTab === 'weekly' ? 'active' : ''; ?>" id="ds-tab-panel-weekly" role="tabpanel">

                <div class="wo-intro-banner">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    <span>Weekly Overrides are <strong>temporary</strong> adjustments for specific dates — emergency leave, half days, training, meetings, or a one-off schedule change. They sit on top of the Monthly Schedule for that date only and never modify the original monthly schedule.</span>
                </div>

                <!-- Doctor + week selectors -->
                <section class="card">
                    <div class="card-header schedule-week-header">
                        <div>
                            <h2>Select a Doctor &amp; Week</h2>
                            <span class="card-subtitle">Choose whose schedule to temporarily override</span>
                        </div>
                        <div class="schedule-week-nav">
                            <select class="filter-select" id="woDoctorSelect" aria-label="Select doctor" data-week-offset="<?php echo $woWeekOffset; ?>">
                                <?php foreach ($doctors as $docId => $doc): ?>
                                    <option value="<?php echo $docId; ?>" <?php echo $docId === $woDoctorId ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($doc['name']); ?> — <?php echo htmlspecialchars($doc['department']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <a class="btn btn-secondary" href="?tab=weekly&wo_doctor_id=<?php echo $woDoctorId; ?>&wo_week_offset=<?php echo $woWeekOffset - 1; ?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="15 18 9 12 15 6"></polyline>
                                </svg>
                                Previous Week
                            </a>
                            <?php if (!$woIsCurrentWeek): ?>
                                <a class="btn btn-secondary" href="?tab=weekly&wo_doctor_id=<?php echo $woDoctorId; ?>&wo_week_offset=0">Current Week</a>
                            <?php endif; ?>
                            <a class="btn btn-secondary" href="?tab=weekly&wo_doctor_id=<?php echo $woDoctorId; ?>&wo_week_offset=<?php echo $woWeekOffset + 1; ?>">
                                Next Week
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="9 18 15 12 9 6"></polyline>
                                </svg>
                            </a>
                        </div>
                    </div>
                </section>

                <?php if ($woIsDeactivated): ?>
                    <div class="ds-warning-banner">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                            <line x1="12" y1="9" x2="12" y2="13"></line>
                            <line x1="12" y1="17" x2="12.01" y2="17"></line>
                        </svg>
                        <span><?php echo htmlspecialchars($woDoctor['name']); ?>'s account is deactivated. Overrides are read-only until the account is reactivated in User Management.</span>
                    </div>
                <?php endif; ?>

                <!-- Weekly override summary -->
                <section class="stats-grid ds-summary-grid" id="woSummaryGrid">
                    <div class="stat-card stat-teal fade-in-card">
                        <div class="stat-icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M4 4v16h16"></path>
                                <path d="M4 15l4-4 4 3 8-9"></path>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <span class="stat-value" data-summary="overrideDays"><?php echo $woOverrideDays; ?></span>
                            <span class="stat-label">Override Days</span>
                        </div>
                    </div>
                    <div class="stat-card stat-red fade-in-card">
                        <div class="stat-icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 9v4"></path>
                                <circle cx="12" cy="16.5" r="0.6" fill="currentColor"></circle>
                                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <span class="stat-value" data-summary="leaveDays"><?php echo $woLeaveDays; ?></span>
                            <span class="stat-label">Leave Days</span>
                        </div>
                    </div>
                    <div class="stat-card stat-amber fade-in-card">
                        <div class="stat-icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <path d="M12 12V6"></path>
                                <path d="M12 12l4 2"></path>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <span class="stat-value" data-summary="halfDays"><?php echo $woHalfDays; ?></span>
                            <span class="stat-label">Half Days</span>
                        </div>
                    </div>
                    <div class="stat-card stat-blue fade-in-card">
                        <div class="stat-icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 10v6M2 10l10-5 10 5-10 5-10-5z"></path>
                                <path d="M6 12v5c0 1.5 2.5 3 6 3s6-1.5 6-3v-5"></path>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <span class="stat-value" data-summary="trainingDays"><?php echo $woTrainingDays; ?></span>
                            <span class="stat-label">Training Days</span>
                        </div>
                    </div>
                </section>

                <!-- Legend -->
                <div class="ds-legend wo-legend">
                    <span class="ds-legend-item"><span class="wo-legend-dot">🟢</span>Available</span>
                    <span class="ds-legend-item"><span class="wo-legend-dot">🟡</span>Half Day</span>
                    <span class="ds-legend-item"><span class="wo-legend-dot">🔴</span>Emergency Leave</span>
                    <span class="ds-legend-item"><span class="wo-legend-dot">🔵</span>Training</span>
                    <span class="ds-legend-item"><span class="wo-legend-dot">🟣</span>Meeting</span>
                    <span class="ds-legend-item"><span class="wo-legend-dot">⚫</span>Unavailable</span>
                </div>

                <!-- Weekly override table -->
                <section class="card" id="woOverridesCard"
                    data-doctor-id="<?php echo $woDoctorId; ?>"
                    data-doctor-name="<?php echo htmlspecialchars($woDoctor['name']); ?>"
                    data-week-start="<?php echo date('Y-m-d', $woWeekStartTs); ?>"
                    data-week-offset="<?php echo $woWeekOffset; ?>"
                    data-deactivated="<?php echo $woIsDeactivated ? '1' : '0'; ?>">

                    <div class="card-header">
                        <div>
                            <h2><?php echo htmlspecialchars($woWeekLabel); ?></h2>
                            <span class="card-subtitle"><?php echo htmlspecialchars($woDoctor['name']); ?>'s temporary overrides for the week</span>
                        </div>
                        <button type="button" class="btn btn-primary" id="woCreateOverrideBtn" <?php echo $woIsDeactivated ? 'disabled' : ''; ?>>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="5" x2="12" y2="19"></line>
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                            </svg>
                            Create Override
                        </button>
                    </div>

                    <div class="wo-table-wrap">
                        <table class="wo-table">
                            <thead>
                                <tr>
                                    <th>Day</th>
                                    <th>Date</th>
                                    <th>Original Schedule</th>
                                    <th>Override Start</th>
                                    <th>Override End</th>
                                    <th>Status</th>
                                    <th>Reason</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="woTableBody">
                                <?php foreach ($woWeekData as $dateStr => $woDay): ?>
                                    <?php $isTodayRow = $dateStr === $todayDate; ?>
                                    <tr data-date="<?php echo $dateStr; ?>" class="wo-row-<?php echo $woDay['status']; ?> <?php echo $isTodayRow ? 'wo-row-today' : ''; ?>">
                                        <td><?php echo $woDay['dayName']; ?><?php if ($isTodayRow): ?><span class="status-pill wo-today-badge">Today</span><?php endif; ?></td>
                                        <td><?php echo date('M j, Y', strtotime($dateStr)); ?></td>
                                        <td class="wo-original-cell"><?php echo htmlspecialchars($woDay['originalLabel']); ?></td>
                                        <td class="wo-start-cell"><?php echo $woDay['start'] ? date('g:i A', strtotime($woDay['start'])) : '—'; ?></td>
                                        <td class="wo-end-cell"><?php echo $woDay['end'] ? date('g:i A', strtotime($woDay['end'])) : '—'; ?></td>
                                        <td><span class="status-pill wo-status-pill-<?php echo $woDay['status']; ?>"><?php echo $overrideStatusOptions[$woDay['status']]; ?></span></td>
                                        <td class="wo-reason-cell"><?php echo $woDay['reason'] ? htmlspecialchars($woDay['reason']) : '—'; ?></td>
                                        <td class="wo-action-cell">
                                            <button type="button" class="btn btn-secondary wo-row-edit-btn" data-date="<?php echo $dateStr; ?>" <?php echo $woIsDeactivated ? 'disabled' : ''; ?>>
                                                <?php echo $woDay['status'] === 'no_change' ? 'Add Override' : 'Edit'; ?>
                                            </button>
                                            <button type="button" class="btn btn-secondary wo-row-reset-btn" data-date="<?php echo $dateStr; ?>" <?php echo ($woIsDeactivated || $woDay['status'] === 'no_change') ? 'disabled' : ''; ?>>
                                                Reset
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <p class="ds-editor-hint">Overrides only affect the specific date shown — the Monthly Schedule underneath is left untouched. Use "Reset" to remove an override and fall back to the monthly schedule for that day.</p>
                </section>

            </div>
            <!-- =================== /Weekly Overrides tab ==================== -->

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <!-- Day detail panel (side panel on desktop, sheet on mobile) -->
    <div class="modal-backdrop ds-modal-backdrop" id="dsDayModalBackdrop">
        <div class="modal-box ds-modal-box" role="dialog" aria-modal="true" aria-labelledby="dsDayModalTitle">
            <div class="ds-modal-header">
                <h3 id="dsDayModalTitle">Edit Day</h3>
                <button type="button" class="um-modal-close" id="dsDayModalClose" aria-label="Close">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="ds-modal-body">
                <div class="um-field">
                    <label for="dsFieldDate">Date</label>
                    <input type="text" id="dsFieldDate" readonly>
                </div>

                <div class="um-form-row">
                    <div class="um-field">
                        <label for="dsFieldStatus">Status</label>
                        <select id="dsFieldStatus">
                            <option value="available">Available</option>
                            <option value="off_duty">Off Duty</option>
                            <option value="leave">Leave</option>
                            <option value="half_day">Half Day</option>
                        </select>
                    </div>
                    <div class="um-field">
                        <label for="dsFieldDepartment">Department</label>
                        <select id="dsFieldDepartment">
                            <?php foreach ($departmentOptions as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="um-form-row">
                    <div class="um-field">
                        <label for="dsFieldStart">Start Time</label>
                        <input type="time" id="dsFieldStart">
                    </div>
                    <div class="um-field">
                        <label for="dsFieldEnd">End Time</label>
                        <input type="time" id="dsFieldEnd">
                    </div>
                </div>

                <div class="um-form-row">
                    <div class="um-field">
                        <label for="dsFieldRoom">Room</label>
                        <select id="dsFieldRoom">
                            <?php foreach ($roomOptions as $room): ?>
                                <option value="<?php echo htmlspecialchars($room); ?>"><?php echo htmlspecialchars($room); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="um-field">
                        <label for="dsFieldMaxPatients">Maximum Patients</label>
                        <input type="number" id="dsFieldMaxPatients" min="0" max="60" step="1">
                    </div>
                </div>

                <div class="um-field">
                    <label for="dsFieldNotes">Notes</label>
                    <textarea id="dsFieldNotes" rows="3" placeholder="Optional notes for this day..."></textarea>
                </div>

                <div class="um-modal-actions">
                    <button type="button" class="btn btn-secondary" id="dsDayModalCancel">Cancel</button>
                    <button type="button" class="btn btn-primary" id="dsDayModalSave">Save</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Create / Edit Override modal -->
    <div class="modal-backdrop" id="woOverrideModalBackdrop">
        <div class="modal-box ds-modal-box-centered" role="dialog" aria-modal="true" aria-labelledby="woOverrideModalTitle">
            <div class="ds-modal-header">
                <h3 id="woOverrideModalTitle">Create Override</h3>
                <button type="button" class="um-modal-close" id="woOverrideModalClose" aria-label="Close">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="ds-modal-body">
                <div class="um-form-row">
                    <div class="um-field">
                        <label for="woFieldDoctor">Doctor</label>
                        <select id="woFieldDoctor">
                            <?php foreach ($doctors as $docId => $doc): ?>
                                <option value="<?php echo $docId; ?>"><?php echo htmlspecialchars($doc['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="um-field">
                        <label for="woFieldDate">Date</label>
                        <input type="date" id="woFieldDate">
                    </div>
                </div>

                <div class="um-form-row">
                    <div class="um-field">
                        <label for="woFieldStart">Override Start Time</label>
                        <input type="time" id="woFieldStart">
                    </div>
                    <div class="um-field">
                        <label for="woFieldEnd">Override End Time</label>
                        <input type="time" id="woFieldEnd">
                    </div>
                </div>

                <div class="um-field">
                    <label for="woFieldStatus">Status</label>
                    <select id="woFieldStatus">
                        <?php foreach ($overrideStatusOptions as $val => $label): ?>
                            <option value="<?php echo $val; ?>"><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="um-field">
                    <label for="woFieldReason">Reason</label>
                    <input type="text" id="woFieldReason" placeholder="e.g., Family emergency, CME training...">
                </div>

                <div class="um-field">
                    <label for="woFieldNotes">Notes</label>
                    <textarea id="woFieldNotes" rows="3" placeholder="Optional additional detail..."></textarea>
                </div>

                <p class="ds-editor-hint wo-modal-hint">This override applies to the selected date only and does not change the doctor's Monthly Schedule.</p>

                <div class="um-modal-actions">
                    <button type="button" class="btn btn-secondary" id="woOverrideModalCancel">Cancel</button>
                    <button type="button" class="btn btn-primary" id="woOverrideModalSave">Save</button>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/js/doctor-dashboard.js"></script>
    <script>
        // Full month of mock schedule data for the selected doctor, so the
        // day panel and quick-copy actions can read/update it without a
        // round trip. See generateMockDay() in doctor-schedule.php for how
        // each entry was produced.
        window.dsMonthData = <?php echo json_encode($monthData); ?>;
        window.dsStatusLabels = <?php echo json_encode($statusLabels); ?>;
    </script>
    <script src="../assets/js/doctor-schedule.js"></script>
    <script>
        // One week of mock override data for the selected doctor, plus the
        // status label map and a light-weight doctor lookup, so the Weekly
        // Overrides table and modal can read/update in-memory only. See
        // generateMockOverrideWeek() in doctor-schedule.php.
        window.woWeekData = <?php echo json_encode($woWeekData); ?>;
        window.woStatusLabels = <?php echo json_encode($overrideStatusOptions); ?>;
        window.woDoctors = <?php echo json_encode(array_map(function ($doc) {
                                return ['name' => $doc['name'], 'department' => $doc['department']];
                            }, $doctors)); ?>;
    </script>
    <script src="../assets/js/doctor-schedule-overrides.js"></script>
</body>

</html>