<?php
// pharmacist/dashboard.php
// Module 3.2 — Pharmacist Dashboard.
//
// PIVOT (2026-07-17): the original 3.2 spec centered this page on a
// "Pending Prescriptions" queue feeding into Dispense Medicine. That's
// gone now — the portal tracks box-level stock movement, not per-patient
// dispensing. This page now centers on:
//   - The same persistent low-stock / near-expiry alert banner as before
//     (still just as relevant to box-level stock).
//   - Stat cards reflecting today's stock movement.
//   - A Recent Activity feed of stock-batch and dispense events, most recent
//     first.
//
// PIVOT (2026-07-26): Storage Exit Scan removed — the "Boxes Scanned Out
// Today" stat and its Recent Activity branch were briefly gone.
//
// PIVOT (2026-07-26, later same day): Dispense Stock added back as a
// manual (non-barcode), cart-based replacement, writing to the same
// medicine_exit_log table Storage Exit Scan used — so the stat card and
// Recent Activity branch are restored here too. Unlike the old scan
// feature (and unlike Delivery Receiving), this is piece-level, not
// box-level — see dispense-actions.php's header comment.

require_once '../includes/auth_guard.php';
require_once '../config/db.php'; // provides $conn (mysqli connection)
require_role('pharmacist');

$pharmacistFirstName = $_SESSION['first_name'] ?? 'Pharmacist';
$pharmacistId = (int) $_SESSION['user_id'];
$today = date("F j, Y");
$todaySql = date("Y-m-d");

$totalMedicines = (int) $conn->query(
    "SELECT COUNT(*) c FROM inventory_medicines"
)->fetch_assoc()['c'];

$lowStockCount = (int) $conn->query(
    "SELECT COUNT(*) c FROM inventory_medicines WHERE current_stock <= minimum_stock"
)->fetch_assoc()['c'];

