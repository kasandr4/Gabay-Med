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

function require_role($allowed_role) {
    // Not logged in at all? Send them to login.
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        header("Location: ../login.php");
        exit;
    }

    // Logged in, but wrong role for this page? Block access.
    if ($_SESSION['role'] !== $allowed_role) {
        header("Location: ../login.php");
        exit;
    }
}
?>
