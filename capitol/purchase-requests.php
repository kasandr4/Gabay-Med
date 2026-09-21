<?php
// capitol/purchase-requests.php
// Capitol's inbound queue (2026-09-20, new role - see
// 027_capitol_role_and_po_handoff.sql). Lists every Purchase Request the
// pharmacist has sent here (purchase_requests.status = 'sent_to_capitol'),
// plus a history of ones already converted into a PO, so Capitol can see
// both what's waiting on them and what they've already actioned.
//
// This page is read-only by design - Capitol doesn't edit a PR, they
// either act on it (Create PO, on purchase-orders.php?from_pr=X) or
// leave it for later. There's no reject/return-to-pharmacist action here
// because nothing in the source spec asked for one; if Capitol needs to
// push back on a request, that still happens outside the system (phone/
// email), same as the rest of this paper-trail-adjacent workflow.

require_once '../includes/auth_guard.php';
require_role('capitol');
require_once '../config/db.php';

$current_page = 'purchase-requests';

$queue = [];
$queueResult = $conn->query(
    "SELECT pr.pr_id, pr.pr_no, pr.purpose, pr.sent_to_capitol_at, pr.capitol_log_ref,
            CONCAT(u.first_name, ' ', u.last_name) AS requested_by_name,
            (SELECT COUNT(*) FROM purchase_request_items pri WHERE pri.pr_id = pr.pr_id) AS item_count,
            (SELECT COUNT(*) FROM purchase_orders po WHERE po.pr_id = pr.pr_id) AS po_count
     FROM purchase_requests pr
     JOIN users u ON u.user_id = pr.requested_by
     WHERE pr.status = 'sent_to_capitol'
     ORDER BY pr.sent_to_capitol_at ASC"
);
if ($queueResult) {
    while ($row = $queueResult->fetch_assoc()) {
        $queue[] = $row;
    }
}

