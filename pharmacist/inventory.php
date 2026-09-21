<?php
// pharmacist/inventory.php
// Module 3.5 — Inventory View.
//
// Read-only by design — per 3.5, stock only ever changes through an actual
// action (Dispense, Record Stock Batch), never a manual override field
// here, to preserve the audit trail.
//
// Supports ?filter= so the Dashboard's alert banner (inventory.php?filter=flagged)
// and future links can land here pre-filtered instead of on the full list.
//
// REMOVED (2026-08-22): "Request Restock" and the Purchase Request/
// Approval pipeline it submitted into are gone — see
// 018_replace_procurement_with_funding_source.sql. There's no more
// approval step in this system at all; a pharmacist who needs more stock
// for something already in the catalog just uses Record Stock Batch
// directly (pharmacist/record-stock-batch.php), tagged with the real
// funding source (MAIP/PhilHealth/PHO/Purchase Order/Donated) — nothing
// to "request" first. This page stays read-only, same as always.

require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/csrf.php';

// REAL QUERY: Expiry Date / Batch No. are real, sourced from
// medicine_batches (see 004_create_medicine_batches.sql) — status
// derivation below is computed from that real data, not mocked.
//
// CATEGORY RETIRED (see migration removing medicine_categories /
// medicine_names.category): the pharmacy confirmed they only manage
// medicine stock, never equipment/supplies, and the real hospital
// inventory export has zero equipment/supplies rows either — so the
// equipment/supplies exclusion this page used to apply here
// (isEquipmentCategory()) has nothing left to filter and was removed
// along with the column it read from.
$medicines = [];
$result = $conn->query(
    "SELECT im.medicine_id, im.name, im.unit, im.units_per_box, im.current_stock, im.minimum_stock
     FROM inventory_medicines im
     ORDER BY im.name ASC"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $medicines[] = [
            "id"            => (int) $row['medicine_id'],
            "name"          => $row['name'],
            "unit"          => $row['unit'],
            "units_per_box" => (int) $row['units_per_box'],
            "stock"         => (int) $row['current_stock'],
            "threshold"     => (int) $row['minimum_stock'],
        ];
    }
}

// BOXES + TYPE (2026-07-27): units_per_box and unit were already real
// columns, just never surfaced on this page — Stock Quantity only ever
// showed the piece count. current_stock is piece-denominated (dispensing
// deducts per piece, not per box — see dispense-actions.php), so it
// usually won't divide evenly into whole boxes anymore; boxes/leftover
// here are an approximate "how many full boxes is this" reading for
// shelf-counting, not a second source of truth. Guarded against
// units_per_box being 0 (shouldn't happen, but avoids a division error
// if a row is ever missing it).
foreach ($medicines as &$m) {
    $perBox = max(1, $m['units_per_box']);
    $m['boxes'] = intdiv($m['stock'], $perBox);
    $m['leftover'] = $m['stock'] % $perBox;
}
unset($m);

// REAL (2026-07-27): expiry_date and batch_no now come from
// medicine_batches (see 004_create_medicine_batches.sql) instead of a
// hardcoded PHP array. For each medicine, this shows the batch that's
// soonest to expire among batches that still have stock left (units_remaining
// > 0) — i.e. exactly the batch dispense-actions.php's FEFO logic would
// draw from next, which is the one that actually matters for "when do I
// need to worry about this." A medicine with multiple open batches only
// shows its most urgent one here; the full breakdown lives on
// expiry-tracking.php. NULL-expiry batches (only possible via
// admin/inventory-actions.php's quick-advance path) sort last, same
// priority order FEFO uses, so a medicine's real expiry always wins over
// an unknown one here too.
$batchByMedicineId = [];
$batchResult = $conn->query(
    "SELECT mb.medicine_id, mb.batch_no, mb.expiry_date
     FROM medicine_batches mb
     WHERE mb.units_remaining > 0
     ORDER BY mb.medicine_id, (mb.expiry_date IS NULL) ASC, mb.expiry_date ASC, mb.batch_id ASC"
);
if ($batchResult) {
    while ($row = $batchResult->fetch_assoc()) {
        // First row per medicine_id in this ordering is the soonest-
        // expiring (or only) open batch — skip if already captured.
        if (!isset($batchByMedicineId[$row['medicine_id']])) {
            $batchByMedicineId[$row['medicine_id']] = $row;
        }
    }
}
foreach ($medicines as &$m) {
    $batch = $batchByMedicineId[$m['id']] ?? null;
    // No open batch at all (e.g. a brand-new medicine with stock=0 and
    // no delivery yet) — falls back to "far future" so status derivation
    // below doesn't misread an absent batch as already-expired. has_open_batch
    // exists so the DISPLAY can tell the difference between this fallback
    // and a real date (see the render function below) - the fallback
    // value on its own is indistinguishable from a real ~1-year-out
    // expiry date once formatted, which would silently show fake data as
    // if it were a real one.
    $m['has_open_batch'] = ($batch !== null);
    $m['expiry'] = $batch['expiry_date'] ?? date('Y-m-d', strtotime('+1 year'));
    $m['batch_no'] = $batch['batch_no'] ?? '—';
}
unset($m);

