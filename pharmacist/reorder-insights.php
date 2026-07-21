<?php
// pharmacist/reorder-insights.php
// Module 3.9 — Reorder Point Insights (UI ONLY).
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
// Real columns used: medicine_id, name, category, unit, current_stock,
// minimum_stock, from the existing inventory_medicines table.
//
// SCHEMA GAP: avg_daily_usage and lead_time_days are mocked below —
// neither exists yet. avg_daily_usage needs a real consumption log (the
// same missing piece flagged in Expiry Tracking / Storage Exit Scan —
// there's no medicine_exit_log yet to compute a moving average from).
// lead_time_days could come from supplier_bids.estimated_delivery once a
// supplier has been used more than once for a medicine (average their
// past delivery times), or a manual per-medicine default in the
// meantime.
//
// TODO(backend), if this gets wired up for real:
//   1. Add a consumption log (dispensing events, or reuse
//      medicine_exit_log once Storage Exit Scan is real) and compute a
//      rolling N-day average daily usage per medicine.
//   2. Compute lead_time_days from historical supplier_bids /
//      purchase_orders instead of a flat per-medicine default.
//   3. Reorder Point becomes a stored/derived value, and this page's
//      "Reorder Now" list becomes the actual trigger for suggesting a
//      new Purchase Request, instead of just the Low Stock alert.

require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';

// Real query — same table Inventory View already uses.
$medicines = [];
$result = $conn->query("SELECT medicine_id, name, category, unit, current_stock, minimum_stock FROM inventory_medicines ORDER BY name ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $medicines[] = $row;
    }
}

// TODO(backend): SELECT AVG(daily units) FROM a real consumption log,
// per medicine, over a rolling window (e.g. last 30 days).
// Mocked here as a plausible daily-usage figure, keyed by medicine name
// so it survives whatever medicine_id happens to be in this environment.
$mockDailyUsage = [
    "Amoxicillin 500mg"   => 8,
    "Paracetamol 500mg"   => 15,
    "Losartan 50mg"       => 3,
    "Metformin 500mg"     => 4,
    "Ibuprofen 200mg"     => 9,
    "Cetirizine 10mg"     => 2,
    "Ascorbic Acid 500mg" => 12,
    "Salbutamol Inhaler"  => 1,
];
$defaultDailyUsage = 3; // fallback for any real medicine not in the mock map above

// TODO(backend): derive from historical supplier_bids.estimated_delivery
// per medicine/supplier instead of a flat default.
$defaultLeadTimeDays = 5;

// Safety stock buffer: extra cushion on top of what lead time alone
// would require, in case a delivery is late or usage spikes.
const SAFETY_STOCK_PERCENT = 20;

function computeReorderPoint($avgDailyUsage, $leadTimeDays)
{
    $base = $avgDailyUsage * $leadTimeDays;
    $safety = $base * (SAFETY_STOCK_PERCENT / 100);
    return (int) ceil($base + $safety);
}

$rows = [];
foreach ($medicines as $m) {
    $avgDailyUsage = $mockDailyUsage[$m['name']] ?? $defaultDailyUsage;
    $leadTimeDays = $defaultLeadTimeDays;
    $reorderPoint = computeReorderPoint($avgDailyUsage, $leadTimeDays);
    $daysOfStockLeft = $avgDailyUsage > 0 ? (int) floor($m['current_stock'] / $avgDailyUsage) : null;

    if ($m['current_stock'] <= $reorderPoint) {
        $recommendation = 'reorder_now';
    } elseif ($m['current_stock'] <= $reorderPoint * 1.5) {
        $recommendation = 'monitor';
    } else {
        $recommendation = 'ok';
    }

    // The specific case the panel asked about: still above the flat
    // minimum_stock threshold, but the velocity-aware reorder point says
    // otherwise. This is what the old rule alone would miss.
    $missedByOldRule = ($m['current_stock'] > $m['minimum_stock']) && $recommendation === 'reorder_now';

    $rows[] = $m + [
        "avg_daily_usage"   => $avgDailyUsage,
        "lead_time_days"    => $leadTimeDays,
        "reorder_point"     => $reorderPoint,
        "days_of_stock_left" => $daysOfStockLeft,
        "recommendation"    => $recommendation,
        "missed_by_old_rule" => $missedByOldRule,
    ];
}

