<?php
// pharmacist/inventory-count.php
// Module: Inventory Count — replaces the old single-medicine
// Reconciliation Entry (see 012_inventory_count.sql). Scoped from the
// OMCDH pharmacy's proposed blind-count workflow, mapped onto GabayMed's
// existing roles (per your call):
//
//   Admin      -> adds Staff accounts (already possible today via
//                 admin/user-management.php — nothing new needed there).
//   Pharmacist -> assigns a batch of medicines to a Staff member to
//                 physically count (this page), then reviews the
//                 submission and runs Final Confirmation once it's in.
//   Staff      -> opens their assignment and counts blind (see
//                 staff/inventory-count.php) — system_count is simply
//                 never sent to that page's response at all.
//
// SIMPLIFIED vs. the OMCDH mockup's 14-screen version: "Review & Recount"
// and "Final Confirmation" are one step here (the confirmBatch modal
// below), not two separate pages — once a batch is submitted, the
// comparison table and the editable Final Count/Remarks fields are the
// same screen. No separate Reports & Export module either; Export PDF
// on this page (and admin/inventory-count.php) covers that with the same
// print-window mechanism already used by pharmacist/reports.php elsewhere
// in this app. And no MAIP/PHILHEALTH/PHO/Purchase-Order funding-source
// categories — inventory_medicines has no such column.
//
// SCOPE: this page shows batches THIS pharmacist assigned ("My Assigned
// Counts"), same convention as pharmacist/inventory.php's "My Restock
// Requests" — not a global view across every pharmacist. Admin's
// inventory-count.php is the cross-pharmacist oversight view (read-only,
// same segregation-of-duties pattern already used for Delivery
// Receiving/Procurement).

require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/csrf.php';

$pharmacistId = (int) $_SESSION['user_id'];

// Staff accounts to assign a batch to. Filtered to staff_type='inventory'
// only (see 013_staff_subtype.sql) — front-desk staff share the 'staff'
// role but have no counting workflow to be assigned into.
$staffUsers = [];
$result = $conn->query(
    "SELECT user_id, first_name, last_name FROM users
     WHERE role = 'staff' AND staff_type = 'inventory' AND is_active = 1 AND archived_at IS NULL
     ORDER BY first_name ASC"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $staffUsers[] = [
            "id"   => (int) $row['user_id'],
            "name" => trim($row['first_name'] . ' ' . $row['last_name']),
        ];
    }
}

// Medicine picklist for the Assign modal. Category/equipment exclusion
// was retired — the pharmacy only stocks medicine (no equipment or
// supplies in the real inventory), so there's nothing left to filter.
$medicines = [];
$result = $conn->query(
    "SELECT im.medicine_id, im.name, im.unit, im.current_stock
     FROM inventory_medicines im
     ORDER BY im.name ASC"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $medicines[] = [
            "id"       => (int) $row['medicine_id'],
            "name"     => $row['name'],
            "unit"     => $row['unit'],
            "stock"    => (int) $row['current_stock'],
        ];
    }
}

