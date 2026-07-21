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
 */
function create_notification($conn, $recipient_id, $message, $link = null) {
    $stmt = $conn->prepare("INSERT INTO notifications (recipient_id, message, link) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $recipient_id, $message, $link);
    $stmt->execute();
    $stmt->close();
}

/**
 * Returns the unread notification count for a user. Used to drive the
 * sidebar bell badge.
 */
function get_unread_notification_count($conn, $user_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM notifications WHERE recipient_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $count = $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
    return (int)$count;
}
?>
