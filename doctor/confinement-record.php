<?php
// doctor/confinement-record.php
// The Confinement module's detail page. This is the ONLY page that can
// discharge a patient - it updates confinements.discharge_date,
// confinements.discharge_status, and users.status, and nowhere else does.

require_once '../includes/auth_guard.php';
require_role('doctor');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/status_labels.php';

$doctorFirstName = $_SESSION['first_name'];
$doctorLastName  = $_SESSION['last_name'];
$doctorFullName  = "Dr. " . $doctorFirstName . " " . $doctorLastName;
$doctorInitial   = strtoupper(substr($doctorFirstName, 0, 1));
$doctorId        = (int) $_SESSION['user_id'];

$today = date("F j, Y");

$flash = $_SESSION['confinement_record_flash'] ?? null;
unset($_SESSION['confinement_record_flash']);

$confinementId = isset($_GET['confinement_id']) ? (int) $_GET['confinement_id'] : 0;
if ($confinementId <= 0) {
    $_SESSION['confine_flash'] = ["type" => "error", "message" => "No confinement record was specified."];
    header("Location: confined-patients.php");
    exit;
}

// Scoped to the attending doctor, same as confined-patients.php - one
// doctor can't open another doctor's patient's confinement record.
$stmt = $conn->prepare(
    "SELECT c.confinement_id, c.patient_id, c.attending_doctor_id, c.date_confined,
            c.room_location, c.clinical_status, c.discharge_date, c.discharge_status,
            u.first_name, u.last_name, u.birthdate, u.sex
     FROM confinements c
     JOIN users u ON u.user_id = c.patient_id
     WHERE c.confinement_id = ? AND c.attending_doctor_id = ?
     LIMIT 1"
);
$stmt->bind_param("ii", $confinementId, $doctorId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    $_SESSION['confine_flash'] = ["type" => "error", "message" => "That confinement record could not be found for your account."];
    header("Location: confined-patients.php");
    exit;
}

$isOngoing = $row['discharge_date'] === null;

$age = null;
if (!empty($row['birthdate'])) {
    $age = (new DateTime($row['birthdate']))->diff(new DateTime())->y;
}

$dateConfined = new DateTime($row['date_confined']);
$endDate = $isOngoing ? new DateTime() : new DateTime($row['discharge_date']);
$daysConfined = $endDate->diff($dateConfined)->days;

$patient = [
    "name"   => trim($row['first_name'] . ' ' . $row['last_name']),
    "id"     => $row['patient_id'],
    "age"    => $age,
    "gender" => $row['sex'] ? ucfirst($row['sex']) : "—",
];

$statusLabels = ["stable" => "Stable", "improving" => "Improving", "critical" => "Critical"];
$dischargeLabels = ["recovered" => "Recovered / Healthy", "transferred" => "Transferred", "dama" => "Discharged Against Medical Advice (DAMA)", "deceased" => "Deceased"];
$orderTypeLabels = ["diet" => "Diet", "activity" => "Activity Level", "iv_fluids" => "IV Fluids", "medication" => "Medication"];

// Lab test names from the lab_tests catalog - same source and shape as
// consultation.php's $labTestOptions (see 028_lab_test_catalog.sql).
$labTestOptions = [];
$labTestResult = $conn->query("SELECT test_name FROM lab_tests WHERE is_active = 1 ORDER BY category ASC, test_name ASC");
if ($labTestResult) {
    while ($labTestRow = $labTestResult->fetch_assoc()) {
        $labTestOptions[] = $labTestRow['test_name'];
    }
}

// Medicine catalog for the "Medications to Continue" picker on the
// discharge form below - same {id, name, available} shape and same
// reasoning as consultation.php's $medicines (see that file's header
// comment): a discharge medication now links to a real inventory_medicines
// row via medicine_id, which is what lets staff/dispense-stock.php pull
// up an exact match against a confinement's reference number (see
// 023_confinement_discharge_medications.sql).
$medicines = [];
$medResult = $conn->query("SELECT medicine_id, name, current_stock FROM inventory_medicines ORDER BY name ASC");
if ($medResult) {
    while ($medRow = $medResult->fetch_assoc()) {
        $medicines[] = [
            "id" => (int) $medRow['medicine_id'],
            "name" => $medRow['name'],
            "available" => ((int) $medRow['current_stock']) > 0,
        ];
    }
}

