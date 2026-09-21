<?php
// admin/cancellation-requests-actions.php
// JSON action endpoint backing admin/cancellation-requests.php. Same
// pattern as admin/user-management-actions.php: single action-routed
// endpoint, CSRF-checked, prepared statements only, and the same
// exception/fatal-error handlers so this endpoint always answers with
// real JSON even on an unexpected failure.
//
// Both actions here just call straight into includes/cancellation_policy.php
// (approve_cancellation_request / reject_cancellation_request) — this file
// has no cancellation logic of its own, it only handles the HTTP/JSON
// plumbing and role/CSRF checks.

require_once '../includes/auth_guard.php';
require_role('admin');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/cancellation_policy.php';

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('cancellation-requests-actions.php: ' . $e->getMessage());
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'error' => 'Something went wrong on our end. Please try again.']);
    exit;
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('cancellation-requests-actions.php fatal: ' . $error['message']);
        http_response_code(500);
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['success' => false, 'error' => 'Something went wrong on our end. Please try again.']);
    }
});

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
$adminId = (int) $_SESSION['user_id'];
$requestId = (int) ($_POST['request_id'] ?? 0);

if ($requestId <= 0) {
    respond(['success' => false, 'error' => 'Missing request information. Please refresh and try again.'], 422);
}

switch ($action) {
    case 'approve_cancellation_request': {
            $result = approve_cancellation_request($conn, $requestId, $adminId);
            respond($result, $result['success'] ? 200 : 409);
            break;
        }

    case 'reject_cancellation_request': {
            $note = $_POST['note'] ?? '';
            $result = reject_cancellation_request($conn, $requestId, $adminId, $note);
            respond($result, $result['success'] ? 200 : 409);
            break;
        }

    default:
        respond(['success' => false, 'error' => 'Unknown action.'], 400);
}
