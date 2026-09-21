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

/**
 * True for the *-actions.php JSON endpoints (user-management-actions.php,
 * inventory-actions.php, delivery-actions.php, etc.) - anything hit via
 * fetch() rather than a normal page load. A redirect Location header is
 * useless to those callers: fetch() follows it, lands on login.php's
 * HTML, and res.json() throws - which every one of those endpoints'
 * postAction()-style JS wrappers catches and reports as a generic
 * "Network error", hiding the real "please log in again" reason. Session
 * expiry from those endpoints needs to come back as JSON instead.
 */
function is_json_action_endpoint()
{
    return substr($_SERVER['SCRIPT_NAME'], -12) === '-actions.php';
}

function require_role($allowed_role)
{
    // Not logged in at all? Send them to login - or, for a JSON action
    // endpoint, respond with a JSON 401 so the calling page's fetch()
    // can surface the real reason instead of a generic network error.
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        if (is_json_action_endpoint()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Your session has expired. Please refresh the page and log in again.']);
            exit;
        }
        header("Location: " . login_redirect_path());
        exit;
    }

    // Accepts either a single role string (existing behavior, unchanged
    // for every other call site) or an array of allowed roles, for the
    // rare page/endpoint more than one role is allowed to reach.
    $allowed = is_array($allowed_role) ? $allowed_role : [$allowed_role];
    if (!in_array($_SESSION['role'], $allowed, true)) {
        if (is_json_action_endpoint()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => "You don't have permission to do that."]);
            exit;
        }
        header("Location: " . login_redirect_path());
        exit;
    }
}

/**
 * Staff is one role but two unrelated jobs (see 013_staff_subtype.sql):
 * Front Desk (check-in, walk-in booking, schedule conflicts) and
 * Inventory Counting. Call this AFTER require_role('staff') on every
 * staff page/endpoint so a front-desk account can't land on an
 * inventory-count page (or vice versa) just because both share the same
 * 'staff' role — same "enforced server-side, not just hidden in the
 * sidebar" principle used everywhere else in this app.
 *
 * USAGE:
 *   require_role('staff');
 *   require_staff_type('inventory');   // or 'front_desk'
 */
function require_staff_type($allowed_type)
{
    $allowed = is_array($allowed_type) ? $allowed_type : [$allowed_type];
    if (!isset($_SESSION['staff_type']) || !in_array($_SESSION['staff_type'], $allowed, true)) {
        if (is_json_action_endpoint()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => "You don't have permission to do that."]);
            exit;
        }
        header("Location: " . login_redirect_path());
        exit;
    }
}

/**
 * Blocks a patient whose account was suspended after 3 no-shows (see
 * includes/no_show_policy.php - users.status = 'blocked') from every
 * patient page except blocked.php itself, which explains why and how to
 * get unblocked.
 *
 * FIXED 2026-08-08: patient/dashboard.php, book-appointment.php,
 * book-appointment-actions.php, and confinement-dashboard.php each had
 * their OWN copy of this exact check - but appointment-history.php,
 * prescriptions.php, profile.php, and lookup-visit.php had none at all.
 * Since login.php always lands a patient on dashboard.php first, a
 * blocked patient WOULD get redirected to blocked.php in the normal
 * flow - but navigating directly to one of the un-checked pages (a
 * bookmark, browser history, or a typed URL) bypassed the block
 * entirely. Centralized here instead of a 5th/6th/7th copy.
 *
 * Can't live inside require_role() above: this file loads (and
 * require_role() runs) BEFORE config/db.php on every page, so no $conn
 * exists yet at that point. Call this explicitly, AFTER config/db.php,
 * on every patient page except blocked.php - see that call site's own
 * comment for why it's excluded.
 */
function require_active_patient(mysqli $conn)
{
    $stmt = $conn->prepare("SELECT status FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && $row['status'] === 'blocked') {
        header("Location: blocked.php");
        exit;
    }
}
