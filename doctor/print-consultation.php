<?php
// doctor/print-consultation.php
// Print-friendly findings + prescription handout, generated at the point
// of care regardless of whether the patient has an account. This is the
// universal fallback: a patient who declines account activation (or is a
// guest who never gets around to it) still walks out with something in
// hand, same as any normal clinic visit.
//
// Scoped to the logged-in doctor's own consultations only - same pattern
// as consultation.php's "WHERE a.appointment_id = ? AND a.doctor_id = ?".

require_once '../includes/auth_guard.php';
require_role('doctor');
require_once '../config/db.php';
require_once '../includes/reference_number.php';

$doctorId = (int) $_SESSION['user_id'];
$consultationId = isset($_GET['consultation_id']) ? (int) $_GET['consultation_id'] : 0;

if ($consultationId <= 0) {
    http_response_code(400);
    exit('Missing consultation_id.');
}

// NOTE ON "pat" / "doc" JOINs below: every patient in this system - guest
// (no online account) or active - is a row in `users`, distinguished only
// by `account_status`. So JOINing on `users` does not, by itself, require
// an account; it requires a *patient record*, which every consultation
// already has via consultations.patient_id (NOT NULL, FK'd to users).
// The LEFT JOINs are kept anyway as a defensive measure so a print
// request can never hard-fail with a blank page if that assumption is
// ever violated - missing fields just render as "Not on file" instead.
$stmt = $conn->prepare(
    "SELECT c.consultation_id, c.findings, c.clinical_notes, c.outcome, c.created_at AS consultation_created_at,
            a.appointment_id, a.created_at AS appointment_created_at, a.slot_start,
            pat.first_name AS patient_first, pat.last_name AS patient_last, pat.birthdate, pat.sex,
            pat.phone_number AS patient_phone,
            doc.first_name AS doctor_first, doc.last_name AS doctor_last,
            d.department_name,
            fu.followup_date, fu.followup_time
     FROM consultations c
     JOIN appointments a ON a.appointment_id = c.appointment_id
     LEFT JOIN users pat ON pat.user_id = c.patient_id
     LEFT JOIN users doc ON doc.user_id = c.doctor_id
     JOIN departments d ON d.department_id = a.department_id
     LEFT JOIN follow_ups fu ON fu.follow_up_id = (
         SELECT f2.follow_up_id FROM follow_ups f2
         WHERE f2.original_appointment_id = a.appointment_id AND f2.status = 'scheduled'
         ORDER BY f2.created_at DESC, f2.follow_up_id DESC
         LIMIT 1
     )
     WHERE c.consultation_id = ? AND c.doctor_id = ?
     LIMIT 1"
);
$stmt->bind_param("ii", $consultationId, $doctorId);
$stmt->execute();
$record = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$record) {
    http_response_code(404);
    exit('Consultation not found, or does not belong to you.');
}

