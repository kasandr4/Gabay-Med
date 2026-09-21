<?php
// patient/book-appointment-actions.php
// JSON action endpoint backing the single-page booking form on
// patient/book-appointment.php. Mirrors the *-actions.php pattern already
// established elsewhere in this app (see admin/doctor-schedule-actions.php):
// one action-routed JSON endpoint, CSRF-checked, prepared statements only.
// Because the filename ends in "-actions.php", auth_guard.php's
// require_role() already knows to respond with JSON on an auth failure
// instead of redirecting - see is_json_action_endpoint() there.
//
// IMPORTANT: this endpoint ONLY powers the progressive UI - match checked
// symptoms to a department, auto-assign a doctor, and return that
// doctor's open-slot grid. It never writes to the database. The actual
// appointment INSERT still happens in book-appointment.php's
// "confirm_booking" handler, which re-validates everything from scratch
// rather than trusting anything computed here. That keeps the same
// "never trust the client" guarantee the old session-based wizard had,
// without needing session step state.
//
// REWORKED 2026-09-16: department/doctor are no longer patient choices.
// The old 3-action flow (recommend_department from free text -> get_doctors
// for a chosen/overridden department -> get_slots for a chosen doctor) is
// replaced by ONE action, match_symptoms, that does all three server-side:
// match checked symptom checkboxes to a department (symptom_catalog.php),
// pick the least-busy active doctor in that department who actually has
// an open slot in the booking window, and hand back that doctor's slot
// grid in the same shape get_slots used to return. get_slots itself is
// kept (not everything that built it is gone - includes/slot_grid.php's
// build_doctor_slot_grid() is still exactly what match_symptoms calls)
// in case a future flow (e.g. advance booking) needs to re-fetch a
// specific doctor's grid directly.

require_once '../includes/auth_guard.php';
require_role('patient');
require_once '../config/db.php';
require_once 'includes/symptom_catalog.php';
require_once '../includes/csrf.php';
require_once '../includes/schedule_resolver.php';
require_once '../includes/slot_grid.php';

header('Content-Type: application/json');

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

$patient_id = (int) $_SESSION['user_id'];

