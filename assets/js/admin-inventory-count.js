/**
 * Admin Inventory Count oversight page.
 * Provides read-only batch details and print-to-PDF export.
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

    function statusLabel(status) {
        const labels = {
            pending: 'Pending Count',
            submitted: 'Awaiting Review',
            confirmed: 'Confirmed'
        };
        return labels[status] || status;
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

    function infoItem(label, value, spanTwo) {
        return '<div class="ic-info-item' + (spanTwo ? ' span-2' : '') + '">' +
            '<span class="ic-info-label">' + escapeHtml(label) + '</span>' +
            '<span class="ic-info-value">' + value + '</span>' +
            '</div>';
    }

    function openDrawer() {
        drawer.classList.add('active');
        drawerBackdrop.classList.add('active');
        drawer.setAttribute('aria-hidden', 'false');
        drawerBackdrop.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ic-drawer-open');
    }

    function closeDrawer() {
        drawer.classList.remove('active');
        drawerBackdrop.classList.remove('active');
        drawer.setAttribute('aria-hidden', 'true');
        drawerBackdrop.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('ic-drawer-open');
    }

    function readJson(response) {
        return response.json().catch(function () {
            return { error: 'Unexpected server response.' };
        });
    }

    function differenceClass(difference) {
        if (difference === 0) return 'ic-remark-match';
        return difference < 0 ? 'ic-remark-short' : 'ic-remark-over';
    }

    function differenceLabel(difference) {
        if (difference === 0) return 'MATCH';
        return difference < 0 ? 'SHORT' : 'OVER';
    }

    function renderBatchInfo(batch) {
        let html = '<div class="ic-drawer-section">';
        html += '<div class="ic-drawer-section-title">';
        html += '<h4>Batch Information</h4>';
        html += '<span class="status-pill status-ic-' + escapeHtml(batch.status) + '">' + escapeHtml(statusLabel(batch.status)) + '</span>';
        html += '</div><div class="ic-info-grid">';
        html += infoItem('Batch Number', escapeHtml(batch.batch_number));
        html += infoItem('Assigned To', escapeHtml(batch.staff_name));
        html += infoItem('Assigned By', escapeHtml(batch.pharmacist_name));
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

    function renderItems(items) {
        let html = '<div class="ic-drawer-section">';
        html += '<div class="ic-drawer-section-title"><h4>Count Comparison</h4></div>';
        if (!items.length) {
            return html + '<div class="ic-empty">This batch has no medicine lines.</div></div>';
        }
        html += '<div class="ic-review-table-wrap"><table class="ic-review-table">';
        html += '<thead><tr><th>Medicine</th><th>System</th><th>Staff</th><th>Difference</th><th>Final</th><th>Remarks</th></tr></thead><tbody>';

        items.forEach(function (item) {
            const systemCount = Number(item.system_count) || 0;
            const hasStaffCount = item.staff_count !== null && item.staff_count !== '';
            const staffCount = hasStaffCount ? Number(item.staff_count) : null;
            const hasFinalCount = item.final_count !== null && item.final_count !== '';
            const finalCount = hasFinalCount ? Number(item.final_count) : null;
            const difference = staffCount === null ? null : staffCount - systemCount;

            html += '<tr>';
            html += '<td>' + escapeHtml(item.medicine_name) + '<span class="ic-count-row-meta">' + escapeHtml(item.unit) + '</span></td>';
            html += '<td>' + systemCount + '</td>';
            html += '<td>' + (staffCount === null ? '—' : staffCount) + '</td>';
            if (difference === null) {
                html += '<td>—</td>';
            } else {
                html += '<td><span class="ic-remark-pill ' + differenceClass(difference) + '">' + difference + ' ' + differenceLabel(difference) + '</span></td>';
            }
            html += '<td>' + (finalCount === null ? '—' : finalCount) + '</td>';
            html += '<td>' + escapeHtml(item.remarks || '—') + '</td>';
            html += '</tr>';
        });

        return html + '</tbody></table></div></div>';
    }

    function renderDrawer(data) {
        drawerBody.innerHTML = renderBatchInfo(data.batch) + renderItems(data.items || []);
    }

    async function viewBatch(batchId) {
        drawerTitle.textContent = 'Batch Details';
        drawerBody.innerHTML = '<div class="ic-loading">Loading batch details...</div>';
        openDrawer();

        try {
            const response = await fetch(detailsUrl + '?batch_id=' + encodeURIComponent(batchId));
            const data = await readJson(response);
            if (!response.ok || data.error || !data.batch) {
                drawerBody.innerHTML = '<div class="ic-empty">' + escapeHtml(data.error || 'Could not load this batch.') + '</div>';
                return;
            }
            drawerTitle.textContent = data.batch.batch_number || 'Batch Details';
            renderDrawer(data);
        } catch (error) {
            drawerBody.innerHTML = '<div class="ic-empty">Could not load this batch. Please try again.</div>';
        }
    }

    function exportPdf() {
        const table = document.getElementById('icTable');
        const tableBody = document.getElementById('icTableBody');
        if (!table || !tableBody || !tableBody.querySelector('tr')) {
            alert('There are no records to export yet.');
            return;
        }

        const rows = Array.from(tableBody.querySelectorAll('tr')).map(function (row) {
            const cells = Array.from(row.querySelectorAll('td')).slice(0, 7).map(function (cell) {
                return '<td>' + escapeHtml(cell.textContent.trim()) + '</td>';
            }).join('');
            return '<tr>' + cells + '</tr>';
        }).join('');
        const headers = Array.from(table.querySelectorAll('thead th')).slice(0, 7).map(function (cell) {
            return '<th>' + escapeHtml(cell.textContent.trim()) + '</th>';
        }).join('');
        const generatedAt = new Date().toLocaleString('en-US', {
            dateStyle: 'medium',
            timeStyle: 'short'
        });
        const win = window.open('', '_blank', 'width=960,height=720');

        if (!win) {
            alert('Please allow pop-ups for this site to export a PDF.');
            return;
        }

        win.document.write('<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">' +
            '<title>Inventory Count Oversight - GabayMed</title><style>' +
            'body{font-family:"Segoe UI",Roboto,-apple-system,BlinkMacSystemFont,sans-serif;color:#1c2733;margin:0;padding:36px;}' +
            '.pdf-header{display:flex;align-items:baseline;justify-content:space-between;border-bottom:2px solid #14b8a6;padding-bottom:12px;margin-bottom:6px;}' +
            '.pdf-header h1{margin:0;font-size:19px;color:#0f9c8d;}.pdf-brand{font-size:12px;font-weight:700;}' +
            '.pdf-meta{font-size:12px;color:#6b7785;margin-bottom:22px;}table{width:100%;border-collapse:collapse;font-size:12.5px;}' +
            'th,td{text-align:left;padding:9px 10px;border-bottom:1px solid #e7eaee;}th{background:#e6fbf8;color:#0f9c8d;font-weight:700;}' +
            '</style></head><body><div class="pdf-header"><h1>Inventory Count Oversight</h1>' +
            '<span class="pdf-brand">GabayMed · Admin Portal</span></div>' +
            '<div class="pdf-meta">Generated ' + escapeHtml(generatedAt) + ' · Read-only export</div>' +
            '<table><thead><tr>' + headers + '</tr></thead><tbody>' + rows + '</tbody></table>' +
            '</body></html>');
        win.document.close();
        win.focus();
        setTimeout(function () {
            try {
                win.print();
            } catch (error) {
                // The print dialog can be blocked by the browser after the window opens.
            }
        }, 200);
    }

    document.getElementById('drawerCloseBtn')?.addEventListener('click', closeDrawer);
    drawerBackdrop?.addEventListener('click', function (event) {
        if (event.target === drawerBackdrop) closeDrawer();
    });
    document.getElementById('exportPdfBtn')?.addEventListener('click', exportPdf);

    document.querySelectorAll('[data-view-batch]').forEach(function (button) {
        button.addEventListener('click', function () {
            viewBatch(button.getAttribute('data-view-batch'));
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && drawerBackdrop?.classList.contains('active')) {
            closeDrawer();
        }
    });
})();