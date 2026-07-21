<?php
// doctor/patient-records.php
// Implements spec 2.7 — Patient Records Lookup.
// Entirely read-only: search any patient (not just the doctor's own).
//
// Viewing a specific patient's full record (basic info, medical info,
// appointment/confinement/prescription history) now happens in a modal
// fetched via AJAX (patient-record-ajax.php) instead of a full page
// reload — see assets/js/patient-records.js. This page itself still does
// the search + directory listing server-side, same as before.

require_once '../includes/auth_guard.php';
require_role('doctor');
require_once '../config/db.php';
require_once '../includes/patient_classification.php';

$doctorFirstName = $_SESSION['first_name'];
$doctorLastName = $_SESSION['last_name'];
$doctorFullName = "Dr. " . $doctorFirstName . " " . $doctorLastName;
$doctorInitial = strtoupper(substr($doctorFirstName, 0, 1));

$today = date("F j, Y");

$query = trim($_GET['q'] ?? '');
$matches = [];
$allPatients = [];

function getStatusBadge($status)
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

if ($query !== '') {
    if (ctype_digit($query)) {
        // Looks like a patient ID — exact match only.
        $stmt = $conn->prepare(
            "SELECT user_id, first_name, last_name, status
             FROM users WHERE user_id = ? AND role = 'patient' LIMIT 1"
        );
        $stmt->bind_param("i", $query);
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
            "SELECT user_id, first_name, last_name, status
             FROM users
             WHERE role = 'patient' AND CONCAT(first_name, ' ', last_name) LIKE ?
             ORDER BY first_name, last_name
             LIMIT 20"
        );
        $stmt->bind_param("s", $likeTerm);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $matches[] = $row;
        }
        $stmt->close();
    }
} else {
    // Nothing searched yet — show a browsable directory instead of a
    // blank "no results" screen. Only name/id/status here, never medical
    // history — that stays gated behind an explicit click, which opens
    // the modal (see below).
    $stmt = $conn->prepare(
        "SELECT user_id, first_name, last_name, status
         FROM users WHERE role = 'patient'
         ORDER BY first_name, last_name
         LIMIT 100"
    );
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
    <title>Patient Records - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/patient-records-modal.css">
</head>

<body>

    <div class="app-shell">

        <?php
        $current_page = 'patient-records';
        include 'includes/sidebar.php';
        ?>

        <!-- Main content -->
        <main class="main-content">

            <!-- Page header -->
            <header class="page-header">
                <div>
                    <h1>Patient Records</h1>
                    <p class="page-subtitle">Search and view patient medical records.</p>
                </div>
                <div class="header-actions">
                    <span class="header-date"><?php echo htmlspecialchars($today); ?></span>
                </div>
            </header>

            <!-- Search area -->
            <section class="card search-card">
                <form method="GET" action="patient-records.php" class="search-section">
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
                        <a id="clearBtn" class="btn btn-secondary" href="patient-records.php">
                            Clear
                        </a>
                    </div>
                </form>
            </section>

            <?php if ($query === ''): ?>
                <!-- Nothing searched yet — browsable directory -->
                <section class="card">
                    <div class="card-header">
                        <h2>All Patients</h2>
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
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allPatients as $p):
                                        [$pClass, $pText] = getStatusBadge($p['status']);
                                        $pClassification = patient_classification($p['status']);
                                    ?>
                                        <tr class="patient-row" data-patient-id="<?php echo (int) $p['user_id']; ?>">
                                            <td><?php echo htmlspecialchars(trim($p['first_name'] . ' ' . $p['last_name'])); ?></td>
                                            <td><?php echo (int) $p['user_id']; ?></td>
                                            <td><span class="classification-badge <?php echo $pClassification['class']; ?>"><?php echo htmlspecialchars($pClassification['label']); ?></span></td>
                                            <td><span class="status-pill <?php echo $pClass; ?>"><?php echo $pText; ?></span></td>
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
                            <p>No patients are registered yet.</p>
                        </div>
                    <?php endif; ?>
                </section>
            <?php elseif (count($matches) > 0): ?>
                <!-- Search results (one or more matches) -->
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
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($matches as $m):
                                    [$mClass, $mText] = getStatusBadge($m['status']);
                                    $mClassification = patient_classification($m['status']);
                                ?>
                                    <tr class="patient-row" data-patient-id="<?php echo (int) $m['user_id']; ?>">
                                        <td><?php echo htmlspecialchars(trim($m['first_name'] . ' ' . $m['last_name'])); ?></td>
                                        <td><?php echo (int) $m['user_id']; ?></td>
                                        <td><span class="classification-badge <?php echo $mClassification['class']; ?>"><?php echo htmlspecialchars($mClassification['label']); ?></span></td>
                                        <td><span class="status-pill <?php echo $mClass; ?>"><?php echo $mText; ?></span></td>
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
                <!-- No results for this search -->
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
                        <p><strong>No patient records found for "<?php echo htmlspecialchars($query); ?>".</strong></p>
                        <p style="color: var(--text-muted); font-size: 13.5px;">Try a different name, or search by patient ID.</p>
                    </div>
                </section>
            <?php endif; ?>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <!-- Patient record modal (populated dynamically by patient-records.js) -->
    <div class="modal-backdrop" id="patientModalBackdrop">
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modalPatientName">
            <div class="modal-header">
                <div class="modal-header-top">
                    <div class="modal-identity">
                        <div class="modal-avatar" id="modalAvatar">--</div>
                        <div class="modal-identity-text">
                            <h2 id="modalPatientName">Patient Record</h2>
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
                <div class="modal-loading">Loading patient record...</div>
            </div>
        </div>
    </div>

    <script src="../assets/js/doctor-dashboard.js"></script>
    <script src="../assets/js/patient-records.js"></script>
</body>

</html>