<?php
// pharmacist/inventory-count-history.php
// Assignment history for one inventory-counting staff member.
// The pharmacist can only see batches they assigned.

require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';

$pharmacistId = (int) $_SESSION['user_id'];
$selectedStaffId = filter_var($_GET['staff_id'] ?? null, FILTER_VALIDATE_INT);
$selectedStaffId = $selectedStaffId !== false ? (int) $selectedStaffId : 0;

$staffUsers = [];
$staffResult = $conn->query(
    "SELECT user_id, first_name, last_name
     FROM users
     WHERE role = 'staff' AND staff_type = 'inventory'
       AND is_active = 1 AND archived_at IS NULL
     ORDER BY first_name ASC, last_name ASC"
);
if ($staffResult) {
    while ($row = $staffResult->fetch_assoc()) {
        $staffUsers[] = [
            'id' => (int) $row['user_id'],
            'name' => trim($row['first_name'] . ' ' . $row['last_name']),
        ];
    }
}

$selectedStaffName = '';
foreach ($staffUsers as $staff) {
    if ($staff['id'] === $selectedStaffId) {
        $selectedStaffName = $staff['name'];
        break;
    }
}

$batches = [];
if ($selectedStaffId > 0 && $selectedStaffName !== '') {
    $stmt = $conn->prepare(
        "SELECT icb.batch_id, icb.batch_number, icb.status, icb.assigned_at,
                icb.due_date, icb.submitted_at, icb.confirmed_at,
                COUNT(ici.item_id) AS total_items,
                SUM(CASE WHEN ici.staff_count IS NOT NULL THEN 1 ELSE 0 END) AS counted_items
         FROM inventory_count_batches icb
         LEFT JOIN inventory_count_items ici ON ici.batch_id = icb.batch_id
         WHERE icb.assigned_to = ? AND icb.assigned_by = ?
         GROUP BY icb.batch_id
         ORDER BY icb.assigned_at DESC"
    );
    $stmt->bind_param('ii', $selectedStaffId, $pharmacistId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $batches[] = $row;
    }
    $stmt->close();
}

$statusLabels = [
    'pending' => 'Pending Count',
    'submitted' => 'Awaiting Review',
    'confirmed' => 'Confirmed',
];
$current_page = 'inventory-count-history';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Count History - GabayMed</title>
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
                    <h1>Assignment History</h1>
                    <p class="page-subtitle">Review every inventory count batch assigned to one staff member.</p>
                </div>
                <div class="header-actions">
                    <a class="btn btn-secondary" href="inventory-count.php">Back to Inventory Count</a>
                </div>
            </header>

            <section class="card">
                <form class="ic-history-filter" method="get">
                    <label class="ic-history-field">
                        <span>Inventory Counting Staff</span>
                        <select name="staff_id" required>
                            <option value="">Select a staff member</option>
                            <?php foreach ($staffUsers as $staff): ?>
                                <option value="<?php echo $staff['id']; ?>" <?php echo $staff['id'] === $selectedStaffId ? ' selected' : ''; ?>><?php echo htmlspecialchars($staff['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="btn btn-primary" type="submit">View History</button>
                    <?php if ($selectedStaffId > 0): ?>
                        <a class="btn btn-secondary" href="inventory-count-history.php">Clear</a>
                    <?php endif; ?>
                </form>
            </section>

            <?php if ($selectedStaffId <= 0 || $selectedStaffName === ''): ?>
                <section class="card empty-state">
                    <p>Select an Inventory Counting staff member to view their assignment history.</p>
                </section>
            <?php else: ?>
                <section class="card">
                    <div class="card-header">
                        <div>
                            <h2><?php echo htmlspecialchars($selectedStaffName); ?></h2>
                            <span class="card-subtitle"><?php echo count($batches); ?> batch<?php echo count($batches) === 1 ? '' : 'es'; ?> assigned by you</span>
                        </div>
                    </div>
                    <?php if (empty($batches)): ?>
                        <div class="empty-state">
                            <p>No batches have been assigned to this staff member by you.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>No.</th>
                                        <th>Batch #</th>
                                        <th>Items</th>
                                        <th>Progress</th>
                                        <th>Assigned</th>
                                        <th>Due</th>
                                        <th>Submitted</th>
                                        <th>Confirmed</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($batches as $index => $batch): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td><strong><?php echo htmlspecialchars($batch['batch_number']); ?></strong></td>
                                            <td><?php echo (int) $batch['total_items']; ?></td>
                                            <td><?php echo (int) $batch['counted_items']; ?> / <?php echo (int) $batch['total_items']; ?> counted</td>
                                            <td><?php echo htmlspecialchars(date('M j, Y', strtotime($batch['assigned_at']))); ?></td>
                                            <td><?php echo $batch['due_date'] ? htmlspecialchars(date('M j, Y', strtotime($batch['due_date']))) : '—'; ?></td>
                                            <td><?php echo $batch['submitted_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($batch['submitted_at']))) : '—'; ?></td>
                                            <td><?php echo $batch['confirmed_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($batch['confirmed_at']))) : '—'; ?></td>
                                            <td><span class="status-pill status-ic-<?php echo htmlspecialchars($batch['status']); ?>"><?php echo htmlspecialchars($statusLabels[$batch['status']] ?? $batch['status']); ?></span></td>
                                            <td><button type="button" class="btn btn-secondary" data-view-batch="<?php echo (int) $batch['batch_id']; ?>">Review</button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </main>
    </div>

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

    <script src="../assets/js/inventory-count-history.js"></script>
</body>

</html>