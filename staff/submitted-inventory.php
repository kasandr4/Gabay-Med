<?php
// staff/submitted-inventory.php
// Read-only history of every inventory batch submitted by this staff member.

require_once '../includes/auth_guard.php';
require_role('staff');
require_staff_type('inventory');
require_once '../config/db.php';

$staffId = (int) $_SESSION['user_id'];
$batches = [];
$stmt = $conn->prepare(
    "SELECT icb.batch_number, icb.status, icb.submitted_at, icb.confirmed_at,
            COUNT(ici.item_id) AS total_items
     FROM inventory_count_batches icb
     LEFT JOIN inventory_count_items ici ON ici.batch_id = icb.batch_id
     WHERE icb.assigned_to = ? AND icb.submitted_at IS NOT NULL
     GROUP BY icb.batch_id
     ORDER BY icb.submitted_at DESC"
);
$stmt->bind_param('i', $staffId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $batches[] = $row;
}
$stmt->close();

$statusLabels = [
    'submitted' => 'Submitted',
    'confirmed' => 'Confirmed',
];
$current_page = 'submitted-inventory';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submitted Inventory - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/inventory-count.css">
</head>

<body>
    <div class="app-shell">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">
            <header class="page-header">
                <div>
                    <h1>Submitted Inventory</h1>
                    <p class="page-subtitle">Review the inventory counts you have submitted for pharmacist review.</p>
                </div>
                <a class="btn btn-secondary" href="inventory-count.php">Back to Assignments</a>
            </header>

            <section class="card">
                <div class="card-header">
                    <div>
                        <h2>Submission History</h2>
                        <span class="card-subtitle"><?php echo count($batches); ?> submitted batch<?php echo count($batches) === 1 ? '' : 'es'; ?></span>
                    </div>
                </div>
                <?php if (empty($batches)): ?>
                    <div class="empty-state">
                        <p>You have not submitted any inventory counts yet.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Batch #</th>
                                    <th>Items</th>
                                    <th>Submitted</th>
                                    <th>Confirmed</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($batches as $batch): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($batch['batch_number']); ?></strong></td>
                                        <td><?php echo (int) $batch['total_items']; ?></td>
                                        <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($batch['submitted_at']))); ?></td>
                                        <td><?php echo $batch['confirmed_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($batch['confirmed_at']))) : '—'; ?></td>
                                        <td><span class="status-pill status-ic-<?php echo htmlspecialchars($batch['status']); ?>"><?php echo htmlspecialchars($statusLabels[$batch['status']] ?? ucfirst($batch['status'])); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
    <script src="../assets/js/doctor-dashboard.js"></script>
</body>

</html>