// This pharmacist's assigned batches, with item/count-progress rollups.
$batches = [];
$result = $conn->prepare(
    "SELECT icb.batch_id, icb.batch_number, icb.status, icb.assigned_at, icb.due_date,
            icb.submitted_at, icb.confirmed_at, icb.notes,
            CONCAT(su.first_name, ' ', su.last_name) AS staff_name,
            COUNT(ici.item_id) AS total_items,
            SUM(CASE WHEN ici.staff_count IS NOT NULL THEN 1 ELSE 0 END) AS counted_items
     FROM inventory_count_batches icb
     JOIN users su ON su.user_id = icb.assigned_to
     LEFT JOIN inventory_count_items ici ON ici.batch_id = icb.batch_id
     WHERE icb.assigned_by = ?
     GROUP BY icb.batch_id
     ORDER BY icb.assigned_at DESC"
);
$result->bind_param("i", $pharmacistId);
$result->execute();
$rows = $result->get_result();
while ($row = $rows->fetch_assoc()) {
    // A batch has no dedicated "recount" flag or timestamp in the schema
    // (see 012_inventory_count.sql) — request_recount in
    // inventory-count-actions.php reverts status to 'pending' and appends
    // a plain-text "Recount requested: N item(s)..." marker onto the same
    // notes field used for the pharmacist's original shelf/location
    // instructions. Detecting it here the same way, rather than adding a
    // new column, since that's a schema change that needs its own
    // confirmation and isn't needed just to surface this on the dashboard.
    $notes = (string) ($row['notes'] ?? '');
    $isRecount = stripos($notes, 'Recount requested:') !== false;
    $recountItemCount = null;
    if ($isRecount && preg_match('/Recount requested:\s*(\d+)\s*item/i', $notes, $m)) {
        $recountItemCount = (int) $m[1];
    }

    $batches[] = [
        "id"                => (int) $row['batch_id'],
        "number"            => $row['batch_number'],
        "status"            => $row['status'],
        "staff_name"        => $row['staff_name'],
        "assigned_at"       => date('M j, Y', strtotime($row['assigned_at'])),
        "due_date"          => $row['due_date'] ? date('M j, Y', strtotime($row['due_date'])) : '—',
        "submitted_at"      => $row['submitted_at'] ? date('M j, Y g:i A', strtotime($row['submitted_at'])) : null,
        "confirmed_at"      => $row['confirmed_at'] ? date('M j, Y g:i A', strtotime($row['confirmed_at'])) : null,
        "notes"             => $row['notes'],
        "total_items"       => (int) $row['total_items'],
        "counted_items"     => (int) $row['counted_items'],
        "is_recount"        => $isRecount,
        "recount_item_count" => $recountItemCount,
    ];
}
$result->close();

// Recount Requested: pending batches sent back to staff for a partial
// recount, rather than pending batches that simply haven't been counted
// yet at all. Distinguishing the two matters for the pharmacist — a fresh
// assignment waiting on staff is normal; a recount sitting untouched is a
// batch that already failed review once and needs following up on.
$recountBatches = array_values(array_filter($batches, fn($b) => $b['status'] === 'pending' && $b['is_recount']));

$stats = ['pending' => 0, 'submitted' => 0, 'confirmed' => 0, 'recount_requested' => 0];
foreach ($batches as $b) {
    if (isset($stats[$b['status']])) $stats[$b['status']]++;
}
$stats['recount_requested'] = count($recountBatches);

$statusLabels = [
    'pending'   => 'Pending Count',
    'submitted' => 'Awaiting Review',
    'confirmed' => 'Confirmed',
];

// Nearly Expiring: same open-batch source and soonest-expiry ordering as
// expiry-tracking.php, limited to the next 90 days for this dashboard.
$nearlyExpiring = [];
$expiryResult = $conn->query(
    "SELECT im.name AS medicine, im.unit, mb.batch_id, mb.batch_no, mb.expiry_date,
            mb.units_remaining,
            DATEDIFF(mb.expiry_date, CURDATE()) AS days_remaining
     FROM medicine_batches mb
     JOIN inventory_medicines im ON im.medicine_id = mb.medicine_id
     WHERE mb.units_remaining > 0
       AND mb.expiry_date IS NOT NULL
       AND mb.expiry_date >= CURDATE()
       AND mb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
     ORDER BY mb.expiry_date ASC, mb.batch_id ASC
     LIMIT 5"
);
if ($expiryResult) {
    while ($row = $expiryResult->fetch_assoc()) {
        $nearlyExpiring[] = $row;
    }
}

$current_page = 'inventory-count';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Count - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/pharmacist-dashboard.css">
    <link rel="stylesheet" href="../assets/css/inventory-count.css">
</head>

