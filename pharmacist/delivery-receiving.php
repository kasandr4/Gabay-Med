<?php
// pharmacist/delivery-receiving.php
// Module 3.6 — Delivery Receiving.
//
// SCOPE DECISION (settled 2026-07-17): this follows the simple,
// single-stage version from gabaymed-modules-complete.md 3.6 — manual
// confirmation only, no barcode scan-in step — since that's what the real
// purchase_orders / inventory_medicines schema was actually built for.
//
// REAL BACKEND (2026-07-20): the PO queue and "Confirm Delivery" are both
// real now — see delivery-actions.php. Boxes ordered/received are what
// the pharmacist counts; units_per_box (a real column on
// inventory_medicines, confirmed against the actual gabaymed.sql dump)
// converts that box count to the units current_stock is actually tracked
// in — matching how medicine_exit_log already separates boxes_scanned
// from units_deducted on the Storage Exit side.
//
// ADDITION (2026-07-17): each PO still has a "Track Order" button opening
// a shopping-app-style vertical stepper (Order Placed -> Admin Approved ->
// Capitol Approved -> Shipped -> In Transit -> Delivered). This stays
// mocked for now — see the scope-decision note further down.

require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/csrf.php';

// REAL BACKEND (2026-07-20): the queue itself is a real query now — same
// shape the TODO(backend) comment here used to describe. "Mark as
// Delivered" posts to delivery-actions.php (action=confirm_delivery),
// which moves the PO straight to 'completed' and credits
// inventory_medicines.current_stock with the ACTUAL boxes received,
// converted to units via units_per_box (see that file for why 'completed'
// specifically).
$purchaseOrders = [];
$result = $conn->query(
    "SELECT po.po_id, po.po_number, po.supplier_name,
            po.approved_quantity AS boxes_ordered, po.expected_delivery,
            po.status, pr.medicine_id, im.name AS medicine_name, im.units_per_box
     FROM purchase_orders po
     JOIN purchase_requests pr ON pr.request_id = po.request_id
     JOIN inventory_medicines im ON im.medicine_id = pr.medicine_id
     WHERE po.status IN ('po_issued', 'awaiting_delivery')
     ORDER BY po.expected_delivery ASC"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $purchaseOrders[] = [
            "po_id"             => (int) $row['po_id'],
            "po_number"         => $row['po_number'],
            "medicine"          => $row['medicine_name'],
            "supplier"          => $row['supplier_name'],
            "boxes_ordered"     => (int) $row['boxes_ordered'],
            "units_per_box"     => (int) $row['units_per_box'],
            "expected_delivery" => $row['expected_delivery'],
            "status"            => $row['status'],
        ];
    }
}

// STILL MOCKED (2026-07-20 scope decision): the Track Order stepper below
// stays exactly as-is. purchase_order_tracking's earlier stages
// (order_placed, admin_approved, ...) aren't written by any real action
// yet — only Confirm Delivery's terminal step would be real — so wiring
// just one stage here would leave the stepper half-real, half-mock,
// which is more misleading than the current fully-mocked view. Revisit
// once PO creation (admin/inventory-actions.php's select_winner) also
// writes real tracking rows.
$STAGE_ORDER = ['order_placed', 'admin_approved', 'capitol_approved', 'shipped', 'in_transit', 'delivered'];
$STAGE_LABELS = [
    'order_placed'     => 'Order Placed',
    'admin_approved'   => 'Admin Approved',
    'capitol_approved' => 'Capitol Approved',
    'shipped'          => 'Shipped',
    'in_transit'       => 'In Transit',
    'delivered'        => 'Delivered',
];

$mockTracking = [
    "PO-2026-0088" => [
        'order_placed'   => '2026-07-10 9:14 AM',
        'admin_approved' => '2026-07-10 3:40 PM',
        'capitol_approved' => '2026-07-12 11:02 AM',
        'shipped'        => '2026-07-14 8:30 AM',
        'in_transit'     => '2026-07-16 6:00 AM',
    ],
    "PO-2026-0091" => [
        'order_placed'   => '2026-07-15 10:05 AM',
        'admin_approved' => '2026-07-15 4:22 PM',
    ],
    "PO-2026-0093" => [
        'order_placed'   => '2026-07-11 8:50 AM',
        'admin_approved' => '2026-07-11 2:15 PM',
        'capitol_approved' => '2026-07-13 9:40 AM',
        'shipped'        => '2026-07-15 7:20 AM',
    ],
];

$statusLabels = [
    "po_issued"        => "PO Issued",
    "awaiting_delivery" => "Awaiting Delivery",
];

/**
 * Renders the order-tracking stepper for one PO: stages before the most
 * recently reached one render as "done", the most recent one renders as
 * "current" (highlighted, with its timestamp), everything after renders
 * as "pending" (grey, no timestamp) — same visual language as a shopping
 * app's order tracker.
 */
