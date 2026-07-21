<?php
// admin/print-purchase-order.php
// Print-friendly Purchase Order handout, generated once a winning
// supplier has been confirmed for a request. Same pattern as
// doctor/print-consultation.php: a bare, self-contained HTML page meant
// to be opened in a new tab and printed, not styled like the app shell.

require_once '../includes/auth_guard.php';
require_role('admin');
require_once '../config/db.php';

$poId = isset($_GET['po_id']) ? (int) $_GET['po_id'] : 0;
if ($poId <= 0) {
    http_response_code(400);
    exit('Missing po_id.');
}

$stmt = $conn->prepare(
    "SELECT po.po_number, po.supplier_name, po.approved_quantity, po.total_cost, po.purchase_date,
            po.expected_delivery, po.status, po.created_at,
            m.name AS medicine_name, m.category, m.unit,
            pr.request_id, pr.priority,
            u.first_name AS requester_first, u.last_name AS requester_last
     FROM purchase_orders po
     JOIN purchase_requests pr ON pr.request_id = po.request_id
     JOIN inventory_medicines m ON m.medicine_id = pr.medicine_id
     JOIN users u ON u.user_id = pr.requested_by
     WHERE po.po_id = ?"
);
$stmt->bind_param("i", $poId);
$stmt->execute();
$po = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$po) {
    http_response_code(404);
    exit('Purchase order not found.');
}

$statusLabels = [
    'po_issued' => 'Purchase Order Issued',
    'awaiting_delivery' => 'Awaiting Delivery',
    'delivered' => 'Delivered',
    'completed' => 'Completed',
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($po['po_number']); ?> - GabayMed Purchase Order</title>
    <style>
        :root {
            --teal: #14b8a6;
            --teal-dark: #0f9c8d;
            --border: #e0e4e8;
            --text: #1c2733;
            --muted: #6b7785;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: "Segoe UI", Roboto, -apple-system, BlinkMacSystemFont, sans-serif;
            color: var(--text);
            max-width: 760px;
            margin: 40px auto;
            padding: 0 24px;
        }

        .po-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 3px solid var(--teal);
            padding-bottom: 16px;
            margin-bottom: 24px;
        }

        .po-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .po-brand img {
            width: 42px;
            height: 42px;
        }

        .po-brand h1 {
            font-size: 20px;
            margin: 0;
        }

        .po-brand p {
            margin: 2px 0 0;
            font-size: 12.5px;
            color: var(--muted);
        }

        .po-number-box {
            text-align: right;
        }

        .po-number-box .po-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--muted);
        }

        .po-number-box .po-value {
            font-size: 18px;
            font-weight: 800;
            color: var(--teal-dark);
        }

        .po-status {
            display: inline-block;
            margin-top: 6px;
            padding: 3px 10px;
            border-radius: 999px;
            background: #eef1f4;
            font-size: 11.5px;
            font-weight: 700;
        }

        .po-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px 24px;
            margin-bottom: 28px;
        }

        .po-item .po-item-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: var(--muted);
            margin-bottom: 3px;
        }

        .po-item .po-item-value {
            font-size: 14.5px;
            font-weight: 600;
        }

        table.po-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }

        table.po-table th,
        table.po-table td {
            border: 1px solid var(--border);
            padding: 10px 12px;
            text-align: left;
            font-size: 13.5px;
        }

        table.po-table th {
            background: #f4f8f9;
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: var(--muted);
        }

        .po-total-row td {
            font-weight: 800;
        }

        .po-footer {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
            font-size: 12.5px;
            color: var(--muted);
        }

        .po-sign-line {
            margin-top: 50px;
            border-top: 1px solid var(--text);
            width: 220px;
            padding-top: 6px;
            font-size: 12px;
        }

        .print-btn {
            display: inline-block;
            margin-bottom: 20px;
            padding: 10px 18px;
            background: var(--teal);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13.5px;
            cursor: pointer;
        }

        @media print {
            .print-btn {
                display: none;
            }

            body {
                margin: 0;
                padding: 0 12px;
            }
        }
    </style>
</head>

<body>

    <button class="print-btn" onclick="window.print()">Print this Purchase Order</button>

    <div class="po-header">
        <div class="po-brand">
            <img src="../logo-icon.png" alt="GabayMed logo">
            <div>
                <h1>GabayMed Hospital</h1>
                <p>Inventory Procurement — Purchase Order</p>
            </div>
        </div>
        <div class="po-number-box">
            <div class="po-label">PO Number</div>
            <div class="po-value"><?php echo htmlspecialchars($po['po_number']); ?></div>
            <span class="po-status"><?php echo htmlspecialchars($statusLabels[$po['status']] ?? $po['status']); ?></span>
        </div>
    </div>

    <div class="po-grid">
        <div class="po-item">
            <div class="po-item-label">Supplier</div>
            <div class="po-item-value"><?php echo htmlspecialchars($po['supplier_name']); ?></div>
        </div>
        <div class="po-item">
            <div class="po-item-label">Requested By</div>
            <div class="po-item-value"><?php echo htmlspecialchars($po['requester_first'] . ' ' . $po['requester_last']); ?></div>
        </div>
        <div class="po-item">
            <div class="po-item-label">Purchase Date</div>
            <div class="po-item-value"><?php echo date('F j, Y', strtotime($po['purchase_date'])); ?></div>
        </div>
        <div class="po-item">
            <div class="po-item-label">Expected Delivery</div>
            <div class="po-item-value"><?php echo date('F j, Y', strtotime($po['expected_delivery'])); ?></div>
        </div>
        <div class="po-item">
            <div class="po-item-label">Related Request</div>
            <div class="po-item-value">#<?php echo (int) $po['request_id']; ?> (<?php echo htmlspecialchars(ucfirst($po['priority'])); ?> priority)</div>
        </div>
        <div class="po-item">
            <div class="po-item-label">Issued On</div>
            <div class="po-item-value"><?php echo date('F j, Y', strtotime($po['created_at'])); ?></div>
        </div>
    </div>

    <table class="po-table">
        <thead>
            <tr>
                <th>Medicine</th>
                <th>Category</th>
                <th>Approved Quantity</th>
                <th>Total Cost</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?php echo htmlspecialchars($po['medicine_name']); ?></td>
                <td><?php echo htmlspecialchars($po['category']); ?></td>
                <td><?php echo (int) $po['approved_quantity']; ?> <?php echo htmlspecialchars($po['unit']); ?></td>
                <td>₱<?php echo number_format((float) $po['total_cost'], 2); ?></td>
            </tr>
            <tr class="po-total-row">
                <td colspan="3" style="text-align:right;">Total</td>
                <td>₱<?php echo number_format((float) $po['total_cost'], 2); ?></td>
            </tr>
        </tbody>
    </table>

    <div class="po-sign-line">Authorized Signature</div>

    <div class="po-footer">
        <span>GabayMed Hospital Management System</span>
        <span>Generated <?php echo date('F j, Y g:i A'); ?></span>
    </div>

</body>

</html>
