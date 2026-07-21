<?php
// doctor/dashboard.php

require_once '../includes/auth_guard.php';
require_once '../config/db.php'; // provides $conn (mysqli connection)
require_role('doctor');
require_once '../includes/no_show_policy.php';

// The 30-minute no-show sweep previously only ran from staff/check-in.php
// and staff/walk-in.php, so a doctor's own dashboard (No Shows stat, Recent
// Activity) could show stale statuses if no staff member had loaded either
// of those pages recently. Idempotent by design, safe to call here too.
run_no_show_sweep($conn);

// Logged-in doctor's info comes from the session (set during login).
$doctorId        = $_SESSION['user_id']; // assumes user_id is stored in session at login
$doctorFirstName = $_SESSION['first_name'];
$doctorLastName  = $_SESSION['last_name'];
$doctorFullName  = "Dr. " . $doctorFirstName . " " . $doctorLastName;
$doctorInitial   = strtoupper(substr($doctorFirstName, 0, 1));

// Doctor's specialty/department, pulled from users -> departments.
$specialtyStmt = $conn->prepare(
    "SELECT d.department_name
     FROM users u
     LEFT JOIN departments d ON d.department_id = u.department_id
     WHERE u.user_id = ?"
);
$specialtyStmt->bind_param("i", $doctorId);
$specialtyStmt->execute();
$specialtyRow    = $specialtyStmt->get_result()->fetch_assoc();
$doctorSpecialty = $specialtyRow['department_name'] ?? 'General Medicine';
$specialtyStmt->close();

$today    = date("F j, Y");
$todaySql = date("Y-m-d");

// ===================== TODAY'S QUEUE =====================
// Only checked-in patients appear here. The appointments table has no
// dedicated "checked_in" status, so a 'confirmed' appointment for today
// is treated as checked-in (booked, not yet completed/cancelled/no-show).
$queueStmt = $conn->prepare(
    "SELECT a.appointment_id, a.slot_start, u.first_name, u.last_name, d.department_name
     FROM appointments a
     INNER JOIN users u ON u.user_id = a.patient_id
     INNER JOIN departments d ON d.department_id = a.department_id
     WHERE a.doctor_id = ?
       AND DATE(a.slot_start) = ?
       AND a.status = 'confirmed'
     ORDER BY a.slot_start ASC"
);
$queueStmt->bind_param("is", $doctorId, $todaySql);
$queueStmt->execute();
$queueRows = $queueStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$queueStmt->close();

$queue = array_map(function ($row) {
    return [
        "appointment_id" => (int) $row['appointment_id'],
        "name"       => $row['first_name'] . " " . $row['last_name'],
        "time"       => date("g:i A", strtotime($row['slot_start'])),
        "department" => $row['department_name'],
        "status"     => "checked-in",
    ];
}, $queueRows);

// ===================== STATS =====================

// Patients Seen Today — completed appointments today for this doctor.
$seenStmt = $conn->prepare(
    "SELECT COUNT(*) AS total FROM appointments
     WHERE doctor_id = ? AND DATE(slot_start) = ? AND status = 'completed'"
);
$seenStmt->bind_param("is", $doctorId, $todaySql);
$seenStmt->execute();
$patientsSeenToday = (int) $seenStmt->get_result()->fetch_assoc()['total'];
$seenStmt->close();

// Waiting Patients — checked-in (confirmed) appointments today, not yet seen.
// This mirrors the Today's Queue count above.
$waitingPatients = count($queue);

// Current Confined Patients — this doctor's confinements with no discharge yet.
$confinedStmt = $conn->prepare(
    "SELECT COUNT(*) AS total FROM confinements
     WHERE attending_doctor_id = ? AND discharge_status IS NULL"
);
$confinedStmt->bind_param("i", $doctorId);
$confinedStmt->execute();
$currentConfined = (int) $confinedStmt->get_result()->fetch_assoc()['total'];
$confinedStmt->close();

