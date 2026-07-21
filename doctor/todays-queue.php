<?php
// doctor/todays-queue.php

require_once '../includes/auth_guard.php';
require_role('doctor');
require_once '../config/db.php';
require_once '../includes/no_show_policy.php';

// See doctor/dashboard.php for why this runs here too - without it, a
// patient who never checked in could keep showing as pending/confirmed
// instead of no_show if no staff member happened to load check-in.php.
run_no_show_sweep($conn);

$doctorFirstName = $_SESSION['first_name'];
$doctorLastName  = $_SESSION['last_name'];
$doctorFullName  = "Dr. " . $doctorFirstName . " " . $doctorLastName;
$doctorInitial   = strtoupper(substr($doctorFirstName, 0, 1));
$doctorId        = (int) $_SESSION['user_id'];

$today = date("F j, Y");

// Today's queue = patients who have actually checked in with front desk
// today (checked_in_at IS NOT NULL), not just "anyone booked for today".
// This matches the spec: a patient who never checked in should never
// appear here, and should eventually become a no-show instead.
$stmt = $conn->prepare(
    "SELECT a.appointment_id, a.slot_start, a.status, a.is_follow_up, a.checked_in_at,
            u.first_name, u.last_name, u.priority_type,
            d.department_name
     FROM appointments a
     JOIN users u ON u.user_id = a.patient_id
     JOIN departments d ON d.department_id = a.department_id
     WHERE a.doctor_id = ?
       AND DATE(a.slot_start) = CURDATE()
       AND a.checked_in_at IS NOT NULL
     ORDER BY (u.priority_type != 'regular') DESC, a.checked_in_at ASC"
);
$stmt->bind_param("i", $doctorId);
$stmt->execute();
$rows = $stmt->get_result();

function mapAppointmentStatus($status)
{
    // "checked-in" here means: has arrived, not yet seen by the doctor.
    // "in-progress" has no dedicated data source yet (would need a
    // session-level "doctor currently has this appointment's consultation
    // open" signal) so it will always read 0 for now.
    $map = [
        "pending"   => "checked-in",
        "confirmed" => "checked-in",
        "completed" => "completed",
        "no_show"   => "no-show",
    ];
    return $map[$status] ?? "checked-in";
}

$priorityLabels = [
    'senior'  => 'Senior Citizen',
    'pwd'     => 'PWD',
    'ip'      => 'IP',
    'regular' => 'Regular',
];

$queue = [];
while ($row = $rows->fetch_assoc()) {
    $queue[] = [
        "appointment_id" => (int) $row['appointment_id'],
        "name"       => trim($row['first_name'] . " " . $row['last_name']),
        "time"       => date("g:i A", strtotime($row['slot_start'])),
        "department" => $row['department_name'],
        "type"       => $row['is_follow_up'] ? "Follow-up" : "Consultation",
        "status"     => mapAppointmentStatus($row['status']),
        "priority"   => $row['priority_type'],
    ];
}
$stmt->close();

$departments = array_values(array_unique(array_column($queue, 'department')));
sort($departments);

function statusLabel($status)
{
    $map = [
        "checked-in"  => "Checked In",
        "waiting"     => "Waiting",
        "in-progress" => "In Progress",
        "completed"   => "Completed",
        "no-show"     => "No Show",
    ];
    return $map[$status] ?? ucfirst($status);
}

$statusCounts = [
    "checked-in"  => 0,
    "waiting"     => 0,
    "in-progress" => 0,
    "completed"   => 0,
    "no-show"     => 0,
];
foreach ($queue as $p) {
    if (isset($statusCounts[$p['status']])) {
        $statusCounts[$p['status']]++;
    }
}

