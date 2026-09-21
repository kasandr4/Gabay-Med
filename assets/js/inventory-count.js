/**
 * Pharmacist Inventory Count page.
 * Handles assignment, batch detail review, and final confirmation.
 */
(function () {
    'use strict';

    const actionsUrl = 'inventory-count-actions.php';
    const detailsUrl = 'inventory-count-details-ajax.php';
    const assignModal = document.getElementById('assignModal');
    const assignForm = document.getElementById('assignForm');
    const drawer = document.getElementById('batchDrawer');
    const drawerBackdrop = document.getElementById('drawerBackdrop');
    const drawerBody = document.getElementById('drawerBody');
    const drawerTitle = document.getElementById('drawerTitle');
    const toastHost = document.getElementById('icToastHost');

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function formatDate(value) {
        if (!value) return '—';
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return escapeHtml(value);
        return date.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        });
    }

    function formatDateTime(value) {
        if (!value) return '—';
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return escapeHtml(value);
        return date.toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit'
        });
    }

    function statusLabel(status) {
        const labels = {
            pending: 'Pending Count',
            submitted: 'Awaiting Review',
            confirmed: 'Confirmed'
        };
        return labels[status] || status;
    }

    function toast(message, isError) {
        if (!toastHost) return;
        const element = document.createElement('div');
        element.className = 'scan-toast' + (isError ? ' scan-toast-error' : '');
        element.textContent = message;
        toastHost.appendChild(element);
        requestAnimationFrame(function () {
            element.classList.add('scan-toast-show');
        });
        setTimeout(function () {
            element.classList.remove('scan-toast-show');
            setTimeout(function () {
                element.remove();
            }, 250);
        }, 3800);
    }

    function showFormError(message) {
        const error = document.getElementById('assignError');
        if (!error) return;
        error.textContent = message || '';
        error.classList.toggle('active', Boolean(message));
    }

    function openModal() {
        if (!assignModal) return;
        showFormError('');
        assignModal.classList.add('active');
        assignModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
    }

    function closeModal() {
        if (!assignModal) return;
        assignModal.classList.remove('active');
        assignModal.setAttribute('aria-hidden', 'true');
        if (!document.querySelector('.modal-backdrop.active')) {
            document.body.classList.remove('modal-open');
        }
        showFormError('');
    }

    function openDrawer() {
        if (!drawer || !drawerBackdrop) return;
        drawer.classList.add('active');
        drawerBackdrop.classList.add('active');
        drawer.setAttribute('aria-hidden', 'false');
        drawerBackdrop.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ic-drawer-open');
    }

    function closeDrawer() {
        if (!drawer || !drawerBackdrop) return;
        drawer.classList.remove('active');
        drawerBackdrop.classList.remove('active');
        drawer.setAttribute('aria-hidden', 'true');
        drawerBackdrop.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('ic-drawer-open');
    }

    function setDrawerLoading() {
        if (!drawerBody) return;
        drawerBody.innerHTML = '<div class="ic-loading">Loading batch details...</div>';
    }

    function differenceLabel(difference) {
        if (difference === 0) return 'MATCH';
        return difference < 0 ? 'SHORT' : 'OVER';
    }

    function differenceClass(difference) {
        if (difference === 0) return 'ic-remark-match';
        return difference < 0 ? 'ic-remark-short' : 'ic-remark-over';
    }

    function infoItem(label, value, spanTwo) {
        return '<div class="ic-info-item' + (spanTwo ? ' span-2' : '') + '">' +
            '<span class="ic-info-label">' + escapeHtml(label) + '</span>' +
            '<span class="ic-info-value">' + value + '</span>' +
            '</div>';
    }

    function renderBatchHeader(batch) {
        let html = '<div class="ic-drawer-section">';
        html += '<div class="ic-drawer-section-title">';
        html += '<h4>Batch Information</h4>';
        html += '<span class="status-pill status-ic-' + escapeHtml(batch.status) + '">' + escapeHtml(statusLabel(batch.status)) + '</span>';
        html += '</div><div class="ic-info-grid">';
        html += infoItem('Batch Number', escapeHtml(batch.batch_number));
        html += infoItem('Assigned To', escapeHtml(batch.staff_name));
        html += infoItem('Assigned', formatDate(batch.assigned_at));
        html += infoItem('Due Date', formatDate(batch.due_date));
        if (batch.submitted_at) html += infoItem('Submitted', formatDateTime(batch.submitted_at));
        if (batch.confirmed_at) html += infoItem('Confirmed', formatDateTime(batch.confirmed_at));
        if (batch.confirmed_by_name) html += infoItem('Confirmed By', escapeHtml(batch.confirmed_by_name));
        html += '</div>';
        if (batch.notes) {
            html += '<div class="ic-note-box"><strong>Notes:</strong> ' + escapeHtml(batch.notes) + '</div>';
        }
        html += '</div>';
        return html;
    }

    function renderPending(batch, items) {
        const counted = items.filter(function (item) {
            return item.staff_count !== null && item.staff_count !== '';
        }).length;
        let html = '<div class="ic-drawer-section">';
        html += '<div class="ic-drawer-section-title"><h4>Count Progress</h4></div>';
        html += '<div class="ic-info-grid">';
        html += infoItem('Counted', counted + ' / ' + items.length);
        html += infoItem('Remaining', String(Math.max(0, items.length - counted)));
        html += '</div></div>';
        html += '<div class="ic-empty">This batch is waiting for the assigned staff member to submit the physical count.</div>';
        return html;
    }

    // Common variance reasons for the Remarks column (2026-08-28). A plain
    // free-text box let every pharmacist phrase the same reason
    // differently, which makes the saved remarks unreportable/ungroupable
    // later. This adds a dropdown of standard reasons plus an optional
    // detail box, and composes both into the SAME remarks[itemId] field
    // confirm_batch already expects — no backend/DB change, since
    // inventory_count_items.remarks is still just one varchar(255).
    const REMARK_REASONS = [
        'Unverified / Pending Investigation',
        'Unlogged Dispense',
        'Damaged/Expired \u2014 Disposed',
        'Miscount \u2014 Recount Requested',
        'Data Entry Error',
        'Other',
    ];

    // The exact reason label that flags a line for Send Back for Recount
    // (2026-08-29) — kept as one constant so the dropdown option and the
    // recount-detection check can never silently drift apart.
    const RECOUNT_REASON = 'Miscount \u2014 Recount Requested';

    // Splits a previously saved remarks string back into {reason, detail}
    // so re-opening a batch that was saved (or partially filled) before
    // this UI existed still shows something sensible: an exact reason-label
    // match selects that reason with no detail; a "Reason: detail" match
    // (only when the prefix is one of the known reasons) splits both;
    // anything else lands entirely in the free-text detail box, unselected.
    function splitRemarks(remarks) {
        const value = String(remarks || '');
        for (let i = 0; i < REMARK_REASONS.length; i++) {
            const reason = REMARK_REASONS[i];
            if (value === reason) return { reason: reason, detail: '' };
            if (value.indexOf(reason + ': ') === 0) return { reason: reason, detail: value.slice(reason.length + 2) };
        }
        return { reason: '', detail: value };
    }

    function composeRemarks(reason, detail) {
        const trimmedDetail = detail.trim();
        if (reason && trimmedDetail) return reason + ': ' + trimmedDetail;
        if (reason) return reason;
        return trimmedDetail;
    }

    function remarksCellHtml(itemId, existingRemarks) {
        const parts = splitRemarks(existingRemarks);
        let html = '<div class="ic-remarks-cell" data-item="' + itemId + '">';
        html += '<select class="ic-remarks-reason">';
        html += '<option value="">Select a reason (optional)</option>';
        REMARK_REASONS.forEach(function (reason) {
            html += '<option value="' + escapeHtml(reason) + '"' + (parts.reason === reason ? ' selected' : '') + '>' + escapeHtml(reason) + '</option>';
        });
        html += '</select>';
        html += '<input class="ic-count-input ic-remarks-detail" type="text" maxlength="200" placeholder="Add details (optional)" value="' + escapeHtml(parts.detail) + '">';
        html += '<input type="hidden" class="ic-remarks-hidden" name="remarks[' + itemId + ']" value="' + escapeHtml(existingRemarks || '') + '">';
        html += '</div>';
        return html;
    }

    function updateRecountButtonState(container) {
        const button = document.getElementById('recountBtn');
        if (!button) return;
        const count = container.querySelectorAll('tr[data-recount="1"]').length;
        button.disabled = count === 0;
        button.textContent = count > 0 ? 'Send Back for Recount (' + count + ')' : 'Send Back for Recount';
    }

    // Delegated so it works no matter how many rows the table has, and
    // survives drawerBody.innerHTML being rebuilt on every viewBatch() call
    // (a plain addEventListener per-row would silently stop firing the
    // instant the drawer re-renders, since those old row elements are gone).
    function wireRemarksCells(container) {
        function syncCell(target) {
            const cell = target.closest('.ic-remarks-cell');
            if (!cell) return;
            const reason = cell.querySelector('.ic-remarks-reason').value;
            const detail = cell.querySelector('.ic-remarks-detail').value;
            cell.querySelector('.ic-remarks-hidden').value = composeRemarks(reason, detail);
            const row = cell.closest('tr');
            if (row) row.setAttribute('data-recount', reason === RECOUNT_REASON ? '1' : '0');
            updateRecountButtonState(container);
        }
        container.addEventListener('input', function (event) { syncCell(event.target); });
        container.addEventListener('change', function (event) { syncCell(event.target); });
    }

    function renderReviewTable(items, editable) {
        let html = '<div class="ic-drawer-section">';
        html += '<div class="ic-drawer-section-title"><h4>Count Comparison</h4></div>';
        html += '<div class="ic-review-filters">';
        html += '<label class="ic-review-filter-field"><span>Search</span><input type="search" id="reviewSearch" placeholder="Medicine or item number"></label>';
        html += '<label class="ic-review-filter-field"><span>Unit</span><select id="reviewUnit"><option value="">All units</option>';
        const units = Array.from(new Set(items.map(function (item) { return item.unit || ''; }).filter(Boolean))).sort();
        units.forEach(function (unit) {
            html += '<option value="' + escapeHtml(unit.toLowerCase()) + '">' + escapeHtml(unit) + '</option>';
        });
        html += '</select></label></div>';
        html += '<div class="ic-review-table-wrap"><table class="ic-review-table">';
        html += '<thead><tr><th>No.</th><th>Medicine</th><th>Expiration Date</th><th>Staff</th><th>System</th>';
        if (editable) html += '<th>Final Count</th>';
        else html += '<th>Final</th>';
        html += '<th>Difference</th><th>Remarks</th>';
        html += '</tr></thead><tbody>';

        items.forEach(function (item, index) {
            const systemCount = Number(item.system_count) || 0;
            const staffCount = Number(item.staff_count) || 0;
            const finalCount = item.final_count === null || item.final_count === ''
                ? staffCount
                : Number(item.final_count);
            const difference = staffCount - systemCount;
            const itemId = escapeHtml(item.item_id);

            const medicineName = String(item.medicine_name || '');
            const itemNumber = String(item.item_id || '');
            const initialReason = splitRemarks(item.remarks || '').reason;
            html += '<tr data-item-id="' + itemId + '" data-recount="' + (initialReason === RECOUNT_REASON ? '1' : '0') + '" data-review-name="' + escapeHtml((medicineName + ' ' + itemNumber).toLowerCase()) + '" data-review-unit="' + escapeHtml(String(item.unit || '').toLowerCase()) + '">';
            html += '<td>' + (index + 1) + '</td>';
            html += '<td>' + escapeHtml(medicineName) + '<span class="ic-count-row-meta">Item #' + escapeHtml(itemNumber) + ' | ' + escapeHtml(item.unit || '—') + '</span></td>';
            html += '<td>' + formatDate(item.earliest_expiry) + '</td>';
            html += '<td>' + staffCount + '</td>';
            html += '<td>' + systemCount + '</td>';
            if (editable) {
                html += '<td><input class="ic-count-input" type="number" min="0" step="1" name="final_count[' + itemId + ']" value="' + finalCount + '" required></td>';
            } else {
                html += '<td>' + finalCount + '</td>';
            }
            html += '<td><span class="ic-remark-pill ' + differenceClass(difference) + '">' + difference + ' ' + differenceLabel(difference) + '</span></td>';
            if (editable) {
                html += '<td>' + remarksCellHtml(itemId, item.remarks || '') + '</td>';
            } else {
                html += '<td>' + escapeHtml(item.remarks || '—') + '</td>';
            }
            html += '</tr>';
        });

        html += '</tbody></table></div></div>';
        return html;
    }

    function filterReviewRows() {
        const query = (document.getElementById('reviewSearch')?.value || '').trim().toLowerCase();
        const unit = document.getElementById('reviewUnit')?.value || '';
        document.querySelectorAll('#batchDrawer .ic-review-table tbody tr').forEach(function (row) {
            const matchesSearch = (row.dataset.reviewName || '').includes(query);
            const matchesUnit = !unit || row.dataset.reviewUnit === unit;
            row.hidden = !(matchesSearch && matchesUnit);
        });
    }

    function renderDrawer(data) {
        const batch = data.batch;
        const items = data.items || [];
        const editable = batch.status === 'submitted';
        let html = renderBatchHeader(batch);

        const bodyHtml = (batch.status === 'pending')
            ? renderPending(batch, items)
            : renderReviewTable(items, editable);

        if (editable) {
            // BUG FIX (2026-08-28): the review table (final_count[]/
            // remarks[] inputs) previously sat OUTSIDE this <form> — it was
            // appended to drawerBody's HTML before the form tag opened, and
            // no input had a form="confirmBatchForm" attribute pointing back
            // in. FormData(form) only collects a form's own associated
            // controls, so every Confirm & Finalize submission silently sent
            // final_count/remarks as empty arrays, no matter what a
            // pharmacist typed — confirm_batch's own fallback to staff_count
            // masked this for final_count, but remarks were never actually
            // saved at all. Wrapping the table inside the form fixes both.
            html += '<form id="confirmBatchForm">';
            html += '<input type="hidden" name="action" value="confirm_batch">';
            html += '<input type="hidden" name="batch_id" value="' + escapeHtml(batch.batch_id) + '">';
            const csrf = document.querySelector('#assignForm input[name="csrf_token"]');
            if (csrf) html += '<input type="hidden" name="csrf_token" value="' + escapeHtml(csrf.value) + '">';
            html += bodyHtml;
            html += '<div class="ic-review-actions">';
            html += '<button class="btn btn-secondary" type="button" id="recountBtn" disabled>Send Back for Recount</button>';
            html += '<button class="btn btn-primary" type="submit">Confirm &amp; Finalize</button>';
            html += '</div>';
            html += '</form>';
        } else {
            html += bodyHtml;
        }

        drawerBody.innerHTML = html;
        if (editable) {
            document.getElementById('confirmBatchForm').addEventListener('submit', submitConfirmation);
            document.getElementById('recountBtn')?.addEventListener('click', function () { submitRecount(batch.batch_id); });
            wireRemarksCells(drawerBody);
            updateRecountButtonState(drawerBody);
        }
        document.getElementById('reviewSearch')?.addEventListener('input', filterReviewRows);
        document.getElementById('reviewUnit')?.addEventListener('change', filterReviewRows);
    }

    async function submitRecount(batchId) {
        const button = document.getElementById('recountBtn');
        if (!button) return;
        const itemIds = Array.from(drawerBody.querySelectorAll('tr[data-recount="1"]')).map(function (row) {
            return row.getAttribute('data-item-id');
        });
        if (itemIds.length === 0) return;

        const confirmed = window.confirm(
            'Send ' + itemIds.length + ' item' + (itemIds.length === 1 ? '' : 's') + ' back to the staff member for a recount? ' +
            'This clears their count for just those items and reopens the whole batch for editing — any Final Count or Remarks you\'ve ' +
            'typed for OTHER lines in this batch won\'t be saved and will need to be re-entered once the recount comes back.'
        );
        if (!confirmed) return;

        const csrf = document.querySelector('#assignForm input[name="csrf_token"]');
        const formData = new FormData();
        formData.append('action', 'request_recount');
        formData.append('batch_id', batchId);
        if (csrf) formData.append('csrf_token', csrf.value);
        itemIds.forEach(function (id) { formData.append('recount_item_ids[]', id); });

        button.disabled = true;
        try {
            const response = await fetch(actionsUrl, { method: 'POST', body: formData });
            const data = await readJson(response);
            if (!response.ok || !data.success) {
                toast(data.error || 'Could not send this batch back for recount.', true);
                button.disabled = false;
                return;
            }
            toast(data.message || 'Sent back for recount.');
            closeDrawer();
            window.location.reload();
        } catch (error) {
            toast('Could not send this batch back for recount. Please try again.', true);
            button.disabled = false;
        }
    }

    async function readJson(response) {
        try {
            return await response.json();
        } catch (error) {
            return { success: false, error: 'Unexpected server response.' };
        }
    }

    async function viewBatch(batchId) {
        drawerTitle.textContent = 'Batch Details';
        setDrawerLoading();
        openDrawer();

        try {
            const response = await fetch(detailsUrl + '?batch_id=' + encodeURIComponent(batchId));
            const data = await readJson(response);
            if (!response.ok || data.error || !data.batch) {
                drawerBody.innerHTML = '<div class="ic-empty">' + escapeHtml(data.error || 'Could not load this batch.') + '</div>';
                return;
            }
            drawerTitle.textContent = escapeHtml(data.batch.batch_number || 'Batch Details');
            renderDrawer(data);
        } catch (error) {
            drawerBody.innerHTML = '<div class="ic-empty">Could not load this batch. Please try again.</div>';
        }
    }

    async function submitConfirmation(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const button = form.querySelector('button[type="submit"]');
        const formData = new FormData(form);
        button.disabled = true;

        try {
            const response = await fetch(actionsUrl, { method: 'POST', body: formData });
            const data = await readJson(response);
            if (!response.ok || !data.success) {
                toast(data.error || 'Could not finalize this batch.', true);
                button.disabled = false;
                return;
            }
            toast(data.message || 'Batch confirmed — inventory updated.');
            closeDrawer();
            window.location.reload();
        } catch (error) {
            toast('Could not finalize this batch. Please try again.', true);
            button.disabled = false;
        }
    }

    async function submitAssignment(event) {
        event.preventDefault();
        const formData = new FormData(assignForm);
        const submitButton = assignForm.querySelector('button[type="submit"]');
        showFormError('');
        submitButton.disabled = true;

        try {
            const response = await fetch(actionsUrl, { method: 'POST', body: formData });
            const data = await readJson(response);
            if (!response.ok || !data.success) {
                showFormError(data.error || 'Could not create the count batch.');
                submitButton.disabled = false;
                return;
            }
            toast(data.message || 'Count batch assigned.');
            closeModal();
            window.location.reload();
        } catch (error) {
            showFormError('Could not create the count batch. Please try again.');
            submitButton.disabled = false;
        }
    }

    function updateSelectedCount() {
        const selectedCount = document.getElementById('selectedCount');
        const medicineList = document.getElementById('medicineList');
        if (!selectedCount || !medicineList) return;
        const count = medicineList.querySelectorAll('input[type="checkbox"]:checked').length;
        selectedCount.textContent = count + ' medicine' + (count === 1 ? '' : 's') + ' selected';
    }

    function filterMedicines(event) {
        const query = event.target.value.trim().toLowerCase();
        document.querySelectorAll('#medicineList .ic-medicine-row').forEach(function (row) {
            row.hidden = !(row.dataset.name || '').includes(query);
        });
    }

    function setAssignmentMode(mode) {
        const modeInput = document.getElementById('assignmentMode');
        if (modeInput) modeInput.value = mode;
        document.querySelectorAll('[data-assignment-mode]').forEach(function (tab) {
            const active = tab.dataset.assignmentMode === mode;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        document.querySelectorAll('[data-assignment-panel]').forEach(function (panel) {
            const active = panel.dataset.assignmentPanel === mode;
            panel.hidden = !active;
            panel.classList.toggle('is-active', active);
            panel.querySelectorAll('input, select, textarea').forEach(function (input) {
                input.disabled = !active;
            });
        });
    }

    document.getElementById('openAssignBtn')?.addEventListener('click', openModal);
    document.getElementById('drawerCloseBtn')?.addEventListener('click', closeDrawer);
    assignForm?.addEventListener('submit', submitAssignment);
    document.getElementById('medicineSearch')?.addEventListener('input', filterMedicines);
    document.getElementById('medicineList')?.addEventListener('change', updateSelectedCount);
    document.querySelectorAll('[data-assignment-mode]').forEach(function (tab) {
        tab.addEventListener('click', function () {
            setAssignmentMode(tab.dataset.assignmentMode);
        });
    });
    setAssignmentMode('selection');

    document.querySelectorAll('[data-close-modal="assignModal"]').forEach(function (button) {
        button.addEventListener('click', closeModal);
    });

    assignModal?.addEventListener('click', function (event) {
        if (event.target === assignModal) closeModal();
    });

    drawerBackdrop?.addEventListener('click', function (event) {
        if (event.target === drawerBackdrop) closeDrawer();
    });

    document.querySelectorAll('[data-view-batch]').forEach(function (button) {
        button.addEventListener('click', function () {
            viewBatch(button.getAttribute('data-view-batch'));
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        if (assignModal?.classList.contains('active')) closeModal();
        if (drawerBackdrop?.classList.contains('active')) closeDrawer();
    });
})();