function renderTrackerTimeline(string $poNumber, array $mockTracking, array $stageOrder, array $stageLabels): string
{
    $reached = $mockTracking[$poNumber] ?? [];
    $currentStage = null;
    foreach ($stageOrder as $stage) {
        if (isset($reached[$stage])) {
            $currentStage = $stage;
        }
    }

    $html = '<div class="tracker-timeline">';
    $currentPassed = false;
    foreach ($stageOrder as $stage) {
        $isCurrent = ($stage === $currentStage);
        $isDone = isset($reached[$stage]) && !$isCurrent;
        $isPending = !isset($reached[$stage]);
        $stateClass = $isCurrent ? 'tracker-step-current' : ($isDone ? 'tracker-step-done' : 'tracker-step-pending');

        $html .= '<div class="tracker-step ' . $stateClass . '">';
        $html .= '<span class="tracker-dot"></span>';
        $html .= '<div class="tracker-step-body">';
        $html .= '<span class="tracker-step-label">' . htmlspecialchars($stageLabels[$stage]) . '</span>';
        if ($isCurrent) {
            $html .= '<span class="tracker-step-date">' . htmlspecialchars($reached[$stage]) . '</span>';
        } elseif ($isDone) {
            $html .= '<span class="tracker-step-date">' . htmlspecialchars($reached[$stage]) . '</span>';
        } elseif ($stage === 'capitol_approved' && $isPending) {
            $html .= '<span class="tracker-step-hint">No Capitol Approver portal yet &mdash; marked manually when available</span>';
        }
        $html .= '</div></div>';
    }
    $html .= '</div>';

    return $html;
}