$current_page = 'todays-queue';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Today's Queue - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <!-- Main content -->
        <main class="main-content">

            <header class="page-header">
                <div>
                    <h1>Today's Queue</h1>
                    <p class="page-subtitle">All scheduled patients for <?php echo htmlspecialchars($today); ?>.</p>
                </div>

            </header>

            <!-- Status summary chips -->
            <section class="status-summary">
                <div class="status-chip status-chip-checked-in">
                    <span class="status-chip-value"><?php echo (int) $statusCounts['checked-in']; ?></span>
                    <span class="status-chip-label">Checked In</span>
                </div>
                <div class="status-chip status-chip-waiting">
                    <span class="status-chip-value"><?php echo (int) $statusCounts['waiting']; ?></span>
                    <span class="status-chip-label">Waiting</span>
                </div>
                <div class="status-chip status-chip-in-progress">
                    <span class="status-chip-value"><?php echo (int) $statusCounts['in-progress']; ?></span>
                    <span class="status-chip-label">In Progress</span>
                </div>
                <div class="status-chip status-chip-completed">
                    <span class="status-chip-value"><?php echo (int) $statusCounts['completed']; ?></span>
                    <span class="status-chip-label">Completed</span>
                </div>
                <div class="status-chip status-chip-no-show">
                    <span class="status-chip-value"><?php echo (int) $statusCounts['no-show']; ?></span>
                    <span class="status-chip-label">No Show</span>
                </div>
            </section>

            <!-- Queue card -->
            <section class="card queue-full-card">

                <div class="queue-toolbar">
                    <div class="search-field">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                        <input type="text" id="queueSearchInput" placeholder="Search patient name...">
                    </div>

                    <div class="toolbar-filters">
                        <select id="departmentFilter" class="filter-select">
                            <option value="all">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select id="statusFilter" class="filter-select">
                            <option value="all">All Statuses</option>
                            <option value="checked-in">Checked In</option>
                            <option value="waiting">Waiting</option>
                            <option value="in-progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="no-show">No Show</option>
                        </select>
                    </div>
                </div>

                <?php if (count($queue) > 0): ?>
                    <div class="table-wrap">
                        <table class="queue-table" id="queueTable">
                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Priority</th>
                                    <th>Appointment Time</th>
                                    <th>Department</th>
                                    <th>Visit Type</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($queue as $patient): ?>
                                    <tr
                                        class="queue-row"
                                        data-name="<?php echo htmlspecialchars(strtolower($patient['name'])); ?>"
                                        data-department="<?php echo htmlspecialchars($patient['department']); ?>"
                                        data-status="<?php echo htmlspecialchars($patient['status']); ?>">
                                        <td class="patient-cell">
                                            <span class="patient-avatar"><?php echo htmlspecialchars(strtoupper(substr($patient['name'], 0, 1))); ?></span>
                                            <?php echo htmlspecialchars($patient['name']); ?>
                                        </td>
                                        <td><span class="priority-badge priority-<?php echo htmlspecialchars($patient['priority']); ?>"><?php echo htmlspecialchars($priorityLabels[$patient['priority']] ?? 'Regular'); ?></span></td>
                                        <td><?php echo htmlspecialchars($patient['time']); ?></td>
                                        <td><?php echo htmlspecialchars($patient['department']); ?></td>
                                        <td><?php echo htmlspecialchars($patient['type']); ?></td>
                                        <td>
                                            <span class="status-pill status-<?php echo htmlspecialchars($patient['status']); ?>">
                                                <?php echo htmlspecialchars(statusLabel($patient['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($patient['status'] === 'completed' || $patient['status'] === 'no-show'): ?>
                                                <span class="action-complete-text">No action needed</span>
                                            <?php else: ?>
                                                <a class="btn-view-consult" href="consultation.php?appointment_id=<?php echo (int) $patient['appointment_id']; ?>">
                                                    View Consultation
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <div class="no-results" id="noResultsRow" hidden>
                            <p>No patients match your search or filters.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-illustration">
                            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="14" rx="2"></rect>
                                <line x1="3" y1="9" x2="21" y2="9"></line>
                                <line x1="8" y1="14" x2="14" y2="14"></line>
                            </svg>
                        </div>
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