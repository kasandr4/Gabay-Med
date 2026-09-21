<?php
// patient/confinement-dashboard.php
// Implements spec 1.8 — Confinement Dashboard.
//
// Entirely replaces the normal dashboard while status = confined.
// Strictly read-only. No "Schedule Follow-Up" action here — that's
// doctor-initiated only (spec 2.5); the patient just sees the result
// land under Follow-Up Appointments (1.9) once that exists.

require_once '../includes/auth_guard.php';
require_role('patient');
require_once '../config/db.php';

$active_page = 'confinement';
$patient_id = $_SESSION['user_id'];

// Re-check status — but instead of redirecting away when not confined,
// show a graceful "not currently confined" message, since this page is
// now always reachable from the sidebar (not just via auto-redirect).
$stmt = $conn->prepare("SELECT status FROM users WHERE user_id = ?");
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$status = $stmt->get_result()->fetch_assoc()['status'];
$stmt->close();

$currently_confined = ($status === 'confined');

// If not currently confined, check for any PAST confinement records to
// show instead of a totally empty page (spec keeps past confinements
// out of the active banner, but doesn't forbid showing them in their
// own history view — this nav entry is a reasonable place for that).
$past_confinements = [];
$discharge_medications_by_confinement = []; // confinement_id => [ {name, quantity, instructions, status}, ... ]
if (!$currently_confined) {
    $stmt = $conn->prepare("
        SELECT c.confinement_id, c.date_confined, c.discharge_status, c.discharge_date, c.room_location,
               doc.first_name AS doctor_first, doc.last_name AS doctor_last
        FROM confinements c
        JOIN users doc ON c.attending_doctor_id = doc.user_id
        WHERE c.patient_id = ? AND c.discharge_status IS NOT NULL
        ORDER BY c.date_confined DESC
    ");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $past_confinements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Itemized "Medications to Continue" for each past confinement (see
    // 023_confinement_discharge_medications.sql) - fetched in one query
    // for all of them and grouped in PHP, same idea as
    // staff/dispense-stock.php's $prescriptionGroups grouping.
    if (!empty($past_confinements)) {
        $confinementIds = array_column($past_confinements, 'confinement_id');
        $placeholders = implode(',', array_fill(0, count($confinementIds), '?'));
        $types = str_repeat('i', count($confinementIds));
        $stmt = $conn->prepare(
            "SELECT confinement_id, medicine_name, quantity, instructions, status
             FROM confinement_discharge_medications
             WHERE confinement_id IN ($placeholders)
             ORDER BY discharge_medication_id ASC"
        );
        $stmt->bind_param($types, ...$confinementIds);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $medRow) {
            $discharge_medications_by_confinement[(int) $medRow['confinement_id']][] = $medRow;
        }
        $stmt->close();
    }
}

// Spec edge case: show only the CURRENT ongoing confinement (discharge_status IS NULL).
// Past confinements belong in their own list below, not cluttering this banner.
$confinement = null;
$progress_notes = [];

