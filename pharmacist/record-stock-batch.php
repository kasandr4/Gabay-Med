<?php
require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/csrf.php';

$medicines = [];
$result = $conn->query(
    "SELECT im.medicine_id, im.name, im.unit, im.current_stock
     FROM inventory_medicines im
     ORDER BY im.name ASC"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $medicines[] = [
            'id' => (int) $row['medicine_id'],
            'name' => $row['name'],
            'unit' => $row['unit'],
            'current_stock' => (int) $row['current_stock'],
        ];
    }
}

$units = [];
$unitResult = $conn->query("SELECT unit_id, name FROM medicine_units ORDER BY name ASC");
if ($unitResult) {
    while ($row = $unitResult->fetch_assoc()) {
        $units[] = $row;
    }
}

// "FROM APPROVED PO" TAB: the tab holds a single drag-and-drop import. A
// delivery list (CSV / Excel) is dropped on it, previewed against the
// catalog, and only then added to stock as Purchase Order stock - see
// stock-import-actions.php. There is no purchase request to pick here;
// links that still carry ?pr_id= (or ?tab=pr) just open this tab.
$openPrTab = (int) ($_GET['pr_id'] ?? 0) > 0 || ($_GET['tab'] ?? '') === 'pr';

$recentBatches = [];
$sourceLabels = [
    'maip' => 'MAIP',
    'philhealth' => 'PhilHealth',
    'pho' => 'PHO',
    'purchase_order' => 'Purchase Order',
    'donated' => 'Donated',
];
$historyQuery = $conn->query(
    "SELECT mb.batch_id, im.name AS medicine_name, mb.source, mb.donor_notes, mb.units_received,
            DATE_FORMAT(mb.received_at, '%Y-%m-%d %H:%i') AS received_at,
            CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS recorded_by
     FROM medicine_batches mb
     JOIN inventory_medicines im ON im.medicine_id = mb.medicine_id
     LEFT JOIN users u ON u.user_id = mb.created_by
    WHERE mb.created_by IS NOT NULL
      AND NOT EXISTS (
            SELECT 1 FROM purchase_order_items poi WHERE poi.batch_id = mb.batch_id
          )
      AND NOT EXISTS (
            SELECT 1 FROM purchase_request_items pri WHERE pri.batch_id = mb.batch_id
          )
     ORDER BY mb.received_at DESC, mb.batch_id DESC
     LIMIT 25"
);
if ($historyQuery) {
    while ($row = $historyQuery->fetch_assoc()) {
        $recentBatches[] = [
            'id' => (int) $row['batch_id'],
            'medicine' => $row['medicine_name'],
            'source' => $row['source'],
            'donor_notes' => $row['donor_notes'],
            'quantity' => (int) $row['units_received'],
            'received_at' => $row['received_at'],
            'recorded_by' => trim($row['recorded_by']) ?: 'Unknown',
        ];
    }
}

