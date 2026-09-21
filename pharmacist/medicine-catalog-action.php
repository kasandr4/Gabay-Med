<?php
// pharmacist/medicine-catalog-action.php
// Manages the controlled Unit list used by the Medicine Catalog and
// Record Stock Batch pages. Medicine + batch creation itself lives in
// record-stock-batch-actions.php.
//
// CATEGORY RETIRED: this endpoint used to also manage medicine_categories
// (add_category/remove_category). The pharmacy only manages medicine
// stock (no equipment/supplies in the real inventory), and clinical
// category was never functionally used beyond an equipment/supplies
// exclusion — see the migration dropping medicine_categories and
// medicine_names.category. Units are still tracked, so that half of
// this endpoint is unchanged.

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Invalid request method.'], 405);
}

$submittedToken = $_POST['csrf_token'] ?? '';
$expectedToken = $_SESSION['csrf_token'] ?? '';
if ($expectedToken === '' || !hash_equals($expectedToken, $submittedToken)) {
    respond(['success' => false, 'error' => 'Your session expired. Please refresh the page and try again.'], 403);
}

$action = $_POST['action'] ?? '';
$pharmacistId = (int) $_SESSION['user_id'];

function catalogName(string $value, int $maxLength): string
{
    $value = trim($value);
    if ($value === '' || strlen($value) > $maxLength) {
        respond(['success' => false, 'error' => 'Enter a valid catalog name.'], 422);
    }
    return $value;
}

if ($action === 'add_unit') {
    $name = catalogName($_POST['name'] ?? '', 30);

    $check = $conn->prepare("SELECT unit_id FROM medicine_units WHERE LOWER(name) = LOWER(?) LIMIT 1");
    $check->bind_param('s', $name);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();
    $check->close();
    if ($exists) {
        respond(['success' => false, 'error' => 'That entry already exists.'], 422);
    }

    $insert = $conn->prepare("INSERT INTO medicine_units (name) VALUES (?)");
    $insert->bind_param('s', $name);
    try {
        $insert->execute();
    } catch (Throwable $e) {
        $insert->close();
        respond(['success' => false, 'error' => 'Could not add that entry.'], 500);
    }
    $insert->close();
    write_audit_log($conn, $pharmacistId, 'pharmacist', 'unit_added', 'medicine_catalog', "Added unit '{$name}'.");
    respond(['success' => true, 'message' => 'Unit added.']);
}

if ($action === 'remove_unit') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        respond(['success' => false, 'error' => 'Invalid catalog entry.'], 422);
    }

    $lookup = $conn->prepare("SELECT name FROM medicine_units WHERE unit_id = ?");
    $lookup->bind_param('i', $id);
    $lookup->execute();
    $entry = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$entry) respond(['success' => false, 'error' => 'Unit not found.'], 404);

    $used = $conn->prepare("SELECT COUNT(*) AS total FROM inventory_medicines WHERE LOWER(unit) = LOWER(?)");
    $used->bind_param('s', $entry['name']);
    $used->execute();
    $usageCount = (int) $used->get_result()->fetch_assoc()['total'];
    $used->close();
    if ($usageCount > 0) {
        respond(['success' => false, 'error' => 'This entry is in use by existing medicines and cannot be removed.'], 409);
    }

    $delete = $conn->prepare("DELETE FROM medicine_units WHERE unit_id = ?");
    $delete->bind_param('i', $id);
    $delete->execute();
    $delete->close();
    write_audit_log($conn, $pharmacistId, 'pharmacist', 'unit_removed', 'medicine_catalog', "Removed unit '{$entry['name']}'.");
    respond(['success' => true, 'message' => 'Unit removed.']);
}

respond(['success' => false, 'error' => 'Unknown action.'], 400);
