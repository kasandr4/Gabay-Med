<?php
// pharmacist/purchase-requests.php
//
// Head Pharmacist review of Purchase Requests. Staff (inventory) submit
// them from Restock Management; the pharmacist can View, Modify (while
// it is still 'open', shown as "Submitted"), then Approve or Reject.
// Approving locks the PR: no further edits by anyone, and it can be
// printed or exported as CSV. GabayMed does not send it to Capitol -
// the printed/CSV PR is forwarded manually, outside the system - and
// stock only changes later, at Receiving.
//
// PHASE 5 (2026-09): Receiving now happens against the approved PR
// itself. An approved PR that hasn't been received (and isn't already on
// a Purchase Order) gets a "Receive Stock" button that opens the
// "From Approved PR" tab on record-stock-batch.php; once stocked in, the
// row shows "Received {date}" and links to the read-only record. The
// status stays 'approved' on purpose so received PRs remain printable and
// exportable - "received" is purchase_requests.received_at IS NOT NULL.
//
// PHASE 5b (2026-09): the pharmacist no longer creates purchase requests
// here. There is no "New Purchase Request" button, no "Import from file"
// and no Restock Needs panel - requests come from the inventory staff's
// Restock Management page. What is left is review: View / Print, Modify
// (rows can still be added, changed or removed on a request that is
// still 'open'), Approve, Reject, Cancel, and Receive Stock. The Modify
// form is the same item grid the old create form used.
//
// FIXED 2026-09-21: restored from an uploaded copy of the live file
// after this session's working copy was found to be missing the entire
// "Restock Needs" panel below (root cause not conclusively identified -
// see chat history). This version is the uploaded live file, with only
// the Capitol-return workflow changes applied on top (the "Returned by
// Capitol" note distinction, the Reopen button, and removing the stale
// "Convert to PO" link now that PO creation lives in capitol/
// purchase-orders.php) - nothing else was touched.

require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/csrf.php';

$pharmacistId = (int) $_SESSION['user_id'];

// The units a purchase request can be written in. Deliberately not the
// full medicine_units list (Tablet, Capsule, Ampule...): a PR is in units
// of issue - what the hospital orders and Capitol delivers. 'Box' is added
// to medicine_units by 033_add_box_unit.sql so the server-side unit check
// in purchase-request-actions.php accepts it.
$units = ['Box', 'Bottle', 'Piece'];

// Catalog lookup for the medicine-name datalist + auto-fill on the client.
// CATEGORY RETIRED: the equipment/supplies exclusion here was based on
// medicine_names.category; the pharmacy only manages medicine stock (no
// equipment/supplies in the real inventory), so that column and this
// filter were both removed — see the migration dropping
// medicine_categories / medicine_names.category.
$catalog = [];
$catalogResult = $conn->query(
    "SELECT im.medicine_id, im.name, mn.name AS generic_name, im.strength, im.unit, im.units_per_box,
            im.current_stock, im.minimum_stock
     FROM inventory_medicines im
     JOIN medicine_names mn ON mn.generic_id = im.generic_id
     ORDER BY im.name ASC"
);
if ($catalogResult) {
    while ($row = $catalogResult->fetch_assoc()) {
        $catalog[] = [
            'id' => (int) $row['medicine_id'],
            'name' => $row['name'],
            // generic_name here is medicine_names.name (e.g. "Amoxicillin") -
            // deliberately NOT the same as 'name' above (im.name, e.g.
            // "Amoxicillin 500mg").
            'generic_name' => $row['generic_name'],
            'strength' => $row['strength'],
            'unit' => $row['unit'],
            'units_per_box' => $row['units_per_box'] !== null ? (int) $row['units_per_box'] : null,
            'current_stock' => (int) $row['current_stock'],
            'minimum_stock' => (int) $row['minimum_stock'],
        ];
    }
}

function pr_status_label(string $status): string
{
    $labels = [
        'open' => 'Submitted',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        // Legacy values from the retired Capitol hand-off; treated as approved.
        'sent_to_capitol' => 'Approved',
        'converted' => 'Approved',
        'cancelled' => 'Cancelled',
    ];
    return $labels[$status] ?? ucfirst($status);
}

