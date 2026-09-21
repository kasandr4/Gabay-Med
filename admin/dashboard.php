<?php
// admin/dashboard.php
// Module 1 — Admin Dashboard.
//
// REAL BACKEND (2026-07-22): stats and Recent Activities now pulled from
// $conn, same pattern as doctor/dashboard.php.
//
require_once '../includes/auth_guard.php';
require_once '../config/db.php'; // provides $conn (mysqli connection)
require_role('admin');

$adminFirstName = $_SESSION['first_name'] ?? 'Admin';
$today = date("F j, Y");

$totalPatients = $conn->query(
    "SELECT COUNT(*) c FROM users WHERE role = 'patient' AND archived_at IS NULL"
)->fetch_assoc()['c'];

$activeDoctors = $conn->query(
    "SELECT COUNT(*) c FROM users WHERE role = 'doctor' AND is_active = 1 AND archived_at IS NULL"
)->fetch_assoc()['c'];

$activeStaff = $conn->query(
    "SELECT COUNT(*) c FROM users
     WHERE role IN ('admin','pharmacist') AND is_active = 1 AND archived_at IS NULL"
)->fetch_assoc()['c'];

$confinedPatients = $conn->query(
    "SELECT COUNT(*) c FROM confinements WHERE discharge_status IS NULL"
)->fetch_assoc()['c'];

$stockBatchesToday = $conn->query(
    "SELECT COUNT(*) c FROM medicine_batches
    WHERE created_by IS NOT NULL AND DATE(received_at) = CURDATE()"
)->fetch_assoc()['c'];

$lowStockMedicines = $conn->query(
    "SELECT COUNT(*) c FROM inventory_medicines WHERE current_stock <= minimum_stock"
)->fetch_assoc()['c'];

// ===================== SCHEDULE COVERAGE GAPS =====================
// Option 1 from the advance-booking discussion (2026-09-20): rather than
// let a patient reserve a date with no real doctor/slot behind it (a
// bigger feature, deliberately not built - see that conversation), just
// surface it here BEFORE a patient ever hits "no doctors available" for
// a month nobody's published a schedule for yet. Real gap this actually
// catches: doctor_weekly_schedules rows are republished periodically
// with an end date (effective_to) rather than left open-ended, so a
// doctor whose latest batch just hasn't been renewed yet silently goes
// fully off-duty the day after it lapses.
//
// Deliberately coarse, not per-weekday: a doctor with even ONE
// open-ended (effective_to IS NULL) active row is treated as fully
// covered and never flagged, even if their OTHER weekdays do have an
// end date. Precise per-weekday gap tracking would catch more, but this
// is meant to be a simple, skimmable nudge, not a full audit - and a
// doctor who's already covered indefinitely on at least one day is a
// much lower priority to chase than one covered on NO days past a
// specific date.
$scheduleGapLookaheadDays = 30;
$scheduleGaps = [];
$gapResult = $conn->query(
    "SELECT u.user_id, u.first_name, u.last_name, d.department_name,
            COUNT(dws.schedule_id) AS row_count,
            SUM(CASE WHEN dws.schedule_id IS NOT NULL AND dws.effective_to IS NULL THEN 1 ELSE 0 END) AS open_ended_count,
            MAX(dws.effective_to) AS latest_covered_date
     FROM users u
     JOIN departments d ON d.department_id = u.department_id
     LEFT JOIN doctor_weekly_schedules dws ON dws.doctor_id = u.user_id AND dws.status = 'active'
     WHERE u.role = 'doctor' AND u.is_active = 1
     GROUP BY u.user_id"
);
if ($gapResult) {
    while ($row = $gapResult->fetch_assoc()) {
        if ((int) $row['open_ended_count'] > 0) {
            continue; // covered indefinitely on at least one day - not flagged
        }

        $doctorName = 'Dr. ' . $row['first_name'] . ' ' . $row['last_name'];

        if ((int) $row['row_count'] === 0) {
            // No schedule at all is always the most urgent case - sorts
            // first regardless of what else is flagged.
            $scheduleGaps[] = [
                'sort_key' => -99999,
                'doctor' => $doctorName,
                'department' => $row['department_name'],
                'message' => 'No schedule published at all',
            ];
            continue;
        }

        $daysUntilLapse = (int) round((strtotime($row['latest_covered_date']) - strtotime(date('Y-m-d'))) / 86400);
        if ($daysUntilLapse < 0) {
            $scheduleGaps[] = [
                'sort_key' => $daysUntilLapse,
                'doctor' => $doctorName,
                'department' => $row['department_name'],
                'message' => 'Schedule ended ' . date('M j, Y', strtotime($row['latest_covered_date'])),
            ];
        } elseif ($daysUntilLapse <= $scheduleGapLookaheadDays) {
            $scheduleGaps[] = [
                'sort_key' => $daysUntilLapse,
                'doctor' => $doctorName,
                'department' => $row['department_name'],
                'message' => 'Schedule ends ' . date('M j, Y', strtotime($row['latest_covered_date']))
                    . ' (' . $daysUntilLapse . ' day' . ($daysUntilLapse === 1 ? '' : 's') . ')',
            ];
        }
    }
}
// Soonest-lapsing (or already-lapsed, or no schedule at all) first, so
// the most urgent gap is what admin sees first.
usort($scheduleGaps, fn($a, $b) => $a['sort_key'] <=> $b['sort_key']);