// Status derivation, computed in PHP from real expiry/stock data rather
// than stored per-row — see admin/dashboard.php's low-stock card for the
// same "compute at read time" pattern against this same table.
$today = new DateTime();
foreach ($medicines as &$m) {
    $expiryDate = new DateTime($m['expiry']);
    $daysToExpiry = (int) $today->diff($expiryDate)->format('%r%a');

    if ($expiryDate < $today) {
        $m['status'] = 'expired';
    } elseif ($m['stock'] === 0) {
        $m['status'] = 'out_of_stock';
    } elseif ($daysToExpiry <= 30) {
        $m['status'] = 'near_expiry';
    } elseif ($m['stock'] < $m['threshold']) {
        $m['status'] = 'low_stock';
    } else {
        $m['status'] = 'normal';
    }
}
unset($m);

$statusLabels = [
    'normal'       => 'Normal',
    'low_stock'    => 'Low Stock',
    'near_expiry'  => 'Near Expiry',
    'expired'      => 'Expired',
    'out_of_stock' => 'Out of Stock',
];

// ?filter=flagged (from the Dashboard alert banner) means "anything not Normal".
// ?filter=<status> targets one status directly for more specific links later.
$activeFilter = $_GET['filter'] ?? 'all';
if ($activeFilter === 'flagged') {
    $visibleMedicines = array_filter($medicines, fn($m) => $m['status'] !== 'normal');
} elseif (array_key_exists($activeFilter, $statusLabels)) {
    $visibleMedicines = array_filter($medicines, fn($m) => $m['status'] === $activeFilter);
} else {
    $activeFilter = 'all';
    $visibleMedicines = $medicines;
}

// Equipment already excluded at the query level above (see the
// EQUIPMENT REMOVED note near $medicines) — $visibleMedicines here is
// medicines/fluids only, no further split needed.
$visibleDrugs = $visibleMedicines;

$current_page = 'inventory';

/**
 * Renders the inventory table (Medicines only, since Equipment was
 * removed — see the EQUIPMENT REMOVED note above). Kept parameterized
 * rather than inlined in case this page groups by category again later.
 */