// Inventory count KPIs use the current calendar month as the dashboard period.
// DEDUPLICATED (2026-08-29): this block and the "Nearly Expiring" query
// below it were each accidentally duplicated further down the file (two
// near-identical copies of the same prepared statement, re-running and
// silently overwriting the first result). Consolidated to one copy each
// — the duplication wasn't just wasteful, it was actively dangerous: an
// earlier equipment-exclusion fix this session landed on only one of the
// two "Nearly Expiring" copies, leaving a stale unfiltered copy running
// first and then getting silently overwritten by the fixed one. Harmless
// by luck (last-assignment-wins happened to be the fixed copy), but the
// next person to touch either query might not be so lucky.
$countMetricsStmt = $conn->prepare(
    "SELECT COUNT(*) AS total_assigned,
            SUM(CASE WHEN icb.submitted_at IS NOT NULL AND icb.submitted_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS submitted_count,
            SUM(CASE WHEN icb.status = 'submitted' AND icb.assigned_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS review_count,
            SUM(CASE WHEN icb.status = 'confirmed' AND icb.assigned_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS confirmed_count
     FROM inventory_count_batches icb
     WHERE icb.assigned_by = ? AND icb.assigned_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
);
$countMetricsStmt->bind_param('i', $pharmacistId);
$countMetricsStmt->execute();
$countMetrics = $countMetricsStmt->get_result()->fetch_assoc() ?: [];
$countMetricsStmt->close();

$totalAssigned = (int) ($countMetrics['total_assigned'] ?? 0);
$submittedCount = (int) ($countMetrics['submitted_count'] ?? 0);
$reviewCount = (int) ($countMetrics['review_count'] ?? 0);
$confirmedCount = (int) ($countMetrics['confirmed_count'] ?? 0);
$countPercent = static function (int $value) use ($totalAssigned): int {
    return $totalAssigned > 0 ? (int) round(($value / $totalAssigned) * 100) : 0;
};

$varianceStmt = $conn->prepare(
    "SELECT
            SUM(CASE WHEN ici.final_count = ici.system_count THEN 1 ELSE 0 END) AS match_count,
            SUM(CASE WHEN ici.final_count < ici.system_count THEN 1 ELSE 0 END) AS short_count,
            SUM(CASE WHEN ici.final_count > ici.system_count THEN 1 ELSE 0 END) AS over_count
     FROM inventory_count_batches icb
     JOIN inventory_count_items ici ON ici.batch_id = icb.batch_id
     WHERE icb.assigned_by = ? AND icb.status = 'confirmed'
       AND icb.confirmed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
);
$varianceStmt->bind_param('i', $pharmacistId);
$varianceStmt->execute();
$variance = $varianceStmt->get_result()->fetch_assoc() ?: [];
$varianceStmt->close();

$matchCount = (int) ($variance['match_count'] ?? 0);
$shortCount = (int) ($variance['short_count'] ?? 0);
$overCount = (int) ($variance['over_count'] ?? 0);
$reviewedLines = $matchCount + $shortCount + $overCount;
$variancePercent = static function (int $value) use ($reviewedLines): int {
    return $reviewedLines > 0 ? (int) round(($value / $reviewedLines) * 100) : 0;
};
$chartMax = max($totalMedicines, $totalAssigned, $submittedCount, $confirmedCount, 1);
$matchPercent = $variancePercent($matchCount);
$shortPercent = $variancePercent($shortCount);
$varianceChartStyle = $reviewedLines > 0
    ? "conic-gradient(var(--teal) 0 {$matchPercent}%, var(--red) {$matchPercent}% " . ($matchPercent + $shortPercent) . "%, var(--amber) " . ($matchPercent + $shortPercent) . "% 100%)"
    : 'conic-gradient(var(--border) 0 100%)';

// CATEGORY RETIRED: this used to exclude Medical Supplies items
// (gloves, syringes) from the medicines-expiry widget. Those were
// leftover demo rows — the pharmacy only stocks medicine, so there's
// nothing left to exclude, and medicine_names.category no longer exists.
$nearlyExpiring = [];
$expiryStmt = $conn->prepare(
    "SELECT im.name AS medicine, mb.batch_no, mb.batch_id, mb.expiry_date,
            mb.units_remaining AS quantity_on_hand,
            DATEDIFF(mb.expiry_date, CURDATE()) AS days_remaining,
            im.unit
     FROM medicine_batches mb
     JOIN inventory_medicines im ON im.medicine_id = mb.medicine_id
     WHERE mb.units_remaining > 0
       AND mb.expiry_date >= CURDATE()
       AND mb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
     ORDER BY mb.expiry_date ASC, mb.batch_id ASC"
);
$expiryStmt->execute();
$expiryRows = $expiryStmt->get_result();
while ($row = $expiryRows->fetch_assoc()) {
    $nearlyExpiring[] = $row;
}
$expiryStmt->close();

// REAL (2026-07-27): medicine_batches now exists — same 30-day Near
// Expiry window expiry-tracking.php and inventory-procurement.php use.
// Counts distinct medicines with at least one open batch (units_remaining
// > 0) expiring within 30 days, not a count of batches — a medicine with
// two batches both about to expire should still only nudge this number
// by one, since that's one alert-worthy medicine, not two.
$nearExpiryCount = (int) $conn->query(
    "SELECT COUNT(DISTINCT medicine_id) c FROM medicine_batches
     WHERE units_remaining > 0 AND expiry_date IS NOT NULL
       AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
)->fetch_assoc()['c'];

$unitsReceivedRow = $conn->query(
    "SELECT COALESCE(SUM(units_received), 0) c FROM medicine_batches
    WHERE created_by IS NOT NULL AND DATE(received_at) = CURDATE()"
)->fetch_assoc();
$unitsReceivedToday = (int) $unitsReceivedRow['c'];

// UNIT CHANGE (2026-07-26): dispensing is piece-level now, not box-level
// — see dispense-actions.php's header comment. boxes_scanned holds the
// piece count directly for every row this endpoint writes, so summing it
// here gives pieces dispensed today, not boxes.
$piecesDispensedRow = $conn->query(
    "SELECT COALESCE(SUM(boxes_scanned), 0) c FROM medicine_exit_log
     WHERE DATE(scanned_at) = CURDATE()"
)->fetch_assoc();
$piecesDispensedToday = (int) $piecesDispensedRow['c'];

// ===================== RECENT ACTIVITY =====================
// UNION of recent manually recorded medicine_batches and medicine_exit_log
// rows (dispense-actions.php's dispense_stock writes), most recent first.
$activityResult = $conn->query(
    "(SELECT 'stock_received' AS type,
             im.name AS medicine,
             mb.units_received AS qty,
             COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'Unknown') AS actor,
             mb.received_at AS event_time
      FROM medicine_batches mb
      JOIN inventory_medicines im ON im.medicine_id = mb.medicine_id
      LEFT JOIN users u ON u.user_id = mb.created_by
    WHERE mb.created_by IS NOT NULL
      ORDER BY mb.received_at DESC, mb.batch_id DESC LIMIT 8)
     UNION ALL
     (SELECT 'dispense' AS type,
             im.name AS medicine,
             mel.boxes_scanned AS qty,
             CONCAT(u.first_name, ' ', u.last_name) AS actor,
             mel.scanned_at AS event_time
      FROM medicine_exit_log mel
      JOIN inventory_medicines im ON im.medicine_id = mel.medicine_id
      JOIN users u ON u.user_id = mel.staff_id
      ORDER BY mel.scanned_at DESC LIMIT 8)
     ORDER BY event_time DESC
     LIMIT 8"
);

$recentActivity = [];
if ($activityResult) {
    while ($row = $activityResult->fetch_assoc()) {
        if ($row['type'] === 'stock_received') {
            $unit = (int) $row['qty'] === 1 ? 'unit' : 'units';
            $detail = $row['qty'] . " {$unit} recorded by " . $row['actor'];
        } else {
            $piece = (int) $row['qty'] === 1 ? 'piece' : 'pieces';
            $detail = $row['qty'] . " {$piece} dispensed by " . $row['actor'];
        }
        $recentActivity[] = [
            "type"     => $row['type'],
            "medicine" => $row['medicine'],
            "detail"   => $detail,
            "time"     => date("g:i A", strtotime($row['event_time'])),
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
                        <span class="stat-value"><?php echo $unitsReceivedToday; ?></span>
                        <span class="stat-label">Units Recorded Today</span>
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
                        <span class="stat-value"><?php echo $piecesDispensedToday; ?></span>
                        <span class="stat-label">Pieces Dispensed Today</span>
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

            <section class="stats-grid dashboard-count-stats">
                <div class="stat-card stat-blue">
                    <div class="stat-info"><span class="stat-value"><?php echo $totalMedicines; ?></span><span class="stat-label">Total Medicines</span></div>
                </div>
                <div class="stat-card stat-teal">
                    <div class="stat-info"><span class="stat-value"><?php echo $totalAssigned; ?></span><span class="stat-label">Total Assigned <small>This month</small></span></div>
                </div>
                <div class="stat-card stat-green">
                    <div class="stat-info"><span class="stat-value"><?php echo $submittedCount; ?> <small><?php echo $countPercent($submittedCount); ?>%</small></span><span class="stat-label">Submitted <small>This month</small></span></div>
                </div>
                <div class="stat-card stat-amber">
                    <div class="stat-info"><span class="stat-value"><?php echo $reviewCount; ?> <small><?php echo $countPercent($reviewCount); ?>%</small></span><span class="stat-label">For Review <small>This month</small></span></div>
                </div>
                <div class="stat-card stat-red">
                    <div class="stat-info"><span class="stat-value"><?php echo $confirmedCount; ?> <small><?php echo $countPercent($confirmedCount); ?>%</small></span><span class="stat-label">Confirmed <small>This month</small></span></div>
                </div>
            </section>

            <section class="dashboard-analytics-grid">
                <article class="card dashboard-chart-card">
                    <div class="card-header">
                        <div>
                            <h2>Count Progress</h2><span class="card-subtitle">Month-to-date batch activity</span>
                        </div>
                    </div>
                    <div class="dashboard-progress-list">
                        <?php foreach (
                            [
                                ['label' => 'Total Medicines', 'value' => $totalMedicines, 'class' => 'progress-blue'],
                                ['label' => 'Total Assigned', 'value' => $totalAssigned, 'class' => 'progress-teal'],
                                ['label' => 'Submitted', 'value' => $submittedCount, 'class' => 'progress-green'],
                                ['label' => 'Confirmed', 'value' => $confirmedCount, 'class' => 'progress-amber'],
                            ] as $progress
                        ): ?>
                            <div class="dashboard-progress-row">
                                <div class="dashboard-progress-label"><span><?php echo htmlspecialchars($progress['label']); ?></span><strong><?php echo $progress['value']; ?></strong></div>
                                <div class="dashboard-progress-track"><span class="<?php echo $progress['class']; ?>" style="width: <?php echo round(($progress['value'] / $chartMax) * 100, 2); ?>%"></span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article class="card dashboard-chart-card">
                    <div class="card-header">
                        <div>
                            <h2>Reviewed Variance</h2><span class="card-subtitle">Confirmed lines this month</span>
                        </div>
                    </div>
                    <div class="dashboard-donut-layout">
                        <div class="dashboard-donut" style="background: <?php echo $varianceChartStyle; ?>"><span><?php echo $reviewedLines; ?><small>lines</small></span></div>
                        <div class="dashboard-variance-legend">
                            <div><i class="legend-teal"></i><span>Match</span><strong><?php echo $matchCount; ?> <small><?php echo $matchPercent; ?>%</small></strong></div>
                            <div><i class="legend-red"></i><span>Short</span><strong><?php echo $shortCount; ?> <small><?php echo $shortPercent; ?>%</small></strong></div>
                            <div><i class="legend-amber"></i><span>Over</span><strong><?php echo $overCount; ?> <small><?php echo $variancePercent($overCount); ?>%</small></strong></div>
                        </div>
                    </div>
                </article>
            </section>

            <section class="card dashboard-expiry-panel">
                <div class="card-header">
                    <div>
                        <h2>Nearly Expiring Medicines</h2><span class="card-subtitle">Open batches expiring within the next 90 days</span>
                    </div><a class="btn btn-secondary btn-sm" href="expiry-tracking.php">View Expiry Tracking</a>
                </div>
                <?php if (empty($nearlyExpiring)): ?>
                    <div class="empty-state">
                        <p>No open medicine batches expire within the next 90 days.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table dashboard-expiry-table">
                            <thead>
                                <tr>
                                    <th>Medicine</th>
                                    <th>Batch / Lot</th>
                                    <th>Expiry Date</th>
                                    <th>Days Remaining</th>
                                    <th>Quantity on Hand</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($nearlyExpiring as $expiry): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($expiry['medicine']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($expiry['batch_no'] ?: 'Batch-' . $expiry['batch_id']); ?></td>
                                        <td><?php echo htmlspecialchars(date('M j, Y', strtotime($expiry['expiry_date']))); ?></td>
                                        <td><span class="dashboard-expiry-days<?php echo (int) $expiry['days_remaining'] <= 7 ? ' is-critical' : ''; ?>"><?php echo (int) $expiry['days_remaining']; ?> days</span></td>
                                        <td><?php echo (int) $expiry['quantity_on_hand'] . ' ' . htmlspecialchars($expiry['unit']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Recent Activity — replaces the old Pending Prescriptions
                 queue. Combines Delivery Receiving and Dispense Stock
                 events into one feed, most recent first. -->
            <section class="card">
                <div class="card-header">
                    <h2>Recent Activity</h2>
                    <span class="card-subtitle">Stock batches and dispenses, most recent first</span>
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
                                    <?php if ($event['type'] === 'stock_received'): ?>
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