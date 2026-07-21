<?php
// admin/inventory-procurement.php
// Module: Inventory Procurement — full workflow (Purchase Request ->
// Approval -> Supplier Bidding -> Winning Supplier -> Purchase Order).
//
// ROLE NOTE (2026-07-19): segregation of duties is enforced end to end —
// creating a Purchase Request only happens on pharmacist/inventory.php's
// "Request Restock" (and its "Edit & Resubmit" after a revision request);
// this Admin page only Reviews / Approves / Rejects / Requests Revision /
// runs Supplier Bidding / creates the Purchase Order / monitors delivery.
// admin/inventory-actions.php enforces this server-side too (see the
// $pharmacistActions / $adminActions gate there), not just in this UI.
//
// LAYOUT NOTE: rather than always-visible Section 1/2/3 stacked on one
// page (which would show empty Bidding/PO sections for most requests),
// Supplier Bidding and the Purchase Order summary live inside the same
// right-side drawer as the Request Details, appearing once the request
// reaches the relevant stage (Approved -> bidding tab unlocks; winner
// picked -> PO card appears). This keeps the workflow scoped to the one
// request you're actually looking at instead of scrolling three separate
// global sections.

require_once '../includes/auth_guard.php';
require_role('admin');
require_once '../config/db.php';
require_once '../includes/csrf.php';

$adminFirstName = $_SESSION['first_name'] ?? 'Admin';
$today = date("F j, Y");
$current_page = 'inventory';

// ============================================================
// Dashboard stat cards
// ============================================================
$stats = ['pending' => 0, 'approved' => 0, 'active_bids' => 0, 'low_stock' => 0];

$r = $conn->query("SELECT COUNT(*) c FROM purchase_requests WHERE status = 'pending'");
$stats['pending'] = (int) $r->fetch_assoc()['c'];

$r = $conn->query("SELECT COUNT(*) c FROM purchase_requests WHERE status = 'approved'");
$stats['approved'] = (int) $r->fetch_assoc()['c'];

$r = $conn->query(
    "SELECT COUNT(*) c FROM supplier_bids sb
     JOIN purchase_requests pr ON pr.request_id = sb.request_id
     WHERE pr.status = 'approved' AND sb.is_winner = 0"
);
$stats['active_bids'] = (int) $r->fetch_assoc()['c'];

$r = $conn->query("SELECT COUNT(*) c FROM inventory_medicines WHERE current_stock <= minimum_stock");
$stats['low_stock'] = (int) $r->fetch_assoc()['c'];

// ============================================================
// Filters (GET params) for the Purchase Requests table
// ============================================================
$search   = trim($_GET['search'] ?? '');
$status   = $_GET['status'] ?? '';
$priority = $_GET['priority'] ?? '';
$category = $_GET['category'] ?? '';
$dateFrom = $_GET['date'] ?? '';
$sort     = $_GET['sort'] ?? 'newest';

// "purchase_ordered" is additive here (UI-facing filter/label option
// only). Existing rows already in the DB simply won't match it until a
// backend change actually starts writing that status once a PO is
// generated — this doesn't require or assume a schema change.
$allowedStatus   = ['pending', 'approved', 'revision_requested', 'rejected', 'purchase_ordered', 'completed'];
$allowedPriority = ['low', 'medium', 'high', 'critical'];

$where = [];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = "(m.name LIKE ? OR pr.request_id = ?)";
    $params[] = "%{$search}%";
    $params[] = is_numeric($search) ? (int) $search : 0;
    $types .= 'si';
}
if (in_array($status, $allowedStatus, true)) {
    $where[] = "pr.status = ?";
    $params[] = $status;
    $types .= 's';
}
if (in_array($priority, $allowedPriority, true)) {
    $where[] = "pr.priority = ?";
    $params[] = $priority;
    $types .= 's';
}
if ($category !== '') {
    $where[] = "m.category = ?";
    $params[] = $category;
    $types .= 's';
}
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = "DATE(pr.created_at) = ?";
    $params[] = $dateFrom;
    $types .= 's';
}

$orderBy = "pr.created_at DESC";
switch ($sort) {
    case 'oldest':
        $orderBy = "pr.created_at ASC";
        break;
    case 'priority':
        $orderBy = "FIELD(pr.priority, 'critical','high','medium','low'), pr.created_at DESC";
        break;
    case 'status':
        $orderBy = "FIELD(pr.status, 'pending','revision_requested','approved','completed','rejected'), pr.created_at DESC";
        break;
}

