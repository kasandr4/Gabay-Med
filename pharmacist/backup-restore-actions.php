<?php
// pharmacist/backup-restore-actions.php
// JSON action endpoint backing backup-restore.php's "Reset Selected Data"
// button, and the Archive tab's "Restore" button. Mirrors this app's
// other action endpoints (inventory-count-actions.php,
// record-stock-batch-actions.php): single action-routed JSON endpoint,
// CSRF-checked, prepared statements only, wrapped in a transaction.
//
// "Reset" no longer deletes anything. Per instructor feedback, the system
// must not permanently erase data -- so this archives instead: every row
// the scope touches is copied into a matching archived_* table (grouped
// under one archive_runs row per action), THEN cleared from the live
// table exactly as before. Nothing is destroyed; it's just moved out of
// the way. The Archive tab (reports.php?tab=archive) lists every run and
// can restore any of them in full.
//
// This design deliberately does NOT add a soft-delete column to the live
// tables (e.g. medicine_batches.archived_at) and filter every read query
// by it -- medicine_batches alone is read by 20+ files across admin/,
// pharmacist/, and staff/, and missing even one filter would silently
// show or hide the wrong stock. Moving rows to separate archive tables
// means every one of those existing queries keeps working completely
// unchanged; only this endpoint and the new Archive tab know the archive
// tables exist.
//
// Scoped to pharmacy-domain tables ONLY — never touches users,
// appointments, consultations, doctor schedules, or any other role's
// data. Reset/archive scopes, matching the option cards on
// backup-restore.php and the granular pair embedded on reports.php:
//
//   inventory_counts    -> inventory_count_batches + inventory_count_items
//                          (items are archived first, since deleting the
//                          batch would otherwise cascade-delete them)
//   reports              -> inventory_count_reports + medicine_exit_log
//                          (everything the Reports page's Inventory
//                          Counts and Dispensing tabs show, combined)
//   reports_counts       -> inventory_count_reports only
//   reports_dispensing   -> medicine_exit_log only
//   medicine_batches     -> medicine_batches + medicine_exit_log, then
//                          inventory_medicines.current_stock is
//                          recomputed to 0 for every medicine so the
//                          aggregate stock figure doesn't silently
//                          disagree with "zero batches remaining" — see
//                          dispense-actions.php's header comment on why
//                          current_stock always mirrors batch totals.
//   all                  -> inventory_counts + reports + medicine_batches,
//                          in one transaction, one archive run.
//
// medicine_exit_log is archived by "reports", "reports_dispensing", AND
// "medicine_batches" (each covers it from a different angle: dispensing
// history vs. stock movement) — only one of these scopes is ever active
// per request, so there's no double-archiving to worry about.
//
// The medicine catalog itself (medicine_names, inventory_medicines rows,
// medicine_units) is NEVER touched by this endpoint -- "reset" here
// means archiving pharmacy operational history, not the catalog staff
// assign counts against.
//
// Requires the pharmacist's own current password (password_verify against
// users.password, same check login.php uses) in addition to the normal
// CSRF token for the archive action, since it's still a significant,
// bulk state change — matching the "Admin Authorization Required" pattern
// from the OMCDH reference screens, scoped here to the pharmacist's own
// account instead of a separate admin credential. Restoring an archive
// run back is non-destructive (nothing is lost either way), so it only
// requires CSRF, not the password re-check.

require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/audit_log.php';

header('Content-Type: application/json');

