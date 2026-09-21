<?php
// admin/hospital-reports.php
// Hospital Reports hub — REBUILT 2026-08-08, scoped down from a much
// larger generic BI-dashboard mockup that promised categories with no
// real data model behind them at all:
//   - Laboratory Reports: no real reporting/analytics data model exists
//     for this yet (staff/lab-queue.php, added 2026-09-17, tracks
//     individual lab_order status but nothing rolled up for reporting).
//   - Financial Reports: no billing/payment/revenue tracking exists.
//   - Hospital Census / bed occupancy: room/location for confinement was
//     deliberately kept a plain text field specifically BECAUSE a
//     bed-management module was ruled out of scope early in this project
//     — re-promising "occupancy tracking" here directly contradicted
//     that decision.
//   - The category/department filters even referenced fake departments
//     ("Emergency", "Pharmacy", "Laboratory") and fake roles ("Finance
//     Desk", "System Scheduler") that don't exist in this system at all
//     (real departments: OB-GYN, Internal Medicine, Pediatrics,
//     Pulmonology; real roles: patient/doctor/admin/staff/pharmacist).
//     Surgery was removed 2026-09-16 (soft-deleted, is_active = 0 - see
//     023_deactivate_surgery_and_drop_waitlist.sql) since patient
//     booking is check-ups only now.
//
// Scoped to the three categories that actually have real data behind
// them: Patient, Appointment, Inventory. Inventory already has a real,
// dedicated page (inventory-reports.php) — this hub links out to it
// rather than duplicating. Patient and Appointment don't have their own
// page yet, so their breakdowns live directly on this page for now.
// (Procurement was a fourth category here until
// 018_replace_procurement_with_funding_source.sql removed the whole
// pipeline it reported on — folded into Inventory Reports rather than
// kept as a separate, now-empty category.)
//
// Also removed (none of these had any real backing): the "Generate
// Report"/"Export PDF"/"Export Excel" buttons (no PDF/Excel export
// mechanism exists anywhere in this codebase), the Reports Library table
// (no generated_reports table, no file storage/scheduling
// infrastructure), the analytics chart grid (mostly fabricated metrics —
// Revenue, Hospital Occupancy, Referral Statistics), the Scheduled
// Reports queue, Recent Downloads, Favorite Reports, and the PDF preview
// modal (which had a fabricated hospital address/email and a fake
// "Laboratory Referrals" department in its sample table).

require_once '../includes/auth_guard.php';
require_role('admin');
require_once '../config/db.php';

$today = date("F j, Y");
$current_page = 'reports';

// ============================================================
// Real stat cards
// ============================================================
$r = $conn->query("SELECT COUNT(*) AS cnt FROM users WHERE role = 'patient' AND is_active = 1");
$total_patients = (int) $r->fetch_assoc()['cnt'];

$r = $conn->query("SELECT COUNT(*) AS cnt FROM appointments WHERE MONTH(slot_start) = MONTH(CURDATE()) AND YEAR(slot_start) = YEAR(CURDATE())");
$appts_this_month = (int) $r->fetch_assoc()['cnt'];