<body>
    <div class="app-shell">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <header class="page-header">
                <div>
                    <h1>Inventory Count</h1>
                    <p class="page-subtitle">Assign a physical count to a staff member, then review and finalize what they counted.</p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary" type="button" id="openAssignBtn">+ Assign New Count</button>
                </div>
            </header>

            <section class="stats-grid">
                <div class="stat-card stat-amber">
                    <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <path d="M12 6v6l4 2"></path>
                        </svg></div>
                    <div class="stat-info"><span class="stat-value"><?php echo $stats['pending']; ?></span><span class="stat-label">Pending Count</span></div>
                </div>
                <div class="stat-card stat-blue">
                    <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                        </svg></div>
                    <div class="stat-info"><span class="stat-value"><?php echo $stats['submitted']; ?></span><span class="stat-label">Awaiting Review</span></div>
                </div>
                <div class="stat-card stat-teal">
                    <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 6L9 17l-5-5"></path>
                        </svg></div>
                    <div class="stat-info"><span class="stat-value"><?php echo $stats['confirmed']; ?></span><span class="stat-label">Confirmed</span></div>
                </div>
                <div class="stat-card stat-red">
                    <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M1 4v6h6"></path>
                            <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
                        </svg></div>
                    <div class="stat-info"><span class="stat-value"><?php echo $stats['recount_requested']; ?></span><span class="stat-label">Recounts Requested</span></div>
                </div>
            </section>

            <section class="card ic-expiry-panel">
                <div class="card-header">
                    <div>
                        <h2>Nearly Expiring</h2>
                        <span class="card-subtitle">Top 5 open batches expiring within 90 days</span>
                    </div>
                    <a class="btn btn-secondary btn-sm" href="expiry-tracking.php">View Expiry Tracking</a>
                </div>
                <?php if (empty($nearlyExpiring)): ?>
                    <div class="ic-expiry-empty">No open batches are expiring within the next 90 days.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table ic-expiry-table">
                            <thead>
                                <tr>
                                    <th>Medicine</th>
                                    <th>Batch</th>
                                    <th>Remaining</th>
                                    <th>Expiry</th>
                                    <th>Days Left</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($nearlyExpiring as $expiry): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($expiry['medicine']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($expiry['batch_no'] ?: 'Batch-' . $expiry['batch_id']); ?></td>
                                        <td><?php echo (int) $expiry['units_remaining'] . ' ' . htmlspecialchars($expiry['unit']); ?><?php echo (int) $expiry['units_remaining'] === 1 ? '' : 's'; ?></td>
                                        <td><?php echo htmlspecialchars(date('M j, Y', strtotime($expiry['expiry_date']))); ?></td>
                                        <td><span class="ic-expiry-days<?php echo (int) $expiry['days_remaining'] <= 7 ? ' is-critical' : ''; ?>"><?php echo (int) $expiry['days_remaining']; ?> days</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card ic-recount-panel">
                <div class="card-header">
                    <div>
                        <h2>Recounts Requested</h2>
                        <span class="card-subtitle">Batches sent back to staff for a partial recount, awaiting resubmission</span>
                    </div>
                </div>
                <?php if (empty($recountBatches)): ?>
                    <div class="ic-expiry-empty">No recounts have been requested.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Batch #</th>
                                    <th>Assigned To</th>
                                    <th>Items to Recount</th>
                                    <th>Assigned</th>
                                    <th>Due</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recountBatches as $rb): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($rb['number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($rb['staff_name']); ?></td>
                                        <td><?php echo $rb['recount_item_count'] !== null ? $rb['recount_item_count'] : '—'; ?></td>
                                        <td><?php echo $rb['assigned_at']; ?></td>
                                        <td><?php echo $rb['due_date']; ?></td>
                                        <td>
                                            <button type="button" class="btn btn-secondary btn-sm" data-view-batch="<?php echo $rb['id']; ?>">View</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card">
                <div class="card-header">
                    <h2>My Assigned Counts</h2>
                    <span class="card-subtitle"><?php echo count($batches); ?> batch<?php echo count($batches) === 1 ? '' : 'es'; ?></span>
                </div>

                <?php if (empty($batches)): ?>
                    <div class="empty-state">
                        <p>No count batches yet. Assign one to get started.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Batch #</th>
                                    <th>Assigned To</th>
                                    <th>Items</th>
                                    <th>Progress</th>
                                    <th>Assigned</th>
                                    <th>Due</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($batches as $b): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($b['number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($b['staff_name']); ?></td>
                                        <td><?php echo $b['total_items']; ?></td>
                                        <td><?php echo $b['counted_items']; ?> / <?php echo $b['total_items']; ?> counted</td>
                                        <td><?php echo $b['assigned_at']; ?></td>
                                        <td><?php echo $b['due_date']; ?></td>
                                        <td><span class="status-pill status-ic-<?php echo $b['status']; ?>"><?php echo $statusLabels[$b['status']]; ?></span></td>
                                        <td>
                                            <button type="button" class="btn btn-secondary btn-sm" data-view-batch="<?php echo $b['id']; ?>">
                                                <?php echo $b['status'] === 'submitted' ? 'Review' : 'View'; ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <!-- ===================== MODAL: Assign New Count ===================== -->
    <div class="modal-backdrop" id="assignModal" aria-hidden="true">
        <div class="modal-box ic-modal-box" role="dialog" aria-modal="true" aria-labelledby="assignModalTitle">
            <div class="ic-modal-header">
                <h3 id="assignModalTitle">Assign New Count</h3>
                <button type="button" class="ic-drawer-close" data-close-modal="assignModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <form id="assignForm" class="ic-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="assign_batch">

                <div class="ic-form-row">
                    <label class="ic-field">
                        <span>Assign To (Staff)</span>
                        <select name="assigned_to" required>
                            <option value="">Select staff member&hellip;</option>
                            <?php foreach ($staffUsers as $s): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="ic-field">
                        <span>Assignment Date</span>
                        <input type="date" name="assigned_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </label>
                </div>

                <label class="ic-field">
                    <span>Due Date (Optional)</span>
                    <input type="date" name="due_date">
                </label>

                <div class="ic-assignment-tabs" role="tablist" aria-label="Assignment method">
                    <button type="button" class="ic-assignment-tab" role="tab" aria-selected="false" data-assignment-mode="range">By Item Range</button>
                    <button type="button" class="ic-assignment-tab is-active" role="tab" aria-selected="true" data-assignment-mode="selection">By Selection</button>
                </div>

                <input type="hidden" name="assignment_mode" value="selection" id="assignmentMode">
                <div class="ic-assignment-panel" data-assignment-panel="range" hidden>
                    <div class="ic-form-row">
                        <label class="ic-field">
                            <span>From Item Number</span>
                            <input type="number" name="from_item" min="1" step="1" placeholder="e.g. 1">
                        </label>
                        <label class="ic-field">
                            <span>To Item Number</span>
                            <input type="number" name="to_item" min="1" step="1" placeholder="e.g. 100">
                        </label>
                    </div>
                    <p class="ic-field-hint">Item numbers refer to the medicine catalog IDs.</p>
                </div>

                <label class="ic-field">
                    <span>Remarks (Optional)</span>
                    <textarea name="notes" rows="2" placeholder="e.g. Shelf A, Main Pharmacy"></textarea>
                </label>

                <div class="ic-field ic-assignment-panel is-active" data-assignment-panel="selection">
                    <span>Select Medicines to Count</span>
                    <input type="text" id="medicineSearch" class="ic-medicine-search" placeholder="Search medicine name&hellip;">
                    <div class="ic-medicine-list" id="medicineList">
                        <?php foreach ($medicines as $m): ?>
                            <label class="ic-medicine-row" data-name="<?php echo htmlspecialchars(strtolower($m['name'] . ' ' . $m['id'])); ?>">
                                <input type="checkbox" name="medicine_ids[]" value="<?php echo $m['id']; ?>">
                                <span class="ic-medicine-name">#<?php echo $m['id']; ?> &middot; <?php echo htmlspecialchars($m['name']); ?></span>
                                <span class="ic-medicine-meta"><?php echo htmlspecialchars($m['unit']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <span class="ic-selected-count" id="selectedCount">0 medicines selected</span>
                </div>

                <p class="ic-form-error" id="assignError"></p>
                <div class="ic-modal-actions">
                    <button type="button" class="btn-secondary-sm" data-close-modal="assignModal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Assign Count</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===================== DRAWER: Batch Detail / Review & Confirm ===================== -->
    <div class="ic-drawer-backdrop" id="drawerBackdrop" aria-hidden="true">
        <aside class="ic-drawer" id="batchDrawer">
            <div class="ic-drawer-header">
                <h3 id="drawerTitle">Batch Details</h3>
                <button type="button" class="ic-drawer-close" id="drawerCloseBtn" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="ic-drawer-body" id="drawerBody">
                <!-- populated by JS -->
            </div>
        </aside>
    </div>

    <div id="icToastHost" class="scan-toast-host"></div>

    <script src="../assets/js/inventory-count.js"></script>
</body>

</html>