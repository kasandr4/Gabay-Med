<?php
// pharmacist/export-purchase-request.php
// Downloads an APPROVED Purchase Request as a CSV file (not PDF), named
// after the PR number, e.g. PR-2026-0004.csv. This is the file that is
// forwarded to Capitol MANUALLY - GabayMed does not send it anywhere,
// create a PO, or track what Capitol does with it.
//
// Columns are exactly:
//   PR Number, Date, Staff, Approved By, Medicine, Unit, Requested Quantity
// (no Brand, Estimated Unit Price, Qty Add, PO Number or Capitol info).
//
// Readable by the pharmacist, and by the inventory Staff member who
// submitted the PR. Only approved PRs can be exported; Staff can only
// export their own.

require_once '../includes/auth_guard.php';
require_role(['pharmacist', 'staff']);
if ($_SESSION['role'] === 'staff') {
    require_staff_type('inventory');
}
require_once '../config/db.php';
require_once '../includes/excel_helper.php';

$prId = (int) ($_GET['pr_id'] ?? 0);
if ($prId <= 0) {
    http_response_code(400);
    exit('Missing pr_id.');
}

$stmt = $conn->prepare(
    "SELECT pr.pr_id, pr.pr_no, pr.status, pr.created_at, pr.requested_by, pr.approved_by_name,
            TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS staff_name
     FROM purchase_requests pr
     LEFT JOIN users u ON u.user_id = pr.requested_by
     WHERE pr.pr_id = ?"
);
$stmt->bind_param('i', $prId);
$stmt->execute();
$pr = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$pr) {
    http_response_code(404);
    exit('Purchase request not found.');
}

// 'sent_to_capitol' / 'converted' are legacy values of the retired
// Capitol hand-off and count as approved.
$approvedStatuses = ['approved', 'sent_to_capitol', 'converted'];
if (!in_array($pr['status'], $approvedStatuses, true)) {
    http_response_code(409);
    exit('Only approved purchase requests can be exported.');
}
if ($_SESSION['role'] === 'staff' && (int) $pr['requested_by'] !== (int) $_SESSION['user_id']) {
    http_response_code(403);
    exit('You can only export your own purchase requests.');
}

$itemsStmt = $conn->prepare(
    'SELECT generic_name, strength, unit, qty_requested
     FROM purchase_request_items WHERE pr_id = ? ORDER BY pri_id ASC'
);
$itemsStmt->bind_param('i', $prId);
$itemsStmt->execute();
$items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$itemsStmt->close();

$date = date('Y-m-d', strtotime($pr['created_at']));
$staffName = $pr['staff_name'] !== '' ? $pr['staff_name'] : 'Unknown';
$approvedBy = $pr['approved_by_name'] !== null && $pr['approved_by_name'] !== '' ? $pr['approved_by_name'] : '';

$rows = [];
foreach ($items as $item) {
    // Older pharmacist-made PRs keep strength in its own column; staff-made
    // PRs already have it inside the medicine name. Add it only if missing.
    $medicine = $item['generic_name'];
    if (!empty($item['strength']) && stripos($medicine, $item['strength']) === false) {
        $medicine .= ' ' . $item['strength'];
    }
    $rows[] = [
        $pr['pr_no'],
        $date,
        $staffName,
        $approvedBy,
        $medicine,
        $item['unit'],
        (int) $item['qty_requested'],
    ];
}

$filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $pr['pr_no']) . '.csv';
csv_download(
    $filename,
    ['PR Number', 'Date', 'Staff', 'Approved By', 'Medicine', 'Unit', 'Requested Quantity'],
    $rows
);
