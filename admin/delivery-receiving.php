<?php
// admin/delivery-receiving.php
// Delivery Receiving — Admin Portal (UI ONLY, oversight view).
//
// Per the brief: no backend logic, no SQL beyond the existing auth guard.
// Everything below is static/mock, same shape TODO(backend) comments as
// pharmacist/delivery-receiving.php.
//
// SCOPE DECISION: this is deliberately an OVERSIGHT view, not a mirror of
// the pharmacist page. Confirming physical receipt of a delivery is the
// pharmacist's action (pharmacist/delivery-receiving.php's "Mark as
// Delivered") — same segregation-of-duties principle already enforced on
// Inventory Procurement, where only pharmacists create/resubmit purchase
// requests and only admin approves them. Admin here can see the same PO
// list and the same Track Order stepper, but has no button that mutates
// delivery/inventory state — only "View Details" (read-only) and a
// separate "Recently Delivered" table for after-the-fact oversight.
//
// Shares mock PO data + tracker stage data with the pharmacist page so the
// two views agree; when a real backend lands, both should read the same
// purchase_orders / purchase_order_tracking tables.

require_once '../includes/auth_guard.php';
require_role('admin');

// TODO(backend): SELECT po.po_id, po.po_number, po.supplier_name,
// po.approved_quantity AS boxes_ordered, po.expected_delivery,
// pr.medicine_id, im.name AS medicine_name, im.units_per_box
// FROM purchase_orders po
// JOIN purchase_requests pr ON pr.request_id = po.request_id
// JOIN inventory_medicines im ON im.medicine_id = pr.medicine_id
// WHERE po.status IN ('po_issued', 'awaiting_delivery')
// ORDER BY po.expected_delivery ASC
$purchaseOrders = [
    ["po_number" => "PO-2026-0088", "medicine" => "Amoxicillin 500mg", "supplier" => "MedSupply Corp",         "boxes_ordered" => 12, "units_per_box" => 100, "expected_delivery" => "2026-07-20", "status" => "awaiting_delivery"],
    ["po_number" => "PO-2026-0091", "medicine" => "Paracetamol 500mg", "supplier" => "PharmaLink Distributors", "boxes_ordered" => 20, "units_per_box" => 500, "expected_delivery" => "2026-07-18", "status" => "po_issued"],
    ["po_number" => "PO-2026-0093", "medicine" => "Losartan 50mg",     "supplier" => "MedSupply Corp",         "boxes_ordered" => 8,  "units_per_box" => 60,  "expected_delivery" => "2026-07-22", "status" => "po_issued"],
];

// TODO(backend): SELECT po.po_number, im.name AS medicine, po.supplier_name,
// pod.boxes_received, pod.received_at, pod.received_by
// FROM purchase_orders po JOIN purchase_order_deliveries pod ON ...
// WHERE po.status = 'delivered' ORDER BY pod.received_at DESC LIMIT 5
$recentlyDelivered = [
    ["po_number" => "PO-2026-0079", "medicine" => "Cefalexin 500mg",  "supplier" => "MedSupply Corp",         "boxes_received" => 15, "units_per_box" => 100, "received_at" => "2026-07-17 10:20 AM", "received_by" => "J. Reyes (Pharmacist)"],
    ["po_number" => "PO-2026-0082", "medicine" => "Metformin 500mg",  "supplier" => "PharmaLink Distributors", "boxes_received" => 30, "units_per_box" => 200, "received_at" => "2026-07-16 2:45 PM",  "received_by" => "J. Reyes (Pharmacist)"],
    ["po_number" => "PO-2026-0084", "medicine" => "Cetirizine 10mg",  "supplier" => "MedSupply Corp",         "boxes_received" => 10, "units_per_box" => 150, "received_at" => "2026-07-14 9:05 AM",  "received_by" => "A. Cruz (Pharmacist)"],
];

// TODO(backend): SELECT stage, reached_at FROM purchase_order_tracking
// WHERE po_id = ? ORDER BY reached_at ASC. Whichever stage has the most
// recent row is "current" — everything after it (per STAGE_ORDER below)
// has no row yet and renders as pending. Same data pharmacist's tracker
// modal reads.
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
        'order_placed'     => '2026-07-10 9:14 AM',
        'admin_approved'   => '2026-07-10 3:40 PM',
        'capitol_approved' => '2026-07-12 11:02 AM',
        'shipped'          => '2026-07-14 8:30 AM',
        'in_transit'       => '2026-07-16 6:00 AM',
    ],
    "PO-2026-0091" => [
        'order_placed'   => '2026-07-15 10:05 AM',
        'admin_approved' => '2026-07-15 4:22 PM',
    ],
    "PO-2026-0093" => [
        'order_placed'     => '2026-07-11 8:50 AM',
        'admin_approved'   => '2026-07-11 2:15 PM',
        'capitol_approved' => '2026-07-13 9:40 AM',
        'shipped'          => '2026-07-15 7:20 AM',
    ],
];

