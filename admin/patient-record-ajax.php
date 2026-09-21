<?php
// admin/patient-record-ajax.php
// Returns one patient's full record as JSON, for the Hospital Census
// "All Patients" tab's record modal. Read-only, admin-only. Identical
// data shape/logic to doctor/patient-record-ajax.php (see that file's
// header comment for the schema-gap notes on Diagnosis/Medical
// Conditions) - kept as its own file rather than shared across portals
// to match this app's existing per-portal action/endpoint convention
// (e.g. admin/doctor-schedule-actions.php vs doctor/my-schedule-actions.php).

require_once '../includes/auth_guard.php';
require_role('admin');
require_once '../config/db.php';
require_once '../includes/patient_classification.php';

header('Content-Type: application/json');

function respond($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

$patientId = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;
if ($patientId <= 0) {
    respond(["error" => "Missing or invalid patient_id."], 400);
}

$stmt = $conn->prepare(
    "SELECT u.user_id, u.first_name, u.last_name, u.sex, u.birthdate, u.phone_number,
            u.address, u.philhealth_id, u.status, u.account_status,
            pp.blood_type, pp.civil_status, pp.emergency_contact_name, pp.emergency_contact_number,
            pp.allergies, pp.current_medications
     FROM users u
     LEFT JOIN patient_profiles pp ON pp.patient_id = u.user_id
     WHERE u.user_id = ? AND u.role = 'patient' LIMIT 1"
);
$stmt->bind_param("i", $patientId);
$stmt->execute();
$patient = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$patient) {
    respond(["error" => "Patient not found."], 404);
}

$age = null;
if (!empty($patient['birthdate'])) {
    $age = (new DateTime($patient['birthdate']))->diff(new DateTime())->y;
}

// --- Appointment history, with diagnosis pulled from the matching
// consultation (if the visit reached that stage) -------------------------
$appointments = [];
$stmt = $conn->prepare(
    "SELECT a.slot_start, a.status, a.booking_source, d.department_name,
            u.first_name AS doctor_first, u.last_name AS doctor_last,
            c.findings AS diagnosis
     FROM appointments a
     JOIN departments d ON d.department_id = a.department_id
     JOIN users u ON u.user_id = a.doctor_id
     LEFT JOIN consultations c ON c.consultation_id = (
         SELECT c2.consultation_id FROM consultations c2
         WHERE c2.appointment_id = a.appointment_id
         ORDER BY c2.created_at DESC, c2.consultation_id DESC
         LIMIT 1
     )
     WHERE a.patient_id = ?
     ORDER BY a.slot_start DESC"
);
$stmt->bind_param("i", $patientId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $appointments[] = [
        "date" => date("M j, Y \a\t g:i A", strtotime($row['slot_start'])),
        "department" => $row['department_name'],
        "doctor" => "Dr. " . trim($row['doctor_first'] . ' ' . $row['doctor_last']),
        "status" => ucfirst($row['status']),
        "diagnosis" => $row['diagnosis'], // null if no consultation yet
        "is_walk_in" => $row['booking_source'] === 'walk_in',
    ];
}
$stmt->close();

// --- Confinement history --------------------------------------------------
$confinements = [];
$stmt = $conn->prepare(
    "SELECT date_confined, discharge_date, room_location, discharge_status
     FROM confinements WHERE patient_id = ? ORDER BY date_confined DESC"
);
$stmt->bind_param("i", $patientId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $confinements[] = [
        "date_confined" => date("M j, Y", strtotime($row['date_confined'])),
        "date_discharged" => $row['discharge_date'] ? date("M j, Y", strtotime($row['discharge_date'])) : null,
        "room" => $row['room_location'],
        "discharge_status" => $row['discharge_status'] ? ucfirst($row['discharge_status']) : "Ongoing",
    ];
}
$stmt->close();

// --- Prescription history -------------------------------------------------
$prescriptions = [];
$stmt = $conn->prepare(
    "SELECT medicine_name, quantity, instructions, created_at
     FROM prescriptions WHERE patient_id = ? ORDER BY created_at DESC"
);
$stmt->bind_param("i", $patientId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $prescriptions[] = [
        "medicine" => $row['medicine_name'],
        "dosage" => $row['quantity'],
        "instructions" => $row['instructions'],
        "date_issued" => date("M j, Y", strtotime($row['created_at'])),
    ];
}
$stmt->close();

// Build a single display-ready string for the modal (the frontend just
// prints this value directly — it doesn't expect a nested object).
$ecName   = $patient['emergency_contact_name'] ?: '';
$ecNumber = $patient['emergency_contact_number'] ?: '';
if ($ecName !== '' && $ecNumber !== '') {
    $emergencyContact = "$ecName — $ecNumber";
} elseif ($ecName !== '') {
    $emergencyContact = $ecName;
} elseif ($ecNumber !== '') {
    $emergencyContact = $ecNumber;
} else {
    $emergencyContact = null;
}

respond([
    "basic" => [
        "full_name" => trim($patient['first_name'] . ' ' . $patient['last_name']),
        "patient_id" => (int) $patient['user_id'],
        "gender" => $patient['sex'] ? ucfirst($patient['sex']) : null,
        "birthdate" => $patient['birthdate'] ? date("M j, Y", strtotime($patient['birthdate'])) : null,
        "age" => $age,
        "contact_number" => $patient['phone_number'],
        "address" => $patient['address'],
        "blood_type" => $patient['blood_type'] ?: null,
        "civil_status" => $patient['civil_status'] ?: null,
        "status" => $patient['status'],
        "classification" => patient_classification($patient['status']),
        "account_status" => $patient['account_status'], // 'guest' = no login set up yet (e.g. walk-in never activated)
    ],
    "medical" => [
        "allergies" => $patient['allergies'] ?: null,
        "conditions" => null,          // not tracked in the current schema
        "medications" => $patient['current_medications'] ?: null,
        "emergency_contact" => $emergencyContact,
    ],
    "appointments" => $appointments,
    "confinements" => $confinements,
    "prescriptions" => $prescriptions,
]);
