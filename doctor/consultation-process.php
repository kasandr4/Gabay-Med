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
require_once '../includes/audit_log.php';

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
// NOTE: this still only ever writes to `prescriptions` — it does not touch
// inventory_medicines.current_stock, and that's intentional. Prescribing
// is a clinical record of what a doctor ordered for one patient;
// inventory_medicines tracks BULK stock for procurement, supplier
// bidding, and reconciliation. Those remain two different concerns, but
// as of 022_link_prescriptions_to_dispense.sql they're now linked by a
// real medicine_id so staff/dispense-stock.php can pull up an exact,
// verifiable match when a patient later presents this prescription —
// see that file's header comment for the dispense side of this.
//
// LINKED TO INVENTORY (2026-09-05): the form now submits medicine_id[]
// (the catalog row the doctor picked from consultation.php's
// medicineOptions), not a free-typed name. The name stored in
// prescriptions.medicine_name is resolved from that id server-side just
// below — never trusted from the client — so the two columns can never
// disagree.
$medicineIdsRaw = $_POST['medicine_id'] ?? [];
$quantities = $_POST['quantity'] ?? [];
$instructions = $_POST['instructions'] ?? [];

$prescriptionRows = [];
if ($outcome === 'prescribed') {
    foreach ($medicineIdsRaw as $i => $rawId) {
        $medicineId = (int) $rawId;
        if ($medicineId <= 0) {
            continue;
        }
        $prescriptionRows[] = [
            "medicine_id" => $medicineId,
            "quantity" => trim($quantities[$i] ?? ''),
            "instructions" => trim($instructions[$i] ?? ''),
        ];
    }

    if (empty($prescriptionRows)) {
        backToConsultation($appointmentId, "error", "Add at least one medicine before issuing a prescription.");
    }

    // Resolve every picked medicine_id to its current catalog name in one
    // query, and reject the whole submission if any id doesn't actually
    // exist (e.g. removed from the catalog between page load and submit).
    // This guarantees prescriptions.medicine_name always matches a real
    // inventory_medicines row at the moment it's saved.
    $idsToResolve = array_unique(array_column($prescriptionRows, 'medicine_id'));
    $placeholders = implode(',', array_fill(0, count($idsToResolve), '?'));
    $types = str_repeat('i', count($idsToResolve));
    $stmt = $conn->prepare("SELECT medicine_id, name FROM inventory_medicines WHERE medicine_id IN ($placeholders)");
    $stmt->bind_param($types, ...$idsToResolve);
    $stmt->execute();
    $resolvedNames = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $resolvedNames[(int) $row['medicine_id']] = $row['name'];
    }
    $stmt->close();

    foreach ($prescriptionRows as $row) {
        if (!isset($resolvedNames[$row['medicine_id']])) {
            backToConsultation($appointmentId, "error", "One of the selected medicines is no longer available in the catalog. Please review the prescription.");
        }
    }
}

// Lab tests are optional and independent of $outcome - a doctor may order
// labs regardless of whether the visit ends in consultation_only,
// prescribed, follow_up, or admitted. Rows with no test selected are
// dropped, same handling as empty medicine rows above.
$labTestNames = $_POST['labTestName'] ?? [];
$labTestNotes = $_POST['labTestNotes'] ?? [];

$labOrderRows = [];
foreach ($labTestNames as $i => $name) {
    $name = trim($name);
    if ($name === '') {
        continue;
    }
    $labOrderRows[] = [
        "test_name" => $name,
        "notes" => trim($labTestNotes[$i] ?? ''),
    ];
}

// Every selected test must exist in the active lab_tests catalog; the
// catalog id is stored alongside the test_name snapshot on lab_orders.
if (!empty($labOrderRows)) {
    $labCatalog = [];
    $labCatalogResult = $conn->query("SELECT lab_test_id, test_name FROM lab_tests WHERE is_active = 1");
    if ($labCatalogResult) {
        while ($labCatalogRow = $labCatalogResult->fetch_assoc()) {
            $labCatalog[$labCatalogRow['test_name']] = (int) $labCatalogRow['lab_test_id'];
        }
    }
    foreach ($labOrderRows as $i => $row) {
        if (!isset($labCatalog[$row['test_name']])) {
            backToConsultation($appointmentId, "error", "One of the selected lab tests is no longer available. Please review the lab tests.");
        }
        $labOrderRows[$i]['lab_test_id'] = $labCatalog[$row['test_name']];
    }
}

// --- Save -------------------------------------------------------------

// outcome posted from the form maps 1:1 to the consultations.outcome enum now
// (consultation_only / prescribed / follow_up / admitted) - no translation needed.
$dbOutcome = $outcome;
$clinicalNotesValue = $clinicalNotes === '' ? null : $clinicalNotes;

$conn->begin_transaction();

