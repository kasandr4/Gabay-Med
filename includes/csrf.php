<?php
// includes/csrf.php
// Minimal CSRF protection — no libraries, matching the rest of this
// project (native PHP only). One token per session, checked on every
// state-changing POST.
//
/*
 * Usage in a page that RENDERS a form:
 *   require_once '../includes/csrf.php';
 *   ... inside the <form> ...
 *   <?= csrf_field() ?>
 *
 * Usage in a file that PROCESSES a POST (at the very top, after
 * auth_guard/require_role, before touching the database):
 * require_once '../includes/csrf.php';
 * require_csrf();
 *
 * This assumes session_start() has already run (auth_guard.php does this
 * on every protected page; login.php/register.php also start their own).
 */

/**
 * Returns the current session's CSRF token, generating one the first
 * time it's needed. Same token is reused for the life of the session
 * (regenerated automatically on login via session_regenerate_id, which
 * also clears session data, so a new token is naturally created after
 * that).
 */
function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Outputs a ready-to-use hidden input for a <form>. Echo this directly
 * inside the form tag: <?= csrf_field() ?>
 */
function csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Call at the top of any script that processes a POST request, before
 * doing anything else. Silently redirects back with a flash-style error
 * if the token is missing/invalid, rather than throwing a raw 403 - most
 * of this app's process files already redirect back with $_SESSION
 * flash messages, so this matches that pattern instead of introducing a
 * new failure mode.
 *
 * $redirect_to: where to send the user back to on failure (relative to
 * the calling script's own folder, same as this app's other redirects).
 * $flash_key: the $_SESSION key this page already uses for its flash
 * message (e.g. 'checkin_flash', 'consultation_flash'), so the failure
 * shows up through the same banner the page already renders. Defaults
 * to 'csrf_flash' for pages that don't have their own flash convention.
 */
function require_csrf($redirect_to = 'index.php', $flash_key = 'csrf_flash')
{
    $submitted = $_POST['csrf_token'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';

    if ($expected === '' || !hash_equals($expected, $submitted)) {
        $_SESSION[$flash_key] = [
            "type" => "error",
            "message" => "Your session expired or this form was already submitted. Please try again.",
        ];
        header("Location: " . $redirect_to);
        exit;
    }
}
