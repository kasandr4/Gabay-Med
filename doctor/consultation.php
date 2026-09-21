<?php
// doctor/consultation.php

require_once '../includes/auth_guard.php';
require_role('doctor');
require_once '../config/db.php';
require_once '../includes/csrf.php';

$doctorFirstName = $_SESSION['first_name'];
$doctorLastName = $_SESSION['last_name'];
$doctorFullName = "Dr. " . $doctorFirstName . " " . $doctorLastName;
$doctorInitial = strtoupper(substr($doctorFirstName, 0, 1));
$doctorId = (int) $_SESSION['user_id'];

$today = date("F j, Y");

// Flash message set by consultation-process.php after a save attempt.
$flash = $_SESSION['consultation_flash'] ?? null;
unset($_SESSION['consultation_flash']);

// This page must be opened for a specific appointment (e.g. from
// Today's Queue via "View Consultation"). Without one there is nothing
// to consult on.
$appointmentId = isset($_GET['appointment_id']) ? (int) $_GET['appointment_id'] : 0;
if ($appointmentId <= 0) {
    $_SESSION['consultation_flash'] = [
        "type" => "error",
        "message" => "No appointment was specified. Select a patient from Today's Queue to begin a consultation.",
    ];
    header("Location: todays-queue.php");
    exit;
}

// Pull the appointment together with patient and department info, scoped
// to the logged-in doctor so one doctor can never consult on another
// doctor's appointment.
$stmt = $conn->prepare(
    "SELECT a.appointment_id, a.patient_id, a.doctor_id, a.status, a.symptom_description,
            a.slot_start, a.slot_end, a.checked_in_at, a.booking_source,
            u.first_name, u.last_name, u.birthdate, u.sex,
            d.department_name
     FROM appointments a
     JOIN users u ON u.user_id = a.patient_id
     JOIN departments d ON d.department_id = a.department_id
     WHERE a.appointment_id = ? AND a.doctor_id = ?
     LIMIT 1"
);
$stmt->bind_param("ii", $appointmentId, $doctorId);
$stmt->execute();
$result = $stmt->get_result();
$appointment = $result->fetch_assoc();
$stmt->close();

if (!$appointment) {
    $_SESSION['consultation_flash'] = [
        "type" => "error",
        "message" => "That appointment could not be found for your account.",
    ];
    header("Location: todays-queue.php");
    exit;
}

// Findings can only be recorded once per appointment. If it was already
// completed, send the doctor back to the queue instead of showing a
// stale form.
if ($appointment['status'] === 'completed' || $appointment['status'] === 'cancelled') {
    $_SESSION['consultation_flash'] = [
        "type" => "error",
        "message" => "This appointment is already " . $appointment['status'] . " and can no longer be consulted on.",
    ];
    header("Location: todays-queue.php");
    exit;
}

// A consultation must never begin before the front desk has checked the
// patient in - this is what actually confirms the patient is present and
// puts them in the doctor's Today's Queue. Enforce it here too so that
// simply knowing/guessing an appointment_id and hitting this URL directly
// can never open the consultation form early.
if ($appointment['checked_in_at'] === null) {
    $_SESSION['consultation_flash'] = [
        "type" => "error",
        "message" => "The patient has not checked in yet. Consultation cannot begin until check-in is completed.",
    ];
    header("Location: todays-queue.php");
    exit;
}

$age = null;
if (!empty($appointment['birthdate'])) {
    $birthDate = new DateTime($appointment['birthdate']);
    $age = $birthDate->diff(new DateTime())->y;
}

$patient = [
    "name" => trim($appointment['first_name'] . " " . $appointment['last_name']),
    "id" => $appointment['patient_id'],
    "age" => $age,
    "gender" => $appointment['sex'] ? ucfirst($appointment['sex']) : "—",
    "department" => $appointment['department_name'],
    "appointmentDate" => date("Y-m-d", strtotime($appointment['slot_start'])),
    "appointmentTime" => date("g:i A", strtotime($appointment['slot_start'])),
    "reasonForVisit" => $appointment['symptom_description'],
];

// Medicine names from the same inventory_medicines table Pharmacy
// manages, so the dropdown offers standardized names instead of
// free-typed variants of the same drug. Previously this was a
// hardcoded sample list with names that didn't even match what
// Pharmacy actually stocks.
//
// LINKED TO INVENTORY (2026-09-05): a prescription now always carries the
// real medicine_id the doctor picked here, not just a name string — that's
// what lets staff/dispense-stock.php pull up an exact, verifiable match
// against inventory_medicines when a patient later presents this
// prescription to be filled. consultation-process.php re-resolves the
// name server-side from this id (never trusts a client-submitted name).
//
// Exact current_stock figures are still not shown (a bulk-stock number
// isn't a meaningful per-prescription comparison), but whether it's
// in stock AT ALL is now surfaced as an "available" flag, so a doctor
// isn't prescribing blind — an out-of-stock item is labeled "Not
// available in pharmacy" in the dropdown but can still be selected
// (the patient may need to source it elsewhere, or wait for restock).
$medicines = [];
$medResult = $conn->query("SELECT medicine_id, name, current_stock FROM inventory_medicines ORDER BY name ASC");
if ($medResult) {
    while ($row = $medResult->fetch_assoc()) {
        $medicines[] = [
            "id" => (int) $row['medicine_id'],
            "name" => $row['name'],
            "available" => ((int) $row['current_stock']) > 0,
        ];
    }
}

