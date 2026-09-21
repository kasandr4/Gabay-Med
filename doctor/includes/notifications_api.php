<?php
// doctor/includes/notifications_api.php
// Small JSON API backing the notification bell dropdown. Called via
// fetch() from sidebar.php's JavaScript. Doctor-only, session-scoped —
// every query is filtered by the logged-in doctor's own ID.
// Mirrors patient/includes/notifications_api.php exactly, just re-scoped.

require_once '../../includes/auth_guard.php';
require_role('doctor');
require_once '../../config/db.php';
require_once '../../includes/notifications.php';

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

switch ($action) {

    case 'list':
        $stmt = $conn->prepare("
            SELECT notification_id, message, type, link, is_read, created_at
            FROM notifications
            WHERE recipient_id = ?
            ORDER BY created_at DESC
            LIMIT 20
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Add a human-friendly "time ago" string for each notification
        foreach ($rows as &$row) {
            $row['time_ago'] = time_ago($row['created_at']);
        }

        echo json_encode(['notifications' => $rows]);
        break;

    case 'mark_read':
        $notification_id = (int)($_GET['id'] ?? 0);
        // Ownership check: can only mark YOUR OWN notifications as read
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND recipient_id = ?");
        $stmt->bind_param("ii", $notification_id, $user_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true]);
        break;

    case 'mark_all_read':
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE recipient_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true]);
        break;

    case 'count':
        $count = get_unread_notification_count($conn, $user_id);
        echo json_encode(['count' => $count]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}

/**
 * Converts a MySQL datetime into a short human-friendly "time ago" string.
 */
function time_ago($datetime)
{
    $diff = time() - strtotime($datetime);

    if ($diff < 60) return "just now";
    if ($diff < 3600) return floor($diff / 60) . "m ago";
    if ($diff < 86400) return floor($diff / 3600) . "h ago";
    if ($diff < 604800) return floor($diff / 86400) . "d ago";
    return date('M j', strtotime($datetime));
}