$requests = [];
$listQuery = $conn->query(
    "SELECT pr.pr_id, pr.pr_no, pr.purpose, pr.status, pr.created_at,
            pr.approved_by_name, pr.approved_at, pr.rejected_at, pr.rejection_reason,
            pr.sent_to_capitol_at, pr.capitol_log_ref, pr.received_at,
            (SELECT po.po_no FROM purchase_orders po
              WHERE po.pr_id = pr.pr_id AND po.status <> 'cancelled'
              ORDER BY po.po_id DESC LIMIT 1) AS linked_po_no,
            CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS requested_by_name,
            (SELECT COUNT(*) FROM purchase_request_items pri WHERE pri.pr_id = pr.pr_id) AS item_count
     FROM purchase_requests pr
     LEFT JOIN users u ON u.user_id = pr.requested_by
     ORDER BY pr.created_at DESC, pr.pr_id DESC
     LIMIT 100"
);
if ($listQuery) {
    while ($row = $listQuery->fetch_assoc()) {
        $requests[] = $row;
    }
}

// Pre-load purpose/notes/items for PRs the pharmacist can still Modify
// (status 'open'), so the Modify button can fill the form without an
// extra round-trip.
$openPrData = [];
$openPrIds = [];
foreach ($requests as $req) {
    if ($req['status'] === 'open') {
        $openPrIds[] = (int) $req['pr_id'];
    }
}
if (!empty($openPrIds)) {
    $idList = implode(',', $openPrIds);
    $hdrRes = $conn->query("SELECT pr_id, purpose, notes FROM purchase_requests WHERE pr_id IN ($idList)");
    if ($hdrRes) {
        while ($h = $hdrRes->fetch_assoc()) {
            $openPrData[(int) $h['pr_id']] = ['purpose' => $h['purpose'] ?? '', 'notes' => $h['notes'] ?? '', 'items' => []];
        }
    }
    $itemRes = $conn->query(
        "SELECT pr_id, medicine_id, generic_name, brand, strength, unit, units_per_box, qty_requested,
                unit_price_estimated, notes
         FROM purchase_request_items WHERE pr_id IN ($idList) ORDER BY pri_id ASC"
    );
    if ($itemRes) {
        while ($it = $itemRes->fetch_assoc()) {
            $openPrData[(int) $it['pr_id']]['items'][] = $it;
        }
    }
}

$current_page = 'purchase-requests';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Requests - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/pharmacist-dashboard.css">
    <link rel="stylesheet" href="../assets/css/inventory-count.css">
    <link rel="stylesheet" href="../assets/css/procurement.css">
    <style>
        .main-content {
            padding: 28px 30px 32px;
        }


        /* Item detail has 2 fields (Strength, Notes): one row, Notes wider.
           Funding source is not a field - every PR line is Purchase Order,
           set by purchase-request-actions.php. */
        #prForm .po-item-row-detail {
            grid-template-columns: 1fr 2fr;
        }

        #prForm .po-item-row-detail>div:last-child {
            grid-column: auto;
        }
    </style>
</head>

