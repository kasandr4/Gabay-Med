<?php
// admin/hospital-census.php
// Module 2 — Hospital Census (UI ONLY, read-only).
//
// Per the brief: no backend logic, no SQL beyond the existing auth guard.
// $confinedPatients below stands in for a future query against
// `confinements` JOIN `users` JOIN `users` (attending doctor) — see the
// TODO(backend) comment on the array. Every field name deliberately
// matches the real `confinements`/`users` columns (room_location,
// clinical_status, etc.) so wiring this up later is a drop-in swap, not a
// redesign.
//
// This page is intentionally read-only: no discharge button, no edit
// button, anywhere — matching the spec. That action lives in the Doctor
// Portal's Confinement module, not here.

require_once '../includes/auth_guard.php';
require_role('admin');

$today = date("F j, Y");

// TODO(backend): replace with
//   SELECT c.confinement_id, p.first_name, p.last_name, p.user_id AS patient_id,
//          d.first_name AS doc_first, d.last_name AS doc_last,
//          c.date_confined, c.room_location, c.clinical_status
//   FROM confinements c
//   JOIN users p ON p.user_id = c.patient_id
//   JOIN users d ON d.user_id = c.attending_doctor_id
//   WHERE c.discharge_status IS NULL
//   ORDER BY c.date_confined ASC
// "days_confined" would be DATEDIFF(CURDATE(), c.date_confined) in SQL;
// computed here in PHP from the placeholder date instead.
// "diagnosis" and "timeline" don't have dedicated columns yet — diagnosis
// would likely come from the admitting consultation's `findings`, and the
// timeline from a future confinement_notes/progress_notes table (the
// Doctor Portal's confinement-note-process.php already writes something
// like this). Both are illustrative placeholder content for now.
$confinedPatientsRaw = [
    [
        "patient_id" => 1,
        "name" => "Juan Dela Cruz",
        "doctor" => "Dr. Ramon Santos",
        "department" => "Internal Medicine",
        "date_confined" => "2026-07-10",
        "room" => "Ward 3, Bed 7",
        "status" => "stable",
        "diagnosis" => "Community-acquired pneumonia, responding to antibiotic therapy.",
        "timeline" => [
            ["text" => "Admitted to Ward 3, Bed 7", "time" => "Jul 10, 2026 — 8:14 AM"],
            ["text" => "Started on IV antibiotics", "time" => "Jul 10, 2026 — 9:00 AM"],
            ["text" => "Vitals stable, oxygen weaned to room air", "time" => "Jul 12, 2026 — 7:30 AM"],
            ["text" => "Chest X-ray follow-up scheduled", "time" => "Jul 14, 2026 — 10:15 AM"],
        ],
    ],
    [
        "patient_id" => 19,
        "name" => "Angela Mercado",
        "doctor" => "Dr. Ramon Santos",
        "department" => "Internal Medicine",
        "date_confined" => "2026-07-11",
        "room" => "Ward 3, Bed 7",
        "status" => "improving",
        "diagnosis" => "Dengue fever without warning signs, on supportive management.",
        "timeline" => [
            ["text" => "Admitted to Ward 3, Bed 7", "time" => "Jul 11, 2026 — 6:40 AM"],
            ["text" => "Platelet count monitoring started", "time" => "Jul 11, 2026 — 7:00 AM"],
            ["text" => "Fever subsided, appetite improving", "time" => "Jul 13, 2026 — 6:00 PM"],
        ],
    ],
    [
        "patient_id" => 24,
        "name" => "Pedro Reyes",
        "doctor" => "Dr. Liza Fernandez",
        "department" => "Surgery",
        "date_confined" => "2026-07-08",
        "room" => "Ward 1, Bed 3",
        "status" => "critical",
        "diagnosis" => "Post-operative monitoring following emergency appendectomy.",
        "timeline" => [
            ["text" => "Admitted for post-op monitoring", "time" => "Jul 8, 2026 — 11:20 PM"],
            ["text" => "Returned to OR for wound revision", "time" => "Jul 9, 2026 — 4:10 AM"],
            ["text" => "Placed under close nursing observation", "time" => "Jul 9, 2026 — 5:00 AM"],
        ],
    ],
    [
        "patient_id" => 31,
        "name" => "Maria Santos",
        "doctor" => "Dr. Ramon Santos",
        "department" => "Internal Medicine",
        "date_confined" => "2026-07-13",
        "room" => "Ward 2, Bed 5",
        "status" => "stable",
        "diagnosis" => "Hypertensive urgency, blood pressure under active management.",
        "timeline" => [
            ["text" => "Admitted to Ward 2, Bed 5", "time" => "Jul 13, 2026 — 2:05 PM"],
            ["text" => "Started on oral antihypertensives", "time" => "Jul 13, 2026 — 2:45 PM"],
        ],
    ],
];

$confinedPatients = array_map(function ($p) {
    $p['days_confined'] = (new DateTime($p['date_confined']))->diff(new DateTime('today'))->days + 1;
    return $p;
}, $confinedPatientsRaw);

$departments = array_values(array_unique(array_column($confinedPatients, 'department')));
sort($departments);

function censusStatusLabel($status)
{
    $map = ["stable" => "Stable", "improving" => "Improving", "critical" => "Critical"];
    return $map[$status] ?? ucfirst($status);
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
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">

            <header class="page-header">
                <div>
                    <h1>Hospital Census</h1>
                    <p class="page-subtitle">All currently confined patients, hospital-wide. Read-only.</p>
                </div>
                <div class="header-actions">
                    <span class="header-date"><?php echo htmlspecialchars($today); ?></span>
                </div>
            </header>

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
                    <p class="drawer-section-title">Diagnosis</p>
                    <div class="drawer-diagnosis-box" id="drawerDiagnosis"></div>
                </div>

                <div class="drawer-section">
                    <p class="drawer-section-title">Timeline</p>
                    <ul class="activity-timeline" id="drawerTimeline"></ul>
                </div>
            </div>
        </aside>
    </div>

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
                timelineEl.innerHTML = (p.timeline || []).map(function(item) {
                    return '<li class="activity-item"><span class="activity-dot"></span>' +
                        '<div class="activity-body"><p>' + escapeHtml(item.text) + '</p>' +
                        '<span class="activity-time">' + escapeHtml(item.time) + '</span></div></li>';
                }).join('');

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