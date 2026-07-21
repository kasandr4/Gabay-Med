<?php
// patient/prescriptions.php

require_once '../includes/auth_guard.php';
require_role('patient');
require_once '../config/db.php';

$active_page = 'prescriptions';

// The logged-in patient's ID comes only from the session - never from the
// query string or a form field - so a patient can never view someone
// else's prescriptions by guessing an ID.
$patientId = (int) $_SESSION['user_id'];

/**
 * Format a MySQL datetime/timestamp string for display.
 */
function formatRxDate($datetime)
{
    $timestamp = strtotime($datetime);
    return $timestamp ? date("M j, Y", $timestamp) : $datetime;
}

/**
 * Turn the consultations.outcome value into a display label for the
 * status pill. This reuses the existing outcome column rather than
 * introducing a new "status" concept that doesn't exist in the schema.
 */
function formatRxStatus($outcome)
{
    $map = [
        "discharged" => "Discharged",
        "confined"   => "Confined",
    ];
    return $map[$outcome] ?? ucfirst(str_replace('_', ' ', $outcome));
}

// One row per prescribed medicine, joined back to the consultation it was
// issued under and the doctor who issued it. Scoped to this patient only
// via the WHERE clause (prepared statement, no string concatenation).
$sql = "SELECT
            c.consultation_id,
            c.created_at    AS consultation_date,
            c.findings      AS diagnosis,
            c.clinical_notes,
            c.outcome,
            u.first_name    AS doctor_first_name,
            u.last_name     AS doctor_last_name,
            p.prescription_id,
            p.medicine_name,
            p.quantity,
            p.instructions
        FROM prescriptions p
        INNER JOIN consultations c ON c.consultation_id = p.consultation_id
        INNER JOIN users u ON u.user_id = p.doctor_id
        WHERE p.patient_id = ?
        ORDER BY c.created_at DESC, p.prescription_id ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $patientId);
$stmt->execute();
$result = $stmt->get_result();

// Group the flat medicine rows into one entry per consultation, since a
// single consultation can carry several prescribed medicines.
$prescriptionGroups = [];
while ($row = $result->fetch_assoc()) {
    $cid = $row['consultation_id'];

    if (!isset($prescriptionGroups[$cid])) {
        $prescriptionGroups[$cid] = [
            "consultation_id" => $cid,
            "date"            => formatRxDate($row['consultation_date']),
            "doctor_name"     => "Dr. " . trim($row['doctor_first_name'] . " " . $row['doctor_last_name']),
            "diagnosis"       => $row['diagnosis'] !== '' ? $row['diagnosis'] : null,
            "notes"           => $row['clinical_notes'],
            "status"          => formatRxStatus($row['outcome']),
            "status_raw"      => $row['outcome'],
            "medicines"       => [],
        ];
    }

    $prescriptionGroups[$cid]['medicines'][] = [
        "name"         => $row['medicine_name'],
        "quantity"     => $row['quantity'],
        "instructions" => $row['instructions'],
    ];
}
$stmt->close();

// Insertion order already matches the SQL ORDER BY (newest consultation first).
$prescriptions = array_values($prescriptionGroups);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescriptions - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/prescriptions.css">
</head>

<body>
    <div class="app-layout">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">
            <div class="page-header">
                <h1 class="page-title">Prescriptions</h1>
                <p class="page-subtitle">View your prescription history and medication details.</p>
            </div>

            <div class="card">
                <?php if (empty($prescriptions)): ?>
                    <div class="empty-state">
                        <p class="empty-state-text">No prescriptions found.</p>
                        <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="rx-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Doctor</th>
                                    <th>Diagnosis</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($prescriptions as $rx): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($rx['date']); ?></td>
                                        <td><?php echo htmlspecialchars($rx['doctor_name']); ?></td>
                                        <td><?php echo $rx['diagnosis'] !== null ? htmlspecialchars($rx['diagnosis']) : '<span class="rx-muted">Not available</span>'; ?></td>
                                        <td>
                                            <span class="status-chip status-chip-<?php echo htmlspecialchars($rx['status_raw']); ?>">
                                                <?php echo htmlspecialchars($rx['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button
                                                type="button"
                                                class="btn-view-rx"
                                                data-consultation-id="<?php echo (int) $rx['consultation_id']; ?>">
                                                View Details
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Prescription Details Modal (single instance, populated via JS) -->
    <div class="rx-modal-overlay" id="rxModalOverlay">
        <div class="rx-modal" role="dialog" aria-modal="true" aria-labelledby="rxModalTitle">
            <div class="rx-modal-header">
                <div class="rx-modal-header-title">
                    <span class="rx-modal-header-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="16" y1="13" x2="8" y2="13"></line>
                            <line x1="16" y1="17" x2="8" y2="17"></line>
                        </svg>
                    </span>
                    <h2 id="rxModalTitle">Prescription Details</h2>
                </div>
                <button type="button" class="rx-modal-close" id="rxModalClose" aria-label="Close">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>

            <div class="rx-modal-body">

                <!-- Doctor / Date metadata -->
                <div class="rx-meta-grid">
                    <div class="rx-meta-item">
                        <span class="rx-meta-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </span>
                        <div class="rx-meta-text">
                            <span class="rx-detail-label">Doctor</span>
                            <span class="rx-detail-value" id="rxDetailDoctor">—</span>
                        </div>
                    </div>
                    <div class="rx-meta-item">
                        <span class="rx-meta-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                        </span>
                        <div class="rx-meta-text">
                            <span class="rx-detail-label">Date</span>
                            <span class="rx-detail-value" id="rxDetailDate">—</span>
                        </div>
                    </div>
                </div>

                <!-- Diagnosis (accent card) -->
                <div class="rx-diagnosis-card">
                    <span class="rx-detail-label">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 11l3 3L22 4"></path>
                            <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                        </svg>
                        Diagnosis
                    </span>
                    <p class="rx-diagnosis-text" id="rxDetailDiagnosis">—</p>
                </div>

                <!-- Notes / Instructions (bordered container) -->
                <div class="rx-notes-card">
                    <span class="rx-detail-label">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                        </svg>
                        Notes
                    </span>
                    <p class="rx-notes-text" id="rxDetailNotes">—</p>
                </div>

                <!-- Medications -->
                <div class="rx-medicines-section">
                    <h3 class="rx-section-title">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.5 20.5 3.5 13.5a4.95 4.95 0 1 1 7-7l7 7a4.95 4.95 0 1 1-7 7Z"></path>
                            <path d="m8.5 8.5 7 7"></path>
                        </svg>
                        Medications
                    </h3>
                    <div id="rxMedicinesList" class="rx-medicines-list">
                        <!-- Populated via JS -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Prescription data for this patient only, already scoped server-side -->
    <script>
        var prescriptionsData = <?php echo json_encode($prescriptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <script src="../assets/js/prescriptions.js"></script>
</body>

</html>