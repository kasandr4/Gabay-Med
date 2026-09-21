<?php
// doctor/appointment-history-ajax.php
// Returns one patient's checkup history as JSON, scoped to ONLY the
// logged-in doctor's own encounters - every query below filters by
// doctor_id (or attending_doctor_id) = the session doctor. This is the
// deliberate difference from doctor/patient-record-ajax.php, which pulls
// a patient's hospital-wide record across every doctor.
//
// Access check: if this doctor has never consulted or confined this
// patient, the request is refused (404) rather than silently returning
// an empty-but-200 record - a doctor probing patient IDs shouldn't be
// able to tell "no history" apart from "not your patient" from the
// response shape, and shouldn't get a 200 for either.

require_once '../includes/auth_guard.php';
require_role('doctor');
require_once '../config/db.php';
require_once '../includes/patient_classification.php';
require_once '../includes/status_labels.php';

header('Content-Type: application/json');

function respond($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

$doctorId = (int) $_SESSION['user_id'];
$patientId = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;
if ($patientId <= 0) {
    respond(["error" => "Missing or invalid patient_id."], 400);
}

$stmt = $conn->prepare(
    "SELECT user_id, first_name, last_name, sex, birthdate, status
     FROM users WHERE user_id = ? AND role = 'patient' LIMIT 1"
);
$stmt->bind_param("i", $patientId);
$stmt->execute();
$patient = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$patient) {
    respond(["error" => "Patient not found."], 404);
}

// Ownership check - this doctor must have actually checked this patient
// up at least once (consultation or confinement) before any history is
// returned.
$stmt = $conn->prepare(
    "SELECT
        (EXISTS (SELECT 1 FROM consultations WHERE doctor_id = ? AND patient_id = ?)) AS has_consult,
        (EXISTS (SELECT 1 FROM confinements WHERE attending_doctor_id = ? AND patient_id = ?)) AS has_confine"
);
$stmt->bind_param("iiii", $doctorId, $patientId, $doctorId, $patientId);
$stmt->execute();
$scope = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$scope['has_consult'] && !$scope['has_confine']) {
    respond(["error" => "You haven't checked up on this patient."], 404);
}

$age = null;
if (!empty($patient['birthdate'])) {
    $age = (new DateTime($patient['birthdate']))->diff(new DateTime())->y;
}

// --- Visit timeline: every consultation THIS doctor had with this
// patient, most recent first. Prescriptions and lab orders are nested
// under the consultation they came from. ---------------------------------
$visits = [];
$stmt = $conn->prepare(
    "SELECT consultation_id, findings, clinical_notes, outcome, created_at
     FROM consultations
     WHERE doctor_id = ? AND patient_id = ?
     ORDER BY created_at DESC"
);
$stmt->bind_param("ii", $doctorId, $patientId);
$stmt->execute();
$consultRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($consultRows as $c) {
    $consultationId = (int) $c['consultation_id'];

    $prescriptions = [];
    $stmt = $conn->prepare(
        "SELECT medicine_name, quantity, instructions, status, created_at
         FROM prescriptions
         WHERE consultation_id = ? AND doctor_id = ?
         ORDER BY created_at ASC"
    );
    $stmt->bind_param("ii", $consultationId, $doctorId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $prescriptions[] = [
            "medicine" => $row['medicine_name'],
            "dosage" => $row['quantity'],
            "instructions" => $row['instructions'],
            "status" => ucfirst($row['status']),
            "date_issued" => date("M j, Y", strtotime($row['created_at'])),
        ];
    }
    $stmt->close();

    $labOrders = [];
    $stmt = $conn->prepare(
        "SELECT test_name, notes, status, created_at
         FROM lab_orders
         WHERE consultation_id = ? AND doctor_id = ?
         ORDER BY created_at ASC"
    );
    $stmt->bind_param("ii", $consultationId, $doctorId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $labOrders[] = [
            "test_name" => $row['test_name'],
            "notes" => $row['notes'],
            "status" => (get_lab_status_labels()[$row['status']] ?? ucfirst($row['status'])),
            "date" => date("M j, Y", strtotime($row['created_at'])),
        ];
    }
    $stmt->close();

    $visits[] = [
        "date" => date("M j, Y \a\t g:i A", strtotime($c['created_at'])),
        "diagnosis" => $c['findings'],
        "clinical_notes" => $c['clinical_notes'],
        "outcome" => ucfirst(str_replace('_', ' ', $c['outcome'])),
        "prescriptions" => $prescriptions,
        "lab_orders" => $labOrders,
    ];
}

