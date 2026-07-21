<?php
// doctor/consultation-process.php
// Handles the POST from consultation.php: saves the consultation record,
// saves prescriptions (if discharging), updates the appointment status,
// then redirects back to the doctor.

require_once '../includes/auth_guard.php';
require_role('doctor');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/notifications.php';

function backToConsultation($appointmentId, $type, $message)
{
    $_SESSION['consultation_flash'] = ["type" => $type, "message" => $message];
    $target = $appointmentId > 0
        ? "consultation.php?appointment_id=" . (int) $appointmentId
        : "todays-queue.php";
    header("Location: " . $target);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: todays-queue.php");
    exit;
}

require_csrf('todays-queue.php', 'consultation_flash');

$doctorId = (int) $_SESSION['user_id'];
$appointmentId = isset($_POST['appointment_id']) ? (int) $_POST['appointment_id'] : 0;
$patientId = isset($_POST['patient_id']) ? (int) $_POST['patient_id'] : 0;
$outcome = $_POST['outcome'] ?? '';
$findings = trim($_POST['findings'] ?? '');
$clinicalNotes = trim($_POST['clinicalNotes'] ?? '');

// --- Validation -----------------------------------------------------

if ($appointmentId <= 0 || $patientId <= 0) {
    backToConsultation(0, "error", "Missing appointment information. Please try again from Today's Queue.");
}

if ($findings === '') {
    backToConsultation($appointmentId, "error", "Consultation findings are required before saving.");
}

// These are the ONLY 4 outcomes a consultation can have. Note that none of
// them discharge a confined patient or touch the `confinements` table -
// that is exclusively the Confinement module's job, and only after the
// doctor has clicked "Discharge Patient" there and picked one of
// Recovered/Transferred/DAMA/Deceased.
$validOutcomes = ['consultation_only', 'prescribed', 'follow_up', 'admitted'];
if (!in_array($outcome, $validOutcomes, true)) {
    backToConsultation($appointmentId, "error", "Please choose an outcome for this consultation.");
}

// Re-check the appointment belongs to this doctor and is still open.
$stmt = $conn->prepare(
    "SELECT appointment_id, status, slot_start, checked_in_at FROM appointments WHERE appointment_id = ? AND doctor_id = ? AND patient_id = ? LIMIT 1"
);
$stmt->bind_param("iii", $appointmentId, $doctorId, $patientId);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appointment) {
    backToConsultation(0, "error", "That appointment could not be found for your account.");
}

if (in_array($appointment['status'], ['completed', 'cancelled'], true)) {
    backToConsultation(0, "error", "This appointment is already " . $appointment['status'] . " and can no longer be consulted on.");
}

// Never persist a consultation for a patient who hasn't been checked in
// by the front desk, even if the doctor posts here directly (bypassing
// the UI/consultation.php entirely). This is the authoritative check -
// the one in consultation.php only protects the form view.
if ($appointment['checked_in_at'] === null) {
    backToConsultation(0, "error", "The patient has not checked in yet. Consultation cannot begin until check-in is completed.");
}

// Collect prescription rows (only relevant when a prescription is issued).
// NOTE: this only ever writes to `prescriptions` — it does not touch
// inventory_medicines.current_stock. Prescribing and Inventory are still
// two separate records of the same event; consultation.php now at least
// shows the doctor real stock levels from inventory_medicines while
// they're prescribing (see medicineOptions there), but nothing here
// deducts against it. TODO(backend): decide whether stock should be
// deducted at issue time (here) or at actual dispensing time in Pharmacy
// (more clinically accurate, but requires a real dispensing workflow
// that doesn't exist yet either).
$medicineNames = $_POST['medicine_name'] ?? [];
$quantities = $_POST['quantity'] ?? [];
$instructions = $_POST['instructions'] ?? [];

$prescriptionRows = [];
if ($outcome === 'prescribed') {
    foreach ($medicineNames as $i => $name) {
        $name = trim($name);
        if ($name === '') {
            continue;
        }
        $prescriptionRows[] = [
            "medicine_name" => $name,
            "quantity" => trim($quantities[$i] ?? ''),
            "instructions" => trim($instructions[$i] ?? ''),
        ];
    }

    if (empty($prescriptionRows)) {
        backToConsultation($appointmentId, "error", "Add at least one medicine before issuing a prescription.");
    }
}

// --- Save -------------------------------------------------------------

// outcome posted from the form maps 1:1 to the consultations.outcome enum now
// (consultation_only / prescribed / follow_up / admitted) - no translation needed.
$dbOutcome = $outcome;
$clinicalNotesValue = $clinicalNotes === '' ? null : $clinicalNotes;

$conn->begin_transaction();

