<?php
// admin/hospital-census.php
// Module 2 — Hospital Census (read-only).
//
// REAL BACKEND (2026-07-22): confirmed patients now come from
// confinements JOIN users (patient) JOIN users (attending doctor) LEFT
// JOIN departments (doctor's department stands in for the patient's
// ward/department, same assumption doctor/dashboard.php already makes
// for its own specialty lookup).
//
// "Diagnosis" -> renamed to "Admission Note" in the drawer below.
// confinements has no diagnosis column and no link back to the admitting
// consultation, so there's no reliable way to pull a real diagnosis
// without guessing which past consultation it was. What IS real and
// reliably linked is confinement_notes (via confinement_id) - the
// earliest note for a confinement is the admission note written by
// confine-patient-process.php at admit time, so that's what's shown
// instead. "Timeline" is now the real, full confinement_notes history
// for that confinement, oldest first.

require_once '../includes/auth_guard.php';
require_once '../config/db.php'; // provides $conn (mysqli connection)
require_role('admin');
require_once '../includes/patient_classification.php';

$today = date("F j, Y");

// ---- Which tab: currently-confined roster (default) or the full patient
// directory. Same GET-param view-switch pattern as admin/user-management.php's
// Active/Archived toggle. ----
$currentTab = (($_GET['tab'] ?? '') === 'all-patients') ? 'all-patients' : 'confined';

$confinedResult = $conn->query(
    "SELECT c.confinement_id, c.patient_id,
            p.first_name AS p_first, p.last_name AS p_last,
            d.first_name AS d_first, d.last_name AS d_last,
            dept.department_name,
            c.date_confined, c.room_location, c.clinical_status
     FROM confinements c
     JOIN users p ON p.user_id = c.patient_id
     JOIN users d ON d.user_id = c.attending_doctor_id
     LEFT JOIN departments dept ON dept.department_id = d.department_id
     WHERE c.discharge_status IS NULL
     ORDER BY c.date_confined ASC"
);
$confinedRows = $confinedResult ? $confinedResult->fetch_all(MYSQLI_ASSOC) : [];

// One extra query to pull every confinement_notes row for these
// confinements at once (grouped in PHP below) instead of one query per row.
$notesByConfinement = [];
$confinementIds = array_column($confinedRows, 'confinement_id');
if (!empty($confinementIds)) {
    $idList = implode(',', array_map('intval', $confinementIds));
    $notesResult = $conn->query(
        "SELECT confinement_id, note, created_at FROM confinement_notes
         WHERE confinement_id IN ($idList)
         ORDER BY created_at ASC"
    );
    while ($n = $notesResult->fetch_assoc()) {
        $notesByConfinement[$n['confinement_id']][] = $n;
    }
}

$confinedPatients = array_map(function ($row) use ($notesByConfinement) {
    $notes = $notesByConfinement[$row['confinement_id']] ?? [];
    $admissionNote = $notes[0]['note'] ?? 'No admission note recorded.';

    $timeline = array_map(function ($n) {
        return [
            "text" => $n['note'],
            "time" => date("M j, Y — g:i A", strtotime($n['created_at'])),
        ];
    }, $notes);

    return [
        "patient_id"     => (int) $row['patient_id'],
        "name"           => $row['p_first'] . ' ' . $row['p_last'],
        "doctor"         => "Dr. " . $row['d_first'] . ' ' . $row['d_last'],
        "department"     => $row['department_name'] ?? 'Unassigned',
        "date_confined"  => $row['date_confined'],
        "room"           => $row['room_location'] ?? '—',
        "status"         => $row['clinical_status'],
        "diagnosis"      => $admissionNote,
        "timeline"       => $timeline,
        "days_confined"  => (new DateTime($row['date_confined']))->diff(new DateTime('today'))->days + 1,
    ];
}, $confinedRows);

$departments = array_values(array_unique(array_column($confinedPatients, 'department')));
sort($departments);

