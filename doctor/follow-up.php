<?php
// doctor/follow-up.php

require_once '../includes/auth_guard.php';
require_role('doctor');
require_once '../config/db.php';
require_once '../includes/csrf.php';

// Logged-in doctor's info comes from the session (set during login).
$doctorFirstName = $_SESSION['first_name'];
$doctorLastName  = $_SESSION['last_name'];
$doctorFullName  = "Dr. " . $doctorFirstName . " " . $doctorLastName;
$doctorInitial   = strtoupper(substr($doctorFirstName, 0, 1));
$doctorId        = (int) $_SESSION['user_id'];

$today = date("F j, Y");

// Flash message set by follow-up-process.php / follow-up-update.php after a save attempt.
$flash = $_SESSION['followup_flash'] ?? null;
unset($_SESSION['followup_flash']);

// Optional prefill when arriving here from Consultation's "Follow-up
// Recommended" outcome. These only pre-populate the form fields below -
// nothing is saved until the doctor submits "Schedule Follow-Up", which
// still goes through follow-up-process.php's own validation.
$prefillAppointmentId = isset($_GET['appointment_id']) ? (int) $_GET['appointment_id'] : 0;
$prefillPatientName = isset($_GET['patient_name']) ? trim($_GET['patient_name']) : '';
$prefillOriginalVisit = isset($_GET['original_visit']) ? trim($_GET['original_visit']) : '';

// Real follow-ups for this doctor, scoped by doctor_id so one doctor never
// sees another doctor's patients here.
$followUps = [];
$stmt = $conn->prepare(
    "SELECT f.follow_up_id, f.original_visit_date, f.followup_date, f.followup_time,
            f.reason, f.status,
            u.first_name, u.last_name
     FROM follow_ups f
     JOIN users u ON u.user_id = f.patient_id
     WHERE f.doctor_id = ?
     ORDER BY f.followup_date ASC, f.followup_time ASC"
);
$stmt->bind_param("i", $doctorId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $followUps[] = [
        "id"             => (int) $row['follow_up_id'],
        "patient"        => trim($row['first_name'] . ' ' . $row['last_name']),
        "original_visit" => $row['original_visit_date'], // may be null
        "followup_date"  => $row['followup_date'],
        "followup_time"  => $row['followup_time'] ? date("g:i A", strtotime($row['followup_time'])) : "—",
        "reason"         => $row['reason'],
        "status"         => $row['status'],
    ];
}
$stmt->close();

function followUpStatusLabel($status)
{
    $map = [
        "scheduled" => "Scheduled",
        "completed" => "Completed",
        "missed"    => "Missed",
        "cancelled" => "Cancelled",
    ];
    return $map[$status] ?? ucfirst($status);
}

function formatFollowUpDate($isoDate)
{
    if (!$isoDate) {
        return "—";
    }
    $timestamp = strtotime($isoDate);
    return $timestamp ? date("M j, Y", $timestamp) : $isoDate;
}

// Stats derived from the follow-ups fetched above.
$statusCounts = [
    "scheduled" => 0,
    "completed" => 0,
    "missed"    => 0,
    "cancelled" => 0,
];
foreach ($followUps as $item) {
    if (isset($statusCounts[$item['status']])) {
        $statusCounts[$item['status']]++;
    }
}

$stats = [
    [
        "label"  => "Scheduled Follow-Ups",
        "value"  => $statusCounts['scheduled'],
        "icon"   => "calendar",
        "accent" => "teal",
    ],
    [
        "label"  => "Completed",
        "value"  => $statusCounts['completed'],
        "icon"   => "check-circle",
        "accent" => "blue",
    ],
    [
        "label"  => "Missed",
        "value"  => $statusCounts['missed'],
        "icon"   => "x-circle",
        "accent" => "red",
    ],
    [
        "label"  => "Cancelled",
        "value"  => $statusCounts['cancelled'],
        "icon"   => "slash",
        "accent" => "amber",
    ],
];

// Upcoming reminders shown in the side column — next few scheduled follow-ups.
$upcoming = array_values(array_filter($followUps, function ($item) {
    return $item['status'] === 'scheduled';
}));
$upcoming = array_slice($upcoming, 0, 4);