$stmt = $conn->prepare(
    "SELECT medicine_name, quantity, instructions FROM prescriptions WHERE consultation_id = ?"
);
$stmt->bind_param("i", $consultationId);
$stmt->execute();
$prescriptions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$referenceNumber = format_appointment_reference($record['appointment_id'], $record['appointment_created_at']);
$age = $record['birthdate'] ? floor((time() - strtotime($record['birthdate'])) / 31556926) : null;
$patientName = trim(($record['patient_first'] ?? '') . ' ' . ($record['patient_last'] ?? ''));
if ($patientName === '') {
    $patientName = 'Patient record unavailable';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visit Summary - <?php echo htmlspecialchars($patientName); ?></title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: Georgia, 'Times New Roman', serif;
            color: #1a1a1a;
            max-width: 720px;
            margin: 0 auto;
            padding: 40px 32px;
            line-height: 1.5;
        }

        header.doc-header {
            border-bottom: 2px solid #1a1a1a;
            padding-bottom: 16px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        header.doc-header h1 {
            margin: 0;
            font-size: 22px;
        }

        header.doc-header .ref {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            text-align: right;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 24px;
            font-size: 14px;
            margin-bottom: 28px;
        }

        .meta-grid .label {
            color: #555;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        section {
            margin-bottom: 24px;
        }

        section h2 {
            font-size: 15px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            border-bottom: 1px solid #ccc;
            padding-bottom: 4px;
            margin-bottom: 10px;
        }

        .rx-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .rx-table th,
        .rx-table td {
            text-align: left;
            padding: 6px 8px;
            border-bottom: 1px solid #eee;
        }

        .rx-table th {
            font-size: 12px;
            text-transform: uppercase;
            color: #555;
        }

        footer.doc-footer {
            margin-top: 48px;
            padding-top: 16px;
            border-top: 1px solid #ccc;
            font-size: 12px;
            color: #777;
        }

        @media print {
            body {
                padding: 0;
            }
        }
    </style>
</head>

<body>

    <header class="doc-header">
        <div>
            <h1>GabayMed</h1>
            <div style="font-size: 13px; color: #555;">Visit Summary</div>
        </div>
        <div class="ref">
            Ref: <?php echo htmlspecialchars($referenceNumber); ?><br>
            <?php echo htmlspecialchars(date("M j, Y", strtotime($record['slot_start']))); ?>
        </div>
    </header>

    <div class="meta-grid">
        <div>
            <div class="label">Patient</div>
            <?php echo htmlspecialchars($patientName); ?>
            <?php if ($age !== null): ?> (<?php echo (int) $age; ?> y/o<?php echo $record['sex'] ? ', ' . ucfirst($record['sex']) : ''; ?>)<?php endif; ?>
        </div>
        <div>
            <div class="label">Phone Number</div>
            <?php echo htmlspecialchars($record['patient_phone'] ?? 'Not on file'); ?>
        </div>
        <div>
            <div class="label">Attending Physician</div>
            Dr. <?php echo htmlspecialchars(trim(($record['doctor_first'] ?? '') . ' ' . ($record['doctor_last'] ?? ''))); ?>
        </div>
        <div>
            <div class="label">Department</div>
            <?php echo htmlspecialchars($record['department_name']); ?>
        </div>
        <div>
            <div class="label">Visit Time</div>
            <?php echo htmlspecialchars(date("g:i A", strtotime($record['slot_start']))); ?>
        </div>
        <?php if (!empty($record['followup_date'])): ?>
            <div>
                <div class="label">Follow-up Date</div>
                <?php
                echo htmlspecialchars(date("M j, Y", strtotime($record['followup_date'])));
                if (!empty($record['followup_time'])) {
                    echo ' at ' . htmlspecialchars(date("g:i A", strtotime($record['followup_time'])));
                }
                ?>
            </div>
        <?php endif; ?>
    </div>

    <section>
        <h2>Findings</h2>
        <p><?php echo nl2br(htmlspecialchars($record['findings'])); ?></p>
    </section>

    <?php if (!empty($record['clinical_notes'])): ?>
        <section>
            <h2>Clinical Notes</h2>
            <p><?php echo nl2br(htmlspecialchars($record['clinical_notes'])); ?></p>
        </section>
    <?php endif; ?>

    <?php if (count($prescriptions) > 0): ?>
        <section>
            <h2>Prescription</h2>
            <table class="rx-table">
                <thead>
                    <tr>
                        <th>Medicine</th>
                        <th>Quantity</th>
                        <th>Instructions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($prescriptions as $p): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($p['medicine_name']); ?></td>
                            <td><?php echo htmlspecialchars($p['quantity'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($p['instructions'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    <footer class="doc-footer">
        <span>Generated <?php echo date("M j, Y g:i A"); ?></span>
    </footer>

    <script>
        // Auto-open the print dialog on load - staff can still cancel it and
        // just hand over/save the page as-is if they don't need a physical copy.
        window.addEventListener('load', function() {
            window.print();
        });
    </script>

</body>

</html>