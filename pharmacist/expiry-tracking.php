<?php
// pharmacist/expiry-tracking.php
// Module 3.8 — Expiry Tracking (UI ONLY).
// Also covers Module 3.10 — FEFO Dispense Order (UI ONLY), added below
// the main batch table: groups batches by medicine and ranks them by
// soonest-to-expire, plus a "Simulate a Dispense" tool that walks that
// order client-side. See the "FEFO grouping" comment further down for
// why this is FEFO (First-Expired-First-Out) rather than strict FIFO.
//
// Per the brief: no backend logic, no SQL beyond the existing auth guard
// and the medicine name list (which reuses the real inventory_medicines
// table, since that one already exists — only expiry_date/batch_no are
// mocked here).
//
// SCHEMA GAP: inventory_medicines has no expiry_date or batch_no column
// yet — this is the same gap already flagged in inventory-procurement.php
// ("Sample preview — no expiry_date column yet") and in
// delivery-receiving.php's Batch Number / Expiry Date fields ("not read
// by Confirm Delivery's click handler"). This page is the first full
// mockup of what those fields unlock once they exist: per-batch expiry
// tracking with a risk-banded table, instead of one date per medicine.
//
// TODO(backend), if this gets wired up for real:
//   1. ALTER inventory_medicines (or a new inventory_batches table, if a
//      medicine can have more than one open batch at a time) to add
//      batch_no VARCHAR, expiry_date DATE, received_at DATE.
//   2. "Add Batch" below becomes a real INSERT, ideally the same one
//      Delivery Receiving's Confirm Delivery step already stubs out.
//   3. Days Remaining / risk band become a computed column or view
//      instead of the client-side JS calculation used here.
//   4. The notification bell (includes/notifications_api.php once that
//      exists for this portal) gets a trigger: fire once when a batch
//      first crosses into Warning (<=30 days) and again at Critical
//      (<=7 days), so the Admin/Pharmacist aren't notified daily for the
//      same batch.

require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';

// Real table, real query — just the medicine list for the Add Batch
// dropdown. Everything about expiry_date/batch_no below it is mock.
$medicines = [];
$result = $conn->query("SELECT name FROM inventory_medicines ORDER BY name ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $medicines[] = $row['name'];
    }
}
if (empty($medicines)) {
    // Fallback so the page still renders something sensible if the table
    // is empty or unreachable in a given environment.
    $medicines = ["Amoxicillin 500mg", "Paracetamol 500mg", "Losartan 50mg", "Metformin 500mg", "Ibuprofen 200mg", "Cetirizine 10mg"];
}

// Alert-rule thresholds shown in the "Expiry Alert Rules" card and used
// to color-band the mock batches below. Answers the panel's "when" —
// these are the two points a batch would trip a notification once this
// is wired to a real notifications table.
const EXPIRY_WARNING_DAYS = 30;
const EXPIRY_CRITICAL_DAYS = 7;

function expiryStatus($daysRemaining)
{
    if ($daysRemaining < 0) return 'expired';
    if ($daysRemaining <= EXPIRY_CRITICAL_DAYS) return 'critical';
    if ($daysRemaining <= EXPIRY_WARNING_DAYS) return 'warning';
    return 'safe';
}

$statusLabels = [
    'expired'  => 'Expired',
    'critical' => 'Critical',
    'warning'  => 'Warning',
    'safe'     => 'Safe',
];

// TODO(backend): SELECT im.name, ib.batch_no, ib.quantity, ib.expiry_date
// FROM inventory_batches ib JOIN inventory_medicines im ON im.medicine_id = ib.medicine_id
// ORDER BY ib.expiry_date ASC
$today = new DateTime('today');
$mockBatches = [
    ["medicine" => "Amoxicillin 500mg",   "batch" => "BN-014-2026", "quantity" => 120, "expiry" => "2026-07-10"],
    ["medicine" => "Amoxicillin 500mg",   "batch" => "BN-027-2026", "quantity" => 200, "expiry" => "2026-09-18"],
    ["medicine" => "Ibuprofen 200mg",     "batch" => "BN-011-2026", "quantity" => 90,  "expiry" => "2026-07-15"],
    ["medicine" => "Ascorbic Acid 500mg", "batch" => "BN-030-2026", "quantity" => 500, "expiry" => "2026-07-21"],
    ["medicine" => "Paracetamol 500mg",   "batch" => "BN-021-2026", "quantity" => 300, "expiry" => "2026-07-24"],
    ["medicine" => "Paracetamol 500mg",   "batch" => "BN-034-2026", "quantity" => 150, "expiry" => "2026-11-02"],
    ["medicine" => "Cetirizine 10mg",     "batch" => "BN-009-2026", "quantity" => 60,  "expiry" => "2026-08-02"],
    ["medicine" => "Losartan 50mg",       "batch" => "BN-018-2026", "quantity" => 200, "expiry" => "2026-08-14"],
    ["medicine" => "Metformin 500mg",     "batch" => "BN-005-2026", "quantity" => 150, "expiry" => "2026-10-01"],
    ["medicine" => "Salbutamol Inhaler",  "batch" => "BN-002-2026", "quantity" => 40,  "expiry" => "2026-12-15"],
];

