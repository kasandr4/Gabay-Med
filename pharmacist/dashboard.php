<?php
// pharmacist/dashboard.php
// Module 3.2 — Pharmacist Dashboard (UI ONLY).
//
// Per the brief: no backend logic, no SQL, no PHP functionality beyond the
// existing auth guard. Every number and list item below is static
// placeholder data so the page can be designed and reviewed before the
// data layer exists. Each spot that will eventually need a real query is
// marked "TODO(backend)".
//
// PIVOT (2026-07-17): the original 3.2 spec centered this page on a
// "Pending Prescriptions" queue feeding into Dispense Medicine. That's
// gone now — the portal tracks box-level stock movement, not per-patient
// dispensing. This page now centers on:
//   - The same persistent low-stock / near-expiry alert banner as before
//     (still just as relevant to box-level stock).
//   - Stat cards reflecting today's box movement (received / scanned out)
//     instead of a dispensed count.
//   - A Recent Activity feed combining Delivery Receiving and Storage
//     Exit Scan events, most recent first, replacing the queue table.

require_once '../includes/auth_guard.php';
require_role('pharmacist');

$pharmacistFirstName = $_SESSION['first_name'] ?? 'Pharmacist';
$today = date("F j, Y");

// TODO(backend): SELECT COUNT(*) FROM inventory_medicines WHERE current_stock < minimum_stock
$lowStockCount = 3;
// TODO(backend): SELECT COUNT(*) FROM medicines WHERE expiry_date <= NOW() + INTERVAL 30 DAY
// (also needs the expiry_date column flagged earlier for Inventory View)
$nearExpiryCount = 2;
// TODO(backend): SELECT COALESCE(SUM(approved_quantity), 0) FROM purchase_orders
// JOIN ... WHERE status = 'delivered' AND DATE(updated_at) = CURDATE()
// (approved_quantity is boxes ordered, same field delivery-receiving.php
// labels boxes_ordered — this dashboard figure is a box count, not a
// units count, so no units_per_box conversion needed here specifically;
// that conversion only applies where current_stock itself is touched,
// e.g. delivery-actions.php / exit-actions.php / inventory-actions.php's
// advance_po_status)
$boxesReceivedToday = 32;
// TODO(backend): SELECT COALESCE(SUM(boxes_scanned), 0) FROM medicine_exit_log WHERE DATE(scanned_at) = CURDATE()
$boxesScannedOutToday = 9;

// TODO(backend): UNION of recent purchase_orders (status = 'delivered')
// and recent medicine_exit_log rows, ORDER BY event time DESC LIMIT 8
$recentActivity = [
    ["type" => "exit",     "medicine" => "Losartan 50mg",     "detail" => "1 box scanned out by Liza Fernandez",              "time" => "9:40 AM"],
    ["type" => "delivery", "medicine" => "Metformin 500mg",   "detail" => "20 boxes received from PharmaLink Distributors",   "time" => "9:10 AM"],
    ["type" => "exit",     "medicine" => "Paracetamol 500mg", "detail" => "1 box scanned out by Liza Mendoza",                "time" => "8:15 AM"],
    ["type" => "delivery", "medicine" => "Amoxicillin 500mg", "detail" => "12 boxes received from MedSupply Corp",           "time" => "7:50 AM"],
];