// No Shows — today's no-show appointments for this doctor.
$noShowStmt = $conn->prepare(
    "SELECT COUNT(*) AS total FROM appointments
     WHERE doctor_id = ? AND DATE(slot_start) = ? AND status = 'no_show'"
);
$noShowStmt->bind_param("is", $doctorId, $todaySql);
$noShowStmt->execute();
$noShows = (int) $noShowStmt->get_result()->fetch_assoc()['total'];
$noShowStmt->close();

$stats = [
    [
        "label"  => "Patients Seen Today",
        "value"  => $patientsSeenToday,
        "icon"   => "check-circle",
        "accent" => "teal"
    ],
    [
        "label"  => "Waiting Patients",
        "value"  => $waitingPatients,
        "icon"   => "clock",
        "accent" => "amber"
    ],
    [
        "label"  => "Current Confined Patients",
        "value"  => $currentConfined,
        "icon"   => "bed",
        "accent" => "blue"
    ],
    [
        "label"  => "No Shows",
        "value"  => $noShows,
        "icon"   => "x-circle",
        "accent" => "red"
    ],
];

// NOTE: Recent Activity remains placeholder/sample data — out of scope
// for Module 2.2 (Today's Queue + stats only). Wire this once an
// activity/audit-log source is defined.
$activity = [
    [
        "text" => "Juan Dela Cruz checked in",
        "time" => "10 minutes ago",
        "icon" => "check-circle",
    ],
    [
        "text" => "Maria Santos discharged",
        "time" => "42 minutes ago",
        "icon" => "log-out",
    ],
    [
        "text" => "Prescription issued for Pedro Reyes",
        "time" => "1 hour ago",
        "icon" => "file-text",
    ],
    [
        "text" => "New confinement added — Angela Mercado",
        "time" => "2 hours ago",
        "icon" => "bed",
    ],
];

function statusLabel($status)
{
    $map = [
        "checked-in"  => "Checked In",
        "waiting"     => "Waiting",
        "in-progress" => "In Progress",
    ];
    return $map[$status] ?? ucfirst($status);
}

$current_page = 'dashboard';