// Lab test names for the dropdown, read from the lab_tests catalog
// (see 028_lab_test_catalog.sql) - only active tests are offered. Still a
// plain list of names, so consultation.js's row builder is unchanged.
// Ordering a lab test is optional and independent of whichever outcome the
// doctor picks below, since a doctor may want labs run regardless of
// whether the visit ends in a prescription, follow-up, admission, etc.
$labTestOptions = [];
$labTestResult = $conn->query("SELECT test_name FROM lab_tests WHERE is_active = 1 ORDER BY category ASC, test_name ASC");
if ($labTestResult) {
    while ($labTestRow = $labTestResult->fetch_assoc()) {
        $labTestOptions[] = $labTestRow['test_name'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultation - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
</head>

<body>

    <div class="app-shell">

        <?php
        // Consultation no longer has its own sidebar entry - it's only ever
        // reached by picking a patient from Today's Queue - so keep that
        // nav item highlighted as the parent context instead of a key that
        // no longer exists in $navigation.
        $current_page = 'todays-queue';
        include 'includes/sidebar.php';
        ?>

        <!-- Main content -->
        <main class="main-content">



            <!-- Page header -->
            <header class="page-header">
                <div>
                    <h1>Patient Consultation</h1>
                    <p class="page-subtitle">Complete patient consultation and determine next steps.</p>
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

            <!-- Patient Information Card -->
            <section class="card patient-info-card">
                <div class="card-header">
                    <h2>Patient Information</h2>
                </div>
                <div class="patient-info-grid">
                    <div class="info-item">
                        <span class="info-label">Name</span>
                        <span class="info-value">
                            <?php echo htmlspecialchars($patient['name']); ?>
                            <?php if ($appointment['booking_source'] === 'walk_in'): ?>
                                <span style="display:inline-block; margin-left:8px; padding:2px 8px; font-size:11px; font-weight:600; border-radius:999px; background:var(--teal-light, #e0f2f1); color:var(--teal, #00695c);">Walk-in</span>
                            <?php endif; ?>
                        </span>
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
                    <div class="info-item">
                        <span class="info-label">Department</span>
                        <span class="info-value"><?php echo htmlspecialchars($patient['department']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Appointment</span>
                        <span class="info-value"><?php echo htmlspecialchars($patient['appointmentDate']); ?> at <?php echo htmlspecialchars($patient['appointmentTime']); ?></span>
                    </div>
                </div>
                <div class="patient-reason">
                    <span class="info-label">Reason for Visit</span>
                    <p style="margin: 8px 0 0; color: var(--text-primary); font-size: 14px;">
                        <?php echo htmlspecialchars($patient['reasonForVisit']); ?>
                    </p>
                </div>
            </section>

            <form id="consultationForm" class="consultation-form" method="POST" action="consultation-process.php">
                <?= csrf_field() ?>
                <input type="hidden" name="appointment_id" value="<?php echo (int) $appointment['appointment_id']; ?>">
                <input type="hidden" name="patient_id" value="<?php echo (int) $appointment['patient_id']; ?>">
                <input type="hidden" name="outcome" id="outcomeField" value="">

                <!-- Consultation Form -->
                <section class="card consultation-form-card">
                    <div class="card-header">
                        <h2>Consultation Findings</h2>
                    </div>

                    <!-- Findings -->
                    <div class="form-group">
                        <label for="findings" class="form-label">
                            Findings <span class="required">*</span>
                        </label>
                        <textarea
                            id="findings"
                            name="findings"
                            class="form-textarea"
                            placeholder="Enter clinical findings and observations..."
                            rows="6"
                            required></textarea>
                        <span class="form-hint">Describe patient condition, vital signs, symptoms, and physical examination results.</span>
                    </div>

                    <!-- Clinical Notes -->
                    <div class="form-group">
                        <label for="clinicalNotes" class="form-label">Clinical Notes</label>
                        <textarea
                            id="clinicalNotes"
                            name="clinicalNotes"
                            class="form-textarea"
                            placeholder="Add any additional clinical observations..."
                            rows="4"></textarea>
                        <span class="form-hint">Optional: Add differential diagnosis, patient education, or follow-up recommendations.</span>
                    </div>
                </section>

                <!-- Laboratory Tests (optional, independent of outcome below -
                     a doctor may order labs regardless of whether the visit
                     ends in a prescription, follow-up, admission, etc.) -->
                <section class="card prescription-card">
                    <div class="card-header">
                        <h2>Laboratory Tests</h2>
                        <p style="margin: 0; font-size: 13.5px; color: var(--text-muted); font-weight: 400;">
                            Optional. Order any tests this patient needs to take at the laboratory.
                        </p>
                    </div>

                    <div id="labTestsList" class="medicines-list">
                        <!-- Lab test rows will be added here -->
                    </div>

                    <div class="add-medicine-btn-wrapper">
                        <button type="button" id="addLabTestBtn" class="btn btn-secondary">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="5" x2="12" y2="19"></line>
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                            </svg>
                            Add Lab Test
                        </button>
                    </div>
                </section>

                <!-- Consultation Outcome -->
                <section class="card outcome-card">
                    <div class="card-header">
                        <h2>Consultation Outcome</h2>
                        <p style="margin: 0; font-size: 13.5px; color: var(--text-muted); font-weight: 400;">
                            Select the next step for this patient
                        </p>
                    </div>

                    <div class="outcome-actions">
                        <button
                            type="button"
                            class="action-card"
                            id="consultationOnlyBtn"
                            data-action="consultation_only">
                            <div class="action-icon">
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M9 11l3 3L22 4"></path>
                                    <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                                </svg>
                            </div>
                            <div class="action-content">
                                <h3>Consultation Only</h3>
                                <p>Routine check-up, no medication or follow-up needed.</p>
                            </div>
                        </button>

                        <button
                            type="button"
                            class="action-card"
                            id="dischargeBtn"
                            data-action="prescribed">
                            <div class="action-icon">
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="20 6 9 17 4 12"></polyline>
                                </svg>
                            </div>
                            <div class="action-content">
                                <h3>Prescription Issued</h3>
                                <p>Patient recovers and is prescribed medication.</p>
                            </div>
                        </button>

                        <button
                            type="button"
                            class="action-card"
                            id="followUpBtn"
                            data-action="follow_up">
                            <div class="action-icon">
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <polyline points="12 6 12 12 16 14"></polyline>
                                </svg>
                            </div>
                            <div class="action-content">
                                <h3>Follow-up Recommended</h3>
                                <p>Patient should return for another consultation.</p>
                            </div>
                        </button>

                        <button
                            type="button"
                            class="action-card"
                            id="confineBtn"
                            data-action="admitted">
                            <div class="action-icon">
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M2 4v16"></path>
                                    <path d="M2 8h18a2 2 0 0 1 2 2v10"></path>
                                    <path d="M2 17h20"></path>
                                    <path d="M6 8v9"></path>
                                </svg>
                            </div>
                            <div class="action-content">
                                <h3>Admit Patient</h3>
                                <p>Patient requires hospital confinement.</p>
                            </div>
                        </button>
                    </div>
                </section>

                <!-- Prescription Section (hidden by default) -->
                <section id="prescriptionSection" class="card prescription-card" hidden>
                    <div class="card-header">
                        <h2>Prescription</h2>
                    </div>

                    <div id="medicinesList" class="medicines-list">
                        <!-- Medicine rows will be added here -->
                    </div>

                    <div class="add-medicine-btn-wrapper">
                        <button type="button" id="addMedicineBtn" class="btn btn-secondary">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="5" x2="12" y2="19"></line>
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                            </svg>
                            Add Medicine
                        </button>
                    </div>

                    <div class="form-group" style="margin-top: 24px;">
                        <button type="submit" class="btn btn-primary" id="issuePrescriptionBtn">
                            Issue Prescription
                        </button>
                    </div>
                </section>

                <!-- Confine Patient Confirmation (hidden by default) -->
                <section id="confineConfirmation" class="card confine-confirmation-card" hidden>
                    <div class="confirmation-content">
                        <div class="confirmation-icon">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M2 4v16"></path>
                                <path d="M2 8h18a2 2 0 0 1 2 2v10"></path>
                                <path d="M2 17h20"></path>
                                <path d="M6 8v9"></path>
                            </svg>
                        </div>
                        <h3>Admit Patient</h3>
                        <p>
                            The patient will be admitted to the hospital. You will be redirected to the confinement admission form
                            to complete the details and assign a room/ward.
                        </p>
                    </div>
                    <div class="confirmation-buttons">
                        <button type="submit" class="btn btn-primary" id="confirmConfineBtn">
                            Proceed to Admit Patient
                        </button>
                    </div>
                </section>
            </form>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <!-- Medicine catalog from inventory_medicines: {id, name, available}
         per item. `id` is what actually gets submitted (medicine_id[]) so
         every prescription line links to a real catalog row — staff can
         later look this up and verify it against the printed handout
         before dispensing. `available` only flags whether current_stock
         is above zero (shown as a label, not a number) so a doctor isn't
         prescribing blind, without surfacing a bulk-stock figure that
         isn't a meaningful per-prescription comparison. -->
    <script>
        var medicineOptions = <?php echo json_encode($medicines); ?>;
        // Static reference list (see $labTestOptions in consultation.php) -
        // not DB-backed, since there's no lab test catalog table yet.
        var labTestOptions = <?php echo json_encode($labTestOptions); ?>;
    </script>

    <script src="../assets/js/doctor-dashboard.js"></script>
    <script src="../assets/js/consultation.js"></script>
</body>

</html>