$current_page = 'record-stock-batch';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Stock Batch - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/pharmacist-dashboard.css">
    <link rel="stylesheet" href="../assets/css/inventory-count.css">
    <link rel="stylesheet" href="../assets/css/procurement.css">
    <style>
        .main-content {
            padding: 28px 30px 32px;
        }

        /* "From Approved PO" tab: just the drop zone. */
        .rsb-drop-wrap {
            padding: 20px;
        }

        .rsb-drop-wrap .po-dropzone {
            padding: 44px 16px;
            min-height: 180px;
        }

        .rsb-drop-wrap .po-dropzone.is-busy {
            cursor: progress;
            opacity: 0.7;
        }

        .rsb-drop-wrap .po-dropzone-hint.is-error {
            color: #b91c1c;
            font-weight: 600;
        }

        .po-grid-wrap {
            overflow-x: auto;
            padding: 0 20px 16px;
        }

        .po-grid {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
        }

        .po-grid th,
        .po-grid td {
            padding: 8px 10px;
            border-bottom: 1px solid var(--border);
            text-align: left;
            vertical-align: top;
        }

        .po-grid input,
        .po-grid select {
            width: 100%;
            box-sizing: border-box;
            padding: 5px 6px;
            font-size: 12.5px;
        }

        .page-header {
            margin-bottom: 22px;
        }

        .rsb-page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
        }

        .rsb-breadcrumb {
            display: inline-block;
            margin-bottom: 6px;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
        }

        .rsb-breadcrumb:hover {
            color: var(--teal-dark);
        }

        .rsb-back-btn {
            flex-shrink: 0;
            white-space: nowrap;
        }

        /* Import from file modal */
        #importModal [hidden] {
            display: none !important;
        }

        #importModal .dr-modal-box {
            width: min(1040px, calc(100vw - 48px));
        }

        #importModal .imp-fields {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            margin: 16px 0 12px;
        }

        #importModal .imp-fields .rsb-field:last-child {
            grid-column: 1 / -1;
        }

        #importModal .imp-template-row {
            margin: 0 0 12px;
            font-size: 12.5px;
            color: var(--text-secondary);
        }

        #importModal .po-grid-wrap {
            padding: 0;
        }

        #importModal .po-grid td.imp-num {
            white-space: nowrap;
        }

        #importModal .po-grid tr.imp-skipped td {
            background: rgba(148, 163, 184, 0.08);
            color: var(--text-secondary);
        }

        #importModal .imp-note {
            display: block;
            margin-top: 3px;
            font-size: 11.5px;
            color: var(--text-muted);
        }

        @media (max-width: 640px) {
            #importModal .imp-fields {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .rsb-page-header {
                flex-direction: column;
            }
        }

        .rsb-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.55fr) minmax(320px, 0.95fr);
            gap: 24px;
            align-items: start;
        }

        @media (max-width: 980px) {
            .rsb-grid {
                grid-template-columns: 1fr;
            }
        }

        .card {
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            border-radius: 16px;
        }

        .card-header {
            padding: 18px 20px 14px;
            border-bottom: 1px solid var(--border);
        }

        .card-header h2 {
            margin: 0 0 4px;
            font-size: 18px;
            line-height: 1.3;
        }

        .card-subtitle {
            font-size: 12px;
            color: var(--text-secondary);
        }

        .rsb-tabs {
            display: flex;
            gap: 4px;
            padding: 0 20px;
            margin-top: 14px;
            border-bottom: 1px solid var(--border);
        }

        .rsb-tab {
            padding: 10px 4px;
            margin-bottom: -1px;
            border: none;
            background: none;
            font: inherit;
            font-weight: 700;
            font-size: 13.5px;
            color: var(--text-secondary);
            cursor: pointer;
            border-bottom: 2px solid transparent;
        }

        .rsb-tab+.rsb-tab {
            margin-left: 18px;
        }

        .rsb-tab.is-active {
            color: var(--teal);
            border-bottom-color: var(--teal);
        }

        .rsb-new-medicine-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }

        @media (max-width: 780px) {
            .rsb-new-medicine-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 480px) {
            .rsb-new-medicine-grid {
                grid-template-columns: 1fr;
            }
        }

        .rsb-section-label {
            display: block;
            margin: 2px 0 12px;
            font-size: 12.5px;
            font-weight: 800;
            color: var(--teal-dark);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .rsb-divider {
            height: 1px;
            background: var(--border);
            margin: 4px 0;
        }

        .rsb-section-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            align-items: start;
        }

        @media (max-width: 780px) {
            .rsb-section-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 480px) {
            .rsb-section-grid {
                grid-template-columns: 1fr;
            }
        }

        .rsb-field--span2 {
            grid-column: span 2;
        }

        @media (max-width: 480px) {
            .rsb-field--span2 {
                grid-column: span 1;
            }
        }

        .rsb-form {
            display: grid;
            gap: 18px;
            padding: 20px 20px 8px;
        }

        .rsb-form-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .rsb-field {
            display: grid;
            gap: 8px;
        }

        /* BUG FIX: same cascade issue as .empty-state[hidden] in
           doctor-dashboard.css — .rsb-field's own `display: grid` beats the
           browser's built-in `[hidden] { display: none }` rule, so toggling
           existingMedicinePanel.hidden via JS was silently ignored and the
           medicine-search list stayed visible under the New Medicine tab. */
        #existingMedicinePanel[hidden] {
            display: none;
        }

        /* Same cascade problem in two more places: .rsb-form and .rsb-field both
           set `display: grid`, so form.hidden (set while the Purchase Order tab
           is active) and donorNotesWrap.hidden (set unless Source is Donated)
           were ignored. Covers everything toggled with [hidden] inside this
           layout so the next one doesn't slip through. */
        .rsb-grid [hidden] {
            display: none;
        }

        .rsb-field label,
        .rsb-field span {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .rsb-field input,
        .rsb-field select,
        .rsb-field textarea {
            width: 100%;
            box-sizing: border-box;
            min-height: 42px;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface);
            color: var(--text-primary);
            font: inherit;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .rsb-field input:focus,
        .rsb-field select:focus,
        .rsb-field textarea:focus {
            outline: none;
            border-color: rgba(20, 184, 166, 0.7);
            box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.12);
        }

        .rsb-field textarea {
            min-height: 90px;
            resize: vertical;
        }

        .ic-medicine-list {
            max-height: 260px;
            margin-top: 4px;
            overflow-y: auto;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: var(--surface);
            box-shadow: inset 0 1px 2px rgba(15, 23, 30, 0.03);
        }

        .ic-medicine-row {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            align-items: center;
            gap: 10px;
            min-height: 44px;
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .ic-medicine-row:last-child {
            border-bottom: none;
        }

        .ic-medicine-row:hover,
        .ic-medicine-row.is-selected {
            background: var(--teal-light);
        }

        .ic-medicine-row input[type="radio"] {
            width: 16px;
            height: 16px;
            margin: 0;
            accent-color: var(--teal);
            cursor: pointer;
            flex-shrink: 0;
        }

        .ic-medicine-name {
            min-width: 0;
            overflow: hidden;
            font-size: 13.5px;
            font-weight: 700;
            color: var(--text-primary);
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .ic-medicine-meta {
            color: var(--text-muted);
            font-size: 12px;
            text-align: right;
            white-space: nowrap;
        }

        .table-wrap {
            width: 100%;
            overflow: hidden;
        }

        .rsb-history-table {
            width: 100%;
            min-width: 0;
            table-layout: auto;
            border-collapse: collapse;
            font-size: 12px;
        }

        .rsb-history-table thead th,
        .rsb-history-table tbody td {
            padding: 10px 12px;
            vertical-align: middle;
        }

        .rsb-history-table th:first-child,
        .rsb-history-table td:first-child {
            width: auto;
            max-width: 0;
        }

        .rsb-history-table th:nth-child(2),
        .rsb-history-table td:nth-child(2),
        .rsb-history-table th:nth-child(3),
        .rsb-history-table td:nth-child(3) {
            width: 1%;
            white-space: nowrap;
            text-align: center;
        }

        .rsb-history-table th,
        .rsb-history-table td {
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .rsb-history-name,
        .rsb-history-note {
            display: block;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .rsb-history-note {
            margin-top: 4px;
            color: var(--text-muted);
            font-size: 11.5px;
        }

        .rsb-source-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            text-transform: capitalize;
            letter-spacing: 0.02em;
            min-width: 74px;
            text-align: center;
        }

        .rsb-source-badge.maip {
            background: #e0f2fe;
            color: #075985;
        }

        .rsb-source-badge.philhealth {
            background: #fef3c7;
            color: #92400e;
        }

        .rsb-source-badge.pho {
            background: #ede9fe;
            color: #5b21b6;
        }

        .rsb-source-badge.purchase_order {
            background: #dbeafe;
            color: #1e40af;
        }

        .rsb-source-badge.donated {
            background: #ecfccb;
            color: #3f6212;
        }

        .rsb-empty {
            padding: 20px;
            color: var(--text-secondary);
        }

        .form-error {
            margin: 0;
            padding: 10px 12px;
            border-radius: 10px;
            background: var(--red-light);
            color: var(--red);
            border: 1px solid rgba(239, 68, 68, 0.15);
            font-size: 13px;
            font-weight: 600;
        }

        .rsb-history-panel {
            padding-bottom: 0;
        }

        .rsb-history-panel .card-header {
            padding-bottom: 12px;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-start;
            margin-top: 6px;
            padding-bottom: 8px;
        }

        .modal-actions .btn {
            min-width: 150px;
        }

        .rsb-actions-bar {
            justify-content: space-between;
            align-items: center;
            padding-top: 10px;
            border-top: 1px solid var(--border);
        }

        .rsb-required-note {
            font-size: 12px;
            color: var(--text-muted);
        }

        .rsb-actions-buttons {
            display: flex;
            gap: 12px;
        }

        @media (max-width: 480px) {
            .rsb-actions-bar {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }

            .rsb-actions-buttons {
                justify-content: flex-end;
            }
        }

        @media (max-width: 640px) {
            .rsb-form-row {
                grid-template-columns: 1fr;
            }

            .ic-medicine-meta {
                white-space: normal;
            }

            .rsb-history-table thead th,
            .rsb-history-table tbody td {
                padding: 9px 7px;
                font-size: 11px;
            }

            .rsb-source-badge {
                min-width: 0;
                padding: 4px 5px;
            }
        }
    </style>
</head>

<body>
    <div class="app-shell">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">
            <header class="page-header rsb-page-header">
                <div>
                    <a href="inventory.php" class="rsb-breadcrumb">Inventory <span aria-hidden="true">&rsaquo;</span> Record Stock Batch</a>
                    <h1>Record Stock Batch</h1>
                    <p class="page-subtitle">Log a stock batch against an existing medicine, register a new medicine and its first batch together, or import a delivery list from a file.</p>
                </div>
                <a href="inventory.php" class="btn btn-secondary rsb-back-btn">&larr; Back to Inventory</a>
            </header>

            <div class="rsb-grid">
                <section class="card">
                    <div class="card-header">
                        <div>
                            <h2>Record Batch</h2>
                            <span class="card-subtitle">For MAIP, PhilHealth, PHO, donated stock, or a delivery list imported from a file.</span>
                        </div>
                    </div>

                    <div class="rsb-tabs" role="tablist">
                        <button type="button" class="rsb-tab is-active" id="tabExisting" role="tab" aria-selected="true">Existing Medicine</button>
                        <button type="button" class="rsb-tab" id="tabNew" role="tab" aria-selected="false">New Medicine</button>
                        <button type="button" class="rsb-tab" id="tabPr" role="tab" aria-selected="false">From Approved PO</button>
                    </div>

                    <form id="recordBatchForm" class="rsb-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" id="formAction" value="record_batch">

                        <div class="rsb-field" id="existingMedicinePanel">
                            <span>Select Medicine</span>
                            <input type="text" id="medicineSearch" placeholder="Search medicine name...">
                            <div class="ic-medicine-list" id="medicineList">
                                <?php foreach ($medicines as $medicine): ?>
                                    <label class="ic-medicine-row" data-name="<?php echo htmlspecialchars(strtolower($medicine['name'])); ?>">
                                        <input type="radio" name="medicine_id" value="<?php echo (int) $medicine['id']; ?>">
                                        <span class="ic-medicine-name"><?php echo htmlspecialchars($medicine['name']); ?></span>
                                        <span class="ic-medicine-meta"><?php echo htmlspecialchars($medicine['unit']); ?> &middot; <?php echo (int) $medicine['current_stock']; ?> in stock</span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div id="newMedicinePanel" hidden>
                            <span class="rsb-section-label">Medicine Information</span>
                            <div class="rsb-new-medicine-grid">
                                <div class="rsb-field">
                                    <label for="newGenericName">Generic / Medicine Name</label>
                                    <input id="newGenericName" name="generic_name" maxlength="150" placeholder="e.g. Amoxicillin">
                                </div>
                                <div class="rsb-field">
                                    <label for="newUnit">Unit of Measure</label>
                                    <select id="newUnit" name="unit">
                                        <option value="">Select unit</option>
                                        <?php foreach ($units as $unit): ?>
                                            <option value="<?php echo htmlspecialchars($unit['name']); ?>"><?php echo htmlspecialchars($unit['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="rsb-field">
                                    <label for="newManufacturer">Manufacturer (Optional)</label>
                                    <input id="newManufacturer" name="manufacturer" maxlength="150">
                                </div>
                                <div class="rsb-field">
                                    <label for="newStrength">Strength (Optional)</label>
                                    <input id="newStrength" name="strength" maxlength="50" placeholder="e.g. 500mg">
                                </div>
                                <div class="rsb-field">
                                    <label for="newUnitsPerBox">Units per Box</label>
                                    <input id="newUnitsPerBox" name="units_per_box" type="number" min="1" step="1" value="1">
                                </div>
                            </div>
                        </div>

                        <div class="rsb-divider"></div>

                        <div class="rsb-section">
                            <span class="rsb-section-label">Batch &amp; Inventory Details</span>
                            <div class="rsb-section-grid">
                                <div class="rsb-field">
                                    <label for="quantityReceived">Quantity Received</label>
                                    <input id="quantityReceived" name="quantity" type="number" min="1" step="1" required>
                                </div>
                                <div class="rsb-field">
                                    <label for="batchNo">Batch Number (Optional)</label>
                                    <input id="batchNo" name="batch_no" type="text" maxlength="100" placeholder="e.g. DN-001">
                                </div>
                                <div class="rsb-field">
                                    <label for="batchBrand">Brand (Optional)</label>
                                    <input id="batchBrand" name="brand" type="text" maxlength="150" placeholder="e.g. Muconov 200">
                                </div>
                                <div class="rsb-field">
                                    <label for="expiryDate">Expiry Date (Optional)</label>
                                    <input id="expiryDate" name="expiry_date" type="date">
                                </div>
                                <div class="rsb-field">
                                    <label for="unitPrice">Unit Price (Optional)</label>
                                    <input id="unitPrice" name="unit_price" type="number" min="0" step="0.01" placeholder="e.g. 10.10">
                                </div>
                                <div class="rsb-field">
                                    <label for="sellingPrice">Selling Price (Optional)</label>
                                    <input id="sellingPrice" name="selling_price" type="number" min="0" step="0.01" placeholder="e.g. 13.00">
                                </div>
                                <div class="rsb-field">
                                    <label for="stockSource">Source</label>
                                    <select id="stockSource" name="source" required>
                                        <option value="maip">MAIP</option>
                                        <option value="philhealth">PhilHealth</option>
                                        <option value="pho">PHO</option>
                                        <option value="donated">Donated</option>
                                    </select>
                                </div>
                                <div class="rsb-field rsb-field--span2" id="donorNotesWrap" hidden>
                                    <label for="donorNotes">Donor / Notes</label>
                                    <textarea id="donorNotes" name="donor_notes" maxlength="255" placeholder="e.g. Red Cross donation drive, Aug 2026"></textarea>
                                </div>
                            </div>
                        </div>

                        <p id="recordBatchError" class="form-error" role="alert" hidden></p>
                        <div class="modal-actions rsb-actions-bar">
                            <span class="rsb-required-note">* Required fields</span>
                            <div class="rsb-actions-buttons">
                                <a href="inventory.php" class="btn btn-secondary">Cancel</a>
                                <button type="submit" class="btn btn-primary">Record Batch</button>
                            </div>
                        </div>
                    </form>

                    <div id="prPanel" hidden>
                        <div class="rsb-drop-wrap">
                            <label class="po-dropzone" id="importDropzone">
                                <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"></path>
                                    <path d="M12 12v9"></path>
                                    <path d="m16 16-4-4-4 4"></path>
                                </svg>
                                <span class="po-dropzone-title" id="importDzTitle">Drag a file here, or click to browse</span>
                                <span class="po-dropzone-hint" id="importDzMsg">Accepts .csv or .xlsx</span>
                                <input type="file" id="importFile" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                            </label>
                        </div>
                    </div>
                </section>

                <section class="card rsb-history-panel">
                    <div class="card-header">
                        <div>
                            <h2>Recent Batch History</h2>
                            <span class="card-subtitle">Latest manually recorded stock entries</span>
                        </div>
                    </div>

                    <?php if (empty($recentBatches)): ?>
                        <div class="rsb-empty">No manually recorded batches yet.</div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table rsb-history-table">
                                <thead>
                                    <tr>
                                        <th>Medicine</th>
                                        <th>Qty</th>
                                        <th>Source</th>
                                        <th>Date</th>
                                        <th>Recorded By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentBatches as $batch): ?>
                                        <tr>
                                            <td>
                                                <span class="rsb-history-name"><?php echo htmlspecialchars($batch['medicine']); ?></span>
                                                <?php if (!empty($batch['donor_notes'])): ?>
                                                    <span class="rsb-history-note"><?php echo htmlspecialchars($batch['donor_notes']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo (int) $batch['quantity']; ?></td>
                                            <td><span class="rsb-source-badge <?php echo htmlspecialchars($batch['source']); ?>"><?php echo htmlspecialchars($sourceLabels[$batch['source']] ?? 'Unknown'); ?></span></td>
                                            <td><?php echo htmlspecialchars($batch['received_at']); ?></td>
                                            <td><?php echo htmlspecialchars($batch['recorded_by']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </main>
    </div>

    <!-- Import review (opened after a file is dropped on the "From Approved PO" tab) -->
    <div class="modal-backdrop" id="importModal" aria-hidden="true">
        <div class="modal-box dr-modal-box" role="dialog" aria-modal="true" aria-labelledby="importModalTitle">
            <div class="dr-modal-header">
                <h3 id="importModalTitle">Review stock import</h3>
                <button type="button" class="dr-modal-close" data-close-modal="importModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="dr-modal-body">
                <?= csrf_field() ?>
                <p id="importSummary" class="po-review-banner info"></p>
                <div class="po-grid-wrap">
                    <table class="po-grid" id="importGrid">
                        <thead>
                            <tr>
                                <th style="width:34px;"><input type="checkbox" id="importCheckAll" aria-label="Select all matched rows" checked></th>
                                <th>Row</th>
                                <th style="min-width:150px;">In your file</th>
                                <th style="min-width:190px;">Matched to</th>
                                <th>In stock</th>
                                <th style="min-width:90px;">Qty to add</th>
                                <th style="min-width:110px;">Batch No.</th>
                                <th style="min-width:130px;">Expiry date</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <p id="importReviewError" class="form-error" role="alert" hidden></p>
            </div>
            <div class="dr-modal-actions">
                <button type="button" class="btn btn-secondary" data-close-modal="importModal">Cancel</button>
                <button type="button" class="btn btn-primary" id="importConfirmBtn" disabled>Add to Stock</button>
            </div>
        </div>
    </div>

    <div id="rsbToastHost" class="scan-toast-host"></div>

    <script>
        (function() {
            const searchInput = document.getElementById('medicineSearch');
            const rows = Array.from(document.querySelectorAll('.ic-medicine-row'));
            const sourceSelect = document.getElementById('stockSource');
            const donorNotesWrap = document.getElementById('donorNotesWrap');
            const donorNotesField = document.getElementById('donorNotes');
            const form = document.getElementById('recordBatchForm');
            const errorBox = document.getElementById('recordBatchError');

            const tabExisting = document.getElementById('tabExisting');
            const tabNew = document.getElementById('tabNew');
            const tabPr = document.getElementById('tabPr');
            const existingPanel = document.getElementById('existingMedicinePanel');
            const newPanel = document.getElementById('newMedicinePanel');
            const prPanel = document.getElementById('prPanel');
            const formAction = document.getElementById('formAction');
            const newGenericName = document.getElementById('newGenericName');
            const newUnit = document.getElementById('newUnit');
            const newUnitsPerBox = document.getElementById('newUnitsPerBox');

            function setMode(mode) {
                const isNew = mode === 'new';
                const isPr = mode === 'pr';
                tabExisting.classList.toggle('is-active', mode === 'existing');
                tabExisting.setAttribute('aria-selected', String(mode === 'existing'));
                tabNew.classList.toggle('is-active', isNew);
                tabNew.setAttribute('aria-selected', String(isNew));
                tabPr.classList.toggle('is-active', isPr);
                tabPr.setAttribute('aria-selected', String(isPr));

                form.hidden = isPr;
                prPanel.hidden = !isPr;
                existingPanel.hidden = isNew || isPr;
                newPanel.hidden = !isNew || isPr;
                formAction.value = isNew ? 'record_new_medicine' : 'record_batch';

                // Only the fields relevant to the active mode should be required,
                // so switching tabs doesn't block submission on hidden inputs.
                newGenericName.required = isNew;
                newUnit.required = isNew;
                newUnitsPerBox.required = isNew;
                rows.forEach(function(row) {
                    row.querySelector('input[type="radio"]').required = mode === 'existing';
                });

                errorBox.hidden = true;
                errorBox.textContent = '';
            }

            tabExisting.addEventListener('click', function() {
                setMode('existing');
            });
            tabNew.addEventListener('click', function() {
                setMode('new');
            });
            tabPr.addEventListener('click', function() {
                setMode('pr');
            });
            setMode(<?php echo $openPrTab ? "'pr'" : "'existing'"; ?>);

            function syncSelectedMedicineRow() {
                rows.forEach(function(row) {
                    const input = row.querySelector('input[type="radio"]');
                    row.classList.toggle('is-selected', !!input && input.checked);
                });
            }

            function syncSourceFields() {
                const isDonated = sourceSelect.value === 'donated';
                donorNotesWrap.hidden = !isDonated;
                donorNotesField.required = isDonated;
            }

            rows.forEach(function(row) {
                const radio = row.querySelector('input[type="radio"]');
                radio.addEventListener('change', syncSelectedMedicineRow);
            });

            sourceSelect.addEventListener('change', syncSourceFields);
            syncSourceFields();
            syncSelectedMedicineRow();

            searchInput.addEventListener('input', function() {
                const query = searchInput.value.trim().toLowerCase();
                rows.forEach(function(row) {
                    const text = row.dataset.name || '';
                    row.style.display = !query || text.includes(query) ? '' : 'none';
                });
            });

            form.addEventListener('submit', async function(event) {
                event.preventDefault();
                const button = form.querySelector('button[type="submit"]');
                const data = new FormData(form);
                const csrfToken = form.querySelector('[name="csrf_token"]').value;
                data.append('csrf_token', csrfToken);
                errorBox.hidden = true;
                errorBox.textContent = '';
                button.disabled = true;

                try {
                    const response = await fetch('record-stock-batch-actions.php', {
                        method: 'POST',
                        body: data,
                    });
                    const result = await response.json();
                    if (!response.ok || !result.success) {
                        throw new Error(result.error || 'Could not record the batch.');
                    }
                    form.reset();
                    setMode('existing');
                    syncSourceFields();
                    window.location.reload();
                } catch (err) {
                    errorBox.textContent = err.message || 'Could not record the batch.';
                    errorBox.hidden = false;
                    button.disabled = false;
                }
            });
        })();

        // ------------------------------------------------------------------
        // "From Approved PO" tab: drag-and-drop import. Dropping (or picking) a
        // medicine list reads it, matches it to the catalog and opens a review
        // dialog; nothing changes until "Add to Stock" is pressed. The stock is
        // added as Purchase Order stock, one batch per row, by
        // stock-import-actions.php ('confirm') - this script only reads, shows
        // and submits.
        // ------------------------------------------------------------------
        (function() {
            const modal = document.getElementById('importModal');
            const dropzone = document.getElementById('importDropzone');
            const fileInput = document.getElementById('importFile');
            const dzTitle = document.getElementById('importDzTitle');
            const dzMsg = document.getElementById('importDzMsg');
            const modalTitle = document.getElementById('importModalTitle');
            const reviewError = document.getElementById('importReviewError');
            const summary = document.getElementById('importSummary');
            const gridBody = document.querySelector('#importGrid tbody');
            const checkAll = document.getElementById('importCheckAll');
            const confirmBtn = document.getElementById('importConfirmBtn');
            const csrf = modal.querySelector('[name="csrf_token"]').value;
            const DZ_TITLE = 'Drag a file here, or click to browse';
            const DZ_HINT = 'Accepts .csv or .xlsx';
            let items = [];

            function openModal() {
                reviewError.hidden = true;
                modal.classList.add('active');
                document.body.classList.add('modal-open');
            }

            function closeModal() {
                modal.classList.remove('active');
                document.body.classList.remove('modal-open');
                gridBody.innerHTML = '';
                items = [];
            }

            modal.querySelectorAll('[data-close-modal="importModal"]').forEach(function(btn) {
                btn.addEventListener('click', closeModal);
            });
            modal.addEventListener('click', function(e) {
                if (e.target === modal) closeModal();
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('active')) closeModal();
            });

            function cell(tr, content, className) {
                const td = document.createElement('td');
                if (className) td.className = className;
                if (content instanceof Node) {
                    td.appendChild(content);
                } else if (content !== null && content !== undefined) {
                    td.textContent = content;
                }
                tr.appendChild(td);
                return td;
            }

            function input(type, value, extra) {
                const el = document.createElement('input');
                el.type = type;
                el.value = value === null || value === undefined ? '' : value;
                Object.assign(el, extra || {});
                return el;
            }

            function badge(cls, text) {
                const span = document.createElement('span');
                span.className = 'po-variance ' + cls;
                span.textContent = text;
                return span;
            }

            function note(text) {
                const span = document.createElement('span');
                span.className = 'imp-note';
                span.textContent = text;
                return span;
            }

            function renderRows() {
                gridBody.innerHTML = '';
                items.forEach(function(item, index) {
                    const tr = document.createElement('tr');
                    tr.dataset.index = String(index);
                    const ok = item.status === 'matched';
                    if (!ok) tr.className = 'imp-skipped';

                    const check = input('checkbox', '', {
                        checked: ok,
                        disabled: !ok
                    });
                    check.className = 'f-include';
                    check.setAttribute('aria-label', 'Add row ' + item.line);
                    cell(tr, check);
                    cell(tr, String(item.line), 'imp-num');
                    cell(tr, item.file_name || '\u2014');

                    const matchCell = cell(tr, null);
                    if (ok) {
                        const name = document.createElement('strong');
                        name.textContent = item.medicine_name;
                        matchCell.appendChild(name);
                        matchCell.appendChild(note(item.unit || ''));
                        if (item.note) matchCell.appendChild(note(item.note));
                    } else {
                        matchCell.appendChild(badge('short', item.status === 'invalid' ? 'Skipped' : (item.status === 'ambiguous' ? 'Unclear match' : 'Not matched')));
                        matchCell.appendChild(note(item.note));
                    }

                    cell(tr, ok ? String(item.current_stock) : '\u2014', 'imp-num');
                    if (ok) {
                        const qty = input('number', item.qty, {
                            min: 1,
                            step: 1
                        });
                        qty.className = 'f-qty';
                        cell(tr, qty);
                        const batch = input('text', item.batch_no, {
                            maxLength: 100
                        });
                        batch.className = 'f-batch';
                        cell(tr, batch);
                        const expiry = input('date', item.expiry_date);
                        expiry.className = 'f-expiry';
                        cell(tr, expiry);
                    } else {
                        cell(tr, item.qty ? String(item.qty) : '\u2014', 'imp-num');
                        cell(tr, '');
                        cell(tr, '');
                    }
                    gridBody.appendChild(tr);
                });
            }

            // Rows that will be added: matched, ticked, with a valid quantity.
            function chosenRows() {
                const rows = [];
                let problem = '';
                gridBody.querySelectorAll('tr').forEach(function(tr) {
                    const check = tr.querySelector('.f-include');
                    if (!check || check.disabled || !check.checked) return;
                    const qtyText = tr.querySelector('.f-qty').value.trim();
                    const item = items[Number(tr.dataset.index)];
                    if (!/^\d+$/.test(qtyText) || Number(qtyText) < 1) {
                        if (!problem) problem = 'Row ' + item.line + ': enter a whole-number quantity above 0, or untick the row.';
                        return;
                    }
                    rows.push({
                        medicine_id: item.medicine_id,
                        qty: qtyText,
                        batch_no: tr.querySelector('.f-batch').value.trim(),
                        expiry_date: tr.querySelector('.f-expiry').value,
                        unit_price: item.unit_price,
                        selling_price: item.selling_price,
                    });
                });
                return {
                    rows: rows,
                    problem: problem
                };
            }

            function totalUnits(rows) {
                return rows.reduce(function(sum, r) {
                    return sum + Number(r.qty);
                }, 0);
            }

            let leftOutNote = '';

            function refreshSummary() {
                const chosen = chosenRows();
                const units = totalUnits(chosen.rows);
                const skipped = items.filter(function(i) {
                    return i.status !== 'matched';
                }).length;
                let text = chosen.rows.length + ' of ' + items.length + ' row' + (items.length === 1 ? '' : 's') + ' will be added \u2014 ' + units.toLocaleString() + ' unit' + (units === 1 ? '' : 's') + ' in total.';
                if (skipped) text += ' ' + skipped + ' row' + (skipped === 1 ? ' was' : 's were') + ' skipped (see the reason under each).';
                summary.textContent = text + leftOutNote;
                summary.className = 'po-review-banner ' + (skipped ? 'warning' : 'info');
                confirmBtn.disabled = chosen.rows.length === 0;
                reviewError.hidden = true;
            }

            gridBody.addEventListener('input', refreshSummary);
            gridBody.addEventListener('change', refreshSummary);
            checkAll.addEventListener('change', function() {
                gridBody.querySelectorAll('.f-include:not(:disabled)').forEach(function(c) {
                    c.checked = checkAll.checked;
                });
                refreshSummary();
            });

            function dzReset() {
                dropzone.classList.remove('is-busy');
                dzTitle.textContent = DZ_TITLE;
                dzMsg.textContent = DZ_HINT;
                dzMsg.classList.remove('is-error');
            }

            function dzError(text) {
                dropzone.classList.remove('is-busy');
                dzTitle.textContent = DZ_TITLE;
                dzMsg.textContent = text;
                dzMsg.classList.add('is-error');
            }

            async function readFile(file) {
                if (!file) return;
                dzMsg.classList.remove('is-error');
                if (!/\.(csv|xlsx|txt)$/i.test(file.name)) {
                    dzError('Please choose a .csv or .xlsx file.');
                    return;
                }
                dropzone.classList.add('is-busy');
                dzTitle.textContent = 'Reading ' + file.name + '\u2026';
                dzMsg.textContent = 'Matching it to the catalog';

                const formData = new FormData();
                formData.append('import_file', file);
                formData.append('csrf_token', csrf);
                formData.append('action', 'parse_upload');
                try {
                    const res = await fetch('stock-import-actions.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await res.json();
                    if (!res.ok || !result.success) {
                        throw new Error(result.error || 'Could not read that file.');
                    }
                    items = result.items;
                    const noQty = result.summary ? result.summary.left_out_no_qty : 0;
                    leftOutNote = noQty > 0 ? ' ' + noQty + ' row' + (noQty === 1 ? '' : 's') + ' with no quantity ' + (noQty === 1 ? 'was' : 'were') + ' left out.' : '';
                    modalTitle.textContent = 'Review stock import \u2014 ' + file.name;
                    checkAll.checked = true;
                    renderRows();
                    refreshSummary();
                    dzReset();
                    openModal();
                } catch (err) {
                    dzError(err.message || 'Could not read that file.');
                } finally {
                    fileInput.value = '';
                }
            }

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
                if (e.dataTransfer && e.dataTransfer.files.length) readFile(e.dataTransfer.files[0]);
            });
            fileInput.addEventListener('change', function() {
                if (fileInput.files.length) readFile(fileInput.files[0]);
            });

            confirmBtn.addEventListener('click', async function() {
                reviewError.hidden = true;
                const chosen = chosenRows();
                if (chosen.problem) {
                    reviewError.textContent = chosen.problem;
                    reviewError.hidden = false;
                    return;
                }
                if (chosen.rows.length === 0) return;
                const units = totalUnits(chosen.rows);
                if (!window.confirm('Add ' + units.toLocaleString() + ' unit(s) across ' + chosen.rows.length + ' row(s) to stock as Purchase Order stock?\n\nA new batch is created for each row. This adds to the current stock; it does not replace it.')) {
                    return;
                }
                confirmBtn.disabled = true;
                try {
                    const res = await fetch('stock-import-actions.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            action: 'confirm',
                            csrf_token: csrf,
                            items: chosen.rows
                        })
                    });
                    const result = await res.json();
                    if (!res.ok || !result.success) {
                        throw new Error(result.error || 'Could not add this stock.');
                    }
                    alert('Added ' + Number(result.units).toLocaleString() + ' unit(s) across ' + result.medicines + ' medicine(s) (' + result.batches + ' batch' + (result.batches === 1 ? '' : 'es') + ').');
                    window.location.reload();
                } catch (err) {
                    reviewError.textContent = err.message || 'Could not add this stock.';
                    reviewError.hidden = false;
                    confirmBtn.disabled = false;
                }
            });
        })();
    </script>
</body>

</html>