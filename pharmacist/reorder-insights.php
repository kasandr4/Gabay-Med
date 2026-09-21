<?php
// pharmacist/reorder-insights.php
// Module 3.9 — Reorder Point Insights.
//
// Direct answer to the panel's question: "when to order if the medicine
// is fast-moving but won't be out of stock (yet)?" The existing Low
// Stock alert (inventory.php / admin's Hospital Inventory Alerts) only
// compares current_stock to a flat minimum_stock — it has no idea how
// fast a medicine is actually moving, so a fast-mover can look "fine"
// right up until it crashes to zero between deliveries.
//
// This page shows what a velocity-aware reorder point would look like
// instead: reorder_point = (avg_daily_usage x supplier_lead_time_days)
// + a safety-stock buffer. A medicine can be above minimum_stock and
// still need ordering *today* if it's moving fast enough to run out
// before a new order would arrive.
//
// FIXED (2026-08-22): the historical-lead-time query against
// purchase_order_deliveries/purchase_orders/purchase_requests broke when
// 018_replace_procurement_with_funding_source.sql dropped all three
// tables. Record Stock Batch (the table's replacement — see that
// migration) logs stock the instant it arrives; there's no separate
// "order placed" timestamp to compare against a "received" timestamp
// anymore, so historical lead time genuinely isn't computable. Rather
// than invent a substitute from medicine_batches.received_at gaps —
// which would measure how often this pharmacy happens to restock, not
// how long an order takes, and presenting that as "lead time" would be
// misleading rather than just imprecise — every medicine now uses
// the admin-configurable reorder_default_lead_time_days setting
// (matches includes/reorder_point.php's same setting/fix, since these
// two files intentionally duplicate this formula — see that file's
// header for why).
//
//   - avg_daily_usage: rolling 30-day average from medicine_exit_log.units_deducted,
//     per medicine. Divides by the number of days actually covered (capped
//     at 30) rather than a flat 30, so a medicine with only a few days of
//     log history isn't artificially under-averaged. Still real.
//   - lead_time_days: always the default constant now — see FIXED note
//     above.
//
// One judgment call: a medicine with zero exit-log history has no
// velocity to compute a reorder point from. Rather than guess a number,
// its reorder point falls back to minimum_stock (the old flat rule) and
// the row is flagged "No usage data yet" — see $noUsageData below.

require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/system_settings.php';

// CATEGORY RETIRED: this used to exclude Medical Supplies items (gloves,
// syringes) from the reorder-point view. Those were leftover demo rows —
// the pharmacy only stocks medicine, so there's nothing left to exclude,
// and medicine_names.category no longer exists.
$medicines = [];
$result = $conn->query(
    "SELECT im.medicine_id, im.name, im.unit, im.current_stock, im.minimum_stock
     FROM inventory_medicines im
     ORDER BY im.name ASC"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $medicines[] = $row;
    }
}

// Rolling 30-day average daily usage per medicine, from real exit-scan
// history. days_span is capped at 30 and floored at 1 so a medicine
// scanned out only once, today, doesn't divide by zero or get diluted
// against a mostly-empty 30-day window it hasn't existed for.
$avgDailyUsageByMedicine = [];
$usageResult = $conn->query(
    "SELECT medicine_id,
            SUM(units_deducted) AS total_units,
            LEAST(DATEDIFF(CURDATE(), MIN(scanned_at)) + 1, 30) AS days_span
     FROM medicine_exit_log
     WHERE scanned_at >= (CURDATE() - INTERVAL 29 DAY)
     GROUP BY medicine_id"
);
if ($usageResult) {
    while ($row = $usageResult->fetch_assoc()) {
        $days = max((int) $row['days_span'], 1);
        $avgDailyUsageByMedicine[(int) $row['medicine_id']] = $row['total_units'] / $days;
    }
}

// Admin-configurable via System Settings > Pharmacy & Inventory (see
// includes/system_settings.php). This used to be a per-medicine
// historical-lead-time lookup; see FIXED note above for why that broke
// and was replaced with one shared default. Read the same way
// includes/reorder_point.php reads it, so this report page and the
// dispense-time notification trigger can never silently disagree —
// they intentionally duplicate the reorder-point formula (see that
// file's header), but must never duplicate a stale copy of the setting.
$defaultLeadTimeDays = get_setting_int($conn, 'reorder_default_lead_time_days', 5);