function respond($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function countOf(mysqli $conn, string $table): int
{
    $result = $conn->query("SELECT COUNT(*) c FROM $table");
    return (int) $result->fetch_assoc()['c'];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Invalid request method.'], 405);
}

$submittedToken = $_POST['csrf_token'] ?? '';
$expectedToken = $_SESSION['csrf_token'] ?? '';
if ($expectedToken === '' || !hash_equals($expectedToken, $submittedToken)) {
    respond(['success' => false, 'error' => 'Your session expired. Please refresh the page and try again.'], 403);
}

$action = $_POST['action'] ?? '';
if (!in_array($action, ['reset_data', 'restore_archive'], true)) {
    respond(['success' => false, 'error' => 'Unknown action.'], 400);
}

$pharmacistId = (int) $_SESSION['user_id'];

// =====================================================================
// Archive (formerly "Reset")
// =====================================================================
if ($action === 'reset_data') {
    $scope = strtolower(trim((string) ($_POST['scope'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    $scopeLabels = [
        'inventory_counts' => 'Inventory Counts Only',
        'reports' => 'Reports & Exports Only',
        'reports_counts' => 'Inventory Count Reports Only',
        'reports_dispensing' => 'Dispensing Log Only',
        'medicine_batches' => 'Medicine Batches Only',
        'all' => 'All Pharmacy Data',
    ];
    if (!array_key_exists($scope, $scopeLabels)) {
        respond(['success' => false, 'error' => 'Select what to archive.'], 422);
    }
    if ($password === '') {
        respond(['success' => false, 'error' => 'Enter your password to confirm.'], 422);
    }

    // Re-verify the pharmacist's own current password — a valid session
    // alone isn't enough authorization for a bulk archive action.
    $stmt = $conn->prepare('SELECT password FROM users WHERE user_id = ? AND role = ?');
    $role = 'pharmacist';
    $stmt->bind_param('is', $pharmacistId, $role);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || !$user['password'] || !password_verify($password, $user['password'])) {
        respond(['success' => false, 'error' => 'Incorrect password.'], 403);
    }

    $counts = [
        'inventory_count_batches' => 0,
        'inventory_count_reports' => 0,
        'medicine_batches' => 0,
        'medicine_exit_log' => 0,
    ];

    $conn->begin_transaction();
    try {
        $runStmt = $conn->prepare('INSERT INTO archive_runs (scope, archived_by) VALUES (?, ?)');
        $runStmt->bind_param('si', $scope, $pharmacistId);
        $runStmt->execute();
        $runId = $runStmt->insert_id;
        $runStmt->close();

        if ($scope === 'inventory_counts' || $scope === 'all') {
            $counts['inventory_count_batches'] = countOf($conn, 'inventory_count_batches');
            // Items first — deleting the parent batch afterward would
            // otherwise cascade-delete them (fk_ici_batch) before we get
            // a chance to copy them.
            $conn->query(
                "INSERT INTO archived_inventory_count_items (run_id, item_id, batch_id, medicine_id, system_count, staff_count, final_count, remarks)
                 SELECT $runId, item_id, batch_id, medicine_id, system_count, staff_count, final_count, remarks FROM inventory_count_items"
            );
            $conn->query(
                "INSERT INTO archived_inventory_count_batches (run_id, batch_id, batch_number, assigned_to, assigned_by, assigned_at, due_date, notes, status, submitted_at, confirmed_by, confirmed_at)
                 SELECT $runId, batch_id, batch_number, assigned_to, assigned_by, assigned_at, due_date, notes, status, submitted_at, confirmed_by, confirmed_at FROM inventory_count_batches"
            );
            $conn->query('DELETE FROM inventory_count_batches');
        }

        if ($scope === 'reports' || $scope === 'reports_counts' || $scope === 'all') {
            $counts['inventory_count_reports'] = countOf($conn, 'inventory_count_reports');
            $conn->query(
                "INSERT INTO archived_inventory_count_reports (run_id, report_id, batch_id, report_name, saved_at, saved_by, total_items, total_count, snapshot_data)
                 SELECT $runId, report_id, batch_id, report_name, saved_at, saved_by, total_items, total_count, snapshot_data FROM inventory_count_reports"
            );
            $conn->query('DELETE FROM inventory_count_reports');
        }

        if ($scope === 'reports' || $scope === 'reports_dispensing' || $scope === 'all') {
            $counts['medicine_exit_log'] = countOf($conn, 'medicine_exit_log');
            $conn->query(
                "INSERT INTO archived_medicine_exit_log (run_id, log_id, medicine_id, batch_id, boxes_scanned, units_deducted, staff_id, barcode, scanned_at)
                 SELECT $runId, log_id, medicine_id, batch_id, boxes_scanned, units_deducted, staff_id, barcode, scanned_at FROM medicine_exit_log"
            );
            $conn->query('DELETE FROM medicine_exit_log');
        }

        if ($scope === 'medicine_batches' || $scope === 'all') {
            $counts['medicine_batches'] = countOf($conn, 'medicine_batches');
            // (Unless the "reports"/"reports_dispensing" branch above
            // already archived it, in the "all" case -- either way it
            // ends up archived exactly once.)
            if ($counts['medicine_exit_log'] === 0) {
                $counts['medicine_exit_log'] = countOf($conn, 'medicine_exit_log');
                $conn->query(
                    "INSERT INTO archived_medicine_exit_log (run_id, log_id, medicine_id, batch_id, boxes_scanned, units_deducted, staff_id, barcode, scanned_at)
                     SELECT $runId, log_id, medicine_id, batch_id, boxes_scanned, units_deducted, staff_id, barcode, scanned_at FROM medicine_exit_log"
                );
            }
            $conn->query(
                "INSERT INTO archived_medicine_batches (run_id, batch_id, medicine_id, source, brand, donor_notes, batch_no, units_received, units_remaining, expiry_date, unit_price, selling_price, received_at, created_by)
                 SELECT $runId, batch_id, medicine_id, source, brand, donor_notes, batch_no, units_received, units_remaining, expiry_date, unit_price, selling_price, received_at, created_by FROM medicine_batches"
            );
            $conn->query('DELETE FROM medicine_batches');
            $conn->query('DELETE FROM medicine_exit_log');
            // With zero batches remaining, the aggregate stock figure
            // every other page reads (inventory_medicines.current_stock)
            // must go to zero too, or it would silently disagree with
            // "no stock on hand" — same invariant dispense-actions.php
            // maintains in the other direction.
            $conn->query('UPDATE inventory_medicines SET current_stock = 0');
        }

        $summary = [];
        if ($scope === 'inventory_counts' || $scope === 'all') $summary[] = $counts['inventory_count_batches'] . ' count batch(es)';
        if ($scope === 'reports' || $scope === 'reports_counts' || $scope === 'all') $summary[] = $counts['inventory_count_reports'] . ' report(s)';
        if ($scope === 'reports' || $scope === 'reports_dispensing' || $scope === 'all') $summary[] = $counts['medicine_exit_log'] . ' dispense log entr(y/ies)';
        if ($scope === 'medicine_batches' || $scope === 'all') $summary[] = $counts['medicine_batches'] . ' batch record(s)';
        $summaryText = implode(', ', $summary);

        $updateSummary = $conn->prepare('UPDATE archive_runs SET summary = ? WHERE run_id = ?');
        $updateSummary->bind_param('si', $summaryText, $runId);
        $updateSummary->execute();
        $updateSummary->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        respond(['success' => false, 'error' => 'Could not archive data. Please try again.'], 500);
    }

    write_audit_log(
        $conn,
        $pharmacistId,
        'pharmacist',
        'data_archived',
        'inventory',
        "Archived scope: {$scopeLabels[$scope]}. Moved {$summaryText} to archive (run #{$runId})."
    );

    respond(['success' => true, 'message' => 'Selected data has been archived.', 'scope' => $scope, 'run_id' => $runId]);
}

// =====================================================================
// Restore an archive run
// =====================================================================
$runId = (int) ($_POST['run_id'] ?? 0);
if ($runId <= 0) {
    respond(['success' => false, 'error' => 'Select an archived run to restore.'], 422);
}

$stmt = $conn->prepare('SELECT scope, restored_at FROM archive_runs WHERE run_id = ?');
$stmt->bind_param('i', $runId);
$stmt->execute();
$run = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$run) {
    respond(['success' => false, 'error' => 'Archived run not found.'], 404);
}
if ($run['restored_at'] !== null) {
    respond(['success' => false, 'error' => 'This run was already restored.'], 422);
}

$scope = $run['scope'];

$conn->begin_transaction();
try {
    // Parents before children, so FK constraints on the live tables are
    // satisfied as each INSERT runs.
    if ($scope === 'inventory_counts' || $scope === 'all') {
        $conn->query(
            "INSERT INTO inventory_count_batches (batch_id, batch_number, assigned_to, assigned_by, assigned_at, due_date, notes, status, submitted_at, confirmed_by, confirmed_at)
             SELECT batch_id, batch_number, assigned_to, assigned_by, assigned_at, due_date, notes, status, submitted_at, confirmed_by, confirmed_at
             FROM archived_inventory_count_batches WHERE run_id = $runId"
        );
        $conn->query(
            "INSERT INTO inventory_count_items (item_id, batch_id, medicine_id, system_count, staff_count, final_count, remarks)
             SELECT item_id, batch_id, medicine_id, system_count, staff_count, final_count, remarks
             FROM archived_inventory_count_items WHERE run_id = $runId"
        );
    }

    if ($scope === 'medicine_batches' || $scope === 'all') {
        $conn->query(
            "INSERT INTO medicine_batches (batch_id, medicine_id, source, brand, donor_notes, batch_no, units_received, units_remaining, expiry_date, unit_price, selling_price, received_at, created_by)
             SELECT batch_id, medicine_id, source, brand, donor_notes, batch_no, units_received, units_remaining, expiry_date, unit_price, selling_price, received_at, created_by
             FROM archived_medicine_batches WHERE run_id = $runId"
        );
        // Recompute rather than add-back, so this stays correct even if
        // some medicines already received new batches since the archive.
        $conn->query(
            'UPDATE inventory_medicines im
             SET im.current_stock = (SELECT COALESCE(SUM(mb.units_remaining), 0) FROM medicine_batches mb WHERE mb.medicine_id = im.medicine_id)'
        );
    }

    if ($scope === 'reports' || $scope === 'reports_dispensing' || $scope === 'medicine_batches' || $scope === 'all') {
        $conn->query(
            "INSERT INTO medicine_exit_log (log_id, medicine_id, batch_id, boxes_scanned, units_deducted, staff_id, barcode, scanned_at)
             SELECT log_id, medicine_id, batch_id, boxes_scanned, units_deducted, staff_id, barcode, scanned_at
             FROM archived_medicine_exit_log WHERE run_id = $runId"
        );
    }

    if ($scope === 'reports' || $scope === 'reports_counts' || $scope === 'all') {
        $conn->query(
            "INSERT INTO inventory_count_reports (report_id, batch_id, report_name, saved_at, saved_by, total_items, total_count, snapshot_data)
             SELECT report_id, batch_id, report_name, saved_at, saved_by, total_items, total_count, snapshot_data
             FROM archived_inventory_count_reports WHERE run_id = $runId"
        );
    }

    // Clear the archive tables for this run now that the data is back
    // live (archive_runs itself is kept, marked restored, as a record
    // that this happened).
    $conn->query("DELETE FROM archived_inventory_count_items WHERE run_id = $runId");
    $conn->query("DELETE FROM archived_inventory_count_batches WHERE run_id = $runId");
    $conn->query("DELETE FROM archived_medicine_batches WHERE run_id = $runId");
    $conn->query("DELETE FROM archived_medicine_exit_log WHERE run_id = $runId");
    $conn->query("DELETE FROM archived_inventory_count_reports WHERE run_id = $runId");

    $markRestored = $conn->prepare('UPDATE archive_runs SET restored_at = NOW(), restored_by = ? WHERE run_id = ?');
    $markRestored->bind_param('ii', $pharmacistId, $runId);
    $markRestored->execute();
    $markRestored->close();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    respond(['success' => false, 'error' => 'Could not restore this archive. Please try again.'], 500);
}

write_audit_log(
    $conn,
    $pharmacistId,
    'pharmacist',
    'data_restored',
    'inventory',
    "Restored archive run #{$runId} (scope: {$scope})."
);

respond(['success' => true, 'message' => 'Archived data has been restored.', 'run_id' => $runId]);
