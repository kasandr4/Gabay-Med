<?php
// staff/restock-management.php
// Restock Management for inventory-type Staff: two side-by-side panels.
//   Left  - Restock Needs: the medicines that need restocking, in three
//           tabs (Out of Stock / Critical, Fast-Moving, Near Expiry), with
//           a checkbox on the far right and a Select All / Deselect All
//           control. (There used to be an "All" tab listing every
//           medicine; it was removed so staff only request what the
//           reorder logic flags.)
//   Right - New Purchase Request: only the medicines ticked on the left,
//           each with its unit and an editable Requested Qty (pre-filled
//           from the existing reorder logic - see restock_defaults.php).
// Ticking, unticking, removing from the right panel and Select All all
// keep the two panels in sync, and a medicine can only ever appear once.
//
// Submitting hands the PR to the Pharmacist (status 'open', shown as
// "Submitted") via restock-actions.php. Inventory is NOT changed here.
//
// Restock Needs does NOT show each medicine's on-hand stock: inventory
// staff work from blind counts and never see system quantities (same rule
// as inventory-count). current_stock is still read above, but only
// server-side to size the default Requested Qty - it is never printed.
//
// PHASE 5: once the Pharmacist has stocked in an approved PR, My Purchase
// Requests shows it as "Received" (purchase_requests.received_at). Its
// status stays 'approved', so Print / Export CSV keep working.

require_once '../includes/auth_guard.php';
require_role('staff');
require_staff_type('inventory');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/restock_defaults.php';

$staffId = (int) $_SESSION['user_id'];

$medicines = [];
$medResult = $conn->query(
    "SELECT medicine_id, name, unit, current_stock, minimum_stock
     FROM inventory_medicines
     ORDER BY name ASC"
);
if ($medResult) {
    while ($row = $medResult->fetch_assoc()) {
        $medicines[] = $row;
    }
}
$defaultQty = restock_default_quantities($conn, $medicines);

// The same three Restock Needs categories the Pharmacist sees.
$needsByCategory = restock_needs_by_category($conn, $medicines);
$medicinesById = [];
foreach ($medicines as $m) {
    $medicinesById[(int) $m['medicine_id']] = $m;
}
// A medicine can be flagged in more than one category; the most severe one
// wins for the pre-filled quantity: critical > fast-moving > near-expiry.
$reasonById = [];
foreach (['critical', 'fast-moving', 'near-expiry'] as $catKey) {
    foreach ($needsByCategory[$catKey] as $entry) {
        $mid = $entry['medicine_id'];
        if (!isset($reasonById[$mid])) {
            $reasonById[$mid] = $entry;
        }
    }
}
$tabDefs = [
    'critical' => 'Out of Stock / Critical',
    'fast-moving' => 'Fast-Moving',
    'near-expiry' => 'Near Expiry',
];
$tabEmpty = [
    'critical' => 'Nothing out of stock or critically low right now.',
    'fast-moving' => 'No fast-movers below their reorder point right now.',
    'near-expiry' => 'No near-expiry batches that would drop stock below minimum.',
];
$tabRows = [];
foreach (['critical', 'fast-moving', 'near-expiry'] as $catKey) {
    $tabRows[$catKey] = [];
    foreach ($needsByCategory[$catKey] as $entry) {
        $tabRows[$catKey][] = ['id' => $entry['medicine_id'], 'note' => $entry['note']];
    }
}
$firstTab = array_key_first($tabDefs); // the tab shown when the page opens
// Distinct medicines flagged across the tabs (one can sit in several).
$flaggedCount = count($reasonById);

// This staff member's own PRs, read-only. 'open' is what the pharmacist
// side calls an unreviewed PR - to Staff it simply means "Submitted".
$myRequests = [];
$reqStmt = $conn->prepare(
    "SELECT pr.pr_id, pr.pr_no, pr.status, pr.created_at, pr.rejection_reason, pr.received_at,
            (SELECT COUNT(*) FROM purchase_request_items pri WHERE pri.pr_id = pr.pr_id) AS item_count
     FROM purchase_requests pr
     WHERE pr.requested_by = ?
     ORDER BY pr.created_at DESC, pr.pr_id DESC
     LIMIT 50"
);
$reqStmt->bind_param('i', $staffId);
$reqStmt->execute();
$reqResult = $reqStmt->get_result();
while ($row = $reqResult->fetch_assoc()) {
    $myRequests[] = $row;
}
$reqStmt->close();

