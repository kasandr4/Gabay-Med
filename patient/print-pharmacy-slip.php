<?php
// patient/print-pharmacy-slip.php
// Patient-facing reprint of doctor/print-pharmacy-slip.php's Rx slip -
// same content and layout, just scoped by the patient's own session ID
// instead of doctor ownership.
//
// UPDATED 2026-09-17: staff/dispense-stock.php (the page this slip used
// to be required for - staff verified a prescription by the reference
// number printed here) has been removed. Dispensing now happens from
// staff/prescription-queue.php directly, with no reference number for a
// patient to carry in or staff to type - see that file's header comment.
// This page is kept as a personal-copy convenience only (a patient's own
// record of what was prescribed) - printing it is no longer a required
// step to get medicine dispensed.
//
// Supports two sources, same "one shared presentation, two data sources"
// pattern as prescriptions.php's $prescriptionGroups/$dischargeGroups
// merge - an outpatient consultation (?consultation_id=) or a
// confinement's take-home discharge medications (?confinement_id=).
// Exactly one must be given.

require_once '../includes/auth_guard.php';
require_role('patient');
require_once '../config/db.php';
require_once '../includes/reference_number.php';

$patientId = (int) $_SESSION['user_id'];
$consultationId = isset($_GET['consultation_id']) ? (int) $_GET['consultation_id'] : 0;
$confinementId = isset($_GET['confinement_id']) ? (int) $_GET['confinement_id'] : 0;

if ($consultationId <= 0 && $confinementId <= 0) {
    http_response_code(400);
    exit('Missing consultation_id or confinement_id.');
}

// Ownership check is patient_id = session's own user_id ONLY - same
// guarantee as the rest of the patient portal.
if ($confinementId > 0) {
    $stmt = $conn->prepare(
        "SELECT c.confinement_id, c.created_at, c.discharge_date,
                pat.first_name AS patient_first, pat.last_name AS patient_last, pat.birthdate, pat.sex,
                doc.first_name AS doctor_first, doc.last_name AS doctor_last
         FROM confinements c
         LEFT JOIN users pat ON pat.user_id = c.patient_id
         LEFT JOIN users doc ON doc.user_id = c.attending_doctor_id
         WHERE c.confinement_id = ? AND c.patient_id = ?
         LIMIT 1"
    );
    $stmt->bind_param("ii", $confinementId, $patientId);
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
    $prescriptions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (count($prescriptions) === 0) {
        http_response_code(404);
        exit('No discharge medications were recorded for this confinement.');
    }

    $referenceNumber = format_confinement_reference($record['confinement_id'], $record['created_at']);
    $slipDate = $record['discharge_date'];
} else {
    $stmt = $conn->prepare(
        "SELECT c.consultation_id, a.appointment_id, a.created_at AS appointment_created_at, a.slot_start,
                pat.first_name AS patient_first, pat.last_name AS patient_last, pat.birthdate, pat.sex,
                doc.first_name AS doctor_first, doc.last_name AS doctor_last
         FROM consultations c
         JOIN appointments a ON a.appointment_id = c.appointment_id
         LEFT JOIN users pat ON pat.user_id = c.patient_id
         LEFT JOIN users doc ON doc.user_id = c.doctor_id
         WHERE c.consultation_id = ? AND c.patient_id = ?
         LIMIT 1"
    );
    $stmt->bind_param("ii", $consultationId, $patientId);
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

    if (count($prescriptions) === 0) {
        http_response_code(404);
        exit('No prescription was issued for this consultation.');
    }

    $referenceNumber = format_appointment_reference($record['appointment_id'], $record['appointment_created_at']);
    $slipDate = $record['slot_start'];
}

