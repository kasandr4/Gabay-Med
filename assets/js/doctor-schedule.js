// assets/js/doctor-schedule-overrides.js
// GabayMed — Admin Portal: Doctor Schedule page, Weekly Overrides tab.
// UI ONLY: exactly the same pattern as doctor-schedule.js (no fetch() to a
// backend anywhere). Doctor/week switching navigates via query params
// (server re-renders the mock week), while row edits, "Create Override",
// and "Reset" operate purely on the in-memory `woWeekData` object and the
// DOM. Nothing here touches the Monthly Schedule tab or its script.
//
// TODO(backend): once a `schedule_overrides` table exists (see the note at
// the top of the Weekly Overrides section in admin/doctor-schedule.php),
// the Save button below becomes an UPSERT keyed on (doctor_id,
// override_date) and "Reset" becomes a DELETE for that row.

(function () {
    'use strict';

    const overridesCard = document.getElementById('woOverridesCard');
    if (!overridesCard) return;

    const weekData = window.woWeekData || {};
    const statusLabels = window.woStatusLabels || {
        no_change: 'No Change', available: 'Available', half_day: 'Half Day',
        emergency_leave: 'Emergency Leave', training: 'Training',
        meeting: 'Meeting', unavailable: 'Unavailable',
    };
    const doctors = window.woDoctors || {};

    const isDeactivated = overridesCard.dataset.deactivated === '1';
    const weekStart = overridesCard.dataset.weekStart;
    const currentDoctorId = overridesCard.dataset.doctorId;

    /* ============================= Toasts ============================= */
    // Reuses the same lightweight toast host pattern as doctor-schedule.js
    // (separate host element so the two scripts never fight over one node).

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

    /* ========================= Row rendering ============================ */

    function formatTime(hhmm) {
        if (!hhmm) return '—';
        const [h, m] = hhmm.split(':').map(Number);
        const period = h >= 12 ? 'PM' : 'AM';
        const hour12 = ((h + 11) % 12) + 1;
        return `${hour12}:${String(m).padStart(2, '0')} ${period}`;
    }

    function renderRow(dateStr) {
        const row = overridesCard.querySelector(`tr[data-date="${dateStr}"]`);
        const day = weekData[dateStr];
        if (!row || !day) return;

        row.className = row.className
            .split(' ')
            .filter(c => !c.startsWith('wo-row-') || c === 'wo-row-today')
            .join(' ');
        row.classList.add('wo-row-' + day.status);

        row.querySelector('.wo-start-cell').textContent = formatTime(day.start);
        row.querySelector('.wo-end-cell').textContent = formatTime(day.end);
        row.querySelector('.wo-reason-cell').textContent = day.reason ? day.reason : '—';

        const pill = row.querySelector('.status-pill');
        pill.className = 'status-pill wo-status-pill-' + day.status;
        pill.textContent = statusLabels[day.status] || day.status;

        const editBtn = row.querySelector('.wo-row-edit-btn');
        const resetBtn = row.querySelector('.wo-row-reset-btn');
        if (editBtn) editBtn.textContent = day.status === 'no_change' ? 'Add Override' : 'Edit';
        if (resetBtn) resetBtn.disabled = isDeactivated || day.status === 'no_change';
    }

    function recomputeSummary() {
        let overrideDays = 0, leaveDays = 0, halfDays = 0, trainingDays = 0;
        Object.values(weekData).forEach(day => {
            if (day.status !== 'no_change') overrideDays++;
            if (day.status === 'emergency_leave') leaveDays++;
            if (day.status === 'half_day') halfDays++;
            if (day.status === 'training') trainingDays++;
        });
        const set = (key, val) => {
            const el = document.querySelector(`[data-summary="${key}"]`);
            if (el) el.textContent = val;
        };
        set('overrideDays', overrideDays);
        set('leaveDays', leaveDays);
        set('halfDays', halfDays);
        set('trainingDays', trainingDays);
    }

    /* ============================ Modal ============================= */

    const backdrop = document.getElementById('woOverrideModalBackdrop');
    const modalTitle = document.getElementById('woOverrideModalTitle');
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

    function openModal(dateStr) {
        const day = dateStr ? weekData[dateStr] : null;

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
        btn.addEventListener('click', () => {
            if (btn.disabled) return;
            const dateStr = btn.dataset.date;
            weekData[dateStr] = {
                ...weekData[dateStr],
                status: 'no_change',
                start: null,
                end: null,
                reason: '',
                notes: '',
            };
            renderRow(dateStr);
            recomputeSummary();
            showToast('Override removed — this day falls back to the Monthly Schedule. (UI only)');
        });
    });

    if (saveBtn) {
        saveBtn.addEventListener('click', () => {
            const dateStr = fieldDate.value;
            if (!dateStr) {
                showToast('Please choose a date for this override.');
                return;
            }
            if (String(fieldDoctor.value) !== String(currentDoctorId)) {
                showToast(`Saved for ${doctors[fieldDoctor.value] ? doctors[fieldDoctor.value].name : 'the selected doctor'}. Switch to that doctor to see it in the table. (UI only)`);
                closeModal();
                return;
            }
            if (!weekData[dateStr]) {
                showToast('That date is outside the week currently shown, so it was not added to this table. Navigate to that week first.');
                return;
            }

            weekData[dateStr] = {
                ...weekData[dateStr],
                status: fieldStatus.value,
                start: fieldStart.value || null,
                end: fieldEnd.value || null,
                reason: fieldReason.value.trim(),
                notes: fieldNotes.value.trim(),
            };
            renderRow(dateStr);
            recomputeSummary();
            closeModal();
            showToast('Override saved. (UI only — not yet saved to the database.)');
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