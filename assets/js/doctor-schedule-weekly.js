// assets/js/doctor-schedule-weekly.js
// GabayMed — Admin Portal: Doctor Schedule page, Weekly Recurring
// Schedule tab. Unlike doctor-schedule.js and the (missing)
// doctor-schedule-overrides.js, this tab is REAL and backend-connected:
// every Save/Delete/Activate/Deactivate action below is a fetch() POST
// to admin/doctor-schedule-actions.php, which writes to the
// `doctor_weekly_schedules` table. On success the page reloads so the
// table, and everything else that reads this data (Doctor Portal,
// patient booking), reflects the change immediately.

(function () {
    'use strict';

    const tableBody = document.getElementById('rsScheduleTableBody');
    if (!tableBody) return; // tab not present on this page load

    const doctorId = window.rsDoctorId || 0;
    const csrfToken = window.rsCsrfToken || '';

    /* ============================= Toasts ============================= */
    // Same lightweight toast host pattern used by the other two tabs'
    // scripts, in its own host element so they don't collide.

    function showToast(message) {
        let host = document.getElementById('rsToastHost');
        if (!host) {
            host = document.createElement('div');
            host.className = 'ds-toast-host';
            host.id = 'rsToastHost';
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

    const backdrop = document.getElementById('rsScheduleModalBackdrop');
    const modalTitle = document.getElementById('rsScheduleModalTitle');
    const modalError = document.getElementById('rsModalError');
    const fieldDayOfWeek = document.getElementById('rsFieldDayOfWeek');
    const fieldStart = document.getElementById('rsFieldStart');
    const fieldEnd = document.getElementById('rsFieldEnd');
    const fieldStatus = document.getElementById('rsFieldStatus');
    const fieldEffectiveFrom = document.getElementById('rsFieldEffectiveFrom');
    const fieldEffectiveTo = document.getElementById('rsFieldEffectiveTo');
    const closeBtn = document.getElementById('rsScheduleModalClose');
    const cancelBtn = document.getElementById('rsScheduleModalCancel');
    const saveBtn = document.getElementById('rsScheduleModalSave');
    const addBtn = document.getElementById('rsAddScheduleBtn');

    let editingScheduleId = null; // null = creating a new schedule

    function showModalError(message) {
        if (!modalError) return;
        modalError.textContent = message;
        modalError.classList.toggle('rs-modal-error-visible', !!message);
    }

    function openModal(schedule) {
        editingScheduleId = schedule ? schedule.schedule_id : null;
        modalTitle.textContent = schedule ? 'Edit Schedule' : 'Add Schedule';
        showModalError('');

        if (schedule) {
            fieldDayOfWeek.value = schedule.day_of_week;
            fieldStart.value = (schedule.start_time || '').slice(0, 5);
            fieldEnd.value = (schedule.end_time || '').slice(0, 5);
            fieldStatus.value = schedule.status;
            fieldEffectiveFrom.value = schedule.effective_from;
            fieldEffectiveTo.value = schedule.effective_to || '';
        } else {
            fieldDayOfWeek.value = '1';
            fieldStart.value = '08:00';
            fieldEnd.value = '17:00';
            fieldStatus.value = 'active';
            fieldEffectiveFrom.value = new Date().toISOString().slice(0, 10);
            fieldEffectiveTo.value = '';
        }

        document.body.classList.add('modal-open');
        backdrop.classList.add('active');
    }

    function closeModal() {
        backdrop.classList.remove('active');
        document.body.classList.remove('modal-open');
        editingScheduleId = null;
    }

    if (addBtn) addBtn.addEventListener('click', () => openModal(null));
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

    tableBody.querySelectorAll('.rs-edit-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const schedule = JSON.parse(btn.dataset.schedule);
            openModal(schedule);
        });
    });

    if (saveBtn) {
        saveBtn.addEventListener('click', async () => {
            showModalError('');

            if (!fieldStart.value || !fieldEnd.value) {
                showModalError('Please provide both a start and end time.');
                return;
            }
            if (fieldEnd.value <= fieldStart.value) {
                showModalError('End time must be later than start time.');
                return;
            }
            if (!fieldEffectiveFrom.value) {
                showModalError('Please provide an effective start date.');
                return;
            }
            if (fieldEffectiveTo.value && fieldEffectiveTo.value < fieldEffectiveFrom.value) {
                showModalError("Effective end date can't be before the start date.");
                return;
            }

            const fields = {
                doctor_id: doctorId,
                day_of_week: fieldDayOfWeek.value,
                start_time: fieldStart.value,
                end_time: fieldEnd.value,
                effective_from: fieldEffectiveFrom.value,
                effective_to: fieldEffectiveTo.value,
                status: fieldStatus.value,
            };

            saveBtn.disabled = true;
            try {
                if (editingScheduleId) {
                    fields.schedule_id = editingScheduleId;
                    await postAction('update_schedule', fields);
                } else {
                    await postAction('create_schedule', fields);
                }
                closeModal();
                window.location.reload();
            } catch (err) {
                showModalError(err.message);
            } finally {
                saveBtn.disabled = false;
            }
        });
    }

    /* ======================= Toggle / Delete ========================== */

    tableBody.querySelectorAll('.rs-toggle-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            btn.disabled = true;
            try {
                await postAction('toggle_status', { schedule_id: btn.dataset.scheduleId });
                window.location.reload();
            } catch (err) {
                showToast(err.message);
                btn.disabled = false;
            }
        });
    });

    tableBody.querySelectorAll('.rs-archive-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Archive this schedule? It will no longer appear in this list, but its record is kept.')) return;
            btn.disabled = true;
            try {
                await postAction('archive_schedule', { schedule_id: btn.dataset.scheduleId });
                window.location.reload();
            } catch (err) {
                showToast(err.message);
                btn.disabled = false;
            }
        });
    });

    // Warn before leaving with the modal open and edits pending in it.
    window.addEventListener('beforeunload', (e) => {
        if (backdrop && backdrop.classList.contains('active')) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
})();