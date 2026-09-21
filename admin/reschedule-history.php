<?php
// admin/reschedule-history.php
// Module — Reschedule History (read-only admin audit log).
//
// Simple read-only view over appointment_reschedule_history, populated by
// doctor/my-schedule-actions.php's `reschedule` action every time a doctor
// moves one of their own patients to a new slot. This page does not write
// anything - it only reads the history table, joined against appointments
// (for the patient) and users (for doctor + patient names).
//
// Reuses the same card / table-wrap / records-table styling every other
// admin list page already uses (doctor-dashboard.css + admin-dashboard.css) -
// no new CSS file needed for a page this simple.

require_once '../includes/auth_guard.php';
require_role('admin');
require_once '../config/db.php';

$today = date("F j, Y");
$current_page = 'reschedule-history';

$rows = [];
$result = $conn->query(
    "SELECT h.history_id, h.old_slot_start, h.old_slot_end, h.new_slot_start, h.new_slot_end,
            h.emergency_reason, h.changed_at,
            doc.first_name AS doc_first, doc.last_name AS doc_last,
            pat.first_name AS pat_first, pat.last_name AS pat_last
     FROM appointment_reschedule_history h
     JOIN users doc ON doc.user_id = h.doctor_id
     JOIN appointments a ON a.appointment_id = h.appointment_id
     JOIN users pat ON pat.user_id = a.patient_id
     ORDER BY h.changed_at DESC"
);
if ($result) {
    $rows = $result->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reschedule History - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-dashboard.css">
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">

            <header class="page-header">
                <div>
                    <h1>Reschedule History</h1>
                    <p class="page-subtitle">Every doctor-initiated appointment reschedule, most recent first.</p>
                </div>
                <div class="header-actions">
                    <span class="header-date"><?php echo htmlspecialchars($today); ?></span>
                </div>
            </header>

            <section class="card">
                <div class="card-header">
                    <div>
                        <h2><?php echo count($rows); ?> Reschedule<?php echo count($rows) === 1 ? '' : 's'; ?> Logged</h2>
                        <span class="card-subtitle">Read directly from appointment_reschedule_history</span>
                    </div>
                </div>

                <?php if (empty($rows)): ?>
                    <p class="schedule-day-empty" style="padding: 0 20px 20px;">No doctor-initiated reschedules have been logged yet.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="records-table">
                            <thead>
                                <tr>
                                    <th>Doctor</th>
                                    <th>Patient</th>
                                    <th>Old Schedule</th>
                                    <th>New Schedule</th>
                                    <th>Emergency Reason</th>
                                    <th>Date Changed</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td>Dr. <?php echo htmlspecialchars($r['doc_first'] . ' ' . $r['doc_last']); ?></td>
                                        <td><?php echo htmlspecialchars($r['pat_first'] . ' ' . $r['pat_last']); ?></td>
                                        <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($r['old_slot_start']))); ?></td>
                                        <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($r['new_slot_start']))); ?></td>
                                        <td><?php echo htmlspecialchars($r['emergency_reason']); ?></td>
                                        <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($r['changed_at']))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

</body>

</html>
