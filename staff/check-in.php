<?php
// staff/check-in.php
// Front-desk screen: look up a patient's appointment by reference number,
// confirm identity, then mark them checked in for today.
//
// REAL (2026-07-27): the priority ID checkbox below actually persists
// now (to priority_status/priority_id_number, see check-in-process.php
// and 007_add_priority_verification.sql) — it used to be required to
// submit but was never read server-side. Already-verified patients (from
// a previous visit, or via staff/edit-priority.php) skip straight past
// this and just see a confirmation note instead of being asked again.
//
// Reuses doctor-dashboard.css for visual consistency with the rest of
// GabayMed rather than introducing a new stylesheet for one screen.

require_once '../includes/auth_guard.php';
require_role('staff');
require_staff_type('front_desk');
require_once '../config/db.php';
require_once '../includes/reference_number.php';
require_once '../includes/no_show_policy.php';
require_once '../includes/csrf.php';

// Auto-enforce the no-show policy on every load of this page. Front desk
// staff have this screen open continuously throughout clinic hours, which
// makes it the natural, always-running trigger for sweeping appointments
// that have crossed the 30-minute grace period with no check-in - no
// cron/scheduled task exists in this project, so this is what keeps the
// policy enforced without one. Idempotent by design (see
// includes/no_show_policy.php), so running it on every page load is safe.
run_no_show_sweep($conn);

$staffFirstName = $_SESSION['first_name'] ?? '';
$staffLastName  = $_SESSION['last_name'] ?? '';
$staffFullName  = trim($staffFirstName . " " . $staffLastName);
$today = date("F j, Y");
$current_page = 'check-in';

$flash = $_SESSION['checkin_flash'] ?? null;
unset($_SESSION['checkin_flash']);

// --- Look up a reference number (GET, triggered by the search form) ------
$match = null;
$searchedRef = trim($_GET['reference_number'] ?? '');

if ($searchedRef !== '') {
    // The reference number IS the appointment_id, just formatted
    // (GM-YYYY-NNNNNN). Decode it first — if it doesn't even match that
    // shape, there's no point querying the database at all.
    $lookupId = parse_appointment_reference($searchedRef);

    if ($lookupId !== null) {
        $stmt = $conn->prepare(
            "SELECT a.appointment_id, a.created_at, a.slot_start, a.status, a.checked_in_at,
                    u.first_name, u.last_name, u.priority_type, u.priority_status,
                    d.department_name,
                    doc.first_name AS doctor_first_name, doc.last_name AS doctor_last_name
             FROM appointments a
             JOIN users u ON u.user_id = a.patient_id
             JOIN departments d ON d.department_id = a.department_id
             JOIN users doc ON doc.user_id = a.doctor_id
             WHERE a.appointment_id = ?
             LIMIT 1"
        );
        $stmt->bind_param("i", $lookupId);
        $stmt->execute();
        $candidate = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Re-derive the reference from the appointment's own data and
        // compare — guards against a mistyped year still parsing to a
        // real (but wrong) appointment_id.
        if ($candidate && verify_appointment_reference($searchedRef, $candidate['appointment_id'], $candidate['created_at'])) {
            $match = $candidate;
        }
    }
}

// Today's already-checked-in list, for staff to glance at without re-searching.
$checkedInToday = [];
$stmt = $conn->prepare(
    "SELECT a.appointment_id, a.created_at, a.slot_start, a.checked_in_at,
            u.first_name, u.last_name, u.priority_type, d.department_name
     FROM appointments a
     JOIN users u ON u.user_id = a.patient_id
     JOIN departments d ON d.department_id = a.department_id
     WHERE a.checked_in_at IS NOT NULL
       AND DATE(a.checked_in_at) = CURDATE()
     ORDER BY a.checked_in_at DESC"
);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $checkedInToday[] = $row;
}
$stmt->close();