$r = $conn->query("
    SELECT SUM(status = 'no_show') AS no_shows, SUM(status IN ('completed', 'no_show')) AS occurred
    FROM appointments
    WHERE MONTH(slot_start) = MONTH(CURDATE()) AND YEAR(slot_start) = YEAR(CURDATE())
");
$row = $r->fetch_assoc();
$occurred = (int) ($row['occurred'] ?? 0);
$no_shows = (int) ($row['no_shows'] ?? 0);
$no_show_rate = $occurred > 0 ? round(($no_shows / $occurred) * 100, 1) : 0.0;

$r = $conn->query("SELECT COUNT(*) AS cnt FROM appointments WHERE status = 'completed' AND MONTH(slot_start) = MONTH(CURDATE()) AND YEAR(slot_start) = YEAR(CURDATE())");
$completed_this_month = (int) $r->fetch_assoc()['cnt'];

// Same "low stock" definition inventory-reports.php already uses.
$r = $conn->query("SELECT COUNT(*) AS cnt FROM inventory_medicines WHERE current_stock <= minimum_stock");
$low_stock_count = (int) $r->fetch_assoc()['cnt'];

// FIXED (2026-08-22): purchase_requests was dropped by
// 018_replace_procurement_with_funding_source.sql, and there's no more
// "pending approval" concept in the funding-source model anyway — Record
// Stock Batch has no approval step. Replaced with a real equivalent:
// how much stock activity has actually happened this month, across every
// funding source, hospital-wide.
$r = $conn->query("SELECT COUNT(*) AS cnt FROM medicine_batches WHERE MONTH(received_at) = MONTH(CURDATE()) AND YEAR(received_at) = YEAR(CURDATE())");
$batches_this_month = (int) $r->fetch_assoc()['cnt'];

// ============================================================
// Patient Reports data
// ============================================================
$registrations_by_month = [];
$r = $conn->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, DATE_FORMAT(created_at, '%b %Y') AS label, COUNT(*) AS cnt
    FROM users
    WHERE role = 'patient' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY ym, label
    ORDER BY ym ASC
");
while ($row = $r->fetch_assoc()) $registrations_by_month[] = $row;
$max_month_count = max(array_merge(array_column($registrations_by_month, 'cnt'), [1]));

$priority_labels = ['regular' => 'Regular', 'senior' => 'Senior Citizen', 'pwd' => 'PWD', 'ip' => 'Indigenous Person'];
$priority_breakdown = [];
$r = $conn->query("SELECT priority_type, COUNT(*) AS cnt FROM users WHERE role = 'patient' AND is_active = 1 GROUP BY priority_type ORDER BY cnt DESC");
while ($row = $r->fetch_assoc()) $priority_breakdown[] = $row;

$top_no_shows = [];
$r = $conn->query("SELECT first_name, last_name, no_show_count FROM users WHERE role = 'patient' AND no_show_count > 0 ORDER BY no_show_count DESC LIMIT 5");
while ($row = $r->fetch_assoc()) $top_no_shows[] = $row;

// ============================================================
// Appointment Reports data
// ============================================================
$appts_by_department = [];
$r = $conn->query("
    SELECT d.department_name, COUNT(*) AS cnt
    FROM appointments a JOIN departments d ON a.department_id = d.department_id
    WHERE MONTH(a.slot_start) = MONTH(CURDATE()) AND YEAR(a.slot_start) = YEAR(CURDATE())
    GROUP BY d.department_name ORDER BY cnt DESC
");
while ($row = $r->fetch_assoc()) $appts_by_department[] = $row;
$max_dept_count = max(array_merge(array_column($appts_by_department, 'cnt'), [1]));

$status_labels = [
    'pending' => 'Pending',
    'confirmed' => 'Confirmed',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'no_show' => 'No-Show',
];
$appts_by_status = [];
$r = $conn->query("
    SELECT status, COUNT(*) AS cnt FROM appointments
    WHERE MONTH(slot_start) = MONTH(CURDATE()) AND YEAR(slot_start) = YEAR(CURDATE())
    GROUP BY status
");
while ($row = $r->fetch_assoc()) $appts_by_status[$row['status']] = (int) $row['cnt'];

$appts_by_source = ['patient' => 0, 'walk_in' => 0];
$r = $conn->query("
    SELECT booking_source, COUNT(*) AS cnt FROM appointments
    WHERE MONTH(slot_start) = MONTH(CURDATE()) AND YEAR(slot_start) = YEAR(CURDATE())
    GROUP BY booking_source
");
while ($row = $r->fetch_assoc()) $appts_by_source[$row['booking_source']] = (int) $row['cnt'];
$total_by_source = max($appts_by_source['patient'] + $appts_by_source['walk_in'], 1);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hospital Reports - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-dashboard.css">
    <link rel="stylesheet" href="../assets/css/hospital-reports.css">
</head>

<body>
    <div class="app-shell">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <header class="page-header reports-page-header">
                <div>
                    <h1>Hospital Reports</h1>
                    <p class="page-subtitle">Real, current-month figures pulled directly from the database.</p>
                </div>
                <span class="header-date"><?php echo htmlspecialchars($today); ?></span>
            </header>

            <section class="stats-grid-6 reports-summary-grid" aria-label="Hospital report summary">
                <div class="stat-card stat-teal reports-stat-card">
                    <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                        </svg></div>
                    <div class="stat-info"><span class="stat-value"><?php echo $total_patients; ?></span><span class="stat-label">Active Patients</span></div>
                </div>
                <div class="stat-card stat-blue reports-stat-card">
                    <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M8 2v4"></path>
                            <path d="M16 2v4"></path>
                            <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                            <path d="M3 10h18"></path>
                        </svg></div>
                    <div class="stat-info"><span class="stat-value"><?php echo $appts_this_month; ?></span><span class="stat-label">Appointments This Month</span></div>
                </div>
                <div class="stat-card stat-red reports-stat-card">
                    <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 8v4l3 3"></path>
                            <circle cx="12" cy="12" r="10"></circle>
                        </svg></div>
                    <div class="stat-info"><span class="stat-value"><?php echo $no_show_rate; ?>%</span><span class="stat-label">No-Show Rate This Month</span></div>
                </div>
                <div class="stat-card stat-green reports-stat-card">
                    <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 6L9 17l-5-5"></path>
                        </svg></div>
                    <div class="stat-info"><span class="stat-value"><?php echo $completed_this_month; ?></span><span class="stat-label">Consultations Completed This Month</span></div>
                </div>
                <div class="stat-card stat-amber reports-stat-card">
                    <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                            <path d="M12 9v4"></path>
                            <path d="M12 17h.01"></path>
                        </svg></div>
                    <div class="stat-info"><span class="stat-value"><?php echo $low_stock_count; ?></span><span class="stat-label">Medicines Below Reorder Point</span></div>
                </div>
                <div class="stat-card stat-purple reports-stat-card">
                    <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 7h-9"></path>
                            <path d="M14 17H5"></path>
                            <circle cx="17" cy="17" r="3"></circle>
                            <circle cx="7" cy="7" r="3"></circle>
                        </svg></div>
                    <div class="stat-info"><span class="stat-value"><?php echo $batches_this_month; ?></span><span class="stat-label">Stock Batches Recorded This Month</span></div>
                </div>
            </section>

            <section class="reports-category-grid" aria-label="Report categories">
                <article class="report-category-card">
                    <span class="report-category-icon report-category-teal"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                        </svg></span>
                    <h2>Patient Reports</h2>
                    <p>Registration trends, priority-type breakdown, and no-show patterns.</p>
                    <a class="btn btn-secondary" href="#patient-reports">View Below</a>
                </article>
                <article class="report-category-card">
                    <span class="report-category-icon report-category-red"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M8 2v4"></path>
                            <path d="M16 2v4"></path>
                            <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                            <path d="M3 10h18"></path>
                        </svg></span>
                    <h2>Appointment Reports</h2>
                    <p>This month's bookings by department, status, and walk-in vs. booked.</p>
                    <a class="btn btn-secondary" href="#appointment-reports">View Below</a>
                </article>
                <article class="report-category-card">
                    <span class="report-category-icon report-category-green"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 8v13H3V8"></path>
                            <path d="M1 3h22v5H1z"></path>
                            <path d="M10 12h4"></path>
                        </svg></span>
                    <h2>Inventory Reports</h2>
                    <p>Stock received by funding source, reconciliation discrepancies, and low-stock overview.</p>
                    <a class="btn btn-secondary" href="inventory-reports.php">View Reports</a>
                </article>
            </section>

            <section class="reports-analytics-grid" aria-label="Patient and appointment breakdowns" id="patient-reports">
                <article class="card report-chart-card">
                    <div class="card-header">
                        <h2>Patient Registrations</h2><span class="card-subtitle">Last 6 months</span>
                    </div>
                    <?php if (empty($registrations_by_month)): ?>
                        <p class="reports-empty-note">No registrations in the last 6 months.</p>
                    <?php else: ?>
                        <div class="real-bar-list">
                            <?php foreach ($registrations_by_month as $row): ?>
                                <div class="real-bar-row">
                                    <span class="real-bar-label"><?php echo htmlspecialchars($row['label']); ?></span>
                                    <div class="real-bar-track">
                                        <div class="real-bar-fill" style="width: <?php echo max(4, round(($row['cnt'] / $max_month_count) * 100)); ?>%"></div>
                                    </div>
                                    <span class="real-bar-value"><?php echo (int) $row['cnt']; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="card report-chart-card">
                    <div class="card-header">
                        <h2>Patients by Priority Type</h2><span class="card-subtitle">Active patients</span>
                    </div>
                    <?php if (empty($priority_breakdown)): ?>
                        <p class="reports-empty-note">No active patients on file.</p>
                    <?php else: ?>
                        <div class="real-simple-list">
                            <?php foreach ($priority_breakdown as $row): ?>
                                <div><span><?php echo htmlspecialchars($priority_labels[$row['priority_type']] ?? ucfirst($row['priority_type'])); ?></span><strong><?php echo (int) $row['cnt']; ?></strong></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="card report-chart-card">
                    <div class="card-header">
                        <h2>Highest No-Show Counts</h2><span class="card-subtitle">All-time, active patients</span>
                    </div>
                    <?php if (empty($top_no_shows)): ?>
                        <p class="reports-empty-note">No patients with recorded no-shows.</p>
                    <?php else: ?>
                        <div class="real-simple-list">
                            <?php foreach ($top_no_shows as $row): ?>
                                <div><span><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></span><strong><?php echo (int) $row['no_show_count']; ?></strong></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="card report-chart-card" id="appointment-reports">
                    <div class="card-header">
                        <h2>Appointments by Department</h2><span class="card-subtitle">This month</span>
                    </div>
                    <?php if (empty($appts_by_department)): ?>
                        <p class="reports-empty-note">No appointments booked this month yet.</p>
                    <?php else: ?>
                        <div class="real-bar-list">
                            <?php foreach ($appts_by_department as $row): ?>
                                <div class="real-bar-row">
                                    <span class="real-bar-label"><?php echo htmlspecialchars($row['department_name']); ?></span>
                                    <div class="real-bar-track">
                                        <div class="real-bar-fill" style="width: <?php echo max(4, round(($row['cnt'] / $max_dept_count) * 100)); ?>%"></div>
                                    </div>
                                    <span class="real-bar-value"><?php echo (int) $row['cnt']; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="card report-chart-card">
                    <div class="card-header">
                        <h2>Appointments by Status</h2><span class="card-subtitle">This month</span>
                    </div>
                    <?php if (empty($appts_by_status)): ?>
                        <p class="reports-empty-note">No appointments booked this month yet.</p>
                    <?php else: ?>
                        <div class="real-simple-list">
                            <?php foreach ($status_labels as $key => $label): ?>
                                <?php if (isset($appts_by_status[$key])): ?>
                                    <div><span><?php echo htmlspecialchars($label); ?></span><strong><?php echo (int) $appts_by_status[$key]; ?></strong></div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="card report-chart-card">
                    <div class="card-header">
                        <h2>Booked vs. Walk-In</h2><span class="card-subtitle">This month</span>
                    </div>
                    <div class="real-simple-list">
                        <div><span>Booked in advance</span><strong><?php echo $appts_by_source['patient']; ?> (<?php echo round(($appts_by_source['patient'] / $total_by_source) * 100); ?>%)</strong></div>
                        <div><span>Walk-in</span><strong><?php echo $appts_by_source['walk_in']; ?> (<?php echo round(($appts_by_source['walk_in'] / $total_by_source) * 100); ?>%)</strong></div>
                    </div>
                </article>
            </section>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>
        </main>
    </div>
</body>

</html>