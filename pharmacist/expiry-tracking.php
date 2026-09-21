<?php
// pharmacist/expiry-tracking.php
// Module 3.8 — Expiry Tracking.
// Also covers Module 3.10 — FEFO Dispense Order, below the main batch
// table: groups batches by medicine and ranks them by soonest-to-expire.
// See the "FEFO grouping" comment further down for why this is FEFO
// (First-Expired-First-Out) rather than strict FIFO.
//
// REAL (2026-07-27): medicine_batches now exists — see
// 004_create_medicine_batches.sql and dispense-actions.php's header
// comment for the full FEFO picture. Every batch shown here is real,
// created by either pharmacist/delivery-actions.php's confirm_delivery
// (real batch_no/expiry_date, from that form) or
// admin/inventory-actions.php's advance_po_status quick-advance path
// (NULL batch_no/expiry_date — no receiving form to collect them from;
// those batches are counted separately below rather than risk-banded,
// since an unknown expiry can't be Safe/Warning/Critical/Expired).
//
// REMOVED (2026-09-14): "Add Batch" (expiry-batch-actions.php) and the
// "Simulate a Dispense" preview tool were both taken out — Add Batch was
// unneeded, and Simulate a Dispense's client-side preview didn't include
// unknown-expiry batches the way the real FEFO query in
// staff/dispense-actions.php does, so it could understate what a real
// dispense would actually draw from. See git history for either if
// they're ever needed again.

require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/system_settings.php';

// Alert-rule thresholds shown in the "Expiry Alert Rules" card and used
// to color-band the batches below. FORMERLY hardcoded constants — now
// admin-editable via admin/system-settings.php (system_settings table,
// category 'inventory').
$expiry_warning_days = get_setting_int($conn, 'expiry_warning_days', 30);
$expiry_critical_days = get_setting_int($conn, 'expiry_critical_days', 7);

function expiryStatus($daysRemaining, $criticalDays, $warningDays)
{
    if ($daysRemaining < 0) return 'expired';
    if ($daysRemaining <= $criticalDays) return 'critical';
    if ($daysRemaining <= $warningDays) return 'warning';
    return 'safe';
}

$statusLabels = [
    'expired'  => 'Expired',
    'critical' => 'Critical',
    'warning'  => 'Warning',
    'safe'     => 'Safe',
];


// REAL (2026-07-27): medicine_batches now exists (004_create_medicine_
// batches.sql) — every batch here is real, created either by
// pharmacist/delivery-actions.php's confirm_delivery (real batch_no/
// expiry_date from that form) or admin/inventory-actions.php's
// advance_po_status quick-advance path (NULL batch_no/expiry_date, no
// receiving form to collect them from). Only batches with a KNOWN
// expiry_date are shown in the risk-banded table below — a NULL expiry
// can't be risk-banded into Safe/Warning/Critical/Expired, so those are
// counted separately instead of forcing a 5th status category. Only
// units_remaining > 0 batches are shown — a fully-dispensed batch has
// nothing left to track expiry risk for.
$today = new DateTime('today');

$batches = [];
$unknownExpiryCount = 0;
// CATEGORY RETIRED: this used to exclude Nitrile Gloves, Syringes, and
// other "Medical Supplies"-category rows. Those were leftover demo data
// — the pharmacy only manages medicine stock, and the real hospital
// inventory export has no equipment/supplies items at all — so they and
// medicine_names.category were both removed. Nothing left to exclude.
$batchQuery = $conn->query(
    "SELECT im.name AS medicine, mb.batch_id, mb.batch_no, mb.source, mb.units_remaining AS quantity, mb.expiry_date AS expiry
     FROM medicine_batches mb
     JOIN inventory_medicines im ON im.medicine_id = mb.medicine_id
     WHERE mb.units_remaining > 0
     ORDER BY (mb.expiry_date IS NULL) ASC, mb.expiry_date ASC, mb.batch_id ASC"
);
if ($batchQuery) {
    while ($row = $batchQuery->fetch_assoc()) {
        if ($row['expiry'] === null) {
            $unknownExpiryCount++;
            continue;
        }
        $row['batch'] = $row['batch_no'] ?? ('BATCH-' . $row['batch_id']);
        $expiryDate = new DateTime($row['expiry']);
        $daysRemaining = (int) $today->diff($expiryDate)->format('%r%a');
        $status = expiryStatus($daysRemaining, $expiry_critical_days, $expiry_warning_days);
        $batches[] = $row + ["days_remaining" => $daysRemaining, "status" => $status];
    }
}
// Already soonest-to-expire first — the SQL's ORDER BY expiry_date ASC
// above already sorts this, no separate usort() needed here.