$batches = [];
foreach ($mockBatches as $b) {
    $expiryDate = new DateTime($b['expiry']);
    $daysRemaining = (int) $today->diff($expiryDate)->format('%r%a');
    $status = expiryStatus($daysRemaining);
    $batches[] = $b + ["days_remaining" => $daysRemaining, "status" => $status];
}

// Sort soonest-to-expire first — the whole point of the table.
usort($batches, fn($a, $b) => $a['days_remaining'] <=> $b['days_remaining']);

$counts = ['expired' => 0, 'critical' => 0, 'warning' => 0, 'safe' => 0];
foreach ($batches as $b) {
    $counts[$b['status']]++;
}

// ---------- FEFO grouping (Module 3.10 addition) ----------
// FIFO for a pharmacy is really FEFO — First-Expired-First-Out. Which
// batch physically arrived first doesn't matter clinically; which one
// expires soonest does, since that's the batch that becomes unusable
// first. So "dispense order" here is ranked by expiry_date, not
// received_at (which isn't even tracked yet — see the TODO(backend)
// above).
$batchesByMedicine = [];
foreach ($batches as $b) {
    $batchesByMedicine[$b['medicine']][] = $b;
}
// Already sorted soonest-first from the usort() above, so each group
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
                <div class="header-actions">
                    <button class="btn btn-primary" type="button" id="openAddBatchBtn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                        Add Batch
                    </button>
                </div>
            </header>

            <!-- UI ONLY: static counts computed above from the mock $batches
                 array. Once inventory_batches is real, this becomes a
                 GROUP BY status count query. -->
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
                        <span>A batch enters Warning 30 days before its expiry date.</span>
                    </div>
                    <div class="expiry-rule-row">
                        <span class="status-pill status-expiry-critical">Critical</span>
                        <span>A batch enters Critical 7 days before its expiry date.</span>
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
                    <select id="statusFilter">
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
                                            <span class="fefo-rank"><?php echo $i === 0 ? 'Use First' : 'Use Next'; ?></span>
                                            <span class="fefo-batch-no"><?php echo htmlspecialchars($b['batch']); ?></span>
                                            <span class="fefo-batch-qty"><?php echo (int) $b['quantity']; ?> units</span>
                                            <span class="fefo-batch-expiry">Expires <?php echo htmlspecialchars(date('M j, Y', strtotime($b['expiry']))); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <!-- Simulate a Dispense: purely client-side, walks
                 window.BATCH_DATA in FEFO order and shows which batch(es)
                 a given quantity would be drawn from. Nothing here
                 deducts real stock or writes anything — it's meant to
                 make the FEFO rule tangible, the same way the Reorder
                 Insights page's Lead Time / Safety Stock inputs make
                 that formula tangible. -->
            <section class="card">
                <div class="card-header">
                    <h2>Simulate a Dispense</h2>
                    <span class="card-subtitle">See which batch(es) FEFO would draw from for a given quantity &mdash; nothing is actually deducted</span>
                </div>
                <div class="recon-form-grid" style="margin-bottom:16px;">
                    <div class="dr-field">
                        <label for="fefoMedicineSelect">Medicine</label>
                        <select id="fefoMedicineSelect">
                            <option value="">Select medicine&hellip;</option>
                            <?php foreach (array_keys($batchesByMedicine) as $medicineName): ?>
                                <option value="<?php echo htmlspecialchars($medicineName); ?>"><?php echo htmlspecialchars($medicineName); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="dr-field">
                        <label for="fefoQuantityInput">Quantity to Dispense</label>
                        <input type="number" id="fefoQuantityInput" min="1" step="1" placeholder="e.g. 250">
                    </div>
                    <div class="dr-field">
                        <label>&nbsp;</label>
                        <button type="button" class="btn btn-primary" id="fefoSimulateBtn">Simulate</button>
                    </div>
                </div>
                <div id="fefoResult"></div>
            </section>

        </main>
    </div>

    <!-- Add Batch modal — same shell as Delivery Receiving's modal.
         UI ONLY: nothing here is saved. Days Remaining / Status are
         computed client-side purely to show what "automatically
         determining expiration upon acquisition" would look like once
         expiry_date/batch_no are real columns. -->
    <div class="modal-backdrop" id="addBatchModal" aria-hidden="true">
        <div class="modal-box dr-modal-box" role="dialog" aria-modal="true" aria-labelledby="addBatchModalTitle">
            <div class="dr-modal-header">
                <h3 id="addBatchModalTitle">Add Batch</h3>
                <button type="button" class="dr-modal-close" data-close-modal="addBatchModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <form id="addBatchForm">
                <div class="dr-modal-body">
                    <div class="dr-field">
                        <label for="batchMedicine">Medicine</label>
                        <select id="batchMedicine" required>
                            <option value="">Select medicine&hellip;</option>
                            <?php foreach ($medicines as $med): ?>
                                <option value="<?php echo htmlspecialchars($med); ?>"><?php echo htmlspecialchars($med); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="dr-field dr-field-spaced">
                        <label for="batchNo">Batch Number</label>
                        <input type="text" id="batchNo" placeholder="e.g. BN-031-2026" required>
                    </div>
                    <div class="dr-field dr-field-spaced">
                        <label for="batchQuantity">Quantity</label>
                        <input type="number" id="batchQuantity" min="1" step="1" required>
                    </div>
                    <div class="dr-field dr-field-spaced">
                        <label for="batchExpiry">Expiry Date</label>
                        <input type="date" id="batchExpiry" required>
                    </div>
                    <p class="resubmit-admin-note" id="batchPreview"></p>
                </div>
                <div class="dr-modal-actions">
                    <button type="button" class="btn btn-secondary" data-close-modal="addBatchModal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Batch</button>
                </div>
            </form>
        </div>
    </div>

    <div class="scan-toast-host" id="expiryToastHost"></div>

    <script>
        // Mirrors $batchesByMedicine from PHP above — already sorted
        // soonest-to-expire first per medicine, i.e. already in FEFO
        // order. Used only by the Simulate a Dispense tool below;
        // nothing here writes back to this data or to the database.
        const BATCH_DATA = <?php echo json_encode($batchesByMedicine); ?>;

        const WARNING_DAYS = <?php echo EXPIRY_WARNING_DAYS; ?>;
        const CRITICAL_DAYS = <?php echo EXPIRY_CRITICAL_DAYS; ?>;
        const STATUS_LABELS = {
            expired: 'Expired',
            critical: 'Critical',
            warning: 'Warning',
            safe: 'Safe'
        };

        function daysRemaining(expiryStr) {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const expiry = new Date(expiryStr + 'T00:00:00');
            return Math.round((expiry - today) / 86400000);
        }

        function statusFor(days) {
            if (days < 0) return 'expired';
            if (days <= CRITICAL_DAYS) return 'critical';
            if (days <= WARNING_DAYS) return 'warning';
            return 'safe';
        }

        function formatDaysLabel(days) {
            return days < 0 ? `${Math.abs(days)} days ago` : `${days} days`;
        }

        function formatDateLabel(expiryStr) {
            const d = new Date(expiryStr + 'T00:00:00');
            return d.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric'
            });
        }

        function refreshCounts() {
            const counts = {
                expired: 0,
                critical: 0,
                warning: 0,
                safe: 0
            };
            document.querySelectorAll('#batchTableBody tr').forEach(row => {
                counts[row.dataset.status] = (counts[row.dataset.status] || 0) + 1;
            });
            document.getElementById('countExpired').textContent = counts.expired;
            document.getElementById('countCritical').textContent = counts.critical;
            document.getElementById('countWarning').textContent = counts.warning;
            document.getElementById('countSafe').textContent = counts.safe;
        }

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

        // ---------- Add Batch modal ----------
        const addBatchModal = document.getElementById('addBatchModal');
        const addBatchForm = document.getElementById('addBatchForm');
        const batchExpiryInput = document.getElementById('batchExpiry');
        const batchPreview = document.getElementById('batchPreview');

        document.getElementById('openAddBatchBtn').addEventListener('click', () => {
            addBatchForm.reset();
            batchPreview.textContent = '';
            addBatchModal.classList.add('active');
            document.body.classList.add('modal-open');
        });

        document.querySelectorAll('[data-close-modal="addBatchModal"]').forEach(btn => {
            btn.addEventListener('click', closeAddBatchModal);
        });
        addBatchModal.addEventListener('click', (e) => {
            if (e.target === addBatchModal) closeAddBatchModal();
        });

        function closeAddBatchModal() {
            addBatchModal.classList.remove('active');
            document.body.classList.remove('modal-open');
        }

        // Live preview of what this batch's risk band would be as soon as
        // a date is picked — this is the "automatic determination" bit.
        batchExpiryInput.addEventListener('change', () => {
            if (!batchExpiryInput.value) {
                batchPreview.textContent = '';
                return;
            }
            const days = daysRemaining(batchExpiryInput.value);
            const status = statusFor(days);
            batchPreview.textContent = `This batch would be logged as ${STATUS_LABELS[status]} (${formatDaysLabel(days)}).`;
        });

        addBatchForm.addEventListener('submit', (e) => {
            e.preventDefault();

            const medicine = document.getElementById('batchMedicine').value;
            const batchNo = document.getElementById('batchNo').value.trim();
            const quantity = document.getElementById('batchQuantity').value;
            const expiry = batchExpiryInput.value;
            if (!medicine || !batchNo || !quantity || !expiry) return;

            const days = daysRemaining(expiry);
            const status = statusFor(days);

            const row = document.createElement('tr');
            row.dataset.medicine = medicine.toLowerCase();
            row.dataset.batch = batchNo.toLowerCase();
            row.dataset.status = status;
            row.innerHTML = `
                <td>${escapeHtml(medicine)}</td>
                <td>${escapeHtml(batchNo)}</td>
                <td>${escapeHtml(quantity)}</td>
                <td>${formatDateLabel(expiry)}</td>
                <td>${formatDaysLabel(days)}</td>
                <td><span class="status-pill status-expiry-${status}">${STATUS_LABELS[status]}</span></td>
            `;
            document.getElementById('batchTableBody').prepend(row);

            refreshCounts();
            applyFilters();
            closeAddBatchModal();
            showExpiryToast(`\u2713 Batch ${batchNo} added to tracking (UI preview \u2014 not saved yet)`);
        });

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        function showExpiryToast(message) {
            const host = document.getElementById('expiryToastHost');
            const toast = document.createElement('div');
            toast.className = 'scan-toast';
            toast.textContent = message;
            host.appendChild(toast);
            requestAnimationFrame(() => toast.classList.add('scan-toast-show'));
            setTimeout(() => {
                toast.classList.remove('scan-toast-show');
                setTimeout(() => toast.remove(), 250);
            }, 2400);
        }

        // ---------- Simulate a Dispense (FEFO) ----------
        document.getElementById('fefoSimulateBtn').addEventListener('click', () => {
            const medicine = document.getElementById('fefoMedicineSelect').value;
            const quantity = parseInt(document.getElementById('fefoQuantityInput').value, 10);
            const resultEl = document.getElementById('fefoResult');

            if (!medicine) {
                resultEl.innerHTML = '<p class="resubmit-admin-note">Select a medicine first.</p>';
                return;
            }
            if (!quantity || quantity <= 0) {
                resultEl.innerHTML = '<p class="resubmit-admin-note">Enter a quantity greater than 0.</p>';
                return;
            }

            const group = BATCH_DATA[medicine] || [];
            let remaining = quantity;
            const draws = [];

            // Already in FEFO order (soonest-expiry first) — just walk it.
            for (const batch of group) {
                if (remaining <= 0) break;
                const drawAmount = Math.min(remaining, batch.quantity);
                if (drawAmount <= 0) continue;
                draws.push({
                    batch,
                    drawAmount,
                    leftInBatch: batch.quantity - drawAmount
                });
                remaining -= drawAmount;
            }

            let html = '<div class="fefo-groups"><div class="fefo-group">';
            html += `<h3 class="fefo-group-title">${escapeHtml(medicine)} \u2014 dispensing ${quantity}</h3>`;
            html += '<div class="fefo-batch-list">';

            if (draws.length === 0) {
                html += '<p class="resubmit-admin-note">No batches on hand for this medicine.</p>';
            } else {
                draws.forEach((d, i) => {
                    html += '<div class="fefo-batch-row">' +
                        `<span class="fefo-rank">${i === 0 ? 'Draw First' : 'Draw Next'}</span>` +
                        `<span class="fefo-batch-no">${escapeHtml(d.batch.batch)}</span>` +
                        `<span class="fefo-batch-qty">Draw ${d.drawAmount} \u2014 ${d.leftInBatch} left</span>` +
                        `<span class="fefo-batch-expiry">Expires ${formatDateLabel(d.batch.expiry)}</span>` +
                        '</div>';
                });
            }

            if (remaining > 0) {
                html += `<p class="resubmit-admin-note">Short by ${remaining} \u2014 not enough stock across all batches to fully cover this quantity.</p>`;
            }

            html += '</div></div></div>';
            resultEl.innerHTML = html;
        });
    </script>
</body>

</html>