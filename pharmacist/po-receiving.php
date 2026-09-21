<?php
// pharmacist/po-receiving.php
//
// Step 3 of the simplified procurement flow: reconciling what was
// actually delivered against what was ordered, and adding the actual
// received quantities to stock. This is the "excel form to check if
// there is missing or too much medicine" your instructor asked for.
//
// The item grid is always pre-filled from the PO's own line items
// (Qty Received defaults to Qty Ordered, editable). The pharmacist can
// either type corrections directly, or download a CSV of the same
// rows, edit it in Excel, and upload it back — either way the same
// on-page grid is what gets reviewed and confirmed.

require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/csrf.php';

$poId = (int) ($_GET['po_id'] ?? 0);
if ($poId <= 0) {
    header('Location: purchase-orders.php');
    exit;
}

$poStmt = $conn->prepare(
    'SELECT po.po_id, po.po_no, po.supplier_name, po.order_date, po.status, po.received_at, pr.pr_no,
            CONCAT(COALESCE(ru.first_name,\'\'), \' \', COALESCE(ru.last_name,\'\')) AS received_by_name
     FROM purchase_orders po
     LEFT JOIN purchase_requests pr ON pr.pr_id = po.pr_id
     LEFT JOIN users ru ON ru.user_id = po.received_by
     WHERE po.po_id = ?'
);
$poStmt->bind_param('i', $poId);
$poStmt->execute();
$po = $poStmt->get_result()->fetch_assoc();
$poStmt->close();

if (!$po) {
    header('Location: purchase-orders.php');
    exit;
}

$itemsStmt = $conn->prepare(
    'SELECT poi_id, medicine_id, generic_name, brand, strength, unit, units_per_box,
            qty_ordered, unit_price_estimated, funding_source,
            qty_received, variance, variance_note, received_batch_no, received_expiry_date,
            received_unit_price, received_selling_price, notes
     FROM purchase_order_items WHERE po_id = ? ORDER BY poi_id ASC'
);
$itemsStmt->bind_param('i', $poId);
$itemsStmt->execute();
$res = $itemsStmt->get_result();
$items = [];
while ($row = $res->fetch_assoc()) {
    $items[] = $row;
}
$itemsStmt->close();

$isReceived = $po['status'] === 'received';
$current_page = 'purchase-orders';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receive Delivery - <?php echo htmlspecialchars($po['po_no']); ?> - GabayMed</title>
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
                    <span class="po-breadcrumb">Procurement &rsaquo; Purchase Orders &rsaquo; Receiving</span>
                    <h1><?php echo htmlspecialchars($po['po_no']); ?></h1>
                    <p class="page-subtitle">
                        <?php echo $po['pr_no'] ? 'From ' . htmlspecialchars($po['pr_no']) . ' &middot; ' : ''; ?>
                        <?php echo htmlspecialchars($po['supplier_name'] ?: 'No supplier on file'); ?>
                        <?php echo $po['order_date'] ? ' &middot; Ordered ' . htmlspecialchars(date('M j, Y', strtotime($po['order_date']))) : ''; ?>
                        &middot; <span class="po-status-badge <?php echo htmlspecialchars($po['status']); ?>"><?php echo htmlspecialchars(ucfirst($po['status'])); ?></span>
                    </p>
                </div>
                <a href="purchase-orders.php" class="btn btn-secondary">&larr; Back to Purchase Orders</a>
            </header>

            <?php if ($isReceived): ?>
                <p class="po-review-banner info">
                    Received <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($po['received_at']))); ?> by <?php echo htmlspecialchars(trim($po['received_by_name']) ?: 'Unknown'); ?>.
                    This delivery has already been recorded — the table below is read-only.
                </p>
            <?php else: ?>
                <section class="card">
                    <div class="card-header">
                        <div>
                            <h2>Receiving Sheet</h2>
                            <span class="card-subtitle">Confirm the actual quantity received per item. Mismatches are flagged automatically.</span>
                        </div>
                    </div>
                    <div style="padding: 14px 20px 0;">
                        <div class="po-grid-actions" style="margin-top:0;">
                            <div class="po-grid-actions-left">
                                <a href="po-receiving-actions.php?action=download_template&po_id=<?php echo (int) $poId; ?>" class="btn btn-secondary">Download Receiving Sheet (CSV)</a>
                                <span class="po-upload-inline">
                                    <input type="file" id="csvUpload" accept=".csv,text/csv">
                                    <button type="button" class="btn btn-secondary" id="csvUploadBtn">Upload Completed Sheet</button>
                                </span>
                            </div>
                        </div>
                        <p id="uploadBanner" class="po-review-banner info" hidden></p>
                    </div>
                </section>
            <?php endif; ?>

            <section class="card" style="margin-top: 20px;">
                <div class="card-header">
                    <div>
                        <h2>Items</h2>
                    </div>
                </div>
                <form id="receivingForm">
                    <?= csrf_field() ?>
                    <div class="po-item-grid-wrap">
                        <table class="po-item-grid" id="receivingGrid">
                            <thead>
                                <tr>
                                    <th style="min-width:160px;">Medicine</th>
                                    <th>Ordered</th>
                                    <th>Received</th>
                                    <th>Variance</th>
                                    <th>Batch No.</th>
                                    <th>Expiry Date</th>
                                    <th>Unit Price</th>
                                    <th>Selling Price</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <?php
                                    $defaultReceived = $isReceived ? $item['qty_received'] : $item['qty_ordered'];
                                    $variance = $isReceived ? $item['variance'] : 0;
                                    $varianceClass = $variance < 0 ? 'short' : ($variance > 0 ? 'excess' : 'match');
                                    $varianceLabel = $variance < 0 ? ('Short ' . abs($variance)) : ($variance > 0 ? ('Excess ' . $variance) : 'Matches');
                                    ?>
                                    <tr data-poi-id="<?php echo (int) $item['poi_id']; ?>" data-qty-ordered="<?php echo (int) $item['qty_ordered']; ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars($item['generic_name']); ?></strong><?php echo $item['strength'] ? ' ' . htmlspecialchars($item['strength']) : ''; ?><br>
                                            <span style="font-size:11.5px;color:var(--text-muted);"><?php echo htmlspecialchars($item['brand'] ?: '—'); ?> &middot; <?php echo htmlspecialchars($item['unit']); ?></span>
                                        </td>
                                        <td><?php echo (int) $item['qty_ordered']; ?></td>
                                        <td><input type="number" class="f-received" min="0" step="1" value="<?php echo (int) $defaultReceived; ?>" <?php echo $isReceived ? 'readonly' : ''; ?>></td>
                                        <td class="variance-cell"><span class="po-variance <?php echo $varianceClass; ?>"><?php echo htmlspecialchars($varianceLabel); ?></span></td>
                                        <td><input type="text" class="f-batch" maxlength="50" value="<?php echo htmlspecialchars($item['received_batch_no'] ?? ''); ?>" <?php echo $isReceived ? 'readonly' : ''; ?>></td>
                                        <td><input type="date" class="f-expiry" value="<?php echo htmlspecialchars($item['received_expiry_date'] ?? ''); ?>" <?php echo $isReceived ? 'readonly' : ''; ?>></td>
                                        <td><input type="number" class="f-price" min="0" step="0.01" value="<?php echo htmlspecialchars($item['received_unit_price'] ?? $item['unit_price_estimated'] ?? ''); ?>" <?php echo $isReceived ? 'readonly' : ''; ?>></td>
                                        <td><input type="number" class="f-selling" min="0" step="0.01" value="<?php echo htmlspecialchars($item['received_selling_price'] ?? ''); ?>" <?php echo $isReceived ? 'readonly' : ''; ?>></td>
                                        <td><input type="text" class="f-notes" maxlength="255" value="<?php echo htmlspecialchars($item['notes'] ?? ''); ?>" <?php echo $isReceived ? 'readonly' : ''; ?>></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (!$isReceived): ?>
                        <p id="receivingError" class="form-error" role="alert" hidden></p>
                        <div class="modal-actions rsb-actions-bar" style="margin-top:16px;">
                            <span class="rsb-required-note">Missing or excess quantities are recorded automatically — they won't block saving.</span>
                            <div class="rsb-actions-buttons">
                                <button type="submit" class="btn btn-primary">Confirm &amp; Add to Stock</button>
                            </div>
                        </div>
                    <?php endif; ?>
                </form>
            </section>
        </main>
    </div>

    <?php if (!$isReceived): ?>
        <script>
            (function() {
                const poId = <?php echo (int) $poId; ?>;
                const grid = document.getElementById('receivingGrid').querySelector('tbody');
                const csvUpload = document.getElementById('csvUpload');
                const csvUploadBtn = document.getElementById('csvUploadBtn');
                const uploadBanner = document.getElementById('uploadBanner');
                const form = document.getElementById('receivingForm');
                const errorBox = document.getElementById('receivingError');
                const csrfToken = document.querySelector('#receivingForm [name="csrf_token"]').value;

                function varianceLabel(variance) {
                    if (variance < 0) return {
                        cls: 'short',
                        text: 'Short ' + Math.abs(variance)
                    };
                    if (variance > 0) return {
                        cls: 'excess',
                        text: 'Excess ' + variance
                    };
                    return {
                        cls: 'match',
                        text: 'Matches'
                    };
                }

                function updateVariance(tr) {
                    const ordered = parseInt(tr.dataset.qtyOrdered, 10) || 0;
                    const received = parseInt(tr.querySelector('.f-received').value, 10) || 0;
                    const variance = received - ordered;
                    const info = varianceLabel(variance);
                    const cell = tr.querySelector('.variance-cell');
                    cell.innerHTML = '<span class="po-variance ' + info.cls + '">' + info.text + '</span>';
                }

                grid.querySelectorAll('tr').forEach(function(tr) {
                    tr.querySelector('.f-received').addEventListener('input', function() {
                        updateVariance(tr);
                    });
                });

                csvUploadBtn.addEventListener('click', async function() {
                    if (!csvUpload.files.length) {
                        alert('Choose a CSV file first.');
                        return;
                    }
                    uploadBanner.hidden = true;
                    const formData = new FormData();
                    formData.append('csv_file', csvUpload.files[0]);
                    formData.append('csrf_token', csrfToken);
                    formData.append('action', 'parse_upload');
                    formData.append('po_id', poId);
                    csvUploadBtn.disabled = true;
                    try {
                        const res = await fetch('po-receiving-actions.php', {
                            method: 'POST',
                            body: formData
                        });
                        const result = await res.json();
                        if (!res.ok || !result.success) {
                            throw new Error(result.error || 'Could not read that file.');
                        }
                        let applied = 0;
                        result.items.forEach(function(item) {
                            const tr = grid.querySelector('tr[data-poi-id="' + item.poi_id + '"]');
                            if (!tr) return;
                            tr.querySelector('.f-received').value = item.qty_received;
                            tr.querySelector('.f-batch').value = item.batch_no || '';
                            tr.querySelector('.f-expiry').value = item.expiry_date || '';
                            tr.querySelector('.f-price').value = item.unit_price || '';
                            tr.querySelector('.f-selling').value = item.selling_price || '';
                            tr.querySelector('.f-notes').value = item.notes || '';
                            updateVariance(tr);
                            applied++;
                        });
                        uploadBanner.className = 'po-review-banner ' + (result.warnings && result.warnings.length ? 'warning' : 'info');
                        uploadBanner.textContent = applied + ' row(s) updated from the file.' + (result.warnings && result.warnings.length ? ' Issues: ' + result.warnings.join('; ') : ' Review below, then confirm.');
                        uploadBanner.hidden = false;
                        csvUpload.value = '';
                    } catch (err) {
                        uploadBanner.className = 'po-review-banner warning';
                        uploadBanner.textContent = err.message || 'Could not read that file.';
                        uploadBanner.hidden = false;
                    } finally {
                        csvUploadBtn.disabled = false;
                    }
                });

                form.addEventListener('submit', async function(event) {
                    event.preventDefault();
                    errorBox.hidden = true;
                    const items = Array.from(grid.querySelectorAll('tr')).map(function(tr) {
                        return {
                            poi_id: tr.dataset.poiId,
                            qty_received: tr.querySelector('.f-received').value,
                            batch_no: tr.querySelector('.f-batch').value.trim(),
                            expiry_date: tr.querySelector('.f-expiry').value,
                            unit_price: tr.querySelector('.f-price').value,
                            selling_price: tr.querySelector('.f-selling').value,
                            notes: tr.querySelector('.f-notes').value.trim(),
                        };
                    });

                    const submitBtn = form.querySelector('button[type="submit"]');
                    submitBtn.disabled = true;
                    try {
                        const res = await fetch('po-receiving-actions.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                action: 'confirm',
                                csrf_token: csrfToken,
                                po_id: poId,
                                items: items
                            })
                        });
                        const result = await res.json();
                        if (!res.ok || !result.success) {
                            throw new Error(result.error || 'Could not confirm this delivery.');
                        }
                        let message = 'Delivery confirmed and stock updated.';
                        if (result.mismatches && result.mismatches.length) {
                            message += '\n\nFlagged for the record:\n' + result.mismatches.join('\n');
                        }
                        alert(message);
                        window.location.reload();
                    } catch (err) {
                        errorBox.textContent = err.message || 'Could not confirm this delivery.';
                        errorBox.hidden = false;
                    } finally {
                        submitBtn.disabled = false;
                    }
                });
            })();
        </script>
    <?php endif; ?>
</body>

</html>