$history = [];
$historyResult = $conn->query(
    "SELECT pr.pr_id, pr.pr_no, pr.purpose, pr.sent_to_capitol_at,
            CONCAT(u.first_name, ' ', u.last_name) AS requested_by_name,
            po.po_id, po.po_no, po.status AS po_status, po.sent_to_pharmacist_at
     FROM purchase_requests pr
     JOIN users u ON u.user_id = pr.requested_by
     LEFT JOIN purchase_orders po ON po.pr_id = pr.pr_id
     WHERE pr.status = 'converted'
     ORDER BY pr.sent_to_capitol_at DESC
     LIMIT 100"
);
if ($historyResult) {
    while ($row = $historyResult->fetch_assoc()) {
        $history[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Requests - Capitol Portal - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/pharmacist-dashboard.css">
    <link rel="stylesheet" href="../assets/css/procurement.css">
</head>

<body>
    <div class="app-shell">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">
            <header class="page-header">
                <div>
                    <h1>Purchase Requests</h1>
                    <p class="page-subtitle">Requests the pharmacist has sent for procurement. Create a Purchase Order once you're ready to act on one.</p>
                </div>
            </header>

            <section class="card">
                <div class="card-header">
                    <div>
                        <h2>Awaiting Action</h2>
                        <span class="card-subtitle">Oldest first</span>
                    </div>
                </div>
                <?php if (empty($queue)): ?>
                    <div class="po-empty">Nothing waiting right now.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>PR No.</th>
                                    <th>Purpose</th>
                                    <th>Requested By</th>
                                    <th>Items</th>
                                    <th>Sent</th>
                                    <th>Log Ref</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($queue as $pr): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($pr['pr_no']); ?></td>
                                        <td><?php echo htmlspecialchars($pr['purpose'] ?: '—'); ?></td>
                                        <td><?php echo htmlspecialchars($pr['requested_by_name']); ?></td>
                                        <td><?php echo (int) $pr['item_count']; ?></td>
                                        <td><?php echo $pr['sent_to_capitol_at'] ? htmlspecialchars(date('M j, Y', strtotime($pr['sent_to_capitol_at']))) : '—'; ?></td>
                                        <td><?php echo htmlspecialchars($pr['capitol_log_ref'] ?: '—'); ?></td>
                                        <td>
                                            <a href="../pharmacist/print-purchase-request.php?pr_id=<?php echo (int) $pr['pr_id']; ?>" target="_blank" class="btn btn-secondary" style="padding:6px 10px;font-size:12px;">View</a>
                                            <?php if ((int) $pr['po_count'] === 0): ?>
                                                <a href="purchase-orders.php?from_pr=<?php echo (int) $pr['pr_id']; ?>" class="btn btn-primary" style="padding:6px 10px;font-size:12px;">Create PO</a>
                                                <button type="button" class="btn btn-secondary return-pr-btn" data-pr-id="<?php echo (int) $pr['pr_id']; ?>" style="padding:6px 10px;font-size:12px;">Return to Pharmacist</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card" style="margin-top:20px;">
                <div class="card-header">
                    <div>
                        <h2>Already Actioned</h2>
                        <span class="card-subtitle">Converted into a Purchase Order</span>
                    </div>
                </div>
                <?php if (empty($history)): ?>
                    <div class="po-empty">Nothing here yet.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>PR No.</th>
                                    <th>Requested By</th>
                                    <th>PO No.</th>
                                    <th>PO Status</th>
                                    <th>Sent to Pharmacist?</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($history as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['pr_no']); ?></td>
                                        <td><?php echo htmlspecialchars($row['requested_by_name']); ?></td>
                                        <td><?php echo $row['po_no'] ? htmlspecialchars($row['po_no']) : '—'; ?></td>
                                        <td><?php echo $row['po_status'] ? htmlspecialchars(ucfirst($row['po_status'])) : '—'; ?></td>
                                        <td><?php echo $row['sent_to_pharmacist_at'] ? 'Yes, ' . htmlspecialchars(date('M j, Y', strtotime($row['sent_to_pharmacist_at']))) : 'Not yet'; ?></td>
                                        <td>
                                            <?php if ($row['po_id']): ?>
                                                <a href="purchase-orders.php" class="btn btn-secondary" style="padding:6px 10px;font-size:12px;">Manage PO</a>
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

    <!-- Return to Pharmacist - reason modal. Small, single-field modal
         rather than a bare prompt(), matching the modal shell already
         used elsewhere in this app (e.g. staff/prescription-queue.php's
         void-reason modal) so the reason field gets real validation and
         a real error state instead of a browser prompt() the pharmacist
         can't style or validate. -->
    <div id="returnPrOverlay" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:100; align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:12px; width:100%; max-width:440px; padding:20px;">
            <h3 style="margin-top:0;">Return to Pharmacist</h3>
            <p id="returnPrLabel" style="margin-bottom:6px; color:var(--text-muted, #666); font-size:13.5px;"></p>
            <label for="returnPrReason" class="form-label">What needs to change?</label>
            <textarea id="returnPrReason" class="form-input" rows="3" maxlength="255" style="width:100%; box-sizing:border-box;"></textarea>
            <p id="returnPrError" style="color:#b91c1c; font-size:13px; margin-top:6px;" hidden></p>
            <div style="display:flex; gap:10px; margin-top:14px;">
                <button type="button" class="btn btn-secondary" id="returnPrCancelBtn" style="flex:1;">Cancel</button>
                <button type="button" class="btn btn-primary" id="returnPrConfirmBtn" style="flex:1;">Return This Request</button>
            </div>
        </div>
    </div>

    <script>
        (function() {
            const csrfToken = <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>;
            const overlay = document.getElementById('returnPrOverlay');
            const label = document.getElementById('returnPrLabel');
            const reasonInput = document.getElementById('returnPrReason');
            const errorEl = document.getElementById('returnPrError');
            const cancelBtn = document.getElementById('returnPrCancelBtn');
            const confirmBtn = document.getElementById('returnPrConfirmBtn');
            let targetPrId = null;

            document.querySelectorAll('.return-pr-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    targetPrId = btn.dataset.prId;
                    const row = btn.closest('tr');
                    const prNo = row ? row.querySelector('td').textContent.trim() : ('PR #' + targetPrId);
                    label.textContent = 'Returning ' + prNo + ' to the pharmacist.';
                    reasonInput.value = '';
                    errorEl.hidden = true;
                    overlay.style.display = 'flex';
                });
            });

            cancelBtn.addEventListener('click', function() {
                overlay.style.display = 'none';
                targetPrId = null;
            });
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) {
                    overlay.style.display = 'none';
                    targetPrId = null;
                }
            });

            confirmBtn.addEventListener('click', async function() {
                const reason = reasonInput.value.trim();
                if (reason === '') {
                    errorEl.textContent = 'Please explain what needs to change.';
                    errorEl.hidden = false;
                    return;
                }
                errorEl.hidden = true;
                confirmBtn.disabled = true;

                const res = await fetch('purchase-request-actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'return_to_pharmacist',
                        csrf_token: csrfToken,
                        pr_id: targetPrId,
                        reason: reason
                    })
                });
                const result = await res.json();
                if (result.success) {
                    window.location.reload();
                } else {
                    confirmBtn.disabled = false;
                    errorEl.textContent = result.error || 'Could not return this request.';
                    errorEl.hidden = false;
                }
            });
        })();
    </script>
</body>

</html>