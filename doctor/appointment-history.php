<?php
// doctor/appointment-history.php
// "Appointment History" — unlike patient-records.php (hospital-wide, any
// doctor), this page only lists patients THIS logged-in doctor has
// personally checked up: patients with a consultations row where
// doctor_id = this doctor, OR a confinements row where
// attending_doctor_id = this doctor (a patient can be admitted directly
// via confined-patients.php without a prior consultations row for this
// doctor - see confine-patient-process.php - so both are checked).
//
// Clicking a patient opens a modal (appointment-history-ajax.php) showing
// only THIS doctor's own encounters with that patient: a chronological
// visit timeline (diagnosis, prescriptions, lab orders per visit) plus
// any confinement(s) this doctor attended. Other doctors' encounters with
// the same patient are never shown here - that's what Patient Records is
// for.

require_once '../includes/auth_guard.php';
require_role('doctor');
require_once '../config/db.php';
require_once '../includes/patient_classification.php';

$doctorId = (int) $_SESSION['user_id'];
$doctorFirstName = $_SESSION['first_name'];
$doctorLastName = $_SESSION['last_name'];
$doctorFullName = "Dr. " . $doctorFirstName . " " . $doctorLastName;
$doctorInitial = strtoupper(substr($doctorFirstName, 0, 1));

$today = date("F j, Y");

$query = trim($_GET['q'] ?? '');
$matches = [];
$allPatients = [];

function ahStatusBadge($status)
{
    $badgeClass = [
        "active" => "status-active",
        "confined" => "status-confined",
        "blocked" => "status-discharged",
    ];
    $badgeText = [
        "active" => "Active",
        "confined" => "Confined",
        "blocked" => "Blocked",
    ];
    $class = $badgeClass[$status] ?? "status-active";
    $text = $badgeText[$status] ?? ucfirst($status);
    return [$class, $text];
}

// Every listing query (search or directory) is scoped down to this
// doctor's own patients via this EXISTS pair, plus visit-count/last-visit
// so the directory can show something more useful than a bare name list.
$scopeSql = "(
        EXISTS (SELECT 1 FROM consultations c WHERE c.doctor_id = ? AND c.patient_id = u.user_id)
        OR EXISTS (SELECT 1 FROM confinements cf WHERE cf.attending_doctor_id = ? AND cf.patient_id = u.user_id)
    )";
$visitCountSql = "(SELECT COUNT(*) FROM consultations c2 WHERE c2.doctor_id = ? AND c2.patient_id = u.user_id) AS visit_count";
$lastVisitSql = "(SELECT MAX(c3.created_at) FROM consultations c3 WHERE c3.doctor_id = ? AND c3.patient_id = u.user_id) AS last_visit";