$flash = $_SESSION['consultation_flash'] ?? null;
unset($_SESSION['consultation_flash']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Dashboard - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <!-- Main content -->
        <main class="main-content">

            <header class="page-header">
                <div>
                    <h1>Good Morning, <?php echo htmlspecialchars($doctorFullName); ?></h1>
                    <p class="page-subtitle">Here's today's clinical overview.</p>
                </div>
                <div class="header-actions">
                    <span class="header-date"><?php echo htmlspecialchars($today); ?></span>

                </div>
            </header>

            <?php if ($flash): ?>
                <section class="card" style="border-left: 4px solid <?php echo $flash['type'] === 'error' ? 'var(--red)' : 'var(--teal)'; ?>; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; gap: 16px;">
                    <p style="margin: 0; font-size: 14px;"><?php echo htmlspecialchars($flash['message']); ?></p>
                    <?php if (!empty($flash['consultation_id'])): ?>
                        <a href="print-consultation.php?consultation_id=<?php echo (int) $flash['consultation_id']; ?>"
                            target="_blank" class="btn btn-secondary" style="white-space: nowrap;">
                            Print Visit Summary
                        </a>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <!-- Stats -->
            <section class="stats-grid">
                <?php foreach ($stats as $stat): ?>
                    <div class="stat-card stat-<?php echo htmlspecialchars($stat['accent']); ?>">
                        <div class="stat-icon">
                            <?php if ($stat['icon'] === 'check-circle'): ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                                </svg>
                            <?php elseif ($stat['icon'] === 'clock'): ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <polyline points="12 6 12 12 16 14"></polyline>
                                </svg>
                            <?php elseif ($stat['icon'] === 'bed'): ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M2 4v16"></path>
                                    <path d="M2 8h18a2 2 0 0 1 2 2v10"></path>
                                    <path d="M2 17h20"></path>
                                    <path d="M6 8v9"></path>
                                </svg>
                            <?php else: ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="15" y1="9" x2="9" y2="15"></line>
                                    <line x1="9" y1="9" x2="15" y2="15"></line>
                                </svg>
                            <?php endif; ?>
                        </div>
                        <div class="stat-info">
                            <span class="stat-value"><?php echo htmlspecialchars($stat['value']); ?></span>
                            <span class="stat-label"><?php echo htmlspecialchars($stat['label']); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>

            <!-- Content grid -->
            <section class="content-grid">

                <!-- Today's Queue -->
                <div class="card queue-card">
                    <div class="card-header">
                        <h2>Today's Queue</h2>
                        <span class="card-subtitle"><?php echo count($queue); ?> patients</span>
                    </div>

                    <?php if (count($queue) > 0): ?>
                        <div class="table-wrap">
                            <table class="queue-table">
                                <thead>
                                    <tr>
                                        <th>Patient</th>
                                        <th>Appointment Time</th>
                                        <th>Department</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($queue as $patient): ?>
                                        <tr>
                                            <td class="patient-cell">
                                                <span class="patient-avatar"><?php echo htmlspecialchars(strtoupper(substr($patient['name'], 0, 1))); ?></span>
                                                <?php echo htmlspecialchars($patient['name']); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($patient['time']); ?></td>
                                            <td><?php echo htmlspecialchars($patient['department']); ?></td>
                                            <td>
                                                <span class="status-pill status-<?php echo htmlspecialchars($patient['status']); ?>">
                                                    <?php echo htmlspecialchars(statusLabel($patient['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a class="btn-view-consult" href="consultation.php?appointment_id=<?php echo (int) $patient['appointment_id']; ?>">
                                                    View Consultation
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
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
                </div>

                <!-- Right column -->
                <div class="side-column">

                    <!-- Quick Actions -->
                    <div class="card quick-actions-card">
                        <div class="card-header">
                            <h2>Quick Actions</h2>
                        </div>
                        <div class="quick-actions-grid">
                            <button class="quick-action" type="button" data-href="todays-queue.php">
                                <span class="quick-action-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M22 12h-4l-3 9L9 3l-3 9H2"></path>
                                    </svg>
                                </span>
                                Start Consultation
                            </button>
                            <button class="quick-action" type="button" data-href="confined-patients.php">
                                <span class="quick-action-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M2 4v16"></path>
                                        <path d="M2 8h18a2 2 0 0 1 2 2v10"></path>
                                        <path d="M2 17h20"></path>
                                        <path d="M6 8v9"></path>
                                    </svg>
                                </span>
                                Confined Patients
                            </button>
                            <button class="quick-action" type="button" data-href="patient-records.php">
                                <span class="quick-action-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="11" cy="11" r="8"></circle>
                                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                    </svg>
                                </span>
                                Patient Lookup
                            </button>
                            <button class="quick-action" type="button" data-href="reports.php">
                                <span class="quick-action-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 3v18h18"></path>
                                        <path d="M18 17V9"></path>
                                        <path d="M13 17V5"></path>
                                        <path d="M8 17v-3"></path>
                                    </svg>
                                </span>
                                My Reports
                            </button>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="card activity-card">
                        <div class="card-header">
                            <h2>Recent Activity</h2>
                        </div>
                        <ul class="activity-timeline">
                            <?php foreach ($activity as $item): ?>
                                <li class="activity-item">
                                    <span class="activity-dot"></span>
                                    <div class="activity-body">
                                        <p><?php echo htmlspecialchars($item['text']); ?></p>
                                        <span class="activity-time"><?php echo htmlspecialchars($item['time']); ?></span>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                </div>
            </section>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <script src="../assets/js/doctor-dashboard.js"></script>
</body>

</html>