// Soonest-to-run-out first.
usort($rows, fn($a, $b) => ($a['days_of_stock_left'] ?? PHP_INT_MAX) <=> ($b['days_of_stock_left'] ?? PHP_INT_MAX));

$counts = ['reorder_now' => 0, 'monitor' => 0, 'ok' => 0];
$missedCount = 0;
foreach ($rows as $r) {
    $counts[$r['recommendation']]++;
    if ($r['missed_by_old_rule']) $missedCount++;
}

$recommendationLabels = [
    'reorder_now' => 'Reorder Now',
    'monitor'     => 'Monitor',
    'ok'          => 'OK',
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

            <section class="card expiry-rules-card">
                <div class="card-header">
                    <h2>Why Minimum Stock Alone Isn't Enough</h2>
                    <span class="card-subtitle">Answers: "when to order if the medicine is fast-moving but won't be out of stock yet?"</span>
                </div>
                <div class="expiry-rules-list">
                    <div class="expiry-rule-row">
                        <span class="status-pill status-reorder-ok">Old rule</span>
                        <span>Flag it once <strong>current stock &le; minimum stock</strong>. Doesn't account for how fast it's being used.</span>
                    </div>
                    <div class="expiry-rule-row">
                        <span class="status-pill status-reorder-now">New rule</span>
                        <span>Reorder Point = (average daily usage &times; supplier lead time) + <?php echo SAFETY_STOCK_PERCENT; ?>% safety buffer. Flag it once <strong>current stock &le; reorder point</strong> &mdash; which can trigger well before minimum stock does, for a fast-moving item.</span>
                    </div>
                    <?php if ($missedCount > 0): ?>
                        <div class="expiry-rule-row">
                            <span class="status-pill status-reorder-monitor"><?php echo $missedCount; ?></span>
                            <span><?php echo $missedCount === 1 ? 'medicine below' : 'medicines below'; ?> would be missed by the old rule right now &mdash; still above minimum stock, but already past its velocity-aware reorder point.</span>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <div class="stats-grid">
                <div class="stat-card stat-red">
                    <div class="stat-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"></path><path d="M12 9v4"></path><path d="M12 17h.01"></path></svg>
                    </div>
                    <div class="stat-info">
                        <span class="stat-value"><?php echo $counts['reorder_now']; ?></span>
                        <span class="stat-label">Reorder Now</span>
                    </div>
                </div>
                <div class="stat-card stat-amber">
                    <div class="stat-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg>
                    </div>
                    <div class="stat-info">
                        <span class="stat-value"><?php echo $counts['monitor']; ?></span>
                        <span class="stat-label">Monitor</span>
                    </div>
                </div>
                <div class="stat-card stat-teal">
                    <div class="stat-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    </div>
                    <div class="stat-info">
                        <span class="stat-value"><?php echo $counts['ok']; ?></span>
                        <span class="stat-label">OK</span>
                    </div>
                </div>
            </div>

            <section class="card">
                <div class="card-header">
                    <h2>Reorder Point by Medicine</h2>
                    <span class="card-subtitle">Sorted by soonest to run out. Lead time and safety stock % are adjustable below to see how the recommendation shifts.</span>
                </div>

                <div class="recon-form-grid" style="margin-bottom:16px;">
                    <div class="dr-field">
                        <label for="leadTimeInput">Supplier Lead Time (days)</label>
                        <input type="number" id="leadTimeInput" min="1" step="1" value="<?php echo $defaultLeadTimeDays; ?>">
                    </div>
                    <div class="dr-field">
                        <label for="safetyStockInput">Safety Stock Buffer (%)</label>
                        <input type="number" id="safetyStockInput" min="0" step="5" value="<?php echo SAFETY_STOCK_PERCENT; ?>">
                    </div>
                    <div class="dr-field">
                        <label>&nbsp;</label>
                        <button type="button" class="btn btn-secondary" id="recalcBtn">Recalculate</button>
                    </div>
                </div>

                <div class="toolbar-filters">
                    <input type="text" id="reorderSearch" placeholder="Search medicine&hellip;" class="search-input">
                    <select id="reorderFilter">
                        <option value="all">All Recommendations</option>
                        <option value="reorder_now">Reorder Now</option>
                        <option value="monitor">Monitor</option>
                        <option value="ok">OK</option>
                    </select>
                </div>

                <div class="table-wrap">
                    <table class="queue-table" id="reorderTable">
                        <thead>
                            <tr>
                                <th>Medicine</th>
                                <th>Current Stock</th>
                                <th>Min. Stock (old rule)</th>
                                <th>Avg. Daily Usage</th>
                                <th>Days of Stock Left</th>
                                <th>Reorder Point (new rule)</th>
                                <th>Recommendation</th>
                            </tr>
                        </thead>
                        <tbody id="reorderTableBody">
                            <?php foreach ($rows as $r): ?>
                                <tr
                                    data-name="<?php echo htmlspecialchars(strtolower($r['name'])); ?>"
                                    data-current-stock="<?php echo (int) $r['current_stock']; ?>"
                                    data-min-stock="<?php echo (int) $r['minimum_stock']; ?>"
                                    data-avg-usage="<?php echo (float) $r['avg_daily_usage']; ?>"
                                    data-unit="<?php echo htmlspecialchars($r['unit']); ?>"
                                    data-recommendation="<?php echo $r['recommendation']; ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars($r['name']); ?></strong>
                                        <?php if ($r['missed_by_old_rule']): ?>
                                            <div class="restock-revision-note">Missed by the old minimum-stock rule</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="cell-current-stock"><?php echo (int) $r['current_stock']; ?> <?php echo htmlspecialchars($r['unit']); ?></td>
                                    <td><?php echo (int) $r['minimum_stock']; ?> <?php echo htmlspecialchars($r['unit']); ?></td>
                                    <td class="cell-avg-usage"><?php echo (float) $r['avg_daily_usage']; ?> / day</td>
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
        const SAFETY_STOCK_DEFAULT = <?php echo SAFETY_STOCK_PERCENT; ?>;
        const LEAD_TIME_DEFAULT = <?php echo $defaultLeadTimeDays; ?>;
        const RECOMMENDATION_LABELS = { reorder_now: 'Reorder Now', monitor: 'Monitor', ok: 'OK' };
        const RECOMMENDATION_PILL_CLASS = { reorder_now: 'status-reorder-now', monitor: 'status-reorder-monitor', ok: 'status-reorder-ok' };

        function computeReorderPoint(avgDailyUsage, leadTimeDays, safetyPercent) {
            const base = avgDailyUsage * leadTimeDays;
            const safety = base * (safetyPercent / 100);
            return Math.ceil(base + safety);
        }

        function recommendationFor(currentStock, reorderPoint) {
            if (currentStock <= reorderPoint) return 'reorder_now';
            if (currentStock <= reorderPoint * 1.5) return 'monitor';
            return 'ok';
        }

        // Recomputes every row's Reorder Point / Days Left / Recommendation
        // client-side, purely so the effect of lead time and safety stock
        // is visible immediately. Nothing here is persisted.
        function recalcAll() {
            const leadTime = parseFloat(document.getElementById('leadTimeInput').value) || LEAD_TIME_DEFAULT;
            const safetyPercent = parseFloat(document.getElementById('safetyStockInput').value) || 0;

            document.querySelectorAll('#reorderTableBody tr').forEach(row => {
                const currentStock = parseFloat(row.dataset.currentStock);
                const avgUsage = parseFloat(row.dataset.avgUsage);
                const reorderPoint = computeReorderPoint(avgUsage, leadTime, safetyPercent);
                const daysLeft = avgUsage > 0 ? Math.floor(currentStock / avgUsage) : null;
                const recommendation = recommendationFor(currentStock, reorderPoint);

                row.dataset.recommendation = recommendation;
                row.querySelector('.cell-reorder-point').textContent = reorderPoint + ' ' + row.dataset.unit;
                row.querySelector('.cell-days-left').textContent = daysLeft !== null ? daysLeft + ' days' : '\u2014';

                const pillCell = row.querySelector('.cell-recommendation');
                pillCell.innerHTML = `<span class="status-pill ${RECOMMENDATION_PILL_CLASS[recommendation]}">${RECOMMENDATION_LABELS[recommendation]}</span>`;
            });

            applyFilters();
        }

        document.getElementById('recalcBtn').addEventListener('click', recalcAll);

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