function restock_status_label(string $status): string
{
    $labels = [
        'open' => 'Submitted',
        'approved' => 'Approved',
        'sent_to_capitol' => 'Approved',
        'converted' => 'Approved',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
    ];
    return $labels[$status] ?? ucfirst($status);
}

$submittedNo = '';
if (isset($_GET['submitted']) && preg_match('/^PR-\d{4}-\d{4}$/', (string) $_GET['submitted'])) {
    $submittedNo = (string) $_GET['submitted'];
}

$jsMedicines = [];
foreach ($medicines as $m) {
    $jsMedicines[(int) $m['medicine_id']] = [
        'name' => $m['name'],
        'unit' => $m['unit'],
        'qty' => (isset($reasonById[(int) $m['medicine_id']]) && $reasonById[(int) $m['medicine_id']]['suggested_qty'] !== null)
            ? (int) $reasonById[(int) $m['medicine_id']]['suggested_qty']
            : ($defaultQty[(int) $m['medicine_id']] ?? 1),
    ];
}

$current_page = 'restock-management';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restock Management - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/procurement.css">
    <link rel="stylesheet" href="../assets/css/staff-restock.css">
</head>

<body>
    <div class="app-shell">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">
            <header class="page-header">
                <div>
                    <h1>Restock Management</h1>
                    <p class="page-subtitle">Tick the medicines that need restocking, adjust quantities, and submit a Purchase Request for the Head Pharmacist to review.</p>
                </div>
            </header>

            <?php if ($submittedNo !== ''): ?>
                <p class="rm-banner rm-banner-success" role="status"><?php echo htmlspecialchars($submittedNo); ?> was submitted to the Head Pharmacist. Stock levels are unchanged until the delivery is received.</p>
            <?php endif; ?>

            <div class="rm-grid">
                <!-- LEFT: Restock Needs -->
                <section class="card rm-panel" id="needsPanel">
                    <div class="rm-panel-head">
                        <div>
                            <h2>Restock Needs</h2>
                            <span class="card-subtitle"><?php echo $flaggedCount; ?> medicine<?php echo $flaggedCount === 1 ? '' : 's'; ?> need restocking</span>
                        </div>
                        <button type="button" class="btn btn-secondary" id="selectAllBtn">Select All</button>
                    </div>
                    <input type="search" id="needsSearch" class="rm-search" placeholder="Search medicines..." aria-label="Search medicines">

                    <div class="restock-tabs" role="tablist">
                        <?php foreach ($tabDefs as $tabKey => $tabLabel): ?>
                            <button type="button" class="restock-tab<?php echo $tabKey === $firstTab ? ' is-active' : ''; ?>" data-restock-panel="<?php echo $tabKey; ?>"><?php echo htmlspecialchars($tabLabel); ?><span class="count-pill"><?php echo count($tabRows[$tabKey]); ?></span></button>
                        <?php endforeach; ?>
                    </div>

                    <?php foreach ($tabRows as $tabKey => $rows): ?>
                        <div class="restock-list" data-restock-list="<?php echo $tabKey; ?>" <?php echo $tabKey !== $firstTab ? 'hidden' : ''; ?>>
                            <?php if (empty($rows)): ?>
                                <div class="restock-empty"><?php echo htmlspecialchars($tabEmpty[$tabKey]); ?></div>
                            <?php else: ?>
                                <?php foreach ($rows as $r):
                                    $m = $medicinesById[$r['id']];
                                ?>
                                    <label class="restock-row" data-medicine-id="<?php echo (int) $m['medicine_id']; ?>" data-search="<?php echo htmlspecialchars(strtolower($m['name'])); ?>">
                                        <div class="restock-row-info">
                                            <div class="restock-row-name"><?php echo htmlspecialchars($m['name']); ?></div>
                                            <?php if ($r['note'] !== ''): ?>
                                                <div class="restock-row-note"><?php echo htmlspecialchars($r['note']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <input type="checkbox" class="rm-need-check" aria-label="Select <?php echo htmlspecialchars($m['name']); ?>">
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <p class="restock-empty" id="needsNoMatch" hidden>No medicines match your search.</p>
                </section>

                <!-- RIGHT: New Purchase Request -->
                <section class="card rm-panel" id="prPanel">
                    <div class="rm-panel-head">
                        <div>
                            <h2>New Purchase Request</h2>
                            <span class="card-subtitle" id="prCount">0 selected</span>
                        </div>
                    </div>
                    <div class="rm-list" id="prList">
                        <div class="rm-pr-header">
                            <span>Medicine</span><span>Unit</span><span>Requested Qty</span><span></span>
                        </div>
                        <div id="prRows"></div>
                        <p class="rm-empty" id="prEmpty">Nothing selected yet. Tick a medicine on the left to add it here.</p>
                    </div>
                    <p id="prError" class="form-error" role="alert" hidden></p>
                    <div class="rm-submit-bar">
                        <?= csrf_field() ?>
                        <button type="button" class="btn btn-primary" id="submitPrBtn" disabled>Submit Purchase Request</button>
                    </div>
                </section>
            </div>

            <section class="card" style="margin-top: 20px;">
                <div class="card-header">
                    <div>
                        <h2>My Purchase Requests</h2>
                        <span class="card-subtitle">Read-only. Once submitted, a request is handled by the Head Pharmacist. Approved requests can be printed or exported for manual forwarding.</span>
                    </div>
                </div>
                <?php if (empty($myRequests)): ?>
                    <div class="empty-state">
                        <p>You haven't submitted any purchase requests yet.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>PR No.</th>
                                    <th>Items</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($myRequests as $req): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($req['pr_no']); ?></strong></td>
                                        <td><?php echo (int) $req['item_count']; ?></td>
                                        <td><?php echo htmlspecialchars(date('M j, Y', strtotime($req['created_at']))); ?></td>
                                        <td>
                                            <?php $isReceived = $req['status'] === 'approved' && $req['received_at'] !== null; ?>
                                            <span class="po-status-badge <?php echo $isReceived ? 'received' : htmlspecialchars($req['status']); ?>"><?php echo $isReceived ? 'Received' : htmlspecialchars(restock_status_label($req['status'])); ?></span>
                                            <?php if ($isReceived): ?>
                                                <div class="pr-status-note">Stocked in <?php echo htmlspecialchars(date('M j, Y', strtotime($req['received_at']))); ?></div>
                                            <?php endif; ?>
                                            <?php if ($req['status'] === 'rejected' && !empty($req['rejection_reason'])): ?>
                                                <div class="pr-status-note pr-status-note-warn">Reason: <?php echo htmlspecialchars($req['rejection_reason']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (in_array($req['status'], ['approved', 'sent_to_capitol', 'converted'], true)): ?>
                                                <a href="../pharmacist/print-purchase-request.php?pr_id=<?php echo (int) $req['pr_id']; ?>" target="_blank" class="btn btn-secondary" style="padding:6px 10px;font-size:12px;">Print</a>
                                                <a href="../pharmacist/export-purchase-request.php?pr_id=<?php echo (int) $req['pr_id']; ?>" class="btn btn-primary" style="padding:6px 10px;font-size:12px;">Export CSV</a>
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
    <script src="../assets/js/doctor-dashboard.js"></script>
    <script>
        (function() {
            const medicines = <?php echo json_encode($jsMedicines); ?>;
            const selected = new Map(); // medicine_id (string) -> qty (string, as typed)

            const needsPanel = document.getElementById('needsPanel');
            const needTabs = Array.from(needsPanel.querySelectorAll('.restock-tab'));
            const needLists = Array.from(needsPanel.querySelectorAll('[data-restock-list]'));
            // A medicine can appear in several tabs (e.g. Out of Stock and
            // Near Expiry), so every row/checkbox for the same medicine is
            // kept in sync.
            const needRows = Array.from(needsPanel.querySelectorAll('.restock-row'));
            const prRows = document.getElementById('prRows');
            const prEmpty = document.getElementById('prEmpty');
            const prCount = document.getElementById('prCount');
            const prError = document.getElementById('prError');
            const submitBtn = document.getElementById('submitPrBtn');
            const selectAllBtn = document.getElementById('selectAllBtn');
            const searchInput = document.getElementById('needsSearch');
            const noMatch = document.getElementById('needsNoMatch');

            function activeList() {
                return needLists.find(function(l) {
                    return !l.hidden;
                });
            }

            // Rows currently shown: in the active tab and not filtered out by search.
            function visibleRows() {
                const list = activeList();
                return needRows.filter(function(r) {
                    return list.contains(r) && !r.hidden;
                });
            }

            function refreshNoMatch() {
                const list = activeList();
                const hasRows = list.querySelector('.restock-row') !== null;
                noMatch.hidden = !(hasRows && visibleRows().length === 0);
            }

            // One place that redraws the right panel and every dependent
            // control from `selected`, so the checkboxes, the PR list, the
            // counter and the Select All label can never drift apart.
            function render() {
                prRows.innerHTML = '';
                selected.forEach(function(qty, id) {
                    const med = medicines[id];
                    const row = document.createElement('div');
                    row.className = 'rm-pr-row';
                    row.dataset.medicineId = id;

                    const name = document.createElement('span');
                    name.className = 'rm-pr-name';
                    name.textContent = med.name;

                    const unit = document.createElement('span');
                    unit.textContent = med.unit;

                    const input = document.createElement('input');
                    input.type = 'number';
                    input.min = '1';
                    input.step = '1';
                    input.value = qty;
                    input.className = 'rm-qty-input';
                    input.setAttribute('aria-label', 'Requested quantity for ' + med.name);
                    input.addEventListener('input', function() {
                        selected.set(id, input.value);
                    });

                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'rm-remove';
                    remove.title = 'Remove';
                    remove.setAttribute('aria-label', 'Remove ' + med.name);
                    remove.textContent = '\u00d7';
                    remove.addEventListener('click', function() {
                        selected.delete(id);
                        render();
                    });

                    row.append(name, unit, input, remove);
                    prRows.appendChild(row);
                });

                needRows.forEach(function(r) {
                    r.querySelector('.rm-need-check').checked = selected.has(r.dataset.medicineId);
                });

                prEmpty.hidden = selected.size > 0;
                prCount.textContent = selected.size + ' selected';
                submitBtn.disabled = selected.size === 0;

                const vis = visibleRows();
                const allVisibleSelected = vis.length > 0 && vis.every(function(r) {
                    return selected.has(r.dataset.medicineId);
                });
                selectAllBtn.textContent = allVisibleSelected ? 'Deselect All' : 'Select All';
            }

            function select(id) {
                if (!selected.has(id) && medicines[id]) {
                    selected.set(id, String(medicines[id].qty));
                }
            }

            needRows.forEach(function(r) {
                r.querySelector('.rm-need-check').addEventListener('change', function(e) {
                    const id = r.dataset.medicineId;
                    if (e.target.checked) {
                        select(id);
                    } else {
                        selected.delete(id);
                    }
                    render();
                });
            });

            // Select All acts on the rows currently shown, so with no
            // search text it selects every medicine in the open tab.
            selectAllBtn.addEventListener('click', function() {
                const vis = visibleRows();
                const allVisibleSelected = vis.length > 0 && vis.every(function(r) {
                    return selected.has(r.dataset.medicineId);
                });
                vis.forEach(function(r) {
                    if (allVisibleSelected) {
                        selected.delete(r.dataset.medicineId);
                    } else {
                        select(r.dataset.medicineId);
                    }
                });
                render();
            });

            searchInput.addEventListener('input', function() {
                const q = searchInput.value.trim().toLowerCase();
                let shown = 0;
                needRows.forEach(function(r) {
                    const match = q === '' || r.dataset.search.indexOf(q) !== -1;
                    r.hidden = !match;
                    if (match) shown++;
                });
                refreshNoMatch();
                render();
            });

            needTabs.forEach(function(tab) {
                tab.addEventListener('click', function() {
                    needTabs.forEach(function(t) {
                        t.classList.toggle('is-active', t === tab);
                    });
                    needLists.forEach(function(l) {
                        l.hidden = l.dataset.restockList !== tab.dataset.restockPanel;
                    });
                    refreshNoMatch();
                    render();
                });
            });

            submitBtn.addEventListener('click', async function() {
                prError.hidden = true;
                const items = [];
                for (const entry of selected) {
                    const id = entry[0];
                    const qty = parseInt(entry[1], 10);
                    if (!/^\d+$/.test(String(entry[1]).trim()) || qty <= 0) {
                        prError.textContent = 'Enter a whole-number quantity greater than zero for ' + medicines[id].name + '.';
                        prError.hidden = false;
                        return;
                    }
                    items.push({
                        medicine_id: parseInt(id, 10),
                        qty: qty
                    });
                }
                if (items.length === 0) {
                    prError.textContent = 'Select at least one medicine.';
                    prError.hidden = false;
                    return;
                }

                submitBtn.disabled = true;
                try {
                    const res = await fetch('restock-actions.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            action: 'submit',
                            csrf_token: document.querySelector('#prPanel [name="csrf_token"]').value,
                            items: items
                        })
                    });
                    const result = await res.json();
                    if (!res.ok || !result.success) {
                        throw new Error(result.error || 'Could not submit this purchase request.');
                    }
                    window.location.href = 'restock-management.php?submitted=' + encodeURIComponent(result.pr_no);
                } catch (err) {
                    prError.textContent = err.message || 'Could not submit this purchase request.';
                    prError.hidden = false;
                    submitBtn.disabled = false;
                }
            });

            render();
        })();
    </script>
</body>

</html>