function renderInventoryTable(string $tableId, array $rows, string $itemWord, array $statusLabels): void
{
    if (empty($rows)) {
?>
        <div class="empty-state">
            <div class="empty-illustration">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 8v13H3V8"></path>
                    <path d="M1 3h22v5H1z"></path>
                    <path d="M10 12h4"></path>
                </svg>
            </div>
            <p>No <?php echo htmlspecialchars($itemWord); ?> match this filter</p>
        </div>
    <?php
        return;
    }
    ?>
    <div class="table-wrap">
        <table class="queue-table sortable-table" id="<?php echo $tableId; ?>">
            <thead>
                <tr>
                    <th class="sortable" data-sort="name" data-type="text">Name <span class="sort-arrow"></span></th>
                    <th>Batch No.</th>
                    <th class="sortable" data-sort="type" data-type="text">Unit <span class="sort-arrow"></span></th>
                    <th class="sortable" data-sort="stock" data-type="number">Stock Quantity <span class="sort-arrow"></span></th>
                    <th>Pieces per Box</th>
                    <th class="sortable" data-sort="boxes" data-type="number">Boxes in Stock <span class="sort-arrow"></span></th>
                    <th class="sortable" data-sort="expiry" data-type="date">Expiry Date <span class="sort-arrow"></span></th>
                    <th class="sortable" data-sort="status" data-type="text">Status <span class="sort-arrow"></span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $m): ?>
                    <tr
                        data-name="<?php echo htmlspecialchars(strtolower($m['name'])); ?>"
                        data-type="<?php echo htmlspecialchars(strtolower($m['unit'])); ?>"
                        data-stock="<?php echo $m['stock']; ?>"
                        data-boxes="<?php echo $m['boxes']; ?>"
                        data-expiry="<?php echo $m['expiry']; ?>"
                        data-status="<?php echo $statusLabels[$m['status']]; ?>">
                        <td><?php echo htmlspecialchars($m['name']); ?></td>
                        <td><span class="batch-no"><?php echo htmlspecialchars($m['batch_no']); ?></span></td>
                        <td><?php echo htmlspecialchars(ucfirst($m['unit'])); ?></td>
                        <td><?php echo $m['stock']; ?> <?php echo $m['stock'] === 0 ? '<span class="stock-zero-tag">0</span>' : ''; ?></td>
                        <td><?php echo $m['units_per_box']; ?></td>
                        <td>
                            <?php echo $m['boxes']; ?> box<?php echo $m['boxes'] === 1 ? '' : 'es'; ?>
                            <?php if ($m['leftover'] > 0): ?>
                                <span class="text-muted">+ <?php echo $m['leftover']; ?> <?php echo htmlspecialchars($m['unit']); ?><?php echo $m['leftover'] === 1 ? '' : 's'; ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($m['has_open_batch']): ?>
                                <?php echo htmlspecialchars((new DateTime($m['expiry']))->format('M j, Y')); ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-pill status-<?php echo str_replace('_', '-', $m['status']); ?>">
                                <?php echo htmlspecialchars($statusLabels[$m['status']]); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php
}

// UI-ONLY (2026-07-18): summary cards, counted from $medicines already
// loaded above — no extra queries. "Critical" groups Out of Stock +
// Expired, since those are the two statuses that actually block
// dispensing right now (Near Expiry is a warning, not yet critical).
$totalMedicinesCount = count($medicines);
$lowStockSummaryCount = count(array_filter($medicines, fn($m) => $m['status'] === 'low_stock'));
$criticalSummaryCount = count(array_filter($medicines, fn($m) => in_array($m['status'], ['out_of_stock', 'expired'], true)));