$stats = [
    ["label" => "Total Patients",            "value" => (int) $totalPatients,          "icon" => "users",   "accent" => "teal"],
    ["label" => "Active Doctors",            "value" => (int) $activeDoctors,          "icon" => "user-md", "accent" => "blue"],
    ["label" => "Active Staff",              "value" => (int) $activeStaff,            "icon" => "user",    "accent" => "purple"],
    ["label" => "Confined Patients",         "value" => (int) $confinedPatients,       "icon" => "bed",     "accent" => "green"],
    ["label" => "Stock Batches Today",       "value" => (int) $stockBatchesToday,       "icon" => "box",     "accent" => "amber"],
    ["label" => "Low Stock Medicines",       "value" => (int) $lowStockMedicines,      "icon" => "alert",   "accent" => "red"],
];

// ===================== RECENT ACTIVITIES =====================
// UNION across the real events available: new hospital-staff accounts,
// manually recorded stock batches, and sign-in/sign-out events from
// audit_log (module = 'auth', written by login.php / logout.php). Most
// recent first, capped at 8.
$activityResult = $conn->query(
    "(SELECT 'user_added' AS type,
             CONCAT(u.first_name, ' ', u.last_name) AS subject,
             u.role AS extra,
             u.created_at AS event_time
      FROM users u
      WHERE u.role IN ('doctor','staff','pharmacist','admin')
      ORDER BY u.created_at DESC LIMIT 8)
     UNION ALL
         (SELECT 'stock_received' AS type,
             im.name AS subject,
             CAST(mb.units_received AS CHAR) AS extra,
             mb.received_at AS event_time
          FROM medicine_batches mb
          JOIN inventory_medicines im ON im.medicine_id = mb.medicine_id
          WHERE mb.created_by IS NOT NULL
          ORDER BY mb.received_at DESC, mb.batch_id DESC LIMIT 8)
     UNION ALL
         (SELECT 'auth_event' AS type,
             CONCAT(u.first_name, ' ', u.last_name) AS subject,
             al.action AS extra,
             al.logged_at AS event_time
          FROM audit_log al
          JOIN users u ON u.user_id = al.user_id
          WHERE al.module = 'auth'
          ORDER BY al.logged_at DESC LIMIT 8)
     ORDER BY event_time DESC
     LIMIT 8"
);

