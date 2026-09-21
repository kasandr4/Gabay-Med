<?php
// doctor/print-confinement-discharge.php
// Print-friendly discharge summary + take-home medicines handout,
// generated once a confinement has actually been discharged. This is the
// confinement-side equivalent of print-consultation.php: the reference
// number printed here ("GMC-...", see includes/reference_number.php) is
// what a patient/companion brings to staff/dispense-stock.php to have
// the itemized medicines actually dispensed.
//
// Scoped to the logged-in doctor's own confinements only - same pattern
// as confinement-record.php's "WHERE c.confinement_id = ? AND
// c.attending_doctor_id = ?". Only viewable once discharge_date is set —
// there's nothing to print (no reference number, no itemized meds yet)
// before that.

require_once '../includes/auth_guard.php';
require_role('doctor');
require_once '../config/db.php';
require_once '../includes/reference_number.php';

$doctorId = (int) $_SESSION['user_id'];
$confinementId = isset($_GET['confinement_id']) ? (int) $_GET['confinement_id'] : 0;

if ($confinementId <= 0) {
    http_response_code(400);
    exit('Missing confinement_id.');
}

$stmt = $conn->prepare(
    "SELECT c.confinement_id, c.created_at, c.date_confined, c.room_location,
            c.discharge_date, c.discharge_status, c.final_diagnosis,
            c.discharge_medications, c.follow_up_date, c.follow_up_instructions,
            pat.first_name AS patient_first, pat.last_name AS patient_last, pat.birthdate, pat.sex,
            pat.phone_number AS patient_phone,
            doc.first_name AS doctor_first, doc.last_name AS doctor_last
     FROM confinements c
     LEFT JOIN users pat ON pat.user_id = c.patient_id
     LEFT JOIN users doc ON doc.user_id = c.attending_doctor_id
     WHERE c.confinement_id = ? AND c.attending_doctor_id = ?
     LIMIT 1"
);
$stmt->bind_param("ii", $confinementId, $doctorId);
$stmt->execute();
$record = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$record) {
    http_response_code(404);
    exit('Confinement not found, or does not belong to you.');
}

if ($record['discharge_date'] === null) {
    http_response_code(409);
    exit('This patient has not been discharged yet — there is nothing to print until discharge is completed.');
}

$stmt = $conn->prepare(
    "SELECT medicine_name, quantity, instructions FROM confinement_discharge_medications WHERE confinement_id = ? ORDER BY discharge_medication_id ASC"
);
$stmt->bind_param("i", $confinementId);
$stmt->execute();
$medications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$dischargeLabels = ["recovered" => "Recovered / Healthy", "transferred" => "Transferred", "dama" => "Discharged Against Medical Advice (DAMA)", "deceased" => "Deceased"];

$referenceNumber = format_confinement_reference($record['confinement_id'], $record['created_at']);
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
    <title>Discharge Summary - <?php echo htmlspecialchars($patientName); ?></title>
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
            <div style="font-size: 13px; color: #555;">Discharge Summary</div>
        </div>
        <div class="ref">
            Ref: <?php echo htmlspecialchars($referenceNumber); ?><br>
            <?php echo htmlspecialchars(date("M j, Y", strtotime($record['discharge_date']))); ?>
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
            <div class="label">Room</div>
            <?php echo htmlspecialchars($record['room_location'] ?? 'Not on file'); ?>
        </div>
        <div>
            <div class="label">Date Confined</div>
            <?php echo htmlspecialchars(date("M j, Y", strtotime($record['date_confined']))); ?>
        </div>
        <div>
            <div class="label">Discharge Status</div>
            <?php echo htmlspecialchars($dischargeLabels[$record['discharge_status']] ?? ucfirst((string) $record['discharge_status'])); ?>
        </div>
        <?php if (!empty($record['follow_up_date'])): ?>
            <div>
                <div class="label">Follow-up Date</div>
                <?php echo htmlspecialchars(date("M j, Y", strtotime($record['follow_up_date']))); ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($record['final_diagnosis'])): ?>
        <section>
            <h2>Final Diagnosis</h2>
            <p><?php echo nl2br(htmlspecialchars($record['final_diagnosis'])); ?></p>
        </section>
    <?php endif; ?>

    <?php if (count($medications) > 0): ?>
        <section>
            <h2>Medications to Continue</h2>
            <table class="rx-table">
                <thead>
                    <tr>
                        <th>Medicine</th>
                        <th>Quantity</th>
                        <th>Instructions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($medications as $m): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($m['medicine_name']); ?></td>
                            <td><?php echo htmlspecialchars($m['quantity'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($m['instructions'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    <?php if (!empty($record['discharge_medications'])): ?>
        <section>
            <h2>Additional Medication Notes</h2>
            <p><?php echo nl2br(htmlspecialchars($record['discharge_medications'])); ?></p>
        </section>
    <?php endif; ?>

    <?php if (!empty($record['follow_up_instructions'])): ?>
        <section>
            <h2>Follow-up Instructions</h2>
            <p><?php echo nl2br(htmlspecialchars($record['follow_up_instructions'])); ?></p>
        </section>
    <?php endif; ?>

    <footer class="doc-footer">
        <span>Generated <?php echo date("M j, Y g:i A"); ?></span>
    </footer>

    <script>
        // Auto-open the print dialog on load - same as print-consultation.php.
        window.addEventListener('load', function() {
            window.print();
        });
    </script>

</body>

</html>