if ($currently_confined) {
    $stmt = $conn->prepare("
        SELECT c.confinement_id, c.date_confined, c.room_location, c.discharge_status, c.discharge_date,
               doc.first_name AS doctor_first, doc.last_name AS doctor_last
        FROM confinements c
        JOIN users doc ON c.attending_doctor_id = doc.user_id
        WHERE c.patient_id = ? AND c.discharge_status IS NULL
        ORDER BY c.date_confined DESC LIMIT 1
    ");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $confinement = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Pull progress notes for this confinement, most recent first.
    // NOTE: doctor-side "Add Progress Note" (confinement-note-process.php)
    // writes into `confinement_notes` (note, created_at) — NOT the older
    // `progress_notes` table (note_date, note_text). We read from
    // `confinement_notes` here so notes doctors actually add show up.
    // `created_at` is aliased to `note_date` so the display code below
    // doesn't need to change.
    if ($confinement) {
        $stmt = $conn->prepare("
            SELECT cn.created_at AS note_date, cn.note AS note_text,
                   doc.first_name AS doctor_first, doc.last_name AS doctor_last
            FROM confinement_notes cn
            JOIN users doc ON cn.doctor_id = doc.user_id
            WHERE cn.confinement_id = ?
            ORDER BY cn.created_at DESC, cn.note_id DESC
        ");
        $stmt->bind_param("i", $confinement['confinement_id']);
        $stmt->execute();
        $progress_notes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

// Per spec: maps a discharge_status to patient-facing text.
// "Deceased" is NEVER rendered to the patient's own session — this function
// simply won't be called with that value in a live patient session, since
// the only way to reach THIS page is status='confined' (an active session),
// and a deceased patient's status would have moved on from 'confined'.
// Documented here as the recognized edge case per spec, not specially handled.
function get_discharge_label($discharge_status)
{
    switch ($discharge_status) {
        case 'recovered':
            return 'Discharged — Recovered';
        case 'transferred':
            return 'Discharged — Transferred';
        case 'dama':
            return 'Discharged Against Medical Advice (DAMA)';
        default:
            return 'Discharged';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confinement - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/confinement.css">
    <link rel="stylesheet" href="../assets/css/prescriptions.css">
    <style>
        /* TODO: move into confinement.css once merged there */
        .confinement-blocked-note {
            background: #FFF7E6;
            border: 1px solid #F0C36D;
            color: var(--text-dark);
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 14px;
            margin-bottom: 18px;
        }
    </style>
</head>

<body>
    <div class="app-layout">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">

            <div class="page-header">
                <h1 class="page-title">My Confinement</h1>
            </div>

            <?php
            $blocked_labels = ['dashboard' => 'Dashboard', 'book-appointment' => 'Book Appointment'];
            $blocked_from = $_GET['blocked'] ?? '';
            if (isset($blocked_labels[$blocked_from])):
            ?>
                <div class="confinement-blocked-note">
                    <?= htmlspecialchars($blocked_labels[$blocked_from]) ?> isn't available while you're confined — you're redirected here instead.
                </div>
            <?php endif; ?>

            <?php if ($currently_confined && $confinement): ?>

                <!-- Status banner -->
                <div class="confinement-banner">
                    <div class="confinement-banner-title">
                        Confined since <?= date('F j, Y', strtotime($confinement['date_confined'])) ?>
                    </div>
                    <div class="confinement-banner-meta">
                        Attending: Dr. <?= htmlspecialchars($confinement['doctor_first'] . ' ' . $confinement['doctor_last']) ?>
                        <?php if (!empty($confinement['room_location'])): ?>
                            &middot; Room/Location: <?= htmlspecialchars($confinement['room_location']) ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Progress notes timeline -->
                <div class="card">
                    <div class="card-title">Progress Notes</div>

                    <?php if (empty($progress_notes)): ?>
                        <div class="empty-state">
                            <p class="empty-state-text">No progress notes have been recorded yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="timeline">
                            <?php foreach ($progress_notes as $note): ?>
                                <div class="timeline-item">
                                    <div class="timeline-date"><?= date('M j, Y', strtotime($note['note_date'])) ?></div>
                                    <div class="timeline-doctor">Dr. <?= htmlspecialchars($note['doctor_first'] . ' ' . $note['doctor_last']) ?></div>
                                    <div class="timeline-text"><?= htmlspecialchars($note['note_text']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif (!empty($past_confinements)): ?>

                <!-- Not currently confined, but has past confinement history -->
                <div class="card">
                    <div class="empty-state">
                        <p class="empty-state-text">You are not currently confined.</p>
                    </div>
                </div>

                <div class="card">
                    <div class="card-title">Past Confinements</div>
                    <div class="timeline">
                        <?php foreach ($past_confinements as $past): ?>
                            <div class="timeline-item">
                                <div class="timeline-date">
                                    <?= date('M j, Y', strtotime($past['date_confined'])) ?>
                                    &ndash;
                                    <?= $past['discharge_date'] ? date('M j, Y', strtotime($past['discharge_date'])) : '' ?>
                                </div>
                                <div class="timeline-doctor">Dr. <?= htmlspecialchars($past['doctor_first'] . ' ' . $past['doctor_last']) ?></div>
                                <div class="timeline-text">
                                    <?= htmlspecialchars(get_discharge_label($past['discharge_status'])) ?>
                                    <?php if (!empty($past['room_location'])): ?>
                                        &middot; <?= htmlspecialchars($past['room_location']) ?>
                                    <?php endif; ?>
                                </div>
                                <?php $meds = $discharge_medications_by_confinement[(int) $past['confinement_id']] ?? []; ?>
                                <?php if (!empty($meds)): ?>
                                    <div class="timeline-medications">
                                        <div class="timeline-medications-title">Medications to Continue</div>
                                        <?php foreach ($meds as $med): ?>
                                            <div class="timeline-medication-row">
                                                <span class="timeline-medication-name"><?= htmlspecialchars($med['medicine_name']) ?></span>
                                                <?php if (!empty($med['quantity'])): ?>
                                                    <span class="timeline-medication-qty"><?= htmlspecialchars($med['quantity']) ?></span>
                                                <?php endif; ?>
                                                <span class="rx-chip rx-chip-<?= htmlspecialchars($med['status']) ?>">
                                                    <?= htmlspecialchars(ucfirst($med['status'])) ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                        <a
                                            href="print-pharmacy-slip.php?confinement_id=<?= (int) $past['confinement_id'] ?>"
                                            target="_blank"
                                            class="btn-view-rx"
                                            style="margin-top: 10px; display: inline-block; text-decoration: none;">
                                            Print Pharmacy Slip
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            <?php else: ?>

                <!-- Not confined now, and no past confinement history at all -->
                <div class="card">
                    <div class="empty-state">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" style="margin: 0 auto 14px; color: var(--sage);">
                            <path d="M3 12h4l2 8 4-16 2 8h6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <p class="empty-state-heading">You don't have any confinement records right now.</p>
                        <p class="empty-state-subtext">If you're ever admitted for inpatient care, your room, attending doctor, and daily progress notes will appear here.</p>
                    </div>
                </div>

            <?php endif; ?>

        </main>
    </div>
</body>

</html>