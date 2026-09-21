<?php
// capitol/purchase-orders.php
// Capitol's own PO workspace (2026-09-20, new role - see
// 027_capitol_role_and_po_handoff.sql). Adapted from the pharmacist
// portal's original purchase-orders.php, which used to let the
// pharmacist create POs directly - that capability moved here
// entirely; the pharmacist's own copy of this page is now read-only
// (see pharmacist/purchase-orders.php's own header comment).
//
// FIXED vs. the original: the "From Purchase Request" dropdown used to
// query `status = 'open'` (drafts) - but purchase-order-actions.php's
// own create-from-PR check has always required `status = 'sent_to_capitol'`,
// so every PR that dropdown offered would have failed validation the
// moment it was actually submitted. Fixed below to query the status
// that's actually accepted.
//
// Adds one step the pharmacist-authored version never needed: "Send to
// Pharmacist" - creating a PO and making it visible to the pharmacist
// are now two distinct, separately-timestamped actions
// (purchase_orders.sent_to_pharmacist_at/_by), not implied by creation
// alone.

require_once '../includes/auth_guard.php';
require_role('capitol');
require_once '../config/db.php';
require_once '../includes/csrf.php';

$capitolId = (int) $_SESSION['user_id'];

$fromPrId = isset($_GET['from_pr']) ? (int) $_GET['from_pr'] : 0;
$prefillPr = null;
$prefillItems = [];
if ($fromPrId > 0) {
    $prStmt = $conn->prepare("SELECT pr_id, pr_no, purpose FROM purchase_requests WHERE pr_id = ? AND status = 'sent_to_capitol'");
    $prStmt->bind_param('i', $fromPrId);
    $prStmt->execute();
    $prefillPr = $prStmt->get_result()->fetch_assoc();
    $prStmt->close();

    if ($prefillPr) {
        $itemsStmt = $conn->prepare(
            'SELECT medicine_id, generic_name, brand, strength, unit, units_per_box, qty_requested, unit_price_estimated AS unit_price, funding_source, notes
             FROM purchase_request_items WHERE pr_id = ?'
        );
        $itemsStmt->bind_param('i', $fromPrId);
        $itemsStmt->execute();
        $res = $itemsStmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $prefillItems[] = $row;
        }
        $itemsStmt->close();
    }
}

$units = [];
$unitResult = $conn->query("SELECT unit_id, name FROM medicine_units ORDER BY name ASC");
if ($unitResult) {
    while ($row = $unitResult->fetch_assoc()) {
        $units[] = $row['name'];
    }
}

$catalog = [];
$catalogResult = $conn->query(
    "SELECT im.medicine_id, im.name, mn.name AS generic_name, im.strength, im.unit, im.units_per_box
     FROM inventory_medicines im
     JOIN medicine_names mn ON mn.generic_id = im.generic_id
     ORDER BY im.name ASC"
);
if ($catalogResult) {
    while ($row = $catalogResult->fetch_assoc()) {
        $catalog[] = [
            'id' => (int) $row['medicine_id'],
            'name' => $row['name'],
            'generic_name' => $row['generic_name'],
            'strength' => $row['strength'],
            'unit' => $row['unit'],
            'units_per_box' => $row['units_per_box'] !== null ? (int) $row['units_per_box'] : null,
        ];
    }
}

// FIXED - see header comment. This used to read status='open' (drafts),
// which the actual create-from-PR check has never accepted.
$openPrs = [];
$openPrResult = $conn->query("SELECT pr_id, pr_no, purpose FROM purchase_requests WHERE status = 'sent_to_capitol' ORDER BY sent_to_capitol_at ASC");
if ($openPrResult) {
    while ($row = $openPrResult->fetch_assoc()) {
        $openPrs[] = $row;
    }
}

$sourceLabels = [
    'maip' => 'MAIP',
    'philhealth' => 'PhilHealth',
    'pho' => 'PHO',
    'purchase_order' => 'Purchase Order',
    'donated' => 'Donated',
];