// --- Confinements THIS doctor attended for this patient, each with its
// own lab orders (lab_orders can attach to a confinement instead of a
// consultation) and its confinement_notes timeline. ----------------------
$confinements = [];
$stmt = $conn->prepare(
    "SELECT confinement_id, date_confined, discharge_date, room_location, clinical_status, discharge_status,
            final_diagnosis, discharge_medications, follow_up_date, follow_up_instructions
     FROM confinements
     WHERE attending_doctor_id = ? AND patient_id = ?
     ORDER BY date_confined DESC"
);
$stmt->bind_param("ii", $doctorId, $patientId);
$stmt->execute();
$confineRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($confineRows as $cf) {
    $confinementId = (int) $cf['confinement_id'];

    // Itemized discharge medications (see migration
    // 023_confinement_discharge_medications.sql) - separate from
    // discharge_medications, which is free-text for anything not tied to
    // one specific medicine (e.g. "reduce dosage gradually").
    $dischargeMeds = [];
    $stmt = $conn->prepare(
        "SELECT medicine_name, quantity, instructions, status
         FROM confinement_discharge_medications
         WHERE confinement_id = ?
         ORDER BY discharge_medication_id ASC"
    );
    $stmt->bind_param("i", $confinementId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $dischargeMeds[] = [
            "medicine" => $row['medicine_name'],
            "dosage" => $row['quantity'],
            "instructions" => $row['instructions'],
            "status" => ucfirst($row['status']),
        ];
    }
    $stmt->close();

    $labOrders = [];
    $stmt = $conn->prepare(
        "SELECT test_name, notes, status, created_at
         FROM lab_orders
         WHERE confinement_id = ? AND doctor_id = ?
         ORDER BY created_at ASC"
    );
    $stmt->bind_param("ii", $confinementId, $doctorId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $labOrders[] = [
            "test_name" => $row['test_name'],
            "notes" => $row['notes'],
            "status" => (get_lab_status_labels()[$row['status']] ?? ucfirst($row['status'])),
            "date" => date("M j, Y", strtotime($row['created_at'])),
        ];
    }
    $stmt->close();

    $notes = [];
    $stmt = $conn->prepare(
        "SELECT note, created_at FROM confinement_notes
         WHERE confinement_id = ? ORDER BY created_at ASC"
    );
    $stmt->bind_param("i", $confinementId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $notes[] = [
            "text" => $row['note'],
            "time" => date("M j, Y — g:i A", strtotime($row['created_at'])),
        ];
    }
    $stmt->close();

    $confinements[] = [
        "date_confined" => date("M j, Y", strtotime($cf['date_confined'])),
        "date_discharged" => $cf['discharge_date'] ? date("M j, Y", strtotime($cf['discharge_date'])) : null,
        "room" => $cf['room_location'] ?: '—',
        "clinical_status" => $cf['clinical_status'] ? ucfirst($cf['clinical_status']) : null,
        "discharge_status" => $cf['discharge_status'] ? ucfirst($cf['discharge_status']) : "Ongoing",
        "final_diagnosis" => $cf['final_diagnosis'] ?: null,
        "discharge_medications_notes" => $cf['discharge_medications'] ?: null,
        "discharge_medications" => $dischargeMeds,
        "follow_up_date" => $cf['follow_up_date'] ? date("M j, Y", strtotime($cf['follow_up_date'])) : null,
        "follow_up_instructions" => $cf['follow_up_instructions'] ?: null,
        "lab_orders" => $labOrders,
        "notes" => $notes,
    ];
}

respond([
    "basic" => [
        "full_name" => trim($patient['first_name'] . ' ' . $patient['last_name']),
        "patient_id" => (int) $patient['user_id'],
        "gender" => $patient['sex'] ? ucfirst($patient['sex']) : null,
        "age" => $age,
        "status" => $patient['status'],
        "classification" => patient_classification($patient['status']),
    ],
    "visits" => $visits,
    "confinements" => $confinements,
]);