$activity = [];
if ($activityResult) {
    while ($row = $activityResult->fetch_assoc()) {
        switch ($row['type']) {
            case 'user_added':
                $text = "New " . ucfirst($row['extra']) . " account created — " . $row['subject'];
                $icon = "user-plus";
                break;
            case 'stock_received':
                $text = "Stock recorded — " . $row['subject'] . " (" . $row['extra'] . " units)";
                $icon = "box";
                break;
            case 'auth_event':
                $text = $row['subject'] . ($row['extra'] === 'login' ? " signed in" : " signed out");
                $icon = $row['extra'] === 'login' ? "log-in" : "log-out";
                break;
            default:
                $text = "Recent activity — " . $row['subject'];
                $icon = "box";
                break;
        }
        $activity[] = [
            "text" => $text,
            "time" => date("g:i A, M j", strtotime($row['event_time'])),
            "icon" => $icon,
        ];
    }
}

$current_page = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-dashboard.css">
    <style>
        /* Schedule Coverage Gaps banner (2026-09-20) - scoped here rather
           than added to admin-dashboard.css since this is the only page
           that uses it; matches the inline-<style> precedent already set
           by staff/prescription-queue.php and staff/lab-queue.php for
           page-specific one-off additions. */
        .schedule-gap-banner {
            display: flex;
            gap: 12px;
            background: #FEF3E2;
            border: 1px solid #F0C36D;
            border-radius: 10px;
            padding: 16px 18px;
            margin-bottom: 20px;
            color: #8A5A00;
        }

        .schedule-gap-banner svg {
            flex-shrink: 0;
            margin-top: 2px;
        }

        .schedule-gap-body strong {
            display: block;
            margin-bottom: 4px;
            font-size: 14.5px;
        }

        .schedule-gap-body p {
            margin: 0 0 8px;
            font-size: 13.5px;
        }

        .schedule-gap-body ul {
            margin: 0 0 10px;
            padding-left: 20px;
            font-size: 13.5px;
        }

        .schedule-gap-body li {
            margin-bottom: 3px;
        }

        .schedule-gap-link {
            font-size: 13.5px;
            font-weight: 600;
            color: #8A5A00;
            text-decoration: underline;
        }
    </style>
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <!-- Main content -->
        <main class="main-content">

            <header class="page-header">
                <div>
                    <h1>Welcome back, <?php echo htmlspecialchars($adminFirstName); ?></h1>
                    <p class="page-subtitle">Here's what's happening across the hospital today.</p>
                </div>
                <div class="header-actions">
                    <span class="header-date"><?php echo htmlspecialchars($today); ?></span>
                </div>
            </header>

            <!-- Schedule Coverage Gaps warning (2026-09-20, Option 1 from
                 the advance-booking discussion) - only rendered when
                 there's actually something to flag, same "don't show an
                 empty state for a good thing" pattern as the patient
                 portal's no-show warning banner. -->
            <?php if (!empty($scheduleGaps)): ?>
                <div class="schedule-gap-banner">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                        <line x1="12" y1="9" x2="12" y2="13"></line>
                        <line x1="12" y1="17" x2="12.01" y2="17"></line>
                    </svg>
                    <div class="schedule-gap-body">
                        <strong>Schedule coverage gaps</strong>
                        <p>These doctors have no published schedule covering the next <?php echo $scheduleGapLookaheadDays; ?> days — patients booking ahead may see "no doctors available" for their department until this is renewed.</p>
                        <ul>
                            <?php foreach ($scheduleGaps as $gap): ?>
                                <li><?php echo htmlspecialchars($gap['doctor']); ?> (<?php echo htmlspecialchars($gap['department']); ?>) — <?php echo htmlspecialchars($gap['message']); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="doctor-schedule.php" class="schedule-gap-link">Go to Weekly Recurring Schedule &rarr;</a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Stats -->
            <section class="stats-grid-6">
                <?php foreach ($stats as $stat): ?>
                    <div class="stat-card stat-<?php echo htmlspecialchars($stat['accent']); ?>">
                        <div class="stat-icon">
                            <?php if ($stat['icon'] === 'users'): ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="9" cy="7" r="4"></circle>
                                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                                </svg>
                            <?php elseif ($stat['icon'] === 'user-md'): ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M19 8v3a7 7 0 0 1-14 0V8"></path>
                                    <line x1="12" y1="18" x2="12" y2="22"></line>
                                    <line x1="8" y1="22" x2="16" y2="22"></line>
                                    <path d="M12 2a3 3 0 0 0-3 3v3a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"></path>
                                </svg>
                            <?php elseif ($stat['icon'] === 'user'): ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="12" cy="7" r="4"></circle>
                                </svg>
                            <?php elseif ($stat['icon'] === 'bed'): ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M2 4v16"></path>
                                    <path d="M2 8h18a2 2 0 0 1 2 2v10"></path>
                                    <path d="M2 17h20"></path>
                                    <path d="M6 8v9"></path>
                                </svg>
                            <?php elseif ($stat['icon'] === 'box'): ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 8v13H3V8"></path>
                                    <path d="M1 3h22v5H1z"></path>
                                    <path d="M10 12h4"></path>
                                </svg>
                            <?php else: // alert 
                            ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                                    <line x1="12" y1="9" x2="12" y2="13"></line>
                                    <line x1="12" y1="17" x2="12.01" y2="17"></line>
                                </svg>
                            <?php endif; ?>
                        </div>
                        <div class="stat-info">
                            <span class="stat-value"><?php echo htmlspecialchars($stat['value']); ?></span>
                            <span class="stat-label"><?php echo htmlspecialchars($stat['label']); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>

            <!-- Content grid -->
            <section class="content-grid">

                <!-- Recent Activities -->
                <div class="card activity-card">
                    <div class="card-header">
                        <h2>Recent Activities</h2>
                    </div>
                    <ul class="activity-timeline">
                        <?php foreach ($activity as $item): ?>
                            <li class="activity-item">
                                <span class="activity-dot"></span>
                                <div class="activity-body">
                                    <p><?php echo htmlspecialchars($item['text']); ?></p>
                                    <span class="activity-time"><?php echo htmlspecialchars($item['time']); ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Right column -->
                <div class="side-column">

                    <!-- Quick Actions -->
                    <div class="card quick-actions-card">
                        <div class="card-header">
                            <h2>Quick Actions</h2>
                        </div>
                        <div class="quick-actions-grid">
                            <button class="quick-action" type="button" data-href="user-management.php?tab=doctors&action=add">
                                <span class="quick-action-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                        <circle cx="8.5" cy="7" r="4"></circle>
                                        <line x1="20" y1="8" x2="20" y2="14"></line>
                                        <line x1="23" y1="11" x2="17" y2="11"></line>
                                    </svg>
                                </span>
                                Add Doctor
                            </button>
                            <button class="quick-action" type="button" data-href="user-management.php?tab=staff&action=add">
                                <span class="quick-action-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                        <circle cx="8.5" cy="7" r="4"></circle>
                                        <line x1="20" y1="8" x2="20" y2="14"></line>
                                        <line x1="23" y1="11" x2="17" y2="11"></line>
                                    </svg>
                                </span>
                                Add Staff
                            </button>
                            <button class="quick-action" type="button" data-href="hospital-reports.php">
                                <span class="quick-action-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 3v18h18"></path>
                                        <path d="M18 17V9"></path>
                                        <path d="M13 17V5"></path>
                                        <path d="M8 17v-3"></path>
                                    </svg>
                                </span>
                                View Reports
                            </button>
                            <button class="quick-action" type="button" data-href="medicine-catalog.php">
                                <span class="quick-action-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M21 8v13H3V8"></path>
                                        <path d="M1 3h22v5H1z"></path>
                                        <path d="M10 12h4"></path>
                                    </svg>
                                </span>
                                Medicine Catalog
                            </button>
                        </div>
                    </div>

                </div>
            </section>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <script>
        // Quick action buttons -> navigate to their data-href.
        // (Small and self-contained here rather than pulling in
        // doctor-dashboard.js, which also wires up doctor-only queue/
        // follow-up filters that don't apply to this portal.)
        document.querySelectorAll('.quick-action').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var href = btn.getAttribute('data-href');
                if (href) window.location.href = href;
            });
        });
    </script>
</body>

</html>