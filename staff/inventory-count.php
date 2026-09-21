<?php
// staff/inventory-count.php
// The blind-counting screen. Staff sees only the medicines in their
// assigned batch and a blank quantity field per line — system_count is
// never selected in any query on this page, and never sent to the
// browser, so there's nothing to "peek" at in the page source or network
// tab either. That's the whole point of the workflow (see
// pharmacist/inventory-count.php's header comment for the full picture).
//
// Two actions, both in inventory-count-actions.php: Save Progress (can be
// partial — staff can count a few lines, save, come back later) and
// Submit (requires every line counted, locks the batch to 'submitted'
// and hands it to the assigning Pharmacist for review).

require_once '../includes/auth_guard.php';
require_role('staff');
require_staff_type('inventory');
require_once '../config/db.php';
require_once '../includes/csrf.php';

$staffId = (int) $_SESSION['user_id'];

// Batches assigned to this staff member — pending ones need action,
// submitted/confirmed ones are shown for reference only.
$batches = [];
$stmt = $conn->prepare(
    "SELECT icb.batch_id, icb.batch_number, icb.status, icb.assigned_at, icb.due_date, icb.notes,
            icb.submitted_at, icb.confirmed_at,
            CONCAT(pu.first_name, ' ', pu.last_name) AS assigned_by_name,
            COUNT(ici.item_id) AS total_items,
            SUM(CASE WHEN ici.staff_count IS NOT NULL THEN 1 ELSE 0 END) AS counted_items
     FROM inventory_count_batches icb
     JOIN users pu ON pu.user_id = icb.assigned_by
     LEFT JOIN inventory_count_items ici ON ici.batch_id = icb.batch_id
     WHERE icb.assigned_to = ?
     GROUP BY icb.batch_id
     ORDER BY (icb.status = 'pending') DESC, icb.assigned_at DESC"
);
$stmt->bind_param("i", $staffId);
$stmt->execute();
$rows = $stmt->get_result();
while ($row = $rows->fetch_assoc()) {
    // A batch has no dedicated "recount" flag or timestamp in the schema
    // (see 012_inventory_count.sql) — pharmacist/inventory-count-actions.php's
    // request_recount reverts status to 'pending' and appends a plain-text
    // "Recount requested: N item(s)..." marker onto the same notes field
    // used for the pharmacist's original shelf/location instructions.
    // Detecting it here the same way pharmacist/inventory-count.php does,
    // so staff gets the same "this already failed review once" signal
    // instead of a recount silently blending into the general pending list.
    $notes = (string) ($row['notes'] ?? '');
    $isRecount = stripos($notes, 'Recount requested:') !== false;
    $recountItemCount = null;
    if ($isRecount && preg_match('/Recount requested:\s*(\d+)\s*item/i', $notes, $m)) {
        $recountItemCount = (int) $m[1];
    }

    $batches[] = [
        "id"            => (int) $row['batch_id'],
        "number"        => $row['batch_number'],
        "status"        => $row['status'],
        "assigned_by"   => $row['assigned_by_name'],
        "assigned_at"   => date('M j, Y', strtotime($row['assigned_at'])),
        "due_date"      => $row['due_date'] ? date('M j, Y', strtotime($row['due_date'])) : '—',
        "notes"         => $row['notes'],
        "total_items"   => (int) $row['total_items'],
        "counted_items" => (int) $row['counted_items'],
        "is_recount"        => $isRecount,
        "recount_item_count" => $recountItemCount,
    ];
}
$stmt->close();

// Recount Requested: pending batches sent back by the pharmacist for a
// partial recount, rather than pending batches that simply haven't been
// started yet. A fresh assignment waiting to be counted is normal; a
// recount is a batch that already failed review once and needs
// following up on — worth calling out separately, same distinction
// pharmacist/inventory-count.php makes on the assigning side.
$recountBatches = array_values(array_filter($batches, fn($b) => $b['status'] === 'pending' && $b['is_recount']));

$stats = ['pending' => 0, 'submitted' => 0, 'confirmed' => 0, 'recount_requested' => 0];
foreach ($batches as $b) {
    if (isset($stats[$b['status']])) $stats[$b['status']]++;
}
$stats['recount_requested'] = count($recountBatches);

$statusLabels = [
    'pending'   => 'Not Started',
    'submitted' => 'Submitted',
    'confirmed' => 'Confirmed',
];

$activeBatches = array_values(array_filter($batches, static function ($batch) {
    return in_array($batch['status'], ['pending', 'submitted'], true);
}));
$completedBatches = array_values(array_filter($batches, static function ($batch) {
    return $batch['status'] === 'confirmed';
}));

