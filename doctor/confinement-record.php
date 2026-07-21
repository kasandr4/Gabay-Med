<?php
// doctor/confinement-record.php
// The Confinement module's detail page. This is the ONLY page that can
// discharge a patient - it updates confinements.discharge_date,
// confinements.discharge_status, and users.status, and nowhere else does.

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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confinement Record - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
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
                    </div>
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

    <script src="../assets/js/doctor-dashboard.js"></script>
    <script src="../assets/js/confinement-record.js"></script>
</body>

</html>