// Progress notes, most recent first.
$notes = [];
$stmt = $conn->prepare(
    "SELECT n.note, n.created_at, u.first_name, u.last_name
     FROM confinement_notes n
     JOIN users u ON u.user_id = n.doctor_id
     WHERE n.confinement_id = ?
     ORDER BY n.created_at DESC"
);
$stmt->bind_param("i", $confinementId);
$stmt->execute();
$result = $stmt->get_result();
while ($noteRow = $result->fetch_assoc()) {
    $notes[] = $noteRow;
}
$stmt->close();

// Lab orders placed during THIS confinement, most recent first. See
// 022_confinement_orders_and_discharge.sql - a lab order can belong to a
// confinement now, not just a consultation.
$labOrders = [];
$stmt = $conn->prepare(
    "SELECT lab_order_id, test_name, notes, status, created_at
     FROM lab_orders
     WHERE confinement_id = ?
     ORDER BY created_at DESC, lab_order_id DESC"
);
$stmt->bind_param("i", $confinementId);
$stmt->execute();
$result = $stmt->get_result();
while ($labRow = $result->fetch_assoc()) {
    $labOrders[] = $labRow;
}
$stmt->close();

// Itemized discharge medications (see 023_confinement_discharge_medications.sql)
// - only populated once discharge has actually happened, same timing as
// discharge_medications/final_diagnosis/etc above.
$dischargeMedicationRows = [];
$stmt = $conn->prepare(
    "SELECT medicine_name, quantity, instructions, status
     FROM confinement_discharge_medications
     WHERE confinement_id = ?
     ORDER BY discharge_medication_id ASC"
);
$stmt->bind_param("i", $confinementId);
$stmt->execute();
$result = $stmt->get_result();
while ($medRow2 = $result->fetch_assoc()) {
    $dischargeMedicationRows[] = $medRow2;
}
$stmt->close();
// most recent first - active and discontinued both shown, same
// full-history pattern as Progress Notes below.
$confinementOrders = [];
$stmt = $conn->prepare(
    "SELECT order_id, order_type, description, status, created_at, discontinued_at
     FROM confinement_orders
     WHERE confinement_id = ?
     ORDER BY created_at DESC"
);
$stmt->bind_param("i", $confinementId);
$stmt->execute();
$result = $stmt->get_result();
while ($orderRow = $result->fetch_assoc()) {
    $confinementOrders[] = $orderRow;
}
$stmt->close();

// Referral log entries, most recent first. See 023_confinement_referrals.sql
// - a simple log, not a tracked workflow.
$referrals = [];
$stmt = $conn->prepare(
    "SELECT referral_id, referred_to, reason, created_at
     FROM confinement_referrals
     WHERE confinement_id = ?
     ORDER BY created_at DESC"
);
$stmt->bind_param("i", $confinementId);
$stmt->execute();
$result = $stmt->get_result();
while ($referralRow = $result->fetch_assoc()) {
    $referrals[] = $referralRow;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confinement Record - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <style>
        /* Compact per-row action button for the Lab Orders table — this
           page doesn't load a .btn-sm variant, so a minimal scoped style
           (matching .btn-secondary's look, just smaller) is simpler than
           pulling in an unrelated stylesheet. */
        .btn-table-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            background: var(--surface);
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            text-decoration: none;
            white-space: nowrap;
            transition: background 0.15s ease, color 0.15s ease;
        }

        .btn-table-link:hover {
            background: var(--teal);
            border-color: var(--teal);
            color: #ffffff;
        }
    </style>
</head>