$current_page = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacist Dashboard - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/pharmacist-dashboard.css">
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <!-- Main content -->
        <main class="main-content">

            <header class="page-header">
                <div>
                    <h1>Welcome back, <?php echo htmlspecialchars($pharmacistFirstName); ?></h1>
                    <p class="page-subtitle">Here's what needs your attention today.</p>
                </div>
                <div class="header-actions">
                    <span class="header-date"><?php echo htmlspecialchars($today); ?></span>
                </div>
            </header>

            <!-- Persistent low-stock / near-expiry alert banner.
                 Not dismissible on purpose — it stays visible until the
                 underlying stock issue is actually resolved. -->
            <?php if ($lowStockCount > 0 || $nearExpiryCount > 0): ?>
                <a href="inventory.php?filter=flagged" class="alert-banner">
                    <span class="alert-banner-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                            <line x1="12" y1="9" x2="12" y2="13"></line>
                            <line x1="12" y1="17" x2="12.01" y2="17"></line>
                        </svg>
                    </span>
                    <span class="alert-banner-text">
                        <?php if ($lowStockCount > 0): ?>
                            <strong><?php echo $lowStockCount; ?></strong> medicine<?php echo $lowStockCount === 1 ? '' : 's'; ?> below the low-stock threshold
                        <?php endif; ?>
                        <?php if ($lowStockCount > 0 && $nearExpiryCount > 0): ?>
                            &nbsp;&middot;&nbsp;
                        <?php endif; ?>
                        <?php if ($nearExpiryCount > 0): ?>
                            <strong><?php echo $nearExpiryCount; ?></strong> near expiry (within 30 days)
                        <?php endif; ?>
                    </span>
                    <span class="alert-banner-action">View inventory &rarr;</span>
                </a>
            <?php endif; ?>

            <!-- Stats -->
            <section class="stats-grid">
                <div class="stat-card stat-teal">
                    <div class="stat-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10 17h4V5H2v12h3"></path>
                            <path d="M20 17h2v-3.34a4 4 0 0 0-1.17-2.83L19 9h-5v8h1"></path>
                            <circle cx="7.5" cy="17.5" r="2.5"></circle>
                            <circle cx="17.5" cy="17.5" r="2.5"></circle>
                        </svg>
                    </div>
                    <div class="stat-info">
                        <span class="stat-value"><?php echo $boxesReceivedToday; ?></span>
                        <span class="stat-label">Boxes Received Today</span>
                    </div>
                </div>
                <div class="stat-card stat-green">
                    <div class="stat-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 7V5a2 2 0 0 1 2-2h2"></path>
                            <path d="M17 3h2a2 2 0 0 1 2 2v2"></path>
                            <path d="M21 17v2a2 2 0 0 1-2 2h-2"></path>
                            <path d="M7 21H5a2 2 0 0 1-2-2v-2"></path>
                            <line x1="7" y1="12" x2="17" y2="12"></line>
                        </svg>
                    </div>
                    <div class="stat-info">
                        <span class="stat-value"><?php echo $boxesScannedOutToday; ?></span>
                        <span class="stat-label">Boxes Scanned Out Today</span>
                    </div>
                </div>
                <div class="stat-card stat-amber">
                    <div class="stat-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 8v13H3V8"></path>
                            <path d="M1 3h22v5H1z"></path>
                            <path d="M10 12h4"></path>
                        </svg>
                    </div>
                    <div class="stat-info">
                        <span class="stat-value"><?php echo $lowStockCount; ?></span>
                        <span class="stat-label">Low Stock Medicines</span>
                    </div>
                </div>
                <div class="stat-card stat-red">
                    <div class="stat-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                    </div>
                    <div class="stat-info">
                        <span class="stat-value"><?php echo $nearExpiryCount; ?></span>
                        <span class="stat-label">Near Expiry (30 days)</span>
                    </div>
                </div>
            </section>

            <!-- Recent Activity — replaces the old Pending Prescriptions
                 queue. Combines Delivery Receiving and Storage Exit Scan
                 events into one feed, most recent first. -->
            <section class="card">
                <div class="card-header">
                    <h2>Recent Activity</h2>
                    <span class="card-subtitle">Deliveries and box scans, most recent first</span>
                </div>

                <?php if (empty($recentActivity)): ?>
                    <div class="empty-state">
                        <p>No stock movement logged yet today</p>
                    </div>
                <?php else: ?>
                    <div class="activity-feed">
                        <?php foreach ($recentActivity as $event): ?>
                            <div class="activity-item">
                                <span class="activity-icon activity-icon-<?php echo $event['type']; ?>">
                                    <?php if ($event['type'] === 'delivery'): ?>
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M10 17h4V5H2v12h3"></path>
                                            <path d="M20 17h2v-3.34a4 4 0 0 0-1.17-2.83L19 9h-5v8h1"></path>
                                            <circle cx="7.5" cy="17.5" r="2.5"></circle>
                                            <circle cx="17.5" cy="17.5" r="2.5"></circle>
                                        </svg>
                                    <?php else: ?>
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M3 7V5a2 2 0 0 1 2-2h2"></path>
                                            <path d="M17 3h2a2 2 0 0 1 2 2v2"></path>
                                            <path d="M21 17v2a2 2 0 0 1-2 2h-2"></path>
                                            <path d="M7 21H5a2 2 0 0 1-2-2v-2"></path>
                                            <line x1="7" y1="12" x2="17" y2="12"></line>
                                        </svg>
                                    <?php endif; ?>
                                </span>
                                <div class="activity-body">
                                    <span class="activity-medicine"><?php echo htmlspecialchars($event['medicine']); ?></span>
                                    <span class="activity-detail"><?php echo htmlspecialchars($event['detail']); ?></span>
                                </div>
                                <span class="activity-time"><?php echo htmlspecialchars($event['time']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

</body>

</html>