function censusStatusLabel($status)
{
    $map = ["stable" => "Stable", "improving" => "Improving", "critical" => "Critical"];
    return $map[$status] ?? ucfirst($status);
}

// ---- All Patients tab: hospital-wide directory + search, same
// search-then-directory logic as doctor/patient-records.php (spec 2.7) -
// this just gives admin the same read-only lookup. ----
$patientQuery = '';
$patientMatches = [];
$allPatientsDirectory = [];

function censusPatientStatusBadge($status)
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

if ($currentTab === 'all-patients') {
    $patientQuery = trim($_GET['q'] ?? '');

    if ($patientQuery !== '') {
        if (ctype_digit($patientQuery)) {
            // Looks like a patient ID — exact match only.
            $stmt = $conn->prepare(
                "SELECT user_id, first_name, last_name, status
                 FROM users WHERE user_id = ? AND role = 'patient' LIMIT 1"
            );
            $stmt->bind_param("i", $patientQuery);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $patientMatches[] = $row;
            }
            $stmt->close();
        } else {
            // Name search — could match more than one patient.
            $likeTerm = "%" . $patientQuery . "%";
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
                $patientMatches[] = $row;
            }
            $stmt->close();
        }
    } else {
        // Nothing searched yet — browsable directory, same 100-row cap as
        // the doctor portal's version. Only name/id/status here, never
        // medical history — that stays gated behind the record modal.
        $stmt = $conn->prepare(
            "SELECT user_id, first_name, last_name, status
             FROM users WHERE role = 'patient'
             ORDER BY first_name, last_name
             LIMIT 100"
        );
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $allPatientsDirectory[] = $row;
        }
        $stmt->close();
    }
}

