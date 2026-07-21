<?php
// includes/auth_guard.php
//
// Drop this at the TOP of any page that should only be visible to
// logged-in users of a specific role. It checks the session and
// kicks out anyone who shouldn't be there.
//
// USAGE (put this at the very top of the page, before any HTML):
//   require_once '../includes/auth_guard.php';
//   require_role('patient');   // only patients can view this page
//
// session_start() must run before any HTML output, which is why
// this file should be the very first thing included.

session_start();

/**
 * Builds a working relative path back to the project root's login.php,
 * regardless of how deep the calling script lives (e.g. 'patient/dashboard.php'
 * is 1 level deep, but 'patient/includes/notifications_api.php' is 2 levels
 * deep). A hardcoded '../login.php' only works for the 1-level case — for
 * anything deeper, the browser resolves it to a non-existent path (e.g.
 * 'patient/login.php'), which 404s instead of redirecting to the real
 * login page. This walks up from the calling script's real folder to this
 * file's parent folder (the project root) and counts how many '../' are
 * actually needed.
 */
function login_redirect_path()
{
    $projectRoot = realpath(__DIR__ . '/..');
    $callerDir = realpath(dirname($_SERVER['SCRIPT_FILENAME']));

    if ($callerDir === false || strpos($callerDir, $projectRoot) !== 0) {
        // Fallback: same behavior as before if depth can't be determined
        return '../login.php';
    }

    $relative = ltrim(substr($callerDir, strlen($projectRoot)), DIRECTORY_SEPARATOR);
    $depth = ($relative === '') ? 0 : substr_count($relative, DIRECTORY_SEPARATOR) + 1;

    return str_repeat('../', $depth) . 'login.php';
}

function require_role($allowed_role)
{
    // Not logged in at all? Send them to login.
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        header("Location: " . login_redirect_path());
        exit;
    }

    // Accepts either a single role string (existing behavior, unchanged
    // for every other call site) or an array of allowed roles, for the
    // rare page/endpoint more than one role is allowed to reach.
    $allowed = is_array($allowed_role) ? $allowed_role : [$allowed_role];
    if (!in_array($_SESSION['role'], $allowed, true)) {
        header("Location: " . login_redirect_path());
        exit;
    }
}