$orders = [];
$listQuery = $conn->query(
    "SELECT po.po_id, po.po_no, po.supplier_name, po.order_date, po.status, po.created_at, po.pr_id,
            po.sent_to_pharmacist_at,
            pr.pr_no,
            (SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.po_id = po.po_id) AS item_count
     FROM purchase_orders po
     LEFT JOIN purchase_requests pr ON pr.pr_id = po.pr_id
     ORDER BY po.created_at DESC, po.po_id DESC
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
    <title>Purchase Orders - Capitol Portal - GabayMed</title>
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
                    <p class="page-subtitle">Create a PO from a submitted Purchase Request, then send it to the pharmacist once it's ready.</p>
                </div>
                <button type="button" class="btn btn-primary" id="newPoBtn">+ New Purchase Order</button>
            </header>

            <section class="card" id="createPoCard" <?php echo $prefillPr ? '' : 'hidden'; ?>>
                <div class="card-header">
                    <div>
                        <h2>New Purchase Order</h2>
                        <span class="card-subtitle">Create from a submitted Purchase Request, or start blank.</span>
                    </div>
                </div>
                <div style="padding: 18px 20px;">
                    <form id="poForm">
                        <?= csrf_field() ?>
                        <div class="po-field-row">
                            <div class="po-field">
                                <label for="poFromPr">From Purchase Request (Optional)</label>
                                <select id="poFromPr">
                                    <option value="">— Standalone (no PR) —</option>
                                    <?php foreach ($openPrs as $pr): ?>
                                        <option value="<?php echo (int) $pr['pr_id']; ?>" <?php echo ($prefillPr && $prefillPr['pr_id'] == $pr['pr_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($pr['pr_no']); ?><?php echo $pr['purpose'] ? ' — ' . htmlspecialchars($pr['purpose']) : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="po-field">
                                <label for="poSupplier">Supplier Name (Optional)</label>
                                <input id="poSupplier" maxlength="150">
                            </div>
                            <div class="po-field">
                                <label for="poOrderDate">Order Date (Optional)</label>
                                <input id="poOrderDate" type="date">
                            </div>
                        </div>

                        <div class="po-tabs">
                            <button type="button" class="po-tab active" data-tab="manual">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4Z"></path>
                                </svg>
                                Add manually
                            </button>
                            <button type="button" class="po-tab" data-tab="import">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <path d="M14 2v6h6"></path>
                                    <path d="M12 18v-6"></path>
                                    <path d="m9 15 3-3 3 3"></path>
                                </svg>
                                Import from file
                            </button>
                        </div>

                        <div class="po-tab-panel active" data-panel="manual">
                            <button type="button" class="btn btn-secondary" id="addRowBtn">+ Add Item</button>
                        </div>

                        <div class="po-tab-panel" data-panel="import">
                            <label class="po-dropzone" id="dropzone">
                                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"></path>
                                    <path d="M12 12v9"></path>
                                    <path d="m16 16-4-4-4 4"></path>
                                </svg>
                                <span class="po-dropzone-title" id="dzTitle">Drag a file here, or click to browse</span>
                                <span class="po-dropzone-hint">Accepts .csv, or the hospital's official PO/PR (.xlsx)</span>
                                <input type="file" id="csvUpload" accept=".csv,text/csv,.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                            </label>
                            <div class="po-template-row">
                                <span>No CSV yet?</span>
                                <a href="purchase-order-actions.php?action=download_template" class="btn btn-secondary" style="padding:5px 10px;font-size:12px;">Download template</a>
                            </div>
                        </div>

                        <p id="uploadBanner" class="po-review-banner info" hidden></p>

                        <div class="po-item-rows" id="itemGridBody"></div>
                        <p id="gridEmptyMsg" class="po-empty">No items yet. Click "+ Add Item" or import a file.</p>

                        <p id="poError" class="form-error" role="alert" hidden></p>
                        <div class="modal-actions rsb-actions-bar" style="margin-top:16px;">
                            <span class="rsb-required-note">Items must have a medicine name, unit, and quantity.</span>
                            <div class="rsb-actions-buttons">
                                <button type="button" class="btn btn-secondary" id="cancelPoBtn">Cancel</button>
                                <button type="submit" class="btn btn-primary">Save Purchase Order</button>
                            </div>
                        </div>
                    </form>
                </div>
            </section>

            <section class="card" style="margin-top: 20px;">
                <div class="card-header">
                    <div>
                        <h2>All Purchase Orders</h2>
                        <span class="card-subtitle">Most recent first</span>
                    </div>
                </div>
                <?php if (empty($orders)): ?>
                    <div class="po-empty">No purchase orders yet.</div>
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
                                    <th>Status</th>
                                    <th>Sent to Pharmacist?</th>
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
                                        <td><span class="po-status-badge <?php echo htmlspecialchars($po['status']); ?>"><?php echo htmlspecialchars(ucfirst($po['status'])); ?></span></td>
                                        <td><?php echo $po['sent_to_pharmacist_at'] ? 'Yes, ' . htmlspecialchars(date('M j, Y', strtotime($po['sent_to_pharmacist_at']))) : 'Not yet'; ?></td>
                                        <td>
                                            <a href="../pharmacist/print-purchase-order.php?po_id=<?php echo (int) $po['po_id']; ?>" target="_blank" class="btn btn-secondary" style="padding:6px 10px;font-size:12px;">Print</a>
                                            <?php if (!$po['sent_to_pharmacist_at']): ?>
                                                <button type="button" class="btn btn-primary send-po-btn" data-po-id="<?php echo (int) $po['po_id']; ?>" style="padding:6px 10px;font-size:12px;">Send to Pharmacist</button>
                                            <?php endif; ?>
                                            <?php if ($po['status'] === 'open'): ?>
                                                <button type="button" class="btn btn-secondary cancel-po-btn" data-po-id="<?php echo (int) $po['po_id']; ?>" style="padding:6px 10px;font-size:12px;">Cancel</button>
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
        (function() {
            const catalog = <?php echo json_encode($catalog); ?>;
            const units = <?php echo json_encode($units); ?>;
            const sourceLabels = <?php echo json_encode($sourceLabels); ?>;
            const prefillItems = <?php echo json_encode($prefillItems); ?>;

            const catalogByName = {};
            // See purchase-requests.php's identical block for why this
            // second index exists -- CSV imports split strength into its
            // own column, so they need a generic+strength key rather than
            // the combined display name catalogByName uses.
            const catalogByGenericStrength = {};
            catalog.forEach(function(m) {
                catalogByName[m.name.toLowerCase()] = m;
                const genKey = (m.generic_name || '').trim().toLowerCase() + '|' + (m.strength || '').trim().toLowerCase();
                catalogByGenericStrength[genKey] = m;
            });

            function matchCatalog(genericName, strength) {
                const name = (genericName || '').trim().toLowerCase();
                if (strength !== undefined) {
                    const genKey = name + '|' + (strength || '').trim().toLowerCase();
                    if (catalogByGenericStrength[genKey]) return catalogByGenericStrength[genKey];
                }
                return catalogByName[name] || null;
            }

            const newPoBtn = document.getElementById('newPoBtn');
            const createCard = document.getElementById('createPoCard');
            const cancelPoBtn = document.getElementById('cancelPoBtn');
            const gridBody = document.getElementById('itemGridBody');
            const gridEmptyMsg = document.getElementById('gridEmptyMsg');
            const addRowBtn = document.getElementById('addRowBtn');
            const csvUpload = document.getElementById('csvUpload');
            const dropzone = document.getElementById('dropzone');
            const dzTitle = document.getElementById('dzTitle');
            const uploadBanner = document.getElementById('uploadBanner');
            const poForm = document.getElementById('poForm');
            const poError = document.getElementById('poError');
            const poFromPr = document.getElementById('poFromPr');

            document.querySelectorAll('.po-tab').forEach(function(tab) {
                tab.addEventListener('click', function() {
                    document.querySelectorAll('.po-tab').forEach(t => t.classList.remove('active'));
                    document.querySelectorAll('.po-tab-panel').forEach(p => p.classList.remove('active'));
                    tab.classList.add('active');
                    document.querySelector('.po-tab-panel[data-panel="' + tab.dataset.tab + '"]').classList.add('active');
                });
            });

            ['dragenter', 'dragover'].forEach(function(evt) {
                dropzone.addEventListener(evt, function(e) {
                    e.preventDefault();
                    dropzone.classList.add('dragover');
                });
            });
            ['dragleave', 'drop'].forEach(function(evt) {
                dropzone.addEventListener(evt, function(e) {
                    e.preventDefault();
                    dropzone.classList.remove('dragover');
                });
            });
            dropzone.addEventListener('drop', function(e) {
                if (e.dataTransfer.files.length) {
                    csvUpload.files = e.dataTransfer.files;
                    handleFileUpload();
                }
            });
            csvUpload.addEventListener('change', function() {
                if (csvUpload.files.length) handleFileUpload();
            });

            function showCreateCard() {
                createCard.hidden = false;
                newPoBtn.hidden = true;
            }
            newPoBtn.addEventListener('click', function() {
                showCreateCard();
                if (gridBody.children.length === 0) addRow({}, true);
            });
            cancelPoBtn.addEventListener('click', function() {
                createCard.hidden = true;
                newPoBtn.hidden = false;
                gridBody.innerHTML = '';
                poForm.reset();
                refreshEmptyState();
            });

            function optionList(values, selected) {
                return '<option value="">Select</option>' + values.map(function(v) {
                    return '<option value="' + escapeHtml(v) + '"' + (v === selected ? ' selected' : '') + '>' + escapeHtml(v) + '</option>';
                }).join('');
            }

            function sourceOptionList(selected) {
                let html = '<option value="">Optional</option>';
                Object.keys(sourceLabels).forEach(function(key) {
                    html += '<option value="' + key + '"' + (key === selected ? ' selected' : '') + '>' + escapeHtml(sourceLabels[key]) + '</option>';
                });
                return html;
            }

            function escapeHtml(str) {
                const div = document.createElement('div');
                div.textContent = str == null ? '' : String(str);
                return div.innerHTML;
            }

            const chevronSvg = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>';
            const trashSvg = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';

            // startOpen: a fresh blank row (manual "+ Add Item") opens
            // expanded so nothing's hidden while typing; rows populated
            // in bulk from a file (or copied in from a PR) start
            // collapsed so a large import doesn't turn into dozens of
            // open rows at once.
            function addRow(data, startOpen) {
                data = data || {};
                const row = document.createElement('div');
                row.className = 'po-item-row' + (startOpen ? ' open' : '');
                row.innerHTML = `
                    <div class="po-item-row-head">
                        <span class="po-row-chevron">${chevronSvg}</span>
                        <input type="text" class="f-name" list="catalogNames" maxlength="150" placeholder="Medicine (generic name)" value="${escapeHtml(data.generic_name)}">
                        <select class="f-unit">${optionList(units, data.unit || '')}</select>
                        <input type="number" class="f-qty" min="1" step="1" placeholder="Qty" value="${escapeHtml(data.qty_requested || data.qty_ordered)}">
                        <button type="button" class="po-remove-row" title="Remove row">${trashSvg}</button>
                    </div>
                    <div class="po-item-row-detail">
                        <div><label>Brand</label><input type="text" class="f-brand" maxlength="150" value="${escapeHtml(data.brand)}"></div>
                        <div><label>Strength</label><input type="text" class="f-strength" maxlength="50" value="${escapeHtml(data.strength)}"></div>
                        <div><label>Units/box</label><input type="number" class="f-upb" min="1" step="1" value="${escapeHtml(data.units_per_box)}"></div>
                        <div><label>Est. unit price</label><input type="number" class="f-price" min="0" step="0.01" value="${escapeHtml(data.unit_price)}"></div>
                        <div><label>Funding source</label><select class="f-source">${sourceOptionList(data.funding_source || '')}</select></div>
                    </div>
                `;
                row.dataset.medicineId = data.medicine_id || '';
                const nameInput = row.querySelector('.f-name');
                nameInput.addEventListener('change', function() {
                    const match = catalogByName[nameInput.value.trim().toLowerCase()];
                    if (match) {
                        row.dataset.medicineId = match.id;
                        row.querySelector('.f-strength').value = match.strength || '';
                        row.querySelector('.f-unit').value = match.unit || '';
                        if (match.units_per_box) row.querySelector('.f-upb').value = match.units_per_box;
                    } else {
                        row.dataset.medicineId = '';
                    }
                });
                row.querySelector('.po-row-chevron').addEventListener('click', function() {
                    row.classList.toggle('open');
                });
                row.querySelector('.po-remove-row').addEventListener('click', function(e) {
                    e.stopPropagation();
                    row.remove();
                    refreshEmptyState();
                });
                gridBody.appendChild(row);
                refreshEmptyState();
            }

            function refreshEmptyState() {
                gridEmptyMsg.hidden = gridBody.children.length > 0;
            }

            addRowBtn.addEventListener('click', function() {
                addRow({}, true);
            });

            async function handleFileUpload() {
                const file = csvUpload.files[0];
                if (!file) return;
                dzTitle.textContent = file.name;
                uploadBanner.hidden = true;
                const formData = new FormData();
                formData.append('csv_file', file);
                formData.append('csrf_token', document.querySelector('#poForm [name="csrf_token"]').value);
                formData.append('action', 'parse_upload');
                try {
                    const res = await fetch('purchase-order-actions.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await res.json();
                    if (!res.ok || !result.success) {
                        throw new Error(result.error || 'Could not read that file.');
                    }
                    result.items.forEach(function(item) {
                        // The official xlsx has no Brand/Strength/
                        // Units-per-box columns, so those come back blank
                        // from the server, and its generic_name keeps
                        // strength baked into the text -- catalogByName
                        // matches that directly. A CSV instead splits
                        // strength into its own column, so matchCatalog()
                        // tries the generic+strength pair first for that
                        // case. If the imported name matches something
                        // already in the Medicine Catalog either way, fill
                        // in blanks the same way picking a name from the
                        // datalist does on a manual row -- without
                        // overwriting anything the file itself provided
                        // (e.g. a unit already parsed out of the
                        // description).
                        const match = matchCatalog(item.generic_name, item.strength);
                        if (match) {
                            item.medicine_id = item.medicine_id || match.id;
                            if (!item.strength) item.strength = match.strength || '';
                            if (!item.unit) item.unit = match.unit || '';
                            if (!item.units_per_box) item.units_per_box = match.units_per_box || '';
                        }
                        addRow(item, false);
                    });
                    uploadBanner.className = 'po-review-banner ' + (result.warnings && result.warnings.length ? 'warning' : 'info');
                    uploadBanner.textContent = result.items.length + ' row(s) added from the file.' + (result.warnings && result.warnings.length ? ' Some rows had issues: ' + result.warnings.join('; ') : ' Review below before saving.');
                    uploadBanner.hidden = false;
                } catch (err) {
                    uploadBanner.className = 'po-review-banner warning';
                    uploadBanner.textContent = err.message || 'Could not read that file.';
                    uploadBanner.hidden = false;
                } finally {
                    dzTitle.textContent = 'Drag a file here, or click to browse';
                    csvUpload.value = '';
                }
            }

            poForm.addEventListener('submit', async function(event) {
                event.preventDefault();
                poError.hidden = true;
                const rows = Array.from(gridBody.querySelectorAll('.po-item-row'));
                if (rows.length === 0) {
                    poError.textContent = 'Add at least one item.';
                    poError.hidden = false;
                    return;
                }
                const items = rows.map(function(row) {
                    return {
                        medicine_id: row.dataset.medicineId || null,
                        generic_name: row.querySelector('.f-name').value.trim(),
                        brand: row.querySelector('.f-brand').value.trim(),
                        strength: row.querySelector('.f-strength').value.trim(),
                        unit: row.querySelector('.f-unit').value,
                        units_per_box: row.querySelector('.f-upb').value,
                        qty_ordered: row.querySelector('.f-qty').value,
                        unit_price: row.querySelector('.f-price').value,
                        funding_source: row.querySelector('.f-source').value,
                    };
                });

                const submitBtn = poForm.querySelector('button[type="submit"]');
                submitBtn.disabled = true;
                try {
                    const res = await fetch('purchase-order-actions.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            action: 'create',
                            csrf_token: document.querySelector('#poForm [name="csrf_token"]').value,
                            pr_id: poFromPr.value || null,
                            supplier_name: document.getElementById('poSupplier').value.trim(),
                            order_date: document.getElementById('poOrderDate').value,
                            items: items
                        })
                    });
                    const result = await res.json();
                    if (!res.ok || !result.success) {
                        throw new Error(result.error || 'Could not save this purchase order.');
                    }
                    window.location.href = 'purchase-orders.php';
                } catch (err) {
                    poError.textContent = err.message || 'Could not save this purchase order.';
                    poError.hidden = false;
                } finally {
                    submitBtn.disabled = false;
                }
            });

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

            document.querySelectorAll('.send-po-btn').forEach(function(btn) {
                btn.addEventListener('click', async function() {
                    if (!confirm('Send this purchase order to the pharmacist? They will be able to see and receive it once sent.')) return;
                    btn.disabled = true;
                    const csrfToken = '<?php echo csrf_token(); ?>';
                    const res = await fetch('purchase-order-actions.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            action: 'send_to_pharmacist',
                            csrf_token: csrfToken,
                            po_id: btn.dataset.poId
                        })
                    });
                    const result = await res.json();
                    if (result.success) {
                        window.location.reload();
                    } else {
                        btn.disabled = false;
                        alert(result.error || 'Could not send this order to the pharmacist.');
                    }
                });
            });

            if (prefillItems && prefillItems.length) {
                showCreateCard();
                prefillItems.forEach(function(item) {
                    addRow(item, false);
                });
                // Unit is optional when a Purchase Request is created, so
                // a converted PR can arrive with rows still missing one --
                // flag it here since this is the point it actually becomes
                // required.
                const incomplete = prefillItems.filter(function(item) {
                    return !item.unit;
                }).length;
                if (incomplete > 0) {
                    uploadBanner.className = 'po-review-banner warning';
                    uploadBanner.textContent = incomplete + ' item(s) from this request are missing a unit -- fill those in below before saving.';
                    uploadBanner.hidden = false;
                }
            }
        })();
    </script>
    <datalist id="catalogNames">
        <?php foreach ($catalog as $m): ?>
            <option value="<?php echo htmlspecialchars($m['name']); ?>">
            <?php endforeach; ?>
    </datalist>
</body>

</html>