$current_page = 'follow-up';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Follow-Up Scheduling - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <!-- Main content -->
        <main class="main-content">

            <header class="page-header">
                <div>
                    <h1>Follow-Up Scheduling</h1>
                    <p class="page-subtitle">Track and manage your patients' follow-up visits.</p>
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

            <!-- Stats -->
            <section class="stats-grid">
                <?php foreach ($stats as $stat): ?>
                    <div class="stat-card stat-<?php echo htmlspecialchars($stat['accent']); ?>">
                        <div class="stat-icon">
                            <?php if ($stat['icon'] === 'calendar'): ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                                    <line x1="16" y1="2" x2="16" y2="6"></line>
                                    <line x1="8" y1="2" x2="8" y2="6"></line>
                                    <line x1="3" y1="10" x2="21" y2="10"></line>
                                </svg>
                            <?php elseif ($stat['icon'] === 'check-circle'): ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                                </svg>
                            <?php elseif ($stat['icon'] === 'x-circle'): ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="15" y1="9" x2="9" y2="15"></line>
                                    <line x1="9" y1="9" x2="15" y2="15"></line>
                                </svg>
                            <?php else: ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
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

                <!-- Follow-up list -->
                <div class="card follow-up-list-card">
                    <div class="card-header">
                        <h2>Follow-Up Appointments</h2>
                        <span class="card-subtitle"><?php echo count($followUps); ?> total</span>
                    </div>

                    <div class="queue-toolbar">
                        <div class="search-field">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8"></circle>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            </svg>
                            <input type="text" id="followUpSearchInput" placeholder="Search patient name...">
                        </div>
                        <div class="toolbar-filters">
                            <select class="filter-select" id="followUpStatusFilter">
                                <option value="all">All Statuses</option>
                                <option value="scheduled">Scheduled</option>
                                <option value="completed">Completed</option>
                                <option value="missed">Missed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>

                    <?php if (count($followUps) > 0): ?>
                        <div class="table-wrap">
                            <table class="queue-table" id="followUpTable">
                                <thead>
                                    <tr>
                                        <th>Patient</th>
                                        <th>Original Visit</th>
                                        <th>Follow-Up Schedule</th>
                                        <th>Reason</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($followUps as $item): ?>
                                        <tr class="followup-row" data-name="<?php echo htmlspecialchars(strtolower($item['patient'])); ?>" data-status="<?php echo htmlspecialchars($item['status']); ?>">
                                            <td class="patient-cell">
                                                <span class="patient-avatar"><?php echo htmlspecialchars(strtoupper(substr($item['patient'], 0, 1))); ?></span>
                                                <?php echo htmlspecialchars($item['patient']); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars(formatFollowUpDate($item['original_visit'])); ?></td>
                                            <td><?php echo htmlspecialchars(formatFollowUpDate($item['followup_date'])); ?> · <?php echo htmlspecialchars($item['followup_time']); ?></td>
                                            <td><?php echo htmlspecialchars($item['reason']); ?></td>
                                            <td>
                                                <span class="status-pill status-<?php echo htmlspecialchars($item['status']); ?>">
                                                    <?php echo htmlspecialchars(followUpStatusLabel($item['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($item['status'] === 'scheduled'): ?>
                                                    <div class="table-actions">
                                                        <form method="POST" action="follow-up-update.php" style="display:inline;">
                                                        <?= csrf_field() ?>
                                                            <input type="hidden" name="follow_up_id" value="<?php echo (int) $item['id']; ?>">
                                                            <input type="hidden" name="action" value="complete">
                                                            <button class="btn-view-consult btn-mark-complete" type="submit">
                                                                Mark Completed
                                                            </button>
                                                        </form>
                                                        <form method="POST" action="follow-up-update.php" style="display:inline;">
                                                        <?= csrf_field() ?>
                                                            <input type="hidden" name="follow_up_id" value="<?php echo (int) $item['id']; ?>">
                                                            <input type="hidden" name="action" value="cancel">
                                                            <button class="btn-cancel-followup" type="submit">
                                                                Cancel
                                                            </button>
                                                        </form>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="action-complete-text">No action needed</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr id="noFollowUpResults" hidden>
                                        <td colspan="6">
                                            <div class="no-results">
                                                <p>No follow-up appointments match your search.</p>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-illustration">
                                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                                    <line x1="16" y1="2" x2="16" y2="6"></line>
                                    <line x1="8" y1="2" x2="8" y2="6"></line>
                                    <line x1="3" y1="10" x2="21" y2="10"></line>
                                </svg>
                            </div>
                            <p>No follow-up appointments scheduled yet.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Right column -->
                <div class="side-column">

                    <!-- Schedule New Follow-Up -->
                    <div class="card schedule-followup-card">
                        <div class="card-header">
                            <h2>Schedule New Follow-Up</h2>
                        </div>
                        <form id="scheduleFollowUpForm" class="consultation-form" method="POST" action="follow-up-process.php">
                        <?= csrf_field() ?>
                            <input type="hidden" name="appointment_id" value="<?php echo (int) $prefillAppointmentId; ?>">
                            <div class="form-group">
                                <label class="form-label" for="followUpPatientName">Patient Name <span class="required">*</span></label>
                                <input type="text" class="form-input" id="followUpPatientName" name="patient_name" placeholder="e.g. Juan Dela Cruz" value="<?php echo htmlspecialchars($prefillPatientName); ?>" required>
                                <span class="form-hint">Must match the patient's name on file exactly.</span>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="followUpOriginalVisit">Original Visit Date</label>
                                <input type="date" class="form-input" id="followUpOriginalVisit" name="original_visit" value="<?php echo htmlspecialchars($prefillOriginalVisit); ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="followUpDate">Follow-Up Date <span class="required">*</span></label>
                                <input type="date" class="form-input" id="followUpDate" name="followup_date" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="followUpTime">Follow-Up Time</label>
                                <input type="time" class="form-input" id="followUpTime" name="followup_time">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="followUpReason">Reason for Follow-Up <span class="required">*</span></label>
                                <textarea class="form-textarea" id="followUpReason" name="reason" rows="3" placeholder="e.g. Review lab results" required></textarea>
                                <span class="form-hint">This will appear in the patient's follow-up list.</span>
                            </div>
                            <button type="submit" class="btn btn-primary">Schedule Follow-Up</button>
                        </form>
                    </div>

                    <!-- Upcoming this week -->
                    <div class="card activity-card">
                        <div class="card-header">
                            <h2>Upcoming Follow-Ups</h2>
                        </div>
                        <?php if (count($upcoming) > 0): ?>
                            <ul class="activity-timeline">
                                <?php foreach ($upcoming as $item): ?>
                                    <li class="activity-item">
                                        <span class="activity-dot"></span>
                                        <div class="activity-body">
                                            <p><?php echo htmlspecialchars($item['patient']); ?> — <?php echo htmlspecialchars($item['reason']); ?></p>
                                            <span class="activity-time"><?php echo htmlspecialchars(formatFollowUpDate($item['followup_date'])); ?> · <?php echo htmlspecialchars($item['followup_time']); ?></span>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="empty-state">
                                <p>No upcoming follow-ups scheduled.</p>
                            </div>
                        <?php endif; ?>
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