<body>
    <div class="app-shell">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">
            <header class="page-header po-page-header">
                <div>

                    <h1>Purchase Requests</h1>
                    <p class="page-subtitle">Review the requests submitted by inventory staff. Approve or reject them; stock only changes when an approved request is received.</p>
                </div>
            </header>

            <section class="card" id="createPrCard" hidden>
                <div class="card-header">
                    <div>
                        <h2 id="createPrTitle">Modify Purchase Request</h2>
                        <span class="card-subtitle">Add, change or remove items. A request can only be modified until it is approved.</span>
                    </div>
                </div>
                <div style="padding: 18px 20px;">
                    <form id="prForm">
                        <?= csrf_field() ?>
                        <div class="po-field-row">
                            <div class="po-field" style="grid-column: span 2;">
                                <label for="prPurpose">Purpose (Optional)</label>
                                <input id="prPurpose" name="purpose" maxlength="255" placeholder="e.g. Monthly restock - antibiotics">
                            </div>
                            <div class="po-field">
                                <label for="prNotes">Notes (Optional)</label>
                                <input id="prNotes" name="notes" maxlength="255">
                            </div>
                        </div>

                        <button type="button" class="btn btn-secondary" id="addRowBtn">+ Add Item</button>

                        <div class="po-item-rows" id="itemGridBody"></div>
                        <p id="gridEmptyMsg" class="po-empty">No items yet. Click "+ Add Item".</p>

                        <p id="prError" class="form-error" role="alert" hidden></p>
                        <div class="modal-actions rsb-actions-bar" style="margin-top:16px;">
                            <span class="rsb-required-note">Only a medicine name and quantity are required here. Unit can be left blank for now.</span>
                            <div class="rsb-actions-buttons">
                                <button type="button" class="btn btn-secondary" id="cancelPrBtn">Cancel</button>
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                            </div>
                        </div>
                    </form>
                </div>
            </section>

            <section class="card" style="margin-top: 20px;">
                <div class="card-header">
                    <div>
                        <h2>All Purchase Requests</h2>
                        <span class="card-subtitle">Most recent first</span>
                    </div>
                </div>
                <?php if (empty($requests)): ?>
                    <div class="po-empty">No purchase requests yet.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>PR No.</th>
                                    <th>Purpose</th>
                                    <th>Items</th>
                                    <th>Requested By</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requests as $req): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($req['pr_no']); ?></td>
                                        <td><?php echo htmlspecialchars($req['purpose'] ?: '—'); ?></td>
                                        <td><?php echo (int) $req['item_count']; ?></td>
                                        <td><?php echo htmlspecialchars(trim($req['requested_by_name']) ?: 'Unknown'); ?></td>
                                        <td><?php echo htmlspecialchars(date('M j, Y', strtotime($req['created_at']))); ?></td>
                                        <td>
                                            <?php $isReceived = $req['status'] === 'approved' && $req['received_at'] !== null; ?>
                                            <span class="po-status-badge <?php echo $isReceived ? 'received' : htmlspecialchars($req['status']); ?>"><?php echo $isReceived ? 'Received' : htmlspecialchars(pr_status_label($req['status'])); ?></span>
                                            <?php if ($isReceived): ?>
                                                <div class="pr-status-note">Received <?php echo htmlspecialchars(date('M j, Y', strtotime($req['received_at']))); ?></div>
                                            <?php endif; ?>
                                            <?php if ($req['status'] === 'approved' && $req['approved_by_name']): ?>
                                                <div class="pr-status-note">Approved by <?php echo htmlspecialchars($req['approved_by_name']); ?><?php echo $req['approved_at'] ? ', ' . htmlspecialchars(date('M j, Y', strtotime($req['approved_at']))) : ''; ?></div>
                                            <?php elseif ($req['status'] === 'rejected' && $req['rejection_reason']): ?>
                                                <div class="pr-status-note pr-status-note-warn">Reason: <?php echo htmlspecialchars($req['rejection_reason']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="print-purchase-request.php?pr_id=<?php echo (int) $req['pr_id']; ?>" target="_blank" class="btn btn-secondary" style="padding:6px 10px;font-size:12px;">Print</a>
                                            <?php if ($req['status'] === 'open'): ?>
                                                <button type="button" class="btn btn-secondary modify-pr-btn" data-pr-id="<?php echo (int) $req['pr_id']; ?>" style="padding:6px 10px;font-size:12px;">Modify</button>
                                                <button type="button" class="btn btn-secondary approve-pr-btn" data-pr-id="<?php echo (int) $req['pr_id']; ?>" style="padding:6px 10px;font-size:12px;">Approve</button>
                                                <button type="button" class="btn btn-secondary reject-pr-btn" data-pr-id="<?php echo (int) $req['pr_id']; ?>" style="padding:6px 10px;font-size:12px;">Reject</button>
                                                <button type="button" class="btn btn-secondary cancel-pr-btn" data-pr-id="<?php echo (int) $req['pr_id']; ?>" style="padding:6px 10px;font-size:12px;">Cancel</button>
                                            <?php elseif (in_array($req['status'], ['approved', 'sent_to_capitol', 'converted'], true)): ?>
                                                <?php if ($req['status'] === 'approved' && $req['received_at'] !== null): ?>
                                                    <a href="record-stock-batch.php?pr_id=<?php echo (int) $req['pr_id']; ?>" class="btn btn-secondary" style="padding:6px 10px;font-size:12px;">View Receiving</a>
                                                <?php elseif ($req['status'] === 'approved' && empty($req['linked_po_no'])): ?>
                                                    <a href="record-stock-batch.php?pr_id=<?php echo (int) $req['pr_id']; ?>" class="btn btn-primary" style="padding:6px 10px;font-size:12px;">Receive Stock</a>
                                                <?php elseif (!empty($req['linked_po_no'])): ?>
                                                    <span class="pr-status-note" title="This request already has a Purchase Order, so it is received from Purchase Orders.">On <?php echo htmlspecialchars($req['linked_po_no']); ?></span>
                                                <?php endif; ?>
                                                <a href="export-purchase-request.php?pr_id=<?php echo (int) $req['pr_id']; ?>" class="btn btn-primary" style="padding:6px 10px;font-size:12px;">Export CSV</a>
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

    <!-- Approve modal -->
    <div class="modal-backdrop" id="approvePrModal" aria-hidden="true">
        <div class="modal-box dr-modal-box" role="dialog" aria-modal="true" aria-labelledby="approvePrModalTitle">
            <div class="dr-modal-header">
                <h3 id="approvePrModalTitle">Approve Purchase Request</h3>
                <button type="button" class="dr-modal-close" data-close-modal="approvePrModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <form id="approvePrForm">
                <div class="dr-modal-body">
                    <p class="resubmit-admin-note">Approving locks this request: it can no longer be modified, and it becomes available to print or export as CSV. The approval is recorded under your name.</p>
                    <p id="approvePrError" class="form-error" role="alert" hidden></p>
                </div>
                <div class="dr-modal-actions">
                    <button type="button" class="btn btn-secondary" data-close-modal="approvePrModal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Approve</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Log Rejection modal -->
    <div class="modal-backdrop" id="rejectPrModal" aria-hidden="true">
        <div class="modal-box dr-modal-box" role="dialog" aria-modal="true" aria-labelledby="rejectPrModalTitle">
            <div class="dr-modal-header">
                <h3 id="rejectPrModalTitle">Log Rejection</h3>
                <button type="button" class="dr-modal-close" data-close-modal="rejectPrModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <form id="rejectPrForm">
                <div class="dr-modal-body">
                    <p class="resubmit-admin-note">Record that the Chief of Hospital declined to sign this PR, and why.</p>
                    <div class="dr-field">
                        <label for="rejectionReason">Reason</label>
                        <textarea id="rejectionReason" rows="3" maxlength="255" required></textarea>
                    </div>
                    <p id="rejectPrError" class="form-error" role="alert" hidden></p>
                </div>
                <div class="dr-modal-actions">
                    <button type="button" class="btn btn-secondary" data-close-modal="rejectPrModal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Log Rejection</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function() {
            const catalog = <?php echo json_encode($catalog); ?>;
            const openPrData = <?php echo json_encode($openPrData); ?>;
            let editingPrId = null; // set while the form is editing an existing PR (Modify)
            const units = <?php echo json_encode($units); ?>;

            // Lets a medicine picked from the name suggestions link back to its
            // catalog entry (and pull its strength) - see the name field's
            // change handler in addRow().
            const catalogByName = {};
            catalog.forEach(function(m) {
                catalogByName[m.name.toLowerCase()] = m;
            });

            const createCard = document.getElementById('createPrCard');
            const cancelPrBtn = document.getElementById('cancelPrBtn');
            const gridBody = document.getElementById('itemGridBody');
            const gridEmptyMsg = document.getElementById('gridEmptyMsg');
            const addRowBtn = document.getElementById('addRowBtn');
            const prForm = document.getElementById('prForm');
            const prError = document.getElementById('prError');

            // Modify: open the item form pre-filled with the PR's saved items;
            // submit calls the 'update' action for that request.
            function setEditMode(prId) {
                editingPrId = prId;
            }
            document.querySelectorAll('.modify-pr-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const data = openPrData[btn.dataset.prId];
                    if (!data) return;
                    gridBody.innerHTML = '';
                    prForm.reset();
                    prError.hidden = true;
                    setEditMode(parseInt(btn.dataset.prId, 10));
                    document.getElementById('prPurpose').value = data.purpose || '';
                    document.getElementById('prNotes').value = data.notes || '';
                    data.items.forEach(function(item) {
                        addRow(item, false);
                    });
                    createCard.hidden = false;
                    createCard.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                });
            });
            cancelPrBtn.addEventListener('click', function() {
                setEditMode(null);
                createCard.hidden = true;
                gridBody.innerHTML = '';
                prForm.reset();
                refreshEmptyState();
            });

            function optionList(values, selected) {
                // A saved unit outside the list (e.g. 'Tablet' on a request
                // the inventory staff submitted) stays selectable so opening
                // Modify doesn't silently blank it; new picks are the list only.
                const all = selected && values.indexOf(selected) === -1 ? values.concat([selected]) : values;
                return '<option value="">Select</option>' + all.map(function(v) {
                    return '<option value="' + escapeHtml(v) + '"' + (v === selected ? ' selected' : '') + '>' + escapeHtml(v) + '</option>';
                }).join('');
            }

            // Pulls the strength out of a medicine name, e.g.
            //   "Acetylcysteine 200mg (Powder for oral solution) Sachet" -> "200mg"
            //   "Gentamycin sulfate 40mg/ml, 2ml (Ampule)"               -> "40mg/ml, 2ml"
            // Parenthetical dosage forms are ignored, a trailing "- MRP ..."
            // price note is dropped, and trailing form words ("... 60ml
            // Suspension") are trimmed off. Returns '' when the name has no
            // number-plus-unit (e.g. "Insulin Regular (Humulin R)"). Only
            // ever used to pre-fill an EMPTY Strength box - the pharmacist
            // can always overwrite it. Checked against all 426 medicine names
            // in the catalog: 414 give a strength, the other 12 have none.
            function extractStrength(name) {
                let text = String(name || '').replace(/\s+-\s+MRP\b.*$/i, '').replace(/\([^()]*\)/g, ' ');
                const unitPattern = 'mcg|mg|µg|ug|kg|grams?|g|ml|l|m?\\s?i\\.?u\\.?|lsu|meq|mmol|doses?|%';
                const m = text.match(new RegExp('(?:^|\\s)(\\d[\\d.,/]*\\s?(?:' + unitPattern + ')(?![a-z]).*)$', 'i'));
                if (!m) return '';
                const unitLike = new RegExp('^(?:[\\d.,/]+\\s*)?(?:' + unitPattern + ')', 'i');
                const tokens = m[1].trim().split(/\s+/);
                while (tokens.length > 1) {
                    const last = tokens[tokens.length - 1];
                    if (/\d/.test(last) || unitLike.test(last) || /^[\/+,.-]+$/.test(last)) break;
                    tokens.pop();
                }
                return tokens.join(' ').replace(/[\s,]+$/, '').slice(0, 50);
            }

            // Keeps the medicine name and the Strength box from repeating each
            // other. Strength is filled from the name (only while the box is
            // empty or still holds an earlier auto-fill - never over a value
            // typed by hand or taken from the catalog), and whatever strength
            // the box then holds is TAKEN OUT of the name:
            //   name "Aluminum hydroxide/Magnesium hydroxide 200/100mg/5ml, 120ml (Suspension)"
            //   -> name "Aluminum hydroxide/Magnesium hydroxide (Suspension)"
            //      strength "200/100mg/5ml, 120ml"
            // Left alone: a strength that sits inside brackets (the catalog's
            // "Insulin Regular (Humulin R)" has strength "Humulin R"), and any
            // case where removing it would empty the name.
            function splitStrengthFromName(row) {
                const nameBox = row.querySelector('.f-name');
                const box = row.querySelector('.f-strength');
                if (box.value.trim() === '' || box.value === box.dataset.auto) {
                    const guess = extractStrength(nameBox.value);
                    box.value = guess;
                    box.dataset.auto = guess;
                }
                const strength = box.value.trim();
                if (strength === '') return;

                // Tolerant of extra spaces between the strength's words.
                const pattern = strength.split(/\s+/).map(function(t) {
                    return t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                }).join('\\s+');
                const name = nameBox.value;
                let last = null;
                const re = new RegExp(pattern, 'gi');
                let hit;
                while ((hit = re.exec(name)) !== null) {
                    last = hit;
                    if (hit[0].length === 0) re.lastIndex++;
                }
                if (!last) return;
                const before = name.slice(0, last.index);
                if ((before.match(/\(/g) || []).length !== (before.match(/\)/g) || []).length) return; // inside brackets
                const cleaned = (before + ' ' + name.slice(last.index + last[0].length))
                    .replace(/\s+/g, ' ')
                    .replace(/\s+([,;])/g, '$1')
                    .trim();
                if (cleaned !== '') nameBox.value = cleaned;
            }

            function escapeHtml(str) {
                const div = document.createElement('div');
                div.textContent = str == null ? '' : String(str);
                return div.innerHTML;
            }

            const chevronSvg = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>';
            const trashSvg = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';

            // startOpen: a fresh blank row ("+ Add Item") opens expanded so
            // nothing's hidden while typing; rows loaded from a saved request
            // (Modify) start collapsed so a long request doesn't open as
            // dozens of expanded rows at once.
            function addRow(data, startOpen) {
                data = data || {};
                const row = document.createElement('div');
                row.className = 'po-item-row' + (startOpen ? ' open' : '');
                row.innerHTML = `
                    <div class="po-item-row-head">
                        <span class="po-row-chevron">${chevronSvg}</span>
                        <input type="text" class="f-name" list="catalogNames" maxlength="150" placeholder="Medicine (generic name)" value="${escapeHtml(data.generic_name)}">
                        <select class="f-unit">${optionList(units, data.unit || '')}</select>
                        <input type="number" class="f-qty" min="1" step="1" placeholder="Qty" value="${escapeHtml(data.qty_requested)}">
                        <button type="button" class="po-remove-row" title="Remove row">${trashSvg}</button>
                    </div>
                    <div class="po-item-row-detail">
                        <div><label>Strength</label><input type="text" class="f-strength" maxlength="50" value="${escapeHtml(data.strength)}"></div>
                        <div><label>Notes</label><input type="text" class="f-notes" maxlength="255" value="${escapeHtml(data.notes)}"></div>
                    </div>
                `;
                row.dataset.medicineId = data.medicine_id || '';
                const nameInput = row.querySelector('.f-name');
                splitStrengthFromName(row);
                nameInput.addEventListener('change', function() {
                    const match = catalogByName[nameInput.value.trim().toLowerCase()];
                    if (match) {
                        row.dataset.medicineId = match.id;
                        row.querySelector('.f-strength').value = match.strength || '';
                        // Catalog units are dispensing units (Tablet, Capsule...);
                        // only carry one over if it is also a unit of issue.
                        row.querySelector('.f-unit').value = units.indexOf(match.unit) !== -1 ? match.unit : '';
                    } else {
                        row.dataset.medicineId = '';
                    }
                    splitStrengthFromName(row);
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
                return row;
            }

            function refreshEmptyState() {
                gridEmptyMsg.hidden = gridBody.children.length > 0;
            }

            addRowBtn.addEventListener('click', function() {
                addRow({}, true);
            });

            prForm.addEventListener('submit', async function(event) {
                event.preventDefault();
                prError.hidden = true;
                const rows = Array.from(gridBody.querySelectorAll('.po-item-row'));
                if (rows.length === 0) {
                    prError.textContent = 'Add at least one item.';
                    prError.hidden = false;
                    return;
                }
                const items = rows.map(function(row) {
                    return {
                        medicine_id: row.dataset.medicineId || null,
                        generic_name: row.querySelector('.f-name').value.trim(),
                        strength: row.querySelector('.f-strength').value.trim(),
                        unit: row.querySelector('.f-unit').value,
                        qty_requested: row.querySelector('.f-qty').value,
                        notes: row.querySelector('.f-notes').value.trim(),
                    };
                });

                const submitBtn = prForm.querySelector('button[type="submit"]');
                submitBtn.disabled = true;
                try {
                    const res = await fetch('purchase-request-actions.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            action: 'update',
                            pr_id: editingPrId,
                            csrf_token: document.querySelector('#prForm [name="csrf_token"]').value,
                            purpose: document.getElementById('prPurpose').value.trim(),
                            notes: document.getElementById('prNotes').value.trim(),
                            items: items
                        })
                    });
                    const result = await res.json();
                    if (!res.ok || !result.success) {
                        throw new Error(result.error || 'Could not save this purchase request.');
                    }
                    window.location.reload();
                } catch (err) {
                    prError.textContent = err.message || 'Could not save this purchase request.';
                    prError.hidden = false;
                } finally {
                    submitBtn.disabled = false;
                }
            });

            document.querySelectorAll('.cancel-pr-btn').forEach(function(btn) {
                btn.addEventListener('click', async function() {
                    if (!confirm('Cancel this purchase request?')) return;
                    const csrfToken = '<?php echo csrf_token(); ?>';
                    const res = await fetch('purchase-request-actions.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            action: 'cancel',
                            csrf_token: csrfToken,
                            pr_id: btn.dataset.prId
                        })
                    });
                    const result = await res.json();
                    if (result.success) {
                        window.location.reload();
                    } else {
                        alert(result.error || 'Could not cancel this request.');
                    }
                });
            });

            // --- Approve / Reject modals -----------------------------
            // Same modal shell (.modal-backdrop/.modal-box from
            // pharmacist-dashboard.css) already used elsewhere in this
            // portal.
            const csrfToken = '<?php echo csrf_token(); ?>';

            function wireModal(modalId) {
                const modal = document.getElementById(modalId);

                function close() {
                    modal.classList.remove('active');
                    document.body.classList.remove('modal-open');
                }
                modal.querySelectorAll('[data-close-modal="' + modalId + '"]').forEach(function(btn) {
                    btn.addEventListener('click', close);
                });
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) close();
                });
                return modal;
            }

            function openModal(modal) {
                modal.classList.add('active');
                document.body.classList.add('modal-open');
            }

            async function submitPrAction(action, extraFields, errorEl) {
                const res = await fetch('purchase-request-actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(Object.assign({
                        action: action,
                        csrf_token: csrfToken
                    }, extraFields))
                });
                const result = await res.json();
                if (!res.ok || !result.success) {
                    throw new Error(result.error || 'Something went wrong. Please try again.');
                }
                window.location.reload();
            }

            let activePrId = null;

            // Approve
            const approveModal = wireModal('approvePrModal');
            const approveForm = document.getElementById('approvePrForm');
            const approvePrError = document.getElementById('approvePrError');
            document.querySelectorAll('.approve-pr-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    activePrId = btn.dataset.prId;
                    approveForm.reset();
                    approvePrError.hidden = true;
                    openModal(approveModal);
                });
            });
            approveForm.addEventListener('submit', async function(event) {
                event.preventDefault();
                approvePrError.hidden = true;
                const submitBtn = approveForm.querySelector('button[type="submit"]');
                submitBtn.disabled = true;
                try {
                    await submitPrAction('approve', {
                        pr_id: activePrId
                    });
                } catch (err) {
                    approvePrError.textContent = err.message;
                    approvePrError.hidden = false;
                    submitBtn.disabled = false;
                }
            });

            // Log Rejection
            const rejectModal = wireModal('rejectPrModal');
            const rejectForm = document.getElementById('rejectPrForm');
            const rejectPrError = document.getElementById('rejectPrError');
            document.querySelectorAll('.reject-pr-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    activePrId = btn.dataset.prId;
                    rejectForm.reset();
                    rejectPrError.hidden = true;
                    openModal(rejectModal);
                });
            });
            rejectForm.addEventListener('submit', async function(event) {
                event.preventDefault();
                rejectPrError.hidden = true;
                const submitBtn = rejectForm.querySelector('button[type="submit"]');
                submitBtn.disabled = true;
                try {
                    await submitPrAction('reject', {
                        pr_id: activePrId,
                        rejection_reason: document.getElementById('rejectionReason').value.trim()
                    });
                } catch (err) {
                    rejectPrError.textContent = err.message;
                    rejectPrError.hidden = false;
                    submitBtn.disabled = false;
                }
            });
        })();
    </script>
    <datalist id="catalogNames">
        <?php foreach ($catalog as $m): ?>
            <option value="<?php echo htmlspecialchars($m['name']); ?>">
            <?php endforeach; ?>
    </datalist>
</body>

</html>