function renderAssignmentTable(array $batches, array $statusLabels): void
{
    if (empty($batches)) {
        echo '<div class="empty-state"><p>No batches in this view.</p></div>';
        return;
    }
?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Batch #</th>
                    <th>Assigned By</th>
                    <th>Items</th>
                    <th>Progress</th>
                    <th>Due</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($batches as $batch): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($batch['number']); ?></strong></td>
                        <td><?php echo htmlspecialchars($batch['assigned_by']); ?></td>
                        <td><?php echo $batch['total_items']; ?></td>
                        <td><?php echo $batch['counted_items']; ?> / <?php echo $batch['total_items']; ?> counted</td>
                        <td><?php echo htmlspecialchars($batch['due_date']); ?></td>
                        <td><span class="status-pill status-ic-<?php echo htmlspecialchars($batch['status']); ?>"><?php echo htmlspecialchars($statusLabels[$batch['status']] ?? $batch['status']); ?></span></td>
                        <td>
                            <button type="button" class="btn btn-primary btn-sm" data-open-count="<?php echo $batch['id']; ?>">
                                <?php echo $batch['status'] === 'pending' ? 'Count Now' : 'View'; ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php
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
    <link rel="stylesheet" href="../assets/css/inventory-count.css">
</head>

<body>
    <div class="app-shell">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <header class="page-header">
                <div>
                    <h1>Inventory Count</h1>
                    <p class="page-subtitle">Count the medicines assigned to you carefully, then submit your counted quantities.</p>
                </div>
            </header>

            <section class="stats-grid">
                <div class="stat-card stat-amber">
                    <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <path d="M12 6v6l4 2"></path>
                        </svg></div>
                    <div class="stat-info"><span class="stat-value"><?php echo $stats['pending']; ?></span><span class="stat-label">Not Started / In Progress</span></div>
                </div>
                <div class="stat-card stat-blue">
                    <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                        </svg></div>
                    <div class="stat-info"><span class="stat-value"><?php echo $stats['submitted']; ?></span><span class="stat-label">Submitted</span></div>
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

            <section class="card ic-recount-panel">
                <div class="card-header">
                    <div>
                        <h2>Recounts Requested</h2>
                        <span class="card-subtitle">Batches the pharmacist sent back for a partial recount</span>
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
                                    <th>Assigned By</th>
                                    <th>Items to Recount</th>
                                    <th>Due</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recountBatches as $rb): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($rb['number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($rb['assigned_by']); ?></td>
                                        <td><?php echo $rb['recount_item_count'] !== null ? $rb['recount_item_count'] : '—'; ?></td>
                                        <td><?php echo htmlspecialchars($rb['due_date']); ?></td>
                                        <td>
                                            <button type="button" class="btn btn-primary btn-sm" data-open-count="<?php echo $rb['id']; ?>">Count Now</button>
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
                    <h2>My Assignments</h2>
                    <span class="card-subtitle"><?php echo count($batches); ?> batch<?php echo count($batches) === 1 ? '' : 'es'; ?></span>
                </div>
                <div class="ic-tabs" role="tablist" aria-label="Assignment status">
                    <button class="ic-tab is-active" type="button" role="tab" aria-selected="true" aria-controls="activeAssignments" data-assignment-tab="active">
                        Active <span><?php echo count($activeBatches); ?></span>
                    </button>
                    <button class="ic-tab" type="button" role="tab" aria-selected="false" aria-controls="completedAssignments" data-assignment-tab="completed">
                        Completed <span><?php echo count($completedBatches); ?></span>
                    </button>
                </div>
                <div id="activeAssignments" class="ic-assignment-panel is-active" role="tabpanel">
                    <?php renderAssignmentTable($activeBatches, $statusLabels); ?>
                </div>
                <div id="completedAssignments" class="ic-assignment-panel" role="tabpanel" hidden>
                    <?php renderAssignmentTable($completedBatches, $statusLabels); ?>
                </div>
            </section>
        </main>
    </div>

    <!-- ===================== DRAWER: Count Entry ===================== -->
    <div class="ic-drawer-backdrop" id="drawerBackdrop" aria-hidden="true">
        <aside class="ic-drawer" id="countDrawer">
            <input type="hidden" id="inventoryCountCsrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <div class="ic-drawer-header">
                <h3 id="drawerTitle">Count Entry</h3>
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

    <script src="../assets/js/doctor-dashboard.js"></script>
    <script src="../assets/js/staff-inventory-count.js"></script>
</body>

</html>