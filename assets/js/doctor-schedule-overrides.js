// assets/js/doctor-schedule-overrides.js
// GabayMed — Admin Portal: Doctor Schedule page, Weekly Overrides tab.
// REAL and backend-connected: Create/Edit/Reset all fetch() POST to
// admin/doctor-schedule-actions.php (save_override / delete_override),
// which writes to `doctor_schedule_overrides`. On success the page
// reloads, so the table, summary cards, and the read-only Monthly
// Schedule calendar (which resolves through the same table via
// includes/schedule_resolver.php) all reflect the change immediately.
//
// This file used to be missing from the project - a copy of its
// UI-only mock logic was accidentally saved as assets/js/doctor-schedule.js
// instead. That file is no longer loaded by admin/doctor-schedule.php to
// avoid double-binding the same element IDs; this is the one real
// implementation now.

(function () {
    'use strict';

    const overridesCard = document.getElementById('woOverridesCard');
    if (!overridesCard) return;

    const weekData = window.woWeekData || {};
    const csrfToken = window.woCsrfToken || '';

    const isDeactivated = overridesCard.dataset.deactivated === '1';
    const weekStart = overridesCard.dataset.weekStart;
    const currentDoctorId = overridesCard.dataset.doctorId;

    /* ============================= Toasts ============================= */

    function showToast(message) {
        let host = document.getElementById('woToastHost');
        if (!host) {
            host = document.createElement('div');
            host.className = 'ds-toast-host';
            host.id = 'woToastHost';
            document.body.appendChild(host);
        }
        const toast = document.createElement('div');
        toast.className = 'ds-toast';
        toast.textContent = message;
        host.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('ds-toast-show'));
        setTimeout(() => {
            toast.classList.remove('ds-toast-show');
            setTimeout(() => toast.remove(), 250);
        }, 3200);
    }

    async function postAction(action, fields) {
        const body = new URLSearchParams({ action, csrf_token: csrfToken, ...fields });
        const res = await fetch('doctor-schedule-actions.php', {
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

    /* ============================ Modal ============================= */

    const backdrop = document.getElementById('woOverrideModalBackdrop');
    const modalTitle = document.getElementById('woOverrideModalTitle');
    const modalError = document.getElementById('woModalError');
    const fieldDoctor = document.getElementById('woFieldDoctor');
    const fieldDate = document.getElementById('woFieldDate');
    const fieldStart = document.getElementById('woFieldStart');
    const fieldEnd = document.getElementById('woFieldEnd');
    const fieldStatus = document.getElementById('woFieldStatus');
    const fieldReason = document.getElementById('woFieldReason');
    const fieldNotes = document.getElementById('woFieldNotes');
    const closeBtn = document.getElementById('woOverrideModalClose');
    const cancelBtn = document.getElementById('woOverrideModalCancel');
    const saveBtn = document.getElementById('woOverrideModalSave');

    // Statuses that require start/end times to be filled in
    const statusesWithHours = ['available', 'half_day', 'training', 'meeting'];

    function showModalError(message) {
        if (!modalError) return;
        modalError.textContent = message;
        modalError.classList.toggle('rs-modal-error-visible', !!message);
    }

    function openModal(dateStr) {
        const day = dateStr ? weekData[dateStr] : null;

        showModalError('');
        modalTitle.textContent = day && day.status !== 'no_change' ? 'Edit Override' : 'Create Override';
        if (fieldDoctor) fieldDoctor.value = currentDoctorId;
        fieldDate.value = dateStr || weekStart;
        fieldDate.min = weekStart;

        if (day) {
            fieldStart.value = day.start || '';
            fieldEnd.value = day.end || '';
            fieldStatus.value = day.status === 'no_change' ? 'available' : day.status;
            fieldReason.value = day.reason || '';
            fieldNotes.value = day.notes || '';
        } else {
            fieldStart.value = '';
            fieldEnd.value = '';
            fieldStatus.value = 'available';
            fieldReason.value = '';
            fieldNotes.value = '';
        }

        document.body.classList.add('modal-open');
        backdrop.classList.add('active');
    }

    function closeModal() {
        backdrop.classList.remove('active');
        document.body.classList.remove('modal-open');
    }

    const createBtn = document.getElementById('woCreateOverrideBtn');
    if (createBtn) createBtn.addEventListener('click', () => openModal(null));
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
    if (backdrop) {
        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop) closeModal();
        });
    }
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && backdrop && backdrop.classList.contains('active')) closeModal();
    });

    overridesCard.querySelectorAll('.wo-row-edit-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            if (btn.disabled) return;
            openModal(btn.dataset.date);
        });
    });

    overridesCard.querySelectorAll('.wo-row-reset-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (btn.disabled) return;
            if (!confirm("Remove this override? The date will fall back to the doctor's recurring schedule.")) return;
            btn.disabled = true;
            try {
                await postAction('delete_override', {
                    doctor_id: currentDoctorId,
                    override_date: btn.dataset.date,
                });
                window.location.reload();
            } catch (err) {
                showToast(err.message);
                btn.disabled = false;
            }
        });
    });

    if (saveBtn) {
        saveBtn.addEventListener('click', async () => {
            showModalError('');
            const dateStr = fieldDate.value;

            if (!dateStr) {
                showModalError('Please choose a date for this override.');
                return;
            }
            if (dateStr < weekStart) {
                showModalError('That date is before the week currently shown. Navigate to that week first.');
                return;
            }

            if (statusesWithHours.includes(fieldStatus.value)) {
                if (!fieldStart.value || !fieldEnd.value) {
                    showModalError('Please provide a start and end time for this status.');
                    return;
                }
                if (fieldEnd.value <= fieldStart.value) {
                    showModalError('End time must be later than start time.');
                    return;
                }
            }

            saveBtn.disabled = true;
            try {
                await postAction('save_override', {
                    doctor_id: fieldDoctor ? fieldDoctor.value : currentDoctorId,
                    override_date: dateStr,
                    status: fieldStatus.value,
                    start_time: fieldStart.value,
                    end_time: fieldEnd.value,
                    reason: fieldReason.value.trim(),
                    notes: fieldNotes.value.trim(),
                });
                closeModal();
                window.location.reload();
            } catch (err) {
                showModalError(err.message);
            } finally {
                saveBtn.disabled = false;
            }
        });
    }

    /* ==================== Doctor / week navigation ====================== */

    const doctorSelect = document.getElementById('woDoctorSelect');
    if (doctorSelect) {
        doctorSelect.addEventListener('change', () => {
            const weekOffset = doctorSelect.dataset.weekOffset || '0';
            window.location.href = `?tab=weekly&wo_doctor_id=${doctorSelect.value}&wo_week_offset=${weekOffset}`;
        });
    }

    // Warn before leaving with the modal open and edits pending in it.
    window.addEventListener('beforeunload', (e) => {
        if (backdrop && backdrop.classList.contains('active')) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
})();