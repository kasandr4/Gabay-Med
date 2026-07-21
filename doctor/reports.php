<?php
// doctor/reports.php
//
// Connected to the backend. All figures below are computed per logged-in
// doctor (doctor_id scoped) from appointments / consultations /
// prescriptions / confinements — nothing here is placeholder data anymore.
//
// Month selection is a simple GET param (?month=YYYY-MM), defaulting to
// the current month. A month is treated as "Finalized" if it's before the
// current calendar month, "In Progress" otherwise — this is computed on
// the fly, not stored, since there's no report-locking table.
//
// NOTE: There is no table that logs "a report was generated/exported",
// so the old "Recent Report Activity" card has been removed rather than
// faked. If that's wanted later, it needs a small
// report_generation_log(doctor_id, month, generated_at) table plus an
// INSERT wired to the Export button.

require_once '../includes/auth_guard.php';
require_role('doctor');
require_once '../config/db.php';

$doctorFirstName = $_SESSION['first_name'];
$doctorLastName  = $_SESSION['last_name'];
$doctorFullName  = "Dr. " . $doctorFirstName . " " . $doctorLastName;
$doctorInitial   = strtoupper(substr($doctorFirstName, 0, 1));
$doctorId        = (int) $_SESSION['user_id'];

$today = date("F j, Y");

// ============================================================
// Selected month (defaults to current month)
// ============================================================
$currentMonthKey = date('Y-m');
$selectedMonthKey = $_GET['month'] ?? $currentMonthKey;

// Validate format strictly (YYYY-MM) — anything else falls back to current
// month rather than letting a bad value reach the SQL date range below.
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonthKey)) {
    $selectedMonthKey = $currentMonthKey;
}

function monthBounds($monthKey)
{
    $start = $monthKey . '-01 00:00:00';
    $end = date('Y-m-d 23:59:59', strtotime($start . ' +1 month -1 day'));
    return [$start, $end];
}

/**
 * Runs the four headline-stat queries for one doctor over one month range.
 * Reused for both the top stat cards and each row of the monthly history
 * table, so the two never drift apart.
 */
function getMonthStats($conn, $doctorId, $monthStart, $monthEnd)
{
    $stats = [];

    $stmt = $conn->prepare(
        "SELECT COUNT(DISTINCT patient_id) AS cnt FROM appointments
         WHERE doctor_id = ? AND status = 'completed'
           AND slot_start BETWEEN ? AND ?"
    );
    $stmt->bind_param("iss", $doctorId, $monthStart, $monthEnd);
    $stmt->execute();
    $stats['patients_seen'] = (int) $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM consultations
         WHERE doctor_id = ? AND created_at BETWEEN ? AND ?"
    );
    $stmt->bind_param("iss", $doctorId, $monthStart, $monthEnd);
    $stmt->execute();
    $stats['consultations'] = (int) $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM prescriptions
         WHERE doctor_id = ? AND created_at BETWEEN ? AND ?"
    );
    $stmt->bind_param("iss", $doctorId, $monthStart, $monthEnd);
    $stmt->execute();
    $stats['prescriptions'] = (int) $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM confinements
         WHERE attending_doctor_id = ? AND date_confined BETWEEN ? AND ?"
    );
    $stmt->bind_param("iss", $doctorId, $monthStart, $monthEnd);
    $stmt->execute();
    $stats['confinements'] = (int) $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM confinements
         WHERE attending_doctor_id = ? AND discharge_date BETWEEN ? AND ?"
    );
    $stmt->bind_param("iss", $doctorId, $monthStart, $monthEnd);
    $stmt->execute();
    $stats['discharges'] = (int) $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    return $stats;
}

[$selectedMonthStart, $selectedMonthEnd] = monthBounds($selectedMonthKey);
$selectedStats = getMonthStats($conn, $doctorId, $selectedMonthStart, $selectedMonthEnd);

$stats = [
    ["label" => "Patients Seen", "value" => $selectedStats['patients_seen'], "icon" => "users", "accent" => "teal"],
    ["label" => "Consultations Completed", "value" => $selectedStats['consultations'], "icon" => "activity", "accent" => "blue"],
    ["label" => "Prescriptions Issued", "value" => $selectedStats['prescriptions'], "icon" => "file-text", "accent" => "amber"],
    ["label" => "Confinements Recorded", "value" => $selectedStats['confinements'], "icon" => "bed", "accent" => "red"],
];