// UI-ONLY (2026-07-18): "Recent Stock Activity" side panel — sample
// entries only, per the design brief. TODO(backend): once a unified
// stock-movement log exists, replace with a real query combining
// medicine_batches (stock in, any funding source — see
// 018_replace_procurement_with_funding_source.sql) and any expiry
// write-offs, most recent first.
$mockStockActivity = [
    ["type" => "delivery",  "medicine" => "Amoxicillin 500mg",        "detail" => "Delivery received from MedSupply Corp", "qty" => "+200", "time" => "Today, 9:10 AM"],
    ["type" => "dispensed", "medicine" => "Paracetamol 500mg",        "detail" => "Dispensed to patient",       "qty" => "-30",  "time" => "Today, 10:45 AM"],
    ["type" => "expired",   "medicine" => "Salbutamol Nebule 2.5mg",  "detail" => "Removed — expired batch",           "qty" => "-5",   "time" => "Yesterday, 4:20 PM"],
    ["type" => "delivery",  "medicine" => "Metformin 500mg",          "detail" => "Delivery received from PharmaLink Distributors", "qty" => "+20", "time" => "Jul 16, 9:10 AM"],
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory View - GabayMed</title>
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
                    <h1>Inventory View</h1>
                    <p class="page-subtitle">Current stock levels &mdash; read-only. Stock changes only through Dispense or Record Stock Batch.</p>
                </div>
            </header>

            <!-- Summary cards — counted from the same data the table below
                 already has, reusing the shared .stats-grid/.stat-card
                 pattern from every other portal dashboard. -->
            <section class="stats-grid">
                <div class="stat-card stat-teal">
                    <div class="stat-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 8v13H3V8"></path>
                            <path d="M1 3h22v5H1z"></path>
                            <path d="M10 12h4"></path>
                        </svg>
                    </div>
                    <div class="stat-info">
                        <span class="stat-value"><?php echo $totalMedicinesCount; ?></span>
                        <span class="stat-label">Total Items</span>
                    </div>
                </div>
                <div class="stat-card stat-amber">
                    <div class="stat-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                            <line x1="12" y1="9" x2="12" y2="13"></line>
                            <line x1="12" y1="17" x2="12.01" y2="17"></line>
                        </svg>
                    </div>
                    <div class="stat-info">
                        <span class="stat-value"><?php echo $lowStockSummaryCount; ?></span>
                        <span class="stat-label">Low Stock</span>
                    </div>
                </div>
                <div class="stat-card stat-red">
                    <div class="stat-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="8" x2="12" y2="12"></line>
                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
                        </svg>
                    </div>
                    <div class="stat-info">
                        <span class="stat-value"><?php echo $criticalSummaryCount; ?></span>
                        <span class="stat-label">Critical Stock</span>
                    </div>
                </div>
            </section>

            <section class="card">

                <div class="inventory-toolbar">
                    <input
                        type="text"
                        id="inventorySearch"
                        class="inventory-search"
                        placeholder="Search medicine name&hellip;" />

                    <div class="inventory-filter-chips">
                        <?php
                        $filterChips = ['all' => 'All'] + $statusLabels;
                        foreach ($filterChips as $key => $label):
                            $count = $key === 'all' ? count($medicines) : count(array_filter($medicines, fn($m) => $m['status'] === $key));
                        ?>
                            <a href="?filter=<?php echo $key; ?>" class="filter-chip <?php echo $activeFilter === $key ? 'active' : ''; ?>">
                                <?php echo htmlspecialchars($label); ?> <span class="filter-chip-count"><?php echo $count; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <div class="inventory-toolbar-actions">
                        <button type="button" class="btn btn-secondary btn-sm" id="openActivityPanel">
                            Recent Stock Activity
                        </button>
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="card-header">
                    <h2>Medicines</h2>
                    <span class="card-subtitle">Stock, box counts, and expiry for medicine items</span>
                </div>
                <?php renderInventoryTable('medicineTable', $visibleDrugs, 'medicines', $statusLabels); ?>
            </section>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <!-- Recent Stock Activity — UI ONLY, sample entries per the design
         brief. Reuses the same modal shell and .activity-feed list the
         Dashboard already uses for its Recent Activity feed. -->
    <div class="modal-backdrop" id="activityModal" aria-hidden="true">
        <div class="modal-box dr-modal-box" role="dialog" aria-modal="true" aria-labelledby="activityModalTitle">
            <div class="dr-modal-header">
                <h3 id="activityModalTitle">Recent Stock Activity</h3>
                <button type="button" class="dr-modal-close" data-close-modal="activityModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="dr-modal-body">
                <div class="activity-feed">
                    <?php foreach ($mockStockActivity as $event): ?>
                        <div class="activity-item">
                            <span class="activity-icon activity-icon-<?php echo $event['type']; ?>">
                                <?php if ($event['type'] === 'delivery'): ?>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M10 17h4V5H2v12h3"></path>
                                        <path d="M20 17h2v-3.34a4 4 0 0 0-1.17-2.83L19 9h-5v8h1"></path>
                                        <circle cx="7.5" cy="17.5" r="2.5"></circle>
                                        <circle cx="17.5" cy="17.5" r="2.5"></circle>
                                    </svg>
                                <?php elseif ($event['type'] === 'dispensed'): ?>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 7V5a2 2 0 0 1 2-2h2"></path>
                                        <path d="M17 3h2a2 2 0 0 1 2 2v2"></path>
                                        <path d="M21 17v2a2 2 0 0 1-2 2h-2"></path>
                                        <path d="M7 21H5a2 2 0 0 1-2-2v-2"></path>
                                        <line x1="7" y1="12" x2="17" y2="12"></line>
                                    </svg>
                                <?php else: ?>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="10"></circle>
                                        <line x1="12" y1="8" x2="12" y2="12"></line>
                                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                                    </svg>
                                <?php endif; ?>
                            </span>
                            <div class="activity-body">
                                <span class="activity-medicine"><?php echo htmlspecialchars($event['medicine']); ?></span>
                                <span class="activity-detail"><?php echo htmlspecialchars($event['detail']); ?></span>
                            </div>
                            <span class="activity-qty <?php echo str_starts_with($event['qty'], '+') ? 'activity-qty-positive' : 'activity-qty-negative'; ?>"><?php echo htmlspecialchars($event['qty']); ?></span>
                            <span class="activity-time"><?php echo htmlspecialchars($event['time']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Client-side search + column sort — this page is read-only, all
        // the data is already on the page, so no reload is needed for
        // either. Filtering by status uses the ?filter= links above
        // instead (server-rendered, so those stay simple, shareable URLs).
        //
        // TABLE (2026-08-20): Equipment's separate table was removed
        // (per the pharmacist's call, this page is medicines/fluids
        // only now) — this search/sort logic is unchanged and still
        // works generically off .sortable-table, just against the one
        // remaining table instead of two.
        const searchInput = document.getElementById('inventorySearch');
        const tables = Array.from(document.querySelectorAll('.sortable-table'));

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const term = searchInput.value.trim().toLowerCase();
                tables.forEach(function(table) {
                    table.querySelectorAll('tbody tr').forEach(function(row) {
                        row.style.display = row.dataset.name.includes(term) ? '' : 'none';
                    });
                });
            });
        }

        tables.forEach(function(table) {
            const tbody = table.querySelector('tbody');
            if (!tbody) return;

            let currentSort = {
                key: null,
                dir: 1
            };
            table.querySelectorAll('th.sortable').forEach(function(th) {
                th.addEventListener('click', function() {
                    const key = th.dataset.sort;
                    const type = th.dataset.type;
                    const dir = (currentSort.key === key) ? -currentSort.dir : 1;
                    currentSort = {
                        key,
                        dir
                    };

                    table.querySelectorAll('th.sortable .sort-arrow').forEach(el => el.textContent = '');
                    th.querySelector('.sort-arrow').textContent = dir === 1 ? '\u25B2' : '\u25BC';

                    const rows = Array.from(tbody.querySelectorAll('tr'));
                    rows.sort(function(a, b) {
                        let av = a.dataset[key],
                            bv = b.dataset[key];
                        if (type === 'number') {
                            av = parseFloat(av);
                            bv = parseFloat(bv);
                        } else if (type === 'date') {
                            av = new Date(av);
                            bv = new Date(bv);
                        }
                        if (av < bv) return -1 * dir;
                        if (av > bv) return 1 * dir;
                        return 0;
                    });
                    rows.forEach(row => tbody.appendChild(row));
                });
            });
        });

        // ---------- Recent Stock Activity (UI only) ----------
        const activityModal = document.getElementById('activityModal');
        const openActivityBtn = document.getElementById('openActivityPanel');
        if (openActivityBtn && activityModal) {
            openActivityBtn.addEventListener('click', function() {
                activityModal.classList.add('active');
                document.body.classList.add('modal-open');
            });
            activityModal.addEventListener('click', function(e) {
                if (e.target === activityModal) closeActivityModal();
            });
            activityModal.querySelectorAll('[data-close-modal="activityModal"]').forEach(function(btn) {
                btn.addEventListener('click', closeActivityModal);
            });
        }

        function closeActivityModal() {
            activityModal.classList.remove('active');
            document.body.classList.remove('modal-open');
        }
    </script>
</body>

</html>