// current_stock/minimum_stock/unit added here (display-only — same
// inventory_medicines join that already existed, just selecting two more
// of its columns) so the Purchase Requests table can show Current Stock
// and Minimum Stock per the connected Pharmacy Inventory workflow.
$sql = "SELECT pr.request_id, pr.requested_quantity, pr.priority, pr.status, pr.created_at,
               m.name AS medicine_name, m.category, m.current_stock, m.minimum_stock, m.unit,
               u.first_name AS requester_first, u.last_name AS requester_last
        FROM purchase_requests pr
        JOIN inventory_medicines m ON m.medicine_id = pr.medicine_id
        JOIN users u ON u.user_id = pr.requested_by";
if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY {$orderBy}";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Category list for the filter dropdown
$categories = $conn->query("SELECT DISTINCT category FROM inventory_medicines ORDER BY category ASC")->fetch_all(MYSQLI_ASSOC);

// Medicine catalog for the "Create Purchase Request" modal dropdown
$medicines = $conn->query("SELECT medicine_id, name, category, unit, current_stock, minimum_stock FROM inventory_medicines ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

// ============================================================
// Hospital Inventory Alerts (formerly "Low Stock Alert")
// ============================================================
// Low Stock / Critical Stock below are real, same query/threshold logic
// as before (current_stock vs minimum_stock on inventory_medicines) —
// just split into the two tiers the alert type calls for.
//
// Near Expiry / Expired are UI-only placeholders: inventory_medicines
// has no expiry_date column yet, so there's nothing to query. These
// stay clearly marked and reuse real item names/categories from the
// existing catalog for a believable preview, the same way other pages
// in this codebase (e.g. hospital-census.php's diagnosis/timeline) show
// illustrative data ahead of a schema change.
// TODO(backend): once inventory_medicines gains an expiry_date column,
// replace $nearExpiryStock / $expiredStock with real queries, e.g.
//   WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
//   WHERE expiry_date < CURDATE()
$stockAlerts = $conn->query(
    "SELECT medicine_id, name, category, unit, current_stock, minimum_stock
     FROM inventory_medicines
     WHERE current_stock <= minimum_stock
     ORDER BY (current_stock / GREATEST(minimum_stock,1)) ASC"
)->fetch_all(MYSQLI_ASSOC);

$lowStock = [];
$criticalStock = [];
foreach ($stockAlerts as $med) {
    $ratio = $med['minimum_stock'] > 0 ? $med['current_stock'] / $med['minimum_stock'] : 0;
    if ($ratio <= 0.5) {
        $criticalStock[] = $med;
    } else {
        $lowStock[] = $med;
    }
}

$catalogSample = array_slice($medicines, 0, 2);
$nearExpiryStock = [];
$expiredStock = [];
if (count($catalogSample) > 0) {
    $nearExpiryStock[] = [
        'name' => $catalogSample[0]['name'],
        'category' => $catalogSample[0]['category'],
        'unit' => $catalogSample[0]['unit'],
        'expiry_date' => date('Y-m-d', strtotime('+18 days')),
    ];
}
if (count($catalogSample) > 1) {
    $expiredStock[] = [
        'name' => $catalogSample[1]['name'],
        'category' => $catalogSample[1]['category'],
        'unit' => $catalogSample[1]['unit'],
        'expiry_date' => date('Y-m-d', strtotime('-6 days')),
    ];
}

$totalAlerts = count($lowStock) + count($criticalStock) + count($nearExpiryStock) + count($expiredStock);

function priorityLabel($p)
{
    return ucfirst($p);
}
function statusLabel($s)
{
    $map = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'revision_requested' => 'Revision Requested',
        'rejected' => 'Rejected',
        'purchase_ordered' => 'Purchase Ordered',
        'completed' => 'Completed',
    ];
    return $map[$s] ?? ucfirst($s);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Procurement - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-dashboard.css">
    <link rel="stylesheet" href="../assets/css/inventory-procurement.css">
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">

            <header class="page-header">
                <div>
                    <h1>Inventory Procurement</h1>
                    <p class="page-subtitle">Connected to Pharmacy Inventory — review restock requests for medicines, medical supplies, and medical equipment, manage supplier bids, and track purchase orders.</p>
                </div>
                <div class="header-actions">
                    <span class="header-date"><?php echo htmlspecialchars($today); ?></span>
                </div>
            </header>

            <!-- Stat cards -->
            <section class="stats-grid" id="statsGrid">
                <div class="stat-card stat-amber" data-stat="pending">
                    <div class="stat-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                    </div>
                    <div class="stat-info">
                        <span class="stat-value" data-stat-value="pending"><?php echo $stats['pending']; ?></span>
                        <span class="stat-label">Pending Purchase Requests</span>
                    </div>
                </div>
                <div class="stat-card stat-teal" data-stat="approved">
                    <div class="stat-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                        </svg>
                    </div>
                    <div class="stat-info">
                        <span class="stat-value" data-stat-value="approved"><?php echo $stats['approved']; ?></span>
                        <span class="stat-label">Approved Requests</span>
                    </div>
                </div>
                <div class="stat-card stat-blue" data-stat="active_bids">
                    <div class="stat-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 20v-6M6 20V10M18 20V4"></path>
                        </svg>
                    </div>
                    <div class="stat-info">
                        <span class="stat-value" data-stat-value="active_bids"><?php echo $stats['active_bids']; ?></span>
                        <span class="stat-label">Active Supplier Bids</span>
                    </div>
                </div>
                <div class="stat-card stat-red" data-stat="low_stock">
                    <div class="stat-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                            <line x1="12" y1="9" x2="12" y2="13"></line>
                            <line x1="12" y1="17" x2="12.01" y2="17"></line>
                        </svg>
                    </div>
                    <div class="stat-info">
                        <!-- data-stat-value stays "low_stock" so the existing
                             inventory-actions.php stat patching (approve/reject/etc.)
                             keeps working without a backend change; it will patch in
                             the real low+critical stock count, which is the bulk of
                             this figure. The Near Expiry / Expired placeholders are
                             folded into the number on initial page load only. -->
                        <span class="stat-value" data-stat-value="low_stock"><?php echo $totalAlerts; ?></span>
                        <span class="stat-label">Hospital Inventory Alerts</span>
                    </div>
                </div>
            </section>

            <!-- ===================== SECTION 1: PURCHASE REQUESTS ===================== -->
            <section class="card ip-section">
                <div class="card-header ip-card-header">
                    <div>
                        <h2>Purchase Requests</h2>
                        <p class="card-subtitle">Restock requests submitted by Pharmacy Inventory, across medicines, supplies, and equipment — newest first.</p>
                    </div>
                </div>

                <form method="get" class="ip-toolbar" id="filterForm">
                    <div class="search-field">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                        <input type="text" name="search" placeholder="Search medicine or request ID" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="toolbar-filters">
                        <select name="status" class="filter-select">
                            <option value="">All Statuses</option>
                            <?php foreach ($allowedStatus as $s): ?>
                                <option value="<?php echo $s; ?>" <?php echo $status === $s ? 'selected' : ''; ?>><?php echo statusLabel($s); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="priority" class="filter-select">
                            <option value="">All Priorities</option>
                            <?php foreach ($allowedPriority as $p): ?>
                                <option value="<?php echo $p; ?>" <?php echo $priority === $p ? 'selected' : ''; ?>><?php echo priorityLabel($p); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="category" class="filter-select">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?php echo htmlspecialchars($c['category']); ?>" <?php echo $category === $c['category'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['category']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="date" name="date" class="filter-select" value="<?php echo htmlspecialchars($dateFrom); ?>">
                        <select name="sort" class="filter-select">
                            <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest</option>
                            <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest</option>
                            <option value="priority" <?php echo $sort === 'priority' ? 'selected' : ''; ?>>Priority</option>
                            <option value="status" <?php echo $sort === 'status' ? 'selected' : ''; ?>>Status</option>
                        </select>
                        <button type="submit" class="btn-secondary-sm">Apply</button>
                        <?php if ($search || $status || $priority || $category || $dateFrom || $sort !== 'newest'): ?>
                            <a href="inventory-procurement.php" class="btn-clear-filters">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if (empty($requests)): ?>
                    <div class="empty-state">
                        <div class="empty-illustration">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 8v13H3V8"></path>
                                <path d="M1 3h22v5H1z"></path>
                                <path d="M10 12h4"></path>
                            </svg>
                        </div>
                        <p><strong>No purchase requests found.</strong></p>
                        <p>Try adjusting your filters. New requests are submitted by the Pharmacist via Request Restock.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="queue-table ip-table">
                            <thead>
                                <tr>
                                    <th>Request ID</th>
                                    <th>Item Name</th>
                                    <th>Category</th>
                                    <th>Current Stock</th>
                                    <th>Minimum Stock</th>
                                    <th>Requested Qty</th>
                                    <th>Requested By</th>
                                    <th>Date Requested</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requests as $req): ?>
                                    <tr>
                                        <td>#<?php echo $req['request_id']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($req['medicine_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($req['category']); ?></td>
                                        <td><?php echo (int) $req['current_stock']; ?> <span class="table-subtext-inline"><?php echo htmlspecialchars($req['unit']); ?></span></td>
                                        <td><?php echo (int) $req['minimum_stock']; ?> <span class="table-subtext-inline"><?php echo htmlspecialchars($req['unit']); ?></span></td>
                                        <td><?php echo (int) $req['requested_quantity']; ?></td>
                                        <td><?php echo htmlspecialchars($req['requester_first'] . ' ' . $req['requester_last']); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($req['created_at'])); ?></td>
                                        <td><span class="priority-badge priority-<?php echo $req['priority']; ?>"><?php echo priorityLabel($req['priority']); ?></span></td>
                                        <td><span class="status-pill status-pr-<?php echo $req['status']; ?>"><?php echo statusLabel($req['status']); ?></span></td>
                                        <td>
                                            <div class="row-actions">
                                                <button type="button" class="btn-row-action" data-action="view" data-id="<?php echo $req['request_id']; ?>">View</button>
                                                <?php if ($req['status'] === 'pending'): ?>
                                                    <button type="button" class="btn-row-action btn-row-approve" data-action="approve" data-id="<?php echo $req['request_id']; ?>">Approve</button>
                                                    <button type="button" class="btn-row-action btn-row-revision" data-action="revision" data-id="<?php echo $req['request_id']; ?>">Revise</button>
                                                    <button type="button" class="btn-row-action btn-row-reject" data-action="reject" data-id="<?php echo $req['request_id']; ?>">Reject</button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <!-- ===================== HOSPITAL INVENTORY ALERTS ===================== -->
            <!-- Formerly "Low Stock Alert" / stat card "Low Stock Medicines".
                 Broadened to the 4 alert types the Pharmacy Inventory module
                 workflow calls for. Low Stock / Critical Stock are real data
                 (same inventory_medicines query as before, just split by
                 ratio). Near Expiry / Expired are UI-only placeholders — see
                 the TODO(backend) note above $nearExpiryStock/$expiredStock. -->
            <section class="card ip-section">
                <div class="card-header ip-card-header">
                    <div>
                        <h2>Hospital Inventory Alerts</h2>
                        <p class="card-subtitle">Stock and expiry alerts from Pharmacy Inventory — medicines, supplies, and equipment.</p>
                    </div>
                </div>

                <div class="ip-alert-chip-row">
                    <span class="ip-alert-chip ip-alert-chip-low"><strong><?php echo count($lowStock); ?></strong> Low Stock</span>
                    <span class="ip-alert-chip ip-alert-chip-critical"><strong><?php echo count($criticalStock); ?></strong> Critical Stock</span>
                    <span class="ip-alert-chip ip-alert-chip-expiring"><strong><?php echo count($nearExpiryStock); ?></strong> Near Expiry</span>
                    <span class="ip-alert-chip ip-alert-chip-expired"><strong><?php echo count($expiredStock); ?></strong> Expired</span>
                </div>

                <?php if ($totalAlerts === 0): ?>
                    <div class="empty-state">
                        <div class="empty-illustration empty-illustration-good">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                <polyline points="22 4 12 14.01 9 11.01"></polyline>
                            </svg>
                        </div>
                        <p><strong>No inventory alerts right now.</strong></p>
                        <p>All medicines, supplies, and equipment are within safe stock and expiry ranges.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="queue-table ip-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Category</th>
                                    <th>Current / Minimum Stock</th>
                                    <th>Expiry Date</th>
                                    <th>Alert Type</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($criticalStock as $med): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($med['name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($med['category']); ?></td>
                                        <td><?php echo (int) $med['current_stock']; ?> / <?php echo (int) $med['minimum_stock']; ?> <?php echo htmlspecialchars($med['unit']); ?></td>
                                        <td>—</td>
                                        <td><span class="status-pill status-stock-critical">Critical Stock</span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php foreach ($lowStock as $med): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($med['name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($med['category']); ?></td>
                                        <td><?php echo (int) $med['current_stock']; ?> / <?php echo (int) $med['minimum_stock']; ?> <?php echo htmlspecialchars($med['unit']); ?></td>
                                        <td>—</td>
                                        <td><span class="status-pill status-stock-low">Low Stock</span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php foreach ($nearExpiryStock as $item): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($item['name']); ?></strong>
                                            <div class="table-subtext">Sample preview — no expiry_date column yet</div>
                                        </td>
                                        <td><?php echo htmlspecialchars($item['category']); ?></td>
                                        <td>—</td>
                                        <td><?php echo htmlspecialchars(date('M j, Y', strtotime($item['expiry_date']))); ?></td>
                                        <td><span class="status-pill status-stock-expiring">Near Expiry</span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php foreach ($expiredStock as $item): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($item['name']); ?></strong>
                                            <div class="table-subtext">Sample preview — no expiry_date column yet</div>
                                        </td>
                                        <td><?php echo htmlspecialchars($item['category']); ?></td>
                                        <td>—</td>
                                        <td><?php echo htmlspecialchars(date('M j, Y', strtotime($item['expiry_date']))); ?></td>
                                        <td><span class="status-pill status-stock-expired">Expired</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <!-- ===================== DRAWER: Request Details / Bidding / PO ===================== -->
    <div class="ip-drawer-overlay" id="drawerOverlay"></div>
    <aside class="ip-drawer" id="requestDrawer" aria-hidden="true">
        <div class="ip-drawer-header">
            <h3 id="drawerTitle">Request Details</h3>
            <button type="button" class="ip-drawer-close" id="drawerCloseBtn" aria-label="Close">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>
        <div class="ip-drawer-body" id="drawerBody">
            <!-- populated by inventory-procurement.js via inventory-details-ajax.php -->
            <div class="ip-skeleton-block"></div>
            <div class="ip-skeleton-block"></div>
            <div class="ip-skeleton-block short"></div>
        </div>
    </aside>

    <!-- ===================== MODAL: Send for Revision ===================== -->
    <div class="modal-backdrop" id="revisionModal" aria-hidden="true">
        <div class="modal-box ip-modal-box ip-modal-box-sm" role="dialog" aria-modal="true" aria-labelledby="revisionModalTitle">
            <div class="ip-modal-header">
                <h3 id="revisionModalTitle">Send Back for Revision</h3>
                <button type="button" class="ip-drawer-close" data-close-modal="revisionModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <form id="revisionForm" class="ip-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send_revision">
                <input type="hidden" name="request_id" id="revisionRequestId">
                <label class="ip-field">
                    <span>What needs to change?</span>
                    <textarea name="revision_note" rows="3" required placeholder="e.g. Confirm quantity against last month's usage"></textarea>
                </label>
                <p class="ip-form-error" id="revisionError"></p>
                <div class="ip-modal-actions">
                    <button type="button" class="btn-secondary-sm" data-close-modal="revisionModal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Send for Revision</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===================== MODAL: Reject ===================== -->
    <div class="modal-backdrop" id="rejectModal" aria-hidden="true">
        <div class="modal-box ip-modal-box ip-modal-box-sm" role="dialog" aria-modal="true" aria-labelledby="rejectModalTitle">
            <div class="ip-modal-header">
                <h3 id="rejectModalTitle">Reject Purchase Request</h3>
                <button type="button" class="ip-drawer-close" data-close-modal="rejectModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <form id="rejectForm" class="ip-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reject_request">
                <input type="hidden" name="request_id" id="rejectRequestId">
                <label class="ip-field">
                    <span>Reason for rejection</span>
                    <textarea name="rejection_reason" rows="3" required placeholder="Let the requester know why this was rejected"></textarea>
                </label>
                <p class="ip-form-error" id="rejectError"></p>
                <div class="ip-modal-actions">
                    <button type="button" class="btn-secondary-sm" data-close-modal="rejectModal">Cancel</button>
                    <button type="submit" class="btn-danger">Reject Request</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===================== MODAL: Add Supplier Bid ===================== -->
    <div class="modal-backdrop" id="addBidModal" aria-hidden="true">
        <div class="modal-box ip-modal-box ip-modal-box-sm" role="dialog" aria-modal="true" aria-labelledby="addBidModalTitle">
            <div class="ip-modal-header">
                <h3 id="addBidModalTitle">Record Supplier Bid</h3>
                <button type="button" class="ip-drawer-close" data-close-modal="addBidModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <form id="addBidForm" class="ip-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_bid">
                <input type="hidden" name="request_id" id="addBidRequestId">
                <label class="ip-field">
                    <span>Supplier Name</span>
                    <input type="text" name="supplier_name" required>
                </label>
                <div class="ip-form-row">
                    <label class="ip-field">
                        <span>Bid Price (₱)</span>
                        <input type="number" name="bid_price" min="0.01" step="0.01" required>
                    </label>
                    <label class="ip-field">
                        <span>Estimated Delivery</span>
                        <input type="date" name="estimated_delivery_date" required>
                    </label>
                </div>
                <div class="ip-form-row">
                    <label class="ip-field">
                        <span>Warranty</span>
                        <input type="text" name="warranty" placeholder="e.g. 6 months shelf warranty">
                    </label>
                    <label class="ip-field">
                        <span>Supplier Rating (0–5)</span>
                        <input type="number" name="supplier_rating" min="0" max="5" step="0.1" value="4.0">
                    </label>
                </div>
                <p class="ip-form-error" id="addBidError"></p>
                <div class="ip-modal-actions">
                    <button type="button" class="btn-secondary-sm" data-close-modal="addBidModal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Bid</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===================== MODAL: Confirm Winning Supplier ===================== -->
    <div class="modal-backdrop" id="confirmWinnerModal" aria-hidden="true">
        <div class="modal-box ip-modal-box ip-modal-box-sm" role="dialog" aria-modal="true" aria-labelledby="confirmWinnerModalTitle">
            <div class="ip-modal-header">
                <h3 id="confirmWinnerModalTitle">Confirm Winning Supplier</h3>
                <button type="button" class="ip-drawer-close" data-close-modal="confirmWinnerModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="ip-confirm-body" id="confirmWinnerBody">
                <!-- populated by JS -->
            </div>
            <form id="confirmWinnerForm" class="ip-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="select_winner">
                <input type="hidden" name="bid_id" id="confirmWinnerBidId">

                <!-- UI-ONLY safeguard (2026-07-19): a second admin's sign-off
                     before the winning bid is confirmed. This is checked in
                     JS only, purely to slow down the "one Admin adds a bid
                     and immediately picks it as the winner" path — it does
                     NOT verify a second admin account is actually logged in,
                     and neither field is posted to inventory-actions.php
                     (no name= attribute, and select_winner only ever reads
                     bid_id). TODO(backend): to make this a real control,
                     add a supplier_bids.second_approver_id column and
                     require that confirmation come from a *different*
                     authenticated admin session, not a typed name. -->
                <label class="ip-field">
                    <span>Second Approver Name</span>
                    <input type="text" id="secondApproverName" placeholder="Name of the admin who independently reviewed this bid" autocomplete="off">
                </label>
                <label class="ip-checkbox-field">
                    <input type="checkbox" id="secondApproverConfirm">
                    <span>I confirm this selection has been independently reviewed.</span>
                </label>

                <p class="ip-form-error" id="confirmWinnerError"></p>
                <div class="ip-modal-actions">
                    <button type="button" class="btn-secondary-sm" data-close-modal="confirmWinnerModal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Confirm Winner</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast host -->
    <div class="ip-toast-host" id="toastHost"></div>

    <script>
        window.IP_CONFIG = {
            actionsUrl: 'inventory-actions.php',
            detailsUrl: 'inventory-details-ajax.php',
            printUrl: 'print-purchase-order.php'
        };
    </script>
    <script src="../assets/js/inventory-procurement.js" defer></script>
</body>

</html>