<?php
// pharmacist/purchase-orders.php
//
// REWORKED 2026-09-20: this page used to let the pharmacist create a PO
// themselves (see capitol/purchase-orders.php's own header comment for
// the full history). Per the business rule that Capitol - not the
// pharmacist - owns PO creation, this is now read-only: a list of every
// PO Capitol has actually SENT (sent_to_pharmacist_at IS NOT NULL - a
// PO Capitol is still drafting/hasn't sent yet never appears here at
// all, not even as a preview), with Print and Receive Delivery as the
// only actions. Receiving itself (po-receiving.php) is completely
// unchanged - it never created POs, only reconciled deliveries against
// ones that already existed.

require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/csrf.php';

$pharmacistId = (int) $_SESSION['user_id'];

$orders = [];
$listQuery = $conn->query(
    "SELECT po.po_id, po.po_no, po.supplier_name, po.order_date, po.status, po.created_at, po.pr_id,
            po.sent_to_pharmacist_at,
            pr.pr_no,
            (SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.po_id = po.po_id) AS item_count
     FROM purchase_orders po
     LEFT JOIN purchase_requests pr ON pr.pr_id = po.pr_id
     WHERE po.sent_to_pharmacist_at IS NOT NULL
     ORDER BY po.sent_to_pharmacist_at DESC, po.po_id DESC
     LIMIT 100"
);
if ($listQuery) {
    while ($row = $listQuery->fetch_assoc()) {
        $orders[] = $row;
    }
}

$current_page = 'purchase-orders';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Orders - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/pharmacist-dashboard.css">
    <link rel="stylesheet" href="../assets/css/inventory-count.css">
    <link rel="stylesheet" href="../assets/css/procurement.css">
    <style>
        .main-content {
            padding: 28px 30px 32px;
        }
    </style>
</head>

<body>
    <div class="app-shell">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">
            <header class="page-header po-page-header">
                <div>
                    <h1>Purchase Orders</h1>
                    <p class="page-subtitle">What Capitol has sent in response to your requests. Stock is added on the Receiving screen once a delivery actually arrives.</p>
                </div>
            </header>

            <section class="card" style="margin-top: 20px;">
                <div class="card-header">
                    <div>
                        <h2>Purchase Orders From Capitol</h2>
                        <span class="card-subtitle">Most recently sent first</span>
                    </div>
                </div>
                <?php if (empty($orders)): ?>
                    <div class="po-empty">Nothing from Capitol yet. Check Purchase Requests to see what's still pending.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>PO No.</th>
                                    <th>From PR</th>
                                    <th>Supplier</th>
                                    <th>Items</th>
                                    <th>Order Date</th>
                                    <th>Sent</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $po): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($po['po_no']); ?></td>
                                        <td><?php echo $po['pr_no'] ? htmlspecialchars($po['pr_no']) : '—'; ?></td>
                                        <td><?php echo htmlspecialchars($po['supplier_name'] ?: '—'); ?></td>
                                        <td><?php echo (int) $po['item_count']; ?></td>
                                        <td><?php echo $po['order_date'] ? htmlspecialchars(date('M j, Y', strtotime($po['order_date']))) : '—'; ?></td>
                                        <td><?php echo htmlspecialchars(date('M j, Y', strtotime($po['sent_to_pharmacist_at']))); ?></td>
                                        <td><span class="po-status-badge <?php echo htmlspecialchars($po['status']); ?>"><?php echo htmlspecialchars(ucfirst($po['status'])); ?></span></td>
                                        <td>
                                            <a href="print-purchase-order.php?po_id=<?php echo (int) $po['po_id']; ?>" target="_blank" class="btn btn-secondary" style="padding:6px 10px;font-size:12px;">Print</a>
                                            <?php if ($po['status'] === 'open'): ?>
                                                <a href="po-receiving.php?po_id=<?php echo (int) $po['po_id']; ?>" class="btn btn-primary" style="padding:6px 10px;font-size:12px;">Receive Delivery</a>
                                                <button type="button" class="btn btn-secondary cancel-po-btn" data-po-id="<?php echo (int) $po['po_id']; ?>" style="padding:6px 10px;font-size:12px;">Cancel</button>
                                            <?php elseif ($po['status'] === 'received'): ?>
                                                <a href="po-receiving.php?po_id=<?php echo (int) $po['po_id']; ?>" class="btn btn-secondary" style="padding:6px 10px;font-size:12px;">View Receiving</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <script>
        document.querySelectorAll('.cancel-po-btn').forEach(function(btn) {
            btn.addEventListener('click', async function() {
                if (!confirm('Cancel this purchase order?')) return;
                const csrfToken = '<?php echo csrf_token(); ?>';
                const res = await fetch('purchase-order-actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'cancel',
                        csrf_token: csrfToken,
                        po_id: btn.dataset.poId
                    })
                });
                const result = await res.json();
                if (result.success) {
                    window.location.reload();
                } else {
                    alert(result.error || 'Could not cancel this order.');
                }
            });
        });
    </script>
</body>

</html>