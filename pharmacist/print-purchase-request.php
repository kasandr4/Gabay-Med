<?php
// pharmacist/print-purchase-request.php
//
// Standalone printable view of a single Purchase Request, laid out to
// match the official PGOM Purchase Request form (see
// PO_and_PR_Format.xlsx) field-for-field:
//   - Provincial seal + agency letterhead
//   - Department / Section block, P.R. No. / SAI No. / OBR No. + Date
//   - Item table with Estimated Unit Cost / Estimated Cost columns
//   - Purpose line
//   - 3-column signature block: Requested by (Chief of Hospital),
//     Cash Availability (Provincial Treasurer), Approved by
//     (Provincial Governor)
//
// All three signatures are wet-ink: the printed PR is forwarded manually
// (outside GabayMed) and signed there. GabayMed records who approved it
// (the Head Pharmacist, see the 'approve' action in
// purchase-request-actions.php) in the "GabayMed Record" box at the
// bottom, not in a signature slot. We deliberately don't hardcode a
// person's name into any of the blanks.
//
// The item table's Estimated Unit Cost / Estimated Cost columns come
// from purchase_request_items.unit_price_estimated (added alongside
// this printable view). Items saved before that column existed will
// still have it NULL, so those rows -- and PRs entirely made up of
// them -- fall back to an em dash, same as print-purchase-order.php
// already does for its own unpriced rows.

require_once '../includes/auth_guard.php';
// Readable by the pharmacist, and by the inventory Staff member who
// submitted the PR once it has been approved (Staff/Pharmacy forwards
// the printed PR manually - there is no Capitol account).
require_role(['pharmacist', 'staff']);
if ($_SESSION['role'] === 'staff') {
    require_staff_type('inventory');
}
require_once '../config/db.php';

$prId = (int) ($_GET['pr_id'] ?? 0);
if ($prId <= 0) {
    http_response_code(400);
    exit('Missing pr_id.');
}

$stmt = $conn->prepare(
    "SELECT pr.pr_id, pr.pr_no, pr.purpose, pr.notes, pr.status, pr.created_at,
            pr.requested_by, pr.approved_by_name, pr.approved_at,
            pr.rejected_at, pr.rejection_reason,
            CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS requested_by_name
     FROM purchase_requests pr
     LEFT JOIN users u ON u.user_id = pr.requested_by
     WHERE pr.pr_id = ?"
);
$stmt->bind_param('i', $prId);
$stmt->execute();
$pr = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$pr) {
    http_response_code(404);
    exit('Purchase request not found.');
}

// Staff may only print their own PRs, and only once approved.
if ($_SESSION['role'] === 'staff') {
    $approvedStatuses = ['approved', 'sent_to_capitol', 'converted'];
    if ((int) $pr['requested_by'] !== (int) $_SESSION['user_id'] || !in_array($pr['status'], $approvedStatuses, true)) {
        http_response_code(403);
        exit('You can only print your own approved purchase requests.');
    }
}

$itemsStmt = $conn->prepare(
    'SELECT generic_name, brand, strength, unit, qty_requested, unit_price_estimated, funding_source, notes
     FROM purchase_request_items WHERE pr_id = ? ORDER BY pri_id ASC'
);
$itemsStmt->bind_param('i', $prId);
$itemsStmt->execute();
$items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$itemsStmt->close();

$totalAmount = 0.0;
foreach ($items as $item) {
    if ($item['unit_price_estimated'] !== null) {
        $totalAmount += (float) $item['unit_price_estimated'] * (int) $item['qty_requested'];
    }
}