$counts = ['expired' => 0, 'critical' => 0, 'warning' => 0, 'safe' => 0];
foreach ($batches as $b) {
    $counts[$b['status']]++;
}

// ---------- FEFO grouping (Module 3.10 addition) ----------
// FIFO for a pharmacy is really FEFO — First-Expired-First-Out. Which
// batch physically arrived first doesn't matter clinically; which one
// expires soonest does, since that's the batch that becomes unusable
// first. So "dispense order" here is ranked by expiry_date — the exact
// same ordering dispense-actions.php's real FEFO logic uses.
$batchesByMedicine = [];
foreach ($batches as $b) {
    $batchesByMedicine[$b['medicine']][] = $b;
}
// Already sorted soonest-first from the query above, so each group
// inherits that order — no re-sort needed per group.
$multiBatchMedicines = array_filter($batchesByMedicine, fn($group) => count($group) > 1);

$current_page = 'expiry-tracking';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expiry Tracking - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/pharmacist-dashboard.css">
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">

            <header class="page-header">
                <div>
                    <h1>Expiry Tracking</h1>
                    <p class="page-subtitle">Per-batch expiry monitoring, banded by how soon each batch needs to move or be pulled.</p>
                </div>
            </header>

            <?php if ($unknownExpiryCount > 0): ?>
                <p class="resubmit-admin-note">
                    <?php echo $unknownExpiryCount; ?> batch<?php echo $unknownExpiryCount === 1 ? '' : 'es'; ?> with stock left have no recorded expiry date (created via Admin's quick-advance path, which has no receiving form to collect one) — not shown below since they can't be risk-banded. FEFO still dispenses these last, after every batch with a known expiry.
                </p>
            <?php endif; ?>

            <!-- Real counts, computed above from the real $batches query. -->
            <div class="stats-grid">
                <div class="stat-card stat-red">
                    <div class="stat-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="15" y1="9" x2="9" y2="15"></line>
                            <line x1="9" y1="9" x2="15" y2="15"></line>
                        </svg>
                    </div>
                    <div class="stat-info">
                        <span class="stat-value" id="countExpired"><?php echo $counts['expired']; ?></span>
                        <span class="stat-label">Expired</span>
                    </div>
                </div>
                <div class="stat-card stat-amber">
                    <div class="stat-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 9v4"></path>
                            <path d="M12 17h.01"></path>
                            <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"></path>
                        </svg>
                    </div>
                    <div class="stat-info">
                        <span class="stat-value" id="countCritical"><?php echo $counts['critical']; ?></span>
                        <span class="stat-label">Critical (&le; 7 days)</span>
                    </div>
                </div>
                <div class="stat-card stat-blue">
                    <div class="stat-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                            <line x1="16" y1="2" x2="16" y2="6"></line>
                            <line x1="8" y1="2" x2="8" y2="6"></line>
                            <line x1="3" y1="10" x2="21" y2="10"></line>
                        </svg>
                    </div>
                    <div class="stat-info">
                        <span class="stat-value" id="countWarning"><?php echo $counts['warning']; ?></span>
                        <span class="stat-label">Warning (&le; 30 days)</span>
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
                        <span class="stat-value" id="countSafe"><?php echo $counts['safe']; ?></span>
                        <span class="stat-label">Safe</span>
                    </div>
                </div>
            </div>

            <!-- Answers "when does a notification fire" directly, since
                 there's no real notifications trigger to point to yet. -->
            <section class="card expiry-rules-card">
                <div class="card-header">
                    <h2>Expiry Alert Rules</h2>
                    <span class="card-subtitle">Not yet wired to real notifications &mdash; shown here as the intended policy</span>
                </div>
                <div class="expiry-rules-list">
                    <div class="expiry-rule-row">
                        <span class="status-pill status-expiry-warning">Warning</span>
                        <span>A batch enters Warning <?php echo (int) $expiry_warning_days; ?> days before its expiry date.</span>
                    </div>
                    <div class="expiry-rule-row">
                        <span class="status-pill status-expiry-critical">Critical</span>
                        <span>A batch enters Critical <?php echo (int) $expiry_critical_days; ?> days before its expiry date.</span>
                    </div>
                    <div class="expiry-rule-row">
                        <span class="status-pill status-expiry-expired">Expired</span>
                        <span>Past its expiry date &mdash; should be pulled from dispensable stock.</span>
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="card-header">
                    <h2>Tracked Batches</h2>
                    <span class="card-subtitle">Sorted soonest-to-expire first</span>
                </div>

                <div class="toolbar-filters">
                    <input type="text" id="batchSearch" placeholder="Search medicine or batch no.&hellip;" class="search-input">
                    <select id="statusFilter" class="filter-select">
                        <option value="all">All Statuses</option>
                        <option value="expired">Expired</option>
                        <option value="critical">Critical</option>
                        <option value="warning">Warning</option>
                        <option value="safe">Safe</option>
                    </select>
                </div>

                <div class="table-wrap">
                    <table class="queue-table" id="batchTable">
                        <thead>
                            <tr>
                                <th>Medicine</th>
                                <th>Batch No.</th>
                                <th>Source</th>
                                <th>Quantity</th>
                                <th>Expiry Date</th>
                                <th>Days Remaining</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="batchTableBody">
                            <?php foreach ($batches as $b): ?>
                                <tr data-medicine="<?php echo htmlspecialchars(strtolower($b['medicine'])); ?>" data-batch="<?php echo htmlspecialchars(strtolower($b['batch'])); ?>" data-status="<?php echo $b['status']; ?>">
                                    <td><?php echo htmlspecialchars($b['medicine']); ?></td>
                                    <td><?php echo htmlspecialchars($b['batch']); ?></td>
                                    <td><span class="status-pill batch-source-badge batch-source-<?php echo htmlspecialchars($b['source']); ?>"><?php echo htmlspecialchars(ucfirst($b['source'])); ?></span></td>
                                    <td><?php echo (int) $b['quantity']; ?></td>
                                    <td><?php echo htmlspecialchars(date('M j, Y', strtotime($b['expiry']))); ?></td>
                                    <td><?php echo $b['days_remaining'] < 0 ? abs($b['days_remaining']) . ' days ago' : $b['days_remaining'] . ' days'; ?></td>
                                    <td><span class="status-pill status-expiry-<?php echo $b['status']; ?>"><?php echo $statusLabels[$b['status']]; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="empty-state" id="batchEmptyState" hidden>No batches match your filters.</p>
                </div>
            </section>

            <!-- FEFO grouping: only medicines with 2+ open batches are
                 shown, since a single-batch medicine has no ordering
                 decision to make. -->
            <?php if (!empty($multiBatchMedicines)): ?>
                <section class="card">
                    <div class="card-header">
                        <h2>FEFO Dispense Order</h2>
                        <span class="card-subtitle">First-Expired, First-Out &mdash; medicines with more than one open batch, ranked by which to draw from first</span>
                    </div>
                    <div class="fefo-groups">
                        <?php foreach ($multiBatchMedicines as $medicineName => $group): ?>
                            <div class="fefo-group">
                                <h3 class="fefo-group-title"><?php echo htmlspecialchars($medicineName); ?></h3>
                                <div class="fefo-batch-list">
                                    <?php foreach ($group as $i => $b): ?>
                                        <div class="fefo-batch-row">
                                            <div class="fefo-batch-row-top">
                                                <span class="fefo-rank"><?php echo $i === 0 ? 'Use First' : 'Use Next'; ?></span>
                                                <span class="fefo-batch-no"><?php echo htmlspecialchars($b['batch']); ?></span>
                                            </div>
                                            <div class="fefo-batch-row-bottom">
                                                <span class="status-pill batch-source-badge batch-source-<?php echo htmlspecialchars($b['source']); ?>"><?php echo htmlspecialchars(ucfirst($b['source'])); ?></span>
                                                <span class="fefo-batch-qty"><?php echo (int) $b['quantity']; ?> units</span>
                                                <span class="fefo-batch-expiry">Expires <?php echo htmlspecialchars(date('M j, Y', strtotime($b['expiry']))); ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

        </main>
    </div>

    <script>
        // ---------- Search + status filter ----------
        const searchInput = document.getElementById('batchSearch');
        const statusFilter = document.getElementById('statusFilter');
        const emptyState = document.getElementById('batchEmptyState');

        function applyFilters() {
            const q = searchInput.value.trim().toLowerCase();
            const status = statusFilter.value;
            let visibleCount = 0;

            document.querySelectorAll('#batchTableBody tr').forEach(row => {
                const matchesSearch = !q || row.dataset.medicine.includes(q) || row.dataset.batch.includes(q);
                const matchesStatus = status === 'all' || row.dataset.status === status;
                const visible = matchesSearch && matchesStatus;
                row.hidden = !visible;
                if (visible) visibleCount++;
            });

            emptyState.hidden = visibleCount > 0;
        }

        searchInput.addEventListener('input', applyFilters);
        statusFilter.addEventListener('change', applyFilters);
    </script>
</body>

</html>