// ============================================================
// Monthly Report History — this month plus the previous 5
// ============================================================
$monthlyReports = [];
for ($i = 0; $i < 6; $i++) {
    $monthKey = date('Y-m', strtotime("$currentMonthKey-01 -$i month"));
    [$mStart, $mEnd] = monthBounds($monthKey);
    $mStats = getMonthStats($conn, $doctorId, $mStart, $mEnd);

    $monthlyReports[] = [
        "month_key"     => $monthKey,
        "month"         => date('F Y', strtotime($mStart)),
        "patients_seen" => $mStats['patients_seen'],
        "consultations" => $mStats['consultations'],
        "prescriptions" => $mStats['prescriptions'],
        "confinements"  => $mStats['confinements'],
        "discharges"    => $mStats['discharges'],
        "status"        => $monthKey < $currentMonthKey ? "Finalized" : "In Progress",
    ];
}

// ============================================================
// Outcomes breakdown for the selected month
// ============================================================
$outcomeCounts = [
    'recovered'   => 0,
    'transferred' => 0,
    'dama'        => 0,
    'deceased'    => 0,
];

$stmt = $conn->prepare(
    "SELECT discharge_status, COUNT(*) AS cnt FROM confinements
     WHERE attending_doctor_id = ? AND discharge_date BETWEEN ? AND ?
       AND discharge_status IS NOT NULL
     GROUP BY discharge_status"
);
$stmt->bind_param("iss", $doctorId, $selectedMonthStart, $selectedMonthEnd);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    if (isset($outcomeCounts[$row['discharge_status']])) {
        $outcomeCounts[$row['discharge_status']] = (int) $row['cnt'];
    }
}
$stmt->close();

// "Currently Confined" = admitted on/before the end of the selected month,
// and still not discharged as of that same cutoff (either never discharged,
// or discharged after the month in question — a snapshot as-of month end).
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt FROM confinements
     WHERE attending_doctor_id = ? AND date_confined <= ?
       AND (discharge_date IS NULL OR discharge_date > ?)"
);
$stmt->bind_param("iss", $doctorId, $selectedMonthEnd, $selectedMonthEnd);
$stmt->execute();
$currentlyConfined = (int) $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// OPD count for parity with "Currently Confined": distinct patients this
// doctor saw via a completed appointment this month, excluding anyone
// who's part of the currently-confined set above. Same derived-status
// logic as includes/patient_classification.php (confined = Resident,
// everything else = OPD) — just applied per-doctor/per-month here instead
// of per-patient, to match how this page already scopes everything else.
$stmt = $conn->prepare(
    "SELECT COUNT(DISTINCT a.patient_id) AS cnt FROM appointments a
     WHERE a.doctor_id = ? AND a.status = 'completed'
       AND a.slot_start BETWEEN ? AND ?
       AND a.patient_id NOT IN (
           SELECT patient_id FROM confinements
           WHERE attending_doctor_id = ? AND date_confined <= ?
             AND (discharge_date IS NULL OR discharge_date > ?)
       )"
);
$stmt->bind_param(
    "ississ",
    $doctorId,
    $selectedMonthStart,
    $selectedMonthEnd,
    $doctorId,
    $selectedMonthEnd,
    $selectedMonthEnd
);
$stmt->execute();
$opdPatients = (int) $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$outcomes = [
    ["label" => "OPD Patients Seen", "value" => $opdPatients, "chipClass" => "status-chip-checked-in"],
    ["label" => "Recovered", "value" => $outcomeCounts['recovered'], "chipClass" => "status-chip-checked-in"],
    ["label" => "Currently Confined", "value" => $currentlyConfined, "chipClass" => "status-chip-in-progress"],
    ["label" => "Transferred", "value" => $outcomeCounts['transferred'], "chipClass" => "status-chip-completed"],
    ["label" => "DAMA", "value" => $outcomeCounts['dama'], "chipClass" => "status-chip-waiting"],
    ["label" => "Deceased", "value" => $outcomeCounts['deceased'], "chipClass" => "status-chip-no-show"],
];