$age = $record['birthdate'] ? floor((time() - strtotime($record['birthdate'])) / 31556926) : null;
$patientName = trim(($record['patient_first'] ?? '') . ' ' . ($record['patient_last'] ?? ''));
if ($patientName === '') {
    $patientName = 'Patient record unavailable';
}
$doctorName = trim(($record['doctor_first'] ?? '') . ' ' . ($record['doctor_last'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacy Slip - <?php echo htmlspecialchars($patientName); ?></title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Courier New', monospace;
            color: #1a1a1a;
            max-width: 480px;
            margin: 0 auto;
            padding: 32px 28px;
            line-height: 1.5;
        }

        header.slip-header {
            text-align: center;
            border-bottom: 2px solid #1a1a1a;
            padding-bottom: 10px;
            margin-bottom: 18px;
        }

        header.slip-header h1 {
            margin: 0;
            font-size: 17px;
            letter-spacing: 0.02em;
        }

        header.slip-header .subtitle {
            font-size: 12px;
            color: #555;
            margin-top: 2px;
        }

        .patient-fields {
            font-size: 13px;
            margin-bottom: 4px;
        }

        .patient-fields div {
            margin-bottom: 6px;
            border-bottom: 1px dotted #999;
            padding-bottom: 3px;
        }

        .patient-fields span.label {
            font-weight: bold;
            margin-right: 6px;
        }

        .pharmacy-label {
            text-align: center;
            font-weight: bold;
            letter-spacing: 0.15em;
            font-size: 13px;
            margin: 18px 0 6px;
        }

        .rx-mark {
            font-size: 22px;
            font-weight: bold;
            margin: 10px 0 10px;
        }

        ol.rx-list {
            list-style: none;
            counter-reset: rx;
            margin: 0 0 24px;
            padding: 0;
            font-size: 14px;
        }

        ol.rx-list li {
            counter-increment: rx;
            display: flex;
            align-items: baseline;
            gap: 6px;
            padding: 5px 0;
            border-bottom: 1px dotted #ccc;
        }

        ol.rx-list li::before {
            content: counter(rx) ".";
            flex: 0 0 auto;
        }

        ol.rx-list li .rx-item-name {
            flex: 1 1 auto;
        }

        ol.rx-list li .rx-item-instructions {
            display: block;
            font-size: 11.5px;
            color: #555;
            font-family: Georgia, serif;
        }

        ol.rx-list li .rx-item-qty {
            flex: 0 0 auto;
            min-width: 32px;
            text-align: right;
            border-bottom: 1px solid #1a1a1a;
        }

        footer.slip-footer {
            margin-top: 40px;
            font-size: 13px;
        }

        footer.slip-footer .signature-line {
            display: inline-block;
            border-bottom: 1px solid #1a1a1a;
            min-width: 220px;
            margin-left: 6px;
        }

        .ref-note {
            margin-top: 28px;
            font-size: 11px;
            color: #777;
            text-align: right;
        }

        .reprint-note {
            background: #FFF7E6;
            border: 1px solid #F0C36D;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 11.5px;
            margin-bottom: 20px;
            font-family: Georgia, serif;
        }

        @media print {
            body {
                padding: 0;
            }

            .reprint-note {
                display: none;
            }
        }
    </style>
</head>

<body>

    <div class="reprint-note">
        This is a copy of your pharmacy slip, generated from your GabayMed account, for your own records.
    </div>

    <header class="slip-header">
        <h1>GabayMed</h1>
        <div class="subtitle">Pharmacy Prescription Slip</div>
    </header>

    <div class="patient-fields">
        <div><span class="label">NAME:</span> <?php echo htmlspecialchars($patientName); ?></div>
        <div><span class="label">AGE:</span> <?php echo $age !== null ? (int) $age : ''; ?><?php echo $record['sex'] ? ' / ' . ucfirst($record['sex']) : ''; ?>
        </div>
        <div><span class="label">DATE:</span> <?php echo htmlspecialchars(date("M j, Y", strtotime($slipDate))); ?></div>
    </div>

    <div class="pharmacy-label">PHARMACY</div>
    <div class="rx-mark">℞</div>

    <ol class="rx-list">
        <?php foreach ($prescriptions as $p): ?>
            <li>
                <span class="rx-item-name">
                    <?php echo htmlspecialchars($p['medicine_name']); ?>
                    <?php if (!empty($p['instructions'])): ?>
                        <span class="rx-item-instructions"><?php echo htmlspecialchars($p['instructions']); ?></span>
                    <?php endif; ?>
                </span>
                <span class="rx-item-qty">#<?php echo htmlspecialchars($p['quantity'] ?? ''); ?></span>
            </li>
        <?php endforeach; ?>
    </ol>

    <footer class="slip-footer">
        Prepared by: Dr. <span class="signature-line"><?php echo htmlspecialchars($doctorName); ?></span>
    </footer>

    <div class="ref-note">Ref: <?php echo htmlspecialchars($referenceNumber); ?></div>

    <script>
        window.addEventListener('load', function() {
            window.print();
        });
    </script>

</body>

</html>