<?php
// patient/print-lab-order.php
// Patient-facing reprint of doctor/print-lab-order.php's laboratory slip.
// Same content/layout, scoped by the patient's own session ID instead of
// doctor ownership - lets a patient who lost their original paper
// self-serve a reprint instead of returning to the doctor's office.
//
// Same two entry points as the doctor version:
// ?consultation_id=  - one outpatient visit's tests.
// ?confinement_id=   - every test ordered across a whole inpatient stay
//   (patient/lab-orders.php already groups confinement-based orders this
//   way - "whole stay", not per-submission - so no ids= filter is used
//   here; a patient reprinting from that page always wants everything in
//   that group).

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

if ($confinementId > 0) {
    // --- Confinement path ---------------------------------------------
    $stmt = $conn->prepare(
        "SELECT c.confinement_id, c.date_confined, c.room_location,
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
        exit('Confinement record not found, or does not belong to you.');
    }

    $stmt = $conn->prepare(
        "SELECT test_name, notes FROM lab_orders WHERE confinement_id = ? ORDER BY lab_order_id ASC"
    );
    $stmt->bind_param("i", $confinementId);
    $stmt->execute();
    $labOrders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($labOrders)) {
        http_response_code(404);
        exit('No lab tests were ordered for this confinement.');
    }

    $referenceNumber = 'CONF-' . str_pad((string) $confinementId, 6, '0', STR_PAD_LEFT);
    $age = $record['birthdate'] ? floor((time() - strtotime($record['birthdate'])) / 31556926) : null;
    $patientName = trim(($record['patient_first'] ?? '') . ' ' . ($record['patient_last'] ?? ''));
    if ($patientName === '') {
        $patientName = 'Patient record unavailable';
    }
    $orderedDate = date("M j, Y", strtotime($record['date_confined']));
    $departmentOrRoom = 'Inpatient' . ($record['room_location'] ? ' — ' . $record['room_location'] : '');
    $doctorFirst = $record['doctor_first'];
    $doctorLast = $record['doctor_last'];
} else {
    // --- Consultation path ----------------------------------------------
    $stmt = $conn->prepare(
        "SELECT c.consultation_id, c.created_at AS consultation_created_at,
                a.appointment_id, a.created_at AS appointment_created_at, a.slot_start,
                pat.first_name AS patient_first, pat.last_name AS patient_last, pat.birthdate, pat.sex,
                doc.first_name AS doctor_first, doc.last_name AS doctor_last,
                d.department_name
         FROM consultations c
         JOIN appointments a ON a.appointment_id = c.appointment_id
         LEFT JOIN users pat ON pat.user_id = c.patient_id
         LEFT JOIN users doc ON doc.user_id = c.doctor_id
         JOIN departments d ON d.department_id = a.department_id
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
        "SELECT test_name, notes FROM lab_orders WHERE consultation_id = ? ORDER BY lab_order_id ASC"
    );
    $stmt->bind_param("i", $consultationId);
    $stmt->execute();
    $labOrders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($labOrders)) {
        http_response_code(404);
        exit('No lab tests were ordered for this consultation.');
    }

    $referenceNumber = format_appointment_reference($record['appointment_id'], $record['appointment_created_at']);
    $age = $record['birthdate'] ? floor((time() - strtotime($record['birthdate'])) / 31556926) : null;
    $patientName = trim(($record['patient_first'] ?? '') . ' ' . ($record['patient_last'] ?? ''));
    if ($patientName === '') {
        $patientName = 'Patient record unavailable';
    }
    $orderedDate = date("M j, Y", strtotime($record['slot_start']));
    $departmentOrRoom = $record['department_name'];
    $doctorFirst = $record['doctor_first'];
    $doctorLast = $record['doctor_last'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laboratory Order - <?php echo htmlspecialchars($patientName); ?></title>
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

        .lab-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .lab-table th,
        .lab-table td {
            text-align: left;
            padding: 6px 8px;
            border-bottom: 1px solid #eee;
        }

        .lab-table th {
            font-size: 12px;
            text-transform: uppercase;
            color: #555;
        }

        .signature-block {
            margin-top: 40px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        .signature-line {
            border-top: 1px solid #1a1a1a;
            padding-top: 6px;
            font-size: 12px;
            color: #555;
        }

        footer.doc-footer {
            margin-top: 48px;
            padding-top: 16px;
            border-top: 1px solid #ccc;
            font-size: 12px;
            color: #777;
        }

        .reprint-note {
            background: #FFF7E6;
            border: 1px solid #F0C36D;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 12.5px;
            margin-bottom: 24px;
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
        This is a reprint of your laboratory order, generated from your GabayMed account.
    </div>

    <header class="doc-header">
        <div>
            <h1>GabayMed</h1>
            <div style="font-size: 13px; color: #555;">Laboratory Order</div>
        </div>
        <div class="ref">
            Ref: <?php echo htmlspecialchars($referenceNumber); ?><br>
            <?php echo htmlspecialchars($orderedDate); ?>
        </div>
    </header>

    <div class="meta-grid">
        <div>
            <div class="label">Patient</div>
            <?php echo htmlspecialchars($patientName); ?>
            <?php if ($age !== null): ?> (<?php echo (int) $age; ?> y/o<?php echo !empty($record['sex']) ? ', ' . ucfirst($record['sex']) : ''; ?>)<?php endif; ?>
        </div>
        <div>
            <div class="label">Ordering Physician</div>
            Dr. <?php echo htmlspecialchars(trim(($doctorFirst ?? '') . ' ' . ($doctorLast ?? ''))); ?>
        </div>
        <div>
            <div class="label"><?php echo $confinementId > 0 ? 'Room / Location' : 'Department'; ?></div>
            <?php echo htmlspecialchars($departmentOrRoom); ?>
        </div>
        <div>
            <div class="label">Date Ordered</div>
            <?php echo htmlspecialchars($orderedDate); ?>
        </div>
    </div>

    <section>
        <h2>Tests Requested</h2>
        <table class="lab-table">
            <thead>
                <tr>
                    <th>Test</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($labOrders as $t): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($t['test_name']); ?></td>
                        <td><?php echo htmlspecialchars($t['notes'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <p style="font-size: 13px; color: #555;">
        Please present this slip at the Laboratory. Results should be returned to the ordering physician above.
    </p>

    <div class="signature-block">
        <div class="signature-line">Physician's Signature</div>
        <div class="signature-line">Date Received by Laboratory</div>
    </div>

    <footer class="doc-footer">
        <span>Reprinted <?php echo date("M j, Y g:i A"); ?></span>
    </footer>

    <script>
        window.addEventListener('load', function() {
            window.print();
        });
    </script>

</body>

</html>