$priorityLabels = [
    'senior'  => 'Senior Citizen',
    'pwd'     => 'PWD',
    'ip'      => 'IP',
    'regular' => 'Regular',
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Check-In - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content" style="margin: 0 auto; max-width: 820px;">

            <header class="page-header">
                <div>
                    <h1>Patient Check-In</h1>
                    <p class="page-subtitle">Front desk — confirm patient arrival by reference number.</p>
                </div>
                <div class="header-actions">
                    <span class="header-date"><?php echo htmlspecialchars($today); ?></span>
                </div>
            </header>

            <?php if ($flash): ?>
                <section class="card" style="border-left: 4px solid <?php echo $flash['type'] === 'error' ? 'var(--red)' : 'var(--teal)'; ?>; margin-bottom: 20px;">
                    <p style="margin: 0; font-size: 14px; color: <?php echo $flash['type'] === 'error' ? 'var(--red)' : 'var(--text-primary)'; ?>;">
                        <?php echo htmlspecialchars($flash['message']); ?>
                    </p>
                </section>
            <?php endif; ?>

            <!-- Search -->
            <section class="card">
                <div class="card-header">
                    <h2>Look Up Appointment</h2>
                </div>
                <form method="GET" action="check-in.php" class="consultation-form">
                    <div class="form-group">
                        <label for="reference_number" class="form-label">Reference Number</label>
                        <input
                            type="text"
                            id="reference_number"
                            name="reference_number"
                            class="form-input"
                            placeholder="e.g. GM-20260703-0003"
                            value="<?php echo htmlspecialchars($searchedRef); ?>"
                            autofocus
                            required>
                        <span class="form-hint">Type it in, or scan the patient's QR code into this field.</span>
                    </div>
                    <button type="submit" class="btn btn-primary">Look Up</button>
                </form>
            </section>

            <?php if ($searchedRef !== ''): ?>
                <section class="card" style="margin-top: 20px;">
                    <div class="card-header">
                        <h2>Result</h2>
                    </div>

                    <?php if (!$match): ?>
                        <div class="empty-state">
                            <p>No appointment found for reference number <strong><?php echo htmlspecialchars($searchedRef); ?></strong>.</p>
                        </div>
                    <?php elseif ($match['checked_in_at']): ?>
                        <div class="empty-state">
                            <p>
                                <strong><?php echo htmlspecialchars(trim($match['first_name'] . ' ' . $match['last_name'])); ?></strong>
                                was already checked in at
                                <?php echo htmlspecialchars(date("g:i A", strtotime($match['checked_in_at']))); ?>.
                            </p>
                        </div>
                    <?php elseif (!in_array($match['status'], ['pending', 'confirmed'], true)): ?>
                        <div class="empty-state">
                            <p>This appointment is <?php echo htmlspecialchars($match['status']); ?> and can't be checked in.</p>
                        </div>
                    <?php elseif (date("Y-m-d", strtotime($match['slot_start'])) !== date("Y-m-d")): ?>
                        <div class="empty-state">
                            <p>
                                This appointment is scheduled for
                                <?php echo htmlspecialchars(date("M j, Y", strtotime($match['slot_start']))); ?>,
                                not today.
                            </p>
                        </div>
                    <?php elseif (time() < strtotime($match['slot_start']) - 1800): ?>
                        <div class="empty-state">
                            <p>
                                It's too early to check in
                                <strong><?php echo htmlspecialchars(trim($match['first_name'] . ' ' . $match['last_name'])); ?></strong>.
                                Their appointment is at <?php echo htmlspecialchars(date("g:i A", strtotime($match['slot_start']))); ?> —
                                check-in opens at <?php echo htmlspecialchars(date("g:i A", strtotime($match['slot_start']) - 1800)); ?>.
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="patient-info-grid">
                            <div class="info-item">
                                <span class="info-label">Patient</span>
                                <span class="info-value"><?php echo htmlspecialchars(trim($match['first_name'] . ' ' . $match['last_name'])); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Priority</span>
                                <span class="info-value"><span class="priority-badge priority-<?php echo htmlspecialchars($match['priority_type']); ?>"><?php echo htmlspecialchars($priorityLabels[$match['priority_type']] ?? 'Regular'); ?></span></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Appointment Time</span>
                                <span class="info-value"><?php echo htmlspecialchars(date("g:i A", strtotime($match['slot_start']))); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Department</span>
                                <span class="info-value"><?php echo htmlspecialchars($match['department_name']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Doctor</span>
                                <span class="info-value">Dr. <?php echo htmlspecialchars(trim($match['doctor_first_name'] . ' ' . $match['doctor_last_name'])); ?></span>
                            </div>
                        </div>

                        <form method="POST" action="check-in-process.php" style="margin-top: 20px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="appointment_id" value="<?php echo (int) $match['appointment_id']; ?>">
                            <input type="hidden" name="reference_number" value="<?php echo htmlspecialchars(format_appointment_reference($match['appointment_id'], $match['created_at'])); ?>">
                            <?php if ($match['priority_type'] !== 'regular' && $match['priority_status'] === 'verified'): ?>
                                <!-- Already verified — from a previous visit, or from
                                     staff/edit-priority.php. Don't ask again every time;
                                     just show that it's on file. -->
                                <div class="form-group" style="margin-top: 16px;">
                                    <span style="font-size: 13px; color: var(--teal, #14b8a6);">
                                        &#10003; <?php echo htmlspecialchars($priorityLabels[$match['priority_type']]); ?> ID already verified on file &mdash; no need to check again.
                                    </span>
                                </div>
                            <?php elseif ($match['priority_type'] !== 'regular'): ?>
                                <!-- REAL (2026-07-27): this used to be a checkbox with
                                     nothing behind it — required to submit, but
                                     check-in-process.php never read or saved it. Now it
                                     actually persists to priority_status/priority_id_number
                                     (see 007_add_priority_verification.sql), same fields
                                     staff/edit-priority.php's verification writes to —
                                     whichever of the two happens first "wins". -->
                                <div class="form-group" style="margin-top: 16px;">
                                    <label for="priority_id_number" class="form-label">
                                        <?php echo htmlspecialchars($priorityLabels[$match['priority_type']]); ?> ID Number
                                    </label>
                                    <input
                                        type="text"
                                        id="priority_id_number"
                                        name="priority_id_number"
                                        class="form-input"
                                        placeholder="ID number on the card"
                                        required>
                                    <span class="form-hint">Required to check in a priority patient for the first time — this is what marks it verified.</span>
                                </div>
                                <div class="form-group">
                                    <label style="display:flex; align-items:flex-start; gap:8px; font-size: 13px; font-weight: 400; color: var(--text-secondary);">
                                        <input type="checkbox" name="priority_verified" value="1" required style="margin-top: 3px;">
                                        <span>I have verified <?php echo htmlspecialchars(trim($match['first_name'] . ' ' . $match['last_name'])); ?>'s <?php echo htmlspecialchars($priorityLabels[$match['priority_type']]); ?> ID before checking them in.</span>
                                    </label>
                                </div>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary">
                                Confirm Check-In for <?php echo htmlspecialchars(trim($match['first_name'] . ' ' . $match['last_name'])); ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <!-- Today's checked-in list -->
            <section class="card" style="margin-top: 20px;">
                <div class="card-header">
                    <h2>Checked In Today</h2>
                    <span class="card-subtitle"><?php echo count($checkedInToday); ?> total</span>
                </div>

                <?php if (count($checkedInToday) > 0): ?>
                    <div class="table-wrap">
                        <table class="queue-table">
                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Priority</th>
                                    <th>Reference #</th>
                                    <th>Appointment Time</th>
                                    <th>Department</th>
                                    <th>Checked In At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($checkedInToday as $row): ?>
                                    <tr>
                                        <td class="patient-cell">
                                            <span class="patient-avatar"><?php echo htmlspecialchars(strtoupper(substr($row['first_name'], 0, 1))); ?></span>
                                            <?php echo htmlspecialchars(trim($row['first_name'] . ' ' . $row['last_name'])); ?>
                                        </td>
                                        <td><span class="priority-badge priority-<?php echo htmlspecialchars($row['priority_type']); ?>"><?php echo htmlspecialchars($priorityLabels[$row['priority_type']] ?? 'Regular'); ?></span></td>
                                        <td><?php echo htmlspecialchars(format_appointment_reference($row['appointment_id'], $row['created_at'])); ?></td>
                                        <td><?php echo htmlspecialchars(date("g:i A", strtotime($row['slot_start']))); ?></td>
                                        <td><?php echo htmlspecialchars($row['department_name']); ?></td>
                                        <td><?php echo htmlspecialchars(date("g:i A", strtotime($row['checked_in_at']))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <p>No patients checked in yet today.</p>
                    </div>
                <?php endif; ?>
            </section>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <script src="../assets/js/doctor-dashboard.js"></script>
</body>

</html>