$current_page = 'delivery';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Receiving - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/pharmacist-dashboard.css">
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <!-- Main content -->
        <main class="main-content">

            <header class="page-header">
                <div>
                    <h1>Delivery Receiving</h1>
                    <p class="page-subtitle">Confirm deliveries against their purchase order to restock inventory.</p>
                </div>
            </header>

            <section class="card queue-full-card">
                <div class="card-header">
                    <h2>Awaiting Delivery</h2>
                    <span class="card-subtitle">Purchase orders not yet received</span>
                </div>

                <?php if (empty($purchaseOrders)): ?>
                    <div class="empty-state">
                        <div class="empty-illustration">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M10 17h4V5H2v12h3"></path>
                                <path d="M20 17h2v-3.34a4 4 0 0 0-1.17-2.83L19 9h-5v8h1"></path>
                                <circle cx="7.5" cy="17.5" r="2.5"></circle>
                                <circle cx="17.5" cy="17.5" r="2.5"></circle>
                            </svg>
                        </div>
                        <p>No deliveries pending right now</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="queue-table" id="deliveryTable">
                            <thead>
                                <tr>
                                    <th>PO Number</th>
                                    <th>Medicine</th>
                                    <th>Supplier</th>
                                    <th>Boxes Ordered</th>
                                    <th>Expected Delivery</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($purchaseOrders as $po): ?>
                                    <tr id="row-<?php echo htmlspecialchars($po['po_number']); ?>">
                                        <td><?php echo htmlspecialchars($po['po_number']); ?></td>
                                        <td><?php echo htmlspecialchars($po['medicine']); ?></td>
                                        <td><?php echo htmlspecialchars($po['supplier']); ?></td>
                                        <td><?php echo (int) $po['boxes_ordered']; ?> boxes</td>
                                        <td><?php echo htmlspecialchars(date('M j, Y', strtotime($po['expected_delivery']))); ?></td>
                                        <td><span class="status-pill status-<?php echo str_replace('_', '-', $po['status']); ?>"><?php echo htmlspecialchars($statusLabels[$po['status']]); ?></span></td>
                                        <td class="delivery-actions-cell">
                                            <button
                                                type="button"
                                                class="btn btn-secondary btn-sm"
                                                data-open-tracker="<?php echo htmlspecialchars($po['po_number']); ?>">
                                                Track Order
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-primary btn-sm"
                                                data-open-modal="deliveryModal"
                                                data-po-id="<?php echo (int) $po['po_id']; ?>"
                                                data-po="<?php echo htmlspecialchars($po['po_number']); ?>"
                                                data-medicine="<?php echo htmlspecialchars($po['medicine']); ?>"
                                                data-supplier="<?php echo htmlspecialchars($po['supplier']); ?>"
                                                data-boxes-ordered="<?php echo (int) $po['boxes_ordered']; ?>"
                                                data-units-per-box="<?php echo (int) $po['units_per_box']; ?>">
                                                Mark as Delivered
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <!-- Hidden per-PO tracker content, rendered server-side. JS clones the
         matching one into the Track Order modal when its button is clicked. -->
    <?php foreach ($purchaseOrders as $po): ?>
        <template id="tracker-content-<?php echo htmlspecialchars($po['po_number']); ?>">
            <?php echo renderTrackerTimeline($po['po_number'], $mockTracking, $STAGE_ORDER, $STAGE_LABELS); ?>
        </template>
    <?php endforeach; ?>

    <!-- Track Order modal — a shopping-app-style vertical stepper showing
         this PO's journey from Order Placed through Delivered. -->
    <div class="modal-backdrop" id="trackModal" aria-hidden="true">
        <div class="modal-box dr-modal-box" role="dialog" aria-modal="true" aria-labelledby="trackModalTitle">
            <div class="dr-modal-header">
                <h3 id="trackModalTitle">Track Order</h3>
                <button type="button" class="dr-modal-close" data-close-modal="trackModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="dr-modal-body" id="trackModalBody"></div>
        </div>
    </div>

    <!-- Confirm Delivery modal — reuses the shared .modal-backdrop/.modal-box
         base from inventory-procurement.css's pattern, with its own "dr-"
         prefixed field/action classes. -->
    <div class="modal-backdrop" id="deliveryModal" aria-hidden="true">
        <div class="modal-box dr-modal-box" role="dialog" aria-modal="true" aria-labelledby="deliveryModalTitle">
            <div class="dr-modal-header">
                <h3 id="deliveryModalTitle">Confirm Delivery</h3>
                <button type="button" class="dr-modal-close" data-close-modal="deliveryModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>

            <div class="dr-modal-body">
                <?= csrf_field() ?>
                <div class="modal-section-label">Purchase Order</div>
                <div class="dr-po-summary">
                    <div class="summary-item">
                        <span class="summary-label">PO Number</span>
                        <span class="summary-value" id="modalPoNumber">&mdash;</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Medicine</span>
                        <span class="summary-value" id="modalMedicine">&mdash;</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Supplier</span>
                        <span class="summary-value" id="modalSupplier">&mdash;</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Boxes Ordered</span>
                        <span class="summary-value" id="modalBoxesOrdered">&mdash;</span>
                    </div>
                </div>

                <div class="modal-section-label">Receiving Details</div>

                <div class="dr-field">
                    <label for="boxesReceived">Quantity Received (Boxes)</label>
                    <input type="number" id="boxesReceived" min="0" step="1">
                    <span class="dr-units-computed" id="unitsComputed"></span>
                </div>

                <!-- UI ONLY: no batch_no / expiry_date column exists on
                     inventory_medicines or purchase_orders yet (same gap
                     flagged on Inventory View) — these two fields are not
                     read by Confirm Delivery's click handler below, purely
                     placeholders for the eventual receiving form. -->
                <div class="dr-field dr-field-spaced">
                    <label for="deliveryBatchNo">Batch Number</label>
                    <input type="text" id="deliveryBatchNo" placeholder="e.g. BN-014-2026">
                </div>

                <div class="dr-field dr-field-spaced">
                    <label for="deliveryExpiryDate">Expiry Date</label>
                    <input type="date" id="deliveryExpiryDate">
                </div>

                <div class="dr-field dr-field-spaced">
                    <label for="deliveryNotes">Notes (optional)</label>
                    <textarea id="deliveryNotes" rows="2" placeholder="Condition of the shipment, discrepancies, etc."></textarea>
                </div>

                <p class="dr-mismatch-warning" id="mismatchWarning" hidden></p>
                <p class="dr-mismatch-warning" id="deliveryError" hidden></p>
            </div>

            <div class="dr-modal-actions">
                <button type="button" class="btn btn-secondary" data-close-modal="deliveryModal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmDeliveryBtn">Confirm Delivery</button>
            </div>
        </div>
    </div>

    <div class="scan-toast-host" id="deliveryToastHost"></div>

    <script>
        const modal = document.getElementById('deliveryModal');
        const trackModal = document.getElementById('trackModal');
        const trackModalTitle = document.getElementById('trackModalTitle');
        const trackModalBody = document.getElementById('trackModalBody');
        const boxesInput = document.getElementById('boxesReceived');
        const unitsComputed = document.getElementById('unitsComputed');
        const mismatchWarning = document.getElementById('mismatchWarning');
        const deliveryError = document.getElementById('deliveryError');
        const confirmBtn = document.getElementById('confirmDeliveryBtn');
        let activePoId = null;
        let activePoNumber = null;
        let boxesOrdered = 0;
        let unitsPerBox = 0;

        document.querySelectorAll('[data-open-modal]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                activePoId = btn.dataset.poId;
                activePoNumber = btn.dataset.po;
                boxesOrdered = parseInt(btn.dataset.boxesOrdered, 10);
                unitsPerBox = parseInt(btn.dataset.unitsPerBox, 10);

                document.getElementById('modalPoNumber').textContent = btn.dataset.po;
                document.getElementById('modalMedicine').textContent = btn.dataset.medicine;
                document.getElementById('modalSupplier').textContent = btn.dataset.supplier;
                document.getElementById('modalBoxesOrdered').textContent = `${boxesOrdered} boxes (${boxesOrdered * unitsPerBox} units)`;

                deliveryError.hidden = true;

                // Pre-filled with the ordered box count, per 3.6 — editable
                // in case the delivery doesn't match exactly.
                boxesInput.value = boxesOrdered;
                updateComputedUnits();

                modal.classList.add('active');
                document.body.classList.add('modal-open');
                boxesInput.focus();
            });
        });

        document.querySelectorAll('[data-open-tracker]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const poNumber = btn.dataset.openTracker;
                const template = document.getElementById('tracker-content-' + poNumber);

                trackModalTitle.textContent = `Track Order \u2014 ${poNumber}`;
                trackModalBody.innerHTML = '';
                if (template) {
                    trackModalBody.appendChild(template.content.cloneNode(true));
                }

                trackModal.classList.add('active');
                document.body.classList.add('modal-open');
            });
        });

        document.querySelectorAll('[data-close-modal]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                closeModal(document.getElementById(btn.dataset.closeModal));
            });
        });
        [modal, trackModal].forEach(function(m) {
            m.addEventListener('click', function(e) {
                if (e.target === m) closeModal(m);
            });
        });

        function closeModal(m) {
            m.classList.remove('active');
            document.body.classList.remove('modal-open');
        }

        boxesInput.addEventListener('input', updateComputedUnits);

        function updateComputedUnits() {
            const boxes = parseInt(boxesInput.value, 10);

            if (!isNaN(boxes)) {
                unitsComputed.textContent = `= ${boxes * unitsPerBox} units (${unitsPerBox} per box)`;
            } else {
                unitsComputed.textContent = '';
            }

            if (!isNaN(boxes) && boxes !== boxesOrdered) {
                mismatchWarning.textContent = `This differs from the ${boxesOrdered} boxes ordered \u2014 double check before confirming.`;
                mismatchWarning.hidden = false;
            } else {
                mismatchWarning.hidden = true;
            }
        }

        // REAL SUBMIT (2026-07-20): posts to delivery-actions.php
        // (action=confirm_delivery), which moves the PO to 'completed' and
        // credits inventory_medicines.current_stock with the actual boxes
        // received — see that file for the segregation-of-duties note on
        // why 'completed' specifically.
        confirmBtn.addEventListener('click', function() {
            const boxes = parseInt(boxesInput.value, 10);
            deliveryError.hidden = true;

            if (isNaN(boxes) || boxes <= 0) {
                deliveryError.textContent = 'Enter a valid number of boxes received.';
                deliveryError.hidden = false;
                return;
            }

            const csrfToken = modal.querySelector('[name="csrf_token"]').value;
            const notes = document.getElementById('deliveryNotes').value;

            confirmBtn.disabled = true;
            confirmBtn.textContent = 'Confirming\u2026';

            const body = new URLSearchParams({
                action: 'confirm_delivery',
                csrf_token: csrfToken,
                po_id: activePoId,
                boxes_received: boxes,
                notes: notes,
            });

            fetch('delivery-actions.php', {
                    method: 'POST',
                    body: body,
                })
                .then(res => res.json())
                .then(data => {
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = 'Confirm Delivery';

                    if (!data.success) {
                        deliveryError.textContent = data.error || 'Something went wrong. Please try again.';
                        deliveryError.hidden = false;
                        return;
                    }

                    const row = document.getElementById('row-' + activePoNumber);
                    if (row) row.remove();
                    closeModal(modal);
                    showToast(`\u2713 ${activePoNumber} marked as delivered \u2014 ${boxes} boxes (${data.units_received} units) added to inventory`);
                })
                .catch(() => {
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = 'Confirm Delivery';
                    deliveryError.textContent = 'Could not reach the server. Please check your connection and try again.';
                    deliveryError.hidden = false;
                });
        });

        function showToast(message) {
            const host = document.getElementById('deliveryToastHost');
            const toast = document.createElement('div');
            toast.className = 'scan-toast';
            toast.textContent = message;
            host.appendChild(toast);
            requestAnimationFrame(() => toast.classList.add('scan-toast-show'));
            setTimeout(() => {
                toast.classList.remove('scan-toast-show');
                setTimeout(() => toast.remove(), 250);
            }, 2400);
        }
    </script>
</body>

</html>