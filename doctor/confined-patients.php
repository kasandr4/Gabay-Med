<?php
// doctor/confined-patients.php
// Entry point into the Confinement module, linked from the sidebar.
//
// This file serves two views depending on the query string:
//   - No patient_id  -> "Confined Patients" list/dashboard: every ongoing
//     confinement under this doctor (this is what the sidebar links to).
//   - patient_id (+ optional appointment_id) present -> "Admit Patient"
//     form, reached from Consultation's "Admit Patient" outcome or manually.
//     This branch only creates the confinement record - discharge and
//     ongoing monitoring happen on confinement-record.php.
//
// IMPORTANT: the "no patient specified" fallback below must NEVER redirect
// back to this same file with no patient_id, or the list view (the sidebar's
// target) would redirect to itself forever.

require_once '../includes/auth_guard.php';
require_role('doctor');
require_once '../config/db.php';
require_once '../includes/csrf.php';

$doctorFirstName = $_SESSION['first_name'];
$doctorLastName  = $_SESSION['last_name'];
$doctorFullName  = "Dr. " . $doctorFirstName . " " . $doctorLastName;
$doctorInitial   = strtoupper(substr($doctorFirstName, 0, 1));
$doctorId        = (int) $_SESSION['user_id'];

$today = date("F j, Y");

$flash = $_SESSION['confine_flash'] ?? null;
unset($_SESSION['confine_flash']);

$patientId = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;
$appointmentId = isset($_GET['appointment_id']) ? (int) $_GET['appointment_id'] : 0;

$admitMode = $patientId > 0;
$patient = null;
$confinedPatients = [];
$locations = [];

