<?php
// admin/dashboard.php
// Module 1 — Admin Dashboard (UI ONLY).
//
// Per the brief: no backend logic, no SQL, no PHP functionality beyond the
// existing auth guard. Every number and list item below is static
// placeholder data so the page can be designed and reviewed before the
// data layer exists. Each spot that will eventually need a real query is
// marked "TODO(backend)" — swap those out the same way doctor/dashboard.php
// pulls its stats from $conn once this module is wired up for real.

require_once '../includes/auth_guard.php';
require_role('admin');

$adminFirstName = $_SESSION['first_name'] ?? 'Admin';
$today = date("F j, Y");

// TODO(backend): replace with real COUNT() queries per card
// (users WHERE role='patient', role='doctor' AND is_active=1,
// role IN ('admin','pharmacist') AND is_active=1, confinements WHERE
// discharge_status IS NULL, purchase_requests WHERE status='pending',
// inventory WHERE quantity <= reorder_threshold).
$stats = [
    ["label" => "Total Patients",           "value" => 1284, "icon" => "users",   "accent" => "teal"],
    ["label" => "Active Doctors",           "value" => 18,   "icon" => "user-md", "accent" => "blue"],
    ["label" => "Active Staff",             "value" => 9,    "icon" => "user",    "accent" => "purple"],
    ["label" => "Confined Patients",        "value" => 12,   "icon" => "bed",     "accent" => "green"],
    ["label" => "Pending Purchase Requests", "value" => 5,   "icon" => "box",     "accent" => "amber"],
    ["label" => "Low Stock Medicines",      "value" => 3,    "icon" => "alert",   "accent" => "red"],
];

// TODO(backend): pull from an activity/audit-log table, most recent first.
$activity = [
    ["text" => "New doctor added — Dr. Ramon Santos (Internal Medicine)", "time" => "20 minutes ago", "icon" => "user-plus"],
    ["text" => "Purchase request approved — Amoxicillin 500mg (200 units)", "time" => "1 hour ago", "icon" => "check-circle"],
    ["text" => "Inventory updated — Paracetamol restocked", "time" => "2 hours ago", "icon" => "box"],
    ["text" => "Staff account created — Liza Fernandez (Pharmacist)", "time" => "5 hours ago", "icon" => "user-plus"],
];

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
                            <button class="quick-action" type="button" data-href="inventory-procurement.php">
                                <span class="quick-action-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M21 8v13H3V8"></path>
                                        <path d="M1 3h22v5H1z"></path>
                                        <path d="M10 12h4"></path>
                                    </svg>
                                </span>
                                Inventory
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