try {
    $stmt = $conn->prepare(
        "INSERT INTO consultations (appointment_id, patient_id, doctor_id, findings, clinical_notes, outcome)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("iiisss", $appointmentId, $patientId, $doctorId, $findings, $clinicalNotesValue, $dbOutcome);
    $stmt->execute();
    $consultationId = $stmt->insert_id;
    $stmt->close();

    if ($outcome === 'prescribed') {
        $stmt = $conn->prepare(
            "INSERT INTO prescriptions (consultation_id, patient_id, doctor_id, medicine_name, quantity, instructions)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        foreach ($prescriptionRows as $row) {
            $quantityValue = $row['quantity'] === '' ? null : $row['quantity'];
            $instructionsValue = $row['instructions'] === '' ? null : $row['instructions'];
            $stmt->bind_param("iiisss", $consultationId, $patientId, $doctorId, $row['medicine_name'], $quantityValue, $instructionsValue);
            $stmt->execute();
        }
        $stmt->close();
    }

    // The appointment/visit itself is always over once a consultation is
    // saved, regardless of outcome - including 'admitted'. Admitting a
    // patient does NOT create a confinement record here; that happens in
    // the Confinement module, which is a separate step the doctor takes
    // next. This file must never write to `confinements` or `users.status`.
    $stmt = $conn->prepare("UPDATE appointments SET status = 'completed' WHERE appointment_id = ?");
    $stmt->bind_param("i", $appointmentId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    backToConsultation($appointmentId, "error", "Something went wrong while saving the consultation. Please try again.");
}

// Notify the patient of the outcome. Skipped for 'admitted' - that
// notification fires from confine-patient-process.php instead, once the
// confinement record actually exists, so we don't tell a patient they've
// been admitted before the room assignment step is even done.
if ($outcome !== 'admitted') {
    $stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $doctorId);
    $stmt->execute();
    $doctorRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $doctorName = $doctorRow ? "Dr. " . trim($doctorRow['first_name'] . ' ' . $doctorRow['last_name']) : "your doctor";

    $notificationMessages = [
        "consultation_only" => "Your consultation with {$doctorName} is complete.",
        "prescribed"        => "Your consultation with {$doctorName} is complete and a new prescription has been issued.",
        "follow_up"         => "Your consultation with {$doctorName} is complete. A follow-up visit will be scheduled soon.",
    ];
    $notificationLinks = [
        "consultation_only" => "appointment-history.php",
        "prescribed"        => "prescriptions.php",
        "follow_up"         => "appointment-history.php",
    ];

    create_notification($conn, $patientId, $notificationMessages[$outcome], $notificationLinks[$outcome]);
}

// --- Redirect -----------------------------------------------------------

$successMessages = [
    "consultation_only" => "Consultation saved.",
    "prescribed"        => "Consultation saved and prescription issued.",
    "follow_up"         => "Consultation saved. Follow-up recommended.",
    "admitted"          => "Consultation saved. Continue below to admit the patient to confinement.",
];

if ($outcome === 'admitted') {
    $_SESSION['consultation_flash'] = [
        "type" => "success",
        "message" => $successMessages[$outcome],
    ];
    // This only starts the Confinement module's admission form (room
    // assignment, etc.) - it does not itself confine or discharge anyone.
    // (Was "confine-patient.php", which doesn't exist - the admission form
    // lives at confined-patients.php.)
    header("Location: confined-patients.php?patient_id=" . $patientId . "&appointment_id=" . $appointmentId);
    exit;
}

if ($outcome === 'follow_up') {
    $_SESSION['consultation_flash'] = [
        "type" => "success",
        "message" => $successMessages[$outcome] . " Set a date below to finish scheduling it.",
    ];
    // Look up the patient's name to prefill the Schedule Follow-Up form -
    // this redirect only pre-fills fields, it does not itself create a
    // follow_ups row. The doctor still confirms date/time/reason on
    // follow-up.php and follow-up-process.php does the actual insert.
    $stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $patientId);
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $patientName = $patient ? trim($patient['first_name'] . ' ' . $patient['last_name']) : '';
    $originalVisitDate = $appointment['slot_start'] ? date('Y-m-d', strtotime($appointment['slot_start'])) : '';

    header("Location: follow-up.php?prefill=1"
        . "&appointment_id=" . $appointmentId
        . "&patient_name=" . urlencode($patientName)
        . "&original_visit=" . urlencode($originalVisitDate));
    exit;
}

$_SESSION['consultation_flash'] = [
    "type" => "success",
    "message" => $successMessages[$outcome],
    // dashboard.php can check for this to show a "Print Summary" link -
    // see doctor/print-consultation.php?consultation_id=... The patient
    // may decline account activation, so a printed handout is the
    // universal fallback regardless of that choice.
    "consultation_id" => $consultationId,
];
header("Location: dashboard.php");
exit;
