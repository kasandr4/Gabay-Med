<?php
// includes/status_labels.php
//
// Single source of truth for how prescription and lab-order statuses are
// DISPLAYED to a person - never for what they mean to the database
// (those are just the plain enum values: prescriptions.status is
// pending/dispensed/void, lab_orders.status is pending/accepted/
// completed - nothing here changes what gets stored or how the backend
// decides transitions, only how each value reads on screen).
//
// FIXED 2026-09-20: before this file existed, the same label -> text
// mapping was hand-copied into patient/appointment-history.php,
// staff/lab-queue.php, and the JS files for prescriptions.php and
// lab-orders.php separately - four places that had to be kept in sync
// by hand, with nothing enforcing that they actually were. Now there's
// exactly one place each side (PHP callers use the functions below
// directly; JS callers read the same array via a page-embedded JSON
// global instead of hardcoding their own copy - see prescriptions.js/
// lab-orders.js's own header comments for that half of it).
//
// NOT consolidated here: appointment status (admin/hospital-reports.php),
// schedule-override status (doctor-schedule.js), and user account status
// (user-management.js) each have their own, genuinely different, label
// set - they were never actually duplicated with each other or with
// these two, just superficially similar-looking hardcoded arrays. Folding
// unrelated domains into one file because they rhyme would make this
// file responsible for things it has no real business knowing about.

/**
 * @return array<string,string> prescriptions.status / confinement_discharge_
 *   medications.status value => display label.
 */
function get_rx_status_labels(): array
{
    return [
        'pending' => 'Pending',
        'dispensed' => 'Dispensed',
        'void' => 'Void',
    ];
}

/**
 * @return array<string,string> lab_orders.status value => display label.
 */
function get_lab_status_labels(): array
{
    return [
        'pending' => 'Pending',
        'accepted' => 'In Progress',
        'completed' => 'Results Ready',
    ];
}

/**
 * Background/foreground colors for the lab status badge on
 * staff/lab-queue.php. Only 'pending'/'accepted' are ever actually
 * rendered there (a completed order drops off that queue entirely - see
 * that file's own query, status IN ('pending','accepted')) but
 * 'completed' is included anyway so this stays a complete, reusable
 * color set rather than one that would silently need a third entry
 * added the moment somewhere else wants to show a completed badge too.
 *
 * @return array<string,array{bg:string,fg:string}>
 */
function get_lab_status_colors(): array
{
    return [
        'pending' => ['bg' => '#FEF3E2', 'fg' => '#8A5A00'],
        'accepted' => ['bg' => '#E6F1FB', 'fg' => '#0C447C'],
        'completed' => ['bg' => '#EAF7F2', 'fg' => '#1F6F5C'],
    ];
}
