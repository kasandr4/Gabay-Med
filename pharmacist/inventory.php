<?php
// pharmacist/inventory.php
// Module 3.5 — Inventory View.
//
// Read-only by design — per 3.5, stock only ever changes through an actual
// action (Dispense, Storage Exit Scan, Delivery Receiving), never a manual
// override field here, to preserve the audit trail.
//
// Supports ?filter= so the Dashboard's alert banner (inventory.php?filter=flagged)
// and future links can land here pre-filtered instead of on the full list.
//
// REAL BACKEND (2026-07-17): "Request Restock" is now actually wired, not
// mocked — per the segregation-of-duties decision (Pharmacist raises the
// request, Admin approves it), this POSTs into the SAME purchase_requests
// table admin/inventory-procurement.php already reads from, via the same
// admin/inventory-actions.php?action=create_request endpoint Admin uses.
// require_role() (includes/auth_guard.php) now accepts an array of roles,
// and that endpoint's role check was loosened to let 'pharmacist' reach
// create_request specifically — every other action there stays admin-only.
// Because of the medicine_id foreign key, the medicine list below is a
// real query now too (see note further down) — everything else on this
// page (expiry, status derivation) is still mocked, same as before.

require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/csrf.php';

// REAL QUERY (2026-07-17): unlike the rest of this page, the medicine list
// itself has to be real now — Request Restock submits into
// purchase_requests.medicine_id, which has a strict FK to
// inventory_medicines.medicine_id. Using made-up mock IDs here would
// either violate that constraint or silently attach a request to the
// wrong medicine.
$medicines = [];
$result = $conn->query("SELECT medicine_id, name, category, unit, current_stock, minimum_stock FROM inventory_medicines ORDER BY name ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $medicines[] = [
            "id"        => (int) $row['medicine_id'],
            "name"      => $row['name'],
            "stock"     => (int) $row['current_stock'],
            "threshold" => (int) $row['minimum_stock'],
        ];
    }
}

// TODO(backend): the real expiry_date column doesn't exist on
// inventory_medicines yet (flagged back when Inventory View was first
// built) — still mocked here, keyed by the real medicine_id above, purely
// so Near Expiry / Expired have something to demo against.
$mockExpiryByMedicineId = [
    1 => "2027-03-15", // Amoxicillin 500mg
    2 => "2026-11-02", // Paracetamol 500mg
    3 => "2026-08-05", // Losartan 50mg
    4 => "2027-02-01", // Cetirizine 10mg
    5 => "2026-07-25", // Insulin Regular (Humulin R) — near expiry
    6 => "2026-06-30", // Salbutamol Nebule 2.5mg — already expired
];
foreach ($medicines as &$m) {
    $m['expiry'] = $mockExpiryByMedicineId[$m['id']] ?? date('Y-m-d', strtotime('+1 year'));
}
unset($m);

// UI-ONLY (2026-07-18): Batch No. has no backing column yet either (same
// gap as expiry above) — derived deterministically from the real
// medicine_id purely so the new column has something stable to show.
foreach ($medicines as &$m) {
    $m['batch_no'] = 'BN-' . str_pad((string) $m['id'], 3, '0', STR_PAD_LEFT) . '-' . date('Y', strtotime($m['expiry']));
}
unset($m);

// Status derivation, kept in PHP here since this is UI-only — the real
// version of this is a live query, not stored per-row (see admin/dashboard.php's
// low-stock card, which reads the same underlying medicines table).
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

$current_page = 'inventory';

// REAL QUERY (2026-07-17): now that Request Restock actually submits,
// showing only client-side-simulated rows here would look broken on
// reload — this pulls this pharmacist's own submitted requests back out
// of the same purchase_requests table admin/inventory-procurement.php
// reads from.
$myRequests = [];
$stmt = $conn->prepare(
    "SELECT pr.request_id, pr.requested_quantity, pr.priority, pr.status, pr.created_at,
            pr.reason, pr.notes, pr.revision_note, im.name AS medicine_name
     FROM purchase_requests pr
     JOIN inventory_medicines im ON im.medicine_id = pr.medicine_id
     WHERE pr.requested_by = ?
     ORDER BY pr.created_at DESC
     LIMIT 10"
);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$myRequests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Same labels as admin/inventory-procurement.php's statusLabel(), kept in
// sync manually since this page doesn't share a PHP include with admin/.
$requestStatusLabels = [
    'pending'             => 'Pending Approval',
    'approved'            => 'Approved',
    'revision_requested'  => 'Revision Requested',
    'rejected'            => 'Rejected',
    'purchase_ordered'    => 'Purchase Ordered',
    'completed'           => 'Completed',
];