if ($query !== '') {
    if (ctype_digit($query)) {
        // Looks like a patient ID — exact match only.
        $stmt = $conn->prepare(
            "SELECT u.user_id, u.first_name, u.last_name, u.status, $visitCountSql, $lastVisitSql
             FROM users u
             WHERE u.user_id = ? AND u.role = 'patient' AND $scopeSql
             LIMIT 1"
        );
        $stmt->bind_param("iiiii", $doctorId, $doctorId, $query, $doctorId, $doctorId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $matches[] = $row;
        }
        $stmt->close();
    } else {
        // Name search — could match more than one patient.
        $likeTerm = "%" . $query . "%";
        $stmt = $conn->prepare(
            "SELECT u.user_id, u.first_name, u.last_name, u.status, $visitCountSql, $lastVisitSql
             FROM users u
             WHERE u.role = 'patient' AND CONCAT(u.first_name, ' ', u.last_name) LIKE ? AND $scopeSql
             ORDER BY u.first_name, u.last_name
             LIMIT 20"
        );
        $stmt->bind_param("iisii", $doctorId, $doctorId, $likeTerm, $doctorId, $doctorId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $matches[] = $row;
        }
        $stmt->close();
    }
} else {
    // Nothing searched yet — directory of every patient this doctor has
    // ever personally checked up, most recent visit first.
    $stmt = $conn->prepare(
        "SELECT u.user_id, u.first_name, u.last_name, u.status, $visitCountSql, $lastVisitSql
         FROM users u
         WHERE u.role = 'patient' AND $scopeSql
         ORDER BY last_visit DESC
         LIMIT 100"
    );
    $stmt->bind_param("iiii", $doctorId, $doctorId, $doctorId, $doctorId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $allPatients[] = $row;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointment History - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/patient-records-modal.css">
</head>

<body>

    <div class="app-shell">

        <?php
        $current_page = 'appointment-history';
        include 'includes/sidebar.php';
        ?>

        <!-- Main content -->
        <main class="main-content">

            <!-- Page header -->
            <header class="page-header">
                <div>
                    <h1>Appointment History</h1>
                    <p class="page-subtitle">Every patient you've personally checked up on, with the full visit history for each.</p>
                </div>
                <div class="header-actions">
                    <span class="header-date"><?php echo htmlspecialchars($today); ?></span>
                </div>
            </header>

            <!-- Search area -->
            <section class="card search-card">
                <form method="GET" action="appointment-history.php" class="search-section">
                    <div class="search-input-wrapper">
                        <input
                            type="text"
                            id="patientSearchInput"
                            name="q"
                            placeholder="Search by Patient Name or ID (e.g., 1)..."
                            class="search-input-large"
                            value="<?php echo htmlspecialchars($query); ?>">
                    </div>
                    <div class="search-buttons">
                        <button id="searchBtn" class="btn btn-search" type="submit">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8"></circle>
                                <path d="m21 21-4.35-4.35"></path>
                            </svg>
                            Search
                        </button>
                        <a id="clearBtn" class="btn btn-secondary" href="appointment-history.php">
                            Clear
                        </a>
                    </div>
                </form>
            </section>

            <?php if ($query === ''): ?>
                <!-- Nothing searched yet — this doctor's own patient directory -->
                <section class="card">
                    <div class="card-header">
                        <h2>Patients You've Checked Up</h2>
                        <span class="card-subtitle"><?php echo count($allPatients); ?> total</span>
                    </div>
                    <?php if (count($allPatients) > 0): ?>
                        <div class="table-wrap">
                            <table class="records-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Patient ID</th>
                                        <th>Classification</th>
                                        <th>Status</th>
                                        <th>Visits</th>
                                        <th>Last Visit</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allPatients as $p):
                                        [$pClass, $pText] = ahStatusBadge($p['status']);
                                        $pClassification = patient_classification($p['status']);
                                    ?>
                                        <tr class="patient-row" data-patient-id="<?php echo (int) $p['user_id']; ?>">
                                            <td><?php echo htmlspecialchars(trim($p['first_name'] . ' ' . $p['last_name'])); ?></td>
                                            <td><?php echo (int) $p['user_id']; ?></td>
                                            <td><span class="classification-badge <?php echo $pClassification['class']; ?>"><?php echo htmlspecialchars($pClassification['label']); ?></span></td>
                                            <td><span class="status-pill <?php echo $pClass; ?>"><?php echo $pText; ?></span></td>
                                            <td><?php echo (int) $p['visit_count']; ?></td>
                                            <td><?php echo $p['last_visit'] ? htmlspecialchars(date('M j, Y', strtotime($p['last_visit']))) : '—'; ?></td>
                                            <td>
                                                <button type="button" class="btn-view-record view-patient-btn" data-patient-id="<?php echo (int) $p['user_id']; ?>">
                                                    View Details
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <p>You haven't checked up on any patients yet.</p>
                        </div>
                    <?php endif; ?>
                </section>
            <?php elseif (count($matches) > 0): ?>
                <!-- Search results (one or more matches, still scoped to this doctor) -->
                <section class="card">
                    <div class="card-header">
                        <h2>Search Results</h2>
                        <span class="card-subtitle"><?php echo count($matches); ?> found</span>
                    </div>
                    <div class="table-wrap">
                        <table class="records-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Patient ID</th>
                                    <th>Classification</th>
                                    <th>Status</th>
                                    <th>Visits</th>
                                    <th>Last Visit</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($matches as $m):
                                    [$mClass, $mText] = ahStatusBadge($m['status']);
                                    $mClassification = patient_classification($m['status']);
                                ?>
                                    <tr class="patient-row" data-patient-id="<?php echo (int) $m['user_id']; ?>">
                                        <td><?php echo htmlspecialchars(trim($m['first_name'] . ' ' . $m['last_name'])); ?></td>
                                        <td><?php echo (int) $m['user_id']; ?></td>
                                        <td><span class="classification-badge <?php echo $mClassification['class']; ?>"><?php echo htmlspecialchars($mClassification['label']); ?></span></td>
                                        <td><span class="status-pill <?php echo $mClass; ?>"><?php echo $mText; ?></span></td>
                                        <td><?php echo (int) $m['visit_count']; ?></td>
                                        <td><?php echo $m['last_visit'] ? htmlspecialchars(date('M j, Y', strtotime($m['last_visit']))) : '—'; ?></td>
                                        <td>
                                            <button type="button" class="btn-view-record view-patient-btn" data-patient-id="<?php echo (int) $m['user_id']; ?>">
                                                View Details
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php else: ?>
                <!-- No results for this search, or the match isn't one of this doctor's own patients -->
                <section class="card">
                    <div class="empty-state">
                        <div class="empty-illustration">
                            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <line x1="12" y1="11" x2="12" y2="17"></line>
                                <line x1="9" y1="14" x2="15" y2="14"></line>
                            </svg>
                        </div>
                        <p><strong>No patient of yours found for "<?php echo htmlspecialchars($query); ?>".</strong></p>
                        <p style="color: var(--text-muted); font-size: 13.5px;">Try a different name, or search by patient ID. This only searches patients you've personally checked up on.</p>
                    </div>
                </section>
            <?php endif; ?>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <!-- Visit history modal (populated dynamically by appointment-history.js) -->
    <div class="modal-backdrop" id="patientModalBackdrop">
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modalPatientName">
            <div class="modal-header">
                <div class="modal-header-top">
                    <div class="modal-identity">
                        <div class="modal-avatar" id="modalAvatar">--</div>
                        <div class="modal-identity-text">
                            <h2 id="modalPatientName">Patient History</h2>
                            <div class="modal-identity-meta">
                                <span class="modal-patient-id" id="modalPatientId"></span>
                                <span class="classification-badge" id="modalClassificationBadge"></span>
                                <span class="status-pill" id="modalStatusBadge"></span>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="modal-close-btn" id="modalCloseBtn" aria-label="Close">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                </div>
            </div>
            <div class="modal-body" id="modalBody">
                <div class="modal-loading">Loading visit history...</div>
            </div>
        </div>
    </div>

    <script src="../assets/js/doctor-dashboard.js"></script>
    <script src="../assets/js/appointment-history.js"></script>
</body>

</html>