// Safety stock buffer: extra cushion on top of what lead time alone
// would require, in case a delivery is late or usage spikes. Also
// admin-configurable, same reasoning as above.
$safetyStockPercent = get_setting_int($conn, 'reorder_safety_stock_percent', 20);

function computeReorderPoint($avgDailyUsage, $leadTimeDays, $safetyPercent)
{
    $base = $avgDailyUsage * $leadTimeDays;
    $safety = $base * ($safetyPercent / 100);
    return (int) ceil($base + $safety);
}

$rows = [];
foreach ($medicines as $m) {
    $medicineId = (int) $m['medicine_id'];
    $avgDailyUsage = $avgDailyUsageByMedicine[$medicineId] ?? 0;
    $leadTimeDays = $defaultLeadTimeDays;
    $noUsageData = !isset($avgDailyUsageByMedicine[$medicineId]);

    if ($noUsageData) {
        // No velocity to compute from — fall back to the old flat rule
        // instead of guessing a number.
        $reorderPoint = (int) $m['minimum_stock'];
    } else {
        $reorderPoint = computeReorderPoint($avgDailyUsage, $leadTimeDays, $safetyStockPercent);
    }
    $daysOfStockLeft = $avgDailyUsage > 0 ? (int) floor($m['current_stock'] / $avgDailyUsage) : null;

    if ($m['current_stock'] <= $reorderPoint) {
        $recommendation = 'reorder_now';
    } elseif (!$noUsageData && $m['current_stock'] <= $reorderPoint * 1.5) {
        $recommendation = 'monitor';
    } else {
        $recommendation = 'ok';
    }

    // The specific case the panel asked about: still above the flat
    // minimum_stock threshold, but the velocity-aware reorder point says
    // otherwise. This is what the old rule alone would miss.
    $missedByOldRule = !$noUsageData && ($m['current_stock'] > $m['minimum_stock']) && $recommendation === 'reorder_now';

    $rows[] = $m + [
        "avg_daily_usage"   => $avgDailyUsage,
        "lead_time_days"    => $leadTimeDays,
        "reorder_point"     => $reorderPoint,
        "days_of_stock_left" => $daysOfStockLeft,
        "recommendation"    => $recommendation,
        "missed_by_old_rule" => $missedByOldRule,
        "no_usage_data"     => $noUsageData,
    ];
}

// Soonest-to-run-out first.
usort($rows, fn($a, $b) => ($a['days_of_stock_left'] ?? PHP_INT_MAX) <=> ($b['days_of_stock_left'] ?? PHP_INT_MAX));

$counts = ['reorder_now' => 0, 'monitor' => 0, 'ok' => 0];
foreach ($rows as $r) {
    $counts[$r['recommendation']]++;
}

$recommendationLabels = [
    'reorder_now' => 'Reorder Now',
    'monitor'     => 'Monitor',
    'ok'          => 'Sufficient Stock',
];
$recommendationPillClass = [
    'reorder_now' => 'status-reorder-now',
    'monitor'     => 'status-reorder-monitor',
    'ok'          => 'status-reorder-ok',
];

