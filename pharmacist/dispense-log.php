<?php
// pharmacist/dispense-log.php
// Read-only oversight of stock dispenses performed by inventory-counting staff.

require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';

$search = trim($_GET['search'] ?? '');
$fromDate = trim($_GET['from'] ?? '');
$toDate = trim($_GET['to'] ?? '');

// Older medicine_exit_log rows do not carry FEFO batch identity. Keep the
// page compatible with that schema while showing batch data when available.
$hasBatchId = false;
$columnResult = $conn->query("SHOW COLUMNS FROM medicine_exit_log LIKE 'batch_id'");
if ($columnResult && $columnResult->num_rows > 0) {
    $hasBatchId = true;
}

$batchSelect = $hasBatchId
    ? "mel.batch_id, mb.batch_no, mb.expiry_date"
    : "NULL AS batch_id, NULL AS batch_no, NULL AS expiry_date";
$batchJoin = $hasBatchId
    ? "LEFT JOIN medicine_batches mb ON mb.batch_id = mel.batch_id"
    : '';

$sql = "SELECT mel.scanned_at, im.name AS medicine, im.unit,
               mel.units_deducted AS quantity,
               CONCAT(u.first_name, ' ', u.last_name) AS staff_name,
               {$batchSelect}
        FROM medicine_exit_log mel
        JOIN inventory_medicines im ON im.medicine_id = mel.medicine_id
        JOIN users u ON u.user_id = mel.staff_id
        {$batchJoin}
        WHERE 1=1";
$types = '';
$params = [];

if ($search !== '') {
    $sql .= " AND im.name LIKE ?";
    $types .= 's';
    $params[] = '%' . $search . '%';
}
if ($fromDate !== '') {
    $sql .= " AND mel.scanned_at >= ?";
    $types .= 's';
    $params[] = $fromDate . ' 00:00:00';
}
if ($toDate !== '') {
    $sql .= " AND mel.scanned_at <= ?";
    $types .= 's';
    $params[] = $toDate . ' 23:59:59';
}
$sql .= " ORDER BY mel.scanned_at DESC LIMIT 500";

$dispenses = [];
$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $dispenses[] = $row;
}
$stmt->close();

$current_page = 'dispense-log';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dispense Log - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/pharmacist-dashboard.css">
</head>

<body>
    <div class="app-shell">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">
            <header class="page-header">
                <div>
                    <h1>Dispense Log</h1>
                    <p class="page-subtitle">Read-only oversight of stock released by Inventory Counting staff.</p>
                </div>
            </header>

            <section class="card">
                <form method="get" class="toolbar-filters" style="margin-bottom: 20px;">
                    <input class="filter-select" type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search medicine">
                    <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 13.5px; color: var(--text-muted);">
                        From
                        <input class="filter-select" type="date" name="from" value="<?php echo htmlspecialchars($fromDate); ?>">
                    </label>
                    <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 13.5px; color: var(--text-muted);">
                        To
                        <input class="filter-select" type="date" name="to" value="<?php echo htmlspecialchars($toDate); ?>">
                    </label>
                    <button class="btn btn-secondary btn-sm" type="submit">Filter</button>
                    <?php if ($search !== '' || $fromDate !== '' || $toDate !== ''): ?>
                        <a class="btn btn-secondary btn-sm" href="dispense-log.php">Clear</a>
                    <?php endif; ?>
                </form>

                <?php if (!$hasBatchId): ?>
                    <p class="scan-inline-error">FEFO batch identity is not stored on existing dispense-log rows, so historical batch numbers cannot be displayed.</p>
                <?php endif; ?>

                <?php if (empty($dispenses)): ?>
                    <div class="empty-state">
                        <p>No dispense records match the current filters.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="queue-table">
                            <thead>
                                <tr>
                                    <th>Date / Time</th>
                                    <th>Medicine</th>
                                    <th>Quantity</th>
                                    <th>Dispensed By</th>
                                    <th>FEFO Batch</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dispenses as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($row['scanned_at']))); ?></td>
                                        <td><?php echo htmlspecialchars($row['medicine']); ?></td>
                                        <td><?php echo (int) $row['quantity'] . ' ' . htmlspecialchars($row['unit']); ?><?php echo (int) $row['quantity'] === 1 ? '' : 's'; ?></td>
                                        <td><?php echo htmlspecialchars($row['staff_name']); ?></td>
                                        <td>
                                            <?php if ($row['batch_id'] !== null): ?>
                                                <?php echo htmlspecialchars($row['batch_no'] ?: ('BATCH-' . $row['batch_id'])); ?>
                                                <?php if ($row['expiry_date']): ?>
                                                    <small>(expires <?php echo htmlspecialchars(date('M j, Y', strtotime($row['expiry_date']))); ?>)</small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                Not recorded
                                            <?php endif; ?>
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
</body>

</html>