/**
 * Pharmacist Assignment History page (inventory-count-history.php).
 *
 * Read-only drawer only — no assign modal, no confirm form, no editable
 * inputs. This page exists for looking up a staff member's full assignment
 * record across all time and every status, so unlike inventory-count.php's
 * drawer (which is editable for submitted batches), this one is ALWAYS
 * read-only, on purpose: if a batch here still needs to be reviewed and
 * confirmed, that action stays on the Inventory Count page — keeping one
 * clear place to actually act on a batch, rather than two pages that can
 * both submit changes to the same batch.
 *
 * Deliberately a separate, smaller file rather than reusing
 * inventory-count.js as-is: that file's rendering assumes an assign
 * modal and confirm form exist on the page (they don't here), and this
 * read-only-only version is simple enough that duplicating the handful
 * of shared helpers (escapeHtml/formatDate/statusLabel/etc.) is safer
 * than trying to make one script serve two very different page shapes.
 */
(function () {
    'use strict';

    const detailsUrl = 'inventory-count-details-ajax.php';
    const drawer = document.getElementById('batchDrawer');
    const drawerBackdrop = document.getElementById('drawerBackdrop');
    const drawerBody = document.getElementById('drawerBody');
    const drawerTitle = document.getElementById('drawerTitle');

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function formatDate(value) {
        if (!value) return '—';
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return escapeHtml(value);
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function formatDateTime(value) {
        if (!value) return '—';
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return escapeHtml(value);
        return date.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    }

    function statusLabel(status) {
        const labels = { pending: 'Pending Count', submitted: 'Awaiting Review', confirmed: 'Confirmed' };
        return labels[status] || status;
    }

    function differenceLabel(difference) {
        if (difference === 0) return 'MATCH';
        return difference < 0 ? 'SHORT' : 'OVER';
    }

    function differenceClass(difference) {
        if (difference === 0) return 'ic-remark-match';
        return difference < 0 ? 'ic-remark-short' : 'ic-remark-over';
    }

    function infoItem(label, value) {
        return '<div class="ic-info-item"><span class="ic-info-label">' + escapeHtml(label) +
            '</span><span class="ic-info-value">' + value + '</span></div>';
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

    function renderBatchHeader(batch) {
        let html = '<div class="ic-drawer-section">';
        html += '<div class="ic-drawer-section-title"><h4>Batch Information</h4>';
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
        if (batch.notes) html += '<div class="ic-note-box"><strong>Notes:</strong> ' + escapeHtml(batch.notes) + '</div>';
        html += '</div>';
        return html;
    }

    function renderReadOnlyTable(items) {
        let html = '<div class="ic-drawer-section">';
        html += '<div class="ic-drawer-section-title"><h4>Count Comparison</h4></div>';
        if (!items.length) {
            html += '<div class="ic-empty">No items on this batch.</div></div>';
            return html;
        }
        html += '<div class="ic-review-table-wrap"><table class="ic-review-table">';
        html += '<thead><tr><th>No.</th><th>Medicine</th><th>Expiration Date</th><th>Staff</th><th>System</th><th>Final</th><th>Difference</th><th>Remarks</th></tr></thead><tbody>';
        items.forEach(function (item, index) {
            const systemCount = Number(item.system_count) || 0;
            const staffCount = Number(item.staff_count) || 0;
            const finalCount = item.final_count === null || item.final_count === '' ? staffCount : Number(item.final_count);
            const difference = staffCount - systemCount;
            const medicineName = String(item.medicine_name || '');
            const itemNumber = String(item.item_id || '');
            html += '<tr>';
            html += '<td>' + (index + 1) + '</td>';
            html += '<td>' + escapeHtml(medicineName) + '<span class="ic-count-row-meta">Item #' + escapeHtml(itemNumber) + ' | ' + escapeHtml(item.unit || '—') + '</span></td>';
            html += '<td>' + formatDate(item.earliest_expiry) + '</td>';
            html += '<td>' + staffCount + '</td>';
            html += '<td>' + systemCount + '</td>';
            html += '<td>' + finalCount + '</td>';
            html += '<td><span class="ic-remark-pill ' + differenceClass(difference) + '">' + difference + ' ' + differenceLabel(difference) + '</span></td>';
            html += '<td>' + escapeHtml(item.remarks || '—') + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table></div></div>';
        return html;
    }

    async function viewBatch(batchId) {
        if (drawerTitle) drawerTitle.textContent = 'Batch Details';
        openDrawer();
        setDrawerLoading();
        try {
            const response = await fetch(detailsUrl + '?batch_id=' + encodeURIComponent(batchId));
            const data = await response.json();
            if (!response.ok || data.error) {
                drawerBody.innerHTML = '<div class="ic-empty">' + escapeHtml(data.error || 'Could not load this batch.') + '</div>';
                return;
            }
            if (drawerTitle) drawerTitle.textContent = data.batch.batch_number || 'Batch Details';
            drawerBody.innerHTML = renderBatchHeader(data.batch) + renderReadOnlyTable(data.items || []);
        } catch (error) {
            drawerBody.innerHTML = '<div class="ic-empty">Could not load this batch. Please try again.</div>';
        }
    }

    document.getElementById('drawerCloseBtn')?.addEventListener('click', closeDrawer);
    drawerBackdrop?.addEventListener('click', function (event) {
        if (event.target === drawerBackdrop) closeDrawer();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && drawerBackdrop?.classList.contains('active')) closeDrawer();
    });
    document.querySelectorAll('[data-view-batch]').forEach(function (button) {
        button.addEventListener('click', function () {
            viewBatch(button.getAttribute('data-view-batch'));
        });
    });
})();