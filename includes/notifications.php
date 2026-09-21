<?php
// includes/notifications.php
// Shared helper for creating and reading notifications.
// Include this from any page/script that needs to fire a notification,
// or that needs to display the bell icon / notification list.

/**
 * Creates a notification for a given user.
 *
 * @param mysqli $conn Active database connection
 * @param int $recipient_id The user_id to notify
 * @param string $message The notification text
 * @param string|null $link Relative path to navigate to when clicked (e.g. 'dashboard.php')
 * @param string $type One of 'info' (default), 'success', 'warning', 'critical' —
 *                      drives which icon the bell UI shows. See
 *                      006_add_notification_type.sql for what each means.
 */
function create_notification($conn, $recipient_id, $message, $link = null, $type = 'info')
{
    $stmt = $conn->prepare("INSERT INTO notifications (recipient_id, message, link, type) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $recipient_id, $message, $link, $type);
    $stmt->execute();
    $stmt->close();
}

/**
 * Returns the unread notification count for a user. Used to drive the
 * sidebar bell badge.
 */
function get_unread_notification_count($conn, $user_id)
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM notifications WHERE recipient_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $count = $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
    return (int)$count;
}

/**
 * All current admin user_ids — every "notify the whole admin team"
 * trigger (critical stock, batch shortfall, new purchase request) shares
 * this instead of each querying it separately.
 */
function all_admin_ids($conn): array
{
    $ids = [];
    $result = $conn->query("SELECT user_id FROM users WHERE role = 'admin'");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['user_id'];
        }
    }
    return $ids;
}

/**
 * All active laboratory-staff user_ids (role 'staff', staff_type
 * 'laboratory') - the recipients for "a doctor just ordered lab tests".
 * Mirrors all_admin_ids() above.
 */
function all_lab_staff_ids($conn): array
{
    $ids = [];
    $result = $conn->query("SELECT user_id FROM users WHERE role = 'staff' AND staff_type = 'laboratory' AND is_active = 1");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['user_id'];
        }
    }
    return $ids;
}

/**
 * Notifies the patient and every laboratory staff member that tests were
 * just ordered. One notification per submission (not per test) so a
 * multi-test order doesn't spam anyone. $doctorId is only used to
 * resolve the "Dr. Name" shown to the patient.
 *
 * @param string[] $testNames
 */
function notify_lab_order_created($conn, int $patientId, int $doctorId, array $testNames): void
{
    if (empty($testNames)) {
        return;
    }

    $stmt = $conn->prepare("SELECT role, first_name, last_name FROM users WHERE user_id IN (?, ?)");
    $stmt->bind_param("ii", $patientId, $doctorId);
    $stmt->execute();
    $names = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $names[$row['role']] = trim($row['first_name'] . ' ' . $row['last_name']);
    }
    $stmt->close();

    $count = count($testNames);
    $summary = $count === 1 ? $testNames[0] : $count . ' tests';
    $doctorLabel = isset($names['doctor']) ? 'Dr. ' . $names['doctor'] : 'Your doctor';
    $patientLabel = $names['patient'] ?? 'a patient';

    create_notification(
        $conn,
        $patientId,
        "{$doctorLabel} ordered {$summary} for you. You can track the status under Laboratory.",
        'lab-orders.php'
    );

    foreach (all_lab_staff_ids($conn) as $labStaffId) {
        create_notification(
            $conn,
            $labStaffId,
            "New lab request: {$summary} for {$patientLabel} from {$doctorLabel}.",
            'lab-queue.php'
        );
    }
}

/**
 * All current pharmacist user_ids — mirrors all_admin_ids() above.
 * Used by staff/dispense-actions.php's void-line actions (2026-09-07) to
 * let the pharmacist team know when a line was voided specifically
 * because a medicine isn't in the catalog, since that may be a real
 * catalog gap worth adding rather than legacy data.
 */
function all_pharmacist_ids($conn): array
{
    $ids = [];
    $result = $conn->query("SELECT user_id FROM users WHERE role = 'pharmacist'");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['user_id'];
        }
    }
    return $ids;
}