$current_page = 'hospital-census';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hospital Census - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-dashboard.css">
    <link rel="stylesheet" href="../assets/css/hospital-census.css">
    <link rel="stylesheet" href="../assets/css/patient-records-modal.css">
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">

            <header class="page-header">
                <div>
                    <h1>Hospital Census</h1>
                    <p class="page-subtitle">Confined patients and the full patient directory, hospital-wide. Read-only.</p>
                </div>
                <div class="header-actions">
                    <span class="header-date"><?php echo htmlspecialchars($today); ?></span>
                </div>
            </header>

            <div class="tabs-container">
                <div class="tabs-nav">
                    <a href="?tab=confined" class="tab-btn <?php echo $currentTab === 'confined' ? 'active' : ''; ?>">Confined Patients</a>
                    <a href="?tab=all-patients" class="tab-btn <?php echo $currentTab === 'all-patients' ? 'active' : ''; ?>">All Patients</a>
                </div>
            </div>

            <?php if ($currentTab === 'confined'): ?>
                <section class="card queue-full-card">

                    <div class="queue-toolbar">
                        <div class="search-field">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8"></circle>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            </svg>
                            <input type="text" id="censusSearchInput" placeholder="Search patient name...">
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
                                <option value="stable">Stable</option>
                                <option value="improving">Improving</option>
                                <option value="critical">Critical</option>
                            </select>

                            <select id="sortSelect" class="filter-select">
                                <option value="admission-desc">Newest Admission</option>
                                <option value="admission-asc">Oldest Admission</option>
                                <option value="days-desc">Most Days Confined</option>
                                <option value="name-asc">Patient Name (A–Z)</option>
                            </select>
                        </div>
                    </div>

                    <?php if (count($confinedPatients) > 0): ?>
                        <div class="table-wrap">
                            <table class="queue-table" id="censusTable">
                                <thead>
                                    <tr>
                                        <th>Patient Name</th>
                                        <th>Assigned Doctor</th>
                                        <th>Admission Date</th>
                                        <th>Days Confined</th>
                                        <th>Room</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($confinedPatients as $p): ?>
                                        <tr class="queue-row census-row"
                                            data-patient-id="<?php echo (int) $p['patient_id']; ?>"
                                            data-name="<?php echo htmlspecialchars(strtolower($p['name'])); ?>"
                                            data-department="<?php echo htmlspecialchars($p['department']); ?>"
                                            data-status="<?php echo htmlspecialchars($p['status']); ?>"
                                            data-date="<?php echo htmlspecialchars($p['date_confined']); ?>"
                                            data-days="<?php echo (int) $p['days_confined']; ?>"
                                            tabindex="0">
                                            <td class="patient-cell">
                                                <span class="patient-avatar"><?php echo htmlspecialchars(strtoupper(substr($p['name'], 0, 1))); ?></span>
                                                <?php echo htmlspecialchars($p['name']); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($p['doctor']); ?></td>
                                            <td><?php echo htmlspecialchars(date('M j, Y', strtotime($p['date_confined']))); ?></td>
                                            <td><?php echo (int) $p['days_confined']; ?> day<?php echo $p['days_confined'] == 1 ? '' : 's'; ?></td>
                                            <td><?php echo htmlspecialchars($p['room']); ?></td>
                                            <td>
                                                <span class="status-pill status-<?php echo htmlspecialchars($p['status']); ?>">
                                                    <?php echo htmlspecialchars(censusStatusLabel($p['status'])); ?>
                                                </span>
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
                                    <path d="M2 4v16"></path>
                                    <path d="M2 8h18a2 2 0 0 1 2 2v10"></path>
                                    <path d="M2 17h20"></path>
                                    <path d="M6 8v9"></path>
                                </svg>
                            </div>
                            <p>No confined patients.</p>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($currentTab === 'all-patients'): ?>
                <section class="card search-card">
                    <form method="GET" action="hospital-census.php" class="search-section">
                        <input type="hidden" name="tab" value="all-patients">
                        <div class="search-input-wrapper">
                            <input
                                type="text"
                                id="patientSearchInput"
                                name="q"
                                placeholder="Search by Patient Name or ID (e.g., 1)..."
                                class="search-input-large"
                                value="<?php echo htmlspecialchars($patientQuery); ?>">
                        </div>
                        <div class="search-buttons">
                            <button id="patientSearchBtn" class="btn btn-search" type="submit">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="11" cy="11" r="8"></circle>
                                    <path d="m21 21-4.35-4.35"></path>
                                </svg>
                                Search
                            </button>
                            <a id="patientClearBtn" class="btn btn-secondary" href="?tab=all-patients">
                                Clear
                            </a>
                        </div>
                    </form>
                </section>

                <?php if ($patientQuery === ''): ?>
                    <!-- Nothing searched yet — browsable directory -->
                    <section class="card">
                        <div class="card-header">
                            <h2>All Patients</h2>
                            <span class="card-subtitle"><?php echo count($allPatientsDirectory); ?> total</span>
                        </div>
                        <?php if (count($allPatientsDirectory) > 0): ?>
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
                                        <?php foreach ($allPatientsDirectory as $p):
                                            [$pClass, $pText] = censusPatientStatusBadge($p['status']);
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
                <?php elseif (count($patientMatches) > 0): ?>
                    <!-- Search results (one or more matches) -->
                    <section class="card">
                        <div class="card-header">
                            <h2>Search Results</h2>
                            <span class="card-subtitle"><?php echo count($patientMatches); ?> found</span>
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
                                    <?php foreach ($patientMatches as $m):
                                        [$mClass, $mText] = censusPatientStatusBadge($m['status']);
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
                            <p><strong>No patient records found for "<?php echo htmlspecialchars($patientQuery); ?>".</strong></p>
                            <p style="color: var(--text-muted); font-size: 13.5px;">Try a different name, or search by patient ID.</p>
                        </div>
                    </section>
                <?php endif; ?>
            <?php endif; ?>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <!-- Right-side drawer: patient detail, read-only. Opened by clicking
         any row in the table above. -->
    <div class="drawer-backdrop" id="censusDrawerBackdrop">
        <aside class="drawer-panel" id="censusDrawerPanel" role="dialog" aria-modal="true" aria-labelledby="drawerPatientName">
            <div class="drawer-header">
                <div class="drawer-header-identity">
                    <div class="drawer-avatar" id="drawerAvatar"></div>
                    <div>
                        <p class="drawer-header-name" id="drawerPatientName"></p>
                        <p class="drawer-header-sub" id="drawerPatientSub"></p>
                    </div>
                </div>
                <button type="button" class="drawer-close-btn" id="drawerCloseBtn" aria-label="Close panel">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="drawer-body">
                <div class="drawer-section">
                    <p class="drawer-section-title">Patient Information</p>
                    <div class="drawer-info-row">
                        <span class="drawer-info-key">Attending Doctor</span>
                        <span class="drawer-info-value" id="drawerDoctor"></span>
                    </div>
                    <div class="drawer-info-row">
                        <span class="drawer-info-key">Admission Date</span>
                        <span class="drawer-info-value" id="drawerDate"></span>
                    </div>
                    <div class="drawer-info-row">
                        <span class="drawer-info-key">Room</span>
                        <span class="drawer-info-value" id="drawerRoom"></span>
                    </div>
                    <div class="drawer-info-row">
                        <span class="drawer-info-key">Status</span>
                        <span class="drawer-info-value" id="drawerStatus"></span>
                    </div>
                </div>

                <div class="drawer-section">
                    <p class="drawer-section-title">Admission Note</p>
                    <div class="drawer-diagnosis-box" id="drawerDiagnosis"></div>
                </div>

                <div class="drawer-section">
                    <p class="drawer-section-title">Timeline</p>
                    <ul class="activity-timeline" id="drawerTimeline"></ul>
                </div>
            </div>
        </aside>
    </div>

    <!-- Patient record modal (All Patients tab) — populated dynamically by
         patient-records.js, same modal used by the doctor portal's Patient
         Records page. Harmless to include on the Confined Patients tab too
         since nothing on that tab carries a .patient-row/.view-patient-btn
         trigger for it to bind to. -->
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

    <script src="../assets/js/patient-records.js"></script>

    <script>
        // Same data the table above was rendered from, re-exposed as JSON so
        // the drawer doesn't need a second round-trip to the server. Once
        // this is wired to the backend, this can become a small fetch() to
        // e.g. hospital-census-detail.php?patient_id=... instead.
        const censusData = <?php echo json_encode(array_combine(
                                array_column($confinedPatients, 'patient_id'),
                                $confinedPatients
                            )); ?>;

        // ---- Search / filter / sort (same pattern as Today's Queue) ----
        (function() {
            const searchInput = document.getElementById('censusSearchInput');
            const departmentFilter = document.getElementById('departmentFilter');
            const statusFilter = document.getElementById('statusFilter');
            const sortSelect = document.getElementById('sortSelect');
            const table = document.getElementById('censusTable');
            const noResults = document.getElementById('noResultsRow');
            if (!table) return;

            const tbody = table.querySelector('tbody');
            let rows = Array.from(table.querySelectorAll('.census-row'));

            function applyFilters() {
                const searchTerm = searchInput ? searchInput.value.trim().toLowerCase() : '';
                const department = departmentFilter ? departmentFilter.value : 'all';
                const status = statusFilter ? statusFilter.value : 'all';
                let visibleCount = 0;

                rows.forEach(function(row) {
                    const name = row.getAttribute('data-name') || '';
                    const rowDept = row.getAttribute('data-department') || '';
                    const rowStatus = row.getAttribute('data-status') || '';

                    const matchesSearch = name.indexOf(searchTerm) !== -1;
                    const matchesDept = department === 'all' || rowDept === department;
                    const matchesStatus = status === 'all' || rowStatus === status;

                    const isVisible = matchesSearch && matchesDept && matchesStatus;
                    row.hidden = !isVisible;
                    if (isVisible) visibleCount++;
                });

                if (noResults) noResults.hidden = visibleCount !== 0;
            }

            function applySort() {
                const mode = sortSelect ? sortSelect.value : 'admission-desc';
                const sorted = rows.slice().sort(function(a, b) {
                    if (mode === 'admission-desc') return b.getAttribute('data-date').localeCompare(a.getAttribute('data-date'));
                    if (mode === 'admission-asc') return a.getAttribute('data-date').localeCompare(b.getAttribute('data-date'));
                    if (mode === 'days-desc') return (+b.getAttribute('data-days')) - (+a.getAttribute('data-days'));
                    if (mode === 'name-asc') return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
                    return 0;
                });
                sorted.forEach(function(row) {
                    tbody.appendChild(row);
                });
                rows = sorted;
            }

            if (searchInput) searchInput.addEventListener('input', applyFilters);
            if (departmentFilter) departmentFilter.addEventListener('change', applyFilters);
            if (statusFilter) statusFilter.addEventListener('change', applyFilters);
            if (sortSelect) sortSelect.addEventListener('change', function() {
                applySort();
                applyFilters();
            });
        })();

        // ---- Drawer open/close ----
        (function() {
            const backdrop = document.getElementById('censusDrawerBackdrop');
            const closeBtn = document.getElementById('drawerCloseBtn');
            if (!backdrop) return;

            const statusLabels = {
                stable: 'Stable',
                improving: 'Improving',
                critical: 'Critical'
            };

            function openDrawer(patientId) {
                const p = censusData[patientId];
                if (!p) return;

                document.getElementById('drawerAvatar').textContent = p.name.charAt(0).toUpperCase();
                document.getElementById('drawerPatientName').textContent = p.name;
                document.getElementById('drawerPatientSub').textContent = p.department;
                document.getElementById('drawerDoctor').textContent = p.doctor;
                document.getElementById('drawerDate').textContent = new Date(p.date_confined + 'T00:00:00')
                    .toLocaleDateString('en-US', {
                        month: 'short',
                        day: 'numeric',
                        year: 'numeric'
                    });
                document.getElementById('drawerRoom').textContent = p.room;

                const statusEl = document.getElementById('drawerStatus');
                statusEl.innerHTML = '';
                const chip = document.createElement('span');
                chip.className = 'status-pill status-' + p.status;
                chip.textContent = statusLabels[p.status] || p.status;
                statusEl.appendChild(chip);

                document.getElementById('drawerDiagnosis').textContent = p.diagnosis;

                const timelineEl = document.getElementById('drawerTimeline');
                if (p.timeline && p.timeline.length > 0) {
                    timelineEl.innerHTML = p.timeline.map(function(item) {
                        return '<li class="activity-item"><span class="activity-dot"></span>' +
                            '<div class="activity-body"><p>' + escapeHtml(item.text) + '</p>' +
                            '<span class="activity-time">' + escapeHtml(item.time) + '</span></div></li>';
                    }).join('');
                } else {
                    timelineEl.innerHTML = '<li class="activity-item"><div class="activity-body"><p>No notes recorded yet.</p></div></li>';
                }

                backdrop.classList.add('active');
                document.body.classList.add('drawer-open');
            }

            function closeDrawer() {
                backdrop.classList.remove('active');
                document.body.classList.remove('drawer-open');
            }

            function escapeHtml(str) {
                const div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            }

            document.querySelectorAll('.census-row').forEach(function(row) {
                row.addEventListener('click', function() {
                    openDrawer(row.getAttribute('data-patient-id'));
                });
                row.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        openDrawer(row.getAttribute('data-patient-id'));
                    }
                });
            });

            if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
            backdrop.addEventListener('click', function(e) {
                if (e.target === backdrop) closeDrawer();
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') closeDrawer();
            });
        })();
    </script>
</body>

</html>