function pri_item_description(array $item): string
{
    $desc = $item['generic_name'];
    // Skip the strength when the name already spells it out (e.g. the name
    // "Acetylcysteine 200mg (...) Sachet" with strength "200mg" pulled from
    // it by the PR form) so the printed description doesn't repeat it.
    if (!empty($item['strength']) && stripos($desc, $item['strength']) === false) {
        $desc .= ' ' . $item['strength'];
    }
    if (!empty($item['brand'])) {
        $desc .= ' (' . $item['brand'] . ')';
    }
    return $desc;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Request <?php echo htmlspecialchars($pr['pr_no']); ?> - GabayMed</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Times New Roman', Times, serif;
            color: #000;
            max-width: 800px;
            margin: 0 auto;
            padding: 36px 32px;
            font-size: 13px;
            line-height: 1.4;
        }

        .print-note {
            background: #FFF7E6;
            border: 1px solid #F0C36D;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 12.5px;
            margin-bottom: 22px;
            font-family: Arial, sans-serif;
        }

        .doc-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 14px;
            text-align: center;
            margin-bottom: 6px;
        }

        .doc-header img {
            width: 58px;
            height: 58px;
            flex-shrink: 0;
        }

        .doc-header .agency {
            font-weight: bold;
            font-size: 14px;
        }

        .doc-title {
            text-align: center;
            font-weight: bold;
            font-size: 15px;
            text-decoration: underline;
            margin: 18px 0 14px;
        }

        table.meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        table.meta-table td {
            vertical-align: top;
            padding: 2px 4px;
        }

        .meta-table .dept-name {
            font-weight: bold;
        }

        .meta-table .no-cell {
            white-space: nowrap;
        }

        .meta-table .date-cell {
            white-space: nowrap;
            width: 1%;
        }

        .fill-line {
            border-bottom: 1px dotted #000;
            display: inline-block;
            min-width: 130px;
        }

        table.item-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }

        table.item-table th,
        table.item-table td {
            border: 1px solid #000;
            padding: 4px 6px;
            font-size: 12.5px;
        }

        table.item-table th {
            text-align: center;
            font-weight: bold;
        }

        table.item-table td.num {
            text-align: right;
        }

        table.item-table td.center {
            text-align: center;
        }

        .cost-blank {
            color: #999;
        }

        tr.total-row td {
            font-weight: bold;
            border-top: 2px solid #000;
        }

        .purpose-row {
            margin: 10px 0;
        }

        .tracking-box {
            margin-top: 16px;
            border: 1px solid #999;
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 11.5px;
            font-family: Arial, sans-serif;
            color: #333;
        }

        .tracking-box .tracking-title {
            font-weight: bold;
            margin-bottom: 4px;
        }

        .tracking-box .tracking-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
        }

        .tracking-box .tracking-row+.tracking-row {
            margin-top: 4px;
        }

        .tracking-box .reason {
            margin-top: 4px;
            font-style: italic;
        }

        .sign-grid {
            width: 100%;
            border-collapse: collapse;
            margin-top: 26px;
        }

        .sign-grid td {
            width: 33.33%;
            padding: 4px 10px 0;
            vertical-align: top;
            text-align: center;
        }

        .sign-grid .sign-label {
            font-size: 11.5px;
            text-align: left;
            margin-bottom: 30px;
        }

        .sign-grid .sign-line {
            border-top: 1px solid #000;
            padding-top: 3px;
            font-size: 12px;
            min-height: 14px;
        }

        .sign-grid .designation {
            font-size: 11px;
            color: #333;
        }

        .doc-footer {
            margin-top: 30px;
            font-size: 10.5px;
            color: #444;
            display: flex;
            justify-content: space-between;
        }

        @media print {
            body {
                padding: 0;
            }

            .print-note {
                display: none;
            }
        }
    </style>
</head>