<body>

    <div class="app-shell">

        <?php
        $current_page = 'confined-patients';
        include 'includes/sidebar.php';
        ?>

        <main class="main-content">

            <nav class="breadcrumb">
                <ol>
                    <li><a href="confined-patients.php">Confined Patients</a></li>
                    <li><span>Confinement Record</span></li>
                </ol>
            </nav>

            <header class="page-header">
                <div>
                    <h1>Confinement Record</h1>
                    <p class="page-subtitle">Monitor progress and manage discharge for this patient.</p>
                </div>
                <div class="header-actions">
                    <span class="header-date"><?php echo htmlspecialchars($today); ?></span>
                </div>
            </header>

            <?php if ($flash): ?>
                <section class="card" style="border-left: 4px solid <?php echo $flash['type'] === 'error' ? 'var(--red)' : 'var(--teal)'; ?>; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
                    <p style="margin: 0; font-size: 14px; color: <?php echo $flash['type'] === 'error' ? 'var(--red)' : 'var(--text-primary)'; ?>;">
                        <?php echo htmlspecialchars($flash['message']); ?>
                    </p>
                    <?php if (!empty($flash['has_lab_order']) && !empty($flash['confinement_id'])): ?>
                        <a href="print-lab-order.php?confinement_id=<?php echo (int) $flash['confinement_id']; ?>&ids=<?php echo urlencode($flash['lab_order_ids'] ?? ''); ?>"
                            target="_blank" class="btn btn-secondary" style="white-space: nowrap;">
                            Print Lab Order
                        </a>
                    <?php endif; ?>
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
                    <div class="info-item">
                        <span class="info-label">Room / Location</span>
                        <span class="info-value"><?php echo htmlspecialchars($row['room_location'] ?: '—'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Date Confined</span>
                        <span class="info-value"><?php echo htmlspecialchars(date("M j, Y", strtotime($row['date_confined']))); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Days Confined</span>
                        <span class="info-value"><?php echo (int) $daysConfined; ?> day<?php echo $daysConfined !== 1 ? 's' : ''; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Status</span>
                        <span class="info-value">
                            <?php if ($isOngoing): ?>
                                <span class="status-pill status-<?php echo htmlspecialchars($row['clinical_status']); ?>">
                                    <?php echo htmlspecialchars($statusLabels[$row['clinical_status']] ?? ucfirst($row['clinical_status'])); ?>
                                </span>
                            <?php else: ?>
                                <span class="status-pill status-discharged">Discharged</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </section>

            <?php if (!$isOngoing): ?>
                <!-- Discharge Summary (read-only once discharged) -->
                <section class="card">
                    <div class="card-header">
                        <h2>Discharge Summary</h2>
                        <a href="print-confinement-discharge.php?confinement_id=<?php echo (int) $confinementId; ?>" target="_blank" class="btn btn-secondary">Print Discharge Summary</a>
                        <?php if (count($dischargeMedicationRows) > 0): ?>
                            <a href="print-pharmacy-slip.php?confinement_id=<?php echo (int) $confinementId; ?>" target="_blank" class="btn btn-secondary">Print Pharmacy Slip</a>
                        <?php endif; ?>
                    </div>
                    <div class="patient-info-grid">
                        <div class="info-item">
                            <span class="info-label">Date Discharged</span>
                            <span class="info-value"><?php echo htmlspecialchars(date("M j, Y", strtotime($row['discharge_date']))); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Discharge Status</span>
                            <span class="info-value"><?php echo htmlspecialchars($dischargeLabels[$row['discharge_status']] ?? ucfirst($row['discharge_status'])); ?></span>
                        </div>
                        <?php if (!empty($row['follow_up_date'])): ?>
                            <div class="info-item">
                                <span class="info-label">Follow-up Date</span>
                                <span class="info-value"><?php echo htmlspecialchars(date("M j, Y", strtotime($row['follow_up_date']))); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($row['final_diagnosis'])): ?>
                        <div class="form-group" style="margin-top: 16px;">
                            <span class="info-label">Final Diagnosis</span>
                            <p style="margin: 4px 0 0;"><?php echo nl2br(htmlspecialchars($row['final_diagnosis'])); ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if (count($dischargeMedicationRows) > 0): ?>
                        <div class="form-group" style="margin-top: 16px;">
                            <span class="info-label">Medications to Continue</span>
                            <div class="table-wrap" style="margin-top: 8px;">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Medicine</th>
                                            <th>Quantity</th>
                                            <th>Instructions</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($dischargeMedicationRows as $medRow): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($medRow['medicine_name']); ?></td>
                                                <td><?php echo htmlspecialchars($medRow['quantity'] ?? '—'); ?></td>
                                                <td><?php echo htmlspecialchars($medRow['instructions'] ?? '—'); ?></td>
                                                <td><?php echo htmlspecialchars(ucfirst($medRow['status'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($row['discharge_medications'])): ?>
                        <div class="form-group" style="margin-top: 16px;">
                            <span class="info-label">Additional Medication Notes</span>
                            <p style="margin: 4px 0 0;"><?php echo nl2br(htmlspecialchars($row['discharge_medications'])); ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($row['follow_up_instructions'])): ?>
                        <div class="form-group" style="margin-top: 16px;">
                            <span class="info-label">Follow-up Instructions</span>
                            <p style="margin: 4px 0 0;"><?php echo nl2br(htmlspecialchars($row['follow_up_instructions'])); ?></p>
                        </div>
                    <?php endif; ?>
                </section>
            <?php else: ?>
                <!-- Add Progress Note + Update Status -->
                <section class="card">
                    <div class="card-header">
                        <h2>Add Progress Note</h2>
                    </div>
                    <form class="consultation-form" method="POST" action="confinement-note-process.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="confinement_id" value="<?php echo (int) $confinementId; ?>">
                        <div class="form-group">
                            <label class="form-label" for="progressNote">Note</label>
                            <textarea class="form-textarea" id="progressNote" name="note" rows="3" placeholder="e.g. Vitals stable, tolerating oral intake"></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="clinicalStatusUpdate">Update Status</label>
                            <select class="form-input" id="clinicalStatusUpdate" name="clinical_status">
                                <option value="stable" <?php echo $row['clinical_status'] === 'stable' ? 'selected' : ''; ?>>Stable</option>
                                <option value="improving" <?php echo $row['clinical_status'] === 'improving' ? 'selected' : ''; ?>>Improving</option>
                                <option value="critical" <?php echo $row['clinical_status'] === 'critical' ? 'selected' : ''; ?>>Critical</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </form>
                </section>

                <!-- Order Lab Test -->
                <section class="card prescription-card">
                    <div class="card-header">
                        <div>
                            <h2>Order Lab Test</h2>
                            <p class="card-subtitle" style="margin: 4px 0 0;">
                                Order any tests this patient needs while confined - repeat CBCs, chem panels, etc.
                            </p>
                        </div>
                    </div>
                    <form class="consultation-form" method="POST" action="confinement-lab-order-process.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="confinement_id" value="<?php echo (int) $confinementId; ?>">
                        <div id="labTestsList" class="medicines-list">
                            <!-- Lab test rows added here by JS -->
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
                        <button type="submit" class="btn btn-primary" style="margin-top: 16px;">Order Tests</button>
                    </form>
                </section>

                <!-- Doctor's Orders (diet / activity / IV fluids / medication) -->
                <section class="card">
                    <div class="card-header">
                        <div>
                            <h2>Doctor's Orders</h2>
                            <p class="card-subtitle" style="margin: 4px 0 0;">
                                Diet, activity level, IV fluids, and medication orders for this stay.
                            </p>
                        </div>
                    </div>
                    <form class="consultation-form" method="POST" action="confinement-order-process.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="confinement_id" value="<?php echo (int) $confinementId; ?>">
                        <div class="form-group">
                            <label class="form-label" for="orderType">Order Type</label>
                            <select class="form-input" id="orderType" name="order_type" required>
                                <option value="">Select type</option>
                                <?php foreach ($orderTypeLabels as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="orderDescription">Description</label>
                            <input class="form-input" type="text" id="orderDescription" name="description" maxlength="255" placeholder="e.g. NPO except sips of water, or Ambulate as tolerated">
                        </div>
                        <button type="submit" class="btn btn-primary">Add Order</button>
                    </form>

                    <?php if (count($confinementOrders) > 0): ?>
                        <div class="table-wrap" style="margin-top: 20px;">
                            <table class="queue-table">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th>Ordered</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($confinementOrders as $order): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($orderTypeLabels[$order['order_type']] ?? ucfirst($order['order_type'])); ?></td>
                                            <td><?php echo htmlspecialchars($order['description']); ?></td>
                                            <td><?php echo htmlspecialchars(date("M j, Y g:i A", strtotime($order['created_at']))); ?></td>
                                            <td>
                                                <?php if ($order['status'] === 'active'): ?>
                                                    <span class="status-pill status-stable">Active</span>
                                                <?php else: ?>
                                                    <span class="status-pill status-discharged">Discontinued</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($order['status'] === 'active'): ?>
                                                    <form method="POST" action="confinement-order-discontinue-process.php" onsubmit="return confirm('Discontinue this order?');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="confinement_id" value="<?php echo (int) $confinementId; ?>">
                                                        <input type="hidden" name="order_id" value="<?php echo (int) $order['order_id']; ?>">
                                                        <button type="submit" class="btn btn-secondary">Discontinue</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state" style="margin-top: 16px;">
                            <p><strong>No orders yet.</strong></p>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- Log Referral -->
                <section class="card">
                    <div class="card-header">
                        <div>
                            <h2>Log Referral</h2>
                            <p class="card-subtitle" style="margin: 4px 0 0;">
                                Record a referral to another facility or specialist - e.g. referring up to a provincial or regional hospital.
                            </p>
                        </div>
                    </div>
                    <form class="consultation-form" method="POST" action="confinement-referral-process.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="confinement_id" value="<?php echo (int) $confinementId; ?>">
                        <div class="form-group">
                            <label class="form-label" for="referredTo">Referred To</label>
                            <input class="form-input" type="text" id="referredTo" name="referred_to" maxlength="255" placeholder="e.g. Oriental Mindoro Provincial Hospital - Internal Medicine">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="referralReason">Reason</label>
                            <textarea class="form-textarea" id="referralReason" name="reason" rows="2" placeholder="e.g. Requires cardiac catheterization not available at OMCDH"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Log Referral</button>
                    </form>

                    <?php if (count($referrals) > 0): ?>
                        <div class="table-wrap" style="margin-top: 20px;">
                            <table class="queue-table">
                                <thead>
                                    <tr>
                                        <th>Referred To</th>
                                        <th>Reason</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($referrals as $ref): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($ref['referred_to']); ?></td>
                                            <td><?php echo htmlspecialchars($ref['reason']); ?></td>
                                            <td><?php echo htmlspecialchars(date("M j, Y g:i A", strtotime($ref['created_at']))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- Discharge -->
                <section class="card">
                    <div class="card-header">
                        <h2>Discharge Patient</h2>
                    </div>
                    <p class="page-subtitle" style="margin-bottom: 16px;">
                        Select the outcome to discharge this patient. This cannot be undone from this page.
                    </p>
                    <button type="button" class="btn-cancel-followup" id="showDischargeFormBtn">
                        Discharge Patient
                    </button>

                    <form class="consultation-form" method="POST" action="confinement-discharge-process.php" id="dischargeForm" hidden style="margin-top: 16px;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="confinement_id" value="<?php echo (int) $confinementId; ?>">
                        <div class="form-group">
                            <label class="form-label">Discharge Outcome <span class="required">*</span></label>
                            <div class="outcome-actions">
                                <?php foreach ($dischargeLabels as $value => $label): ?>
                                    <label class="action-card" style="cursor: pointer;">
                                        <input type="radio" name="discharge_status" value="<?php echo htmlspecialchars($value); ?>" required style="margin-right: 8px;">
                                        <div class="action-content">
                                            <h3><?php echo htmlspecialchars($label); ?></h3>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="finalDiagnosis">Final Diagnosis</label>
                            <input class="form-input" type="text" id="finalDiagnosis" name="final_diagnosis" maxlength="255" placeholder="Optional">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Medications to Continue</label>
                            <div id="dischargeMedicinesList" class="medicines-list"></div>
                            <div class="add-medicine-btn-wrapper">
                                <button type="button" id="addDischargeMedicineBtn" class="btn btn-secondary">Add Medicine</button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="dischargeMedications">Additional Medication Notes</label>
                            <textarea class="form-textarea" id="dischargeMedications" name="discharge_medications" rows="2" placeholder="Optional - anything not tied to one specific medicine above, e.g. reduce dosage gradually"></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="followUpDate">Follow-up Date</label>
                            <input class="form-input" type="date" id="followUpDate" name="follow_up_date">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="followUpInstructions">Follow-up Instructions</label>
                            <input class="form-input" type="text" id="followUpInstructions" name="follow_up_instructions" maxlength="255" placeholder="Optional - e.g. Return to OPD in 1 week for wound check">
                        </div>
                        <button type="submit" class="btn btn-primary">Confirm Discharge</button>
                    </form>
                </section>
            <?php endif; ?>

            <!-- Progress Notes History -->
            <section class="card">
                <div class="card-header">
                    <h2>Progress Notes</h2>
                </div>
                <?php if (count($notes) > 0): ?>
                    <div class="notes-list">
                        <?php foreach ($notes as $note): ?>
                            <div class="note-item" style="padding: 12px 0; border-bottom: 1px solid var(--border-color, #e5e7eb);">
                                <div style="display:flex; justify-content: space-between; margin-bottom: 4px;">
                                    <span class="info-label">Dr. <?php echo htmlspecialchars(trim($note['first_name'] . ' ' . $note['last_name'])); ?></span>
                                    <span class="info-label"><?php echo htmlspecialchars(date("M j, Y g:i A", strtotime($note['created_at']))); ?></span>
                                </div>
                                <p style="margin: 0;"><?php echo nl2br(htmlspecialchars($note['note'])); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <p><strong>No progress notes yet.</strong></p>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Lab Orders History -->
            <section class="card">
                <div class="card-header">
                    <h2>Lab Orders</h2>
                    <?php if (count($labOrders) > 0): ?>
                        <a href="print-lab-order.php?confinement_id=<?php echo (int) $confinementId; ?>" target="_blank" class="btn btn-secondary">Print All</a>
                    <?php endif; ?>
                </div>
                <?php if (count($labOrders) > 0): ?>
                    <div class="table-wrap">
                        <table class="queue-table">
                            <thead>
                                <tr>
                                    <th>Test</th>
                                    <th>Notes</th>
                                    <th>Ordered</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($labOrders as $lab): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($lab['test_name']); ?></td>
                                        <td><?php echo htmlspecialchars($lab['notes'] ?? '—'); ?></td>
                                        <td><?php echo htmlspecialchars(date("M j, Y g:i A", strtotime($lab['created_at']))); ?></td>
                                        <td><?php echo htmlspecialchars(get_lab_status_labels()[$lab['status']] ?? ucfirst($lab['status'])); ?></td>
                                        <td>
                                            <a href="print-lab-order.php?confinement_id=<?php echo (int) $confinementId; ?>&ids=<?php echo (int) $lab['lab_order_id']; ?>" target="_blank" class="btn-table-link">Print</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <p><strong>No lab tests ordered yet.</strong></p>
                    </div>
                <?php endif; ?>
            </section>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <!-- Discharge confirmation modal -->
    <div class="confirm-modal-backdrop" id="dischargeConfirmModal">
        <div class="confirm-modal-box" role="dialog" aria-modal="true" aria-labelledby="dischargeConfirmTitle">
            <div class="confirm-modal-icon">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"></path>
                    <line x1="12" y1="9" x2="12" y2="13"></line>
                    <line x1="12" y1="17" x2="12.01" y2="17"></line>
                </svg>
            </div>
            <h3 id="dischargeConfirmTitle">Discharge this patient?</h3>
            <p>This closes out the confinement record and cannot be undone from this page. Make sure the outcome you selected is correct before continuing.</p>
            <div class="confirm-modal-actions">
                <button type="button" class="btn btn-secondary" id="dischargeConfirmCancel">Cancel</button>
                <button type="button" class="btn btn-primary" id="dischargeConfirmProceed">Yes, Discharge Patient</button>
            </div>
        </div>
    </div>

    <script>
        // Static reference list (see $labTestOptions in confinement-record.php) -
        // same pattern as consultation.php.
        var labTestOptions = <?php echo json_encode($labTestOptions); ?>;
        // Medicine catalog for "Medications to Continue" - see $medicines
        // in confinement-record.php and consultation.php's own copy of
        // this comment for the {id, name, available} shape.
        var medicineOptions = <?php echo json_encode($medicines); ?>;
    </script>
    <script src="../assets/js/doctor-dashboard.js"></script>
    <script src="../assets/js/confinement-record.js"></script>
</body>

</html>