$current_page = 'reorder-insights';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reorder Point Insights - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/pharmacist-dashboard.css">
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">

            <header class="page-header">
                <div>
                    <h1>Reorder Point Insights</h1>
                    <p class="page-subtitle">When a medicine actually needs reordering, based on how fast it's moving &mdash; not just whether it's already below minimum stock.</p>
                </div>
            </header>
            <?php if ($counts['reorder_now'] > 0): ?>
                <p class="card-subtitle" style="margin:-4px 0 18px;"><?php echo $counts['reorder_now']; ?> medicine<?php echo $counts['reorder_now'] === 1 ? '' : 's'; ?> flagged Reorder Now. Download the list above, then upload it on <a href="purchase-requests.php">Purchase Requests</a> &rsaquo; Import from file &mdash; quantities and details come pre-filled.</p>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card stat-red">
                    <div class="stat-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"></path>
                            <path d="M12 9v4"></path>
                            <path d="M12 17h.01"></path>
                        </svg>
                    </div>
                    <div class="stat-info">
                        <span class="stat-value"><?php echo $counts['reorder_now']; ?></span>
                        <span class="stat-label">Reorder Now</span>
                    </div>
                </div>
                <div class="stat-card stat-amber">
                    <div class="stat-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <path d="M12 6v6l4 2"></path>
                        </svg>
                    </div>
                    <div class="stat-info">
                        <span class="stat-value"><?php echo $counts['monitor']; ?></span>
                        <span class="stat-label">Monitor</span>
                    </div>
                </div>
                <div class="stat-card stat-teal">
                    <div class="stat-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                        </svg>
                    </div>
                    <div class="stat-info">
                        <span class="stat-value"><?php echo $counts['ok']; ?></span>
                        <span class="stat-label">Sufficient Stock</span>
                    </div>
                </div>
            </div>

            <section class="card">
                <div class="card-header">
                    <h2>Reorder Point by Medicine</h2>
                    <span class="card-subtitle">Sorted by soonest to run out. Assumes a <?php echo $defaultLeadTimeDays; ?>-day supplier lead time with a <?php echo $safetyStockPercent; ?>% safety stock buffer.</span>
                </div>

                <div class="toolbar-filters">
                    <input type="text" id="reorderSearch" placeholder="Search medicine&hellip;" class="search-input">
                    <select id="reorderFilter" class="filter-select">
                        <option value="all">All Recommendations</option>
                        <option value="reorder_now">Reorder Now</option>
                        <option value="monitor">Monitor</option>
                        <option value="ok">Sufficient Stock</option>
                    </select>
                </div>

                <div class="table-wrap">
                    <table class="queue-table" id="reorderTable">
                        <thead>
                            <tr>
                                <th>Medicine</th>
                                <th>Current Stock</th>
                                <th>Avg. Daily Usage</th>
                                <th>Days of Stock Left</th>
                                <th>Reorder Point</th>
                                <th>Recommendation</th>
                            </tr>
                        </thead>
                        <tbody id="reorderTableBody">
                            <?php foreach ($rows as $r): ?>
                                <tr
                                    data-name="<?php echo htmlspecialchars(strtolower($r['name'])); ?>"
                                    data-recommendation="<?php echo $r['recommendation']; ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars($r['name']); ?></strong>
                                        <?php if ($r['missed_by_old_rule']): ?>
                                            <div class="restock-revision-note">Missed by the old minimum-stock rule</div>
                                        <?php endif; ?>
                                        <?php if ($r['no_usage_data']): ?>
                                            <div class="restock-revision-note">No usage data yet &mdash; falling back to minimum stock</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="cell-current-stock"><?php echo (int) $r['current_stock']; ?> <?php echo htmlspecialchars($r['unit']); ?></td>
                                    <td class="cell-avg-usage"><?php echo round((float) $r['avg_daily_usage'], 1); ?> / day</td>
                                    <td class="cell-days-left"><?php echo $r['days_of_stock_left'] !== null ? $r['days_of_stock_left'] . ' days' : '&mdash;'; ?></td>
                                    <td class="cell-reorder-point"><?php echo (int) $r['reorder_point']; ?> <?php echo htmlspecialchars($r['unit']); ?></td>
                                    <td class="cell-recommendation"><span class="status-pill <?php echo $recommendationPillClass[$r['recommendation']]; ?>"><?php echo $recommendationLabels[$r['recommendation']]; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="empty-state" id="reorderEmptyState" hidden>No medicines match your filters.</p>
                </div>
            </section>

        </main>
    </div>

    <script>
        // ---------- Search + recommendation filter ----------
        const searchInput = document.getElementById('reorderSearch');
        const reorderFilter = document.getElementById('reorderFilter');
        const emptyState = document.getElementById('reorderEmptyState');

        function applyFilters() {
            const q = searchInput.value.trim().toLowerCase();
            const rec = reorderFilter.value;
            let visibleCount = 0;

            document.querySelectorAll('#reorderTableBody tr').forEach(row => {
                const matchesSearch = !q || row.dataset.name.includes(q);
                const matchesRec = rec === 'all' || row.dataset.recommendation === rec;
                const visible = matchesSearch && matchesRec;
                row.hidden = !visible;
                if (visible) visibleCount++;
            });

            emptyState.hidden = visibleCount > 0;
        }

        searchInput.addEventListener('input', applyFilters);
        reorderFilter.addEventListener('change', applyFilters);
    </script>
</body>

</html>