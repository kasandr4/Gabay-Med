/**
 * GabayMed — Inventory Procurement module
 * Drives the request drawer, all action modals, and every AJAX call to
 * inventory-actions.php / inventory-details-ajax.php. Loaded on
 * admin/inventory-procurement.php only.
 */
(function () {
    'use strict';

    const CFG = window.IP_CONFIG || {};
    let currentRequestId = null;
    let currentRequestStatus = null;

    // ============================================================
    // Small helpers
    // ============================================================

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    function money(n) {
        const num = Number(n) || 0;
        return '₱' + num.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function formatDate(d) {
        if (!d) return '—';
        const dt = new Date(d.replace(' ', 'T'));
        if (isNaN(dt.getTime())) return d;
        return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function statusLabel(s) {
        const map = {
            pending: 'Pending',
            approved: 'Approved',
            revision_requested: 'Revision Requested',
            rejected: 'Rejected',
            purchase_ordered: 'Purchase Ordered',
            completed: 'Completed',
            po_issued: 'Purchase Order Issued',
            awaiting_delivery: 'Awaiting Delivery',
            delivered: 'Delivered'
        };
        return map[s] || s;
    }

    function toast(message, isError) {
        const host = document.getElementById('toastHost');
        if (!host) return;
        const el = document.createElement('div');
        el.className = 'ip-toast' + (isError ? ' ip-toast-error' : '');
        el.textContent = message;
        host.appendChild(el);
        requestAnimationFrame(() => el.classList.add('ip-toast-show'));
        setTimeout(() => {
            el.classList.remove('ip-toast-show');
            setTimeout(() => el.remove(), 250);
        }, 3800);
    }

    async function callAction(formData) {
        const res = await fetch(CFG.actionsUrl, { method: 'POST', body: formData });
        let data;
        try {
            data = await res.json();
        } catch (e) {
            data = { success: false, error: 'Unexpected server response.' };
        }
        return data;
    }

    function updateStats(stats) {
        if (!stats) return;
        Object.keys(stats).forEach((key) => {
            const el = document.querySelector('[data-stat-value="' + key + '"]');
            if (el) el.textContent = stats[key];
        });
    }

    // ============================================================
    // Generic modal open/close
    // ============================================================

    function openModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
    }

    function closeModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
        if (!document.querySelector('.modal-backdrop.active')) {
            document.body.classList.remove('modal-open');
        }
        const err = modal.querySelector('.ip-form-error');
        if (err) {
            err.textContent = '';
            err.classList.remove('active');
        }
    }

    document.querySelectorAll('[data-close-modal]').forEach((btn) => {
        btn.addEventListener('click', () => closeModal(btn.getAttribute('data-close-modal')));
    });
    document.querySelectorAll('.modal-backdrop').forEach((backdrop) => {
        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop) closeModal(backdrop.id);
        });
    });
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.modal-backdrop.active').forEach((m) => closeModal(m.id));
        closeDrawer();
    });

    function showFormError(form, message) {
        const err = form.querySelector('.ip-form-error');
        if (!err) return;
        err.textContent = message;
        err.classList.add('active');
    }

    // ============================================================
    // Drawer
    // ============================================================

    const drawer = document.getElementById('requestDrawer');
    const drawerOverlay = document.getElementById('drawerOverlay');
    const drawerBody = document.getElementById('drawerBody');
    const drawerTitle = document.getElementById('drawerTitle');

    function openDrawer() {
        drawer.classList.add('active');
        drawerOverlay.classList.add('active');
        drawer.setAttribute('aria-hidden', 'false');
        document.body.classList.add('drawer-open');
    }

    function closeDrawer() {
        drawer.classList.remove('active');
        drawerOverlay.classList.remove('active');
        drawer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('drawer-open');
    }

    document.getElementById('drawerCloseBtn').addEventListener('click', closeDrawer);
    drawerOverlay.addEventListener('click', closeDrawer);

    async function viewRequest(requestId) {
        currentRequestId = requestId;
        drawerTitle.textContent = 'Request #' + requestId;
        drawerBody.innerHTML =
            '<div class="ip-skeleton-block"></div><div class="ip-skeleton-block"></div><div class="ip-skeleton-block short"></div>';
        openDrawer();

        try {
            const res = await fetch(CFG.detailsUrl + '?request_id=' + encodeURIComponent(requestId));
            const data = await res.json();
            if (!res.ok || data.error) {
                drawerBody.innerHTML = '<div class="empty-state"><p>' + escapeHtml(data.error || 'Could not load this request.') + '</p></div>';
                return;
            }
            currentRequestStatus = data.request.status;
            renderDrawer(data);
        } catch (e) {
            drawerBody.innerHTML = '<div class="empty-state"><p>Could not load this request. Please try again.</p></div>';
        }
    }

    function renderDrawer(data) {
        const req = data.request;
        const bids = data.bids || [];
        const po = data.purchase_order;

        let html = '';

        // ----- Section: Request Information -----
        html += '<div class="ip-drawer-section">';
        html += '<div class="ip-drawer-section-title"><h4>Request Information</h4><span class="status-pill status-pr-' + req.status + '">' + statusLabel(req.status) + '</span></div>';
        html += '<div class="ip-info-grid">';
        html += infoItem('Item Name', escapeHtml(req.medicine_name));
        html += infoItem('Category', escapeHtml(req.category));
        html += infoItem('Current Stock', escapeHtml(req.current_stock) + ' ' + escapeHtml(req.unit));
        html += infoItem('Minimum Stock Level', escapeHtml(req.minimum_stock) + ' ' + escapeHtml(req.unit));
        html += infoItem('Requested Quantity', escapeHtml(req.requested_quantity) + ' ' + escapeHtml(req.unit));
        html += infoItem('Priority', '<span class="priority-badge priority-' + req.priority + '">' + req.priority.charAt(0).toUpperCase() + req.priority.slice(1) + '</span>', true);
        html += infoItem('Requested By', escapeHtml(req.requester_first + ' ' + req.requester_last));
        html += infoItem('Date Requested', formatDate(req.created_at));
        if (req.description) {
            html += infoItem('Description', escapeHtml(req.description), true, true);
        }
        html += infoItem('Reason for Request', escapeHtml(req.reason || '—'), true, true);
        if (req.notes) {
            html += infoItem('Attached Notes', escapeHtml(req.notes), true, true);
        }
        html += '</div>';

        if (req.status === 'revision_requested' && req.revision_note) {
            html += '<div class="ip-note-box ip-note-revision"><strong>Revision requested:</strong> ' + escapeHtml(req.revision_note) + '</div>';
        }
        if (req.status === 'rejected' && req.rejection_reason) {
            html += '<div class="ip-note-box ip-note-rejected"><strong>Rejected:</strong> ' + escapeHtml(req.rejection_reason) + '</div>';
        }

        // Actions available on this request
        if (req.status === 'pending') {
            html += '<div class="ip-drawer-actions">';
            html += '<button class="btn btn-primary" type="button" data-drawer-action="approve">Approve</button>';
            html += '<button class="btn btn-secondary" type="button" data-drawer-action="revision">Send for Revision</button>';
            html += '<button class="btn btn-danger" type="button" data-drawer-action="reject">Reject</button>';
            html += '</div>';
        } else if (req.status === 'revision_requested') {
            html += '<div class="ip-drawer-actions">';
            html += '<p class="ip-field-hint">Waiting on the Pharmacist to edit and resubmit this request.</p>';
            html += '</div>';
        }
        html += '</div>'; // end section

        // ----- Section 2: Supplier Bidding (only for approved+) -----
        const biddingRelevant = ['approved', 'completed'].includes(req.status) || bids.length > 0;
        if (biddingRelevant) {
            html += '<div class="ip-drawer-section">';
            html += '<div class="ip-drawer-section-title"><h4>Supplier Bidding</h4>';
            if (req.status === 'approved' && !po) {
                html += '<button class="btn-secondary-sm" type="button" id="drawerAddBidBtn">+ Add Bid</button>';
            }
            html += '</div>';

            if (bids.length === 0) {
                html += '<div class="empty-state"><div class="empty-illustration"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20v-6M6 20V10M18 20V4"></path></svg></div><p>No supplier bids yet.</p></div>';
            } else {
                const lowestPrice = Math.min(...bids.map((b) => Number(b.bid_price)));
                const hasWinner = bids.some((b) => Number(b.is_winner) === 1);
                html += '<div class="ip-bid-list">';
                bids.forEach((bid) => {
                    const isLowest = Number(bid.bid_price) === lowestPrice;
                    const isWinner = Number(bid.is_winner) === 1;
                    const cardClasses = ['ip-bid-card'];
                    if (isLowest && !hasWinner) cardClasses.push('ip-bid-lowest');
                    if (isWinner) cardClasses.push('ip-bid-winner');
                    if (hasWinner && !isWinner) cardClasses.push('ip-bid-disabled');

                    html += '<div class="' + cardClasses.join(' ') + '">';
                    html += '<div class="ip-bid-top"><span class="ip-bid-supplier">' + escapeHtml(bid.supplier_name);
                    if (isLowest && !hasWinner) html += '<span class="ip-bid-lowest-tag">Lowest Bid</span>';
                    html += '</span><span class="ip-bid-price">' + money(bid.bid_price) + '</span></div>';
                    html += '<div class="ip-bid-meta">';
                    html += '<span>Delivery: <strong>' + formatDate(bid.estimated_delivery_date) + '</strong></span>';
                    html += '<span>Warranty: <strong>' + escapeHtml(bid.warranty || '—') + '</strong></span>';
                    html += '<span>Rating: <strong>' + escapeHtml(Number(bid.supplier_rating).toFixed(1)) + ' / 5.0</strong></span>';
                    html += '<span>Submitted: <strong>' + formatDate(bid.created_at) + '</strong></span>';
                    html += '</div>';

                    if (isWinner) {
                        html += '<span class="ip-winner-badge"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg> Winning Supplier</span>';
                    } else if (!hasWinner && req.status === 'approved') {
                        html += '<div class="ip-bid-actions"><button class="btn-row-action btn-row-approve" type="button" data-select-winner="' + bid.bid_id + '" data-supplier="' + escapeHtml(bid.supplier_name) + '" data-price="' + bid.bid_price + '" data-delivery="' + bid.estimated_delivery_date + '">Select Winner</button></div>';
                    }

                    html += '</div>';
                });
                html += '</div>';
            }
            html += '</div>'; // end section
        }

        // ----- Section 3: Purchase Order -----
        if (po) {
            html += '<div class="ip-drawer-section">';
            html += '<div class="ip-drawer-section-title"><h4>Purchase Order</h4></div>';
            html += '<div class="ip-po-card">';
            html += '<div class="ip-po-header"><span class="ip-po-number">' + escapeHtml(po.po_number) + '</span><span class="status-pill status-po-' + po.status + '">' + statusLabel(po.status) + '</span></div>';
            html += '<div class="ip-info-grid">';
            html += infoItem('PO Number', escapeHtml(po.po_number));
            html += infoItem('Supplier', escapeHtml(po.supplier_name));
            html += infoItem('Item', escapeHtml(req.medicine_name));
            html += infoItem('Category', escapeHtml(req.category));
            html += infoItem('Quantity', escapeHtml(po.approved_quantity) + ' ' + escapeHtml(req.unit));
            html += infoItem('Total Cost', money(po.total_cost));
            html += infoItem('Expected Delivery', formatDate(po.expected_delivery));
            html += infoItem('Procurement Status', statusLabel(po.status));
            html += '</div>';
            html += '<div class="ip-po-actions">';
            if (po.status !== 'completed') {
                const nextLabel = { po_issued: 'Mark Awaiting Delivery', awaiting_delivery: 'Mark Delivered', delivered: 'Mark Completed' }[po.status];
                html += '<button class="btn btn-primary" type="button" data-advance-po="' + po.po_id + '">' + nextLabel + '</button>';
            }
            html += '<a class="btn btn-secondary" href="' + CFG.printUrl + '?po_id=' + po.po_id + '" target="_blank" rel="noopener">Print Purchase Order</a>';
            html += '</div>';
            html += '</div>';
            html += '</div>';
        }

        drawerBody.innerHTML = html;
        wireDrawerButtons();
    }

    function infoItem(label, value, span, spanFull) {
        return (
            '<div class="ip-info-item' + (span || spanFull ? ' span-2' : '') + '">' +
            '<span class="ip-info-label">' + label + '</span>' +
            '<div class="ip-info-value">' + value + '</div>' +
            '</div>'
        );
    }

    function wireDrawerButtons() {
        const approveBtn = drawerBody.querySelector('[data-drawer-action="approve"]');
        if (approveBtn) approveBtn.addEventListener('click', () => quickApprove(currentRequestId));

        const revisionBtn = drawerBody.querySelector('[data-drawer-action="revision"]');
        if (revisionBtn) revisionBtn.addEventListener('click', () => {
            document.getElementById('revisionRequestId').value = currentRequestId;
            openModal('revisionModal');
        });

        const rejectBtn = drawerBody.querySelector('[data-drawer-action="reject"]');
        if (rejectBtn) rejectBtn.addEventListener('click', () => {
            document.getElementById('rejectRequestId').value = currentRequestId;
            openModal('rejectModal');
        });

        const addBidBtn = document.getElementById('drawerAddBidBtn');
        if (addBidBtn) addBidBtn.addEventListener('click', () => {
            document.getElementById('addBidRequestId').value = currentRequestId;
            openModal('addBidModal');
        });

        drawerBody.querySelectorAll('[data-select-winner]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const bidId = btn.getAttribute('data-select-winner');
                document.getElementById('confirmWinnerBidId').value = bidId;
                document.getElementById('confirmWinnerBody').innerHTML =
                    '<div class="ip-info-grid">' +
                    infoItem('Supplier', escapeHtml(btn.getAttribute('data-supplier'))) +
                    infoItem('Bid Amount', money(btn.getAttribute('data-price'))) +
                    infoItem('Delivery Estimate', formatDate(btn.getAttribute('data-delivery')), true) +
                    '</div>';
                // Reset the UI-only second-approver fields for each new
                // confirmation — they should never carry over between bids.
                document.getElementById('secondApproverName').value = '';
                document.getElementById('secondApproverConfirm').checked = false;
                openModal('confirmWinnerModal');
            });
        });

        drawerBody.querySelectorAll('[data-advance-po]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                btn.disabled = true;
                const fd = new FormData();
                fd.append('action', 'advance_po_status');
                fd.append('po_id', btn.getAttribute('data-advance-po'));
                fd.append('csrf_token', getCsrfToken());
                const data = await callAction(fd);
                btn.disabled = false;
                if (data.success) {
                    toast(data.message);
                    updateStats(data.stats);
                    viewRequest(currentRequestId);
                } else {
                    toast(data.error || 'Could not update the purchase order.', true);
                }
            });
        });
    }

    async function quickApprove(requestId) {
        const fd = new FormData();
        fd.append('action', 'approve_request');
        fd.append('request_id', requestId);
        fd.append('csrf_token', getCsrfToken());
        const data = await callAction(fd);
        if (data.success) {
            toast(data.message);
            updateStats(data.stats);
            viewRequest(requestId);
            setTimeout(() => window.location.reload(), 900);
        } else {
            toast(data.error || 'Could not approve this request.', true);
        }
    }

    function getCsrfToken() {
        const anyForm = document.querySelector('input[name="csrf_token"]');
        return anyForm ? anyForm.value : '';
    }

    // ============================================================
    // Table row action buttons (View / Approve / Revise / Reject / Create-from-low-stock)
    // ============================================================

    document.querySelectorAll('.btn-row-action[data-action]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const action = btn.getAttribute('data-action');
            const id = btn.getAttribute('data-id');

            if (action === 'view') {
                viewRequest(id);
            } else if (action === 'approve') {
                quickApprove(id);
            } else if (action === 'revision') {
                document.getElementById('revisionRequestId').value = id;
                openModal('revisionModal');
            } else if (action === 'reject') {
                document.getElementById('rejectRequestId').value = id;
                openModal('rejectModal');
            }
        });
    });

    // NOTE: Create / Resubmit Purchase Request no longer live here.
    // Segregation of duties: only the Pharmacist creates a request
    // (pharmacist/inventory.php "Request Restock") and only the
    // Pharmacist edits & resubmits one after a revision request
    // (same page, "My Restock Requests" table). Admin only reviews,
    // approves, rejects, requests revision, runs bidding, and manages
    // the resulting Purchase Order below.

    // ============================================================
    // Revision / Reject / Add Bid / Confirm Winner forms
    // ============================================================

    function bindSimpleForm(formId, { onSuccessReload = true, validate = null } = {}) {
        const form = document.getElementById(formId);
        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            if (validate) {
                const validationError = validate();
                if (validationError) {
                    showFormError(form, validationError);
                    return;
                }
            }

            const submitBtn = form.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            const fd = new FormData(form);
            const data = await callAction(fd);
            submitBtn.disabled = false;

            const modal = form.closest('.modal-backdrop');
            if (data.success) {
                toast(data.message);
                if (modal) closeModal(modal.id);
                updateStats(data.stats);
                if (currentRequestId) viewRequest(currentRequestId);
                if (onSuccessReload) setTimeout(() => window.location.reload(), 900);
            } else {
                showFormError(form, data.error || 'Something went wrong. Please try again.');
            }
        });
    }

    bindSimpleForm('revisionForm');
    bindSimpleForm('rejectForm');
    bindSimpleForm('addBidForm', { onSuccessReload: false });

    // UI-ONLY safeguard: require a second approver's name + acknowledgment
    // before the winning bid is actually confirmed. Purely a client-side
    // gate on top of the existing select_winner action — see the comment
    // above the fields in inventory-procurement.php for what it would take
    // to make this a real, backend-enforced control.
    bindSimpleForm('confirmWinnerForm', {
        onSuccessReload: false,
        validate: () => {
            const nameEl = document.getElementById('secondApproverName');
            const confirmEl = document.getElementById('secondApproverConfirm');
            if (!nameEl.value.trim()) {
                return 'Enter the name of the second admin who reviewed this selection.';
            }
            if (!confirmEl.checked) {
                return 'Please confirm this selection has been independently reviewed.';
            }
            return null;
        },
    });
})();