// UI-ONLY (2026-07-18): summary cards, counted from the same $medicines /
// $myRequests arrays already loaded above — no extra queries. "Critical"
// groups Out of Stock + Expired, since those are the two statuses that
// actually block dispensing right now (Near Expiry is a warning, not yet
// critical).
$totalMedicinesCount = count($medicines);
$lowStockSummaryCount = count(array_filter($medicines, fn($m) => $m['status'] === 'low_stock'));
$criticalSummaryCount = count(array_filter($medicines, fn($m) => in_array($m['status'], ['out_of_stock', 'expired'], true)));
$pendingRestockCount = count(array_filter($myRequests, fn($r) => $r['status'] === 'pending'));

// UI-ONLY (2026-07-18): "Recent Stock Activity" side panel — sample
// entries only, per the design brief. TODO(backend): once a unified
// stock-movement log exists, replace with a real query combining
// purchase_orders (delivered), medicine_exit_log, and any expiry
// write-offs, most recent first.
$mockStockActivity = [
    ["type" => "delivery",  "medicine" => "Amoxicillin 500mg",        "detail" => "Delivery received from MedSupply Corp", "qty" => "+200", "time" => "Today, 9:10 AM"],
    ["type" => "dispensed", "medicine" => "Paracetamol 500mg",        "detail" => "Dispensed via Storage Exit Scan",       "qty" => "-30",  "time" => "Today, 10:45 AM"],
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
                    <p class="page-subtitle">Current stock levels &mdash; read-only. Stock changes only through Dispense, Exit Scan, or Delivery Receiving.</p>
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
                        <span class="stat-label">Total Medicines</span>
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
                <div class="stat-card stat-blue">
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
                        <span class="stat-value"><?php echo $pendingRestockCount; ?></span>
                        <span class="stat-label">Pending Restock Requests</span>
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

                <?php if (empty($visibleMedicines)): ?>
                    <div class="empty-state">
                        <div class="empty-illustration">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 8v13H3V8"></path>
                                <path d="M1 3h22v5H1z"></path>
                                <path d="M10 12h4"></path>
                            </svg>
                        </div>
                        <p>No medicines match this filter</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="queue-table sortable-table" id="inventoryTable">
                            <thead>
                                <tr>
                                    <th class="sortable" data-sort="name" data-type="text">Medicine <span class="sort-arrow"></span></th>
                                    <th>Batch No.</th>
                                    <th class="sortable" data-sort="stock" data-type="number">Stock Quantity <span class="sort-arrow"></span></th>
                                    <th class="sortable" data-sort="expiry" data-type="date">Expiry Date <span class="sort-arrow"></span></th>
                                    <th class="sortable" data-sort="status" data-type="text">Status <span class="sort-arrow"></span></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($visibleMedicines as $m): ?>
                                    <tr
                                        data-name="<?php echo htmlspecialchars(strtolower($m['name'])); ?>"
                                        data-stock="<?php echo $m['stock']; ?>"
                                        data-expiry="<?php echo $m['expiry']; ?>"
                                        data-status="<?php echo $statusLabels[$m['status']]; ?>">
                                        <td><?php echo htmlspecialchars($m['name']); ?></td>
                                        <td><span class="batch-no"><?php echo htmlspecialchars($m['batch_no']); ?></span></td>
                                        <td><?php echo $m['stock']; ?> <?php echo $m['stock'] === 0 ? '<span class="stock-zero-tag">0</span>' : ''; ?></td>
                                        <td><?php echo htmlspecialchars((new DateTime($m['expiry']))->format('M j, Y')); ?></td>
                                        <td>
                                            <span class="status-pill status-<?php echo str_replace('_', '-', $m['status']); ?>">
                                                <?php echo htmlspecialchars($statusLabels[$m['status']]); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button
                                                type="button"
                                                class="btn btn-secondary btn-sm"
                                                data-open-restock
                                                data-medicine-id="<?php echo (int) $m['id']; ?>"
                                                data-medicine-name="<?php echo htmlspecialchars($m['name']); ?>"
                                                data-current-stock="<?php echo (int) $m['stock']; ?>">
                                                Request Restock
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Requests this pharmacist has submitted, awaiting Admin's
                 Approve/Reject/Revise decision on admin/inventory-procurement.php.
                 TODO(backend): SELECT * FROM purchase_requests
                 WHERE requested_by = ? ORDER BY created_at DESC LIMIT 10 -->
            <section class="card">
                <div class="card-header">
                    <h2>My Restock Requests</h2>
                    <span class="card-subtitle">Submitted requests, pending Admin approval</span>
                </div>
                <div id="myRequestsEmpty" class="empty-state" <?php echo empty($myRequests) ? '' : 'hidden'; ?>>
                    <p>No restock requests submitted yet</p>
                </div>
                <div class="table-wrap" id="myRequestsTableWrap" <?php echo empty($myRequests) ? 'hidden' : ''; ?>>
                    <table class="queue-table">
                        <thead>
                            <tr>
                                <th>Medicine</th>
                                <th>Requested Qty</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Submitted</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="myRequestsBody">
                            <?php foreach ($myRequests as $r): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($r['medicine_name']); ?></td>
                                    <td><?php echo (int) $r['requested_quantity']; ?></td>
                                    <td><span class="priority-badge priority-<?php echo htmlspecialchars($r['priority']); ?>"><?php echo htmlspecialchars(ucfirst($r['priority'])); ?></span></td>
                                    <td>
                                        <span class="status-pill status-pr-<?php echo htmlspecialchars($r['status']); ?>"><?php echo htmlspecialchars($requestStatusLabels[$r['status']] ?? ucfirst($r['status'])); ?></span>
                                        <?php if ($r['status'] === 'revision_requested' && !empty($r['revision_note'])): ?>
                                            <div class="restock-revision-note"><strong>Admin:</strong> <?php echo htmlspecialchars($r['revision_note']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($r['created_at']))); ?></td>
                                    <td>
                                        <?php if ($r['status'] === 'revision_requested'): ?>
                                            <button
                                                type="button"
                                                class="btn btn-secondary btn-sm"
                                                data-open-resubmit
                                                data-request-id="<?php echo (int) $r['request_id']; ?>"
                                                data-medicine-name="<?php echo htmlspecialchars($r['medicine_name']); ?>"
                                                data-quantity="<?php echo (int) $r['requested_quantity']; ?>"
                                                data-priority="<?php echo htmlspecialchars($r['priority']); ?>"
                                                data-reason="<?php echo htmlspecialchars($r['reason'] ?? ''); ?>"
                                                data-notes="<?php echo htmlspecialchars($r['notes'] ?? ''); ?>">
                                                Edit &amp; Resubmit
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <!-- Request Restock modal — same fields as admin's "New Purchase Request"
         modal (requested_quantity, priority, reason, notes), since this
         submits into the same purchase_requests table. -->
    <div class="modal-backdrop" id="restockModal" aria-hidden="true">
        <div class="modal-box dr-modal-box" role="dialog" aria-modal="true" aria-labelledby="restockModalTitle">
            <div class="dr-modal-header">
                <h3 id="restockModalTitle">Request Restock</h3>
                <button type="button" class="dr-modal-close" data-close-modal="restockModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>

            <form id="restockForm">
                <?= csrf_field() ?>
                <div class="dr-modal-body">
                    <div class="modal-section-label">Medicine</div>
                    <div class="dr-po-summary">
                        <div class="summary-item">
                            <span class="summary-label">Medicine</span>
                            <span class="summary-value" id="restockMedicineName">&mdash;</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Current Stock</span>
                            <span class="summary-value" id="restockCurrentStock">&mdash;</span>
                        </div>
                    </div>

                    <div class="modal-section-label">Request Details</div>

                    <div class="dr-field">
                        <label for="restockQuantity">Suggested / Requested Quantity</label>
                        <input type="number" id="restockQuantity" min="1" required>
                    </div>

                    <div class="dr-field dr-field-spaced">
                        <label for="restockPriority">Priority</label>
                        <select id="restockPriority">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>

                    <div class="dr-field dr-field-spaced">
                        <label for="restockReason">Reason for Request</label>
                        <textarea id="restockReason" rows="3" required placeholder="Why does this need to be restocked?"></textarea>
                    </div>

                    <div class="dr-field dr-field-spaced">
                        <label for="restockNotes">Additional Notes (optional)</label>
                        <textarea id="restockNotes" rows="2" placeholder="Anything else the reviewer should know"></textarea>
                    </div>

                    <p class="dr-form-error" id="restockError" hidden></p>
                </div>

                <div class="dr-modal-actions">
                    <button type="button" class="btn btn-secondary" data-close-modal="restockModal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit Request</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit & Resubmit modal — shown only for the pharmacist's own
         requests currently in "Revision Requested". Submits into the same
         purchase_requests row via admin/inventory-actions.php's
         resubmit_request action, which is now Pharmacist-only and
         ownership-checked (requested_by = the logged-in pharmacist). -->
    <div class="modal-backdrop" id="resubmitModal" aria-hidden="true">
        <div class="modal-box dr-modal-box" role="dialog" aria-modal="true" aria-labelledby="resubmitModalTitle">
            <div class="dr-modal-header">
                <h3 id="resubmitModalTitle">Edit &amp; Resubmit Request</h3>
                <button type="button" class="dr-modal-close" data-close-modal="resubmitModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>

            <form id="resubmitForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="resubmit_request">
                <input type="hidden" name="request_id" id="resubmitRequestId">
                <div class="dr-modal-body">
                    <div class="modal-section-label">Medicine</div>
                    <div class="dr-po-summary">
                        <div class="summary-item">
                            <span class="summary-label">Medicine</span>
                            <span class="summary-value" id="resubmitMedicineName">&mdash;</span>
                        </div>
                    </div>

                    <p class="resubmit-admin-note" id="resubmitAdminNote"></p>

                    <div class="modal-section-label">Request Details</div>

                    <div class="dr-field">
                        <label for="resubmitQuantity">Requested Quantity</label>
                        <input type="number" name="requested_quantity" id="resubmitQuantity" min="1" required>
                    </div>

                    <div class="dr-field dr-field-spaced">
                        <label for="resubmitPriority">Priority</label>
                        <select name="priority" id="resubmitPriority">
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>

                    <div class="dr-field dr-field-spaced">
                        <label for="resubmitReason">Reason for Request</label>
                        <textarea name="reason" id="resubmitReason" rows="3" required placeholder="Why does this need to be restocked?"></textarea>
                    </div>

                    <div class="dr-field dr-field-spaced">
                        <label for="resubmitNotes">Additional Notes (optional)</label>
                        <textarea name="notes" id="resubmitNotes" rows="2" placeholder="Anything else the reviewer should know"></textarea>
                    </div>

                    <p class="dr-form-error" id="resubmitError" hidden></p>
                </div>

                <div class="dr-modal-actions">
                    <button type="button" class="btn btn-secondary" data-close-modal="resubmitModal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Resubmit Request</button>
                </div>
            </form>
        </div>
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

    <div class="scan-toast-host" id="restockToastHost"></div>

    <script>
        // Client-side search + column sort — this page is read-only, all
        // the data is already on the page, so no reload is needed for
        // either. Filtering by status uses the ?filter= links above
        // instead (server-rendered, so those stay simple, shareable URLs).
        const searchInput = document.getElementById('inventorySearch');
        const table = document.getElementById('inventoryTable');
        const tbody = table ? table.querySelector('tbody') : null;

        if (searchInput && tbody) {
            searchInput.addEventListener('input', function() {
                const term = searchInput.value.trim().toLowerCase();
                tbody.querySelectorAll('tr').forEach(function(row) {
                    row.style.display = row.dataset.name.includes(term) ? '' : 'none';
                });
            });
        }

        if (table && tbody) {
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
        }

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

        // ---------- Request Restock ----------
        const restockModal = document.getElementById('restockModal');
        const restockForm = document.getElementById('restockForm');
        const myRequestsEmpty = document.getElementById('myRequestsEmpty');
        const myRequestsTableWrap = document.getElementById('myRequestsTableWrap');
        const myRequestsBody = document.getElementById('myRequestsBody');
        let activeMedicine = null;

        document.querySelectorAll('[data-open-restock]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                activeMedicine = {
                    id: btn.dataset.medicineId,
                    name: btn.dataset.medicineName,
                    stock: btn.dataset.currentStock,
                };
                document.getElementById('restockMedicineName').textContent = activeMedicine.name;
                document.getElementById('restockCurrentStock').textContent = activeMedicine.stock;
                restockForm.reset();
                restockModal.classList.add('active');
                document.body.classList.add('modal-open');
            });
        });

        document.querySelectorAll('[data-close-modal="restockModal"]').forEach(function(btn) {
            btn.addEventListener('click', closeRestockModal);
        });
        restockModal.addEventListener('click', function(e) {
            if (e.target === restockModal) closeRestockModal();
        });

        function closeRestockModal() {
            restockModal.classList.remove('active');
            document.body.classList.remove('modal-open');
        }

        // REAL SUBMIT (2026-07-17): POSTs to admin/inventory-actions.php
        // (action=create_request), the same endpoint and same
        // purchase_requests row admin/inventory-procurement.php's own
        // "New Purchase Request" modal creates. require_role() there now
        // accepts ['admin','pharmacist'], with a per-action check still
        // restricting every OTHER action to admin only.
        restockForm.addEventListener('submit', function(e) {
            e.preventDefault();
            if (!activeMedicine) return;

            const errorEl = document.getElementById('restockError');
            errorEl.hidden = true;

            const quantity = document.getElementById('restockQuantity').value;
            const priority = document.getElementById('restockPriority').value;
            const reason = document.getElementById('restockReason').value;
            const notes = document.getElementById('restockNotes').value;
            const csrfToken = restockForm.querySelector('[name="csrf_token"]').value;

            const submitBtn = restockForm.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting\u2026';

            const body = new URLSearchParams({
                action: 'create_request',
                csrf_token: csrfToken,
                medicine_id: activeMedicine.id,
                requested_quantity: quantity,
                priority: priority,
                reason: reason,
                notes: notes,
            });

            fetch('../admin/inventory-actions.php', {
                    method: 'POST',
                    body: body,
                })
                .then(res => res.json())
                .then(data => {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit Request';

                    if (!data.success) {
                        errorEl.textContent = data.error || 'Something went wrong. Please try again.';
                        errorEl.hidden = false;
                        return;
                    }

                    myRequestsEmpty.hidden = true;
                    myRequestsTableWrap.hidden = false;

                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${activeMedicine.name}</td>
                        <td>${quantity}</td>
                        <td><span class="priority-badge priority-${priority}">${priority.charAt(0).toUpperCase() + priority.slice(1)}</span></td>
                        <td><span class="status-pill status-pr-pending">Pending Approval</span></td>
                        <td>Just now</td>
                    `;
                    myRequestsBody.insertBefore(row, myRequestsBody.firstChild);

                    showRestockToast(`\u2713 Restock request submitted for ${activeMedicine.name} \u2014 awaiting Admin approval`);
                    closeRestockModal();
                })
                .catch(() => {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit Request';
                    errorEl.textContent = 'Could not reach the server. Please check your connection and try again.';
                    errorEl.hidden = false;
                });
        });

        // ---------- Edit & Resubmit (after Admin requests a revision) ----------
        const resubmitModal = document.getElementById('resubmitModal');
        const resubmitForm = document.getElementById('resubmitForm');

        document.querySelectorAll('[data-open-resubmit]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.getElementById('resubmitRequestId').value = btn.dataset.requestId;
                document.getElementById('resubmitMedicineName').textContent = btn.dataset.medicineName;
                document.getElementById('resubmitQuantity').value = btn.dataset.quantity;
                document.getElementById('resubmitPriority').value = btn.dataset.priority;
                document.getElementById('resubmitReason').value = btn.dataset.reason || '';
                document.getElementById('resubmitNotes').value = btn.dataset.notes || '';

                const noteRow = btn.closest('tr').querySelector('.restock-revision-note');
                const adminNoteEl = document.getElementById('resubmitAdminNote');
                adminNoteEl.textContent = noteRow ? noteRow.textContent : '';

                document.getElementById('resubmitError').hidden = true;
                resubmitModal.classList.add('active');
                document.body.classList.add('modal-open');
            });
        });

        if (resubmitModal) {
            document.querySelectorAll('[data-close-modal="resubmitModal"]').forEach(function(btn) {
                btn.addEventListener('click', closeResubmitModal);
            });
            resubmitModal.addEventListener('click', function(e) {
                if (e.target === resubmitModal) closeResubmitModal();
            });
        }

        function closeResubmitModal() {
            resubmitModal.classList.remove('active');
            document.body.classList.remove('modal-open');
        }

        resubmitForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const errorEl = document.getElementById('resubmitError');
            errorEl.hidden = true;

            const submitBtn = resubmitForm.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Resubmitting\u2026';

            const body = new URLSearchParams(new FormData(resubmitForm));

            fetch('../admin/inventory-actions.php', {
                    method: 'POST',
                    body: body,
                })
                .then(res => res.json())
                .then(data => {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Resubmit Request';

                    if (!data.success) {
                        errorEl.textContent = data.error || 'Something went wrong. Please try again.';
                        errorEl.hidden = false;
                        return;
                    }

                    showRestockToast('\u2713 Request resubmitted \u2014 awaiting Admin approval');
                    closeResubmitModal();
                    setTimeout(() => window.location.reload(), 700);
                })
                .catch(() => {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Resubmit Request';
                    errorEl.textContent = 'Could not reach the server. Please check your connection and try again.';
                    errorEl.hidden = false;
                });
        });

        function showRestockToast(message) {
            const host = document.getElementById('restockToastHost');
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
    </script>
</body>

</html>