try {
    // Atomic claim: only ONE request can flip this appointment out of
    // pending/confirmed. A double-click or a second tab racing this same
    // POST loses here (0 rows affected) instead of inserting a second
    // consultation (and duplicate prescriptions/lab orders) for the same
    // visit - the earlier status check above runs before the transaction
    // and can't stop that on its own.
    $stmt = $conn->prepare("UPDATE appointments SET status = 'completed' WHERE appointment_id = ? AND status IN ('pending', 'confirmed')");
    $stmt->bind_param("i", $appointmentId);
    $stmt->execute();
    $claimed = $stmt->affected_rows;
    $stmt->close();
    if ($claimed !== 1) {
        $conn->rollback();
        backToConsultation(0, "error", "This appointment was already completed.");
    }

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
            "INSERT INTO prescriptions (consultation_id, patient_id, doctor_id, medicine_name, medicine_id, quantity, instructions)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($prescriptionRows as $row) {
            $medicineName = $resolvedNames[$row['medicine_id']];
            $quantityValue = $row['quantity'] === '' ? null : $row['quantity'];
            $instructionsValue = $row['instructions'] === '' ? null : $row['instructions'];
            $stmt->bind_param("iiisiss", $consultationId, $patientId, $doctorId, $medicineName, $row['medicine_id'], $quantityValue, $instructionsValue);
            $stmt->execute();
        }
        $stmt->close();
    }

    if (!empty($labOrderRows)) {
        $stmt = $conn->prepare(
            "INSERT INTO lab_orders (consultation_id, patient_id, doctor_id, lab_test_id, test_name, notes)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        foreach ($labOrderRows as $row) {
            $notesValue = $row['notes'] === '' ? null : $row['notes'];
            $stmt->bind_param("iiiiss", $consultationId, $patientId, $doctorId, $row['lab_test_id'], $row['test_name'], $notesValue);
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

// Tell the patient and lab staff about newly ordered tests. Independent
// of $outcome - ordering labs never implies admission or any other outcome.
if (!empty($labOrderRows)) {
    notify_lab_order_created($conn, $patientId, $doctorId, array_column($labOrderRows, 'test_name'));
}

// Audit trail entry for prescribing activity (see includes/audit_log.php
// and pharmacist/audit-trail.php, which surfaces the 'prescribing' module
// alongside inventory/dispensing/reports - a pharmacist can now see which
// doctor prescribed what, not just who later dispensed it). Best-effort
// and non-blocking, same as every other write_audit_log call in the app:
// a logging failure must never undo an already-committed prescription.
if ($outcome === 'prescribed' && !empty($prescriptionRows)) {
    $rxDetails = array_map(
        static fn(array $row): string => $resolvedNames[$row['medicine_id']] . ($row['quantity'] !== '' ? " ({$row['quantity']})" : ''),
        $prescriptionRows
    );
    write_audit_log($conn, $doctorId, 'doctor', 'prescription_issued', 'prescribing', "Consultation #{$consultationId}: " . implode('; ', $rxDetails), $patientId);
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

// Lab tests are independent of outcome, so this flag/consultation_id gets
// attached to whichever flash block below actually fires, regardless of
// which outcome the doctor picked. print-lab-order.php looks up its rows
// by consultation_id, the same way print-consultation.php already does.
$hasLabOrder = !empty($labOrderRows);
if ($hasLabOrder) {
    $successMessages = array_map(function ($msg) {
        return $msg . " Lab tests ordered.";
    }, $successMessages);
}

if ($outcome === 'admitted') {
    // confined-patients.php reads $_SESSION['confine_flash'], not
    // consultation_flash - using the wrong key here meant this message
    // (and, before this fix, the has_lab_order/consultation_id data) was
    // silently dropped: never shown, and left sitting in the session to
    // leak onto whatever page next happened to read consultation_flash
    // (e.g. dashboard.php, on the doctor's next visit there).
    $_SESSION['confine_flash'] = [
        "type" => "success",
        "message" => $successMessages[$outcome],
        "consultation_id" => $hasLabOrder ? $consultationId : null,
        "has_lab_order" => $hasLabOrder,
    ];
    // This only starts the Confinement module's admission form (room
    // assignment, etc.) - it does not itself confine or discharge anyone.
    // (Was "confine-patient.php", which doesn't exist - the admission form
    // lives at confined-patients.php.)
    header("Location: confined-patients.php?patient_id=" . $patientId . "&appointment_id=" . $appointmentId);
    exit;
}

if ($outcome === 'follow_up') {
    // follow-up.php reads $_SESSION['followup_flash'], not
    // consultation_flash - same mismatch/leak as the 'admitted' branch
    // above, fixed the same way.
    $_SESSION['followup_flash'] = [
        "type" => "success",
        "message" => $successMessages[$outcome] . " Set a date below to finish scheduling it.",
        "consultation_id" => $hasLabOrder ? $consultationId : null,
        "has_lab_order" => $hasLabOrder,
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
    "has_lab_order" => $hasLabOrder,
    // Separate from has_lab_order: dashboard.php uses this to show a
    // "Print Pharmacy Slip" link (print-pharmacy-slip.php) alongside the
    // Visit Summary one, only when there's actually something to hand to
    // the pharmacy counter.
    "has_prescription" => !empty($prescriptionRows),
];
header("Location: dashboard.php");
exit;