<body>

    <div class="print-note">
        This is a print view generated from GabayMed, laid out to match the official PGOM Purchase Request form. SAI No., OBR No., and the Cash Availability / Approved by signatures are completed by hand / by the relevant office &mdash; the system does not track those. Estimated Unit Cost and Estimated Cost print blank for any item saved without an estimated unit price.
    </div>

    <div class="doc-header">
        <img src="../assets/img/omcdh-seal.jpg" alt="Provincial seal">
        <div>
            <div class="agency">PROVINCIAL GOVERNMENT OF ORIENTAL MINDORO</div>
            <div>Capitol Complex, Camilmil, Calapan City 5200, Oriental Mindoro</div>
        </div>
    </div>

    <div class="doc-title">PURCHASE REQUEST</div>

    <table class="meta-table">
        <tr>
            <td style="width:55%;" rowspan="3">
                Department<br>
                <span class="dept-name">ORIENTAL MINDORO CENTRAL DISTRICT HOSPITAL</span><br>
                Section&nbsp;&nbsp;&nbsp;&nbsp;Pharmacy
            </td>
            <td class="no-cell">
                P.R. No.: <span class="fill-line"><?php echo htmlspecialchars($pr['pr_no']); ?></span>
            </td>
            <td class="date-cell">
                Date: <?php echo htmlspecialchars(date('m/d/Y', strtotime($pr['created_at']))); ?>
            </td>
        </tr>
        <tr>
            <td class="no-cell">
                SAI No.: <span class="fill-line">&nbsp;</span>
            </td>
            <td class="date-cell">
                Date: <span class="fill-line" style="min-width:70px;">&nbsp;</span>
            </td>
        </tr>
        <tr>
            <td class="no-cell">
                OBR No.: <span class="fill-line">&nbsp;</span>
            </td>
            <td class="date-cell">
                Date: <span class="fill-line" style="min-width:70px;">&nbsp;</span>
            </td>
        </tr>
    </table>

    <table class="item-table">
        <thead>
            <tr>
                <th style="width:6%;">Item No.</th>
                <th style="width:9%;">Quantity</th>
                <th style="width:10%;">Unit of Issue</th>
                <th>Item Description</th>
                <th style="width:14%;">Estimated Unit Cost</th>
                <th style="width:14%;">Estimated Cost</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $i => $item):
                $unitCost = $item['unit_price_estimated'];
                $lineCost = $unitCost !== null ? (float) $unitCost * (int) $item['qty_requested'] : null;
            ?>
                <tr>
                    <td class="center"><?php echo $i + 1; ?></td>
                    <td class="num"><?php echo (int) $item['qty_requested']; ?></td>
                    <td class="center"><?php echo htmlspecialchars($item['unit'] ?: '—'); ?></td>
                    <td><?php echo htmlspecialchars(pri_item_description($item)); ?></td>
                    <?php if ($unitCost !== null): ?>
                        <td class="num"><?php echo number_format((float) $unitCost, 2); ?></td>
                        <td class="num"><?php echo number_format($lineCost, 2); ?></td>
                    <?php else: ?>
                        <td class="num cost-blank">&mdash;</td>
                        <td class="num cost-blank">&mdash;</td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="4" style="text-align:right;">TOTAL AMOUNT</td>
                <td></td>
                <td class="num"><?php echo number_format($totalAmount, 2); ?></td>
            </tr>
        </tbody>
    </table>

    <?php if ($pr['purpose']): ?>
        <div class="purpose-row">Purpose: <?php echo htmlspecialchars($pr['purpose']); ?></div>
    <?php endif; ?>

    <table class="sign-grid">
        <tr>
            <td>
                <div class="sign-label">Signature</div>
                <div class="sign-line">&nbsp;</div>
                <div class="designation">Requested by<br>Chief of Hospital</div>
            </td>
            <td>
                <div class="sign-label">Signature</div>
                <div class="sign-line">&nbsp;</div>
                <div class="designation">Cash Availability<br>Actg. Provincial Treasurer</div>
            </td>
            <td>
                <div class="sign-label">Signature</div>
                <div class="sign-line">&nbsp;</div>
                <div class="designation">Approved by<br>Provincial Governor</div>
            </td>
        </tr>
    </table>

    <?php if (in_array($pr['status'], ['approved', 'rejected', 'sent_to_capitol', 'converted'], true)): ?>
        <div class="tracking-box">
            <div class="tracking-title">GabayMed Record (not part of the official form)</div>
            <?php if ($pr['approved_at']): ?>
                <div class="tracking-row">
                    <span>Approved: <?php echo htmlspecialchars(date('m/d/Y', strtotime($pr['approved_at']))); ?></span>
                    <span>Approved by (Head Pharmacist): <?php echo htmlspecialchars($pr['approved_by_name'] ?: '—'); ?></span>
                </div>
            <?php endif; ?>
            <?php if ($pr['status'] === 'rejected'): ?>
                <div class="tracking-row">
                    <span>Rejected: <?php echo $pr['rejected_at'] ? htmlspecialchars(date('m/d/Y', strtotime($pr['rejected_at']))) : '—'; ?></span>
                </div>
                <div class="reason">Reason: <?php echo htmlspecialchars($pr['rejection_reason'] ?: '—'); ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="doc-footer">
        <span>Generated from GabayMed &middot; <?php echo htmlspecialchars($pr['pr_no']); ?></span>
        <span>Printed <?php echo date('M j, Y g:i A'); ?></span>
    </div>

    <script>
        window.addEventListener('load', function() {
            window.print();
        });
    </script>

</body>

</html>