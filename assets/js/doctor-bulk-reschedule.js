// assets/js/doctor-bulk-reschedule.js
// GabayMed — Doctor Portal: My Schedule page, "Reschedule Selected"
// bulk flow in the Needs Follow-Up panel.
//
// Doctor-scoped twin of the staff bulk-reschedule concept that used to
// live on staff/schedule-conflicts.php - that responsibility has since
// moved entirely to the doctor's own side (doctors know their own
// patients and their own reason for being out, so they're better placed
// to pick new slots than staff relaying secondhand). Staff now only get
// a read-only Doctor Availability view.
//
// Talks to bulk_reschedule in my-schedule-actions.php, which always
// resolves "which doctor" from the logged-in session - there's no
// doctor picker or doctor-id data attribute anywhere here, unlike a
// staff-facing version would need.

(function () {
    'use strict';

    const selectAll = document.getElementById('msSelectAll');
    if (!selectAll) return; // no Needs Follow-Up panel on this page load

    const rowChecks = () => Array.from(document.querySelectorAll('.ms-row-check'));
    const bulkBar = document.getElementById('msBulkBar');
    const bulkCount = document.getElementById('msBulkCount');
    const bulkRescheduleBtn = document.getElementById('msBulkRescheduleBtn');

    const csrfToken = window.msCsrfToken || '';

    const backdrop = document.getElementById('msBulkModalBackdrop');
    const modalError = document.getElementById('msBulkModalError');
    const modalContext = document.getElementById('msBulkModalContext');
    const modalForm = document.getElementById('msBulkModalForm');
    const modalResults = document.getElementById('msBulkModalResults');
    const searchFrom = document.getElementById('msBulkSearchFrom');
    const reasonSelect = document.getElementById('msBulkReason');
    const reasonOtherWrap = document.getElementById('msBulkReasonOtherWrap');
    const reasonOtherInput = document.getElementById('msBulkReasonOther');
    const closeBtn = document.getElementById('msBulkModalClose');
    const cancelBtn = document.getElementById('msBulkModalCancel');
    const confirmBtn = document.getElementById('msBulkModalConfirm');

    function getReasonValue() {
        if (!reasonSelect) return '';
        const selected = reasonSelect.value;
        if (selected === 'Other') {
            return (reasonOtherInput.value || '').trim();
        }
        return selected;
    }

    if (reasonSelect) {
        reasonSelect.addEventListener('change', () => {
            const isOther = reasonSelect.value === 'Other';
            reasonOtherWrap.style.display = isOther ? '' : 'none';
            if (!isOther) reasonOtherInput.value = '';
        });
    }

    function selectedRows() {
        return rowChecks().filter(cb => cb.checked);
    }

    function refreshBulkBar() {
        const selected = selectedRows();
        if (selected.length > 1) {
            bulkBar.hidden = false;
            bulkCount.textContent = `${selected.length} selected`;
        } else {
            bulkBar.hidden = true;
        }
        selectAll.checked = rowChecks().length > 0 && rowChecks().every(cb => cb.checked);
    }

    selectAll.addEventListener('change', () => {
        rowChecks().forEach((cb) => { cb.checked = selectAll.checked; });
        refreshBulkBar();
    });

    document.addEventListener('change', (e) => {
        if (e.target.classList && e.target.classList.contains('ms-row-check')) {
            refreshBulkBar();
        }
    });

    function showModalError(message) {
        modalError.textContent = message;
        modalError.classList.toggle('rs-modal-error-visible', !!message);
    }

    async function postAction(action, fields) {
        const body = new URLSearchParams({ action, csrf_token: csrfToken, ...fields });
        const res = await fetch('my-schedule-actions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
        });
        let data;
        try {
            data = await res.json();
        } catch (e) {
            throw new Error('Unexpected response from the server. Please try again.');
        }
        if (!res.ok || !data.success) {
            throw new Error(data.error || 'Something went wrong. Please try again.');
        }
        return data;
    }

    function openModal() {
        const selected = selectedRows();
        if (selected.length < 2) return;

        showModalError('');
        modalForm.hidden = false;
        modalResults.hidden = true;
        modalResults.innerHTML = '';
        confirmBtn.hidden = false;
        confirmBtn.disabled = false;
        confirmBtn.textContent = 'Reschedule All';
        confirmBtn.onclick = null; // clear any leftover "Done" handler from a previous run

        modalContext.textContent = `${selected.length} appointments selected.`;

        reasonSelect.value = '';
        reasonOtherWrap.style.display = 'none';
        reasonOtherInput.value = '';

        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        searchFrom.value = tomorrow.toISOString().slice(0, 10);
        searchFrom.disabled = false;

        document.body.classList.add('modal-open');
        backdrop.classList.add('active');

        bindConfirmHandler();
    }

    function closeModal() {
        backdrop.classList.remove('active');
        document.body.classList.remove('modal-open');
    }

    bulkRescheduleBtn.addEventListener('click', openModal);
    closeBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) closeModal();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && backdrop.classList.contains('active')) closeModal();
    });

    // Bound fresh each time the modal opens (rather than once at load)
    // so it always reads the CURRENT selection, not whatever was
    // selected the first time the modal was ever opened.
    function bindConfirmHandler() {
        confirmBtn.onclick = async () => {
            showModalError('');

            const reason = getReasonValue();
            if (!reason) {
                showModalError('Please select or enter a reason for rescheduling.');
                return;
            }
            if (!searchFrom.value) {
                showModalError('Please choose a starting date.');
                return;
            }

            const ids = selectedRows().map(cb => cb.dataset.appointmentId).join(',');
            if (!ids) {
                showModalError('Please select at least one appointment.');
                return;
            }

            confirmBtn.disabled = true;
            confirmBtn.textContent = 'Rescheduling…';
            searchFrom.disabled = true;

            try {
                const data = await postAction('bulk_reschedule', {
                    appointment_ids: ids,
                    search_from_date: searchFrom.value,
                    reason: reason,
                });

                modalForm.hidden = true;
                modalResults.hidden = false;

                let html = `<p class="schedule-day-empty" style="margin-bottom: 10px;">${data.rescheduled_count} appointment${data.rescheduled_count === 1 ? '' : 's'} rescheduled and ${data.rescheduled_count === 1 ? 'its patient has' : 'their patients have'} been notified.</p>`;

                if (data.unresolved && data.unresolved.length) {
                    html += `<p class="schedule-day-empty" style="margin-bottom: 6px;"><strong>${data.unresolved.length} need manual attention:</strong></p>`;
                    html += '<ul style="margin: 0 0 10px 18px; padding: 0; font-size: 13px;">';
                    data.unresolved.forEach((u) => {
                        html += `<li>${u.patient_name} (was ${u.old_time}) - ${u.reason}</li>`;
                    });
                    html += '</ul>';
                    html += '<p class="ds-editor-hint">Use the regular Reschedule button on each of these to place them by hand - most likely there just isn\'t an open slot close to their original time within 15 days.</p>';
                }

                modalResults.innerHTML = html;
                confirmBtn.textContent = 'Done';
                confirmBtn.disabled = false;
                confirmBtn.onclick = () => window.location.reload();
            } catch (err) {
                showModalError(err.message);
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Reschedule All';
                searchFrom.disabled = false;
            }
        };
    }
})();