if ($admitMode) {

    // Look up the patient (must actually be a patient, not any user_id).
    $stmt = $conn->prepare(
        "SELECT user_id, first_name, last_name, birthdate, sex
         FROM users WHERE user_id = ? AND role = 'patient' LIMIT 1"
    );
    $stmt->bind_param("i", $patientId);
    $stmt->execute();
    $patientRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$patientRow) {
        $_SESSION['confine_flash'] = [
            "type" => "error",
            "message" => "That patient could not be found.",
        ];
        header("Location: confined-patients.php");
        exit;
    }

    // A patient can't be admitted twice - if there's already an ongoing
    // confinement (discharge_date IS NULL), send the doctor to that record
    // instead of letting them create a second, conflicting one.
    $stmt = $conn->prepare(
        "SELECT confinement_id FROM confinements
         WHERE patient_id = ? AND discharge_date IS NULL LIMIT 1"
    );
    $stmt->bind_param("i", $patientId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $_SESSION['confine_flash'] = [
            "type" => "error",
            "message" => "This patient already has an ongoing confinement.",
        ];
        header("Location: confinement-record.php?confinement_id=" . (int) $existing['confinement_id']);
        exit;
    }

    $age = null;
    if (!empty($patientRow['birthdate'])) {
        $age = (new DateTime($patientRow['birthdate']))->diff(new DateTime())->y;
    }

    $patient = [
        "name"   => trim($patientRow['first_name'] . ' ' . $patientRow['last_name']),
        "id"     => $patientRow['user_id'],
        "age"    => $age,
        "gender" => $patientRow['sex'] ? ucfirst($patientRow['sex']) : "—",
    ];

    // --- Emergency-info gate before admission ------------------------------
    // A confined patient needs an emergency contact on file. Walk-in patients
    // in particular may only have name/phone (see staff/walk-in.php), so this
    // check catches that gap right before admission rather than after.
    $stmt = $conn->prepare("SELECT account_status FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $patientId);
    $stmt->execute();
    $accountRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $isGuestAccount = ($accountRow['account_status'] ?? 'active') === 'guest';

    $stmt = $conn->prepare(
        "SELECT blood_type, civil_status, emergency_contact_name, emergency_contact_number
         FROM patient_profiles WHERE patient_id = ? LIMIT 1"
    );
    $stmt->bind_param("i", $patientId);
    $stmt->execute();
    $profileRow = $stmt->get_result()->fetch_assoc() ?: [
        'blood_type' => '',
        'civil_status' => '',
        'emergency_contact_name' => '',
        'emergency_contact_number' => '',
    ];
    $stmt->close();

    // Handle the emergency-info form submit (this page posts to itself for
    // this one action, rather than a separate process file, since it's a
    // small gate check rather than a full page of its own).
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_emergency_info') {
        require_csrf('confined-patients.php?patient_id=' . $patientId . '&appointment_id=' . $appointmentId, 'confine_flash');

        $ecName = trim($_POST['emergency_contact_name'] ?? '');
        $ecNumber = trim($_POST['emergency_contact_number'] ?? '');
        $bloodType = trim($_POST['blood_type'] ?? '');
        $civilStatus = trim($_POST['civil_status'] ?? '');

        if ($ecName === '' || $ecNumber === '') {
            $_SESSION['confine_flash'] = [
                "type" => "error",
                "message" => "Emergency contact name and number are required before admission.",
            ];
        } elseif (!preg_match('/^[0-9+\-\s()]{7,20}$/', $ecNumber)) {
            $_SESSION['confine_flash'] = [
                "type" => "error",
                "message" => "Please enter a valid emergency contact number.",
            ];
        } else {
            $stmt = $conn->prepare("
                INSERT INTO patient_profiles
                    (patient_id, blood_type, civil_status, emergency_contact_name, emergency_contact_number)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    blood_type = VALUES(blood_type),
                    civil_status = VALUES(civil_status),
                    emergency_contact_name = VALUES(emergency_contact_name),
                    emergency_contact_number = VALUES(emergency_contact_number)
            ");
            $stmt->bind_param("issss", $patientId, $bloodType, $civilStatus, $ecName, $ecNumber);
            $stmt->execute();
            $stmt->close();

            $profileRow = [
                'blood_type' => $bloodType,
                'civil_status' => $civilStatus,
                'emergency_contact_name' => $ecName,
                'emergency_contact_number' => $ecNumber,
            ];
        }

        header("Location: confined-patients.php?patient_id=" . $patientId . "&appointment_id=" . $appointmentId);
        exit;
    }

    $emergencyInfoComplete = $profileRow['emergency_contact_name'] !== '' && $profileRow['emergency_contact_number'] !== '';
} else {

    // List mode: every ongoing confinement under this doctor.
    $stmt = $conn->prepare(
        "SELECT c.confinement_id, c.patient_id, c.room_location, c.clinical_status, c.date_confined,
                u.first_name, u.last_name
         FROM confinements c
         JOIN users u ON u.user_id = c.patient_id
         WHERE c.attending_doctor_id = ? AND c.discharge_date IS NULL
         ORDER BY c.date_confined DESC, c.confinement_id DESC"
    );
    $stmt->bind_param("i", $doctorId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $confinedPatients[] = $row;
    }
    $stmt->close();

    foreach ($confinedPatients as $cp) {
        if (!empty($cp['room_location']) && !in_array($cp['room_location'], $locations, true)) {
            $locations[] = $cp['room_location'];
        }
    }
    sort($locations);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $admitMode ? 'Admit Patient' : 'Confined Patients'; ?> - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
</head>

<body>

    <div class="app-shell">

        <?php
        $current_page = 'confined-patients';
        include 'includes/sidebar.php';
        ?>

        <main class="main-content">

            <header class="page-header">
                <div>
                    <h1><?php echo $admitMode ? 'Admit Patient' : 'Confined Patients'; ?></h1>
                    <p class="page-subtitle">
                        <?php echo $admitMode
                            ? 'Create a confinement record and assign a room.'
                            : 'Patients currently admitted under your care.'; ?>
                    </p>
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

            <?php if ($admitMode): ?>

                <!-- Patient Information Card -->
                <section class="card patient-info-card">
                    <div class="card-header">
                        <h2>Patient Information</h2>
                    </div>
                    <div class="patient-info-grid">
                        <div class="info-item">
                            <span class="info-label">Name</span>
                            <span class="info-value"><?php echo htmlspecialchars($patient['name']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Patient ID</span>
                            <span class="info-value"><?php echo htmlspecialchars($patient['id']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Age</span>
                            <span class="info-value"><?php echo $patient['age'] !== null ? htmlspecialchars($patient['age']) . ' years' : '—'; ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Gender</span>
                            <span class="info-value"><?php echo htmlspecialchars($patient['gender']); ?></span>
                        </div>
                    </div>
                </section>

                <!-- Emergency Info Gate -->
                <?php if (!$emergencyInfoComplete): ?>
                    <section class="card" style="border-left: 4px solid var(--red); margin-bottom: 20px;">
                        <div class="card-header">
                            <h2>Emergency Contact Required</h2>
                            <p style="margin: 0; font-size: 13.5px; color: var(--text-muted); font-weight: 400;">
                                This patient has no emergency contact on file — required before admission.
                            </p>
                        </div>
                        <form class="consultation-form" method="POST" action="confined-patients.php?patient_id=<?php echo $patientId; ?>&appointment_id=<?php echo $appointmentId; ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="complete_emergency_info">
                            <div class="form-group">
                                <label class="form-label" for="ecName">Emergency Contact Name <span class="required">*</span></label>
                                <input type="text" class="form-input" id="ecName" name="emergency_contact_name" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="ecNumber">Emergency Contact Number <span class="required">*</span></label>
                                <input type="text" class="form-input" id="ecNumber" name="emergency_contact_number" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="bloodType">Blood Type</label>
                                <select class="form-input" id="bloodType" name="blood_type">
                                    <option value="">Select</option>
                                    <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'Unknown'] as $bt): ?>
                                        <option value="<?php echo $bt; ?>"><?php echo $bt; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="civilStatus">Civil Status</label>
                                <select class="form-input" id="civilStatus" name="civil_status">
                                    <option value="">Select</option>
                                    <?php foreach (['Single', 'Married', 'Widowed', 'Divorced', 'Separated'] as $cs): ?>
                                        <option value="<?php echo $cs; ?>"><?php echo $cs; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">Save &amp; Continue to Admission</button>
                        </form>
                    </section>
                <?php endif; ?>

                <!-- Informational note for guest (walk-in) patients — no account action needed here -->
                <?php if ($isGuestAccount): ?>
                    <section class="card" style="margin-bottom: 20px;">
                        <div class="card-header">
                            <h2>Patient Account</h2>
                            <p style="margin: 0; font-size: 13.5px; color: var(--text-muted); font-weight: 400;">
                                This patient doesn't have an online account yet. An account is optional and can be created
                                anytime by the patient themselves at the patient registration page, using this same phone number.
                            </p>
                        </div>
                    </section>
                <?php endif; ?>

                <!-- Admission Form -->
                <?php if ($emergencyInfoComplete): ?>
                    <section class="card">
                        <div class="card-header">
                            <h2>Confinement Details</h2>
                        </div>

                        <form class="consultation-form" method="POST" action="confine-patient-process.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="patient_id" value="<?php echo (int) $patientId; ?>">
                            <input type="hidden" name="appointment_id" value="<?php echo (int) $appointmentId; ?>">

                            <div class="form-group">
                                <label class="form-label" for="roomLocation">Room / Location <span class="required">*</span></label>
                                <input type="text" class="form-input" id="roomLocation" name="room_location" placeholder="e.g. Ward 3, Bed 7" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="clinicalStatus">Initial Status</label>
                                <select class="form-input" id="clinicalStatus" name="clinical_status">
                                    <option value="stable" selected>Stable</option>
                                    <option value="improving">Improving</option>
                                    <option value="critical">Critical</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="admissionNote">Admission Note</label>
                                <textarea class="form-textarea" id="admissionNote" name="admission_note" rows="3" placeholder="Optional - reason for admission, initial observations, etc."></textarea>
                                <span class="form-hint">Saved as the first progress note on this patient's confinement record.</span>
                            </div>

                            <button type="submit" class="btn btn-primary">Admit Patient</button>
                        </form>
                    </section>
                <?php endif; ?>

            <?php else: ?>

                <!-- Confined Patients List -->
                <section class="card">
                    <div class="card-header">
                        <h2>Currently Confined Patients</h2>
                    </div>

                    <div class="toolbar">
                        <div class="toolbar-search">
                            <input type="text" id="confinedSearchInput" placeholder="Search by patient name...">
                        </div>
                        <div class="toolbar-filters">
                            <select id="locationFilter" class="filter-select">
                                <option value="all">All Locations</option>
                                <?php foreach ($locations as $loc): ?>
                                    <option value="<?php echo htmlspecialchars($loc); ?>"><?php echo htmlspecialchars($loc); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="statusFilter" class="filter-select">
                                <option value="all">All Statuses</option>
                                <option value="stable">Stable</option>
                                <option value="improving">Improving</option>
                                <option value="critical">Critical</option>
                            </select>
                            <button type="button" class="btn-refresh" id="refreshBtn" title="Refresh list">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="23 4 23 10 17 10"></polyline>
                                    <polyline points="1 20 1 14 7 14"></polyline>
                                    <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <?php if (count($confinedPatients) === 0): ?>
                        <div class="empty-state">
                            <p><strong>No confined patients right now.</strong></p>
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="confined-table" id="confinedTable">
                                <thead>
                                    <tr>
                                        <th>Patient</th>
                                        <th>Room / Location</th>
                                        <th>Days Confined</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($confinedPatients as $cp):
                                        $fullName = trim($cp['first_name'] . ' ' . $cp['last_name']);
                                        $daysConfined = (new DateTime())->diff(new DateTime($cp['date_confined']))->days;
                                    ?>
                                        <tr class="confined-row"
                                            data-name="<?php echo htmlspecialchars(strtolower($fullName)); ?>"
                                            data-location="<?php echo htmlspecialchars($cp['room_location'] ?? ''); ?>"
                                            data-status="<?php echo htmlspecialchars($cp['clinical_status']); ?>">
                                            <td class="patient-cell">
                                                <span class="patient-avatar"><?php echo htmlspecialchars(strtoupper(substr($cp['first_name'], 0, 1))); ?></span>
                                                <?php echo htmlspecialchars($fullName); ?>
                                            </td>
                                            <td>
                                                <div class="room-info">
                                                    <span class="room-number"><?php echo htmlspecialchars($cp['room_location'] ?: '—'); ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="days-badge"><?php echo (int) $daysConfined; ?> day<?php echo $daysConfined !== 1 ? 's' : ''; ?></span>
                                            </td>
                                            <td>
                                                <span class="status-pill status-<?php echo htmlspecialchars($cp['clinical_status']); ?>">
                                                    <?php echo htmlspecialchars(ucfirst($cp['clinical_status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a class="btn-view-record" href="confinement-record.php?confinement_id=<?php echo (int) $cp['confinement_id']; ?>">View Record</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr id="noResultsRow" hidden>
                                        <td colspan="5" class="no-results">
                                            <p>No patients match your search or filters.</p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

            <?php endif; ?>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <script src="../assets/js/doctor-dashboard.js"></script>
    <?php if (!$admitMode): ?>
        <script src="../assets/js/confined-patients.js"></script>
    <?php endif; ?>
</body>

</html>