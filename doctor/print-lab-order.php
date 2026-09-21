<?php
// doctor/print-lab-order.php
// Printable lab order slip: the doctor gives this to the patient, who
// carries it physically to the laboratory. There is no digital lab
// module/portal yet, so this is the entire "handoff" - same paper-based
// pattern as print-consultation.php's visit summary, just for lab tests
// instead of findings/prescriptions.
//
// Scoped to the logged-in doctor's own orders only - same pattern as
// print-consultation.php's "WHERE c.consultation_id = ? AND c.doctor_id = ?".
//
// Two entry points now (see 022_confinement_orders_and_discharge.sql):
// ?consultation_id=  - the original outpatient path, unchanged below.
// ?confinement_id=&ids= - a confinement has no consultation row to join
//   against, so this path looks up patient/doctor/room via `confinements`
//   instead, and prints only the specific lab_order_ids just submitted
//   (a confinement can span many separate lab-order submissions over
//   several days, unlike a consultation which is naturally one visit).

require_once '../includes/auth_guard.php';
require_role('doctor');
require_once '../config/db.php';
require_once '../includes/reference_number.php';

$doctorId = (int) $_SESSION['user_id'];
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
         WHERE c.confinement_id = ? AND c.attending_doctor_id = ?
         LIMIT 1"
    );
    $stmt->bind_param("ii", $confinementId, $doctorId);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$record) {
        http_response_code(404);
        exit('Confinement record not found, or does not belong to you.');
    }

    // ids= is a comma list of specific lab_order_id values from the
    // submission that just happened (see confinement-lab-order-process.php).
    // Falls back to every order ever placed for this confinement if ids
    // is missing/empty, so a stale or hand-edited link still shows
    // something meaningful rather than a hard 404.
    $idsParam = trim($_GET['ids'] ?? '');
    $ids = array_filter(array_map('intval', explode(',', $idsParam)));

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = 'i' . str_repeat('i', count($ids));
        $stmt = $conn->prepare(
            "SELECT test_name, notes FROM lab_orders
             WHERE confinement_id = ? AND lab_order_id IN ($placeholders)
             ORDER BY lab_order_id ASC"
        );
        $stmt->bind_param($types, $confinementId, ...$ids);
    } else {
        $stmt = $conn->prepare(
            "SELECT test_name, notes FROM lab_orders WHERE confinement_id = ? ORDER BY lab_order_id ASC"
        );
        $stmt->bind_param("i", $confinementId);
    }
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
    // --- Consultation path (unchanged) ----------------------------------
    // Same LEFT JOIN defensiveness as print-consultation.php: every patient
    // (guest or active account) is a row in `users`, so this does not require
    // an online account, just a patient record - which every consultation
    // already has via consultations.patient_id.
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