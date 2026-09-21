<?php
// logout.php
// Destroys the session, logging the user out, then sends them back to the homepage.

session_start();

// Capture who's signing out BEFORE session_unset() wipes it, so the audit
// entry below still knows the user's id/role. If there's no session (e.g.
// someone hits logout.php directly while already logged out), there's
// nothing to log.
$loggingOutUserId = $_SESSION['user_id'] ?? null;
$loggingOutRole    = $_SESSION['role'] ?? null;

session_unset();    // clear all session variables
session_destroy();  // destroy the session itself

if ($loggingOutUserId !== null && $loggingOutRole !== null) {
    require_once 'config/db.php';
    require_once 'includes/audit_log.php';
    write_audit_log($conn, (int) $loggingOutUserId, $loggingOutRole, 'logout', 'auth', 'Signed out.');
}

header("Location: index.php");
exit;