$statusLabels = [
    "po_issued"         => "PO Issued",
    "awaiting_delivery"  => "Awaiting Delivery",
];

// ============================================================
// Stat cards — computed from the mock arrays above
// ============================================================
$today = new DateTime('2026-07-20');
$overdueCount = 0;
foreach ($purchaseOrders as $po) {
    $expected = new DateTime($po['expected_delivery']);
    if ($expected < $today) {
        $overdueCount++;
    }
}
$inTransitCount = 0;
foreach ($purchaseOrders as $po) {
    $reached = $mockTracking[$po['po_number']] ?? [];
    if (isset($reached['shipped']) || isset($reached['in_transit'])) {
        $inTransitCount++;
    }
}
$stats = [
    'awaiting'    => count($purchaseOrders),
    'overdue'     => $overdueCount,
    'in_transit'  => $inTransitCount,
    'delivered_7d' => count($recentlyDelivered),
];

/**
 * Renders the order-tracking stepper for one PO — identical markup to
 * pharmacist/delivery-receiving.php's renderTrackerTimeline() so the two
 * portals render the same tracker.
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
    foreach ($stageOrder as $stage) {
        $isCurrent = ($stage === $currentStage);
        $isDone = isset($reached[$stage]) && !$isCurrent;
        $isPending = !isset($reached[$stage]);
        $stateClass = $isCurrent ? 'tracker-step-current' : ($isDone ? 'tracker-step-done' : 'tracker-step-pending');

        $html .= '<div class="tracker-step ' . $stateClass . '">';
        $html .= '<span class="tracker-dot"></span>';
        $html .= '<div class="tracker-step-body">';
        $html .= '<span class="tracker-step-label">' . htmlspecialchars($stageLabels[$stage]) . '</span>';
        if ($isCurrent || $isDone) {
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
    <link rel="stylesheet" href="../assets/css/admin-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-delivery-receiving.css">
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <!-- Main content -->
        <main class="main-content">

            <header class="page-header">
                <div>
                    <h1>Delivery Receiving</h1>
                    <p class="page-subtitle">Monitor purchase orders in transit and confirmed deliveries.</p>
                </div>
            </header>

            <div class="dr-oversight-note">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="16" x2="12" y2="12"></line>
                    <line x1="12" y1="8" x2="12.01" y2="8"></line>
                </svg>
                <span>Confirming receipt of a delivery is done by the Pharmacist Portal. This view is for tracking and oversight only.</span>
            </div>

            <div class="dr-stats-grid">
                <div class="dr-stat-card">
                    <span class="dr-stat-label">Awaiting Delivery</span>
                    <span class="dr-stat-value"><?php echo $stats['awaiting']; ?></span>
                </div>
                <div class="dr-stat-card">
                    <span class="dr-stat-label">Overdue</span>
                    <span class="dr-stat-value <?php echo $stats['overdue'] > 0 ? 'dr-stat-danger' : ''; ?>"><?php echo $stats['overdue']; ?></span>
                </div>
                <div class="dr-stat-card">
                    <span class="dr-stat-label">In Transit</span>
                    <span class="dr-stat-value dr-stat-warning"><?php echo $stats['in_transit']; ?></span>
                </div>
                <div class="dr-stat-card">
                    <span class="dr-stat-label">Delivered (Recent)</span>
                    <span class="dr-stat-value"><?php echo $stats['delivered_7d']; ?></span>
                </div>
            </div>

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
                        <table class="queue-table">
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
                                <?php foreach ($purchaseOrders as $po):
                                    $isOverdue = (new DateTime($po['expected_delivery'])) < $today;
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($po['po_number']); ?></td>
                                        <td><?php echo htmlspecialchars($po['medicine']); ?></td>
                                        <td><?php echo htmlspecialchars($po['supplier']); ?></td>
                                        <td><?php echo (int) $po['boxes_ordered']; ?> boxes</td>
                                        <td>
                                            <?php echo htmlspecialchars(date('M j, Y', strtotime($po['expected_delivery']))); ?>
                                            <?php if ($isOverdue): ?>
                                                <span class="status-pill status-overdue" style="margin-left:6px;">Overdue</span>
                                            <?php endif; ?>
                                        </td>
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
                                                class="btn btn-secondary btn-sm"
                                                data-open-details="<?php echo htmlspecialchars($po['po_number']); ?>"
                                                data-medicine="<?php echo htmlspecialchars($po['medicine']); ?>"
                                                data-supplier="<?php echo htmlspecialchars($po['supplier']); ?>"
                                                data-boxes-ordered="<?php echo (int) $po['boxes_ordered']; ?>"
                                                data-units-per-box="<?php echo (int) $po['units_per_box']; ?>"
                                                data-expected="<?php echo htmlspecialchars(date('M j, Y', strtotime($po['expected_delivery']))); ?>"
                                                data-status="<?php echo htmlspecialchars($statusLabels[$po['status']]); ?>">
                                                View Details
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card queue-full-card">
                <div class="card-header">
                    <h2>Recently Delivered</h2>
                    <span class="card-subtitle">Confirmed by the Pharmacist Portal</span>
                </div>

                <?php if (empty($recentlyDelivered)): ?>
                    <div class="empty-state">
                        <p>No deliveries confirmed yet</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="queue-table">
                            <thead>
                                <tr>
                                    <th>PO Number</th>
                                    <th>Medicine</th>
                                    <th>Supplier</th>
                                    <th>Boxes Received</th>
                                    <th>Received</th>
                                    <th>Received By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentlyDelivered as $d): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($d['po_number']); ?></td>
                                        <td><?php echo htmlspecialchars($d['medicine']); ?></td>
                                        <td><?php echo htmlspecialchars($d['supplier']); ?></td>
                                        <td><?php echo (int) $d['boxes_received']; ?> boxes (<?php echo (int) $d['boxes_received'] * (int) $d['units_per_box']; ?> units)</td>
                                        <td><?php echo htmlspecialchars($d['received_at']); ?></td>
                                        <td><?php echo htmlspecialchars($d['received_by']); ?></td>
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

    <!-- Track Order modal — identical to the pharmacist portal's, since a
         PO's shipping journey is the same regardless of who's viewing it. -->
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

    <!-- View Details modal — read-only, no editable fields and no confirm
         action, on purpose: admin can look but not mark deliveries
         received (that stays exclusive to the Pharmacist Portal). -->
    <div class="modal-backdrop" id="detailsModal" aria-hidden="true">
        <div class="modal-box dr-modal-box" role="dialog" aria-modal="true" aria-labelledby="detailsModalTitle">
            <div class="dr-modal-header">
                <h3 id="detailsModalTitle">Purchase Order Details</h3>
                <button type="button" class="dr-modal-close" data-close-modal="detailsModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>

            <div class="dr-modal-body">
                <div class="modal-section-label">Purchase Order</div>
                <div class="dr-po-summary">
                    <div class="dr-field">
                        <label>PO Number</label>
                        <span id="modalPoNumber">&mdash;</span>
                    </div>
                    <div class="dr-field">
                        <label>Status</label>
                        <span id="modalStatus">&mdash;</span>
                    </div>
                    <div class="dr-field">
                        <label>Medicine</label>
                        <span id="modalMedicine">&mdash;</span>
                    </div>
                    <div class="dr-field">
                        <label>Supplier</label>
                        <span id="modalSupplier">&mdash;</span>
                    </div>
                    <div class="dr-field">
                        <label>Boxes Ordered</label>
                        <span id="modalBoxesOrdered">&mdash;</span>
                    </div>
                    <div class="dr-field">
                        <label>Expected Delivery</label>
                        <span id="modalExpected">&mdash;</span>
                    </div>
                </div>

                <p class="dr-readonly-note">This is a read-only view. Receipt of the delivery is confirmed by the pharmacist once the shipment arrives.</p>
            </div>

            <div class="dr-modal-actions">
                <button type="button" class="btn btn-secondary" data-close-modal="detailsModal">Close</button>
            </div>
        </div>
    </div>

    <script>
        const trackModal = document.getElementById('trackModal');
        const trackModalTitle = document.getElementById('trackModalTitle');
        const trackModalBody = document.getElementById('trackModalBody');
        const detailsModal = document.getElementById('detailsModal');

        document.querySelectorAll('[data-open-tracker]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const poNumber = btn.dataset.openTracker;
                const template = document.getElementById('tracker-content-' + poNumber);

                trackModalTitle.textContent = `Track Order \u2014 ${poNumber}`;
                trackModalBody.innerHTML = '';
                if (template) {
                    trackModalBody.appendChild(template.content.cloneNode(true));
                }

                openModal(trackModal);
            });
        });

        document.querySelectorAll('[data-open-details]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.getElementById('modalPoNumber').textContent = btn.dataset.openDetails;
                document.getElementById('modalStatus').textContent = btn.dataset.status;
                document.getElementById('modalMedicine').textContent = btn.dataset.medicine;
                document.getElementById('modalSupplier').textContent = btn.dataset.supplier;
                document.getElementById('modalBoxesOrdered').textContent =
                    `${btn.dataset.boxesOrdered} boxes (${btn.dataset.boxesOrdered * btn.dataset.unitsPerBox} units)`;
                document.getElementById('modalExpected').textContent = btn.dataset.expected;

                openModal(detailsModal);
            });
        });

        document.querySelectorAll('[data-close-modal]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                closeModal(document.getElementById(btn.dataset.closeModal));
            });
        });
        [trackModal, detailsModal].forEach(function(m) {
            m.addEventListener('click', function(e) {
                if (e.target === m) closeModal(m);
            });
        });

        function openModal(m) {
            m.classList.add('active');
            document.body.classList.add('modal-open');
        }

        function closeModal(m) {
            m.classList.remove('active');
            document.body.classList.remove('modal-open');
        }
    </script>
</body>

</html>
