<?php
// pharmacist/backup-export.php
// Server-side, dependency-free backup download for pharmacy-scoped data.
// Mirrors report-export.php's conventions (GET download, no external
// library, HTML-escaped content, session-scoped auth) — but this exports
// raw row data as JSON rather than a formatted report, since the point
// of a backup is restorability, not readability.
//
// Scope matches backup-restore.php's reset options exactly, so "back up
// before you reset" actually covers what's about to be touched:
//   - inventory_counts: inventory_count_batches + inventory_count_items
//   - reports: inventory_count_reports + medicine_exit_log (everything
//     shown on the Reports page's Inventory Counts and Dispensing tabs)
//   - reports_counts / reports_dispensing: the same two tables split
//     apart, for the granular scope picker embedded on the Reports page
//     itself (reports.php) alongside the combined "reports" option
//   - medicine_batches: medicine_batches + medicine_exit_log
//   - all: all of the above, plus a read-only inventory_medicines
//     snapshot (current_stock etc.) for reference when restoring by hand
//
// medicine_exit_log intentionally appears under "reports", "reports_
// dispensing", AND "medicine_batches" -- it's dispensing history (Reports
// page) AND stock movement tied to batches, so it belongs conceptually
// to more than one scope.
//
// Deliberately NOT touching users, appointments, consultations, or any
// non-pharmacy table — see backup-restore-actions.php's header for why
// this stays scoped to the pharmacy domain only.

require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/audit_log.php';

$scope = strtolower(trim($_GET['scope'] ?? 'all'));
$allowedScopes = ['inventory_counts', 'reports', 'reports_counts', 'reports_dispensing', 'medicine_batches', 'all'];
if (!in_array($scope, $allowedScopes, true)) {
    http_response_code(400);
    exit('Invalid backup scope.');
}

$pharmacistId = (int) $_SESSION['user_id'];

function fetchAll(mysqli $conn, string $sql): array
{
    $rows = [];
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) $rows[] = $row;
    }
    return $rows;
}

$backup = [
    'exported_at' => date('c'),
    'exported_by' => $pharmacistId,
    'scope' => $scope,
    'data' => [],
];

if ($scope === 'inventory_counts' || $scope === 'all') {
    $backup['data']['inventory_count_batches'] = fetchAll($conn, 'SELECT * FROM inventory_count_batches');
    $backup['data']['inventory_count_items'] = fetchAll($conn, 'SELECT * FROM inventory_count_items');
}
if ($scope === 'reports' || $scope === 'reports_counts' || $scope === 'all') {
    $backup['data']['inventory_count_reports'] = fetchAll($conn, 'SELECT * FROM inventory_count_reports');
}
if ($scope === 'reports' || $scope === 'reports_dispensing' || $scope === 'medicine_batches' || $scope === 'all') {
    $backup['data']['medicine_exit_log'] = fetchAll($conn, 'SELECT * FROM medicine_exit_log');
}
if ($scope === 'medicine_batches' || $scope === 'all') {
    $backup['data']['medicine_batches'] = fetchAll($conn, 'SELECT * FROM medicine_batches');
}
if ($scope === 'all') {
    // Read-only reference snapshot — not itself a reset target, but
    // needed to make sense of/restore the above by hand.
    $backup['data']['inventory_medicines'] = fetchAll($conn, 'SELECT * FROM inventory_medicines');
}

$scopeLabels = [
    'inventory_counts' => 'Inventory Counts',
    'reports' => 'Reports & Exports',
    'reports_counts' => 'Inventory Count Reports Only',
    'reports_dispensing' => 'Dispensing Log Only',
    'medicine_batches' => 'Medicine Batches',
    'all' => 'All Pharmacy Data',
];
write_audit_log($conn, $pharmacistId, 'pharmacist', 'backup_created', 'inventory', "Downloaded backup: {$scopeLabels[$scope]}.");

$filename = 'gabaymed-pharmacy-backup-' . $scope . '-' . date('Y-m-d_His') . '.json';
header('Content-Type: application/json; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
