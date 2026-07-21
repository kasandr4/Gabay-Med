<?php
// pharmacist/reconciliation-actions.php
// JSON action endpoint backing reconciliation.php's "Calculate & Compare"
// and "Submit Reconciliation". Mirrors delivery-actions.php /
// exit-actions.php's conventions: single action-routed JSON endpoint,
// CSRF-checked, prepared statements only.
//
// system_count is SUM(medicine_exit_log.units_deducted) for the medicine
// within the period, not a raw row COUNT(*) — the TODO(backend) comment
// this replaced said COUNT(*), but that would undercount every time a
// single scan released more than one box (boxes field on that form is
// editable for exactly that case). units_deducted is already the
// unit-denominated ledger entry, same one exit-actions.php writes.
//
// submit_reconciliation recomputes system_count itself rather than
// trusting whatever calculate_compare returned earlier in the browser
// session — the same reason a checkout endpoint recomputes a price
// server-side instead of trusting a hidden form field.

require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/csrf.php';

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

/**
 * SUM(units_deducted) from medicine_exit_log for one medicine, over
 * [periodStart, periodEnd] inclusive. scanned_at is a full timestamp, so
 * the end bound is "before the day after periodEnd" rather than a plain
 * BETWEEN, or scans made later in periodEnd's day would be missed.
 */
function systemCountFor(mysqli $conn, int $medicineId, string $periodStart, string $periodEnd): int
{
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(units_deducted), 0) AS total
         FROM medicine_exit_log
         WHERE medicine_id = ? AND scanned_at >= ? AND scanned_at < DATE_ADD(?, INTERVAL 1 DAY)"
    );
    $stmt->bind_param("iss", $medicineId, $periodStart, $periodEnd);
    $stmt->execute();
    $total = (int) $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    return $total;
}

$action = $_POST['action'] ?? '';
$pharmacistId = (int) $_SESSION['user_id'];

switch ($action) {

    // ------------------------------------------------------------
    // Calculate & Compare — live preview only, nothing written yet.
    // ------------------------------------------------------------
    case 'calculate_compare': {
            $medicineId = (int) ($_POST['medicine_id'] ?? 0);
            $periodStart = $_POST['period_start'] ?? '';
            $periodEnd = $_POST['period_end'] ?? '';
            $actualCount = (int) ($_POST['actual_count'] ?? -1);

            if ($medicineId <= 0 || !$periodStart || !$periodEnd || $actualCount < 0) {
                respond(['success' => false, 'error' => 'Fill in the medicine, period, and physical count first.'], 422);
            }
            if ($periodEnd < $periodStart) {
                respond(['success' => false, 'error' => 'Period End can\'t be before Period Start.'], 422);
            }

            $systemCount = systemCountFor($conn, $medicineId, $periodStart, $periodEnd);
            $difference = $actualCount - $systemCount;

            respond([
                'success'      => true,
                'system_count' => $systemCount,
                'difference'   => $difference,
                'is_match'     => $difference === 0,
            ]);
            break;
        }

    // ------------------------------------------------------------
    // Submit Reconciliation — recomputes system_count fresh (see header
    // comment) and inserts the finalized row.
    // ------------------------------------------------------------
    case 'submit_reconciliation': {
            $medicineId = (int) ($_POST['medicine_id'] ?? 0);
            $periodStart = $_POST['period_start'] ?? '';
            $periodEnd = $_POST['period_end'] ?? '';
            $actualCount = (int) ($_POST['actual_count'] ?? -1);

            if ($medicineId <= 0 || !$periodStart || !$periodEnd || $actualCount < 0) {
                respond(['success' => false, 'error' => 'Fill in the medicine, period, and physical count first.'], 422);
            }
            if ($periodEnd < $periodStart) {
                respond(['success' => false, 'error' => 'Period End can\'t be before Period Start.'], 422);
            }

            $medStmt = $conn->prepare("SELECT medicine_id FROM inventory_medicines WHERE medicine_id = ?");
            $medStmt->bind_param("i", $medicineId);
            $medStmt->execute();
            $found = $medStmt->get_result()->fetch_assoc();
            $medStmt->close();
            if (!$found) {
                respond(['success' => false, 'error' => 'Medicine not found.'], 404);
            }

            $systemCount = systemCountFor($conn, $medicineId, $periodStart, $periodEnd);
            $isMatch = ($actualCount === $systemCount);
            $status = $isMatch ? 'match' : 'mismatch';

            $ins = $conn->prepare(
                "INSERT INTO inventory_reconciliation
                    (medicine_id, period_start, period_end, system_count, actual_count, status, submitted_by, submitted_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $ins->bind_param("issiisi", $medicineId, $periodStart, $periodEnd, $systemCount, $actualCount, $status, $pharmacistId);
            $ins->execute();
            $ins->close();

            respond([
                'success'      => true,
                'system_count' => $systemCount,
                'difference'   => $actualCount - $systemCount,
                'is_match'     => $isMatch,
            ]);
            break;
        }

    default:
        respond(['success' => false, 'error' => 'Unknown action.'], 400);
}
