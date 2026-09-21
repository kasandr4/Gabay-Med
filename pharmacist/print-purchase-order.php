<?php
// pharmacist/print-purchase-order.php
//
// Same idea as print-purchase-request.php, but for a Purchase Order.
// Unlike PR items, purchase_order_items DOES carry unit_price_estimated,
// so the cost columns here are real, computed figures, not blanks.

require_once '../includes/auth_guard.php';
// Readable by both the pharmacist (who receives/reconciles it) and
// Capitol (who creates it) - see 027_capitol_role_and_po_handoff.sql.
require_role(['pharmacist', 'capitol']);
require_once '../config/db.php';

$poId = (int) ($_GET['po_id'] ?? 0);
if ($poId <= 0) {
    http_response_code(400);
    exit('Missing po_id.');
}

$stmt = $conn->prepare(
    "SELECT po.po_id, po.po_no, po.supplier_name, po.order_date, po.status, po.created_at, pr.pr_no,
            CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS created_by_name
     FROM purchase_orders po
     LEFT JOIN purchase_requests pr ON pr.pr_id = po.pr_id
     LEFT JOIN users u ON u.user_id = po.created_by
     WHERE po.po_id = ?"
);
$stmt->bind_param('i', $poId);
$stmt->execute();
$po = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$po) {
    http_response_code(404);
    exit('Purchase order not found.');
}

$itemsStmt = $conn->prepare(
    'SELECT generic_name, brand, strength, unit, qty_ordered, unit_price_estimated
     FROM purchase_order_items WHERE po_id = ? ORDER BY poi_id ASC'
);
$itemsStmt->bind_param('i', $poId);
$itemsStmt->execute();
$items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$itemsStmt->close();

$totalAmount = 0.0;
foreach ($items as $item) {
    if ($item['unit_price_estimated'] !== null) {
        $totalAmount += (float) $item['unit_price_estimated'] * (int) $item['qty_ordered'];
    }
}

function po_item_description(array $item): string
{
    $desc = $item['generic_name'];
    if (!empty($item['strength'])) {
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
    <title>Purchase Order <?php echo htmlspecialchars($po['po_no']); ?> - GabayMed</title>
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
            text-align: center;
            margin-bottom: 6px;
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

        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }

        .meta-table td {
            vertical-align: top;
            padding: 2px 4px;
        }

        .meta-table .dept-name {
            font-weight: bold;
        }

        .fill-line {
            border-bottom: 1px dotted #000;
            display: inline-block;
            min-width: 140px;
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
            margin-top: 10px;
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
        This is a print view generated from GabayMed, laid out to match the official PGOM Purchase Request/Order form. SAI No., OBR No., and the Cash Availability / Approved by signatures are completed by hand / by the relevant office &mdash; the system does not track those.
    </div>

    <div class="doc-header">
        <div class="agency">PROVINCIAL GOVERNMENT OF ORIENTAL MINDORO</div>
        <div>Capitol Complex, Camilmil, Calapan City 5200, Oriental Mindoro</div>
    </div>

    <div class="doc-title">PURCHASE ORDER</div>

    <table class="meta-table">
        <tr>
            <td style="width:55%;">
                Department<br>
                <span class="dept-name">ORIENTAL MINDORO CENTRAL DISTRICT HOSPITAL</span><br>
                Section&nbsp;&nbsp;&nbsp;&nbsp;Pharmacy<br><br>
                Supplier: <span class="fill-line"><?php echo htmlspecialchars($po['supplier_name'] ?: ''); ?></span>
            </td>
            <td style="width:45%;">
                P.O. No.: <span class="fill-line"><?php echo htmlspecialchars($po['po_no']); ?></span><br><br>
                <?php if ($po['pr_no']): ?>
                    Ref. P.R. No.: <span class="fill-line"><?php echo htmlspecialchars($po['pr_no']); ?></span><br><br>
                <?php endif; ?>
                SAI No.: <span class="fill-line">&nbsp;</span><br><br>
                OBR No.: <span class="fill-line">&nbsp;</span>
            </td>
        </tr>
    </table>
    <div style="text-align:right;margin-bottom:10px;">
        Date: <?php echo htmlspecialchars(date('m/d/Y', strtotime($po['order_date'] ?: $po['created_at']))); ?>
    </div>

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
                $lineCost = $unitCost !== null ? (float) $unitCost * (int) $item['qty_ordered'] : null;
            ?>
                <tr>
                    <td class="center"><?php echo $i + 1; ?></td>
                    <td class="num"><?php echo (int) $item['qty_ordered']; ?></td>
                    <td class="center"><?php echo htmlspecialchars($item['unit']); ?></td>
                    <td><?php echo htmlspecialchars(po_item_description($item)); ?></td>
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

    <table class="sign-grid">
        <tr>
            <td>
                <div class="sign-label">Signature</div>
                <div class="sign-line"><?php echo htmlspecialchars(trim($po['created_by_name'])) ?: '—'; ?></div>
                <div class="designation">Prepared by</div>
            </td>
            <td>
                <div class="sign-label">Signature</div>
                <div class="sign-line">&nbsp;</div>
                <div class="designation">Cash Availability</div>
            </td>
            <td>
                <div class="sign-label">Signature</div>
                <div class="sign-line">&nbsp;</div>
                <div class="designation">Approved by</div>
            </td>
        </tr>
    </table>

    <div class="doc-footer">
        <span>Generated from GabayMed &middot; <?php echo htmlspecialchars($po['po_no']); ?></span>
        <span>Printed <?php echo date('M j, Y g:i A'); ?></span>
    </div>

    <script>
        window.addEventListener('load', function() {
            window.print();
        });
    </script>

</body>

</html>