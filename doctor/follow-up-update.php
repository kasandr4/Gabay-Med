<?php
// doctor/follow-up-update.php
// Handles "Mark Completed" and "Cancel" from the follow-up list on follow-up.php.
// Deliberately does NOT handle "missed" - that status is meant for a
// scheduled follow-up whose date passed with no action taken, which is a
// job for a scheduled/cron process, not something a doctor clicks.

require_once '../includes/auth_guard.php';
require_role('doctor');
require_once '../config/db.php';
require_once '../includes/csrf.php';

function backToFollowUps($type, $message)
{
    $_SESSION['followup_flash'] = ["type" => $type, "message" => $message];
    header("Location: follow-up.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: follow-up.php");
    exit;
}

require_csrf('follow-up.php', 'followup_flash');

$doctorId = (int) $_SESSION['user_id'];
$followUpId = isset($_POST['follow_up_id']) ? (int) $_POST['follow_up_id'] : 0;
$action = $_POST['action'] ?? '';

$actionToStatus = [
    'complete' => 'completed',
    'cancel'   => 'cancelled',
];

if ($followUpId <= 0 || !isset($actionToStatus[$action])) {
    backToFollowUps("error", "That follow-up action could not be completed. Please try again.");
}

$newStatus = $actionToStatus[$action];

// Ownership + state check in one query: only this doctor's own follow-ups,
// and only while still 'scheduled' (no re-completing/re-cancelling something
// that's already been resolved).
$stmt = $conn->prepare(
    "UPDATE follow_ups
     SET status = ?
     WHERE follow_up_id = ? AND doctor_id = ? AND status = 'scheduled'"
);
$stmt->bind_param("sii", $newStatus, $followUpId, $doctorId);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($affected === 0) {
    backToFollowUps("error", "That follow-up could not be updated. It may have already been resolved.");
}

$message = $newStatus === 'completed' ? "Follow-up marked as completed." : "Follow-up cancelled.";
backToFollowUps("success", $message);