// A blocked/confined account means nothing on this page should be
// actionable - re-check here too, not just on the page's initial load, in
// case it changed mid-session (staff blocked the account, etc.).
$stmt = $conn->prepare("SELECT status FROM users WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$account_status = $stmt->get_result()->fetch_assoc()['status'] ?? '';
$stmt->close();

if (in_array($account_status, ['blocked', 'confined'], true)) {
    respond(['success' => false, 'error' => 'Your account cannot book right now. Please refresh the page.'], 403);
}

// One active appointment total, system-wide (see book-appointment.php's
// $active_appointments header comment for the policy history).
$stmt = $conn->prepare("SELECT appointment_id FROM appointments WHERE patient_id = ? AND status IN ('pending','confirmed') LIMIT 1");
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$has_pending = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($has_pending) {
    respond(['success' => false, 'error' => 'You already have an appointment scheduled. Please refresh the page.'], 403);
}

function has_active_appointment_in_department(mysqli $conn, int $patientId, int $departmentId): bool
{
    $stmt = $conn->prepare("
        SELECT appointment_id FROM appointments
        WHERE patient_id = ? AND department_id = ? AND status IN ('pending', 'confirmed')
        LIMIT 1
    ");
    $stmt->bind_param("ii", $patientId, $departmentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (bool) $row;
}

$action = $_POST['action'] ?? '';

switch ($action) {

    // ------------------------------------------------------------
    // Symptoms -> department -> auto-assigned doctor -> that doctor's
    // slot grid, all in one round trip. See this file's header comment
    // for why this replaced the old 3-action flow.
    // ------------------------------------------------------------
    case 'match_symptoms': {
            $submittedKeys = $_POST['symptoms'] ?? [];
            if (!is_array($submittedKeys)) {
                $submittedKeys = [];
            }

            // Advance booking (2026-09-17): optional start_date/num_days
            // let the patient jump the whole slot-grid window out to a
            // future month instead of always starting at today - see
            // patient/dashboard.php's "Advance Booking" button and
            // book-appointment.php's month picker. Both are optional and
            // independently validated; a missing or invalid value just
            // falls back to the normal "starting today" window rather
            // than erroring the whole match out, since this is a nice-to-
            // have on top of the core symptom-matching flow, not a
            // required input.
            $startDate = null;
            if (!empty($_POST['start_date'])) {
                $parsed = DateTime::createFromFormat('Y-m-d', $_POST['start_date']);
                if ($parsed && $parsed->format('Y-m-d') === $_POST['start_date']) {
                    $today = new DateTime('today');
                    $maxAhead = (clone $today)->modify('+6 months');
                    if ($parsed >= $today && $parsed <= $maxAhead) {
                        $startDate = $parsed->format('Y-m-d');
                    }
                }
            }
            $numDays = $startDate !== null ? 30 : null;
            if (!empty($_POST['num_days'])) {
                $numDays = max(1, min(45, (int) $_POST['num_days']));
            }

            $match = match_department_from_symptoms($submittedKeys);
            if (!$match['success']) {
                respond(['success' => false, 'error' => $match['error']], 422);
            }

            $departmentName = $match['department'];

            $stmt = $conn->prepare("SELECT department_id FROM departments WHERE department_name = ? AND is_active = 1");
            $stmt->bind_param("s", $departmentName);
            $stmt->execute();
            $deptRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$deptRow) {
                // Shouldn't happen - the catalog only contains active
                // departments - but if a department gets deactivated out
                // from under an already-loaded page, fail closed rather
                // than matching something no longer bookable.
                respond(['success' => false, 'error' => 'This type of care is not currently available for booking. Please contact the front desk.'], 422);
            }
            $departmentId = (int) $deptRow['department_id'];

            if (has_active_appointment_in_department($conn, $patient_id, $departmentId)) {
                respond([
                    'success' => false,
                    'error' => "You already have an active appointment in this department. Cancel it first, or visit the front desk for anything else.",
                ], 403);
            }

            $stmt = $conn->prepare("
                SELECT u.user_id, u.first_name, u.last_name
                FROM users u
                WHERE u.role = 'doctor' AND u.is_active = 1 AND u.department_id = ?
                ORDER BY u.last_name, u.first_name
            ");
            $stmt->bind_param("i", $departmentId);
            $stmt->execute();
            $doctors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            // No active doctors at all in this department - terminate
            // right here rather than pretending there's a date/time step
            // to move on to.
            if (empty($doctors)) {
                respond([
                    'success' => true,
                    'department' => $departmentName,
                    'matched_labels' => $match['matched_labels'],
                    'no_doctors' => true,
                    'message' => 'There are currently no doctors available for this concern. Please contact the front desk at (043) 398 0350, or visit in person.',
                ]);
            }

            // Auto-assign: least booked this week first, so appointments
            // spread evenly across doctors in the department rather than
            // always landing on the first one alphabetically.
            foreach ($doctors as &$doc) {
                $stmt = $conn->prepare("
                    SELECT COUNT(*) AS booked_count FROM appointments
                    WHERE doctor_id = ? AND status IN ('pending','confirmed','completed')
                      AND slot_start >= CURDATE() AND slot_start < DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                ");
                $stmt->bind_param("i", $doc['user_id']);
                $stmt->execute();
                $doc['booked_this_week'] = (int) $stmt->get_result()->fetch_assoc()['booked_count'];
                $stmt->close();
            }
            unset($doc);

            usort($doctors, fn($a, $b) => $a['booked_this_week'] <=> $b['booked_this_week']);

            // Walk the least-busy-first list and hand back the first
            // doctor who actually has an open slot in the booking window
            // - "least busy" alone isn't enough, since the least-busy
            // doctor could still be fully booked out if they work fewer
            // days than everyone else.
            foreach ($doctors as $doc) {
                $grid = build_doctor_slot_grid($conn, (int) $doc['user_id'], $numDays, $startDate);
                if ($grid && grid_has_open_slot($grid)) {
                    respond([
                        'success' => true,
                        'department' => $departmentName,
                        'matched_labels' => $match['matched_labels'],
                        'no_doctors' => false,
                        'doctor_id' => (int) $doc['user_id'],
                        'doctor_name' => trim($doc['first_name'] . ' ' . $doc['last_name']),
                        'grid' => $grid,
                    ]);
                }
            }

            // Every doctor in the department exists but nobody has a real
            // opening in the booking window - terminate the same way as
            // the "no doctors at all" case above, with a message that
            // reflects what actually happened.
            //
            // FIXED 2026-09-17: this used to hardcode "over the next
            // couple of weeks" regardless of which window was actually
            // being searched - misleading when the patient had picked a
            // specific future month (advance booking) rather than the
            // normal rolling window. Now reflects whichever was actually
            // searched.
            $windowLabel = $startDate !== null
                ? 'for the selected month'
                : 'over the next couple of weeks';
            respond([
                'success' => true,
                'department' => $departmentName,
                'matched_labels' => $match['matched_labels'],
                'no_doctors' => true,
                'message' => "All doctors for this concern are fully booked {$windowLabel}. Please contact the front desk at (043) 398 0350, or visit in person.",
            ]);
        }

        // ------------------------------------------------------------
        // Kept for direct re-fetching of one specific doctor's grid
        // (e.g. a future "advance booking" mode) - not called by the
        // normal symptom-checkbox flow above, which gets its grid
        // straight from match_symptoms instead. Mirrors the exact rules
        // the old render-time grid (and the final re-validation) both
        // use: lunch break excluded, on-duty days from the shared
        // schedule resolver, day marked full once max_patients is hit.
        // ------------------------------------------------------------
    case 'get_slots': {
            $doctor_id = (int) ($_POST['doctor_id'] ?? 0);
            $numDays = isset($_POST['num_days']) ? max(1, min(60, (int) $_POST['num_days'])) : null;

            $doctor_department_id = get_doctor_department_id($conn, $doctor_id);
            if ($doctor_department_id !== null && has_active_appointment_in_department($conn, $patient_id, $doctor_department_id)) {
                respond([
                    'success' => false,
                    'error' => "You already have an active appointment in this doctor's department. Cancel it first, or choose a different department.",
                ], 403);
            }

            $grid = build_doctor_slot_grid($conn, $doctor_id, $numDays);
            if (!$grid) {
                respond(['success' => false, 'error' => 'Please select a valid doctor.'], 422);
            }

            respond(array_merge(['success' => true], $grid));
        }

    default:
        respond(['success' => false, 'error' => 'Unknown action.'], 400);
}
