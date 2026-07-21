<?php
// patient/lookup-visit.php
// No-login lookup for a single visit's findings/prescription, using the
// same signed reference number check-in.php already relies on. This is
// the guest-patient fallback: someone who never activated an account
// (account_status = 'guest') still has permanent access to their own
// visit record without needing a password.
//
// Two things must both match before anything is shown:
//   1. The reference number's signature must verify (includes/reference_number.php)
//   2. The last name entered must match that appointment's patient
// This stops a stray/guessed reference number from exposing someone
// else's medical record.

require_once '../config/db.php';
require_once '../includes/reference_number.php';

$reference = trim($_GET['reference_number'] ?? '');
$last_name_input = trim($_GET['last_name'] ?? '');

$visit = null;
$error = null;

if ($reference !== '' && $last_name_input !== '') {
    $appointment_id = parse_appointment_reference($reference);

    if ($appointment_id === null) {
        $error = "That reference number doesn't look valid. Please check it and try again.";
    } else {
        $stmt = $conn->prepare(
            "SELECT a.appointment_id, a.created_at, a.slot_start, a.status,
                    u.first_name, u.last_name,
                    d.department_name,
                    doc.first_name AS doctor_first_name, doc.last_name AS doctor_last_name,
                    c.findings, c.clinical_notes, c.outcome, c.consultation_id
             FROM appointments a
             JOIN users u ON u.user_id = a.patient_id
             JOIN departments d ON d.department_id = a.department_id
             JOIN users doc ON doc.user_id = a.doctor_id
             LEFT JOIN consultations c ON c.consultation_id = (
                 SELECT c2.consultation_id FROM consultations c2
                 WHERE c2.appointment_id = a.appointment_id
                 ORDER BY c2.created_at DESC, c2.consultation_id DESC
                 LIMIT 1
             )
             WHERE a.appointment_id = ?
             LIMIT 1"
        );
        $stmt->bind_param("i", $appointment_id);
        $stmt->execute();
        $candidate = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (
            !$candidate
            || !verify_appointment_reference($reference, $candidate['appointment_id'], $candidate['created_at'])
        ) {
            $error = "No visit found for that reference number.";
        } elseif (strcasecmp(trim($candidate['last_name']), $last_name_input) !== 0) {
            // Deliberately vague — don't reveal whether the reference number
            // itself was valid if the name doesn't match, so this can't be
            // used to fish for a real reference number's associated name.
            $error = "That reference number and last name don't match our records.";
        } else {
            $visit = $candidate;
        }
    }

    if ($visit && !$visit['consultation_id']) {
        $error = "This visit hasn't been completed yet — findings aren't available until after the consultation.";
        $visit = null;
    }
}

$prescriptions = [];
if ($visit) {
    $stmt = $conn->prepare(
        "SELECT medicine_name, quantity, instructions
         FROM prescriptions WHERE consultation_id = ?"
    );
    $stmt->bind_param("i", $visit['consultation_id']);
    $stmt->execute();
    $prescriptions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Look Up Your Visit - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
</head>

<body>
    <div class="app-shell">
        <main class="main-content" style="margin: 0 auto; max-width: 640px;">
            <header class="page-header">
                <div>
                    <h1>Look Up Your Visit</h1>
                    <p class="page-subtitle">No account needed — enter your reference number and last name.</p>
                </div>
            </header>

            <?php if ($error): ?>
                <section class="card" style="border-left: 4px solid var(--red); margin-bottom: 20px;">
                    <p style="margin: 0; font-size: 14px; color: var(--red);"><?php echo htmlspecialchars($error); ?></p>
                </section>
            <?php endif; ?>

            <section class="card">
                <form method="GET" action="lookup-visit.php" class="consultation-form">
                    <div class="form-group">
                        <label class="form-label">Reference Number</label>
                        <input type="text" name="reference_number" class="form-input"
                            placeholder="e.g. GM-2026-000047-A3F9"
                            value="<?php echo htmlspecialchars($reference); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" class="form-input"
                            value="<?php echo htmlspecialchars($last_name_input); ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Look Up</button>
                </form>
            </section>

            <?php if ($visit): ?>
                <section class="card" style="margin-top: 20px;">
                    <div class="card-header">
                        <h2>Visit Summary</h2>
                    </div>
                    <div class="patient-info-grid">
                        <div class="info-item"><span class="info-label">Date</span><span class="info-value"><?php echo htmlspecialchars(date("M j, Y \a\t g:i A", strtotime($visit['slot_start']))); ?></span></div>
                        <div class="info-item"><span class="info-label">Department</span><span class="info-value"><?php echo htmlspecialchars($visit['department_name']); ?></span></div>
                        <div class="info-item"><span class="info-label">Doctor</span><span class="info-value">Dr. <?php echo htmlspecialchars(trim($visit['doctor_first_name'] . ' ' . $visit['doctor_last_name'])); ?></span></div>
                    </div>

                    <div style="margin-top: 16px;">
                        <h3 style="font-size: 15px;">Findings</h3>
                        <p><?php echo nl2br(htmlspecialchars($visit['findings'])); ?></p>
                    </div>

                    <?php if ($visit['clinical_notes']): ?>
                        <div style="margin-top: 16px;">
                            <h3 style="font-size: 15px;">Clinical Notes</h3>
                            <p><?php echo nl2br(htmlspecialchars($visit['clinical_notes'])); ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if (count($prescriptions) > 0): ?>
                        <div style="margin-top: 16px;">
                            <h3 style="font-size: 15px;">Prescription</h3>
                            <ul>
                                <?php foreach ($prescriptions as $p): ?>
                                    <li>
                                        <?php echo htmlspecialchars($p['medicine_name']); ?>
                                        <?php if ($p['quantity']): ?> — <?php echo htmlspecialchars($p['quantity']); ?><?php endif; ?>
                                            <?php if ($p['instructions']): ?><br><span class="form-hint"><?php echo htmlspecialchars($p['instructions']); ?></span><?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>
        </main>
    </div>
</body>

</html>