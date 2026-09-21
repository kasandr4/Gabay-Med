/**
 * Staff Inventory Count page.
 * Keeps count entry blind and sends only staff-entered quantities.
 */
(function () {
    'use strict';

    const actionsUrl = 'inventory-count-actions.php';
    const detailsUrl = 'inventory-count-details-ajax.php';
    const drawer = document.getElementById('countDrawer');
    const drawerBackdrop = document.getElementById('drawerBackdrop');
    const drawerBody = document.getElementById('drawerBody');
    const drawerTitle = document.getElementById('drawerTitle');
    const toastHost = document.getElementById('icToastHost');
    const csrfToken = document.getElementById('inventoryCountCsrf')?.value || '';
    let hasUnsavedProgress = false;

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
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

    function openDrawer() {
        hasUnsavedProgress = false;
        drawer.classList.add('active');
        drawerBackdrop.classList.add('active');
        drawer.setAttribute('aria-hidden', 'false');
        drawerBackdrop.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ic-drawer-open');
    }

    function closeDrawer() {
        hasUnsavedProgress = false;
        drawer.classList.remove('active');
        drawerBackdrop.classList.remove('active');
        drawer.setAttribute('aria-hidden', 'true');
        drawerBackdrop.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('ic-drawer-open');
    }

    function setLoading() {
        drawerBody.innerHTML = '<div class="ic-loading">Loading count details...</div>';
    }

    function readJson(response) {
        return response.json().catch(function () {
            return { success: false, error: 'Unexpected server response.' };
        });
    }

    function renderBatchInfo(batch) {
        let html = '<div class="ic-drawer-section">';
        html += '<div class="ic-drawer-section-title">';
        html += '<h4>Assignment Information</h4>';
        html += '<span class="status-pill status-ic-' + escapeHtml(batch.status) + '">' + escapeHtml(statusLabel(batch.status)) + '</span>';
        html += '</div><div class="ic-info-grid">';
        html += infoItem('Batch Number', escapeHtml(batch.batch_number));
        html += infoItem('Assigned By', escapeHtml(batch.assigned_by_name));
        html += infoItem('Due Date', escapeHtml(batch.due_date || '—'));
        html += '</div>';
        if (batch.notes) {
            html += '<div class="ic-note-box"><strong>Instructions:</strong> ' + escapeHtml(batch.notes) + '</div>';
        }
        html += '</div>';
        return html;
    }

    function infoItem(label, value) {
        return '<div class="ic-info-item"><span class="ic-info-label">' + escapeHtml(label) + '</span>' +
            '<span class="ic-info-value">' + value + '</span></div>';
    }

    function statusLabel(status) {
        const labels = {
            pending: 'Not Started / In Progress',
            submitted: 'Submitted',
            confirmed: 'Confirmed'
        };
        return labels[status] || status;
    }

    function renderCountForm(batch, items) {
        const editable = batch.status === 'pending';
        let html = '<div class="ic-drawer-section">';
        html += '<div class="ic-drawer-section-title"><h4>Medicine Count</h4></div>';
        if (!editable) {
            html += '<div class="ic-note-box">This batch is no longer editable.</div>';
        }
        html += '<form id="staffCountForm" class="ic-count-list">';
        html += '<input type="hidden" name="batch_id" value="' + escapeHtml(batch.batch_id) + '">';

        items.forEach(function (item) {
            const itemId = escapeHtml(item.item_id);
            const value = item.staff_count === null || item.staff_count === '' ? '' : escapeHtml(item.staff_count);
            html += '<label class="ic-count-row">';
            html += '<span><span class="ic-count-row-name">' + escapeHtml(item.medicine_name) + '</span>';
            html += '<span class="ic-count-row-meta">';
            html += 'Brand: ' + escapeHtml(item.brand || '—');
            html += ' | Unit: ' + escapeHtml(item.unit || '—');
            html += ' | Expiry: ' + escapeHtml(item.expiry_date || '—');
            html += '</span></span>';
            html += '<input class="ic-count-input" type="number" min="0" step="1" inputmode="numeric"';
            html += ' name="staff_count[' + itemId + ']" value="' + value + '"';
            if (!editable) html += ' disabled';
            html += ' aria-label="Count for ' + escapeHtml(item.medicine_name) + '">';
            html += '</label>';
        });

        html += '<p id="staffCountError" class="ic-form-error" role="alert"></p>';
        if (editable) {
            html += '<div class="ic-drawer-actions">';
            html += '<button class="btn btn-secondary" type="button" id="saveProgressBtn">Save</button>';
            html += '<button class="btn btn-primary" type="submit" id="submitBatchBtn">Submit</button>';
            html += '</div>';
        }
        html += '</form></div>';
        return html;
    }

    function renderDrawer(data) {
        const batch = data.batch;
        const items = data.items || [];
        drawerBody.innerHTML = renderBatchInfo(batch) + renderCountForm(batch, items);
        if (batch.status !== 'pending') return;

        const form = document.getElementById('staffCountForm');
        document.getElementById('saveProgressBtn').addEventListener('click', function () {
            submitCounts(form, 'save_progress');
        });
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submitCounts(form, 'submit_batch');
        });
        updateSubmitState(form);
        form.addEventListener('input', function () {
            hasUnsavedProgress = true;
            updateSubmitState(form);
            clearInlineError();
        });
    }

    function updateSubmitState(form) {
        const submitButton = document.getElementById('submitBatchBtn');
        if (!submitButton) return;
        const inputs = Array.from(form.querySelectorAll('input[name^="staff_count["]'));
        submitButton.disabled = inputs.some(function (input) {
            return input.value.trim() === '';
        });
    }

    function showInlineError(message) {
        const error = document.getElementById('staffCountError');
        if (!error) return;
        error.textContent = message || '';
        error.classList.toggle('active', Boolean(message));
    }

    function clearInlineError() {
        showInlineError('');
    }

    async function submitCounts(form, action) {
        const buttons = form.querySelectorAll('button');
        buttons.forEach(function (button) {
            button.disabled = true;
        });
        clearInlineError();

        const formData = new FormData(form);
        formData.append('action', action);
        formData.append('csrf_token', csrfToken);

        try {
            const response = await fetch(actionsUrl, { method: 'POST', body: formData });
            const data = await readJson(response);
            if (!response.ok || !data.success) {
                if (action === 'submit_batch' && response.status === 422) {
                    showInlineError(data.error || 'Please count all items before submitting.');
                } else {
                    toast(data.error || 'Could not save this count.', true);
                }
                updateSubmitState(form);
                if (action === 'save_progress') {
                    const saveButton = document.getElementById('saveProgressBtn');
                    if (saveButton) saveButton.disabled = false;
                }
                return;
            }

            if (action === 'save_progress') {
                hasUnsavedProgress = false;
                toast(data.message || 'Progress saved.');
                buttons.forEach(function (button) {
                    button.disabled = false;
                });
                updateSubmitState(form);
                return;
            }

            toast(data.message || 'Count submitted for review.');
            closeDrawer();
            window.location.reload();
        } catch (error) {
            toast('Could not save this count. Please try again.', true);
            buttons.forEach(function (button) {
                button.disabled = false;
            });
            updateSubmitState(form);
        }
    }

    async function openBatch(batchId) {
        drawerTitle.textContent = 'Count Entry';
        setLoading();
        openDrawer();

        try {
            const response = await fetch(detailsUrl + '?batch_id=' + encodeURIComponent(batchId));
            const data = await readJson(response);
            if (!response.ok || data.error || !data.batch) {
                drawerBody.innerHTML = '<div class="ic-empty">' + escapeHtml(data.error || 'Could not load this batch.') + '</div>';
                return;
            }
            drawerTitle.textContent = data.batch.batch_number || 'Count Entry';
            renderDrawer(data);
        } catch (error) {
            drawerBody.innerHTML = '<div class="ic-empty">Could not load this batch. Please try again.</div>';
        }
    }

    document.querySelectorAll('[data-assignment-tab]').forEach(function (tab) {
        tab.addEventListener('click', function () {
            const selected = tab.getAttribute('data-assignment-tab');
            document.querySelectorAll('[data-assignment-tab]').forEach(function (otherTab) {
                const isSelected = otherTab === tab;
                otherTab.classList.toggle('is-active', isSelected);
                otherTab.setAttribute('aria-selected', isSelected ? 'true' : 'false');
            });
            document.querySelectorAll('.ic-assignment-panel').forEach(function (panel) {
                const isSelected = panel.id === selected + 'Assignments';
                panel.hidden = !isSelected;
                panel.classList.toggle('is-active', isSelected);
            });
        });
    });

    document.getElementById('drawerCloseBtn')?.addEventListener('click', closeDrawer);
    drawerBackdrop?.addEventListener('click', function (event) {
        if (event.target === drawerBackdrop) closeDrawer();
    });

    document.querySelectorAll('[data-open-count]').forEach(function (button) {
        button.addEventListener('click', function () {
            openBatch(button.getAttribute('data-open-count'));
        });
    });

    document.querySelectorAll('.logout-btn').forEach(function (logoutLink) {
        logoutLink.addEventListener('click', function (event) {
            if (!drawerBackdrop?.classList.contains('active') || !hasUnsavedProgress) return;
            const leave = window.confirm('You have unsaved progress. Please save or complete your progress before logging out. Log out anyway?');
            if (!leave) event.preventDefault();
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && drawerBackdrop?.classList.contains('active')) {
            closeDrawer();
        }
    });
})();