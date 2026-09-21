<?php
// staff/dashboard.php
// Landing dashboard for Inventory Counting staff.

require_once '../includes/auth_guard.php';
require_role('staff');
require_staff_type('inventory');
require_once '../config/db.php';

$staffId = (int) $_SESSION['user_id'];
$firstName = $_SESSION['first_name'] ?? 'Staff';

$activeBatches = [];
$stmt = $conn->prepare(
    "SELECT icb.batch_id, icb.batch_number, icb.status, icb.assigned_at,
            icb.due_date, icb.submitted_at,
            CONCAT(pu.first_name, ' ', pu.last_name) AS assigned_by_name,
            COUNT(ici.item_id) AS total_items,
            SUM(CASE WHEN ici.staff_count IS NOT NULL THEN 1 ELSE 0 END) AS counted_items
     FROM inventory_count_batches icb
     JOIN users pu ON pu.user_id = icb.assigned_by
     LEFT JOIN inventory_count_items ici ON ici.batch_id = icb.batch_id
     WHERE icb.assigned_to = ? AND icb.status IN ('pending', 'submitted')
     GROUP BY icb.batch_id
     ORDER BY (icb.status = 'pending') DESC, icb.assigned_at DESC"
);
$stmt->bind_param('i', $staffId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $activeBatches[] = $row;
}
$stmt->close();

$totalAssigned = count($activeBatches);
$countedItems = 0;
$totalItems = 0;
$pendingBatches = 0;
foreach ($activeBatches as $batch) {
    $countedItems += (int) $batch['counted_items'];
    $totalItems += (int) $batch['total_items'];
    if ($batch['status'] === 'pending') {
        $pendingBatches++;
    }
}
$countPercentage = $totalItems > 0 ? round(($countedItems / $totalItems) * 100) : 0;
$singleAssignment = count($activeBatches) === 1 ? $activeBatches[0] : null;

$current_page = 'staff-dashboard';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/inventory-count.css">
    <link rel="stylesheet" href="../assets/css/staff-dashboard.css">
</head>

<body>
    <div class="app-shell">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">
            <header class="page-header sd-welcome-header">
                <div>
                    <p class="sd-eyebrow">Inventory Counting Portal</p>
                    <h1>Hello, <?php echo htmlspecialchars($firstName); ?>!</h1>
                    <p class="page-subtitle">Here is the current status of your assigned inventory counts.</p>
                </div>
            </header>

            <section class="stats-grid">
                <div class="stat-card stat-blue">
                    <div class="stat-info"><span class="stat-value"><?php echo $totalAssigned; ?></span><span class="stat-label">Total Assigned</span></div>
                </div>
                <div class="stat-card stat-teal">
                    <div class="stat-info"><span class="stat-value"><?php echo $countedItems; ?></span><span class="stat-label">Items Counted</span></div>
                </div>
                <div class="stat-card stat-amber">
                    <div class="stat-info"><span class="stat-value"><?php echo $pendingBatches; ?></span><span class="stat-label">Pending Assignments</span></div>
                </div>
                <div class="stat-card stat-green">
                    <div class="stat-info"><span class="stat-value"><?php echo $countPercentage; ?>%</span><span class="stat-label">Count Percentage</span></div>
                </div>
            </section>

            <?php if ($singleAssignment): ?>
                <section class="card sd-assignment-summary">
                    <div class="card-header">
                        <div>
                            <h2>Current Assignment</h2>
                            <span class="card-subtitle">Your only active assignment</span>
                        </div>
                        <span class="status-pill status-ic-<?php echo htmlspecialchars($singleAssignment['status']); ?>"><?php echo $singleAssignment['status'] === 'pending' ? 'Not Started' : 'Submitted'; ?></span>
                    </div>
                    <div class="sd-summary-grid">
                        <div><span>Batch</span><strong><?php echo htmlspecialchars($singleAssignment['batch_number']); ?></strong></div>
                        <div><span>When Assigned</span><strong><?php echo htmlspecialchars(date('M j, Y', strtotime($singleAssignment['assigned_at']))); ?></strong></div>
                        <div><span>Assigned By</span><strong><?php echo htmlspecialchars($singleAssignment['assigned_by_name']); ?></strong></div>
                        <div><span>Progress</span><strong><?php echo (int) $singleAssignment['counted_items']; ?> / <?php echo (int) $singleAssignment['total_items']; ?> counted</strong></div>
                    </div>
                </section>
            <?php endif; ?>

            <section class="sd-quick-links">
                <a class="card sd-quick-card" href="inventory-count.php">
                    <span class="sd-quick-icon">✓</span>
                    <span><strong>My Assignments</strong><small>Open active and completed count assignments.</small></span>
                    <span class="sd-quick-arrow">&rarr;</span>
                </a>
                <a class="card sd-quick-card" href="submitted-inventory.php">
                    <span class="sd-quick-icon">▤</span>
                    <span><strong>Submitted Inventory</strong><small>Review everything you have submitted.</small></span>
                    <span class="sd-quick-arrow">&rarr;</span>
                </a>
            </section>
        </main>
    </div>
    <script src="../assets/js/doctor-dashboard.js"></script>
</body>

</html>