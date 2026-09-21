<?php
// includes/asset_helpers.php
//
// FIXED 2026-09-20: originally added as a one-off local function inside
// patient/book-appointment.php to fix a checkbox-layout bug that turned
// out to be stale browser-cached CSS, not a code bug (the fix: put each
// stylesheet's own last-modified time in its URL, so the URL itself
// changes the instant the file's content does and a browser can't serve
// a stale copy without a hard refresh). Pulled out into its own shared
// file immediately after, rather than copy-pasting that function into
// every other page that could hit the exact same caching problem -
// which is any of them, since they all load the same shared
// stylesheets.

// includes/ is always exactly one level under the project root, same as
// every page directory that calls asset_url() (patient/, staff/,
// admin/, doctor/) - so this constant works for all of them without
// needing to know which one is actually calling it.
if (!defined('GABAYMED_ROOT')) {
    define('GABAYMED_ROOT', dirname(__DIR__));
}

/**
 * Appends a cache-busting ?v=<file's last-modified time> to a stylesheet
 * (or any static asset) URL. $relativePath is written exactly as it
 * would appear in the <link href="..."> - relative to the CALLING
 * page's own directory, e.g. "../assets/css/booking.css" - not relative
 * to this file's own location.
 */
function asset_url(string $relativePath): string
{
    $fsPath = GABAYMED_ROOT . '/' . preg_replace('#^\.\./#', '', $relativePath);
    $v = @filemtime($fsPath) ?: time();
    return $relativePath . '?v=' . $v;
}
