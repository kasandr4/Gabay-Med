<?php
// doctor/confinement-lab-order-process.php
// Handles the "Order Lab Test" form on confinement-record.php. Mirrors
// consultation-process.php's lab order handling (same labTestName[]/
// labTestNotes[] field names, same static $labTestOptions list in
// confinement-record.php), but inserts with confinement_id set and
// consultation_id left NULL - see 022_confinement_orders_and_discharge.sql
// for why lab_orders now allows either.
//
// Only valid while the confinement is still ongoing, same rule as
// confinement-note-process.php.

require_once '../includes/auth_guard.php';
require_role('doctor');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/notifications.php';

function backToRecord($confinementId, $type, $message, $extra = [])
{
    $_SESSION['confinement_record_flash'] = array_merge(
        ["type" => $type, "message" => $message],
        $extra
    );
    header("Location: confinement-record.php?confinement_id=" . (int) $confinementId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: confined-patients.php");
    exit;
}

require_csrf('confined-patients.php', 'confinement_record_flash');

$doctorId = (int) $_SESSION['user_id'];
$confinementId = isset($_POST['confinement_id']) ? (int) $_POST['confinement_id'] : 0;

if ($confinementId <= 0) {
    header("Location: confined-patients.php");
    exit;
}

// Ownership + ongoing check, same pattern as confinement-note-process.php.
$stmt = $conn->prepare(
    "SELECT confinement_id, patient_id FROM confinements
     WHERE confinement_id = ? AND attending_doctor_id = ? AND discharge_date IS NULL LIMIT 1"
);
$stmt->bind_param("ii", $confinementId, $doctorId);
$stmt->execute();
$confinement = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$confinement) {
    backToRecord($confinementId, "error", "This confinement record is not available to update (it may already be discharged).");
}

$patientId = (int) $confinement['patient_id'];

// Same optional-multi-row handling as consultation-process.php: rows with
// no test selected are dropped.
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
            backToRecord($confinementId, "error", "One of the selected lab tests is no longer available. Please review the lab tests.");
        }
        $labOrderRows[$i]['lab_test_id'] = $labCatalog[$row['test_name']];
    }
}

if (empty($labOrderRows)) {
    backToRecord($confinementId, "error", "Select at least one test before ordering.");
}

$insertedLabOrderIds = [];
$stmt = $conn->prepare(
    "INSERT INTO lab_orders (confinement_id, patient_id, doctor_id, lab_test_id, test_name, notes)
     VALUES (?, ?, ?, ?, ?, ?)"
);
foreach ($labOrderRows as $row) {
    $notesValue = $row['notes'] === '' ? null : $row['notes'];
    $stmt->bind_param("iiiiss", $confinementId, $patientId, $doctorId, $row['lab_test_id'], $row['test_name'], $notesValue);
    $stmt->execute();
    // insert_id is a connection property, not a statement one - read it
    // right after each execute(), before the next loop iteration
    // overwrites it, so each row's real ID is captured correctly.
    $insertedLabOrderIds[] = $conn->insert_id;
}
$stmt->close();

notify_lab_order_created($conn, $patientId, $doctorId, array_column($labOrderRows, 'test_name'));

// Passed through so the "Print Lab Order" link on confinement-record.php
// prints exactly the tests just ordered in THIS submission, not every
// test ever ordered across the whole stay - a confinement can span many
// separate lab-order submissions over several days, unlike a consultation
// which is naturally one visit = one submission.
backToRecord($confinementId, "success", "Lab tests ordered.", [
    "has_lab_order" => true,
    "confinement_id" => $confinementId,
    "lab_order_ids" => implode(',', $insertedLabOrderIds),
]);