$current_page = 'reports';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reports - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <!-- Main content -->
        <main class="main-content">

            <header class="page-header">
                <div>
                    <h1>My Reports</h1>
                    <p class="page-subtitle">Your clinical activity and patient outcomes at a glance.</p>
                </div>
                <div class="header-actions">
                    <span class="header-date"><?php echo htmlspecialchars($today); ?></span>
                </div>
            </header>

            <!-- Stats (for the selected month) -->
            <section class="stats-grid">
                <?php foreach ($stats as $stat): ?>
                    <div class="stat-card stat-<?php echo htmlspecialchars($stat['accent']); ?>">
                        <div class="stat-icon">
                            <?php if ($stat['icon'] === 'users'): ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="9" cy="7" r="4"></circle>
                                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                                </svg>
                            <?php elseif ($stat['icon'] === 'activity'): ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                                </svg>
                            <?php elseif ($stat['icon'] === 'file-text'): ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                    <line x1="16" y1="13" x2="8" y2="13"></line>
                                    <line x1="16" y1="17" x2="8" y2="17"></line>
                                </svg>
                            <?php else: ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M2 4v16"></path>
                                    <path d="M2 8h18a2 2 0 0 1 2 2v10"></path>
                                    <path d="M2 17h20"></path>
                                    <path d="M6 8v9"></path>
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

            <!-- Outcomes breakdown (selected month) -->
            <section class="status-summary">
                <?php foreach ($outcomes as $outcome): ?>
                    <div class="status-chip <?php echo htmlspecialchars($outcome['chipClass']); ?>">
                        <span class="status-chip-value"><?php echo htmlspecialchars($outcome['value']); ?></span>
                        <span class="status-chip-label"><?php echo htmlspecialchars($outcome['label']); ?></span>
                    </div>
                <?php endforeach; ?>
            </section>

            <!-- Content grid -->
            <section class="content-grid">

                <!-- Monthly report history -->
                <div class="card">
                    <div class="card-header">
                        <h2>Monthly Report History</h2>
                        <span class="card-subtitle"><?php echo count($monthlyReports); ?> months</span>
                    </div>

                    <?php if (count($monthlyReports) > 0): ?>
                        <div class="table-wrap">
                            <table class="records-table">
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th>Patients Seen</th>
                                        <th>Consultations</th>
                                        <th>Prescriptions</th>
                                        <th>Confinements</th>
                                        <th>Discharges</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($monthlyReports as $report): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($report['month']); ?></td>
                                            <td><?php echo htmlspecialchars($report['patients_seen']); ?></td>
                                            <td><?php echo htmlspecialchars($report['consultations']); ?></td>
                                            <td><?php echo htmlspecialchars($report['prescriptions']); ?></td>
                                            <td><?php echo htmlspecialchars($report['confinements']); ?></td>
                                            <td><?php echo htmlspecialchars($report['discharges']); ?></td>
                                            <td>
                                                <span class="status-pill <?php echo $report['status'] === 'Finalized' ? 'status-checked-in' : 'status-waiting'; ?>">
                                                    <?php echo htmlspecialchars($report['status']); ?>
                                                </span>
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
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                </svg>
                            </div>
                            <p>No reports have been generated yet.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Right column -->
                <div class="side-column">

                    <!-- Generate report -->
                    <div class="card">
                        <div class="card-header">
                            <h2>Generate Report</h2>
                        </div>
                        <form method="GET" action="reports.php">
                            <div class="toolbar-filters" style="margin-bottom: 16px;">
                                <select class="filter-select" name="month" onchange="this.form.submit()">
                                    <?php foreach ($monthlyReports as $report): ?>
                                        <option value="<?php echo htmlspecialchars($report['month_key']); ?>" <?php echo $report['month_key'] === $selectedMonthKey ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($report['month']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <noscript><button type="submit" class="btn btn-secondary" style="margin-bottom:12px;">View Month</button></noscript>
                        </form>
                        <button type="button" class="btn btn-primary" disabled title="PDF export not yet implemented">Export as PDF</button>
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